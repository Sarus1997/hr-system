<?php
$root = dirname(__DIR__);
require_once $root . '/includes/auth.php';
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'กรุณาเข้าสู่ระบบ']);
    exit;
}
$accountStmt = $pdo->prepare(
    "SELECT 1
     FROM users u
     JOIN employees e ON e.id = u.employee_id
     WHERE u.id = ? AND u.status = 1 AND e.status = 1
       AND e.employment_status IN ('active', 'probation')"
);
$accountStmt->execute([$_SESSION['user_id']]);
if (!$accountStmt->fetchColumn()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'กรุณาเข้าสู่ระบบ']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$events = [];
foreach ($pdo->query("SELECT holiday_date, holiday_name FROM holidays WHERE status = 1") as $h) {
    $events[] = ['title' => 'วันหยุด: ' . $h['holiday_name'], 'start' => $h['holiday_date'], 'color' => '#ef4444'];
}
$employeeId = (string)$_SESSION['employee_id'];
$canViewAll = canViewAllEmployeeData();
if ($canViewAll) {
    $leaveStmt = $pdo->query("SELECT lr.start_date, lr.end_date, e.first_name, lt.name FROM leave_requests lr JOIN employees e ON lr.employee_id=e.id JOIN leave_types lt ON lr.leave_type_id=lt.id WHERE lr.status='approved'");
} else {
    $leaveStmt = $pdo->prepare("SELECT lr.start_date, lr.end_date, e.first_name, lt.name
        FROM leave_requests lr
        JOIN employees e ON lr.employee_id = e.id
        JOIN leave_types lt ON lr.leave_type_id = lt.id
        WHERE lr.status = 'approved' AND (e.department_id = ? OR lr.employee_id = ?)");
    $leaveStmt->execute([getEmployeeDepartmentId($pdo, $employeeId), $employeeId]);
}
foreach ($leaveStmt as $lv) {
    $events[] = ['title' => $lv['first_name'] . ' (' . $lv['name'] . ')', 'start' => $lv['start_date'], 'end' => $lv['end_date'], 'color' => '#4f46e5'];
}
echo json_encode($events, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
