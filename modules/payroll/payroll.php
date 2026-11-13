<?php
    require_once __DIR__ . '/../../includes/auth.php';
    requireLogin();
    if (! hasRole(['admin', 'hr'])) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์เข้าถึงหน้านี้');
    }

    function calcSSO($s)
    {return round(min(max((float) $s, 0), 17500) * 0.05, 2);}
    function calcTax($inc, $sso)
    {
    $ann = $inc * 12;
    $net = max(0, $ann - min($ann * 0.5, 100000) - 60000 - ($sso * 12));
    $tax = 0;
    if ($net > 500000) {$tax += ($net - 500000) * 0.15;
        $net                             = 500000;}
    if ($net > 300000) {$tax += ($net - 300000) * 0.10;
        $net                             = 300000;}
    if ($net > 150000) {$tax += ($net - 150000) * 0.05;}
    return round($tax / 12, 2);
    }

    function nextPayrollPeriodId(PDO $pdo): string
    {
    $lastId   = $pdo->query("SELECT id FROM payroll_periods WHERE id REGEXP '^A-[0-9]{6}$' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $sequence = $lastId === false ? 0 : (int) substr($lastId, 2);
    if ($sequence >= 999999) {
        throw new RuntimeException('ไม่สามารถสร้างรหัสงวดเงินเดือนเพิ่มเติมได้');
    }

    return sprintf('A-%06d', $sequence + 1);
    }

    if (isset($_POST['run_payroll'])) {
    requireValidCsrfToken();
    $companyId = filter_var($_POST['company_id'] ?? null, FILTER_VALIDATE_INT);
    $periodName = is_string($_POST['period_name'] ?? null) ? trim($_POST['period_name']) : '';
    $startDate = is_string($_POST['start_date'] ?? null) ? $_POST['start_date'] : '';
    $endDate = is_string($_POST['end_date'] ?? null) ? $_POST['end_date'] : '';
    $startDateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
    $endDateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
    if ($companyId === false || $companyId < 1 || $periodName === '' || mb_strlen($periodName) > 50 ||
        !$startDateValue || $startDateValue->format('Y-m-d') !== $startDate ||
        !$endDateValue || $endDateValue->format('Y-m-d') !== $endDate || $endDateValue < $startDateValue) {
        http_response_code(400);
        exit('ข้อมูลงวดเงินเดือนไม่ถูกต้อง');
    }

    $companyStmt = $pdo->prepare('SELECT company_id FROM companies WHERE company_id = ? AND status = 1');
    $companyStmt->execute([$companyId]);
    if ($companyStmt->fetchColumn() === false) {
        http_response_code(400);
        exit('ไม่พบบริษัทที่เลือก');
    }

    $lockName = 'hr_system.payroll_period_sequence';
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lockStmt->execute([$lockName]);
    if ((int) $lockStmt->fetchColumn() !== 1) {
        throw new RuntimeException('ไม่สามารถจองรหัสงวดเงินเดือนได้ กรุณาลองใหม่');
    }

    $duplicatePeriod = false;
    try {
        $duplicateStmt = $pdo->prepare('SELECT id FROM payroll_periods WHERE company_id = ? AND start_date = ? AND end_date = ? LIMIT 1');
        $duplicateStmt->execute([$companyId, $startDate, $endDate]);
        if ($duplicateStmt->fetchColumn() !== false) {
            $duplicatePeriod = true;
        } else {
            $pdo->beginTransaction();
            $pid = nextPayrollPeriodId($pdo);
            $pdo->prepare("INSERT INTO payroll_periods (id, company_id, period_name, start_date, end_date, pay_date, status) VALUES (?, ?, ?, ?, ?, ?, 'calculated')")
                ->execute([$pid, $companyId, $periodName, $startDate, $endDate, $endDate]);

            $employees = $pdo->prepare("SELECT * FROM employees WHERE company_id = ? AND status = 1 AND employment_status IN ('active', 'probation')");
            $employees->execute([$companyId]);
            foreach ($employees as $emp) {
            $stmtOT = $pdo->prepare("SELECT COALESCE(SUM(total_pay),0) FROM ot_requests WHERE employee_id=? AND status='approved' AND ot_date BETWEEN ? AND ?");
            $stmtOT->execute([$emp['id'], $startDate, $endDate]);
            $otAmount = round((float) $stmtOT->fetchColumn(), 2);
            $sso      = calcSSO($emp['base_salary']);
            $tax      = calcTax($emp['base_salary'] + $otAmount, $sso);
            $net      = $emp['base_salary'] + $otAmount - $sso - $tax;

            $pdo->prepare("INSERT INTO payroll_items (period_id, employee_id, base_salary, ot_amount, social_security, withholding_tax, net_salary) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$pid, $emp['id'], $emp['base_salary'], $otAmount, $sso, $tax, $net]);
            }
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    } finally {
        $releaseLockStmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $releaseLockStmt->execute([$lockName]);
    }
    if ($duplicatePeriod) {
        http_response_code(409);
        exit('มีงวดเงินเดือนของบริษัทนี้ในช่วงวันที่ดังกล่าวแล้ว');
    }
    header("Location: payroll.php?period_id=$pid");
    exit;
    }

    if (isset($_POST['export_tiger']) && ! empty($_POST['period_id'])) {
    requireValidCsrfToken();
    $pid = is_string($_POST['period_id']) ? trim($_POST['period_id']) : '';
    if ($pid === '' || strlen($pid) > 20) {
        http_response_code(400);
        exit('รหัสงวดเงินเดือนไม่ถูกต้อง');
    }
    $pdo->prepare("INSERT INTO payroll_outsourcing_logs (period_id, exported_by) VALUES (?, ?)")->execute([$pid, $_SESSION['employee_id']]);
    $pdo->prepare("UPDATE payroll_periods SET status = 'outsourced' WHERE id = ?")->execute([$pid]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tiger_payroll_' . rawurlencode($pid) . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['EMP_CODE', 'ID_CARD', 'FULL_NAME', 'BASE_SALARY', 'OT_AMOUNT', 'SSO', 'TAX_PND1', 'NET_SALARY']);
    $rows = $pdo->prepare("SELECT e.emp_code, e.id_card_no, CONCAT(e.first_name,' ',e.last_name) AS name, pi.* FROM payroll_items pi JOIN employees e ON pi.employee_id=e.id WHERE pi.period_id=?");
    $rows->execute([$pid]);
    foreach ($rows as $r) {
        fputcsv($out, [$r['emp_code'], $r['id_card_no'], $r['name'], $r['base_salary'], $r['ot_amount'], $r['social_security'], $r['withholding_tax'], $r['net_salary']]);
    }

    fclose($out);
    exit;
    }

    $companies         = $pdo->query("SELECT company_id, company_name FROM companies WHERE status = 1 ORDER BY company_name")->fetchAll();
    $periods           = $pdo->query("SELECT pp.*, c.company_name FROM payroll_periods pp JOIN companies c ON c.company_id = pp.company_id ORDER BY pp.id DESC")->fetchAll();
    $requestedPeriodId = $_GET['period_id'] ?? null;
    $selectedPeriod    = is_string($requestedPeriodId) ? $requestedPeriodId : ($periods[0]['id'] ?? null);
    $items             = [];
    if ($selectedPeriod) {
    $stmt = $pdo->prepare("SELECT pi.*, e.emp_code, e.first_name, e.last_name FROM payroll_items pi JOIN employees e ON pi.employee_id=e.id WHERE pi.period_id=?");
    $stmt->execute([$selectedPeriod]);
    $items = $stmt->fetchAll();
    }
    $pageTitle = 'คำนวณเงินเดือน & Tiger Payroll';
    include ROOT_PATH . '/includes/layout_top.php';
?>

<div class="glass-card p-4 mb-4 animate-up">
    <h6 class="fw-bold mb-3">ประมวลผลงวดเงินเดือนใหม่ (คำนวณเงินเดือน + OT + ประกันสังคม + ภ.ง.ด.1)</h6>
    <form method="POST" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="col-md-3"><select name="company_id" class="form-select" required><option value="">เลือกบริษัท</option><?php foreach ($companies as $company): ?><option value="<?php echo (int) $company['company_id'] ?>"><?php echo htmlspecialchars($company['company_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><input type="text" name="period_name" placeholder="งวด เช่น 2025-05" class="form-control" required></div>
        <div class="col-md-2"><input type="date" name="start_date" class="form-control" required></div>
        <div class="col-md-2"><input type="date" name="end_date" class="form-control" required></div>
        <div class="col-md-3"><button type="submit" name="run_payroll" class="btn btn-primary btn-modern w-100"><i class="bi bi-calculator me-1"></i>คำนวณเงินเดือน</button></div>
    </form>
</div>

<?php if ($selectedPeriod): ?>
<div class="glass-card p-4 animate-up delay-1">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <form method="GET">
            <select name="period_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($periods as $pr) {
                        echo '<option value="' . htmlspecialchars($pr['id'], ENT_QUOTES, 'UTF-8') . '" ' . ($pr['id'] === $selectedPeriod ? 'selected' : '') . '>งวด ' . htmlspecialchars($pr['company_name'], ENT_QUOTES, 'UTF-8') . ' - ' . htmlspecialchars($pr['period_name'], ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($pr['status'], ENT_QUOTES, 'UTF-8') . ')</option>';
                    }
                ?>
            </select>
        </form>
        <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="period_id" value="<?php echo htmlspecialchars($selectedPeriod, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" name="export_tiger" value="1" class="btn btn-warning btn-modern"><i class="bi bi-box-arrow-up-right me-1"></i>ส่งออก CSV</button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light"><tr><th>พนักงาน</th><th>เงินเดือน</th><th>ค่า OT</th><th>สปส. (5%, สูงสุด 875 บาท)</th><th>ภาษี ภ.ง.ด.1</th><th>สุทธิ</th><th>สลิป</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td class="fw-semibold"><?php echo htmlspecialchars($it['first_name'] . ' ' . $it['last_name']) ?></td>
                    <td>฿<?php echo number_format($it['base_salary'], 2) ?></td>
                    <td class="text-primary">+฿<?php echo number_format($it['ot_amount'], 2) ?></td>
                    <td class="text-danger">-฿<?php echo number_format($it['social_security'], 2) ?></td>
                    <td class="text-danger">-฿<?php echo number_format($it['withholding_tax'], 2) ?></td>
                    <td class="fw-bold text-success">฿<?php echo number_format($it['net_salary'], 2) ?></td>
                    <td><a href="payslip.php?item_id=<?php echo $it['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill">ดูสลิป</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php include ROOT_PATH . '/includes/layout_bottom.php'; ?>