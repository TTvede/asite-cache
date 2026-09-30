<?php
/**
 * Asite Cache – interface: side under Værktøjer og knap i admin-linjen.
 */
if (!defined('ABSPATH')) { exit; }

class Asite_Cache_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_bar_menu', [__CLASS__, 'admin_bar'], 100);
        add_action('admin_post_asite_cache', [__CLASS__, 'handle']);
        add_action('admin_notices', [__CLASS__, 'notices']);
    }

    public static function cap() { return current_user_can('manage_options'); }

    public static function menu() {
        add_management_page('Asite Cache', 'Asite Cache', 'manage_options', 'asite-cache', [__CLASS__, 'page']);
    }

    private static function action_url($do, $extra = []) {
        return wp_nonce_url(add_query_arg(array_merge(['action' => 'asite_cache', 'do' => $do], $extra), admin_url('admin-post.php')), 'asite_cache_' . $do);
    }

    public static function admin_bar($bar) {
        if (!self::cap()) { return; }
        $s = Asite_Cache::settings();
        $bar->add_node(['id' => 'asite-cache', 'title' => 'Cache' . (empty($s['enabled']) ? ' (fra)' : ''), 'href' => admin_url('tools.php?page=asite-cache')]);
        $bar->add_node(['parent' => 'asite-cache', 'id' => 'asite-cache-alt', 'title' => 'Tøm hele cachen', 'href' => self::action_url('purge_all', ['back' => rawurlencode(self::current_url())])]);
        if (!is_admin()) {
            $bar->add_node(['parent' => 'asite-cache', 'id' => 'asite-cache-side', 'title' => 'Tøm denne side', 'href' => self::action_url('purge_url', ['url' => rawurlencode(self::current_url()), 'back' => rawurlencode(self::current_url())])]);
        }
    }

    private static function current_url() {
        return (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    public static function handle() {
        if (!self::cap()) { wp_die('Ingen adgang.'); }
        $do = sanitize_key($_REQUEST['do'] ?? '');
        check_admin_referer('asite_cache_' . $do);
        $msg = '';
        switch ($do) {
            case 'purge_all':
                Asite_Cache::purge_all(); $msg = 'Hele cachen er tømt.'; break;
            case 'purge_url':
                $u = esc_url_raw(rawurldecode((string) ($_GET['url'] ?? '')));
                if ($u) { Asite_Cache::purge_url($u); } $msg = 'Siden er tømt fra cachen.'; break;
            case 'preload':
                Asite_Cache::schedule_preload(); $msg = 'Forvarmning er startet i baggrunden.'; break;
            case 'save':
                $s = Asite_Cache::settings();
                $ny = [
                    'enabled'      => !empty($_POST['enabled']),
                    'lifespan'     => max(0, (int) ($_POST['lifespan_hours'] ?? 10)) * HOUR_IN_SECONDS,   // 0 = uendelig
                    'preload'      => !empty($_POST['preload']),
                    'exclude'      => sanitize_textarea_field(wp_unslash($_POST['exclude'] ?? '')),
                    'remove_emoji' => !empty($_POST['remove_emoji']),
                ];
                if ($ny['enabled'] && Asite_Cache::dropin_status() !== 'ours') {
                    $r = Asite_Cache::install_dropin();
                    if (is_wp_error($r)) { $ny['enabled'] = false; $msg = 'Cachen blev IKKE slået til: ' . $r->get_error_message(); }
                }
                update_option(Asite_Cache::OPT, array_merge($s, $ny));
                Asite_Cache::write_config();
                Asite_Cache::purge_all();
                $msg = $msg ?: ('Indstillingerne er gemt' . ($ny['enabled'] ? ' – cachen er slået til.' : ' – cachen er slået fra.'));
                break;
        }
        set_transient('asite_cache_msg_' . get_current_user_id(), $msg, 60);
        $back = isset($_GET['back']) ? esc_url_raw(rawurldecode((string) $_GET['back'])) : admin_url('tools.php?page=asite-cache');
        wp_safe_redirect($back ?: admin_url('tools.php?page=asite-cache'));
        exit;
    }

    public static function notices() {
        $k = 'asite_cache_msg_' . get_current_user_id();
        if ($m = get_transient($k)) {
            delete_transient($k);
            echo '<div class="notice notice-info is-dismissible"><p><strong>Asite Cache:</strong> ' . esc_html($m) . '</p></div>';
        }
        if (self::cap() && Asite_Cache::dropin_status() === 'foreign' && !empty(Asite_Cache::settings()['enabled'])) {
            echo '<div class="notice notice-error"><p><strong>Asite Cache:</strong> en anden cache bruger advanced-cache.php. Deaktivér den, og gem Asite Cache igen.</p></div>';
        }
    }

    public static function page() {
        if (!self::cap()) { return; }
        $s = Asite_Cache::settings();
        $st = Asite_Cache::stats();
        $drop = Asite_Cache::dropin_status();
        $status = empty($s['enabled']) ? ['Fra', '#b32d2e'] : ($drop === 'ours' && defined('WP_CACHE') && WP_CACHE ? ['Til', '#008a20'] : ['Til, men drop-in mangler', '#b32d2e']);
        ?>
        <div class="wrap">
            <h1>Asite Cache</h1>
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0">
                <?php foreach ([['Status', '<span style="color:' . $status[1] . '">' . esc_html($status[0]) . '</span>'], ['Sider i cachen', (int) $st['pages']], ['Størrelse', esc_html(size_format($st['bytes']) ?: '0 B')], ['I forvarmningskø', (int) $st['queue']]] as $k): ?>
                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 18px;min-width:150px">
                        <div style="font-size:12px;color:#646970;text-transform:uppercase;letter-spacing:.04em"><?php echo esc_html($k[0]); ?></div>
                        <div style="font-size:22px;font-weight:600;margin-top:4px"><?php echo $k[1]; // allerede escapet ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(self::action_url('purge_all')); ?>">Tøm hele cachen</a>
                <a class="button" href="<?php echo esc_url(self::action_url('preload')); ?>">Forvarm nu</a>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:8px 20px;max-width:760px">
                <input type="hidden" name="action" value="asite_cache"><input type="hidden" name="do" value="save">
                <?php wp_nonce_field('asite_cache_save'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Cache</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($s['enabled'])); ?>> Slå sidecache til</label>
                        <p class="description">Anonyme besøgende får en færdig side. Indloggede, kurv, kasse og konto caches aldrig.</p></td></tr>
                    <tr><th scope="row">Levetid</th><td><input type="number" min="0" max="8760" name="lifespan_hours" value="<?php echo (int) ($s['lifespan'] / HOUR_IN_SECONDS); ?>" style="width:80px"> timer
                        <p class="description"><strong>0 = uendelig</strong> – siderne fornyes kun, når indhold ændres (anbefalet). Brug fx 10 timer, hvis sitet har formularer med WordPress-sikkerhedsnøgler (nonce), som udløber efter 12–24 timer.</p></td></tr>
                    <tr><th scope="row">Forvarmning</th><td><label><input type="checkbox" name="preload" value="1" <?php checked(!empty($s['preload'])); ?>> Hent siderne fra sitemappet igen efter tømning</label></td></tr>
                    <tr><th scope="row">Cache ikke</th><td><textarea name="exclude" rows="6" cols="50" class="code"><?php echo esc_textarea($s['exclude']); ?></textarea>
                        <p class="description">Én sti pr. linje, fx <code>/kontakt/</code>. Alt, der starter med stien, udelades.</p></td></tr>
                    <tr><th scope="row">Ekstra</th><td><label><input type="checkbox" name="remove_emoji" value="1" <?php checked(!empty($s['remove_emoji'])); ?>> Fjern WordPress' emoji-script (sparer ca. 20 KB pr. side)</label></td></tr>
                </table>
                <?php submit_button('Gem indstillinger'); ?>
            </form>
            <p style="color:#646970">Drop-in: <?php echo esc_html(['ours' => 'Asite Cache', 'foreign' => 'en anden cache (fx WP Rocket)', 'none' => 'ingen'][$drop]); ?> · WP_CACHE: <?php echo defined('WP_CACHE') && WP_CACHE ? 'til' : 'fra'; ?> · Version <?php echo esc_html(ASITE_CACHE_VERSION); ?></p>
        </div>
        <?php
    }
}
