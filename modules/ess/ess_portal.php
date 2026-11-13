<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once ROOT_PATH . '/includes/leave_service.php';
require_once ROOT_PATH . '/includes/ot_calculation.php';
require_once ROOT_PATH . '/includes/approval_workflow.php';
requireLogin();

$empId = (string)($_SESSION['employee_id'] ?? '');
$notice = $_SESSION['ess_notice'] ?? '';
unset($_SESSION['ess_notice']);
$error = '';

$clock = $pdo->query('SELECT NOW() AS current_datetime, CURDATE() AS today')->fetch();
$now = new DateTimeImmutable($clock['current_datetime']);
$today = $clock['today'];
$currentDateTime = $now->format('Y-m-d H:i:s');
$dayStart = $today . ' 00:00:00';
$tomorrow = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

$attendanceStmt = $pdo->prepare("
    SELECT MIN(CASE WHEN scan_type = 'check_in' THEN scan_time END) AS check_in_at,
           MAX(CASE WHEN scan_type = 'check_out' THEN scan_time END) AS check_out_at
    FROM attendance_logs
    WHERE employee_id = ? AND scan_time >= ? AND scan_time < ?
");
$attendanceStmt->execute([$empId, $dayStart, $tomorrow]);
$attendance = $attendanceStmt->fetch();

$recentScansStmt = $pdo->prepare("
    SELECT scan_type, scan_time
    FROM attendance_logs
    WHERE employee_id = ? AND scan_type IN ('check_in', 'check_out')
      AND scan_time >= DATE_SUB(?, INTERVAL 36 HOUR)
    ORDER BY scan_time DESC, id DESC
    LIMIT 2
");
$recentScansStmt->execute([$empId, $currentDateTime]);
$recentScans = $recentScansStmt->fetchAll();
$latestScan = $recentScans[0] ?? null;
if ($latestScan && $latestScan['scan_type'] === 'check_in') {
    $attendance['check_in_at'] = $latestScan['scan_time'];
    $attendance['check_out_at'] = null;
} elseif ($latestScan && $latestScan['scan_type'] === 'check_out' &&
    date('Y-m-d', strtotime($latestScan['scan_time'])) === $today &&
    isset($recentScans[1]) && $recentScans[1]['scan_type'] === 'check_in') {
    $attendance['check_in_at'] = $recentScans[1]['scan_time'];
    $attendance['check_out_at'] = $latestScan['scan_time'];
}

$shiftReferenceDate = $attendance['check_in_at'] !== null && $attendance['check_out_at'] === null
    ? date('Y-m-d', strtotime($attendance['check_in_at']))
    : $today;
$shiftStmt = $pdo->prepare("
    SELECT s.start_time, s.end_time
    FROM employee_shifts es
    JOIN shifts s ON s.id = es.shift_id
    WHERE es.employee_id = ? AND es.status = 1 AND s.status = 1 AND es.effective_date <= ?
    ORDER BY es.effective_date DESC, es.id DESC
    LIMIT 1
");
$shiftStmt->execute([$empId, $shiftReferenceDate]);
$shift = $shiftStmt->fetch();
$shiftEndAt = null;
if ($shift) {
    $shiftStartAt = new DateTimeImmutable($shiftReferenceDate . ' ' . $shift['start_time']);
    $shiftEndAt = new DateTimeImmutable($shiftReferenceDate . ' ' . $shift['end_time']);
    if ($shiftEndAt <= $shiftStartAt) {
        $shiftEndAt = $shiftEndAt->modify('+1 day');
    }
}

$activeLeaveStmt = $pdo->prepare("
    SELECT end_date
    FROM leave_requests
    WHERE employee_id = ? AND status = 'approved'
      AND start_date <= ? AND end_date > ?
    ORDER BY end_date DESC
    LIMIT 1
");
$currentDateTime = $now->format('Y-m-d H:i:s');
$activeLeaveStmt->execute([$empId, $currentDateTime, $currentDateTime]);
$activeLeave = $activeLeaveStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'checkin') {
        $scanType = $_POST['scan_type'] ?? '';
        $latitude = filter_var($_POST['lat'] ?? '', FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($_POST['lng'] ?? '', FILTER_VALIDATE_FLOAT);
        $hasCoordinates = $latitude !== false && $longitude !== false;
        $hasAnyCoordinates = $latitude !== false || $longitude !== false;

        if (!is_string($scanType) || !in_array($scanType, ['check_in', 'check_out'], true) ||
            ($hasAnyCoordinates && (!$hasCoordinates || abs($latitude) > 90 || abs($longitude) > 180))) {
            $error = 'ข้อมูลการลงเวลาไม่ถูกต้อง กรุณาลองใหม่';
        } elseif ($scanType === 'check_in') {
            if ($attendance['check_out_at'] !== null) {
                $error = 'วันนี้ลงเวลาเข้า-ออกครบแล้ว';
            } elseif ($attendance['check_in_at'] !== null) {
                $error = 'วันนี้เช็กอินแล้ว';
            } elseif ($activeLeave) {
                $error = 'ยังอยู่ในช่วงลาที่อนุมัติแล้ว เช็กอินได้หลัง ' . date('d/m/Y H:i', strtotime($activeLeave['end_date']));
            }
        } elseif ($attendance['check_in_at'] === null) {
            $error = 'กรุณาเช็กอินก่อนเช็กเอาต์';
        } elseif ($attendance['check_out_at'] !== null) {
            $error = 'วันนี้เช็กเอาต์แล้ว';
        } elseif (!$shift || !$shiftEndAt) {
            $error = 'ไม่พบกะทำงานของวันนี้ กรุณาติดต่อ HR ก่อนเช็กเอาต์';
        } elseif ($now < $shiftEndAt) {
            $error = 'เช็กเอาต์ได้ตั้งแต่เวลาเลิกกะ ' . $shiftEndAt->format('H:i') . ' น. เป็นต้นไป';
        }

        if ($error === '') {
            $scanTime = $pdo->query('SELECT NOW()')->fetchColumn();
            $eventKey = hash('sha256', serialize([$empId, null, $scanTime, $scanType]));
            $pdo->prepare("INSERT INTO attendance_logs (employee_id, scan_time, scan_type, event_key, verify_mode, latitude, longitude) VALUES (?, ?, ?, ?, 'mobile_gps', ?, ?)")
                ->execute([$empId, $scanTime, $scanType, $eventKey, $hasCoordinates ? $latitude : null, $hasCoordinates ? $longitude : null]);
            $_SESSION['ess_notice'] = $scanType === 'check_in' ? 'เช็กอินสำเร็จ' : 'เช็กเอาต์สำเร็จ';
            header('Location: ' . BASE_URL . '/modules/ess/ess_portal.php');
            exit;
        }
    } elseif ($action === 'approve_leave' || $action === 'approve_ot') {
        $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
        $status = $_POST['status'] ?? '';
        if ($requestId === false || $requestId < 1 || !in_array($status, ['approved', 'rejected'], true)) {
            $error = 'ข้อมูลการอนุมัติไม่ถูกต้อง';
        } else {
            $isLeaveApproval = $action === 'approve_leave';
            $requestTable = $isLeaveApproval ? 'leave_requests' : 'ot_requests';
            $approvalTable = $isLeaveApproval ? 'leave_approvals' : 'ot_approvals';
            $requestIdColumn = $isLeaveApproval ? 'leave_request_id' : 'ot_request_id';
            $pdo->beginTransaction();
            try {
                $requestStmt = $pdo->prepare("SELECT * FROM {$requestTable} WHERE id = ? AND status = 'pending' FOR UPDATE");
                $requestStmt->execute([$requestId]);
                $request = $requestStmt->fetch();
                if (!$request) {
                    $pdo->rollBack();
                    $error = 'ไม่พบคำขอที่รออนุมัติ';
                } else {
                    $approvalStmt = $pdo->prepare(
                        "SELECT id, step_order FROM {$approvalTable}
                         WHERE {$requestIdColumn} = ? AND step_order = ? AND approver_id = ? AND status = 'pending'
                         FOR UPDATE"
                    );
                    $approvalStmt->execute([$requestId, $request['current_step'], $empId]);
                    $currentApproval = $approvalStmt->fetch();
                    if (!$currentApproval) {
                        $pdo->rollBack();
                        http_response_code(403);
                        exit('ไม่มีสิทธิ์อนุมัติขั้นตอนนี้');
                    }

                    $isFinalStep = (int)$request['current_step'] >= (int)$request['total_steps'];
                    if ($isLeaveApproval && $status === 'approved' && $isFinalStep) {
                        $balanceStmt = $pdo->prepare('SELECT quota_days, carried_days, used_days FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ? FOR UPDATE');
                        $leaveYear = (int)date('Y', strtotime($request['start_date']));
                        $balanceStmt->execute([$request['employee_id'], $request['leave_type_id'], $leaveYear]);
                        $balance = $balanceStmt->fetch();
                        $pendingStmt = $pdo->prepare("SELECT COALESCE(SUM(total_days), 0) FROM leave_requests WHERE employee_id = ? AND leave_type_id = ? AND YEAR(start_date) = ? AND status = 'pending' AND id <> ?");
                        $pendingStmt->execute([$request['employee_id'], $request['leave_type_id'], $leaveYear, $requestId]);
                        $pendingDays = (float)$pendingStmt->fetchColumn();
                        if (!$balance || (float)$balance['used_days'] + $pendingDays + (float)$request['total_days'] > (float)$balance['quota_days'] + (float)$balance['carried_days']) {
                            $pdo->rollBack();
                            $error = 'ยอดวันลาคงเหลือไม่เพียงพอ จึงอนุมัติคำขอนี้ไม่ได้';
                        } else {
                            $pdo->prepare('UPDATE leave_balances SET used_days = used_days + ? WHERE employee_id = ? AND leave_type_id = ? AND year = ?')
                                ->execute([$request['total_days'], $request['employee_id'], $request['leave_type_id'], $leaveYear]);
                        }
                    }

                    if ($pdo->inTransaction()) {
                        $pdo->prepare("UPDATE {$approvalTable} SET status = ?, action_at = NOW() WHERE id = ? AND status = 'pending'")
                            ->execute([$status, $currentApproval['id']]);
                        if ($status === 'rejected') {
                            $pdo->prepare("UPDATE {$requestTable} SET status = 'rejected', pending_dedupe_key = NULL WHERE id = ? AND status = 'pending'")
                                ->execute([$requestId]);
                            $notice = 'ปฏิเสธคำขอแล้ว';
                        } elseif ($isFinalStep) {
                            $approvedAtSql = $isLeaveApproval ? ', approved_at = NOW()' : '';
                            $pdo->prepare("UPDATE {$requestTable} SET status = 'approved', approved_by = ?{$approvedAtSql}, pending_dedupe_key = NULL WHERE id = ? AND status = 'pending'")
                                ->execute([$empId, $requestId]);
                            $notice = 'อนุมัติคำขอครบทุกขั้นแล้ว';
                        } else {
                            $nextStep = (int)$request['current_step'] + 1;
                            $nextApprovalStmt = $pdo->prepare(
                                "SELECT id FROM {$approvalTable}
                                 WHERE {$requestIdColumn} = ? AND step_order = ? AND status = 'waiting'
                                 FOR UPDATE"
                            );
                            $nextApprovalStmt->execute([$requestId, $nextStep]);
                            $nextApprovalId = $nextApprovalStmt->fetchColumn();
                            if ($nextApprovalId === false) {
                                throw new RuntimeException('ลำดับการอนุมัติไม่ครบ กรุณาติดต่อผู้ดูแลระบบ');
                            }
                            $pdo->prepare("UPDATE {$approvalTable} SET status = 'pending' WHERE id = ? AND status = 'waiting'")
                                ->execute([$nextApprovalId]);
                            $pdo->prepare("UPDATE {$requestTable} SET current_step = ? WHERE id = ? AND status = 'pending'")
                                ->execute([$nextStep, $requestId]);
                            $notice = 'อนุมัติขั้นที่ ' . (int)$request['current_step'] . '/' . (int)$request['total_steps'] . ' แล้ว ส่งต่อผู้อนุมัติถัดไป';
                        }

                        $pdo->commit();
                        $_SESSION['ess_notice'] = $notice;
                        header('Location: ' . BASE_URL . '/modules/ess/ess_portal.php');
                        exit;
                    }
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    } else {
        http_response_code(400);
        exit('ไม่รู้จักคำสั่งนี้');
    }
}

$nextScanType = null;
$canRecordTime = false;
$attendanceMessage = '';
$checkInDisplay = $attendance['check_in_at'] ? date('d/m H:i', strtotime($attendance['check_in_at'])) : 'ยังไม่มีรายการ';
$checkOutDisplay = $attendance['check_out_at'] ? date('d/m H:i', strtotime($attendance['check_out_at'])) : 'ยังไม่มีรายการ';
if ($attendance['check_out_at'] !== null) {
    $attendanceMessage = 'ลงเวลาเข้า-ออกวันนี้ครบแล้ว';
} elseif ($attendance['check_in_at'] !== null) {
    $nextScanType = 'check_out';
    if (!$shift || !$shiftEndAt) {
        $attendanceMessage = 'ไม่พบกะทำงานของวันนี้ กรุณาติดต่อ HR';
    } elseif ($now < $shiftEndAt) {
        $attendanceMessage = 'เช็กเอาต์ได้ตั้งแต่ ' . $shiftEndAt->format('H:i') . ' น. เป็นต้นไป';
    } else {
        $canRecordTime = true;
        $attendanceMessage = 'ถึงเวลาเลิกกะแล้ว สามารถเช็กเอาต์ได้';
    }
} else {
    $nextScanType = 'check_in';
    if ($activeLeave) {
        $attendanceMessage = 'เช็กอินได้หลังสิ้นสุดการลาที่อนุมัติ เวลา ' . date('d/m/Y H:i', strtotime($activeLeave['end_date']));
    } else {
        $canRecordTime = true;
        $attendanceMessage = 'พร้อมสำหรับการเช็กอิน';
    }
}

$pendingLeaves = [];
$pendingOTs = [];
$leaveStmt = $pdo->prepare("SELECT lr.*, e.first_name, e.last_name, lt.name AS leave_name,
                                   la.approver_role, la.step_order
                            FROM leave_approvals la
                            JOIN leave_requests lr ON lr.id = la.leave_request_id
                            JOIN employees e ON lr.employee_id = e.id
                            JOIN leave_types lt ON lr.leave_type_id = lt.id
                            WHERE lr.status = 'pending' AND lr.current_step = la.step_order
                              AND la.status = 'pending' AND la.approver_id = ?
                            ORDER BY lr.created_at DESC");
$otStmt = $pdo->prepare("SELECT o.*, e.first_name, e.last_name,
                                oa.approver_role, oa.step_order
                         FROM ot_approvals oa
                         JOIN ot_requests o ON o.id = oa.ot_request_id
                         JOIN employees e ON o.employee_id = e.id
                         WHERE o.status = 'pending' AND o.current_step = oa.step_order
                           AND oa.status = 'pending' AND oa.approver_id = ?
                         ORDER BY o.ot_date DESC");
$leaveStmt->execute([$empId]);
$otStmt->execute([$empId]);
$pendingLeaves = $leaveStmt->fetchAll();
$pendingOTs = $otStmt->fetchAll();
$canReceiveApprovals = hasRole(['admin', 'hr_manager', 'director', 'manager', 'supervisor']) ||
    !empty($pendingLeaves) || !empty($pendingOTs);
$pendingLeaveAttachments = getLeaveAttachmentsByRequestIds($pdo, array_column($pendingLeaves, 'id'));

$pageTitle = 'เช็กอิน / เช็กเอาต์';
include ROOT_PATH . '/includes/layout_top.php';
?>

<?php if ($notice !== ''): ?>
    <div class="alert alert-success rounded-3 animate-up"><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($notice) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger rounded-3 animate-up"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="<?= $canReceiveApprovals ? 'col-lg-5' : 'col-12' ?>">
        <section class="glass-card p-4 h-100">
            <h2 class="h6 fw-bold mb-3"><i class="bi bi-geo-alt-fill text-danger me-2" aria-hidden="true"></i>บันทึกเวลาเข้า-ออก</h2>
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                <span class="text-muted small">วันที่ <?= htmlspecialchars(date('d/m/Y', strtotime($today))) ?></span>
                <?php if ($shift): ?>
                    <span class="small">เวลาทำงาน <?= htmlspecialchars(substr($shift['start_time'], 0, 5) . '–' . substr($shift['end_time'], 0, 5)) ?></span>
                <?php else: ?>
                    <span class="small text-warning">ยังไม่ได้กำหนดกะทำงาน</span>
                <?php endif; ?>
            </div>

            <div class="mb-3 small">
                <div>เช็กอิน: <strong><?= htmlspecialchars($checkInDisplay) ?></strong></div>
                <div>เช็กเอาต์: <strong><?= htmlspecialchars($checkOutDisplay) ?></strong></div>
            </div>

            <p class="small text-muted mb-3"><?= htmlspecialchars($attendanceMessage) ?></p>
            <?php if ($nextScanType !== null): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="checkin">
                    <input type="hidden" name="scan_type" value="<?= htmlspecialchars($nextScanType) ?>">
                    <input type="hidden" name="lat" id="lat">
                    <input type="hidden" name="lng" id="lng">
                    <button type="submit" class="btn <?= $nextScanType === 'check_in' ? 'btn-success' : 'btn-primary' ?> btn-modern w-100 py-2" <?= $canRecordTime ? '' : 'disabled' ?>>
                        <i class="bi <?= $nextScanType === 'check_in' ? 'bi-box-arrow-in-right' : 'bi-box-arrow-right' ?> me-1" aria-hidden="true"></i>
                        <?= $nextScanType === 'check_in' ? 'เช็กอินเข้างาน' : 'เช็กเอาต์เลิกงาน' ?>
                    </button>
                </form>
            <?php endif; ?>
            <div class="d-flex gap-3 mt-3 small">
                <a href="<?= BASE_URL ?>/modules/ess/leave_request.php">ระบบลางาน</a>
                <a href="<?= BASE_URL ?>/modules/ess/ot_request.php">ยื่นขอ OT</a>
            </div>
        </section>
    </div>

    <?php if ($canReceiveApprovals): ?>
    <div class="col-lg-7">
        <section id="approvalQueue" class="glass-card p-4 h-100">
            <h2 class="h6 fw-bold mb-3"><i class="bi bi-check2-square text-success me-2" aria-hidden="true"></i>รายการรออนุมัติจากทีม (MSS)</h2>
            <?php if (empty($pendingLeaves) && empty($pendingOTs)): ?>
                <div class="text-center text-muted py-4 small">ไม่มีรายการรออนุมัติในขณะนี้</div>
            <?php else: ?>
                <?php foreach ($pendingLeaves as $request): ?>
                    <div class="p-3 border rounded-3 mb-2 bg-light d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <span class="badge bg-primary me-1">ขอลา</span>
                            <span class="badge bg-secondary-subtle text-secondary">ขั้น <?= (int)$request['step_order'] ?>/<?= (int)$request['total_steps'] ?> · <?= htmlspecialchars(getApprovalRoleLabel($request['approver_role'])) ?></span>
                            <strong class="small"><?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?></strong>
                            <div class="text-muted small"><?= htmlspecialchars($request['leave_name']) ?> · <?= number_format((float)$request['total_days'], 1) ?> วัน (<?= htmlspecialchars($request['reason']) ?>)</div>
                            <?php if (!empty($pendingLeaveAttachments[(int)$request['id']])): ?>
                                <div class="small mt-1">
                                    <?php foreach ($pendingLeaveAttachments[(int)$request['id']] as $attachment): ?>
                                        <div><a href="<?= BASE_URL ?>/modules/ess/leave_attachment.php?id=<?= (int)$attachment['id'] ?>" target="_blank" rel="noopener"><?= htmlspecialchars($attachment['file_name']) ?></a></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif (!empty($request['attachment_url']) && str_starts_with($request['attachment_url'], BASE_URL . '/uploads/')): ?>
                                <div class="small mt-1"><a href="<?= BASE_URL ?>/modules/ess/leave_attachment.php?legacy_request_id=<?= (int)$request['id'] ?>" target="_blank" rel="noopener">ดูไฟล์แนบเดิม</a></div>
                            <?php endif; ?>
                        </div>
                        <form method="post" class="d-flex gap-1">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="approve_leave">
                            <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                            <button name="status" value="approved" class="btn btn-success btn-sm">อนุมัติ</button>
                            <button name="status" value="rejected" class="btn btn-danger btn-sm">ปฏิเสธ</button>
                        </form>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($pendingOTs as $request): ?>
                    <div class="p-3 border rounded-3 mb-2 bg-light d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <span class="badge bg-warning text-dark me-1">ขอ OT</span>
                            <span class="badge bg-secondary-subtle text-secondary">ขั้น <?= (int)$request['step_order'] ?>/<?= (int)$request['total_steps'] ?> · <?= htmlspecialchars(getApprovalRoleLabel($request['approver_role'])) ?></span>
                            <strong class="small"><?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?></strong>
                            <div class="text-muted small"><?= htmlspecialchars($request['ot_date']) ?> · <?= htmlspecialchars(getOtTypeLabels()[$request['ot_type']] ?? $request['ot_type']) ?> · <?= number_format((float)$request['hours'], 0) ?> ชม. · ฿<?= number_format((float)$request['total_pay'], 2) ?> · <?= htmlspecialchars($request['reason']) ?></div>
                            <?php if (!empty($request['attachment_url']) && str_starts_with($request['attachment_url'], BASE_URL . '/uploads/ot_attachments/')): ?>
                                <a href="<?= htmlspecialchars($request['attachment_url']) ?>" target="_blank" rel="noopener" class="small">ดูไฟล์แนบ</a>
                            <?php endif; ?>
                        </div>
                        <form method="post" class="d-flex gap-1">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="approve_ot">
                            <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                            <button name="status" value="approved" class="btn btn-success btn-sm">อนุมัติ</button>
                            <button name="status" value="rejected" class="btn btn-danger btn-sm">ปฏิเสธ</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
</div>

<section class="glass-card p-4 animate-up">
    <h2 class="h6 fw-bold mb-3"><i class="bi bi-calendar3 text-primary me-2" aria-hidden="true"></i>ปฏิทินการทำงาน วันหยุด และวันลา</h2>
    <div id="calendar"></div>
</section>

<script>
if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(position => {
        document.getElementById('lat').value = position.coords.latitude;
        document.getElementById('lng').value = position.coords.longitude;
    });
}

document.addEventListener('DOMContentLoaded', () => {
    new FullCalendar.Calendar(document.getElementById('calendar'), {
        initialView: 'dayGridMonth',
        locale: 'th',
        height: 480,
        events: '<?= BASE_URL ?>/api/calendar_events.php'
    }).render();
});
</script>
<?php include ROOT_PATH . '/includes/layout_bottom.php'; ?>
