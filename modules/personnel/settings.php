<?php
    require_once __DIR__ . '/../../includes/auth.php';
    requireLogin();

    if (! hasRole(['admin', 'hr'])) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์เข้าถึงหน้านี้');
    }

    $error     = '';
    $companies = $pdo->query('SELECT company_id, company_name FROM companies WHERE status = 1 ORDER BY company_name')->fetchAll();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action   = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $postText = static fn($key)       => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';

    try {
        $companyIdRaw = $_POST['company_id'] ?? '';
        $companyId    = $companyIdRaw === ''  ?null : filter_var($companyIdRaw, FILTER_VALIDATE_INT);
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
                    'position'   => 'positions',
                    'shift'      => 'shifts',
                    'leave_type' => 'leave_types',
                    'holiday'    => 'holidays',
                    'device'     => 'attendance_devices',
                ];
                $masterType = $postText('master_type');
                $masterId   = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                $status     = filter_var($_POST['status'] ?? null, FILTER_VALIDATE_INT);
                if (! isset($masterTypes[$masterType]) || $masterId === false || $masterId < 1 ||
                    ! in_array($status, [0, 1], true)) {
                    $error = 'ข้อมูลสถานะไม่ถูกต้อง';
                } else {
                    $table      = $masterTypes[$masterType];
                    $existsStmt = $pdo->prepare("SELECT id FROM {$table} WHERE id = ?");
                    $existsStmt->execute([$masterId]);
                    if (! $existsStmt->fetchColumn()) {
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
                    $existing     = $pdo->prepare('SELECT id FROM departments WHERE company_id = ? AND code = ? AND name = ? LIMIT 1');
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
                $title           = $postText('title');
                $level           = filter_var($_POST['level'] ?? null, FILTER_VALIDATE_INT);
                $isLeaveRequired = filter_var($_POST['is_leave_required'] ?? null, FILTER_VALIDATE_INT);
                if ($error !== '' || $title === '' || $level === false || $level < 1 || $level > 10 || ! in_array($isLeaveRequired, [0, 1], true)) {
                    $error = 'กรุณาระบุตำแหน่งและระดับตั้งแต่ 1 ถึง 10';
                } else {
                    $duplicateKey = hash('sha256', serialize([$companyId, $title, $level, $isLeaveRequired]));
                    $existing     = $pdo->prepare('SELECT id FROM positions WHERE company_id <=> ? AND title = ? AND level = ? AND is_leave_required = ? LIMIT 1');
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
                $shiftName    = $postText('shift_name');
                $startTime    = $postText('start_time');
                $endTime      = $postText('end_time');
                $lateGrace    = filter_var($_POST['late_grace'] ?? null, FILTER_VALIDATE_INT);
                $breakMinutes = filter_var($_POST['break_minutes'] ?? null, FILTER_VALIDATE_INT);
                $validTime    = static fn($time) => is_string($time) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time);
                if ($error !== '' || $shiftName === '' || ! $validTime($startTime) || ! $validTime($endTime) || $lateGrace === false || $lateGrace < 0 || $lateGrace > 180 || $breakMinutes === false || $breakMinutes < 0 || $breakMinutes > 240) {
                    $error = 'กรุณาตรวจสอบชื่อกะ เวลา และช่วงผ่อนผันการมาสาย';
                } else {
                    $duplicateKey = hash('sha256', serialize([$companyId, $shiftName, $startTime, $endTime, $breakMinutes, $lateGrace]));
                    $existing     = $pdo->prepare('SELECT id FROM shifts WHERE company_id <=> ? AND shift_name = ? AND start_time = ? AND end_time = ? AND break_minutes = ? AND late_grace_minutes = ? LIMIT 1');
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
                if ($error !== '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate) || $holidayName === '') {
                    $error = 'กรุณาระบุวันที่และชื่อวันหยุดให้ถูกต้อง';
                } else {
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $holidayDate);
                    if (! $date || $date->format('Y-m-d') !== $holidayDate) {
                        $error = 'วันที่วันหยุดไม่ถูกต้อง';
                    } else {
                        $duplicateKey = hash('sha256', serialize([$companyId, $holidayDate, $holidayName]));
                        $existing     = $pdo->prepare('SELECT id FROM holidays WHERE company_id <=> ? AND holiday_date = ? AND holiday_name = ? LIMIT 1');
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
                $serial       = $postText('device_sn');
                $deviceName   = $postText('device_name');
                $deviceType   = $postText('device_type');
                $location     = $postText('location');
                $allowedTypes = ['face_3d', 'fingerprint', 'mobile_gps'];
                if ($error !== '' || $serial === '' || $deviceName === '' || $location === '' || ! in_array($deviceType, $allowedTypes, true)) {
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
    $depts      = $pdo->query("SELECT d.id, d.code, d.name, d.status, c.company_name FROM departments d JOIN companies c ON c.company_id = d.company_id ORDER BY c.company_name, d.name")->fetchAll();
    $positions  = $pdo->query("SELECT p.id, p.title, p.level, p.is_leave_required, p.status, COALESCE(c.company_name, 'ใช้ร่วมกันทุกบริษัท') AS company_name FROM positions p LEFT JOIN companies c ON c.company_id = p.company_id ORDER BY p.level, p.title")->fetchAll();
    $shifts     = $pdo->query("SELECT s.id, s.shift_name, s.start_time, s.end_time, s.break_minutes, s.late_grace_minutes, s.status, COALESCE(c.company_name, 'ใช้ร่วมกันทุกบริษัท') AS company_name FROM shifts s LEFT JOIN companies c ON c.company_id = s.company_id ORDER BY s.shift_name")->fetchAll();
    $leaveTypes = $pdo->query('SELECT id, name, max_days_per_year, status FROM leave_types ORDER BY id')->fetchAll();
    $holidays   = $pdo->query("SELECT h.id, h.holiday_date, h.holiday_name, h.status, COALESCE(c.company_name, 'วันหยุดส่วนกลาง') AS company_name FROM holidays h LEFT JOIN companies c ON c.company_id = h.company_id ORDER BY h.holiday_date DESC")->fetchAll();
    $devices    = $pdo->query("SELECT d.id, d.device_sn, d.device_name, d.device_type, d.location, d.api_token, d.status, COALESCE(c.company_name, 'ใช้ร่วมกันทุกบริษัท') AS company_name FROM attendance_devices d LEFT JOIN companies c ON c.company_id = d.company_id ORDER BY d.device_name")->fetchAll();

    /* ---------- ตัวช่วยแสดงผล (ไม่กระทบตรรกะด้านบน) ---------- */
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

    // สวิตช์เปิด/ปิดการใช้งาน: ข้อความบนสวิตช์บอกสถานะปัจจุบัน กดแล้วสลับสถานะ
    $renderStatusControl = static function (string $type, int $id, int $status, string $name = '') use ($h): string {
    $nextStatus = $status === 1 ? 0 : 1;
    $aria       = ($status === 1 ? 'ปิดใช้งาน ' : 'เปิดใช้งาน ') . $name;

    return '<form method="post" class="set-switch-form">'
        . '<input type="hidden" name="csrf_token" value="' . $h(csrfToken()) . '">'
        . '<input type="hidden" name="action" value="set_master_status">'
        . '<input type="hidden" name="master_type" value="' . $h($type) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="status" value="' . $nextStatus . '">'
        . '<button type="submit" class="set-switch" role="switch" aria-checked="' . ($status === 1 ? 'true' : 'false') . '" aria-label="' . $h(trim($aria)) . '">'
        . '<span class="set-switch-track" aria-hidden="true"></span>'
        . '<span class="set-switch-text">' . ($status === 1 ? 'ใช้งาน' : 'ปิดอยู่') . '</span>'
        . '</button>'
        . '</form>';
    };

    $scopeChip = static function (string $companyName) use ($h): string {
    $shared = in_array($companyName, ['ใช้ร่วมกันทุกบริษัท', 'วันหยุดส่วนกลาง'], true);
    return '<span class="set-chip ' . ($shared ? 'set-chip-shared' : 'set-chip-company') . '">'
        . '<i class="bi ' . ($shared ? 'bi-globe2' : 'bi-building') . '" aria-hidden="true"></i>' . $h($companyName) . '</span>';
    };

    $thaiMonths  = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $deviceTypes = [
    'face_3d'     => ['ใบหน้า 3 มิติ', 'bi-person-bounding-box'],
    'fingerprint' => ['ลายนิ้วมือ', 'bi-fingerprint'],
    'mobile_gps'  => ['มือถือ / GPS', 'bi-geo-alt'],
    ];
    $companyOptions = static function (string $emptyLabel) use ($companies, $h): string {
    $html = '<option value="">' . $h($emptyLabel) . '</option>';
    foreach ($companies as $company) {
        $html .= '<option value="' . (int) $company['company_id'] . '">' . $h($company['company_name']) . '</option>';
    }
    return $html;
    };
    $activeCount = static fn(array $rows): int => count(array_filter($rows, static fn($r) => (int) $r['status'] === 1));

    $pageTitle = 'ตั้งค่าโครงสร้างและอุปกรณ์';
    include ROOT_PATH . '/includes/layout_top.php';
?>

<style>
/* =========================================================
   Settings page (ใช้ design tokens จากธีมหลัก รองรับ dark อัตโนมัติ)
   ========================================================= */
.set-page { --ok: #059669; --ok-soft: rgba(5, 150, 105, .12); --off-soft: rgba(100, 116, 139, .14); }
[data-bs-theme="dark"] .set-page { --ok: #34d399; --ok-soft: rgba(52, 211, 153, .14); --off-soft: rgba(140, 150, 176, .16); }

/* Header */
.set-hero { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 1rem 2rem; margin-bottom: 1.5rem; }
.set-hero h1 { font-size: 1.6rem; margin: 0 0 .25rem; }
.set-hero p { margin: 0; color: var(--ink-3); max-width: 46ch; }
.set-jump { display: flex; flex-wrap: wrap; gap: .5rem; }
.set-jump a { display: inline-flex; align-items: center; gap: .5rem; padding: .42rem .85rem; border-radius: 999px; border: 1px solid var(--line); background: var(--surface); color: var(--ink-2); font-size: .85rem; font-weight: 500; text-decoration: none; box-shadow: var(--shadow-xs); transition: border-color .2s ease, color .2s ease, box-shadow .2s ease; }
.set-jump a:hover { color: var(--primary); border-color: var(--line-strong); box-shadow: var(--shadow-sm); }
.set-jump a:focus-visible { outline: 2px solid var(--primary-light); outline-offset: 2px; }
.set-jump b { font-weight: 600; color: var(--ink); font-variant-numeric: tabular-nums; }

/* Alerts */
.set-alert { display: flex; gap: .75rem; align-items: flex-start; padding: .9rem 1.1rem; margin-bottom: 1rem; border-radius: var(--radius-md); border: 1px solid var(--line); background: var(--surface); box-shadow: var(--shadow-xs); }
.set-alert > .bi { font-size: 1.2rem; line-height: 1.4; }
.set-alert-ok { border-color: rgba(5, 150, 105, .35); background: var(--ok-soft); }
.set-alert-ok > .bi { color: var(--ok); }
.set-alert-error { border-color: rgba(220, 38, 38, .35); background: rgba(220, 38, 38, .08); }
.set-alert-error > .bi { color: #dc2626; }
.set-alert-token { border-color: rgba(201, 164, 92, .55); background: rgba(201, 164, 92, .12); }
.set-alert-token > .bi { color: var(--gold); }
.set-alert-body { flex: 1; min-width: 0; }
.set-token-row { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin-top: .5rem; }
.set-token-row code { flex: 1 1 18rem; min-width: 0; padding: .45rem .7rem; border-radius: var(--radius-sm); background: var(--surface); border: 1px solid var(--line); color: var(--ink); word-break: break-all; font-size: .82rem; }

/* Card */
.set-card { height: 100%; padding: 1.5rem; scroll-margin-top: 5.5rem; }
.set-head { display: flex; align-items: center; gap: .9rem; margin-bottom: 1.1rem; }
.set-icon { width: 42px; height: 42px; flex: 0 0 auto; display: grid; place-items: center; border-radius: var(--radius-sm); background: var(--primary-soft); color: var(--primary); font-size: 1.15rem; }
.set-head h2 { font-size: 1.05rem; margin: 0; }
.set-head p { margin: 0; font-size: .82rem; color: var(--ink-3); }
.set-count { margin-left: auto; font-size: .78rem; font-weight: 600; color: var(--ink-3); background: var(--surface-muted); border: 1px solid var(--line); border-radius: 999px; padding: .2rem .65rem; white-space: nowrap; font-variant-numeric: tabular-nums; }

/* Add form */
.set-form { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: .75rem; padding: 1rem; margin-bottom: 1.1rem; border-radius: var(--radius-md); background: var(--surface-muted); border: 1px dashed var(--line-strong); }
.set-field { display: flex; flex-direction: column; gap: .25rem; min-width: 0; grid-column: span 3; }
.set-field.w-2 { grid-column: span 2; }
.set-field.w-4 { grid-column: span 4; }
.set-field.w-6 { grid-column: span 6; }
.set-field label { font-size: .78rem; font-weight: 500; color: var(--ink-3); }
.set-form .form-control, .set-form .form-select { border-radius: var(--radius-sm); border-color: var(--line-strong); background-color: var(--surface); color: var(--ink); }
.set-form-actions { grid-column: 1 / -1; display: flex; justify-content: flex-end; }
@media (max-width: 575.98px) { .set-field, .set-field.w-2, .set-field.w-4 { grid-column: span 6; } }

/* List */
.set-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .5rem; max-height: 26rem; overflow-y: auto; padding-right: .25rem; }
.set-row { display: flex; align-items: center; gap: .9rem; padding: .75rem .9rem; border-radius: var(--radius-md); border: 1px solid var(--line); background: var(--surface); transition: border-color .2s ease, box-shadow .2s ease, opacity .2s ease; }
.set-row:hover { border-color: var(--line-strong); box-shadow: var(--shadow-xs); }
.set-row.is-off .set-main { opacity: .55; }
.set-main { flex: 1; min-width: 0; }
.set-title { display: block; font-weight: 600; color: var(--ink); line-height: 1.35; overflow-wrap: anywhere; }
.set-sub { display: block; margin-top: .1rem; font-size: .82rem; color: var(--ink-3); }
.set-meta { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .45rem; }
.set-chip { display: inline-flex; align-items: center; gap: .35rem; padding: .15rem .6rem; border-radius: 999px; font-size: .75rem; font-weight: 500; background: var(--surface-muted); border: 1px solid var(--line); color: var(--ink-2); }
.set-chip .bi { font-size: .8rem; opacity: .8; }
.set-chip-company { background: var(--primary-soft); border-color: transparent; color: var(--primary); }
.set-chip-shared { color: var(--ink-3); }
.set-chip-gold { background: rgba(201, 164, 92, .16); border-color: transparent; color: #8a6a24; }
[data-bs-theme="dark"] .set-chip-gold { color: #e3c37f; }
.set-empty { padding: 1.5rem 1rem; text-align: center; color: var(--ink-3); border: 1px dashed var(--line-strong); border-radius: var(--radius-md); }
.set-empty .bi { display: block; font-size: 1.6rem; margin-bottom: .35rem; opacity: .6; }

/* Code tile (แผนก) / Date tile (วันหยุด) / Time tile (กะ) */
.set-tile { width: 46px; height: 46px; flex: 0 0 auto; display: grid; place-items: center; text-align: center; border-radius: var(--radius-sm); background: var(--primary-soft); color: var(--primary); font-weight: 700; font-size: .82rem; letter-spacing: .01em; line-height: 1.1; }
.set-tile.date { background: var(--surface-muted); border: 1px solid var(--line); color: var(--ink); }
.set-tile.date strong { display: block; font-size: 1.1rem; line-height: 1; }
.set-tile.date small { display: block; font-size: .65rem; font-weight: 500; color: var(--ink-3); margin-top: .15rem; }
.set-tile.level small { display: block; font-size: .6rem; font-weight: 500; opacity: .75; }
.set-tile.level strong { font-size: 1.05rem; }

/* Switch */
.set-switch-form { margin: 0; }
.set-switch { display: inline-flex; align-items: center; gap: .55rem; padding: .25rem .65rem .25rem .3rem; border-radius: 999px; border: 1px solid var(--line); background: var(--surface); color: var(--ink-3); font-size: .78rem; font-weight: 600; cursor: pointer; white-space: nowrap; transition: background-color .25s ease, border-color .25s ease, color .25s ease; }
.set-switch:focus-visible { outline: 2px solid var(--primary-light); outline-offset: 2px; }
.set-switch-track { position: relative; width: 32px; height: 18px; border-radius: 999px; background: var(--line-strong); transition: background-color .3s var(--ease-fluid); }
.set-switch-track::after { content: ''; position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; border-radius: 50%; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, .3); transition: transform .3s var(--ease-fluid); }
.set-switch[aria-checked="true"] { color: var(--ok); background: var(--ok-soft); border-color: transparent; }
.set-switch[aria-checked="true"] .set-switch-track { background: var(--ok); }
.set-switch[aria-checked="true"] .set-switch-track::after { transform: translateX(14px); }
.set-switch:hover { border-color: var(--line-strong); }

/* Device table */
.set-table-wrap { border: 1px solid var(--line); border-radius: var(--radius-md); overflow: hidden; }
.set-table { margin: 0; --bs-table-bg: transparent; --bs-table-color: var(--ink-2); }
.set-table thead th { background: var(--surface-muted); color: var(--ink-3); font-size: .78rem; font-weight: 600; border-bottom: 1px solid var(--line); padding: .7rem 1rem; white-space: nowrap; }
.set-table td { padding: .8rem 1rem; border-color: var(--line); vertical-align: middle; }
.set-table tbody tr:hover td { background: var(--surface-muted); }
.set-table tr.is-off td:not(:last-child) { opacity: .55; }
.set-device-name { font-weight: 600; color: var(--ink); }
.set-sn { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; color: var(--ink-3); }
.set-token { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; padding: .15rem .5rem; border-radius: 6px; background: var(--surface-muted); border: 1px solid var(--line); color: var(--ink-2); }
</style>

<div class="set-page">

    <header class="set-hero">
        <div>
            <h1>ตั้งค่าโครงสร้างและอุปกรณ์</h1>
            <p>จัดการแผนก ตำแหน่ง กะการทำงาน วันหยุด และเครื่องลงเวลาของทุกบริษัทได้จากหน้านี้</p>
        </div>
        <nav class="set-jump" aria-label="ไปยังหัวข้อ">
            <a href="#sec-dept"><i class="bi bi-diagram-3" aria-hidden="true"></i>แผนก <b><?php echo count($depts) ?></b></a>
            <a href="#sec-position"><i class="bi bi-person-badge" aria-hidden="true"></i>ตำแหน่ง <b><?php echo count($positions) ?></b></a>
            <a href="#sec-shift"><i class="bi bi-clock-history" aria-hidden="true"></i>กะ <b><?php echo count($shifts) ?></b></a>
            <a href="#sec-leave"><i class="bi bi-calendar2-check" aria-hidden="true"></i>การลา <b><?php echo count($leaveTypes) ?></b></a>
            <a href="#sec-holiday"><i class="bi bi-calendar-heart" aria-hidden="true"></i>วันหยุด <b><?php echo count($holidays) ?></b></a>
            <a href="#sec-device"><i class="bi bi-fingerprint" aria-hidden="true"></i>อุปกรณ์ <b><?php echo count($devices) ?></b></a>
        </nav>
    </header>

    <?php if ($notice !== ''): ?>
        <div class="set-alert set-alert-ok" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><div class="set-alert-body"><?php echo $h($notice) ?></div></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="set-alert set-alert-error" role="alert"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><div class="set-alert-body"><?php echo $h($error) ?></div></div>
    <?php endif; ?>
    <?php if ($newDeviceToken): ?>
        <div class="set-alert set-alert-token" role="status">
            <i class="bi bi-key-fill" aria-hidden="true"></i>
            <div class="set-alert-body">
                <strong>Token ของ <?php echo $h($newDeviceToken['name']) ?></strong>
                <div class="small text-secondary">แสดงครั้งเดียวเท่านั้น กรุณาคัดลอกและเก็บไว้ในที่ปลอดภัย</div>
                <div class="set-token-row">
                    <code id="newDeviceToken"><?php echo $h($newDeviceToken['token']) ?></code>
                    <button type="button" class="btn btn-sm btn-light btn-modern" id="copyTokenBtn"><i class="bi bi-clipboard" aria-hidden="true"></i> คัดลอก</button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- แผนก -->
        <div class="col-lg-6">
            <section class="glass-card set-card" id="sec-dept" aria-labelledby="h-dept">
                <div class="set-head">
                    <span class="set-icon"><i class="bi bi-diagram-3" aria-hidden="true"></i></span>
                    <div><h2 id="h-dept">แผนก</h2><p>แยกตามบริษัท</p></div>
                    <span class="set-count">ใช้งาน <?php echo $activeCount($depts) ?>/<?php echo count($depts) ?></span>
                </div>
                <form method="post" class="set-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="add_department">
                    <div class="set-field w-6"><label for="departmentCompany">บริษัท</label>
                        <select id="departmentCompany" name="company_id" class="form-select form-select-sm" required><?php echo $companyOptions('เลือกบริษัท') ?></select></div>
                    <div class="set-field w-2"><label for="departmentCode">รหัสแผนก</label>
                        <input id="departmentCode" name="code" placeholder="เช่น MK" class="form-control form-control-sm" required maxlength="20"></div>
                    <div class="set-field w-4"><label for="departmentName">ชื่อแผนก</label>
                        <input id="departmentName" name="name" placeholder="เช่น การตลาด" class="form-control form-control-sm" required maxlength="100"></div>
                    <div class="set-form-actions"><button class="btn btn-primary btn-sm btn-modern"><i class="bi bi-plus-lg" aria-hidden="true"></i> เพิ่มแผนก</button></div>
                </form>
                <?php if (! $depts): ?>
                    <div class="set-empty"><i class="bi bi-diagram-3" aria-hidden="true"></i>ยังไม่มีแผนก เริ่มเพิ่มแผนกแรกได้จากแบบฟอร์มด้านบน</div>
                <?php else: ?>
                    <ul class="set-list">
                        <?php foreach ($depts as $department): $on = (int) $department['status'] === 1; ?>
                            <li class="set-row <?php echo $on ? '' : 'is-off' ?>">
                                <span class="set-tile"><?php echo $h($department['code']) ?></span>
                                <div class="set-main">
                                    <span class="set-title"><?php echo $h($department['name']) ?></span>
                                    <div class="set-meta"><?php echo $scopeChip($department['company_name']) ?></div>
                                </div>
                                <?php echo $renderStatusControl('department', (int)$department['id'], (int)$department['status'], $department['name']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>

        <!-- ตำแหน่งงาน -->
        <div class="col-lg-6">
            <section class="glass-card set-card" id="sec-position" aria-labelledby="h-position">
                <div class="set-head">
                    <span class="set-icon"><i class="bi bi-person-badge" aria-hidden="true"></i></span>
                    <div><h2 id="h-position">ตำแหน่งงาน</h2><p>ระดับ 1 ถึง 10 และเงื่อนไขการยื่นลา</p></div>
                    <span class="set-count">ใช้งาน <?php echo $activeCount($positions) ?>/<?php echo count($positions) ?></span>
                </div>
                <form method="post" class="set-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="add_position">
                    <div class="set-field w-6"><label for="positionCompany">บริษัท</label>
                        <select id="positionCompany" name="company_id" class="form-select form-select-sm"><?php echo $companyOptions('ใช้ร่วมกันทุกบริษัท') ?></select></div>
                    <div class="set-field w-4"><label for="positionTitle">ชื่อตำแหน่ง</label>
                        <input id="positionTitle" name="title" placeholder="เช่น ผู้จัดการฝ่ายขาย" class="form-control form-control-sm" required maxlength="100"></div>
                    <div class="set-field w-2"><label for="positionLevel">ระดับ</label>
                        <input id="positionLevel" type="number" name="level" min="1" max="10" value="1" class="form-control form-control-sm" required></div>
                    <div class="set-field w-6"><label for="positionLeaveRequired">การยื่นลา</label>
                        <select id="positionLeaveRequired" name="is_leave_required" class="form-select form-select-sm"><option value="1">ต้องยื่นลา</option><option value="0">ไม่ต้องยื่นลา</option></select></div>
                    <div class="set-form-actions"><button class="btn btn-primary btn-sm btn-modern"><i class="bi bi-plus-lg" aria-hidden="true"></i> เพิ่มตำแหน่ง</button></div>
                </form>
                <?php if (! $positions): ?>
                    <div class="set-empty"><i class="bi bi-person-badge" aria-hidden="true"></i>ยังไม่มีตำแหน่งงาน</div>
                <?php else: ?>
                    <ul class="set-list">
                        <?php foreach ($positions as $position): $on = (int) $position['status'] === 1; ?>
                            <li class="set-row <?php echo $on ? '' : 'is-off' ?>">
                                <span class="set-tile level"><span><small>Lv.</small><strong><?php echo (int)$position['level'] ?></strong></span></span>
                                <div class="set-main">
                                    <span class="set-title"><?php echo $h($position['title']) ?></span>
                                    <div class="set-meta">
                                        <span class="set-chip <?php echo (int)$position['is_leave_required'] === 1 ? '' : 'set-chip-gold' ?>"><i class="bi <?php echo (int)$position['is_leave_required'] === 1 ? 'bi-file-earmark-text' : 'bi-star' ?>" aria-hidden="true"></i><?php echo (int)$position['is_leave_required'] === 1 ? 'ต้องยื่นลา' : 'ไม่ต้องยื่นลา' ?></span>
                                        <?php echo $scopeChip($position['company_name']) ?>
                                    </div>
                                </div>
                                <?php echo $renderStatusControl('position', (int)$position['id'], (int)$position['status'], $position['title']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>

        <!-- กะการทำงาน -->
        <div class="col-lg-6">
            <section class="glass-card set-card" id="sec-shift" aria-labelledby="h-shift">
                <div class="set-head">
                    <span class="set-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
                    <div><h2 id="h-shift">กะการทำงาน</h2><p>เวลาเข้าออกงาน เวลาพัก และช่วงผ่อนผัน</p></div>
                    <span class="set-count">ใช้งาน <?php echo $activeCount($shifts) ?>/<?php echo count($shifts) ?></span>
                </div>
                <form method="post" class="set-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="add_shift">
                    <div class="set-field w-6"><label for="shiftCompany">บริษัท</label>
                        <select id="shiftCompany" name="company_id" class="form-select form-select-sm"><?php echo $companyOptions('ใช้ร่วมกันทุกบริษัท') ?></select></div>
                    <div class="set-field w-6"><label for="shiftName">ชื่อกะ</label>
                        <input id="shiftName" name="shift_name" placeholder="เช่น กะเช้า" class="form-control form-control-sm" required maxlength="50"></div>
                    <div class="set-field"><label for="shiftStart">เริ่มงาน</label>
                        <input id="shiftStart" type="time" name="start_time" class="form-control form-control-sm" required></div>
                    <div class="set-field"><label for="shiftEnd">เลิกงาน</label>
                        <input id="shiftEnd" type="time" name="end_time" class="form-control form-control-sm" required></div>
                    <div class="set-field"><label for="breakMinutes">เวลาพัก (นาที)</label>
                        <input id="breakMinutes" type="number" name="break_minutes" min="0" max="240" value="60" class="form-control form-control-sm" required></div>
                    <div class="set-field"><label for="lateGrace">ผ่อนผันมาสาย (นาที)</label>
                        <input id="lateGrace" type="number" name="late_grace" min="0" max="180" value="5" class="form-control form-control-sm" required></div>
                    <div class="set-form-actions"><button class="btn btn-primary btn-sm btn-modern"><i class="bi bi-plus-lg" aria-hidden="true"></i> เพิ่มกะ</button></div>
                </form>
                <?php if (! $shifts): ?>
                    <div class="set-empty"><i class="bi bi-clock-history" aria-hidden="true"></i>ยังไม่มีกะการทำงาน</div>
                <?php else: ?>
                    <ul class="set-list">
                        <?php foreach ($shifts as $shift): $on = (int) $shift['status'] === 1; ?>
                            <li class="set-row <?php echo $on ? '' : 'is-off' ?>">
                                <span class="set-tile"><i class="bi bi-clock" aria-hidden="true"></i></span>
                                <div class="set-main">
                                    <span class="set-title"><?php echo $h($shift['shift_name']) ?></span>
                                    <span class="set-sub"><?php echo $h(substr($shift['start_time'], 0, 5)) ?> - <?php echo $h(substr($shift['end_time'], 0, 5)) ?> น.</span>
                                    <div class="set-meta">
                                        <span class="set-chip"><i class="bi bi-cup-hot" aria-hidden="true"></i>พัก <?php echo (int)$shift['break_minutes'] ?> นาที</span>
                                        <span class="set-chip"><i class="bi bi-hourglass-split" aria-hidden="true"></i>ผ่อนผัน <?php echo (int)$shift['late_grace_minutes'] ?> นาที</span>
                                        <?php echo $scopeChip($shift['company_name']) ?>
                                    </div>
                                </div>
                                <?php echo $renderStatusControl('shift', (int)$shift['id'], (int)$shift['status'], $shift['shift_name']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>

        <!-- ประเภทการลา + วันหยุด (ซ้อนในคอลัมน์เดียวกันให้สมดุลความสูง) -->
        <div class="col-lg-6 d-flex flex-column gap-4">
            <section class="glass-card set-card" id="sec-leave" aria-labelledby="h-leave" style="height:auto">
                <div class="set-head">
                    <span class="set-icon"><i class="bi bi-calendar2-check" aria-hidden="true"></i></span>
                    <div><h2 id="h-leave">ประเภทการลา</h2><p>สิทธิ์วันลาสูงสุดต่อปี</p></div>
                    <span class="set-count">ใช้งาน <?php echo $activeCount($leaveTypes) ?>/<?php echo count($leaveTypes) ?></span>
                </div>
                <?php if (! $leaveTypes): ?>
                    <div class="set-empty"><i class="bi bi-calendar2-check" aria-hidden="true"></i>ยังไม่มีประเภทการลา</div>
                <?php else: ?>
                    <ul class="set-list">
                        <?php foreach ($leaveTypes as $leaveType): $on = (int) $leaveType['status'] === 1; ?>
                            <li class="set-row <?php echo $on ? '' : 'is-off' ?>">
                                <div class="set-main">
                                    <span class="set-title"><?php echo $h($leaveType['name']) ?></span>
                                    <span class="set-sub">สูงสุด <?php echo (int)$leaveType['max_days_per_year'] ?> วันต่อปี</span>
                                </div>
                                <?php echo $renderStatusControl('leave_type', (int)$leaveType['id'], (int)$leaveType['status'], $leaveType['name']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="glass-card set-card" id="sec-holiday" aria-labelledby="h-holiday" style="height:auto">
                <div class="set-head">
                    <span class="set-icon" style="background:rgba(220,38,38,.1);color:#dc2626"><i class="bi bi-calendar-heart" aria-hidden="true"></i></span>
                    <div><h2 id="h-holiday">วันหยุดนักขัตฤกษ์</h2><p>วันหยุดส่วนกลางหรือเฉพาะบริษัท</p></div>
                    <span class="set-count">ใช้งาน <?php echo $activeCount($holidays) ?>/<?php echo count($holidays) ?></span>
                </div>
                <form method="post" class="set-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="add_holiday">
                    <div class="set-field w-6"><label for="holidayCompany">บริษัท</label>
                        <select id="holidayCompany" name="company_id" class="form-select form-select-sm"><?php echo $companyOptions('วันหยุดส่วนกลาง') ?></select></div>
                    <div class="set-field w-2"><label for="holidayDate">วันที่</label>
                        <input id="holidayDate" type="date" name="holiday_date" class="form-control form-control-sm" required></div>
                    <div class="set-field w-4"><label for="holidayName">ชื่อวันหยุด</label>
                        <input id="holidayName" type="text" name="holiday_name" placeholder="เช่น วันสงกรานต์" class="form-control form-control-sm" required maxlength="150"></div>
                    <div class="set-form-actions"><button class="btn btn-danger btn-sm btn-modern"><i class="bi bi-plus-lg" aria-hidden="true"></i> เพิ่มวันหยุด</button></div>
                </form>
                <?php if (! $holidays): ?>
                    <div class="set-empty"><i class="bi bi-calendar-heart" aria-hidden="true"></i>ยังไม่มีวันหยุด</div>
                <?php else: ?>
                    <ul class="set-list">
                        <?php foreach ($holidays as $holiday):
                                $on = (int) $holiday['status'] === 1;
                                $hd = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $holiday['holiday_date']);
                        ?>
                            <li class="set-row <?php echo $on ? '' : 'is-off' ?>">
                                <span class="set-tile date">
                                    <?php if ($hd): ?>
                                        <span><strong><?php echo (int)$hd->format('j') ?></strong><small><?php echo $h($thaiMonths[(int)$hd->format('n')] . ' ' . substr((string)((int)$hd->format('Y') + 543), -2)) ?></small></span>
                                    <?php else: ?>
                                        <i class="bi bi-calendar" aria-hidden="true"></i>
                                    <?php endif; ?>
                                </span>
                                <div class="set-main">
                                    <span class="set-title"><?php echo $h($holiday['holiday_name']) ?></span>
                                    <div class="set-meta">
                                        <span class="set-chip"><i class="bi bi-calendar3" aria-hidden="true"></i><?php echo $h($holiday['holiday_date']) ?></span>
                                        <?php echo $scopeChip($holiday['company_name']) ?>
                                    </div>
                                </div>
                                <?php echo $renderStatusControl('holiday', (int)$holiday['id'], (int)$holiday['status'], $holiday['holiday_name']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>

        <!-- อุปกรณ์ลงเวลา -->
        <div class="col-12">
            <section class="glass-card set-card" id="sec-device" aria-labelledby="h-device" style="height:auto">
                <div class="set-head">
                    <span class="set-icon"><i class="bi bi-fingerprint" aria-hidden="true"></i></span>
                    <div><h2 id="h-device">อุปกรณ์ลงเวลา</h2><p>ระบบจะสร้าง Token ให้อัตโนมัติและแสดงให้เห็นเพียงครั้งเดียว</p></div>
                    <span class="set-count">ใช้งาน <?php echo $activeCount($devices) ?>/<?php echo count($devices) ?></span>
                </div>
                <form method="post" class="set-form" style="grid-template-columns:repeat(12,minmax(0,1fr))">
                    <input type="hidden" name="csrf_token" value="<?php echo $h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="add_device">
                    <div class="set-field" style="grid-column:span 4"><label for="deviceCompany">บริษัท</label>
                        <select id="deviceCompany" name="company_id" class="form-select form-select-sm"><?php echo $companyOptions('ใช้ร่วมกันทุกบริษัท') ?></select></div>
                    <div class="set-field" style="grid-column:span 4"><label for="deviceSerial">Serial No.</label>
                        <input id="deviceSerial" name="device_sn" placeholder="เช่น FP-0001" class="form-control form-control-sm" required maxlength="100"></div>
                    <div class="set-field" style="grid-column:span 4"><label for="deviceName">ชื่อเครื่อง</label>
                        <input id="deviceName" name="device_name" placeholder="เช่น เครื่องสแกนหน้าประตูหลัก" class="form-control form-control-sm" required maxlength="100"></div>
                    <div class="set-field" style="grid-column:span 4"><label for="deviceType">ประเภทเครื่อง</label>
                        <select id="deviceType" name="device_type" class="form-select form-select-sm">
                            <?php foreach ($deviceTypes as $value => $meta): ?><option value="<?php echo $h($value) ?>"><?php echo $h($meta[0]) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="set-field" style="grid-column:span 4"><label for="deviceLocation">จุดติดตั้ง</label>
                        <input id="deviceLocation" name="location" placeholder="เช่น ล็อบบี้ ชั้น 1" class="form-control form-control-sm" required maxlength="150"></div>
                    <div class="set-form-actions" style="grid-column:span 4;align-items:flex-end"><button class="btn btn-success btn-sm btn-modern w-100"><i class="bi bi-plus-lg" aria-hidden="true"></i> เพิ่มเครื่อง</button></div>
                </form>

                <?php if (! $devices): ?>
                    <div class="set-empty"><i class="bi bi-fingerprint" aria-hidden="true"></i>ยังไม่มีอุปกรณ์ลงเวลา</div>
                <?php else: ?>
                    <div class="set-table-wrap table-responsive">
                        <table class="table set-table align-middle">
                            <thead><tr><th>เครื่อง</th><th>บริษัท</th><th>ประเภท</th><th>จุดติดตั้ง</th><th>Token</th><th class="text-end">สถานะ</th></tr></thead>
                            <tbody>
                            <?php foreach ($devices as $device):
                                    $on       = (int) $device['status'] === 1;
                                    $typeMeta = $deviceTypes[$device['device_type']] ?? [$device['device_type'], 'bi-cpu'];
                            ?>
                                <tr class="<?php echo $on ? '' : 'is-off' ?>">
                                    <td>
                                        <div class="set-device-name"><?php echo $h($device['device_name']) ?></div>
                                        <div class="set-sn"><?php echo $h($device['device_sn']) ?></div>
                                    </td>
                                    <td><?php echo $scopeChip($device['company_name']) ?></td>
                                    <td><span class="set-chip"><i class="bi <?php echo $h($typeMeta[1]) ?>" aria-hidden="true"></i><?php echo $h($typeMeta[0]) ?></span></td>
                                    <td><?php echo $h($device['location'] ?? '') ?></td>
                                    <td><span class="set-token"><?php echo $h(substr($device['api_token'], 0, 4) . '••••••••') ?></span></td>
                                    <td class="text-end"><?php echo $renderStatusControl('device', (int)$device['id'], (int)$device['status'], $device['device_name']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>

<script>
(function () {
    var btn = document.getElementById('copyTokenBtn');
    var code = document.getElementById('newDeviceToken');
    if (!btn || !code) return;
    btn.addEventListener('click', function () {
        var done = function () {
            btn.innerHTML = '<i class="bi bi-check2" aria-hidden="true"></i> คัดลอกแล้ว';
            setTimeout(function () { btn.innerHTML = '<i class="bi bi-clipboard" aria-hidden="true"></i> คัดลอก'; }, 2000);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code.textContent.trim()).then(done);
        } else {
            var range = document.createRange(); range.selectNodeContents(code);
            var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
            try { document.execCommand('copy'); done(); } catch (e) {}
        }
    });
})();
</script>

<?php include ROOT_PATH . '/includes/layout_bottom.php'; ?>