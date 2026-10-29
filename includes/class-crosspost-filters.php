<?php
defined( 'ABSPATH' ) || exit;

/**
 * Filters applied to product data before it is sent to the remote site.
 *
 * Usage — add your own hooks in a theme's functions.php or a separate plugin:
 *
 *   // Translate descriptions
 *   add_filter( 'crosspost_short_description', function( $desc, $product ) {
 *       return my_translate( $desc );
 *   }, 10, 2 );
 *
 *   add_filter( 'crosspost_long_description', function( $desc, $product ) {
 *       return my_translate( $desc );
 *   }, 10, 2 );
 *
 *   // Change price
 *   add_filter( 'crosspost_regular_price', function( $price, $product ) {
 *       return (string) ( (float) $price * 1.1 ); // +10%
 *   }, 10, 2 );
 *
 *   add_filter( 'crosspost_sale_price', function( $price, $product ) {
 *       return $price;
 *   }, 10, 2 );
 *
 *   // Modify any part of the full payload. Upsell/cross-sell IDs are still local
 *   // here; they are translated to remote IDs by Crosspost_Sender afterwards.
 *   add_filter( 'crosspost_product_data', function( $data, $product ) {
 *       $data['status'] = 'draft';
 *       return $data;
 *   }, 10, 2 );
 *
 *   // Add meta keys whose values hold product IDs to translate to remote IDs
 *   add_filter( 'crosspost_translate_keys', function( $keys ) {
 *       $keys[] = '_my_accessory_ids';
 *       return $keys;
 *   } );
 *
 *   // Override how a single local product ID maps to a remote ID (false = drop)
 *   add_filter( 'crosspost_translate_id', function( $remote_id, $local_id, $field ) {
 *       return $remote_id;
 *   }, 10, 3 );
 *
 *   // Modify a single variation's payload before it is sent
 *   add_filter( 'crosspost_variation_data', function( $data, $variation, $product ) {
 *       return $data;
 *   }, 10, 3 );
 *
 *   // Add / remove postmeta keys included in the explicit allowlist
 *   add_filter( 'crosspost_meta_keys', function( $keys ) {
 *       $keys[] = '_my_custom_meta';
 *       return $keys;
 *   } );
 *
 *   // Add keys to the exclusion list used when "Include all postmeta" is enabled
 *   add_filter( 'crosspost_meta_exclude_keys', function( $keys ) {
 *       $keys[] = '_my_internal_cache_key';
 *       return $keys;
 *   } );
 *
 *   // Transform a specific meta value before it is sent
 *   add_filter( 'crosspost_meta_value', function( $value, $key, $product ) {
 *       if ( '_my_custom_meta' === $key ) {
 *           return strtoupper( $value );
 *       }
 *       return $value;
 *   }, 10, 3 );
 */
class Crosspost_Filters {

	/**
	 * Run all filters and return the final payload array ready to send.
	 *
	 * @param  WC_Product $product
	 * @return array
	 */
	public static function build_payload( WC_Product $product ) {
		$short_desc = apply_filters(
			'crosspost_short_description',
			$product->get_short_description(),
			$product
		);

		$long_desc = apply_filters(
			'crosspost_long_description',
			$product->get_description(),
			$product
		);

		$regular_price = apply_filters(
			'crosspost_regular_price',
			$product->get_regular_price(),
			$product
		);

		$sale_price = apply_filters(
			'crosspost_sale_price',
			$product->get_sale_price(),
			$product
		);

		$sale_from = $product->get_date_on_sale_from();
		$sale_to   = $product->get_date_on_sale_to();

		$data = array(
			// Core identity
			'name'               => $product->get_name(),
			'type'               => $product->get_type(),
			'status'             => $product->get_status(),
			'sku'                => $product->get_sku(),
			'menu_order'         => $product->get_menu_order(),

			// Visibility
			'catalog_visibility' => $product->get_catalog_visibility(),
			'featured'           => $product->is_featured(),
			'reviews_allowed'    => $product->get_reviews_allowed(),

			// Descriptions
			'description'        => $long_desc,
			'short_description'  => $short_desc,
			'purchase_note'      => $product->get_purchase_note(),

			// Pricing
			'regular_price'      => (string) $regular_price,
			'sale_price'         => (string) $sale_price,
			'date_on_sale_from'  => $sale_from ? $sale_from->date( 'Y-m-d\TH:i:s' ) : null,
			'date_on_sale_to'    => $sale_to   ? $sale_to->date(   'Y-m-d\TH:i:s' ) : null,

			// Stock
			'manage_stock'       => $product->get_manage_stock(),
			'stock_quantity'     => $product->get_stock_quantity(),
			'stock_status'       => $product->get_stock_status(),
			'backorders'         => $product->get_backorders(),

			// Shipping & dimensions
			'weight'             => $product->get_weight(),
			'dimensions'         => array(
				'length' => $product->get_length(),
				'width'  => $product->get_width(),
				'height' => $product->get_height(),
			),
			'shipping_class'     => $product->get_shipping_class(),

			// Tax
			'tax_status'         => $product->get_tax_status(),
			'tax_class'          => $product->get_tax_class(),

			// Virtual / downloadable
			'virtual'            => $product->is_virtual(),
			'downloadable'       => $product->is_downloadable(),
			'downloads'          => self::map_downloads( $product ),
			'download_limit'     => $product->get_download_limit(),
			'download_expiry'    => $product->get_download_expiry(),

			// External / affiliate
			'external_url'       => $product instanceof WC_Product_External ? $product->get_product_url() : '',
			'button_text'        => $product instanceof WC_Product_External ? $product->get_button_text()  : '',

			// Relationships (local IDs — translated to remote IDs by Crosspost_Sender)
			'upsell_ids'         => $product->get_upsell_ids(),
			'cross_sell_ids'     => $product->get_cross_sell_ids(),

			// Attributes & variations
			'attributes'         => self::map_attributes( $product ),
			'default_attributes' => self::map_default_attributes( $product ),

			// Taxonomy
			'categories'         => self::map_terms( $product->get_category_ids() ),
			'tags'               => self::map_terms( $product->get_tag_ids() ),

			// Media
			'images'             => self::map_images( $product ),

			// Custom postmeta
			'meta_data'          => self::build_meta( $product ),
		);

		// Allow complete payload override.
		$data = apply_filters( 'crosspost_product_data', $data, $product );

		return $data;
	}

	/**
	 * Build the payload for a single product variation.
	 *
	 * @param  WC_Product_Variation $variation
	 * @param  WC_Product           $parent
	 * @return array
	 */
	public static function build_variation_payload( WC_Product_Variation $variation, WC_Product $parent ) {
		$regular_price = apply_filters( 'crosspost_regular_price', $variation->get_regular_price(), $variation );
		$sale_price    = apply_filters( 'crosspost_sale_price',    $variation->get_sale_price(),    $variation );

		$sale_from = $variation->get_date_on_sale_from();
		$sale_to   = $variation->get_date_on_sale_to();

		$image_id  = $variation->get_image_id();
		$image_src = $image_id ? wp_get_attachment_url( $image_id ) : '';

		$data = array(
			'sku'               => $variation->get_sku(),
			'status'            => 'publish',
			'description'       => $variation->get_description(),
			'regular_price'     => (string) $regular_price,
			'sale_price'        => (string) $sale_price,
			'date_on_sale_from' => $sale_from ? $sale_from->date( 'Y-m-d\TH:i:s' ) : null,
			'date_on_sale_to'   => $sale_to   ? $sale_to->date(   'Y-m-d\TH:i:s' ) : null,
			'weight'            => $variation->get_weight(),
			'dimensions'        => array(
				'length' => $variation->get_length(),
				'width'  => $variation->get_width(),
				'height' => $variation->get_height(),
			),
			'shipping_class'    => $variation->get_shipping_class(),
			'tax_status'        => $variation->get_tax_status(),
			'tax_class'         => $variation->get_tax_class(),
			'manage_stock'      => $variation->get_manage_stock(),
			'stock_quantity'    => $variation->get_stock_quantity(),
			'stock_status'      => $variation->get_stock_status(),
			'backorders'        => $variation->get_backorders(),
			'virtual'           => $variation->is_virtual(),
			'downloadable'      => $variation->is_downloadable(),
			'downloads'         => self::map_downloads( $variation ),
			'download_limit'    => $variation->get_download_limit(),
			'download_expiry'   => $variation->get_download_expiry(),
			'image'             => $image_src ? array(
				'src'  => $image_src,
				'name' => get_the_title( $image_id ),
				'alt'  => get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
			) : null,
			'attributes'        => self::map_variation_attributes( $variation ),
			'meta_data'         => self::build_meta( $variation ),
		);

		$data = apply_filters( 'crosspost_variation_data', $data, $variation, $parent );

		return $data;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private static function map_terms( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$term = get_term( $id );
			if ( $term && ! is_wp_error( $term ) ) {
				$out[] = array( 'name' => $term->name );
			}
		}
		return $out;
	}

	private static function map_images( WC_Product $product ) {
		$images    = array();
		$image_ids = array_filter( array_merge(
			array( $product->get_image_id() ),
			$product->get_gallery_image_ids()
		) );

		foreach ( $image_ids as $image_id ) {
			$src = wp_get_attachment_url( $image_id );
			if ( $src ) {
				$images[] = array(
					'src'  => $src,
					'name' => get_the_title( $image_id ),
					'alt'  => get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
				);
			}
		}

		return $images;
	}

	private static function map_downloads( WC_Product $product ) {
		$out = array();
		foreach ( $product->get_downloads() as $download ) {
			$out[] = array(
				'name' => $download->get_name(),
				'file' => $download->get_file(),
			);
		}
		return $out;
	}

	private static function map_attributes( WC_Product $product ) {
		$out = array();
		foreach ( $product->get_attributes() as $attribute ) {
			$out[] = array(
				'name'      => $attribute->get_name(),
				'position'  => $attribute->get_position(),
				'visible'   => $attribute->get_visible(),
				'variation' => $attribute->get_variation(),
				'options'   => $attribute->is_taxonomy()
					? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
					: $attribute->get_options(),
			);
		}
		return $out;
	}

	private static function map_default_attributes( WC_Product $product ) {
		$out = array();
		foreach ( $product->get_default_attributes() as $name => $value ) {
			$out[] = array( 'name' => $name, 'option' => $value );
		}
		return $out;
	}

	private static function map_variation_attributes( WC_Product_Variation $variation ) {
		$out = array();
		foreach ( $variation->get_variation_attributes() as $attribute_name => $option ) {
			$out[] = array(
				'name'   => str_replace( 'attribute_', '', $attribute_name ),
				'option' => $option,
			);
		}
		return $out;
	}

	private static function build_meta( WC_Product $product ) {
		$all_meta_enabled = 'yes' === get_option( 'crosspost_all_meta', 'no' );

		if ( $all_meta_enabled ) {
			$keys = self::all_meta_keys( $product->get_id() );
		} else {
			$configured = get_option( 'crosspost_meta_keys', array() );
			if ( ! is_array( $configured ) ) {
				$configured = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $configured ) ) );
			}
			$keys = apply_filters( 'crosspost_meta_keys', $configured );
		}

		$meta_data = array();
		foreach ( $keys as $key ) {
			$raw_value = get_post_meta( $product->get_id(), $key, true );
			$value     = apply_filters( 'crosspost_meta_value', $raw_value, $key, $product );
			$meta_data[] = array(
				'key'   => $key,
				'value' => $value,
			);
		}

		return $meta_data;
	}

	/**
	 * Return all meta keys for a post, minus internal WP/WC/plugin keys that
	 * should never be synced.
	 */
	private static function all_meta_keys( int $post_id ): array {
		$all = get_post_meta( $post_id );
		if ( ! is_array( $all ) ) {
			return array();
		}

		$default_exclude = array(
			'_edit_lock',
			'_edit_last',
			'_wp_trash_meta_status',
			'_wp_trash_meta_time',
			'total_sales',
			'_wc_average_rating',
			'_wc_rating_count',
			'_wc_review_count',
			'_crosspost_remote_id',
			'_crosspost_remote_variation_id',
			'_crosspost_last_status',
			'_crosspost_last_error',
			'_crosspost_enabled',
		);

		$exclude = apply_filters( 'crosspost_meta_exclude_keys', $default_exclude );

		$keys = array_diff( array_keys( $all ), $exclude );

		// Also apply the allowlist filter so manual additions still merge in.
		$keys = apply_filters( 'crosspost_meta_keys', array_values( $keys ) );

		return $keys;
	}
}
