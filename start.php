<?php

require 'Worker.php';

$worker = new Worker();
$worker->count = 2;

$worker->onWorkerStart = function ($worker) {
    echo "worker 已经启动了!" .PHP_EOL;
    return;
};
Worker::runAll();