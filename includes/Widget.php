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
		add_action( 'wp_footer', array( $this, 'render_guest_launcher' ), 20 );
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
				'args'                => array(
					'lang' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
				// Public route by design: it returns data for the *caller's own*
				// session only, and nothing at all for logged-out visitors.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Resolve the logged-in user for a REST request.
	 *
	 * WordPress deliberately ignores the auth cookie on REST requests that
	 * carry no X-WP-Nonce header — rest_cookie_check_errors() calls
	 * wp_set_current_user(0) and comments "act as if it's an unauthenticated
	 * request". The nonce cannot be printed into the page here: it is
	 * per-user and expiring, so on a full-page-cached site one visitor would
	 * be served another's. The cookie is validated directly instead.
	 *
	 * Safe without a nonce because the response is read-only and contains only
	 * the caller's own identity: a cross-origin page can trigger the request
	 * but cannot read the reply, since no CORS headers are sent.
	 *
	 * @return int User ID, or 0.
	 */
	private function current_user(): int {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			return $user_id;
		}
		return (int) wp_validate_auth_cookie( '', 'logged_in' );
	}

	/**
	 * Return the current user's widget identity.
	 *
	 * @return WP_REST_Response
	 */
	public function identity( $request = null ): WP_REST_Response {
		$response = new WP_REST_Response( array( 'identified' => false ) );
		// Never cache: the payload is per-session.
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );

		$user_id = $this->current_user();
		if ( ! $user_id ) {
			return $response;
		}

		$identifier = Identity::identifier_for_user( $user_id );
		if ( '' === $identifier ) {
			return $response;
		}

		// Every inbox carries its own HMAC secret, so the signature has to be
		// made with the one belonging to the language the visitor is browsing.
		// Signing with another inbox's secret fails validation silently and the
		// visitor stays anonymous.
		$lang   = $request ? (string) $request->get_param( 'lang' ) : '';
		$secret = Settings::tokens_for_language( $lang )['hmac_token'];

		$response->set_data(
			array(
				'identified'      => true,
				'identifier'      => $identifier,
				'name'            => Identity::name_for_user( $user_id ),
				'email'           => $identifier,
				'phone_number'    => Identity::phone_for_user( $user_id ),
				'identifier_hash' => Identity::hmac( $identifier, $secret ),
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
	 * Whether to show logged-out visitors a launcher that asks them to sign in.
	 *
	 * Opening the real widget to everyone invites abuse and fills the inbox with
	 * contacts that cannot be tied to an account. A launcher that looks the same
	 * but opens the sign-in modal keeps support visible without that cost, and
	 * turns the click into a registration.
	 *
	 * @return bool
	 */
	private function should_render_guest_launcher(): bool {
		if ( is_admin() || is_user_logged_in() ) {
			return false;
		}
		if ( 'everyone' === Settings::get( 'widget_scope', 'logged_in' ) ) {
			return false;
		}
		/**
		 * Filter whether the sign-in launcher is shown to logged-out visitors.
		 *
		 * @param bool $render Whether to render the launcher.
		 */
		return (bool) apply_filters( 'cws_render_guest_launcher', true );
	}

	/**
	 * Print a launcher for logged-out visitors that opens the sign-in modal.
	 *
	 * The theme opens its modal from any element carrying `data-login-trigger`,
	 * through a listener delegated on document, and falls back to `/#auth=` on
	 * layouts that do not embed the modal. Using the attribute means this button
	 * needs no JavaScript of its own.
	 *
	 * @return void
	 */
	public function render_guest_launcher(): void {
		if ( ! $this->should_render_guest_launcher() ) {
			return;
		}
		$label = __( 'Chat with us', 'chatwoot-woocommerce-sync' );
		?>
		<button type="button"
			data-login-trigger
			id="cws-guest-launcher"
			aria-label="<?php echo esc_attr( $label ); ?>"
			title="<?php echo esc_attr( $label ); ?>"
			style="position:fixed;right:1rem;bottom:1rem;z-index:50;display:flex;align-items:center;gap:.5rem;
				padding:.875rem 1rem;border:0;border-radius:9999px;cursor:pointer;
				background:var(--color-primary,#4F46E5);color:#fff;font-weight:600;font-size:.9375rem;line-height:1;
				box-shadow:0 10px 25px -5px rgb(0 0 0 / .25),0 8px 10px -6px rgb(0 0 0 / .2);">
			<svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
				<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"
					stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>
			<span class="cws-guest-launcher__text"><?php echo esc_html( $label ); ?></span>
		</button>
		<style>
			/* Match the real launcher, which drops its label on small screens. */
			@media (max-width: 767px) {
				#cws-guest-launcher { padding: .875rem; }
				#cws-guest-launcher .cws-guest-launcher__text { display: none; }
			}
			#cws-guest-launcher:hover { filter: brightness(1.08); }
			#cws-guest-launcher:focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }
		</style>
		<?php
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
		$locale   = $this->locale();
		// Each language has its own inbox, because greeting, out-of-office and
		// CSAT copy are single-value fields: one inbox cannot speak twenty
		// languages.
		$tokens   = Settings::tokens_for_language( $locale );
		$settings = array(
			'position'          => 'right',
			'type'              => 'expanded_bubble',
			// Overwritten client-side from the site's own theme. 'auto' follows
			// the operating system, which drifts from the site whenever the two
			// disagree — and any styling the theme layers over the widget then
			// stops matching what the widget actually renders.
			'darkMode'          => 'light',
			'useBrowserLanguage' => false,
			'locale'            => $locale,
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
			var TOKEN = <?php echo wp_json_encode( $tokens['website_token'] ); ?>;
			var IDENTITY_URL = <?php echo wp_json_encode( esc_url_raw( add_query_arg( 'lang', $locale, rest_url( self::REST_NAMESPACE . '/identity' ) ) ) ); ?>;
			var CONSENT_COOKIE = <?php echo wp_json_encode( $consent_cookie ); ?>;
			var CONSENT_VALUE = <?php echo wp_json_encode( $consent_value ); ?>;

			window.chatwootSettings = <?php echo wp_json_encode( $settings ); ?>;

			// Follow the site's theme, not the operating system's. The theme
			// stamps data-theme on the root element and toggles it live, so the
			// widget is kept in step both at boot and on every switch.
			function siteTheme() {
				var root = document.documentElement;
				if (root.dataset && root.dataset.theme) { return root.dataset.theme; }
				return root.classList.contains('dark') ? 'dark' : 'light';
			}

			window.chatwootSettings.darkMode = siteTheme();

			function syncTheme() {
				var mode = siteTheme();
				window.chatwootSettings.darkMode = mode;
				if (window.$chatwoot && typeof window.$chatwoot.setColorScheme === 'function') {
					window.$chatwoot.setColorScheme(mode);
				}
			}

			new MutationObserver(syncTheme).observe(document.documentElement, {
				attributes: true,
				attributeFilter: ['data-theme', 'class']
			});

			function hasConsent() {
				if (!CONSENT_COOKIE) { return true; }
				return document.cookie.split('; ').some(function (c) {
					var parts = c.split('=');
					return parts[0] === CONSENT_COOKIE && (!CONSENT_VALUE || parts[1] === CONSENT_VALUE);
				});
			}

			// Identity is fetched per session so it never lands in cached HTML.
			var identified = false;
			function identify() {
				if (identified) { return; }
				fetch(IDENTITY_URL, { credentials: 'same-origin', cache: 'no-store' })
					.then(function (r) { return r.ok ? r.json() : null; })
					.then(function (d) {
						if (!d || !d.identified) { return; }
						// The SDK may not have finished booting when this
						// resolves, so wait for it rather than dropping the
						// identity and leaving the visitor anonymous.
						var tries = 0;
						(function attach() {
							if (window.$chatwoot && typeof window.$chatwoot.setUser === 'function') {
								window.$chatwoot.setUser(d.identifier, {
									name: d.name,
									email: d.email,
									phone_number: d.phone_number,
									identifier_hash: d.identifier_hash
								});
								identified = true;
								return;
							}
							if (tries++ < 40) { setTimeout(attach, 250); }
						})();
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
					// Belt and braces: the ready event is the normal path, but
					// starting here too means a missed event costs nothing.
					identify();
				};
				document.head.appendChild(s);
			};

			window.addEventListener('chatwoot:ready', identify);

			<?php
			/**
			 * Whether the widget loads itself on this request.
			 *
			 * Every page now that the widget is limited to signed-in visitors:
			 * a support bubble nobody can find is the same as no support. Still
			 * deferred to idle, so it never competes for the first paint.
			 *
			 * @param bool $autoload Whether to load the widget automatically.
			 */
			if ( apply_filters( 'cws_autoload_widget', true ) ) :
			?>
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
