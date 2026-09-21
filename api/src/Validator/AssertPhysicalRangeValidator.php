<?php

namespace App\Validator;

use App\Entity\Measurement;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class AssertPhysicalRangeValidator extends ConstraintValidator
{
    /**
     * @param array<string, array{0: float, 1: float}> $ranges Measurement type => [min, max]
     */
    public function __construct(
        private readonly array $ranges,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$value instanceof Measurement || !$constraint instanceof AssertPhysicalRange) {
            return;
        }

        $type = $value->getType();
        $measured = $value->getValue();
        if (null === $type || null === $measured || !isset($this->ranges[$type])) {
            return;
        }

        [$min, $max] = $this->ranges[$type];
        if ($measured >= $min && $measured <= $max) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ type }}', $type)
            ->setParameter('{{ value }}', (string) $measured)
            ->setParameter('{{ min }}', (string) $min)
            ->setParameter('{{ max }}', (string) $max)
            ->addViolation();
    }
}
