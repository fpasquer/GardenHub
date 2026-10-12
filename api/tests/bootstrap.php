<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

if ('1' === getenv('GARDENHUB_COVERAGE')) {
	ini_set('memory_limit', '512M');
}