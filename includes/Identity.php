<?php
/**
 * Canonical contact identity.
 *
 * Every path that touches Chatwoot — widget, user sync, contact form — uses the
 * e-mail address as the single canonical identifier. Mixing identifier schemes
 * (user_login here, e-mail there, nothing in the widget) is what produces
 * duplicate and unlinkable contacts.
 *
 * @package ChatwootWooSync
 */

namespace ChatwootWooSync;

defined( 'ABSPATH' ) || exit;

/**
 * Identity resolution helpers.
 */
class Identity {

	const META_CONTACT_ID = 'chatwoot_contact_id';
	const META_SYNC_HASH  = '_cws_sync_hash';

	/**
	 * Meta keys consulted for a user's phone number, in priority order.
	 *
	 * @var string[]
	 */
	const PHONE_META_KEYS = array(
		'otpress_phone',
		'digits_phone',
		'billing_phone',
	);

	/**
	 * The canonical identifier for a user: their e-mail, lower-cased.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Empty string when the user has no e-mail.
	 */
	public static function identifier_for_user( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return '';
		}
		return self::normalize_email( $user->user_email );
	}

	/**
	 * Normalise an e-mail for use as an identifier.
	 *
	 * @param string $email Raw address.
	 * @return string
	 */
	public static function normalize_email( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * HMAC used by Chatwoot identity validation.
	 *
	 * @param string $identifier Canonical identifier.
	 * @return string Empty string when no HMAC token is configured.
	 */
	public static function hmac( string $identifier, string $secret = '' ): string {
		if ( '' === $secret ) {
			$secret = (string) Settings::get( 'hmac_token' );
		}
		if ( '' === $secret || '' === $identifier ) {
			return '';
		}
		return hash_hmac( 'sha256', $identifier, $secret );
	}

	/**
	 * Format a phone number as E.164, or return an empty string.
	 *
	 * @param string $phone Raw phone number.
	 * @return string
	 */
	public static function format_phone( string $phone ): string {
		if ( '' === trim( $phone ) ) {
			return '';
		}
		$phone = preg_replace( '/[^0-9+]/', '', $phone );
		if ( '' === (string) $phone ) {
			return '';
		}
		if ( '+' !== substr( $phone, 0, 1 ) ) {
			$phone = '+' . $phone;
		}
		return preg_match( '/^\+[1-9]\d{6,14}$/', $phone ) ? $phone : '';
	}

	/**
	 * Best known phone number for a user, in E.164.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	public static function phone_for_user( int $user_id ): string {
		foreach ( self::PHONE_META_KEYS as $key ) {
			$value = (string) get_user_meta( $user_id, $key, true );
			if ( '' !== trim( $value ) ) {
				$formatted = self::format_phone( $value );
				if ( '' !== $formatted ) {
					return $formatted;
				}
			}
		}

		// Legacy Digits stored the country code separately from the number.
		$cc     = (string) get_user_meta( $user_id, 'digt_countrycode', true );
		$number = (string) get_user_meta( $user_id, 'digits_phone_no', true );
		if ( '' !== $cc && '' !== $number ) {
			return self::format_phone( $cc . $number );
		}

		return '';
	}

	/**
	 * Display name for a user, preferring their real name.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	public static function name_for_user( int $user_id ): string {
		$first = (string) get_user_meta( $user_id, 'first_name', true );
		$last  = (string) get_user_meta( $user_id, 'last_name', true );
		$name  = trim( $first . ' ' . $last );
		if ( '' !== $name ) {
			return $name;
		}
		$user = get_userdata( $user_id );
		return $user ? (string) $user->display_name : '';
	}

	/**
	 * Resolve a Chatwoot contact ID for an arbitrary person, creating one if needed.
	 *
	 * Resolution order is deterministic: stored ID, then e-mail, then phone,
	 * then create.
	 *
	 * @param string $email      E-mail address.
	 * @param string $name       Display name.
	 * @param string $phone      Phone number in any format.
	 * @param array  $attributes Custom attributes to store on the contact.
	 * @param int    $user_id    Optional WordPress user the contact belongs to.
	 * @return int|\WP_Error Contact ID.
	 */
	public static function resolve( string $email, string $name = '', string $phone = '', array $attributes = array(), int $user_id = 0 ) {
		$email      = self::normalize_email( $email );
		$phone      = self::format_phone( $phone );
		$client     = Client::instance();
		$contact_id = 0;

		if ( $user_id > 0 ) {
			$contact_id = (int) get_user_meta( $user_id, self::META_CONTACT_ID, true );
		}

		if ( ! $contact_id && '' !== $email ) {
			foreach ( $client->search_contacts( $email ) as $candidate ) {
				if ( isset( $candidate['email'] ) && self::normalize_email( (string) $candidate['email'] ) === $email ) {
					$contact_id = (int) $candidate['id'];
					break;
				}
			}
		}

		if ( ! $contact_id && '' !== $phone ) {
			foreach ( $client->search_contacts( $phone ) as $candidate ) {
				if ( isset( $candidate['phone_number'] ) && self::format_phone( (string) $candidate['phone_number'] ) === $phone ) {
					$contact_id = (int) $candidate['id'];
					break;
				}
			}
		}

		$payload = array_filter(
			array(
				'name'              => $name,
				'email'             => $email,
				'phone_number'      => $phone,
				'identifier'        => $email,
				'custom_attributes' => $attributes,
			),
			static fn( $value ) => '' !== $value && array() !== $value
		);

		if ( $contact_id ) {
			$result = $client->update_contact( $contact_id, $payload );
		} else {
			$result = $client->create_contact( $payload );

			// A concurrent request may have created the contact first; the API
			// reports the e-mail as taken. Look it up rather than giving up.
			if ( is_wp_error( $result ) && '' !== $email ) {
				foreach ( $client->search_contacts( $email ) as $candidate ) {
					if ( isset( $candidate['email'] ) && self::normalize_email( (string) $candidate['email'] ) === $email ) {
						$result = (int) $candidate['id'];
						break;
					}
				}
			}
		}

		if ( ! is_wp_error( $result ) && $user_id > 0 ) {
			update_user_meta( $user_id, self::META_CONTACT_ID, (int) $result );
		}

		return $result;
	}
}
