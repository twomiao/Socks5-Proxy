<?php
namespace Socks5\Server\Protocols\Command;

use Socks5\Server\TcpConnection;

abstract class AbstractCommand
{
    const COMMAND = 'init';

    /**
     * 执行客户端命令
     * @param string $buffer
     * @param TcpConnection $connection
     * @return mixed
     */
    public abstract function run(string $buffer, TcpConnection  $connection) :int;
}