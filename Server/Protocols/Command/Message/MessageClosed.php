<?php
namespace Socks5\Server\Protocols\Command\Message;

class MessageClosed extends MessageSock
{
    /**
     * MessageClosed constructor.
     * @param int $command
     * @param string $destAddr
     * @param int $port
     * @param int $rsv
     * @param int $sock_ver
     */
    public function __construct(int $command, string $destAddr, int $port,
                                int $rsv = 0x00, int $sock_ver = self::SOCK_VER)
    {
        $this->command = $command;
        $this->destAddr = $destAddr;
        $this->port = $port;
        $this->rsv = $rsv;
        $this->sock_ver = $sock_ver;
    }

    public function respSock(): string
    {
        return $this->toSock();
    }

    protected function toSock(): string
    {
        $sock = [
            'ver' => $this->sock_ver,
            'command' => $this->command,
            'rsv' => $this->rsv,
            'dest_addr' => $this->destAddr,
            'port' => $this->port
        ];

        $sock_header = array(
            $sock['ver'],
            $sock['command'],
            $sock['rsv'],
        );

        $sock_header = pack('C*', ... $sock_header);

        return $sock_header . pack('n', $sock['port']);
    }
}