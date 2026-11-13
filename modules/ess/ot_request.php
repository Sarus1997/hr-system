<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once ROOT_PATH . '/config/cloud_storage.php';
require_once ROOT_PATH . '/includes/ot_calculation.php';
require_once ROOT_PATH . '/includes/approval_workflow.php';
requireLogin();

$myEmployeeId = (string)($_SESSION['employee_id'] ?? '');
$employees = getVisibleEmployees($pdo, $myEmployeeId);
$canViewOthers = count($employees) > 1;
$currentYear = (int)date('Y');
$selectedYear = filter_var($_GET['year'] ?? $currentYear, FILTER_VALIDATE_INT);
if ($selectedYear === false || $selectedYear < 2000 || $selectedYear > $currentYear + 1) {
    $selectedYear = $currentYear;
}

$viewEmployeeId = $myEmployeeId;
if (isset($_GET['emp_id'])) {
    $requestedEmployeeId = is_string($_GET['emp_id']) ? trim($_GET['emp_id']) : '';
    if ($requestedEmployeeId === '' || strlen($requestedEmployeeId) > 20) {
        http_response_code(400);
        exit('รหัสพนักงานไม่ถูกต้อง');
    }
    $allowedIds = array_map('strval', array_column($employees, 'id'));
    if (!in_array($requestedEmployeeId, $allowedIds, true)) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์ดูข้อมูลพนักงานรายนี้');
    }
    $viewEmployeeId = $requestedEmployeeId;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel') {
        $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
        if ($requestId === false || $requestId < 1) {
            http_response_code(400);
            exit('รหัสคำขอไม่ถูกต้อง');
        }
        $cancelStmt = $pdo->prepare("DELETE FROM ot_requests WHERE id = ? AND employee_id = ? AND status = 'pending'");
        $cancelStmt->execute([$requestId, $myEmployeeId]);
        $_SESSION['ot_notice'] = $cancelStmt->rowCount() ? 'ยกเลิกคำขอ OT แล้ว' : 'ไม่พบคำขอที่ยกเลิกได้';
        header('Location: ' . BASE_URL . '/modules/ess/ot_request.php?year=' . $selectedYear);
        exit;
    }

    if ($action === 'request') {
        $otDate = $_POST['ot_date'] ?? '';
        $startRaw = $_POST['start_time'] ?? '';
        $endRaw = $_POST['end_time'] ?? '';
        $otType = $_POST['ot_type'] ?? '';
        $reason = is_string($_POST['reason'] ?? null) ? trim($_POST['reason']) : '';
        $start = is_string($startRaw) ? DateTimeImmutable::createFromFormat('!H:i', $startRaw) : false;
        $end = is_string($endRaw) ? DateTimeImmutable::createFromFormat('!H:i', $endRaw) : false;
        $attachment = $_FILES['attachment'] ?? null;
        $attachmentExtension = null;

        if ($attachment !== null) {
            if (!is_array($attachment) || !isset($attachment['error'])) {
                $error = 'ข้อมูลไฟล์แนบไม่ถูกต้อง';
            } elseif ($attachment['error'] !== UPLOAD_ERR_NO_FILE) {
                $allowedMimeTypes = [
                    'application/pdf' => 'pdf',
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                ];
                $isUploadedFile = $attachment['error'] === UPLOAD_ERR_OK &&
                    isset($attachment['tmp_name'], $attachment['size']) &&
                    is_string($attachment['tmp_name']) && is_int($attachment['size']) &&
                    is_uploaded_file($attachment['tmp_name']);
                $mimeType = $isUploadedFile
                    ? (new finfo(FILEINFO_MIME_TYPE))->file($attachment['tmp_name'])
                    : false;
                if (!$isUploadedFile || $attachment['size'] < 1 || $attachment['size'] > 5 * 1024 * 1024 ||
                    !isset($allowedMimeTypes[$mimeType])) {
                    $error = 'ไฟล์แนบต้องเป็น PDF, JPG หรือ PNG และมีขนาดไม่เกิน 5 MB';
                } else {
                    $attachmentExtension = $allowedMimeTypes[$mimeType];
                }
            }
        }

        if ($error === '' && (
            !is_string($otDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $otDate) ||
            !$start || !$end || $start->format('H:i') !== $startRaw || $end->format('H:i') !== $endRaw ||
            !is_string($otType) || !array_key_exists($otType, getOtTypeLabels()) || $reason === ''
        )) {
            $error = 'กรุณาตรวจสอบวันที่ ประเภท OT เวลา และรายละเอียดงาน';
        } elseif ($error === '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $otDate);
            try {
                $otDetails = calculateOtDetails($startRaw, $endRaw, $otType);
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }

            if ($error === '' && (!$date || $date->format('Y-m-d') !== $otDate)) {
                $error = 'กรุณาตรวจสอบวันที่ OT';
            } else {
                if ($error === '') {
                    try {
                        $approvalSteps = buildSequentialApprovalSteps($pdo, $myEmployeeId);
                    } catch (ApprovalWorkflowException $exception) {
                        $error = $exception->getMessage();
                    }
                }
                if ($error === '') {
                    $duplicateStmt = $pdo->prepare("SELECT id FROM ot_requests WHERE employee_id = ? AND ot_date = ? AND start_time = ? AND end_time = ? AND ot_type = ? AND COALESCE(reason, '') = ? AND status = 'pending' LIMIT 1");
                    $duplicateStmt->execute([
                        $myEmployeeId,
                        $otDate,
                        $start->format('H:i:s'),
                        $end->format('H:i:s'),
                        $otType,
                        $reason,
                    ]);
                    if ($duplicateStmt->fetchColumn() !== false) {
                        $error = 'มีคำขอ OT ประเภทและช่วงเวลาเดียวกันที่กำลังรออนุมัติอยู่แล้ว';
                    }
                }
                if ($error === '') {
                    $attachmentUrl = null;
                    if ($attachmentExtension !== null) {
                        try {
                            $cloud = CloudStorage::upload($attachment['tmp_name'], 'ot-attachment.' . $attachmentExtension, 'ot_attachments');
                            $attachmentUrl = $cloud['url'];
                        } catch (RuntimeException $exception) {
                            $error = 'ไม่สามารถบันทึกไฟล์แนบได้ กรุณาลองใหม่';
                        }
                    }
                    if ($error === '') {
                        $pdo->beginTransaction();
                        try {
                            $pendingDedupeKey = hash('sha256', serialize([
                                $myEmployeeId,
                                $otDate,
                                $start->format('H:i:s'),
                                $end->format('H:i:s'),
                                $otType,
                                $reason,
                            ]));
                            $insertStmt = $pdo->prepare("INSERT INTO ot_requests (employee_id, ot_date, start_time, end_time, ot_type, raw_hours, net_hours, hours, lunch_deducted, total_pay, reason, attachment_url, current_step, total_steps, pending_dedupe_key, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, 'pending')");
                            $insertStmt->execute([
                                $myEmployeeId,
                                $otDate,
                                $start->format('H:i:s'),
                                $end->format('H:i:s'),
                                $otType,
                                $otDetails['raw_hours'],
                                $otDetails['net_hours'],
                                $otDetails['hours'],
                                $otDetails['lunch_deducted'],
                                $otDetails['total_pay'],
                                $reason,
                                $attachmentUrl,
                                count($approvalSteps),
                                $pendingDedupeKey,
                            ]);
                            $requestId = (int)$pdo->lastInsertId();
                            insertSequentialApprovalSteps($pdo, 'ot', $requestId, $approvalSteps);
                            $pdo->commit();
                            $_SESSION['ot_notice'] = 'ส่งคำขอ OT แล้ว รอผู้อนุมัติขั้นที่ 1 ดำเนินการ';
                            header('Location: ' . BASE_URL . '/modules/ess/ot_request.php');
                            exit;
                        } catch (Throwable $exception) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            throw $exception;
                        }
                    }
                }
            }
        }

    }
}

$notice = $_SESSION['ot_notice'] ?? '';
unset($_SESSION['ot_notice']);
$employeeStmt = $pdo->prepare('SELECT e.emp_code, e.first_name, e.last_name, d.name AS department FROM employees e LEFT JOIN departments d ON e.department_id = d.id WHERE e.id = ?');
$employeeStmt->execute([$viewEmployeeId]);
$employee = $employeeStmt->fetch();
if (!$employee) {
    http_response_code(404);
    exit('ไม่พบข้อมูลพนักงาน');
}
$otTypeLabels = getOtTypeLabels();

$summaryStmt = $pdo->prepare("
    SELECT COUNT(CASE WHEN status = 'approved' THEN 1 END) AS approved_times,
           COALESCE(SUM(CASE WHEN status = 'approved' THEN hours ELSE 0 END), 0) AS approved_hours,
           COALESCE(SUM(CASE WHEN status = 'approved' THEN total_pay ELSE 0 END), 0) AS approved_pay,
           COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_times
    FROM ot_requests WHERE employee_id = ? AND YEAR(ot_date) = ?
");
$summaryStmt->execute([$viewEmployeeId, $selectedYear]);
$summary = $summaryStmt->fetch();
$estimatedPay = (float)$summary['approved_pay'];

$historyStmt = $pdo->prepare("
    SELECT o.*, CONCAT(ap.first_name, ' ', ap.last_name) AS approver_name,
           oa.approver_role AS current_approver_role
    FROM ot_requests o
    LEFT JOIN employees ap ON ap.id = o.approved_by
    LEFT JOIN ot_approvals oa ON oa.ot_request_id = o.id AND oa.step_order = o.current_step
    WHERE o.employee_id = ? AND YEAR(o.ot_date) = ?
    ORDER BY o.ot_date DESC, o.start_time DESC
");
$historyStmt->execute([$viewEmployeeId, $selectedYear]);
$history = $historyStmt->fetchAll();

$pageTitle = 'ระบบยื่นขอ OT และประวัติรายปี';
include ROOT_PATH . '/includes/layout_top.php';
?>

<?php if ($notice !== ''): ?><div class="alert alert-success rounded-3"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger rounded-3"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<section class="glass-card p-3 mb-4">
    <form method="get" class="row g-2 align-items-center">
        <div class="col-md-5">
            <strong><?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?></strong>
            <span class="text-muted small"><?= htmlspecialchars($employee['emp_code'] . ' · ' . ($employee['department'] ?? '-')) ?></span>
        </div>
        <?php if ($canViewOthers): ?>
        <div class="col-md-4">
            <label class="visually-hidden" for="employeeFilter">เลือกพนักงาน</label>
            <select id="employeeFilter" name="emp_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="<?= htmlspecialchars($myEmployeeId, ENT_QUOTES, 'UTF-8') ?>" <?= $viewEmployeeId === $myEmployeeId ? 'selected' : '' ?>>ข้อมูลของฉัน</option>
                <?php foreach ($employees as $listedEmployee): ?>
                    <option value="<?= htmlspecialchars($listedEmployee['id']) ?>" <?= (string)$listedEmployee['id'] === $viewEmployeeId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($listedEmployee['emp_code'] . ' - ' . $listedEmployee['first_name'] . ' ' . $listedEmployee['last_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="col-md-3">
            <label class="visually-hidden" for="yearFilter">เลือกปี</label>
            <select id="yearFilter" name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php for ($year = $currentYear + 1; $year >= $currentYear - 4; $year--): ?>
                    <option value="<?= $year ?>" <?= $year === $selectedYear ? 'selected' : '' ?>>ปี <?= $year ?></option>
                <?php endfor; ?>
            </select>
        </div>
    </form>
</section>

<div class="row g-3 mb-4">
    <div class="col-md-4"><section class="glass-card p-4 h-100"><div class="small text-muted">OT ที่อนุมัติแล้ว</div><div class="h3 fw-bold mb-1"><?= (int)$summary['approved_times'] ?> ครั้ง</div><div class="small">รออนุมัติ <?= (int)$summary['pending_times'] ?> รายการ</div></section></div>
    <div class="col-md-4"><section class="glass-card p-4 h-100"><div class="small text-muted">ชั่วโมง OT ที่อนุมัติแล้ว</div><div class="h3 fw-bold mb-1"><?= number_format((float)$summary['approved_hours'], 0) ?> ชม.</div><div class="small">นับตามจำนวนชั่วโมงหลังปัดเศษ</div></section></div>
    <div class="col-md-4"><section class="glass-card p-4 h-100"><div class="small text-muted">ค่าตอบแทน OT ที่อนุมัติแล้ว</div><div class="h3 fw-bold mb-1">฿<?= number_format($estimatedPay, 2) ?></div><div class="small">คำนวณตามประเภทและชั่วโมงสุทธิ</div></section></div>
</div>

<div class="row g-4">
    <?php if ($viewEmployeeId === $myEmployeeId): ?>
    <div class="col-lg-4">
        <section class="glass-card p-4 h-100">
            <h2 class="h6 fw-bold mb-3">ยื่นคำขอทำ OT</h2>
            <form method="post" enctype="multipart/form-data" class="row g-3" id="otRequestForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="request">
                <div class="col-12"><label for="otDate" class="form-label small">วันที่ทำ OT</label><input id="otDate" type="date" name="ot_date" value="<?= date('Y-m-d') ?>" class="form-control" required></div>
                <div class="col-12"><label for="otType" class="form-label small">ประเภท OT</label><select id="otType" name="ot_type" class="form-select" required><?php foreach ($otTypeLabels as $type => $label): ?><option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label for="otStart" class="form-label small">เวลาเริ่ม</label><input id="otStart" type="time" name="start_time" class="form-control" required></div>
                <div class="col-6"><label for="otEnd" class="form-label small">เวลาสิ้นสุด</label><input id="otEnd" type="time" name="end_time" class="form-control" required></div>
                <div class="col-6"><label for="otHours" class="form-label small">ชั่วโมงสุทธิหลังปัดเศษ</label><input id="otHours" type="text" class="form-control" readonly aria-live="polite"></div>
                <div class="col-6"><label for="otPay" class="form-label small">ค่าตอบแทนโดยประมาณ</label><input id="otPay" type="text" class="form-control" readonly aria-live="polite"></div>
                <div class="col-12"><div id="otCalculationNote" class="form-text" aria-live="polite">ชั่วโมงคำนวณจากเวลาและปัดเศษ 30 นาทีขึ้นไปเป็น 1 ชั่วโมง</div></div>
                <div class="col-12"><label for="otReason" class="form-label small">รายละเอียดงาน</label><textarea id="otReason" name="reason" rows="2" class="form-control" required></textarea></div>
                <div class="col-12">
                    <label for="otAttachment" class="form-label small">เอกสารประกอบ (ไม่บังคับ)</label>
                    <input id="otAttachment" type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="form-control">
                    <div class="form-text">รองรับ PDF, JPG และ PNG ขนาดไม่เกิน 5 MB</div>
                </div>
                <div class="col-12"><button class="btn btn-warning btn-modern w-100">ส่งคำขออนุมัติ OT</button></div>
            </form>
        </section>
    </div>
    <?php endif; ?>

    <div class="col-lg-<?= $viewEmployeeId === $myEmployeeId ? '8' : '12' ?>">
        <section class="glass-card p-4 h-100">
            <h2 class="h6 fw-bold mb-3">ประวัติ OT ปี <?= $selectedYear ?></h2>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light"><tr><th>วันที่</th><th>ประเภท OT</th><th>เวลา</th><th>ชั่วโมง / ค่าตอบแทน</th><th>งานที่ทำ</th><th>ผู้อนุมัติ</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
                    <tbody>
                    <?php if (!$history): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">ไม่มีรายการ OT ในปีนี้</td></tr>
                    <?php else: foreach ($history as $request): ?>
                        <tr>
                            <td><?= htmlspecialchars(date('d/m/Y', strtotime($request['ot_date']))) ?></td>
                            <td><?= htmlspecialchars($otTypeLabels[$request['ot_type']] ?? $request['ot_type']) ?></td>
                            <td><?= htmlspecialchars(substr($request['start_time'], 0, 5) . ' - ' . substr($request['end_time'], 0, 5)) ?></td>
                            <td><?= number_format((float)$request['hours'], 0) ?> ชม. · ฿<?= number_format((float)$request['total_pay'], 2) ?><div class="small text-muted">สุทธิ <?= number_format((float)$request['net_hours'], 2) ?> ชม. จาก <?= number_format((float)$request['raw_hours'], 2) ?> ชม.</div></td>
                            <td>
                                <?= htmlspecialchars($request['reason'] ?? '') ?>
                                <?php if (!empty($request['attachment_url']) && str_starts_with($request['attachment_url'], BASE_URL . '/uploads/ot_attachments/')): ?>
                                    <br><a href="<?= htmlspecialchars($request['attachment_url']) ?>" target="_blank" rel="noopener" class="small">ดูไฟล์แนบ</a>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($request['approver_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars(getApprovalProgressLabel($request)) ?></td>
                            <td>
                                <?php if ($request['status'] === 'pending' && $viewEmployeeId === $myEmployeeId): ?>
                                    <form method="post" onsubmit="return confirm('ยกเลิกคำขอ OT นี้หรือไม่?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">ยกเลิก</button>
                                    </form>
                                <?php else: ?>- <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<script>
(() => {
    const start = document.getElementById('otStart');
    const end = document.getElementById('otEnd');
    const hours = document.getElementById('otHours');
    const type = document.getElementById('otType');
    const pay = document.getElementById('otPay');
    const note = document.getElementById('otCalculationNote');
    if (!start || !end || !hours || !type || !pay || !note) return;

    const calculatePay = roundedHours => {
        if (roundedHours < 1) return 0;
        if (roundedHours <= 2) return 325;
        if (roundedHours <= 4) return 650;
        if (roundedHours <= 6) return 975;
        if (roundedHours <= 8) return 1300;
        return Math.min(roundedHours * 162.5, 2600);
    };

    const updateTimeLimits = () => {
        start.min = type.value === 'workday_post' ? '17:30' : '';
        end.max = type.value === 'workday_pre' ? '08:00' : '';
    };

    const calculateHours = () => {
        if (!start.value || !end.value) {
            hours.value = '';
            pay.value = '';
            note.textContent = 'ชั่วโมงคำนวณจากเวลาและปัดเศษ 30 นาทีขึ้นไปเป็น 1 ชั่วโมง';
            return;
        }
        const [startHour, startMinute] = start.value.split(':').map(Number);
        const [endHour, endMinute] = end.value.split(':').map(Number);
        let duration = (endHour * 60 + endMinute) - (startHour * 60 + startMinute);
        if (duration <= 0) duration += 24 * 60;
        const startMinutes = startHour * 60 + startMinute;
        const lunchDeducted = type.value === 'holiday' && startMinutes < 780 && startMinutes + duration > 720;
        const netMinutes = Math.max(0, duration - (lunchDeducted ? 60 : 0));
        const roundedHours = Math.floor(netMinutes / 60) + (netMinutes % 60 >= 30 ? 1 : 0);
        hours.value = String(roundedHours);
        pay.value = '฿' + calculatePay(roundedHours).toLocaleString('th-TH');
        note.textContent = lunchDeducted
            ? 'หักพักเที่ยง 1 ชั่วโมงแล้ว; ปัดเศษ 30 นาทีขึ้นไปเป็น 1 ชั่วโมง'
            : 'ชั่วโมงสุทธิจากเวลา; ปัดเศษ 30 นาทีขึ้นไปเป็น 1 ชั่วโมง';
    };

    type.addEventListener('change', () => {
        updateTimeLimits();
        calculateHours();
    });
    start.addEventListener('change', calculateHours);
    end.addEventListener('change', calculateHours);
    updateTimeLimits();
})();
</script>

<?php include ROOT_PATH . '/includes/layout_bottom.php'; ?>
