<?php
/**
 * Plugin Name:       Chatwoot WooCommerce Sync
 * Plugin URI:        https://github.com/
 * Description:       Two-way sync between WooCommerce/WordPress and Chatwoot: identified live-chat (HMAC), contact sync, and contact-form conversations.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Chatwoot WooCommerce Sync contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       chatwoot-woocommerce-sync
 *
 * @package ChatwootWooSync
 */

namespace ChatwootWooSync;

defined( 'ABSPATH' ) || exit;

define( 'CWS_VERSION', '1.0.0' );
define( 'CWS_FILE', __FILE__ );
define( 'CWS_PATH', plugin_dir_path( __FILE__ ) );
define( 'CWS_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4-ish autoloader for the plugin namespace.
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( __NAMESPACE__ ) + 1 );
		$file     = CWS_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Boot the plugin once all plugins are loaded.
 */
function boot() {
	Settings::instance()->hooks();

	// Nothing else runs until the install is configured.
	if ( ! Settings::is_configured() ) {
		return;
	}

	Widget::instance()->hooks();
	Sync::instance()->hooks();
	Conversations::instance()->hooks();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );

/**
 * Create the log table on activation.
 */
function activate() {
	Log::install_table();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Clear scheduled work on deactivation.
 */
function deactivate() {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( Sync::ACTION_SYNC_CONTACT );
	}
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
