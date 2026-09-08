<?php
/**
 * Form Manager — Business Logic Orchestrator.
 *
 * Stateless service that coordinates form submission processing:
 * validate → honeypot → CAPTCHA → spam check → save → email → log.
 *
 * Instantiated by FormAPI — NOT a Module itself.
 *
 * @package HD\Modules\Form
 */

declare(strict_types=1);

namespace HDForm;

use HDForm\Compat\Helper;
use HDForm\Cron\AsyncFormProcessor;
use HDForm\Notification\NotificationDispatcher;
use HDForm\Notification\NotificationMessage;
use HDForm\Repository\EntryCountCache;
use HDForm\Repository\FormEntryRepository;
use HDForm\Repository\FormLogRepository;
use HDForm\Security\CaptchaGuard;
use HDForm\Security\DuplicateGuard;
use HDForm\Security\GeoIPResolver;
use HDForm\Security\HoneypotGuard;
use HDForm\Security\SpamChecker;

defined( 'ABSPATH' ) || exit;

final class FormManager {
	private const DEFAULT_MIN_SUBMIT_TIME = 3;
	private const DEFAULT_MAX_RENDER_AGE  = 1800;

	/**
	 * Process a form submission.
	 *
	 * This is the main entry point called by the REST endpoint.
	 * It separates business logic from HTTP-layer concerns.
	 *
	 * @param array  $input     Raw payload from WP_REST_Request.
	 * @param string $ip        Client IP address.
	 * @param string $userAgent Client User-Agent header.
	 * @param string $referer   HTTP Referer header.
	 * @param array  $files     Uploaded files ($_FILES-style).
	 *
	 * @return array{entry_id: int, spam: bool}|\WP_Error Result on success, WP_Error on failure.
	 */
	public function processSubmission( array $input, string $ip, string $userAgent, string $referer, array $files = [] ): array|\WP_Error {

		// 1. Honeypot check (before validation to fast-fail bots).
		$honeypotState = HoneypotGuard::inspect( $input );
		if ( 'bot' === $honeypotState ) {
			self::logDropEvent(
				'bot_dropped',
				'Honeypot check failed; submission dropped.',
				[
					'form_type' => sanitize_key( (string) ( $input['form_type'] ?? '' ) ),
					'reason'    => 'honeypot',
				],
				$ip
			);

			return new \WP_Error(
				'spam_detected',
				__( 'Spam check failed. Please refresh the page and try again.', 'hd-form' ),
				[ 'status' => 400 ]
			);
		}

		if ( 'expired' === $honeypotState ) {
			// Validly signed token from a stale page (e.g. served by a full-page
			// cache) — not tampering: continue through the normal anti-bot layers.
			self::logDropEvent(
				'render_expired',
				'Stale honeypot token accepted; submission continued.',
				[ 'form_type' => sanitize_key( (string) ( $input['form_type'] ?? '' ) ) ],
				$ip
			);
		}

		// 1.2. User Interaction Telemetry — advisory only. The flag is unsigned
		// client telemetry, so a miss must never destroy a real submission:
		// log it for review and rely on the downstream layers (CAPTCHA, rate
		// limit, duplicate guard) for enforcement.
		$config             = FormConfig::all();
		$interactionEnabled = ! isset( $config['spam']['user_interaction_enabled'] ) || ! empty( $config['spam']['user_interaction_enabled'] );
		if ( $interactionEnabled && empty( $input['_user_interacted'] ) ) {
			self::logDropEvent(
				'interaction_missing',
				'Interaction telemetry absent; submission continued on remaining anti-bot layers.',
				[
					'form_type' => sanitize_key( (string) ( $input['form_type'] ?? '' ) ),
					'reason'    => 'telemetry',
				],
				$ip
			);
		}

		// 1.5. Minimum submission time (bots submit too fast).
		$timestampResult = self::validateRenderTimestamp( $input, $config, $ip );
		if ( is_wp_error( $timestampResult ) ) {
			return $timestampResult;
		}

		// 2. Validate & sanitize input.
		$validator = new FormValidator();
		$validated = $validator->validate( $input );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$formType = $validated['formType'];

		// 3. Verify CAPTCHA (before file upload to prevent bot orphan files).
		$captchaError = $this->verifyCaptcha( $formType, $validated['captchaToken'], $ip );
		if ( is_wp_error( $captchaError ) ) {
			return $captchaError;
		}

		// 3.5. Duplicate submission detection (30min window).
		$dupeHash = DuplicateGuard::hash( $ip, $formType, $validated );
		$claimKey = DuplicateGuard::claimKey( $dupeHash );

		if ( ! DuplicateGuard::claim( $claimKey ) ) {
			DuplicateGuard::logDrop( $formType, $validated, $dupeHash, $ip );

			return new \WP_Error(
				'duplicate_submission',
				__( 'A submission with this content was already received recently.', 'hd-form' ),
				[ 'status' => 429 ]
			);
		}

		// 4. Validate & upload files.
		$uploadedFiles = [];
		if ( ! empty( $files ) ) {
			$fileResult = $validator->validateFiles( $files );
			if ( is_wp_error( $fileResult ) ) {
				DuplicateGuard::release( $claimKey );

				return $fileResult;
			}

			$uploadResult = ( new FileUploadHandler() )->store( $files );
			if ( is_wp_error( $uploadResult ) ) {
				DuplicateGuard::release( $claimKey );

				return $uploadResult;
			}

			$uploadedFiles = $uploadResult;
		}

		// 5. Build DTO.
		$utm         = $validated['utm'];
		$entryData   = $validated['fields'];
		$fieldLabels = $validated['fieldLabels'] ?? [];

		if ( ! empty( $uploadedFiles ) ) {
			$entryData['__files'] = $uploadedFiles;
			foreach ( $uploadedFiles as $fileField => $fileUrl ) {
				$entryData[ $fileField ] = $fileUrl;
			}
		}

		if ( ! empty( $fieldLabels ) ) {
			$entryData['__labels'] = $fieldLabels;
		}

		// 5.5. GeoIP enrichment.
		$geo = GeoIPResolver::resolve( $ip );
		if ( $geo ) {
			$entryData['__geo'] = $geo;
		}

		$entry = new FormEntry(
			formType:      $formType,
			formId:        $validated['formId'],
			name:          $validated['name'],
			email:         $validated['email'],
			phone:         $validated['phone'],
			phoneCountry:  '',
			phoneNational: '',
			ipAddress:     $ip,
			userAgent:     $userAgent,
			refererUrl:    $referer,
			pageUrl:       sanitize_url( (string) ( $input['page_url'] ?? '' ) ),
			data:          $entryData,
			submissionHash: $dupeHash,
			utmSource:     $utm['source'] ?? '',
			utmMedium:     $utm['medium'] ?? '',
			utmCampaign:   $utm['campaign'] ?? '',
			utmTerm:       $utm['term'] ?? '',
			utmContent:    $utm['content'] ?? '',
			userId:        get_current_user_id(),
		);

		// 6. Spam check.
		$spamOverride = null !== FormConfig::getFormType( $formType )
			? null
			: ( $input['spam_check'] ?? null );

		$spamReasons = SpamChecker::checkCheap( $entry, $spamOverride );
		$isSpam      = ! empty( $spamReasons );

		// 7. Save to database.
		$repo    = new FormEntryRepository();
		$entryId = $repo->insert( $entry );

		if ( is_wp_error( $entryId ) ) {
			DuplicateGuard::release( $claimKey );

			if ( 'duplicate_submission' === $entryId->get_error_code() ) {
				DuplicateGuard::logDrop( $formType, $validated, $dupeHash, $ip );

				return [
					'entry_id' => 0,
					'spam'     => false,
				];
			}

			return new \WP_Error(
				'save_failed',
				__( 'An error occurred. Please try again.', 'hd-form' ),
				[ 'status' => 500 ]
			);
		}

		// Mark as spam post-save if detected.
		if ( $isSpam ) {
			$repo->bulkUpdateStatus( [ $entryId ], 'spam' );
		}

		if ( ! $isSpam ) {
			AsyncFormProcessor::enqueueAkismet( $entryId );
		}

		// 8. Log the submission event.
		$logRepo = new FormLogRepository();
		$logRepo->log(
			$entryId,
			$isSpam ? 'spam_detected' : 'submitted',
			$isSpam ? 'Form submitted (spam detected).' : 'Form submitted.',
			[
				'form_id'      => $validated['formId'],
				'spam_reasons' => $spamReasons,
			],
			'system',
			$ip
		);

		// 9. Dispatch notifications (skip for spam).
		if ( ! $isSpam ) {
			$this->dispatchNotifications( $entryId, $formType, $entry, $logRepo );
		}

		// 10. Extensibility hook for third-party code.
		do_action( 'hd_form_submitted', $entryId, $formType, $entry, $isSpam );

		// 11. Invalidate badge count cache.
		EntryCountCache::flush();

		return [
			'entry_id' => $entryId,
			'spam'     => $isSpam,
		];
	}

	/**
	 * Dispatch notifications to all enabled channels.
	 *
	 * @param int              $entryId  The saved entry ID.
	 * @param string           $formType Form type slug.
	 * @param FormEntry        $entry    The form entry DTO.
	 * @param FormLogRepository $logRepo Log repository instance.
	 */
	private function dispatchNotifications( int $entryId, string $formType, FormEntry $entry, FormLogRepository $logRepo ): void {
		try {
			$message = NotificationMessage::fromEntry( $entry, $entryId );
			$results = NotificationDispatcher::dispatch( $message );

			// Success rows are per-submission noise — keep them behind the
			// debug flag; failures are always recorded.
			$logSuccesses = self::logDebugEvents();

			foreach ( $results as $channel => $success ) {
				if ( $success && ! $logSuccesses ) {
					continue;
				}

				$eventType = match ( true ) {
					$success => $channel . '_queued',
					default  => $channel . '_queue_failed',
				};
				$label = $success ? 'Queued' : 'Failed';

				$logRepo->log(
					$entryId,
					$eventType,
					sprintf( 'Notification via %s: %s', $channel, $label ),
					[ 'channel' => $channel ],
					'system',
					$entry->ipAddress
				);
			}

			$scheduled = AsyncFormProcessor::enqueueNotifications( $entryId, $entry );
			if ( ! $scheduled || $logSuccesses ) {
				$logRepo->log(
					$entryId,
					$scheduled ? 'notifications_queued' : 'notifications_queue_failed',
					$scheduled ? 'Async notifications queued.' : 'Failed to queue async notifications.',
					[ 'channels' => 'non_email' ],
					'system',
					$entry->ipAddress
				);
			}
		} catch ( \Throwable $e ) {
			$logRepo->log(
				$entryId,
				'notification_failed',
				'Failed to dispatch notifications.',
				[ 'error' => $e->getMessage() ],
				'system',
				$entry->ipAddress
			);
		}
	}

	/**
	 * Whether debug-level lifecycle events (e.g. successful queue enqueues)
	 * should be written to the form log.
	 */
	private static function logDebugEvents(): bool {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return true;
		}

		return ! empty( FormConfig::all()['debug'] );
	}

	/**
	 * Verify CAPTCHA token.
	 *
	 * @param string $formType     Form type slug.
	 * @param string $captchaToken Token from frontend.
	 * @param string $ip           Client IP.
	 *
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	private function verifyCaptcha( string $formType, string $captchaToken, string $ip ): bool|\WP_Error {
		// Provider always resolved from config — never trust client-provided type.
		$guard = CaptchaGuard::make( $formType );

		if ( ! $guard->verify( $captchaToken, $ip ) ) {
			return new \WP_Error(
				'captcha_failed',
				__( 'CAPTCHA verification failed.', 'hd-form' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Validate the client render timestamp before expensive validation.
	 *
	 * Hard drops are reserved for unambiguous bot signatures: a missing or
	 * future timestamp and sub-minimum fill times. A stale timestamp — cached
	 * pages, tabs left open overnight — is logged as an advisory and the
	 * submission continues, mirroring the honeypot expiry tolerance.
	 *
	 * @param array<string, mixed> $input  Raw submission payload.
	 * @param array<string, mixed> $config Form module config.
	 * @param string               $ip     Client IP for the advisory log.
	 *
	 * @return array{entry_id: int, spam: bool}|null
	 */
	private static function validateRenderTimestamp( array $input, array $config, string $ip = '' ): ?\WP_Error {
		$renderedAt = self::normalizeRenderTimestamp( $input['_render_ts'] ?? null );
		if ( $renderedAt <= 0 ) {
			return new \WP_Error(
				'invalid_timestamp',
				__( 'Invalid submission timestamp. Please refresh and try again.', 'hd-form' ),
				[ 'status' => 400 ]
			);
		}

		$elapsed = time() - $renderedAt;
		if ( $elapsed < 0 ) {
			return new \WP_Error(
				'invalid_timestamp',
				__( 'Invalid submission timestamp. Please refresh and try again.', 'hd-form' ),
				[ 'status' => 400 ]
			);
		}

		$minTime = max( 0, (int) ( $config['min_submit_time'] ?? self::DEFAULT_MIN_SUBMIT_TIME ) );
		if ( $minTime > 0 && $elapsed < $minTime ) {
			return new \WP_Error(
				'submitted_too_fast',
				__( 'You are submitting too quickly. Please wait a few seconds and try again.', 'hd-form' ),
				[ 'status' => 429 ]
			);
		}

		$maxAge = max( 0, (int) ( $config['max_render_age'] ?? self::DEFAULT_MAX_RENDER_AGE ) );
		if ( $maxAge > 0 && $elapsed > $maxAge ) {
			self::logDropEvent(
				'submit_window_expired',
				'Stale render timestamp accepted; submission continued.',
				[
					'form_type'   => sanitize_key( (string) ( $input['form_type'] ?? '' ) ),
					'age_seconds' => $elapsed,
				],
				$ip
			);

			return null;
		}

		return null;
	}

	private static function normalizeRenderTimestamp( mixed $value ): int {
		if ( ! is_scalar( $value ) ) {
			return 0;
		}

		$timestamp = abs( (int) $value );

		return $timestamp > 9999999999
			? (int) floor( $timestamp / 1000 )
			: $timestamp;
	}

	/**
	 * Record a pre-persistence security event for admin diagnostics.
	 *
	 * @param string               $event   Event type.
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Extra context.
	 * @param string               $ip      Client IP.
	 */
	private static function logDropEvent( string $event, string $message, array $context, string $ip ): void {
		try {
			( new FormLogRepository() )->log( 0, $event, $message, $context, 'system', $ip );
		} catch ( \Throwable $e ) {
			Helper::errorLog( '[FormManager] Failed to log ' . $event . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Silent success shape used for bot-suspect submissions.
	 *
	 * @return array{entry_id: int, spam: bool}
	 */
	private static function spamSuspectResult(): array {
		return [
			'entry_id' => 0,
			'spam'     => true,
		];
	}
}
