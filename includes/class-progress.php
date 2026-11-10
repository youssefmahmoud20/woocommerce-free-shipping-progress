<?php
namespace WCFSP;

defined( 'ABSPATH' ) || exit;

/** Reads native shipping rules. Never changes prices, settings or shipping rates. */
final class Progress {
	public function snapshot() {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return array();
		}
		if ( ! $cart->is_empty() && ! $cart->needs_shipping() ) {
			return array( $this->notice( __( 'Your cart does not require shipping.', 'wc-free-shipping-progress' ) ) );
		}
		$packages = $cart->get_shipping_packages();
		// An empty cart can have no packages. Use the current customer's destination.
		if ( ! $packages ) {
			$customer = WC()->customer;
			$packages = array( array( 'contents' => array(), 'destination' => array(
				'country'   => $customer->get_shipping_country(),
				'state'     => $customer->get_shipping_state(),
				'postcode'  => $customer->get_shipping_postcode(),
				'city'      => $customer->get_shipping_city(),
				'address'   => $customer->get_shipping_address(),
				'address_2' => $customer->get_shipping_address_2(),
			) ) );
		}
		$rows = array();
		foreach ( array_values( $packages ) as $index => $package ) {
			$zone = \WC_Shipping_Zones::get_zone_matching_package( $package );
			$candidates = array();
			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if ( 'free_shipping' === $method->id ) {
					$candidates[] = $this->evaluate( $method, $cart, $package );
				}
			}
			// Prefer an available method, then a spend-only path, then coupon-gated paths.
			usort( $candidates, function ( $a, $b ) {
				return ( $a['rank'] <=> $b['rank'] ) ?: ( $a['remaining'] <=> $b['remaining'] );
			} );
			$row = $candidates ? $candidates[0] : $this->notice( __( 'Free shipping is not configured for your current shipping destination.', 'wc-free-shipping-progress' ) );
			$row['label'] = count( $packages ) > 1
				? sprintf( __( 'Package %d', 'wc-free-shipping-progress' ), $index + 1 )
				: __( 'Free shipping', 'wc-free-shipping-progress' );
			$row['note'] = __( 'Based on your current shipping destination; eligibility may change when you enter your address.', 'wc-free-shipping-progress' );
			$rows[] = $row;
		}
		return $rows;
	}

	public function evaluate( $method, $cart, $package ) {
		$requires = (string) $method->get_option( 'requires', '' );
		$threshold = max( 0, (float) $method->get_option( 'min_amount', 0 ) );
		$total = (float) $cart->get_displayed_subtotal();
		if ( 'no' === $method->get_option( 'ignore_discounts', 'no' ) ) {
			$total -= (float) $cart->get_discount_total();
			if ( $cart->display_prices_including_tax() ) {
				$total -= (float) $cart->get_discount_tax();
			}
		}
		// Match WooCommerce's currency precision at the threshold boundary.
		$total = max( 0, \Automattic\WooCommerce\Utilities\NumberUtil::round( $total, wc_get_price_decimals() ) );
		$remaining = max( 0, $threshold - $total );
		$has_coupon = false;
		if ( in_array( $requires, array( 'coupon', 'both', 'either' ), true ) ) {
			foreach ( $cart->get_coupons() as $coupon ) {
				if ( $coupon->is_valid() && $coupon->get_free_shipping() ) {
					$has_coupon = true;
					break;
				}
			}
		}
		$available = ! $cart->is_empty() && $method->is_available( $package );
		$spend_rule = in_array( $requires, array( 'min_amount', 'either', 'both' ), true );
		$coupon_needed = in_array( $requires, array( 'coupon', 'both' ), true ) && ! $has_coupon;
		$row = array(
			'percent'   => $spend_rule ? ( $threshold > 0 ? min( 100, 100 * $total / $threshold ) : 100 ) : null,
			'remaining' => $remaining,
			'rank'      => $available ? 0 : ( $coupon_needed ? 2 : 1 ),
			'state'     => $available ? 'unlocked' : 'progress',
			'note'      => '',
			'label'     => __( 'Free shipping', 'wc-free-shipping-progress' ),
		);
		if ( $available ) {
			$row['percent'] = 100;
			$row['message'] = __( 'Free shipping is available for this package!', 'wc-free-shipping-progress' );
		} elseif ( $cart->is_empty() ) {
			$row['percent'] = $spend_rule ? 0 : null;
			$row['message'] = $spend_rule
				? sprintf( __( 'Your cart is empty. Add %s in eligible products to reach the free-shipping minimum.', 'wc-free-shipping-progress' ), $this->money( $threshold ) )
				: __( 'Your cart is empty. Add products to check free-shipping eligibility.', 'wc-free-shipping-progress' );
			if ( $coupon_needed ) {
				$row['message'] .= ' ' . __( 'A valid free-shipping coupon is also required.', 'wc-free-shipping-progress' );
			}
		} elseif ( 'coupon' === $requires && ! $has_coupon ) {
			$row['message'] = __( 'Apply a valid free-shipping coupon to unlock free shipping.', 'wc-free-shipping-progress' );
		} elseif ( $spend_rule && $remaining > 0 ) {
			$row['message'] = sprintf( __( 'Add %s more in eligible products to reach the free-shipping minimum.', 'wc-free-shipping-progress' ), $this->money( $remaining ) );
			if ( $coupon_needed ) {
				$row['message'] .= ' ' . __( 'A valid free-shipping coupon is also required.', 'wc-free-shipping-progress' );
			}
		} elseif ( $coupon_needed ) {
			$row['message'] = __( 'Minimum reached. Apply a valid free-shipping coupon to unlock free shipping.', 'wc-free-shipping-progress' );
		} else {
			// Eligibility filters may veto core rules, even after the minimum is reached.
			$row['state'] = 'notice';
			$row['message'] = __( 'Free shipping is not currently available for this package. Check your shipping details.', 'wc-free-shipping-progress' );
		}
		return $row;
	}

	private function money( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	private function notice( $message ) {
		return array( 'message' => $message, 'percent' => null, 'state' => 'notice', 'rank' => 3, 'remaining' => 0, 'label' => __( 'Free shipping', 'wc-free-shipping-progress' ), 'note' => '' );
	}
}
