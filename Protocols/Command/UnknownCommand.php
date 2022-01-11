<?php
namespace Socks5\Protocols\Command;

use Socks5\Protocols\Command\Message\MessageClosed;
use Socks5\TcpConnection;

class UnknownCommand extends AbstractCommand
{
    const COMMAND = 'unknown';

    public function run(string $buffer, TcpConnection $connection): int
    {
        var_dump('未知命令关闭');
        $connection->close(new MessageClosed(Command::COMMAND_UNKNOWN, '0.0.0.0', '0'));
        return 0;
    }
}