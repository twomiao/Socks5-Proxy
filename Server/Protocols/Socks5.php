<?php
namespace Socks5\Server\Protocols;

use Socks5\Server\TcpConnection;
use Socks5\Server\RequestState;
use Socks5\Server\Worker;

class Socks5 implements ProtocolInterface
{
    /**
     *  不需要认证（常用）
     * @var int
     */
    protected const PASSWORD_NO = 0x00;

    /**
     * 账号密码认证（常用）
     * @var int
     */
    protected const PASSWORD_YES = 0x02;

    /*  METHOD定义
     *  0x00 不需要认证（常用）
     *  0x01 GSSAPI认证
     *  0x02 账号密码认证（常用）
     *  0x03 - 0x7F IANA分配
     *  0x80 - 0xFE 私有方法保留
     *  0xFF 无支持的认证方法
     */
    /**
     * 仅支持的认证方式
     * @var array|int[]
     */
    protected static array $_serverMethods = [self::PASSWORD_NO, self::PASSWORD_YES];

    /**
     * @param $buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function input($buffer, TcpConnection $connection): int
    {
        Worker::debug("recv buffer:" . bin2hex($buffer));
        switch ($connection->state) {
            case RequestState::INIT:
                // 3. 认证过程
                if (strlen($buffer) < 2) {
                    Worker::debug("init socks5 fail: The data packet is less than 2 bytes.");
                    $connection->close('\x05\xff', true);
                    return 0;
                }
                $offset = 0;
                //  SOCKS协议版本，目前固定0x05
                $sock_ver = ord($buffer[$offset]);
                $offset++;

                // 客户端支持的认证方法数量
                $methods_count = ord($buffer[$offset]);
                $offset++;

                $buffer_size = $offset + $methods_count;
                $buffer_len = strlen($buffer);

                if ($buffer_len !== $buffer_size) {
                    Worker::debug("init socks5 fail: {$buffer_size} bytes is not equal to {$buffer_len} bytes.");
                    $connection->close('\x05\xff', true);
                    return 0;
                }
                //  客户端认证方式
                $client_methods = ord($buffer[$offset]);
                for ($i = 0; $i < $methods_count; $i++) {
                    $client_method = $client_methods[$i];
                    if (in_array($client_method, self::$_serverMethods)) {
                        Worker::debug("init socks5 methods: " . bin2hex('\x05' . chr($client_method)));

                        if ($client_method === self::PASSWORD_NO) {
                            $connection->state = RequestState::PASSWORD_NO;
                        } else {
                            $connection->state = RequestState::PASSWORD_YES;
                        }
                        $connection->send("\x05" . $connection->state, true);
                        if (!isset($connection->auth_type)) {
                            $connection->auth_type = $client_method;
                        }
                        return 0;
                    }
                }

                Worker::debug("init socks5 methods: Failed to match authorization method");
                $connection->close("\x05\xff");
                Worker::debug("init socks5 methods: " . "send " . bin2hex("\x05\xff"));
                return 0;
            case RequestState::PASSWORD_YES:
                // 3.2.3 客户端发送账号密码
                // 务端返回的认证方法为0x02(账号密码认证)时，客户端会发送账号密码数据给代理服务器
                $buffer_size = strlen($buffer);

                if ($buffer_size < 5) {
                    $connection->close("\x05\xff", true);
                    Worker::debug("Authentication failed socks5: The authentication form is less than 5 bytes.");
                    return 0;
                }
                switch ($connection->auth_type) {
                    case RequestState::PASSWORD_YES:
                        $offset = 0;

                        // 子协议版本
                        $request['ver'] = ord($buffer[$offset]);
                        $offset++;

                        // 用户名长度
                        $request['user_len'] = ord($buffer[$offset]);
                        $offset++;

                        if ($buffer_size < $request['user_len'] + 4) {
                            $connection->close("\x01\x01", true);
                            Worker::debug("Authentication failed socks5: buffer too short.");
                            return 0;
                        }

                        // 用户名
                        $request['username'] = substr($buffer, $offset, $request['user_len']);
                        $offset += $request['user_len'];

                        $request['password_len'] = ord($buffer[$offset]);
                        $offset++;

                        if ($buffer_size < $offset + $request['password_len']) {
                            $connection->close("\x01\x01", true);
                            Worker::debug("Authentication failed socks5: buffer too short.");
                            return 0;
                        }

                        // 密码
                        $request['password'] = substr($buffer, $offset, $request['password_len']);
                        $offset += $request['password_len'];

                        // 认证
                        if (!isset($connection->onSocks5Auth)) {
                            $connection->close("\x01\x01", true);
                            Worker::debug("Authentication failed socks5: No authentication callback function defined.");
                            return 0;
                        }

                        try {
                            $pass = call_user_func($connection->onSocks5Auth, $request['username'], $request['password']);
                            if (!is_bool($pass)) {
                                throw new \Exception("Password '{$request['username']}' authentication failed.");
                            }
                            if (!$pass) {
                                throw new \Exception("Password '{$request['username']}' authentication failed.");
                            }
                        } catch (\Throwable $e) {
                            $connection->close("\x01\x01", true);
                            Worker::debug("Authentication failed socks5: " . $e->getMessage());
                            return 0;
                        }
                        $connection->send("\x01\x00", true);
                        Worker::debug("Authentication failed socks5: Authentication is successful.");
                        $connection->onSocks5Auth = null;
                        return 0;
                }
                break;
        }
    }

    public static function encode($buffer, TcpConnection $connection): string
    {

    }

    public static function decode($buffer, TcpConnection $connection): string
    {

    }
}