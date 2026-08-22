<?php
/**
 * Front-end form, submission handling and background processing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MA_Form {

	public static function init() {
		add_shortcode( 'menu_audit', array( __CLASS__, 'shortcode' ) );

		add_action( 'wp_ajax_ma_submit', array( __CLASS__, 'ajax_submit' ) );
		add_action( 'wp_ajax_nopriv_ma_submit', array( __CLASS__, 'ajax_submit' ) );

		add_action( 'wp_ajax_ma_process', array( __CLASS__, 'ajax_process' ) );
		add_action( 'wp_ajax_nopriv_ma_process', array( __CLASS__, 'ajax_process' ) );

		add_action( 'wp_ajax_ma_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_nopriv_ma_status', array( __CLASS__, 'ajax_status' ) );

		add_action( 'ma_process_lead', array( __CLASS__, 'process' ) );
	}

	/**
	 * [menu_audit] — drops the form anywhere. Attributes let one site run more
	 * than one entry point with different copy.
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'title'    => 'Get your free menu audit',
				'subtitle' => 'Paste your menu below and we will send you an AI-generated analysis with scores and practical recommendations.',
				'button'   => 'Run my free audit',
			),
			$atts,
			'menu_audit'
		);

		self::enqueue();

		$settings = MA_Settings::all();

		ob_start();
		include MENU_AUDIT_PATH . 'templates/form.php';
		return ob_get_clean();
	}

	private static function enqueue() {
		wp_enqueue_style( 'menu-audit', MENU_AUDIT_URL . 'assets/menu-audit.css', array(), MENU_AUDIT_VERSION );
		wp_enqueue_script( 'menu-audit', MENU_AUDIT_URL . 'assets/menu-audit.js', array(), MENU_AUDIT_VERSION, true );

		wp_localize_script(
			'menu-audit',
			'maAudit',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ma_submit' ),
			)
		);

		wp_add_inline_style(
			'menu-audit',
			':root{--ma-accent:' . esc_attr( MA_Settings::get( 'accent_colour', '#c0392b' ) ) . ';}'
		);
	}

	/**
	 * Step 1 — validate, store the lead, kick off processing, return a token.
	 */
	public static function ajax_submit() {
		check_ajax_referer( 'ma_submit', 'nonce' );

		// Honeypot: a real visitor never fills this in.
		if ( ! empty( $_POST['ma_website_url'] ) ) {
			wp_send_json_success( array( 'token' => 'skipped' ) );
		}

		$settings = MA_Settings::all();

		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$business = isset( $_POST['business'] ) ? sanitize_text_field( wp_unslash( $_POST['business'] ) ) : '';
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone    = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$website  = isset( $_POST['website'] ) ? esc_url_raw( wp_unslash( $_POST['website'] ) ) : '';
		$menu     = isset( $_POST['menu'] ) ? sanitize_textarea_field( wp_unslash( $_POST['menu'] ) ) : '';

		if ( ! $name || ! $email ) {
			wp_send_json_error( array( 'message' => 'Please fill in your name and email address.' ) );
		}

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => 'That email address does not look right.' ) );
		}

		if ( ! empty( $settings['require_consent'] ) && empty( $_POST['consent'] ) ) {
			wp_send_json_error( array( 'message' => 'Please tick the consent box so we can send you the report.' ) );
		}

		$limit = (int) $settings['rate_limit'];
		if ( $limit > 0 && MA_DB::recent_count_for_ip( 60 ) >= $limit ) {
			wp_send_json_error( array( 'message' => 'You have already run a few audits in the last hour. Please try again later.' ) );
		}

		$image = null;

		if ( ! empty( $_FILES['menu_file']['name'] ) ) {
			$extracted = MA_Extract::from_upload( $_FILES['menu_file'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			if ( is_wp_error( $extracted ) ) {
				wp_send_json_error( array( 'message' => $extracted->get_error_message() ) );
			}

			$image     = $extracted['image'];
			$menu      = trim( $extracted['text'] . "\n\n" . $menu );
			$file_name = $extracted['filename'];
		}

		if ( ! $image && strlen( trim( $menu ) ) < 40 ) {
			wp_send_json_error( array( 'message' => 'Please paste your menu, or upload it as a file, so we have something to analyse.' ) );
		}

		$id = MA_DB::insert(
			array(
				'name'        => $name,
				'business'    => $business,
				'email'       => $email,
				'phone'       => $phone,
				'website'     => $website,
				'menu_text'   => $menu,
				'source_file' => isset( $file_name ) ? $file_name : '',
			)
		);

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => 'We could not save your submission. Please try again.' ) );
		}

		$lead = MA_DB::get( $id );

		// The image is only needed for the one API call, so it lives in a
		// transient rather than bloating the leads table.
		if ( $image ) {
			set_transient( 'ma_image_' . $lead->token, $image, 30 * MINUTE_IN_SECONDS );
		}

		self::spawn( $lead->token );

		wp_send_json_success(
			array(
				'token'    => $lead->token,
				'redirect' => $settings['redirect_url'],
			)
		);
	}

	/**
	 * Fire the worker without making the visitor wait for it.
	 */
	private static function spawn( $token ) {
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 1,
				'blocking'  => false,
				'sslverify' => false,
				'body'      => array(
					'action' => 'ma_process',
					'token'  => $token,
				),
			)
		);

		// Belt and braces on hosts that block loopback requests.
		if ( ! wp_next_scheduled( 'ma_process_lead', array( $token ) ) ) {
			wp_schedule_single_event( time() + 30, 'ma_process_lead', array( $token ) );
		}
	}

	public static function ajax_process() {
		$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';

		if ( $token ) {
			self::process( $token );
		}

		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * The actual work. Safe to call more than once — the status guard means only
	 * the first caller runs the audit.
	 */
	public static function process( $token ) {
		$lead = MA_DB::get_by_token( $token );

		if ( ! $lead || 'pending' !== $lead->status ) {
			return;
		}

		MA_DB::update( $lead->id, array( 'status' => 'processing' ) );

		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$image  = get_transient( 'ma_image_' . $token );
		$report = MA_AI::audit( $lead->menu_text, $lead->business, $image ? $image : null );

		delete_transient( 'ma_image_' . $token );

		if ( is_wp_error( $report ) ) {
			MA_DB::update(
				$lead->id,
				array(
					'status'        => 'failed',
					'error_message' => $report->get_error_message(),
				)
			);
			return;
		}

		MA_DB::update(
			$lead->id,
			array(
				'status'        => 'complete',
				'report'        => wp_json_encode( $report ),
				'overall_score' => (int) $report['overall_score'],
			)
		);

		$lead = MA_DB::get( $lead->id );

		MA_Mailer::send_report( $lead, $report );
		MA_Mailer::notify_owner( $lead, $report );
		MA_Mailer::push_webhook( $lead, $report );
	}

	/**
	 * Step 2 — the front end polls this until the report is ready.
	 */
	public static function ajax_status() {
		check_ajax_referer( 'ma_submit', 'nonce' );

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$lead  = $token ? MA_DB::get_by_token( $token ) : null;

		if ( ! $lead ) {
			wp_send_json_error( array( 'message' => 'We lost track of that submission. Please try again.' ) );
		}

		if ( 'failed' === $lead->status ) {
			wp_send_json_error(
				array(
					'message' => 'Something went wrong while analysing your menu. Please try again, or get in touch and we will run it manually.',
					'debug'   => current_user_can( 'manage_options' ) ? $lead->error_message : '',
				)
			);
		}

		if ( 'complete' !== $lead->status ) {
			// The loopback can be blocked; a poll that finds it still pending
			// after the first few seconds runs the job on this request instead.
			if ( 'pending' === $lead->status && strtotime( $lead->created_at ) < ( current_time( 'timestamp' ) - 10 ) ) {
				self::process( $token );
			}

			wp_send_json_success( array( 'status' => $lead->status ) );
		}

		$report = json_decode( $lead->report, true );

		wp_send_json_success(
			array(
				'status' => 'complete',
				'html'   => MA_Report::render( $report, $lead ),
				'url'    => MA_Report::url( $lead->token ),
			)
		);
	}
}
