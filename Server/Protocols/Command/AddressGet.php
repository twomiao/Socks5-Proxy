<?php

namespace Socks5\Server\Protocols\Command;

use Socks5\Server\TcpConnection;

class AddressGet
{
    public const IPV4 = 0x01;
    public const IPV6 = 0x04;
    public const DNS = 0x03;

    /**
     * IP地址类型
     * @var int $addrType
     */
    private int $addrType;

    /**
     * 代理数据
     * @var string $buffer
     */
    private string $buffer;

    /**
     * @var TcpConnection
     */
    private TcpConnection $connection;

    /**
     * @var int $offset
     */
    public int $offset;

    /**
     * @var int $hostLen
     */
    private int $hostLen;

    /**
     * AddressGet constructor.
     * @param string $buffer
     * @param int $offset
     * @param TcpConnection $connection ;
     */
    public function __construct(string $buffer, int $offset, TcpConnection $connection)
    {
        $this->buffer = $buffer;
        $this->addrType = $connection->addr_type;
        $this->offset = $offset;
        $this->connection = $connection;
        $this->hostLen = $connection->host_len;
    }

    public function __destruct()
    {
       unset( $this->connection->host_len);
       unset($this->connection->addr_type);
    }

    /**
     * @return int
     */
    public function getAddrType(): int
    {
        if (!$this->addrType) {
            return 0;
        }
        return $this->addrType;
    }

    private function getAddrIpv4(): string
    {
        if ($this->addrType === self::IPV4) {
            $dest_addr = substr($this->buffer, $this->offset, $this->hostLen);
            $this->offset += $this->hostLen;
            $ip_src = inet_ntop($dest_addr);
            return $ip_src === false ? '' : $ip_src;
        }
        return '';
    }

    private function getAddrIpv6(): string
    {
        if ($this->addrType === self::IPV6) {
            $dest_addr = substr($this->buffer, $this->offset, $this->hostLen);
            $this->offset += $this->hostLen;
            $ip_src = inet_ntop($dest_addr);
            return $ip_src === false ? '' : $ip_src;
        }
        return '';
    }

    private function getAddrDns(): string
    {
        if ($this->addrType === self::DNS) {
            $this->offset++;
            $dest_addr = substr($this->buffer, $this->offset, $this->hostLen);
            $this->offset += strlen($dest_addr);

            var_dump('dns_get_record before.');
            $addr_list = dns_get_record($dest_addr, DNS_A);
            var_dump('dns_get_record after.');
            if ($addr_list === false) {
                return 'null';
            }
            $dest_addr = array_pop($addr_list)['ip'];
            return $dest_addr;
        }
        return '';
    }

    public function getAddr(): string
    {
        $addr = '';
        switch ($this->addrType) {
            case self::IPV4:
                $addr = $this->getAddrIpv4();
                break;
            case self::DNS:
                $addr = $this->getAddrDns();
                break;
            case self::IPV6:
                $addr = $this->getAddrIpv6();
                break;
        }

        return $addr;
    }

    public function __toString(): string
    {
        $addr_map = array(
            self::IPV4 => 'IPV4',
            self::DNS => 'DNS',
            self::IPV6 => 'IPV6'
        );

        return $addr_map[$this->addrType] ?? 'N/A';
    }
}