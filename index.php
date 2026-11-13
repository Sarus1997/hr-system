<?php
    require_once __DIR__ . '/includes/auth.php';
    requireLogin();

    $myEmployeeId = (string)($_SESSION['employee_id'] ?? '');
    $canViewAll = canViewAllEmployeeData();
    $departmentId = getEmployeeDepartmentId($pdo, $myEmployeeId);
    $scopeOptions = [
        'self' => 'ของฉัน',
        'department' => 'แผนกของฉัน',
    ];
    if ($canViewAll) {
        $scopeOptions['all'] = 'ทั้งหมด';
    }
    $defaultScope = $canViewAll ? 'all' : 'department';
    $selectedScope = $_GET['scope'] ?? $defaultScope;
    if (!is_string($selectedScope) || !isset($scopeOptions[$selectedScope])) {
        http_response_code(isset($_GET['scope']) ? 400 : 500);
        exit('ขอบเขตข้อมูลไม่ถูกต้อง');
    }
    if (!$canViewAll && array_key_exists('company', $_GET)) {
        http_response_code(403);
        exit('คุณไม่มีสิทธิ์ดูข้อมูลรายบริษัท');
    }
    if ($selectedScope !== 'all' && array_key_exists('company', $_GET)) {
        http_response_code(400);
        exit('กรุณาเลือกมุมมองทั้งหมดก่อนเลือกบริษัท');
    }

    $companyOptions = [];
    $companyCodes = [];
    $selectedCompany = 'all';
    $selectedCompanyId = null;
    if ($canViewAll) {
        $companyRows = $pdo->query(
            'SELECT company_id, company_code, company_name, status
             FROM companies ORDER BY company_name, company_id'
        )->fetchAll();
        $companyOptions['all'] = 'ทุกบริษัท';
        foreach ($companyRows as $company) {
            $companyId = (string)(int)$company['company_id'];
            $companyCodes[$companyId] = $company['company_code'];
            $companyOptions[$companyId] = $company['company_code'] . ' · ' . $company['company_name']
                . ((int)$company['status'] === 1 ? '' : ' (ปิดใช้งาน)');
        }

        if (array_key_exists('company', $_GET)) {
            $requestedCompany = $_GET['company'];
            if (!is_string($requestedCompany) || !array_key_exists($requestedCompany, $companyOptions)) {
                http_response_code(400);
                exit('บริษัทที่เลือกไม่ถูกต้อง');
            }
            $selectedCompany = $requestedCompany;
        }
        if ($selectedCompany !== 'all') {
            $selectedCompanyId = (int)$selectedCompany;
        }
    }

    $employeeScope = '';
    $scopeParams = [];
    if ($selectedScope === 'self') {
        $employeeScope = ' AND e.id = ?';
        $scopeParams = [$myEmployeeId];
    } elseif ($selectedScope === 'department') {
        $employeeScope = ' AND (e.department_id = ? OR e.id = ?)';
        $scopeParams = [$departmentId, $myEmployeeId];
    }
    $scopeLabel = $selectedScope === 'department' && $departmentId === null
        ? 'ข้อมูลของฉัน (ยังไม่ได้กำหนดแผนก)'
        : $scopeOptions[$selectedScope];
    if ($selectedScope === 'all') {
        $scopeLabel .= ' · ' . ($selectedCompany === 'all' ? 'ทุกบริษัท' : $companyCodes[$selectedCompany]);
    }
    $companyEmployeeScope = $selectedCompanyId === null ? '' : ' AND e.company_id = ?';
    $companyParams = $selectedCompanyId === null ? [] : [$selectedCompanyId];
    $dashboardEmployeeScope = $employeeScope . $companyEmployeeScope;
    $dashboardParams = array_merge($scopeParams, $companyParams);
    $showMyStats = $selectedScope !== 'self' && $selectedCompanyId === null;
    $pageTitle = 'แดชบอร์ดส่วนตัวและภาพรวม';

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
    $logsWhere = ["e.status = 1", "e.employment_status IN ('active', 'probation')"];
    $logsParams = [];
    if (!$canViewAll || $selectedScope === 'self') {
        $logsWhere[] = 'a.employee_id = ?';
        $logsParams = [$myEmployeeId];
    } elseif ($selectedScope === 'department') {
        $logsWhere[] = '(e.department_id = ? OR a.employee_id = ?)';
        $logsParams = [$departmentId, $myEmployeeId];
    }
    if ($selectedCompanyId !== null) {
        $logsWhere[] = 'e.company_id = ?';
        $logsParams[] = $selectedCompanyId;
    }
    if ($logsWhere) {
        $logsSql .= ' WHERE ' . implode(' AND ', $logsWhere);
    }
    $logsSql .= ' ORDER BY a.scan_time DESC LIMIT 6';
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
            $deptStatsSql .= ' AND d.company_id = ?';
            $deptStatsParams[] = $selectedCompanyId;
        }
        $deptStatsSql .= ' GROUP BY d.id, c.company_code, d.name ORDER BY c.company_code, d.name';
        $deptStatsStmt = $pdo->prepare($deptStatsSql);
        $deptStatsStmt->execute($deptStatsParams);
        $deptStats = $deptStatsStmt->fetchAll();
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

<nav class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4" aria-label="ขอบเขตข้อมูลแดชบอร์ด">
    <div>
        <h2 class="h6 fw-bold mb-1">แสดงข้อมูล</h2>
        <p class="small text-muted mb-0">เลือกดูข้อมูลส่วนตัวหรือภาพรวมตามสิทธิ์ของคุณ</p>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <?php if ($canViewAll && $selectedScope === 'all'): ?>
            <form method="get" action="<?= BASE_URL ?>/index.php" class="d-flex flex-wrap align-items-end gap-2">
                <input type="hidden" name="scope" value="all">
                <div>
                    <label for="dashboardCompany" class="form-label small mb-1">บริษัท</label>
                    <select id="dashboardCompany" name="company" class="form-select form-select-sm" onchange="this.form.requestSubmit()">
                        <?php foreach ($companyOptions as $companyId => $companyLabel): ?>
                            <option value="<?= htmlspecialchars($companyId) ?>" <?= $selectedCompany === (string)$companyId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($companyLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        <?php endif; ?>
        <div class="btn-group btn-group-sm" role="group" aria-label="เลือกขอบเขตข้อมูล">
            <?php foreach ($scopeOptions as $scope => $label): ?>
                <?php $scopeQuery = ['scope' => $scope]; ?>
                <?php if ($scope === 'all' && $selectedCompany !== 'all'): $scopeQuery['company'] = $selectedCompany; endif; ?>
                <a href="<?= BASE_URL ?>/index.php?<?= htmlspecialchars(http_build_query($scopeQuery)) ?>"
                   class="btn <?= $selectedScope === $scope ? 'btn-primary' : 'btn-outline-primary' ?>"
                   <?= $selectedScope === $scope ? 'aria-current="page"' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</nav>

<div class="row g-4 mb-4">
    <div class="col-sm-6 col-xl-3 animate-up delay-1">
        <div class="glass-card glass-card-hover kpi-gradient-1 p-4 h-100 dashboard-kpi-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="opacity-75 small"><?= $selectedScope === 'self' ? 'พนักงานของฉัน' : 'พนักงานในภาพรวม (' . htmlspecialchars($scopeLabel) . ')' ?></div>
                    <h2 class="fw-bold mb-0 mt-1"><?php echo $totalEmp ?> <span class="fs-6 fw-normal">คน</span></h2>
                </div>
                <i class="bi bi-people-fill fs-1 opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3 animate-up delay-2">
        <div class="glass-card glass-card-hover kpi-gradient-2 p-4 h-100 dashboard-kpi-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="opacity-75 small">เช็กอินวันนี้ (<?= htmlspecialchars($scopeLabel) ?>)</div>
                    <h2 class="fw-bold mb-0 mt-1"><?php echo $todayIn ?> <span class="fs-6 fw-normal">คน</span></h2>
                    <?php if ($showMyStats): ?><div class="small opacity-75 mt-1">ของฉัน: <?= (int)$myTodayIn ?> คน</div><?php endif; ?>
                </div>
                <i class="bi bi-person-check-fill fs-1 opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3 animate-up delay-3">
        <div class="glass-card glass-card-hover kpi-gradient-4 p-4 h-100 dashboard-kpi-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="opacity-75 small">คำขอลารออนุมัติ (<?= htmlspecialchars($scopeLabel) ?>)</div>
                    <h2 class="fw-bold mb-0 mt-1"><?php echo $pendingLeaves ?> <span class="fs-6 fw-normal">รายการ</span></h2>
                    <?php if ($showMyStats): ?><div class="small opacity-75 mt-1">ของฉัน: <?= (int)$myPendingLeaves ?> รายการ</div><?php endif; ?>
                </div>
                <i class="bi bi-calendar2-week-fill fs-1 opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3 animate-up delay-4">
        <div class="glass-card glass-card-hover kpi-gradient-3 p-4 h-100 dashboard-kpi-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="opacity-75 small">คำขอ OT รออนุมัติ (<?= htmlspecialchars($scopeLabel) ?>)</div>
                    <h2 class="fw-bold mb-0 mt-1"><?php echo $pendingOTs ?> <span class="fs-6 fw-normal">รายการ</span></h2>
                    <?php if ($showMyStats): ?><div class="small opacity-75 mt-1">ของฉัน: <?= (int)$myPendingOTs ?> รายการ</div><?php endif; ?>
                </div>
                <i class="bi bi-wallet2 fs-1 opacity-50"></i>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7 animate-up delay-2">
        <div class="glass-card p-4 h-100">
            <h6 class="fw-bold mb-3"><i class="bi bi-bar-chart-fill text-primary me-2"></i><?= $selectedScope === 'all' ? ($selectedCompanyId === null ? 'จำนวนพนักงานแยกตามบริษัทและแผนก' : 'จำนวนพนักงานแยกตามแผนก') : ($selectedScope === 'department' ? 'จำนวนพนักงานในแผนกของฉัน' : 'จำนวนพนักงานของฉัน') ?></h6>
            <?php if ($deptStats): ?>
                <canvas id="deptChart" height="130"></canvas>
            <?php else: ?>
                <div class="text-center text-muted py-5">ไม่พบข้อมูลแผนก</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5 animate-up delay-3">
        <div class="glass-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0"><i class="bi bi-cpu-fill text-success me-2"></i><?= $canViewAll && $selectedScope !== 'self' ? 'บันทึกสแกนล่าสุด' : 'บันทึกสแกนของฉันล่าสุด' ?></h6>
                <span class="badge bg-primary-subtle text-primary">3D Face / GPS</span>
            </div>
            <?php if (empty($logs)): ?>
                <div class="text-center text-muted py-5">ยังไม่มีการลงเวลาในวันนี้</div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                <?php foreach ($logs as $l): ?>
                    <div class="list-group-item px-0 d-flex justify-content-between align-items-center border-light">
                        <div class="d-flex align-items-center gap-3">
                            <div class="avatar-circle" style="width:34px;height:34px;font-size:0.75rem;">
                                <?php $profileImageUrl = getSafeProfileImageUrl($l['profile_image_url'] ?? null); ?>
                                <?php if ($profileImageUrl !== null): ?>
                                    <img class="avatar-image" src="<?php echo htmlspecialchars($profileImageUrl) ?>" alt="">
                                <?php else: ?>
                                    <?php echo htmlspecialchars(mb_substr($l['first_name'], 0, 1)) ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="fw-semibold small"><?php echo htmlspecialchars($l['first_name'].' '.$l['last_name']) ?></div>
                                <small class="text-muted"><?php echo date('d/m H:i น.', strtotime($l['scan_time'])) ?></small>
                            </div>
                        </div>
                        <span class="badge rounded-pill <?php echo $l['scan_type']=='check_in'?'bg-success-subtle text-success':'bg-warning-subtle text-warning' ?>">
                            <?php echo $l['verify_mode'] ?>
                        </span>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($deptStats): ?>
<script>
new Chart(document.getElementById('deptChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_column($deptStats, 'name'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        datasets: [{
            label: 'จำนวนพนักงาน (คน)',
            data: <?php echo json_encode(array_column($deptStats, 'cnt')) ?>,
            backgroundColor: ['#4f46e5', '#0ea5e9', '#10b981', '#f59e0b'],
            borderRadius: 10
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
});
</script>
<?php endif; ?>

<?php include ROOT_PATH . '/includes/layout_bottom.php'; ?>