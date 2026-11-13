<?php
    require_once __DIR__ . '/../../includes/auth.php';
    require_once ROOT_PATH . '/includes/leave_service.php';
    requireLogin();

    $currentYear = (int) ($_GET['year'] ?? date('Y'));
    $nextYear    = $currentYear + 1;
    $msg         = $err         = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_reset'])) {
        requireValidCsrfToken();
    try {
        $res         = processAnnualLeaveReset($pdo, (int) $_POST['from_year'], (int) $_POST['to_year'], $_SESSION['employee_id']);
        $msg         = "ประมวลผลรีเซ็ตวันลาขึ้นปี {$_POST['to_year']} สำเร็จ ({$res['processed']} คน)";
        $currentYear = (int) $_POST['to_year'];
        $nextYear    = $currentYear + 1;
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
    }

    $stmt = $pdo->prepare("SELECT lb.*, e.emp_code, e.first_name, e.last_name, lt.name AS leave_name, lt.is_carry_over, lt.max_carry_over_years
                       FROM leave_balances lb JOIN employees e ON lb.employee_id=e.id JOIN leave_types lt ON lb.leave_type_id=lt.id
                       WHERE lb.year = ? ORDER BY e.emp_code, lt.id");
    $stmt->execute([$currentYear]);
    $balances = $stmt->fetchAll();
    $groupedBalances = [];
    foreach ($balances as $balance) {
        $employeeId = (string)$balance['employee_id'];
        if (!isset($groupedBalances[$employeeId])) {
            $groupedBalances[$employeeId] = [
                'employee_id' => $employeeId,
                'emp_code' => (string)$balance['emp_code'],
                'full_name' => $balance['first_name'] . ' ' . $balance['last_name'],
                'leaves' => [],
                'used_total' => 0.0,
                'remaining_total' => 0.0,
            ];
        }

        $remainingDays = (float)$balance['quota_days'] + (float)$balance['carried_days'] - (float)$balance['used_days'];
        $groupedBalances[$employeeId]['leaves'][] = [
            'leave_name' => (string)$balance['leave_name'],
            'quota_days' => (float)$balance['quota_days'],
            'carried_days' => (float)$balance['carried_days'],
            'used_days' => (float)$balance['used_days'],
            'remaining_days' => $remainingDays,
            'is_carry_over' => (bool)$balance['is_carry_over'],
            'carry_cycle_count' => (int)$balance['carry_cycle_count'],
            'max_carry_over_years' => (int)$balance['max_carry_over_years'],
        ];
        $groupedBalances[$employeeId]['used_total'] += (float)$balance['used_days'];
        $groupedBalances[$employeeId]['remaining_total'] += $remainingDays;
    }
    $employeeBalances = array_values($groupedBalances);
    $balanceDetailsJson = json_encode(
        $employeeBalances,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    if ($balanceDetailsJson === false) {
        throw new RuntimeException('ไม่สามารถเตรียมรายละเอียดวันลาได้: ' . json_last_error_msg());
    }

    $pageTitle = 'รีเซ็ตวันลาประจำปี & สะสมพักร้อน 3 ปี';
    include ROOT_PATH . '/includes/layout_top.php';
?>

<div class="glass-card p-4 mb-4 animate-up" style="background: linear-gradient(135deg, #eef2ff, #ffffff); border-left: 5px solid var(--primary);">
    <div class="row align-items-center g-3">
        <div class="col-md-8">
            <h5 class="fw-bold mb-1">ประมวลผลปิดยอดวันลาปี <?php echo $currentYear ?> ➔ ขึ้นปีใหม่ <?php echo $nextYear ?></h5>
            <p class="text-muted small mb-0">
                • <strong>ลาป่วย / ลากิจ:</strong> รีเซ็ตสิทธิ์ใหม่เต็มจำนวนทุกปี<br>
                • <strong>ลาพักร้อน:</strong> ยกยอดคงเหลือสะสมข้ามปีได้ <strong>ไม่เกิน 3 ปี</strong> เมื่อครบปีที่ 3 ระบบจะรีเซ็ตยอดสะสมเป็น 0 อัตโนมัติ
            </p>
        </div>
        <div class="col-md-4 text-md-end">
            <form method="POST" onsubmit="return confirm('ยืนยันประมวลผลวันลาขึ้นปี <?php echo $nextYear ?>?');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="from_year" value="<?php echo $currentYear ?>">
                <input type="hidden" name="to_year" value="<?php echo $nextYear ?>">
                <button type="submit" name="run_reset" class="btn btn-danger btn-modern"><i class="bi bi-arrow-clockwise me-1"></i>รีเซ็ตขึ้นปี <?php echo $nextYear ?></button>
            </form>
        </div>
    </div>
</div>

<?php if ($msg) {
    echo "<div class='alert alert-success rounded-3'>$msg</div>";
    }
?>
<?php if ($err) {
    echo "<div class='alert alert-danger rounded-3'>$err</div>";
    }
?>

<div class="glass-card p-4 animate-up delay-1">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
        <div>
            <h6 class="fw-bold mb-1">ยอดวันลารายพนักงาน · ปี <?php echo $currentYear ?></h6>
            <p class="small text-muted mb-0">ยอดรวมเป็นผลรวมเพื่อสรุปเท่านั้น วันลาต่างประเภทใช้แทนกันไม่ได้ กดดูรายละเอียดเพื่อแยกตามประเภท</p>
        </div>
        <div class="d-flex flex-column flex-sm-row gap-2">
            <label class="input-group" style="max-width: 360px;">
                <span class="input-group-text bg-white"><i class="bi bi-search" aria-hidden="true"></i></span>
                <input type="search" id="employeeSearch" class="form-control" placeholder="ค้นหาชื่อหรือรหัสพนักงาน" aria-label="ค้นหาชื่อหรือรหัสพนักงาน">
            </label>
            <form method="GET" class="d-flex gap-2">
                <label for="yearFilter" class="visually-hidden">เลือกปี</label>
                <input id="yearFilter" type="number" name="year" value="<?php echo $currentYear ?>" class="form-control form-control-sm" style="width: 110px;" min="2000" max="2100">
                <button class="btn btn-outline-primary btn-sm text-nowrap">ดูปี</button>
            </form>
        </div>
    </div>
    <div class="small text-muted mb-2" id="employeeResultCount" aria-live="polite">
        <?php echo count($employeeBalances) ?> คน
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr><th>พนักงาน</th><th>ประเภทลา</th><th>ใช้ไปรวม</th><th>คงเหลือรวม</th><th class="text-end">รายละเอียด</th></tr>
            </thead>
            <tbody>
            <?php if (!$employeeBalances): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">ไม่พบข้อมูลวันลาสำหรับปีนี้</td></tr>
            <?php else: foreach ($employeeBalances as $index => $employee): ?>
                <tr data-employee-row data-search="<?php echo htmlspecialchars($employee['emp_code'] . ' ' . $employee['full_name'], ENT_QUOTES, 'UTF-8') ?>">
                    <td>
                        <div class="fw-semibold"><?php echo htmlspecialchars($employee['full_name']) ?></div>
                        <small class="text-muted"><?php echo htmlspecialchars($employee['emp_code']) ?></small>
                    </td>
                    <td><?php echo count($employee['leaves']) ?> ประเภท</td>
                    <td><?php echo number_format($employee['used_total'], 1) ?> วัน</td>
                    <td class="fw-bold text-success"><?php echo number_format($employee['remaining_total'], 1) ?> วัน</td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#employeeBalanceModal" data-balance-index="<?php echo $index ?>">
                            <i class="bi bi-list-ul me-1" aria-hidden="true"></i>ดูรายละเอียด
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
                <tr id="employeeSearchEmpty" hidden><td colspan="5" class="text-center text-muted py-4">ไม่พบพนักงานที่ตรงกับคำค้นหา</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="employeeBalanceModal" tabindex="-1" aria-labelledby="employeeBalanceTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5 fw-bold" id="employeeBalanceTitle">รายละเอียดวันลา</h2>
                    <p class="small text-muted mb-0">ปี <?php echo $currentYear ?> · ยอดแยกตามประเภท</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>ประเภทลา</th><th>สิทธิ์ปีนี้</th><th>สะสม</th><th>ใช้ไป</th><th>คงเหลือ</th><th>รอบสะสม</th></tr>
                        </thead>
                        <tbody id="employeeBalanceDetails"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script type="application/json" id="leaveBalanceData"><?php echo $balanceDetailsJson ?></script>
<script>
(() => {
    const dataElement = document.getElementById('leaveBalanceData');
    const modal = document.getElementById('employeeBalanceModal');
    const title = document.getElementById('employeeBalanceTitle');
    const detailsBody = document.getElementById('employeeBalanceDetails');
    const searchInput = document.getElementById('employeeSearch');
    const rows = Array.from(document.querySelectorAll('[data-employee-row]'));
    const emptyRow = document.getElementById('employeeSearchEmpty');
    const resultCount = document.getElementById('employeeResultCount');
    const employees = JSON.parse(dataElement.textContent);
    const formatDays = value => new Intl.NumberFormat('th-TH', { maximumFractionDigits: 1 }).format(value);

    modal.addEventListener('show.bs.modal', event => {
        const index = Number(event.relatedTarget.dataset.balanceIndex);
        const employee = employees[index];
        if (!employee) {
            console.error('ไม่พบข้อมูลวันลาของพนักงานที่เลือก');
            return;
        }

        title.textContent = `${employee.full_name} (${employee.emp_code})`;
        detailsBody.replaceChildren();
        employee.leaves.forEach(leave => {
            const row = document.createElement('tr');
            const values = [
                leave.leave_name,
                `${formatDays(leave.quota_days)} วัน`,
                `${formatDays(leave.carried_days)} วัน`,
                `${formatDays(leave.used_days)} วัน`,
                `${formatDays(leave.remaining_days)} วัน`,
            ];
            values.forEach((value, valueIndex) => {
                const cell = document.createElement('td');
                cell.textContent = value;
                if (valueIndex === 4) cell.className = 'fw-semibold text-success';
                row.appendChild(cell);
            });

            const cycleCell = document.createElement('td');
            if (!leave.is_carry_over) {
                cycleCell.textContent = 'รีเซ็ตทุกปี';
            } else if (leave.carry_cycle_count >= leave.max_carry_over_years) {
                cycleCell.textContent = `ปีที่ ${leave.carry_cycle_count}/${leave.max_carry_over_years} · สิ้นปีรีเซ็ต`;
            } else {
                cycleCell.textContent = `สะสมปีที่ ${leave.carry_cycle_count}/${leave.max_carry_over_years}`;
            }
            row.appendChild(cycleCell);
            detailsBody.appendChild(row);
        });
    });

    searchInput.addEventListener('input', () => {
        const query = searchInput.value.trim().toLocaleLowerCase('th');
        let visibleCount = 0;
        rows.forEach(row => {
            const matches = row.dataset.search.toLocaleLowerCase('th').includes(query);
            row.hidden = !matches;
            if (matches) visibleCount++;
        });
        if (emptyRow) emptyRow.hidden = visibleCount > 0;
        resultCount.textContent = `แสดง ${visibleCount} จาก ${rows.length} คน`;
    });
})();
</script>
<?php include ROOT_PATH . '/includes/layout_bottom.php'; ?>