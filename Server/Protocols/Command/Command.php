<?php
namespace Socks5\Server\Protocols\Command;

class Command
{
    /**
     * 连接上游服务器
     * @var int
     */
    public const CONNECT = 0x01;

    /**
     * 绑定，客户端会接收来自代理服务器的链接，著名的FTP被动模式
     * @var int
     */
    public const BIND = 0x02;

    /**
     * UDP中继
     * @var int
     */
    public const UDP_ASSOCIATE = 0x03;

    const COMMAND_CONNECT_SUCCESS = 0x00; // 连接成功
    const COMMAND_SERVER_ERROR = 0x01; // 代理服务器故障
    const COMMAND_CONNECT_DENY = 0x02; // 代理服务器规则集不允许连接
    const COMMAND_UNKNOWN =0x07;
    const COMMAND_CONNECT_DEST_DENY = 0x05; // 连接目标服务器被拒绝
    const COMMAND_NOT_SUPPORT_ADDRESSTYPE = 0x08; //  不支持的目标服务器地址类型
    const COMMAND_NOT_ASSIGN = 0x09; // 未分配

    /**
     * @var int $command
     */
    private int $command;

    /**
     * Command constructor.
     * @param int $command
     */
    public function __construct(int $command)
    {
        $this->command = $command;
    }

    public function __toString()
    {
        $command = [
            self::BIND => 'BIND',
            self::CONNECT => 'CONNECT',
            self::UDP_ASSOCIATE => 'UDP_ASSOCIATE'
        ];
        return $command[$this->command] ?? 'UNKNOWN';
    }

    public function getCommandValue(): int
    {
        return $this->command;
    }
}