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
use HDForm\Integrations\MultilingualIntegration;
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
		$config = FormConfig::all();

		// 1.0. IP Block List check (silent drop).
		if ( ! empty( $config['spam']['ip_block_enabled'] ) ) {
			$blockList = is_array( $config['spam']['ip_block_list'] ?? null ) ? $config['spam']['ip_block_list'] : [];
			if ( ! empty( $blockList ) && SpamChecker::isBlockedIp( $ip, $blockList ) ) {
				self::logDropEvent(
					'ip_blocked',
					'Blocked IP submission dropped.',
					[ 'form_type' => sanitize_key( (string) ( $input['form_type'] ?? '' ) ) ],
					$ip
				);

				return self::spamSuspectResult();
			}
		}

		// 1. Honeypot check (before validation to fast-fail bots).
		$honeypotEnabled = ! isset( $config['spam']['honeypot_enabled'] ) || ! empty( $config['spam']['honeypot_enabled'] );
		if ( $honeypotEnabled ) {
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
				self::logDropEvent(
					'render_expired',
					'Stale honeypot token accepted; submission continued.',
					[ 'form_type' => sanitize_key( (string) ( $input['form_type'] ?? '' ) ) ],
					$ip
				);
			}
		}

		// 1.2. User Interaction Telemetry — advisory only.
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

		// 2.5. Keyword / Word Blacklist check (silent drop if submission contains banned words).
		$wordBlacklist = is_array( $config['spam']['word_blacklist'] ?? null ) ? $config['spam']['word_blacklist'] : [];
		if ( ! empty( $wordBlacklist ) && SpamChecker::hasBlacklistedWord( $validated, $wordBlacklist ) ) {
			self::logDropEvent(
				'word_blacklisted',
				'Blacklisted keyword detected; submission dropped.',
				[ 'form_type' => $formType ],
				$ip
			);

			return self::spamSuspectResult();
		}

		// 3. Verify CAPTCHA (before file upload to prevent bot orphan files).
		$captchaError = $this->verifyCaptcha( $formType, $validated['captchaToken'], $ip );
		if ( is_wp_error( $captchaError ) ) {
			return $captchaError;
		}

		// 3.5. Duplicate submission detection.
		$claimKey   = '';
		$dupeHash   = '';
		$dupeConfig = $config['duplicate_prevention'] ?? [];
		if ( is_bool( $dupeConfig ) ) {
			$dupeConfig = [ 'enabled' => $dupeConfig ];
		}
		$dupeEnabled = ! is_array( $dupeConfig ) || ! isset( $dupeConfig['enabled'] ) || ! empty( $dupeConfig['enabled'] );

		if ( $dupeEnabled ) {
			$checkMode     = is_array( $dupeConfig ) ? (string) ( $dupeConfig['check_mode'] ?? 'email_phone' ) : 'email_phone';
			$windowMinutes = is_array( $dupeConfig ) ? max( 1, (int) ( $dupeConfig['window_minutes'] ?? 30 ) ) : 30;
			$dupeHash      = DuplicateGuard::hash( $ip, $formType, $validated, $checkMode, $windowMinutes );
			$claimKey      = DuplicateGuard::claimKey( $dupeHash );

			if ( ! DuplicateGuard::claim( $claimKey, $windowMinutes ) ) {
				DuplicateGuard::logDrop( $formType, $validated, $dupeHash, $ip );

				$actionMode = is_array( $dupeConfig ) ? (string) ( $dupeConfig['action_mode'] ?? 'silent_success' ) : 'silent_success';
				if ( 'error_message' === $actionMode ) {
					$customMsg = is_array( $dupeConfig ) ? trim( (string) ( $dupeConfig['custom_message'] ?? '' ) ) : '';
					$targetLng = MultilingualIntegration::resolveLanguage( $input );
					$message   = '' !== $customMsg
						? MultilingualIntegration::translate( $customMsg, $targetLng, 'hd-form', 'form_duplicate_custom_message' )
						: __( 'A submission with this content was already received recently.', 'hd-form' );
					$message   = (string) apply_filters( 'hd_form_duplicate_error_message', $message, $formType, $input );

					return new \WP_Error(
						'duplicate_submission',
						$message,
						[ 'status' => 429 ]
					);
				}

				return [
					'entry_id' => 0,
					'spam'     => false,
				];
			}
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

		try {
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

			$rawPageUrl = $input['page_url'] ?? '';
			$pageUrl    = is_string( $rawPageUrl ) ? sanitize_url( $rawPageUrl ) : '';

			$entry = new FormEntry(
				formType:       $formType,
				formId:         $validated['formId'],
				name:           $validated['name'],
				email:          $validated['email'],
				phone:          $validated['phone'],
				phoneCountry:   '',
				phoneNational:  '',
				ipAddress:      $ip,
				userAgent:      $userAgent,
				refererUrl:     $referer,
				pageUrl:        $pageUrl,
				data:           $entryData,
				submissionHash: $dupeHash,
				utmSource:      $utm['source'] ?? '',
				utmMedium:      $utm['medium'] ?? '',
				utmCampaign:    $utm['campaign'] ?? '',
				utmTerm:        $utm['term'] ?? '',
				utmContent:     $utm['content'] ?? '',
				userId:         get_current_user_id(),
			);

			// 6. Spam check.
			$spamOverride = null !== FormConfig::getFormType( $formType )
				? null
				: ( $input['spam_check'] ?? null );

			$spamReasons = SpamChecker::checkCheap( $entry, $spamOverride );
			$isSpam      = ! empty( $spamReasons );

			// 6.5. Filter entry DTO before database insertion (e.g. attach submission language).
			$preparedEntry = apply_filters( 'hd_form_prepare_entry', $entry, $formType, $validated, $input );
			if ( $preparedEntry instanceof FormEntry ) {
				$entry = $preparedEntry;
			}

			// 7. Save to database.
			$repo    = new FormEntryRepository();
			$status  = $isSpam ? FormEntryStatus::Spam->value : FormEntryStatus::New->value;
			$entryId = $repo->insert( $entry, $status );

			if ( is_wp_error( $entryId ) ) {
				self::rollbackSubmission( $claimKey, $uploadedFiles );

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
		} catch ( \Throwable $e ) {
			self::rollbackSubmission( $claimKey, $uploadedFiles );
			throw $e;
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
			$this->dispatchNotifications( $entryId, $entry, $logRepo );
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
	 * @param int               $entryId The saved entry ID.
	 * @param FormEntry         $entry   The form entry DTO.
	 * @param FormLogRepository $logRepo Log repository instance.
	 */
	private function dispatchNotifications( int $entryId, FormEntry $entry, FormLogRepository $logRepo ): void {
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
	 * Rollback claims and uploaded files on submission failure.
	 *
	 * @param string               $claimKey      Claim key to release.
	 * @param array<string, mixed> $uploadedFiles Uploaded files map to purge.
	 */
	private static function rollbackSubmission( string $claimKey, array $uploadedFiles ): void {
		if ( '' !== $claimKey ) {
			DuplicateGuard::release( $claimKey );
		}
		if ( ! empty( $uploadedFiles ) ) {
			FileUploadHandler::cleanupFiles( $uploadedFiles );
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
