<?php
/**
 * Plugin Name:       QC Twenty Sync
 * Plugin URI:        https://qualitycomponents.com.au/
 * Description:       Syncs WordPress accounts to Twenty CRM (applications, approvals, profile edits, order summaries) and Twenty retailers to Omnisend, including a signed webhook for instant updates. Queued with Action Scheduler.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Quality Components Pty Ltd
 * Text Domain:       qc-twenty-sync
 *
 * DEV-FIRST. This plugin is built and tested on the Dev site only.
 * Do not deploy to Live without written approval from Fraser.
 */

defined( 'ABSPATH' ) || exit;

define( 'QC_TWENTY_SYNC_VERSION', '0.2.0' );
define( 'QC_TWENTY_SYNC_FILE', __FILE__ );
define( 'QC_TWENTY_SYNC_DIR', plugin_dir_path( __FILE__ ) );

require_once QC_TWENTY_SYNC_DIR . 'includes/class-qc-twenty-log.php';
require_once QC_TWENTY_SYNC_DIR . 'includes/class-qc-twenty-client.php';
require_once QC_TWENTY_SYNC_DIR . 'includes/class-qc-twenty-sync.php';
require_once QC_TWENTY_SYNC_DIR . 'includes/class-qc-omnisend-client.php';
require_once QC_TWENTY_SYNC_DIR . 'includes/class-qc-omnisend-sync.php';
require_once QC_TWENTY_SYNC_DIR . 'includes/class-qc-twenty-webhook.php';

if ( is_admin() ) {
	require_once QC_TWENTY_SYNC_DIR . 'includes/class-qc-twenty-admin.php';
}

register_activation_hook( __FILE__, array( 'QC_Twenty_Log', 'install' ) );
register_deactivation_hook( __FILE__, array( 'QC_Omnisend_Sync', 'unschedule' ) );

add_action( 'plugins_loaded', array( 'QC_Twenty_Sync', 'init' ) );
add_action( 'plugins_loaded', array( 'QC_Omnisend_Sync', 'init' ) );
