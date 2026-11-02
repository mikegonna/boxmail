<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('ไม่มีสิทธิ์เข้าถึงหน้านี้');
}

$error = '';
$message = '';
$approvedResetLink = (string) ($_SESSION['approved_reset_link'] ?? '');
unset($_SESSION['approved_reset_link']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
    $action = (string) ($_POST['action'] ?? '');

    if (!csrf_is_valid()) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } elseif ($requestId === false || $requestId === null || !in_array($action, ['approve', 'deny'], true)) {
        $error = 'ข้อมูลคำขอไม่ถูกต้อง';
    } else {
        try {
            $connection = db();
            $connection->beginTransaction();

            $find = $connection->prepare(
                "SELECT request_id, user_id
                 FROM password_reset_requests
                 WHERE request_id = :request_id AND status = 'pending'
                 FOR UPDATE"
            );
            $find->execute(['request_id' => $requestId]);
            $pendingRequest = $find->fetch();

            if (!$pendingRequest) {
                $connection->rollBack();
                $error = 'คำขอนี้ได้รับการจัดการแล้ว หรือไม่พบคำขอ';
            } elseif ($action === 'approve') {
                $token = bin2hex(random_bytes(32));
                $resetLink = app_url('reset_password.php?token=' . rawurlencode($token));
                $invalidate = $connection->prepare(
                    "UPDATE password_reset_requests
                     SET status = 'expired', token_hash = NULL
                     WHERE user_id = :user_id AND status = 'approved'"
                );
                $invalidate->execute(['user_id' => $pendingRequest['user_id']]);
                $update = $connection->prepare(
                    "UPDATE password_reset_requests
                     SET status = 'approved',
                         token_hash = :token_hash,
                         reviewed_at = CURRENT_TIMESTAMP,
                         reviewed_by = :reviewed_by,
                         expires_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE)
                     WHERE request_id = :request_id AND status = 'pending'"
                );
                $update->execute([
                    'token_hash' => hash('sha256', $token),
                    'reviewed_by' => $_SESSION['user_id'],
                    'request_id' => $requestId,
                ]);
                $connection->commit();

                $_SESSION['approved_reset_link'] = $resetLink;
                header('Location: admin_reset_requests.php');
                exit;
            } else {
                $update = $connection->prepare(
                    "UPDATE password_reset_requests
                     SET status = 'denied', reviewed_at = CURRENT_TIMESTAMP, reviewed_by = :reviewed_by
                     WHERE request_id = :request_id AND status = 'pending'"
                );
                $update->execute([
                    'reviewed_by' => $_SESSION['user_id'],
                    'request_id' => $requestId,
                ]);
                $connection->commit();
                $message = 'ปฏิเสธคำขอเรียบร้อยแล้ว';
            }
        } catch (Throwable $exception) {
            if (isset($connection) && $connection instanceof PDO && $connection->inTransaction()) {
                $connection->rollBack();
            }

            error_log('Boxmail admin reset request action failed: ' . $exception->getMessage());
            $error = 'ไม่สามารถจัดการคำขอได้ในขณะนี้ กรุณาลองใหม่ภายหลัง';
        }
    }
}

try {
    $requests = db()->query(
        "SELECT r.request_id, r.status, r.requested_at, r.reviewed_at, r.expires_at,
                u.user_name, u.full_name, u.phones
         FROM password_reset_requests AS r
         JOIN users AS u ON u.user_id = r.user_id
         ORDER BY (r.status = 'pending') DESC, r.requested_at DESC
         LIMIT 100"
    )->fetchAll();
} catch (PDOException $exception) {
    error_log('Boxmail admin reset request list failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('ไม่สามารถโหลดรายการคำขอได้ กรุณาตรวจสอบฐานข้อมูล');
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
    <meta name="referrer" content="no-referrer">
    <title>คำขอรีเซ็ตรหัสผ่าน | Mailbox</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <main class="card card-wide">
        <div class="brand-mark" aria-hidden="true">M</div>
        <p class="eyebrow">MAILBOX ADMIN</p>
        <h1>คำขอรีเซ็ตรหัสผ่าน</h1>
        <p class="subtitle">ตรวจสอบชื่อผู้ใช้และหมายเลขโทรศัพท์ก่อนอนุมัติ</p>

        <?php if ($error !== ''): ?>
            <p class="notice notice-error" role="alert"><?= escape($error) ?></p>
        <?php elseif ($message !== ''): ?>
            <p class="notice notice-success" role="status"><?= escape($message) ?></p>
        <?php endif; ?>

        <?php if ($approvedResetLink !== ''): ?>
            <section class="reset-link-box" aria-labelledby="reset-link-heading">
                <h2 id="reset-link-heading">อนุมัติแล้ว — ส่งลิงก์นี้ให้ผู้ใช้</h2>
                <p>ลิงก์ใช้ได้ครั้งเดียวและหมดอายุใน 30 นาที กรุณาส่งให้ผู้ใช้ผ่านช่องทางที่ยืนยันตัวตนแล้ว</p>
                <input type="text" value="<?= escape($approvedResetLink) ?>" readonly aria-label="ลิงก์รีเซ็ตรหัสผ่าน">
            </section>
        <?php endif; ?>

        <?php if ($requests === []): ?>
            <p class="empty-state">ยังไม่มีคำขอรีเซ็ตรหัสผ่าน</p>
        <?php else: ?>
            <div class="request-list">
                <?php foreach ($requests as $request): ?>
                    <section class="request-item">
                        <div class="request-details">
                            <strong><?= escape($request['full_name']) ?> (<?= escape($request['user_name']) ?>)</strong>
                            <span>โทรศัพท์: <?= escape($request['phones']) ?></span>
                            <span>ส่งคำขอ: <?= escape($request['requested_at']) ?></span>
                            <span>สถานะ: <?= escape($request['status']) ?></span>
                            <?php if ($request['expires_at'] !== null): ?>
                                <span>ลิงก์หมดอายุ: <?= escape($request['expires_at']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($request['status'] === 'pending'): ?>
                            <div class="request-actions">
                                <form method="post" action="admin_reset_requests.php">
                                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button class="button" type="submit">ยืนยันและอนุมัติ</button>
                                </form>
                                <form method="post" action="admin_reset_requests.php">
                                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>">
                                    <input type="hidden" name="action" value="deny">
                                    <button class="button button-danger" type="submit">ปฏิเสธ</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <p class="form-footer"><a href="index.php">กลับหน้าหลัก</a></p>
    </main>
</body>
</html>
