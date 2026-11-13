<?php
    require_once __DIR__ . '/../../includes/auth.php';
    requireLogin();

    $employees = $pdo->query("SELECT e.id, e.first_name, e.last_name, e.manager_id, e.profile_image_url, d.name as dept, p.title as pos
                          FROM employees e LEFT JOIN departments d ON e.department_id=d.id LEFT JOIN positions p ON e.position_id=p.id
                          WHERE e.status = 1 AND e.employment_status IN ('active', 'probation')")->fetchAll();

    function renderOrgTree($employees, $managerId = null)
    {
    $children = array_filter($employees, fn($e) => $e['manager_id'] === $managerId);
    if (empty($children)) {
        return '';
    }

    $html = '<ul>';
    foreach ($children as $emp) {
        $html .= '<li>';
        $html .= '<div class="org-node">';
        $profileImageUrl = getSafeProfileImageUrl($emp['profile_image_url'] ?? null);
        $avatarContent = $profileImageUrl !== null
            ? '<img class="avatar-image" src="' . htmlspecialchars($profileImageUrl) . '" alt="">'
            : htmlspecialchars(mb_substr($emp['first_name'], 0, 1));
        $html .= '<div class="avatar-circle mx-auto mb-2">' . $avatarContent . '</div>';
        $html .= '<div class="fw-bold small">' . htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) . '</div>';
        $html .= '<div class="text-primary" style="font-size:0.75rem;font-weight:600;">' . htmlspecialchars($emp['pos'] ?? '-') . '</div>';
        $html .= '<span class="badge bg-light text-muted border mt-1" style="font-size:0.68rem;">' . htmlspecialchars($emp['dept'] ?? '-') . '</span>';
        $html .= '</div>';
        $html .= renderOrgTree($employees, $emp['id']);
        $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
    }

    $pageTitle = 'โครงสร้างองค์กร (Org Chart)';
    include ROOT_PATH . '/includes/layout_top.php';
?>
<div class="glass-card p-4 animate-up overflow-auto">
    <h5 class="fw-bold mb-3"><i class="bi bi-diagram-3-fill text-primary me-2"></i>ผังโครงสร้างองค์กร (Organization Hierarchy)</h5>
    <div class="org-tree py-3">
        <?php echo renderOrgTree($employees, null) ?>
    </div>
</div>
<?php include ROOT_PATH . '/includes/layout_bottom.php'; ?>