<?php
    require_once __DIR__ . '/../../includes/auth.php';
    requireLogin();
    if (!hasRole(['admin', 'hr'])) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์เข้าถึงหน้านี้');
    }

    $summary = $pdo->query("SELECT pp.period_name, c.company_name, COUNT(pi.id) AS total_emp, SUM(pi.base_salary + pi.ot_amount) AS total_income,
                        SUM(pi.social_security) AS total_sso, SUM(pi.withholding_tax) AS total_tax
                        FROM payroll_periods pp JOIN companies c ON c.company_id = pp.company_id
                        JOIN payroll_items pi ON pp.id = pi.period_id GROUP BY pp.id, c.company_name ORDER BY pp.id DESC")->fetchAll();
    $pageTitle = 'รายงาน ภ.ง.ด.1 และ ประกันสังคม (สปส.1-10)';
    include ROOT_PATH . '/includes/layout_top.php';
?>
<div class="glass-card p-4 animate-up">
    <h6 class="fw-bold mb-3"><i class="bi bi-file-earmark-spreadsheet-fill text-primary me-2"></i>สรุปยอดนำส่งภาษีและประกันสังคมรายงวด</h6>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr><th>บริษัท</th><th>งวด</th><th>พนักงาน</th><th>รายได้รวม</th><th>สปส. พนักงาน</th><th>สปส. นายจ้าง</th><th>รวมนำส่ง สปส.</th><th>ภาษี ภ.ง.ด.1</th></tr>
            </thead>
            <tbody>
            <?php foreach ($summary as $s): ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['company_name']) ?></td>
                    <td class="fw-bold"><?php echo htmlspecialchars($s['period_name']) ?></td>
                    <td><?php echo $s['total_emp'] ?> คน</td>
                    <td>฿<?php echo number_format($s['total_income'], 2) ?></td>
                    <td>฿<?php echo number_format($s['total_sso'], 2) ?></td>
                    <td>฿<?php echo number_format($s['total_sso'], 2) ?></td>
                    <td class="fw-bold text-primary">฿<?php echo number_format($s['total_sso']*2, 2) ?></td>
                    <td class="fw-bold text-danger">฿<?php echo number_format($s['total_tax'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include ROOT_PATH . '/includes/layout_bottom.php'; ?>