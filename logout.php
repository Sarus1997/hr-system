<?php
    require_once __DIR__ . '/config/app.php';

    if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    }

    // ล้างข้อมูล session และคุกกี้ให้หมด
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $cookie['path'],
        $cookie['domain'],
        $cookie['secure'],
        $cookie['httponly']
    );
    }
    session_destroy();

    header('Cache-Control: no-store');

    $loginUrl        = BASE_URL . '/login.php';
    $redirectSeconds = 4;
?>
<!DOCTYPE html>
<html lang="th" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#f6f7fb">
    <meta http-equiv="refresh" content="<?php echo (int) $redirectSeconds ?>;url=<?php echo htmlspecialchars($loginUrl) ?>">
    <title>ออกจากระบบแล้ว - <?php echo htmlspecialchars(APP_NAME) ?></title>
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo BASE_URL ?>/assets/css/style.css?v=<?php echo @filemtime(ROOT_PATH . '/assets/css/style.css') ?: 1 ?>" rel="stylesheet">
    <link href="<?php echo BASE_URL ?>/assets/css/logout.css?v=<?php echo @filemtime(ROOT_PATH . '/assets/css/logout.css') ?: 1 ?>" rel="stylesheet">
</head>
<body>
<div class="scene" aria-hidden="true">
    <div class="aurora"><i></i><i></i><i></i></div>
    <div class="grid-bg"></div>
</div>

<main class="bye">
    <div class="bye-brand rise" style="--i:0">
        <div class="brand-icon"><i class="bi bi-layers-half" aria-hidden="true"></i></div>
        <div>
            <div class="brand-name">NEXUS HR</div>
            <div class="brand-sub">Cloud Workforce Suite</div>
        </div>
    </div>

    <section class="bye-card rise" style="--i:1" role="status" aria-live="polite">
        <div class="check" aria-hidden="true">
            <span class="ring"></span>
            <span class="ring ring-2"></span>
            <svg viewBox="0 0 64 64" width="84" height="84">
                <circle class="check-circle" cx="32" cy="32" r="28" pathLength="100"/>
                <path class="check-mark" d="M20 33 L29 42 L45 24" pathLength="100"/>
            </svg>
        </div>

        <h1 class="bye-title rise" style="--i:3">ออกจากระบบเรียบร้อยแล้ว</h1>
        <p class="bye-sub rise" style="--i:4">ขอบคุณที่ใช้งาน ข้อมูลเซสชันของคุณถูกล้างแล้ว<br>ระบบจะพากลับไปหน้าเข้าสู่ระบบอัตโนมัติ</p>

        <div class="countdown rise" style="--i:5" aria-hidden="true">
            <span class="countdown-bar" style="--secs: <?php echo (int) $redirectSeconds ?>s"></span>
        </div>

        <a href="<?php echo htmlspecialchars($loginUrl) ?>" class="btn-back rise" style="--i:6">
            <span class="label"><i class="bi bi-arrow-left" aria-hidden="true"></i> เข้าสู่ระบบอีกครั้ง</span>
        </a>
    </section>

    <div class="bye-foot rise" style="--i:7">&copy; <?php echo date('Y') ?> <?php echo htmlspecialchars(APP_NAME) ?></div>
</main>
</body>
</html>