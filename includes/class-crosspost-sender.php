<?php
defined( 'ABSPATH' ) || exit;

class Crosspost_Sender {

	private string $remote_url;
	private string $consumer_key;
	private string $consumer_secret;
	private bool   $update_only;

	/** @var array<int,int|false> Local product ID => remote product ID (false if unresolved). */
	private array  $id_map = array();

	public function __construct() {
		$this->remote_url   = rtrim( get_option( 'crosspost_remote_url', '' ), '/' );
		$this->consumer_key    = get_option( 'crosspost_consumer_key', '' );
		$this->consumer_secret = get_option( 'crosspost_consumer_secret', '' );
		$this->update_only     = 'yes' === get_option( 'crosspost_update_only', 'no' );
	}

	/**
	 * Build the product payload, then POST or PUT to the remote WooCommerce REST API.
	 * In update-only mode the product must already exist on the remote site (matched by SKU);
	 * if it does not, a WP_Error with code crosspost_skipped is returned.
	 *
	 * @param  WC_Product $product
	 * @return array|WP_Error
	 */
	public function send( WC_Product $product ) {
		if ( empty( $this->remote_url ) || empty( $this->consumer_key ) ) {
			$this->log( 'error', 'Plugin not configured — remote URL or consumer key is missing' );
			return new WP_Error( 'crosspost_not_configured', __( 'Crosspost remote site is not configured.', 'crosspost' ) );
		}

		$payload   = Crosspost_Filters::build_payload( $product );
		$payload   = $this->translate_payload_ids( $payload );
		$remote_id = get_post_meta( $product->get_id(), '_crosspost_remote_id', true );

		$this->log( 'info', 'Prepared payload', $payload);

		$this->log( 'info', 'Preparing send', array(
			'product_id'      => $product->get_id(),
			'sku'             => $product->get_sku(),
			'cached_remote_id'=> $remote_id ?: '(none)',
			'update_only'     => $this->update_only ? 'yes' : 'no',
			'remote_url'      => $this->remote_url,
		) );

		// Resolve remote ID from SKU when we don't have a cached one.
		if ( ! $remote_id ) {
			$this->log( 'info', 'No cached remote ID — looking up by SKU: ' . $product->get_sku() );
			$remote_id = $this->find_remote_by_sku( $product->get_sku() );

			if ( $remote_id ) {
				$this->log( 'info', 'SKU lookup matched remote product', array( 'remote_id' => $remote_id ) );
				update_post_meta( $product->get_id(), '_crosspost_remote_id', $remote_id );
			} elseif ( $this->update_only ) {
				$this->log( 'warning', 'Update-only mode: no remote product with SKU "' . $product->get_sku() . '" — skipping' );
				return new WP_Error(
					'crosspost_skipped',
					sprintf(
						__( 'Skipped — no remote product with SKU "%s" found.', 'crosspost' ),
						$product->get_sku()
					)
				);
			} else {
				$this->log( 'info', 'SKU not found on remote — will create new product' );
			}
		}

		// In update-only mode, strip fields that should remain unchanged on the remote site.
		if ( $this->update_only ) {
			unset(
				$payload['name'],
				$payload['price'],
				$payload['regular_price'],
				$payload['sale_price'],
				$payload['date_on_sale_from'],
				$payload['date_on_sale_to'],
				$payload['categories']
			);
			$this->log( 'info', 'Update-only mode: name and price fields removed from payload' );
		}

		$endpoint = $remote_id ? '/wp-json/wc/v3/products/' . absint( $remote_id ) : '/wp-json/wc/v3/products';
		$method   = $remote_id ? 'PUT' : 'POST';

		$this->log( 'info', 'Sending product payload', array(
			'method'   => $method,
			'endpoint' => $endpoint,
			'payload'  => $payload,
		) );

		$body = $this->request( $endpoint, $method, $payload );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$remote_id = $body['id'] ?? $remote_id;
		$this->log( 'info', 'Product send succeeded', array( 'remote_id' => $remote_id ) );

		// Sync variations for variable products.
		if ( $product->is_type( 'variable' ) && $remote_id ) {
			$this->log( 'info', 'Variable product — syncing variations', array( 'remote_product_id' => $remote_id ) );
			$variation_error = $this->sync_variations( $product, (int) $remote_id );
			if ( is_wp_error( $variation_error ) ) {
				return $variation_error;
			}
		}

		return $body;
	}

	// -------------------------------------------------------------------------
	// Variation syncing
	// -------------------------------------------------------------------------

	private function sync_variations( WC_Product $product, int $remote_product_id ) {
		$children = $product->get_children();
		$this->log( 'info', 'Syncing ' . count( $children ) . ' variation(s)', array( 'remote_product_id' => $remote_product_id ) );

		foreach ( $children as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				$this->log( 'warning', 'Could not load variation', array( 'variation_id' => $variation_id ) );
				continue;
			}

			$payload         = Crosspost_Filters::build_variation_payload( $variation, $product );
			$payload         = $this->translate_payload_ids( $payload );
			$remote_var_id   = get_post_meta( $variation_id, '_crosspost_remote_variation_id', true );
			$variations_base = '/wp-json/wc/v3/products/' . $remote_product_id . '/variations';

			// In update-only mode, resolve the remote variation by SKU when not cached.
			if ( ! $remote_var_id && $this->update_only ) {
				$this->log( 'info', 'Looking up remote variation by SKU: ' . $variation->get_sku() );
				$remote_var_id = $this->find_remote_variation_by_sku(
					$remote_product_id,
					$variation->get_sku()
				);

				if ( $remote_var_id ) {
					$this->log( 'info', 'Variation SKU matched', array( 'variation_id' => $variation_id, 'remote_var_id' => $remote_var_id ) );
					update_post_meta( $variation_id, '_crosspost_remote_variation_id', $remote_var_id );
				} else {
					$this->log( 'warning', 'Update-only mode: no remote variation with SKU "' . $variation->get_sku() . '" — skipping', array( 'variation_id' => $variation_id ) );
					continue;
				}
			}

			// Strip price fields from variations in update-only mode too.
			if ( $this->update_only ) {
				unset(
					$payload['regular_price'],
					$payload['sale_price'],
					$payload['date_on_sale_from'],
					$payload['date_on_sale_to']
				);
			}

			$var_endpoint = $remote_var_id ? $variations_base . '/' . absint( $remote_var_id ) : $variations_base;
			$var_method   = $remote_var_id ? 'PUT' : 'POST';

			$this->log( 'info', 'Sending variation', array(
				'variation_id'  => $variation_id,
				'sku'           => $variation->get_sku(),
				'method'        => $var_method,
				'endpoint'      => $var_endpoint,
				'payload'       => $payload,
			) );

			$result = $this->request( $var_endpoint, $var_method, $payload );

			if ( is_wp_error( $result ) ) {
				$this->log( 'error', 'Variation send failed: ' . $result->get_error_message(), array(
					'variation_id' => $variation_id,
					'error_code'   => $result->get_error_code(),
					'error_data'   => $result->get_error_data(),
				) );
				return $result;
			}

			$this->log( 'info', 'Variation send succeeded', array(
				'variation_id'  => $variation_id,
				'remote_var_id' => $result['id'] ?? null,
			) );

			if ( ! empty( $result['id'] ) ) {
				update_post_meta( $variation_id, '_crosspost_remote_variation_id', $result['id'] );
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// ID translation
	// -------------------------------------------------------------------------

	/**
	 * Replace local product IDs in the payload with their remote equivalents.
	 * Covers upsell/cross-sell IDs plus any meta keys listed in the
	 * "ID Fields to Translate" setting. IDs with no remote match are dropped.
	 *
	 * @param  array $payload
	 * @return array
	 */
	private function translate_payload_ids( array $payload ): array {
		foreach ( array( 'upsell_ids', 'cross_sell_ids' ) as $field ) {
			if ( ! empty( $payload[ $field ] ) && is_array( $payload[ $field ] ) ) {
				$payload[ $field ] = $this->translate_value( $payload[ $field ], $field );
			}
		}

		$keys = $this->translate_keys();
		if ( $keys && ! empty( $payload['meta_data'] ) && is_array( $payload['meta_data'] ) ) {
			foreach ( $payload['meta_data'] as $i => $meta ) {
				if ( isset( $meta['key'] ) && in_array( $meta['key'], $keys, true ) ) {
					$payload['meta_data'][ $i ]['value'] = $this->translate_value( $meta['value'], $meta['key'] );
				}
			}
		}

		return $payload;
	}

	private function translate_keys(): array {
		$configured = get_option( 'crosspost_translate_keys', '' );
		if ( ! is_array( $configured ) ) {
			$configured = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', (string) $configured ) ) );
		}
		return array_values( apply_filters( 'crosspost_translate_keys', $configured ) );
	}

	/**
	 * Translate a value that holds one or more local product IDs, keeping its shape:
	 * a single ID, an (optionally nested) array of IDs, or a comma-separated string.
	 * Non-ID entries are left untouched.
	 *
	 * @param  mixed  $value
	 * @param  string $field Field/meta key, for logging.
	 * @return mixed
	 */
	private function translate_value( $value, string $field ) {
		if ( is_array( $value ) ) {
			$is_list = array_values( $value ) === $value;
			$out     = array();
			foreach ( $value as $k => $item ) {
				if ( is_array( $item ) || ! $this->is_id( $item ) ) {
					$out[ $k ] = is_array( $item ) ? $this->translate_value( $item, $field ) : $item;
					continue;
				}
				$remote = $this->resolve_remote_id( (int) $item, $field );
				if ( $remote ) {
					$out[ $k ] = is_string( $item ) ? (string) $remote : $remote;
				}
			}
			return $is_list ? array_values( $out ) : $out;
		}

		if ( is_string( $value ) && false !== strpos( $value, ',' ) ) {
			$parts = array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' );
			return implode( ',', $this->translate_value( array_values( $parts ), $field ) );
		}

		if ( $this->is_id( $value ) ) {
			$remote = $this->resolve_remote_id( (int) $value, $field );
			if ( ! $remote ) {
				return '';
			}
			return is_string( $value ) ? (string) $remote : $remote;
		}

		return $value;
	}

	private function is_id( $value ): bool {
		return ( is_int( $value ) && $value > 0 )
			|| ( is_string( $value ) && ctype_digit( $value ) && (int) $value > 0 );
	}

	/**
	 * Find the remote ID for a local product: cached remote ID first, then a
	 * remote SKU lookup (cached on the local product when matched).
	 *
	 * @param  int    $local_id
	 * @param  string $field Field/meta key, for logging.
	 * @return int|false
	 */
	private function resolve_remote_id( int $local_id, string $field ) {
		if ( array_key_exists( $local_id, $this->id_map ) ) {
			return $this->id_map[ $local_id ];
		}

		$remote = get_post_meta( $local_id, '_crosspost_remote_id', true )
			?: get_post_meta( $local_id, '_crosspost_remote_variation_id', true );

		if ( ! $remote ) {
			$related = wc_get_product( $local_id );
			// The remote SKU lookup only searches parent products, not variations.
			if ( $related && $related->get_sku() && ! $related->is_type( 'variation' ) ) {
				$remote = $this->find_remote_by_sku( $related->get_sku() );
				if ( $remote ) {
					update_post_meta( $local_id, '_crosspost_remote_id', $remote );
				}
			}
		}

		$remote = apply_filters( 'crosspost_translate_id', $remote ? (int) $remote : false, $local_id, $field );

		if ( $remote ) {
			$this->log( 'info', 'Translated product ID', array( 'field' => $field, 'local_id' => $local_id, 'remote_id' => $remote ) );
		} else {
			$this->log( 'warning', 'No remote match for product ID — dropped', array( 'field' => $field, 'local_id' => $local_id ) );
		}

		$this->id_map[ $local_id ] = $remote ?: false;

		return $this->id_map[ $local_id ];
	}

	// -------------------------------------------------------------------------
	// SKU lookup helpers
	// -------------------------------------------------------------------------

	/**
	 * Query the remote site for a product matching the given SKU.
	 * Returns the remote product ID, or false if not found.
	 *
	 * @param  string $sku
	 * @return int|false
	 */
	private function find_remote_by_sku( string $sku ) {
		if ( empty( $sku ) ) {
			$this->log( 'warning', 'find_remote_by_sku called with empty SKU' );
			return false;
		}

		$result = $this->request(
			'/wp-json/wc/v3/products?' . http_build_query( array( 'sku' => $sku, 'per_page' => 1 ) ),
			'GET',
			array()
		);

		if ( is_wp_error( $result ) ) {
			$this->log( 'error', 'SKU lookup request failed: ' . $result->get_error_message(), array( 'sku' => $sku ) );
			return false;
		}

		if ( empty( $result ) || ! isset( $result[0]['id'] ) ) {
			$this->log( 'info', 'SKU lookup returned no results', array( 'sku' => $sku, 'response' => $result ) );
			return false;
		}

		return (int) $result[0]['id'];
	}

	/**
	 * Query the remote site for a variation of a given product matching the given SKU.
	 * Returns the remote variation ID, or false if not found.
	 *
	 * @param  int    $remote_product_id
	 * @param  string $sku
	 * @return int|false
	 */
	private function find_remote_variation_by_sku( int $remote_product_id, string $sku ) {
		if ( empty( $sku ) ) {
			$this->log( 'warning', 'find_remote_variation_by_sku called with empty SKU', array( 'remote_product_id' => $remote_product_id ) );
			return false;
		}

		$result = $this->request(
			'/wp-json/wc/v3/products/' . $remote_product_id . '/variations?' . http_build_query( array( 'per_page' => 100 ) ),
			'GET',
			array()
		);

		if ( is_wp_error( $result ) ) {
			$this->log( 'error', 'Variation SKU lookup failed: ' . $result->get_error_message(), array(
				'remote_product_id' => $remote_product_id,
				'sku'               => $sku,
			) );
			return false;
		}

		if ( ! is_array( $result ) ) {
			$this->log( 'warning', 'Variation SKU lookup returned unexpected response', array(
				'remote_product_id' => $remote_product_id,
				'sku'               => $sku,
				'response'          => $result,
			) );
			return false;
		}

		foreach ( $result as $variation ) {
			if ( isset( $variation['sku'] ) && $variation['sku'] === $sku ) {
				return (int) $variation['id'];
			}
		}

		$this->log( 'info', 'No remote variation matched SKU', array(
			'remote_product_id'    => $remote_product_id,
			'sku'                  => $sku,
			'remote_variation_count' => count( $result ),
		) );

		return false;
	}

	// -------------------------------------------------------------------------
	// HTTP helper
	// -------------------------------------------------------------------------

	private function request( string $path, string $method, array $payload ) {
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Basic ' . base64_encode( $this->consumer_key . ':' . $this->consumer_secret ),
			),
		);

		if ( 'GET' !== $method ) {
			$args['body'] = wp_json_encode( $payload );
		}

		$this->log( 'info', $method . ' ' . $this->remote_url . $path );

		$response = wp_remote_request( $this->remote_url . $path, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'error', 'HTTP request failed: ' . $response->get_error_message(), array(
				'path'       => $path,
				'method'     => $method,
				'error_code' => $response->get_error_code(),
			) );
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$body        = json_decode( $raw_body, true );

		$this->log( 'info', 'HTTP response received', array(
			'method'      => $method,
			'path'        => $path,
			'status_code' => $status_code,
			'body'        => $body,
		) );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = $body['message'] ?? sprintf( __( 'Remote returned HTTP %d', 'crosspost' ), $status_code );
			$this->log( 'error', 'Remote returned error status', array(
				'status_code' => $status_code,
				'message'     => $message,
				'raw_body'    => $raw_body,
			) );
			return new WP_Error( 'crosspost_remote_error', $message, array( 'status' => $status_code ) );
		}

		return $body;
	}

	private function log( string $level, string $message, array $data = array() ) {
		$logger  = wc_get_logger();
		$context = array( 'source' => 'crosspost' );
		$logger->$level( $message . ( $data ? ' ' . print_r( $data, true ) : '' ), $context );
	}
}
