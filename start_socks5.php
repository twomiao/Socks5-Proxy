<?php
require __DIR__ . '/vendor/autoload.php';

use Socks5\Protocols\Command\Command;
use Socks5\Protocols\Socks5;
use Socks5\Worker;
use Socks5\TcpConnection;
use Socks5\Protocols\Command\InitCommand;
use Socks5\Protocols\Command\Message\MessageClosed;
use Socks5\Protocols\Command\Message\MessageSock;
use Socks5\Connection\AsyncTcpConnection;

Worker::$onMasterStart = function () {
    echo " ____                                       ____             _          ____
/ ___|_      _____  _ __ ___   __ _ _ __   / ___|  ___   ___| | _____  | ___|
\___ \ \ /\ / / _ \| '_ ` _ \ / _` | '_ \  \___ \ / _ \ / __| |/ / __| |___ \
 ___) \ V  V / (_) | | | | | | (_| | | | |  ___) | (_) | (__|   <\__ \  ___) |
|____/ \_/\_/ \___/|_| |_| |_|\__,_|_| |_| |____/ \___/ \___|_|\_\___/ |____/
\n{ Socks5 } 多进程、高性能Socks5代理服务器\n";
    Worker::debug('Master started success.');
};

Worker::$processTitle = 'Socks5-Server';
$worker = new Worker('tcp://0.0.0.0:1090');
$worker->layerProtocol = Socks5::class;
$worker->user = 'meows';
$worker->group = 'meows';
$worker->count = 16;

$worker->onWorkerStart = function () {
    Worker::debug('Worker started success.');
};

$worker->onConnect = function (TcpConnection $connection) {
    if (!isset($connection->state)) {
        $connection->state = InitCommand::COMMAND;
    }
    $connection->onSocks5Auth = function ($username, $password) {
        return $password === 'pass';
    };
};

$worker->onMessage = function (TcpConnection $connection, $message) {
    Worker::debug($connection->getClientIP() . ":" . $connection->getClientPort() . " -> " . $message);
    $message = json_decode($message, true);
    if (!$message) {
        $connection->close(
            new MessageClosed(Command::COMMAND_SERVER_ERROR, '0.0.0.0', '0')
        );
        return;
    }
//    var_dump($message);

    switch ($command = $message['command']) {
        case 'CONNECT':
            $proxyClient = new AsyncTcpConnection(
                "tcp://" . $message["dest_address"] . ":" . $message["port"]
            );
            $proxyClient->onConnect = function (AsyncTcpConnection $proxyClient) use ($connection, $message) {
                $connection->send(
                    new MessageSock(
                        Command::COMMAND_CONNECT_SUCCESS,
                        $connection->getClientIP(),
                        $connection->getClientPort(),
                        (int)$message['addr_type'],
                    )
                );

                $proxyClient->onMessage = function (AsyncTcpConnection $proxyClient, $data) use ($connection) {
                    $connection->send($data);
                };

                $proxyClient->onBufferFull = function (AsyncTcpConnection $proxyClient) {
                    $proxyClient->pauseRecv();
                };

                $proxyClient->onBufferDrain = function (AsyncTcpConnection $proxyClient) {
                    $proxyClient->resumeRecv();
                };

                $connection->onMessage = function (TcpConnection $connection, $data) use ($proxyClient) {
                    $proxyClient->send($data);
                };
                $connection->onBufferFull = function (TcpConnection $connection) {
                    $connection->pauseRecv();
                };
                $connection->onBufferDrain = function (TcpConnection $connection) {
                    $connection->resumeRecv();
                };

                $connection->onClose = function ($connection) use ($proxyClient) {
                    $proxyClient->close();
                };

                $proxyClient->onClose = function ($proxyClient) use ($connection) {
                    $connection->close();
                };
            };

            $proxyClient->connect();
            break;
        default:
            Worker::debug("[ {$command} ] 未知命令.");
            $connection->close(
                new MessageClosed(Command::COMMAND_UNKNOWN, '0.0.0.0', '0')
            );
            break;
    }
};

$worker->onClose = function () {
    var_dump('关闭连接.');
};

$worker->start();
