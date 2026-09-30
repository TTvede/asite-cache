<?php
// Asite Cache – afinstallation: fjern indstillinger, konfiguration og cache. Rører ikke wp-config.php.
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
delete_option('asite_cache_settings');
delete_option('asite_cache_preload_queue');
delete_site_transient('asite_cache_latest');
$dropin = WP_CONTENT_DIR . '/advanced-cache.php';
if (is_file($dropin) && strpos((string) file_get_contents($dropin), 'ASITE_CACHE_DROPIN') !== false) { @unlink($dropin); }
foreach ([WP_CONTENT_DIR . '/cache/asite-cache', WP_CONTENT_DIR . '/asite-cache'] as $dir) {
    if (!is_dir($dir)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir);
}
