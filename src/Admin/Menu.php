<?php
/**
 * Admin menu registration.
 *
 * @package AB\AccelerateWPDebugger
 * @since   1.0
 */

declare(strict_types=1);

namespace AB\AccelerateWPDebugger\Admin;

defined('ABSPATH') || exit;

/**
 * Adds the Tools → AccelerateWP Debugger submenu.
 */
final class Menu {

    public const PAGE_SLUG = 'awpd-debugger';

    /**
     * Hook id used by Assets to gate enqueues.
     */
    public const HOOK_SUFFIX = 'tools_page_' . self::PAGE_SLUG;

    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_page']);
    }

    public static function register_page(): void {
        add_management_page(
            __('AccelerateWP Debugger', 'acceleratewp-debugger'),
            __('AccelerateWP Debugger', 'acceleratewp-debugger'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void {
        require AWPD_PLUGIN_DIR . 'templates/admin/page.php';
    }
}
