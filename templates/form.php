<?php
/**
 * Front-end form markup.
 *
 * @var array $atts
 * @var array $settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uid = 'ma-' . wp_generate_password( 6, false, false );
?>
<div class="ma-wrap" id="<?php echo esc_attr( $uid ); ?>">

	<form class="ma-form" novalidate>
		<?php if ( $atts['title'] ) : ?>
			<h2 class="ma-title"><?php echo esc_html( $atts['title'] ); ?></h2>
		<?php endif; ?>

		<?php if ( $atts['subtitle'] ) : ?>
			<p class="ma-sub"><?php echo esc_html( $atts['subtitle'] ); ?></p>
		<?php endif; ?>

		<div class="ma-grid">
			<label class="ma-field">
				<span>Your name <em>*</em></span>
				<input type="text" name="name" required autocomplete="name">
			</label>

			<label class="ma-field">
				<span>Restaurant or business name</span>
				<input type="text" name="business" autocomplete="organization">
			</label>

			<label class="ma-field">
				<span>Email address <em>*</em></span>
				<input type="email" name="email" required autocomplete="email">
			</label>

			<label class="ma-field">
				<span>Phone (optional)</span>
				<input type="tel" name="phone" autocomplete="tel">
			</label>
		</div>

		<label class="ma-field">
			<span>Your menu <em>*</em></span>
			<textarea name="menu" rows="10" placeholder="Paste your menu here — sections, dish names, descriptions and prices. Recipes are welcome too."></textarea>
		</label>

		<div class="ma-upload">
			<label class="ma-file">
				<input type="file" name="menu_file" accept=".pdf,.doc,.docx,.txt,.md,.csv,.rtf,.jpg,.jpeg,.png,.webp">
				<span class="ma-file-btn">Or upload a file</span>
				<span class="ma-file-name">PDF, Word, text or a photo of your menu</span>
			</label>
		</div>

		<?php if ( ! empty( $settings['require_consent'] ) ) : ?>
			<label class="ma-consent">
				<input type="checkbox" name="consent" value="1">
				<span><?php echo esc_html( $settings['consent_text'] ); ?></span>
			</label>
		<?php endif; ?>

		<!-- Honeypot. Hidden from people, irresistible to bots. -->
		<div class="ma-hp" aria-hidden="true">
			<label>Website<input type="text" name="ma_website_url" tabindex="-1" autocomplete="off"></label>
		</div>

		<button type="submit" class="ma-submit"><?php echo esc_html( $atts['button'] ); ?></button>

		<p class="ma-error" role="alert" hidden></p>
	</form>

	<div class="ma-loading" hidden>
		<div class="ma-spinner" aria-hidden="true"></div>
		<p class="ma-loading-title">Analysing your menu…</p>
		<p class="ma-loading-step">Reading through your items and prices</p>
	</div>

	<div class="ma-result" hidden></div>
</div>
