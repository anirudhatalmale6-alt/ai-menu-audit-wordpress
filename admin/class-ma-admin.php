<?php
/**
 * Admin screens: leads list, single lead, settings, CSV export.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MA_Admin {

	const CAP = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
	}

	public static function menu() {
		add_menu_page(
			'Menu Audit',
			'Menu Audit',
			self::CAP,
			'menu-audit-leads',
			array( __CLASS__, 'leads_page' ),
			'dashicons-food',
			26
		);

		add_submenu_page( 'menu-audit-leads', 'Leads', 'Leads', self::CAP, 'menu-audit-leads', array( __CLASS__, 'leads_page' ) );
		add_submenu_page( 'menu-audit-leads', 'Settings', 'Settings', self::CAP, 'menu-audit-settings', array( __CLASS__, 'settings_page' ) );
	}

	/* ---------------------------------------------------------------- actions */

	public static function handle_actions() {
		if ( ! is_admin() || ! current_user_can( self::CAP ) ) {
			return;
		}

		if ( isset( $_POST['ma_save_settings'] ) ) {
			self::save_settings();
		}

		if ( isset( $_GET['ma_export'] ) ) {
			self::export_csv();
		}

		if ( isset( $_GET['ma_delete'] ) ) {
			check_admin_referer( 'ma_delete_lead' );
			MA_DB::delete( (int) $_GET['ma_delete'] );
			wp_safe_redirect( admin_url( 'admin.php?page=menu-audit-leads&deleted=1' ) );
			exit;
		}

		if ( isset( $_GET['ma_retry'] ) ) {
			check_admin_referer( 'ma_retry_lead' );
			$lead = MA_DB::get( (int) $_GET['ma_retry'] );
			if ( $lead ) {
				MA_DB::update( $lead->id, array( 'status' => 'pending', 'error_message' => '' ) );
				MA_Form::process( $lead->token );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=menu-audit-leads&lead=' . (int) $_GET['ma_retry'] ) );
			exit;
		}
	}

	private static function save_settings() {
		check_admin_referer( 'ma_settings' );

		$posted   = wp_unslash( $_POST );
		$existing = MA_Settings::all();

		$values = array(
			'provider'         => in_array( $posted['provider'], array( 'anthropic', 'openai' ), true ) ? $posted['provider'] : 'anthropic',
			'api_key'          => trim( $posted['api_key'] ) !== '' ? trim( $posted['api_key'] ) : $existing['api_key'],
			'model'            => sanitize_text_field( $posted['model'] ),
			'effort'           => in_array( $posted['effort'], array( 'low', 'medium', 'high' ), true ) ? $posted['effort'] : 'medium',
			'prompt'           => trim( $posted['prompt'] ),
			'business_context' => sanitize_textarea_field( $posted['business_context'] ),
			'from_name'        => sanitize_text_field( $posted['from_name'] ),
			'from_email'       => sanitize_email( $posted['from_email'] ),
			'email_subject'    => sanitize_text_field( $posted['email_subject'] ),
			'email_intro'      => sanitize_textarea_field( $posted['email_intro'] ),
			'notify_email'     => sanitize_email( $posted['notify_email'] ),
			'notify_enabled'   => empty( $posted['notify_enabled'] ) ? 0 : 1,
			'accent_colour'    => sanitize_hex_color( $posted['accent_colour'] ) ? sanitize_hex_color( $posted['accent_colour'] ) : '#c0392b',
			'rate_limit'       => max( 0, (int) $posted['rate_limit'] ),
			'require_consent'  => empty( $posted['require_consent'] ) ? 0 : 1,
			'consent_text'     => sanitize_text_field( $posted['consent_text'] ),
			'redirect_url'     => esc_url_raw( $posted['redirect_url'] ),
			'webhook_url'      => esc_url_raw( $posted['webhook_url'] ),
		);

		if ( ! empty( $posted['reset_prompt'] ) ) {
			$values['prompt'] = MA_Settings::default_prompt();
		}

		MA_Settings::save( $values );

		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
			}
		);
	}

	private static function export_csv() {
		check_admin_referer( 'ma_export' );

		global $wpdb;
		$table = MA_DB::table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" ); // phpcs:ignore

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=menu-audit-leads-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );

		fputcsv( $out, array( 'Date', 'Name', 'Business', 'Email', 'Phone', 'Website', 'Score', 'Status', 'Emailed', 'Report URL' ) );

		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$row->created_at,
					$row->name,
					$row->business,
					$row->email,
					$row->phone,
					$row->website,
					$row->overall_score,
					$row->status,
					$row->email_sent ? 'yes' : 'no',
					MA_Report::url( $row->token ),
				)
			);
		}

		fclose( $out );
		exit;
	}

	/* ------------------------------------------------------------------ views */

	public static function leads_page() {
		if ( isset( $_GET['lead'] ) ) {
			self::single_lead( (int) $_GET['lead'] );
			return;
		}

		global $wpdb;
		$table = MA_DB::table();

		$per_page = 25;
		$paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$offset   = ( $paged - 1 ) * $per_page;
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		if ( $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$where = $wpdb->prepare( 'WHERE name LIKE %s OR business LIKE %s OR email LIKE %s', $like, $like, $like );
		} else {
			$where = '';
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" ); // phpcs:ignore
		$leads = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ) ); // phpcs:ignore
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Menu Audit Leads</h1>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=menu-audit-leads&ma_export=1' ), 'ma_export' ) ); ?>" class="page-title-action">Export CSV</a>
			<hr class="wp-header-end">

			<form method="get">
				<input type="hidden" name="page" value="menu-audit-leads">
				<p class="search-box">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search leads">
					<button class="button">Search</button>
				</p>
			</form>

			<p><?php echo esc_html( number_format_i18n( $total ) ); ?> submission<?php echo 1 === $total ? '' : 's'; ?>.</p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:150px;">Date</th>
						<th>Business</th>
						<th>Contact</th>
						<th style="width:70px;">Score</th>
						<th style="width:110px;">Status</th>
						<th style="width:150px;"></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $leads ) : ?>
						<tr><td colspan="6">No submissions yet.</td></tr>
					<?php endif; ?>

					<?php foreach ( $leads as $lead ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( 'j M Y, H:i', strtotime( $lead->created_at ) ) ); ?></td>
							<td>
								<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=menu-audit-leads&lead=' . $lead->id ) ); ?>">
									<?php echo esc_html( $lead->business ? $lead->business : '(no business name)' ); ?>
								</a></strong>
							</td>
							<td>
								<?php echo esc_html( $lead->name ); ?><br>
								<a href="mailto:<?php echo esc_attr( $lead->email ); ?>"><?php echo esc_html( $lead->email ); ?></a>
								<?php if ( $lead->phone ) : ?><br><?php echo esc_html( $lead->phone ); ?><?php endif; ?>
							</td>
							<td><?php echo $lead->overall_score ? esc_html( $lead->overall_score . '/10' ) : '&mdash;'; ?></td>
							<td><?php echo wp_kses_post( self::status_badge( $lead ) ); ?></td>
							<td>
								<?php if ( 'complete' === $lead->status ) : ?>
									<a href="<?php echo esc_url( MA_Report::url( $lead->token ) ); ?>" target="_blank" rel="noopener">Report</a> |
								<?php endif; ?>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=menu-audit-leads&ma_delete=' . $lead->id ), 'ma_delete_lead' ) ); ?>"
									onclick="return confirm('Delete this lead permanently?');" style="color:#b32d2e;">Delete</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$pages = (int) ceil( $total / $per_page );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	private static function status_badge( $lead ) {
		$map = array(
			'complete'   => array( 'Complete', '#2e9e5b' ),
			'processing' => array( 'Processing', '#d08700' ),
			'pending'    => array( 'Queued', '#6e6e73' ),
			'failed'     => array( 'Failed', '#b32d2e' ),
		);

		$badge = isset( $map[ $lead->status ] ) ? $map[ $lead->status ] : array( $lead->status, '#6e6e73' );

		return sprintf(
			'<span style="display:inline-block;padding:2px 9px;border-radius:99px;font-size:12px;font-weight:600;color:#fff;background:%s;">%s</span>',
			esc_attr( $badge[1] ),
			esc_html( $badge[0] )
		);
	}

	private static function single_lead( $id ) {
		$lead = MA_DB::get( $id );

		if ( ! $lead ) {
			echo '<div class="wrap"><h1>Lead not found</h1></div>';
			return;
		}

		$report = json_decode( $lead->report, true );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $lead->business ? $lead->business : $lead->name ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=menu-audit-leads' ) ); ?>" class="page-title-action">Back to leads</a>
			<?php if ( 'complete' !== $lead->status ) : ?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=menu-audit-leads&ma_retry=' . $lead->id ), 'ma_retry_lead' ) ); ?>" class="page-title-action">Run again</a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php if ( $lead->error_message ) : ?>
				<div class="notice notice-error"><p><strong>Last error:</strong> <?php echo esc_html( $lead->error_message ); ?></p></div>
			<?php endif; ?>

			<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">
				<div style="flex:1 1 320px;min-width:300px;">
					<div class="postbox"><div class="inside">
						<h2>Contact</h2>
						<table class="widefat striped">
							<tr><td>Name</td><td><strong><?php echo esc_html( $lead->name ); ?></strong></td></tr>
							<tr><td>Business</td><td><?php echo esc_html( $lead->business ); ?></td></tr>
							<tr><td>Email</td><td><a href="mailto:<?php echo esc_attr( $lead->email ); ?>"><?php echo esc_html( $lead->email ); ?></a></td></tr>
							<tr><td>Phone</td><td><?php echo esc_html( $lead->phone ); ?></td></tr>
							<tr><td>Website</td><td><?php echo esc_html( $lead->website ); ?></td></tr>
							<tr><td>Submitted</td><td><?php echo esc_html( date_i18n( 'j M Y, H:i', strtotime( $lead->created_at ) ) ); ?></td></tr>
							<tr><td>Status</td><td><?php echo wp_kses_post( self::status_badge( $lead ) ); ?></td></tr>
							<tr><td>Report emailed</td><td><?php echo $lead->email_sent ? 'Yes' : 'No'; ?></td></tr>
							<?php if ( $lead->source_file ) : ?>
								<tr><td>Uploaded file</td><td><?php echo esc_html( $lead->source_file ); ?></td></tr>
							<?php endif; ?>
							<?php if ( 'complete' === $lead->status ) : ?>
								<tr><td>Report link</td><td><a href="<?php echo esc_url( MA_Report::url( $lead->token ) ); ?>" target="_blank" rel="noopener">Open</a></td></tr>
							<?php endif; ?>
						</table>
					</div></div>

					<div class="postbox"><div class="inside">
						<h2>Menu they submitted</h2>
						<pre style="white-space:pre-wrap;max-height:420px;overflow:auto;background:#f6f7f7;padding:12px;border-radius:6px;"><?php echo esc_html( $lead->menu_text ); ?></pre>
					</div></div>
				</div>

				<div style="flex:1 1 420px;min-width:360px;">
					<div class="postbox"><div class="inside">
						<h2>Report</h2>
						<?php if ( is_array( $report ) ) : ?>
							<?php echo MA_Report::render( $report, $lead ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php else : ?>
							<p>No report yet.</p>
						<?php endif; ?>
					</div></div>
				</div>
			</div>
		</div>
		<?php
	}

	public static function settings_page() {
		$s      = MA_Settings::all();
		$models = MA_Settings::models( $s['provider'] );
		?>
		<div class="wrap">
			<h1>Menu Audit Settings</h1>

			<p>
				Add <code>[menu_audit]</code> to any page or Elementor widget to show the form.
				Optional attributes: <code>title</code>, <code>subtitle</code>, <code>button</code>.
			</p>

			<form method="post">
				<?php wp_nonce_field( 'ma_settings' ); ?>

				<h2>AI provider</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="provider">Provider</label></th>
						<td>
							<select name="provider" id="provider">
								<option value="anthropic" <?php selected( $s['provider'], 'anthropic' ); ?>>Anthropic (Claude)</option>
								<option value="openai" <?php selected( $s['provider'], 'openai' ); ?>>OpenAI</option>
							</select>
							<p class="description">Save after switching to reload the model list.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="api_key">API key</label></th>
						<td>
							<input type="password" name="api_key" id="api_key" class="regular-text" autocomplete="off"
								placeholder="<?php echo $s['api_key'] ? 'Saved — leave blank to keep it' : 'Paste your key'; ?>">
							<p class="description">Stored in your own database. Leave blank to keep the existing key.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="model">Model</label></th>
						<td>
							<select name="model" id="model">
								<?php foreach ( $models as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="effort">Depth</label></th>
						<td>
							<select name="effort" id="effort">
								<option value="low" <?php selected( $s['effort'], 'low' ); ?>>Low — fastest, cheapest</option>
								<option value="medium" <?php selected( $s['effort'], 'medium' ); ?>>Medium — recommended</option>
								<option value="high" <?php selected( $s['effort'], 'high' ); ?>>High — most thorough, slower</option>
							</select>
							<p class="description">Claude models only. Higher depth costs more per audit.</p>
						</td>
					</tr>
				</table>

				<h2>Audit criteria</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="prompt">Prompt</label></th>
						<td>
							<textarea name="prompt" id="prompt" rows="22" class="large-text code"><?php echo esc_textarea( $s['prompt'] ); ?></textarea>
							<p class="description">
								Placeholders: <code>{business}</code>, <code>{context}</code>.
								<label style="margin-left:12px;"><input type="checkbox" name="reset_prompt" value="1"> Reset to the default prompt on save</label>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="business_context">Your market context</label></th>
						<td>
							<textarea name="business_context" id="business_context" rows="4" class="large-text"><?php echo esc_textarea( $s['business_context'] ); ?></textarea>
							<p class="description">Optional. Anything the AI should know about your niche, region or the kind of client you work with.</p>
						</td>
					</tr>
				</table>

				<h2>Report email</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="from_name">From name</label></th>
						<td><input type="text" name="from_name" id="from_name" class="regular-text" value="<?php echo esc_attr( $s['from_name'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="from_email">From address</label></th>
						<td>
							<input type="email" name="from_email" id="from_email" class="regular-text" value="<?php echo esc_attr( $s['from_email'] ); ?>">
							<p class="description">Use an address on your own domain, and set up SMTP, or reports will land in spam.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="email_subject">Subject</label></th>
						<td><input type="text" name="email_subject" id="email_subject" class="large-text" value="<?php echo esc_attr( $s['email_subject'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="email_intro">Intro text</label></th>
						<td>
							<textarea name="email_intro" id="email_intro" rows="6" class="large-text"><?php echo esc_textarea( $s['email_intro'] ); ?></textarea>
							<p class="description">Placeholders: <code>{name}</code>, <code>{business}</code>, <code>{email}</code>, <code>{site}</code>.</p>
						</td>
					</tr>
				</table>

				<h2>Leads</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">New lead alerts</th>
						<td>
							<label><input type="checkbox" name="notify_enabled" value="1" <?php checked( $s['notify_enabled'], 1 ); ?>> Email me when someone submits a menu</label><br><br>
							<input type="email" name="notify_email" class="regular-text" value="<?php echo esc_attr( $s['notify_email'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="webhook_url">Webhook URL</label></th>
						<td>
							<input type="url" name="webhook_url" id="webhook_url" class="large-text" value="<?php echo esc_attr( $s['webhook_url'] ); ?>">
							<p class="description">Optional. Each lead is POSTed here as JSON — useful for Zapier, Make, Mailchimp or a CRM.</p>
						</td>
					</tr>
				</table>

				<h2>Form</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="accent_colour">Accent colour</label></th>
						<td><input type="text" name="accent_colour" id="accent_colour" class="regular-text" value="<?php echo esc_attr( $s['accent_colour'] ); ?>" placeholder="#c0392b"></td>
					</tr>
					<tr>
						<th scope="row"><label for="rate_limit">Submissions per hour</label></th>
						<td>
							<input type="number" name="rate_limit" id="rate_limit" min="0" value="<?php echo esc_attr( $s['rate_limit'] ); ?>" class="small-text">
							<p class="description">Per IP address. 0 disables the limit.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Consent checkbox</th>
						<td>
							<label><input type="checkbox" name="require_consent" value="1" <?php checked( $s['require_consent'], 1 ); ?>> Require a consent tick before submitting</label><br><br>
							<input type="text" name="consent_text" class="large-text" value="<?php echo esc_attr( $s['consent_text'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="redirect_url">Redirect after submit</label></th>
						<td>
							<input type="url" name="redirect_url" id="redirect_url" class="large-text" value="<?php echo esc_attr( $s['redirect_url'] ); ?>">
							<p class="description">Leave blank to show the report on the same page — recommended, it converts better.</p>
						</td>
					</tr>
				</table>

				<p class="submit"><button type="submit" name="ma_save_settings" value="1" class="button button-primary">Save settings</button></p>
			</form>
		</div>
		<?php
	}
}
