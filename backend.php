<?php
/**
 * ============================================
 * PULSAKU - Backend Live + QRIS Payment
 * Qiospay H2H Integration
 * ============================================
 */

// Konfigurasi dari ENV Railway
define('QIOSPAY_MEMBER_ID', getenv('MEMBER_ID') ?: '');
define('QIOSPAY_PIN',       getenv('PIN') ?: '');
define('QIOSPAY_PASSWORD',  getenv('PASSWORD') ?: '');
define('QIOSPAY_API_URL',   'https://qiospay.id/api/h2h/trx');

// QRIS Config
define('QRIS_MERCHANT_CODE', getenv('MERCHANT_CODE') ?: '');
define('QRIS_API_KEY',       getenv('API_KEY') ?: '');
define('QRIS_STRING',        getenv('STRING_QR') ?: '');

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

// Data dir
define('DATA_DIR', sys_get_temp_dir() . '/pulsaku/');
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);

// Log request
@file_put_contents(DATA_DIR . 'access.log',
    date('Y-m-d H:i:s') . " | " . ($_SERVER['REQUEST_METHOD'] ?? '') . " | " . ($_SERVER['REQUEST_URI'] ?? '') . "\n",
    FILE_APPEND
);

$route = $_GET['route'] ?? '';

// =====================
// ROUTING
// =====================

// Callback dari Qiospay (ada refid di GET)
if (isset($_GET['refid']) || isset($_GET['refID'])) {
    handleCallback();
}
elseif ($route === 'order') {
    handleOrder();
}
elseif ($route === 'qris_create') {
    handleQrisCreate();
}
elseif ($route === 'qris_check') {
    handleQrisCheck();
}
elseif ($route === 'balance') {
    handleBalance();
}
elseif ($route === 'ip') {
    $ip = @file_get_contents('https://api.ipify.org');
    echo json_encode(['ip' => $ip ?: $_SERVER['SERVER_ADDR']]);
}
elseif ($route === 'check') {
    handleCheckStatus();
}
else {
    echo json_encode(['status' => 'ok', 'message' => 'PulsaKu API v2.0 Ready', 'time' => date('Y-m-d H:i:s')]);
}


// =====================
// ORDER H2H (LIVE)
// =====================
function handleOrder() {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $product = trim($data['product'] ?? $_GET['product'] ?? '');
    $dest    = trim($data['dest']    ?? $_GET['dest']    ?? '');
    $refID   = trim($data['refID']   ?? $_GET['refID']   ?? 'TRX' . time() . rand(100,999));
    $buyer   = trim($data['buyer']   ?? '');
    $email   = trim($data['email']   ?? '');
    $price   = intval($data['price'] ?? 0);

    if (!$product || !$dest) {
        echo json_encode(['success' => false, 'message' => 'Product dan dest wajib diisi']); return;
    }
    if (!QIOSPAY_MEMBER_ID || !QIOSPAY_PIN || !QIOSPAY_PASSWORD) {
        echo json_encode(['success' => false, 'message' => 'ENV Variables belum diisi di Railway (MEMBER_ID, PIN, PASSWORD)']); return;
    }

    $params = http_build_query([
        'product'  => $product,
        'dest'     => $dest,
        'refID'    => $refID,
        'memberID' => QIOSPAY_MEMBER_ID,
        'pin'      => QIOSPAY_PIN,
        'password' => QIOSPAY_PASSWORD,
    ]);

    $url      = QIOSPAY_API_URL . '?' . $params;
    $response = httpGet($url);

    if ($response === false) {
        echo json_encode(['success' => false, 'message' => 'Gagal hubungi Qiospay. Cek whitelist IP di Qiospay.']); return;
    }

    $parsed = parseResponse($response);

    saveTransaction([
        'refID'    => $refID,
        'product'  => $product,
        'dest'     => $dest,
        'buyer'    => $buyer,
        'email'    => $email,
        'price'    => $price,
        'status'   => $parsed['status'],
        'sn'       => $parsed['sn'],
        'response' => $response,
        'created'  => date('Y-m-d H:i:s'),
    ]);

    echo json_encode([
        'success' => true,
        'refID'   => $refID,
        'status'  => $parsed['status'],
        'sn'      => $parsed['sn'],
        'message' => $response,
    ]);
}


// =====================
// BUAT QRIS DINAMIS
// =====================
function handleQrisCreate() {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $amount  = intval($data['amount']  ?? $_GET['amount']  ?? 0);
    $refID   = trim($data['refID']     ?? $_GET['refID']   ?? 'QR' . time() . rand(100,999));
    $product = trim($data['product']   ?? '');
    $dest    = trim($data['dest']      ?? '');
    $buyer   = trim($data['buyer']     ?? '');

    if ($amount < 1000) {
        echo json_encode(['success' => false, 'message' => 'Nominal minimal Rp 1.000']); return;
    }
    if (!QRIS_MERCHANT_CODE || !QRIS_API_KEY || !QRIS_STRING) {
        echo json_encode(['success' => false, 'message' => 'ENV Variables QRIS belum diisi (MERCHANT_CODE, API_KEY, STRING_QR)']); return;
    }

    // Generate QRIS dinamis dengan nominal
    $qrisString = generateDynamicQRIS(QRIS_STRING, $amount);

    // Simpan pending order
    $expiredAt = date('Y-m-d H:i:s', time() + 300); // 5 menit
    savePendingOrder([
        'refID'   => $refID,
        'amount'  => $amount,
        'product' => $product,
        'dest'    => $dest,
        'buyer'   => $buyer,
        'status'  => 'PENDING',
        'qrcode'  => $qrisString,
        'created' => date('Y-m-d H:i:s'),
        'expired' => $expiredAt,
    ]);

    echo json_encode([
        'success' => true,
        'refID'   => $refID,
        'amount'  => $amount,
        'qrcode'  => $qrisString,
        'expired' => $expiredAt,
    ]);
}


// =====================
// CEK STATUS QRIS
// =====================
function handleQrisCheck() {
    $refID = trim($_GET['refID'] ?? '');
    if (!$refID) { echo json_encode(['success' => false, 'message' => 'refID diperlukan']); return; }

    $order = getPendingOrder($refID);
    if (!$order) { echo json_encode(['success' => false, 'message' => 'Order tidak ditemukan']); return; }

    if ($order['status'] === 'PAID') {
        echo json_encode(['success' => true, 'status' => 'PAID', 'order' => $order]); return;
    }
    if ($order['status'] === 'EXPIRED') {
        echo json_encode(['success' => true, 'status' => 'EXPIRED']); return;
    }

    // Cek mutasi ke Qiospay
    $params = http_build_query([
        'merchantcode' => QRIS_MERCHANT_CODE,
        'apikey'       => QRIS_API_KEY,
        'amount'       => $order['amount'],
        'reffid'       => $refID,
    ]);

    $checkUrl = 'https://qiospay.id/api/qris/check?' . $params;
    $response = httpGet($checkUrl);

    $paid = false;
    if ($response) {
        $resData = json_decode($response, true);
        // Cek berbagai format response Qiospay QRIS
        $status = strtoupper($resData['status'] ?? $resData['data']['status'] ?? '');
        if ($status === 'PAID' || $status === 'SUCCESS' || $status === 'SUKSES') {
            $paid = true;
        }
    }

    if ($paid) {
        updatePendingOrder($refID, ['status' => 'PAID', 'paid_at' => date('Y-m-d H:i:s')]);

        // Proses H2H otomatis setelah bayar
        $trxResult = null;
        if (!empty($order['product']) && !empty($order['dest']) && QIOSPAY_MEMBER_ID) {
            $trxRefID = 'TRX' . time() . rand(100,999);
            $params = http_build_query([
                'product'  => $order['product'],
                'dest'     => $order['dest'],
                'refID'    => $trxRefID,
                'memberID' => QIOSPAY_MEMBER_ID,
                'pin'      => QIOSPAY_PIN,
                'password' => QIOSPAY_PASSWORD,
            ]);
            $trxUrl  = QIOSPAY_API_URL . '?' . $params;
            $trxResp = httpGet($trxUrl);
            $trxParsed = parseResponse($trxResp ?: '');

            saveTransaction([
                'refID'    => $trxRefID,
                'qrisRef'  => $refID,
                'product'  => $order['product'],
                'dest'     => $order['dest'],
                'buyer'    => $order['buyer'] ?? '',
                'price'    => $order['amount'],
                'status'   => $trxParsed['status'],
                'sn'       => $trxParsed['sn'],
                'response' => $trxResp,
                'created'  => date('Y-m-d H:i:s'),
            ]);
            $trxResult = ['refID' => $trxRefID, 'status' => $trxParsed['status'], 'sn' => $trxParsed['sn']];
        }

        echo json_encode(['success' => true, 'status' => 'PAID', 'transaction' => $trxResult]);
        return;
    }

    // Cek expired
    if (time() > strtotime($order['expired'])) {
        updatePendingOrder($refID, ['status' => 'EXPIRED']);
        echo json_encode(['success' => true, 'status' => 'EXPIRED']); return;
    }

    $sisaDetik = strtotime($order['expired']) - time();
    echo json_encode(['success' => true, 'status' => 'PENDING', 'expired' => $order['expired'], 'sisa_detik' => $sisaDetik]);
}


// =====================
// CALLBACK H2H
// =====================
function handleCallback() {
    $refID   = $_GET['refid'] ?? $_GET['refID'] ?? '';
    $message = $_GET['message'] ?? file_get_contents('php://input');

    if (empty($message)) { echo json_encode(['status' => 'error']); return; }

    $parsed = parseResponse($message);
    if ($parsed['refID']) $refID = $parsed['refID'];

    updateTransaction($refID, [
        'status'  => $parsed['status'],
        'sn'      => $parsed['sn'],
        'balance' => $parsed['balance'],
        'updated' => date('Y-m-d H:i:s'),
    ]);

    @file_put_contents(DATA_DIR . 'callback.log',
        date('Y-m-d H:i:s') . " | {$refID} | {$parsed['status']} | {$message}\n", FILE_APPEND
    );

    echo json_encode(['status' => 'ok', 'refID' => $refID, 'status_trx' => $parsed['status']]);
}


// =====================
// BALANCE
// =====================
function handleBalance() {
    if (!QIOSPAY_MEMBER_ID) { echo json_encode(['success' => false, 'message' => 'ENV belum diisi']); return; }
    $params = http_build_query(['balance' => QIOSPAY_MEMBER_ID, 'pin' => QIOSPAY_PIN, 'password' => QIOSPAY_PASSWORD]);
    $response = httpGet('https://qiospay.id/api/h2h_center/trx?' . $params);
    echo json_encode(['success' => true, 'response' => $response]);
}


// =====================
// CHECK STATUS TRX
// =====================
function handleCheckStatus() {
    $refID = $_GET['refID'] ?? '';
    foreach (loadTransactions() as $t) {
        if ($t['refID'] === $refID) { echo json_encode(['success' => true, 'transaction' => $t]); return; }
    }
    echo json_encode(['success' => false, 'message' => 'Tidak ditemukan']);
}


// =====================
// PARSE RESPONSE
// =====================
function parseResponse($text) {
    $r = ['status' => 'PENDING', 'refID' => null, 'sn' => null, 'balance' => null];
    if (preg_match('/R#(\S+)/', $text, $m)) $r['refID'] = $m[1];
    if (stripos($text, 'SUKSES') !== false) {
        $r['status'] = 'SUKSES';
        if (preg_match('/SN\/Ref:\s*([^.]+)/', $text, $m)) $r['sn'] = trim($m[1]);
    } elseif (stripos($text, 'GAGAL') !== false) {
        $r['status'] = 'GAGAL';
    }
    if (preg_match('/=\s*([\d.]+)\s*@/', $text, $m)) $r['balance'] = $m[1];
    return $r;
}


// =====================
// GENERATE QRIS DINAMIS
// =====================
function generateDynamicQRIS($staticQRIS, $amount) {
    // Hapus CRC lama (4 karakter terakhir setelah 6304)
    $pos6304 = strrpos($staticQRIS, '6304');
    if ($pos6304 !== false) {
        $staticQRIS = substr($staticQRIS, 0, $pos6304);
    }

    // Hapus field 54 jika sudah ada
    if (preg_match('/54\d{2}\d+/', $staticQRIS, $m, PREG_OFFSET_CAPTURE)) {
        $staticQRIS = str_replace($m[0][0], '', $staticQRIS);
    }

    // Tambah field 54 (amount)
    $amountStr   = number_format($amount, 0, '.', '');
    $amountField = '54' . str_pad(strlen($amountStr), 2, '0', STR_PAD_LEFT) . $amountStr;

    // Insert sebelum field 58
    $pos58 = strpos($staticQRIS, '5802ID');
    if ($pos58 !== false) {
        $newQRIS = substr($staticQRIS, 0, $pos58) . $amountField . substr($staticQRIS, $pos58);
    } else {
        $newQRIS = $staticQRIS . $amountField;
    }

    // Tambah field 63 + CRC baru
    $newQRIS .= '6304';
    $newQRIS .= crc16($newQRIS);

    return strtoupper($newQRIS);
}

function crc16($data) {
    $crc = 0xFFFF;
    for ($i = 0; $i < strlen($data); $i++) {
        $crc ^= ord($data[$i]) << 8;
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
            $crc &= 0xFFFF;
        }
    }
    return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}


// =====================
// DATABASE
// =====================
function saveTransaction($trx) {
    $list = loadTransactions();
    $found = false;
    foreach ($list as &$t) { if ($t['refID'] === $trx['refID']) { $t = array_merge($t, $trx); $found = true; break; } }
    if (!$found) array_unshift($list, $trx);
    @file_put_contents(DATA_DIR . 'transactions.json', json_encode(array_slice($list, 0, 500)));
}
function updateTransaction($refID, $updates) {
    $list = loadTransactions();
    foreach ($list as &$t) { if ($t['refID'] === $refID) { $t = array_merge($t, $updates); break; } }
    @file_put_contents(DATA_DIR . 'transactions.json', json_encode($list));
}
function loadTransactions() {
    $f = DATA_DIR . 'transactions.json';
    return file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
}
function savePendingOrder($o) {
    $list = loadPendingOrders();
    $found = false;
    foreach ($list as &$item) { if ($item['refID'] === $o['refID']) { $item = array_merge($item, $o); $found = true; break; } }
    if (!$found) array_unshift($list, $o);
    @file_put_contents(DATA_DIR . 'pending_orders.json', json_encode(array_slice($list, 0, 200)));
}
function updatePendingOrder($refID, $updates) {
    $list = loadPendingOrders();
    foreach ($list as &$o) { if ($o['refID'] === $refID) { $o = array_merge($o, $updates); break; } }
    @file_put_contents(DATA_DIR . 'pending_orders.json', json_encode($list));
}
function getPendingOrder($refID) {
    foreach (loadPendingOrders() as $o) { if ($o['refID'] === $refID) return $o; }
    return null;
}
function loadPendingOrders() {
    $f = DATA_DIR . 'pending_orders.json';
    return file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
}


// =====================
// HTTP GET
// =====================
function httpGet($url, $timeout = 30) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'PulsaKu/2.0', CURLOPT_FOLLOWLOCATION => true,
        ]);
        $r = curl_exec($ch); $e = curl_error($ch); curl_close($ch);
        if ($e) { error_log("cURL: $e"); return false; }
        return $r;
    }
    return @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeout]]));
}
?>
