<?php

declare(strict_types=1);

$root = dirname(__DIR__);
umask(0077);
foreach (['storage/files', 'sessions'] as $directory) {
    if (!is_dir($root . '/var/' . $directory)) {
        mkdir($root . '/var/' . $directory, 0700, true);
    }
}
if (!is_file($root . '/.env.local')) {
    file_put_contents($root . '/.env.local', "APP_SECRET=" . bin2hex(random_bytes(32)) . "\n", LOCK_EX);
    chmod($root . '/.env.local', 0600);
}
$keyPath = $root . '/var/storage/encryption.key';
if (!is_file($keyPath)) {
    file_put_contents($keyPath, random_bytes(32), LOCK_EX);
    chmod($keyPath, 0600);
}
echo "Configuration prête. Les clés existantes sont conservées.\n";
