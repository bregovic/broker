<?php
/**
 * Robust historical FX importer using ČNB's single-currency endpoint, which is
 * immune to yearly-matrix basket changes (e.g. the 2022 RUB removal that corrupted
 * the old cnb-import-year output). Use this instead of cnb-import-year.
 *
 * Usage: import-rates.php?mena=USD&od=2019&do=2026   (od/do are years; optional)
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate_sync.php';

function resolveUserId() {
    foreach (['user_id','uid','userid','id'] as $k) {
        if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) return (int)$_SESSION[$k];
    }
    return 0;
}

if (!resolveUserId()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $pdo  = get_pdo();
    $cur  = strtoupper(trim($_GET['mena'] ?? $_GET['currency'] ?? 'USD'));
    $fromY = (int)($_GET['od'] ?? 2019);
    $toY   = (int)($_GET['do'] ?? (int)date('Y'));
    if ($cur === '' || $cur === 'CZK') {
        echo json_encode(['success' => false, 'error' => 'Specify a non-CZK currency, e.g. ?mena=USD']);
        exit;
    }

    $total = 0; $perYear = [];
    for ($y = $fromY; $y <= $toY; $y++) {
        $n = rates_upsert($pdo, $cur, cnb_fetch_currency($cur, "01.01.$y", "31.12.$y"));
        $perYear[(string)$y] = $n;
        $total += $n;
    }

    echo json_encode(['success' => true, 'currency' => $cur, 'total' => $total, 'per_year' => $perYear]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
