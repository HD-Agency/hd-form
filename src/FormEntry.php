<?php
/**
 * Form Entry DTO
 *
 * Immutable value object representing a form submission.
 *
 * @package HD\Modules\Form
 */

declare(strict_types=1);

namespace HDForm;

defined( 'ABSPATH' ) || exit;

final class FormEntry {
	public function __construct(
		public readonly string $formType,
		public readonly string $formId,
		public readonly string $name,
		public readonly string $email,
		public readonly string $phone,
		public readonly string $phoneCountry,
		public readonly string $phoneNational,
		public readonly string $ipAddress,
		public readonly string $userAgent,
		public readonly string $refererUrl,
		public readonly string $pageUrl,
		public readonly array $data,
		public readonly string $submissionHash = '',
		public readonly string $utmSource = '',
		public readonly string $utmMedium = '',
		public readonly string $utmCampaign = '',
		public readonly string $utmTerm = '',
		public readonly string $utmContent = '',
		public readonly array $attachments = [],
		public readonly int $userId = 0,
	) {}

	/**
	 * Construct from a database row (associative array).
	 *
	 * @param array<string, mixed> $row Single row from hd_form_entries table.
	 *
	 * @return self
	 */
	public static function fromRow( array $row ): self {
		return new self(
			formType:      (string) ( $row['form_type'] ?? '' ),
			formId:        (string) ( $row['form_id'] ?? '' ),
			name:          (string) ( $row['name'] ?? '' ),
			email:         (string) ( $row['email'] ?? '' ),
			phone:         (string) ( $row['phone'] ?? '' ),
			phoneCountry:  (string) ( $row['phone_country'] ?? '' ),
			phoneNational: (string) ( $row['phone_national'] ?? '' ),
			ipAddress:     (string) ( $row['ip_address'] ?? '' ),
			userAgent:     (string) ( $row['user_agent'] ?? '' ),
			refererUrl:    (string) ( $row['referer_url'] ?? '' ),
			pageUrl:       (string) ( $row['page_url'] ?? '' ),
			data:          is_array( $row['data'] ?? null ) ? $row['data'] : [],
			submissionHash: (string) ( $row['submission_hash'] ?? '' ),
			utmSource:     (string) ( $row['utm_source'] ?? '' ),
			utmMedium:     (string) ( $row['utm_medium'] ?? '' ),
			utmCampaign:   (string) ( $row['utm_campaign'] ?? '' ),
			utmTerm:       (string) ( $row['utm_term'] ?? '' ),
			utmContent:    (string) ( $row['utm_content'] ?? '' ),
			userId:        (int) ( $row['user_id'] ?? 0 ),
		);
	}
}
