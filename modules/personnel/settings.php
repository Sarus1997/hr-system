<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!hasRole(['admin', 'hr'])) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์เข้าถึงหน้านี้');
}

$error = '';
$companies = $pdo->query('SELECT company_id, company_name FROM companies WHERE status = 1 ORDER BY company_name')->fetchAll();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $postText = static fn($key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';

    try {
        $companyIdRaw = $_POST['company_id'] ?? '';
        $companyId = $companyIdRaw === '' ? null : filter_var($companyIdRaw, FILTER_VALIDATE_INT);
        if ($companyIdRaw !== '' && ($companyId === false || $companyId < 1)) {
            $error = 'กรุณาเลือกบริษัทให้ถูกต้อง';
        } elseif ($companyId !== null) {
            $companyCheck = $pdo->prepare('SELECT company_id FROM companies WHERE company_id = ? AND status = 1');
            $companyCheck->execute([$companyId]);
            if ($companyCheck->fetchColumn() === false) {
                $error = 'ไม่พบบริษัทที่เลือก';
            }
        }

        switch ($action) {
            case 'set_master_status':
                $masterTypes = [
                    'department' => 'departments',
                    'position' => 'positions',
                    'shift' => 'shifts',
                    'leave_type' => 'leave_types',
                    'holiday' => 'holidays',
                    'device' => 'attendance_devices',
                ];
                $masterType = $postText('master_type');
                $masterId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                $status = filter_var($_POST['status'] ?? null, FILTER_VALIDATE_INT);
                if (!isset($masterTypes[$masterType]) || $masterId === false || $masterId < 1 ||
                    !in_array($status, [0, 1], true)) {
                    $error = 'ข้อมูลสถานะไม่ถูกต้อง';
                } else {
                    $table = $masterTypes[$masterType];
                    $existsStmt = $pdo->prepare("SELECT id FROM {$table} WHERE id = ?");
                    $existsStmt->execute([$masterId]);
                    if (!$existsStmt->fetchColumn()) {
                        $error = 'ไม่พบรายการที่ต้องการเปลี่ยนสถานะ';
                    } else {
                        $pdo->prepare("UPDATE {$table} SET status = ? WHERE id = ?")
                            ->execute([$status, $masterId]);
                    }
                }
                break;

            case 'add_department':
                $code = strtoupper($postText('code'));
                $name = $postText('name');
                if ($error !== '' || $companyId === null || $code === '' || $name === '') {
                    $error = $error !== '' ? $error : 'กรุณาเลือกบริษัทและกรอกรหัสกับชื่อแผนก';
                } else {
                    $duplicateKey = hash('sha256', serialize([$companyId, $code, $name]));
                    $existing = $pdo->prepare('SELECT id FROM departments WHERE company_id = ? AND code = ? AND name = ? LIMIT 1');
                    $existing->execute([$companyId, $code, $name]);
                    if ($existing->fetchColumn() !== false) {
                        $error = 'แผนกนี้มีอยู่แล้วในบริษัทที่เลือก';
                    } else {
                        $pdo->prepare('INSERT INTO departments (company_id, code, name, duplicate_key) VALUES (?, ?, ?, ?)')
                            ->execute([$companyId, $code, $name, $duplicateKey]);
                    }
                }
                break;

            case 'add_position':
                $title = $postText('title');
                $level = filter_var($_POST['level'] ?? null, FILTER_VALIDATE_INT);
                $isLeaveRequired = filter_var($_POST['is_leave_required'] ?? null, FILTER_VALIDATE_INT);
                if ($error !== '' || $title === '' || $level === false || $level < 1 || $level > 10 || !in_array($isLeaveRequired, [0, 1], true)) {
                    $error = 'กรุณาระบุตำแหน่งและระดับตั้งแต่ 1 ถึง 10';
                } else {
                    $duplicateKey = hash('sha256', serialize([$companyId, $title, $level, $isLeaveRequired]));
                    $existing = $pdo->prepare('SELECT id FROM positions WHERE company_id <=> ? AND title = ? AND level = ? AND is_leave_required = ? LIMIT 1');
                    $existing->execute([$companyId, $title, $level, $isLeaveRequired]);
                    if ($existing->fetchColumn() !== false) {
                        $error = 'ตำแหน่งและเงื่อนไขนี้มีอยู่แล้ว';
                    } else {
                        $pdo->prepare('INSERT INTO positions (company_id, title, level, is_leave_required, duplicate_key) VALUES (?, ?, ?, ?, ?)')
                            ->execute([$companyId, $title, $level, $isLeaveRequired, $duplicateKey]);
                    }
                }
                break;

            case 'add_shift':
                $shiftName = $postText('shift_name');
                $startTime = $postText('start_time');
                $endTime = $postText('end_time');
                $lateGrace = filter_var($_POST['late_grace'] ?? null, FILTER_VALIDATE_INT);
                $breakMinutes = filter_var($_POST['break_minutes'] ?? null, FILTER_VALIDATE_INT);
                $validTime = static fn($time) => is_string($time) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time);
                if ($error !== '' || $shiftName === '' || !$validTime($startTime) || !$validTime($endTime) || $lateGrace === false || $lateGrace < 0 || $lateGrace > 180 || $breakMinutes === false || $breakMinutes < 0 || $breakMinutes > 240) {
                    $error = 'กรุณาตรวจสอบชื่อกะ เวลา และช่วงผ่อนผันการมาสาย';
                } else {
                    $duplicateKey = hash('sha256', serialize([$companyId, $shiftName, $startTime, $endTime, $breakMinutes, $lateGrace]));
                    $existing = $pdo->prepare('SELECT id FROM shifts WHERE company_id <=> ? AND shift_name = ? AND start_time = ? AND end_time = ? AND break_minutes = ? AND late_grace_minutes = ? LIMIT 1');
                    $existing->execute([$companyId, $shiftName, $startTime, $endTime, $breakMinutes, $lateGrace]);
                    if ($existing->fetchColumn() !== false) {
                        $error = 'กะนี้และเงื่อนไขการทำงานมีอยู่แล้ว';
                    } else {
                        $pdo->prepare('INSERT INTO shifts (company_id, shift_name, start_time, end_time, break_minutes, late_grace_minutes, duplicate_key) VALUES (?, ?, ?, ?, ?, ?, ?)')
                            ->execute([$companyId, $shiftName, $startTime, $endTime, $breakMinutes, $lateGrace, $duplicateKey]);
                    }
                }
                break;

            case 'add_holiday':
                $holidayDate = $postText('holiday_date');
                $holidayName = $postText('holiday_name');
                if ($error !== '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate) || $holidayName === '') {
                    $error = 'กรุณาระบุวันที่และชื่อวันหยุดให้ถูกต้อง';
                } else {
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $holidayDate);
                    if (!$date || $date->format('Y-m-d') !== $holidayDate) {
                        $error = 'วันที่วันหยุดไม่ถูกต้อง';
                    } else {
                        $duplicateKey = hash('sha256', serialize([$companyId, $holidayDate, $holidayName]));
                        $existing = $pdo->prepare('SELECT id FROM holidays WHERE company_id <=> ? AND holiday_date = ? AND holiday_name = ? LIMIT 1');
                        $existing->execute([$companyId, $holidayDate, $holidayName]);
                        if ($existing->fetchColumn() !== false) {
                            $error = 'วันหยุดนี้มีอยู่แล้วในขอบเขตบริษัทที่เลือก';
                        } else {
                            $pdo->prepare('INSERT INTO holidays (company_id, holiday_date, holiday_name, duplicate_key) VALUES (?, ?, ?, ?)')
                                ->execute([$companyId, $holidayDate, $holidayName, $duplicateKey]);
                        }
                    }
                }
                break;

            case 'add_device':
                $serial = $postText('device_sn');
                $deviceName = $postText('device_name');
                $deviceType = $postText('device_type');
                $location = $postText('location');
                $allowedTypes = ['face_3d', 'fingerprint', 'mobile_gps'];
                if ($error !== '' || $serial === '' || $deviceName === '' || $location === '' || !in_array($deviceType, $allowedTypes, true)) {
                    $error = 'กรุณากรอกข้อมูลอุปกรณ์ให้ครบถ้วน';
                } else {
                    $apiToken = bin2hex(random_bytes(32));
                    $pdo->prepare('INSERT INTO attendance_devices (company_id, device_sn, device_name, device_type, location, api_token) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([$companyId, $serial, $deviceName, $deviceType, $location, $apiToken]);
                    $_SESSION['new_device_token'] = ['name' => $deviceName, 'token' => $apiToken];
                }
                break;

            default:
                http_response_code(400);
                exit('ไม่รู้จักคำสั่งนี้');
        }
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            $error = 'ข้อมูลที่ระบุซ้ำกับรายการที่มีอยู่แล้ว';
        } else {
            throw $exception;
        }
    }

    if ($error !== '') {
        http_response_code(422);
    } else {
        $_SESSION['settings_notice'] = 'บันทึกข้อมูลเรียบร้อยแล้ว';
        header('Location: ' . BASE_URL . '/modules/personnel/settings.php');
        exit;
    }
}

$notice = $_SESSION['settings_notice'] ?? '';
unset($_SESSION['settings_notice']);
$newDeviceToken = $_SESSION['new_device_token'] ?? null;
unset($_SESSION['new_device_token']);
$depts = $pdo->query("SELECT d.id, d.code, d.name, d.status, c.company_name FROM departments d JOIN companies c ON c.company_id = d.company_id ORDER BY c.company_name, d.name")->fetchAll();
$positions = $pdo->query("SELECT p.id, p.title, p.level, p.is_leave_required, p.status, COALESCE(c.company_name, 'ใช้ร่วมกันทุกบริษัท') AS company_name FROM positions p LEFT JOIN companies c ON c.company_id = p.company_id ORDER BY p.level, p.title")->fetchAll();
$shifts = $pdo->query("SELECT s.id, s.shift_name, s.start_time, s.end_time, s.break_minutes, s.late_grace_minutes, s.status, COALESCE(c.company_name, 'ใช้ร่วมกันทุกบริษัท') AS company_name FROM shifts s LEFT JOIN companies c ON c.company_id = s.company_id ORDER BY s.shift_name")->fetchAll();
$leaveTypes = $pdo->query('SELECT id, name, max_days_per_year, status FROM leave_types ORDER BY id')->fetchAll();
$holidays = $pdo->query("SELECT h.id, h.holiday_date, h.holiday_name, h.status, COALESCE(c.company_name, 'วันหยุดส่วนกลาง') AS company_name FROM holidays h LEFT JOIN companies c ON c.company_id = h.company_id ORDER BY h.holiday_date DESC")->fetchAll();
$devices = $pdo->query("SELECT d.id, d.device_sn, d.device_name, d.device_type, d.location, d.api_token, d.status, COALESCE(c.company_name, 'ใช้ร่วมกันทุกบริษัท') AS company_name FROM attendance_devices d LEFT JOIN companies c ON c.company_id = d.company_id ORDER BY d.device_name")->fetchAll();
$renderStatusControl = static function (string $type, int $id, int $status): string {
    $nextStatus = $status === 1 ? 0 : 1;
    $label = $status === 1 ? 'ปิดใช้งาน' : 'เปิดใช้งาน';
    $buttonClass = $status === 1 ? 'btn-outline-secondary' : 'btn-outline-success';

    return '<form method="post" class="d-inline">'
        . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="action" value="set_master_status">'
        . '<input type="hidden" name="master_type" value="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="status" value="' . $nextStatus . '">'
        . '<button class="btn btn-sm ' . $buttonClass . '">' . $label . '</button>'
        . '</form>';
};

$pageTitle = 'ตั้งค่าโครงสร้างและอุปกรณ์';
include ROOT_PATH . '/includes/layout_top.php';
?>

<?php if ($notice !== ''): ?><div class="alert alert-success rounded-3"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger rounded-3"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($newDeviceToken): ?>
    <div class="alert alert-warning rounded-3">
        Token สำหรับ <?= htmlspecialchars($newDeviceToken['name']) ?> (แสดงครั้งเดียว กรุณาบันทึกไว้):
        <code><?= htmlspecialchars($newDeviceToken['token']) ?></code>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-md-6">
        <section class="glass-card p-4 h-100">
            <h2 class="h6 fw-bold mb-3">แผนก</h2>
            <form method="post" class="d-flex flex-wrap gap-2 mb-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="add_department">
                <select name="company_id" class="form-select form-select-sm" required aria-label="บริษัท">
                    <option value="">เลือกบริษัท</option>
                    <?php foreach ($companies as $company): ?><option value="<?= (int)$company['company_id'] ?>"><?= htmlspecialchars($company['company_name']) ?></option><?php endforeach; ?>
                </select>
                <label class="visually-hidden" for="departmentCode">รหัสแผนก</label>
                <input id="departmentCode" name="code" placeholder="รหัส เช่น MK" class="form-control form-control-sm" required maxlength="20">
                <label class="visually-hidden" for="departmentName">ชื่อแผนก</label>
                <input id="departmentName" name="name" placeholder="ชื่อแผนก" class="form-control form-control-sm" required maxlength="100">
                <button class="btn btn-primary btn-sm">เพิ่ม</button>
            </form>
            <?php foreach ($depts as $department): ?>
                <div class="d-inline-flex align-items-center gap-2 border rounded-pill px-2 py-1 me-1 mb-2">
                    <span><?= htmlspecialchars($department['code'] . ' : ' . $department['name'] . ' · ' . $department['company_name']) ?></span>
                    <span class="badge <?= (int)$department['status'] === 1 ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>"><?= (int)$department['status'] === 1 ? 'ใช้งาน' : 'ปิดใช้งาน' ?></span>
                    <?= $renderStatusControl('department', (int)$department['id'], (int)$department['status']) ?>
                </div>
            <?php endforeach; ?>
        </section>
    </div>

    <div class="col-md-6">
        <section class="glass-card p-4 h-100">
            <h2 class="h6 fw-bold mb-3">ตำแหน่งงาน</h2>
            <form method="post" class="d-flex flex-wrap gap-2 mb-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="add_position">
                <select name="company_id" class="form-select form-select-sm" aria-label="บริษัท">
                    <option value="">ใช้ร่วมกันทุกบริษัท</option>
                    <?php foreach ($companies as $company): ?><option value="<?= (int)$company['company_id'] ?>"><?= htmlspecialchars($company['company_name']) ?></option><?php endforeach; ?>
                </select>
                <label class="visually-hidden" for="positionTitle">ชื่อตำแหน่ง</label>
                <input id="positionTitle" name="title" placeholder="ชื่อตำแหน่ง" class="form-control form-control-sm" required maxlength="100">
                <label class="visually-hidden" for="positionLevel">ระดับ</label>
                <input id="positionLevel" type="number" name="level" placeholder="ระดับ" min="1" max="10" value="1" class="form-control form-control-sm" required>
                <label class="visually-hidden" for="positionLeaveRequired">ต้องยื่นลาหรือไม่</label>
                <select id="positionLeaveRequired" name="is_leave_required" class="form-select form-select-sm" aria-label="เงื่อนไขการลา"><option value="1">ยื่นลา</option><option value="0">ไม่ต้องยื่นลา</option></select>
                <button class="btn btn-primary btn-sm">เพิ่ม</button>
            </form>
            <?php foreach ($positions as $position): ?>
                <div class="d-inline-flex align-items-center gap-2 border rounded-pill px-2 py-1 me-1 mb-2">
                    <span><?= htmlspecialchars($position['title'] . ' (Lv.' . $position['level'] . ') · ' . ((int)$position['is_leave_required'] === 1 ? 'ยื่นลา' : 'ไม่ต้องยื่นลา') . ' · ' . $position['company_name']) ?></span>
                    <span class="badge <?= (int)$position['status'] === 1 ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>"><?= (int)$position['status'] === 1 ? 'ใช้งาน' : 'ปิดใช้งาน' ?></span>
                    <?= $renderStatusControl('position', (int)$position['id'], (int)$position['status']) ?>
                </div>
            <?php endforeach; ?>
        </section>
    </div>

    <div class="col-md-6">
        <section class="glass-card p-4 h-100">
            <h2 class="h6 fw-bold mb-3">กะการทำงาน</h2>
            <form method="post" class="row g-2 mb-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="add_shift">
                <div class="col-12"><select name="company_id" class="form-select form-select-sm" aria-label="บริษัท"><option value="">ใช้ร่วมกันทุกบริษัท</option><?php foreach ($companies as $company): ?><option value="<?= (int)$company['company_id'] ?>"><?= htmlspecialchars($company['company_name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><label class="visually-hidden" for="shiftName">ชื่อกะ</label><input id="shiftName" name="shift_name" placeholder="ชื่อกะ" class="form-control form-control-sm" required maxlength="50"></div>
                <div class="col-6"><label class="form-label small" for="shiftStart">เริ่มงาน</label><input id="shiftStart" type="time" name="start_time" class="form-control form-control-sm" required></div>
                <div class="col-6"><label class="form-label small" for="shiftEnd">เลิกงาน</label><input id="shiftEnd" type="time" name="end_time" class="form-control form-control-sm" required></div>
                <div class="col-6"><label class="form-label small" for="breakMinutes">เวลาพัก (นาที)</label><input id="breakMinutes" type="number" name="break_minutes" min="0" max="240" value="60" class="form-control form-control-sm" required></div>
                <div class="col-6"><label class="form-label small" for="lateGrace">ผ่อนผัน (นาที)</label><input id="lateGrace" type="number" name="late_grace" min="0" max="180" value="5" class="form-control form-control-sm" required></div>
                <div class="col-6 d-flex align-items-end"><button class="btn btn-primary btn-sm w-100">เพิ่มกะ</button></div>
            </form>
            <ul class="list-group list-group-flush small">
                <?php foreach ($shifts as $shift): ?>
                    <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span><?= htmlspecialchars($shift['shift_name']) ?> (<?= htmlspecialchars(substr($shift['start_time'], 0, 5) . ' - ' . substr($shift['end_time'], 0, 5)) ?>) · พัก <?= (int)$shift['break_minutes'] ?> นาที · ผ่อนผัน <?= (int)$shift['late_grace_minutes'] ?> นาที · <?= htmlspecialchars($shift['company_name']) ?></span>
                        <span class="badge <?= (int)$shift['status'] === 1 ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>"><?= (int)$shift['status'] === 1 ? 'ใช้งาน' : 'ปิดใช้งาน' ?></span>
                        <?= $renderStatusControl('shift', (int)$shift['id'], (int)$shift['status']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>

    <div class="col-md-6">
        <section class="glass-card p-4 h-100">
            <h2 class="h6 fw-bold mb-3">ประเภทการลา</h2>
            <ul class="list-group list-group-flush small">
                <?php foreach ($leaveTypes as $leaveType): ?>
                    <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span><?= htmlspecialchars($leaveType['name']) ?> · <?= (int)$leaveType['max_days_per_year'] ?> วัน/ปี</span>
                        <span class="badge <?= (int)$leaveType['status'] === 1 ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>"><?= (int)$leaveType['status'] === 1 ? 'ใช้งาน' : 'ปิดใช้งาน' ?></span>
                        <?= $renderStatusControl('leave_type', (int)$leaveType['id'], (int)$leaveType['status']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>

    <div class="col-md-6">
        <section class="glass-card p-4 h-100">
            <h2 class="h6 fw-bold mb-3">วันหยุดนักขัตฤกษ์</h2>
            <form method="post" class="d-flex flex-wrap gap-2 mb-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="add_holiday">
                <select name="company_id" class="form-select form-select-sm" aria-label="บริษัท"><option value="">วันหยุดส่วนกลาง</option><?php foreach ($companies as $company): ?><option value="<?= (int)$company['company_id'] ?>"><?= htmlspecialchars($company['company_name']) ?></option><?php endforeach; ?></select>
                <label class="visually-hidden" for="holidayDate">วันที่</label>
                <input id="holidayDate" type="date" name="holiday_date" class="form-control form-control-sm" required>
                <label class="visually-hidden" for="holidayName">ชื่อวันหยุด</label>
                <input id="holidayName" type="text" name="holiday_name" placeholder="ชื่อวันหยุด" class="form-control form-control-sm" required maxlength="150">
                <button class="btn btn-danger btn-sm">เพิ่ม</button>
            </form>
            <ul class="list-group list-group-flush small">
                <?php foreach ($holidays as $holiday): ?>
                    <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span><strong><?= htmlspecialchars($holiday['holiday_date']) ?></strong>: <?= htmlspecialchars($holiday['holiday_name']) ?> · <?= htmlspecialchars($holiday['company_name']) ?></span>
                        <span class="badge <?= (int)$holiday['status'] === 1 ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>"><?= (int)$holiday['status'] === 1 ? 'ใช้งาน' : 'ปิดใช้งาน' ?></span>
                        <?= $renderStatusControl('holiday', (int)$holiday['id'], (int)$holiday['status']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>

    <div class="col-12">
        <section class="glass-card p-4">
            <h2 class="h6 fw-bold mb-3">ลงทะเบียนอุปกรณ์ลงเวลา</h2>
            <form method="post" class="row g-2 mb-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="add_device">
                <div class="col-md-3"><label class="visually-hidden" for="deviceCompany">บริษัท</label><select id="deviceCompany" name="company_id" class="form-select form-select-sm"><option value="">ใช้ร่วมกันทุกบริษัท</option><?php foreach ($companies as $company): ?><option value="<?= (int)$company['company_id'] ?>"><?= htmlspecialchars($company['company_name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="visually-hidden" for="deviceSerial">Serial No.</label><input id="deviceSerial" name="device_sn" placeholder="Serial No." class="form-control form-control-sm" required maxlength="100"></div>
                <div class="col-md-3"><label class="visually-hidden" for="deviceName">ชื่อเครื่อง</label><input id="deviceName" name="device_name" placeholder="ชื่อเครื่อง" class="form-control form-control-sm" required maxlength="100"></div>
                <div class="col-md-2"><label class="visually-hidden" for="deviceType">ประเภทเครื่อง</label><select id="deviceType" name="device_type" class="form-select form-select-sm"><option value="face_3d">ใบหน้า 3 มิติ</option><option value="fingerprint">ลายนิ้วมือ</option><option value="mobile_gps">มือถือ / GPS</option></select></div>
                <div class="col-md-2"><label class="visually-hidden" for="deviceLocation">จุดติดตั้ง</label><input id="deviceLocation" name="location" placeholder="จุดติดตั้ง" class="form-control form-control-sm" required maxlength="150"></div>
                <div class="col-md-2"><button class="btn btn-success btn-sm w-100">เพิ่มเครื่อง</button></div>
            </form>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Serial No.</th><th>ชื่อเครื่อง</th><th>บริษัท</th><th>ประเภท</th><th>สถานที่</th><th>Token</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
                    <tbody>
                    <?php foreach ($devices as $device): ?>
                        <tr>
                            <td><?= htmlspecialchars($device['device_sn']) ?></td>
                            <td><?= htmlspecialchars($device['device_name']) ?></td>
                            <td><?= htmlspecialchars($device['company_name']) ?></td>
                            <td><?= htmlspecialchars($device['device_type']) ?></td>
                            <td><?= htmlspecialchars($device['location'] ?? '') ?></td>
                            <td><code><?= htmlspecialchars(substr($device['api_token'], 0, 4) . '••••••••') ?></code></td>
                            <td><span class="badge <?= (int)$device['status'] === 1 ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>"><?= (int)$device['status'] === 1 ? 'ใช้งาน' : 'ปิดใช้งาน' ?></span></td>
                            <td><?= $renderStatusControl('device', (int)$device['id'], (int)$device['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<?php include ROOT_PATH . '/includes/layout_bottom.php'; ?>
