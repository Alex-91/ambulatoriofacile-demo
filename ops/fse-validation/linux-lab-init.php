<?php
// Run only as the one-shot initializer of a NEW isolated Compose lab volume.
if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || !is_file('/opt/fse/linux-lab-image')) {
    throw new RuntimeException('Isolated Linux image required.');
}
$id = (string) getenv('FSE_LINUX_LAB_ID');
if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new RuntimeException('Invalid lab identifier.');
$base = '/var/www/html/rest/writable';
$root = $base.'/fse-app-labs/'.$id;
if (is_link($base) || realpath($base) !== $base || file_exists($root)) {
    throw new RuntimeException('A fresh writable volume is required; never reseed an existing lab.');
}
umask(0077);
foreach (['','/mysql','/writable','/writable/cache','/writable/logs','/writable/session','/writable/tenants','/signing','/recovery'] as $path) {
    if (!mkdir($root.$path, 0700, true)) throw new RuntimeException('Cannot create lab directory.');
}
$config = ['mode'=>'FSE_SYNTHETIC_APP_LAB','runtime'=>'linux-container','port'=>33079,'web_port'=>8088,
    'password'=>bin2hex(random_bytes(32)), 'secret_key'=>bin2hex(random_bytes(32)),
    'login_password'=>bin2hex(random_bytes(24))];
if (file_put_contents($root.'/lab.json', json_encode($config, JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT), LOCK_EX) === false
    || file_put_contents($root.'/mysql-password', $config['password'], LOCK_EX) === false) {
    throw new RuntimeException('Cannot write synthetic credentials.');
}
// Only files just created in this fresh marked volume, never any host/production path.
$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($items as $item) {
    if ($item->isLink() || !chown($item->getPathname(), 33) || !chgrp($item->getPathname(), 33)) throw new RuntimeException('Cannot set lab ownership.');
}
if (!chown($root, 33) || !chgrp($root, 33)) throw new RuntimeException('Cannot set lab ownership.');
// Parents need traversal by both MySQL entrypoint and the application, no listing of credentials.
chmod($base.'/fse-app-labs', 0711);
chmod($root, 0711);
echo "Fresh synthetic lab initialized. Credentials remain in the private volume.\n";
