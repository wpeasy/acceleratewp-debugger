<?php
/**
 * Plugin bootstrap.
 *
 * @package AB\AccelerateWPDebugger
 * @since   1.0
 */

declare(strict_types=1);

namespace AB\AccelerateWPDebugger;

use AB\AccelerateWPDebugger\Admin\Assets;
use AB\AccelerateWPDebugger\Admin\Menu;
use AB\AccelerateWPDebugger\REST\PreloadController;

defined('ABSPATH') || exit;

/**
 * Registers plugin services with WordPress.
 */
final class Plugin {

    /**
     * Wire up hooks.
     */
    public static function init(): void {
        Menu::init();
        Assets::init();
        PreloadController::init();
    }
}
