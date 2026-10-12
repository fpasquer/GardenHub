<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use ApiPlatform\Metadata\ApiResource;
use App\Entity\Device;
use App\Entity\Measurement;
use App\Entity\WateringProposal;
use App\Entity\WateringProposalState;
use App\Entity\WateringRun;
use App\Entity\WateringTelegramProgress;
use App\Watering\ActuatorState;
use App\Watering\ProposalDecisionResult;
use App\Watering\WateringNotificationStatus;
use App\Watering\WateringProposalStatus;
use App\Watering\WateringRunStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Validator\Validation;

#[Group('unitest_gardenhub-entity-contracts')]
final class EntityContractTest extends TestCase
{
    #[DataProvider('entities')]
    public function testNamedSerializerValidatorAndApiGroups(string $name, string $groupName): void
    {
        $class = sprintf('App\\Entity\\%s', $name);
        $serializer = new ClassMetadataFactory(new AttributeLoader());
        foreach ($serializer->getMetadataFor($class)->getAttributesMetadata() as $attribute) {
            foreach ($attribute->getGroups() as $group) {
                self::assertMatchesRegularExpression('/^(read|write):[a-z]+(?:_[a-z]+)*$/D', $group);
            }
        }
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $metadata = $validator->getMetadataFor($class);
        $constraints = $metadata->getConstraints();
        foreach ($metadata->getConstrainedProperties() as $property) {
            foreach ($metadata->getPropertyMetadata($property) as $mapping) {
                $constraints = [...$constraints, ...$mapping->getConstraints()];
            }
        }
        foreach ($constraints as $constraint) {
            self::assertSame([sprintf('validate:%s', $groupName)], $constraint->groups);
        }
        foreach ((new \ReflectionClass($class))->getAttributes(ApiResource::class) as $attribute) {
            $resource = $attribute->newInstance();
            self::assertSame([sprintf('read:%s', $groupName)], $resource->getNormalizationContext()['groups']);
            self::assertSame([sprintf('write:%s', $groupName)], $resource->getDenormalizationContext()['groups']);
            self::assertSame([sprintf('validate:%s', $groupName)], $resource->getValidationContext()['groups']);
        }
    }

    #[DataProvider('invalidEntities')]
    public function testNamedValidationRejectsInvalidEntity(string $kind, string $groupName, string $property): void
    {
        $entity = match ($kind) {
            'measurement' => (new Measurement())->setMeasuredAt(new \DateTimeImmutable('+1 day')),
            'run' => (new WateringRun())->setRequestedSeconds(0),
            'proposal' => (new WateringProposal())->setDurationSeconds(0),
            'state' => new WateringProposalState(new Device(), str_repeat('a', 256)),
            'progress' => (new WateringTelegramProgress())->setNextUpdateId(-1),
        };
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $paths = [];
        foreach ($validator->validate($entity, null, [sprintf('validate:%s', $groupName)]) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
        self::assertContains($property, $paths);
    }

    #[DataProvider('enums')]
    public function testEnumValuesAndEntityAliases(string $enum, string $entity, string $prefix, array $expected): void
    {
        self::assertSame($expected, array_column($enum::cases(), 'value'));
        foreach ($enum::cases() as $case) {
            self::assertSame($case->value,
                constant(sprintf('%s::%s%s', $entity, $prefix, strtoupper($case->value))));
        }
    }

    public function testDecisionActuatorAndErrorLengthContracts(): void
    {
        self::assertSame(['ignored', 'executing', 'approved', 'rejected', 'expired', 'invalidated', 'failed', 'uncertain'], array_column(ProposalDecisionResult::cases(), 'value'));
        self::assertSame(['ON', 'OFF'], array_column(ActuatorState::cases(), 'value'));
        self::assertSame(255, WateringRun::ERROR_MAX_LENGTH);
        self::assertSame(255, WateringProposal::FAILURE_MAX_LENGTH);
    }

    public static function entities(): iterable
    {
        foreach (['Device' => 'device', 'Sensor' => 'sensor', 'Measurement' => 'measurement',
            'ApiClient' => 'api_client', 'WateringControl' => 'watering_control',
            'WateringProposal' => 'watering_proposal', 'WateringRun' => 'watering_run',
            'WateringProposalState' => 'watering_proposal_state',
            'WateringTelegramProgress' => 'watering_telegram_progress'] as $class => $group) {
            yield $class => [$class, $group];
        }
    }

    public static function invalidEntities(): iterable
    {
        yield 'future measurement' => ['measurement', 'measurement', 'measuredAt'];
        yield 'zero requested seconds' => ['run', 'watering_run', 'requestedSeconds'];
        yield 'zero proposal duration' => ['proposal', 'watering_proposal', 'durationSeconds'];
        yield 'long actuator topic' => ['state', 'watering_proposal_state', 'actuatorTopic'];
        yield 'negative update ID' => ['progress', 'watering_telegram_progress', 'nextUpdateId'];
    }

    public static function enums(): iterable
    {
        yield 'run' => [WateringRunStatus::class, WateringRun::class, 'STATUS_',
            ['pending', 'running', 'completed', 'uncertain', 'timed_out', 'reviewed']];
        yield 'proposal' => [WateringProposalStatus::class, WateringProposal::class, 'STATUS_',
            ['pending', 'executing', 'approved', 'rejected', 'expired', 'invalidated', 'failed', 'uncertain']];
        yield 'notification' => [WateringNotificationStatus::class, WateringProposal::class, 'NOTIFICATION_',
            ['new', 'sending', 'sent', 'uncertain', 'final']];
    }
}