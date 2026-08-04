<?php
/**
 * Plugin settings, with environment-variable overrides.
 *
 * Secrets are never stored in code. When an environment variable is present it
 * always wins over the stored option, so deployments that manage secrets
 * outside the database (Bedrock, Docker, etc.) keep them out of wp_options.
 *
 * @package ChatwootWooSync
 */

namespace ChatwootWooSync;

defined( 'ABSPATH' ) || exit;

/**
 * Settings registry and admin screen.
 */
class Settings {

	const OPTION_GROUP = 'cws_settings';

	/**
	 * Setting definitions: key => [label, type, env var, sensitive].
	 *
	 * @var array<string, array{0:string,1:string,2:string,3:bool}>
	 */
	const FIELDS = array(
		'base_url'        => array( 'Chatwoot URL', 'url', 'CHATWOOT_BASE_URL', false ),
		'account_id'      => array( 'Account ID', 'text', 'CHATWOOT_ACCOUNT_ID', false ),
		'api_token'       => array( 'API access token', 'password', 'CHATWOOT_API_ACCESS_TOKEN', true ),
		'website_token'   => array( 'Web widget token', 'text', 'CHATWOOT_WEBSITE_TOKEN', false ),
		'token_map'       => array( 'Per-language widget tokens (JSON)', 'textarea', 'CHATWOOT_TOKEN_MAP', false ),
		'hmac_token'      => array( 'Identity validation (HMAC) token', 'password', 'CHATWOOT_HMAC_TOKEN', true ),
		'email_inbox_id'  => array( 'Email inbox ID', 'text', 'CHATWOOT_EMAIL_INBOX_ID', false ),
		'widget_scope'    => array( 'Load widget for', 'select', '', false ),
		'require_consent' => array( 'Require cookie consent', 'checkbox', '', false ),
		'consent_cookie'  => array( 'Consent cookie name=value', 'text', '', false ),
	);

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get the shared instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Read a setting, preferring the environment variable when defined.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get( string $key, $default = '' ) {
		if ( isset( self::FIELDS[ $key ][2] ) && '' !== self::FIELDS[ $key ][2] ) {
			$env = getenv( self::FIELDS[ $key ][2] );
			if ( false !== $env && '' !== $env ) {
				return $env;
			}
		}
		$stored = get_option( 'cws_' . $key, null );
		return ( null === $stored || '' === $stored ) ? $default : $stored;
	}

	/**
	 * Whether the minimum configuration required to talk to Chatwoot is present.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== self::get( 'base_url' ) && '' !== self::get( 'account_id' );
	}

	/**
	 * The REST base of the Chatwoot API for the configured account.
	 *
	 * @return string
	 */
	public static function api_base(): string {
		return rtrim( (string) self::get( 'base_url' ), '/' ) . '/api/v1/accounts/' . rawurlencode( (string) self::get( 'account_id' ) );
	}

	/**
	 * Widget credentials for a language.
	 *
	 * Greeting, out-of-office and CSAT copy are single-value fields on an
	 * inbox, so serving several languages from one inbox means showing some of
	 * them the wrong language. A per-language inbox fixes that, and this maps
	 * the visitor's language to the right one. Falls back to the single-token
	 * settings when no map is configured.
	 *
	 * @param string $lang Two-letter language code.
	 * @return array{website_token:string,hmac_token:string}
	 */
	public static function tokens_for_language( string $lang ): array {
		$fallback = array(
			'website_token' => (string) self::get( 'website_token' ),
			'hmac_token'    => (string) self::get( 'hmac_token' ),
		);

		$raw = (string) self::get( 'token_map' );
		if ( '' === trim( $raw ) ) {
			return $fallback;
		}

		$map = json_decode( $raw, true );
		if ( ! is_array( $map ) ) {
			return $fallback;
		}

		$entry = $map[ $lang ] ?? null;
		if ( ! is_array( $entry ) || empty( $entry['website_token'] ) ) {
			return $fallback;
		}

		return array(
			'website_token' => (string) $entry['website_token'],
			// An inbox without its own HMAC secret would silently fail identity
			// validation, so the shared one is used rather than an empty string.
			'hmac_token'    => (string) ( $entry['hmac_token'] ?? $fallback['hmac_token'] ),
		);
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	/**
	 * Add the options page.
	 *
	 * @return void
	 */
	public function menu(): void {
		add_options_page(
			__( 'Chatwoot Sync', 'chatwoot-woocommerce-sync' ),
			__( 'Chatwoot Sync', 'chatwoot-woocommerce-sync' ),
			'manage_options',
			'chatwoot-woocommerce-sync',
			array( $this, 'render' )
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( self::FIELDS as $key => $def ) {
			register_setting(
				self::OPTION_GROUP,
				'cws_' . $key,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'url' === $def[1] ? 'esc_url_raw' : 'sanitize_text_field',
					'default'           => '',
				)
			);
		}
	}

	/**
	 * Render the options page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Chatwoot WooCommerce Sync', 'chatwoot-woocommerce-sync' ); ?></h1>
			<?php $this->render_status(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation"><tbody>
				<?php foreach ( self::FIELDS as $key => $def ) : ?>
					<?php
					list( $label, $type, $env, $sensitive ) = $def;
					$env_set = '' !== $env && false !== getenv( $env ) && '' !== getenv( $env );
					$value   = $env_set ? '' : (string) get_option( 'cws_' . $key, '' );
					?>
					<tr>
						<th scope="row"><label for="cws_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<?php if ( $env_set ) : ?>
								<code><?php echo esc_html( $env ); ?></code>
								<p class="description"><?php esc_html_e( 'Set from the environment; the stored value is ignored.', 'chatwoot-woocommerce-sync' ); ?></p>
							<?php elseif ( 'checkbox' === $type ) : ?>
								<input type="checkbox" id="cws_<?php echo esc_attr( $key ); ?>" name="cws_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( '1', $value ); ?> />
							<?php elseif ( 'select' === $type ) : ?>
								<select id="cws_<?php echo esc_attr( $key ); ?>" name="cws_<?php echo esc_attr( $key ); ?>">
									<?php foreach ( array( 'logged_in' => __( 'Logged-in users only', 'chatwoot-woocommerce-sync' ), 'everyone' => __( 'Everyone', 'chatwoot-woocommerce-sync' ) ) as $v => $l ) : ?>
										<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $v, $value ?: 'logged_in' ); ?>><?php echo esc_html( $l ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="<?php echo esc_attr( $sensitive ? 'password' : $type ); ?>"
									id="cws_<?php echo esc_attr( $key ); ?>"
									name="cws_<?php echo esc_attr( $key ); ?>"
									value="<?php echo esc_attr( $value ); ?>"
									class="regular-text"
									autocomplete="off" />
								<?php if ( '' !== $env ) : ?>
									<p class="description">
										<?php
										/* translators: %s: environment variable name. */
										printf( esc_html__( 'Can also be set with the %s environment variable.', 'chatwoot-woocommerce-sync' ), '<code>' . esc_html( $env ) . '</code>' );
										?>
									</p>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Show a connectivity check for the configured credentials.
	 *
	 * @return void
	 */
	private function render_status(): void {
		if ( ! self::is_configured() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Enter your Chatwoot URL and account ID to enable the integration.', 'chatwoot-woocommerce-sync' ) . '</p></div>';
			return;
		}
		$check = Client::instance()->ping();
		if ( is_wp_error( $check ) ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Chatwoot connection failed:', 'chatwoot-woocommerce-sync' ) . '</strong> ' . esc_html( $check->get_error_message() ) . '</p></div>';
			return;
		}
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Connected to Chatwoot.', 'chatwoot-woocommerce-sync' ) . '</p></div>';
	}
}
