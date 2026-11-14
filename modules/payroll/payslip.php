<?php
    require_once __DIR__ . '/../../includes/auth.php';
    requireLogin();
    $itemId = filter_input(INPUT_GET, 'item_id', FILTER_VALIDATE_INT);
    $canViewAllPayslips = hasRole(['admin', 'hr']);
    $stmt = $pdo->prepare("SELECT pi.*, e.emp_code, e.first_name, e.last_name, d.name as dept, pp.period_name, pp.pay_date
                       FROM payroll_items pi JOIN employees e ON pi.employee_id=e.id
                       LEFT JOIN departments d ON e.department_id=d.id JOIN payroll_periods pp ON pi.period_id=pp.id
                       WHERE pi.id=? AND (pi.employee_id=? OR ?=1)");
    $stmt->execute([(int)$itemId, (string)($_SESSION['employee_id'] ?? ''), $canViewAllPayslips ? 1 : 0]);
    $slip = $stmt->fetch();
    if (! $slip) {
    http_response_code(404);
    exit("ไม่พบข้อมูลสลิป");
    }

?>
<!DOCTYPE html>
<html lang="th" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#f6f7fb">
    <title>สลิปเงินเดือน - <?php echo htmlspecialchars($slip['emp_code']) ?></title>
    <script>
    (function () {
        try {
            var saved = localStorage.getItem('hr-theme');
            var theme = saved || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-bs-theme', theme);
            document.querySelector('meta[name="theme-color"]').setAttribute('content', theme === 'dark' ? '#080b16' : '#f6f7fb');
        } catch (e) {}
    })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL ?>/assets/css/style.css?v=<?php echo filemtime(ROOT_PATH . '/assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="p-3 p-md-4">
<div class="glass-card mx-auto p-4 p-md-5 animate-up" style="max-width: 720px;">
    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-4">
        <div>
            <h4 class="fw-bold text-primary mb-0">NEXUS HR CLOUD</h4>
            <small class="text-muted">ใบแจ้งเงินเดือนอิเล็กทรอนิกส์ (E-Pay Slip)</small>
        </div>
        <button onclick="window.print()" class="btn btn-primary btn-sm d-print-none">พิมพ์ / PDF</button>
    </div>
    <div class="row mb-4 small">
        <div class="col-6"><strong>พนักงาน:</strong> <?php echo htmlspecialchars($slip['emp_code'].' '.$slip['first_name'].' '.$slip['last_name']) ?><br><strong>แผนก:</strong> <?php echo htmlspecialchars($slip['dept']??'-') ?></div>
        <div class="col-6 text-end"><strong>งวดเดือน:</strong> <?php echo htmlspecialchars($slip['period_name']) ?><br><strong>วันที่จ่าย:</strong> <?php echo $slip['pay_date'] ?></div>
    </div>
    <table class="table table-bordered">
        <thead class="table-light"><tr><th>รายการได้</th><th class="text-end">จำนวนเงิน</th><th>รายการหัก</th><th class="text-end">จำนวนเงิน</th></tr></thead>
        <tbody>
            <tr><td>เงินเดือนฐาน</td><td class="text-end"><?php echo number_format($slip['base_salary'],2) ?></td><td>ประกันสังคม (5%, สูงสุด 875 บาท)</td><td class="text-end"><?php echo number_format($slip['social_security'],2) ?></td></tr>
            <tr><td>ค่าล่วงเวลา (OT)</td><td class="text-end"><?php echo number_format($slip['ot_amount'],2) ?></td><td>ภาษีหัก ณ ที่จ่าย (ภ.ง.ด.1)</td><td class="text-end"><?php echo number_format($slip['withholding_tax'],2) ?></td></tr>
            <tr class="table-primary fw-bold"><td colspan="2">เงินได้สุทธิ (Net Pay)</td><td colspan="2" class="text-end fs-5">฿<?php echo number_format($slip['net_salary'],2) ?></td></tr>
        </tbody>
    </table>
</div>
</body>
</html>