<?php
    declare(strict_types=1);

    require __DIR__ . '/config.php';

    if (isset($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }

    $error = '';
    $userName = '';
    $fullName = '';
    $phone = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $userName = trim((string) ($_POST['user_name'] ?? ''));
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $phone = trim((string) ($_POST['phones'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (!csrf_is_valid()) {
            $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
        } elseif ($userName === '' || mb_strlen($userName, 'UTF-8') > 255) {
            $error = 'กรุณากรอกชื่อผู้ใช้ไม่เกิน 255 ตัวอักษร';
        } elseif ($fullName === '' || mb_strlen($fullName, 'UTF-8') > 255) {
            $error = 'กรุณากรอกชื่อ-นามสกุลไม่เกิน 255 ตัวอักษร';
        } elseif (mb_strlen($phone, 'UTF-8') > 255) {
            $error = 'หมายเลขโทรศัพท์ต้องไม่เกิน 255 ตัวอักษร';
        } elseif (mb_strlen($password, 'UTF-8') < 8 || strlen($password) > 72) {
            $error = 'รหัสผ่านต้องมีความยาว 8 ถึง 72 ไบต์';
        } elseif ($password !== $confirmPassword) {
            $error = 'รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน';
        } else {
            try {
                $statement = db()->prepare(
                    'INSERT INTO users (user_name, user_pass, full_name, phones, role)
                    VALUES (:user_name, :user_pass, :full_name, :phones, :role)'
                );
                $statement->execute([
                    'user_name' => $userName,
                    'user_pass' => password_hash($password, PASSWORD_DEFAULT),
                    'full_name' => $fullName,
                    'phones' => $phone,
                    'role' => 'user',
                ]);

                header('Location: login.php?registered=1');
                exit;
            } catch (PDOException $exception) {
                if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                    $error = 'ชื่อผู้ใช้นี้มีผู้ใช้แล้ว กรุณาเลือกชื่ออื่น';
                } else {
                    error_log('Boxmail registration query failed: ' . $exception->getMessage());
                    $error = 'ไม่สามารถสมัครสมาชิกได้ในขณะนี้ กรุณาลองใหม่ภายหลัง';
                }
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
    <title>สมัครสมาชิก | Mailbox</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <main class="card">
        <div class="brand-mark" aria-hidden="true">M</div>
        <p class="eyebrow">MAILBOX</p>
        <h1>สร้างบัญชี</h1>
        <p class="subtitle">สมัครสมาชิกเพื่อเริ่มใช้งาน</p>

        <?php if ($error !== ''): ?>
            <p class="notice notice-error" role="alert"><?= escape($error) ?></p>
        <?php endif; ?>

        <form method="post" action="register.php">
            <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">

            <label for="user_name">ชื่อผู้ใช้ <span class="optional" style="color: #dc3545;">*</span></label>
            <input
                id="user_name"
                name="user_name"
                type="text"
                autocomplete="username"
                maxlength="255"
                value="<?= escape($userName) ?>"
                required
            >

            <label for="password">รหัสผ่าน <span class="optional" style="color: #dc3545;">*</span></label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="new-password"
                minlength="4"
                maxlength="8"
                required
            >

            <label for="confirm_password">ยืนยันรหัสผ่าน <span class="optional" style="color: #dc3545;">*</span></label>
            <input
                id="confirm_password"
                name="confirm_password"
                type="password"
                autocomplete="new-password"
                minlength="4"
                maxlength="8"
                required
            >

            <label for="full_name">ชื่อ-นามสกุล <span class="optional" style="color: #dc3545;">*</span></label>
            <input
                id="full_name"
                name="full_name"
                type="text"
                autocomplete="name"
                maxlength="255"
                value="<?= escape($fullName) ?>"
                required
                autofocus
            >

            <label for="phones">หมายเลขโทรศัพท์ <span class="optional">(ไม่บังคับ)</span></label>
            <input
                id="phones"
                name="phones"
                type="tel"
                autocomplete="tel"
                maxlength="10"
                value="<?= escape($phone) ?>"
            >

            <button class="button" type="submit">สมัครสมาชิก</button>
        </form>

        <p class="form-footer">มีบัญชีอยู่แล้ว? <a href="login.php">เข้าสู่ระบบ</a></p>
    </main>
</body>
</html>
