<?php
/**
 * Turn site-side messages (contact forms) into Chatwoot conversations.
 *
 * @package ChatwootWooSync
 */

namespace ChatwootWooSync;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Conversation creation helpers.
 */
class Conversations {

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
		/**
		 * Programmatic entry point for themes and other plugins.
		 *
		 * do_action( 'cws_create_conversation', [ 'email' => …, 'message' => … ] );
		 */
		add_action( 'cws_create_conversation', array( $this, 'create' ), 10, 1 );
	}

	/**
	 * Create a conversation for an inbound message.
	 *
	 * @param array $args {
	 *     Message details.
	 *
	 *     @type string $email    Sender e-mail. Required.
	 *     @type string $message  Message body. Required.
	 *     @type string $name     Sender name.
	 *     @type string $phone    Sender phone.
	 *     @type string $subject  Conversation subject.
	 *     @type int    $inbox_id Target inbox; defaults to the configured e-mail inbox.
	 *     @type array  $labels   Conversation labels.
	 *     @type string $source   Free-form origin label stored on the contact.
	 * }
	 * @return int|WP_Error Conversation ID.
	 */
	public function create( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'email'    => '',
				'message'  => '',
				'name'     => '',
				'phone'    => '',
				'subject'  => '',
				'inbox_id' => (int) Settings::get( 'email_inbox_id' ),
				'labels'   => array(),
				'source'   => 'Website',
			)
		);

		$email = Identity::normalize_email( (string) $args['email'] );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'cws_bad_email', __( 'A valid e-mail address is required.', 'chatwoot-woocommerce-sync' ) );
		}
		if ( '' === trim( (string) $args['message'] ) ) {
			return new WP_Error( 'cws_empty_message', __( 'The message is empty.', 'chatwoot-woocommerce-sync' ) );
		}
		$inbox_id = (int) $args['inbox_id'];
		if ( $inbox_id < 1 ) {
			return new WP_Error( 'cws_no_inbox', __( 'No target inbox configured.', 'chatwoot-woocommerce-sync' ) );
		}

		$existing = get_user_by( 'email', $email );

		$contact_id = Identity::resolve(
			$email,
			(string) $args['name'],
			(string) $args['phone'],
			array( 'source' => (string) $args['source'] ),
			$existing ? (int) $existing->ID : 0
		);
		if ( is_wp_error( $contact_id ) ) {
			return $contact_id;
		}

		$attributes = array();
		if ( '' !== trim( (string) $args['subject'] ) ) {
			$attributes['subject'] = (string) $args['subject'];
		}

		return Client::instance()->create_conversation(
			$inbox_id,
			(int) $contact_id,
			uniqid( 'web_', true ),
			(string) $args['message'],
			(array) $args['labels'],
			$attributes
		);
	}
}
