<?php
/**
 * Admin asset enqueues.
 *
 * @package AB\AccelerateWPDebugger
 * @since   1.0
 */

declare(strict_types=1);

namespace AB\AccelerateWPDebugger\Admin;

defined('ABSPATH') || exit;

/**
 * Loads the Svelte admin bundle on the debugger page only.
 */
final class Assets {

    public static function init(): void {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function enqueue(string $hook): void {
        if ($hook !== Menu::HOOK_SUFFIX) {
            return;
        }

        $base = AWPD_PLUGIN_URL;

        wp_enqueue_script(
            'awpd-shared',
            $base . 'assets/dist/shared.js',
            [],
            self::asset_version('assets/dist/shared.js'),
            false
        );

        $data = [
            'apiUrl'  => esc_url_raw(rest_url('awpd/v1')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'version' => AWPD_VERSION,
            'homeUrl' => esc_url_raw(home_url('/')),
        ];

        wp_add_inline_script(
            'awpd-shared',
            'window.AWPDData = ' . wp_json_encode($data) . ';',
            'before'
        );

        wp_enqueue_script_module(
            'awpd-admin',
            $base . 'assets/dist/admin/main.js',
            [],
            self::asset_version('assets/dist/admin/main.js')
        );

        wp_enqueue_style(
            'awpd-admin',
            $base . 'assets/dist/admin/style.css',
            [],
            self::asset_version('assets/dist/admin/style.css')
        );
    }

    /**
     * Use the built file's mtime so each rebuild busts the browser cache.
     * Falls back to the plugin version if the file is missing.
     */
    private static function asset_version(string $relative_path): string {
        $absolute = AWPD_PLUGIN_DIR . $relative_path;
        if (file_exists($absolute)) {
            $mtime = filemtime($absolute);
            if ($mtime !== false) {
                return (string) $mtime;
            }
        }
        return AWPD_VERSION;
    }
}
