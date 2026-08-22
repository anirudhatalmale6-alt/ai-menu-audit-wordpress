<?php
/**
 * Standalone report page. Rendered outside the theme so the layout is identical
 * for everyone and prints cleanly to PDF.
 *
 * @var object $lead
 * @var array  $report
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$accent = MA_Settings::get( 'accent_colour', '#c0392b' );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( 'Menu Audit — ' . ( $lead->business ? $lead->business : get_bloginfo( 'name' ) ) ); ?></title>
	<style>
		body { margin:0; padding:32px 20px 64px; background:#fff; }
		.ma-page { max-width:680px; margin:0 auto; }
		.ma-bar { display:flex; justify-content:space-between; align-items:center; gap:16px;
			margin-bottom:28px; font:14px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#6e6e73; }
		.ma-print { border:1px solid #d2d2d7; background:#fff; color:#1d1d1f; border-radius:8px;
			padding:9px 16px; font-size:14px; font-weight:600; cursor:pointer; }
		.ma-print:hover { background:#f5f5f7; }
		.ma-foot { margin-top:48px; padding-top:20px; border-top:1px solid #ececf0; text-align:center;
			font:13px/1.6 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#8a8a8f; }
		.ma-foot a { color:<?php echo esc_attr( $accent ); ?>; }
		@media print { .ma-bar { display:none; } body { padding:0; } }
	</style>
</head>
<body>
	<div class="ma-page">
		<div class="ma-bar">
			<span>Prepared <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $lead->created_at ) ) ); ?></span>
			<button class="ma-print" onclick="window.print()">Save as PDF</button>
		</div>

		<?php echo MA_Report::render( $report, $lead ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

		<div class="ma-foot">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
		</div>
	</div>
</body>
</html>
