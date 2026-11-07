<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Monolog\Handler\TelegramHandler;
use App\Tests\Monolog\FakeTelegramTransport;
use App\Tests\Monolog\RecordingLogger;
use Monolog\Level;
use Monolog\LogRecord;

$transport = new FakeTelegramTransport();
$handler = new TelegramHandler(
    $transport, true, 'synthetic', 'synthetic', 'GardenHub', 'test',
    new RecordingLogger(), 'warning',
);
$handler->handle(new LogRecord(
    new DateTimeImmutable(), 'telegram', Level::Warning, 'coverage probe',
));
echo json_encode(['calls' => count($transport->calls)], JSON_THROW_ON_ERROR);