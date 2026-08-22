<?php
/**
 * Options wrapper + the default audit prompt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MA_Settings {

	const OPTION = 'menu_audit_settings';

	public static function defaults() {
		return array(
			'provider'          => 'anthropic',
			'api_key'           => '',
			'model'             => 'claude-opus-5',
			'effort'            => 'medium',
			'prompt'            => self::default_prompt(),
			'business_context'  => '',
			'from_name'         => get_bloginfo( 'name' ),
			'from_email'        => get_option( 'admin_email' ),
			'email_subject'     => 'Your Menu Audit Report — {business}',
			'email_intro'       => "Hi {name},\n\nThanks for submitting your menu. Your audit report is below — it covers eight areas and finishes with a few things you can act on this week.\n\nIf you'd like us to walk you through it, just reply to this email.",
			'notify_email'      => get_option( 'admin_email' ),
			'notify_enabled'    => 1,
			'accent_colour'     => '#c0392b',
			'rate_limit'        => 3,
			'require_consent'   => 1,
			'consent_text'      => 'I agree to be contacted about my menu audit.',
			'redirect_url'      => '',
			'webhook_url'       => '',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key, $fallback = '' ) {
		$all = self::all();
		return isset( $all[ $key ] ) && '' !== $all[ $key ] ? $all[ $key ] : $fallback;
	}

	public static function save( $values ) {
		update_option( self::OPTION, $values );
	}

	/**
	 * The audit criteria. Editable by the site owner from the settings screen —
	 * {menu}, {business} and {context} are substituted at request time.
	 */
	public static function default_prompt() {
		return <<<PROMPT
You are a restaurant menu consultant with 20 years of experience in menu engineering,
food costing and hospitality marketing. You are auditing the menu below for {business}.

{context}

Score each of the eight criteria from 1 to 10, where 1 is poor and 10 is excellent.
Be honest and specific — a generous score that tells the owner nothing is useless.
Every observation must point at something you can actually see in the menu: name a
dish, a price, a section heading. Never invent items that are not there.

The criteria:

1. Differentiation — how much this menu stands apart from a typical competitor in
   its category. Generic dish names and copy-paste sections score low.
2. Uniqueness — signature items, house recipes, anything that could not simply be
   ordered somewhere else down the road.
3. Pricing — price spread, anchoring, psychological pricing, obvious money left on
   the table, and items that look underpriced or overpriced for what they are.
4. Structure — ordering, sectioning, length, and how easy the menu is to scan.
   Sections longer than about seven items usually hurt decision-making.
5. High-margin potential — which items likely carry the best margin, and whether
   the menu currently draws attention to them.
6. Customer appeal — how appetising and clear the descriptions are, whether
   dietary needs are addressed, whether the language suits the audience.
7. Competitive positioning — what the menu says about where this business sits in
   its local market, and whether that position is being communicated deliberately.
8. Improvement opportunity — the size of the gap between where the menu is now
   and where it could realistically be.

Then give 3 to 5 recommendations. Each one must be something the owner could start
this week without a rebuild — a price change, a description rewrite, a section
reorder, an item to cut or promote. Say what to do and why it will help.

Write in plain, warm, direct English. Address the owner as "you". No jargon, no
filler, no restating the criteria back at them.
PROMPT;
	}

	public static function models( $provider = 'anthropic' ) {
		if ( 'openai' === $provider ) {
			return array(
				'gpt-4.1'      => 'GPT-4.1',
				'gpt-4.1-mini' => 'GPT-4.1 mini (cheaper)',
				'gpt-4o'       => 'GPT-4o',
			);
		}

		return array(
			'claude-opus-5'     => 'Claude Opus 5 (best quality)',
			'claude-sonnet-5'   => 'Claude Sonnet 5 (balanced)',
			'claude-haiku-4-5'  => 'Claude Haiku 4.5 (cheapest)',
		);
	}

	/**
	 * The eight criteria, in report order. Keys match the AI response schema.
	 */
	public static function criteria() {
		return array(
			'differentiation'         => 'Menu differentiation',
			'uniqueness'              => 'Recipe & product uniqueness',
			'pricing'                 => 'Pricing opportunities',
			'structure'               => 'Menu structure',
			'high_margin_potential'   => 'High-margin potential',
			'customer_appeal'         => 'Customer appeal',
			'competitive_positioning' => 'Competitive positioning',
			'improvement_opportunity' => 'Room for improvement',
		);
	}
}
