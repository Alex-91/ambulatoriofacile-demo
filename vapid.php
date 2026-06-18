<?php
require __DIR__ . '/vendor/autoload.php';

putenv('OPENSSL_CONF=C:\\xampp_82\\apache\\conf\\openssl.cnf'); // <-- percorso giusto per te

$keys = \Minishlink\WebPush\VAPID::createVapidKeys();
echo "PUBLIC:  {$keys['publicKey']}\n";
echo "PRIVATE: {$keys['privateKey']}\n";
