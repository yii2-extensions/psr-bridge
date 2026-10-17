<?php

declare(strict_types=1);

use function yii2\extensions\psrbridge\tests\support\scenario\run;

defined('YII_DEBUG') || define('YII_DEBUG', false);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/WorkerScenarios.php';

$sessionDirectory = $argv[2] ?? throw new RuntimeException('Missing fixture session directory.');

mkdir($sessionDirectory, 0o700, true);
ini_set('session.save_path', $sessionDirectory);

$GLOBALS['shutdown-file'] = $sessionDirectory . '/shutdown.txt';

echo json_encode(run($argv[1]), JSON_THROW_ON_ERROR);
