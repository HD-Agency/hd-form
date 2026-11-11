<?php
/**
 * Form Validator
 *
 * Sanitizes and validates REST API form submission payload.
 *
 * @package HD\Modules\Form
 */

declare(strict_types=1);

namespace HDForm;

use HDForm\Compat\Str;

defined( 'ABSPATH' ) || exit;

class FormValidator {
	private const DEFAULT_MAX_FIELDS       = 100;
	private const DEFAULT_MAX_UPLOAD_BYTES = 4194304; // 4 MB.
	public const DEFAULT_MAX_UPLOAD_FILES  = 10;
	private const DEFAULT_MAX_VALUE_CHARS  = 2000;
	private const DEFAULT_MAX_FIELD_CHARS  = 100000;
	private const MAX_FIELD_DEPTH          = 3;
	private const DANGEROUS_EXTENSIONS     = [ 'php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'exe', 'bat', 'cmd', 'sh', 'cgi', 'asp', 'aspx', 'jsp' ];
	private const DANGEROUS_FILENAMES      = [ '.env', '.htaccess', '.user.ini', 'httpd.conf', 'nginx.conf', 'php.ini', 'web.config', 'wp-config.php' ];

	/**
	 * Validate and sanitize a form submission payload.
	 *
	 * @param array $input Raw request payload.
	 *
	 * @return array|\WP_Error Sanitized data array or WP_Error on failure.
	 */
	public function validate( array $input ): array|\WP_Error {
		// 1. form_type is always required.
		$formType = is_string( $input['form_type'] ?? null ) ? sanitize_key( $input['form_type'] ) : '';
		if ( '' === $formType ) {
			return new \WP_Error(
				'missing_form_type',
				__( 'Missing form type.', 'hd-form' ),
				[ 'status' => 400 ]
			);
		}

		// 1a. Closed registry — reject unregistered form types.
		if ( ! FormConfig::isRegistered( $formType ) ) {
			return new \WP_Error(
				'unknown_form_type',
				__( 'Unknown form type.', 'hd-form' ),
				[ 'status' => 400 ]
			);
		}

		// 2. form_id: sanitize; JS auto-generates one, this is a server-side fallback.
		$rawFormId = is_string( $input['form_id'] ?? null ) ? sanitize_key( $input['form_id'] ) : '';
		$formId    = '' !== $rawFormId ? $rawFormId : $formType . '-' . time();

		// 3. Core mapped fields — always sanitize.
		$name    = is_string( $input['name'] ?? null ) ? sanitize_text_field( $input['name'] ) : '';
		$phone   = is_string( $input['phone'] ?? null ) ? sanitize_text_field( $input['phone'] ) : '';
		$message = is_string( $input['message'] ?? null ) ? sanitize_textarea_field( $input['message'] ) : '';

		// Email: validate raw input BEFORE sanitize_email() which strips
		// invalid emails to '' — causing silent data loss with no user feedback.
		$rawEmail = is_string( $input['email'] ?? null ) ? trim( $input['email'] ) : '';
		$email    = sanitize_email( $rawEmail );

		// 4. Per-field validation errors — accumulate all errors.
		$fieldErrors = [];

		// 4a. Validate email format against raw input.
		if ( '' !== $rawEmail && ! is_email( $rawEmail ) ) {
			$fieldErrors['email'] = __( 'Invalid email address.', 'hd-form' );
		}

		// 4b. Validate phone format (6–15 digits when cleaned).
		if ( '' !== $phone && ! self::isValidPhone( $phone ) ) {
			$fieldErrors['phone'] = __( 'Invalid phone number.', 'hd-form' );
		}

		// 4c. Email domain allow/deny list.
		if ( '' !== $email && empty( $fieldErrors['email'] ) ) {
			$domainError = self::checkEmailDomain( $email );
			if ( $domainError ) {
				$fieldErrors['email'] = $domainError;
			}
		}

		// 5. Extra fields → sanitize each value, stored as JSON `data` column.
		$rawFields  = (array) ( $input['fields'] ?? [] );
		$maxFields  = self::maxFieldCount();
		$maxRepeats = self::maxRepeatsCount();
		if ( count( $rawFields ) > $maxFields ) {
			return new \WP_Error(
				'validation_failed',
				__( 'Please fix the errors below.', 'hd-form' ),
				[
					'status' => 422,
					'fields' => [
						'fields' => sprintf(
							/* translators: %d: maximum allowed field count */
							__( 'Too many fields. Maximum allowed is %d.', 'hd-form' ),
							$maxFields
						),
					],
				]
			);
		}

		$fields     = [];
		$charBudget = self::maxTotalFieldChars();
		foreach ( $rawFields as $key => $value ) {
			$cleanKey = sanitize_key( (string) $key );
			if ( '' === $cleanKey || str_starts_with( $cleanKey, 'ak_' ) ) {
				continue;
			}

			if ( is_array( $value ) && count( $value ) > $maxRepeats ) {
				return new \WP_Error(
					'validation_failed',
					__( 'Please fix the errors below.', 'hd-form' ),
					[
						'status' => 422,
						'fields' => [
							$cleanKey => sprintf(
								/* translators: 1: field name, 2: maximum allowed rows */
								__( 'Too many items in "%1$s". Maximum allowed is %2$d.', 'hd-form' ),
								$cleanKey,
								$maxRepeats
							),
						],
					]
				);
			}

			$fields[ $cleanKey ] = $this->sanitizeFieldValue( $value, 0, $charBudget );
		}

		// Include message in fields if it arrived that way.
		if ( '' !== $message ) {
			$fields['message'] = $message;
		}

		// 6. Validate required fields based on registered form type config.
		$formConfig = FormConfig::getFormType( $formType );
		if ( null !== $formConfig ) {
			$required = $formConfig['required'] ?? [ 'name', 'phone' ];
			$fieldMap = array_merge( $fields, compact( 'name', 'email', 'phone', 'message' ) );

			foreach ( $required as $field ) {
				$val = $fieldMap[ $field ] ?? null;
				if ( self::isMissing( $val ) && ! isset( $fieldErrors[ $field ] ) ) {
					/* translators: %s: field name */
					$fieldErrors[ $field ] = sprintf( __( 'Field "%s" is required.', 'hd-form' ), $field );
				}
			}
		}

		// Return all field errors at once.
		if ( ! empty( $fieldErrors ) ) {
			return new \WP_Error(
				'validation_failed',
				__( 'Please fix the errors below.', 'hd-form' ),
				[
					'status' => 422,
					'fields' => $fieldErrors,
				]
			);
		}

		// 7. Field labels → human-readable display names from data-title attributes.
		$fieldLabels = [];
		foreach ( (array) ( $input['field_labels'] ?? [] ) as $key => $label ) {
			if ( is_scalar( $label ) ) {
				$fieldLabels[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $label );
			}
		}

		// 8. UTM → sanitize all keys from JS-collected params.
		$utm    = [];
		$rawUtm = is_array( $input['utm'] ?? null ) ? $input['utm'] : [];
		foreach ( [ 'source', 'medium', 'campaign', 'term', 'content' ] as $utmKey ) {
			$utmVal         = $rawUtm[ $utmKey ] ?? '';
			$utm[ $utmKey ] = is_scalar( $utmVal ) ? sanitize_text_field( (string) $utmVal ) : '';
		}

		// 9. CAPTCHA token — verified by FormManager via config-resolved guard.
		$captchaToken = is_string( $input['captcha_token'] ?? null ) ? sanitize_text_field( $input['captcha_token'] ) : '';

		return compact(
			'formType',
			'formId',
			'name',
			'email',
			'phone',
			'fields',
			'fieldLabels',
			'utm',
			'captchaToken',
		);
	}

	/**
	 * Validate uploaded files.
	 *
	 * Checks upload errors, file size, and allowed MIME types.
	 *
	 * @param array $files $_FILES-style array.
	 *
	 * @return array|\WP_Error Validated file info or error.
	 */
	public function validateFiles( array $files ): array|\WP_Error {
		if ( empty( $files ) ) {
			return [];
		}

		$allowedMimes = (array) apply_filters(
			'hd_form_allowed_mimes',
			[
				'pdf'  => 'application/pdf',
				'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'zip'  => 'application/zip',
				'rar'  => 'application/x-rar-compressed',
				'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'xls'  => 'application/vnd.ms-excel',
				'csv'  => 'text/csv',
			]
		);

		$maxSize            = self::maxUploadBytes();
		$maxUploadFiles     = self::maxUploadFiles();
		$allowMultiple      = FormConfig::isAllowMultipleUploadsEnabled();
		$totalUploadedFiles = 0;
		$fieldErrors        = [];
		$diagnostics        = [];

		foreach ( $files as $key => $rawFile ) {
			$fieldKey = self::uploadFieldKey( $key );

			if ( ! is_array( $rawFile ) ) {
				$fieldErrors[ $fieldKey ] = __( 'Invalid upload payload.', 'hd-form' );
				$diagnostics[ $fieldKey ] = [ 'reason' => 'invalid_shape' ];
				continue;
			}

			$fileList = FileUploadHandler::normalizeFileItem( $rawFile );

			foreach ( $fileList as $file ) {
				// Skip empty file inputs (no file selected).
				if ( UPLOAD_ERR_NO_FILE === (int) ( $file['error'] ?? 4 ) ) {
					continue;
				}

				++$totalUploadedFiles;

				if ( $totalUploadedFiles > 1 && ! $allowMultiple ) {
					$fieldErrors[ $fieldKey ] = __( 'Multiple file upload is not allowed.', 'hd-form' );
					$diagnostics[ $fieldKey ] = [ 'reason' => 'multiple_not_allowed' ];
					break;
				}

				if ( $totalUploadedFiles > $maxUploadFiles ) {
					$fieldErrors[ $fieldKey ] = __( 'Too many uploaded files.', 'hd-form' );
					$diagnostics[ $fieldKey ] = [
						'reason' => 'max_files_exceeded',
						'limit'  => $maxUploadFiles,
					];
					break;
				}

				if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
					$fieldErrors[ $fieldKey ] = __( 'File upload error. Please try again.', 'hd-form' );
					$diagnostics[ $fieldKey ] = [
						'reason' => 'php_upload_error',
						'code'   => (int) $file['error'],
					];
					break;
				}

				if ( (int) $file['size'] > $maxSize ) {
					$fieldErrors[ $fieldKey ] = __( 'File too large.', 'hd-form' );
					$diagnostics[ $fieldKey ] = [
						'reason' => 'file_too_large',
						'limit'  => $maxSize,
					];
					break;
				}

				// Anti-script: reject files with dangerous extensions anywhere in filename.
				$filename     = self::normalizedFilename( (string) ( $file['name'] ?? '' ) );
				$nameParts    = explode( '.', $filename );
				$hasScriptExt = ! empty( array_intersect( $nameParts, self::DANGEROUS_EXTENSIONS ) );

				if ( $hasScriptExt || in_array( $filename, self::DANGEROUS_FILENAMES, true ) ) {
					$fieldErrors[ $fieldKey ] = __( 'This file type is not allowed for security reasons.', 'hd-form' );
					$diagnostics[ $fieldKey ] = [ 'reason' => 'dangerous_file' ];
					break;
				}

				$fileType = function_exists( 'wp_check_filetype_and_ext' )
					? wp_check_filetype_and_ext( $file['tmp_name'] ?? '', $file['name'], $allowedMimes )
					: wp_check_filetype( $file['name'], $allowedMimes );
				if ( empty( $fileType['ext'] ) || empty( $fileType['type'] ) ) {
					$fieldErrors[ $fieldKey ] = __( 'Invalid file type. Accepted: PDF, DOCX, ZIP, RAR.', 'hd-form' );
					$diagnostics[ $fieldKey ] = [ 'reason' => 'invalid_file_type' ];
					break;
				}
			}
		}

		if ( $fieldErrors ) {
			return new \WP_Error(
				'upload_validation_failed',
				__( 'Please fix uploaded files.', 'hd-form' ),
				[
					'status'        => 422,
					'fields'        => $fieldErrors,
					'upload_errors' => $diagnostics,
				]
			);
		}

		return $files;
	}

	public static function uploadFieldKey( int|string $key ): string {
		$fieldKey = sanitize_key( (string) $key );

		return '' !== $fieldKey ? $fieldKey : 'file';
	}

	private static function maxFieldCount(): int {
		$config = FormConfig::all();
		$limit  = absint( $config['max_fields'] ?? $config['limits']['max_fields'] ?? self::DEFAULT_MAX_FIELDS );

		return max( 1, $limit );
	}

	/**
	 * Per-value character clamp, configurable via settings or filter.
	 */
	private static function maxValueChars(): int {
		$config = FormConfig::all();
		$limit  = absint( $config['limits']['max_value_chars'] ?? self::DEFAULT_MAX_VALUE_CHARS );

		return max( 1, (int) apply_filters( 'hd_form_max_value_chars', $limit ) );
	}

	/**
	 * Total character budget across all extra fields of one submission.
	 */
	private static function maxTotalFieldChars(): int {
		$config = FormConfig::all();
		$limit  = absint( $config['limits']['max_field_chars'] ?? self::DEFAULT_MAX_FIELD_CHARS );

		return max( 1, (int) apply_filters( 'hd_form_max_total_field_chars', $limit ) );
	}

	/**
	 * Recursively sanitize an extra field value with hard bounds.
	 *
	 * Strings are clamped to the per-value limit; arrays deeper than
	 * MAX_FIELD_DEPTH are dropped; a shared character budget caps the total
	 * payload so one request cannot smuggle unbounded data into the JSON
	 * `data` column.
	 *
	 * @param mixed $value  Raw value.
	 * @param int   $depth  Current recursion depth.
	 * @param int   $budget Remaining shared character allowance (by reference).
	 *
	 * @return string|array Sanitized scalar or nested list.
	 */
	private function sanitizeFieldValue( mixed $value, int $depth, int &$budget ): string|array {
		if ( ! is_array( $value ) ) {
			$text = sanitize_text_field( (string) $value );

			$length = mb_strlen( $text );
			if ( $length > self::maxValueChars() ) {
				$text   = mb_substr( $text, 0, self::maxValueChars() );
				$length = self::maxValueChars();
			}

			if ( $length > $budget ) {
				$text   = mb_substr( $text, 0, max( 0, $budget ) );
				$length = max( 0, $budget );
			}

			$budget -= $length;

			return $text;
		}

		if ( $depth >= self::MAX_FIELD_DEPTH ) {
			return '';
		}

		$clean = [];
		foreach ( $value as $key => $item ) {
			$cleanItem = $this->sanitizeFieldValue( $item, $depth + 1, $budget );
			if ( '' === $cleanItem || [] === $cleanItem ) {
				continue;
			}

			$clean[ is_string( $key ) ? sanitize_key( $key ) : $key ] = $cleanItem;
		}

		return $clean;
	}

	private static function maxUploadBytes(): int {
		$config = FormConfig::all();
		$limit  = absint( $config['max_upload_size'] ?? $config['upload_max_size'] ?? $config['uploads']['max_size'] ?? self::DEFAULT_MAX_UPLOAD_BYTES );

		return max( 1, $limit );
	}

	public static function maxUploadFiles(): int {
		$limit = (int) apply_filters( 'hd_form_max_upload_files', self::DEFAULT_MAX_UPLOAD_FILES );

		return max( 1, $limit );
	}

	private static function maxRepeatsCount(): int {
		return max( 1, (int) apply_filters( 'hd_form_max_repeats_count', 20 ) );
	}

	/**
	 * Determine if a field value is missing.
	 *
	 * Replaces broad empty(). Accepts legitimate '0', 0, and 0.0 as valid submissions,
	 * while strictly rejecting null, '', pure whitespace, and empty arrays.
	 *
	 * @param mixed $value Field value.
	 *
	 * @return bool True if missing.
	 */
	public static function isMissing( mixed $value ): bool {
		return null === $value
			|| ( is_string( $value ) && '' === trim( $value ) )
			|| ( is_array( $value ) && empty( $value ) );
	}

	private static function normalizedFilename( string $filename ): string {
		$filename = strtolower( str_replace( '\\', '/', $filename ) );

		return basename( $filename );
	}

	/**
	 * Validate phone number format.
	 *
	 * @param string $phone Raw phone number.
	 *
	 * @return bool True if valid.
	 */
	private static function isValidPhone( string $phone ): bool {
		$filtered = apply_filters( 'hd_form_validate_phone', null, $phone );
		if ( null !== $filtered ) {
			return (bool) $filtered;
		}

		$vnOnly = ! empty( FormConfig::all()['phone_vn_only'] );

		if ( $vnOnly ) {
			return Str::isValidPhone( $phone );
		}

		// International format fallback.
		$cleaned = preg_replace( '/[()\/*#\s.-]+/', '', $phone );

		if ( str_starts_with( $cleaned, '+' ) || str_starts_with( $cleaned, '00' ) ) {
			$cleaned = '+' . ltrim( $cleaned, '+0' );
		}

		return (bool) preg_match( '/^[+]?[0-9]+$/', $cleaned )
			&& strlen( $cleaned ) > 5
			&& strlen( $cleaned ) < 16;
	}

	/**
	 * Check email against allow/deny lists from form config (supports domains, specific emails, and wildcards).
	 *
	 * @param string $email Validated email address.
	 *
	 * @return string|null Error message if blocked, null if OK.
	 */
	private static function checkEmailDomain( string $email ): ?string {
		$config = FormConfig::all()['email_filter'] ?? [];

		if ( empty( $config ) || ! is_array( $config ) ) {
			return null;
		}

		$email = strtolower( trim( $email ) );

		// Deny list: block matching domains, emails, or wildcard patterns.
		$denyList = (array) ( $config['deny_domains'] ?? [] );
		foreach ( $denyList as $denyEntry ) {
			if ( self::matchesEmailPattern( $email, (string) $denyEntry ) ) {
				return __( 'This email address is not allowed.', 'hd-form' );
			}
		}

		// Allow list: if populated, only accept matching domains/emails/patterns.
		$allowList = (array) ( $config['allow_domains'] ?? [] );
		if ( ! empty( $allowList ) ) {
			$allowed = false;
			foreach ( $allowList as $allowEntry ) {
				if ( self::matchesEmailPattern( $email, (string) $allowEntry ) ) {
					$allowed = true;
					break;
				}
			}

			if ( ! $allowed ) {
				return __( 'This email address is not allowed.', 'hd-form' );
			}
		}

		return null;
	}

	/**
	 * Check if an email matches a domain, specific email, or wildcard pattern.
	 */
	private static function matchesEmailPattern( string $email, string $entry ): bool {
		$email = strtolower( trim( $email ) );
		$entry = strtolower( trim( $entry ) );

		if ( '' === $entry ) {
			return false;
		}

		// 1. Wildcard pattern matching (contains * or ?)
		if ( str_contains( $entry, '*' ) || str_contains( $entry, '?' ) ) {
			$pattern = str_contains( $entry, '@' ) ? $entry : '*@' . ltrim( $entry, '@.' );
			return fnmatch( $pattern, $email, FNM_CASEFOLD );
		}

		// 2. Exact email matching (contains '@' and does not start with '@')
		if ( str_contains( $entry, '@' ) && ! str_starts_with( $entry, '@' ) ) {
			return $email === $entry;
		}

		// 3. Domain matching (e.g. 'mailinator.com' or '@mailinator.com')
		$cleanDomain = ltrim( $entry, '@.' );
		$emailDomain = (string) substr( $email, (int) strrpos( $email, '@' ) + 1 );

		return $emailDomain === $cleanDomain;
	}
}
