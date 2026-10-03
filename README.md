# QC Twenty Sync

WordPress plugin for Quality Components. It keeps three systems in step:

```
 WordPress / WooCommerce / B2BKing  ──►  Twenty CRM  ──►  Omnisend
        (accounts, orders)            (system of record)   (email marketing)
```

Current version: **0.2.5**. Requires WordPress 6.0+, PHP 7.4+, WooCommerce and B2BKing. Action Scheduler (bundled with WooCommerce) is used for the queue.

> **Dev-first.** Built and tested on the dev site. Deploy to Live only with approval from Fraser or Simon. Dev and Live use the **same** Twenty and Omnisend accounts, so anything dev pushes is real.

---

## 1. Sync directions at a glance

| Direction | What moves | Trigger | Speed |
|---|---|---|---|
| **WordPress → Twenty** | Company, Person, application Opportunity, order summary | WP events (see §3) and the backfill tool | Queued, within about a minute |
| **Twenty → Omnisend** | Contacts for people at **ACTIVE** Companies, tag, custom properties | Twenty webhook **and** an hourly reconcile | About 30 seconds (webhook); within the hour (reconcile) |
| **Twenty → WordPress** | **Nothing.** | — | — |
| **Omnisend → anything** | **Nothing.** | — | — |

Key consequences:

- **WordPress is the source for account facts** (name, ABN, GST, segment, rep, terms, order history). Editing those in Twenty will be overwritten by the next WordPress push. Fields Twenty owns are listed in §5.
- **Edits made in Twenty never reach WordPress.** WordPress has no webhook receiver for Twenty and does not poll it.
- **Omnisend is fed from Twenty, not from WordPress.** A person only reaches Omnisend if they exist in Twenty, are linked to a Company, and that Company is ACTIVE.
- **Consent is never changed.** The plugin never subscribes or unsubscribes anyone. A contact it has never seen is created as `nonSubscribed`; an existing contact's channel status is sent back exactly as Omnisend holds it.

---

## 2. Setup

### 2.1 Install

Install from GitHub with WP Pusher: repository `fraserwilk/qc-twenty-sync`, branch `main`. Activate normally (activation creates the log table; copying files over an active install does not).

### 2.2 Secrets in `wp-config.php`

Secrets are read from constants and are **never stored in the database or logged**. Add these **above** the `/* That's all, stop editing! */` line, with straight quotes:

```php
define( 'QC_TWENTY_API_KEY',        '…' );   // Twenty API key (required)
define( 'QC_OMNISEND_API_KEY',      '…' );   // Omnisend API key (required for Omnisend)
define( 'QC_TWENTY_WEBHOOK_SECRET', '…' );   // Shared secret for the Twenty webhook (required for the webhook)
// Optional:
define( 'QC_TWENTY_BASE_URL', 'https://crm.qualitycomponents.com.au' ); // default shown
```

Generate a webhook secret with `openssl rand -hex 32`.

### 2.3 Twenty fields the plugin expects

Companies: `externalAccountId`, `wpCustomerId`, `wpAccountUrl`, `lifecycleStage`, `tradingName`, `legalName`, `abn`, `nzbn`, `country`, `address`, `companyEmail`, `companyPhone`, `website`, `dealerId`, `gstRegistered`, `accountTerms`, `b2Bsegment` (API name; RETAILER / OEM), `salesRep` (text), `accountOwnerId`, `orderCount`, `lastOrderDate`, `lastOrderValue`, `lifetimeOrderValue`, `lastOrderSummaryRefreshed`. People: name, emails, phones, `jobTitle`. A Company text field called **Sales Rep** (API name `salesRep`) must exist before the rep is synced.

### 2.4 Twenty webhook (for instant Omnisend updates)

In Twenty: **Settings → APIs & Webhooks → Webhooks → add**.

1. **Endpoint URL:** `https://<your-site>/wp-json/qc-twenty/v1/webhook`
2. **Filters:** *Companies → All* and *People → All*.
3. **Secret:** paste the **same** value as `QC_TWENTY_WEBHOOK_SECRET`. Leave it empty and Twenty won't sign requests, so every one is rejected.
4. Save, then edit an ACTIVE Company in Twenty and check the Event log (§6) after about 30 seconds.

Register the webhook against **Live only**; Twenty cannot reach a `.local` dev site.

---

## 3. WordPress → Twenty (details)

### 3.1 Events that trigger a push

| Event | WordPress hook | What it does in Twenty |
|---|---|---|
| `application` | `b2bking_new_user_requires_approval` | Upserts Company (stage **APPLICANT**), Person, and an Opportunity named `Application - <company>` at stage `NEW`. |
| `approved` | `b2bking_approved_user_as_b2c`, `b2bking_account_approved_finish` | Company stage **ACTIVE**; closes the application Opportunity as `WON` with a close date. |
| `profile` | `woocommerce_created_customer`, `profile_update`, `woocommerce_customer_save_address`, `woocommerce_save_account_details` | Refreshes Company and Person fields. **Never changes lifecycle stage** and never touches Opportunities. Staff accounts (anyone who can edit posts) are ignored. |
| `order` | `woocommerce_order_status_processing`, `…_completed`, `…_on-hold`, `b2bking_after_approve_order` | Recounts the Company's order summary from WooCommerce (never increments, so it self-corrects). Guest orders are skipped. A Company is never created from an order. |

Events are queued in Action Scheduler and retried with back-off (up to 6 attempts) on network errors, timeouts and rate limits. `order` and `profile` payloads are rebuilt when they run, so you always get current data.

### 3.2 Matching an existing Twenty Company

Most Companies existed in Twenty before WordPress, without a WordPress ID. The plugin looks for a match in this order and stops at the first hit:

1. `externalAccountId` = `wp-<user id>` (the permanent key)
2. `wpCustomerId`
3. ABN (digits-only, and the spaced `12 345 678 901` form)
4. Company email
5. Exact Company name

A match is updated and stamped with `externalAccountId`, `wpCustomerId` and `wpAccountUrl`. If a fallback finds **more than one** Company the push is skipped with a note and logged, never guessed; link those manually. No match creates a new Company.

### 3.3 Field mapping

| Twenty field | WordPress source | Overwritten each sync? |
|---|---|---|
| `externalAccountId`, `wpCustomerId`, `wpAccountUrl` | user ID | Yes |
| `tradingName`, `legalName` | `billing_company`, else B2BKing Company Name field (`b2bking_custom_field_18`) | Yes |
| `name` | same as trading name | **Only when creating.** The curated Twenty name is never overwritten. |
| `abn` | `billing_vat` (B2BKing ABN field), stored digits-only; placeholders like `999999999` ignored | Yes |
| `gstRegistered` | explicit meta if present, else **true when a valid 11-digit ABN exists** | Yes |
| `b2Bsegment` | B2BKing group title: *Retailer* → RETAILER, *OEM* → OEM | Yes |
| `accountTerms` | explicit value if present, else **COD for retailers** | **Fill-blank only** |
| `salesRep` (text) | `qc_sales_rep` user meta, chosen from **Settings → Sales Reps** | Yes |
| `accountOwnerId` | rep → Twenty workspace member (see §3.5) | **Fill-blank only** |
| `address` | billing address 1+2, city, state, postcode, country | **Fill-blank only** |
| `companyEmail`, `companyPhone`, `website`, `dealerId`, `country` | billing email / phone, website meta, dealer id meta, billing country (AU/NZ) | **Fill-blank only** |
| `lifecycleStage` | APPLICANT on application, ACTIVE on approval | Only on those two events |
| `orderCount`, `lastOrderDate`, `lastOrderValue`, `lifetimeOrderValue`, `lastOrderSummaryRefreshed` | WooCommerce orders in `processing`, `completed`, `on-hold`, **ex GST** | Yes (recounted) |
| Person: name, email, phone, `jobTitle` | billing name / email / phone, job-title meta | Yes |
| `customerType` | **Never sent.** Curated by hand in Twenty. | — |

**Fill-blank only** means: if Twenty already has a value, WordPress leaves it alone, so hand-curated data (imported addresses, phones, dealer IDs) is safe. Empty WordPress values are never sent, so a blank never erases a Twenty value.

International phone numbers (starting `+`) are sent without a forced country code; otherwise AU (NZ for NZ billing country) is assumed.

### 3.4 Lifecycle stage

| Stage | Set by |
|---|---|
| PROSPECT | Hand in Twenty (all Companies that have no WordPress account were bulk-set to this) |
| APPLICANT | WordPress, on a dealer application |
| ACTIVE | WordPress, on approval (or by hand) |
| DORMANT / CLOSED | By hand in Twenty |

Only **ACTIVE** Companies are sent to Omnisend (§4).

### 3.5 Sales reps

Reps are not Twenty workspace members, so the rep is stored as text in `salesRep`. If a rep *does* exist in Twenty, add a sub-field named `rep_twenty_email` to the repeater under **Settings → Sales Reps** (ACF group "Sales Representatives") and fill in their Twenty login email; the Company's **Account owner** is then set too (fill-blank only). Without a matching member the owner is simply skipped.

### 3.6 Backfill tool (existing customers)

**Tools → QC Twenty Sync → Backfill customers.** It pushes every WordPress customer to Twenty (profile fields + order summary) and shows, per user, one of: `linked`, `will-link (<matched by>)`, `will-create`, `ambiguous`, `error`.

1. Click **Dry run (first batch)**. Nothing is written. Review the table.
2. Click **Run live (first batch)**. Live batches are **8 users** (about a minute; each user takes ~6 seconds to stay under Twenty's rate limit). Dry-run batches are 40.
3. Keep clicking the **(next batch)** button until it stops appearing. Do not close the page while a batch runs.
4. Each mode continues from its own place, so a dry run never makes a live run skip users. Re-running is safe: it recomputes and never double-counts.

Administrators, shop managers and editors are excluded. Internal and test accounts are *not* excluded and will be created in Twenty.

---

## 4. Twenty → Omnisend (details)

### 4.1 Who is eligible

A person is tagged and synced when they have a valid email, are linked to a Twenty Company, and that Company's lifecycle stage is **ACTIVE** (filter `qc_omnisend_eligible_stage`).

### 4.2 What is written to Omnisend

- Tag **`qc_retailer_active`** (other tags on the contact are always kept).
- First and last name.
- Custom properties: `qc_account_id`, `qc_dealer_code`, `qc_company`, `qc_lifecycle_stage`, `qc_account_terms`, `qc_country`, `qc_segment`, `qc_state`, `qc_postcode`, `qc_customer_type`, `qc_order_count`, `qc_last_order_date`, `qc_lifetime_value`. Empty values are not sent.

### 4.3 How it runs

| Path | When | Notes |
|---|---|---|
| **Webhook** | A Company or Person changes in Twenty | Verified by HMAC signature. The job waits **20 seconds** so a burst of edits becomes one sync, then **re-reads the record from Twenty** (the webhook payload is never trusted as data). |
| **Hourly reconcile** | WP-cron, every hour | Safety net. Processes a rotating window of **200 contacts per run** with a saved cursor that wraps, so every contact is eventually reached however many are eligible. |

### 4.4 Untagging

A contact we tagged is untagged when: its Company leaves ACTIVE (or is deleted), the Person is deleted, or the Person moves to a non-ACTIVE Company. Untagging removes only `qc_retailer_active`, keeps all other tags and the consent status, and sets `qc_lifecycle_stage` to the Company's current stage. The plugin tracks which contacts it tagged (option `qc_omnisend_tracked`), so it never untags a contact it did not tag.

Safeguards: if Twenty returns no eligible contacts at all while many are tracked, the clean-up is skipped and an error is logged rather than untagging everyone. A Person email change leaves the old address tagged until the hourly clean-up finds it.

---

## 5. Who owns which field

| Owner | Fields |
|---|---|
| **WordPress** (overwritten on every push) | WP IDs and URL, trading/legal name, ABN, GST, segment, sales rep, order summary, Person name/email/phone/job title |
| **Twenty** (WordPress only fills blanks) | Address, company email/phone, website, dealer ID, country, account terms, account owner |
| **Twenty only** (never sent) | Company `name` after creation, `customerType`, lifecycle stage beyond APPLICANT/ACTIVE, notes, anything else |

---

## 6. Event log and retention

**Tools → QC Twenty Sync → Event log** shows the latest 100 rows. Use the view links above the table:

- **Default** hides the hourly Omnisend pushes so real events aren't buried.
- **All**, **Failed**, **Pending**, **Omnisend pushes**, **Omnisend untags**, **Profile**, **Application**, **Approved**, **Order**.

Columns: time (UTC), event, WP ID, status (`pending`, `done`, `failed`, `skipped`), tries, HTTP code, last error. **Retry** re-runs a queued WordPress→Twenty event. Skipped rows carry a note, e.g. `ambiguous Company match` or `no Company for wp-123`.

Retention (daily job): finished rows deleted after **30 days**, failed or stuck rows after **90**. Filters: `qc_twenty_log_keep_days`, `qc_twenty_log_keep_days_failed`.

Some actions are not logged as WordPress→Twenty events: the backfill writes directly, and its result table is shown only on the page after each batch.

---

## 7. How to use it day to day

| I want to… | Do this |
|---|---|
| Add a new dealer | Nothing. The B2BKing application creates an APPLICANT in Twenty; approving it makes it ACTIVE and (within the hour, or sooner via webhook) tags them in Omnisend. |
| Change a dealer's address or phone | Edit it in WordPress. If Twenty already has an address/phone it will **not** change; edit it in Twenty. |
| Rename a dealer in Twenty | Go ahead. The Company `name` is never overwritten. |
| Stop a dealer receiving retailer emails | Set their Company to anything other than ACTIVE in Twenty. The Omnisend tag is removed within about 30 seconds. |
| Re-sync everyone | Run the backfill (§3.6). |
| Assign a rep | Set the user's Sales Rep in WordPress; `salesRep` updates on the next push or backfill. |
| See why something didn't sync | Event log → **Failed**, or **Pending**. Check the Code and Last error columns. |
| Pause syncing | Deactivate the plugin (this removes the hourly Omnisend and daily log-prune jobs; already-queued Action Scheduler jobs are not cancelled). |

---

## 8. Troubleshooting

| Symptom | Likely cause |
|---|---|
| Red "QC_TWENTY_API_KEY is not defined" on the admin page | Constant missing or below the "stop editing" line in `wp-config.php`. |
| Webhook returns **503** `QC_TWENTY_WEBHOOK_SECRET is not defined` | Secret not defined on that site. |
| Webhook returns **401** | Secret in Twenty differs from `wp-config.php`, the Twenty secret field is empty, or the request is older than 10 minutes. |
| Edit in Twenty produces no Omnisend row | The Company isn't ACTIVE, or has no Person with an email, or the webhook isn't registered. |
| Many **429** errors | Twenty rate limit (100 requests/minute). The plugin backs off and retries; use smaller backfill batches. |
| Backfill stops partway | Batch exceeded a server time limit. Live batches are 8 users for this reason. |
| `ambiguous Company match` | Two Twenty Companies share the ABN/email/name. Set `externalAccountId` on the right one by hand. |
| Contact still tagged after leaving ACTIVE | Wait for the webhook or hourly job; check **Omnisend untags** in the log. |

---

## 9. Developer reference

**Filters**

| Filter | Purpose |
|---|---|
| `qc_twenty_company_meta_map` | Meta keys per Twenty field (first non-empty wins) |
| `qc_twenty_company_extra` | Add or override Company fields per user |
| `qc_twenty_b2b_segment_map` | B2BKing group title → segment value |
| `qc_twenty_default_retailer_terms` | Default terms for retailers (`COD`) |
| `qc_twenty_sales_rep_map` | Rep label → Twenty member email |
| `qc_twenty_fill_blank_company_fields` | Fields where an existing Twenty value wins |
| `qc_twenty_manage_company_name` | Allow WordPress to overwrite `name` |
| `qc_twenty_update_company_data` | Final Company payload before update |
| `qc_twenty_lifecycle_for_event`, `qc_twenty_application_stage`, `qc_twenty_approved_opportunity_stage` | Stages used on application / approval |
| `qc_twenty_counted_order_statuses`, `qc_twenty_amounts_include_tax` | Which orders count, and ex- or inc-GST |
| `qc_omnisend_eligible_stage`, `qc_omnisend_contact_payload` | Who is tagged, and the Omnisend contact body |
| `qc_twenty_base_url`, `qc_twenty_api_key`, `qc_omnisend_base_url`, `qc_omnisend_api_key` | Override endpoints/keys |
| `qc_twenty_log_keep_days`, `qc_twenty_log_keep_days_failed` | Log retention |

**Files**

- `qc-twenty-sync.php`: bootstrap, version, activation and deactivation hooks
- `includes/class-qc-twenty-sync.php`: WordPress hooks, payload mapping, queue and retry, backfill
- `includes/class-qc-twenty-client.php`: Twenty REST client, Company matching, fill-blank policy
- `includes/class-qc-omnisend-sync.php`: hourly reconcile, tagging, untagging, tracking
- `includes/class-qc-omnisend-client.php`: Omnisend contacts API (consent-safe upsert, tag removal)
- `includes/class-qc-twenty-webhook.php`: signed webhook endpoint and its delayed job
- `includes/class-qc-twenty-log.php`: event log table, views, retention
- `includes/class-qc-twenty-admin.php`: Tools page (backfill, log)

**Webhook signature:** HMAC SHA256 over `"{X-Twenty-Webhook-Timestamp}:{raw body}"` with the secret, compared to `X-Twenty-Webhook-Signature`; timestamps more than 10 minutes off are rejected.

**Scheduled jobs:** `qc_omnisend_reconcile` (hourly), `qc_twenty_log_prune` (daily), Action Scheduler jobs `qc_twenty_process_event` and `qc_twenty_webhook_job`.

## Known limitations

- No Twenty → WordPress sync.
- Reps can't be Twenty owners until they are workspace members.
- `accountTerms` has no WordPress source other than the retailer default.
- Order values are ex GST (filterable).
- An Omnisend contact whose email changes in Twenty keeps its old tag until the hourly clean-up.
