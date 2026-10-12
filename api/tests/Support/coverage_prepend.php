<?php

declare(strict_types=1);

if (in_array(basename($_SERVER['SCRIPT_FILENAME'] ?? ''),
	['index.php', 'console'], true)) {
	$_SERVER['APP_RUNTIME'] = 'App\\Tests\\Support\\CoverageRuntime';

	return;
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

App\Tests\Support\ChildCoverage::start();