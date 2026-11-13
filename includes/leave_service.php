<?php
function getLeaveAttachmentsByRequestIds(PDO $pdo, array $requestIds): array
{
    $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds), static function ($id) {
        return $id > 0;
    })));
    if (!$requestIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, leave_request_id, file_name
         FROM leave_attachments
         WHERE leave_request_id IN ({$placeholders})
         ORDER BY uploaded_at, id"
    );
    $stmt->execute($requestIds);
    $attachmentsByRequest = [];
    foreach ($stmt->fetchAll() as $attachment) {
        $attachmentsByRequest[(int)$attachment['leave_request_id']][] = $attachment;
    }

    return $attachmentsByRequest;
}

function removeLocalLeaveAttachmentFiles(array $objectKeys): void
{
    $storageRoot = realpath(ROOT_PATH . '/uploads/leave_attachments');
    if ($storageRoot === false) {
        return;
    }

    foreach ($objectKeys as $objectKey) {
        if (!is_string($objectKey) ||
            !preg_match('#^leave_attachments/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$#D', $objectKey) ||
            strpos($objectKey, '..') !== false) {
            continue;
        }

        $filePath = realpath(ROOT_PATH . '/uploads/' . str_replace('/', DIRECTORY_SEPARATOR, $objectKey));
        if ($filePath === false || strpos($filePath, $storageRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($filePath)) {
            continue;
        }
        if (!unlink($filePath)) {
            error_log('Unable to remove a leave attachment file');
        }
    }
}

function processAnnualLeaveReset(PDO $pdo, int $fromYear, int $toYear, ?int $executedBy = null): array
{
    $chk = $pdo->prepare("SELECT id FROM leave_reset_logs WHERE to_year = ?");
    $chk->execute([$toYear]);
    if ($chk->fetch()) {
        throw new Exception("ปี $toYear ได้ทำการประมวลผลรีเซ็ตวันลาไปแล้ว");
    }

    $pdo->beginTransaction();
    try {
        $employees      = $pdo->query("SELECT id FROM employees WHERE status = 1 AND employment_status IN ('active', 'probation')")->fetchAll(PDO::FETCH_COLUMN);
        $leaveTypes     = $pdo->query("SELECT * FROM leave_types WHERE status = 1")->fetchAll();
        $processedCount = 0;

        foreach ($employees as $empId) {
            foreach ($leaveTypes as $lt) {
                $stmtOld = $pdo->prepare("SELECT * FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ?");
                $stmtOld->execute([$empId, $lt['id'], $fromYear]);
                $old = $stmtOld->fetch();

                $carriedDays = 0.0;
                $nextCycle   = 1;

                if ($lt['is_carry_over'] && $old) {
                    $remaining    = max(0, ($old['quota_days'] + $old['carried_days']) - $old['used_days']);
                    $currentCycle = (int) $old['carry_cycle_count'];

                    if ($currentCycle < (int) $lt['max_carry_over_years']) {
                        $carriedDays = $remaining;
                        $nextCycle   = $currentCycle + 1;
                    } else {
                        // ครบ 3 ปีแล้ว รีเซ็ตยอดสะสมเป็น 0 เริ่มรอบใหม่
                        $carriedDays = 0.0;
                        $nextCycle   = 1;
                    }
                }

                $stmtIns = $pdo->prepare("
                    INSERT INTO leave_balances (employee_id, leave_type_id, year, quota_days, carried_days, used_days, carry_cycle_count)
                    VALUES (?, ?, ?, ?, ?, 0.0, ?)
                    ON DUPLICATE KEY UPDATE quota_days = VALUES(quota_days), carried_days = VALUES(carried_days), carry_cycle_count = VALUES(carry_cycle_count)
                ");
                $stmtIns->execute([$empId, $lt['id'], $toYear, $lt['max_days_per_year'], $carriedDays, $nextCycle]);
            }
            $processedCount++;
        }

        $pdo->prepare("INSERT INTO leave_reset_logs (from_year, to_year, processed_employees, executed_by) VALUES (?, ?, ?, ?)")
            ->execute([$fromYear, $toYear, $processedCount, $executedBy]);

        $pdo->commit();
        return ['status' => 'success', 'processed' => $processedCount];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
