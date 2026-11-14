<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once ROOT_PATH . '/config/cloud_storage.php';
require_once ROOT_PATH . '/includes/audit_log.php';
requireLogin();

if (!hasRole(['admin', 'hr'])) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์เข้าถึงหน้านี้');
}

$rawEditId = $_GET['id'] ?? null;
if ($rawEditId !== null && (!is_string($rawEditId) || trim($rawEditId) === '' || strlen($rawEditId) > 20)) {
    http_response_code(400);
    exit('รหัสพนักงานไม่ถูกต้อง');
}
$editId = $rawEditId === null ? '' : trim($rawEditId);
$emp = null;
$accountStatus = null;
if ($editId !== '') {
    $employeeStmt = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
    $employeeStmt->execute([$editId]);
    $emp = $employeeStmt->fetch();
    if (!$emp) {
        http_response_code(404);
        exit('ไม่พบข้อมูลพนักงาน');
    }
    $accountStmt = $pdo->prepare('SELECT status FROM users WHERE employee_id = ?');
    $accountStmt->execute([$editId]);
    $accountStatus = $accountStmt->fetchColumn();
    $accountStatus = $accountStatus === false ? null : (int)$accountStatus;
}
$companiesStmt = $pdo->prepare('SELECT company_id, company_code, company_name FROM companies WHERE status = 1 OR company_id = ? ORDER BY company_name');
$companiesStmt->execute([$emp['company_id'] ?? 0]);
$companies = $companiesStmt->fetchAll();
$selectedCompanyId = $editId !== ''
    ? (int)$emp['company_id']
    : filter_var($_POST['company_id'] ?? ($companies[0]['company_id'] ?? null), FILTER_VALIDATE_INT);
if ($selectedCompanyId === false) {
    $selectedCompanyId = null;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_document_status'])) {
    requireValidCsrfToken();
    $documentId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT);
    $documentStatus = filter_var($_POST['document_status'] ?? null, FILTER_VALIDATE_INT);
    if ($editId === '' || $documentId === false || $documentId < 1 ||
        !in_array($documentStatus, [0, 1], true)) {
        http_response_code(400);
        exit('ข้อมูลสถานะเอกสารไม่ถูกต้อง');
    }

    $documentCheck = $pdo->prepare('SELECT id FROM cloud_documents WHERE id = ? AND employee_id = ?');
    $documentCheck->execute([$documentId, $editId]);
    if (!$documentCheck->fetchColumn()) {
        http_response_code(404);
        exit('ไม่พบเอกสารของพนักงานรายนี้');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE cloud_documents SET status = ? WHERE id = ? AND employee_id = ?')
            ->execute([$documentStatus, $documentId, $editId]);
        recordAuditEvent($pdo, 'employee.document_status_changed', 'employee_document', (string)$documentId, [
            'employee_id' => $editId,
            'status' => (int)$documentStatus,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    header('Location: ' . BASE_URL . '/modules/personnel/employee_form.php?id=' . rawurlencode($editId) . '&document_updated=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_emp'])) {
    requireValidCsrfToken();
    $postText = static fn($key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    $values = [
        'emp_code' => $postText('emp_code'),
        'first_name' => $postText('first_name'),
        'last_name' => $postText('last_name'),
        'id_card_no' => $postText('id_card_no'),
        'email' => $postText('email'),
        'phone' => $postText('phone'),
        'company_id' => $editId !== '' ? (int)$emp['company_id'] : filter_var($_POST['company_id'] ?? null, FILTER_VALIDATE_INT),
        'department_id' => filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT),
        'position_id' => filter_var($_POST['position_id'] ?? null, FILTER_VALIDATE_INT),
        'manager_id' => $postText('manager_id'),
        'hire_date' => $_POST['hire_date'] ?? '',
        'base_salary' => filter_var($_POST['base_salary'] ?? null, FILTER_VALIDATE_FLOAT),
        'employment_status' => $_POST['employment_status'] ?? 'active',
        'status' => filter_var($_POST['status'] ?? ($emp['status'] ?? 1), FILTER_VALIDATE_INT),
        'account_status' => filter_var($_POST['account_status'] ?? ($accountStatus ?? 1), FILTER_VALIDATE_INT),
    ];
    $hireDate = is_string($values['hire_date']) ? DateTimeImmutable::createFromFormat('!Y-m-d', $values['hire_date']) : false;
    $allowedStatuses = ['active', 'probation', 'resigned'];
    $departmentId = $values['department_id'] > 0 ? $values['department_id'] : null;
    $positionId = $values['position_id'] > 0 ? $values['position_id'] : null;
    $managerId = $values['manager_id'] !== '' ? $values['manager_id'] : null;
    $companyIsValid = false;
    if ($values['company_id'] !== false && $values['company_id'] !== null && $values['company_id'] > 0) {
        $companyCheck = $pdo->prepare('SELECT status FROM companies WHERE company_id = ?');
        $companyCheck->execute([$values['company_id']]);
        $companyStatus = $companyCheck->fetchColumn();
        $companyIsValid = $companyStatus !== false && ($editId !== '' || (int)$companyStatus === 1);
    }
    $managerIsValid = true;
    if ($managerId !== null) {
        $managerCheck = $pdo->prepare(
            "SELECT e.id
             FROM employees e
             JOIN users u ON u.employee_id = e.id AND u.status = 1
             WHERE e.id = ? AND e.id <> ? AND e.company_id = ? AND e.status = 1
               AND e.employment_status IN ('active', 'probation')"
        );
        $managerCheck->execute([$managerId, $editId, $values['company_id']]);
        $managerIsValid = (bool)$managerCheck->fetchColumn() ||
            ($editId !== '' && (string)$managerId === (string)($emp['manager_id'] ?? ''));
    }
    $companyForReferences = $values['company_id'];
    $referenceIsValid = static function (string $table, ?int $value, $currentValue) use ($pdo, $companyForReferences): bool {
        if ($value === null || ($currentValue !== null && (string)$value === (string)$currentValue)) {
            return true;
        }
        if (!in_array($table, ['departments', 'positions'], true)) {
            return false;
        }
        $referenceStmt = $pdo->prepare("SELECT status, company_id FROM {$table} WHERE id = ?");
        $referenceStmt->execute([$value]);
        $reference = $referenceStmt->fetch();
        return $reference && (int)$reference['status'] === 1 &&
            ($reference['company_id'] === null || (int)$reference['company_id'] === (int)$companyForReferences);
    };
    $departmentIsValid = $referenceIsValid(
        'departments',
        $departmentId,
        $emp['department_id'] ?? null
    );
    $positionIsValid = $referenceIsValid(
        'positions',
        $positionId,
        $emp['position_id'] ?? null
    );
    $rawShiftId = is_string($_POST['shift_id'] ?? null) ? trim($_POST['shift_id']) : '';
    $shiftId = $rawShiftId === '' ? null : filter_var($rawShiftId, FILTER_VALIDATE_INT);
    $shiftIsValid = true;
    if ($editId === '' && $shiftId !== null) {
        if ($shiftId === false || $shiftId < 1) {
            $shiftIsValid = false;
        } else {
            $shiftCheck = $pdo->prepare('SELECT id FROM shifts WHERE id = ? AND (company_id = ? OR company_id IS NULL) AND status = 1');
            $shiftCheck->execute([$shiftId, $values['company_id']]);
            $shiftIsValid = (bool)$shiftCheck->fetchColumn();
        }
    }

    if ($values['emp_code'] === '' || mb_strlen($values['emp_code']) > 20 || $values['first_name'] === '' || $values['last_name'] === '' ||
        !preg_match('/^\d{13}$/', $values['id_card_no']) || !filter_var($values['email'], FILTER_VALIDATE_EMAIL) ||
        $values['base_salary'] === false || $values['base_salary'] < 0 ||
        !$hireDate || $hireDate->format('Y-m-d') !== $values['hire_date'] ||
        ($editId !== '' && (!in_array($values['employment_status'], $allowedStatuses, true) ||
            !in_array($values['status'], [0, 1], true) ||
            ($accountStatus !== null && !in_array($values['account_status'], [0, 1], true)))) ||
        !$companyIsValid || !$managerIsValid || !$departmentIsValid || !$positionIsValid || !$shiftIsValid) {
        $error = 'กรุณาตรวจสอบข้อมูลพนักงานที่จำเป็นให้ถูกต้อง';
    } elseif ($editId === '' && (!is_string($_POST['init_password'] ?? null) || mb_strlen($_POST['init_password']) < 6)) {
        $error = 'รหัสผ่านเริ่มต้นต้องมีอย่างน้อย 6 ตัวอักษร';
    } else {
        $role = $postText('role') ?: 'employee';
        $allowedRoles = hasRole(['admin'])
            ? ['employee', 'manager', 'hr', 'admin']
            : ['employee', 'manager', 'hr'];
        if ($editId === '' && !in_array($role, $allowedRoles, true)) {
            $error = 'สิทธิ์ผู้ใช้งานที่เลือกไม่ถูกต้อง';
        }

        $file = $_FILES['doc_file'] ?? null;
        if ($error === '' && $file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            $allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'];
            $mimeType = $file['error'] === UPLOAD_ERR_OK ? (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) : false;
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024 || !in_array($mimeType, $allowedMimeTypes, true)) {
                $error = 'ไฟล์แนบต้องเป็น PDF, JPG หรือ PNG และมีขนาดไม่เกิน 5 MB';
            }
        }
        $docType = $postText('doc_type');
        $allowedDocTypes = ['contract', 'id_card', 'certificate', 'house_reg', 'other'];
        if ($error === '' && $file && $file['error'] === UPLOAD_ERR_OK && !in_array($docType, $allowedDocTypes, true)) {
            $error = 'ประเภทเอกสารไม่ถูกต้อง';
        }

        if ($error === '') {
            try {
                $pdo->beginTransaction();
                if ($editId !== '') {
                    $stmt = $pdo->prepare('UPDATE employees SET emp_code=?, first_name=?, last_name=?, id_card_no=?, email=?, phone=?, department_id=?, position_id=?, manager_id=?, hire_date=?, base_salary=?, employment_status=?, status=? WHERE id=?');
                    $stmt->execute([
                        $values['emp_code'], $values['first_name'], $values['last_name'], $values['id_card_no'],
                        $values['email'], $values['phone'] ?: null, $departmentId, $positionId, $managerId,
                        $values['hire_date'], $values['base_salary'], $values['employment_status'], $values['status'], $editId,
                    ]);
                    if ($accountStatus !== null) {
                        $pdo->prepare('UPDATE users SET status = ? WHERE employee_id = ?')
                            ->execute([$values['account_status'], $editId]);
                    }
                    $targetEmployeeId = $editId;
                } else {
                    $stmt = $pdo->prepare("INSERT INTO employees (id, company_id, emp_code, first_name, last_name, id_card_no, email, phone, department_id, position_id, manager_id, hire_date, base_salary, employment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                    $stmt->execute([
                        $values['emp_code'], $values['company_id'], $values['emp_code'], $values['first_name'], $values['last_name'], $values['id_card_no'],
                        $values['email'], $values['phone'] ?: null, $departmentId, $positionId, $managerId,
                        $values['hire_date'], $values['base_salary'],
                    ]);
                    $targetEmployeeId = $values['emp_code'];

                    $username = strtolower($values['emp_code']);
                    $passwordHash = password_hash($_POST['init_password'], PASSWORD_BCRYPT, ['cost' => 12]);
                    $pdo->prepare('INSERT INTO users (employee_id, username, password, password_hash, role) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$targetEmployeeId, $username, $passwordHash, $passwordHash, $role]);

                    $leaveTypes = $pdo->query('SELECT id, max_days_per_year FROM leave_types WHERE status = 1')->fetchAll();
                    $balanceStmt = $pdo->prepare('INSERT IGNORE INTO leave_balances (employee_id, leave_type_id, year, quota_days, carried_days, used_days, carry_cycle_count) VALUES (?, ?, ?, ?, 0, 0, 1)');
                    foreach ($leaveTypes as $leaveType) {
                        $balanceStmt->execute([$targetEmployeeId, $leaveType['id'], date('Y'), $leaveType['max_days_per_year']]);
                    }

                    if ($shiftId !== null) {
                        $pdo->prepare('INSERT INTO employee_shifts (employee_id, shift_id, effective_date) VALUES (?, ?, ?)')
                            ->execute([$targetEmployeeId, $shiftId, $values['hire_date']]);
                    }
                }

                if ($file && $file['error'] === UPLOAD_ERR_OK) {
                    $cloud = CloudStorage::upload($file['tmp_name'], $file['name'], 'employee_docs');
                    $pdo->prepare('INSERT INTO cloud_documents (employee_id, doc_type, file_name, cloud_bucket, cloud_object_key, file_url) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([$targetEmployeeId, $docType, basename($file['name']), $cloud['bucket'], $cloud['key'], $cloud['url']]);
                }

                $changedFields = [];
                if ($editId !== '') {
                    $auditedValues = [
                        'emp_code' => $values['emp_code'],
                        'first_name' => $values['first_name'],
                        'last_name' => $values['last_name'],
                        'id_card_no' => $values['id_card_no'],
                        'email' => $values['email'],
                        'phone' => $values['phone'] ?: null,
                        'department_id' => $departmentId,
                        'position_id' => $positionId,
                        'manager_id' => $managerId,
                        'hire_date' => $values['hire_date'],
                        'base_salary' => $values['base_salary'],
                        'employment_status' => $values['employment_status'],
                        'status' => $values['status'],
                    ];
                    foreach ($auditedValues as $field => $value) {
                        if ((string)($emp[$field] ?? '') !== (string)($value ?? '')) {
                            $changedFields[] = $field;
                        }
                    }
                    if ($accountStatus !== null && $accountStatus !== (int)$values['account_status']) {
                        $changedFields[] = 'account_status';
                    }
                }
                recordAuditEvent(
                    $pdo,
                    $editId === '' ? 'employee.created' : 'employee.updated',
                    'employee',
                    (string)$targetEmployeeId,
                    $editId === '' ? [] : ['changed_fields' => $changedFields]
                );
                $pdo->commit();
                header('Location: ' . BASE_URL . '/modules/personnel/employees.php?saved=1');
                exit;
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($exception->getCode() === '23000') {
                    $error = 'รหัสพนักงานหรือชื่อผู้ใช้งานนี้มีอยู่แล้ว กรุณาตรวจสอบรหัสพนักงาน';
                } else {
                    throw $exception;
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    }
}

$form = array_merge($emp ?? [], ['account_status' => $accountStatus], $_POST);
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$companyNames = array_column($companies, 'company_name', 'company_id');
$departmentStmt = $pdo->prepare('SELECT id, name, status FROM departments WHERE (company_id = ? OR company_id IS NULL) AND (status = 1 OR id = ?) ORDER BY name');
$departmentStmt->execute([$selectedCompanyId, $emp['department_id'] ?? null]);
$depts = $departmentStmt->fetchAll();
$positionStmt = $pdo->prepare('SELECT id, title, status FROM positions WHERE (company_id = ? OR company_id IS NULL) AND (status = 1 OR id = ?) ORDER BY title');
$positionStmt->execute([$selectedCompanyId, $emp['position_id'] ?? null]);
$positions = $positionStmt->fetchAll();
$shiftStmt = $pdo->prepare('SELECT id, shift_name FROM shifts WHERE (company_id = ? OR company_id IS NULL) AND status = 1 ORDER BY shift_name');
$shiftStmt->execute([$selectedCompanyId]);
$shifts = $shiftStmt->fetchAll();
$managerStmt = $pdo->prepare(
    "SELECT e.id, e.first_name, e.last_name, e.status, e.employment_status, u.status AS account_status
     FROM employees e
     LEFT JOIN users u ON u.employee_id = e.id
     WHERE e.id <> ? AND (e.company_id = ? OR e.id = ?)
       AND ((e.status = 1 AND e.employment_status IN ('active', 'probation') AND u.status = 1) OR e.id = ?)
     ORDER BY e.first_name, e.last_name"
);
$managerStmt->execute([$editId, $selectedCompanyId, $emp['manager_id'] ?? null, $emp['manager_id'] ?? null]);
$managers = $managerStmt->fetchAll();
$docs = [];
if ($editId !== '') {
    $docStmt = $pdo->prepare('SELECT id, doc_type, file_name, file_url, uploaded_at, status FROM cloud_documents WHERE employee_id = ? ORDER BY uploaded_at DESC');
    $docStmt->execute([$editId]);
    $docs = $docStmt->fetchAll();
}

$pageTitle = $editId ? 'แก้ไขประวัติพนักงาน' : 'เพิ่มพนักงานใหม่';
$documentNotice = isset($_GET['document_updated']) ? 'อัปเดตสถานะเอกสารแล้ว' : '';
include ROOT_PATH . '/includes/layout_top.php';
?>

<?php if ($error !== ''): ?><div class="alert alert-danger rounded-3"><?= $escape($error) ?></div><?php endif; ?>
<?php if ($documentNotice !== ''): ?><div class="alert alert-success rounded-3"><?= $escape($documentNotice) ?></div><?php endif; ?>

<section class="glass-card p-4 animate-up" style="max-width: 960px;">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h2 class="h5 fw-bold mb-0"><i class="bi bi-person-badge-fill text-primary me-2" aria-hidden="true"></i><?= $escape($pageTitle) ?></h2>
        <a href="employees.php" class="btn btn-light btn-modern" onclick="if (document.referrer && new URL(document.referrer).origin === window.location.origin) { window.history.back(); return false; }">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>ย้อนกลับหน้าก่อน
        </a>
    </div>
    <form method="post" enctype="multipart/form-data" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
        <?php if ($editId === ''): ?>
        <div class="col-md-4"><label for="company" class="form-label small">บริษัท</label><select id="company" name="company_id" class="form-select" required><?php foreach ($companies as $company): ?><option value="<?= (int)$company['company_id'] ?>" <?= (string)($form['company_id'] ?? $selectedCompanyId) === (string)$company['company_id'] ? 'selected' : '' ?>><?= $escape($company['company_code'] . ' - ' . $company['company_name']) ?></option><?php endforeach; ?></select></div>
        <?php else: ?>
        <div class="col-md-4"><span class="form-label small d-block">บริษัท</span><span class="form-control-plaintext"><?= $escape($companyNames[$emp['company_id']] ?? $emp['company_id']) ?></span></div>
        <?php endif; ?>
        <div class="col-md-3"><label for="empCode" class="form-label small">รหัสพนักงาน</label><input id="empCode" type="text" name="emp_code" value="<?= $escape($form['emp_code'] ?? '') ?>" class="form-control" maxlength="20" required></div>
        <div class="col-md-3"><label for="firstName" class="form-label small">ชื่อ</label><input id="firstName" type="text" name="first_name" value="<?= $escape($form['first_name'] ?? '') ?>" class="form-control" maxlength="100" required></div>
        <div class="col-md-3"><label for="lastName" class="form-label small">นามสกุล</label><input id="lastName" type="text" name="last_name" value="<?= $escape($form['last_name'] ?? '') ?>" class="form-control" maxlength="100" required></div>
        <div class="col-md-3"><label for="idCard" class="form-label small">เลขบัตรประชาชน</label><input id="idCard" type="text" name="id_card_no" maxlength="13" pattern="[0-9]{13}" value="<?= $escape($form['id_card_no'] ?? '') ?>" class="form-control" required></div>
        <div class="col-md-4"><label for="email" class="form-label small">อีเมล</label><input id="email" type="email" name="email" value="<?= $escape($form['email'] ?? '') ?>" class="form-control" maxlength="100" required></div>
        <div class="col-md-4"><label for="phone" class="form-label small">เบอร์โทร</label><input id="phone" type="tel" name="phone" value="<?= $escape($form['phone'] ?? '') ?>" class="form-control" maxlength="20"></div>
        <div class="col-md-4"><label for="hireDate" class="form-label small">วันเริ่มงาน</label><input id="hireDate" type="date" name="hire_date" value="<?= $escape($form['hire_date'] ?? date('Y-m-d')) ?>" class="form-control" required></div>

        <div class="col-md-3"><label for="department" class="form-label small">แผนก</label><select id="department" name="department_id" class="form-select"><option value="">-- เลือกแผนก --</option><?php foreach ($depts as $department): ?><option value="<?= (int)$department['id'] ?>" <?= (string)($form['department_id'] ?? '') === (string)$department['id'] ? 'selected' : '' ?>><?= $escape($department['name'] . ((int)$department['status'] === 1 ? '' : ' (ปิดใช้งาน)')) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label for="position" class="form-label small">ตำแหน่ง</label><select id="position" name="position_id" class="form-select"><option value="">-- เลือกตำแหน่ง --</option><?php foreach ($positions as $position): ?><option value="<?= (int)$position['id'] ?>" <?= (string)($form['position_id'] ?? '') === (string)$position['id'] ? 'selected' : '' ?>><?= $escape($position['title'] . ((int)$position['status'] === 1 ? '' : ' (ปิดใช้งาน)')) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label for="manager" class="form-label small">หัวหน้างาน</label><select id="manager" name="manager_id" class="form-select"><option value="">-- ไม่มี --</option><?php foreach ($managers as $manager): ?><option value="<?= $escape($manager['id']) ?>" <?= (string)($form['manager_id'] ?? '') === (string)$manager['id'] ? 'selected' : '' ?>><?= $escape($manager['first_name'] . ' ' . $manager['last_name'] . ((int)$manager['status'] === 1 && (int)$manager['account_status'] === 1 && in_array($manager['employment_status'], ['active', 'probation'], true) ? '' : ' (ปิดใช้งาน)')) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label for="baseSalary" class="form-label small">เงินเดือนฐาน</label><input id="baseSalary" type="number" step="0.01" min="0" name="base_salary" value="<?= $escape($form['base_salary'] ?? '') ?>" class="form-control" required></div>

        <?php if ($editId !== ''): ?>
        <div class="col-md-4"><label for="employmentStatus" class="form-label small">สถานะการทำงาน</label><select id="employmentStatus" name="employment_status" class="form-select"><option value="active" <?= ($form['employment_status'] ?? '') === 'active' ? 'selected' : '' ?>>ทำงาน</option><option value="probation" <?= ($form['employment_status'] ?? '') === 'probation' ? 'selected' : '' ?>>ทดลองงาน</option><option value="resigned" <?= ($form['employment_status'] ?? '') === 'resigned' ? 'selected' : '' ?>>ลาออก</option></select></div>
        <div class="col-md-4"><label for="employeeStatus" class="form-label small">สถานะข้อมูลพนักงาน</label><select id="employeeStatus" name="status" class="form-select"><option value="1" <?= (string)($form['status'] ?? '1') === '1' ? 'selected' : '' ?>>ใช้งาน</option><option value="0" <?= (string)($form['status'] ?? '') === '0' ? 'selected' : '' ?>>ปิดใช้งาน</option></select></div>
        <?php if ($accountStatus !== null): ?>
        <div class="col-md-4"><label for="accountStatus" class="form-label small">สถานะบัญชีเข้าสู่ระบบ</label><select id="accountStatus" name="account_status" class="form-select"><option value="1" <?= (string)($form['account_status'] ?? '1') === '1' ? 'selected' : '' ?>>ใช้งาน</option><option value="0" <?= (string)($form['account_status'] ?? '') === '0' ? 'selected' : '' ?>>ระงับบัญชี</option></select></div>
        <?php endif; ?>
        <?php else: ?>
        <div class="col-md-4"><label for="userRole" class="form-label small">สิทธิ์ผู้ใช้งาน</label><select id="userRole" name="role" class="form-select"><?php foreach ((hasRole(['admin']) ? ['employee', 'manager', 'hr', 'admin'] : ['employee', 'manager', 'hr']) as $role): ?><option value="<?= $escape($role) ?>" <?= ($form['role'] ?? 'employee') === $role ? 'selected' : '' ?>><?= $escape(strtoupper($role)) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label for="initPassword" class="form-label small">รหัสผ่านเริ่มต้น (อย่างน้อย 6 ตัวอักษร)</label><input id="initPassword" type="password" name="init_password" class="form-control" minlength="6" autocomplete="new-password" required></div>
        <div class="col-md-4"><label for="shift" class="form-label small">กะการทำงาน</label><select id="shift" name="shift_id" class="form-select"><option value="">-- ไม่กำหนด --</option><?php foreach ($shifts as $shift): ?><option value="<?= (int)$shift['id'] ?>"><?= $escape($shift['shift_name']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>

        <div class="col-12"><h3 class="h6 fw-bold text-info mt-2">เอกสารแนบ</h3></div>
        <div class="col-md-4"><label for="docType" class="form-label small">ประเภทเอกสาร</label><select id="docType" name="doc_type" class="form-select"><option value="contract">สัญญาจ้างงาน</option><option value="id_card">สำเนาบัตรประชาชน</option><option value="house_reg">ทะเบียนบ้าน</option><option value="certificate">ใบรับรอง</option><option value="other">อื่น ๆ</option></select></div>
        <div class="col-md-8"><label for="docFile" class="form-label small">ไฟล์ (PDF, JPG, PNG ไม่เกิน 5 MB)</label><input id="docFile" type="file" name="doc_file" accept=".pdf,.jpg,.jpeg,.png" class="form-control"></div>
        <div class="col-12 mt-3">
            <button type="submit" name="save_emp" class="btn btn-primary btn-modern">บันทึกข้อมูล</button>
            <a href="employees.php" class="btn btn-light btn-modern">กลับหน้ารายการ</a>
        </div>
    </form>

    <?php if ($docs): ?>
    <hr class="my-4">
    <h3 class="h6 fw-bold mb-3">เอกสารของพนักงาน</h3>
    <ul class="list-group">
        <?php foreach ($docs as $document): ?>
        <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><?= $escape($document['file_name']) ?> <small class="text-muted"><?= $escape($document['doc_type']) ?></small>
                <span class="badge <?= (int)$document['status'] === 1 ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>"><?= (int)$document['status'] === 1 ? 'ใช้งาน' : 'ปิดใช้งาน' ?></span>
            </span>
            <div class="d-flex gap-2">
                <?php if ((int)$document['status'] === 1): ?><a href="<?= BASE_URL ?>/modules/personnel/document_download.php?id=<?= (int)$document['id'] ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">เปิดดู</a><?php endif; ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                    <input type="hidden" name="document_id" value="<?= (int)$document['id'] ?>">
                    <input type="hidden" name="document_status" value="<?= (int)$document['status'] === 1 ? 0 : 1 ?>">
                    <button type="submit" name="set_document_status" class="btn btn-sm <?= (int)$document['status'] === 1 ? 'btn-outline-secondary' : 'btn-outline-success' ?>"><?= (int)$document['status'] === 1 ? 'ปิดใช้งาน' : 'เปิดใช้งาน' ?></button>
                </form>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>

<?php include ROOT_PATH . '/includes/layout_bottom.php'; ?>
