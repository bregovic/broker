<?php
header('Content-Type: application/json; charset=utf-8');

$result = [];

// 1. Check if shell_exec is disabled
$result['shell_exec_enabled'] = function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', ini_get('disable_functions'))));

// 2. Run pdftotext version check
if ($result['shell_exec_enabled']) {
    $result['pdftotext_path'] = trim(shell_exec('which pdftotext') ?: 'not found');
    $result['pdftotext_version'] = trim(shell_exec('pdftotext -v 2>&1') ?: 'error running version');
} else {
    $result['pdftotext_path'] = 'disabled';
    $result['pdftotext_version'] = 'disabled';
}

// 3. Check DB connection and rules count
require_once __DIR__ . '/config.php';
try {
    $pdo = get_pdo();
    $result['db_connected'] = true;
    
    $stmt = $pdo->query("SELECT count(*) FROM broker_import_rules");
    $result['rules_count'] = (int)$stmt->fetchColumn();
    
    $stmt2 = $pdo->query("SELECT * FROM broker_import_rules");
    $result['rules'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $result['db_connected'] = false;
    $result['db_error'] = $e->getMessage();
}

echo json_encode($result, JSON_PRETTY_PRINT);
