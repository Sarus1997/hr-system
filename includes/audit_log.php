<?php

function recordAuditEvent(
    PDO $pdo,
    string $action,
    string $entityType,
    ?string $entityId = null,
    array $metadata = []
): void {
    $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    $employeeId = $_SESSION['employee_id'] ?? null;

    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, actor_employee_id, action, entity_type, entity_id, metadata)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId === false ? null : $userId,
        is_string($employeeId) && $employeeId !== '' ? $employeeId : null,
        $action,
        $entityType,
        $entityId,
        $metadataJson,
    ]);
}
