<?php
require __DIR__ . '/vendor/autoload.php';

$server = new \Swoman\Server\Worker('tcp://127.0.0.1:19000');

$server->onWorkerStart = function ()
{
//  throw new Exception('测试');
};

$server->onMessage = function (\Swoman\Server\TcpConnection $connection, $buffer)
{
  $connection->send("HTTP/1.1 200 OK
Server: Swoman
Connection: keep-alive
Content-Type: text/html;charset=utf-8
Content-Length: 12\r\n
".str_repeat('Hello,Swoman', 400));
};
$server->name = 'meows';
$server->group = 'meows';
$server->count = 0;
$server->start();
