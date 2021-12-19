<?php
require __DIR__ . '/vendor/autoload.php';
use Swoman\Server\Protocols\Socks5;
use Swoman\Server\Worker;
use Swoman\Server\TcpConnection;
use Swoman\Server\RequestState;

Worker::$onMasterStart = function() {
    echo " ____             _        ____        ____
/ ___|  ___   ___| | _____| ___|      |  _ \ _ __ _____  ___   _
\___ \ / _ \ / __| |/ / __|___ \ _____| |_) | '__/ _ \ \/ / | | |
 ___) | (_) | (__|   <\__ \___) |_____|  __/| | | (_) >  <| |_| |
|____/ \___/ \___|_|\_\___/____/      |_|   |_|  \___/_/\_\\__, |
                                                           |___/\n\n";
    Worker::debug('Started Master worker.');
};

Worker::$processTitle = 'Socks5-Proxy';
$worker = new Worker('tcp://0.0.0.0:19000');
$worker->layerProtocol = Socks5::class;
//$worker->name = 'Socks5-Proxy';
$worker->user = 'meows';
$worker->group = 'meows';
$worker->count = 4;

$worker->onWorkerStart = function () {
    Worker::debug('Started Worker.');
};

$worker->onConnect = function(TcpConnection $connection) {
    if (!isset($connection->state)) {
        $connection->state = RequestState::INIT;
    }
    $connection->onSocks5Auth = function($username, $password) {

    };

};

$worker->onMessage = function (TcpConnection $connection, $message) {


};
//$worker->start();