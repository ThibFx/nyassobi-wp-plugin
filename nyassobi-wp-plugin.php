<?php
/**
 * Plugin Name: Nyassobi WP Plugin
 * Plugin URI: https://nyassobi.com
 * Description: Adds Nyassobi configuration options for headless usage and exposes them via WPGraphQL.
 * Version: 1.0.0
 * Author: Startingames Origins (for Nyassobi)
 * License: GPL-2.0-or-later
 *
 * @package NyassobiWPPlugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require __DIR__ . '/includes/class-nyassobi-wp-plugin.php';
require __DIR__ . '/includes/class-nyassobi-vault.php';
require __DIR__ . '/includes/class-nyassobi-membership.php';
require __DIR__ . '/includes/class-nyassobi-payment-gateways.php';
require __DIR__ . '/includes/class-nyassobi-membership-payment.php';
require __DIR__ . '/includes/class-nyassobi-conventions.php';

Nyassobi_WP_Plugin::instance();
Nyassobi_Membership::instance();
Nyassobi_Membership_Payment::instance();
Nyassobi_Conventions::instance();

// The daily purge of stale membership requests must not outlive the plugin.
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('nyassobi_membership_purge');
});
