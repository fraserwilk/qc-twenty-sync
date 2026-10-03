<?php
/**
 * Phase 2: Twenty (active retailers) -> Omnisend contacts.
 *
 * A scheduled reconciliation, not an event hook. It reads Twenty for Companies
 * at lifecycleStage ACTIVE, walks their People, and upserts each contact into
 * Omnisend with the retailer tag plus CRM attributes.
 *
 * Consent is never touched. We never send a channel status, so Omnisend keeps
 * whatever the contact already has.
 */

defined( 'ABSPATH' ) || exit;

class QC_Omnisend_Sync {

	const HOOK         = 'qc_omnisend_reconcile';
	const SCHEDULE     = 'qc_omnisend_hourly';
	const TAG          = 'qc_retailer_active';
	const PAGE         = 60;
	const MAX_PER_RUN  = 200;
	const TRACKED      = 'qc_omnisend_tracked'; // email (lower) => Twenty company id we tagged it under.
	const CURSOR       = 'qc_omnisend_cursor';  // Next contact index for the rotating window.

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'reconcile' ) );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 600, self::SCHEDULE, self::HOOK );
		}
	}

	public static function schedules( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => HOUR_IN_SECONDS,
			'display'  => 'Once an hour (QC Omnisend)',
		);
		return $schedules;
	}

	/** Remove the scheduled event. Called on deactivation. */
	public static function unschedule() {
		$ts = wp_next_scheduled( self::HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
			$ts = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Eligibility: Company lifecycle stage that should be tagged as a retailer.
	 * Filterable, so Live can differ from Dev.
	 */
	public static function eligible_stage() {
		return apply_filters( 'qc_omnisend_eligible_stage', 'ACTIVE' );
	}

	/**
	 * Run the sync.
	 *
	 * Builds the full, ordered list of eligible contacts, then pushes a window of
	 * MAX_PER_RUN starting at a stored cursor. The cursor wraps, so every contact
	 * is reached however many are eligible. Afterwards any contact we tagged that
	 * is no longer eligible is untagged.
	 *
	 * @param bool $dry_run When true, build the plan and write nothing.
	 * @return array{plan:array[],untag:array[],written:int,errors:array[],dry_run:bool,total:int,cursor:int}
	 */
	public static function reconcile( $dry_run = false ) {
		$out = array( 'plan' => array(), 'untag' => array(), 'written' => 0, 'errors' => array(), 'dry_run' => (bool) $dry_run, 'total' => 0, 'cursor' => 0 );

		$twenty = QC_Twenty_Client::from_config();
		if ( is_wp_error( $twenty ) ) {
			$out['errors'][] = 'twenty: ' . $twenty->get_error_message();
			return $out;
		}

		$omni = QC_Omnisend_Client::from_config();
		if ( is_wp_error( $omni ) ) {
			$out['errors'][] = 'omnisend: ' . $omni->get_error_message();
			return $out;
		}

		$companies = self::active_companies( $twenty );
		if ( is_wp_error( $companies ) ) {
			$out['errors'][] = 'twenty companies: ' . $companies->get_error_message();
			return $out;
		}

		// One pass over People, grouped by company. One call per company hits the
		// Twenty rate limit (100 tokens / 60s) on any real workspace.
		$people_by_company = self::all_people_by_company( $twenty );
		if ( is_wp_error( $people_by_company ) ) {
			$out['errors'][] = 'twenty people: ' . $people_by_company->get_error_message();
			return $out;
		}

		// Stable, de-duplicated list of everyone who should carry the tag.
		$eligible = array();
		foreach ( $companies as $company ) {
			foreach ( ( $people_by_company[ $company['id'] ] ?? array() ) as $person ) {
				$email = $person['emails']['primaryEmail'] ?? '';
				if ( is_email( $email ) ) {
					$eligible[ strtolower( $email ) ] = array( 'person' => $person, 'company' => $company );
				}
			}
		}
		ksort( $eligible );
		$keys           = array_keys( $eligible );
		$out['total']   = count( $keys );
		$cursor         = (int) get_option( self::CURSOR, 0 );
		$cursor         = ( $cursor >= $out['total'] ) ? 0 : $cursor;
		$out['cursor']  = $cursor;
		$window         = array_slice( $keys, $cursor, self::MAX_PER_RUN );

		foreach ( $window as $key ) {
			$person  = $eligible[ $key ]['person'];
			$company = $eligible[ $key ]['company'];
			$out['plan'][] = array(
				'email'   => $person['emails']['primaryEmail'],
				'company' => $company['name'] ?? '',
				'tag'     => self::TAG,
			);

			if ( $dry_run ) {
				continue;
			}

			$res = self::push_contact( $omni, $person, $company );
			if ( ! empty( $res['error'] ) ) {
				$out['errors'][] = $person['emails']['primaryEmail'] . ': ' . $res['error'];
				continue;
			}
			$out['written']++;
			usleep( 200000 ); // Stay well inside 400 requests/minute.
		}

		if ( ! $dry_run ) {
			$next = $cursor + count( $window );
			update_option( self::CURSOR, $next >= $out['total'] ? 0 : $next, false );
		}

		// Untag anyone we tagged who is no longer eligible. Skipped when Twenty
		// returned nothing at all, which is more likely a glitch than a mass exit.
		$tracked = self::tracked();
		$stale   = array_diff_key( $tracked, $eligible );
		if ( 0 === $out['total'] && count( $tracked ) > 5 ) {
			$out['errors'][] = 'untag skipped: Twenty returned no eligible contacts but ' . count( $tracked ) . ' are tracked';
			$stale           = array();
		}
		foreach ( array_slice( $stale, 0, self::MAX_PER_RUN, true ) as $email => $company_id ) {
			$out['untag'][] = $email;
			if ( $dry_run ) {
				continue;
			}
			$res = self::untag_email( $omni, $email );
			if ( ! empty( $res['error'] ) ) {
				$out['errors'][] = 'untag ' . $email . ': ' . $res['error'];
			}
			usleep( 200000 );
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Single-contact operations. Shared by the reconcile and the webhook.
	 * ------------------------------------------------------------------ */

	/** Upsert one contact with the retailer tag and record that we tagged it. */
	public static function push_contact( QC_Omnisend_Client $omni, $person, $company ) {
		$email = $person['emails']['primaryEmail'] ?? '';
		$res   = $omni->upsert_contact( $email, self::contact_payload( $person, $company ) );
		if ( ! empty( $res['error'] ) ) {
			return $res;
		}

		self::track( $email, $company['id'] ?? '' );
		QC_Twenty_Log::add( 'omnisend', (int) ( $company['wpCustomerId'] ?? 0 ), array(
			'email'   => $email,
			'company' => $company['name'] ?? '',
			'code'    => $res['code'],
		) );
		return $res;
	}

	/** Remove the retailer tag from one contact and stop tracking it. */
	public static function untag_email( QC_Omnisend_Client $omni, $email ) {
		$res = $omni->remove_tag( $email, self::TAG );
		if ( empty( $res['error'] ) ) {
			self::untrack( $email );
			QC_Twenty_Log::add( 'omnisend_untag', 0, array( 'email' => $email, 'code' => $res['code'] ) );
		}
		return $res;
	}

	/**
	 * Bring Omnisend in line with one Twenty Company: tag its people when the
	 * Company is eligible, untag everyone we tagged under it otherwise.
	 *
	 * @param array $company Twenty Company record.
	 * @return array{pushed:int,untagged:int,errors:string[]}
	 */
	public static function sync_company( QC_Twenty_Client $twenty, QC_Omnisend_Client $omni, array $company ) {
		$out      = array( 'pushed' => 0, 'untagged' => 0, 'errors' => array() );
		$eligible = ( $company['lifecycleStage'] ?? '' ) === self::eligible_stage() && empty( $company['deletedAt'] );
		$keep     = array();

		if ( $eligible ) {
			$people = $twenty->people_for_company( $company['id'] );
			if ( is_wp_error( $people ) ) {
				$out['errors'][] = $people->get_error_message();
				return $out;
			}
			foreach ( $people as $person ) {
				$email = $person['emails']['primaryEmail'] ?? '';
				if ( ! is_email( $email ) ) {
					continue;
				}
				$keep[ strtolower( $email ) ] = true;
				$res = self::push_contact( $omni, $person, $company );
				if ( empty( $res['error'] ) ) {
					$out['pushed']++;
				} else {
					$out['errors'][] = $email . ': ' . $res['error'];
				}
			}
		}

		foreach ( self::tracked() as $email => $company_id ) {
			if ( $company_id === $company['id'] && ! isset( $keep[ $email ] ) ) {
				$res = self::untag_email( $omni, $email );
				if ( empty( $res['error'] ) ) {
					$out['untagged']++;
				} else {
					$out['errors'][] = 'untag ' . $email . ': ' . $res['error'];
				}
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Tracking: which contacts WE tagged, so we can untag them later.
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,string> lower-case email => Twenty company id ('' if unknown).
	 */
	public static function tracked() {
		$tracked = get_option( self::TRACKED, null );
		if ( null === $tracked ) {
			$tracked = self::seed_tracked_from_log();
			update_option( self::TRACKED, $tracked, false );
		}
		return is_array( $tracked ) ? $tracked : array();
	}

	private static function track( $email, $company_id ) {
		$tracked                       = self::tracked();
		$tracked[ strtolower( $email ) ] = (string) $company_id;
		update_option( self::TRACKED, $tracked, false );
	}

	private static function untrack( $email ) {
		$tracked = self::tracked();
		unset( $tracked[ strtolower( $email ) ] );
		update_option( self::TRACKED, $tracked, false );
	}

	/** First run: rebuild the tracked set from contacts earlier runs pushed. */
	private static function seed_tracked_from_log() {
		global $wpdb;
		$table = QC_Twenty_Log::table();
		$rows  = $wpdb->get_col( "SELECT payload FROM {$table} WHERE event_type = 'omnisend'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( (array) $rows as $json ) {
			$data = json_decode( (string) $json, true );
			if ( ! empty( $data['email'] ) ) {
				$out[ strtolower( $data['email'] ) ] = '';
			}
		}
		return $out;
	}

	/** GET with 429 backoff. Twenty allows roughly 100 tokens per 60 seconds. */
	private static function get_with_retry( QC_Twenty_Client $twenty, $path, $tries = 4 ) {
		$res = array( 'code' => 0, 'body' => null, 'error' => 'not attempted' );

		for ( $i = 0; $i < $tries; $i++ ) {
			$res = $twenty->request( 'GET', $path );
			if ( 429 !== (int) $res['code'] ) {
				return $res;
			}
			sleep( 2 * ( $i + 1 ) );
		}

		return $res;
	}

	/**
	 * Fetch every Person once and group by companyId.
	 *
	 * Far cheaper than one call per company, and it stays inside the rate limit.
	 */
	private static function all_people_by_company( QC_Twenty_Client $twenty ) {
		$map    = array();
		$cursor = null;

		do {
			$path = '/rest/people?limit=' . self::PAGE . ( $cursor ? '&starting_after=' . rawurlencode( $cursor ) : '' );
			$res  = self::get_with_retry( $twenty, $path );
			if ( ! empty( $res['error'] ) ) {
				return new WP_Error( 'qc_omnisend_twenty', $res['error'] );
			}

			foreach ( ( $res['body']['data']['people'] ?? array() ) as $person ) {
				$cid = $person['companyId'] ?? '';
				if ( $cid ) {
					$map[ $cid ][] = $person;
				}
			}

			$cursor = ! empty( $res['body']['pageInfo']['hasNextPage'] ) ? ( $res['body']['pageInfo']['endCursor'] ?? null ) : null;
		} while ( $cursor );

		return $map;
	}

	/** Companies at the eligible lifecycle stage. Paginated. */
	private static function active_companies( QC_Twenty_Client $twenty ) {
		$stage  = self::eligible_stage();
		$filter = rawurlencode( 'lifecycleStage[eq]:' . $stage );
		$rows   = array();
		$cursor = null;

		do {
			$path = '/rest/companies?limit=' . self::PAGE . '&filter=' . $filter
				. ( $cursor ? '&starting_after=' . rawurlencode( $cursor ) : '' );
			$res = self::get_with_retry( $twenty, $path );
			if ( ! empty( $res['error'] ) ) {
				return new WP_Error( 'qc_omnisend_twenty', $res['error'] );
			}
			$data   = $res['body']['data']['companies'] ?? array();
			$rows   = array_merge( $rows, $data );
			$cursor = ! empty( $res['body']['pageInfo']['hasNextPage'] ) ? ( $res['body']['pageInfo']['endCursor'] ?? null ) : null;
		} while ( $cursor );

		return $rows;
	}

	/**
	 * Build the Omnisend contact body.
	 *
	 * No channel status is ever included: consent is preserved as-is.
	 */
	private static function contact_payload( $person, $company ) {
		$payload = array(
			'tags'             => array( self::TAG ),
			'customProperties' => array_filter(
				array(
					'qc_account_id'      => $company['externalAccountId'] ?? '',
					'qc_dealer_code'     => $company['dealerId'] ?? '',
					'qc_company'         => $company['name'] ?? '',
					'qc_lifecycle_stage' => $company['lifecycleStage'] ?? '',
					'qc_account_terms'   => $company['accountTerms'] ?? '',
					'qc_country'         => $company['country'] ?? '',
					'qc_segment'         => $company['b2Bsegment'] ?? '',
					'qc_state'           => $company['address']['addressState'] ?? '',
					'qc_postcode'        => $company['address']['addressPostcode'] ?? '',
					'qc_customer_type'   => ! empty( $company['customerType'] ) ? implode( ',', (array) $company['customerType'] ) : '',
					'qc_order_count'     => $company['orderCount'] ?? '',
					'qc_last_order_date' => ! empty( $company['lastOrderDate'] ) ? substr( $company['lastOrderDate'], 0, 10 ) : '',
					'qc_lifetime_value'  => isset( $company['lifetimeOrderValue']['amountMicros'] ) ? round( $company['lifetimeOrderValue']['amountMicros'] / 1000000, 2 ) : '',
				),
				static function ( $v ) {
					return '' !== $v && null !== $v;
				}
			),
		);

		$first = $person['name']['firstName'] ?? '';
		$last  = $person['name']['lastName'] ?? '';
		if ( $first ) {
			$payload['firstName'] = $first;
		}
		if ( $last ) {
			$payload['lastName'] = $last;
		}

		return apply_filters( 'qc_omnisend_contact_payload', $payload, $person, $company );
	}
}
