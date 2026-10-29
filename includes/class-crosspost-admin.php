<?php
defined( 'ABSPATH' ) || exit;

class Crosspost_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

		// Per-product panel in the product data metabox.
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_options' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_meta' ) );

		// Manual "crosspost now" action in product list.
		add_filter( 'post_row_actions', array( __CLASS__, 'product_row_action' ), 10, 2 );
		add_action( 'admin_action_crosspost_now', array( __CLASS__, 'handle_crosspost_now' ) );

		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
	}

	// -------------------------------------------------------------------------
	// Settings page
	// -------------------------------------------------------------------------

	public static function add_settings_page() {
		add_options_page(
			__( 'Crosspost', 'crosspost' ),
			__( 'Crosspost', 'crosspost' ),
			'manage_options',
			'crosspost',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		$simple = array(
			'crosspost_remote_url',
			'crosspost_consumer_key',
			'crosspost_consumer_secret',
			'crosspost_all_products',
			'crosspost_update_only',
			'crosspost_all_meta',
		);

		foreach ( $simple as $option ) {
			register_setting( 'crosspost', $option, array(
				'sanitize_callback' => 'sanitize_text_field',
			) );
		}

		// meta_keys is a textarea — override sanitize.
		register_setting( 'crosspost', 'crosspost_meta_keys', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_meta_keys' ),
		) );

		register_setting( 'crosspost', 'crosspost_translate_keys', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_translate_keys' ),
		) );
	}

	public static function sanitize_meta_keys( $value ) {
		$parts = preg_split( '/[\s,]+/', $value );
		$parts = array_map( 'sanitize_key', array_filter( array_map( 'trim', $parts ) ) );
		return implode( ' ', $parts );
	}

	/**
	 * Like sanitize_meta_keys(), but preserves case so keys such as
	 * "_relatedProducts" still match the stored meta key.
	 */
	public static function sanitize_translate_keys( $value ) {
		$parts = preg_split( '/[\s,]+/', (string) $value );
		$parts = array_map( function ( $part ) {
			return preg_replace( '/[^A-Za-z0-9_\-]/', '', $part );
		}, $parts );
		return implode( ' ', array_filter( $parts ) );
	}

	public static function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Crosspost Settings', 'crosspost' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'crosspost' ); ?>
				<table class="form-table" role="presentation">

					<tr>
						<th scope="row"><label for="crosspost_remote_url"><?php esc_html_e( 'Remote Site URL', 'crosspost' ); ?></label></th>
						<td>
							<input type="url" id="crosspost_remote_url" name="crosspost_remote_url"
								value="<?php echo esc_attr( get_option( 'crosspost_remote_url' ) ); ?>"
								class="regular-text" placeholder="https://example.com" />
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="crosspost_consumer_key"><?php esc_html_e( 'Consumer Key', 'crosspost' ); ?></label></th>
						<td>
							<input type="text" id="crosspost_consumer_key" name="crosspost_consumer_key"
								value="<?php echo esc_attr( get_option( 'crosspost_consumer_key' ) ); ?>"
								class="regular-text" autocomplete="off" />
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="crosspost_consumer_secret"><?php esc_html_e( 'Consumer Secret', 'crosspost' ); ?></label></th>
						<td>
							<input type="password" id="crosspost_consumer_secret" name="crosspost_consumer_secret"
								value="<?php echo esc_attr( get_option( 'crosspost_consumer_secret' ) ); ?>"
								class="regular-text" autocomplete="off" />
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Crosspost All Products', 'crosspost' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="crosspost_all_products" value="yes"
									<?php checked( get_option( 'crosspost_all_products' ), 'yes' ); ?> />
								<?php esc_html_e( 'Automatically crosspost every product on save', 'crosspost' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Update Only Mode', 'crosspost' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="crosspost_update_only" value="yes"
									<?php checked( get_option( 'crosspost_update_only' ), 'yes' ); ?> />
								<?php esc_html_e( 'Only update products that already exist on the remote site (matched by SKU). New products will not be created. Name and price fields will be left unchanged on the remote site.', 'crosspost' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Include All Postmeta', 'crosspost' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="crosspost_all_meta" value="yes"
									<?php checked( get_option( 'crosspost_all_meta' ), 'yes' ); ?> />
								<?php esc_html_e( 'Send all postmeta fields to the remote site (excluding internal WP/WooCommerce keys). When enabled, the list below is merged in as additional keys.', 'crosspost' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="crosspost_meta_keys"><?php esc_html_e( 'Postmeta Keys to Include', 'crosspost' ); ?></label></th>
						<td>
							<textarea id="crosspost_meta_keys" name="crosspost_meta_keys"
								rows="6" class="large-text code"><?php echo esc_textarea( get_option( 'crosspost_meta_keys', '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Space-separated meta keys to include. When "Include All Postmeta" is off, only these keys are sent.', 'crosspost' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="crosspost_translate_keys"><?php esc_html_e( 'ID Fields to Translate', 'crosspost' ); ?></label></th>
						<td>
							<textarea id="crosspost_translate_keys" name="crosspost_translate_keys"
								rows="4" class="large-text code"><?php echo esc_textarea( get_option( 'crosspost_translate_keys', '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Space-separated meta keys whose values contain local product IDs (single ID, array, or comma-separated list). Each ID is replaced with the matching remote product ID before sending; IDs with no remote match are dropped. Upsells and cross-sells are always translated. Keys must also be included via the list above or "Include All Postmeta".', 'crosspost' ); ?></p>
						</td>
					</tr>

				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Per-product options
	// -------------------------------------------------------------------------

	public static function product_options() {
		global $post;
		woocommerce_wp_checkbox( array(
			'id'          => '_crosspost_enabled',
			'label'       => __( 'Crosspost this product', 'crosspost' ),
			'description' => __( 'Send this product to the remote site when saved.', 'crosspost' ),
		) );

		$remote_id   = get_post_meta( $post->ID, '_crosspost_remote_id', true );
		$last_status = get_post_meta( $post->ID, '_crosspost_last_status', true );
		$last_error  = get_post_meta( $post->ID, '_crosspost_last_error', true );

		if ( $last_status ) {
			echo '<p class="form-field"><label>' . esc_html__( 'Crosspost status', 'crosspost' ) . '</label>';
			if ( 'success' === $last_status ) {
				echo '<span style="color:green">' . esc_html__( 'Last crosspost succeeded', 'crosspost' );
				if ( $remote_id ) {
					echo ' ' . esc_html( sprintf( __( '(remote ID: %s)', 'crosspost' ), $remote_id ) );
				}
				echo '</span>';
			} elseif ( 'skipped' === $last_status ) {
				echo '<span style="color:#b07d00">' . esc_html__( 'Skipped — no matching product found on remote site', 'crosspost' ) . '</span>';
			} else {
				echo '<span style="color:red">' . esc_html( $last_error ) . '</span>';
			}
			echo '</p>';
		}
	}

	public static function save_product_meta( $product_id ) {
		$value = isset( $_POST['_crosspost_enabled'] ) ? 'yes' : 'no';
		update_post_meta( $product_id, '_crosspost_enabled', $value );
	}

	// -------------------------------------------------------------------------
	// Manual "Crosspost now" row action
	// -------------------------------------------------------------------------

	public static function product_row_action( $actions, $post ) {
		if ( 'product' !== $post->post_type ) {
			return $actions;
		}

		$url = add_query_arg( array(
			'action'     => 'crosspost_now',
			'product_id' => $post->ID,
			'_wpnonce'   => wp_create_nonce( 'crosspost_now_' . $post->ID ),
		), admin_url( 'admin.php' ) );

		$actions['crosspost'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Crosspost now', 'crosspost' ) . '</a>';

		return $actions;
	}

	public static function handle_crosspost_now() {
		$product_id = absint( $_GET['product_id'] ?? 0 );
		if ( ! $product_id || ! check_admin_referer( 'crosspost_now_' . $product_id ) ) {
			wp_die( esc_html__( 'Invalid request.', 'crosspost' ) );
		}

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'crosspost' ) );
		}

		Crosspost::maybe_crosspost( $product_id );

		$redirect = add_query_arg(
			array( 'crosspost_done' => $product_id ),
			wp_get_referer() ?: admin_url( 'edit.php?post_type=product' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	public static function admin_notices() {
		if ( ! isset( $_GET['crosspost_done'] ) ) {
			return;
		}

		$product_id = absint( $_GET['crosspost_done'] );
		$status     = get_post_meta( $product_id, '_crosspost_last_status', true );
		$error      = get_post_meta( $product_id, '_crosspost_last_error', true );

		if ( 'success' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Product crossposted successfully.', 'crosspost' ) . '</p></div>';
		} elseif ( 'skipped' === $status ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Crosspost skipped — no matching product found on the remote site (update-only mode).', 'crosspost' ) . '</p></div>';
		} elseif ( $error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sprintf( __( 'Crosspost failed: %s', 'crosspost' ), $error ) ) . '</p></div>';
		}
	}
}
