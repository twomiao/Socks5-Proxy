<?php
namespace Socks5\Protocols\Command;

use Socks5\TcpConnection;

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