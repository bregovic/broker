<?php
/**
 * API Endpoint pro vracení překladů (Labels)
 */
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");

$lang = $_GET['lang'] ?? 'cs';
$file = "translations/{$lang}.json";

if (file_exists($file)) {
    echo file_get_contents($file);
} else {
    // Fallback na češtinu
    echo file_get_contents("translations/cs.json");
}
