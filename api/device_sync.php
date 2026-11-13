<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

$token = str_replace('Bearer ', '', getallheaders()['Authorization'] ?? '');
$input = json_decode(file_get_contents('php://input'), true);
if (! $input || empty($input['device_sn']) || empty($input['emp_code']) || empty($input['scan_time'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload']);exit;
}

$dev = $pdo->prepare("SELECT id, device_type FROM attendance_devices WHERE device_sn=? AND api_token=? AND status=1");
$dev->execute([$input['device_sn'], $token]);
if (! $device = $dev->fetch()) {http_response_code(401);
    echo json_encode(['status' => 'error']);exit;}

$emp = $pdo->prepare("SELECT id FROM employees WHERE emp_code=? AND status=1 AND employment_status IN ('active', 'probation')");
$emp->execute([$input['emp_code']]);
if (! $e = $emp->fetch()) {http_response_code(404);
    echo json_encode(['status' => 'error']);exit;}

$scanType = $input['scan_type'] ?? 'check_in';
$eventKey = hash('sha256', serialize([$e['id'], $device['id'], $input['scan_time'], $scanType]));
$existingScan = $pdo->prepare('SELECT id FROM attendance_logs WHERE employee_id = ? AND device_id = ? AND scan_time = ? AND scan_type = ? LIMIT 1');
$existingScan->execute([$e['id'], $device['id'], $input['scan_time'], $scanType]);
if ($existingScan->fetchColumn() === false) {
    $pdo->prepare("INSERT INTO attendance_logs (employee_id, device_id, scan_time, scan_type, event_key, verify_mode, snapshot_url) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE event_key = VALUES(event_key)")
        ->execute([$e['id'], $device['id'], $input['scan_time'], $scanType, $eventKey, $device['device_type'], $input['snapshot_url'] ?? null]);
}
echo json_encode(['status' => 'success']);
