<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once ROOT_PATH . '/includes/private_file.php';
requireLogin();

$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($documentId === false || $documentId === null || $documentId < 1) {
    http_response_code(400);
    exit('รหัสเอกสารไม่ถูกต้อง');
}

$stmt = $pdo->prepare(
    'SELECT employee_id, file_name, cloud_object_key
     FROM cloud_documents
     WHERE id = ? AND status = 1'
);
$stmt->execute([$documentId]);
$document = $stmt->fetch();
if (!$document) {
    http_response_code(404);
    exit('ไม่พบเอกสาร');
}

$isOwner = (string)$document['employee_id'] === (string)($_SESSION['employee_id'] ?? '');
if (!$isOwner && !hasRole(['admin', 'hr'])) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ดูเอกสารนี้');
}

sendPrivateUpload((string)$document['cloud_object_key'], (string)$document['file_name'], 'employee_docs');
