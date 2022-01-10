<?php

namespace Socks5\Server;

use Socks5\Server\Protocols\ProtocolInterface;

/**
 *
 * @property $state string
 * @property $onSocks5Auth
 * @property $host_len int
 * @property $addr_type int
 * Class TcpConnection
 * @package Swoman\Server
 */
class TcpConnection
{
    use EventConnection;

    /**
     * 初始化
     * @var int
     */
    protected const STATE_INITIAL = 0;

    /**
     * @var int
     */
    protected const STATE_CONNECTING = 1;

    /**
     * @var int
     */
    protected const STATE_ESTABLISHED = 2;

    /**
     * @var int
     */
    protected const STATE_CLOSING = 3;

    /**
     * @var int
     */
    protected const STATE_CLOSED = 4;

    /**
     * @var int
     */
    protected const READ_DATA_BUFFER_SIZE = 65535;

    /**
     * @var int
     */
    protected const SEND_MSG_FAIL = 2;

    /**
     * @var int $bytesRead
     */
    protected int $bytesRead = 0;

    /**
     * @var int $bytesWritten
     */
    protected int $bytesWritten = 0;

    /**
     * @var string $readDataBuffer
     */
    protected string $readDataBuffer = '';

    /**
     * @var string $writtenDataBuffer
     */
    protected string $writtenDataBuffer = '';

    /**
     * 1M
     * @var int $maxSendBufferSize
     */
    public int $maxSendBufferSize = 1048576;

    /**
     *  1M
     * @var int $defaultMaxSendBufferSize
     */
    public static int $defaultMaxSendBufferSize = 1048576;

    /**
     * @var int $maxPackageSize
     */
    public int $maxPackageSize = 1048576;

    /**
     * @var int $defaultMaxPackageSize
     */
    public static int $defaultMaxPackageSize = 10485760;

    /**
     * @var null|Worker $worker
     */
    public ?Worker $worker = null;

    /**
     * @var $_socket
     */
    protected $_socket;

    /**
     * @var int $_status
     */
    protected int $_status = self::STATE_ESTABLISHED;

    /**
     * @var string $_remoteAddress
     */
    protected string $_remoteAddress = '';

    /**
     * @var int $id_record
     */
    protected static int $id_record = 0;

    /**
     * @var bool $isPaused
     */
    protected bool $isPaused = false;

    /**
     * @var int $id
     */
    public int $id = 0;

    /**
     * @var int $currentPackageLength
     */
    public int $currentPackageLength = 0;

    /**
     * @var string $layerProtocol
     */
    protected string $layerProtocol = '';

    /**
     * @var array $connections
     */
    protected static array $connections = [];

    /**
     * @var int[] $statistics
     */
    public static array $statistics = array(
        'connection_count' => 0,
        'total_request' => 0,
        'throw_exception' => 0,
        'send_fail' => 0,
    );

    /**
     * @param $new_socket
     * @param string $_remote_address
     */
    public function __construct($new_socket, $_remote_address)
    {
        $this->_socket = $new_socket;
        $this->_remoteAddress = $_remote_address;
        static::$id_record++;
        $this->id = static::$id_record;
        \stream_set_blocking($this->_socket, false);
        Worker::$eventLoop->add($this->_socket, LoopInterface::EV_READ, array($this, 'baseRead'));
        $this->maxPackageSize = static::$defaultMaxPackageSize;
        $this->maxSendBufferSize = static::$defaultMaxSendBufferSize;
        static::$connections[$this->id] = $this;
        ++static::$statistics['connection_count'];
    }

    public function baseRead($socket, $check_eof = true)
    {
        $buffer = \fread($this->_socket, static::READ_DATA_BUFFER_SIZE);
        // ?Closed Connection
        if ($buffer === '' || $buffer === false) {
            // ????
            if ($check_eof && (\feof($socket) || !\is_resource($socket) || $buffer === false)) {
                $this->destroy();
                return;
            }
        } else {
            // receive data
            $this->bytesRead += strlen($buffer);
            $this->readDataBuffer .= $buffer;
        }

        // Handling application layer protocols
        if (!empty($this->layerProtocol)) {
            while ($this->readDataBuffer !== '' && !$this->isPaused) {
                // Packet boundary
                if ($this->currentPackageLength) {
                    if (strlen($this->readDataBuffer) < $this->currentPackageLength) {
                        break;
                    }
                } else {
                    try {
                        /**
                         * @var $layerProtocol ProtocolInterface
                         */
                        $layerProtocol = $this->layerProtocol;
                        $this->currentPackageLength = $layerProtocol::input($buffer, $this);
                    } catch (\Throwable $e) {
                            Worker::stopWorker($e);
                    }
                    if ($this->currentPackageLength === 0) {
                        break;
                    } elseif ($this->currentPackageLength > 0 && $this->currentPackageLength <= $this->maxPackageSize) {
                        if ($this->currentPackageLength > strlen($this->readDataBuffer)) {
                            break;
                        }
                    } else {
                        echo 'Error package. package_length=' . \var_export($this->currentPackageLength, true) . PHP_EOL;
                        // error package.
                        $this->destroy();
                        return;
                    }
                }
                ++static::$statistics['total_request'];
                if (\strlen($this->readDataBuffer) === $this->currentPackageLength) {
                    $request_buffer_data = \substr($this->readDataBuffer, 0);
                    $this->readDataBuffer = '';
                } else {
                    $request_buffer_data = \substr($this->readDataBuffer, 0, $this->currentPackageLength);
                    $this->readDataBuffer = \substr($this->readDataBuffer, $this->currentPackageLength);
                }
                $this->currentPackageLength = 0;
                if (!$this->onMessage) {
                    continue;
                }
                try {
                    /**
                     * @var $layerProtocol ProtocolInterface
                     */
                    $layerProtocol = $this->layerProtocol;

                    if ($this->layerProtocol && \class_exists($layerProtocol)) {
                        \call_user_func($this->onMessage, $this, $layerProtocol::decode($request_buffer_data, $this));
                    }
                } catch (\Exception $e) {
                    Worker::stopWorker($e);
                } catch (\Throwable $err) {
                    Worker::stopWorker($err);
                }
                return;
            }
            return;
        }
        // No original data
        if ($this->readDataBuffer === '' || $this->isPaused) {
            return;
        }
        // Drop packets
        if (!$this->onMessage) {
            $this->readDataBuffer = '';
            return;
        }
        try {
            // The original TCP data is handed over to the user for processing
            \call_user_func($this->onMessage, $this, $this->readDataBuffer);
        } catch (\Exception $e) {
            Worker::stopWorker($e);
        } catch (\Error $err) {
            Worker::stopWorker($err);
        }
        // Clean up the current connection data
        $this->readDataBuffer = '';
    }

    public function destroy()
    {
        if ($this->_status === self::STATE_CLOSED) {
            return;
        }
        Worker::$eventLoop->del($this->_socket, LoopInterface::EV_READ);
        Worker::$eventLoop->del($this->_socket, LoopInterface::EV_WRITE);
        try {
            @fclose($this->_socket);
        } catch (\Throwable $e) {
        };
        $this->_status = self::STATE_CLOSED;
        if ($this->onClose) {
            try {
                call_user_func($this->onClose, $this);
            } catch (\Exception $e) {
                Worker::stopWorker($e);
            } catch (\Error $err) {
                Worker::stopWorker($err);
            }
        }

        if ($this->layerProtocol && \method_exists($this->layerProtocol, 'onClose')) {
            try {
                \call_user_func($this->layerProtocol . '::onClose', $this);
            } catch (\Exception $e) {
                Worker::stopWorker($e);
            } catch (\Error $err) {
                Worker::stopWorker($err);
            }
        }

        $this->readDataBuffer = $this->writtenDataBuffer = '';
        $this->currentPackageLength = 0;
        $this->isPaused = false;
        if ($this->_status === self::STATE_CLOSED) {
            /// clear event
            $this->clearCallEvent();
            // 从服务器中删除TCP连接实例
            if ($this->worker) {
                unset($this->worker->connections[$this->id]);
            }
        }
    }

    /**
     * @param $send_buffer
     * @param bool $raw
     * @return bool|void
     */
    public function send($send_buffer, bool $raw = false)
    {
        if ($this->_status === self::STATE_CLOSING || $this->_status === self::STATE_CLOSED) {
            return;
        }

        // TCP包转换为应用层协议包
        if ($this->layerProtocol && $raw === false) {
            /**
             * @var $layerProtocol ProtocolInterface
             */
            $layerProtocol = $this->layerProtocol;
            if (!$send_buffer = $layerProtocol::encode($send_buffer, $this)) {
                return;
            }
        }

        if ($this->writtenDataBuffer === '') {
            $len = 0;
            try {
                $len = @fwrite($this->_socket, $send_buffer);
            } catch (\Exception $e) {
                Worker::stopWorker($e);
            } catch (\Error $err) {
                Worker::stopWorker($err);
            }
            // 同步阻塞发送
            if ($len === strlen($send_buffer)) {
                $this->bytesWritten += $len;
                return true;
            }

            // 发送发送部分数据
            if ($len > 0) {
                $this->writtenDataBuffer .= \substr($send_buffer, $len);
                $this->bytesWritten += $len;
            } else {
                // 发送消息失败
                if (!is_resource($this->_socket) || \feof($this->_socket)) {
                    ++static::$statistics['send_fail'];
                    if ($this->onError) {
                        try {
                            \call_user_func($this->onError, $this, self::SEND_MSG_FAIL, 'client closed');
                        } catch (\Exception $e) {
                            Worker::stopWorker($e);
                        } catch (\Error $err) {
                            Worker::stopWorker($err);
                        }
                    }
                    $this->destroy();
                    return false;
                }
                $this->writtenDataBuffer = $send_buffer;
            }
            Worker::$eventLoop->add($this->_socket, LoopInterface::EV_WRITE, [$this, 'baseWrite']);
            $this->checkBufferWillFull();
            return;
        }

        if ($this->bufferIsFull()) {
            ++static::$statistics['send_fail'];
            return false;
        }

        $this->writtenDataBuffer .= $send_buffer;
        $this->checkBufferWillFull();
    }

    /**
     * @return bool|void
     */
    public function baseWrite()
    {
        // 发送部分数据到客户端
        $len = @\fwrite($this->_socket, $this->writtenDataBuffer);
        // 发送数据完成
        if ($len === \strlen($this->writtenDataBuffer)) {
            $this->bytesWritten += $len;
            Worker::$eventLoop->del($this->_socket, LoopInterface::EV_WRITE);
            $this->writtenDataBuffer = '';

            if ($this->onBufferDrain) {
                try {
                    \call_user_func($this->onBufferDrain, $this);
                } catch (\Throwable $e) {
                    Worker::stopWorker($e);
                }
            }

            if ($this->_status === self::STATE_CLOSING) {
                $this->destroy();
            }
            return true;
        }

        if ($len > 0) {
            $this->bytesWritten += $len;
            $this->writtenDataBuffer = \substr($this->writtenDataBuffer, $len);
        } else {
            ++self::$statistics['send_fail'];
            $this->destroy();
        }
    }


    /**
     * 检查当前连接发送缓冲区是否已满
     * @return bool
     */
    protected function bufferIsFull()
    {
        if ($this->maxSendBufferSize <= \strlen($this->writtenDataBuffer)) {
            if ($this->onError) {
                try {
                    \call_user_func($this->onError, $this, static::SEND_MSG_FAIL, 'send buffer full and drop package');
                } catch (\Exception $e) {
                    Worker::stopWorker($e);
                } catch (\Error $err) {
                    Worker::stopWorker($err);
                }
            }
            return true;
        }
        return false;
    }

    /**
     * 触发当前写缓冲区已满
     */
    protected function checkBufferWillFull()
    {
        if ($this->maxSendBufferSize <= \strlen($this->writtenDataBuffer)) {
            if ($this->onBufferFull) {
                try {
                    \call_user_func($this->onBufferFull, $this);
                } catch (\Exception $e) {
                    Worker::stopWorker($e);
                } catch (\Error $err) {
                    Worker::stopWorker($err);
                }
            }
        }
    }

    /**
     * Close current connection
     * @param null $data
     * @param false $raw
     */
    public function close($data = null, bool $raw = false)
    {
        // 处于正在连接状态，因为没有缓存数据，直接关闭即可
        if ($this->_status === self::STATE_CONNECTING) {
            $this->destroy();;
            return;
        }

        // Called destroy() or call close() again is intercepted
        if (self::STATE_CLOSED === $this->_status || self::STATE_CLOSING === $this->_status) {
            return;
        }

        if (!is_null($data)) {
            $this->send($data, $raw);
        }

        $this->_status = self::STATE_CLOSING;

        // 数据全部发送给客户端，直接关闭即可
        if ($this->writtenDataBuffer === '') {
            $this->destroy();
        } else {
            $this->pauseRecv();
        }
    }

    public function resumeRecv()
    {
        if ($this->isPaused) {
            Worker::$eventLoop->add($this->_socket, LoopInterface::EV_READ, array($this, 'baseRead'));
            $this->isPaused = false;
        }
    }

    public function setLayerProtocol(string $protocol = ''):void
    {
        $this->layerProtocol = $protocol;
    }

    public function pauseRecv()
    {
        if ($this->isPaused === false) {
            $this->isPaused = true;
            // Pause receiving data
            Worker::$eventLoop->del($this->_socket, LoopInterface::EV_READ);
            // TCP缓冲区数据读取到内存缓冲区保存
            $this->baseRead($this->_socket, false);
        }
    }

    public function getSocket()
    {
        return $this->_socket;
    }

    /**
     * @return string
     */
    public function getRemoteAddress(): string
    {
        return $this->_remoteAddress;
    }

    /**
     * 获取客户端IP地址
     * @return string
     */
    public function getClientIP(): string
    {
        $remoteAddress = parse_url($this->_remoteAddress, PHP_URL_HOST);
        if (is_string($remoteAddress)) {
            return $remoteAddress;
        }
        return 'N/A';
    }

    /**
     * 获取客户端端口号
     * @return int
     */
    public function getClientPort(): int
    {
        $remotePort = parse_url($this->_remoteAddress, PHP_URL_PORT);
        if (is_int($remotePort) && $remotePort > 0) {
            return $remotePort;
        }
        return 0;
    }

    /**
     * 手动清除缓冲区数据
     * @var string
     */
    public function clearReaderBuffer()
    {
        $this->readDataBuffer = '';
    }
}