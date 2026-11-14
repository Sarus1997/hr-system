<?php
    require_once __DIR__ . '/config/app.php';
    require_once ROOT_PATH . '/config/db.php';

    $loginFailureThreshold   = 3;
    $loginFailureWindow      = 900;
    $failedLoginAttempts     = $_SESSION['login_failed_attempts'] ?? 0;
    $failureWindowStartedAt  = $_SESSION['login_failure_started_at'] ?? null;
    $now                     = time();

    if (! is_int($failedLoginAttempts) || $failedLoginAttempts < 0 ||
        ! is_int($failureWindowStartedAt) || $failureWindowStartedAt > $now ||
        $failureWindowStartedAt < $now - $loginFailureWindow) {
        unset($_SESSION['login_failed_attempts'], $_SESSION['login_failure_started_at'], $_SESSION['login_captcha']);
        $failedLoginAttempts = 0;
        $failureWindowStartedAt = null;
    }

    $captchaRequired = $failedLoginAttempts >= $loginFailureThreshold;
    $captcha         = $_SESSION['login_captcha'] ?? null;
    $refreshCaptcha  = static function (): array {
        $left  = random_int(2, 9);
        $right = random_int(1, 9);
        $challenge = [
            'question' => $left . ' + ' . $right,
            'answer'   => (string) ($left + $right),
        ];
        $_SESSION['login_captcha'] = $challenge;

        return $challenge;
    };

    if ($captchaRequired &&
        (! is_array($captcha) || ! isset($captcha['question'], $captcha['answer']) ||
            ! is_string($captcha['question']) || ! is_string($captcha['answer']))) {
        $captcha = $refreshCaptcha();
    }

    $usernameValue = isset($_POST['username']) && is_string($_POST['username']) ? $_POST['username'] : '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $captchaValid = true;
        if ($captchaRequired) {
            $providedAnswer = $_POST['captcha_answer'] ?? null;
            $expectedAnswer = is_array($captcha) ? ($captcha['answer'] ?? null) : null;
            if (! is_string($providedAnswer) || ! preg_match('/^\d{1,2}$/D', $providedAnswer) ||
                ! is_string($expectedAnswer) || ! hash_equals($expectedAnswer, $providedAnswer)) {
                $captchaValid = false;
                $error        = 'คำตอบตรวจสอบความปลอดภัยไม่ถูกต้อง กรุณาลองอีกครั้ง';
                $captcha      = $refreshCaptcha();
            } else {
                unset($_SESSION['login_captcha']);
                $captcha = null;
            }
        }

        if ($captchaValid) {
            $username = $_POST['username'] ?? null;
            $password = $_POST['password'] ?? null;
            $user     = null;

            if (is_string($username) && is_string($password)) {
                $stmt = $pdo->prepare(
                    "SELECT u.*, e.first_name, e.last_name
                     FROM users u
                     JOIN employees e ON u.employee_id = e.id
                     WHERE u.username = ? AND u.status = 1 AND e.status = 1
                       AND e.employment_status IN ('active', 'probation')"
                );
                $stmt->execute([$username]);
                $user = $stmt->fetch();
            }

            if ($user && is_string($password) && password_verify($password, $user['password_hash'])) {
                $passwordHash  = $user['password_hash'];
                $bcryptOptions = ['cost' => 12];
                if (password_needs_rehash($passwordHash, PASSWORD_BCRYPT, $bcryptOptions)) {
                    $passwordHash = password_hash($password, PASSWORD_BCRYPT, $bcryptOptions);
                }

                $pdo->prepare('UPDATE users SET last_login = NOW(), password = ?, password_hash = ? WHERE id = ?')
                    ->execute([$passwordHash, $passwordHash, $user['id']]);
                session_regenerate_id(true);
                unset($_SESSION['login_failed_attempts'], $_SESSION['login_failure_started_at'], $_SESSION['login_captcha']);
                $_SESSION['user_id']     = $user['id'];
                $_SESSION['employee_id'] = $user['employee_id'];
                $_SESSION['role']        = $user['role'];
                $_SESSION['full_name']   = $user['first_name'] . ' ' . $user['last_name'];
                header("Location: " . BASE_URL . "/login.php?welcome=1");
                exit;
            }

            if ($failedLoginAttempts === 0) {
                $_SESSION['login_failure_started_at'] = $now;
            }
            $failedLoginAttempts = min($failedLoginAttempts + 1, 1000000);
            $_SESSION['login_failed_attempts'] = $failedLoginAttempts;
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
            $captchaRequired = $failedLoginAttempts >= $loginFailureThreshold;

            if ($captchaRequired) {
                $captcha = $refreshCaptcha();
            }
        }
    }

    // หน้าต้อนรับหลังล็อกอินสำเร็จ (แสดงเฉพาะคนที่ล็อกอินแล้วเท่านั้น)
    $welcome        = isset($_GET['welcome']) && ! empty($_SESSION['user_id']);
    $welcomeName    = (string) ($_SESSION['full_name'] ?? '');
    $welcomeInitial = $welcomeName !== '' ? mb_substr($welcomeName, 0, 1) : 'U';
    $roleLabels     = ['admin' => 'ผู้ดูแลระบบ', 'hr' => 'ฝ่ายบุคคล', 'manager' => 'ผู้จัดการ', 'employee' => 'พนักงาน'];
    $welcomeRole    = $roleLabels[$_SESSION['role'] ?? ''] ?? (string) ($_SESSION['role'] ?? '');
    $dashboardUrl   = BASE_URL . '/index.php';
?>
<!DOCTYPE html>
<html lang="th" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <?php if ($welcome): ?>
    <meta http-equiv="refresh" content="5;url=<?php echo htmlspecialchars($dashboardUrl) ?>">
    <?php endif; ?>
    <title>เข้าสู่ระบบ - <?php echo htmlspecialchars(APP_NAME) ?></title>

    <!-- ตั้งธีมก่อนวาดหน้า (ใช้ค่าเดียวกับทั้งระบบ) -->
    <script>
    (function () {
        try {
            var saved = localStorage.getItem('hr-theme');
            var theme = saved || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-bs-theme', theme);
        } catch (e) {}
    })();
    </script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo BASE_URL ?>/assets/css/style.css?v=<?php echo @filemtime(ROOT_PATH . '/assets/css/style.css') ?: 1 ?>" rel="stylesheet">
    <link href="<?php echo BASE_URL ?>/assets/css/login.css?v=<?php echo @filemtime(ROOT_PATH . '/assets/css/login.css') ?: 1 ?>" rel="stylesheet">
</head>
<body>
<?php if ($welcome): ?>
<div class="welcome-scene" aria-hidden="true">
    <div class="aurora"><i></i><i></i><i></i></div>
    <div class="grid-bg"></div>
</div>

<main class="welcome" role="status" aria-live="polite">
    <div class="welcome-avatar" aria-hidden="true">
        <svg viewBox="0 0 132 132" width="132" height="132">
            <defs>
                <linearGradient id="ringGrad" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#818cf8"/>
                    <stop offset="1" stop-color="#22d3ee"/>
                </linearGradient>
            </defs>
            <circle class="ring-track" cx="66" cy="66" r="62"/>
            <circle class="ring-prog" cx="66" cy="66" r="62" pathLength="100"/>
        </svg>
        <div class="welcome-disc"><?php echo htmlspecialchars($welcomeInitial) ?></div>
        <span class="welcome-ok"><i class="bi bi-check-lg"></i></span>
    </div>

    <p class="welcome-greet" id="greet">ยินดีต้อนรับกลับ</p>
    <h1 class="welcome-name"><span><?php echo htmlspecialchars($welcomeName) ?></span></h1>
    <?php if ($welcomeRole !== ''): ?>
        <span class="welcome-role"><?php echo htmlspecialchars($welcomeRole) ?></span>
    <?php endif; ?>

    <div class="welcome-load" aria-hidden="true"><span class="bar"></span></div>
    <p class="welcome-hint">กำลังเตรียมแดชบอร์ดของคุณ…</p>
</main>
<div class="welcome-wipe" aria-hidden="true"></div>

<script>
(function () {
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var target = <?php echo json_encode($dashboardUrl) ?>;

    var h = new Date().getHours();
    var greet = h < 12 ? 'สวัสดีตอนเช้า' : (h < 17 ? 'สวัสดีตอนบ่าย' : 'สวัสดีตอนเย็น');
    document.getElementById('greet').textContent = greet;

    setTimeout(function () {
        document.body.classList.add('is-leaving');
        setTimeout(function () { location.replace(target); }, reduce ? 0 : 600);
    }, reduce ? 900 : 2800);
})();
</script>
<?php else: ?>
<div class="auth">

    <!-- ===== แผงแบรนด์ ===== -->
    <aside class="auth-brand" id="brandPanel" aria-hidden="false">
        <div class="aurora" aria-hidden="true"><i></i><i></i><i></i></div>
        <div class="grid-bg" aria-hidden="true"></div>
        <div class="spot" aria-hidden="true"></div>

        <div class="brand-top rise" style="--i:0">
            <div class="brand-icon"><i class="bi bi-layers-half" aria-hidden="true"></i></div>
            <div>
                <div class="brand-name">NEXUS HR</div>
                <div class="brand-sub">Cloud Workforce Suite</div>
            </div>
        </div>

        <div>
            <h2 class="headline">
                <span class="line"><span style="--i:0">บริหารคน เวลา</span></span>
                <span class="line"><span style="--i:1">และเงินเดือน</span></span>
                <span class="line"><span style="--i:2">ในที่เดียว</span></span>
            </h2>
            <p class="lead-copy rise" style="--i:6">ระบบบริหารทรัพยากรบุคคลครบวงจร ตั้งแต่ลงเวลา การลา ไปจนถึงภาษีและสลิปเงินเดือน</p>
        </div>

        <div class="feat-stack">
            <div class="feat" style="--i:0">
                <span class="feat-icon"><i class="bi bi-person-bounding-box" aria-hidden="true"></i></span>
                <div><strong>ลงเวลาด้วยใบหน้า 3D และ GPS</strong><span class="d">แม่นยำ ยืนยันตัวตนได้ในไม่กี่วินาที</span></div>
            </div>
            <div class="feat" style="--i:1">
                <span class="feat-icon"><i class="bi bi-patch-check" aria-hidden="true"></i></span>
                <div><strong>อนุมัติลาและ OT ออนไลน์</strong><span class="d">แจ้งเตือนทันที ไม่ตกหล่น</span></div>
            </div>
            <div class="feat" style="--i:2">
                <span class="feat-icon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
                <div><strong>เงินเดือนและภาษีอัตโนมัติ</strong><span class="d">รายงาน ภ.ง.ด.1 และ สปส. ครบถ้วน</span></div>
            </div>
        </div>

        <div class="brand-foot">&copy; <?php echo date('Y') ?> <?php echo htmlspecialchars(APP_NAME) ?></div>
    </aside>

    <!-- ===== แผงฟอร์ม ===== -->
    <main class="auth-main">
        <button class="btn btn-light notification-trigger theme-toggle auth-theme" id="themeToggle" type="button"
                aria-label="สลับธีมสว่าง / มืด" aria-pressed="false" title="สลับธีมสว่าง / มืด">
            <i class="bi bi-moon-stars-fill icon-moon" aria-hidden="true"></i>
            <i class="bi bi-sun-fill icon-sun" aria-hidden="true"></i>
        </button>

        <div class="form-wrap<?php echo ! empty($error) ? ' shake has-error' : '' ?>" id="formWrap">
            <div class="mobile-brand rise" style="--i:0">
                <div class="brand-icon"><i class="bi bi-layers-half text-white" aria-hidden="true"></i></div>
                <div>
                    <div class="brand-name">NEXUS HR</div>
                    <div class="brand-sub">Cloud Workforce Suite</div>
                </div>
            </div>

            <h1 class="form-title rise" style="--i:1">ยินดีต้อนรับกลับ</h1>
            <p class="form-sub rise" style="--i:2">เข้าสู่ระบบเพื่อจัดการงานของคุณ</p>

            <?php if (! empty($error)): ?>
                <div class="auth-alert" role="alert">
                    <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                    <span><?php echo htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" id="loginForm">
                <div class="field rise" style="--i:3">
                    <label class="field-label" for="userInp">ชื่อผู้ใช้งาน</label>
                    <div class="field-box">
                        <i class="bi bi-person bi-lead" aria-hidden="true"></i>
                        <input type="text" name="username" id="userInp" class="field-input"
                               placeholder="เช่น admin" autocomplete="username" autocapitalize="none" spellcheck="false"
                               value="<?php echo htmlspecialchars($usernameValue, ENT_QUOTES, 'UTF-8') ?>" required autofocus>
                    </div>
                </div>

                <div class="field rise" style="--i:4">
                    <label class="field-label" for="passInp">รหัสผ่าน</label>
                    <div class="field-box">
                        <i class="bi bi-lock bi-lead" aria-hidden="true"></i>
                        <input type="password" name="password" id="passInp" class="field-input"
                               placeholder="••••••••" autocomplete="current-password" required>
                        <button type="button" class="pw-toggle" id="pwToggle" aria-label="แสดงรหัสผ่าน" aria-pressed="false">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="caps-hint" id="capsHint" hidden>
                        <i class="bi bi-capslock-fill" aria-hidden="true"></i> เปิด Caps Lock อยู่
                    </div>
                </div>

                <?php if ($captchaRequired): ?>
                    <div class="field rise" style="--i:5">
                        <label class="field-label" for="captchaInp">
                            ยืนยันความปลอดภัย: <?php echo htmlspecialchars($captcha['question'], ENT_QUOTES, 'UTF-8') ?> = ?
                        </label>
                        <div class="field-box">
                            <i class="bi bi-shield-check bi-lead" aria-hidden="true"></i>
                            <input type="number" name="captcha_answer" id="captchaInp" class="field-input"
                                   inputmode="numeric" min="0" max="18" step="1" autocomplete="off" required>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="rise" style="--i:<?php echo $captchaRequired ? 6 : 5 ?>">
                    <button type="submit" class="btn-signin" id="signinBtn">
                        <span class="label">เข้าสู่ระบบ <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                        <span class="spinner" aria-hidden="true"></span>
                    </button>
                </div>
            </form>

            <!-- บัญชีทดสอบ: ควรลบส่วนนี้ออกก่อนขึ้นระบบจริง -->
            <div class="demo rise" style="--i:6">
                <div class="demo-title">บัญชีทดสอบ (คลิกเพื่อกรอกอัตโนมัติ)</div>
                <div class="chip-row">
                    <button type="button" class="chip" onclick="fillLogin('admin')">Admin</button>
                    <button type="button" class="chip" onclick="fillLogin('hr')">HR</button>
                    <button type="button" class="chip" onclick="fillLogin('manager')">Manager</button>
                    <button type="button" class="chip" onclick="fillLogin('employee')">Employee</button>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
function fillLogin(u) {
    document.getElementById('userInp').value = u;
    document.getElementById('passInp').value = 'password';
    document.getElementById('signinBtn').focus();
}

(function () {
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var root = document.documentElement;

    /* แสดง/ซ่อนรหัสผ่าน */
    var pw = document.getElementById('passInp');
    var pwBtn = document.getElementById('pwToggle');
    pwBtn.addEventListener('click', function () {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        pwBtn.setAttribute('aria-pressed', String(show));
        pwBtn.setAttribute('aria-label', show ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
        pwBtn.firstElementChild.className = 'bi ' + (show ? 'bi-eye-slash' : 'bi-eye');
        pw.focus();
    });

    /* เตือน Caps Lock */
    var caps = document.getElementById('capsHint');
    function checkCaps(e) {
        if (e.getModifierState) caps.hidden = !e.getModifierState('CapsLock');
    }
    pw.addEventListener('keydown', checkCaps);
    pw.addEventListener('keyup', checkCaps);
    pw.addEventListener('blur', function () { caps.hidden = true; });

    /* ปุ่มกำลังโหลดตอนส่งฟอร์ม */
    var form = document.getElementById('loginForm');
    var btn = document.getElementById('signinBtn');
    form.addEventListener('submit', function () { btn.classList.add('is-loading'); });
    window.addEventListener('pageshow', function () { btn.classList.remove('is-loading'); });

    /* ถ้ามีข้อผิดพลาด ให้โฟกัสช่องรหัสผ่านทันที */
    <?php if (! empty($error)): ?>
    <?php if ($captchaRequired): ?>
    document.getElementById('captchaInp').focus();
    <?php else: ?>
    pw.focus();
    <?php endif; ?>
    <?php endif; ?>

    /* แสงตามเมาส์บนแผงแบรนด์ */
    var panel = document.getElementById('brandPanel');
    if (!reduce && window.matchMedia('(hover: hover)').matches) {
        panel.addEventListener('pointermove', function (e) {
            var r = panel.getBoundingClientRect();
            panel.style.setProperty('--px', (e.clientX - r.left) + 'px');
            panel.style.setProperty('--py', (e.clientY - r.top) + 'px');
        });
    }

    /* สลับธีม (เก็บค่าเดียวกับทั้งระบบ) */
    var toggle = document.getElementById('themeToggle');
    var KEY = 'hr-theme';
    function current() { return root.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light'; }
    function sync() { toggle.setAttribute('aria-pressed', String(current() === 'dark')); }
    function apply(t) { root.setAttribute('data-bs-theme', t); sync(); }
    sync();

    toggle.addEventListener('click', function () {
        var next = current() === 'dark' ? 'light' : 'dark';
        try { localStorage.setItem(KEY, next); } catch (e) {}

        if (reduce) { apply(next); return; }

        if (typeof document.startViewTransition === 'function') {
            var rect = toggle.getBoundingClientRect();
            var x = rect.left + rect.width / 2, y = rect.top + rect.height / 2;
            var radius = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));
            var t = document.startViewTransition(function () { apply(next); });
            t.ready.then(function () {
                root.animate(
                    { clipPath: ['circle(0px at ' + x + 'px ' + y + 'px)', 'circle(' + radius + 'px at ' + x + 'px ' + y + 'px)'] },
                    { duration: 650, easing: 'cubic-bezier(0.22, 1, 0.36, 1)', pseudoElement: '::view-transition-new(root)' }
                );
            }).catch(function () {});
        } else {
            root.classList.add('theme-fade');
            apply(next);
            setTimeout(function () { root.classList.remove('theme-fade'); }, 450);
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>