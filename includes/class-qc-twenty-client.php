<?php
/**
 * Twenty CRM REST client.
 *
 * Small wrapper over the Twenty core REST API. No dependencies.
 *
 * Response shapes (verified on our instance 22 Sep 2026):
 *   GET  /rest/companies   -> {"data":{"companies":[...]},"totalCount":n,"pageInfo":{...}}
 *   GET  /rest/people      -> {"data":{"people":[...]}}
 *   POST /rest/companies   -> {"data":{"createCompany":{...}}}
 *   PATCH /rest/companies/ -> {"data":{"updateCompany":{...}}}
 * The helpers below accept either shape.
 */

defined( 'ABSPATH' ) || exit;

class QC_Twenty_Client {

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
	 * The key is read from the QC_TWENTY_API_KEY constant. It is never stored
	 * in the database and never logged.
	 *
	 * @return QC_Twenty_Client|WP_Error
	 */
	public static function from_config() {
		$base = defined( 'QC_TWENTY_BASE_URL' ) ? QC_TWENTY_BASE_URL : 'https://crm.qualitycomponents.com.au';
		$key  = defined( 'QC_TWENTY_API_KEY' ) ? QC_TWENTY_API_KEY : '';

		$base = apply_filters( 'qc_twenty_base_url', $base );
		$key  = apply_filters( 'qc_twenty_api_key', $key );

		if ( empty( $key ) ) {
			return new WP_Error( 'qc_twenty_no_key', 'QC_TWENTY_API_KEY is not defined.' );
		}

		return new self( $base, $key );
	}

	/**
	 * Perform a request.
	 *
	 * @return array{code:int,body:mixed,error:string} Always returns; never throws.
	 */
	public function request( $method, $path, $body = null ) {
		$url = $this->base . $path;

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => $this->timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->key,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		// Twenty allows ~100 tokens per 60s. Back off and retry on 429.
		$attempt = 0;
		do {
			$response = wp_remote_request( $url, $args );
			$limited  = ! is_wp_error( $response ) && 429 === (int) wp_remote_retrieve_response_code( $response );
			if ( $limited && $attempt < 3 && ! wp_doing_cron() ) {
				sleep( 20 );
			} else {
				$limited = false;
			}
			++$attempt;
		} while ( $limited );

		if ( is_wp_error( $response ) ) {
			return array(
				'code'  => 0,
				'body'  => null,
				'error' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		return array(
			'code'  => $code,
			'body'  => $data,
			'error' => ( $code >= 200 && $code < 300 ) ? '' : substr( (string) $raw, 0, 500 ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Shape helpers. Twenty nests by object name.
	 * ------------------------------------------------------------------ */

	/** Extract the list from a collection response, whatever the shape. */
	private static function rows( $body, $plural ) {
		if ( ! is_array( $body ) ) {
			return array();
		}
		$data = isset( $body['data'] ) ? $body['data'] : $body;
		if ( isset( $data[ $plural ] ) && is_array( $data[ $plural ] ) ) {
			return $data[ $plural ];
		}
		if ( isset( $data[0] ) ) {
			return $data;
		}
		return array();
	}

	/** Find the first record with an id, anywhere in the response. */
	private static function first_record( $body ) {
		if ( ! is_array( $body ) ) {
			return null;
		}
		if ( isset( $body['id'] ) ) {
			return $body;
		}
		foreach ( $body as $value ) {
			if ( is_array( $value ) ) {
				$found = self::first_record( $value );
				if ( $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	private function filter_path( $object, $filter, $limit = 1 ) {
		return sprintf(
			'/rest/%s?limit=%d&filter=%s',
			$object,
			$limit,
			rawurlencode( $filter )
		);
	}

	/* ---------------------------------------------------------------------
	 * Companies
	 * ------------------------------------------------------------------ */

	/** Find a Company by its permanent integration key. */
	public function find_company_by_external_id( $external_id ) {
		return $this->find_one( 'companies', 'externalAccountId[eq]:' . $external_id );
	}

	private function find_one( $object, $filter ) {
		$res = $this->request( 'GET', $this->filter_path( $object, $filter ) );
		if ( ! empty( $res['error'] ) ) {
			return $res;
		}
		$rows = self::rows( $res['body'], $object );
		return array(
			'code'  => $res['code'],
			'error' => '',
			'body'  => $rows ? $rows[0] : null,
		);
	}

	/**
	 * Find the existing Company for a WordPress account.
	 *
	 * Most Companies were imported into Twenty before WordPress knew about them,
	 * so they have no externalAccountId. Try the permanent key first, then fall
	 * back to identifiers that are safe to match on. A fallback that returns more
	 * than one record is ambiguous and reported, never guessed.
	 *
	 * @param string $external_id Integration key, e.g. wp-123.
	 * @param array  $data        Company payload (abn, companyEmail, wpCustomerId, name...).
	 * @return array{code:int,error:string,body:?array,ambiguous?:string,matched_by?:string}
	 */
	public function find_existing_company( $external_id, array $data ) {
		$found = $this->find_company_by_external_id( $external_id );
		if ( ! empty( $found['error'] ) || $found['body'] ) {
			$found['matched_by'] = 'externalAccountId';
			return $found;
		}

		$candidates = array();
		if ( ! empty( $data['wpCustomerId'] ) ) {
			$candidates['wpCustomerId'] = 'wpCustomerId[eq]:' . (int) $data['wpCustomerId'];
		}
		if ( ! empty( $data['abn'] ) ) {
			$candidates['abn'] = 'abn[eq]:' . $data['abn'];
			if ( 11 === strlen( $data['abn'] ) ) {
				// Some imported records store the spaced form, e.g. 69 154 420 452.
				$candidates['abn (spaced)'] = 'abn[eq]:' . substr( $data['abn'], 0, 2 ) . ' ' . trim( chunk_split( substr( $data['abn'], 2 ), 3, ' ' ) );
			}
		}
		if ( ! empty( $data['companyEmail']['primaryEmail'] ) ) {
			$candidates['companyEmail'] = 'companyEmail.primaryEmail[eq]:' . $data['companyEmail']['primaryEmail'];
		}
		if ( ! empty( $data['name'] ) ) {
			$candidates['name'] = 'name[eq]:' . $data['name'];
		}

		foreach ( $candidates as $label => $filter ) {
			$res = $this->request( 'GET', $this->filter_path( 'companies', $filter, 2 ) );
			if ( ! empty( $res['error'] ) ) {
				return $res;
			}
			$rows = self::rows( $res['body'], 'companies' );
			if ( count( $rows ) > 1 ) {
				return array( 'code' => $res['code'], 'error' => '', 'body' => null, 'ambiguous' => $label );
			}
			if ( 1 === count( $rows ) ) {
				return array( 'code' => $res['code'], 'error' => '', 'body' => $rows[0], 'matched_by' => $label );
			}
		}

		return array( 'code' => 200, 'error' => '', 'body' => null );
	}

	/**
	 * Create or update a Company. Returns the record body.
	 *
	 * An ambiguous match returns with `ambiguous` set and a null body, so the
	 * caller can skip rather than create a duplicate.
	 */
	public function upsert_company( $external_id, array $data ) {
		$found = $this->find_existing_company( $external_id, $data );

		if ( ! empty( $found['error'] ) || ! empty( $found['ambiguous'] ) ) {
			return $found;
		}

		if ( $found['body'] ) {
			// Fill-blank policy: Twenty holds curated values (imported address,
			// phone, dealer id, owner). Only send those when Twenty has none.
			$data = $this->drop_curated_fields( $data, $found['body'] );

			// Never clobber the curated display name on an existing record unless
			// explicitly allowed. Quality Components renames records to add the
			// store or suburb, and a sync must not undo that.
			if ( ! apply_filters( 'qc_twenty_manage_company_name', false, $found['body'] ) ) {
				unset( $data['name'] );
			}

			$data = apply_filters( 'qc_twenty_update_company_data', $data, $found['body'] );
			$res  = $this->request( 'PATCH', '/rest/companies/' . rawurlencode( $found['body']['id'] ), $data );
		} else {
			$res = $this->request( 'POST', '/rest/companies', $data );
		}

		return $this->normalise( $res );
	}

	/** Fields where an existing, non-empty Twenty value wins over WordPress. */
	private function drop_curated_fields( array $data, array $existing ) {
		$fill_blank = apply_filters(
			'qc_twenty_fill_blank_company_fields',
			array( 'address', 'companyEmail', 'companyPhone', 'website', 'dealerId', 'accountOwnerId', 'country' ),
			$existing
		);

		foreach ( $fill_blank as $field ) {
			if ( isset( $data[ $field ], $existing[ $field ] ) && ! self::is_blank( $existing[ $field ] ) ) {
				unset( $data[ $field ] );
			}
		}
		return $data;
	}

	private static function is_blank( $value ) {
		if ( null === $value || '' === $value || array() === $value ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( ! self::is_blank( $v ) ) {
					return false;
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * Fetch one record by id. $plural is the REST collection, e.g. companies.
	 *
	 * @return array{code:int,error:string,body:?array} body is null when not found.
	 */
	public function get_record( $plural, $id ) {
		$res = $this->request( 'GET', '/rest/' . $plural . '/' . rawurlencode( $id ) );
		if ( 404 === (int) $res['code'] ) {
			return array( 'code' => 404, 'error' => '', 'body' => null );
		}
		return $this->normalise( $res );
	}

	/**
	 * Every Person linked to a Company. Paginated.
	 *
	 * @return array[]|WP_Error
	 */
	public function people_for_company( $company_id ) {
		$rows   = array();
		$cursor = null;
		do {
			$path = '/rest/people?limit=60&filter=' . rawurlencode( 'companyId[eq]:' . $company_id )
				. ( $cursor ? '&starting_after=' . rawurlencode( $cursor ) : '' );
			$res  = $this->request( 'GET', $path );
			if ( ! empty( $res['error'] ) ) {
				return new WP_Error( 'qc_twenty_people', $res['error'] );
			}
			$rows   = array_merge( $rows, self::rows( $res['body'], 'people' ) );
			$cursor = ! empty( $res['body']['pageInfo']['hasNextPage'] ) ? ( $res['body']['pageInfo']['endCursor'] ?? null ) : null;
		} while ( $cursor );
		return $rows;
	}

	/** Normalise a write response to {code,error,body=record}. */
	private function normalise( $res ) {
		if ( ! empty( $res['error'] ) ) {
			return $res;
		}
		return array(
			'code'  => $res['code'],
			'error' => '',
			'body'  => self::first_record( $res['body'] ),
		);
	}

	/* ---------------------------------------------------------------------
	 * People
	 * ------------------------------------------------------------------ */

	/** Find a Person by primary email. */
	public function find_person_by_email( $email ) {
		return $this->find_one( 'people', 'emails.primaryEmail[eq]:' . strtolower( $email ) );
	}

	/** Create or update a Person. */
	public function upsert_person( $email, array $data ) {
		$found = $this->find_person_by_email( $email );

		if ( ! empty( $found['error'] ) ) {
			return $found;
		}

		if ( $found['body'] ) {
			$res = $this->request( 'PATCH', '/rest/people/' . rawurlencode( $found['body']['id'] ), $data );
		} else {
			$res = $this->request( 'POST', '/rest/people', $data );
		}

		return $this->normalise( $res );
	}

	/* ---------------------------------------------------------------------
	 * Opportunities
	 * ------------------------------------------------------------------ */

	/**
	 * Find an application Opportunity for a company, or create it.
	 *
	 * @param string $company_id Company record id.
	 * @param array  $data       Opportunity payload for the create path.
	 */
	public function upsert_application_opportunity( $company_id, array $data ) {
		$res  = $this->request( 'GET', $this->filter_path( 'opportunities', 'companyId[eq]:' . $company_id, 20 ) );
		$rows = empty( $res['error'] ) ? self::rows( $res['body'], 'opportunities' ) : array();

		foreach ( $rows as $row ) {
			if ( ! empty( $row['name'] ) && 0 === strpos( $row['name'], 'Application' ) ) {
				return $this->normalise( $this->request( 'PATCH', '/rest/opportunities/' . rawurlencode( $row['id'] ), $data ) );
			}
		}

		return $this->normalise( $this->request( 'POST', '/rest/opportunities', $data ) );
	}

	/**
	 * Close the dealer application Opportunity for a company.
	 *
	 * Called by the `approved` event. Returns a normalised result. When no
	 * application Opportunity exists, returns 200 with a null body.
	 *
	 * @param string $company_id Company record id.
	 * @param string $stage      Stage value to set, e.g. WON.
	 */
	public function close_application_opportunity( $company_id, $stage = 'WON' ) {
		$res = $this->request( 'GET', $this->filter_path( 'opportunities', 'companyId[eq]:' . $company_id, 50 ) );
		if ( ! empty( $res['error'] ) ) {
			return $res;
		}

		$rows = self::rows( $res['body'], 'opportunities' );
		foreach ( $rows as $row ) {
			if ( ! empty( $row['name'] ) && 0 === strpos( $row['name'], 'Application' ) ) {
				return $this->normalise(
					$this->request(
						'PATCH',
						'/rest/opportunities/' . rawurlencode( $row['id'] ),
						array(
							'stage'     => $stage,
							'closeDate' => gmdate( 'Y-m-d\TH:i:s\Z' ),
						)
					)
				);
			}
		}

		return array(
			'code'  => 200,
			'error' => '',
			'body'  => null,
		);
	}
}
