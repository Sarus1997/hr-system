<?php
    require_once __DIR__ . '/config/app.php';
    require_once ROOT_PATH . '/config/db.php';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare(
        "SELECT u.*, e.first_name, e.last_name
         FROM users u
         JOIN employees e ON u.employee_id = e.id
         WHERE u.username = ? AND u.status = 1 AND e.status = 1
           AND e.employment_status IN ('active', 'probation')"
    );
    $stmt->execute([$_POST['username']]);
    $user = $stmt->fetch();

    if ($user && password_verify($_POST['password'], $user['password_hash'])) {
        $passwordHash = $user['password_hash'];
        $bcryptOptions = ['cost' => 12];
        if (password_needs_rehash($passwordHash, PASSWORD_BCRYPT, $bcryptOptions)) {
            $passwordHash = password_hash($_POST['password'], PASSWORD_BCRYPT, $bcryptOptions);
        }

        $_SESSION['user_id']     = $user['id'];
        $_SESSION['employee_id'] = $user['employee_id'];
        $_SESSION['role']        = $user['role'];
        $_SESSION['full_name']   = $user['first_name'] . ' ' . $user['last_name'];
        $pdo->prepare('UPDATE users SET last_login = NOW(), password = ?, password_hash = ? WHERE id = ?')
            ->execute([$passwordHash, $passwordHash, $user['id']]);
        header("Location: " . BASE_URL . "/index.php");
        exit;
    }
    $error = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
    }
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - <?php echo APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
        body {
            background: radial-gradient(circle at top right, #4f46e5, #0f172a 60%);
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
        }
        .login-glass {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(20px);
            border-radius: 24px; padding: 2.5rem; width: 100%; max-width: 420px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
        }
    </style>
</head>
<body>
<div class="login-glass animate-up mx-3">
    <div class="text-center mb-4">
        <div class="brand-icon mx-auto mb-3" style="width: 56px; height: 56px; font-size: 1.6rem;">
            <i class="bi bi-layers-half text-white"></i>
        </div>
        <h4 class="fw-bold mb-1">NEXUS HR Cloud</h4>
        <p class="text-muted small">ระบบบริหารทรัพยากรบุคคลครบวงจร</p>
    </div>

    <?php if (! empty($error)): ?>
        <div class="alert alert-danger py-2 small rounded-3"><i class="bi bi-exclamation-circle me-1"></i><?php echo $error ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-3">
            <label class="form-label small fw-semibold">ชื่อผู้ใช้งาน</label>
            <input type="text" name="username" id="userInp" class="form-control form-control-lg rounded-3 fs-6" placeholder="เช่น admin" required>
        </div>
        <div class="mb-4">
            <label class="form-label small fw-semibold">รหัสผ่าน</label>
            <input type="password" name="password" id="passInp" class="form-control form-control-lg rounded-3 fs-6" placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn btn-primary btn-modern w-100 py-2 fs-6" style="background: linear-gradient(135deg, #4f46e5, #6366f1); border:none;">
            เข้าสู่ระบบ <i class="bi bi-arrow-right ms-1"></i>
        </button>
    </form>

    <div class="mt-4 pt-3 border-top text-center">
        <small class="text-muted d-block mb-2">บัญชีทดสอบ (คลิกเพื่อกรอกอัตโนมัติ):</small>
        <div class="d-flex justify-content-center gap-2 flex-wrap">
            <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill" onclick="fillLogin('admin')">Admin</button>
            <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill" onclick="fillLogin('hr')">HR</button>
            <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill" onclick="fillLogin('manager')">Manager</button>
            <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill" onclick="fillLogin('employee')">Employee</button>
        </div>
    </div>
</div>
<script>
function fillLogin(u) {
    document.getElementById('userInp').value = u;
    document.getElementById('passInp').value = 'password';
}
</script>
</body>
</html>