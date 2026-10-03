<?php
/**
 * Omnisend API client — contacts only.
 *
 * Auth:  Authorization: Omnisend-API-Key <key>
 * Header: Omnisend-Version: 2026-03-15
 * Base:  https://api.omnisend.com/api
 *
 * Consent rule: this client NEVER sets a channel status unless the caller
 * passes one. We must not subscribe anyone. Omnisend keeps the existing
 * subscription state when the channel block is absent.
 */

defined( 'ABSPATH' ) || exit;

class QC_Omnisend_Client {

	const API_VERSION = '2026-03-15';

	/** @var string */
	private $base;

	/** @var string */
	private $key;

	/** @var int */
	private $timeout = 20;

	public function __construct( $base, $key ) {
		$this->base = untrailingslashit( $base );
		$this->key  = $key;
	}

	/**
	 * Build a client from configuration.
	 *
	 * The key is read from the QC_OMNISEND_API_KEY constant. Never stored in
	 * the database and never logged.
	 *
	 * @return QC_Omnisend_Client|WP_Error
	 */
	public static function from_config() {
		$base = apply_filters( 'qc_omnisend_base_url', 'https://api.omnisend.com/api' );
		$key  = defined( 'QC_OMNISEND_API_KEY' ) ? QC_OMNISEND_API_KEY : '';
		$key  = apply_filters( 'qc_omnisend_api_key', $key );

		if ( empty( $key ) ) {
			return new WP_Error( 'qc_omnisend_no_key', 'QC_OMNISEND_API_KEY is not defined.' );
		}

		return new self( $base, $key );
	}

	/**
	 * Perform a request.
	 *
	 * @return array{code:int,body:mixed,error:string}
	 */
	public function request( $method, $path, $body = null ) {
		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => $this->timeout,
			'headers' => array(
				'Authorization'    => 'Omnisend-API-Key ' . $this->key,
				'Omnisend-Version' => self::API_VERSION,
				'Accept'           => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base . $path, $args );

		if ( is_wp_error( $response ) ) {
			return array( 'code' => 0, 'body' => null, 'error' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		return array(
			'code'  => $code,
			'body'  => json_decode( $raw, true ),
			'error' => ( $code >= 200 && $code < 300 ) ? '' : substr( (string) $raw, 0, 500 ),
		);
	}

	/**
	 * Upsert a contact by email.
	 *
	 * POST /contacts creates, or updates when the email already exists.
	 * Returns 201 on create, 200 on update.
	 *
	 * Consent rule: Omnisend requires the email channel on an identifier, so we
	 * cannot omit it. Instead we read the contact first and send its existing
	 * channel back **unchanged**. A contact we have never seen is created as
	 * nonSubscribed — known, not subscribed, no marketing sent.
	 *
	 * @param string $email Primary email.
	 * @param array  $data  Additional fields: firstName, lastName, country,
	 *                      countryCode, city, tags, customProperties.
	 */
	public function upsert_contact( $email, array $data ) {
		$existing = $this->existing_contact( $email );

		$channel = null;
		$tags    = array();

		if ( $existing ) {
			foreach ( ( $existing['identifiers'] ?? array() ) as $identifier ) {
				if ( 'email' === ( $identifier['type'] ?? '' ) && 0 === strcasecmp( (string) ( $identifier['id'] ?? '' ), (string) $email ) ) {
					$channel = $identifier['channels']['email'] ?? null;
				}
			}
			$tags = is_array( $existing['tags'] ?? null ) ? $existing['tags'] : array();
		}

		// Merge tags. Omnisend REPLACES the tag array on write, so we keep the
		// existing tags and add ours. Never drop someone else's tags.
		if ( ! empty( $data['tags'] ) ) {
			$tags = array_values( array_unique( array_merge( $tags, (array) $data['tags'] ) ) );
		}
		if ( $tags ) {
			$data['tags'] = $tags;
		} else {
			unset( $data['tags'] );
		}

		$identifier = array(
			'type'     => 'email',
			'id'       => $email,
			'channels' => array(
				'email' => $channel ? $channel : array( 'status' => 'nonSubscribed' ),
			),
		);

		$body = array_merge( array( 'identifiers' => array( $identifier ) ), $data );

		return $this->request( 'POST', '/contacts', $body );
	}

	/**
	 * Remove one tag from a contact, leaving every other tag and the consent
	 * channel exactly as they are. A contact Omnisend does not know is a no-op.
	 *
	 * Omnisend replaces the tag array on write, so we send back the remainder.
	 * An empty array is sent when ours was the only tag. Optional custom
	 * properties (e.g. the new lifecycle stage) ride along in the same write.
	 */
	public function remove_tag( $email, $tag, array $custom_properties = array() ) {
		$existing = $this->existing_contact( $email );
		if ( ! $existing ) {
			return array( 'code' => 200, 'body' => null, 'error' => '', 'note' => 'unknown contact' );
		}

		$tags = is_array( $existing['tags'] ?? null ) ? $existing['tags'] : array();
		if ( ! in_array( $tag, $tags, true ) ) {
			return array( 'code' => 200, 'body' => null, 'error' => '', 'note' => 'tag not present' );
		}

		$channel = null;
		foreach ( ( $existing['identifiers'] ?? array() ) as $identifier ) {
			if ( 'email' === ( $identifier['type'] ?? '' ) && 0 === strcasecmp( (string) ( $identifier['id'] ?? '' ), (string) $email ) ) {
				$channel = $identifier['channels']['email'] ?? null;
			}
		}

		$body = array(
			'identifiers' => array(
				array(
					'type'     => 'email',
					'id'       => $email,
					'channels' => array( 'email' => $channel ? $channel : array( 'status' => 'nonSubscribed' ) ),
				),
			),
			'tags'        => array_values( array_diff( $tags, array( $tag ) ) ),
		);
		if ( $custom_properties ) {
			$body['customProperties'] = $custom_properties;
		}

		return $this->request( 'POST', '/contacts', $body );
	}

	/** The contact as Omnisend holds it, or null when new. */
	private function existing_contact( $email ) {
		$res = $this->find_contact_by_email( $email );
		return $res['body']['contacts'][0] ?? null;
	}

	/** Look up a contact by email. Read scope required. */
	public function find_contact_by_email( $email ) {
		return $this->request( 'GET', '/contacts?email=' . rawurlencode( $email ) );
	}
}
