<?php
/**
 * Admin page template — mount point for the Svelte admin app.
 *
 * @package AB\AccelerateWPDebugger
 */

defined('ABSPATH') || exit;
?>
<div class="wrap">
    <h1 class="wp-heading-inline">
        <?php esc_html_e('AccelerateWP Debugger', 'acceleratewp-debugger'); ?>
        <span class="awpd-version">v<?php echo esc_html(AWPD_VERSION); ?></span>
    </h1>
    <hr class="wp-header-end" />
    <div id="awpd-admin-app" class="wpea"></div>
</div>
