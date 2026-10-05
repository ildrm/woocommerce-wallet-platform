<?php
declare(strict_types=1);

$root = dirname(__DIR__);
if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('Packaging requires the PHP zip extension.');
}
$files = ['woocommerce-wallet.php', 'uninstall.php', 'autoload.php', 'LICENSE', 'README.md', 'readme.txt', 'CHANGELOG.md', 'composer.json', 'composer.lock'];
foreach (['src', 'assets', 'docs', 'languages'] as $directory) {
    if (!is_dir($root . '/' . $directory)) { continue; }
    $visible = new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS), static fn (SplFileInfo $file): bool => !str_starts_with($file->getFilename(), '.'));
    $iterator = new RecursiveIteratorIterator($visible);
    foreach ($iterator as $file) {
        if ($file->isLink()) { throw new RuntimeException('Package cannot contain symbolic links.'); }
        if ($file->isFile()) { $files[] = substr($file->getPathname(), strlen($root) + 1); }
    }
}
sort($files, SORT_STRING);
$directory = $root . '/dist';
if (!is_dir($directory) && !mkdir($directory, 0755, true)) { throw new RuntimeException('Package directory creation failed.'); }
$archive = $directory . '/woocommerce-wallet-0.1.0-dev.zip';
$zip = new ZipArchive();
if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Package archive creation failed.'); }
$hashes = [];
$epoch = (int) (getenv('SOURCE_DATE_EPOCH') ?: 315532800);
foreach ($files as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path) || is_link($path)) { throw new RuntimeException('Missing/unsafe package source: ' . $file); }
    $name = 'woocommerce-wallet/' . $file;
    if (!$zip->addFile($path, $name) || !$zip->setMtimeName($name, $epoch) || !$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16)) { throw new RuntimeException('Package file write failed.'); }
    $hashes[$file] = hash_file('sha256', $path);
}
$manifest = json_encode(['version' => '0.1.0', 'development' => true, 'production_certified' => false, 'files' => $hashes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (!$zip->addFromString('woocommerce-wallet/source-manifest.json', $manifest) || !$zip->setMtimeName('woocommerce-wallet/source-manifest.json', $epoch) || !$zip->setExternalAttributesName('woocommerce-wallet/source-manifest.json', ZipArchive::OPSYS_UNIX, 0100644 << 16)) { throw new RuntimeException('Package manifest write failed.'); }
if (!$zip->close()) { throw new RuntimeException('Package archive close failed.'); }
if (file_put_contents($directory . '/source-manifest.json', $manifest) === false || file_put_contents($archive . '.sha256', hash_file('sha256', $archive) . '  ' . basename($archive) . "\n") === false) { throw new RuntimeException('Package checksum write failed.'); }
echo $archive . "\n" . count($files) . " source files; development package only.\n";
