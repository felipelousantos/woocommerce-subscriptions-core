<?php
/**
 * WooCommerce Subscriptions Deprecated Functions
 *
 * Functions for handling backward compatibility with the Subscription 1.n
 * data structure and reference system (i.e. $subscription_key instead of a
 * post ID)
 *
 * @author 		Prospress
 * @category 	Core
 * @package 	WooCommerce Subscriptions/Functions
 * @version     2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Get the string key for a subscription used in Subscriptions prior to 2.0.
 *
 * Previously, a subscription key was made up of the ID of the order used to purchase the subscription, and
 * the product to which the subscription relates; however, in Subscriptions 2.0, subscriptions can actually
 * relate to multiple products (because they can contain multiple line items) and they also no longer need
 * to have an original order associated with them, to make manually adding subscriptions more accurate.
 *
 * Therefore, although the return value of this method is a string matching the key form used  inSubscriptions
 * prior to 2.0, the actual value represented is not a perfect analogue. Specifically,
 *  - if the subscription contains more than one product, only the ID of the first line item will be used in the ID
 *  - if the subscription does not contain any products, the key still be missing that component of the
 *  - if the subscription does not have an initial order, then the order ID used will be the WC_Subscription object's ID
 *
 * @param WC_Subscription $subscription An instance of WC_Subscription
 * @return string $subscription_key A subscription key in the deprecated form previously created by @see self::get_subscription_key()
 * @since 2.0
 */
function wcs_get_old_subscription_key( WC_Subscription $subscription ) {

	// Get an ID to use as the order ID
	$order_id = isset( $subscription->order->id ) ? $subscription->order->id : $subscription->id;

	// Get an ID to use as the product ID
	$subscription_items = $subscription->get_items();
	$first_item         = reset( $subscription_items );

	return $order_id . '_' . WC_Subscriptions_Order::get_items_product_id( $first_item );
}

/**
 * Return the post ID of a WC_Subscription object for the given subscription key (if one exists).
 *
 * @param string $subscription_key A subscription key in the deprecated form created by @see WC_Subscriptions_Manager::get_subscription_key()
 * @return int|null The post ID for the subscription if it can be found (i.e. an order exists) or null if no order exists for the subscription.
 * @since 2.0
 */
function wcs_get_subscription_id_from_key( $subscription_key ) {
	global $wpdb;

	$order_and_product_id = explode( '_', $subscription_key );

	$subscription_ids = array();

	// If we have an order ID and product ID, query based on that
	if ( ! empty( $order_and_product_id[0] ) && ! empty( $order_and_product_id[1] ) ) {

		$subscription_ids = $wpdb->get_col( $wpdb->prepare( "
			SELECT DISTINCT order_items.order_id FROM {$wpdb->prefix}woocommerce_order_items as order_items
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS itemmeta ON order_items.order_item_id = itemmeta.order_item_id
				LEFT JOIN {$wpdb->posts} AS posts ON order_items.order_id = posts.ID
			WHERE posts.post_type = 'shop_subscription'
				AND posts.post_parent = %d
				AND itemmeta.meta_value = %d
				AND itemmeta.meta_key IN ( '_variation_id', '_product_id' )",
				$order_and_product_id[0], $order_and_product_id[1] ) );

	} elseif ( ! empty( $order_and_product_id[0] ) ) {

		$subscription_ids = get_posts( array(
			'posts_per_page' => 1,
			'post_parent'    => $order_and_product_id[0],
			'post_status'    => 'any',
			'post_type'      => 'shop_subscription',
			'fields'         => 'ids',
		) );

	}

	return ( ! empty( $subscription_ids ) ) ? $subscription_ids[0] : null;
}

/**
 * Return an instance of a WC_Subscription object for the given subscription key (if one exists).
 *
 * @param string $subscription_key A subscription key in the deprecated form created by @see self::get_subscription_key()
 * @return WC_Subscription|null The subscription object if it can be found (i.e. an order exists) or null if no order exists for the subscription (i.e. it was manually created).
 * @since 2.0
 */
function wcs_get_subscription_from_key( $subscription_key ) {

	$subscription_id = wcs_get_subscription_id_from_key( $subscription_key );

	if ( null !== $subscription_id && is_int( $subscription_id ) ) {
		$subscription = wcs_get_subscription( $subscription_id );
	} else {
		$subscription = null;
	}

	return $subscription;
}

/**
 * Return an associative array of a given subscriptions details (if it exists) in the pre v2.0 data structure.
 *
 * @param WC_Subscription $subscription An instance of WC_Subscription
 * @return array Subscription details
 * @since 2.0
 */
function wcs_get_subscription_in_deprecated_structure( WC_Subscription $subscription ) {

	$completed_payments = array();

	if ( $subscription->get_completed_payment_count() ) {
		if ( isset( $subscription->order->paid_date ) ) {
			$completed_payments[] = $subscription->order->paid_date;
		}

		$paid_renewal_order_ids = get_posts( array(
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'post_type'      => 'shop_order',
			'orderby'        => 'date',
			'order'          => 'desc',
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_paid_date',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_subscription_renewal',
					'compare' => '=',
					'value'   => $subscription->id,
					'type'    => 'numeric'
				),
			),
		) );

		foreach( $paid_renewal_order_ids as $paid_renewal_order_id ) {
			$completed_payments[] = get_post_meta( $paid_renewal_order_id, '_paid_date', true );
		}
	}

	$items = $subscription->get_items();
	$item  = array_pop( $items );

	if ( ! empty( $item ) ) {

		$deprecated_subscription_object = array(
			'order_id'           => $subscription->order->id,
			'product_id'         => isset( $item['product_id'] ) ? $item['product_id'] : 0,
			'variation_id'       => isset( $item['variation_id'] ) ? $item['variation_id'] : 0,
			'status'             => $subscription->get_status(),

			// Subscription billing details
			'period'             => $subscription->billing_period,
			'interval'           => $subscription->billing_interval,
			'length'             => null, // Subscriptions no longer have a length, just an expiration date

			// Subscription dates
			'start_date'         => $subscription->get_date( 'start' ),
			'expiry_date'        => $subscription->get_date( 'end' ),
			'end_date'           => $subscription->has_ended() ? $subscription->get_date( 'end' ) : 0,
			'trial_expiry_date'  => $subscription->get_date( 'trial_end' ),

			// Payment & status change history
			'failed_payments'    => $subscription->failed_payment_count,
			'completed_payments' => $completed_payments,
			'suspension_count'   => $subscription->suspension_count,
			'last_payment_date'  => $subscription->get_date( 'last_payment' ),
		);

	} else {

		$deprecated_subscription_object = array();

	}

	return $deprecated_subscription_object;
}
