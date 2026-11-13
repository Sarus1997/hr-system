<?php
    require_once __DIR__ . '/../../includes/auth.php';
    require_once ROOT_PATH . '/includes/ot_calculation.php';
    requireLogin();
    if (!hasRole(['admin', 'hr'])) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์เข้าถึงหน้านี้');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_ot'])) {
        requireValidCsrfToken();
        $employeeId = is_string($_POST['employee_id'] ?? null) ? trim($_POST['employee_id']) : '';
        $otDate = $_POST['ot_date'] ?? '';
        $startTime = $_POST['start_time'] ?? '';
        $endTime = $_POST['end_time'] ?? '';
        $otType = $_POST['ot_type'] ?? '';
        $reason = is_string($_POST['reason'] ?? null) ? trim($_POST['reason']) : '';
        $date = is_string($otDate) ? DateTimeImmutable::createFromFormat('!Y-m-d', $otDate) : false;
        $start = is_string($startTime) ? DateTimeImmutable::createFromFormat('!H:i', $startTime) : false;
        $end = is_string($endTime) ? DateTimeImmutable::createFromFormat('!H:i', $endTime) : false;

        if ($employeeId === '' || strlen($employeeId) > 20 || !$date || $date->format('Y-m-d') !== $otDate ||
            !$start || !$end || $start->format('H:i') !== $startTime || $end->format('H:i') !== $endTime ||
            !is_string($otType) || !array_key_exists($otType, getOtTypeLabels())) {
            $msg = 'กรุณาตรวจสอบข้อมูล OT ให้ถูกต้อง';
        } else {
            try {
                $otDetails = calculateOtDetails($startTime, $endTime, $otType);
            } catch (InvalidArgumentException $exception) {
                $msg = $exception->getMessage();
            }
            if (empty($msg)) {
                $employeeCheck = $pdo->prepare("SELECT id FROM employees WHERE id = ? AND status = 1 AND employment_status IN ('active', 'probation')");
                $employeeCheck->execute([$employeeId]);
                if (!$employeeCheck->fetchColumn()) {
                    $msg = 'ไม่พบพนักงานที่เลือก';
                } else {
                    $pdo->prepare("INSERT INTO ot_requests (employee_id, ot_date, start_time, end_time, ot_type, raw_hours, net_hours, hours, lunch_deducted, total_pay, reason, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved')")
                        ->execute([
                            $employeeId,
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
                        ]);
                    $msg = 'บันทึก OT ' . $otDetails['hours'] . ' ชั่วโมง มูลค่า ฿' . number_format($otDetails['total_pay'], 2);
                }
            }
        }
    }

    $records = $pdo->query("SELECT DATE(a.scan_time) AS work_date, e.first_name, e.last_name,
        MIN(CASE WHEN a.scan_type = 'check_in' THEN TIME(a.scan_time) END) AS time_in,
        MAX(CASE WHEN a.scan_type = 'check_out' THEN TIME(a.scan_time) END) AS time_out,
        s.shift_name, s.start_time AS shift_start, s.late_grace_minutes
        FROM attendance_logs a JOIN employees e ON a.employee_id = e.id
        LEFT JOIN employee_shifts es ON e.id = es.employee_id AND es.status = 1
        LEFT JOIN shifts s ON es.shift_id = s.id AND s.status = 1
        GROUP BY DATE(a.scan_time), e.id, s.id ORDER BY work_date DESC LIMIT 30")->fetchAll();
    $devices = $pdo->query("SELECT * FROM attendance_devices WHERE status = 1")->fetchAll();
    $emps    = $pdo->query("SELECT id, first_name, last_name FROM employees WHERE status = 1 AND employment_status IN ('active', 'probation')")->fetchAll();

    $pageTitle = 'เวลาทำงาน กะ โอที และเครื่องสแกน 3 มิติ';
    include ROOT_PATH . '/includes/layout_top.php';
?>
<div class="row g-4">
    <div class="col-lg-8 animate-up delay-1">
        <div class="glass-card p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-clock-fill text-primary me-2"></i>บันทึกเข้า-ออกงานและคำนวณมาสาย</h6>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light"><tr><th>วันที่</th><th>พนักงาน</th><th>กะงาน</th><th>เข้า</th><th>ออก</th><th>ผลลัพธ์</th></tr></thead>
                    <tbody>
                    <?php foreach ($records as $r):
                            $late = 0;
                            if ($r['time_in'] && $r['shift_start']) {
                                $diff = (strtotime($r['time_in']) - strtotime($r['shift_start'])) / 60;
                                if ($diff > ($r['late_grace_minutes'] ?? 0)) {
                                    $late = floor($diff);
                                }

                            }
                    ?>
                        <tr>
                            <td><?php echo $r['work_date'] ?></td>
                            <td class="fw-semibold"><?php echo htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></td>
                            <td><small class="text-muted"><?php echo htmlspecialchars($r['shift_name'] ?? '-') ?></small></td>
                            <td><?php echo $r['time_in'] ?? '-' ?></td>
                            <td><?php echo $r['time_out'] ?? '-' ?></td>
                            <td><?php echo $late > 0 ? "<span class='badge bg-danger-subtle text-danger rounded-pill'>สาย {$late} นาที</span>" : "<span class='badge bg-success-subtle text-success rounded-pill'>ตรงเวลา</span>" ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4 animate-up delay-2">
        <div class="glass-card p-4 mb-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-plus-circle-fill text-warning me-2"></i>บันทึกค่าล่วงเวลา (OT)</h6>
            <?php if (! empty($msg)) {
                    echo "<div class='alert alert-info py-2 small'>".htmlspecialchars($msg)."</div>";
                }
            ?>
            <form method="POST" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()) ?>">
                <div class="col-12"><select name="employee_id" class="form-select form-select-sm" required><?php foreach ($emps as $em) {
                                                                                                          echo "<option value='".htmlspecialchars($em['id'], ENT_QUOTES, 'UTF-8')."'>".htmlspecialchars($em['first_name'].' '.$em['last_name'])."</option>";
                                                                                                  }
                                                                                                  ?></select></div>
                <div class="col-12"><input type="date" name="ot_date" class="form-control form-control-sm" required></div>
                <div class="col-12"><select name="ot_type" id="adminOtType" class="form-select form-select-sm" required><?php foreach (getOtTypeLabels() as $type => $label): ?><option value="<?php echo htmlspecialchars($type) ?>"><?php echo htmlspecialchars($label) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><input type="time" name="start_time" id="adminOtStart" class="form-control form-control-sm" required></div>
                <div class="col-6"><input type="time" name="end_time" id="adminOtEnd" class="form-control form-control-sm" required></div>
                <div class="col-12"><input type="text" name="reason" placeholder="เหตุผลการทำงานล่วงเวลา" class="form-control form-control-sm"></div>
                <div class="col-12"><button type="submit" name="add_ot" class="btn btn-primary btn-modern w-100 btn-sm">บันทึก OT</button></div>
            </form>
        </div>

        <div class="glass-card p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-upc-scan text-success me-2"></i>เครื่องสแกนที่เชื่อมต่อ</h6>
            <?php foreach ($devices as $dv): ?>
                <div class="p-3 rounded-3 bg-light mb-2 border d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold small"><?php echo htmlspecialchars($dv['device_name']) ?></div>
                        <small class="text-muted"><?php echo $dv['device_sn'] ?> • <?php echo htmlspecialchars($dv['location']) ?></small>
                    </div>
                    <span class="pulse-dot"></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php include ROOT_PATH . '/includes/layout_bottom.php'; ?>