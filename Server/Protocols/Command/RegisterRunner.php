<?php

namespace Socks5\Server\Protocols\Command;

use Socks5\Server\TcpConnection;

/**
 * 命令执行器
 * Class RegisterRunner
 * @package Socks5\Server\Protocols\AbstractCommand
 */
class RegisterRunner
{
    /**
     * @var array $commands
     */
    protected static array $registerCommands = [
        InitCommand::COMMAND => InitCommand::class,
        PasswordAuthCommand::COMMAND => PasswordAuthCommand::class,
        UnknownCommand::COMMAND => UnknownCommand::class,
        GetRequestLenCommand::COMMAND => GetRequestLenCommand::class,
    ];

    public static function run(string $buffer, TcpConnection $connection): int
    {
        if (!isset(self::$registerCommands[$connection->state])) {
            $connection->state = UnknownCommand::COMMAND;
        }
        $command = self::$registerCommands[$connection->state];
        if (!$command instanceof AbstractCommand) {
//            var_dump($command);
            $connection->state = UnknownCommand::COMMAND;
        }
        return $len = call_user_func([$command, 'run'], $buffer, $connection);
    }
}