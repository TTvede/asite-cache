<?php
/**
 * Asite Cache – drop-in (kopieres til wp-content/advanced-cache.php af pluginet).
 *
 * Leverer en færdig HTML-side fra disken, FØR WordPress starter, når alle betingelser holder.
 * Ellers gør den ingenting, og WordPress kører normalt (pluginet gemmer så siden bagefter).
 * Konservativ: er der tvivl, caches der ikke.
 *
 * ASITE_CACHE_DROPIN – markør, så pluginet kan genkende sin egen fil.
 */
if (!defined('ABSPATH')) { return; }
define('ASITE_CACHE_DROPIN', '1.0.0');

(function () {
    $conf_file = WP_CONTENT_DIR . '/asite-cache/config.php';
    if (!is_file($conf_file)) { return; }
    $c = include $conf_file;
    if (!is_array($c) || empty($c['enabled'])) { return; }

    // Kun almindelige sidevisninger.
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && $method !== 'HEAD') { return; }
    if (PHP_SAPI === 'cli' || defined('DOING_CRON') || defined('WP_CLI')) { return; }

    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') { return; }
    if (preg_match('#(^|/)(wp-admin|wp-login\.php|wp-json|xmlrpc\.php|wp-cron\.php|feed)(/|$)#', $path)) { return; }
    if (preg_match('#\.(php|xml|txt|json|xsl)$#i', $path)) { return; }

    // Forespørgselsstreng: kun kendte sporingsparametre må ignoreres.
    $query = parse_url($uri, PHP_URL_QUERY);
    if ($query) {
        parse_str($query, $q);
        foreach (array_keys($q) as $k) {
            if (!in_array($k, $c['ignore_query'], true)) { return; }
        }
    }

    // Cookies, der betyder personligt indhold (login, kurv, adgangskode m.m.).
    foreach (array_keys($_COOKIE) as $name) {
        foreach ($c['bypass_cookies'] as $prefix) {
            if (strpos($name, $prefix) === 0) { return; }
        }
    }

    // Udelukkede stier (fra indstillingerne).
    foreach ($c['exclude'] as $ex) {
        if ($ex !== '' && strpos($path, $ex) === 0) { return; }
    }

    $host = strtolower(preg_replace('/[^a-z0-9.\-]/i', '', preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '' || !in_array($host, $c['hosts'], true)) { return; }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $rel   = trim(preg_replace('#/+#', '/', $path), '/');
    if (strpos($rel, '..') !== false) { return; }
    $file = WP_CONTENT_DIR . '/cache/asite-cache/' . $host . ($rel !== '' ? '/' . $rel : '') . '/index' . ($https ? '-https' : '') . '.html';

    if (!is_file($file)) { header('X-Asite-Cache: MISS'); return; }
    $age = time() - (int) @filemtime($file);
    if ($age > (int) $c['lifespan']) { header('X-Asite-Cache: EXPIRED'); return; }

    header('Content-Type: text/html; charset=UTF-8');
    header('X-Asite-Cache: HIT');
    header('Cache-Control: no-cache, must-revalidate, max-age=0');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', time() - $age) . ' GMT');
    if ($method === 'HEAD') { exit; }
    readfile($file);
    exit;
})();
