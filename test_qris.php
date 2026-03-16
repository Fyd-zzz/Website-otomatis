<?php
$stringQR = getenv('STRING_QR');
$amount = 10000;

// Generate dynamic QRIS
$pos6304 = strrpos($stringQR, '6304');
if ($pos6304 !== false) {
    $stringQR = substr($stringQR, 0, $pos6304);
}
$amountStr = number_format($amount, 0, '.', '');
$amountField = '54' . str_pad(strlen($amountStr), 2, '0', STR_PAD_LEFT) . $amountStr;
$pos58 = strpos($stringQR, '5802ID');
if ($pos58 !== false) {
    $newQR = substr($stringQR, 0, $pos58) . $amountField . substr($stringQR, $pos58);
} else {
    $newQR = $stringQR . $amountField;
}
$newQR .= '6304';
$crc = 0xFFFF;
for ($i = 0; $i < strlen($newQR); $i++) {
    $crc ^= ord($newQR[$i]) << 8;
    for ($j = 0; $j < 8; $j++) {
        $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
        $crc &= 0xFFFF;
    }
}
$newQR .= strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));

echo "QR String: " . $newQR . "<br><br>";
echo "<img src='https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($newQR) . "'>";
?>
