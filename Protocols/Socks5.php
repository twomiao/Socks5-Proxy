<?php

namespace Socks5\Protocols;

use Socks5\Protocols\Command\AddressGet;
use Socks5\Protocols\Command\Command;
use Socks5\Protocols\Command\Message\MessageClosed;
use Socks5\Protocols\Command\Message\MessageSock;
use Socks5\Protocols\Command\RegisterRunner;
use Socks5\TcpConnection;

class Socks5 implements ProtocolInterface
{
    /**
     * @var int
     */
    public const AUTHS = array(
//        'NO_AUTH' => 0x00,
        'PASSWORD_AUTH' => 0x02
    );

    /**
     * @param $buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function input($buffer, TcpConnection $connection): int
    {
//        Worker::debug($connection->getClientIP() . ":" . $connection->getClientPort() . " 发送数据 '" . bin2hex($buffer) . "'");

        return RegisterRunner::run($buffer, $connection);
    }

    /**
     * @param MessageSock $buffer
     * @param TcpConnection $connection
     * @return string
     */
    public static function encode($buffer, TcpConnection $connection): string
    {
        return $buffer->respSock();
    }

    public static function decode($buffer, TcpConnection $connection): string
    {
        // sock
        $offset = 0;
        $sock_ver = ord($buffer[$offset]);
        $offset++;

        // 命令
        $command = ord($buffer[$offset]);
        $command = (string)new Command($command);
        $offset++;

        // 保留字段
        $rsv = ord($buffer[$offset]);
        $offset++;

        // 获取目标地址
        $offset++;
        $address = new AddressGet($buffer, $offset, $connection);
        $address_type = (string)$address;
        if (empty($dest_address = $address->getAddr()))
        {
            $connection->close(
                new MessageClosed(Command::COMMAND_SERVER_ERROR, '0.0.0.0', '0')
            );
            return '';
        }
        $addr_type = $address->getAddrType();

        // 目标端口
        $port = unpack('nport', substr($buffer, $address->offset, 2))['port'];

        // onMessage 回调数据
        $data = json_encode
        (
            compact('sock_ver', 'command', 'rsv', 'address_type', 'addr_type', 'dest_address', 'port'),
            JSON_UNESCAPED_UNICODE
        );

        $connection->setLayerProtocol();

        return $data;

    }
}