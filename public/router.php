<?php

// Router for the PHP development server. Production serves public/index.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path && preg_match('#^/assets/[a-zA-Z0-9._-]+$#', $path) && is_file(__DIR__ . $path)) {
    return false;
}
require __DIR__ . '/index.php';
