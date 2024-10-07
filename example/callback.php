<?php

require_once __DIR__ . '/../vendor/autoload.php'; // Adjust the path as necessary to point to your Composer autoload file

use Katorymnd\PawaPayIntegration\Callbacks\HandleCallback;

$callbackHandler = new HandleCallback();
$callbackHandler->processCallback();