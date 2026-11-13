<?php
    require_once __DIR__ . '/includes/auth.php';
    requireLogin();

    $myEmployeeId = (string) ($_SESSION['employee_id'] ?? '');
    $canViewAll   = canViewAllEmployeeData();
    $departmentId = getEmployeeDepartmentId($pdo, $myEmployeeId);
    $scopeOptions = [
    'self'       => 'ของฉัน',
    'department' => 'แผนกของฉัน',
    ];
    if ($canViewAll) {
    $scopeOptions['all'] = 'ทั้งหมด';
    }
    $defaultScope  = $canViewAll ? 'all' : 'department';
    $selectedScope = $_GET['scope'] ?? $defaultScope;
    if (! is_string($selectedScope) || ! isset($scopeOptions[$selectedScope])) {
    http_response_code(isset($_GET['scope']) ? 400 : 500);
    exit('ขอบเขตข้อมูลไม่ถูกต้อง');
    }
    if (! $canViewAll && array_key_exists('company', $_GET)) {
    http_response_code(403);
    exit('คุณไม่มีสิทธิ์ดูข้อมูลรายบริษัท');
    }
    if ($selectedScope !== 'all' && array_key_exists('company', $_GET)) {
    http_response_code(400);
    exit('กรุณาเลือกมุมมองทั้งหมดก่อนเลือกบริษัท');
    }

    $companyOptions    = [];
    $companyCodes      = [];
    $selectedCompany   = 'all';
    $selectedCompanyId = null;
    if ($canViewAll) {
    $companyRows = $pdo->query(
        'SELECT company_id, company_code, company_name, status
             FROM companies ORDER BY company_name, company_id'
    )->fetchAll();
    $companyOptions['all'] = 'ทุกบริษัท';
    foreach ($companyRows as $company) {
        $companyId                  = (string) (int) $company['company_id'];
        $companyCodes[$companyId]   = $company['company_code'];
        $companyOptions[$companyId] = $company['company_code'] . ' · ' . $company['company_name']
            . ((int) $company['status'] === 1 ? '' : ' (ปิดใช้งาน)');
    }

    if (array_key_exists('company', $_GET)) {
        $requestedCompany = $_GET['company'];
        if (! is_string($requestedCompany) || ! array_key_exists($requestedCompany, $companyOptions)) {
            http_response_code(400);
            exit('บริษัทที่เลือกไม่ถูกต้อง');
        }
        $selectedCompany = $requestedCompany;
    }
    if ($selectedCompany !== 'all') {
        $selectedCompanyId = (int) $selectedCompany;
    }
    }

    $employeeScope = '';
    $scopeParams   = [];
    if ($selectedScope === 'self') {
    $employeeScope = ' AND e.id = ?';
    $scopeParams   = [$myEmployeeId];
    } elseif ($selectedScope === 'department') {
    $employeeScope = ' AND (e.department_id = ? OR e.id = ?)';
    $scopeParams   = [$departmentId, $myEmployeeId];
    }
    $scopeLabel = $selectedScope === 'department' && $departmentId === null
    ? 'ข้อมูลของฉัน (ยังไม่ได้กำหนดแผนก)'
    : $scopeOptions[$selectedScope];
    if ($selectedScope === 'all') {
    $scopeLabel .= ' · ' . ($selectedCompany === 'all' ? 'ทุกบริษัท' : $companyCodes[$selectedCompany]);
    }
    $companyEmployeeScope   = $selectedCompanyId === null ? '' : ' AND e.company_id = ?';
    $companyParams          = $selectedCompanyId === null ? [] : [$selectedCompanyId];
    $dashboardEmployeeScope = $employeeScope . $companyEmployeeScope;
    $dashboardParams        = array_merge($scopeParams, $companyParams);
    $showMyStats            = $selectedScope !== 'self' && $selectedCompanyId === null;
    $pageTitle              = 'แดชบอร์ดส่วนตัวและภาพรวม';

    $totalEmpStmt = $pdo->prepare("SELECT COUNT(*) FROM employees e WHERE e.status = 1 AND e.employment_status IN ('active', 'probation'){$dashboardEmployeeScope}");
    $totalEmpStmt->execute($dashboardParams);
    $totalEmp = $totalEmpStmt->fetchColumn();

    $todayInStmt = $pdo->prepare("SELECT COUNT(DISTINCT a.employee_id)
        FROM attendance_logs a JOIN employees e ON e.id = a.employee_id
        WHERE e.status = 1 AND e.employment_status IN ('active', 'probation')
          AND DATE(a.scan_time) = CURDATE() AND a.scan_type = 'check_in'{$dashboardEmployeeScope}");
    $todayInStmt->execute($dashboardParams);
    $todayIn = $todayInStmt->fetchColumn();

    $pendingLeaveStmt = $pdo->prepare("SELECT COUNT(*)
        FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id
        WHERE lr.status = 'pending'{$dashboardEmployeeScope}");
    $pendingLeaveStmt->execute($dashboardParams);
    $pendingLeaves = $pendingLeaveStmt->fetchColumn();

    $pendingOTStmt = $pdo->prepare("SELECT COUNT(*)
        FROM ot_requests o JOIN employees e ON e.id = o.employee_id
        WHERE o.status = 'pending'{$dashboardEmployeeScope}");
    $pendingOTStmt->execute($dashboardParams);
    $pendingOTs = $pendingOTStmt->fetchColumn();

    $myCheckinStmt = $pdo->prepare("SELECT COUNT(DISTINCT DATE(scan_time))
        FROM attendance_logs WHERE employee_id = ? AND DATE(scan_time) = CURDATE() AND scan_type = 'check_in'");
    $myCheckinStmt->execute([$myEmployeeId]);
    $myTodayIn = $myCheckinStmt->fetchColumn();

    $myPendingLeaveStmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND status = 'pending'");
    $myPendingLeaveStmt->execute([$myEmployeeId]);
    $myPendingLeaves = $myPendingLeaveStmt->fetchColumn();

    $myPendingOTStmt = $pdo->prepare("SELECT COUNT(*) FROM ot_requests WHERE employee_id = ? AND status = 'pending'");
    $myPendingOTStmt->execute([$myEmployeeId]);
    $myPendingOTs = $myPendingOTStmt->fetchColumn();

    $logsSql = "SELECT a.*, e.first_name, e.last_name, e.profile_image_url
        FROM attendance_logs a JOIN employees e ON a.employee_id = e.id";
    $logsWhere  = ["e.status = 1", "e.employment_status IN ('active', 'probation')"];
    $logsParams = [];
    if (! $canViewAll || $selectedScope === 'self') {
    $logsWhere[] = 'a.employee_id = ?';
    $logsParams  = [$myEmployeeId];
    } elseif ($selectedScope === 'department') {
    $logsWhere[] = '(e.department_id = ? OR a.employee_id = ?)';
    $logsParams  = [$departmentId, $myEmployeeId];
    }
    if ($selectedCompanyId !== null) {
    $logsWhere[]  = 'e.company_id = ?';
    $logsParams[] = $selectedCompanyId;
    }
    if ($logsWhere) {
    $logsSql .= ' WHERE ' . implode(' AND ', $logsWhere);
    }
    $logsSql  .= ' ORDER BY a.scan_time DESC LIMIT 6';
    $logsStmt = $pdo->prepare($logsSql);
    $logsStmt->execute($logsParams);
    $logs = $logsStmt->fetchAll();

    if ($selectedScope === 'all') {
    $departmentLabelSql = $selectedCompanyId === null
        ? "CONCAT(c.company_code, ' · ', d.name)"
        : 'd.name';
    $deptStatsSql = "SELECT {$departmentLabelSql} AS name, COUNT(e.id) AS cnt
            FROM departments d
            JOIN companies c ON c.company_id = d.company_id
            LEFT JOIN employees e ON d.id = e.department_id AND d.company_id = e.company_id
                AND e.status = 1 AND e.employment_status IN ('active', 'probation')
            WHERE d.status = 1";
    $deptStatsParams = [];
    if ($selectedCompanyId !== null) {
        $deptStatsSql      .= ' AND d.company_id = ?';
        $deptStatsParams[]  = $selectedCompanyId;
    }
    $deptStatsSql  .= ' GROUP BY d.id, c.company_code, d.name ORDER BY c.company_code, d.name';
    $deptStatsStmt  = $pdo->prepare($deptStatsSql);
    $deptStatsStmt->execute($deptStatsParams);
    $deptStats  = $deptStatsStmt->fetchAll();
    } elseif ($selectedScope === 'self') {
    $deptStatsStmt = $pdo->prepare("SELECT 'ฉัน' AS name, COUNT(*) AS cnt
            FROM employees e WHERE e.id = ? AND e.status = 1 AND e.employment_status IN ('active', 'probation')");
    $deptStatsStmt->execute([$myEmployeeId]);
    $deptStats = $deptStatsStmt->fetchAll();
    } elseif ($departmentId !== null) {
    $deptStatsStmt = $pdo->prepare("SELECT d.name, COUNT(e.id) AS cnt
            FROM departments d
            LEFT JOIN employees e ON d.id = e.department_id AND e.status = 1 AND e.employment_status IN ('active', 'probation')
            WHERE d.id = ? AND d.status = 1 GROUP BY d.id");
    $deptStatsStmt->execute([$departmentId]);
    $deptStats = $deptStatsStmt->fetchAll();
    } else {
    $deptStats = [];
    }

    include ROOT_PATH . '/includes/layout_top.php';
?>

<style>
    /* ---------- Page-scoped styles (หน้า Dashboard) ---------- */

    /* หัวหน้า */
    .dash-head {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: flex-end;
        gap: 1rem 1.5rem;
        margin-bottom: 1.75rem;
    }
    .dash-head h2 {
        font-size: 1.35rem;
        font-weight: 700;
        letter-spacing: -0.02em;
        margin: 0 0 0.2rem;
    }
    .dash-head p {
        margin: 0;
        color: var(--ink-3);
        font-size: 0.88rem;
    }
    .scope-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: 0.6rem;
        padding: 0.28rem 0.7rem;
        border-radius: 999px;
        background: var(--primary-soft);
        color: var(--primary);
        font-size: 0.78rem;
        font-weight: 600;
    }

    /* ตัวสลับขอบเขตข้อมูลแบบ segmented */
    .seg {
        display: inline-flex;
        padding: 4px;
        gap: 2px;
        background: #e9ecf5;
        border-radius: 12px;
    }
    .seg-item {
        padding: 0.42rem 1rem;
        border-radius: 9px;
        font-size: 0.85rem;
        font-weight: 500;
        color: var(--ink-3);
        text-decoration: none;
        transition: color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
    }
    .seg-item:hover { color: var(--ink); }
    .seg-item.is-active {
        background: #fff;
        color: var(--primary);
        font-weight: 600;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08), 0 2px 8px -2px rgba(15, 23, 42, 0.1);
    }
    .seg-item:focus-visible {
        outline: 2px solid var(--primary-light);
        outline-offset: 2px;
    }

    .company-select {
        min-width: 210px;
        border-radius: 10px;
        border-color: var(--line-strong);
        background-color: #fff;
        font-size: 0.85rem;
        padding-top: 0.45rem;
        padding-bottom: 0.45rem;
        box-shadow: var(--shadow-xs);
    }
    .company-select:focus {
        border-color: var(--primary-light);
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
    }
    .field-label {
        display: block;
        margin-bottom: 0.25rem;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--ink-3);
    }

    /* KPI */
    .kpi-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.75rem;
    }
    .kpi-label {
        font-size: 0.86rem;
        font-weight: 500;
        opacity: 0.88;
    }
    .kpi-icon {
        flex: 0 0 auto;
        width: 40px;
        height: 40px;
        display: grid;
        place-items: center;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.18);
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.22);
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
        font-size: 1.1rem;
    }
    .kpi-value {
        margin-top: 0.9rem;
        font-size: 2.15rem;
        font-weight: 800;
        line-height: 1;
        letter-spacing: -0.03em;
        font-variant-numeric: tabular-nums;
    }
    .kpi-value small {
        margin-left: 0.25rem;
        font-size: 0.85rem;
        font-weight: 500;
        letter-spacing: 0;
        opacity: 0.8;
    }
    .kpi-foot {
        margin-top: 0.7rem;
        padding-top: 0.6rem;
        border-top: 1px solid rgba(255, 255, 255, 0.18);
        font-size: 0.78rem;
        opacity: 0.85;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* หัวการ์ด */
    .panel-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        margin-bottom: 1.25rem;
    }
    .panel-title {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        font-size: 0.98rem;
        font-weight: 700;
        margin: 0;
        letter-spacing: -0.01em;
    }
    .panel-title-icon {
        width: 34px;
        height: 34px;
        display: grid;
        place-items: center;
        flex: 0 0 auto;
        border-radius: 10px;
        font-size: 0.95rem;
    }
    .tone-primary { background: var(--primary-soft); color: var(--primary); }
    .tone-success { background: #e3f6ee; color: #047857; }
    .panel-tag {
        padding: 0.3rem 0.7rem;
        border-radius: 999px;
        background: var(--surface-muted);
        border: 1px solid var(--line);
        color: var(--ink-3);
        font-size: 0.72rem;
        font-weight: 600;
        white-space: nowrap;
    }

    /* รายการสแกน */
    .scan-list { display: flex; flex-direction: column; }
    .scan-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.75rem;
        padding: 0.8rem 0;
        border-bottom: 1px solid var(--line);
    }
    .scan-row:last-child { border-bottom: 0; padding-bottom: 0; }
    .scan-row:first-child { padding-top: 0; }
    .scan-person { display: flex; align-items: center; gap: 0.8rem; min-width: 0; }
    .scan-name {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--ink);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .scan-time { font-size: 0.78rem; color: var(--ink-3); font-variant-numeric: tabular-nums; }
    .scan-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 0.28rem 0.7rem;
        border-radius: 999px;
        font-size: 0.74rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .scan-badge::before {
        content: '';
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
    }
    .scan-in  { background: #e3f6ee; color: #047857; }
    .scan-out { background: #fdf1d8; color: #a15c07; }

    /* สถานะว่าง */
    .empty-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 3rem 1rem;
        text-align: center;
        color: var(--ink-3);
        font-size: 0.88rem;
    }
    .empty-state-icon {
        width: 52px;
        height: 52px;
        display: grid;
        place-items: center;
        border-radius: 16px;
        background: var(--surface-muted);
        border: 1px dashed var(--line-strong);
        font-size: 1.3rem;
        color: #94a3b8;
    }
    .empty-state strong { color: var(--ink-2); font-weight: 600; }

    .chart-wrap { position: relative; min-height: 260px; }
</style>

<?php
    $avatarStyleSmall = 'width:36px;height:36px;font-size:0.78rem;';
    $dataTitle        = $selectedScope === 'all'
    ? ($selectedCompanyId === null ? 'จำนวนพนักงานแยกตามบริษัทและแผนก' : 'จำนวนพนักงานแยกตามแผนก')
    : ($selectedScope === 'department' ? 'จำนวนพนักงานในแผนกของฉัน' : 'จำนวนพนักงานของฉัน');
    $logsTitle = $canViewAll && $selectedScope !== 'self' ? 'บันทึกสแกนล่าสุด' : 'บันทึกสแกนของฉันล่าสุด';
?>

<header class="dash-head animate-up" aria-label="ขอบเขตข้อมูลแดชบอร์ด">
    <div>
        <h2>แสดงข้อมูล</h2>
        <p>เลือกดูข้อมูลส่วนตัวหรือภาพรวมตามสิทธิ์ของคุณ</p>
        <span class="scope-chip"><i class="bi bi-funnel-fill" aria-hidden="true"></i><?php echo htmlspecialchars($scopeLabel) ?></span>
    </div>

    <nav class="d-flex flex-wrap align-items-end gap-3" aria-label="ตัวกรองข้อมูล">
        <?php if ($canViewAll && $selectedScope === 'all'): ?>
            <form method="get" action="<?php echo BASE_URL ?>/index.php">
                <input type="hidden" name="scope" value="all">
                <label for="dashboardCompany" class="field-label">บริษัท</label>
                <select id="dashboardCompany" name="company" class="form-select form-select-sm company-select" onchange="this.form.requestSubmit()">
                    <?php foreach ($companyOptions as $companyId => $companyLabel): ?>
                        <option value="<?php echo htmlspecialchars($companyId) ?>" <?php echo $selectedCompany === (string)$companyId ? 'selected' : '' ?>>
                            <?php echo htmlspecialchars($companyLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>

        <div class="seg" role="group" aria-label="เลือกขอบเขตข้อมูล">
            <?php foreach ($scopeOptions as $scope => $label): ?>
                <?php $scopeQuery                                                                  = ['scope' => $scope]; ?>
                <?php if ($scope === 'all' && $selectedCompany !== 'all'): $scopeQuery['company']  = $selectedCompany;endif; ?>
                <a href="<?php echo BASE_URL ?>/index.php?<?php echo htmlspecialchars(http_build_query($scopeQuery)) ?>"
                   class="seg-item <?php echo $selectedScope === $scope ? 'is-active' : '' ?>"
                   <?php echo $selectedScope === $scope ? 'aria-current="page"' : '' ?>>
                    <?php echo htmlspecialchars($label) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>
</header>

<div class="row g-4 mb-4">
    <div class="col-sm-6 col-xl-3 animate-up delay-1">
        <div class="glass-card glass-card-hover kpi-gradient-1 p-4 h-100 dashboard-kpi-card">
            <div class="kpi-top">
                <span class="kpi-label"><?php echo $selectedScope === 'self' ? 'พนักงานของฉัน' : 'พนักงานทั้งหมด' ?></span>
                <span class="kpi-icon"><i class="bi bi-people-fill" aria-hidden="true"></i></span>
            </div>
            <div class="kpi-value"><?php echo (int)$totalEmp ?><small>คน</small></div>
            <div class="kpi-foot"><?php echo htmlspecialchars($scopeLabel) ?></div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3 animate-up delay-2">
        <div class="glass-card glass-card-hover kpi-gradient-2 p-4 h-100 dashboard-kpi-card">
            <div class="kpi-top">
                <span class="kpi-label">เช็กอินวันนี้</span>
                <span class="kpi-icon"><i class="bi bi-person-check-fill" aria-hidden="true"></i></span>
            </div>
            <div class="kpi-value"><?php echo (int)$todayIn ?><small>คน</small></div>
            <div class="kpi-foot">
                <?php echo $showMyStats ? 'ของฉัน: ' . (int)$myTodayIn . ' คน' : htmlspecialchars($scopeLabel) ?>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3 animate-up delay-3">
        <div class="glass-card glass-card-hover kpi-gradient-4 p-4 h-100 dashboard-kpi-card">
            <div class="kpi-top">
                <span class="kpi-label">คำขอลารออนุมัติ</span>
                <span class="kpi-icon"><i class="bi bi-calendar2-week-fill" aria-hidden="true"></i></span>
            </div>
            <div class="kpi-value"><?php echo (int)$pendingLeaves ?><small>รายการ</small></div>
            <div class="kpi-foot">
                <?php echo $showMyStats ? 'ของฉัน: ' . (int)$myPendingLeaves . ' รายการ' : htmlspecialchars($scopeLabel) ?>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3 animate-up delay-4">
        <div class="glass-card glass-card-hover kpi-gradient-3 p-4 h-100 dashboard-kpi-card">
            <div class="kpi-top">
                <span class="kpi-label">คำขอ OT รออนุมัติ</span>
                <span class="kpi-icon"><i class="bi bi-wallet2" aria-hidden="true"></i></span>
            </div>
            <div class="kpi-value"><?php echo (int)$pendingOTs ?><small>รายการ</small></div>
            <div class="kpi-foot">
                <?php echo $showMyStats ? 'ของฉัน: ' . (int)$myPendingOTs . ' รายการ' : htmlspecialchars($scopeLabel) ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7 animate-up delay-2">
        <section class="glass-card p-4 h-100">
            <div class="panel-head">
                <h3 class="panel-title">
                    <span class="panel-title-icon tone-primary"><i class="bi bi-bar-chart-fill" aria-hidden="true"></i></span>
                    <?php echo htmlspecialchars($dataTitle) ?>
                </h3>
            </div>
            <?php if ($deptStats): ?>
                <div class="chart-wrap">
                    <canvas id="deptChart" aria-label="<?php echo htmlspecialchars($dataTitle) ?>" role="img"></canvas>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <span class="empty-state-icon"><i class="bi bi-diagram-3" aria-hidden="true"></i></span>
                    <strong>ไม่พบข้อมูลแผนก</strong>
                    ยังไม่ได้กำหนดแผนกให้บัญชีนี้ ติดต่อฝ่ายบุคคลเพื่อกำหนดแผนก
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-lg-5 animate-up delay-3">
        <section class="glass-card p-4 h-100">
            <div class="panel-head">
                <h3 class="panel-title">
                    <span class="panel-title-icon tone-success"><i class="bi bi-cpu-fill" aria-hidden="true"></i></span>
                    <?php echo htmlspecialchars($logsTitle) ?>
                </h3>
                <span class="panel-tag">3D Face / GPS</span>
            </div>

            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <span class="empty-state-icon"><i class="bi bi-fingerprint" aria-hidden="true"></i></span>
                    <strong>ยังไม่มีการลงเวลา</strong>
                    เมื่อมีการสแกนเข้า–ออกงาน รายการจะแสดงที่นี่
                </div>
            <?php else: ?>
                <div class="scan-list">
                <?php foreach ($logs as $l): ?>
                    <?php $isIn  = $l['scan_type'] == 'check_in'; ?>
                    <div class="scan-row">
                        <div class="scan-person">
                            <div class="avatar-circle" style="<?php echo $avatarStyleSmall ?>">
                                <?php $profileImageUrl  = getSafeProfileImageUrl($l['profile_image_url'] ?? null); ?>
                                <?php if ($profileImageUrl !== null): ?>
                                    <img class="avatar-image" src="<?php echo htmlspecialchars($profileImageUrl) ?>" alt="">
                                <?php else: ?>
                                    <?php echo htmlspecialchars(mb_substr($l['first_name'], 0, 1)) ?>
                                <?php endif; ?>
                            </div>
                            <div class="min-w-0">
                                <div class="scan-name"><?php echo htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) ?></div>
                                <div class="scan-time"><?php echo date('d/m H:i น.', strtotime($l['scan_time'])) ?></div>
                            </div>
                        </div>
                        <span class="scan-badge <?php echo $isIn ? 'scan-in' : 'scan-out' ?>">
                            <?php echo htmlspecialchars((string)$l['verify_mode']) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php if ($deptStats): ?>
<script>
(function () {
    var canvas = document.getElementById('deptChart');
    if (!canvas || typeof Chart === 'undefined') return;

    Chart.defaults.font.family = "'Plus Jakarta Sans', 'IBM Plex Sans Thai', sans-serif";
    Chart.defaults.color = '#64748b';

    /* แท่งกราฟไล่สีแนวตั้งให้ดูมีมิติ */
    function barFill(context) {
        var chart = context.chart;
        var area = chart.chartArea;
        if (!area) return '#6366f1';
        var g = chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
        g.addColorStop(0, '#6366f1');
        g.addColorStop(1, '#4338ca');
        return g;
    }

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($deptStats, 'name'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            datasets: [{
                label: 'จำนวนพนักงาน (คน)',
                data: <?php echo json_encode(array_column($deptStats, 'cnt')) ?>,
                backgroundColor: barFill,
                hoverBackgroundColor: '#4f46e5',
                borderRadius: { topLeft: 10, topRight: 10, bottomLeft: 4, bottomRight: 4 },
                borderSkipped: false,
                maxBarThickness: 44
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0f172a',
                    padding: 12,
                    cornerRadius: 10,
                    displayColors: false,
                    titleFont: { weight: '600' },
                    callbacks: {
                        label: function (item) { return item.parsed.y + ' คน'; }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: { maxRotation: 0, autoSkip: false }
                },
                y: {
                    beginAtZero: true,
                    border: { display: false },
                    grid: { color: '#eef0f6', drawTicks: false },
                    ticks: { stepSize: 1, precision: 0, padding: 8 }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php include ROOT_PATH . '/includes/layout_bottom.php'; ?>