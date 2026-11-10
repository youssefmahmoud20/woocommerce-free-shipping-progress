<?php
namespace WCFSP;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private $file;

	public function __construct( $file ) {
		$this->file = $file;
	}

	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_shortcode( 'wc_free_shipping_progress', array( $this, 'mount' ) );
		add_action( 'wc_ajax_wcfsp_progress', array( $this, 'endpoint' ) );
		add_action( 'woocommerce_before_cart', array( $this, 'output' ) );
		add_action( 'woocommerce_cart_is_empty', array( $this, 'output' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'output' ) );
		add_action( 'woocommerce_before_mini_cart', array( $this, 'output' ) );
		add_filter( 'render_block', array( $this, 'block' ), 10, 2 );
	}

	public function assets() {
		wp_enqueue_style( 'wcfsp', plugins_url( 'assets/progress.css', $this->file ), array(), '1.0.0' );
		wp_enqueue_script( 'wcfsp', plugins_url( 'assets/progress.js', $this->file ), array( 'jquery', 'wp-data' ), '1.0.0', true );
		wp_localize_script( 'wcfsp', 'wcfspConfig', array(
			'url' => \WC_AJAX::get_endpoint( 'wcfsp_progress' ),
			'loading' => __( 'Checking free-shipping progress…', 'wc-free-shipping-progress' ),
			'error' => __( 'Shipping progress could not be refreshed. Please try again.', 'wc-free-shipping-progress' ),
			'progressLabel' => __( 'Progress toward the free-shipping minimum', 'wc-free-shipping-progress' ),
		) );
	}

	public function mount( $attributes = array(), $content = null, $tag = '' ) {
		// Cached pages contain no customer-specific totals; JS hydrates every mount.
		return '<section class="wcfsp" aria-label="' . esc_attr__( 'Free-shipping progress', 'wc-free-shipping-progress' ) . '"><div class="wcfsp__content" role="status" aria-live="polite" aria-atomic="true"><p>' . esc_html__( 'Checking free-shipping progress…', 'wc-free-shipping-progress' ) . '</p></div><noscript>' . esc_html__( 'Enable JavaScript to see live progress. Shipping eligibility is shown at checkout.', 'wc-free-shipping-progress' ) . '</noscript></section>';
	}

	public function output() {
		if ( apply_filters( 'wcfsp_auto_display', true ) ) {
			echo $this->mount(); // Static, escaped markup above.
		}
	}

	public function block( $html, $block ) {
		if ( ! is_admin() && in_array( $block['blockName'] ?? '', array( 'woocommerce/cart', 'woocommerce/checkout' ), true ) && apply_filters( 'wcfsp_auto_display', true ) ) {
			return $this->mount() . $html;
		}
		return $html;
	}

	public function endpoint() {
		nocache_headers();
		// Same-origin, session-scoped read; accepts no customer ID, cart or settings input.
		// No nonce needed for this read-only endpoint (guest pages can be cached).
		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		try {
			WC()->cart->calculate_totals();
			wp_send_json_success( array( 'rows' => ( new Progress() )->snapshot() ) );
		} catch ( \Throwable $error ) {
			wc_get_logger()->error( $error->getMessage(), array( 'source' => 'wcfsp' ) );
			wp_send_json_error( array( 'message' => __( 'Unable to calculate shipping progress.', 'wc-free-shipping-progress' ) ), 500 );
		}
	}
}
