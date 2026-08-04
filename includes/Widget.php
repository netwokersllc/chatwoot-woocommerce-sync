<?php
/**
 * Live-chat widget embed and identity hydration.
 *
 * The widget is identified through `window.$chatwoot.setUser()`, which is the
 * only API Chatwoot reads identity from. Passing `identifier` /
 * `identifier_hash` inside `window.chatwootSettings` silently does nothing, so
 * every visitor stays anonymous even when identity validation is mandatory on
 * the inbox.
 *
 * Identity is fetched from a REST endpoint rather than printed into the page:
 * on a full-page-cached site, per-user data in the HTML leaks one visitor's
 * details to the next.
 *
 * @package ChatwootWooSync
 */

namespace ChatwootWooSync;

defined( 'ABSPATH' ) || exit;

use WP_REST_Response;

/**
 * Front-end widget integration.
 */
class Widget {

	const REST_NAMESPACE = 'chatwoot-woo/v1';

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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_footer', array( $this, 'render' ), 20 );
	}

	/**
	 * Register the identity endpoint.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/identity',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'identity' ),
				// Public route by design: it returns data for the *current*
				// session only, and nothing at all for logged-out visitors.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Return the current user's widget identity.
	 *
	 * @return WP_REST_Response
	 */
	public function identity(): WP_REST_Response {
		$response = new WP_REST_Response( array( 'identified' => false ) );
		// Never cache: the payload is per-session.
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $response;
		}

		$identifier = Identity::identifier_for_user( $user_id );
		if ( '' === $identifier ) {
			return $response;
		}

		$response->set_data(
			array(
				'identified'      => true,
				'identifier'      => $identifier,
				'name'            => Identity::name_for_user( $user_id ),
				'email'           => $identifier,
				'phone_number'    => Identity::phone_for_user( $user_id ),
				'identifier_hash' => Identity::hmac( $identifier ),
			)
		);
		return $response;
	}

	/**
	 * Whether the widget should be rendered for this request.
	 *
	 * @return bool
	 */
	private function should_render(): bool {
		if ( is_admin() ) {
			return false;
		}
		if ( '' === (string) Settings::get( 'website_token' ) ) {
			return false;
		}
		if ( 'everyone' !== Settings::get( 'widget_scope', 'logged_in' ) && ! is_user_logged_in() ) {
			return false;
		}
		/**
		 * Filter whether the Chatwoot widget is rendered.
		 *
		 * @param bool $render Whether to render the widget.
		 */
		return (bool) apply_filters( 'cws_render_widget', true );
	}

	/**
	 * Print the widget bootstrap.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->should_render() ) {
			return;
		}

		$base_url = rtrim( (string) Settings::get( 'base_url' ), '/' );
		$settings = array(
			'position'          => 'right',
			'type'              => 'expanded_bubble',
			'darkMode'          => 'auto',
			'useBrowserLanguage' => false,
			'locale'            => $this->locale(),
			'launcherTitle'     => __( 'Chat', 'chatwoot-woocommerce-sync' ),
		);

		$consent_cookie = '';
		$consent_value  = '';
		if ( Settings::get( 'require_consent' ) ) {
			$pair           = explode( '=', (string) Settings::get( 'consent_cookie' ), 2 );
			$consent_cookie = trim( $pair[0] ?? '' );
			$consent_value  = trim( $pair[1] ?? '' );
		}
		?>
		<script id="cws-widget">
		(function () {
			var BASE = <?php echo wp_json_encode( $base_url ); ?>;
			var TOKEN = <?php echo wp_json_encode( (string) Settings::get( 'website_token' ) ); ?>;
			var IDENTITY_URL = <?php echo wp_json_encode( esc_url_raw( rest_url( self::REST_NAMESPACE . '/identity' ) ) ); ?>;
			var CONSENT_COOKIE = <?php echo wp_json_encode( $consent_cookie ); ?>;
			var CONSENT_VALUE = <?php echo wp_json_encode( $consent_value ); ?>;

			window.chatwootSettings = <?php echo wp_json_encode( $settings ); ?>;

			function hasConsent() {
				if (!CONSENT_COOKIE) { return true; }
				return document.cookie.split('; ').some(function (c) {
					var parts = c.split('=');
					return parts[0] === CONSENT_COOKIE && (!CONSENT_VALUE || parts[1] === CONSENT_VALUE);
				});
			}

			// Identity is fetched per session so it never lands in cached HTML.
			function identify() {
				fetch(IDENTITY_URL, { credentials: 'same-origin', cache: 'no-store' })
					.then(function (r) { return r.ok ? r.json() : null; })
					.then(function (d) {
						if (!d || !d.identified || !window.$chatwoot) { return; }
						window.$chatwoot.setUser(d.identifier, {
							name: d.name,
							email: d.email,
							phone_number: d.phone_number,
							identifier_hash: d.identifier_hash
						});
					})
					.catch(function () { /* identity is best-effort */ });
			}

			window.loadChatwoot = function () {
				if (window.chatwootLoaded || !hasConsent()) { return; }
				window.chatwootLoaded = true;
				var s = document.createElement('script');
				s.src = BASE + '/packs/js/sdk.js';
				s.defer = true;
				s.async = true;
				s.onload = function () {
					window.chatwootSDK.run({ websiteToken: TOKEN, baseUrl: BASE });
				};
				document.head.appendChild(s);
			};

			window.addEventListener('chatwoot:ready', identify);

			<?php if ( apply_filters( 'cws_autoload_widget', is_checkout() ) ) : ?>
			var boot = function () {
				(window.requestIdleCallback || function (cb) { setTimeout(cb, 1500); })(function () {
					window.loadChatwoot();
				});
			};
			if (document.readyState === 'complete') { boot(); }
			else { window.addEventListener('load', boot, { once: true }); }
			<?php endif; ?>
		})();
		</script>
		<?php
	}

	/**
	 * Current locale in the short form Chatwoot expects.
	 *
	 * @return string
	 */
	private function locale(): string {
		if ( function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language();
			if ( $lang ) {
				return (string) $lang;
			}
		}
		return substr( (string) determine_locale(), 0, 2 );
	}
}
