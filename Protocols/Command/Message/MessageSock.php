<?php
namespace Socks5\Protocols\Command\Message;

use Socks5\Protocols\Command\AddressGet;

class MessageSock
{
    public const IPV4 = 0x01;
    public const IPV6 = 0x04;
    public const DNS = 0x03;

    public const SOCK_VER = 0x05;
    protected const ADDR_TYPE = 0x00;

    protected int $sock_ver = self::SOCK_VER;
    protected int $command;
    protected int $rsv = 0x00;
    protected string $destAddr;
    protected int $port;
    protected int $addrType = self::ADDR_TYPE;

    /**
     * SockMessage constructor.
     * @param int $command
     * @param string $destAddr
     * @param int $port
     * @param int $addrType
     * @param int $rsv
     * @param int $sock_ver
     */
    public function __construct(int $command, string $destAddr, int $port,
                                int $addrType = self::ADDR_TYPE,
                                int $rsv = 0x00, int $sock_ver = self::SOCK_VER)
    {
        $this->command = $command;
        $this->destAddr = $destAddr;
        $this->port = $port;
        $this->addrType = $addrType;
        $this->rsv = $rsv;
        $this->sock_ver = $sock_ver;
    }

    public function respSock(): string
    {
        return $this->toSock();
    }

    public function __toString(): string
    {
        return $this->toSock();
    }

    protected function toSock(): string
    {
        $sock = [
            'ver' => $this->sock_ver,
            'command' => $this->command,
            'rsv' => $this->rsv,
            'addr_type' => $this->addrType,
            'dest_addr' => $this->destAddr,
            'port' => $this->port
        ];

        $sock_header = array(
            $sock['ver'],
            $sock['command'],
            $sock['rsv'],
            $sock['addr_type'],
        );

        $sock_header = pack('C*', ... $sock_header);

        $addr = '';
        switch ($sock['addr_type']) {
            case AddressGet::IPV4:
                $dest_addr = explode('.', $sock['dest_addr']);
                $addr = pack('C*', ... $dest_addr);
                break;
            case AddressGet::IPV6:
//                $header .= pack('N16', $dest_addr);
                break;
            case AddressGet::DNS:
                $dest_addr = $sock['dest_addr'];
                $host_len = strlen($dest_addr);
                $addr = pack("C", $host_len) . $dest_addr;
                break;
        }
        return $sock_header . $addr . pack('n', $sock['port']);
    }
}