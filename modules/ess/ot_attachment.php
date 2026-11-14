<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once ROOT_PATH . '/includes/private_file.php';
requireLogin();

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($requestId === false || $requestId === null || $requestId < 1) {
    http_response_code(400);
    exit('รหัสคำขอไม่ถูกต้อง');
}

$stmt = $pdo->prepare(
    'SELECT id, employee_id, attachment_url, status, current_step
     FROM ot_requests
     WHERE id = ?'
);
$stmt->execute([$requestId]);
$request = $stmt->fetch();
if (!$request || !is_string($request['attachment_url']) ||
    !str_starts_with($request['attachment_url'], BASE_URL . '/uploads/ot_attachments/')) {
    http_response_code(404);
    exit('ไม่พบไฟล์แนบ');
}

$myEmployeeId = (string)($_SESSION['employee_id'] ?? '');
$visibleIds = array_map('strval', array_column(getVisibleEmployees($pdo, $myEmployeeId), 'id'));
$canView = in_array((string)$request['employee_id'], $visibleIds, true);
if (!$canView && $request['status'] === 'pending') {
    $approverStmt = $pdo->prepare(
        "SELECT 1 FROM ot_approvals
         WHERE ot_request_id = ? AND approver_id = ? AND step_order = ?
           AND status = 'pending'
         LIMIT 1"
    );
    $approverStmt->execute([$requestId, $myEmployeeId, $request['current_step']]);
    $canView = (bool)$approverStmt->fetchColumn();
}
if (!$canView) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ดูไฟล์แนบนี้');
}

$objectKey = substr($request['attachment_url'], strlen(BASE_URL . '/uploads/'));
sendPrivateUpload($objectKey, basename($objectKey), 'ot_attachments');
