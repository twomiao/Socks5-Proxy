## Swoman Socks5
Workerman 设计思想，使用Swoole 扩展实现，命名为“Swoman”; <br/>
**仅实现任意TCP流量代理**，采用SOCKS5 协议实现，故“Swoman Socks5”。

## 未知力量的渴望：
- 为了探索Workerman 实现原理，使用Swoole4 + Pcntl 实现核心功能。
- 为了更方便掌握原理，实现的单实例单端口多进程高性能服务器、不支持单进程多端口，当然这并不影响，掌握它的原理。
- 为了更方便解决实际遇见的问题，拥有改造Workerman的能力。

## Socks5 协议
> SOCKS是一种网络传输协议，主要用于客户端与外网服务器之间通讯的中间传递。<br/>
> 当客户端要访问外部的服务器时，就跟SOCKS代理服务器连接。这个代理服务器控制客户端访问外网的资格。<br/>
> SOCKS5支持TCP和UDP应用。<br/>
> 但是由于SOCKS5还支持各种认证机制和域名解析（DNS）也就是说，SOCKS5可以支持SOCKS4支持的任何东西，但它与SOCKS4不一样。<br/>

## 安装使用?
**1.** 安装扩展pcntl+swoole<br/>
**2.** 个人电脑安装SOCKS5客户端， “Proxifier”即可，将全部流量转发给服务器。<br/>
**3.** php start_socks5.php start 启动代理服务<br/>

## Socks5 Run??：
    <?php
    require __DIR__ . '/vendor/autoload.php';
    
    use Socks5\Server\Protocols\Command\Command;
    use Socks5\Server\Protocols\Socks5;
    use Socks5\Server\Worker;
    use Socks5\Server\TcpConnection;
    use Socks5\Server\Protocols\Command\InitCommand;
    use Socks5\Server\Protocols\Command\Message\MessageClosed;
    use Socks5\Server\Protocols\Command\Message\MessageSock;
    use Socks5\Server\Connection\AsyncTcpConnection;
    
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
    //    Worker::debug($connection->getClientIP() . ":" . $connection->getClientPort() . " 客户端代理数据 " . $message);
        $message = json_decode($message, true);
        if (!$message) {
            $connection->close(
                new MessageClosed(Command::COMMAND_SERVER_ERROR, '0.0.0.0', '0')
            );
            return;
        }
        var_dump($message);
    
        switch ($command = $message['command']) {
            case 'CONNECT':
                $proxyClient = new AsyncTcpConnection(
                    "tcp://" . $message["dest_address"] . ":" . $message["port"]
                );
                $proxyClient->onConnect = function ($proxyClient) use ($connection, $message) {
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
    
                    $connection->onMessage = function (TcpConnection $connection, $data) use ($proxyClient) {
                        $proxyClient->send($data);
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

### License

Apache License Version 2.0, http://www.apache.org/licenses/
