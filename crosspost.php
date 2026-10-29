<?php
/**
 * Plugin Name: Crosspost
 * Plugin URI:  https://terrykernan.co.uk
 * Description: Crossposts WooCommerce products to a remote site via the WooCommerce REST API, with configurable data transformations.
 * Version:     1.0.0
 * Author:      Terry Kernan
 * Text Domain: crosspost
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'CROSSPOST_VERSION', '1.0.0' );
define( 'CROSSPOST_DIR', plugin_dir_path( __FILE__ ) );
define( 'CROSSPOST_URL', plugin_dir_url( __FILE__ ) );

require_once CROSSPOST_DIR . 'includes/class-crosspost-filters.php';
require_once CROSSPOST_DIR . 'includes/class-crosspost-sender.php';
require_once CROSSPOST_DIR . 'includes/class-crosspost-admin.php';

add_action( 'plugins_loaded', array( 'Crosspost', 'init' ) );

class Crosspost {

	public static function init() {
		Crosspost_Admin::init();

		// Hook into the product save to trigger crossposting.
		add_action( 'woocommerce_update_product', array( __CLASS__, 'maybe_crosspost' ), 20, 1 );
		add_action( 'woocommerce_new_product',    array( __CLASS__, 'maybe_crosspost' ), 20, 1 );
	}

	/**
	 * Triggered on product save. Only runs if crosspost is enabled for this product.
	 */
	public static function maybe_crosspost( $product_id ) {
		self::log( 'info', 'Trigger fired', array( 'product_id' => $product_id ) );

		// Allow bulk actions / programmatic triggers to skip.
		if ( apply_filters( 'crosspost_skip', false, $product_id ) ) {
			self::log( 'info', 'Skipped via crosspost_skip filter', array( 'product_id' => $product_id ) );
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			self::log( 'warning', 'Could not load product', array( 'product_id' => $product_id ) );
			return;
		}

		// Check the per-product opt-in meta or global "crosspost all" setting.
		$global_all   = get_option( 'crosspost_all_products', 'no' );
		$product_flag = get_post_meta( $product_id, '_crosspost_enabled', true );

		if ( 'yes' !== $global_all && 'yes' !== $product_flag ) {
			self::log( 'info', 'Skipped — crosspost not enabled for this product', array(
				'product_id'   => $product_id,
				'global_all'   => $global_all,
				'product_flag' => $product_flag,
			) );
			return;
		}

		self::log( 'info', 'Sending product', array(
			'product_id' => $product_id,
			'sku'        => $product->get_sku(),
			'name'       => $product->get_name(),
			'type'       => $product->get_type(),
		) );

		$sender = new Crosspost_Sender();
		$result = $sender->send( $product );

		if ( is_wp_error( $result ) ) {
			if ( 'crosspost_skipped' === $result->get_error_code() ) {
				self::log( 'warning', 'Product skipped (update-only mode): ' . $result->get_error_message(), array( 'product_id' => $product_id ) );
				delete_post_meta( $product_id, '_crosspost_last_error' );
				update_post_meta( $product_id, '_crosspost_last_status', 'skipped' );
			} else {
				self::log( 'error', 'Send failed: ' . $result->get_error_message(), array(
					'product_id' => $product_id,
					'error_code' => $result->get_error_code(),
					'error_data' => $result->get_error_data(),
				) );
				update_post_meta( $product_id, '_crosspost_last_error', $result->get_error_message() );
				update_post_meta( $product_id, '_crosspost_last_status', 'error' );
			}
		} else {
			self::log( 'info', 'Send succeeded', array(
				'product_id' => $product_id,
				'remote_id'  => $result['id'] ?? null,
			) );
			delete_post_meta( $product_id, '_crosspost_last_error' );
			update_post_meta( $product_id, '_crosspost_last_status', 'success' );
			update_post_meta( $product_id, '_crosspost_remote_id', $result['id'] ?? '' );
		}
	}

	private static function log( string $level, string $message, array $data = array() ) {
		$logger  = wc_get_logger();
		$context = array( 'source' => 'crosspost' );
		$logger->$level( $message . ( $data ? ' ' . print_r( $data, true ) : '' ), $context );
	}
}
