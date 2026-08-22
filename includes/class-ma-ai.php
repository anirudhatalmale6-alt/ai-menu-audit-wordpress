<?php
/**
 * Talks to the AI provider and returns a validated report array.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MA_AI {

	/**
	 * Run the audit.
	 *
	 * @return array|WP_Error
	 */
	public static function audit( $menu_text, $business = '', $image = null ) {
		$settings = MA_Settings::all();

		if ( empty( $settings['api_key'] ) ) {
			return new WP_Error( 'ma_no_key', 'No API key has been saved in the Menu Audit settings.' );
		}

		$menu_text = self::trim_menu( $menu_text );

		if ( ! $image && strlen( trim( $menu_text ) ) < 40 ) {
			return new WP_Error( 'ma_short_menu', 'The menu text is too short to audit.' );
		}

		$system = self::build_system_prompt( $business );

		$user = $image
			? "The menu is attached as a file. Read every item and price off it, then audit it."
			: "Here is the menu to audit.\n\n<menu>\n" . $menu_text . "\n</menu>";

		if ( $image && '' !== trim( $menu_text ) ) {
			$user .= "\n\nThe owner also typed this:\n\n<notes>\n" . $menu_text . "\n</notes>";
		}

		$raw = ( 'openai' === $settings['provider'] )
			? self::call_openai( $settings, $system, $user, $image )
			: self::call_anthropic( $settings, $system, $user, $image );

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		return self::normalise( $raw );
	}

	private static function build_system_prompt( $business ) {
		$settings = MA_Settings::all();

		$context = trim( (string) $settings['business_context'] );
		if ( '' !== $context ) {
			$context = "Additional context about the business and its market:\n" . $context;
		}

		$prompt = strtr(
			$settings['prompt'],
			array(
				'{business}' => $business ? $business : 'this business',
				'{context}'  => $context,
			)
		);

		// {menu} is supported in the template for people who prefer it inline, but the
		// menu is normally sent as the user turn so the system prompt stays cacheable.
		return str_replace( '{menu}', '', $prompt );
	}

	/**
	 * Anthropic Messages API. Structured outputs guarantee the response parses.
	 */
	private static function call_anthropic( $settings, $system, $user, $image = null ) {
		$model = $settings['model'] ? $settings['model'] : 'claude-opus-5';

		$content = array();

		if ( $image ) {
			$content[] = ( 'application/pdf' === $image['media_type'] )
				? array(
					'type'   => 'document',
					'source' => array(
						'type'       => 'base64',
						'media_type' => 'application/pdf',
						'data'       => $image['data'],
					),
				)
				: array(
					'type'   => 'image',
					'source' => array(
						'type'       => 'base64',
						'media_type' => $image['media_type'],
						'data'       => $image['data'],
					),
				);
		}

		$content[] = array( 'type' => 'text', 'text' => $user );

		$body = array(
			'model'      => $model,
			'max_tokens' => 8000,
			'system'     => $system,
			'messages'   => array(
				array( 'role' => 'user', 'content' => $content ),
			),
			'output_config' => array(
				'format' => array(
					'type'   => 'json_schema',
					'schema' => self::schema(),
				),
			),
		);

		// Haiku 4.5 rejects the effort parameter; only the 5-series accepts it.
		if ( in_array( $model, array( 'claude-opus-5', 'claude-sonnet-5' ), true ) ) {
			$body['output_config']['effort'] = $settings['effort'] ? $settings['effort'] : 'medium';
		}

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 180,
				'headers' => array(
					'content-type'      => 'application/json',
					'x-api-key'         => $settings['api_key'],
					'anthropic-version' => '2023-06-01',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$data = self::unwrap( $response, 'Anthropic' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( isset( $data['stop_reason'] ) && 'refusal' === $data['stop_reason'] ) {
			return new WP_Error( 'ma_refusal', 'The AI declined to analyse this submission.' );
		}

		// With a json_schema format the first text block is the JSON document.
		$text = '';
		foreach ( (array) $data['content'] as $block ) {
			if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
				$text = $block['text'];
				break;
			}
		}

		return self::decode( $text );
	}

	private static function call_openai( $settings, $system, $user, $image = null ) {
		if ( $image && 'application/pdf' === $image['media_type'] ) {
			return new WP_Error( 'ma_pdf_scan', 'That PDF has no readable text layer. Please paste the menu in, upload a photo of it, or switch the plugin to Claude, which can read scanned PDFs.' );
		}

		$content = array( array( 'type' => 'text', 'text' => $user ) );

		if ( $image ) {
			$content[] = array(
				'type'      => 'image_url',
				'image_url' => array(
					'url' => 'data:' . $image['media_type'] . ';base64,' . $image['data'],
				),
			);
		}

		$body = array(
			'model'    => $settings['model'] ? $settings['model'] : 'gpt-4.1',
			'messages' => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $content ),
			),
			'response_format' => array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => 'menu_audit',
					'strict' => true,
					'schema' => self::schema(),
				),
			),
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 180,
				'headers' => array(
					'content-type'  => 'application/json',
					'authorization' => 'Bearer ' . $settings['api_key'],
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$data = self::unwrap( $response, 'OpenAI' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$text = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';

		return self::decode( $text );
	}

	/**
	 * Shared HTTP error handling for both providers.
	 */
	private static function unwrap( $response, $label ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ma_http', $label . ' request failed: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 200 !== $code ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : substr( $body, 0, 300 );
			return new WP_Error( 'ma_api_' . $code, $label . ' returned ' . $code . ': ' . $message );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ma_bad_json', $label . ' returned a response that could not be read.' );
		}

		return $data;
	}

	private static function decode( $text ) {
		$parsed = json_decode( trim( (string) $text ), true );

		if ( ! is_array( $parsed ) ) {
			// Defensive: strip a stray code fence if a future model wraps the JSON.
			$stripped = preg_replace( '/^```(?:json)?|```$/m', '', trim( (string) $text ) );
			$parsed   = json_decode( trim( (string) $stripped ), true );
		}

		if ( ! is_array( $parsed ) ) {
			return new WP_Error( 'ma_parse', 'The AI response could not be parsed as a report.' );
		}

		return $parsed;
	}

	/**
	 * Force the response into the shape the report template expects, so a partial
	 * or unexpected payload degrades instead of fatalling.
	 */
	private static function normalise( $raw ) {
		$report = array(
			'headline'        => isset( $raw['headline'] ) ? sanitize_text_field( $raw['headline'] ) : '',
			'summary'         => isset( $raw['summary'] ) ? wp_kses_post( $raw['summary'] ) : '',
			'overall_score'   => isset( $raw['overall_score'] ) ? self::clamp( $raw['overall_score'] ) : 0,
			'scores'          => array(),
			'recommendations' => array(),
		);

		foreach ( MA_Settings::criteria() as $key => $label ) {
			$score   = isset( $raw['scores'][ $key ]['score'] ) ? self::clamp( $raw['scores'][ $key ]['score'] ) : 0;
			$comment = isset( $raw['scores'][ $key ]['comment'] ) ? wp_kses_post( $raw['scores'][ $key ]['comment'] ) : '';

			$report['scores'][ $key ] = array(
				'label'   => $label,
				'score'   => $score,
				'comment' => $comment,
			);
		}

		if ( ! $report['overall_score'] ) {
			$scores = wp_list_pluck( $report['scores'], 'score' );
			$scores = array_filter( $scores );
			$report['overall_score'] = $scores ? (int) round( array_sum( $scores ) / count( $scores ) ) : 0;
		}

		foreach ( (array) ( isset( $raw['recommendations'] ) ? $raw['recommendations'] : array() ) as $rec ) {
			if ( empty( $rec['title'] ) ) {
				continue;
			}
			$report['recommendations'][] = array(
				'title'  => sanitize_text_field( $rec['title'] ),
				'action' => isset( $rec['action'] ) ? wp_kses_post( $rec['action'] ) : '',
				'why'    => isset( $rec['why'] ) ? wp_kses_post( $rec['why'] ) : '',
			);
		}

		if ( ! $report['recommendations'] ) {
			return new WP_Error( 'ma_empty', 'The AI returned a report with no recommendations.' );
		}

		return $report;
	}

	private static function clamp( $value ) {
		$value = (int) round( (float) $value );
		return max( 0, min( 10, $value ) );
	}

	/**
	 * Very long menus are trimmed so a paste-bomb can't run up the API bill.
	 */
	private static function trim_menu( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		$limit = 60000;

		if ( strlen( $text ) > $limit ) {
			$text = substr( $text, 0, $limit ) . "\n\n[menu truncated]";
		}

		return $text;
	}

	/**
	 * JSON Schema for the report. Numeric min/max are not supported by structured
	 * outputs, so the 1-10 range is stated in the prompt and clamped on the way in.
	 */
	private static function schema() {
		$score_block = array(
			'type'       => 'object',
			'properties' => array(
				'score'   => array(
					'type'        => 'integer',
					'description' => 'A whole number from 1 to 10.',
				),
				'comment' => array(
					'type'        => 'string',
					'description' => 'Two or three sentences justifying the score, referring to specific items on the menu.',
				),
			),
			'required'             => array( 'score', 'comment' ),
			'additionalProperties' => false,
		);

		$scores = array();
		foreach ( array_keys( MA_Settings::criteria() ) as $key ) {
			$scores[ $key ] = $score_block;
		}

		return array(
			'type'       => 'object',
			'properties' => array(
				'overall_score' => array(
					'type'        => 'integer',
					'description' => 'Overall menu score from 1 to 10.',
				),
				'headline'      => array(
					'type'        => 'string',
					'description' => 'One short sentence summarising the single biggest opportunity on this menu.',
				),
				'summary'       => array(
					'type'        => 'string',
					'description' => 'A short paragraph, 60 to 100 words, addressed directly to the owner.',
				),
				'scores'        => array(
					'type'                 => 'object',
					'properties'           => $scores,
					'required'             => array_keys( $scores ),
					'additionalProperties' => false,
				),
				'recommendations' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'title'  => array(
								'type'        => 'string',
								'description' => 'A short action title, under eight words.',
							),
							'action' => array(
								'type'        => 'string',
								'description' => 'Exactly what to do, naming the affected menu items.',
							),
							'why'    => array(
								'type'        => 'string',
								'description' => 'One or two sentences on the expected benefit.',
							),
						),
						'required'             => array( 'title', 'action', 'why' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'overall_score', 'headline', 'summary', 'scores', 'recommendations' ),
			'additionalProperties' => false,
		);
	}
}
