<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once ROOT_PATH . '/config/cloud_storage.php';
require_once ROOT_PATH . '/includes/leave_service.php';
require_once ROOT_PATH . '/includes/approval_workflow.php';
requireLogin();

$myEmployeeId = (string)($_SESSION['employee_id'] ?? '');
$approvalProfile = getApprovalEmployeeProfile($pdo, $myEmployeeId);
$isLeaveRequired = (int)$approvalProfile['is_leave_required'] === 1;
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
        $attachmentFilesStmt = $pdo->prepare(
            "SELECT la.cloud_object_key FROM leave_attachments la
             JOIN leave_requests lr ON lr.id = la.leave_request_id
             WHERE lr.id = ? AND lr.employee_id = ? AND lr.status = 'pending'"
        );
        $attachmentFilesStmt->execute([$requestId, $myEmployeeId]);
        $attachmentObjectKeys = $attachmentFilesStmt->fetchAll(PDO::FETCH_COLUMN);
        $cancelStmt = $pdo->prepare("DELETE FROM leave_requests WHERE id = ? AND employee_id = ? AND status = 'pending'");
        $cancelStmt->execute([$requestId, $myEmployeeId]);
        if ($cancelStmt->rowCount()) {
            removeLocalLeaveAttachmentFiles($attachmentObjectKeys);
        }
        $_SESSION['leave_notice'] = $cancelStmt->rowCount() ? 'ยกเลิกคำขอลาแล้ว' : 'ไม่พบคำขอที่ยกเลิกได้';
        header('Location: ' . BASE_URL . '/modules/ess/leave_request.php?year=' . $selectedYear);
        exit;
    }

    if ($action === 'request' && !$isLeaveRequired) {
        $error = 'ตำแหน่งของคุณไม่จำเป็นต้องยื่นคำขอลา';
    } elseif ($action === 'request') {
        $leaveTypeId = filter_var($_POST['leave_type_id'] ?? null, FILTER_VALIDATE_INT);
        $totalDays = filter_var($_POST['total_days'] ?? null, FILTER_VALIDATE_FLOAT);
        $startRaw = $_POST['start_date'] ?? '';
        $endRaw = $_POST['end_date'] ?? '';
        $reason = is_string($_POST['reason'] ?? null) ? trim($_POST['reason']) : '';
        $startDate = is_string($startRaw) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $startRaw) : false;
        $endDate = is_string($endRaw) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $endRaw) : false;
        $attachments = [];
        $fileInput = $_FILES['attachments'] ?? null;
        if ($fileInput !== null) {
            $hasValidFileInput = is_array($fileInput);
            foreach (['name', 'tmp_name', 'error', 'size'] as $field) {
                $hasValidFileInput = $hasValidFileInput && isset($fileInput[$field]) && is_array($fileInput[$field]);
            }

            if (!$hasValidFileInput) {
                $error = 'ข้อมูลไฟล์แนบไม่ถูกต้อง';
            } else {
                $allowedMimeTypes = [
                    'application/pdf' => 'pdf',
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                ];
                $fileInfo = new finfo(FILEINFO_MIME_TYPE);
                $selectedFileCount = 0;
                foreach ($fileInput['error'] as $index => $uploadError) {
                    if ($uploadError === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $selectedFileCount++;
                    if ($selectedFileCount > 5) {
                        $error = 'แนบไฟล์ได้ไม่เกิน 5 ไฟล์ต่อคำขอ';
                        break;
                    }

                    $temporaryPath = $fileInput['tmp_name'][$index] ?? null;
                    $fileSize = $fileInput['size'][$index] ?? null;
                    $originalName = $fileInput['name'][$index] ?? null;
                    $isUploadedFile = $uploadError === UPLOAD_ERR_OK &&
                        is_string($temporaryPath) && is_int($fileSize) &&
                        is_string($originalName) && is_uploaded_file($temporaryPath);
                    $mimeType = $isUploadedFile ? $fileInfo->file($temporaryPath) : false;
                    if (!$isUploadedFile || $fileSize < 1 || $fileSize > 5 * 1024 * 1024 ||
                        !isset($allowedMimeTypes[$mimeType])) {
                        $error = 'ไฟล์แนบต้องเป็น PDF, JPG หรือ PNG และมีขนาดไม่เกิน 5 MB ต่อไฟล์';
                        break;
                    }

                    $fileName = basename(str_replace('\\', '/', $originalName));
                    $fileName = preg_replace('/[\x00-\x1F\x7F]/', '', $fileName);
                    if (!is_string($fileName) || $fileName === '') {
                        $error = 'ชื่อไฟล์แนบไม่ถูกต้อง';
                        break;
                    }
                    if (function_exists('mb_strcut')) {
                        $fileName = mb_strcut($fileName, 0, 255, 'UTF-8');
                    } else {
                        $fileName = substr($fileName, 0, 255);
                    }

                    $attachments[] = [
                        'tmp_name' => $temporaryPath,
                        'file_name' => $fileName,
                        'extension' => $allowedMimeTypes[$mimeType],
                    ];
                }
            }
        }

        if ($error === '' && ($leaveTypeId === false || $leaveTypeId < 1 || $totalDays === false || $totalDays <= 0 ||
            abs(($totalDays * 2) - round($totalDays * 2)) > 0.00001 ||
            !$startDate || !$endDate || $startDate->format('Y-m-d\TH:i') !== $startRaw ||
            $endDate->format('Y-m-d\TH:i') !== $endRaw || $endDate <= $startDate || $reason === '')) {
            $error = 'กรุณาตรวจสอบประเภทลา วันที่ จำนวนวันลา และเหตุผลให้ถูกต้อง';
        } elseif ($error === '') {
            $leaveYear = (int)$startDate->format('Y');
            $balanceStmt = $pdo->prepare('SELECT quota_days, carried_days, used_days FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ? FOR UPDATE');
            $pendingStmt = $pdo->prepare("SELECT COALESCE(SUM(total_days), 0) FROM leave_requests WHERE employee_id = ? AND leave_type_id = ? AND YEAR(start_date) = ? AND status = 'pending'");
            $typeStmt = $pdo->prepare('SELECT id FROM leave_types WHERE id = ? AND status = 1');
            $typeStmt->execute([$leaveTypeId]);

            if (!$typeStmt->fetchColumn()) {
                $error = 'ไม่พบประเภทการลาที่เลือก';
            } else {
                try {
                    $approvalSteps = buildSequentialApprovalSteps($pdo, $myEmployeeId);
                } catch (ApprovalWorkflowException $exception) {
                    $error = $exception->getMessage();
                }

                if ($error === '') {
                    $pdo->beginTransaction();
                    try {
                        $balanceStmt->execute([$myEmployeeId, $leaveTypeId, $leaveYear]);
                        $balance = $balanceStmt->fetch();
                        $formattedStartDate = $startDate->format('Y-m-d H:i:s');
                        $formattedEndDate = $endDate->format('Y-m-d H:i:s');
                        $duplicateStmt = $pdo->prepare("SELECT id FROM leave_requests WHERE employee_id = ? AND leave_type_id = ? AND start_date = ? AND end_date = ? AND total_days = ? AND COALESCE(reason, '') = ? AND status = 'pending' LIMIT 1 FOR UPDATE");
                        $duplicateStmt->execute([$myEmployeeId, $leaveTypeId, $formattedStartDate, $formattedEndDate, $totalDays, $reason]);
                        if ($duplicateStmt->fetchColumn() !== false) {
                            throw new DomainException('มีคำขอลาประเภทและช่วงเวลาเดียวกันที่กำลังรออนุมัติอยู่แล้ว');
                        }
                        $pendingStmt->execute([$myEmployeeId, $leaveTypeId, $leaveYear]);
                        $pendingDays = (float)$pendingStmt->fetchColumn();
                        $remainingDays = $balance
                            ? (float)$balance['quota_days'] + (float)$balance['carried_days'] - (float)$balance['used_days'] - $pendingDays
                            : 0;

                        if (!$balance || $totalDays > $remainingDays) {
                            $pdo->rollBack();
                            $error = 'วันลาคงเหลือไม่เพียงพอ หรือยังไม่มีสิทธิ์ลาประเภทนี้ในปีที่เลือก';
                        } else {
                            $pendingDedupeKey = hash('sha256', serialize([
                                $myEmployeeId,
                                (int) $leaveTypeId,
                                $formattedStartDate,
                                $formattedEndDate,
                                number_format((float) $totalDays, 1, '.', ''),
                                $reason,
                            ]));
                            $insertStmt = $pdo->prepare("INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, total_days, reason, attachment_url, current_step, total_steps, pending_dedupe_key, status) VALUES (?, ?, ?, ?, ?, ?, NULL, 1, ?, ?, 'pending')");
                            $insertStmt->execute([
                                $myEmployeeId,
                                $leaveTypeId,
                                $formattedStartDate,
                                $formattedEndDate,
                                $totalDays,
                                $reason,
                                count($approvalSteps),
                                $pendingDedupeKey,
                            ]);
                            $requestId = (int)$pdo->lastInsertId();
                            insertSequentialApprovalSteps($pdo, 'leave', $requestId, $approvalSteps);
                            $uploadedObjects = [];
                            $attachmentInsert = $pdo->prepare(
                                'INSERT INTO leave_attachments (leave_request_id, file_name, cloud_bucket, cloud_object_key, file_url)
                                 VALUES (?, ?, ?, ?, ?)'
                            );
                            $uploadFailed = false;
                            foreach ($attachments as $attachment) {
                                try {
                                    $cloud = CloudStorage::upload(
                                        $attachment['tmp_name'],
                                        'leave-attachment.' . $attachment['extension'],
                                        'leave_attachments'
                                    );
                                } catch (RuntimeException $exception) {
                                    $uploadFailed = true;
                                    break;
                                }
                                $uploadedObjects[] = $cloud;
                                $attachmentInsert->execute([
                                    $requestId,
                                    $attachment['file_name'],
                                    $cloud['bucket'],
                                    $cloud['key'],
                                    $cloud['url'],
                                ]);
                            }

                            if ($uploadFailed) {
                                $pdo->rollBack();
                                removeLocalLeaveAttachmentFiles(array_column($uploadedObjects, 'key'));
                                $error = 'ไม่สามารถบันทึกไฟล์แนบได้ กรุณาลองใหม่';
                            } else {
                                if ($uploadedObjects) {
                                    $pdo->prepare('UPDATE leave_requests SET attachment_url = ? WHERE id = ?')
                                        ->execute([$uploadedObjects[0]['url'], $requestId]);
                                }
                                $pdo->commit();
                                $_SESSION['leave_notice'] = 'ส่งคำขอลาแล้ว รอผู้อนุมัติดำเนินการ';
                                header('Location: ' . BASE_URL . '/modules/ess/leave_request.php');
                                exit;
                            }
                        }
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        if ($exception instanceof DomainException) {
                            $error = $exception->getMessage();
                        } else {
                            removeLocalLeaveAttachmentFiles(array_column($uploadedObjects ?? [], 'key'));
                            throw $exception;
                        }
                    }
                }
            }
        }
    }

    if (!in_array($action, ['cancel', 'request'], true)) {
        http_response_code(400);
        exit('ไม่รู้จักคำสั่งนี้');
    }
}

$notice = $_SESSION['leave_notice'] ?? '';
unset($_SESSION['leave_notice']);
$employeeStmt = $pdo->prepare('SELECT e.emp_code, e.first_name, e.last_name, d.name AS department FROM employees e LEFT JOIN departments d ON e.department_id = d.id WHERE e.id = ?');
$employeeStmt->execute([$viewEmployeeId]);
$employee = $employeeStmt->fetch();
if (!$employee) {
    http_response_code(404);
    exit('ไม่พบข้อมูลพนักงาน');
}

$summaryStmt = $pdo->prepare("
    SELECT lt.id, lt.name, lt.status, COALESCE(lb.quota_days, 0) AS quota_days,
           COALESCE(lb.carried_days, 0) AS carried_days, COALESCE(lb.used_days, 0) AS used_days,
           COALESCE((SELECT SUM(lr.total_days) FROM leave_requests lr
                     WHERE lr.employee_id = ? AND lr.leave_type_id = lt.id
                       AND YEAR(lr.start_date) = ? AND lr.status = 'pending'), 0) AS pending_days
    FROM leave_types lt
    LEFT JOIN leave_balances lb ON lb.leave_type_id = lt.id AND lb.employee_id = ? AND lb.year = ?
    ORDER BY lt.id
");
$summaryStmt->execute([$viewEmployeeId, $selectedYear, $viewEmployeeId, $selectedYear]);
$leaveSummary = $summaryStmt->fetchAll();

$historyStmt = $pdo->prepare("
    SELECT lr.*, lt.name AS leave_name, CONCAT(ap.first_name, ' ', ap.last_name) AS approver_name,
           la.approver_role AS current_approver_role
    FROM leave_requests lr
    JOIN leave_types lt ON lt.id = lr.leave_type_id
    LEFT JOIN employees ap ON ap.id = lr.approved_by
    LEFT JOIN leave_approvals la ON la.leave_request_id = lr.id AND la.step_order = lr.current_step
    WHERE lr.employee_id = ? AND YEAR(lr.start_date) = ?
    ORDER BY lr.start_date DESC
");
$historyStmt->execute([$viewEmployeeId, $selectedYear]);
$leaveHistory = $historyStmt->fetchAll();
$leaveAttachments = getLeaveAttachmentsByRequestIds($pdo, array_column($leaveHistory, 'id'));
$leaveTypes = $pdo->query('SELECT id, name FROM leave_types WHERE status = 1 ORDER BY id')->fetchAll();

$pageTitle = 'ระบบลางานและสรุปวันลารายบุคคล';
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
    <?php foreach ($leaveSummary as $summary):
        $quota = (float)$summary['quota_days'] + (float)$summary['carried_days'];
        $used = (float)$summary['used_days'];
        $pending = (float)$summary['pending_days'];
        $remaining = max(0, $quota - $used - $pending);
        $percent = $quota > 0 ? min(100, (int)round(($used + $pending) / $quota * 100)) : 0;
    ?>
    <div class="col-md-4">
        <section class="glass-card p-4 h-100">
            <div class="d-flex justify-content-between gap-2">
                <strong><?= htmlspecialchars($summary['name']) ?></strong>
                <div class="d-flex gap-1">
                    <?php if ((int)$summary['status'] !== 1): ?><span class="badge bg-secondary-subtle text-secondary">ปิดใช้งาน</span><?php endif; ?>
                    <span class="badge bg-primary-subtle text-primary">ปี <?= $selectedYear ?></span>
                </div>
            </div>
            <div class="d-flex align-items-baseline gap-2 my-2">
                <span class="h2 fw-bold text-primary mb-0"><?= number_format($remaining, 1) ?></span>
                <span class="text-muted small">วันคงเหลือจาก <?= number_format($quota, 1) ?> วัน</span>
            </div>
            <div class="progress" role="progressbar" aria-label="ใช้สิทธิ์การลา" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar" style="width: <?= $percent ?>%"></div>
            </div>
            <p class="small text-muted mt-2 mb-0">ใช้แล้ว <?= number_format($used, 1) ?> วัน · รออนุมัติ <?= number_format($pending, 1) ?> วัน</p>
        </section>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-4 mb-4">
    <?php if ($viewEmployeeId === $myEmployeeId): ?>
    <div class="col-lg-5">
        <section class="glass-card p-4 h-100">
            <h2 class="h6 fw-bold mb-3">ยื่นคำขอลางาน</h2>
            <?php if (!$isLeaveRequired): ?>
                <div class="alert alert-info mb-0" role="status">ตำแหน่งของคุณไม่จำเป็นต้องยื่นคำขอลา</div>
            <?php else: ?>
            <form method="post" enctype="multipart/form-data" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="request">
                <div class="col-12"><label for="leaveType" class="form-label small">ประเภทการลา</label><select id="leaveType" name="leave_type_id" class="form-select" required><?php foreach ($leaveTypes as $type): ?><option value="<?= (int)$type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label for="leaveStart" class="form-label small">เริ่ม</label><input id="leaveStart" type="datetime-local" name="start_date" class="form-control" required></div>
                <div class="col-6"><label for="leaveEnd" class="form-label small">สิ้นสุด</label><input id="leaveEnd" type="datetime-local" name="end_date" class="form-control" required></div>
                <div class="col-12"><label for="leaveDays" class="form-label small">จำนวนวัน (เพิ่มครั้งละ 0.5 วัน)</label><input id="leaveDays" type="number" step="0.5" min="0.5" name="total_days" class="form-control" required></div>
                <div class="col-12"><label for="leaveReason" class="form-label small">เหตุผล</label><textarea id="leaveReason" name="reason" rows="2" class="form-control" required></textarea></div>
                <div class="col-12">
                    <label for="leaveAttachments" class="form-label small">เอกสารประกอบ (ไม่บังคับ)</label>
                    <input id="leaveAttachments" type="file" name="attachments[]" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="form-control" multiple>
                    <div class="form-text">แนบได้ไม่เกิน 5 ไฟล์ รองรับ PDF, JPG และ PNG ขนาดไม่เกิน 5 MB ต่อไฟล์</div>
                </div>
                <div class="col-12"><button class="btn btn-primary btn-modern w-100">ส่งคำขอลา</button></div>
            </form>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>

    <div class="col-lg-<?= $viewEmployeeId === $myEmployeeId ? '7' : '12' ?>">
        <section class="glass-card p-4 h-100">
            <h2 class="h6 fw-bold mb-3">ประวัติการลาปี <?= $selectedYear ?></h2>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light"><tr><th>ประเภท</th><th>วันที่</th><th>จำนวน</th><th>เหตุผล</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
                    <tbody>
                    <?php if (!$leaveHistory): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">ไม่มีรายการลาในปีนี้</td></tr>
                    <?php else: foreach ($leaveHistory as $request): ?>
                        <tr>
                            <td><?= htmlspecialchars($request['leave_name']) ?></td>
                            <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($request['start_date']))) ?></td>
                            <td><?= number_format((float)$request['total_days'], 1) ?> วัน</td>
                            <td>
                                <?= htmlspecialchars($request['reason'] ?? '') ?>
                                <?php foreach ($leaveAttachments[(int)$request['id']] ?? [] as $attachment): ?>
                                    <div><a href="<?= BASE_URL ?>/modules/ess/leave_attachment.php?id=<?= (int)$attachment['id'] ?>" target="_blank" rel="noopener" class="small"><?= htmlspecialchars($attachment['file_name']) ?></a></div>
                                <?php endforeach; ?>
                                <?php if (empty($leaveAttachments[(int)$request['id']]) && !empty($request['attachment_url']) && str_starts_with($request['attachment_url'], BASE_URL . '/uploads/')): ?>
                                    <div><a href="<?= BASE_URL ?>/modules/ess/leave_attachment.php?legacy_request_id=<?= (int)$request['id'] ?>" target="_blank" rel="noopener" class="small">ดูไฟล์แนบเดิม</a></div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars(getApprovalProgressLabel($request)) ?></td>
                            <td>
                                <?php if ($request['status'] === 'pending' && $viewEmployeeId === $myEmployeeId): ?>
                                    <form method="post" onsubmit="return confirm('ยกเลิกคำขอลานี้หรือไม่?')">
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

<?php include ROOT_PATH . '/includes/layout_bottom.php'; ?>
