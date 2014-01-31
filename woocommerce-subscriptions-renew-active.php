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
		add_filter( 'woocommerce_my_account_my_subscriptions_actions', __CLASS__ . '::my_account_my_subscriptions_actions', 10, 4 );

		// Adds flag to cart item data that this is a renewal of an active subscription
		add_filter( 'woocommerce_order_again_cart_item_data', __CLASS__ . '::cart_item_data', 10, 3 );

		// Add the next payment date to the end of the subscription to clarify when the new rate will be charged
		add_filter( 'woocommerce_cart_total_ex_tax', __CLASS__ . '::customise_subscription_price_string', 12 );
		add_filter( 'woocommerce_cart_total', __CLASS__ . '::customise_subscription_price_string', 12 );

		// Need to update the renewal date of the subscription upon payment completion
		add_action( 'woocommerce_payment_complete', __CLASS__ . '::maybe_update_next_payment_date', 10, 1 );

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
					'name' => __( 'Renew', 'woocommerce-subscriptions' )
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

		if ( !WC_Subscriptions_Renewal_Order::is_renewal( $order_id, array( 'order_role' => 'child' ) ) || !WC_Subscriptions_Order::requires_manual_renewal( WC_Subscriptions_Renewal_Order::get_parent_order( $order_id ) ) ) {
			return;			
		}

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

	public static function calculate_next_payment_date( $order, $product_id, $type = 'mysql' ) {
		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		$next_payment_date = WC_Subscriptions_Order::get_next_payment_date( $order, $product_id );
		return WC_Subscriptions_Order::calculate_next_payment_date( $order->id, $product_id, $type, $next_payment_date );
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

}

WC_Subscriptions_Renew_Active::init();