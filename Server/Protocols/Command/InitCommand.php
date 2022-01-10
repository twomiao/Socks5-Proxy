<?php

namespace Socks5\Server\Protocols\Command;

use Socks5\Server\Protocols\Socks5;
use Socks5\Server\TcpConnection;
use Socks5\Server\Worker;

/**
 * 初始化命令
 * Class InitAbstractCommand
 * @package Socks5\Server\Protocols\AbstractCommand
 */
class InitCommand extends AbstractCommand
{
    const COMMAND = 'init';

    public function run(string $buffer, TcpConnection $connection): int
    {
        Worker::debug("init command");

        if (strlen($buffer) < 2) // 字节长度不足.无法正确解析
        {
            $connection->close("\x05\xff", true);
            return 0;
        }

        // Socks5协议版本
        $offset = 0;
        $sock_ver = ord($buffer[$offset]);
        $offset++;
        // 认证方法列表
        $method_count = ord($buffer[$offset]);
        $offset++;
        if (strlen($buffer) !== ($offset + $method_count)) {
            $connection->close("\x05\xff", true);
            return 0;
        }

        // 认证字节数组
        $auth_list = [];
        for ($i = 1; $i <= $method_count; $i++) {
            $auth_list[] = ord($buffer[$offset]);
            $offset++;
        }

        // 代理服务器支持的认证方式
        foreach (Socks5::AUTHS as $method) {
            if (in_array($method, $auth_list, true)) {
                Worker::debug("初始化客户端认证方式");
                $connection->state = PasswordAuthCommand::COMMAND;
                $connection->send("\x05" . chr($method), true);
                $connection->clearReaderBuffer();
                return 0;
            }
        }
        Worker::debug("不支持客户端认证方式");
        $connection->close("\x05\xff", true);
        return 0;
    }
}