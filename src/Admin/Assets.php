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

        $version = AWPD_VERSION;
        $base    = AWPD_PLUGIN_URL;

        wp_enqueue_script(
            'awpd-shared',
            $base . 'assets/dist/shared.js',
            [],
            $version,
            false
        );

        $data = [
            'apiUrl'  => esc_url_raw(rest_url('awpd/v1')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'version' => $version,
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
            $version
        );

        wp_enqueue_style(
            'awpd-admin',
            $base . 'assets/dist/admin/style.css',
            [],
            $version
        );
    }
}
