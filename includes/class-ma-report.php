<?php
/**
 * Renders a stored report — on screen, in the email, and on its own shareable page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MA_Report {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_page' ) );
	}

	public static function add_rewrite() {
		add_rewrite_rule( '^menu-audit/([a-zA-Z0-9]+)/?$', 'index.php?ma_report=$matches[1]', 'top' );

		// The rule is registered on init, so the flush has to happen here rather
		// than in the activation hook.
		if ( get_option( 'menu_audit_flush_rewrite' ) ) {
			delete_option( 'menu_audit_flush_rewrite' );
			flush_rewrite_rules();
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = 'ma_report';
		return $vars;
	}

	public static function url( $token ) {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/menu-audit/' . $token . '/' );
		}

		return add_query_arg( 'ma_report', $token, home_url( '/' ) );
	}

	/**
	 * The standalone report page. Unguessable token, no login needed — this is the
	 * link that goes in the email so the report survives an email client that
	 * strips styling.
	 */
	public static function maybe_render_page() {
		$token = get_query_var( 'ma_report' );

		if ( ! $token ) {
			$token = isset( $_GET['ma_report'] ) ? sanitize_text_field( wp_unslash( $_GET['ma_report'] ) ) : '';
		}

		if ( ! $token ) {
			return;
		}

		$lead = MA_DB::get_by_token( $token );

		if ( ! $lead || 'complete' !== $lead->status ) {
			status_header( 404 );
			wp_die( 'That report link is not valid or has expired.', 'Report not found', array( 'response' => 404 ) );
		}

		$report = json_decode( $lead->report, true );

		if ( ! is_array( $report ) ) {
			status_header( 500 );
			wp_die( 'That report could not be loaded.', 'Report error', array( 'response' => 500 ) );
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		include MENU_AUDIT_PATH . 'templates/report-page.php';
		exit;
	}

	/**
	 * Inline-styled report used in the AJAX response and the email body.
	 *
	 * @param bool $for_email Emails need every style inline and no external assets.
	 */
	public static function render( $report, $lead, $for_email = false ) {
		$accent   = MA_Settings::get( 'accent_colour', '#c0392b' );
		$overall  = (int) $report['overall_score'];
		$business = $lead ? $lead->business : '';

		ob_start();
		?>
		<div class="ma-report" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1d1d1f;line-height:1.6;max-width:680px;margin:0 auto;">

			<div style="text-align:center;padding:28px 20px;background:#faf8f5;border-radius:12px;">
				<div style="font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#8a8a8f;">Menu Audit</div>
				<?php if ( $business ) : ?>
					<div style="font-size:22px;font-weight:700;margin-top:6px;"><?php echo esc_html( $business ); ?></div>
				<?php endif; ?>
				<div style="font-size:52px;font-weight:800;line-height:1;margin:14px 0 4px;color:<?php echo esc_attr( $accent ); ?>;">
					<?php echo esc_html( $overall ); ?><span style="font-size:24px;color:#8a8a8f;font-weight:600;">/10</span>
				</div>
				<div style="font-size:13px;color:#8a8a8f;">Overall score</div>
			</div>

			<?php if ( ! empty( $report['headline'] ) ) : ?>
				<p style="font-size:19px;font-weight:600;margin:28px 0 10px;"><?php echo esc_html( $report['headline'] ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $report['summary'] ) ) : ?>
				<p style="margin:0 0 28px;font-size:16px;"><?php echo wp_kses_post( $report['summary'] ); ?></p>
			<?php endif; ?>

			<h3 style="font-size:13px;letter-spacing:.1em;text-transform:uppercase;color:#8a8a8f;margin:0 0 14px;">Scores</h3>

			<?php foreach ( $report['scores'] as $row ) : ?>
				<?php $width = max( 4, (int) $row['score'] * 10 ); ?>
				<div style="margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid #ececf0;">
					<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
						<tr>
							<td style="font-size:16px;font-weight:600;"><?php echo esc_html( $row['label'] ); ?></td>
							<td align="right" style="font-size:16px;font-weight:700;color:<?php echo esc_attr( self::score_colour( $row['score'], $accent ) ); ?>;">
								<?php echo esc_html( $row['score'] ); ?>/10
							</td>
						</tr>
					</table>
					<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ececf0;border-radius:99px;height:6px;">
						<tr>
							<td width="<?php echo esc_attr( $width ); ?>%" style="background:<?php echo esc_attr( self::score_colour( $row['score'], $accent ) ); ?>;border-radius:99px;height:6px;font-size:0;line-height:0;">&nbsp;</td>
							<td style="font-size:0;line-height:0;">&nbsp;</td>
						</tr>
					</table>
					<?php if ( $row['comment'] ) : ?>
						<p style="margin:10px 0 0;font-size:15px;color:#48484a;"><?php echo wp_kses_post( $row['comment'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<h3 style="font-size:13px;letter-spacing:.1em;text-transform:uppercase;color:#8a8a8f;margin:32px 0 14px;">What to do next</h3>

			<?php foreach ( $report['recommendations'] as $i => $rec ) : ?>
				<div style="margin-bottom:14px;padding:18px 20px;background:#faf8f5;border-left:3px solid <?php echo esc_attr( $accent ); ?>;border-radius:0 8px 8px 0;">
					<div style="font-size:12px;font-weight:700;color:<?php echo esc_attr( $accent ); ?>;letter-spacing:.08em;">
						<?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?>
					</div>
					<div style="font-size:17px;font-weight:700;margin:2px 0 8px;"><?php echo esc_html( $rec['title'] ); ?></div>
					<p style="margin:0 0 8px;font-size:15px;"><?php echo wp_kses_post( $rec['action'] ); ?></p>
					<?php if ( $rec['why'] ) : ?>
						<p style="margin:0;font-size:14px;color:#6e6e73;"><em><?php echo wp_kses_post( $rec['why'] ); ?></em></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<?php if ( ! $for_email ) : ?>
				<p style="margin:28px 0 0;font-size:13px;color:#8a8a8f;text-align:center;">
					A copy of this report has been emailed to you.
				</p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function score_colour( $score, $accent ) {
		if ( $score >= 8 ) {
			return '#2e9e5b';
		}
		if ( $score >= 5 ) {
			return '#d08700';
		}
		return $accent;
	}
}
