<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$success = false;
$validToken = false;

if (preg_match('/\A[a-f0-9]{64}\z/', $token) === 1) {
    try {
        $check = db()->prepare(
            "SELECT request_id
             FROM password_reset_requests
             WHERE token_hash = :token_hash
               AND status = 'approved'
               AND expires_at > CURRENT_TIMESTAMP
             LIMIT 1"
        );
        $check->execute(['token_hash' => hash('sha256', $token)]);
        $validToken = $check->fetchColumn() !== false;
    } catch (PDOException $exception) {
        error_log('Boxmail reset token lookup failed: ' . $exception->getMessage());
        $error = 'ไม่สามารถตรวจสอบลิงก์ได้ในขณะนี้ กรุณาลองใหม่ภายหลัง';
    } catch (RuntimeException $exception) {
        $error = 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบการตั้งค่า';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!csrf_is_valid()) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } elseif (!$validToken) {
        $error = 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้อง หมดอายุ หรือถูกใช้ไปแล้ว';
    } elseif (mb_strlen($password, 'UTF-8') < 4 || strlen($password) > 8) {
        $error = 'รหัสผ่านต้องมีความยาว 4 ถึง 8 ตัวอักษร';
    } elseif ($password !== $confirmPassword) {
        $error = 'รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน';
    } else {
        try {
            $connection = db();
            $connection->beginTransaction();
            $lock = $connection->prepare(
                "SELECT request_id, user_id
                 FROM password_reset_requests
                 WHERE token_hash = :token_hash
                   AND status = 'approved'
                   AND expires_at > CURRENT_TIMESTAMP
                 FOR UPDATE"
            );
            $lock->execute(['token_hash' => hash('sha256', $token)]);
            $request = $lock->fetch();

            if (!$request) {
                $connection->rollBack();
                $validToken = false;
                $error = 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้อง หมดอายุ หรือถูกใช้ไปแล้ว';
            } else {
                $updateUser = $connection->prepare(
                    'UPDATE users SET user_pass = :user_pass WHERE user_id = :user_id'
                );
                $updateUser->execute([
                    'user_pass' => password_hash($password, PASSWORD_DEFAULT),
                    'user_id' => $request['user_id'],
                ]);

                $updateRequest = $connection->prepare(
                    "UPDATE password_reset_requests
                     SET status = 'used', token_hash = NULL
                     WHERE request_id = :request_id"
                );
                $updateRequest->execute(['request_id' => $request['request_id']]);
                $connection->commit();
                $success = true;
                $validToken = false;
            }
        } catch (Throwable $exception) {
            if (isset($connection) && $connection instanceof PDO && $connection->inTransaction()) {
                $connection->rollBack();
            }

            error_log('Boxmail password reset failed: ' . $exception->getMessage());
            $error = 'ไม่สามารถเปลี่ยนรหัสผ่านได้ในขณะนี้ กรุณาลองใหม่ภายหลัง';
        }
    }
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>ตั้งรหัสผ่านใหม่ | Mailbox</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <main class="card">
        <div class="brand-mark" aria-hidden="true">M</div>
        <p class="eyebrow">MAILBOX</p>
        <h1>ตั้งรหัสผ่านใหม่</h1>

        <?php if ($success): ?>
            <p class="notice notice-success" role="status">เปลี่ยนรหัสผ่านเรียบร้อยแล้ว</p>
            <a class="button" href="login.php">ไปหน้าเข้าสู่ระบบ</a>
        <?php elseif ($error !== ''): ?>
            <p class="notice notice-error" role="alert"><?= escape($error) ?></p>
            <p class="form-footer"><a href="password_reset_request.php">ส่งคำขอใหม่</a></p>
        <?php elseif ($validToken): ?>
            <p class="subtitle">กำหนดรหัสผ่านใหม่สำหรับบัญชีของคุณ</p>
            <form method="post" action="reset_password.php">
                <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                <input type="hidden" name="token" value="<?= escape($token) ?>">
                <label for="password">รหัสผ่านใหม่</label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="4" maxlength="8" required autofocus>

                <label for="confirm_password">ยืนยันรหัสผ่านใหม่</label>
                <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="4" maxlength="8" required>
                <button class="button" type="submit">บันทึกรหัสผ่านใหม่</button>
            </form>
        <?php else: ?>
            <p class="notice notice-error" role="alert">ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้อง หมดอายุ หรือถูกใช้ไปแล้ว</p>
            <p class="form-footer"><a href="password_reset_request.php">ส่งคำขอใหม่</a></p>
        <?php endif; ?>
    </main>
</body>
</html>
