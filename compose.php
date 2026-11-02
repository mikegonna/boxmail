<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

const MAX_DOCUMENT_COUNT = 5;
const MAX_DOCUMENT_SIZE = 5 * 1024 * 1024;
const MAX_TOTAL_DOCUMENT_SIZE = 20 * 1024 * 1024;

$error = '';
$subject = '';
$body = '';
$recipientNames = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));
    $recipientNames = trim((string) ($_POST['recipients'] ?? ''));
    $uploadedFiles = [];
    $storedFiles = [];

    if (!csrf_is_valid()) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } elseif ($subject === '' || mb_strlen($subject, 'UTF-8') > 255) {
        $error = 'กรุณากรอกหัวข้อไม่เกิน 255 ตัวอักษร';
    } elseif ($body === '' || mb_strlen($body, 'UTF-8') > 20000) {
        $error = 'กรุณากรอกข้อความไม่เกิน 20,000 ตัวอักษร';
    } elseif ($recipientNames === '') {
        $error = 'กรุณาระบุชื่อผู้รับอย่างน้อยหนึ่งคน';
    } else {
        try {
            $names = preg_split('/[\s,;]+/u', $recipientNames, -1, PREG_SPLIT_NO_EMPTY);
            $names = array_values(array_unique(array_map('trim', $names ?: [])));
            if ($names === [] || count($names) > 20 || in_array('', $names, true)) {
                throw new InvalidArgumentException('กรุณาระบุชื่อผู้รับ 1 ถึง 20 คน');
            }

            $lookup = db()->prepare(
                'SELECT user_id, user_name FROM users WHERE user_name IN (' .
                implode(', ', array_fill(0, count($names), '?')) . ')'
            );
            $lookup->execute($names);
            $recipients = $lookup->fetchAll();
            $foundNames = array_column($recipients, 'user_name');
            $missingNames = array_values(array_diff($names, $foundNames));
            if ($missingNames !== []) {
                throw new InvalidArgumentException('ไม่พบชื่อผู้ใช้: ' . implode(', ', $missingNames));
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
                'INSERT INTO mail_messages (sender_id, subject, body)
                 VALUES (:sender_id, :subject, :body)'
            );
            $insertMessage->execute([
                'sender_id' => (int) $_SESSION['user_id'],
                'subject' => $subject,
                'body' => $body,
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
                <label for="recipients">ถึง <span class="optional">พิมพ์ชื่อผู้ใช้ คั่นด้วยจุลภาคหากมีหลายคน (ไม่เกิน 20 คน)</span></label>
                <input id="recipients" name="recipients" type="text" maxlength="2000" value="<?= escape($recipientNames) ?>" placeholder="เช่น somchai, malee" autocomplete="off" required autofocus>

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
</body>
</html>
