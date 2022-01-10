<?php
namespace Socks5\Protocols\Command;

use Socks5\TcpConnection;

class UnknownCommand extends AbstractCommand
{
    const COMMAND = 'unknown';

    public function run(string $buffer, TcpConnection $connection): int
    {
        var_dump('未知命令关闭');
        var_dump($buffer);
        $sock = new SockMessage(Command::COMMAND_UNKNOWN, '0.0.0.0', '0');
        $sock->setAddressType(SockMessage::IPV4);
        $connection->close($sock);
        return 0;
    }
}