<?php
// PROVA con slash avanti (Windows li accetta ed evita ambiguità)
$cnf = 'C:/xampp_82/php/extras/openssl/openssl.cnf';  // <-- metti il percorso che ESISTE
putenv("OPENSSL_CONF=$cnf");

header('Content-Type: text/plain; charset=utf-8');
echo "OPENSSL_CONF=" . getenv('OPENSSL_CONF') . PHP_EOL;
echo "file_exists: " . (file_exists($cnf) ? 'YES' : 'NO') . PHP_EOL;
echo "is_readable: " . (is_readable($cnf) ? 'YES' : 'NO') . PHP_EOL;

// Leggo la prima riga
if (is_readable($cnf)) {
  $h = fopen($cnf, 'r');
  echo "first_line: " . fgets($h) . PHP_EOL;
  fclose($h);
}

// Test EC con 'config' esplicito
$conf = [
  "private_key_type" => OPENSSL_KEYTYPE_EC,
  "curve_name" => "prime256v1",
  "config" => $cnf,  // <--- forza l'uso del cnf
];
$res = @openssl_pkey_new($conf);
echo "openssl_pkey_new_EC: " . ($res ? 'OK' : 'FAIL') . PHP_EOL;
if (!$res) { while ($msg = openssl_error_string()) echo "openssl_error: $msg\n"; }
