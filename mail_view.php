<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$messageId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($messageId === false || $messageId === null || $messageId < 1) {
    http_response_code(404);
    exit('ไม่พบจดหมายที่ต้องการ');
}

try {
    $statement = db()->prepare(
        'SELECT m.message_id, m.subject, m.body, m.sent_at, m.urgency, m.delivery_type, m.department,
                sender.user_name AS sender_name, sender.full_name AS sender_full_name,
                EXISTS (
                    SELECT 1 FROM mail_recipients AS access_check
                    WHERE access_check.message_id = m.message_id
                      AND access_check.recipient_id = :recipient_id
                ) AS is_recipient,
                (SELECT GROUP_CONCAT(recipient.user_name ORDER BY recipient.user_name SEPARATOR ", ")
                 FROM mail_recipients AS sent_to
                 JOIN users AS recipient ON recipient.user_id = sent_to.recipient_id
                 WHERE sent_to.message_id = m.message_id) AS recipient_names
         FROM mail_messages AS m
         JOIN users AS sender ON sender.user_id = m.sender_id
         WHERE m.message_id = :message_id
           AND (
               m.sender_id = :sender_id
               OR EXISTS (
                   SELECT 1 FROM mail_recipients AS allowed
                   WHERE allowed.message_id = m.message_id AND allowed.recipient_id = :allowed_user_id
               )
           )
         LIMIT 1'
    );
    $userId = (int) $_SESSION['user_id'];
    $statement->execute([
        'recipient_id' => $userId,
        'message_id' => $messageId,
        'sender_id' => $userId,
        'allowed_user_id' => $userId,
    ]);
    $message = $statement->fetch();
    if (!$message) {
        http_response_code(404);
        exit('ไม่พบจดหมายที่ต้องการ');
    }

    if ((int) $message['is_recipient'] === 1) {
        $markRead = db()->prepare(
            'UPDATE mail_recipients
             SET read_at = COALESCE(read_at, CURRENT_TIMESTAMP)
             WHERE message_id = :message_id AND recipient_id = :recipient_id'
        );
        $markRead->execute(['message_id' => $messageId, 'recipient_id' => $userId]);
    }

    $documents = db()->prepare(
        'SELECT document_id, original_name, file_size
         FROM mail_documents WHERE message_id = :message_id ORDER BY document_id'
    );
    $documents->execute(['message_id' => $messageId]);
    $documents = $documents->fetchAll();
} catch (PDOException $exception) {
    error_log('Boxmail message view failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('ไม่สามารถเปิดจดหมายได้ กรุณาลองใหม่ภายหลัง');
} catch (RuntimeException $exception) {
    http_response_code(500);
    exit('ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบการตั้งค่า');
}

$isInbox = (int) $message['is_recipient'] === 1;
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escape($message['subject']) ?> | Mailbox</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="mailbox-page">
    <main class="compose-shell">
        <header class="detail-header">
            <a class="brand brand-link" href="index.php">
                <span class="brand-mark" aria-hidden="true">M</span>
                <span>Mailbox</span>
            </a>
            <a class="back-link" href="index.php?view=<?= $isInbox ? 'inbox' : 'sent' ?>">← กลับกล่องข้อความ</a>
        </header>

        <article class="mail-detail">
            <?php if (isset($_GET['sent'])): ?>
                <p class="notice notice-success" role="status">ส่งจดหมายเรียบร้อยแล้ว</p>
            <?php endif; ?>
            <div class="detail-heading">
                <span class="message-label"><?= $isInbox ? 'จดหมายเข้า' : 'จดหมายที่ส่งแล้ว' ?></span>
                <h1><?= escape($message['subject']) ?></h1>
            </div>
            <div class="detail-meta">
                <p><span>จาก</span><strong><?= escape($message['sender_full_name']) ?> (@<?= escape($message['sender_name']) ?>)</strong></p>
                <p><span>ถึง</span><strong>
                    <?= $message['delivery_type'] === 'all'
                        ? 'ผู้ใช้ทุกคน'
                        : ($message['delivery_type'] === 'department'
                            ? 'แผนก ' . escape((string) $message['department'])
                            : escape((string) $message['recipient_names'])) ?>
                </strong></p>
                <p><span>ความเร่งด่วน</span><strong><?= [
                    'normal' => 'ปกติ',
                    'urgent' => 'ด่วน',
                    'very_urgent' => 'ด่วนมาก',
                    'most_urgent' => 'ด่วนที่สุด',
                ][$message['urgency']] ?? 'ปกติ' ?></strong></p>
                <p><span>วันที่</span><strong><?= escape(date('d/m/Y H:i', strtotime($message['sent_at']))) ?></strong></p>
            </div>
            <div class="mail-body"><?= nl2br(escape($message['body'])) ?></div>

            <?php if ($documents !== []): ?>
                <section class="document-section">
                    <h2>เอกสารแนบ (<?= count($documents) ?>)</h2>
                    <?php foreach ($documents as $document): ?>
                        <details class="document-item">
                            <summary>
                                <span class="document-icon" aria-hidden="true">PDF</span>
                                <span class="document-name"><?= escape($document['original_name']) ?></span>
                                <span class="document-size"><?= number_format((int) $document['file_size'] / 1024, 0) ?> KB</span>
                            </summary>
                            <div class="document-viewer">
                                <iframe
                                    src="document.php?id=<?= (int) $document['document_id'] ?>"
                                    title="เอกสาร <?= escape($document['original_name']) ?>"
                                    loading="lazy"
                                ></iframe>
                                <a href="document.php?id=<?= (int) $document['document_id'] ?>" target="_blank" rel="noopener noreferrer">เปิดเอกสารในแท็บใหม่</a>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        </article>
    </main>
</body>
</html>
