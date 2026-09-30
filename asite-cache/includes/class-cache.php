<?php
/**
 * Asite Cache – kerne: indstillinger, gem af sider, tømning, drop-in og vedligehold.
 */
if (!defined('ABSPATH')) { exit; }

class Asite_Cache {

    const OPT = 'asite_cache_settings';

    /** Standardindstillinger. Ændres i Værktøjer → Asite Cache. */
    public static function defaults() {
        return [
            'enabled'        => false,
            'lifespan'       => 10 * HOUR_IN_SECONDS,   // kortere end WordPress' nonce-levetid (formularer)
            'preload'        => true,
            'exclude'        => "/wp-admin/\n/kurv/\n/kassen/\n/min-konto/\n/cart/\n/checkout/\n/my-account/",
            'remove_emoji'   => false,
        ];
    }

    public static function settings() {
        return wp_parse_args((array) get_option(self::OPT, []), self::defaults());
    }

    public static function cache_dir() {
        return WP_CONTENT_DIR . '/cache/asite-cache';
    }

    public static function init() {
        $s = self::settings();
        add_action('template_redirect', [__CLASS__, 'start_buffer'], -999);

        // Automatisk tømning.
        add_action('transition_post_status', [__CLASS__, 'on_post_status'], 10, 3);
        add_action('save_post', [__CLASS__, 'on_save_post'], 20, 2);
        add_action('deleted_post', [__CLASS__, 'purge_all']);
        add_action('wp_update_nav_menu', [__CLASS__, 'purge_all']);
        add_action('switch_theme', [__CLASS__, 'purge_all']);
        add_action('customize_save_after', [__CLASS__, 'purge_all']);
        add_action('activated_plugin', [__CLASS__, 'purge_all']);
        add_action('deactivated_plugin', [__CLASS__, 'purge_all']);
        add_action('upgrader_process_complete', [__CLASS__, 'purge_all']);
        add_action('comment_post', [__CLASS__, 'on_comment']);
        add_action('edit_comment', [__CLASS__, 'on_comment']);
        add_action('updated_option', [__CLASS__, 'on_option'], 10, 1);
        // WooCommerce: lager og produktændringer.
        add_action('woocommerce_product_set_stock', [__CLASS__, 'on_wc_product']);
        add_action('woocommerce_variation_set_stock', [__CLASS__, 'on_wc_product']);

        // Hold drop-in'en ajour efter en plugin-opdatering (kun hvis den er vores).
        add_action('admin_init', [__CLASS__, 'sync_dropin']);
        add_action('upgrader_process_complete', [__CLASS__, 'sync_dropin'], 20);

        // Vedligehold og forvarmning.
        add_action('asite_cache_cleanup', [__CLASS__, 'cleanup_expired']);
        add_action('asite_cache_preload', [__CLASS__, 'preload_batch']);
        if (!wp_next_scheduled('asite_cache_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'asite_cache_cleanup');
        }

        if (!empty($s['remove_emoji'])) {
            remove_action('wp_head', 'print_emoji_detection_script', 7);
            remove_action('wp_print_styles', 'print_emoji_styles');
            remove_action('admin_print_scripts', 'print_emoji_detection_script');
            remove_action('admin_print_styles', 'print_emoji_styles');
        }
    }

    /* ---------------------------------------------------------------- gem */

    public static function start_buffer() {
        if (!self::should_cache_request()) { return; }
        ob_start([__CLASS__, 'save_buffer']);
    }

    private static function should_cache_request() {
        $s = self::settings();
        if (empty($s['enabled'])) { return false; }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { return false; }
        if (is_user_logged_in() || is_admin() || is_preview() || is_search() || is_404() || is_feed() || is_trackback() || post_password_required()) { return false; }
        if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) { return false; }
        if (function_exists('is_cart') && (is_cart() || is_checkout() || is_account_page())) { return false; }
        if (!empty($_GET)) {
            foreach (array_keys($_GET) as $k) {
                if (!in_array($k, self::ignore_query(), true)) { return false; }
            }
        }
        foreach (array_keys($_COOKIE) as $name) {
            foreach (self::bypass_cookies() as $p) {
                if (strpos($name, $p) === 0) { return false; }
            }
        }
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (preg_match('#\.[a-z0-9]{2,5}$#i', $path)) { return false; }
        foreach (self::excludes() as $ex) {
            if ($ex !== '' && strpos($path, $ex) === 0) { return false; }
        }
        return true;
    }

    public static function save_buffer($html) {
        if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) { return $html; }
        if (http_response_code() !== 200) { return $html; }
        if (strlen($html) < 255 || stripos($html, '</html>') === false) { return $html; }
        // Kun rigtige HTML-sider: skal begynde med <!doctype eller <html (ikke XML-sitemaps, feeds, JSON).
        if (!preg_match('/^\s*(<!doctype html|<html)/i', $html)) { return $html; }
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (preg_match('#\.[a-z0-9]{2,5}$#i', $path)) { return $html; }   // stier med filendelse (sitemap.xml, robots.txt …)
        foreach (headers_list() as $h) {
            if (stripos($h, 'Location:') === 0) { return $html; }           // viderestilling
            if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'text/html') === false) { return $html; }
            // En side, der starter en kurv/session eller logger ind, må ikke gemmes. Øvrige cookies (fx statistik) er ufarlige.
            if (stripos($h, 'Set-Cookie:') === 0 && preg_match('/woocommerce|wordpress_logged_in|wp_woocommerce_session|edd_/i', $h)) { return $html; }
        }
        $file = self::file_for_url(self::current_url());
        if (!$file) { return $html; }
        wp_mkdir_p(dirname($file));
        $stamp = "\n<!-- Asite Cache: gemt " . gmdate('Y-m-d H:i:s') . " UTC -->";
        $tmp = $file . '.' . wp_generate_password(6, false) . '.tmp';
        if (@file_put_contents($tmp, $html . $stamp) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
        return $html;
    }

    private static function current_url() {
        $scheme = is_ssl() ? 'https' : 'http';
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return $scheme . '://' . preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '') . $path;
    }

    /** Filen, en given URL gemmes i. Samme regel som drop-in'en. */
    public static function file_for_url($url) {
        $p = wp_parse_url($url);
        if (empty($p['host'])) { return false; }
        $host = strtolower(preg_replace('/[^a-z0-9.\-]/i', '', $p['host']));
        $rel = trim(preg_replace('#/+#', '/', $p['path'] ?? '/'), '/');
        if (strpos($rel, '..') !== false) { return false; }
        $https = (($p['scheme'] ?? '') === 'https');
        return self::cache_dir() . '/' . $host . ($rel !== '' ? '/' . $rel : '') . '/index' . ($https ? '-https' : '') . '.html';
    }

    /* ------------------------------------------------------------- tømning */

    public static function purge_url($url) {
        foreach (['http', 'https'] as $sch) {
            $f = self::file_for_url(set_url_scheme($url, $sch));
            if ($f && is_file($f)) { @unlink($f); }
        }
    }

    public static function purge_all() {
        self::rrmdir(self::cache_dir());
        self::schedule_preload();
    }

    public static function on_post_status($new, $old, $post) {
        if ($new === 'publish' || $old === 'publish') { self::purge_post($post); }
    }

    public static function on_save_post($post_id, $post) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) { return; }
        // Breakdance: skabeloner, header, footer og globale blokke påvirker mange sider.
        if (in_array($post->post_type, ['breakdance_template', 'breakdance_header', 'breakdance_footer', 'breakdance_block', 'breakdance_popup', 'wp_template', 'wp_template_part', 'wp_navigation'], true)) {
            self::purge_all();
            return;
        }
        if ($post->post_status === 'publish') { self::purge_post($post); }
    }

    public static function purge_post($post) {
        $post = get_post($post);
        if (!$post || !is_post_type_viewable($post->post_type)) { return; }
        $urls = [get_permalink($post), home_url('/')];
        if ($post->post_parent) { $urls[] = get_permalink($post->post_parent); }
        if ($archive = get_post_type_archive_link($post->post_type)) { $urls[] = $archive; }
        if ((int) get_option('page_for_posts')) { $urls[] = get_permalink((int) get_option('page_for_posts')); }
        foreach (get_object_taxonomies($post->post_type) as $tax) {
            foreach ((array) wp_get_post_terms($post->ID, $tax, ['fields' => 'ids']) as $tid) {
                $l = get_term_link((int) $tid, $tax);
                if (!is_wp_error($l)) { $urls[] = $l; }
            }
        }
        foreach (array_unique(array_filter($urls)) as $u) { self::purge_url($u); }
        self::schedule_preload(array_unique(array_filter($urls)));
    }

    public static function on_comment($comment_id) {
        $c = get_comment($comment_id);
        if ($c) { self::purge_post($c->comment_post_ID); }
    }

    public static function on_option($option) {
        // Globale indstillinger, der ændrer alle sider (Breakdance-design, forside, permalinks, sitenavn).
        static $globale = ['blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'permalink_structure', 'breakdance_global_settings_json_string', 'breakdance_variables_json_string', 'breakdance_classes_json_string'];
        if (in_array($option, $globale, true) || strpos($option, 'breakdance_global') === 0) {
            self::purge_all();
        }
    }

    public static function on_wc_product($product) {
        $id = is_object($product) && method_exists($product, 'get_id') ? $product->get_id() : (int) $product;
        if ($id) { self::purge_post($id); }
    }

    /* ----------------------------------------------------------- forvarmning */

    public static function schedule_preload($urls = null) {
        $s = self::settings();
        if (empty($s['enabled']) || empty($s['preload'])) { return; }
        $queue = $urls ? array_values($urls) : self::sitemap_urls();
        $old = (array) get_option('asite_cache_preload_queue', []);
        update_option('asite_cache_preload_queue', array_values(array_unique(array_merge($old, $queue))), false);
        if (!wp_next_scheduled('asite_cache_preload')) {
            wp_schedule_single_event(time() + 15, 'asite_cache_preload');
        }
    }

    public static function preload_batch() {
        $queue = (array) get_option('asite_cache_preload_queue', []);
        $batch = array_splice($queue, 0, 10);
        update_option('asite_cache_preload_queue', $queue, false);
        foreach ($batch as $u) {
            wp_remote_get($u, ['timeout' => 20, 'blocking' => true, 'sslverify' => false, 'cookies' => [], 'headers' => ['User-Agent' => 'Asite Cache forvarmning']]);
        }
        if ($queue) { wp_schedule_single_event(time() + 5, 'asite_cache_preload'); }
    }

    /** Alle offentlige URL'er – fra sitemappet, ellers fra udgivne sider og indlæg. */
    public static function sitemap_urls() {
        $urls = [];
        foreach (['/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap.xml'] as $sm) {
            $r = wp_remote_get(home_url($sm), ['timeout' => 15, 'sslverify' => false]);
            if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) { continue; }
            $body = wp_remote_retrieve_body($r);
            preg_match_all('#<loc>([^<]+)</loc>#', $body, $m);
            foreach ($m[1] as $loc) {
                $loc = html_entity_decode(trim($loc));
                if (preg_match('#\.xml$#', $loc)) {
                    $r2 = wp_remote_get($loc, ['timeout' => 15, 'sslverify' => false]);
                    if (!is_wp_error($r2)) {
                        preg_match_all('#<loc>([^<]+)</loc>#', wp_remote_retrieve_body($r2), $m2);
                        foreach ($m2[1] as $l2) { if (!preg_match('#\.(jpe?g|png|webp|avif|gif|svg|pdf)$#i', $l2)) { $urls[] = html_entity_decode(trim($l2)); } }
                    }
                } elseif (!preg_match('#\.(jpe?g|png|webp|avif|gif|svg|pdf)$#i', $loc)) {
                    $urls[] = $loc;
                }
            }
            if ($urls) { break; }
        }
        if (!$urls) {
            foreach (get_posts(['post_type' => ['page', 'post'], 'post_status' => 'publish', 'numberposts' => 300, 'fields' => 'ids']) as $id) { $urls[] = get_permalink($id); }
            $urls[] = home_url('/');
        }
        return array_values(array_unique($urls));
    }

    /* ------------------------------------------------------------ vedligehold */

    public static function cleanup_expired() {
        $life = (int) self::settings()['lifespan'];
        $dir = self::cache_dir();
        if ($life <= 0 || !is_dir($dir)) { return; }   // 0 = uendelig: kun tømning ved ændringer
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->isFile() && (time() - $f->getMTime()) > $life) { @unlink($f->getPathname()); }
            elseif ($f->isDir()) { @rmdir($f->getPathname()); }   // fjernes kun, hvis den er tom
        }
    }

    public static function stats() {
        $n = 0; $bytes = 0; $dir = self::cache_dir();
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) { if ($f->isFile() && substr($f->getFilename(), -5) === '.html') { $n++; $bytes += $f->getSize(); } }
        }
        return ['pages' => $n, 'bytes' => $bytes, 'queue' => count((array) get_option('asite_cache_preload_queue', []))];
    }

    private static function rrmdir($dir) {
        if (!is_dir($dir) || strpos(realpath($dir), realpath(WP_CONTENT_DIR . '/cache')) !== 0) { return; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    }

    /* ----------------------------------------------------- konfiguration/drop-in */

    public static function ignore_query() {
        return ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', '_ga', 'ref'];
    }

    public static function bypass_cookies() {
        return ['wordpress_logged_in_', 'wordpress_sec_', 'wp-postpass_', 'comment_author_', 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_', 'edd_items_in_cart', 'wordpressuser_'];
    }

    public static function excludes() {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) self::settings()['exclude']))));
    }

    /** Skriver config.php, som drop-in'en læser (ingen database før WordPress starter). */
    public static function write_config() {
        $s = self::settings();
        $hosts = array_values(array_unique(array_filter([wp_parse_url(home_url(), PHP_URL_HOST), wp_parse_url(site_url(), PHP_URL_HOST)])));
        $conf = [
            'enabled'        => (bool) $s['enabled'],
            'lifespan'       => (int) $s['lifespan'],
            'exclude'        => self::excludes(),
            'ignore_query'   => self::ignore_query(),
            'bypass_cookies' => self::bypass_cookies(),
            'hosts'          => array_map('strtolower', $hosts),
        ];
        wp_mkdir_p(WP_CONTENT_DIR . '/asite-cache');
        $php = "<?php\n// Skrevet af Asite Cache – ret i Værktøjer → Asite Cache, ikke her.\nreturn " . var_export($conf, true) . ";\n";
        file_put_contents(WP_CONTENT_DIR . '/asite-cache/config.php', $php);
        if (function_exists('opcache_invalidate')) { @opcache_invalidate(WP_CONTENT_DIR . '/asite-cache/config.php', true); }
    }

    /** Status for drop-in: 'ours', 'foreign' (fx WP Rocket), 'none'. */
    public static function dropin_status() {
        $f = WP_CONTENT_DIR . '/advanced-cache.php';
        if (!is_file($f)) { return 'none'; }
        return strpos((string) file_get_contents($f), 'ASITE_CACHE_DROPIN') !== false ? 'ours' : 'foreign';
    }

    public static function install_dropin() {
        if (self::dropin_status() === 'foreign') {
            return new WP_Error('foreign', 'En anden cache (fx WP Rocket) bruger advanced-cache.php. Deaktivér den først.');
        }
        if (!@copy(ASITE_CACHE_DIR . 'includes/advanced-cache.php', WP_CONTENT_DIR . '/advanced-cache.php')) {
            return new WP_Error('copy', 'Kunne ikke skrive wp-content/advanced-cache.php (rettigheder).');
        }
        return self::set_wp_cache(true);
    }

    public static function sync_dropin() {
        if (self::dropin_status() !== 'ours') { return; }
        $kilde = ASITE_CACHE_DIR . 'includes/advanced-cache.php';
        $maal  = WP_CONTENT_DIR . '/advanced-cache.php';
        if (is_file($kilde) && md5_file($kilde) !== md5_file($maal)) { @copy($kilde, $maal); }
    }

    public static function remove_dropin() {
        if (self::dropin_status() === 'ours') { @unlink(WP_CONTENT_DIR . '/advanced-cache.php'); }
    }

    /** Sætter define('WP_CACHE', true) i wp-config.php, hvis den mangler. Rører ikke andet. */
    public static function set_wp_cache($on) {
        if ($on && defined('WP_CACHE') && WP_CACHE) { return true; }
        $cfg = file_exists(ABSPATH . 'wp-config.php') ? ABSPATH . 'wp-config.php' : dirname(ABSPATH) . '/wp-config.php';
        if (!is_writable($cfg)) {
            return new WP_Error('wpconfig', "Tilføj selv define( 'WP_CACHE', true ); i wp-config.php (filen er skrivebeskyttet).");
        }
        $src = file_get_contents($cfg);
        if (preg_match("/define\\(\\s*['\"]WP_CACHE['\"]/", $src)) {
            $src = preg_replace("/define\\(\\s*['\"]WP_CACHE['\"]\\s*,\\s*[^)]*\\)\\s*;/", "define( 'WP_CACHE', true ); // Asite Cache", $src, 1);
        } else {
            $src = preg_replace('/^<\?php\s*/', "<?php\ndefine( 'WP_CACHE', true ); // Asite Cache\n", $src, 1);
        }
        return file_put_contents($cfg, $src) !== false ? true : new WP_Error('wpconfig', 'Kunne ikke skrive wp-config.php.');
    }
}
