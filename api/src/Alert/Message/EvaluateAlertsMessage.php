<?php

namespace App\Alert\Message;

/**
 * Periodic trigger (see Schedule.php) that checks for due alert reminders.
 * Carries no payload: everything it needs is read fresh from the database.
 */
final class EvaluateAlertsMessage
{
}
