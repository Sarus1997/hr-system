<?php

class ApprovalWorkflowException extends RuntimeException
{
}

function getApprovalEmployeeProfile(PDO $pdo, string $employeeId): array
{
    $profileStmt = $pdo->prepare(
        'SELECT e.id, e.manager_id, e.company_id, e.department_id, e.position_id,
                d.id AS active_department_id,
                p.id AS active_position_id,
                COALESCE(p.level, 1) AS position_level,
                COALESCE(p.is_leave_required, 1) AS is_leave_required
         FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id AND d.status = 1
         LEFT JOIN positions p ON p.id = e.position_id AND p.status = 1
         WHERE e.id = ?'
    );
    $profileStmt->execute([$employeeId]);
    $profile = $profileStmt->fetch();
    if (!$profile) {
        throw new ApprovalWorkflowException('ไม่พบข้อมูลผู้ยื่นคำขอ');
    }
    if ($profile['department_id'] !== null && $profile['active_department_id'] === null) {
        throw new ApprovalWorkflowException('แผนกของคุณถูกปิดใช้งาน กรุณาติดต่อ HR เพื่อปรับปรุงข้อมูล');
    }
    if ($profile['position_id'] !== null && $profile['active_position_id'] === null) {
        throw new ApprovalWorkflowException('ตำแหน่งของคุณถูกปิดใช้งาน กรุณาติดต่อ HR เพื่อปรับปรุงข้อมูล');
    }

    return $profile;
}

function buildSequentialApprovalSteps(PDO $pdo, string $requesterId): array
{
    $requester = getApprovalEmployeeProfile($pdo, $requesterId);
    $roleByLevel = [
        2 => 'supervisor',
        3 => 'manager',
        4 => 'director',
    ];
    $requesterLevel = (int)$requester['position_level'];
    $requiredLevels = [];
    if ($requesterLevel < 3) {
        $requiredLevels[] = 3;
    }
    if ($requesterLevel < 4) {
        $requiredLevels[] = 4;
    }
    if ($requiredLevels &&
        ($requester['department_id'] === null || $requester['company_id'] === null)) {
        throw new ApprovalWorkflowException('ไม่พบข้อมูลแผนกหรือบริษัทของคุณ กรุณาติดต่อ HR เพื่อตรวจสอบข้อมูลพนักงาน');
    }

    $approvers = [];
    $seenEmployeeIds = [$requesterId => true];
    $lastLevel = $requesterLevel;
    $managerId = $requester['manager_id'];
    $managerStmt = $pdo->prepare(
        'SELECT e.id, e.manager_id, e.company_id, e.department_id, e.employment_status, e.status,
                COALESCE(p.level, 1) AS position_level,
                u.employee_id AS account_employee_id, u.status AS account_status
         FROM employees e
         LEFT JOIN positions p ON p.id = e.position_id AND p.status = 1
         LEFT JOIN users u ON u.employee_id = e.id
         WHERE e.id = ?'
    );

    while (is_string($managerId) && $managerId !== '' && !isset($seenEmployeeIds[$managerId])) {
        $seenEmployeeIds[$managerId] = true;
        $managerStmt->execute([$managerId]);
        $manager = $managerStmt->fetch();
        if (!$manager) {
            break;
        }

        $level = (int)$manager['position_level'];
        if ($level > 4) {
            break;
        }
        if ($level > $lastLevel && isset($roleByLevel[$level])) {
            if ((string)$manager['company_id'] !== (string)$requester['company_id'] ||
                (string)$manager['department_id'] !== (string)$requester['department_id']) {
                throw new ApprovalWorkflowException('สายผู้อนุมัติข้ามแผนกหรือบริษัท กรุณาติดต่อ HR ให้ตรวจสอบ manager_id');
            }
            if ($manager['account_employee_id'] === null || (int)$manager['account_status'] !== 1 ||
                (int)$manager['status'] !== 1 ||
                !in_array($manager['employment_status'], ['active', 'probation'], true)) {
                if (in_array($level, $requiredLevels, true)) {
                    throw new ApprovalWorkflowException('ไม่พบผู้อนุมัติที่ใช้งานได้ในสายของแผนก กรุณาติดต่อ HR');
                }
                $managerId = $manager['manager_id'];
                continue;
            }

            $approvers[] = [
                'approver_role' => $roleByLevel[$level],
                'approver_id' => (string)$manager['id'],
            ];
            $lastLevel = $level;
        }
        $managerId = $manager['manager_id'];
    }

    $foundLevels = array_map(
        static function (array $approver) use ($roleByLevel): int {
            return (int)array_search($approver['approver_role'], $roleByLevel, true);
        },
        $approvers
    );
    foreach ($requiredLevels as $requiredLevel) {
        if (!in_array($requiredLevel, $foundLevels, true)) {
            $requiredRole = $requiredLevel === 3 ? 'ผู้จัดการ' : 'ผู้อำนวยการ';
            throw new ApprovalWorkflowException(
                'ไม่พบ' . $requiredRole . 'ในสายของแผนก กรุณาติดต่อ HR ให้ตั้งค่า manager_id ให้ครบ'
            );
        }
    }

    $finalApproversStmt = $pdo->prepare(
        "SELECT e.id
         FROM employees e
         JOIN users u ON u.employee_id = e.id
         WHERE e.id <> ? AND e.status = 1 AND u.status = 1
           AND e.employment_status IN ('active', 'probation')
           AND u.role IN ('hr_manager', 'admin')
         ORDER BY (e.company_id = ?) DESC, (u.role = 'hr_manager') DESC, e.id"
    );
    $finalApproversStmt->execute([$requesterId, $requester['company_id']]);
    $excludedApproverIds = array_merge(
        [$requesterId],
        array_column($approvers, 'approver_id')
    );
    $finalApproverId = null;
    foreach ($finalApproversStmt->fetchAll(PDO::FETCH_COLUMN) as $candidateId) {
        if (!in_array((string)$candidateId, $excludedApproverIds, true)) {
            $finalApproverId = (string)$candidateId;
            break;
        }
    }

    if ($finalApproverId === null) {
        throw new ApprovalWorkflowException('ไม่พบ HR Manager/Admin ที่ใช้งานได้สำหรับอนุมัติขั้นสุดท้าย กรุณาติดต่อผู้ดูแลระบบ');
    }

    $approvers[] = [
        'approver_role' => 'hr_admin',
        'approver_id' => $finalApproverId,
    ];

    foreach ($approvers as $index => &$approver) {
        $approver['step_order'] = $index + 1;
        $approver['status'] = $index === 0 ? 'pending' : 'waiting';
    }
    unset($approver);

    return $approvers;
}

function insertSequentialApprovalSteps(PDO $pdo, string $requestType, int $requestId, array $steps): void
{
    $tables = [
        'leave' => ['table' => 'leave_approvals', 'request_column' => 'leave_request_id'],
        'ot' => ['table' => 'ot_approvals', 'request_column' => 'ot_request_id'],
    ];
    if (!isset($tables[$requestType]) || $requestId < 1 || !$steps) {
        throw new InvalidArgumentException('ข้อมูลขั้นตอนอนุมัติไม่ถูกต้อง');
    }

    $table = $tables[$requestType]['table'];
    $requestColumn = $tables[$requestType]['request_column'];
    $approvalStmt = $pdo->prepare(
        "INSERT INTO {$table} ({$requestColumn}, step_order, approver_role, approver_id, status)
         VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($steps as $step) {
        $approvalStmt->execute([
            $requestId,
            $step['step_order'],
            $step['approver_role'],
            $step['approver_id'],
            $step['status'],
        ]);
    }
}

function getApprovalRoleLabel(string $role): string
{
    $labels = [
        'supervisor' => 'หัวหน้า',
        'manager' => 'ผู้จัดการ',
        'director' => 'ผู้อำนวยการ',
        'hr_admin' => 'HR Manager/Admin',
    ];

    return $labels[$role] ?? 'ผู้อนุมัติ';
}

function getApprovalProgressLabel(array $request): string
{
    if ($request['status'] === 'approved') {
        return 'อนุมัติแล้ว';
    }
    if ($request['status'] === 'rejected') {
        return 'ไม่อนุมัติ';
    }

    $roleLabel = getApprovalRoleLabel((string)($request['current_approver_role'] ?? ''));
    return 'รอ' . $roleLabel . ' · ขั้น ' . (int)$request['current_step'] . '/' . (int)$request['total_steps'];
}

function getPendingApprovalNotifications(PDO $pdo, string $approverId, int $limit = 8): array
{
    if ($approverId === '') {
        return ['total' => 0, 'items' => []];
    }

    $limit = max(1, min(20, $limit));
    $countStmt = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*)
             FROM leave_approvals la
             JOIN leave_requests lr ON lr.id = la.leave_request_id
             WHERE la.approver_id = ? AND la.status = 'pending'
               AND la.step_order = lr.current_step AND lr.status = 'pending')
            +
            (SELECT COUNT(*)
             FROM ot_approvals oa
             JOIN ot_requests o ON o.id = oa.ot_request_id
             WHERE oa.approver_id = ? AND oa.status = 'pending'
               AND oa.step_order = o.current_step AND o.status = 'pending')"
    );
    $countStmt->execute([$approverId, $approverId]);

    $itemsStmt = $pdo->prepare(
        "SELECT *
         FROM (
             SELECT 'leave' AS request_type, lr.id AS request_id, lr.created_at,
                    e.first_name, e.last_name, lt.name AS category,
                    CONCAT(DATE_FORMAT(lr.start_date, '%d/%m/%Y'), ' · ',
                           FORMAT(lr.total_days, 1), ' วัน') AS detail
             FROM leave_approvals la
             JOIN leave_requests lr ON lr.id = la.leave_request_id
             JOIN employees e ON e.id = lr.employee_id
             JOIN leave_types lt ON lt.id = lr.leave_type_id
             WHERE la.approver_id = ? AND la.status = 'pending'
               AND la.step_order = lr.current_step AND lr.status = 'pending'
             UNION ALL
             SELECT 'ot' AS request_type, o.id AS request_id, o.created_at,
                    e.first_name, e.last_name, 'ทำงานล่วงเวลา' AS category,
                    CONCAT(DATE_FORMAT(o.ot_date, '%d/%m/%Y'), ' · ',
                           FORMAT(o.hours, 1), ' ชม.') AS detail
             FROM ot_approvals oa
             JOIN ot_requests o ON o.id = oa.ot_request_id
             JOIN employees e ON e.id = o.employee_id
             WHERE oa.approver_id = ? AND oa.status = 'pending'
               AND oa.step_order = o.current_step AND o.status = 'pending'
         ) AS pending_notifications
         ORDER BY created_at DESC, request_id DESC
         LIMIT {$limit}"
    );
    $itemsStmt->execute([$approverId, $approverId]);

    return [
        'total' => (int)$countStmt->fetchColumn(),
        'items' => $itemsStmt->fetchAll(),
    ];
}
