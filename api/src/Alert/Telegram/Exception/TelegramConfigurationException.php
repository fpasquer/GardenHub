<?php

namespace App\Alert\Telegram\Exception;

/**
 * Thrown when Telegram alerts are enabled but required configuration
 * (bot token / chat id) is missing. Never includes secret values.
 */
final class TelegramConfigurationException extends \RuntimeException
{
}
