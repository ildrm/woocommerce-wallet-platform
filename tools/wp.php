<?php
declare(strict_types=1);

// Keep obsolete WP-CLI dependencies' PHP 8.5 deprecations out of test logs only.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$binary = getenv('WALLET_WP_CLI_BINARY') ?: '';
if ($binary === '') {
    foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
        $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'wp';
        if (is_file($candidate) && is_readable($candidate)) {
            $binary = $candidate;
            break;
        }
    }
}
if ($binary === '' || !is_file($binary)) {
    fwrite(STDERR, "Install WP-CLI or set WALLET_WP_CLI_BINARY to its PHP/PHAR path.\n");
    exit(1);
}
require $binary;
