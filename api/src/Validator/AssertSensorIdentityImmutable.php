<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Advisory, non-locking pre-check that a Sensor's device/type/unit are
 * unchanged once it has recorded measurements. See AssertSensorIdentityImmutableValidator.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AssertSensorIdentityImmutable extends Constraint
{
    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
