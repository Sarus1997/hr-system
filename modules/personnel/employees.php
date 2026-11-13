<?php
    require_once __DIR__ . '/../../includes/auth.php';
    requireLogin();
    if (! hasRole(['admin', 'hr'])) {
    die("Access Denied");
    }

    if (isset($_GET['action']) && $_GET['action'] === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="employees_export.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['company_code', 'emp_code', 'first_name', 'last_name', 'id_card_no', 'email', 'department', 'position', 'base_salary', 'status', 'account_status']);
    $sql = "SELECT c.company_code, e.emp_code, e.first_name, e.last_name, e.id_card_no, e.email, d.name, p.title, e.base_salary, e.status, u.status
            FROM employees e JOIN companies c ON c.company_id=e.company_id
            LEFT JOIN departments d ON e.department_id=d.id LEFT JOIN positions p ON e.position_id=p.id
            LEFT JOIN users u ON u.employee_id=e.id";
    foreach ($pdo->query($sql, PDO::FETCH_NUM) as $r) {
        fputcsv($out, $r);
    }

    fclose($out);
    exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
        requireValidCsrfToken();
        $upload = $_FILES['csv_file'];
        if ($upload['error'] !== UPLOAD_ERR_OK || strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'csv') {
            $msg = 'กรุณาเลือกไฟล์ CSV ที่ถูกต้อง';
        } else {
            $file = fopen($upload['tmp_name'], 'r');
            if ($file === false) {
                throw new RuntimeException('ไม่สามารถอ่านไฟล์ CSV ได้');
            }
            $header = fgetcsv($file);
            if (is_array($header) && isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            }
            $columnIndexes = is_array($header)
                ? array_flip(array_map(static fn($column) => strtolower(trim((string)$column)), $header))
                : [];
            $requiredColumns = ['company_code', 'emp_code', 'first_name', 'last_name', 'id_card_no', 'email', 'base_salary'];
            if (array_diff($requiredColumns, array_keys($columnIndexes))) {
                fclose($file);
                $msg = 'ไฟล์ CSV ต้องมีคอลัมน์ company_code, emp_code, first_name, last_name, id_card_no, email และ base_salary';
            } else {
                $companyRows = $pdo->query('SELECT company_code, company_id FROM companies WHERE status = 1')->fetchAll();
                $companyIds = [];
                foreach ($companyRows as $companyRow) {
                    $companyIds[strtoupper($companyRow['company_code'])] = (int)$companyRow['company_id'];
                }

                $stmt = $pdo->prepare("INSERT IGNORE INTO employees (id, company_id, emp_code, first_name, last_name, id_card_no, email, hire_date, base_salary) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)");
                $requiredIndexes = array_map(static fn($column) => $columnIndexes[$column], $requiredColumns);
                $importedCount = 0;
                $skippedCount = 0;
                while (($row = fgetcsv($file)) !== false) {
                    if (count($row) <= max($requiredIndexes)) {
                        $skippedCount++;
                        continue;
                    }

                    $companyCode = strtoupper(trim((string)$row[$columnIndexes['company_code']]));
                    $employeeCode = trim((string)$row[$columnIndexes['emp_code']]);
                    $firstName = trim((string)$row[$columnIndexes['first_name']]);
                    $lastName = trim((string)$row[$columnIndexes['last_name']]);
                    $idCardNo = trim((string)$row[$columnIndexes['id_card_no']]);
                    $email = trim((string)$row[$columnIndexes['email']]);
                    $baseSalary = filter_var(trim((string)$row[$columnIndexes['base_salary']]), FILTER_VALIDATE_FLOAT);

                    if (!isset($companyIds[$companyCode]) || $employeeCode === '' || $firstName === '' || $lastName === '' ||
                        !preg_match('/^\d{13}$/', $idCardNo) || !filter_var($email, FILTER_VALIDATE_EMAIL) ||
                        $baseSalary === false || $baseSalary < 0) {
                        $skippedCount++;
                        continue;
                    }

                    $stmt->execute([$employeeCode, $companyIds[$companyCode], $employeeCode, $firstName, $lastName, $idCardNo, $email, $baseSalary]);
                    if ($stmt->rowCount() === 1) {
                        $importedCount++;
                    } else {
                        $skippedCount++;
                    }
                }
                fclose($file);
                $msg = "นำเข้าพนักงานใหม่ {$importedCount} รายการ; ข้ามรายการซ้ำหรือข้อมูลไม่ครบ {$skippedCount} รายการ";
            }
        }
    }

    if (isset($_GET['saved'])) {
        $msg = 'บันทึกข้อมูลพนักงานสำเร็จ';
    }

    $employees = $pdo->query("SELECT e.*, d.name as dept_name, p.title as pos_title, u.status AS account_status,
                             (SELECT COUNT(*) FROM cloud_documents cd WHERE cd.employee_id=e.id AND cd.status=1) as doc_count
                          FROM employees e LEFT JOIN departments d ON e.department_id=d.id LEFT JOIN positions p ON e.position_id=p.id
                          LEFT JOIN users u ON u.employee_id=e.id ORDER BY e.emp_code")->fetchAll();
    $pageTitle = 'ทะเบียนประวัติพนักงาน';
    include ROOT_PATH . '/includes/layout_top.php';
?>

<div class="glass-card p-4 animate-up">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div class="input-group" style="max-width: 300px;">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" id="searchEmp" class="form-control border-start-0" placeholder="ค้นหาชื่อ หรือ รหัสพนักงาน...">
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="employee_form.php" class="btn btn-primary btn-modern"><i class="bi bi-plus-lg me-1"></i>เพิ่มพนักงาน</a>
            <a href="?action=export" class="btn btn-success btn-modern"><i class="bi bi-download me-1"></i>Export CSV</a>
            <button class="btn btn-outline-secondary btn-modern" data-bs-toggle="modal" data-bs-target="#importModal"><i class="bi bi-upload me-1"></i>Import CSV</button>
        </div>
    </div>

    <?php if (! empty($msg)): ?><div class="alert alert-success rounded-3"><?php echo $msg ?></div><?php endif; ?>

    <div class="table-responsive">
        <table class="table table-hover align-middle" id="empTable">
            <thead class="table-light">
                <tr><th>พนักงาน</th><th>รหัส</th><th>แผนก / ตำแหน่ง</th><th>เงินเดือน</th><th>เอกสาร Cloud</th><th>สถานะ</th><th>จัดการ</th></tr>
            </thead>
            <tbody>
            <?php foreach ($employees as $e): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-3">
                            <div class="avatar-circle">
                                <?php $profileImageUrl = getSafeProfileImageUrl($e['profile_image_url'] ?? null); ?>
                                <?php if ($profileImageUrl !== null): ?>
                                    <img class="avatar-image" src="<?php echo htmlspecialchars($profileImageUrl) ?>" alt="">
                                <?php else: ?>
                                    <?php echo htmlspecialchars(mb_substr($e['first_name'], 0, 1)) ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($e['email']) ?></small>
                            </div>
                        </div>
                    </td>
                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($e['emp_code']) ?></span></td>
                    <td>
                        <div class="small fw-semibold"><?php echo htmlspecialchars($e['dept_name'] ?? '-') ?></div>
                        <small class="text-muted"><?php echo htmlspecialchars($e['pos_title'] ?? '-') ?></small>
                    </td>
                    <td class="fw-semibold">฿<?php echo number_format($e['base_salary'], 2) ?></td>
                    <td><span class="badge bg-info-subtle text-info rounded-pill"><i class="bi bi-cloud-check me-1"></i><?php echo $e['doc_count'] ?> ไฟล์</span></td>
                    <td>
                        <div class="small"><span class="text-muted">งาน:</span> <?php echo htmlspecialchars($e['employment_status']) ?></div>
                        <div class="small"><span class="text-muted">ข้อมูล:</span>
                            <span class="badge <?php echo (int)$e['status'] === 1 ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>">
                                <?php echo (int)$e['status'] === 1 ? 'ใช้งาน' : 'ปิดใช้งาน' ?>
                            </span>
                        </div>
                        <div class="small"><span class="text-muted">บัญชี:</span>
                            <span class="badge <?php echo (int)$e['account_status'] === 1 ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>">
                                <?php echo $e['account_status'] === null ? 'ไม่มีบัญชี' : ((int)$e['account_status'] === 1 ? 'ใช้งาน' : 'ระงับ') ?>
                            </span>
                        </div>
                    </td>
                    <td><a href="employee_form.php?id=<?php echo rawurlencode($e['id']) ?>" class="btn btn-sm btn-outline-primary">แก้ไข</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" enctype="multipart/form-data" class="modal-content rounded-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()) ?>">
            <div class="modal-header"><h6 class="modal-title fw-bold">นำเข้าข้อมูลพนักงาน (CSV)</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><p class="small text-muted">ไฟล์ CSV ต้องมีหัวคอลัมน์ company_code, emp_code, first_name, last_name, id_card_no, email และ base_salary</p><input type="file" name="csv_file" accept=".csv" class="form-control" required></div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary btn-modern">อัปโหลดนำเข้า</button></div>
        </form>
    </div>
</div>

<script>
document.getElementById('searchEmp').addEventListener('keyup', function() {
    let val = this.value.toLowerCase();
    document.querySelectorAll('#empTable tbody tr').forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(val) ? '' : 'none';
    });
});
</script>
<?php include ROOT_PATH . '/includes/layout_bottom.php'; ?>