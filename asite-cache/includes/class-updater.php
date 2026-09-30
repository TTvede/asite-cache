<?php
/**
 * Asite Cache – opdatering fra GitHub-releases.
 *
 * Nye versioner udgives som en GitHub-release med tag »vX.Y.Z« og en vedhæftet asite-cache.zip.
 * Privat repo: sæt define('ASITE_CACHE_GITHUB_TOKEN', '…') i wp-config.php (læseadgang til repoet).
 */
if (!defined('ABSPATH')) { exit; }

class Asite_Cache_Updater {

    const REPO = 'TTvede/asite-cache';
    const SLUG = 'asite-cache/asite-cache.php';

    public static function init() {
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check']);
        add_filter('plugins_api', [__CLASS__, 'info'], 10, 3);
        add_filter('http_request_args', [__CLASS__, 'auth'], 10, 2);
        add_filter('upgrader_source_selection', [__CLASS__, 'fix_folder'], 10, 4);
    }

    private static function token() {
        return defined('ASITE_CACHE_GITHUB_TOKEN') ? ASITE_CACHE_GITHUB_TOKEN : '';
    }

    private static function latest() {
        $cached = get_site_transient('asite_cache_latest');
        if ($cached !== false) { return $cached; }
        $r = wp_remote_get('https://api.github.com/repos/' . self::REPO . '/releases/latest', ['timeout' => 10, 'headers' => ['Accept' => 'application/vnd.github+json']]);
        $rel = null;
        if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
            $d = json_decode(wp_remote_retrieve_body($r), true);
            foreach ((array) ($d['assets'] ?? []) as $a) {
                if (($a['name'] ?? '') === 'asite-cache.zip') {
                    $rel = ['version' => ltrim((string) $d['tag_name'], 'v'), 'zip' => self::token() ? $a['url'] : $a['browser_download_url'], 'notes' => (string) ($d['body'] ?? ''), 'url' => (string) ($d['html_url'] ?? '')];
                }
            }
        }
        set_site_transient('asite_cache_latest', $rel ?: [], 6 * HOUR_IN_SECONDS);
        return $rel ?: [];
    }

    public static function check($t) {
        if (empty($t->checked)) { return $t; }
        $rel = self::latest();
        if ($rel && version_compare($rel['version'], ASITE_CACHE_VERSION, '>')) {
            $t->response[self::SLUG] = (object) ['slug' => 'asite-cache', 'plugin' => self::SLUG, 'new_version' => $rel['version'], 'package' => $rel['zip'], 'url' => $rel['url']];
        }
        return $t;
    }

    public static function info($res, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'asite-cache') { return $res; }
        $rel = self::latest();
        return (object) ['name' => 'Asite Cache', 'slug' => 'asite-cache', 'version' => $rel['version'] ?? ASITE_CACHE_VERSION, 'author' => 'Asite', 'sections' => ['changelog' => nl2br(esc_html($rel['notes'] ?? ''))], 'download_link' => $rel['zip'] ?? ''];
    }

    /** Token på kald til GitHub-API'et (kun ved privat repo). */
    public static function auth($args, $url) {
        if (self::token() && strpos($url, 'api.github.com/repos/' . self::REPO) !== false) {
            $args['headers']['Authorization'] = 'Bearer ' . self::token();
            if (strpos($url, '/releases/assets/') !== false) { $args['headers']['Accept'] = 'application/octet-stream'; }
        }
        return $args;
    }

    /** Sikrer, at den udpakkede mappe hedder asite-cache. */
    public static function fix_folder($source, $remote_source, $upgrader, $hook_extra = []) {
        if (($hook_extra['plugin'] ?? '') !== self::SLUG) { return $source; }
        $want = trailingslashit($remote_source) . 'asite-cache/';
        if (trailingslashit($source) !== $want && @rename($source, $want)) { return $want; }
        return $source;
    }
}
