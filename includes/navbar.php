<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?php echo BASE_URL ?>/index.php">HR Cloud</a>
    <div class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL ?>/index.php">แดชบอร์ด</a></li>
        <?php if (hasRole(['admin', 'hr'])): ?>
        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL ?>/modules/personnel/employees.php">พนักงาน</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL ?>/modules/personnel/org_chart.php">โครงสร้างองค์กร</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL ?>/modules/attendance/attendance.php">เวลาทำงาน & กะ/OT</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL ?>/modules/attendance/leave_reset.php">รีเซ็ตวันลา</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL ?>/modules/payroll/payroll.php">เงินเดือน & ภาษี</a></li>
        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL ?>/modules/payroll/reports.php">รายงาน</a></li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL ?>/modules/ess/ess_portal.php">ESS / MSS</a></li>
      </ul>
      <span class="navbar-text text-white me-3"><?php echo htmlspecialchars($_SESSION['full_name'] ?? '') ?> (<?php echo $_SESSION['role'] ?? '' ?>)</span>
      <a href="<?php echo BASE_URL ?>/logout.php" class="btn btn-outline-light btn-sm">ออกจากระบบ</a>
    </div>
  </div>
</nav>