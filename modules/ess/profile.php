<?php
    require_once __DIR__ . '/../../includes/auth.php';
    require_once ROOT_PATH . '/config/cloud_storage.php';
    requireLogin();

    $employeeId = (string) ($_SESSION['employee_id'] ?? '');
    $error      = '';

    if ($employeeId === '') {
        $accountStmt = $pdo->prepare('SELECT employee_id FROM users WHERE id = ?');
        $accountStmt->execute([$_SESSION['user_id']]);
        $accountEmployeeId = $accountStmt->fetchColumn();
        if (is_string($accountEmployeeId) && $accountEmployeeId !== '') {
            $employeeId = $accountEmployeeId;
            $_SESSION['employee_id'] = $employeeId;
        }
    }

    $employeeStmt = $pdo->prepare('SELECT e.emp_code, e.first_name, e.last_name, e.email, e.phone, e.profile_image_url, d.name AS department, p.title AS position FROM employees e LEFT JOIN departments d ON e.department_id = d.id LEFT JOIN positions p ON e.position_id = p.id WHERE e.id = ?');
    $employeeStmt->execute([$employeeId]);
    $employee = $employeeStmt->fetch();
    if (!$employee) {
        $accountStmt = $pdo->prepare('SELECT employee_id FROM users WHERE id = ?');
        $accountStmt->execute([$_SESSION['user_id']]);
        $accountEmployeeId = $accountStmt->fetchColumn();
        if (is_string($accountEmployeeId) && $accountEmployeeId !== '') {
            $employeeId = $accountEmployeeId;
            $_SESSION['employee_id'] = $employeeId;
            $employeeStmt->execute([$employeeId]);
            $employee = $employeeStmt->fetch();
        }
        if (!$employee) {
            http_response_code(404);
            exit('ไม่พบข้อมูลพนักงาน');
        }
    }
    $profileImageUrl = getSafeProfileImageUrl($employee['profile_image_url'] ?? null);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_profile_image'])) {
    requireValidCsrfToken();
    $file = $_FILES['profile_image'] ?? null;
    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['size'])) {
        $error = 'ข้อมูลรูปโปรไฟล์ไม่ถูกต้อง';
    } elseif ($file['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'กรุณาเลือกรูป JPG หรือ PNG เพื่ออัปโหลด';
    } elseif ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        $error = 'รูปโปรไฟล์ต้องมีขนาดไม่เกิน 5 MB';
    } elseif ($file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name']) ||
        !is_int($file['size']) || !is_uploaded_file($file['tmp_name'])) {
        $error = 'ไม่สามารถอัปโหลดรูปโปรไฟล์ได้ กรุณาลองใหม่';
    } elseif ($file['size'] < 1 || $file['size'] > 5 * 1024 * 1024) {
        $error = 'รูปโปรไฟล์ต้องมีขนาดไม่เกิน 5 MB';
    } else {
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!is_string($mimeType) || !isset($allowedMimeTypes[$mimeType])) {
            $error = 'รูปโปรไฟล์ต้องเป็นไฟล์ JPG หรือ PNG';
        } else {
            try {
                $cloud = CloudStorage::upload(
                    $file['tmp_name'],
                    'profile-image.' . $allowedMimeTypes[$mimeType],
                    'profile_images'
                );
            } catch (RuntimeException $exception) {
                $error = 'ไม่สามารถบันทึกรูปโปรไฟล์ได้ กรุณาลองใหม่';
            }

            if ($error === '') {
                $pdo->prepare('UPDATE employees SET profile_image_url = ? WHERE id = ?')
                    ->execute([$cloud['url'], $employeeId]);
                $_SESSION['profile_notice'] = 'อัปเดตรูปโปรไฟล์สำเร็จ';
                header('Location: ' . BASE_URL . '/modules/ess/profile.php');
                exit;
            }
        }
    }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_pass'])) {
    requireValidCsrfToken();
    $currentPassword = $_POST['old_pass'] ?? '';
    $newPassword     = $_POST['new_pass'] ?? '';
    $confirmPassword = $_POST['confirm_pass'] ?? '';

    $typeCount = 0;
    if (is_string($newPassword)) {
        $typeCount = (preg_match('/[A-Z]/', $newPassword) ? 1 : 0)
             + (preg_match('/[a-z]/', $newPassword) ? 1 : 0)
             + (preg_match('/[0-9]/', $newPassword) ? 1 : 0)
             + (preg_match('/[\p{P}\p{S}]/u', $newPassword) ? 1 : 0);
    }

    if (! is_string($currentPassword) || ! is_string($newPassword) || ! is_string($confirmPassword)) {
        $error = 'ข้อมูลรหัสผ่านไม่ถูกต้อง';
    } elseif (mb_strlen($newPassword) < 8) {
        $error = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร';
    } elseif (preg_match('/\s/u', $newPassword)) {
        $error = 'รหัสผ่านใหม่ต้องไม่มีช่องว่าง';
    } elseif ($typeCount < 2) {
        $error = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 2 ประเภท จาก A-Z, a-z, 0-9 และอักขระพิเศษ (เช่น S@123456)';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'รหัสผ่านใหม่และการยืนยันไม่ตรงกัน';
    } else {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE employee_id = ?');
        $stmt->execute([$employeeId]);
        $passwordHash = $stmt->fetchColumn();

        if (! is_string($passwordHash) || ! password_verify($currentPassword, $passwordHash)) {
            $error = 'รหัสผ่านเดิมไม่ถูกต้อง';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 6]);
            $pdo->prepare('UPDATE users SET password = ?, password_hash = ? WHERE employee_id = ?')
                ->execute([$newPassword, $newHash, $employeeId]);
            session_regenerate_id(true);
            $_SESSION['profile_notice'] = 'เปลี่ยนรหัสผ่านสำเร็จ';
            header('Location: ' . BASE_URL . '/modules/ess/profile.php');
            exit;
        }
    }
    }

    $notice = $_SESSION['profile_notice'] ?? '';
    unset($_SESSION['profile_notice']);

    $slipStmt = $pdo->prepare('SELECT pi.id, pi.net_salary, pp.period_name, pp.pay_date FROM payroll_items pi JOIN payroll_periods pp ON pi.period_id = pp.id WHERE pi.employee_id = ? ORDER BY pp.id DESC');
    $slipStmt->execute([$employeeId]);
    $slips = $slipStmt->fetchAll();

    $docStmt = $pdo->prepare('SELECT file_name, file_url FROM cloud_documents WHERE employee_id = ? AND status = 1 ORDER BY uploaded_at DESC');
    $docStmt->execute([$employeeId]);
    $documents = $docStmt->fetchAll();

    $pageTitle = 'โปรไฟล์และสลิปเงินเดือนของฉัน';
    include ROOT_PATH . '/includes/layout_top.php';
?>

<?php if ($notice !== ''): ?><div class="alert alert-success rounded-3"><?php echo htmlspecialchars($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger rounded-3"><?php echo htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <section class="glass-card p-4 h-100">
            <h2 class="h6 fw-bold mb-3">ข้อมูลพนักงาน</h2>
            <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
                <div class="avatar-circle" style="width:88px;height:88px;font-size:2rem;flex-shrink:0;">
                    <?php if ($profileImageUrl !== null): ?>
                        <img class="avatar-image" src="<?php echo htmlspecialchars($profileImageUrl) ?>" alt="รูปโปรไฟล์ของ <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?>">
                    <?php else: ?>
                        <?php echo htmlspecialchars(mb_substr($employee['first_name'], 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <form method="post" enctype="multipart/form-data" class="flex-grow-1">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()) ?>">
                    <label for="profileImage" class="form-label small fw-semibold">รูปโปรไฟล์</label>
                    <div class="d-flex flex-wrap gap-2">
                        <input id="profileImage" type="file" name="profile_image" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="form-control" style="max-width:360px;" required>
                        <button type="submit" name="upload_profile_image" class="btn btn-primary btn-modern">อัปโหลดรูป</button>
                    </div>
                    <div class="form-text">รองรับ JPG หรือ PNG ขนาดไม่เกิน 5 MB</div>
                </form>
            </div>
            <dl class="row mb-4">
                <dt class="col-sm-4">รหัสพนักงาน</dt><dd class="col-sm-8"><?php echo htmlspecialchars($employee['emp_code']) ?></dd>
                <dt class="col-sm-4">ชื่อ-นามสกุล</dt><dd class="col-sm-8"><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?></dd>
                <dt class="col-sm-4">แผนก</dt><dd class="col-sm-8"><?php echo htmlspecialchars($employee['department'] ?? '-') ?></dd>
                <dt class="col-sm-4">ตำแหน่ง</dt><dd class="col-sm-8"><?php echo htmlspecialchars($employee['position'] ?? '-') ?></dd>
                <dt class="col-sm-4">อีเมล</dt><dd class="col-sm-8"><?php echo htmlspecialchars($employee['email']) ?></dd>
                <dt class="col-sm-4">เบอร์โทร</dt><dd class="col-sm-8"><?php echo htmlspecialchars($employee['phone'] ?? '-') ?></dd>
            </dl>

            <h2 class="h6 fw-bold mb-3">สลิปเงินเดือนย้อนหลัง</h2>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light"><tr><th>งวดเดือน</th><th>วันที่จ่าย</th><th>สุทธิ</th><th>สลิป</th></tr></thead>
                    <tbody>
                    <?php if (! $slips): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีงวดเงินเดือนที่ประมวลผลแล้ว</td></tr>
                    <?php else: foreach ($slips as $slip): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($slip['period_name']) ?></td>
                            <td><?php echo htmlspecialchars($slip['pay_date']) ?></td>
                            <td class="text-success fw-semibold">฿<?php echo number_format((float) $slip['net_salary'], 2) ?></td>
                            <td><a href="<?php echo BASE_URL ?>/modules/payroll/payslip.php?item_id=<?php echo (int) $slip['id'] ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary rounded-pill">ดูสลิป</a></td>
                        </tr>
                    <?php endforeach;endif; ?>
                    </tbody>
                </table>
            </div>

            <h2 class="h6 fw-bold mt-4 mb-2">เอกสารส่วนตัว</h2>
            <?php if (! $documents): ?>
                <p class="text-muted small mb-0">ไม่มีเอกสาร</p>
            <?php else: foreach ($documents as $document):
                        $fileUrl = (string) $document['file_url'];
                        $scheme  = parse_url($fileUrl, PHP_URL_SCHEME);
                        $safeUrl = str_starts_with($fileUrl, BASE_URL . '/uploads/') || $scheme === 'https';
            ?>
                <?php if ($safeUrl): ?>
                    <a href="<?php echo htmlspecialchars($fileUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-light border me-1 mb-1">
                        <i class="bi bi-file-earmark me-1" aria-hidden="true"></i><?php echo htmlspecialchars($document['file_name']) ?>
                    </a>
                <?php else: ?>
                    <span class="btn btn-sm btn-light border text-muted me-1 mb-1" aria-disabled="true">
                        <i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i><?php echo htmlspecialchars($document['file_name']) ?> (ลิงก์ไม่ปลอดภัย)
                    </span>
                <?php endif; ?>
            <?php endforeach;endif; ?>
        </section>
    </div>

    <div class="col-lg-5">
        <section class="glass-card p-4">
            <h2 class="h6 fw-bold mb-3">เปลี่ยนรหัสผ่าน</h2>
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()) ?>">
                <div class="col-12">
                    <label for="oldPassword" class="form-label small">รหัสผ่านเดิม</label>
                    <div class="input-group">
                        <input id="oldPassword" type="password" name="old_pass" class="form-control" autocomplete="current-password" required>
                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="oldPassword" aria-label="แสดงรหัสผ่าน">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <div class="col-12">
                    <label for="newPassword" class="form-label small">รหัสผ่านใหม่</label>
                    <div class="input-group">
                        <input
                            id="newPassword"
                            type="password"
                            name="new_pass"
                            minlength="8"
                            pattern="(?:(?=.*[A-Z])(?=.*[a-z])|(?=.*[A-Z])(?=.*[0-9])|(?=.*[A-Z])(?=.*[^A-Za-z0-9\s])|(?=.*[a-z])(?=.*[0-9])|(?=.*[a-z])(?=.*[^A-Za-z0-9\s])|(?=.*[0-9])(?=.*[^A-Za-z0-9\s]))\S{8,}"
                            title="ต้องมีอย่างน้อย 8 ตัวอักษร ไม่มีช่องว่าง และมีอย่างน้อย 2 ประเภท จาก A-Z, a-z, 0-9 หรืออักขระพิเศษ (เช่น P@123456)"
                            class="form-control"
                            autocomplete="new-password"
                            required
                        >
                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="newPassword" aria-label="แสดงรหัสผ่าน">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="form-text">อย่างน้อย 8 ตัวอักษร ต้องมีอย่างน้อย 2 ประเภท จาก A-Z, a-z, 0-9 และอักขระพิเศษ (เช่น P@123456)</div>
                </div>
                <div class="col-12">
                    <label for="confirmPassword" class="form-label small">ยืนยันรหัสผ่านใหม่</label>
                    <div class="input-group">
                        <input id="confirmPassword" type="password" name="confirm_pass" minlength="8" class="form-control" autocomplete="new-password" required>
                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="confirmPassword" aria-label="แสดงรหัสผ่าน">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" name="change_pass" class="btn btn-primary btn-modern w-100">อัปเดตรหัสผ่าน</button>
                </div>
            </form>
        </section>
    </div>
</div>

<script>
document.querySelectorAll('.toggle-password').forEach(function (button) {
    button.addEventListener('click', function () {
        const input = document.getElementById(button.dataset.target);
        const icon = button.querySelector('i');
        if (!input || !icon) return;

        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        icon.classList.toggle('bi-eye', !isHidden);
        icon.classList.toggle('bi-eye-slash', isHidden);
        button.setAttribute('aria-label', isHidden ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
    });
});
</script>

<?php include ROOT_PATH . '/includes/layout_bottom.php'; ?>