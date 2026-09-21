<?php

namespace App\Validator;

use App\Entity\Sensor;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class AssertSensorIdentityImmutableValidator extends ConstraintValidator
{
    public function __construct(
        private readonly SensorIdentityChecker $checker,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$value instanceof Sensor) {
            return;
        }

        foreach ($this->checker->violations($value) as $violation) {
            $this->context->buildViolation($violation->getMessage())
                ->atPath($violation->getPropertyPath())
                ->addViolation();
        }
    }
}
