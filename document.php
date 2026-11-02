<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์เข้าถึงเอกสารนี้');
}

$documentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($documentId === false || $documentId === null || $documentId < 1) {
    http_response_code(404);
    exit('ไม่พบเอกสารที่ต้องการ');
}

try {
    $statement = db()->prepare(
        'SELECT d.original_name, d.storage_name, d.file_size
         FROM mail_documents AS d
         JOIN mail_messages AS m ON m.message_id = d.message_id
         WHERE d.document_id = :document_id
           AND (
               m.sender_id = :sender_id
               OR EXISTS (
                   SELECT 1 FROM mail_recipients AS allowed
                   WHERE allowed.message_id = m.message_id AND allowed.recipient_id = :recipient_id
               )
           )
         LIMIT 1'
    );
    $userId = (int) $_SESSION['user_id'];
    $statement->execute([
        'document_id' => $documentId,
        'sender_id' => $userId,
        'recipient_id' => $userId,
    ]);
    $document = $statement->fetch();
    if (!$document) {
        http_response_code(404);
        exit('ไม่พบเอกสารที่ต้องการ');
    }

    if (preg_match('/\A[a-f0-9]{64}\z/', $document['storage_name']) !== 1) {
        throw new RuntimeException('Invalid document storage identifier.');
    }
    $path = private_document_directory() . DIRECTORY_SEPARATOR . $document['storage_name'];
    if (!is_file($path)) {
        error_log('Boxmail document file missing for document ID ' . $documentId);
        http_response_code(404);
        exit('ไม่พบไฟล์เอกสาร');
    }

    $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $document['original_name']) ?: 'document.pdf';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $safeName) . '"; filename*=UTF-8\'\'' . rawurlencode($document['original_name']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Security-Policy: sandbox');
    header('Referrer-Policy: no-referrer');
    readfile($path);
} catch (PDOException $exception) {
    error_log('Boxmail document lookup failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('ไม่สามารถเปิดเอกสารได้ กรุณาลองใหม่ภายหลัง');
} catch (RuntimeException $exception) {
    error_log('Boxmail document read failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('ไม่สามารถเปิดเอกสารได้ กรุณาลองใหม่ภายหลัง');
}
