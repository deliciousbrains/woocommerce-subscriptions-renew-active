<?php
/**
 * Plugin Name: WooCommerce Subscriptions Renew Active
 * Plugin URI: http://deliciousbrains.com
 * Description: Extends WooCommerce Subscriptions plugin to enable manual renewals of active subscriptions
 * Version: 1.0
 * Author: Delicious Brains
 * Author URI: http://deliciousbrains.com
 * Requires at least: 3.8
 * Tested up to: 3.8
 *
 * !!!TESTING WARNING!!!
 * I wasted a lot of time in testing this, hopefully this warning saves time.
 * If you renew an active subscription, then use WP Crontrol to "Run Now" the
 * scheduled subscription renewal to simulate a subscription expiring, 
 * and then pay for it, the next payment date will get messed up. This is because 
 * when the subscription is reactivated @see WC_Subscriptions_Manager::reactivate_subscription()
 * it uses the latest payment completed date + interval to calculate the next
 * payment date. In a real-world situation where the subscription exipres because
 * time is up, setting the next payment date to the latest payment completed date + interval
 * would be correct.
 *
 */
class WC_Subscriptions_Renew_Active {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0
	 */
	public static function init() {

		// Allows active subscriptions to be renewed
		add_filter( 'woocommerce_can_subscription_be_renewed', __CLASS__ . '::can_subscription_be_renewed', 10, 4 );

		// Sets the role to 'child' on Renew buttons in My Account
		add_filter( 'woocommerce_my_account_my_subscriptions_actions', __CLASS__ . '::my_account_my_subscriptions_actions', 10, 2 );

		// Adds flag to cart item data that this is a renewal of an active subscription
		add_filter( 'woocommerce_order_again_cart_item_data', __CLASS__ . '::cart_item_data', 10, 3 );

		// Add the next payment date to the end of the subscription to clarify when the new rate will be charged
		add_filter( 'woocommerce_cart_total_ex_tax', __CLASS__ . '::customise_subscription_price_string', 12 );
		add_filter( 'woocommerce_cart_total', __CLASS__ . '::customise_subscription_price_string', 12 );
		
		// When created a renewal order, add meta if it's for rerew on a activate subscription
		add_action( 'woocommerce_subscriptions_renewal_order_created', __CLASS__ . '::add_order_meta', 10, 4 );

		// Need to update the renewal date of the subscription upon payment completion
		add_action( 'woocommerce_order_status_pending_to_completed', __CLASS__ . '::maybe_update_next_payment_date', 10, 1 );
		add_action( 'woocommerce_order_status_on-hold_to_completed', __CLASS__ . '::maybe_update_next_payment_date', 10, 1 );

		// Update ugly order item name for renewals
		add_action( 'woocommerce_new_order_item', __CLASS__ . '::new_order_item', 10, 3 );
	}


	/**
	 * Removes the unneccessary "purchased in Order #1111" from the order item name
	 *
	 * @param int $item_id ID of the order item
	 * @param array $item The item name and type data
	 * @param array $order_id ID of the order
	 * @since 1.0
	 */
	function new_order_item( $item_id, $item, $order_id ) {
		if ( !isset( $item['order_item_type'] ) || 'line_item' !== $item['order_item_type'] ) {
			return;
		}

		if ( !isset( $item['order_item_name'] ) || !preg_match( '@^(Renewal of .*?) purchased in Order .*$@', $item['order_item_name'], $matches ) ) {
			return;
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'woocommerce_order_items',
			array(
				'order_item_name' 	=> $matches[1]
			),
			array(
				'order_item_id'		=> $item_id
			)
		);
	}

	/**
	 * Replaces the renew action in My Account for active subscriptions
	 * setting the role to child
	 *
	 * @param array $all_actions All the subscription actions passed from the hook, indexed by subscription key
	 * @param array $subscriptions An array of subscriptions, indexed by subscription key
	 * @since 1.0
	 */
	public static function my_account_my_subscriptions_actions( $all_actions, $subscriptions ) {

		foreach ( $all_actions as $subscription_key => $actions ) {
			if ( isset( $actions['renew'] ) && 'active' === $subscriptions[$subscription_key]['status'] ) {
				$all_actions[$subscription_key]['renew'] = array(
					'url'  => WC_Subscriptions_Renewal_Order::get_users_renewal_link( $subscription_key, 'child' ),
					'name' => __( 'Early Renew', 'woocommerce-subscriptions' )
				);
			}
		}

		return $all_actions;
	}

	/**
	 * Records manual payment of a renewal order against a subscription.
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.0
	 */
	public static function maybe_update_next_payment_date( $order_id ) {

		if ( ! WC_Subscriptions_Renewal_Order::is_renewal( $order_id, array( 'order_role' => 'child' ) ) || ! WC_Subscriptions_Order::requires_manual_renewal( WC_Subscriptions_Renewal_Order::get_parent_order( $order_id ) ) ) {
			return;			
		}
		
		// Just do when the Renew Order is for activate subscription
		$is_renew_activate_subscription = get_post_meta( $order_id, '_is_renew_activate_subscription', true );
		if ( $is_renew_activate_subscription != 'true' ) return;
		
		// Don't update many time the Next Payment Date for Original Order
		$updated_next_payment_date_original_order = get_post_meta( $order_id, '_updated_next_payment_date_original_order', true );
		if ( $updated_next_payment_date_original_order == 'true' ) return;

		$child_order = new WC_Order( $order_id );

		$parent_order = WC_Subscriptions_Renewal_Order::get_parent_order( $child_order );

		$order_items = $child_order->get_items();

		foreach ( $order_items as $item ) {

			$product_id = WC_Subscriptions_Order::get_items_product_id( $item );
			if ( WC_Subscriptions_Order::is_item_subscription( $parent_order, $product_id ) ) {

				$subscription_key = WC_Subscriptions_Manager::get_subscription_key( $parent_order->id, $product_id );
				$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

				$next_payment_date = self::calculate_next_payment_date( $subscription['order_id'], $subscription['product_id'], 'timestamp' );
				WC_Subscriptions_Manager::set_next_payment_date( $subscription_key, $parent_order->customer_user, $next_payment_date );

				WC_Subscriptions_Manager::update_wp_cron_lock( $subscription_key, $next_payment_date - gmdate( 'U' ), $parent_order->customer_user );
			}
		}
		
		// Set this Order has updated the Next Payment Date for Original Order 
		update_post_meta( $order_id, '_updated_next_payment_date_original_order', 'true' );
		
		// Allow 3rd party plugin can hook
		do_action( 'woocommerce_renewed_active_subscription', $parent_order, $subscription_key, $next_payment_date );

	}

	/**
	 * Check if the cart includes a request to renew an active subscription
	 *
	 * @return bool Returns true if any item in the cart is a active subscription renewal request, otherwise, false.
	 * @since 1.0
	 */
	public static function cart_contains_active_subscription_renewal() {
		global $woocommerce;

		$cart_contains_active_subscription_renewal = false;

		if ( isset( $woocommerce->cart ) ) {
			foreach ( $woocommerce->cart->get_cart() as $cart_item_key => $cart_item ) {
				if ( isset( $cart_item['subscription_renewal']['is_active_subscription'] ) ) {
					$cart_contains_active_subscription_renewal = $cart_item['subscription_renewal'];
					break;
				}
			}
		}

		return $cart_contains_active_subscription_renewal;
	}

	/**
	 * Add the next payment date to the end of the subscription to clarify when the new rate will be charged
	 *
	 * @since 1.0
	 */
	public static function customise_subscription_price_string( $subscription_string ) {
		$renewal_details = self::cart_contains_active_subscription_renewal();

		if ( false !== $renewal_details && 0 != $renewal_details['first_payment_timestamp'] )
			$subscription_string = sprintf( __( '%s %s(next payment %s)%s', 'woocommerce-subscriptions' ), $subscription_string, '<small>', date_i18n( woocommerce_date_format(), $renewal_details['first_payment_timestamp'] ), '</small>' );

		return $subscription_string;
	}
	
	/**
	 * If the order being generated is for renew a activate subscription, keep a record of some of renew
	 * routines meta against the order.
	 *
	 * @since 1.1
	 */
	public static function add_order_meta( $renewal_order, $original_order, $product_id, $new_order_role ) {
		$subscription_renewal = self::cart_contains_active_subscription_renewal();
		if ( $subscription_renewal != false ) {
			update_post_meta( $renewal_order->id, '_is_renew_activate_subscription', 'true' );
		}
	}
	
	
	public static function calculate_next_payment_date( $order, $product_id, $type = 'mysql' ) {
		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		$next_payment_date = WC_Subscriptions_Order::get_next_payment_date( $order, $product_id );
		return self::early_renew_calculate_next_payment_date( $order, $product_id, $type, $next_payment_date );
	}

	/**
	 * Adds flag to cart item data that this is a renewal of an active subscription
	 *
	 * @param array $cart_item_data Current value passed in by the filter
	 * @param stdObject $item Holds item data
	 * @param WC_Order $original_order The WC_Order object
	 */
	public static function cart_item_data( $cart_item_data, $item, $original_order ) {

		if ( !isset( $_GET['renew_subscription'] ) || !isset( $cart_item_data['subscription_renewal'] ) ) {
			return $cart_item_data;
		}

		$subscription_key = $_GET['renew_subscription'];
		$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

		if ( 'active' === $subscription['status'] ) {
			$cart_item_data['subscription_renewal']['is_active_subscription'] = true;
			$cart_item_data['subscription_renewal']['first_payment_timestamp'] = self::calculate_next_payment_date( $subscription['order_id'], $subscription['product_id'], 'timestamp' );
		}

		return $cart_item_data;
	}


	/**
	 * Overrides the check if a given subscription can be renewed, 
	 * allowing active subscriptions to be renewed
	 *
	 * @param bool $subscription_can_be_renewed Current value passed in by the filter
	 * @param WC_Subscriptions_Manager $subscription The WC_Subscriptions_Manager object
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @param int $user_id The ID of the user who owns the subscriptions
	 * @since 1.0
	 */
	public static function can_subscription_be_renewed( $subscription_can_be_renewed, $subscription, $subscription_key, $user_id ) {

		// If we've already determined it can be renewed, don't argue
		if ( $subscription_can_be_renewed ) {
			return $subscription_can_be_renewed;
		}

		if ( empty( $subscription ) ) {
			$subscription_can_be_renewed = false;
		} else {

			$renewal_orders = get_posts( array(
				'meta_key'    => '_original_order', 
				'meta_value'  => $subscription['order_id'], 
				'post_type'   => 'shop_order', 
				'post_parent' => 0 
				)
			);

			if ( empty( $renewal_orders ) && ! empty( $subscription['completed_payments'] ) && in_array( $subscription['status'], array( 'active', 'cancelled', 'expired', 'trash', 'failed' ) ) ) {
				$subscription_can_be_renewed = true;
			} else {
				$subscription_can_be_renewed = false;
			}

		}

		return $subscription_can_be_renewed;
	}
	
	public static function early_renew_calculate_next_payment_date( $order, $product_id, $type = 'mysql', $from_date = '' ) {

		if ( ! is_object( $order ) )
			$order = new WC_Order( $order );

		$from_date_arg = $from_date;

		$subscription              = WC_Subscriptions_Manager::get_subscription( WC_Subscriptions_Manager::get_subscription_key( $order->id, $product_id ) );
		$subscription_period       = WC_Subscriptions_Order::get_subscription_period( $order, $product_id );
		$subscription_interval     = WC_Subscriptions_Order::get_subscription_interval( $order, $product_id );
		$subscription_trial_length = WC_Subscriptions_Order::get_subscription_trial_length( $order, $product_id );
		$subscription_trial_period = WC_Subscriptions_Order::get_subscription_trial_period( $order, $product_id );

		$trial_end_time   = ( ! empty( $subscription['trial_expiry_date'] ) ) ? $subscription['trial_expiry_date'] : WC_Subscriptions_Product::get_trial_expiration_date( $product_id, $order->order_date );
		$trial_end_time   = strtotime( $trial_end_time );

			// We have a timestamp
			if ( ! empty( $from_date ) && is_numeric( $from_date ) )
				$from_date = date( 'Y-m-d H:i:s', $from_date );

			if ( empty( $from_date ) ) {

				if ( ! empty( $subscription['completed_payments'] ) ) {
					$from_date = array_pop( $subscription['completed_payments'] );
					$add_failed_payments = true;
				} else if ( ! empty ( $subscription['start_date'] ) ) {
					$from_date = $subscription['start_date'];
					$add_failed_payments = true;
				} else {
					$from_date = gmdate( 'Y-m-d H:i:s' );
					$add_failed_payments = false;
				}

				$failed_payment_count = WC_Subscriptions_Order::get_failed_payment_count( $order, $product_id );

				// Maybe take into account any failed payments
				if ( true === $add_failed_payments && $failed_payment_count > 0 ) {
					$failed_payment_periods = $failed_payment_count * $subscription_interval;
					$from_timestamp = strtotime( $from_date );

					if ( 'month' == $subscription_period )
						$from_date = date( 'Y-m-d H:i:s', WC_Subscriptions::add_months( $from_timestamp, $failed_payment_periods ) );
					else // Safe to just add a month
						$from_date = date( 'Y-m-d H:i:s', strtotime( "+ {$failed_payment_periods} {$subscription_period}", $from_timestamp ) );
				}
			}

			$from_timestamp = strtotime( $from_date );

			if ( 'month' == $subscription_period ) // Workaround potential PHP issue
				$next_payment_timestamp = WC_Subscriptions::add_months( $from_timestamp, $subscription_interval );
			else
				$next_payment_timestamp = strtotime( "+ {$subscription_interval} {$subscription_period}", $from_timestamp );

			// Make sure the next payment is in the future
			$i = 1;
			while ( $next_payment_timestamp < gmdate( 'U' ) && $i < 30 ) {
				if ( 'month' == $subscription_period ) {
					$next_payment_timestamp = WC_Subscriptions::add_months( $next_payment_timestamp, $subscription_interval );
				} else { // Safe to just add a month
					$next_payment_timestamp = strtotime( "+ {$subscription_interval} {$subscription_period}", $next_payment_timestamp );
				}
				$i = $i + 1;
			}

		// If the subscription has an expiry date and the next billing period comes after the expiration, return 0
		if ( isset( $subscription['expiry_date'] ) && 0 != $subscription['expiry_date'] && ( $next_payment_timestamp + 120 ) > strtotime( $subscription['expiry_date'] ) )
			$next_payment_timestamp =  0;

		$next_payment = ( 'mysql' == $type && 0 != $next_payment_timestamp ) ? date( 'Y-m-d H:i:s', $next_payment_timestamp ) : $next_payment_timestamp;

		return apply_filters( 'woocommerce_subscriptions_calculated_next_payment_date', $next_payment, $order, $product_id, $type, $from_date, $from_date_arg );
	}

}

WC_Subscriptions_Renew_Active::init();