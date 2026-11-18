<?php
/**
 * Notification Channel Interface
 *
 * Contract for all notification channels (Email, Telegram, Viber, etc.)
 *
 * @package HDForm\Notification
 */

declare(strict_types=1);

namespace HDForm\Notification;

defined( 'ABSPATH' ) || exit;

interface NotificationChannelInterface {

	/**
	 * Unique channel slug (e.g. 'email', 'telegram', 'viber').
	 *
	 * @return string
	 */
	public function getSlug(): string;

	/**
	 * Send notification for a form submission.
	 *
	 * @param NotificationMessage $message Channel-agnostic message DTO.
	 *
	 * @return bool True if sent/queued successfully.
	 */
	public function send( NotificationMessage $message ): bool;
}
