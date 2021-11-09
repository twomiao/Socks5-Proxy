<?php
namespace Swoman\Poll\Protocols;

use Swoman\Poll\ProtocolInterface;
use Swoman\Poll\TcpConnection;

/**
 * Frame Protocol.
 */
class Frame implements ProtocolInterface
{
    public static function input($buffer, TcpConnection $connection): int
    {
        if (\strlen($buffer) < 4) {
            return 0;
        }
        $unpack_data = \unpack('Ntotal_length', $buffer);
        return $unpack_data['total_length'];
    }

    public static function encode($buffer, TcpConnection $connection): string
    {
        return \substr($buffer, 4);
    }

    public static function decode($buffer, TcpConnection $connection): string
    {
        $total_length = 4 + \strlen($buffer);
        return \pack('N', $total_length) . $buffer;
    }
}

