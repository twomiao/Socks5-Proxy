<?php
namespace Socks5\Protocols\Command;

use Socks5\TcpConnection;

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
        $command = self::$registerCommands[$connection->state] ?? UnknownCommand::COMMAND;

        if (!is_a($command, AbstractCommand::class, true)) {
            $connection->state = UnknownCommand::COMMAND;
        }

        return $len = call_user_func([$command, 'run'], $buffer, $connection);
    }
}