<?php
/* This program is free software. It comes without any warranty, to
 * the extent permitted by applicable law. You can redistribute it
 * and/or modify it under the terms of the Do What The Fuck You Want
 * To Public License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

ini_set('display_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('America/New_York');
require(__DIR__ . '/lib/SplClassLoader.php');

$classLoader = new SplClassLoader('WebSocket', __DIR__ . '/lib');
$classLoader->register();

//$server = new \WebSocket\Server('10.195.109.238', 8000, false);
$server = new \WebSocket\Server('127.0.0.1', 8000, false);

// server settings:
$server->setMaxClients(100);
$server->setCheckOrigin(false);
//$server->setCheckOrigin(true);
//$server->setAllowedOrigin('skalon.com');
$server->setMaxConnectionsPerIp(100);
$server->setMaxRequestsPerMinute(2000);

// Hint: Status application should not be removed as it displays useful server information.
//$server->registerApplication('status', \WebSocket\Application\StatusApplication::getInstance());
$server->registerApplication('qub', \WebSocket\Application\QubApplication::getInstance());

$server->run();