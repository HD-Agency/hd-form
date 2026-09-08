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

defined( 'ABSPATH' ) || exit;

final class GeneralSection {

	/**
	 * Render the General settings tab.
	 *
	 * @param array  $options Current saved options.
	 * @param string $optKey  Option key for form field names.
	 */
	public static function renderTab( array $options, string $optKey ): void {
		$emails       = implode( "\n", $options['default_email_to'] ?? [] );
		$denyDomains  = implode( "\n", $options['email_filter']['deny_domains'] ?? [] );
		$allowDomains = implode( "\n", $options['email_filter']['allow_domains'] ?? [] );

		?>
		<div class="hd-form-tab-content active" id="hd-tab-general">
			<table class="form-table">
				<tr>
					<th scope="row"><label for="hd-default-emails"><?php esc_html_e( 'Default Email Recipients', 'hd-form' ); ?></label></th>
					<td>
						<textarea name="<?php echo esc_attr( $optKey ); ?>[default_email_to]" id="hd-default-emails" rows="3" cols="50" class="large-text code" placeholder="admin@example.com&#10;sales@example.com"><?php echo esc_textarea( $emails ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Fallback recipients when a form type has none configured. One email per line.', 'hd-form' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Email Domain Filter', 'hd-form' ); ?></h2>
			<p><?php esc_html_e( 'Control which email domains are allowed or blocked. One domain per line.', 'hd-form' ); ?></p>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Deny Domains', 'hd-form' ); ?></th>
					<td>
						<textarea name="<?php echo esc_attr( $optKey ); ?>[email_deny_domains]" rows="4" cols="50" class="large-text code" placeholder="mailinator.com&#10;guerrillamail.com"><?php echo esc_textarea( $denyDomains ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Submissions from these email domains will be rejected.', 'hd-form' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Allow Domains', 'hd-form' ); ?></th>
					<td>
						<textarea name="<?php echo esc_attr( $optKey ); ?>[email_allow_domains]" rows="4" cols="50" class="large-text code" placeholder="company.com&#10;partner.com"><?php echo esc_textarea( $allowDomains ); ?></textarea>
						<p class="description"><?php esc_html_e( 'If set, ONLY these domains are accepted. Leave empty to allow all (except deny list).', 'hd-form' ); ?></p>
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

		// Default email recipients.
		$clean['default_email_to'] = self::parseLines( $input['default_email_to'] ?? '' );
		$clean['default_email_to'] = array_filter( $clean['default_email_to'], 'is_email' );

		// Email domain filter.
		$clean['email_filter'] = [
			'deny_domains'  => self::sanitizeDomainList( $input['email_deny_domains'] ?? '' ),
			'allow_domains' => self::sanitizeDomainList( $input['email_allow_domains'] ?? '' ),
		];

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
	 * Sanitize a single domain string.
	 */
	public static function sanitizeDomain( string $domain ): string {
		$domain = strtolower( trim( $domain ) );
		$domain = ltrim( $domain, '@' );

		if ( str_contains( $domain, '://' ) ) {
			$host   = wp_parse_url( $domain, PHP_URL_HOST );
			$domain = is_string( $host ) ? $host : '';
		}

		$domain = preg_replace( '/:\d+$/', '', $domain );
		$domain = trim( (string) $domain, ". \t\n\r\0\x0B" );

		if ( '' === $domain || strlen( $domain ) > 253 || str_contains( $domain, '..' ) ) {
			return '';
		}

		return (bool) preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])$/', $domain )
			? $domain
			: '';
	}
}
