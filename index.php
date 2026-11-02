<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mailbox</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <main class="card">
        <div class="brand-mark" aria-hidden="true">M</div>
        <p class="eyebrow">MAILBOX</p>
        <h1>ยินดีต้อนรับ</h1>
        <p class="subtitle"><?= escape((string) $_SESSION['full_name']) ?></p>
        <div class="account-info">
            <span>ชื่อผู้ใช้</span>
            <strong><?= escape((string) $_SESSION['user_name']) ?></strong>
            <span>สิทธิ์</span>
            <strong><?= escape((string) $_SESSION['role']) ?></strong>
        </div>
        <a class="button button-secondary" href="logout.php">ออกจากระบบ</a>
    </main>
</body>
</html>
