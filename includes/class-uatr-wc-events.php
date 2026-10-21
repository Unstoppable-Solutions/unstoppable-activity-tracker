<?php
/**
 * WooCommerce event tracking (conditional).
 *
 * @package Unstoppable_Activity_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks WooCommerce actions into the activity logger.
 *
 * This class is only wired up when WooCommerce is active — on non-WC sites
 * the plugin behaves identically to before, with zero added overhead.
 */
class UATR_WC_Events {

	/**
	 * Logger reference.
	 *
	 * @var UATR_Logger
	 */
	private $logger;

	/**
	 * Constructor — only registers hooks if WooCommerce is loaded.
	 *
	 * @param UATR_Logger $logger Logger instance.
	 */
	public function __construct( UATR_Logger $logger ) {
		$this->logger = $logger;

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$this->register_hooks();
	}

	/**
	 * Wire up all WooCommerce hooks.
	 */
	private function register_hooks() {
		// Orders.
		add_action( 'woocommerce_new_order', array( $this, 'on_order_created' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 10, 2 );
		add_action( 'woocommerce_delete_order', array( $this, 'on_order_deleted' ), 10, 1 );

		// Products — these fire on the WP post hooks because WC products are a CPT.
		// We filter in the handler to only act on 'product' post type.
		add_action( 'woocommerce_new_product', array( $this, 'on_product_created' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_updated' ), 10, 1 );
		add_action( 'woocommerce_delete_product', array( $this, 'on_product_deleted' ), 10, 1 );

		// Coupons.
		add_action( 'woocommerce_new_coupon', array( $this, 'on_coupon_created' ), 10, 2 );
		add_action( 'woocommerce_applied_coupon', array( $this, 'on_coupon_applied' ), 10, 1 );
	}

	/* ============================================================
	 * Order handlers
	 * ============================================================ */

	/**
	 * New order created.
	 *
	 * @param int $order_id Order ID.
	 */
	public function on_order_created( $order_id ) {
		$order = $this->get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$total    = $order->get_total();
		$currency = $order->get_currency();

		$this->logger->log(
			UATR_Logger::EVENT_WC_ORDER_CREATED,
			'wc_order',
			'created',
			sprintf(
				/* translators: 1: order ID, 2: formatted total */
				__( 'New WooCommerce order #%1$d created (%2$s)', 'unstoppable-activity-tracker' ),
				(int) $order_id,
				$this->format_money( $total, $currency )
			),
			array(
				'meta' => array(
					'order_id'       => (int) $order_id,
					'order_total'    => (float) $total,
					'order_currency' => (string) $currency,
					'order_status'   => (string) $order->get_status(),
				),
			)
		);
	}

	/**
	 * Order status changed.
	 *
	 * @param int      $order_id   Order ID.
	 * @param string   $old_status Old status (without wc- prefix).
	 * @param string   $new_status New status (without wc- prefix).
	 * @param WC_Order $order      Order object.
	 */
	public function on_order_status_changed( $order_id, $old_status, $new_status, $order ) {
		// Severity: elevate status changes toward refunded/failed/cancelled.
		$severity_map = array(
			'refunded'   => 'warning',
			'failed'     => 'warning',
			'cancelled'  => 'notice',
			'on-hold'    => 'notice',
			'processing' => 'info',
			'completed'  => 'info',
			'pending'    => 'info',
		);
		$severity = isset( $severity_map[ $new_status ] ) ? $severity_map[ $new_status ] : 'info';

		$this->logger->log(
			UATR_Logger::EVENT_WC_ORDER_STATUS,
			'wc_order',
			'status_changed',
			sprintf(
				/* translators: 1: order ID, 2: old status, 3: new status */
				__( 'WooCommerce order #%1$d status changed: %2$s → %3$s', 'unstoppable-activity-tracker' ),
				(int) $order_id,
				$old_status,
				$new_status
			),
			array(
				'severity' => $severity,
				'meta'     => array(
					'order_id'   => (int) $order_id,
					'old_status' => (string) $old_status,
					'new_status' => (string) $new_status,
				),
			)
		);
	}

	/**
	 * Order refunded.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function on_order_refunded( $order_id, $refund_id ) {
		$order  = $this->get_order( $order_id );
		$refund = $this->get_order( $refund_id );

		$refund_amount   = $refund ? $refund->get_amount() : 0;
		$refund_currency = $order ? $order->get_currency() : '';

		$this->logger->log(
			UATR_Logger::EVENT_WC_ORDER_REFUNDED,
			'wc_order',
			'refunded',
			sprintf(
				/* translators: 1: order ID, 2: refund amount */
				__( 'WooCommerce order #%1$d refunded (%2$s)', 'unstoppable-activity-tracker' ),
				(int) $order_id,
				$this->format_money( $refund_amount, $refund_currency )
			),
			array(
				'severity' => 'warning',
				'meta'     => array(
					'order_id'      => (int) $order_id,
					'refund_id'     => (int) $refund_id,
					'refund_amount' => (float) $refund_amount,
					'currency'      => (string) $refund_currency,
				),
			)
		);
	}

	/**
	 * Order deleted.
	 *
	 * @param int $order_id Order ID.
	 */
	public function on_order_deleted( $order_id ) {
		$this->logger->log(
			UATR_Logger::EVENT_WC_ORDER_DELETED,
			'wc_order',
			'deleted',
			sprintf(
				/* translators: %d: order ID */
				__( 'WooCommerce order #%d deleted', 'unstoppable-activity-tracker' ),
				(int) $order_id
			),
			array(
				'severity' => 'warning',
				'meta'     => array( 'order_id' => (int) $order_id ),
			)
		);
	}

	/* ============================================================
	 * Product handlers
	 * ============================================================ */

	/**
	 * New product created.
	 *
	 * @param int $product_id Product ID.
	 */
	public function on_product_created( $product_id ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$name    = $product ? $product->get_name() : (string) $product_id;

		$this->logger->log(
			UATR_Logger::EVENT_WC_PRODUCT_CREATED,
			'wc_product',
			'created',
			sprintf(
				/* translators: %s: product name */
				__( 'Created WooCommerce product "%s"', 'unstoppable-activity-tracker' ),
				$name
			),
			array(
				'post_title' => $name,
				'post_type'  => 'product',
				'meta'       => array( 'product_id' => (int) $product_id ),
			)
		);
	}

	/**
	 * Product updated.
	 *
	 * @param int $product_id Product ID.
	 */
	public function on_product_updated( $product_id ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$name    = $product ? $product->get_name() : (string) $product_id;

		$this->logger->log(
			UATR_Logger::EVENT_WC_PRODUCT_UPDATED,
			'wc_product',
			'updated',
			sprintf(
				/* translators: %s: product name */
				__( 'Updated WooCommerce product "%s"', 'unstoppable-activity-tracker' ),
				$name
			),
			array(
				'post_title' => $name,
				'post_type'  => 'product',
				'meta'       => array( 'product_id' => (int) $product_id ),
			)
		);
	}

	/**
	 * Product deleted.
	 *
	 * @param int $product_id Product ID.
	 */
	public function on_product_deleted( $product_id ) {
		$this->logger->log(
			UATR_Logger::EVENT_WC_PRODUCT_DELETED,
			'wc_product',
			'deleted',
			sprintf(
				/* translators: %d: product ID */
				__( 'Deleted WooCommerce product #%d', 'unstoppable-activity-tracker' ),
				(int) $product_id
			),
			array(
				'severity' => 'warning',
				'post_type' => 'product',
				'meta'     => array( 'product_id' => (int) $product_id ),
			)
		);
	}

	/* ============================================================
	 * Coupon handlers
	 * ============================================================ */

	/**
	 * New coupon created.
	 *
	 * @param int    $coupon_id Coupon ID.
	 * @param object $coupon    WC coupon object (unused — read via wc_get_coupon_code).
	 */
	public function on_coupon_created( $coupon_id, $coupon = null ) {
		$code = function_exists( 'wc_get_coupon_code_by_id' ) ? wc_get_coupon_code_by_id( $coupon_id ) : '';
		if ( '' === $code ) {
			$post = get_post( $coupon_id );
			$code = $post ? $post->post_title : (string) $coupon_id;
		}

		$this->logger->log(
			UATR_Logger::EVENT_WC_COUPON_CREATED,
			'wc_coupon',
			'created',
			sprintf(
				/* translators: %s: coupon code */
				__( 'Created WooCommerce coupon "%s"', 'unstoppable-activity-tracker' ),
				$code
			),
			array(
				'severity' => 'notice',
				'meta'     => array(
					'coupon_id'   => (int) $coupon_id,
					'coupon_code' => (string) $code,
				),
			)
		);
	}

	/**
	 * Coupon applied to cart.
	 *
	 * @param string $coupon_code Coupon code.
	 */
	public function on_coupon_applied( $coupon_code ) {
		$this->logger->log(
			UATR_Logger::EVENT_WC_COUPON_USED,
			'wc_coupon',
			'used',
			sprintf(
				/* translators: %s: coupon code */
				__( 'WooCommerce coupon "%s" applied to a cart', 'unstoppable-activity-tracker' ),
				$coupon_code
			),
			array(
				'meta' => array( 'coupon_code' => (string) $coupon_code ),
			)
		);
	}

	/* ============================================================
	 * Helpers
	 * ============================================================ */

	/**
	 * Fetch an order object (or null on failure).
	 *
	 * @param int $order_id Order ID.
	 * @return WC_Order|null
	 */
	private function get_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		return $order ? $order : null;
	}

	/**
	 * Format a money value, with graceful fallback if WC helpers aren't ready.
	 *
	 * @param float|int $amount   Amount.
	 * @param string    $currency Currency code.
	 * @return string
	 */
	private function format_money( $amount, $currency ) {
		if ( function_exists( 'wc_price' ) ) {
			$html = wc_price( $amount, array( 'currency' => $currency ) );
			return trim( wp_strip_all_tags( $html ) );
		}
		return number_format( (float) $amount, 2 ) . ' ' . (string) $currency;
	}
}
