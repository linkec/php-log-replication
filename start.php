<?php

use Workerman\Worker;
use Workerman\Timer;

require 'Workerman/Autoloader.php';

require_once __DIR__ . '/src/Server.php';
require_once __DIR__ . '/src/Client.php';
require_once __DIR__ . '/src/Relay.php';

$server = new Replication\Server();

$worker = new Worker();
$worker->count = 1;
$worker->name = 'IO Thread';
$worker->onWorkerStart = function ($worker) {
    $client = new Replication\Client(2, '198.50.168.183');
    $client->allowPull = true;
    $client->connect();
};

$worker3 = new Worker();
$worker3->count = 1;
$worker3->name = 'Logger';
$worker3->onWorkerStart = function ($worker) {
    $relay = new Replication\Relay(2);
    $relay->onLog = function ($data) {
        // var_dump($data);
    };
    $relay->start();
};

Worker::runAll();