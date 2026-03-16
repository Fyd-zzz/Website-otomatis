<?php
echo "MERCHANT_CODE: " . (getenv('MERCHANT_CODE') ?: 'KOSONG') . "<br>";
echo "API_KEY: " . (getenv('API_KEY') ? 'ADA' : 'KOSONG') . "<br>";
echo "STRING_QR: " . (getenv('STRING_QR') ? 'ADA (' . strlen(getenv('STRING_QR')) . ' karakter)' : 'KOSONG') . "<br>";
echo "MEMBER_ID: " . (getenv('MEMBER_ID') ?: 'KOSONG') . "<br>";
echo "PIN: " . (getenv('PIN') ? 'ADA' : 'KOSONG') . "<br>";
echo "PASSWORD: " . (getenv('PASSWORD') ? 'ADA' : 'KOSONG') . "<br>";
?>
