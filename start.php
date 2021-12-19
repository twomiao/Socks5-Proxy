<?php
require __DIR__ . '/vendor/autoload.php';

$worker = new \Swoman\Server\Worker('tcp://127.0.0.1:19000');

$worker->onWorkerStart = function (\Swoman\Server\Worker $worker)
{
    // 加载Symfony Laravel ThinkPHP 等Web框架
    // $app = new \think\App();
    // $worker->onMessage = [$app, 'onMessage'];

    // 推荐: 使用Workerman 作者开发的 Webman https://gitee.com/walkor/webman 框架
};

$worker->onMessage = function (\Swoman\Server\TcpConnection $connection, $buffer)
{
    // 处理http 请求逻辑，调用控制器和方法(路由)，返回给客户端数据
    // 不过我未实现http1.1协议
  $connection->send("HTTP/1.1 200 OK
Server: Socks5-Proxy HttpServer
Connection: keep-alive
Content-Type: text/html;charset=utf-8
Content-Length: 12\r\n
".str_repeat('Hello,Socks5-Proxy', 400));
};
$worker->name = 'Socks5-Proxy Http Server';
$worker->user = 'meows';
$worker->group = 'meows';
$worker->count = 0;
$worker->start();
