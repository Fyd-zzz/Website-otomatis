<?php
echo "IP Server: " . $_SERVER['SERVER_ADDR'] . "<br>";
echo "IP Remote: " . $_SERVER['REMOTE_ADDR'] . "<br>";
echo "Host: " . gethostname() . "<br>";
$ip = file_get_contents('https://api.ipify.org');
echo "Public IP: " . $ip;
?>
