<?php
/**
 * HTTP client for the Chatwoot application API.
 *
 * @package ChatwootWooSync
 */

namespace ChatwootWooSync;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Thin wrapper around wp_remote_* with retries and logging.
 */
class Client {

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
	 * Perform a request against the account-scoped API.
	 *
	 * @param string     $method HTTP verb.
	 * @param string     $path   Path relative to the account base, e.g. 'contacts'.
	 * @param array|null $body   Request body, JSON encoded when present.
	 * @param array      $query  Query string arguments.
	 * @return array|WP_Error Decoded response body, or error.
	 */
	public function request( string $method, string $path, ?array $body = null, array $query = array() ) {
		$token = (string) Settings::get( 'api_token' );
		if ( '' === $token ) {
			return new WP_Error( 'cws_no_token', __( 'No Chatwoot API token configured.', 'chatwoot-woocommerce-sync' ) );
		}

		$url = Settings::api_base() . '/' . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			// Hyphenated header name: Rack normalises it to the same value as
			// api_access_token, but underscored headers are dropped by some
			// reverse proxies (Caddy, and nginx by default).
			'headers' => array(
				'api-access-token' => $token,
				'Content-Type'     => 'application/json',
				'Accept'           => 'application/json',
			),
			// TLS verification stays on. Never disable it: the API token travels
			// in the request headers and would be exposed to a MITM.
			'sslverify' => true,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$attempts = 0;
		$last     = null;
		while ( $attempts < 3 ) {
			$attempts++;
			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$last = $response;
				usleep( 250000 * $attempts );
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );

			// Retry on rate limiting and transient upstream failures only.
			if ( 429 === $code || $code >= 500 ) {
				$last = new WP_Error( 'cws_http_' . $code, sprintf( 'HTTP %d: %s', $code, substr( $raw, 0, 200 ) ) );
				usleep( 500000 * $attempts );
				continue;
			}

			$decoded = json_decode( $raw, true );

			if ( $code >= 400 ) {
				$message = is_array( $decoded ) ? ( $decoded['message'] ?? $decoded['error'] ?? $raw ) : $raw;
				$error   = new WP_Error( 'cws_http_' . $code, sprintf( 'HTTP %d: %s', $code, substr( (string) $message, 0, 300 ) ) );
				Log::write( $method, $path, 'error', $error->get_error_message() );
				return $error;
			}

			Log::write( $method, $path, 'ok', (string) $code );
			return is_array( $decoded ) ? $decoded : array();
		}

		$error = $last ?: new WP_Error( 'cws_request_failed', __( 'Request failed.', 'chatwoot-woocommerce-sync' ) );
		Log::write( $method, $path, 'error', $error->get_error_message() );
		return $error;
	}

	/**
	 * Cheap connectivity/credential check.
	 *
	 * @return true|WP_Error
	 */
	public function ping() {
		$result = $this->request( 'GET', 'contacts', null, array( 'page' => '1' ) );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Search contacts by an arbitrary query string.
	 *
	 * @param string $query Email, phone or name fragment.
	 * @return array<int, array> Matching contact payloads.
	 */
	public function search_contacts( string $query ): array {
		if ( '' === trim( $query ) ) {
			return array();
		}
		$result = $this->request( 'GET', 'contacts/search', null, array( 'q' => $query ) );
		if ( is_wp_error( $result ) ) {
			return array();
		}
		return is_array( $result['payload'] ?? null ) ? $result['payload'] : array();
	}

	/**
	 * Create a contact.
	 *
	 * @param array $data Contact attributes.
	 * @return int|WP_Error Contact ID.
	 */
	public function create_contact( array $data ) {
		$result = $this->request( 'POST', 'contacts', $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$id = $result['payload']['contact']['id'] ?? ( $result['payload']['id'] ?? null );
		if ( ! $id ) {
			return new WP_Error( 'cws_no_contact_id', __( 'Chatwoot did not return a contact ID.', 'chatwoot-woocommerce-sync' ) );
		}
		return (int) $id;
	}

	/**
	 * Update a contact.
	 *
	 * @param int   $contact_id Chatwoot contact ID.
	 * @param array $data       Attributes to change.
	 * @return int|WP_Error Contact ID.
	 */
	public function update_contact( int $contact_id, array $data ) {
		$result = $this->request( 'PUT', 'contacts/' . $contact_id, $data );

		// A phone number already claimed by another contact must not abort the
		// whole update: retry without it so the rest of the payload lands.
		if ( is_wp_error( $result ) && isset( $data['phone_number'] ) && false !== stripos( $result->get_error_message(), 'has already been taken' ) ) {
			unset( $data['phone_number'] );
			$result = $this->request( 'PUT', 'contacts/' . $contact_id, $data );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $contact_id;
	}

	/**
	 * Create a conversation and post its first inbound message.
	 *
	 * @param int    $inbox_id   Target inbox.
	 * @param int    $contact_id Contact the conversation belongs to.
	 * @param string $source_id  Unique source identifier for the conversation.
	 * @param string $message    Message body.
	 * @param array  $labels     Conversation labels.
	 * @param array  $attributes Custom attributes (e.g. subject).
	 * @return int|WP_Error Conversation ID.
	 */
	public function create_conversation( int $inbox_id, int $contact_id, string $source_id, string $message, array $labels = array(), array $attributes = array() ) {
		$payload = array(
			'source_id'  => $source_id,
			'inbox_id'   => $inbox_id,
			'contact_id' => $contact_id,
			'status'     => 'open',
		);
		if ( $attributes ) {
			$payload['custom_attributes'] = $attributes;
		}
		if ( $labels ) {
			$payload['labels'] = $labels;
		}

		$conversation = $this->request( 'POST', 'conversations', $payload );
		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}
		$conversation_id = (int) ( $conversation['id'] ?? 0 );
		if ( ! $conversation_id ) {
			return new WP_Error( 'cws_no_conversation_id', __( 'Chatwoot did not return a conversation ID.', 'chatwoot-woocommerce-sync' ) );
		}

		// Labels are applied separately: some inboxes ignore them on create.
		if ( $labels ) {
			$this->request( 'POST', 'conversations/' . $conversation_id . '/labels', array( 'labels' => $labels ) );
		}

		// message_type 0 (integer) posts an inbound message without requiring an
		// API-type inbox, which the string form ('incoming') does.
		$posted = $this->request(
			'POST',
			'conversations/' . $conversation_id . '/messages',
			array(
				'content'      => $message,
				'message_type' => 0,
				'private'      => false,
			)
		);
		if ( is_wp_error( $posted ) ) {
			return $posted;
		}

		return $conversation_id;
	}
}
