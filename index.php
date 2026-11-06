<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$view = (string) ($_GET['view'] ?? 'inbox');
if (!in_array($view, ['inbox', 'sent'], true)) {
    $view = 'inbox';
}

try {
    $unreadStatement = db()->prepare(
        'SELECT COUNT(*) FROM mail_recipients WHERE recipient_id = :user_id AND read_at IS NULL'
    );
    $unreadStatement->execute(['user_id' => $userId]);
    $unreadCount = (int) $unreadStatement->fetchColumn();

    if ($view === 'inbox') {
        $statement = db()->prepare(
            'SELECT m.message_id, m.subject, m.body, m.sent_at, m.urgency, m.delivery_type, m.department, r.read_at,
                    sender.user_name AS sender_name, sender.full_name AS sender_full_name
             FROM mail_recipients AS r
             JOIN mail_messages AS m ON m.message_id = r.message_id
             JOIN users AS sender ON sender.user_id = m.sender_id
             WHERE r.recipient_id = :user_id
             ORDER BY m.sent_at DESC
             LIMIT 100'
        );
        $statement->execute(['user_id' => $userId]);
    } else {
        $statement = db()->prepare(
            'SELECT m.message_id, m.subject, m.body, m.sent_at, m.urgency, m.delivery_type, m.department, NULL AS read_at,
                    NULL AS sender_name, NULL AS sender_full_name,
                    (SELECT GROUP_CONCAT(recipient.user_name ORDER BY recipient.user_name SEPARATOR ", ")
                     FROM mail_recipients AS sent_to
                     JOIN users AS recipient ON recipient.user_id = sent_to.recipient_id
                     WHERE sent_to.message_id = m.message_id) AS recipient_names
             FROM mail_messages AS m
             WHERE m.sender_id = :user_id
             ORDER BY m.sent_at DESC
             LIMIT 100'
        );
        $statement->execute(['user_id' => $userId]);
    }

    $messages = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('Boxmail mailbox query failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('ไม่สามารถโหลดกล่องข้อความได้ กรุณาลองใหม่ภายหลัง');
} catch (RuntimeException $exception) {
    http_response_code(500);
    exit('ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบการตั้งค่า');
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>กล่องข้อความ | Mailbox</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="mailbox-page">
    <main class="mailbox-shell">
        <aside class="mail-sidebar">
            <a class="brand brand-link" href="index.php">
                <span class="brand-mark" aria-hidden="true">M</span>
                <span>Mailbox</span>
            </a>
            <a class="button compose-button" href="compose.php">เขียนจดหมาย</a>
            <nav class="mail-nav" aria-label="กล่องจดหมาย">
                <a class="<?= $view === 'inbox' ? 'active' : '' ?>" href="index.php?view=inbox">
                    <span>กล่องข้อความเข้า</span>
                    <?php if ($unreadCount > 0): ?>
                        <span class="unread-count"><?= $unreadCount ?></span>
                    <?php endif; ?>
                </a>
                <a class="<?= $view === 'sent' ? 'active' : '' ?>" href="index.php?view=sent">จดหมายที่ส่งแล้ว</a>
            </nav>
            <div class="sidebar-account">
                <span class="account-avatar" aria-hidden="true"><?= escape(mb_substr((string) $_SESSION['full_name'], 0, 1, 'UTF-8')) ?></span>
                <span class="account-name">
                    <strong><?= escape((string) $_SESSION['full_name']) ?></strong>
                    <small><?= escape((string) $_SESSION['user_name']) ?> · <?= escape((string) $_SESSION['role']) ?></small>
                </span>
                <a class="logout-link" href="logout.php">ออก</a>
            </div>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                <a class="admin-link" href="admin_reset_requests.php">จัดการคำขอรีเซ็ตรหัสผ่าน</a>
            <?php endif; ?>
        </aside>

        <section class="mail-content">
            <header class="mail-header">
                <div>
                    <p class="eyebrow">MAILBOX</p>
                    <h1><?= $view === 'inbox' ? 'กล่องข้อความเข้า' : 'จดหมายที่ส่งแล้ว' ?></h1>
                    <p class="subtitle">
                        <?= $view === 'inbox' ? 'จดหมายและเอกสารที่ส่งถึงคุณ' : 'จดหมายที่คุณส่งให้ผู้ใช้' ?>
                    </p>
                </div>
                <a class="button header-compose" href="compose.php">+ เขียนจดหมาย</a>
            </header>

            <?php if ($messages === []): ?>
                <div class="mail-empty">
                    <div class="empty-icon" aria-hidden="true">✉</div>
                    <h2><?= $view === 'inbox' ? 'ยังไม่มีจดหมายเข้า' : 'ยังไม่มีจดหมายที่ส่ง' ?></h2>
                    <p><?= $view === 'inbox' ? 'เมื่อมีคนส่งจดหมายถึงคุณ จดหมายจะแสดงที่นี่' : 'จดหมายที่คุณเขียนจะปรากฏที่นี่' ?></p>
                    <?php if ($view === 'inbox'): ?>
                        <a href="compose.php">เขียนจดหมายฉบับแรก</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="message-list">
                    <?php foreach ($messages as $message): ?>
                        <a class="message-row <?= $view === 'inbox' && $message['read_at'] === null ? 'unread' : '' ?>"
                           href="mail_view.php?id=<?= (int) $message['message_id'] ?>">
                            <span class="message-indicator" aria-hidden="true"></span>
                            <span class="message-main">
                                <span class="message-person">
                                    <?= $view === 'inbox'
                                        ? escape($message['sender_full_name'] . ' (@' . $message['sender_name'] . ')')
                                        : ($message['delivery_type'] === 'all'
                                            ? 'ถึง: ผู้ใช้ทุกคน'
                                            : ($message['delivery_type'] === 'department'
                                                ? 'ถึงแผนก: ' . escape((string) $message['department'])
                                                : 'ถึง: ' . escape((string) $message['recipient_names']))) ?>
                                </span>
                                <span class="urgency-badge urgency-<?= escape($message['urgency']) ?>">
                                    <?= [
                                        'normal' => 'ปกติ',
                                        'urgent' => 'ด่วน',
                                        'very_urgent' => 'ด่วนมาก',
                                        'most_urgent' => 'ด่วนที่สุด',
                                    ][$message['urgency']] ?? 'ปกติ' ?>
                                </span>
                                <strong><?= escape($message['subject']) ?></strong>
                                <span class="message-preview"><?= escape(mb_strimwidth($message['body'], 0, 150, '…', 'UTF-8')) ?></span>
                            </span>
                            <time datetime="<?= escape($message['sent_at']) ?>"><?= escape(date('d/m/Y H:i', strtotime($message['sent_at']))) ?></time>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
