<?php
/**
 * Form Settings — General Section.
 *
 * Renders and sanitizes the General tab: default email recipients
 * and email domain allow/deny filters.
 *
 * @package HDForm\Admin\Settings
 */

declare(strict_types=1);

namespace HDForm\Admin\Settings;

use HDForm\FormConfig;

defined( 'ABSPATH' ) || exit;

final class GeneralSection {

	/**
	 * Render the General settings tab.
	 *
	 * @param array  $options Current saved options.
	 * @param string $optKey  Option key for form field names.
	 */
	public static function renderTab( array $options, string $optKey ): void {
		$emails       = implode( "\n", is_array( $options['default_email_to'] ?? null ) ? $options['default_email_to'] : [] );
		$emailsCc     = implode( "\n", is_array( $options['default_email_cc'] ?? null ) ? $options['default_email_cc'] : [] );
		$emailsBcc    = implode( "\n", is_array( $options['default_email_bcc'] ?? null ) ? $options['default_email_bcc'] : [] );
		$denyDomains  = implode( "\n", is_array( $options['email_filter']['deny_domains'] ?? null ) ? $options['email_filter']['deny_domains'] : [] );
		$allowDomains = implode( "\n", is_array( $options['email_filter']['allow_domains'] ?? null ) ? $options['email_filter']['allow_domains'] : [] );
		$uploads      = is_array( $options['uploads'] ?? null ) ? $options['uploads'] : [];
		$config       = FormConfig::all();
		$formTypes    = is_array( $config['form_types'] ?? null ) ? $config['form_types'] : [];
		$savedTypes   = is_array( $options['form_types'] ?? null ) ? $options['form_types'] : [];

		?>
		<div class="hd-form-tab-content active" id="hd-tab-general">
			<table class="form-table">
				<tr>
					<th scope="row"><label for="hd-default-emails"><?php esc_html_e( 'Default Email Recipients (To)', 'hd-form' ); ?></label></th>
					<td>
						<textarea name="<?php echo esc_attr( $optKey ); ?>[default_email_to]" id="hd-default-emails" rows="3" cols="50" class="large-text code" placeholder="admin@example.com&#10;sales@example.com"><?php echo esc_textarea( $emails ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Fallback To recipients when a form type has none configured. One email per line.', 'hd-form' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hd-default-emails-cc"><?php esc_html_e( 'Default CC Recipients', 'hd-form' ); ?></label></th>
					<td>
						<textarea name="<?php echo esc_attr( $optKey ); ?>[default_email_cc]" id="hd-default-emails-cc" rows="2" cols="50" class="large-text code" placeholder="manager@example.com"><?php echo esc_textarea( $emailsCc ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Fallback CC recipients attached to the primary notification email. One email per line.', 'hd-form' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hd-default-emails-bcc"><?php esc_html_e( 'Default BCC Recipients', 'hd-form' ); ?></label></th>
					<td>
						<textarea name="<?php echo esc_attr( $optKey ); ?>[default_email_bcc]" id="hd-default-emails-bcc" rows="2" cols="50" class="large-text code" placeholder="archive@example.com"><?php echo esc_textarea( $emailsBcc ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Fallback BCC recipients attached to the primary notification email. One email per line.', 'hd-form' ); ?></p>
					</td>
				</tr>
			</table>

			<?php if ( ! empty( $formTypes ) ) : ?>
				<h2><?php esc_html_e( 'Per-Form-Type Email Overrides', 'hd-form' ); ?></h2>
				<p><?php esc_html_e( 'Optional recipient overrides for specific form types. Leave empty to inherit Default Email Recipients.', 'hd-form' ); ?></p>
				<?php foreach ( $formTypes as $typeSlug => $typeDef ) : ?>
					<?php
					$typeSlug   = sanitize_key( (string) $typeSlug );
					$typeLabel  = is_array( $typeDef ) ? (string) ( $typeDef['label'] ?? $typeSlug ) : $typeSlug;
					$savedType  = is_array( $savedTypes[ $typeSlug ] ?? null ) ? $savedTypes[ $typeSlug ] : [];
					$typeToStr  = implode( "\n", is_array( $savedType['email_to'] ?? null ) ? $savedType['email_to'] : [] );
					$typeCcStr  = implode( "\n", is_array( $savedType['email_cc'] ?? null ) ? $savedType['email_cc'] : [] );
					$typeBccStr = implode( "\n", is_array( $savedType['email_bcc'] ?? null ) ? $savedType['email_bcc'] : [] );
					?>
					<div class="hd-channel-block">
						<h3><?php echo esc_html( sprintf( '%s (%s)', $typeLabel, $typeSlug ) ); ?></h3>
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'To Recipients', 'hd-form' ); ?></th>
								<td>
									<textarea name="<?php echo esc_attr( $optKey ); ?>[form_types][<?php echo esc_attr( $typeSlug ); ?>][email_to]" rows="2" cols="50" class="large-text code"><?php echo esc_textarea( $typeToStr ); ?></textarea>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'CC Recipients', 'hd-form' ); ?></th>
								<td>
									<textarea name="<?php echo esc_attr( $optKey ); ?>[form_types][<?php echo esc_attr( $typeSlug ); ?>][email_cc]" rows="2" cols="50" class="large-text code"><?php echo esc_textarea( $typeCcStr ); ?></textarea>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'BCC Recipients', 'hd-form' ); ?></th>
								<td>
									<textarea name="<?php echo esc_attr( $optKey ); ?>[form_types][<?php echo esc_attr( $typeSlug ); ?>][email_bcc]" rows="2" cols="50" class="large-text code"><?php echo esc_textarea( $typeBccStr ); ?></textarea>
								</td>
							</tr>
						</table>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Email Domain & Address Filter', 'hd-form' ); ?></h2>
			<p><?php esc_html_e( 'Control which email domains, exact addresses, or wildcard patterns (e.g. *@*.ru, *.xyz) are allowed or blocked. One rule per line.', 'hd-form' ); ?></p>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Deny Domains / Patterns', 'hd-form' ); ?></th>
					<td>
						<textarea name="<?php echo esc_attr( $optKey ); ?>[email_deny_domains]" rows="4" cols="50" class="large-text code" placeholder="mailinator.com&#10;*@*.ru&#10;spammer@gmail.com"><?php echo esc_textarea( $denyDomains ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Submissions matching these domains, email addresses, or wildcard patterns will be rejected.', 'hd-form' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Allow Domains / Patterns', 'hd-form' ); ?></th>
					<td>
						<textarea name="<?php echo esc_attr( $optKey ); ?>[email_allow_domains]" rows="4" cols="50" class="large-text code" placeholder="company.com&#10;*.partner.com"><?php echo esc_textarea( $allowDomains ); ?></textarea>
						<p class="description"><?php esc_html_e( 'If set, ONLY matching domains/patterns are accepted. Leave empty to allow all (except deny list).', 'hd-form' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'File Upload Settings', 'hd-form' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Randomized Filenames', 'hd-form' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $optKey ); ?>[uploads][random_filename]" value="1" <?php checked( ! empty( $uploads['random_filename'] ) ); ?> />
							<?php esc_html_e( 'Generate cryptographic random filenames for uploaded files instead of keeping sanitized original names.', 'hd-form' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Multiple File Uploads', 'hd-form' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $optKey ); ?>[uploads][allow_multiple]" value="1" <?php checked( ! empty( $uploads['allow_multiple'] ) ); ?> />
							<?php esc_html_e( 'Allow multiple files per upload field (<input type="file" multiple>).', 'hd-form' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Sanitize General section settings.
	 *
	 * @param array $input Raw input.
	 *
	 * @return array Sanitized partial.
	 */
	public static function sanitize( array $input ): array {
		$clean = [];

		// Default email recipients (To, CC, BCC).
		$clean['default_email_to']  = self::sanitizeEmailList( $input['default_email_to'] ?? '' );
		$clean['default_email_cc']  = self::sanitizeEmailList( $input['default_email_cc'] ?? '' );
		$clean['default_email_bcc'] = self::sanitizeEmailList( $input['default_email_bcc'] ?? '' );

		// Per-form-type email recipients (email_to, email_cc, email_bcc).
		if ( isset( $input['form_types'] ) && is_array( $input['form_types'] ) ) {
			$cleanFormTypes = [];
			foreach ( $input['form_types'] as $type => $typeData ) {
				$type = sanitize_key( (string) $type );
				if ( '' === $type || ! FormConfig::isRegistered( $type ) || ! is_array( $typeData ) ) {
					continue;
				}
				foreach ( [ 'email_to', 'email_cc', 'email_bcc' ] as $recipientKey ) {
					if ( array_key_exists( $recipientKey, $typeData ) ) {
						$validEmails = self::sanitizeEmailList( $typeData[ $recipientKey ] );
						if ( ! empty( $validEmails ) ) {
							$cleanFormTypes[ $type ][ $recipientKey ] = $validEmails;
						}
					}
				}
			}

			$cleanFormTypes = array_filter(
				$cleanFormTypes,
				static fn( array $cfg ): bool => ! empty( $cfg['email_to'] )
					|| ! empty( $cfg['email_cc'] )
					|| ! empty( $cfg['email_bcc'] )
			);

			if ( ! empty( $cleanFormTypes ) ) {
				$clean['form_types'] = $cleanFormTypes;
			}
		}

		// Email domain filter (supports both flat form fields and nested array).
		$rawDeny  = $input['email_deny_domains'] ?? ( $input['email_filter']['deny_domains'] ?? '' );
		$rawAllow = $input['email_allow_domains'] ?? ( $input['email_filter']['allow_domains'] ?? '' );

		$clean['email_filter'] = [
			'deny_domains'  => self::sanitizeDomainList( $rawDeny ),
			'allow_domains' => self::sanitizeDomainList( $rawAllow ),
		];

		// File upload settings.
		$rawUploads       = is_array( $input['uploads'] ?? null ) ? $input['uploads'] : [];
		$clean['uploads'] = [
			'random_filename' => ! empty( $input['upload_random_filename'] ?? ( $rawUploads['random_filename'] ?? false ) ),
			'allow_multiple'  => ! empty( $input['upload_allow_multiple'] ?? ( $rawUploads['allow_multiple'] ?? false ) ),
		];

		return $clean;
	}

	/**
	 * Parse, validate, and case-insensitively deduplicate an email list.
	 *
	 * @param mixed $raw Raw textarea string or array of emails.
	 *
	 * @return array<int, string>
	 */
	public static function sanitizeEmailList( mixed $raw ): array {
		$lines = self::parseLines( $raw );
		$seen  = [];
		$clean = [];

		foreach ( $lines as $email ) {
			if ( ! is_email( $email ) ) {
				continue;
			}
			$lower = strtolower( $email );
			if ( ! isset( $seen[ $lower ] ) ) {
				$seen[ $lower ] = true;
				$clean[]        = $email;
			}
		}

		return $clean;
	}

	/**
	 * Parse textarea lines to trimmed, non-empty array.
	 *
	 * @param mixed $text Raw textarea or saved list.
	 *
	 * @return array
	 */
	public static function parseLines( mixed $text ): array {
		if ( is_array( $text ) ) {
			$lines = array_map(
				static fn( mixed $line ): string => is_scalar( $line ) ? (string) $line : '',
				$text
			);

			return array_values( array_filter( array_map( 'trim', $lines ) ) );
		}

		$text  = is_scalar( $text ) ? (string) $text : '';
		$lines = explode( "\n", sanitize_textarea_field( $text ) );
		$lines = array_map( 'trim', $lines );

		return array_values( array_filter( $lines ) );
	}

	/**
	 * Sanitize a textarea domain list.
	 *
	 * @param mixed $text Raw textarea or saved list.
	 *
	 * @return array<int, string>
	 */
	public static function sanitizeDomainList( mixed $text ): array {
		$domains = [];

		foreach ( self::parseLines( $text ) as $line ) {
			$domain = self::sanitizeDomain( $line );
			if ( '' !== $domain ) {
				$domains[ $domain ] = $domain;
			}
		}

		return array_values( $domains );
	}

	/**
	 * Sanitize a single domain, exact email address, or wildcard email/domain pattern.
	 */
	public static function sanitizeDomain( string $domain ): string {
		$entry = strtolower( trim( $domain ) );

		if ( str_contains( $entry, '://' ) ) {
			$host  = wp_parse_url( $entry, PHP_URL_HOST );
			$entry = is_string( $host ) ? $host : '';
		}

		$entry = preg_replace( '/:\d+$/', '', $entry );
		$entry = trim( (string) $entry, ". \t\n\r\0\x0B" );

		if ( '' === $entry || strlen( $entry ) > 253 || str_contains( $entry, '..' ) ) {
			return '';
		}

		// 1. Wildcard pattern (e.g. *@*.ru, *.xyz, spammer@*.com)
		if ( str_contains( $entry, '*' ) || str_contains( $entry, '?' ) ) {
			$entry = ltrim( $entry, '@' );
			if (
				'' === $entry
				|| ! str_contains( $entry, '.' )
				|| substr_count( $entry, '@' ) > 1
				|| str_starts_with( $entry, '@' )
				|| str_ends_with( $entry, '@' )
				|| ! preg_match( '/^[a-z0-9.*_?+@-]+$/', $entry )
			) {
				return '';
			}

			return $entry;
		}

		// 2. Exact email address (e.g. spammer@gmail.com)
		if ( str_contains( $entry, '@' ) && ! str_starts_with( $entry, '@' ) ) {
			return false !== is_email( $entry ) ? $entry : '';
		}

		// 3. Standard domain hostname (e.g. mailinator.com or @mailinator.com)
		$entry = ltrim( $entry, '@.' );
		if ( '' === $entry ) {
			return '';
		}

		return (bool) preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])$/', $entry )
			? $entry
			: '';
	}
}
