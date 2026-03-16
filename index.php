<?php
// Entry point - arahkan ke halaman yang sesuai
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// Route callback Qiospay
if (strpos($path, '/callback') !== false || strpos($path, '/api') !== false) {
    require_once __DIR__ . '/backend.php';
    exit;
}

// Default: tampilkan website utama
readfile(__DIR__ . '/index.html');
