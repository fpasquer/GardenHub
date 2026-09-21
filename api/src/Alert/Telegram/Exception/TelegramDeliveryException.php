<?php

namespace App\Alert\Telegram\Exception;

/**
 * A retryable Telegram delivery failure (timeout, 429, 5xx, transport
 * error). Left as a plain exception so the telegram_notifications
 * transport's own retry_strategy applies; not an UnrecoverableException.
 */
final class TelegramDeliveryException extends \RuntimeException
{
}
