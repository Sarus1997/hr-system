<?php
require_once __DIR__ . '/../config/app.php';
require_once ROOT_PATH . '/config/db.php';

function requireLogin()
{
    if (empty($_SESSION['user_id'])) {
        header("Location: " . BASE_URL . "/login.php");
        exit;
    }

    global $pdo;
    $accountStmt = $pdo->prepare(
        "SELECT 1
         FROM users u
         JOIN employees e ON e.id = u.employee_id
         WHERE u.id = ? AND u.status = 1 AND e.status = 1
           AND e.employment_status IN ('active', 'probation')"
    );
    $accountStmt->execute([$_SESSION['user_id']]);
    if (!$accountStmt->fetchColumn()) {
        $_SESSION = [];
        session_destroy();
        header("Location: " . BASE_URL . "/login.php");
        exit;
    }
}

function hasRole(array $roles)
{
    if (!isset($_SESSION['role'])) {
        return false;
    }

    $currentRole = $_SESSION['role'];
    $adminEquivalentRoles = ['admin', 'hr_manager'];
    if (in_array($currentRole, $adminEquivalentRoles, true) &&
        array_intersect($roles, $adminEquivalentRoles)) {
        return true;
    }

    return in_array($currentRole, $roles, true);
}

function canViewAllEmployeeData(): bool
{
    return hasRole(['admin', 'hr', 'hr_manager', 'manager', 'director']);
}

function getSafeProfileImageUrl($profileImageUrl): ?string
{
    $prefix = BASE_URL . '/uploads/profile_images/';
    if (!is_string($profileImageUrl) || !str_starts_with($profileImageUrl, $prefix)) {
        return null;
    }

    $objectKey = substr($profileImageUrl, strlen(BASE_URL . '/uploads/'));
    if (!preg_match('#^profile_images/[0-9]{4}/[0-9]{2}/[A-Za-z0-9_-]+\.(?:jpg|png)$#D', $objectKey)) {
        return null;
    }

    return $profileImageUrl;
}

function getEmployeeDepartmentId(PDO $pdo, string $employeeId): ?int
{
    $departmentStmt = $pdo->prepare(
        'SELECT e.department_id
         FROM employees e
         JOIN departments d ON d.id = e.department_id AND d.status = 1
         WHERE e.id = ? AND e.status = 1'
    );
    $departmentStmt->execute([$employeeId]);
    $departmentId = $departmentStmt->fetchColumn();

    return $departmentId === false || $departmentId === null ? null : (int)$departmentId;
}

function getVisibleEmployees(PDO $pdo, string $employeeId): array
{
    if (canViewAllEmployeeData()) {
        return $pdo->query(
            "SELECT id, emp_code, first_name, last_name
             FROM employees
             WHERE status = 1 AND employment_status IN ('active', 'probation')
             ORDER BY emp_code"
        )->fetchAll();
    }

    $departmentId = getEmployeeDepartmentId($pdo, $employeeId);
    $employeeStmt = $pdo->prepare(
        "SELECT id, emp_code, first_name, last_name FROM employees
         WHERE status = 1 AND employment_status IN ('active', 'probation')
           AND (id = ? OR department_id = ?) ORDER BY emp_code"
    );
    $employeeStmt->execute([$employeeId, $departmentId]);
    return $employeeStmt->fetchAll();
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
        $_SESSION['csrf_tokens'] = [];
    }

    $now = time();
    foreach ($_SESSION['csrf_tokens'] as $token => $issuedAt) {
        if (!is_int($issuedAt) || $issuedAt < $now - 86400) {
            unset($_SESSION['csrf_tokens'][$token]);
        }
    }

    if (count($_SESSION['csrf_tokens']) >= 128) {
        asort($_SESSION['csrf_tokens']);
        $_SESSION['csrf_tokens'] = array_slice($_SESSION['csrf_tokens'], -127, null, true);
    }

    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_tokens'][$token] = $now;

    return $token;
}

function requireValidCsrfToken(): void
{
    $token = $_POST['csrf_token'] ?? '';
    $issuedAt = is_string($token) ? ($_SESSION['csrf_tokens'][$token] ?? null) : null;
    if (!is_int($issuedAt) || $issuedAt < time() - 86400) {
        http_response_code(403);
        exit('คำขอไม่ถูกต้อง กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง');
    }

    unset($_SESSION['csrf_tokens'][$token]);
}

function isCurrentPage(string $file)
{
    return basename($_SERVER['PHP_SELF']) === $file ? 'active' : '';
}
