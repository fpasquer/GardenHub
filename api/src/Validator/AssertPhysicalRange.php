<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint on Measurement: rejects values outside the
 * physically plausible range configured for the measurement's type.
 * Unknown types (not present in the configured range map) are not
 * constrained here — that is a codec-mapping concern, not a range concern.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AssertPhysicalRange extends Constraint
{
    public string $message = 'The {{ type }} value {{ value }} is outside the physically plausible range ({{ min }} to {{ max }}).';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
