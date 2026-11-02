<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $userName = trim((string) ($_POST['user_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        try {
            $statement = db()->prepare(
                'SELECT user_id, user_name, user_pass, full_name, role
                 FROM users
                 WHERE user_name = :user_name
                 LIMIT 1'
            );
            $statement->execute(['user_name' => $userName]);
            $user = $statement->fetch();

            if ($user && password_verify($password, $user['user_pass'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['user_id'];
                $_SESSION['user_name'] = $user['user_name'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                unset($_SESSION['csrf_token']);

                header('Location: index.php');
                exit;
            }

            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        } catch (PDOException $exception) {
            error_log('Boxmail login query failed: ' . $exception->getMessage());
            $error = 'ไม่สามารถเข้าสู่ระบบได้ในขณะนี้ กรุณาลองใหม่ภายหลัง';
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
    <title>เข้าสู่ระบบ | Mailbox</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <main class="card">
        <div class="brand-mark" aria-hidden="true">M</div>
        <p class="eyebrow">MAILBOX</p>
        <h1>เข้าสู่ระบบ</h1>
        <p class="subtitle">เข้าสู่กล่องจดหมายของคุณ</p>

        <?php if (isset($_GET['registered'])): ?>
            <p class="notice notice-success" role="status">สมัครสมาชิกสำเร็จ เข้าสู่ระบบได้เลย</p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <p class="notice notice-error" role="alert"><?= escape($error) ?></p>
        <?php endif; ?>

        <form method="post" action="login.php">
            <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
            <label for="user_name">ชื่อผู้ใช้</label>
            <input
                id="user_name"
                name="user_name"
                type="text"
                autocomplete="username"
                maxlength="255"
                value="<?= escape((string) ($_POST['user_name'] ?? '')) ?>"
                required
                autofocus
            >

            <label for="password">รหัสผ่าน</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                maxlength="8"
                required
            >

            <button class="button" type="submit">เข้าสู่ระบบ</button>
        </form>

        <p class="form-footer"><a href="password_reset_request.php">ลืมรหัสผ่าน?</a></p>
        <p class="form-footer">ยังไม่มีบัญชี? <a href="register.php">สมัครสมาชิก</a></p>
    </main>
</body>
</html>
