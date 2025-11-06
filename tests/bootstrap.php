<?php

declare(strict_types=1);

// Composer autoloader
use yii\console\Application;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Define Yii2 aliases and constants
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

// Load Yii class
require_once dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

// Set up minimal Yii application for testing
// This is needed because Yii2 components use Yii::createObject() and other Yii features
$app = new Application([
    'id' => 'yii2-tiered-cache-tests',
    'basePath' => dirname(__DIR__),
    'vendorPath' => dirname(__DIR__) . '/vendor',
    'components' => [],
]);

