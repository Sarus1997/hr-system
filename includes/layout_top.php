<?php
require_once ROOT_PATH . '/includes/approval_workflow.php';
$approvalNotifications = getPendingApprovalNotifications($pdo, (string)($_SESSION['employee_id'] ?? ''));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo BASE_URL ?>/assets/css/style.css?v=<?php echo filemtime(ROOT_PATH . '/assets/css/style.css') ?>" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
</head>
<body>

<aside class="sidebar" id="sidebar" aria-hidden="false">
    <a href="<?php echo BASE_URL ?>/index.php" class="sidebar-brand">
        <div class="brand-icon"><i class="bi bi-layers-half"></i></div>
        <div>
            <div>NEXUS HR</div>
            <div style="font-size: 0.68rem; color: #94a3b8; font-weight: 400;">Cloud Workforce Suite</div>
        </div>
    </a>

    <button class="sidebar-close" id="sidebarClose" type="button" aria-label="ปิดเมนู">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
    </button>

    <nav aria-label="เมนูหลัก">
        <div class="nav-section">ภาพรวม</div>
        <a href="<?php echo BASE_URL ?>/index.php" class="nav-link <?php echo isCurrentPage('index.php') ?>" <?php echo isCurrentPage('index.php') ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-grid-1x2-fill" aria-hidden="true"></i> แดชบอร์ด
        </a>
        <a href="<?php echo BASE_URL ?>/modules/ess/ess_portal.php" class="nav-link <?php echo isCurrentPage('ess_portal.php') ?>" <?php echo isCurrentPage('ess_portal.php') ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-phone-vibrate" aria-hidden="true"></i> เช็กอิน / เช็กเอาต์
        </a>
        <a href="<?php echo BASE_URL ?>/modules/ess/leave_request.php" class="nav-link <?php echo isCurrentPage('leave_request.php') ?>" <?php echo isCurrentPage('leave_request.php') ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-calendar2-check" aria-hidden="true"></i> ระบบลางาน
        </a>
        <a href="<?php echo BASE_URL ?>/modules/ess/ot_request.php" class="nav-link <?php echo isCurrentPage('ot_request.php') ?>" <?php echo isCurrentPage('ot_request.php') ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-alarm" aria-hidden="true"></i> ยื่นขอ OT
        </a>
        <a href="<?php echo BASE_URL ?>/modules/ess/profile.php" class="nav-link <?php echo isCurrentPage('profile.php') ?>" <?php echo isCurrentPage('profile.php') ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-person-circle" aria-hidden="true"></i> โปรไฟล์และสลิปเงินเดือน
        </a>

        <?php if (hasRole(['admin', 'hr'])): ?>
        <div class="nav-section">บริหารบุคลากร</div>
        <a href="<?php echo BASE_URL ?>/modules/personnel/employees.php" class="nav-link <?php echo isCurrentPage('employees.php') || isCurrentPage('employee_form.php') ? 'active' : '' ?>" <?php echo isCurrentPage('employees.php') || isCurrentPage('employee_form.php') ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-people-fill" aria-hidden="true"></i> ทะเบียนพนักงาน
        </a>
        <a href="<?php echo BASE_URL ?>/modules/personnel/org_chart.php" class="nav-link <?php echo isCurrentPage('org_chart.php') ?>" <?php echo isCurrentPage('org_chart.php') ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-diagram-3-fill" aria-hidden="true"></i> โครงสร้างองค์กร
        </a>
        <a href="<?php echo BASE_URL ?>/modules/personnel/settings.php" class="nav-link <?php echo isCurrentPage('settings.php') ?>" <?php echo isCurrentPage('settings.php') ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-gear" aria-hidden="true"></i> ตั้งค่าองค์กรและอุปกรณ์
        </a>

        <div class="nav-section">เวลาทำงาน &amp; การลา</div>
        <a href="<?php echo BASE_URL ?>/modules/attendance/attendance.php" class="nav-link <?php echo isCurrentPage('attendance.php') ?>" <?php echo isCurrentPage('attendance.php') ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-clock-history" aria-hidden="true"></i> ลงเวลา / กะ / OT
        </a>
        <a href="<?php echo BASE_URL ?>/modules/attendance/leave_reset.php" class="nav-link <?php echo isCurrentPage('leave_reset.php') ?>" <?php echo isCurrentPage('leave_reset.php') ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i> รีเซ็ตและสะสมวันลา
        </a>

        <div class="nav-section">เงินเดือน &amp; ภาษี</div>
        <a href="<?php echo BASE_URL ?>/modules/payroll/payroll.php" class="nav-link <?php echo isCurrentPage('payroll.php') ?>" <?php echo isCurrentPage('payroll.php') ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-cash-coin" aria-hidden="true"></i> คำนวณเงินเดือน
        </a>
        <a href="<?php echo BASE_URL ?>/modules/payroll/reports.php" class="nav-link <?php echo isCurrentPage('reports.php') ?>" <?php echo isCurrentPage('reports.php') ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i> รายงาน ภ.ง.ด.1 / สปส.
        </a>
        <?php endif; ?>
    </nav>
</aside>
<button class="sidebar-backdrop" id="sidebarBackdrop" type="button" aria-label="ปิดเมนู" tabindex="-1" hidden></button>

<div class="main-wrapper">
    <header class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="เปิดเมนู">
                <i class="bi bi-list fs-5" aria-hidden="true"></i>
            </button>
            <h5 class="mb-0 fw-bold text-dark"><?php echo htmlspecialchars($pageTitle ?? 'Dashboard') ?></h5>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span class="badge bg-success-subtle text-success rounded-pill px-3 py-2 d-none d-sm-inline-flex align-items-center gap-2">
                <span class="pulse-dot"></span> Cloud Sync Online
            </span>
            <div class="dropdown">
                <button
                    class="btn btn-light notification-trigger position-relative"
                    type="button"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                    aria-label="<?php echo $approvalNotifications['total'] > 0
                        ? 'การแจ้งเตือน มี ' . (int)$approvalNotifications['total'] . ' รายการรออนุมัติ'
                        : 'การแจ้งเตือน ไม่มีรายการรออนุมัติ' ?>"
                >
                    <i class="bi bi-bell" aria-hidden="true"></i>
                    <?php if ($approvalNotifications['total'] > 0): ?>
                        <span class="badge rounded-pill bg-danger notification-count">
                            <?php echo $approvalNotifications['total'] > 99 ? '99+' : (int)$approvalNotifications['total'] ?>
                        </span>
                    <?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end notification-menu shadow border-0 rounded-3">
                    <li class="notification-heading">
                        <div class="fw-semibold">รายการรออนุมัติ</div>
                        <small class="text-muted"><?php echo (int)$approvalNotifications['total'] ?> รายการ</small>
                    </li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <?php if (!$approvalNotifications['items']): ?>
                        <li><div class="notification-empty text-muted small">ไม่มีคำขอลาหรือ OT ที่รออนุมัติ</div></li>
                    <?php else: ?>
                        <?php foreach ($approvalNotifications['items'] as $notification): ?>
                            <?php $isLeaveNotification = $notification['request_type'] === 'leave'; ?>
                            <li>
                                <a class="dropdown-item notification-item" href="<?php echo BASE_URL ?>/modules/ess/ess_portal.php#approvalQueue">
                                    <span class="notification-icon <?php echo $isLeaveNotification ? 'notification-icon-leave' : 'notification-icon-ot' ?>">
                                        <i class="bi <?php echo $isLeaveNotification ? 'bi-calendar2-check' : 'bi-clock-history' ?>" aria-hidden="true"></i>
                                    </span>
                                    <span class="notification-copy">
                                        <strong><?php echo htmlspecialchars($notification['first_name'] . ' ' . $notification['last_name']) ?></strong>
                                        <small class="text-muted"><?php echo $isLeaveNotification ? 'ขอลา' : 'ขอ OT' ?> · <?php echo htmlspecialchars($notification['category']) ?></small>
                                        <small class="text-muted"><?php echo htmlspecialchars($notification['detail']) ?></small>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if ($approvalNotifications['total'] > count($approvalNotifications['items'])): ?>
                        <li class="px-3 py-2 small text-muted">ยังมีอีก <?php echo (int)($approvalNotifications['total'] - count($approvalNotifications['items'])) ?> รายการ</li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li><a class="dropdown-item text-center small fw-semibold" href="<?php echo BASE_URL ?>/modules/ess/ess_portal.php#approvalQueue">ดูรายการอนุมัติทั้งหมด</a></li>
                </ul>
            </div>
            <div class="dropdown">
                <a href="#" class="d-flex align-items-center gap-2 text-decoration-none text-dark dropdown-toggle" data-bs-toggle="dropdown">
                    <?php
                    $profileImageStmt = $pdo->prepare('SELECT profile_image_url FROM employees WHERE id = ?');
                    $profileImageStmt->execute([(string)($_SESSION['employee_id'] ?? '')]);
                    $profileImageUrl = getSafeProfileImageUrl($profileImageStmt->fetchColumn());
                    ?>
                    <div class="avatar-circle">
                        <?php if ($profileImageUrl !== null): ?>
                            <img class="avatar-image" src="<?php echo htmlspecialchars($profileImageUrl) ?>" alt="">
                        <?php else: ?>
                            <?php echo htmlspecialchars(mb_substr($_SESSION['full_name'] ?? 'U', 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <div class="d-none d-md-block text-start">
                        <div class="fw-semibold" style="font-size: 0.88rem; line-height: 1.1;"><?php echo htmlspecialchars($_SESSION['full_name'] ?? '') ?></div>
                        <small class="text-muted text-uppercase" style="font-size: 0.7rem;"><?php echo htmlspecialchars($_SESSION['role'] ?? '') ?></small>
                    </div>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3">
                    <li><a class="dropdown-item" href="<?php echo BASE_URL ?>/modules/ess/profile.php"><i class="bi bi-person-circle me-2"></i>โปรไฟล์และสลิปเงินเดือน</a></li>
                    <li><a class="dropdown-item text-danger" href="<?php echo BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>ออกจากระบบ</a></li>
                </ul>
            </div>
        </div>
    </header>
    <main class="p-3 p-md-4">