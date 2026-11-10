<?php
/**
 * Plugin Name: WC Free Shipping Progress
 * Description: Destination-aware free-shipping progress for classic and block carts.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.3
 * Author: Week 1 Demo
 * License: GPL-2.0-or-later
 * Text Domain: wc-free-shipping-progress
 */

defined( 'ABSPATH' ) || exit;

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'WC Free Shipping Progress needs WooCommerce to be active.', 'wc-free-shipping-progress' ) . '</p></div>';
		} );
		return;
	}
	require_once __DIR__ . '/includes/class-progress.php';
	require_once __DIR__ . '/includes/class-plugin.php';
	( new WCFSP\Plugin( __FILE__ ) )->register();
} );
