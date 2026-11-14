<?php
    require_once __DIR__ . '/../../includes/auth.php';
    requireLogin();

    $companies = $pdo->query('SELECT company_id, company_code, company_name, status FROM companies ORDER BY company_name, company_id')->fetchAll();
    $companyOptions = ['all' => 'ทุกบริษัท'];
    foreach ($companies as $company) {
        $companyId = (string) (int) $company['company_id'];
        $companyOptions[$companyId] = $company['company_code'] . ' · ' . $company['company_name']
            . ((int) $company['status'] === 1 ? '' : ' (ปิดใช้งาน)');
    }

    $selectedCompany = 'all';
    if (array_key_exists('company', $_GET)) {
        $requestedCompany = $_GET['company'];
        if (! is_string($requestedCompany) || ! array_key_exists($requestedCompany, $companyOptions)) {
            http_response_code(400);
            exit('บริษัทที่เลือกไม่ถูกต้อง');
        }
        $selectedCompany = $requestedCompany;
    }
    $selectedCompanyId = $selectedCompany === 'all' ? null : (int) $selectedCompany;

    $employeesSql = "SELECT e.id, e.first_name, e.last_name, e.manager_id, e.profile_image_url, d.name AS dept, p.title AS pos
                          FROM employees e
                          LEFT JOIN departments d ON e.department_id = d.id
                          LEFT JOIN positions p ON e.position_id = p.id
                          WHERE e.status = 1 AND e.employment_status IN ('active', 'probation')";
    if ($selectedCompanyId !== null) {
        $employeesSql .= ' AND e.company_id = ?';
    }
    $employeesSql .= ' ORDER BY e.first_name, e.last_name';
    $employeesStmt = $pdo->prepare($employeesSql);
    $employeesStmt->execute($selectedCompanyId === null ? [] : [$selectedCompanyId]);
    $employees = $employeesStmt->fetchAll();

    /* ---------- เตรียมข้อมูล ---------- */
    $byId       = [];
    $byManager  = [];
    $deptCounts = [];

    foreach ($employees as $e) {
    $byId[(string) $e['id']] = $e;
    $d                       = trim((string) ($e['dept'] ?? ''));
    if ($d !== '') {
        $deptCounts[$d] = ($deptCounts[$d] ?? 0) + 1;
    }
    }

    foreach ($employees as $e) {
    $mid = ($e['manager_id'] !== null && $e['manager_id'] !== '') ? (string) $e['manager_id'] : '__root';
    if ($mid === (string) $e['id'] || ($mid !== '__root' && ! isset($byId[$mid]))) {
        $mid = '__root';
    }
    $byManager[$mid][] = $e;
    }

    ksort($deptCounts);
    $palette = [
    '#2563eb', '#7c3aed', '#0d9488', '#e11d48',
    '#ea580c', '#059669', '#4f46e5', '#0284c7',
    '#d97706', '#9333ea', '#db2777', '#0891b2',
    ];
    $deptColorMap = [];
    $idx          = 0;
    foreach (array_keys($deptCounts) as $dName) {
    $deptColorMap[$dName] = $idx < count($palette)
        ? $palette[$idx]
        : 'hsl(' . (($idx * 137) % 360) . ', 62%, 44%)';
    $idx++;
    }

    $deptColor = function (?string $name) use ($deptColorMap): string {
    $name = trim((string) ($name ?? ''));
    return $deptColorMap[$name] ?? '#64748b';
    };

    $visited  = [];
    $nodeData = [];
    $maxDepth = 0;

    /** วาดโหนดหนึ่งคนพร้อมลูกทีม คืนค่า [html, จำนวนลูกทีมทั้งหมด] */
    function renderNode(array $emp, ?string $parentId, int $depth, array &$byManager, array &$visited, array &$nodeData, int &$maxDepth, callable $deptColor): array
    {
    $id = (string) $emp['id'];
    if (isset($visited[$id])) {
        return ['', 0];
    }
    $visited[$id] = true;
    $maxDepth     = max($maxDepth, $depth);

    $childrenHtml = '';
    $team         = 0;
    $direct       = 0;
    foreach ($byManager[$id] ?? [] as $child) {
        [$h, $t] = renderNode($child, $id, $depth + 1, $byManager, $visited, $nodeData, $maxDepth, $deptColor);
        if ($h !== '') {
            $childrenHtml .= $h;
            $team         += 1 + $t;
            $direct++;
        }
    }

    $fullName  = trim($emp['first_name'] . ' ' . $emp['last_name']);
    $pos       = trim((string) ($emp['pos'] ?? ''));
    $dept      = trim((string) ($emp['dept'] ?? ''));
    $img       = getSafeProfileImageUrl($emp['profile_image_url'] ?? null);
    $color     = $deptColor($dept);

    $nodeData[$id] = [
        'id'     => $id,
        'name'   => $fullName,
        'pos'    => $pos,
        'dept'   => $dept,
        'img'    => $img,
        'mgr'    => $parentId,
        'direct' => $direct,
        'team'   => $team,
        'color'  => $color,
    ];

    $avatar = $img !== null
        ? '<img class="oc-avatar-img" src="' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy">'
        : htmlspecialchars(mb_substr($emp['first_name'], 0, 1), ENT_QUOTES, 'UTF-8');

    $rootBadge = $depth === 0 ? '<span class="oc-root-badge" title="ผู้บริหารสูงสุด / ระดับบนสุด"><i class="bi bi-award-fill"></i></span>' : '';

    $h = '<li data-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" data-depth="' . $depth . '">';
    $h .= '<div class="oc-node' . ($depth === 0 ? ' is-root' : '') . '" tabindex="0" role="button" data-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" style="--dept:' . $color . '">';
    $h .= $rootBadge;
    $h .= '<div class="oc-avatar">' . $avatar . '</div>';
    $h .= '<div class="oc-name" title="' . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . '</div>';
    $h .= '<div class="oc-pos" title="' . htmlspecialchars($pos !== '' ? $pos : '-', ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($pos !== '' ? $pos : '-', ENT_QUOTES, 'UTF-8') . '</div>';
    $h .= '<span class="oc-dept">' . htmlspecialchars($dept !== '' ? $dept : 'ไม่ระบุแผนก', ENT_QUOTES, 'UTF-8') . '</span>';
    if ($direct > 0) {
        $h .= '<button type="button" class="oc-toggle" aria-expanded="true" title="ย่อ/ขยายทีม (ทีมทั้งหมด ' . $team . ' คน)">'
            . '<i class="bi bi-chevron-up"></i><span>' . $direct . '</span></button>';
    }
    $h .= '</div>';
    if ($childrenHtml !== '') {
        $h .= '<ul>' . $childrenHtml . '</ul>';
    }
    $h .= '</li>';

    return [$h, $team];
    }

    $treeHtml = '';
    foreach ($byManager['__root'] ?? [] as $root) {
    [$h]       = renderNode($root, null, 0, $byManager, $visited, $nodeData, $maxDepth, $deptColor);
    $treeHtml .= $h;
    }
    foreach ($employees as $e) {
    if (! isset($visited[(string) $e['id']])) {
        [$h]       = renderNode($e, null, 0, $byManager, $visited, $nodeData, $maxDepth, $deptColor);
        $treeHtml .= $h;
    }
    }

    $totalEmployees = count($nodeData);
    $managerCount   = count(array_filter($nodeData, fn($n) => $n['direct'] > 0));
    $jsonData       = json_encode(array_values($nodeData), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $pageTitle = 'โครงสร้างองค์กร (Org Chart)';
    include ROOT_PATH . '/includes/layout_top.php';
?>
<link rel="stylesheet" href="<?php echo BASE_URL ?>/assets/css/org-chart.css?v=<?php echo filemtime(ROOT_PATH . '/assets/css/org-chart.css') ?>">

<div class="glass-card p-4 oc" id="oc">
    <div class="oc-header">
        <div class="d-flex align-items-center gap-3">
            <div class="oc-title-icon"><i class="bi bi-diagram-3-fill"></i></div>
            <div>
                <h5 class="fw-bold m-0">ผังโครงสร้างองค์กร (Organization Hierarchy)</h5>
                <div class="oc-subtitle">คลิกการ์ดพนักงานเพื่อดูสายการบังคับบัญชาและทีมงาน</div>
            </div>
        </div>
        <form method="get" class="oc-company-filter" action="<?php echo BASE_URL ?>/modules/personnel/org_chart.php">
            <label for="ocCompanyFilter">บริษัท</label>
            <select id="ocCompanyFilter" name="company" class="form-select form-select-sm" onchange="this.form.requestSubmit()">
                <?php foreach ($companyOptions as $companyId => $companyLabel): ?>
                    <option value="<?php echo htmlspecialchars($companyId, ENT_QUOTES, 'UTF-8') ?>" <?php echo $selectedCompany === (string) $companyId ? 'selected' : '' ?>>
                        <?php echo htmlspecialchars($companyLabel, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="oc-stats">
        <div class="oc-stat" style="--sc:#2563eb">
            <div class="oc-stat-icon"><i class="bi bi-people-fill"></i></div>
            <div><b><?php echo $totalEmployees ?></b><span>พนักงานทั้งหมด</span></div>
        </div>
        <div class="oc-stat" style="--sc:#7c3aed">
            <div class="oc-stat-icon"><i class="bi bi-person-badge-fill"></i></div>
            <div><b><?php echo $managerCount ?></b><span>ผู้บังคับบัญชา</span></div>
        </div>
        <div class="oc-stat" style="--sc:#0d9488">
            <div class="oc-stat-icon"><i class="bi bi-building-fill"></i></div>
            <div><b><?php echo count($deptCounts) ?></b><span>แผนก</span></div>
        </div>
        <div class="oc-stat" style="--sc:#d97706">
            <div class="oc-stat-icon"><i class="bi bi-layers-fill"></i></div>
            <div><b><?php echo $maxDepth + 1 ?></b><span>ระดับชั้นสูงสุด</span></div>
        </div>
    </div>

    <div class="oc-toolbar">
        <div class="oc-search">
            <i class="bi bi-search"></i>
            <input type="search" id="ocSearch" class="form-control" placeholder="ค้นหาชื่อ ตำแหน่ง หรือแผนก…" autocomplete="off">
            <button type="button" class="oc-clear-btn" id="ocClearSearch" aria-label="ล้างคำค้นหา"><i class="bi bi-x"></i></button>
        </div>
        <div class="oc-btn-group" role="group" aria-label="ย่อ/ขยายผัง">
            <button type="button" id="ocExpandAll"><i class="bi bi-arrows-expand"></i><span>ขยายทั้งหมด</span></button>
            <button type="button" id="ocCollapse2"><i class="bi bi-arrows-collapse"></i><span>แสดง 2 ระดับ</span></button>
        </div>
        <div class="oc-btn-group" role="group" aria-label="ซูม">
            <button type="button" id="ocZoomOut" aria-label="ซูมออก"><i class="bi bi-dash-lg"></i></button>
            <span class="oc-zoom-label" id="ocZoomLabel" title="คลิกเพื่อรีเซ็ตเป็น 100%">100%</span>
            <button type="button" id="ocZoomIn" aria-label="ซูมเข้า"><i class="bi bi-plus-lg"></i></button>
            <button type="button" id="ocFit"><i class="bi bi-fullscreen"></i><span>พอดีจอ</span></button>
        </div>
        <div class="oc-btn-group">
            <button type="button" id="ocPrint"><i class="bi bi-printer"></i><span>พิมพ์</span></button>
        </div>
        <span class="oc-result" id="ocResult" aria-live="polite"></span>
        <button type="button" class="oc-reset-filter" id="ocResetFilter"><i class="bi bi-arrow-counterclockwise"></i>ล้างตัวกรอง</button>
    </div>

    <?php if ($deptCounts): ?>
        <div class="oc-legend" id="ocLegend">
            <?php foreach ($deptCounts as $name => $count): ?>
                <button type="button" class="oc-chip" data-dept="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" style="--c:<?php echo $deptColor($name) ?>">
                    <i></i><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?><em><?php echo $count ?></em>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="oc-wrap">
        <div class="oc-viewport" id="ocViewport">
            <?php if ($treeHtml === ''): ?>
                <div class="oc-empty"><i class="bi bi-people fs-1 d-block mb-2 opacity-50"></i>ยังไม่มีข้อมูลพนักงานสำหรับสร้างผัง</div>
            <?php else: ?>
                <div class="oc-canvas" id="ocCanvas">
                    <div class="oc-tree"><ul><?php echo $treeHtml ?></ul></div>
                </div>
            <?php endif; ?>
        </div>

        <aside class="oc-panel" id="ocPanel" aria-label="รายละเอียดพนักงาน">
            <button type="button" class="oc-panel-close" id="ocPanelClose" aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
            <div id="ocPanelBody"></div>
        </aside>
    </div>

    <div class="text-muted small mt-2 oc-hint d-flex flex-wrap gap-3">
        <span><i class="bi bi-hand-index me-1"></i>คลิกการ์ดเพื่อดูรายละเอียด</span>
        <span><i class="bi bi-arrows-move me-1"></i>ลากพื้นที่ว่างเพื่อเลื่อนผัง</span>
        <span><i class="bi bi-mouse me-1"></i>กด Ctrl + ล้อเมาส์เพื่อซูม</span>
    </div>
</div>

<script type="application/json" id="ocData"><?php echo $jsonData ?></script>
<script src="<?php echo BASE_URL ?>/assets/js/org-chart.js?v=<?php echo filemtime(ROOT_PATH . '/assets/js/org-chart.js') ?>" defer></script>
<?php include ROOT_PATH . '/includes/layout_bottom.php'; ?>