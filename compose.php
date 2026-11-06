<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_GET['action'] ?? '') === 'recipient-search') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $queryValue = $_GET['q'] ?? '';
        $query = is_string($queryValue) ? trim($queryValue) : '';
        if (mb_strlen($query, 'UTF-8') < 2) {
            echo json_encode([], JSON_THROW_ON_ERROR);
            exit;
        }

        $search = db()->prepare(
            'SELECT user_id, full_name, user_name
             FROM users
             WHERE full_name LIKE :query
             ORDER BY full_name, user_name
             LIMIT 10'
        );
        $search->execute(['query' => '%' . $query . '%']);
        echo json_encode($search->fetchAll(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        error_log('Boxmail recipient search failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'ไม่สามารถค้นหารายชื่อได้ในขณะนี้'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

const MAX_DOCUMENT_COUNT = 5;
const MAX_DOCUMENT_SIZE = 5 * 1024 * 1024;
const MAX_TOTAL_DOCUMENT_SIZE = 20 * 1024 * 1024;

$error = '';
$subject = '';
$body = '';
$deliveryType = 'individual';
$urgency = 'normal';
$selectedDepartment = '';
$departments = [];
$selectedRecipients = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));
    $deliveryType = is_string($_POST['delivery_type'] ?? null) ? $_POST['delivery_type'] : '';
    $urgency = is_string($_POST['urgency'] ?? null) ? $_POST['urgency'] : '';
    $selectedDepartment = is_string($_POST['department'] ?? null) ? trim($_POST['department']) : '';
    $uploadedFiles = [];
    $storedFiles = [];

    if (!csrf_is_valid()) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } elseif ($subject === '' || mb_strlen($subject, 'UTF-8') > 255) {
        $error = 'กรุณากรอกหัวข้อไม่เกิน 255 ตัวอักษร';
    } elseif ($body === '' || mb_strlen($body, 'UTF-8') > 20000) {
        $error = 'กรุณากรอกข้อความไม่เกิน 20,000 ตัวอักษร';
    } elseif (!in_array($deliveryType, ['individual', 'department', 'all'], true)) {
        $error = 'กรุณาเลือกประเภทการส่ง';
    } elseif (!in_array($urgency, ['normal', 'urgent', 'very_urgent', 'most_urgent'], true)) {
        $error = 'กรุณาเลือกระดับความเร่งด่วน';
    } else {
        try {
            if ($deliveryType === 'individual') {
                if (!isset($_POST['recipient_ids']) || !is_array($_POST['recipient_ids']) || $_POST['recipient_ids'] === []) {
                    throw new InvalidArgumentException('กรุณาระบุชื่อผู้รับอย่างน้อยหนึ่งคน');
                }

                $recipientIds = $_POST['recipient_ids'];
                if (count($recipientIds) > 20) {
                    throw new InvalidArgumentException('กรุณาระบุชื่อผู้รับ 1 ถึง 20 คน');
                }

                foreach ($recipientIds as $recipientId) {
                    if (!is_string($recipientId) || !ctype_digit($recipientId) || (int) $recipientId < 1) {
                        throw new InvalidArgumentException('รายชื่อผู้รับไม่ถูกต้อง กรุณาเลือกจากรายการแนะนำ');
                    }
                }
                $recipientIds = array_values(array_unique(array_map('intval', $recipientIds)));
                if (count($recipientIds) > 20) {
                    throw new InvalidArgumentException('กรุณาระบุชื่อผู้รับ 1 ถึง 20 คน');
                }

                $lookup = db()->prepare(
                    'SELECT user_id, full_name, user_name FROM users WHERE user_id IN (' .
                    implode(', ', array_fill(0, count($recipientIds), '?')) . ')'
                );
                $lookup->execute($recipientIds);
                $recipientsById = [];
                foreach ($lookup->fetchAll() as $recipient) {
                    $recipientsById[(int) $recipient['user_id']] = $recipient;
                }
                foreach ($recipientIds as $recipientId) {
                    if (isset($recipientsById[$recipientId])) {
                        $selectedRecipients[] = $recipientsById[$recipientId];
                    } else {
                        throw new InvalidArgumentException('ไม่พบผู้รับบางราย กรุณาเลือกชื่อจากรายการแนะนำอีกครั้ง');
                    }
                }
                $recipients = $selectedRecipients;
            } elseif ($deliveryType === 'department') {
                if ($selectedDepartment === '' || mb_strlen($selectedDepartment, 'UTF-8') > 100) {
                    throw new InvalidArgumentException('กรุณาเลือกแผนกที่ต้องการส่ง');
                }

                $lookup = db()->prepare(
                    'SELECT user_id FROM users WHERE department = :department ORDER BY user_id'
                );
                $lookup->execute(['department' => $selectedDepartment]);
                $recipients = $lookup->fetchAll();
                if ($recipients === []) {
                    throw new InvalidArgumentException('ไม่พบผู้ใช้ในแผนกนี้');
                }
            } else {
                $recipients = db()->query('SELECT user_id FROM users ORDER BY user_id')->fetchAll();
                if ($recipients === []) {
                    throw new InvalidArgumentException('ไม่พบผู้ใช้ในระบบ');
                }
            }

            if (isset($_FILES['documents']) && is_array($_FILES['documents']['name'])) {
                $fileCount = count($_FILES['documents']['name']);
                if ($fileCount > MAX_DOCUMENT_COUNT) {
                    throw new InvalidArgumentException('แนบเอกสารได้ไม่เกิน 5 ไฟล์ต่อจดหมาย');
                }

                $totalSize = 0;
                $fileInfo = new finfo(FILEINFO_MIME_TYPE);
                for ($index = 0; $index < $fileCount; $index++) {
                    $uploadError = (int) ($_FILES['documents']['error'][$index] ?? UPLOAD_ERR_NO_FILE);
                    if ($uploadError === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    if ($uploadError !== UPLOAD_ERR_OK) {
                        throw new InvalidArgumentException('อัปโหลดเอกสารไม่สำเร็จ กรุณาตรวจสอบขนาดไฟล์');
                    }

                    $temporaryPath = (string) $_FILES['documents']['tmp_name'][$index];
                    $fileSize = (int) $_FILES['documents']['size'][$index];
                    if ($fileSize < 1 || $fileSize > MAX_DOCUMENT_SIZE) {
                        throw new InvalidArgumentException('เอกสารแต่ละไฟล์ต้องมีขนาดไม่เกิน 5 MB');
                    }
                    $totalSize += $fileSize;
                    if ($totalSize > MAX_TOTAL_DOCUMENT_SIZE) {
                        throw new InvalidArgumentException('เอกสารทั้งหมดต้องมีขนาดรวมไม่เกิน 20 MB');
                    }
                    if (
                        $fileInfo->file($temporaryPath) !== 'application/pdf'
                        || file_get_contents($temporaryPath, false, null, 0, 5) !== '%PDF-'
                    ) {
                        throw new InvalidArgumentException('แนบได้เฉพาะเอกสาร PDF เท่านั้น');
                    }

                    $originalName = basename(str_replace('\\', '/', (string) $_FILES['documents']['name'][$index]));
                    $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?: 'document.pdf';
                    if (mb_strlen($originalName, 'UTF-8') > 255) {
                        $originalName = mb_substr($originalName, 0, 255, 'UTF-8');
                    }
                    $uploadedFiles[] = [
                        'temporary_path' => $temporaryPath,
                        'original_name' => $originalName,
                        'size' => $fileSize,
                    ];
                }
            }

            $storageDirectory = private_document_directory();
            foreach ($uploadedFiles as $uploadedFile) {
                $storageName = bin2hex(random_bytes(32));
                $destination = $storageDirectory . DIRECTORY_SEPARATOR . $storageName;
                if (!move_uploaded_file($uploadedFile['temporary_path'], $destination)) {
                    throw new RuntimeException('Unable to store uploaded document.');
                }
                $storedFiles[] = [
                    'storage_name' => $storageName,
                    'original_name' => $uploadedFile['original_name'],
                    'size' => $uploadedFile['size'],
                ];
            }

            $connection = db();
            $connection->beginTransaction();
            $insertMessage = $connection->prepare(
                'INSERT INTO mail_messages (sender_id, subject, body, urgency, delivery_type, department)
                 VALUES (:sender_id, :subject, :body, :urgency, :delivery_type, :department)'
            );
            $insertMessage->execute([
                'sender_id' => (int) $_SESSION['user_id'],
                'subject' => $subject,
                'body' => $body,
                'urgency' => $urgency,
                'delivery_type' => $deliveryType,
                'department' => $deliveryType === 'department' ? $selectedDepartment : null,
            ]);
            $messageId = (int) $connection->lastInsertId();

            $insertRecipient = $connection->prepare(
                'INSERT INTO mail_recipients (message_id, recipient_id) VALUES (:message_id, :recipient_id)'
            );
            foreach ($recipients as $recipient) {
                $insertRecipient->execute([
                    'message_id' => $messageId,
                    'recipient_id' => $recipient['user_id'],
                ]);
            }

            $insertDocument = $connection->prepare(
                'INSERT INTO mail_documents (message_id, original_name, storage_name, file_size)
                 VALUES (:message_id, :original_name, :storage_name, :file_size)'
            );
            foreach ($storedFiles as $file) {
                $insertDocument->execute([
                    'message_id' => $messageId,
                    'original_name' => $file['original_name'],
                    'storage_name' => $file['storage_name'],
                    'file_size' => $file['size'],
                ]);
            }

            $connection->commit();
            header('Location: mail_view.php?id=' . $messageId . '&sent=1');
            exit;
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            if (isset($connection) && $connection instanceof PDO && $connection->inTransaction()) {
                $connection->rollBack();
            }
            foreach ($storedFiles as $file) {
                $path = (isset($storageDirectory) ? $storageDirectory . DIRECTORY_SEPARATOR : '') . $file['storage_name'];
                if (is_file($path)) {
                    unlink($path);
                }
            }
            error_log('Boxmail send message failed: ' . $exception->getMessage());
            $error = 'ไม่สามารถส่งจดหมายได้ในขณะนี้ กรุณาลองใหม่ภายหลัง';
        }
    }
}

try {
    $departmentQuery = db()->query(
        'SELECT DISTINCT department FROM users
         WHERE department IS NOT NULL AND TRIM(department) <> \'\'
         ORDER BY department'
    );
    $departments = $departmentQuery->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $exception) {
    error_log('Boxmail department list query failed: ' . $exception->getMessage());
    if ($error === '') {
        $error = 'ไม่สามารถโหลดรายชื่อแผนกได้ กรุณาตรวจสอบการตั้งค่าฐานข้อมูล';
    }
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>เขียนจดหมาย | Mailbox</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="mailbox-page">
    <main class="compose-shell">
        <header class="detail-header">
            <a class="brand brand-link" href="index.php">
                <span class="brand-mark" aria-hidden="true">M</span>
                <span>Mailbox</span>
            </a>
            <a class="back-link" href="index.php">← กลับกล่องข้อความ</a>
        </header>
        <section class="compose-card">
            <p class="eyebrow">MAILBOX</p>
            <h1>เขียนจดหมาย</h1>
            <p class="subtitle">ส่งข้อความและเอกสารถึงผู้ใช้ในระบบ</p>

            <?php if ($error !== ''): ?>
                <p class="notice notice-error" role="alert"><?= escape($error) ?></p>
            <?php endif; ?>

            <form method="post" action="compose.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                <label for="urgency">ความเร่งด่วน</label>
                <select id="urgency" name="urgency" required>
                    <option value="normal" <?= $urgency === 'normal' ? 'selected' : '' ?>>ปกติ</option>
                    <option value="urgent" <?= $urgency === 'urgent' ? 'selected' : '' ?>>ด่วน</option>
                    <option value="very_urgent" <?= $urgency === 'very_urgent' ? 'selected' : '' ?>>ด่วนมาก</option>
                    <option value="most_urgent" <?= $urgency === 'most_urgent' ? 'selected' : '' ?>>ด่วนที่สุด</option>
                </select>

                <label for="delivery-type">ประเภทการส่ง</label>
                <select id="delivery-type" name="delivery_type" required>
                    <option value="individual" <?= $deliveryType === 'individual' ? 'selected' : '' ?>>ส่งหารายบุคคล</option>
                    <option value="department" <?= $deliveryType === 'department' ? 'selected' : '' ?>>ส่งหาแผนก</option>
                    <option value="all" <?= $deliveryType === 'all' ? 'selected' : '' ?>>ส่งทั้งหมด (ผู้ใช้ทุกคน)</option>
                </select>

                <div id="individual-target" <?= $deliveryType !== 'individual' ? 'hidden' : '' ?>>
                <label for="recipient-search">ถึง <span class="optional">พิมพ์ชื่อ-นามสกุลแล้วเลือกจากรายการ (ไม่เกิน 20 คน)</span></label>
                <div class="recipient-picker">
                    <ul id="recipient-chips" class="recipient-chips" aria-label="ผู้รับที่เลือก">
                        <?php foreach ($selectedRecipients as $recipient): ?>
                            <li class="recipient-chip">
                                <span><?= escape($recipient['full_name']) ?> <small>(@<?= escape($recipient['user_name']) ?>)</small></span>
                                <input type="hidden" name="recipient_ids[]" value="<?= (int) $recipient['user_id'] ?>">
                                <button type="button" class="recipient-remove" aria-label="นำ <?= escape($recipient['full_name']) ?> ออก">×</button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <input id="recipient-search" type="text" maxlength="255" placeholder="พิมพ์ชื่อเพื่อค้นหา..." autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="recipient-suggestions" aria-expanded="false" autofocus>
                </div>
                <ul id="recipient-suggestions" class="recipient-suggestions" role="listbox" hidden></ul>
                <p id="recipient-status" class="recipient-status" role="status" aria-live="polite"></p>
                </div>

                <div id="department-target" <?= $deliveryType !== 'department' ? 'hidden' : '' ?>>
                    <label for="department">แผนก</label>
                    <select id="department" name="department" <?= $deliveryType === 'department' ? 'required' : '' ?>>
                        <option value="">เลือกแผนก</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= escape((string) $department) ?>" <?= $selectedDepartment === $department ? 'selected' : '' ?>>
                                <?= escape((string) $department) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($departments === []): ?>
                        <p class="optional">ยังไม่มีผู้ใช้ที่ระบุแผนก กรุณากำหนดแผนกให้ผู้ใช้ก่อน</p>
                    <?php endif; ?>
                </div>

                <label for="subject">หัวข้อ</label>
                <input id="subject" name="subject" type="text" maxlength="255" value="<?= escape($subject) ?>" required>

                <label for="body">ข้อความ</label>
                <textarea id="body" name="body" rows="9" maxlength="20000" required><?= escape($body) ?></textarea>

                <label for="documents">เอกสารแนบ <span class="optional">PDF เท่านั้น · สูงสุด 5 ไฟล์ · ไฟล์ละ 5 MB</span></label>
                <input id="documents" name="documents[]" type="file" accept="application/pdf,.pdf" multiple>

                <button class="button" type="submit">ส่งจดหมาย</button>
            </form>
        </section>
    </main>
    <script>
        (() => {
            const deliveryType = document.getElementById('delivery-type');
            const individualTarget = document.getElementById('individual-target');
            const departmentTarget = document.getElementById('department-target');
            const departmentSelect = document.getElementById('department');
            const searchInput = document.getElementById('recipient-search');
            const chips = document.getElementById('recipient-chips');
            const suggestions = document.getElementById('recipient-suggestions');
            const status = document.getElementById('recipient-status');
            const form = searchInput.closest('form');
            let matches = [];
            let activeIndex = -1;
            let debounceTimer;
            let requestController;

            const selectedIds = () => new Set(
                Array.from(chips.querySelectorAll('input[name="recipient_ids[]"]'), input => input.value)
            );

            const closeSuggestions = () => {
                suggestions.hidden = true;
                searchInput.setAttribute('aria-expanded', 'false');
                searchInput.removeAttribute('aria-activedescendant');
                matches = [];
                activeIndex = -1;
            };

            const updateDeliveryTarget = () => {
                individualTarget.hidden = deliveryType.value !== 'individual';
                departmentTarget.hidden = deliveryType.value !== 'department';
                departmentSelect.required = deliveryType.value === 'department';
                clearTimeout(debounceTimer);
                if (requestController) {
                    requestController.abort();
                }
                closeSuggestions();
            };

            deliveryType.addEventListener('change', updateDeliveryTarget);
            updateDeliveryTarget();

            const addRecipient = recipient => {
                const ids = selectedIds();
                if (ids.has(String(recipient.user_id))) {
                    status.textContent = 'เลือกรายชื่อนี้แล้ว';
                    return;
                }
                if (ids.size >= 20) {
                    status.textContent = 'เลือกผู้รับได้ไม่เกิน 20 คน';
                    return;
                }

                const chip = document.createElement('li');
                chip.className = 'recipient-chip';
                const name = document.createElement('span');
                name.textContent = `${recipient.full_name} (@${recipient.user_name})`;
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'recipient_ids[]';
                hiddenInput.value = recipient.user_id;
                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'recipient-remove';
                removeButton.setAttribute('aria-label', `นำ ${recipient.full_name} ออก`);
                removeButton.textContent = '×';
                chip.append(name, hiddenInput, removeButton);
                chips.append(chip);
                searchInput.value = '';
                status.textContent = `เพิ่ม ${recipient.full_name} แล้ว`;
                closeSuggestions();
                searchInput.focus();
            };

            const renderSuggestions = () => {
                suggestions.replaceChildren();
                matches = matches.filter(match => !selectedIds().has(String(match.user_id)));
                if (matches.length === 0) {
                    closeSuggestions();
                    status.textContent = 'ไม่พบรายชื่อที่ตรงกัน';
                    return;
                }

                matches.forEach((match, index) => {
                    const option = document.createElement('li');
                    option.id = `recipient-option-${index}`;
                    option.setAttribute('role', 'option');
                    option.setAttribute('aria-selected', 'false');
                    option.className = 'recipient-suggestion';
                    const name = document.createElement('strong');
                    name.textContent = match.full_name;
                    const username = document.createElement('small');
                    username.textContent = `@${match.user_name}`;
                    option.append(name, username);
                    option.addEventListener('mousedown', event => event.preventDefault());
                    option.addEventListener('click', () => addRecipient(match));
                    suggestions.append(option);
                });
                suggestions.hidden = false;
                searchInput.setAttribute('aria-expanded', 'true');
                status.textContent = `พบ ${matches.length} รายชื่อ ใช้ปุ่มลูกศรและ Enter เพื่อเลือก`;
            };

            const searchRecipients = async query => {
                if (requestController) {
                    requestController.abort();
                }
                requestController = new AbortController();
                try {
                    const response = await fetch(
                        `compose.php?action=recipient-search&q=${encodeURIComponent(query)}`,
                        { signal: requestController.signal, headers: { Accept: 'application/json' } }
                    );
                    if (!response.ok) {
                        throw new Error('Recipient search request failed.');
                    }
                    matches = await response.json();
                    renderSuggestions();
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        closeSuggestions();
                        status.textContent = 'ค้นหารายชื่อไม่สำเร็จ กรุณาลองอีกครั้ง';
                    }
                }
            };

            searchInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                if (requestController) {
                    requestController.abort();
                }
                const query = searchInput.value.trim();
                if (query.length < 2) {
                    closeSuggestions();
                    status.textContent = '';
                    return;
                }
                debounceTimer = setTimeout(() => searchRecipients(query), 250);
            });

            searchInput.addEventListener('keydown', event => {
                if (event.key === 'ArrowDown' && !suggestions.hidden) {
                    event.preventDefault();
                    activeIndex = (activeIndex + 1) % matches.length;
                } else if (event.key === 'ArrowUp' && !suggestions.hidden) {
                    event.preventDefault();
                    activeIndex = (activeIndex - 1 + matches.length) % matches.length;
                } else if (event.key === 'Enter' && !suggestions.hidden && matches.length > 0) {
                    event.preventDefault();
                    addRecipient(matches[activeIndex < 0 ? 0 : activeIndex]);
                    return;
                } else if (event.key === 'Escape') {
                    closeSuggestions();
                    return;
                } else {
                    return;
                }

                Array.from(suggestions.children).forEach((option, index) => {
                    const active = index === activeIndex;
                    option.setAttribute('aria-selected', String(active));
                    if (active) {
                        searchInput.setAttribute('aria-activedescendant', option.id);
                    }
                });
            });

            chips.addEventListener('click', event => {
                if (event.target.closest('.recipient-remove')) {
                    event.target.closest('.recipient-chip').remove();
                    status.textContent = 'นำผู้รับออกแล้ว';
                }
            });

            form.addEventListener('submit', event => {
                if (deliveryType.value === 'individual' && selectedIds().size === 0) {
                    event.preventDefault();
                    status.textContent = 'กรุณาเลือกผู้รับอย่างน้อยหนึ่งคนจากรายการแนะนำ';
                    searchInput.focus();
                }
            });

            document.addEventListener('click', event => {
                if (!event.target.closest('.recipient-picker') && !event.target.closest('#recipient-suggestions')) {
                    closeSuggestions();
                }
            });
        })();
    </script>
</body>
</html>
