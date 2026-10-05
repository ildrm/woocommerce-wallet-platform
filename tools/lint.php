<?php
declare(strict_types=1);

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
$failed = false;
$count = 0;
foreach ($iterator as $file) {
    $path = $file->getPathname();
    if ($file->getExtension() !== 'php' || preg_match('~/(vendor|node_modules|dist|\.[^/]+)/~', $path)) {
        continue;
    }
    ++$count;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        echo implode("\n", $output) . "\n";
        $failed = true;
    }
    $output = [];
}
echo "$count PHP files checked\n";
exit($failed ? 1 : 0);
