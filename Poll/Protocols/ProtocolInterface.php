<?php
namespace Swoman\Poll;

use Swoman\Poll\TcpConnection;

interface ProtocolInterface
{
    /**
     * 数据包边界长度
     * @param $buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function input($buffer, TcpConnection $connection):int;

    /**
     * @param $buffer
     * @param TcpConnection $connection
     * @return string
     */
    public static function encode($buffer, TcpConnection $connection):string;

    /**
     * @param $buffer
     * @param TcpConnection $connection
     * @return string
     */
    public static function decode($buffer, TcpConnection $connection):string;
}