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
		// WordPress' default REST filter reflects any Origin and enables
		// credentials. This private identity response must remain same-origin.
		add_filter( 'rest_pre_serve_request', array( $this, 'lock_identity_cors' ), 20, 4 );
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
		register_rest_route(
			self::REST_NAMESPACE,
			'/bootstrap',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'bootstrap' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Return the widget bootstrap for a visitor who signed in in-place.
	 *
	 * The anonymous page only needs the small event loader below. Keeping the
	 * full bootstrap out of that initial response saves parser work and bytes;
	 * the authenticated path still receives exactly the same script.
	 *
	 * @return WP_REST_Response
	 */
	public function bootstrap(): WP_REST_Response {
		$response = new WP_REST_Response();
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$user_id = $this->current_user();
		if ( $user_id ) {
			wp_set_current_user( $user_id );
		}
		ob_start();
		$this->render();
		$html = (string) ob_get_clean();
		$response->set_data( array( 'html' => $html ) );
		return $response;
	}

	/**
	 * Remove WordPress' permissive CORS headers from the identity route.
	 *
	 * The route intentionally uses the logged-in cookie without a REST nonce,
	 * so allowing credentialed cross-origin reads would expose the caller's
	 * e-mail/name/hash to any requesting site. The widget calls this endpoint
	 * same-origin and does not need CORS at all.
	 *
	 * @param bool             $served  Whether the response was already served.
	 * @param WP_HTTP_Response $result  The REST response.
	 * @param WP_REST_Request  $request The REST request.
	 * @param WP_REST_Server   $server  The REST server.
	 * @return bool
	 */
	public function lock_identity_cors( $served, $result, $request, $server ): bool {
		if ( ! $request instanceof \WP_REST_Request || '/chatwoot-woo/v1/identity' !== $request->get_route() ) {
			return $served;
		}

		if ( function_exists( 'header_remove' ) ) {
			foreach ( array(
				'Access-Control-Allow-Origin',
				'Access-Control-Allow-Methods',
				'Access-Control-Allow-Headers',
				'Access-Control-Allow-Credentials',
				'Access-Control-Expose-Headers',
			) as $header ) {
				header_remove( $header );
			}
		}

		return $served;
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
		// Keep the bootstrap on logged-out pages because OTPress signs users in
		// in-place and emits auth:signed-in without reloading the document.
		// The SDK remains hard-gated below on a valid authenticated identity.
		/**
		 * Filter whether the Chatwoot widget is rendered.
		 *
		 * @param bool $render Whether to render the widget.
		 */
		return (bool) apply_filters( 'cws_render_widget', true );
	}

	/**
	 * The floating bubble is gone; the navbar button is the launcher.
	 *
	 * Chatwoot's own bubble rendered about 40px off against our layout, and it
	 * only ever duplicated a control the header already had room for. The theme
	 * prints a button carrying data-chat-launcher, and logged-out visitors get the same
	 * button wired to the sign-in modal instead, which is what the old guest
	 * launcher did: opening the real widget to everyone fills the inbox with
	 * contacts that cannot be tied to an account.
	 *
	 * @return void
	 */
	public function guest_launcher_is_signin(): bool {
		return 'everyone' !== Settings::get( 'widget_scope', 'logged_in' ) && ! is_user_logged_in();
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
		if ( ! is_user_logged_in() ) {
			$bootstrap_url = add_query_arg(
				'lang',
				$this->locale(),
				rest_url( self::REST_NAMESPACE . '/bootstrap' )
			);
			?>
			<script id="cws-widget-loader">
			(function () {
				var loaded = false;
				function boot() {
					if ( loaded || window.loadChatwoot ) { return; }
					loaded = true;
					fetch(<?php echo wp_json_encode( esc_url_raw( $bootstrap_url ) ); ?>, {
						credentials: 'same-origin', cache: 'no-store',
						headers: { 'Accept': 'application/json' }
					})
						.then(function (r) { return r.ok ? r.json() : null; })
						.then(function (data) {
							if (!data || !data.html) { loaded = false; return; }
							var box = document.createElement('div');
							box.innerHTML = data.html;
							var script = box.querySelector('#cws-widget');
							if (!script) { loaded = false; return; }
							var live = document.createElement('script');
							live.id = script.id; live.textContent = script.textContent;
							document.body.appendChild(live);
							setTimeout(function () {
								if (window.loadChatwoot) { window.loadChatwoot(); }
							}, 0);
						})
						.catch(function () { loaded = false; });
				}
				window.addEventListener('auth:signed-in', boot);
			})();
			</script>
			<?php
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
			// The navbar button is the launcher. Without this the SDK draws its
			// own bubble on top of ours, 40px off, and we are back where we
			// started.
			'hideMessageBubble' => true,
			'showPopoutButton'  => false,
			// Overwritten client-side from the site's own theme. 'auto' follows
			// the operating system, which drifts from the site whenever the two
			// disagree — and any styling the theme layers over the widget then
			// stops matching what the widget actually renders.
			'darkMode'          => 'light',
			'useBrowserLanguage' => false,
			'locale'            => $locale,
			// Through the theme's translation layer, so both launchers read the
			// same and the plugin does not need its own catalogue.
			'launcherTitle'     => function_exists( 'pll__' )
				? pll__( 'Chat' )
				: __( 'Chat', 'chatwoot-woocommerce-sync' ),
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

			/*
			 * Identity is fetched before the SDK is injected. Chatwoot creates
			 * a guest contact as soon as the widget is allowed to send, so the
			 * old "load first, identify later" sequence had a race: a fast
			 * visitor could create an anonymous conversation before setUser().
			 *
			 * The promises also serialize hover, focus, click and idle-load
			 * callers. A failed or unauthenticated identity response never
			 * reaches chatwootSDK.run(), and a retry remains possible.
			 */
			var identityData = null;
			var identityPromise = null;
			var sdkPromise = null;
			var widgetReady = false;
			var chatwootReady = !!(window.$chatwoot && document.getElementById('chatwoot_live_chat_widget'));
			var chatwootReadyPromise = new Promise(function (resolve) {
				if (chatwootReady) { resolve(); return; }
				window.addEventListener('chatwoot:ready', function () {
					chatwootReady = true;
					resolve();
				}, { once: true });
			});

			function fetchIdentity() {
				if (identityData) { return Promise.resolve(identityData); }
				if (identityPromise) { return identityPromise; }

				identityPromise = fetch(IDENTITY_URL, {
					credentials: 'same-origin',
					cache: 'no-store',
					headers: { 'Accept': 'application/json' }
				})
					.then(function (r) { return r.ok ? r.json() : null; })
					.then(function (d) {
						if (!d || !d.identified || !d.identifier || !d.identifier_hash) {
							return null;
						}
						identityData = d;
						return d;
					})
					.catch(function () { return null; });

				// A transient network failure or an expired session must not
				// permanently poison this page; the next user action retries.
				identityPromise.then(function (d) {
					if (!d) { identityPromise = null; }
				});
				return identityPromise;
			}

			// Re-resolve identity after registration/login before booting Chatwoot.
			window.addEventListener('auth:signed-in', function () {
				identityData = null;
				identityPromise = null;
				// Re-identify an already booted SDK as well. A cached page or an
				// in-place login can leave Chatwoot mounted before the WP identity
				// becomes available; keeping widgetReady=true would otherwise make
				// loadChatwoot() return early and preserve the anonymous contact.
				widgetReady = false;
				sdkPromise = null;
				window.loadChatwoot();
			});

			function attachIdentity(d) {
				return new Promise(function (resolve, reject) {
					chatwootReadyPromise.then(function () {
						var tries = 0;
						(function attach() {
							if (window.$chatwoot && typeof window.$chatwoot.setUser === 'function') {
								try {
									window.$chatwoot.setUser(d.identifier, {
										name: d.name,
										email: d.email,
										phone_number: d.phone_number,
										identifier_hash: d.identifier_hash
									});
									widgetReady = true;
									resolve(true);
								} catch (e) {
									reject(e);
								}
								return;
							}
							if (tries++ < 40) {
								setTimeout(attach, 250);
								return;
							}
							reject(new Error('Chatwoot SDK did not become ready'));
						})();
					}, reject);
				});
			}

			window.loadChatwoot = function () {
				if (!hasConsent()) { return Promise.resolve(false); }
				if (widgetReady && window.$chatwoot) {
					// A previously mounted SDK may still point at an anonymous
					// contact. Revalidate and reapply the authenticated identity
					// before allowing the launcher to open it.
					return fetchIdentity().then(function (d) {
						if (!d) { return false; }
						// Do not reset here: Chatwoot's reset creates a new anonymous
						// visitor session and can detach the verified contact from the
						// conversation that the launcher is about to open.
						return attachIdentity(d).then(function () { return true; });
					});
				}
				if (sdkPromise) { return sdkPromise; }

				sdkPromise = fetchIdentity().then(function (d) {
					if (!d) { return false; }
					// A widget instance may have been booted by the pre-fix page
					// before the in-place login completed. Reuse it only after the
					// authenticated identity is available; never open it anonymously.
					if (window.$chatwoot && typeof window.$chatwoot.setUser === 'function') {
						return attachIdentity(d).then(function () {
							widgetReady = true;
							fyWireDrawerFx();
							return true;
						});
					}

					return new Promise(function (resolve, reject) {
						var s = document.createElement('script');
						s.src = BASE + '/packs/js/sdk.js';
						s.defer = true;
						s.async = true;
						s.onload = function () {
							if (!window.chatwootSDK) {
								reject(new Error('Chatwoot SDK unavailable'));
								return;
							}
							try {
								window.chatwootSDK.run({ websiteToken: TOKEN, baseUrl: BASE });
								attachIdentity(d).then(function () {
									fyWireDrawerFx();
									resolve(true);
								}, reject);
							} catch (e) {
								reject(e);
							}
						};
						// If the browser blocks or drops the SDK request, allow
						// a later call to retry instead of failing silently.
						s.onerror = function () {
							window.chatwootLoaded = false;
							reject(new Error('Chatwoot SDK failed to load'));
						};
						window.chatwootLoaded = true;
						document.head.appendChild(s);
					});
				}).then(function (ready) {
					if (!ready) { sdkPromise = null; }
					return ready;
				}, function () {
					window.chatwootLoaded = false;
					sdkPromise = null;
					return false;
				});

				return sdkPromise;
			};

			// Drawer FX: a near-silent synthesized whoosh on open, and inert on
			// the holder while it is closed so the off-screen chat stays out of
			// the a11y tree and the tab order.
			var fyWhooshCtx = null;
			function fyEnsureAudio() {
				var AC = window.AudioContext || window.webkitAudioContext;
				if (!AC) { return null; }
				if (!fyWhooshCtx) { fyWhooshCtx = new AC(); }
				if (fyWhooshCtx.state === 'suspended') { fyWhooshCtx.resume(); }
				return fyWhooshCtx;
			}
			function fyPlayWhoosh() {
				try {
					var ctx = fyEnsureAudio();
					if (!ctx) { return; }
					var t0 = ctx.currentTime;
					var dur = 0.3;
					var rate = ctx.sampleRate;
					var len = Math.max(1, Math.ceil(rate * dur));
					var buf = ctx.createBuffer(1, len, rate);
					var d = buf.getChannelData(0);
					// Pink noise (Paul Kellet filter): softer and airier than raw
					// white noise, so the swish reads as air rather than static.
					var b0 = 0, b1 = 0, b2 = 0, b3 = 0, b4 = 0, b5 = 0, b6 = 0;
					for (var i = 0; i < len; i++) {
						var w = Math.random() * 2 - 1;
						b0 = 0.99886 * b0 + w * 0.0555179;
						b1 = 0.99332 * b1 + w * 0.0750759;
						b2 = 0.96900 * b2 + w * 0.1538520;
						b3 = 0.86650 * b3 + w * 0.3104856;
						b4 = 0.55000 * b4 + w * 0.5329522;
						b5 = -0.7616 * b5 - w * 0.0168980;
						d[i] = (b0 + b1 + b2 + b3 + b4 + b5 + b6 + w * 0.5362) * 0.4;
						b6 = w * 0.115926;
					}
					var src = ctx.createBufferSource();
					src.buffer = buf;
					// Classic swish: bandpass noise sweeping upward in pitch.
					var bp = ctx.createBiquadFilter();
					bp.type = 'bandpass';
					bp.Q.value = 1.1;
					bp.frequency.setValueAtTime(320, t0);
					bp.frequency.exponentialRampToValueAtTime(2800, t0 + dur);
					var hp = ctx.createBiquadFilter();
					hp.type = 'highpass';
					hp.frequency.value = 140;
					var g = ctx.createGain();
					g.gain.setValueAtTime(0.0001, t0);
					g.gain.exponentialRampToValueAtTime(0.06, t0 + 0.025);
					g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
					src.connect(bp);
					bp.connect(hp);
					hp.connect(g);
					g.connect(ctx.destination);
					src.start(t0);
					src.stop(t0 + dur);
				} catch (e) { /* sound is best-effort */ }
			}
			var fyDrawerFx = false;
			function fyWireDrawerFx() {
				if (fyDrawerFx) { return; }
				var holder = document.getElementById('cw-widget-holder');
				if (!holder || !window.MutationObserver) { return; }
				fyDrawerFx = true;

				// Pin the drawer under the navbar. The SDK ships a mobile media
				// query (.woot-widget-holder { top:0; height:100% } at <=667px)
				// that our stylesheet rule ought to beat, but in practice the
				// cascade loses to the SDK in enough setups that the drawer ends
				// up covering the navbar. Inline !important wins against both the
				// class rule and the stylesheet, so the offset is enforced from
				// the live header height instead of prayed for in CSS. Reads the
				// same --header-height the site already publishes (it already
				// counts the admin bar), falling back to the measured header.
				var headerEl = document.querySelector('#site-header, header.sticky');
				var pinOffset = function () {
					var h = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--header-height')) || 0;
					if (!h && headerEl) { h = headerEl.getBoundingClientRect().height + headerEl.getBoundingClientRect().top; }
					if (!h) { h = 64; }
					holder.style.setProperty('top', h + 'px', 'important');
					holder.style.setProperty('height', 'calc(100% - ' + h + 'px)', 'important');
					holder.style.setProperty('max-height', 'calc(100vh - ' + h + 'px)', 'important');
				};
				pinOffset();
				if (window.ResizeObserver && headerEl) {
					new ResizeObserver(pinOffset).observe(headerEl);
				}
				window.addEventListener('resize', pinOffset, { passive: true });

				var onClassChange = function () {
					var closed = holder.classList.contains('woot--hide');
					holder.inert = closed;
					if (!closed) {
						pinOffset();
						fyPlayWhoosh();
					}
				};
				holder.inert = holder.classList.contains('woot--hide');
				new MutationObserver(onClassChange).observe(holder, {
					attributes: true,
					attributeFilter: ['class']
				});
			}
			window.addEventListener('chatwoot:ready', fyWireDrawerFx);

			function openChat() {
				window.loadChatwoot().then(function (ready) {
					if (ready && window.$chatwoot) {
						window.$chatwoot.toggle('open');
					}
				});
			}

			function bindLauncher(btn) {
				if (btn.__cwsChatBound) { return; }
				btn.__cwsChatBound = true;
				['pointerenter', 'focus'].forEach(function (ev) {
					btn.addEventListener(ev, function () { window.loadChatwoot(); }, { once: true, passive: true });
				});
				btn.addEventListener('click', function (e) {
					// A guest launcher belongs to the auth modal. Never cancel it or
					// boot the anonymous WebWidget.
					if (btn.hasAttribute('data-login-trigger') && !identityData) { return; }
					e.preventDefault();
					fyEnsureAudio();
					openChat();
				});
			}
			function bindAll() {
				document.querySelectorAll('[data-chat-launcher]').forEach(bindLauncher);
			}
			var launcherObserver = new MutationObserver(bindAll);
			launcherObserver.observe(document.documentElement, {
				attributes: true,
				attributeFilter: ['data-chat-launcher', 'data-login-trigger'],
				childList: true,
				subtree: true
			});
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', bindAll, { once: true });
			} else {
				bindAll();
			}

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
			if ( apply_filters( 'cws_autoload_widget', is_user_logged_in() ) ) :
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
