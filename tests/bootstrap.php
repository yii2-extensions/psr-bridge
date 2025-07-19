<?php

declare(strict_types=1);

error_reporting(-1);

defined('YII_DEBUG') || define('YII_DEBUG', true);
define('YII_ENV', 'test');

// require composer autoloader if available
require(__DIR__ . '/../vendor/autoload.php');
require(__DIR__ . '/../vendor/yiisoft/yii2/Yii.php');

$_SERVER['SCRIPT_NAME'] = '/' . __DIR__;
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
