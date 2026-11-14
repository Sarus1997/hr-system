<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$attachmentId       = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$legacyRequestId    = filter_var($_GET['legacy_request_id'] ?? null, FILTER_VALIDATE_INT);
$isAttachmentRecord = $attachmentId !== false && $attachmentId !== null && $attachmentId > 0;
$isLegacyAttachment = $legacyRequestId !== false && $legacyRequestId !== null && $legacyRequestId > 0;
if ($isAttachmentRecord === $isLegacyAttachment) {
    http_response_code(400);
    exit('รหัสไฟล์แนบไม่ถูกต้อง');
}

$storageRoot = realpath(ROOT_PATH . '/uploads');
if ($isAttachmentRecord) {
    $attachmentStmt = $pdo->prepare(
        'SELECT la.file_name, la.cloud_object_key, la.leave_request_id, lr.employee_id
         FROM leave_attachments la
         JOIN leave_requests lr ON lr.id = la.leave_request_id
         WHERE la.id = ?'
    );
    $attachmentStmt->execute([$attachmentId]);
    $attachment = $attachmentStmt->fetch();
    if (! $attachment) {
        http_response_code(404);
        exit('ไม่พบไฟล์แนบ');
    }

    $objectKey           = $attachment['cloud_object_key'];
    $attachmentRequestId = (int) $attachment['leave_request_id'];
    if (! is_string($objectKey) ||
        ! preg_match('#^leave_attachments/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$#D', $objectKey) ||
        strpos($objectKey, '..') !== false) {
        http_response_code(404);
        exit('ไม่พบไฟล์แนบ');
    }
    $expectedRoot = realpath(ROOT_PATH . '/uploads/leave_attachments');
    $filePath     = realpath(ROOT_PATH . '/uploads/' . str_replace('/', DIRECTORY_SEPARATOR, $objectKey));
    $downloadName = $attachment['file_name'];
} else {
    $legacyStmt = $pdo->prepare('SELECT employee_id, attachment_url FROM leave_requests WHERE id = ?');
    $legacyStmt->execute([$legacyRequestId]);
    $legacyAttachment = $legacyStmt->fetch();
    if (! $legacyAttachment || ! is_string($legacyAttachment['attachment_url']) ||
        ! str_starts_with($legacyAttachment['attachment_url'], BASE_URL . '/uploads/')) {
        http_response_code(404);
        exit('ไม่พบไฟล์แนบ');
    }

    $relativePath        = substr($legacyAttachment['attachment_url'], strlen(BASE_URL . '/uploads/'));
    $filePath            = realpath(ROOT_PATH . '/uploads/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    $expectedRoot        = $storageRoot;
    $attachment          = ['employee_id' => $legacyAttachment['employee_id']];
    $attachmentRequestId = $legacyRequestId;
    $downloadName        = $filePath === false ? '' : basename($filePath);
}

$myEmployeeId       = (string) ($_SESSION['employee_id'] ?? '');
$visibleEmployees   = getVisibleEmployees($pdo, $myEmployeeId);
$visibleEmployeeIds = array_map('strval', array_column($visibleEmployees, 'id'));
$canViewAttachment  = in_array((string) $attachment['employee_id'], $visibleEmployeeIds, true);
if (! $canViewAttachment) {
    $approvalStmt = $pdo->prepare(
        'SELECT 1
         FROM leave_approvals la
         JOIN leave_requests lr ON lr.id = la.leave_request_id
         WHERE la.leave_request_id = ? AND la.approver_id = ?
           AND la.step_order <= lr.current_step
         LIMIT 1'
    );
    $approvalStmt->execute([$attachmentRequestId, $myEmployeeId]);
    $canViewAttachment = (bool) $approvalStmt->fetchColumn();
}
if (! $canViewAttachment) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ดูไฟล์แนบนี้');
}

$rootPrefix = $expectedRoot === false ? '' : $expectedRoot . DIRECTORY_SEPARATOR;
if ($storageRoot === false || $expectedRoot === false || $filePath === false ||
    strpos($filePath, $rootPrefix) !== 0 ||
    ! is_file($filePath) || ! is_readable($filePath)) {
    http_response_code(404);
    exit('ไม่พบไฟล์แนบ');
}

$mimeType         = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
$allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'];
if (! is_string($mimeType) || ! in_array($mimeType, $allowedMimeTypes, true)) {
    http_response_code(404);
    exit('ไม่พบไฟล์แนบ');
}

if (! is_string($downloadName) || $downloadName === '') {
    $downloadName = 'leave-attachment';
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: attachment; filename="leave-attachment"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($filePath);
exit;
