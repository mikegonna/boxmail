<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$error = '';
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userName = trim((string) ($_POST['user_name'] ?? ''));
    $phone = trim((string) ($_POST['phones'] ?? ''));

    if (!csrf_is_valid()) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } elseif ($userName === '' || $phone === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้และหมายเลขโทรศัพท์';
    } else {
        try {
            $connection = db();
            $lookup = $connection->prepare(
                'SELECT user_id
                 FROM users
                 WHERE user_name = :user_name AND phones = :phones
                 LIMIT 1'
            );
            $lookup->execute(['user_name' => $userName, 'phones' => $phone]);
            $userId = $lookup->fetchColumn();

            if ($userId !== false) {
                $insert = $connection->prepare(
                    "INSERT INTO password_reset_requests (user_id, status)
                     VALUES (:user_id, 'pending')"
                );
                try {
                    $insert->execute(['user_id' => $userId]);
                } catch (PDOException $exception) {
                    if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                        throw $exception;
                    }
                }
            }

            $submitted = true;
        } catch (PDOException $exception) {
            error_log('Boxmail reset request failed: ' . $exception->getMessage());
            $error = 'ไม่สามารถส่งคำขอได้ในขณะนี้ กรุณาลองใหม่ภายหลัง';
        } catch (RuntimeException $exception) {
            $error = 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบการตั้งค่า';
        }
    }
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ขอรีเซ็ตรหัสผ่าน | Mailbox</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <main class="card">
        <div class="brand-mark" aria-hidden="true">M</div>
        <p class="eyebrow">MAILBOX</p>
        <h1>ลืมรหัสผ่าน</h1>
        <?php if ($submitted): ?>
            <p class="notice notice-success" role="status">
                หากข้อมูลตรงกับบัญชีที่มีอยู่ ระบบจะส่งคำขอให้ผู้ดูแลตรวจสอบ
            </p>
            <p class="subtitle">เมื่อผู้ดูแลอนุมัติแล้ว จะได้รับลิงก์สำหรับตั้งรหัสผ่านใหม่</p>
        <?php else: ?>
            <p class="subtitle">กรอกชื่อผู้ใช้และเบอร์โทรศัพท์ที่ลงทะเบียน เพื่อส่งคำขอให้ผู้ดูแลตรวจสอบ</p>

            <?php if ($error !== ''): ?>
                <p class="notice notice-error" role="alert"><?= escape($error) ?></p>
            <?php endif; ?>

            <form method="post" action="password_reset_request.php">
                <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                <label for="user_name">ชื่อผู้ใช้</label>
                <input id="user_name" name="user_name" type="text" autocomplete="username" maxlength="255" required autofocus>

                <label for="phones">หมายเลขโทรศัพท์</label>
                <input id="phones" name="phones" type="tel" autocomplete="tel" maxlength="255" required>

                <button class="button" type="submit">ส่งคำขอ</button>
            </form>
        <?php endif; ?>
        <p class="form-footer"><a href="login.php">กลับไปหน้าเข้าสู่ระบบ</a></p>
    </main>
</body>
</html>
