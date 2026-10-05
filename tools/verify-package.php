<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$archive = $argv[1] ?? $root . '/dist/woocommerce-wallet-0.1.0-dev.zip';
$zip = new ZipArchive();
if ($zip->open($archive) !== true) { throw new RuntimeException('Package cannot be opened.'); }
$manifest = json_decode($zip->getFromName('woocommerce-wallet/source-manifest.json') ?: '', true, 32, JSON_THROW_ON_ERROR);
if (($manifest['development'] ?? null) !== true || ($manifest['production_certified'] ?? null) !== false) { throw new RuntimeException('Package status is invalid.'); }
$expected = ['woocommerce-wallet/source-manifest.json'];
foreach ($manifest['files'] as $path => $hash) {
    if (!is_string($path) || str_contains($path, '..') || str_contains($path, '\\') || preg_match('~(?:^|/)\.~', $path) || !preg_match('~^(?:src/|assets/|docs/|languages/|woocommerce-wallet\.php$|uninstall\.php$|autoload\.php$|LICENSE$|README\.md$|readme\.txt$|CHANGELOG\.md$|composer\.(?:json|lock)$)~D', $path)) { throw new RuntimeException('Unexpected package path.'); }
    $name = 'woocommerce-wallet/' . $path;
    $body = $zip->getFromName($name);
    if ($body === false || !hash_equals($hash, hash('sha256', $body))) { throw new RuntimeException('Package hash mismatch: ' . $path); }
    $expected[] = $name;
}
$actual = [];
for ($index = 0; $index < $zip->numFiles; ++$index) { $actual[] = $zip->getNameIndex($index); }
sort($actual);
sort($expected);
if ($actual !== $expected) { throw new RuntimeException('Package includes unmanifested files.'); }
$checksum = trim((string) file_get_contents($archive . '.sha256'));
if ($checksum !== hash_file('sha256', $archive) . '  ' . basename($archive)) { throw new RuntimeException('Archive checksum mismatch.'); }
echo 'PASS package manifest, all file hashes, checksum and source allowlist (' . count($actual) . " entries)\n";
