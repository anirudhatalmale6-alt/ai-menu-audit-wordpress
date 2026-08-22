<?php
/**
 * Plugin Name: AI Menu Audit
 * Plugin URI:  https://example.com/
 * Description: AI-powered menu audit tool for food & beverage businesses. Drop [menu_audit] on any page to capture leads and deliver an instant menu analysis report.
 * Version:     1.0.0
 * Author:      Anirudha Talmale
 * License:     GPL-2.0-or-later
 * Text Domain: menu-audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MENU_AUDIT_VERSION', '1.0.0' );
define( 'MENU_AUDIT_FILE', __FILE__ );
define( 'MENU_AUDIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'MENU_AUDIT_URL', plugin_dir_url( __FILE__ ) );

require_once MENU_AUDIT_PATH . 'includes/class-ma-db.php';
require_once MENU_AUDIT_PATH . 'includes/class-ma-settings.php';
require_once MENU_AUDIT_PATH . 'includes/class-ma-extract.php';
require_once MENU_AUDIT_PATH . 'includes/class-ma-ai.php';
require_once MENU_AUDIT_PATH . 'includes/class-ma-report.php';
require_once MENU_AUDIT_PATH . 'includes/class-ma-mailer.php';
require_once MENU_AUDIT_PATH . 'includes/class-ma-form.php';

if ( is_admin() ) {
	require_once MENU_AUDIT_PATH . 'admin/class-ma-admin.php';
}

register_activation_hook( __FILE__, array( 'MA_DB', 'install' ) );

/**
 * Boot the plugin once WordPress is ready.
 */
function menu_audit_init() {
	MA_DB::maybe_upgrade();
	MA_Form::init();
	MA_Report::init();

	if ( is_admin() ) {
		MA_Admin::init();
	}
}
add_action( 'plugins_loaded', 'menu_audit_init' );
