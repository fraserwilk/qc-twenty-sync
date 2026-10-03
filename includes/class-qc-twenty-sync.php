<?php
/**
 * Sync engine: WordPress hooks, payload mapping, queue and retry.
 */

defined( 'ABSPATH' ) || exit;

class QC_Twenty_Sync {

	const HOOK         = 'qc_twenty_process_event';
	const MAX_ATTEMPTS = 6;

	public static function init() {
		// B2BKing: a new dealer application needs approval.
		add_action( 'b2bking_new_user_requires_approval', array( __CLASS__, 'on_application' ), 20, 3 );

		// B2BKing: the account was approved.
		add_action( 'b2bking_approved_user_as_b2c', array( __CLASS__, 'on_approved_user' ), 20, 2 );
		add_action( 'b2bking_account_approved_finish', array( __CLASS__, 'on_approved_email' ), 20, 1 );

		// Profile edits: keep Twenty current after the application/approval events.
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'on_profile' ), 30, 1 );
		add_action( 'profile_update', array( __CLASS__, 'on_profile' ), 30, 1 );
		add_action( 'woocommerce_customer_save_address', array( __CLASS__, 'on_profile' ), 30, 1 );
		add_action( 'woocommerce_save_account_details', array( __CLASS__, 'on_profile' ), 30, 1 );

		// Queue worker.
		add_action( self::HOOK, array( __CLASS__, 'process' ), 10, 1 );

		// Flow 3: order summaries. Woo status changes are the primary trigger.
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_status' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_status' ), 20, 1 );
		add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'on_order_status' ), 20, 1 );

		// B2BKing order approval is an extra trigger for approval-gated orders.
		add_action( 'b2bking_after_approve_order', array( __CLASS__, 'on_b2bking_order' ), 20, 1 );
	}

	/** Recount the summary when an order reaches a counted status. */
	public static function on_order_status( $order_id ) {
		self::enqueue_from_order( $order_id );
	}

	/** B2BKing approved an order (passes the order object). */
	public static function on_b2bking_order( $order ) {
		$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? $order->get_id() : (int) $order;
		self::enqueue_from_order( $order_id );
	}

	private static function enqueue_from_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$customer_id = (int) $order->get_customer_id();
		if ( ! $customer_id ) {
			return; // Guest order. No account record to update.
		}
		self::enqueue( 'order', $customer_id );
	}

	/* ---------------------------------------------------------------------
	 * Hook handlers
	 * ------------------------------------------------------------------ */

	/**
	 * A dealer application arrived.
	 *
	 * @param int    $user_id New user id.
	 * @param string $type    B2BKing account type, e.g. 'b2cupgrade'.
	 * @param string $extra   Unused, kept for signature stability.
	 */
	public static function on_application( $user_id, $type = '', $extra = '' ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return;
		}
		self::enqueue( 'application', $user_id );
	}

	/** A customer or admin edited account details. Customers only. */
	public static function on_profile( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || ! get_userdata( $user_id ) || user_can( $user_id, 'edit_posts' ) ) {
			return; // Staff accounts are not CRM records.
		}
		self::enqueue( 'profile', $user_id );
	}

	/** Account approved (B2B path). */
	public static function on_approved_user( $user_id, $email = '' ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return;
		}
		self::enqueue( 'approved', $user_id );
	}

	/** Account approved (email-only payload). */
	public static function on_approved_email( $email ) {
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			self::enqueue( 'approved', $user->ID );
		}
	}

	/* ---------------------------------------------------------------------
	 * Queue
	 * ------------------------------------------------------------------ */

	public static function enqueue( $event_type, $wp_object_id ) {
		// Order and profile payloads are rebuilt at process time, so only a
		// not-yet-run event makes a new one redundant.
		$recompute = in_array( $event_type, array( 'order', 'profile' ), true );
		if ( QC_Twenty_Log::is_duplicate( $event_type, $wp_object_id, 24, $recompute ) ) {
			return false;
		}

		$payload  = self::build_payload( $event_type, $wp_object_id );
		$event_id = QC_Twenty_Log::add( $event_type, $wp_object_id, $payload );

		if ( ! $event_id ) {
			return false;
		}

		self::schedule( $event_id, 0 );
		return $event_id;
	}

	private static function schedule( $event_id, $delay = 0 ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + (int) $delay, self::HOOK, array( $event_id ), 'qc-twenty-sync' );
			return;
		}

		// Fallback: run now, in this request.
		self::process( $event_id );
	}

	/** Worker. Processes one logged event. */
	public static function process( $event_id ) {
		$row = QC_Twenty_Log::get_by_event_id( $event_id );
		if ( ! $row ) {
			return;
		}
		if ( 'done' === $row['status'] ) {
			return;
		}

		$attempts = (int) $row['attempts'] + 1;

		$client = QC_Twenty_Client::from_config();
		if ( is_wp_error( $client ) ) {
			QC_Twenty_Log::update(
				$event_id,
				array(
					'status'        => 'failed',
					'attempts'      => $attempts,
					'last_error'    => $client->get_error_message(),
					'response_code' => null,
				)
			);
			return;
		}

		$payload = json_decode( (string) $row['payload'], true );

		// Order summaries must be current, not the value captured at queue time.
		// Two orders in quick succession would otherwise push a stale count.
		if ( in_array( $row['event_type'], array( 'order', 'profile' ), true ) ) {
			$fresh = self::build_payload( $row['event_type'], (int) $row['wp_object_id'] );
			if ( ! empty( $fresh ) ) {
				$payload = $fresh;
			}
		}

		$result  = self::dispatch( $client, $row['event_type'], $payload );

		if ( empty( $result['error'] ) ) {
			QC_Twenty_Log::update(
				$event_id,
				array(
					'status'        => ! empty( $result['skip'] ) ? 'skipped' : 'done',
					'attempts'      => $attempts,
					'last_error'    => ! empty( $result['note'] ) ? $result['note'] : '',
					'response_code' => (int) $result['code'],
				)
			);
			return;
		}

		$retryable = self::is_retryable( (int) $result['code'] );

		if ( $retryable && $attempts < self::MAX_ATTEMPTS ) {
			QC_Twenty_Log::update(
				$event_id,
				array(
					'status'        => 'pending',
					'attempts'      => $attempts,
					'last_error'    => $result['error'],
					'response_code' => (int) $result['code'],
				)
			);
			$delay = min( 3600, 60 * (int) pow( 2, $attempts - 1 ) );
			self::schedule( $event_id, $delay );
			return;
		}

		QC_Twenty_Log::update(
			$event_id,
			array(
				'status'        => 'failed',
				'attempts'      => $attempts,
				'last_error'    => $result['error'],
				'response_code' => (int) $result['code'],
			)
		);
	}

	/** Network errors, timeouts and rate limits are worth another try. */
	private static function is_retryable( $code ) {
		return 0 === $code || 408 === $code || 425 === $code || 429 === $code || $code >= 500;
	}

	/* ---------------------------------------------------------------------
	 * Dispatch
	 * ------------------------------------------------------------------ */

	private static function dispatch( QC_Twenty_Client $client, $event_type, $payload ) {
		if ( 'order' === $event_type ) {
			return self::dispatch_order( $client, $payload );
		}

		$company = $client->upsert_company( $payload['company']['externalAccountId'], $payload['company'] );
		if ( ! empty( $company['ambiguous'] ) ) {
			return array(
				'code'  => 200,
				'error' => '',
				'skip'  => true,
				'note'  => 'ambiguous Company match on ' . $company['ambiguous'] . '; link manually',
			);
		}
		if ( ! empty( $company['error'] ) || empty( $company['body']['id'] ) ) {
			return array( 'code' => $company['code'], 'error' => 'company: ' . $company['error'] );
		}
		$company_id = $company['body']['id'];

		$person = $client->upsert_person( $payload['person']['emails']['primaryEmail'], $payload['person'] );
		if ( ! empty( $person['error'] ) ) {
			return array( 'code' => $person['code'], 'error' => 'person: ' . $person['error'] );
		}
		$person_id = isset( $person['body']['id'] ) ? $person['body']['id'] : null;

		if ( $person_id ) {
			$client->upsert_person(
				$payload['person']['emails']['primaryEmail'],
				array( 'companyId' => $company_id )
			);
		}

		if ( 'profile' === $event_type ) {
			return array( 'code' => 200, 'error' => '' ); // No Opportunity changes on a profile edit.
		}

		$opportunity              = $payload['opportunity'];
		$opportunity['companyId'] = $company_id;
		if ( $person_id ) {
			$opportunity['pointOfContactId'] = $person_id;
		}

		if ( 'approved' === $event_type ) {
			// Flow 2: close the application Opportunity and record close date.
			$stage = apply_filters( 'qc_twenty_approved_opportunity_stage', 'WON', $payload );
			$opp   = $client->close_application_opportunity( $company_id, $stage );
			if ( ! empty( $opp['error'] ) ) {
				return array( 'code' => $opp['code'], 'error' => 'opportunity: ' . $opp['error'] );
			}
			return array( 'code' => 200, 'error' => '' );
		}

		$opp = $client->upsert_application_opportunity( $company_id, $opportunity );
		if ( ! empty( $opp['error'] ) ) {
			return array( 'code' => $opp['code'], 'error' => 'opportunity: ' . $opp['error'] );
		}

		return array( 'code' => 200, 'error' => '' );
	}

	/**
	 * Flow 3: patch the order-summary fields on an existing Company.
	 *
	 * Never creates a Company. An order for an unknown account is skipped.
	 */
	private static function dispatch_order( QC_Twenty_Client $client, $payload ) {
		if ( empty( $payload['order'] ) ) {
			return array( 'code' => 200, 'error' => '', 'skip' => true, 'note' => 'no order data' );
		}

		$ext   = $payload['order']['externalAccountId'];
		$found = $client->find_company_by_external_id( $ext );

		if ( ! empty( $found['error'] ) ) {
			return array( 'code' => $found['code'], 'error' => $found['error'] );
		}

		if ( empty( $found['body']['id'] ) ) {
			return array(
				'code'  => 200,
				'error' => '',
				'skip'  => true,
				'note'  => 'no Company for ' . $ext,
			);
		}

		$res = $client->request( 'PATCH', '/rest/companies/' . rawurlencode( $found['body']['id'] ), $payload['order']['fields'] );

		return array( 'code' => $res['code'], 'error' => $res['error'] );
	}

	/**
	 * Backfill: recount and push order summaries for a list of WordPress user ids.
	 *
	 * One-off catch-up path. Runs the same recount as the live flow, without the
	 * queue. Safe to re-run: it only recomputes, never increments.
	 *
	 * @param int[] $user_ids WordPress user ids.
	 * @return array[] Per-id result rows.
	 */
	public static function backfill_orders( array $user_ids ) {
		$client = QC_Twenty_Client::from_config();
		$out    = array();

		foreach ( $user_ids as $id ) {
			$id  = (int) $id;
			$res = array(
				'wp_id' => $id,
				'orders' => 0,
				'code'  => 0,
				'result' => '',
			);

			$summary = self::order_summary( $id );
			if ( ! $summary ) {
				$res['result'] = 'no counted orders';
				$out[]         = $res;
				continue;
			}
			if ( is_wp_error( $client ) ) {
				$res['result'] = 'no API key';
				$out[]         = $res;
				continue;
			}

			$payload       = self::build_order_payload( $id );
			$r             = self::dispatch_order( $client, $payload );
			$res['orders'] = $summary['count'];
			$res['code']   = (int) $r['code'];
			$res['result'] = ! empty( $r['error'] ) ? 'error: ' . $r['error'] : ( ! empty( $r['skip'] ) ? 'skipped: ' . ( $r['note'] ?? '' ) : 'ok' );
			$out[]         = $res;
		}

		return $out;
	}

	/**
	 * Backfill: link and populate Twenty for existing WordPress customers.
	 *
	 * Dry run reports what would happen (linked / will-link / ambiguous /
	 * will-create) and writes nothing. A live run pushes a `profile` payload,
	 * then the order summary. Paced for Twenty's ~100 requests/60s limit.
	 *
	 * @param int[] $user_ids WordPress user ids.
	 * @param bool  $dry_run  True to plan only.
	 * @return array[] One row per user.
	 */
	public static function backfill_customers( array $user_ids, $dry_run = true ) {
		$client = QC_Twenty_Client::from_config();
		if ( is_wp_error( $client ) ) {
			return array( array( 'wp_id' => 0, 'result' => 'no API key' ) );
		}

		$out = array();
		foreach ( $user_ids as $id ) {
			$id      = (int) $id;
			$payload = self::build_payload( 'profile', $id );
			if ( empty( $payload['company'] ) ) {
				$out[] = array( 'wp_id' => $id, 'result' => 'no user' );
				continue;
			}

			$found  = $client->find_existing_company( $payload['company']['externalAccountId'], $payload['company'] );
			$result = 'will-create';
			if ( ! empty( $found['error'] ) ) {
				$result = 'error: ' . $found['error'];
			} elseif ( ! empty( $found['ambiguous'] ) ) {
				$result = 'ambiguous (' . $found['ambiguous'] . ')';
			} elseif ( ! empty( $found['body'] ) ) {
				$result = ( 'externalAccountId' === $found['matched_by'] ) ? 'linked' : 'will-link (' . $found['matched_by'] . ')';
			}

			if ( ! $dry_run && 0 !== strpos( $result, 'ambiguous' ) && 0 !== strpos( $result, 'error' ) ) {
				$r       = self::dispatch( $client, 'profile', $payload );
				$result .= ! empty( $r['error'] ) ? ' -> error: ' . $r['error'] : ' -> pushed';
				$order   = self::build_order_payload( $id );
				if ( $order ) {
					self::dispatch_order( $client, $order );
				}
				sleep( 6 ); // ~10 requests per user; stay under 100 per 60s.
			}

			$out[] = array( 'wp_id' => $id, 'result' => $result );
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Payload mapping
	 * ------------------------------------------------------------------ */

	/**
	 * Meta keys to try, per Twenty field. Filterable.
	 *
	 * Keys are read from user meta, in order. First non-empty wins.
	 */
	public static function company_meta_map() {
		return apply_filters(
			'qc_twenty_company_meta_map',
			array(
				'tradingName'  => array( 'billing_company', 'b2bking_custom_field_18', 'b2bking_company_name', 'company' ),
				// B2BKing custom field ids, confirmed by Fraser 22 Sep 2026.
				'legalName'    => array( 'b2bking_custom_field_18', 'b2bking_legal_name', 'legal_company_name' ),
				// ABN custom field 25 is connected to billing_vat in B2BKing.
				'abn'          => array( 'billing_vat', 'b2bking_custom_field_25', 'billing_abn', 'abn' ),
				'nzbn'         => array( 'nzbn', 'b2bking_nzbn' ),
				// No WP source today: field 17447 is only the Terms of Trade checkbox.
				'accountTerms' => array( 'b2bking_account_terms', 'account_terms' ),
				'website'      => array( 'billing_website', 'website', 'b2bking_website' ),
				'jobTitle'     => array( 'job_title', 'b2bking_job_title' ),
				'dealerId'     => array( 'qc_dealer_id', 'dealer_id' ),
				'salesRep'     => array( 'qc_sales_rep' ),
				'gst'          => array( 'qc_gst_registered', 'b2bking_gst_registered' ),
			)
		);
	}

	private static function meta_lookup( $user_id, array $keys ) {
		foreach ( $keys as $key ) {
			$val = get_user_meta( $user_id, $key, true );
			if ( is_string( $val ) ) {
				$val = trim( $val );
			}
			if ( ! empty( $val ) ) {
				return $val;
			}
		}
		return '';
	}

	public static function build_payload( $event_type, $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}

		if ( 'order' === $event_type ) {
			return self::build_order_payload( $user_id );
		}

		$external_id = 'wp-' . (int) $user_id;
		$map         = self::company_meta_map();

		$company = array(
			'externalAccountId' => $external_id,
			'wpCustomerId'      => (int) $user_id,
			'wpAccountUrl'      => array(
				'primaryLinkUrl'   => admin_url( 'user-edit.php?user_id=' . (int) $user_id ),
				'primaryLinkLabel' => 'WordPress',
			),
			'lifecycleStage'    => apply_filters(
				'qc_twenty_lifecycle_for_event',
				( 'approved' === $event_type ) ? 'ACTIVE' : 'APPLICANT',
				$event_type,
				$user_id
			),
		);

		if ( 'profile' === $event_type ) {
			// A profile edit must never move an existing Company's lifecycle stage.
			unset( $company['lifecycleStage'] );
		}

		$name = self::meta_lookup( $user_id, $map['tradingName'] );
		if ( $name ) {
			$company['tradingName'] = $name;
			$company['name']        = $name;
		}

		foreach ( array( 'legalName', 'abn', 'nzbn' ) as $field ) {
			$val = self::meta_lookup( $user_id, isset( $map[ $field ] ) ? $map[ $field ] : array() );
			if ( $val ) {
				$company[ $field ] = $val;
			}
		}
		if ( ! empty( $company['abn'] ) ) {
			$abn = self::normalise_abn( $company['abn'] );
			if ( $abn ) {
				$company['abn'] = $abn;
			} else {
				unset( $company['abn'] ); // Placeholder or malformed (e.g. 999999999).
			}
		}

		$country = self::meta_lookup( $user_id, array( 'billing_country', 'b2bking_country' ) );
		if ( 'NZ' === $country ) {
			$company['country'] = 'NZ';
		} elseif ( $country ) {
			$company['country'] = 'AU';
		}

		$company = array_merge( $company, self::extra_company_fields( $user_id, $map ) );

		$email = $user->user_email;
		foreach ( array( 'billing_email', 'b2bking_email' ) as $key ) {
			$candidate = get_user_meta( $user_id, $key, true );
			if ( is_email( $candidate ) ) {
				$email = $candidate;
				break;
			}
		}

		$person = array(
			'name'   => array(
				'firstName' => (string) get_user_meta( $user_id, 'billing_first_name', true ) ?: $user->first_name,
				'lastName'  => (string) get_user_meta( $user_id, 'billing_last_name', true ) ?: $user->last_name,
			),
			'emails' => array( 'primaryEmail' => $email ),
		);

		$phone = self::meta_lookup( $user_id, array( 'billing_phone', 'b2bking_phone' ) );
		if ( $phone ) {
			$person['phones'] = self::phone_field( $phone, 'AU' );
		}

		$job_title = self::meta_lookup( $user_id, $map['jobTitle'] );
		if ( $job_title ) {
			$person['jobTitle'] = $job_title;
		}

		$label       = $name ? $name : $email;
		$stage       = apply_filters( 'qc_twenty_application_stage', 'NEW', $event_type, $user_id );
		$opportunity = array(
			'name'  => 'Application - ' . $label,
			'stage' => $stage,
		);

		return array(
			'company'     => $company,
			'person'      => $person,
			'opportunity' => $opportunity,
			'sources'     => array(
				'wp_user_id' => (int) $user_id,
				'event'      => $event_type,
				'mapped'     => true,
			),
		);
	}

	/**
	 * Twenty phone composite. A number already in international form (+86...)
	 * carries its own country, so no default is forced: Twenty rejects a
	 * default that conflicts with the number.
	 */
	private static function phone_field( $phone, $default_country ) {
		$phone = trim( (string) $phone );
		if ( 0 === strpos( $phone, '+' ) ) {
			return array( 'primaryPhoneNumber' => $phone );
		}
		return array(
			'primaryPhoneNumber'      => $phone,
			'primaryPhoneCountryCode' => $default_country,
		);
	}

	/** Digits-only ABN, or '' when it is not a plausible 11-digit ABN. */
	private static function normalise_abn( $raw ) {
		$digits = preg_replace( '/\D/', '', (string) $raw );
		return ( 11 === strlen( $digits ) && ! preg_match( '/^(\d)\1+$/', $digits ) ) ? $digits : '';
	}

	/**
	 * Twenty b2Bsegment (RETAILER / OEM) from the user's B2BKing group.
	 *
	 * Matches on the group title, so it survives group id changes. Override with
	 * the qc_twenty_b2b_segment_map filter (lowercase title => enum value).
	 */
	private static function b2b_segment( $user_id ) {
		$group = (int) get_user_meta( $user_id, 'b2bking_customergroup', true );
		if ( ! $group ) {
			return '';
		}
		$map   = apply_filters( 'qc_twenty_b2b_segment_map', array( 'retailer' => 'RETAILER', 'oem' => 'OEM' ) );
		$title = strtolower( trim( get_the_title( $group ) ) );
		return isset( $map[ $title ] ) ? $map[ $title ] : '';
	}

	/**
	 * Twenty accountOwnerId from the WP sales rep (user meta qc_sales_rep).
	 *
	 * The rep is text like "Rob Williams (VIC)", chosen from Settings > Sales Reps.
	 * Resolve it to a Twenty workspace member via that rep's rep_twenty_email
	 * sub-field (or the qc_twenty_sales_rep_map filter, label => member email),
	 * else by member name appearing in the label. Unresolved reps (e.g.
	 * "Head Office") return '' and leave the owner untouched.
	 */
	private static function account_owner_id( $user_id, array $map ) {
		$rep = trim( (string) self::meta_lookup( $user_id, $map['salesRep'] ) );
		if ( '' === $rep ) {
			return '';
		}
		$members = self::workspace_members();
		if ( ! $members ) {
			return '';
		}

		// Settings > Sales Reps (ACF options repeater). An optional sub-field
		// rep_twenty_email links a rep to a Twenty workspace member.
		$acf  = array();
		$rows = (int) get_option( 'options_qc_sales_reps', 0 );
		for ( $i = 0; $i < $rows; $i++ ) {
			$name  = trim( (string) get_option( "options_qc_sales_reps_{$i}_rep_name", '' ) );
			$email = trim( (string) get_option( "options_qc_sales_reps_{$i}_rep_twenty_email", '' ) );
			if ( '' !== $name && is_email( $email ) ) {
				$acf[ $name ] = $email;
			}
		}
		$explicit = apply_filters( 'qc_twenty_sales_rep_map', $acf );
		if ( isset( $explicit[ $rep ] ) ) {
			foreach ( $members as $m ) {
				if ( strtolower( $m['email'] ) === strtolower( $explicit[ $rep ] ) ) {
					return $m['id'];
				}
			}
			return '';
		}

		foreach ( $members as $m ) {
			if ( '' !== $m['name'] && false !== stripos( $rep, $m['name'] ) ) {
				return $m['id'];
			}
		}
		return '';
	}

	/** Twenty workspace members, cached for an hour. @return array[] id, name, email */
	private static function workspace_members() {
		$cached = get_transient( 'qc_twenty_members' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$client = QC_Twenty_Client::from_config();
		if ( is_wp_error( $client ) ) {
			return array();
		}
		$res = $client->request( 'GET', '/rest/workspaceMembers?limit=60' );
		if ( ! empty( $res['error'] ) ) {
			return array();
		}
		$rows = isset( $res['body']['data']['workspaceMembers'] ) ? $res['body']['data']['workspaceMembers'] : array();
		$out  = array();
		foreach ( $rows as $m ) {
			$out[] = array(
				'id'    => (string) $m['id'],
				'name'  => trim( ( isset( $m['name']['firstName'] ) ? $m['name']['firstName'] : '' ) . ' ' . ( isset( $m['name']['lastName'] ) ? $m['name']['lastName'] : '' ) ),
				'email' => isset( $m['userEmail'] ) ? (string) $m['userEmail'] : '',
			);
		}
		set_transient( 'qc_twenty_members', $out, HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Company fields beyond identity: address, contact, GST, segment, owner.
	 *
	 * Each is only included when WordPress has a value, so an empty WP field
	 * never blanks something in Twenty. customerType is curated by hand in
	 * Twenty and deliberately never sent from here.
	 */
	private static function extra_company_fields( $user_id, array $map ) {
		$out = array();

		$street = trim( implode( ' ', array_filter( array(
			(string) get_user_meta( $user_id, 'billing_address_1', true ),
			(string) get_user_meta( $user_id, 'billing_address_2', true ),
		) ) ) );
		$city  = (string) get_user_meta( $user_id, 'billing_city', true );
		$state = (string) get_user_meta( $user_id, 'billing_state', true );
		$post  = (string) get_user_meta( $user_id, 'billing_postcode', true );
		$ctry  = (string) get_user_meta( $user_id, 'billing_country', true );
		if ( $street || $city || $post ) {
			$out['address'] = array(
				'addressStreet1'  => $street,
				'addressCity'     => $city,
				'addressState'    => $state,
				'addressPostcode' => $post,
				'addressCountry'  => $ctry,
			);
		}

		$email = get_user_meta( $user_id, 'billing_email', true );
		if ( is_email( $email ) ) {
			$out['companyEmail'] = array( 'primaryEmail' => $email );
		}

		$phone = (string) get_user_meta( $user_id, 'billing_phone', true );
		if ( $phone ) {
			$out['companyPhone'] = self::phone_field( $phone, ( 'NZ' === $ctry ) ? 'NZ' : 'AU' );
		}

		$web = self::meta_lookup( $user_id, $map['website'] );
		if ( $web ) {
			$out['website'] = array( 'primaryLinkUrl' => esc_url_raw( $web ) );
		}

		$dealer = self::meta_lookup( $user_id, $map['dealerId'] );
		if ( $dealer ) {
			$out['dealerId'] = (string) $dealer;
		}

		$gst = self::meta_lookup( $user_id, $map['gst'] );
		if ( '' !== $gst ) {
			$out['gstRegistered'] = in_array( strtolower( (string) $gst ), array( '1', 'yes', 'true', 'on' ), true );
		} elseif ( self::normalise_abn( self::meta_lookup( $user_id, $map['abn'] ) ) ) {
			// Fallback: a valid 11-digit ABN is treated as GST registered.
			$out['gstRegistered'] = true;
		}

		$segment = self::b2b_segment( $user_id );
		if ( $segment ) {
			$out['b2Bsegment'] = $segment;
		}

		// Twenty accountTerms is COD / NET30 / NET60. All retailer accounts are COD.
		$terms = '';
		$raw   = self::meta_lookup( $user_id, $map['accountTerms'] );
		if ( $raw && preg_match( '/^(COD|NET30|NET60)/', strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $raw ) ), $m ) ) {
			$terms = $m[1];
		} elseif ( 'RETAILER' === $segment ) {
			$terms = apply_filters( 'qc_twenty_default_retailer_terms', 'COD', $user_id );
		}
		if ( $terms ) {
			$out['accountTerms'] = $terms;
		}

		// Reps are not Twenty workspace members, so the rep goes in as text (Twenty
		// field salesRep). WordPress owns it: Settings > Sales Reps is the source.
		$rep = trim( (string) self::meta_lookup( $user_id, $map['salesRep'] ) );
		if ( '' !== $rep ) {
			$out['salesRep'] = $rep;
		}

		$owner = self::account_owner_id( $user_id, $map );
		if ( $owner ) {
			$out['accountOwnerId'] = $owner;
		}

		return apply_filters( 'qc_twenty_company_extra', $out, $user_id );
	}

	/* ---------------------------------------------------------------------
	 * Order summary (flow 3)
	 * ------------------------------------------------------------------ */

	/** Build the flow 3 payload. Returns an empty array when there is nothing to push. */
	private static function build_order_payload( $user_id ) {
		$summary = self::order_summary( $user_id );
		if ( ! $summary ) {
			return array();
		}

		return array(
			'order'   => array(
				'externalAccountId' => 'wp-' . (int) $user_id,
				'fields'            => $summary['fields'],
			),
			'sources' => array(
				'wp_user_id'     => (int) $user_id,
				'counted_orders' => $summary['count'],
			),
		);
	}

	/**
	 * Recount from WooCommerce. Self-correcting: never increments.
	 *
	 * WordPress owns the order facts, so we recompute on every trigger.
	 */
	private static function order_summary( $user_id ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$statuses = apply_filters( 'qc_twenty_counted_order_statuses', array( 'processing', 'completed', 'on-hold' ) );
		$orders   = wc_get_orders(
			array(
				'customer_id' => (int) $user_id,
				'limit'       => -1,
				'status'      => $statuses,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		if ( empty( $orders ) ) {
			return null;
		}

		$include_tax = (bool) apply_filters( 'qc_twenty_amounts_include_tax', false );

		$count    = count( $orders );
		$lifetime = 0.0;
		$last     = null;
		foreach ( $orders as $o ) {
			$lifetime += self::net_total( $o, $include_tax );
			if ( null === $last ) {
				$last = $o;
			}
		}

		$currency   = $last ? $last->get_currency() : 'AUD';
		$last_total = $last ? self::net_total( $last, $include_tax ) : 0.0;
		$created    = $last ? $last->get_date_created() : null;

		$fields = array(
			'orderCount'                => (int) $count,
			'lifetimeOrderValue'        => array(
				'amountMicros' => (int) round( $lifetime * 1000000 ),
				'currencyCode' => $currency,
			),
			'lastOrderValue'            => array(
				'amountMicros' => (int) round( $last_total * 1000000 ),
				'currencyCode' => $currency,
			),
			'lastOrderSummaryRefreshed' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);

		if ( $created ) {
			$fields['lastOrderDate'] = $created->date( 'Y-m-d' );
		}

		return array(
			'count'  => $count,
			'fields' => $fields,
		);
	}

	/**
	 * Order value to send to Twenty.
	 *
	 * Default is **ex GST**: the Woo order total minus its tax. For AU orders the
	 * difference between total and total tax is the GST component.
	 * Flip with the qc_twenty_amounts_include_tax filter.
	 *
	 * @param WC_Order $order       Order object.
	 * @param bool     $include_tax True to send the gross total.
	 * @return float
	 */
	private static function net_total( $order, $include_tax ) {
		$total = (float) $order->get_total();

		if ( $include_tax ) {
			return $total;
		}

		return $total - (float) $order->get_total_tax();
	}
}
