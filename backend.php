<?php
/**
 * ============================================
 * PULSAKU - Backend API & Callback Handler
 * ============================================
 * File ini dijalankan di server PHP Anda.
 * Letakkan di: /callback/qiospay.php
 * 
 * URL Callback yang diisi di Qiospay:
 * https://domain-anda.com/callback/qiospay.php
 * ============================================
 */

// Konfigurasi Qiospay (simpan di .env di produksi!)
define('QIOSPAY_MEMBER_ID', 'ISI_MEMBER_ID_ANDA');
define('QIOSPAY_PIN', 'ISI_PIN_ANDA');
define('QIOSPAY_PASSWORD', 'ISI_PASSWORD_ANDA');
define('QIOSPAY_API_URL', 'https://qiospay.id/api/h2h/trx');
define('QIOSPAY_BALANCE_URL', 'https://qiospay.id/api/h2h/balance');

// Log semua request (untuk debugging)
file_put_contents('callback_log.txt', 
    date('Y-m-d H:i:s') . " | " . $_SERVER['REQUEST_METHOD'] . " | " . file_get_contents('php://input') . "\n" . 
    "GET: " . json_encode($_GET) . "\n\n",
    FILE_APPEND
);

header('Content-Type: application/json');

$path = $_SERVER['REQUEST_URI'] ?? '/';

// =====================
// ROUTING
// =====================

// 1. Callback dari Qiospay (dipanggil otomatis oleh Qiospay)
if (strpos($path, '/callback/qiospay') !== false && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handleCallback();
}

// 2. Order API (dipanggil dari frontend)
elseif (strpos($path, '/api/order') !== false && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handleOrder();
}

// 3. Cek Saldo
elseif (strpos($path, '/api/balance') !== false) {
    handleBalance();
}

// 4. Riwayat transaksi
elseif (strpos($path, '/api/transactions') !== false) {
    handleGetTransactions();
}

else {
    echo json_encode(['error' => 'Endpoint tidak ditemukan']);
}


// =====================
// HANDLER FUNCTIONS
// =====================

/**
 * Terima callback dari Qiospay
 * Qiospay mengirim: GET /callback?refid=XXX&message=RESPONSE_TEXT
 */
function handleCallback() {
    $refID   = $_GET['refid'] ?? '';
    $message = $_GET['message'] ?? '';
    
    if (empty($message)) {
        // Beberapa format Qiospay kirim di body
        $message = file_get_contents('php://input');
    }
    
    if (empty($refID) && empty($message)) {
        echo json_encode(['status' => 'error', 'msg' => 'No data']);
        return;
    }
    
    // Parse response dari Qiospay
    $parsed = parseQiospayResponse($message);
    
    if ($parsed['refID']) $refID = $parsed['refID'];
    
    // Update status transaksi di database/file
    updateTransaction($refID, [
        'status'   => $parsed['status'],
        'sn'       => $parsed['sn'],
        'balance'  => $parsed['balance'],
        'response' => $message,
        'updated'  => date('Y-m-d H:i:s'),
    ]);
    
    // Log callback
    logCallback($refID, $message, $parsed);
    
    echo json_encode([
        'status'  => 'ok',
        'refID'   => $refID,
        'parsed'  => $parsed,
    ]);
}

/**
 * Kirim order ke Qiospay
 */
function handleOrder() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validasi input
    $product  = $data['product'] ?? '';
    $dest     = $data['dest'] ?? '';
    $refID    = $data['refID'] ?? 'TRX' . time();
    $buyer    = $data['buyer'] ?? '';
    $email    = $data['email'] ?? '';
    
    if (empty($product) || empty($dest)) {
        echo json_encode(['success' => false, 'message' => 'Product dan dest wajib diisi']);
        return;
    }
    
    // Buat request ke Qiospay H2H
    $params = http_build_query([
        'product'  => $product,
        'dest'     => $dest,
        'refID'    => $refID,
        'memberID' => QIOSPAY_MEMBER_ID,
        'pin'      => QIOSPAY_PIN,
        'password' => QIOSPAY_PASSWORD,
    ]);
    
    $url = QIOSPAY_API_URL . '?' . $params;
    
    // HTTP Request ke Qiospay
    $response = httpGet($url);
    
    if ($response === false) {
        echo json_encode(['success' => false, 'message' => 'Gagal terhubung ke server Qiospay']);
        return;
    }
    
    // Parse response
    $parsed = parseQiospayResponse($response);
    
    // Simpan transaksi
    saveTransaction([
        'refID'    => $refID,
        'product'  => $product,
        'dest'     => $dest,
        'buyer'    => $buyer,
        'email'    => $email,
        'status'   => $parsed['status'],
        'sn'       => $parsed['sn'],
        'response' => $response,
        'created'  => date('Y-m-d H:i:s'),
    ]);
    
    echo json_encode([
        'success'  => true,
        'refID'    => $refID,
        'status'   => $parsed['status'],
        'sn'       => $parsed['sn'],
        'message'  => $response,
    ]);
}

/**
 * Cek saldo Qiospay
 */
function handleBalance() {
    $url = QIOSPAY_BALANCE_URL . '?' . http_build_query([
        'memberID' => QIOSPAY_MEMBER_ID,
        'pin'      => QIOSPAY_PIN,
        'password' => QIOSPAY_PASSWORD,
    ]);
    
    $response = httpGet($url);
    
    echo json_encode([
        'success'  => $response !== false,
        'response' => $response,
    ]);
}

/**
 * Ambil daftar transaksi
 */
function handleGetTransactions() {
    $transactions = loadTransactions();
    echo json_encode(['success' => true, 'data' => $transactions]);
}


// =====================
// PARSING RESPONSE
// =====================

/**
 * Parse response text dari Qiospay
 * 
 * Format SUKSES: "ID#xxx R#REF SUKSES. SN/Ref: SERIAL. Saldo xxx - yyy = zzz"
 * Format GAGAL:  "ID#xxx R#REF GAGAL. Pesan gagal. Saldo xxx"
 * Format PENDING:"R#REF ... akan diproses. Saldo xxx"
 */
function parseQiospayResponse($text) {
    $result = [
        'status'  => 'PENDING',
        'refID'   => null,
        'sn'      => null,
        'balance' => null,
        'message' => $text,
    ];
    
    // Ambil refID
    if (preg_match('/R#(\S+)/', $text, $m)) {
        $result['refID'] = $m[1];
    }
    
    // Cek status
    if (stripos($text, 'SUKSES') !== false) {
        $result['status'] = 'SUKSES';
        // Ambil serial number
        if (preg_match('/SN\/Ref:\s*([^.]+)/', $text, $m)) {
            $result['sn'] = trim($m[1]);
        }
    } elseif (stripos($text, 'GAGAL') !== false) {
        $result['status'] = 'GAGAL';
    } elseif (stripos($text, 'akan diproses') !== false || stripos($text, 'diproses') !== false) {
        $result['status'] = 'PENDING';
    }
    
    // Ambil saldo akhir (format: "= 1.234.567")
    if (preg_match('/=\s*([\d.]+)\s*@/', $text, $m)) {
        $result['balance'] = $m[1];
    } elseif (preg_match('/Saldo[:\s]+([\d.]+)$/', $text, $m)) {
        $result['balance'] = $m[1];
    }
    
    return $result;
}


// =====================
// DATABASE (Flat File)
// =====================
// Untuk produksi, ganti dengan MySQL/PostgreSQL

define('DATA_DIR', __DIR__ . '/data/');

function saveTransaction($trx) {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
    $transactions = loadTransactions();
    // Update jika sudah ada
    $found = false;
    foreach ($transactions as &$t) {
        if ($t['refID'] === $trx['refID']) {
            $t = array_merge($t, $trx);
            $found = true;
            break;
        }
    }
    if (!$found) {
        array_unshift($transactions, $trx);
    }
    // Simpan max 1000 transaksi
    $transactions = array_slice($transactions, 0, 1000);
    file_put_contents(DATA_DIR . 'transactions.json', json_encode($transactions, JSON_PRETTY_PRINT));
}

function updateTransaction($refID, $updates) {
    $transactions = loadTransactions();
    foreach ($transactions as &$t) {
        if ($t['refID'] === $refID) {
            $t = array_merge($t, $updates);
            break;
        }
    }
    file_put_contents(DATA_DIR . 'transactions.json', json_encode($transactions, JSON_PRETTY_PRINT));
}

function loadTransactions() {
    $file = DATA_DIR . 'transactions.json';
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

function logCallback($refID, $raw, $parsed) {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
    $log = date('Y-m-d H:i:s') . " | refID: {$refID} | status: {$parsed['status']} | raw: {$raw}\n";
    file_put_contents(DATA_DIR . 'callback.log', $log, FILE_APPEND);
}


// =====================
// HTTP HELPER
// =====================
function httpGet($url, $timeout = 30) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'PulsaKu/1.0',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("cURL Error: " . $error);
        return false;
    }
    return $response;
}
?>
