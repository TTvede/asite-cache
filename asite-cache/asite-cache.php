<?php
/**
 * Plugin Name: Asite Cache
 * Description: Enkel og hurtig sidecache med ét-kliks tømning. Automatisk tømning ved ændringer, forvarmning fra sitemap, WooCommerce-sikker. Opdateres fra GitHub.
 * Version: 1.0.4
 * Author: Asite
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: asite-cache
 */
if (!defined('ABSPATH')) { exit; }

define('ASITE_CACHE_VERSION', '1.0.4');
define('ASITE_CACHE_DIR', plugin_dir_path(__FILE__));

require_once ASITE_CACHE_DIR . 'includes/class-cache.php';
require_once ASITE_CACHE_DIR . 'includes/class-admin.php';
require_once ASITE_CACHE_DIR . 'includes/class-updater.php';

add_action('plugins_loaded', function () {
    Asite_Cache::init();
    Asite_Cache_Updater::init();
    Asite_Cache_Admin::init();
});

// Aktivering: skriv konfiguration (cachen er slået FRA, til den slås til i interfacet).
register_activation_hook(__FILE__, function () {
    Asite_Cache::write_config();
    // Var cachen slået til før en deaktivering, geninstalleres drop-in'en (hvis ingen anden cache bruger den).
    if (!empty(Asite_Cache::settings()['enabled']) && Asite_Cache::dropin_status() === 'none') {
        Asite_Cache::install_dropin();
    }
});

// Deaktivering: fjern vores drop-in og tøm cachen. WP_CACHE i wp-config er ufarlig uden drop-in.
register_deactivation_hook(__FILE__, function () {
    Asite_Cache::remove_dropin();
    Asite_Cache::purge_all();
    wp_clear_scheduled_hook('asite_cache_cleanup');
    wp_clear_scheduled_hook('asite_cache_preload');
});
