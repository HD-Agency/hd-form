<?php
/**
 * Email Template Engine
 *
 * Renders HTML email templates with placeholder replacement.
 * Uses pure HTML templates — no Markdown dependency.
 *
 * @package HDForm\Notification
 */

declare(strict_types=1);

namespace HDForm\Notification;

use HDForm\Compat\Helper;
use HDForm\Compat\FileSystem;
use HDForm\Compat\Url;

defined( 'ABSPATH' ) || exit;

class TemplateEngine {

	/**
	 * Placeholder names that intentionally carry sanitized HTML.
	 *
	 * @var string[]
	 */
	private const RAW_PLACEHOLDERS = [
		'content',
		'fields_table',
		'site_logo_html',
	];

	/**
	 * Render email template.
	 *
	 * @param string $templateName Template filename without extension.
	 * @param array  $data         Variables for template.
	 *
	 * @return string Rendered HTML.
	 */
	public static function render( string $templateName, array $data ): string {
		$locale = str_replace( '_', '-', determine_locale() );

		$siteLogoId = Helper::getThemeMod( 'custom_logo' );
		$logoUrl    = Helper::attachmentImageSrc( $siteLogoId, 'full' ) ?: '';

		$data = array_merge(
			[
				'lang'                     => '' !== $locale ? $locale : 'vi',
				'site_name'                => Helper::getOption( 'blogname', '' ),
				'site_url'                 => Url::home(),
				'site_logo'                => $logoUrl,
				'year'                     => gmdate( 'Y' ),
				'label_contact_heading'    => __( 'New Contact Inquiry', 'hd-form' ),
				'label_service_heading'    => __( 'New Service Registration', 'hd-form' ),
				'label_submission_heading' => __( 'New Form Submission', 'hd-form' ),
				'label_name'               => __( 'Full Name', 'hd-form' ),
				'label_email'              => __( 'Email', 'hd-form' ),
				'label_phone'              => __( 'Phone', 'hd-form' ),
				'label_page'               => __( 'Source Page', 'hd-form' ),
				'label_message'            => __( 'Message', 'hd-form' ),
				'label_details'            => __( 'Service Details', 'hd-form' ),
				'label_form'               => __( 'Form', 'hd-form' ),
				'label_submitted'          => __( 'Submitted', 'hd-form' ),
			],
			$data
		);

		$data['site_logo_html'] = $data['site_logo_html'] ?? self::buildLogoHtml( (string) $data['site_logo'], (string) $data['site_name'] );
		$data                   = (array) apply_filters( 'hd_form_email_data', $data, $templateName );

		$contentHtml = self::resolveTemplate( $templateName, $data );

		$baseHtmlPath = self::locateTemplateFile( '_base.html' );
		$baseHtml     = $baseHtmlPath ? ( FileSystem::fileRead( $baseHtmlPath ) ?: '{{content}}' ) : '{{content}}';

		// Inject rendered content.
		$data['content'] = $contentHtml;

		$rendered = self::replacePlaceholders( $baseHtml, $data );

		return (string) apply_filters( 'hd_form_email_html', $rendered, $templateName, $data );
	}

	/**
	 * Resolve and render template content.
	 *
	 * Priority: wp_options → theme file → plugin file → default fallback → emergency inline.
	 *
	 * @param string $name Template filename without extension.
	 * @param array  $data Variables for template.
	 *
	 * @return string Rendered HTML content block.
	 */
	protected static function resolveTemplate( string $name, array $data ): string {
		// 1. wp_options (future admin editor).
		$optionKey = 'hd_email_tpl_' . $name;
		$optionTpl = Helper::getOption( $optionKey, '' );
		if ( ! empty( $optionTpl ) ) {
			return self::replacePlaceholders( $optionTpl, $data );
		}

		// 2. Specific template file (theme priority, then plugin).
		$path = self::locateTemplateFile( $name . '.html' );
		if ( $path ) {
			$content = FileSystem::fileRead( $path );
			if ( $content ) {
				return self::replacePlaceholders( $content, $data );
			}
		}

		// 3. Fallback → default.html (theme priority, then plugin).
		$fallback = self::locateTemplateFile( 'default.html' );
		if ( $fallback ) {
			$content = FileSystem::fileRead( $fallback );
			if ( $content ) {
				return self::replacePlaceholders( $content, $data );
			}
		}

		// 4. Emergency inline fallback.
		return self::emergencyFallback( $data );
	}

	/**
	 * Build fields table HTML from entry data.
	 *
	 * @param array $data Template data (must contain 'fields' key).
	 *
	 * @return string HTML table rows.
	 */
	protected static function buildFieldsTable( array $data ): string {
		$fields = $data['fields'] ?? [];
		$labels = $fields['__labels'] ?? [];
		$files  = $fields['__files'] ?? [];

		if ( is_array( $files ) ) {
			foreach ( $files as $fileKey => $fileUrl ) {
				if ( ( empty( $fields[ $fileKey ] ) || ! is_string( $fields[ $fileKey ] ) ) && is_string( $fileUrl ) && '' !== $fileUrl ) {
					$fields[ $fileKey ] = $fileUrl;
				}
			}
		}

		unset(
			$fields['__labels'],
			$fields['__files'],
			$fields['__geo'],
			$fields['__lang'],
			$fields['name'],
			$fields['email'],
			$fields['phone'],
			$fields['message']
		);

		if ( empty( $fields ) ) {
			return '';
		}

		$rows = '';
		foreach ( $fields as $key => $value ) {
			if ( str_starts_with( (string) $key, '__' ) ) {
				continue;
			}
			$label = $labels[ $key ] ?? ucwords( str_replace( [ '_', '-' ], ' ', (string) $key ) );

			if ( is_array( $value ) ) {
				if ( self::isRepeaterArray( $value ) ) {
					$displayVal = self::renderRepeaterSubTable( $value );
				} else {
					$flattened = [];
					array_walk_recursive(
						$value,
						static function ( mixed $v ) use ( &$flattened ): void {
							if ( is_scalar( $v ) && '' !== (string) $v ) {
								$flattened[] = (string) $v;
							}
						}
					);
					$displayVal = esc_html( implode( ', ', $flattened ) );
				}
			} elseif ( is_string( $value ) && self::isUrlList( $value ) ) {
				$urlLinks = [];
				foreach ( explode( "\n", $value ) as $rawUrl ) {
					$rawUrl = trim( $rawUrl );
					if ( '' === $rawUrl ) {
						continue;
					}
					$fileName   = basename( (string) wp_parse_url( $rawUrl, PHP_URL_PATH ) ) ?: $rawUrl;
					$urlLinks[] = sprintf(
						'<a href="%s" target="_blank" style="color:#2563eb;text-decoration:underline;font-weight:600;">📎 %s</a>',
						esc_url( $rawUrl ),
						esc_html( $fileName )
					);
				}
				$displayVal = implode( '<br>', $urlLinks );
			} elseif ( '' === (string) $value ) {
				$displayVal = '<span style="color:#94a3b8;">—</span>';
			} else {
				$displayVal = nl2br( esc_html( (string) $value ) );
			}

			$rows .= sprintf(
				'<tr><td style="border:1px solid #e2e8f0;padding:8px;font-weight:600;color:#334155;width:35%%;background:#f8fafc;vertical-align:top;">%s</td><td style="border:1px solid #e2e8f0;padding:8px;color:#475569;vertical-align:top;">%s</td></tr>',
				esc_html( (string) $label ),
				$displayVal
			);
		}

		if ( '' === $rows ) {
			return '';
		}

		return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;margin-bottom:24px;font-size:14px;">' . $rows . '</table>';
	}

	/**
	 * Check if array is a list of sub-arrays (repeater rows).
	 */
	private static function isRepeaterArray( array $val ): bool {
		if ( empty( $val ) || ! array_is_list( $val ) ) {
			return false;
		}

		foreach ( $val as $item ) {
			if ( is_array( $item ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a string consists entirely of one or more newline-separated URLs.
	 */
	private static function isUrlList( string $value ): bool {
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $value ) ) ) );
		if ( empty( $lines ) ) {
			return false;
		}

		foreach ( $lines as $line ) {
			if ( false === filter_var( $line, FILTER_VALIDATE_URL ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Render repeater array rows into an HTML sub-table.
	 */
	private static function renderRepeaterSubTable( array $rows ): string {
		$cols = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				foreach ( array_keys( $row ) as $k ) {
					$cols[ (string) $k ] = true;
				}
			}
		}

		if ( empty( $cols ) ) {
			return '';
		}

		$colKeys = array_keys( $cols );
		$th      = '';
		foreach ( $colKeys as $col ) {
			$colLabel = ucwords( str_replace( [ '_', '-' ], ' ', $col ) );
			$th      .= sprintf( '<th style="border:1px solid #cbd5e1;padding:6px 10px;background:#e2e8f0;color:#1e293b;font-weight:600;text-align:left;font-size:12px;">%s</th>', esc_html( $colLabel ) );
		}

		$tr = '';
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$td = '';
			foreach ( $colKeys as $col ) {
				$cellVal = $row[ $col ] ?? '';
				if ( is_array( $cellVal ) ) {
					$cellVal = implode( ', ', array_filter( array_map( 'strval', $cellVal ) ) );
				}
				$td .= sprintf( '<td style="border:1px solid #e2e8f0;padding:6px 10px;color:#334155;font-size:13px;">%s</td>', esc_html( (string) $cellVal ) );
			}

			$tr .= '<tr>' . $td . '</tr>';
		}

		return sprintf(
			'<table width="100%%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:4px 0;background:#ffffff;"><thead><tr>%s</tr></thead><tbody>%s</tbody></table>',
			$th,
			$tr
		);
	}

	/**
	 * Replace {{placeholders}} in template string.
	 *
	 * @param string $template Template string.
	 * @param array  $data     Data to replace.
	 *
	 * @return string Replaced string.
	 */
	protected static function replacePlaceholders( string $template, array $data ): string {
		// Auto-generate fields_table if needed.
		if ( ! isset( $data['fields_table'] ) && isset( $data['fields'] ) ) {
			$data['fields_table'] = self::buildFieldsTable( $data );
		}

		return preg_replace_callback(
			'/{{\s*([a-zA-Z0-9_\-]+)\s*}}/',
			static function ( array $matches ) use ( $template, $data ): string {
				$key = (string) ( $matches[1][0] ?? '' );
				if ( '' === $key || ! array_key_exists( $key, $data ) || ! is_scalar( $data[ $key ] ) ) {
					return '';
				}

				$value   = (string) $data[ $key ];
				$context = self::placeholderContext( $template, (int) ( $matches[0][1] ?? 0 ), $key );

				return match ( $context ) {
					'raw'  => wp_kses_post( $value ),
					'url'  => esc_url( $value ),
					'attr' => esc_attr( $value ),
					default => esc_html( $value ),
				};
			},
			$template,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		) ?? '';
	}

	/**
	 * Resolve the escaping context for a placeholder occurrence.
	 */
	private static function placeholderContext( string $template, int $offset, string $key ): string {
		if ( self::isRawPlaceholder( $key ) ) {
			return 'raw';
		}

		$before    = substr( $template, max( 0, $offset - 200 ), min( 200, $offset ) );
		$lastLt    = strrpos( $before, '<' );
		$lastGt    = strrpos( $before, '>' );
		$insideTag = false !== $lastLt && ( false === $lastGt || $lastLt > $lastGt );

		if ( $insideTag ) {
			if ( preg_match( '/(?:href|src|action)\s*=\s*["\'][^"\']*$/i', $before ) ) {
				return 'url';
			}

			if ( preg_match( '/[a-z0-9:_-]+\s*=\s*["\'][^"\']*$/i', $before ) ) {
				return 'attr';
			}
		}

		return self::isUrlPlaceholder( $key ) ? 'url' : 'html';
	}

	/**
	 * Whether a placeholder is explicitly allowed to render sanitized HTML.
	 */
	private static function isRawPlaceholder( string $key ): bool {
		return in_array( $key, self::RAW_PLACEHOLDERS, true )
			|| str_starts_with( $key, 'raw_' )
			|| str_ends_with( $key, '_html' );
	}

	/**
	 * Whether a placeholder should be treated as a URL when no attribute context exists.
	 */
	private static function isUrlPlaceholder( string $key ): bool {
		return str_contains( $key, 'url' )
			|| str_ends_with( $key, '_link' )
			|| str_ends_with( $key, '_href' )
			|| str_ends_with( $key, '_src' );
	}

	/**
	 * Emergency fallback when no template files exist.
	 *
	 * @param array $data Template data.
	 *
	 * @return string Minimal HTML.
	 */
	private static function emergencyFallback( array $data ): string {
		$heading = is_scalar( $data['label_submission_heading'] ?? null ) && '' !== (string) $data['label_submission_heading']
			? (string) $data['label_submission_heading']
			: __( 'New Form Submission', 'hd-form' );
		$html    = '<h2 style="font-size:20px;font-weight:600;color:#1e293b;margin:0 0 16px;">' . esc_html( $heading ) . '</h2>';
		$html   .= self::buildFieldsTable( $data );

		return $html;
	}

	/**
	 * Build logo <img> HTML tag, or empty string when no logo is set.
	 */
	private static function buildLogoHtml( string $src, string $alt ): string {
		if ( '' === $src ) {
			return '';
		}

		return sprintf(
			'<img src="%s" alt="%s" width="150" style="max-width:150px;height:auto;margin:0 auto 12px;display:block;border:0;" />',
			esc_url( $src ),
			esc_attr( $alt )
		);
	}

	/**
	 * Locate template file with theme override support.
	 *
	 * Checks:
	 * 1. Child theme / active stylesheet directory: templates/email/{file} or hd-form/email/{file}
	 * 2. Parent theme template directory: templates/email/{file} or hd-form/email/{file}
	 * 3. Plugin template directory: templates/email/{file}
	 *
	 * @param string $filename File name (e.g. 'contact.html', '_base.html').
	 *
	 * @return string|null Absolute path to template file if found.
	 */
	public static function locateTemplateFile( string $filename ): ?string {
		$filename = basename( ltrim( $filename, '/\\' ) );
		if ( '' === $filename ) {
			return null;
		}

		// 1. Check in child theme / stylesheet directory.
		$stylesheetDir = get_stylesheet_directory();
		if ( '' !== $stylesheetDir ) {
			$candidates = [
				$stylesheetDir . '/templates/email/' . $filename,
				$stylesheetDir . '/hd-form/email/' . $filename,
			];
			foreach ( $candidates as $candidate ) {
				if ( is_file( $candidate ) ) {
					return apply_filters( 'hd_form_email_template_file', $candidate, $filename );
				}
			}
		}

		// 2. Check in parent theme directory.
		$templateDir = get_template_directory();
		if ( '' !== $templateDir && $templateDir !== $stylesheetDir ) {
			$candidates = [
				$templateDir . '/templates/email/' . $filename,
				$templateDir . '/hd-form/email/' . $filename,
			];
			foreach ( $candidates as $candidate ) {
				if ( is_file( $candidate ) ) {
					return apply_filters( 'hd_form_email_template_file', $candidate, $filename );
				}
			}
		}

		// 3. Check in plugin templates directory.
		$pluginFile = HD_FORM_PATH . 'templates/email/' . $filename;
		if ( is_file( $pluginFile ) ) {
			return apply_filters( 'hd_form_email_template_file', $pluginFile, $filename );
		}

		return apply_filters( 'hd_form_email_template_file', null, $filename );
	}
}
