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
	private const DEFAULT_MAX_VALUE_CHARS  = 2000;
	private const DEFAULT_MAX_FIELD_CHARS  = 100000;
	private const MAX_FIELD_DEPTH          = 3;
	private const DANGEROUS_EXTENSIONS     = [ 'php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'exe', 'bat', 'cmd', 'sh', 'cgi', 'asp', 'aspx', 'jsp' ];
	private const DANGEROUS_FILENAMES      = [ '.env', '.htaccess', '.user.ini', 'httpd.conf', 'nginx.conf', 'php.ini', 'web.config', 'wp-config.php' ];

	/**
	 * Validate and sanitize a form submission payload.
	 *
	 * Supports two modes:
	 * - Registered form_type: config-driven validation rules.
	 * - Unknown form_type: accepts any form, reads captcha/spam settings
	 *   from data-* attributes passed in the payload.
	 *
	 * @param array $input Raw request payload.
	 *
	 * @return array|\WP_Error Sanitized data array or WP_Error on failure.
	 */
	public function validate( array $input ): array|\WP_Error {
		// 1. form_type is always required.
		$formType = sanitize_key( $input['form_type'] ?? '' );
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
		$formId = sanitize_key( $input['form_id'] ?? '' ) ?: $formType . '-' . time();

		// 3. Core mapped fields — always sanitize.
		$name    = sanitize_text_field( $input['name'] ?? '' );
		$phone   = sanitize_text_field( $input['phone'] ?? '' );
		$message = sanitize_textarea_field( $input['message'] ?? '' );

		// Email: validate raw input BEFORE sanitize_email() which strips
		// invalid emails to '' — causing silent data loss with no user feedback.
		$rawEmail = trim( (string) ( $input['email'] ?? '' ) );
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
		$rawFields = (array) ( $input['fields'] ?? [] );
		$maxFields = self::maxFieldCount();
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
			$cleanKey            = sanitize_key( $key );
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
			$fieldMap = array_merge( compact( 'name', 'email', 'phone', 'message' ), $fields );

			foreach ( $required as $field ) {
				if ( empty( $fieldMap[ $field ] ) && ! isset( $fieldErrors[ $field ] ) ) {
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
			$fieldLabels[ sanitize_key( $key ) ] = sanitize_text_field( (string) $label );
		}

		// 8. UTM → sanitize all keys from JS-collected params.
		$utm = [];
		foreach ( [ 'source', 'medium', 'campaign', 'term', 'content' ] as $utmKey ) {
			$utm[ $utmKey ] = sanitize_text_field( (string) ( $input['utm'][ $utmKey ] ?? '' ) );
		}

		// 9. CAPTCHA token — verified by FormManager via config-resolved guard.
		$captchaToken = sanitize_text_field( $input['captcha_token'] ?? '' );

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

		$allowedMimes = apply_filters(
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

		$maxSize     = self::maxUploadBytes();
		$fieldErrors = [];
		$diagnostics = [];

		foreach ( $files as $key => $file ) {
			$fieldKey = self::uploadFieldKey( $key );

			if ( ! is_array( $file ) ) {
				$fieldErrors[ $fieldKey ] = __( 'Invalid upload payload.', 'hd-form' );
				$diagnostics[ $fieldKey ] = [ 'reason' => 'invalid_shape' ];
				continue;
			}

			// Skip empty file inputs (no file selected).
			if ( UPLOAD_ERR_NO_FILE === (int) ( $file['error'] ?? 4 ) ) {
				continue;
			}

			if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
				$fieldErrors[ $fieldKey ] = __( 'File upload error. Please try again.', 'hd-form' );
				$diagnostics[ $fieldKey ] = [
					'reason' => 'php_upload_error',
					'code'   => (int) $file['error'],
				];
				continue;
			}

			if ( (int) $file['size'] > $maxSize ) {
				$fieldErrors[ $fieldKey ] = __( 'File too large.', 'hd-form' );
				$diagnostics[ $fieldKey ] = [
					'reason' => 'file_too_large',
					'limit'  => $maxSize,
				];
				continue;
			}

			// Anti-script: reject files with dangerous extensions anywhere in filename.
			$filename     = self::normalizedFilename( (string) ( $file['name'] ?? '' ) );
			$nameParts    = explode( '.', $filename );
			$hasScriptExt = ! empty( array_intersect( $nameParts, self::DANGEROUS_EXTENSIONS ) );

			if ( $hasScriptExt || in_array( $filename, self::DANGEROUS_FILENAMES, true ) ) {
				$fieldErrors[ $fieldKey ] = __( 'This file type is not allowed for security reasons.', 'hd-form' );
				$diagnostics[ $fieldKey ] = [ 'reason' => 'dangerous_file' ];
				continue;
			}

			$fileType = function_exists( 'wp_check_filetype_and_ext' )
				? wp_check_filetype_and_ext( $file['tmp_name'] ?? '', $file['name'], $allowedMimes )
				: wp_check_filetype( $file['name'], $allowedMimes );
			if ( empty( $fileType['ext'] ) || empty( $fileType['type'] ) ) {
				$fieldErrors[ $fieldKey ] = __( 'Invalid file type. Accepted: PDF, DOCX, ZIP, RAR.', 'hd-form' );
				$diagnostics[ $fieldKey ] = [ 'reason' => 'invalid_file_type' ];
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

	private static function normalizedFilename( string $filename ): string {
		$filename = strtolower( str_replace( '\\', '/', $filename ) );

		return basename( $filename );
	}

	/**
	 * Validate phone number format.
	 *
	 * When 'phone_vn_only' is enabled in settings, delegates to
	 * Str::isValidPhone() which validates Vietnamese phone numbers.
	 * Otherwise falls back to international format (6–15 digits).
	 *
	 * @param string $phone Raw phone number.
	 *
	 * @return bool True if valid.
	 */
	private static function isValidPhone( string $phone ): bool {
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
	 * Check email domain against allow/deny lists from form config.
	 *
	 * @param string $email Validated email address.
	 *
	 * @return string|null Error message if blocked, null if OK.
	 */
	private static function checkEmailDomain( string $email ): ?string {
		$config = FormConfig::all()['email_filter'] ?? [];

		if ( empty( $config ) ) {
			return null;
		}

		$domain = strtolower( substr( $email, strrpos( $email, '@' ) + 1 ) );

		// Deny list: block specific domains (e.g. disposable email services).
		$denyList = array_map( 'strtolower', $config['deny_domains'] ?? [] );
		if ( ! empty( $denyList ) && in_array( $domain, $denyList, true ) ) {
			return __( 'This email domain is not allowed.', 'hd-form' );
		}

		// Allow list: if set, only accept these domains.
		$allowList = array_map( 'strtolower', $config['allow_domains'] ?? [] );
		if ( ! empty( $allowList ) && ! in_array( $domain, $allowList, true ) ) {
			return __( 'This email domain is not allowed.', 'hd-form' );
		}

		return null;
	}
}
