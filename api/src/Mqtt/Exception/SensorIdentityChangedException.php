<?php

namespace App\Mqtt\Exception;

/**
 * Thrown when a sensor's identity changed between resolution and locking so
 * Messenger redelivers the uplink and re-resolves against current data.
 */
final class SensorIdentityChangedException extends \RuntimeException
{
}
