<?php
/**
 * Twenty -> Omnisend, immediately.
 *
 * Twenty posts company.* and person.* events to
 *   POST /wp-json/qc-twenty/v1/webhook
 * (Settings -> APIs & Webhooks -> Webhooks in Twenty).
 *
 * The request is authenticated with Twenty's signature: HMAC SHA256 over
 * "{timestamp}:{raw body}" with the webhook secret, sent in
 * X-Twenty-Webhook-Signature / X-Twenty-Webhook-Timestamp. The secret is read
 * from the QC_TWENTY_WEBHOOK_SECRET constant. With no secret defined the
 * endpoint refuses every request.
 *
 * The payload is only used to learn WHAT changed. The job re-reads the record
 * from Twenty, so a forged or stale payload cannot write anything to Omnisend.
 * Jobs are delayed a few seconds so a burst of edits to one record collapses
 * into a single sync.
 */

defined( 'ABSPATH' ) || exit;

class QC_Twenty_Webhook {

	const NAMESPACE_  = 'qc-twenty/v1';
	const HOOK        = 'qc_twenty_webhook_job';
	const DELAY       = 20;   // Seconds. Also the de-dupe window per record.
	const MAX_SKEW    = 600;  // Seconds either side of now.

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( self::HOOK, array( __CLASS__, 'process' ), 10, 4 );
	}

	public static function register_route() {
		register_rest_route(
			self::NAMESPACE_,
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Authentication is the HMAC check inside handle().
				'permission_callback' => '__return_true',
			)
		);
	}

	/** Public URL to paste into Twenty. */
	public static function url() {
		return rest_url( self::NAMESPACE_ . '/webhook' );
	}

	/**
	 * Verify a request. Pure, so it can be tested without HTTP.
	 *
	 * @return true|WP_Error
	 */
	public static function verify( $body, $timestamp, $signature, $secret, $now = null ) {
		if ( '' === (string) $secret ) {
			return new WP_Error( 'qc_twenty_no_secret', 'QC_TWENTY_WEBHOOK_SECRET is not defined.', array( 'status' => 503 ) );
		}
		if ( '' === (string) $timestamp || '' === (string) $signature ) {
			return new WP_Error( 'qc_twenty_unsigned', 'Missing signature.', array( 'status' => 401 ) );
		}

		$expected = hash_hmac( 'sha256', $timestamp . ':' . $body, $secret );
		if ( ! hash_equals( $expected, strtolower( trim( $signature ) ) ) ) {
			return new WP_Error( 'qc_twenty_bad_signature', 'Bad signature.', array( 'status' => 401 ) );
		}

		// Reject replays. Twenty may send seconds, milliseconds or an ISO date.
		$sent = is_numeric( $timestamp ) ? (float) $timestamp : (float) strtotime( $timestamp );
		if ( $sent > 100000000000 ) {
			$sent /= 1000;
		}
		$now = null === $now ? time() : $now;
		if ( abs( $now - $sent ) > self::MAX_SKEW ) {
			return new WP_Error( 'qc_twenty_stale', 'Timestamp outside the allowed window.', array( 'status' => 401 ) );
		}

		return true;
	}

	/** REST callback. Authenticate, validate, queue, return fast. */
	public static function handle( WP_REST_Request $request ) {
		$secret = defined( 'QC_TWENTY_WEBHOOK_SECRET' ) ? QC_TWENTY_WEBHOOK_SECRET : '';
		$ok     = self::verify(
			$request->get_body(),
			(string) $request->get_header( 'x_twenty_webhook_timestamp' ),
			(string) $request->get_header( 'x_twenty_webhook_signature' ),
			$secret
		);
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		$json  = json_decode( $request->get_body(), true );
		$event = is_array( $json ) ? (string) ( $json['event'] ?? $json['eventName'] ?? '' ) : '';
		$data  = is_array( $json ) ? ( $json['data'] ?? $json['record'] ?? array() ) : array();

		$parts = explode( '.', $event, 2 );
		if ( 2 !== count( $parts ) || ! in_array( $parts[0], array( 'company', 'person' ), true ) || empty( $data['id'] ) ) {
			return rest_ensure_response( array( 'ignored' => true ) ); // 200, so Twenty does not retry.
		}

		list( $object, $action ) = $parts;
		$id    = sanitize_text_field( (string) $data['id'] );
		$email = ( 'person' === $object && ! empty( $data['emails']['primaryEmail'] ) ) ? sanitize_email( $data['emails']['primaryEmail'] ) : '';

		// One pending job per record. The job reads fresh data, so edits that land
		// inside the window are included in it.
		$lock = 'qc_twh_' . md5( $object . $id );
		if ( get_transient( $lock ) && 'deleted' !== $action ) {
			return rest_ensure_response( array( 'queued' => false, 'reason' => 'already queued' ) );
		}
		set_transient( $lock, 1, self::DELAY + 5 );

		$args = array( $object, $id, $action, $email );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + self::DELAY, self::HOOK, $args, 'qc-twenty-sync' );
		} else {
			wp_schedule_single_event( time() + self::DELAY, self::HOOK, $args );
		}

		return rest_ensure_response( array( 'queued' => true ) );
	}

	/**
	 * Job: re-read the record from Twenty and sync the affected contacts.
	 *
	 * @param string $object company|person.
	 * @param string $id     Twenty record id.
	 * @param string $action created|updated|deleted|...
	 * @param string $email  Person email from the payload, used only for deletes.
	 */
	public static function process( $object, $id, $action, $email = '' ) {
		$twenty = QC_Twenty_Client::from_config();
		$omni   = QC_Omnisend_Client::from_config();
		if ( is_wp_error( $twenty ) || is_wp_error( $omni ) ) {
			return;
		}

		if ( 'company' === $object ) {
			self::process_company( $twenty, $omni, $id );
			return;
		}
		self::process_person( $twenty, $omni, $id, $action, $email );
	}

	private static function process_company( QC_Twenty_Client $twenty, QC_Omnisend_Client $omni, $id ) {
		$res = $twenty->get_record( 'companies', $id );
		if ( ! empty( $res['error'] ) ) {
			return;
		}

		// Gone from Twenty: treat as no longer eligible.
		$company = $res['body'] ? $res['body'] : array( 'id' => $id, 'lifecycleStage' => '' );
		QC_Omnisend_Sync::sync_company( $twenty, $omni, $company );
	}

	private static function process_person( QC_Twenty_Client $twenty, QC_Omnisend_Client $omni, $id, $action, $hint_email ) {
		$res = $twenty->get_record( 'people', $id );
		if ( ! empty( $res['error'] ) ) {
			return;
		}
		$person = $res['body'];

		// Deleted: the record is gone, so fall back to the email in the payload.
		if ( ! $person || ! empty( $person['deletedAt'] ) ) {
			if ( is_email( $hint_email ) && isset( QC_Omnisend_Sync::tracked()[ strtolower( $hint_email ) ] ) ) {
				QC_Omnisend_Sync::untag_email( $omni, $hint_email );
			}
			return;
		}

		$email = $person['emails']['primaryEmail'] ?? '';
		if ( ! is_email( $email ) ) {
			return;
		}

		$company = ! empty( $person['companyId'] ) ? $twenty->get_record( 'companies', $person['companyId'] ) : null;
		$company = ( $company && empty( $company['error'] ) ) ? $company['body'] : null;

		$eligible = $company && ( $company['lifecycleStage'] ?? '' ) === QC_Omnisend_Sync::eligible_stage();
		if ( $eligible ) {
			QC_Omnisend_Sync::push_contact( $omni, $person, $company );
		} elseif ( isset( QC_Omnisend_Sync::tracked()[ strtolower( $email ) ] ) ) {
			QC_Omnisend_Sync::untag_email( $omni, $email );
		}

		// An email change leaves the old address tagged. The reconcile untags it;
		// the payload does not carry the previous value, so nothing more to do here.
	}
}

QC_Twenty_Webhook::init();
