<?php
/**
 * Plugin Name:       AccelerateWP Debugger
 * Plugin URI:        https://wpeasy.au/
 * Description:       Svelte-based admin interface for managing AccelerateWP Preloader.
 * Version:           1.0.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            WPEasy
 * Author URI:        https://wpeasy.au/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       acceleratewp-debugger
 *
 * @package AB\AccelerateWPDebugger
 */

declare(strict_types=1);

namespace AB\AccelerateWPDebugger;

defined('ABSPATH') || exit;

define('AWPD_VERSION', '1.0.1');
define('AWPD_PLUGIN_FILE', __FILE__);
define('AWPD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AWPD_PLUGIN_URL', plugin_dir_url(__FILE__));

$awpd_autoload = AWPD_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($awpd_autoload)) {
    require_once $awpd_autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'AB\\AccelerateWPDebugger\\';
        $len    = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        $relative = substr($class, $len);
        $file     = AWPD_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

add_action('plugins_loaded', [Plugin::class, 'init']);
