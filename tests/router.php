<?php
declare(strict_types=1);

$root = __DIR__ . '/.runtime/wordpress';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && is_file($root . $path)) {
    return false;
}
require $root . '/index.php';
