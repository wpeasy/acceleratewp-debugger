<?php
/**
 * AccelerateWP Debugger uninstall handler.
 *
 * @package AB\AccelerateWPDebugger
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// Nothing to clean — this plugin reads from the WP Rocket / AccelerateWP cache table
// but does not own any data of its own.
