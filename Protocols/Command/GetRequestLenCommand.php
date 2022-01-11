<?php
namespace Socks5\Protocols\Command;

use Socks5\Protocols\Command\Message\MessageClosed;
use Socks5\TcpConnection;
use Socks5\Worker;

/**
 * 获取代理客户端数据长度
 * Class GetRequestLenAbstractCommand
 * @package Socks5\Server\Protocols\AbstractCommand
 */
class GetRequestLenCommand extends AbstractCommand
{
    const COMMAND = 'get_request_len';

    public function run(string $buffer, TcpConnection $connection): int
    {
        Worker::debug($connection->getRemoteAddress() . " 执行客户端代理请求命令");

        if (strlen($buffer) < 7) {
            $connection->close(new MessageClosed(Command::COMMAND_SERVER_ERROR, '0.0.0.0', '0'));
            return 0;
        }

        $len = 3;
        $addr_type = ord($buffer[$len]);
        $len++;
        switch ($addr_type) {
            case AddressGet::IPV4:
                $len += 4;
                $connection->host_len = 4;
                $connection->addr_type = $addr_type;
                break;
            case AddressGet::IPV6:
                $len += 16;
                $connection->host_len = 16;
                $connection->addr_type = $addr_type;
                break;
            case AddressGet::DNS:
                $host_len = ord($buffer[$len]);
                $len++;
                $connection->host_len = $host_len;
                $connection->addr_type = $addr_type;
                $len += $host_len;
                break;
            default:
                $connection->close(new MessageClosed(Command::COMMAND_SERVER_ERROR, '0.0.0.0', '0'));
                return 0;
        }
        $len += 2;

        if(strlen($buffer) < $len) {
            $connection->close(new MessageClosed(Command::COMMAND_SERVER_ERROR, '0.0.0.0', '0'));
            return 0;
        }
        return $len;
    }
}