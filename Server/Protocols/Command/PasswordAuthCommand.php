<?php
namespace Socks5\Server\Protocols\Command;

use Socks5\Server\TcpConnection;
use Socks5\Server\Worker;

class PasswordAuthCommand extends AbstractCommand
{
    /**
     * @var int
     */
    const COMMAND = 'password_auth';

    public function run(string $buffer, TcpConnection $connection): int
    {
        if (strlen($buffer) < 5) {
            $connection->close("\x01\x01", true);
            return 0;
        }

        // 子协议版本
        $offset = 0;
        $sub_ver = ord($buffer[$offset]);
        if (strlen($buffer) < 2) {
            $connection->close($sub_ver."\x01", true);
            return 0;
        }
        $offset++;
        $user_len = ord($buffer[$offset]);

        if (strlen($buffer) < $offset + $user_len) {
            $connection->close($sub_ver."\x01", true);
            return 0;
        }
        $offset++;
        $username = substr($buffer, $offset, $user_len);

        $offset += $user_len;
        $offset++;
        $password_len = ord($buffer[$offset]);
        $password = substr($buffer, $offset, $password_len);

        Worker::debug($connection->getClientIP() . ":" . $connection->getClientPort() . " 登录账号 'username:{$username}, password:{$password}'");

        $auth = $connection->onSocks5Auth;

        // 认证失败
        if ($auth($username, $password)) {
            Worker::debug("'{$username}' 认证成功!");
            $connection->send($sub_ver."\x00", true);
            $connection->state = GetRequestLenCommand::COMMAND;
            $connection->clearReaderBuffer();
        } else {
            Worker::debug("'{$username}' 认证失败!");
            $connection->close($sub_ver."\x01", true);
        }
        return 0;
    }
}