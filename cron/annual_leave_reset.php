<?php
if (php_sapi_name() !== 'cli') {
    die("CLI Only");
}

require_once __DIR__ . '/../config/app.php';
require_once ROOT_PATH . '/config/db.php';
require_once ROOT_PATH . '/includes/leave_service.php';

$toYear = (int) date('Y');
try {
    $res = processAnnualLeaveReset($pdo, $toYear - 1, $toYear, null);
    echo "Reset complete: {$res['processed']} employees.\n";
} catch (Exception $e) {
    echo "Notice: " . $e->getMessage() . "\n";
}
