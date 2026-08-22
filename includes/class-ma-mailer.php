<?php
/**
 * Sends the report to the visitor and the alert to the site owner.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MA_Mailer {

	public static function send_report( $lead, $report ) {
		$settings = MA_Settings::all();

		$subject = self::tokens( $settings['email_subject'], $lead );
		$intro   = self::tokens( $settings['email_intro'], $lead );

		$body  = '<div style="background:#f5f5f7;padding:28px 12px;">';
		$body .= '<div style="max-width:680px;margin:0 auto;background:#fff;border-radius:14px;padding:28px 24px;">';

		foreach ( preg_split( '/\n{2,}/', trim( $intro ) ) as $para ) {
			$body .= '<p style="font:16px/1.6 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1d1d1f;margin:0 0 14px;">'
				. nl2br( esc_html( $para ) ) . '</p>';
		}

		$body .= '<hr style="border:0;border-top:1px solid #ececf0;margin:26px 0;">';
		$body .= MA_Report::render( $report, $lead, true );

		$body .= '<p style="text-align:center;margin:32px 0 0;">'
			. '<a href="' . esc_url( MA_Report::url( $lead->token ) ) . '" '
			. 'style="display:inline-block;background:' . esc_attr( $settings['accent_colour'] ) . ';color:#fff;'
			. 'text-decoration:none;font:600 15px -apple-system,BlinkMacSystemFont,sans-serif;padding:13px 26px;border-radius:8px;">'
			. 'View or download this report</a></p>';

		$body .= '</div>';
		$body .= '<p style="text-align:center;font:12px/1.6 -apple-system,sans-serif;color:#8a8a8f;margin:18px 0 0;">'
			. esc_html( get_bloginfo( 'name' ) ) . '</p>';
		$body .= '</div>';

		$sent = wp_mail( $lead->email, $subject, $body, self::headers() );

		if ( $sent ) {
			MA_DB::update( $lead->id, array( 'email_sent' => 1 ) );
		}

		return $sent;
	}

	public static function notify_owner( $lead, $report ) {
		$settings = MA_Settings::all();

		if ( empty( $settings['notify_enabled'] ) || empty( $settings['notify_email'] ) ) {
			return false;
		}

		$subject = sprintf( 'New menu audit lead: %s (%d/10)', $lead->business ? $lead->business : $lead->name, (int) $report['overall_score'] );

		$rows = array(
			'Name'     => $lead->name,
			'Business' => $lead->business,
			'Email'    => $lead->email,
			'Phone'    => $lead->phone,
			'Website'  => $lead->website,
			'Score'    => $report['overall_score'] . '/10',
		);

		$body = '<div style="font:15px/1.6 -apple-system,BlinkMacSystemFont,sans-serif;color:#1d1d1f;">';
		$body .= '<p><strong>New menu audit submitted.</strong></p><table cellpadding="6" cellspacing="0">';

		foreach ( $rows as $label => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$body .= '<tr><td style="color:#6e6e73;">' . esc_html( $label ) . '</td><td><strong>' . esc_html( $value ) . '</strong></td></tr>';
		}

		$body .= '</table>';
		$body .= '<p><a href="' . esc_url( MA_Report::url( $lead->token ) ) . '">View their report</a> &middot; '
			. '<a href="' . esc_url( admin_url( 'admin.php?page=menu-audit-leads&lead=' . $lead->id ) ) . '">Open in dashboard</a></p>';
		$body .= '</div>';

		return wp_mail( $settings['notify_email'], $subject, $body, self::headers() );
	}

	/**
	 * Optional POST of the lead to a CRM / Zapier / Make endpoint.
	 */
	public static function push_webhook( $lead, $report ) {
		$url = MA_Settings::get( 'webhook_url' );

		if ( ! $url ) {
			return;
		}

		wp_remote_post(
			$url,
			array(
				'timeout'  => 15,
				'blocking' => false,
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode(
					array(
						'name'          => $lead->name,
						'business'      => $lead->business,
						'email'         => $lead->email,
						'phone'         => $lead->phone,
						'website'       => $lead->website,
						'overall_score' => $report['overall_score'],
						'report_url'    => MA_Report::url( $lead->token ),
						'submitted_at'  => $lead->created_at,
					)
				),
			)
		);
	}

	private static function headers() {
		$settings = MA_Settings::all();

		$from = $settings['from_email'] ? $settings['from_email'] : get_option( 'admin_email' );
		$name = $settings['from_name'] ? $settings['from_name'] : get_bloginfo( 'name' );

		return array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $name, $from ),
		);
	}

	private static function tokens( $string, $lead ) {
		return strtr(
			(string) $string,
			array(
				'{name}'     => $lead->name,
				'{business}' => $lead->business ? $lead->business : $lead->name,
				'{email}'    => $lead->email,
				'{site}'     => get_bloginfo( 'name' ),
			)
		);
	}
}
