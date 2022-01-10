<?php
namespace Socks5\Connection;

use Socks5\LoopInterface;
use Socks5\TcpConnection;
use Socks5\Timer;
use Socks5\Worker;

class AsyncTcpConnection extends TcpConnection
{
    public ?\Closure $onConnect = null;

    /**
     * 远程端口
     * @var string $_remotePort
     */
    protected string $_remotePort = '';

    /**
     * 远程IP地址
     * @var string $_remoteHost
     */
    protected string $_remoteHost = '';

    /**
     * @var string $_remoteAddress
     */
    protected string $_remoteAddress = '';

    /**
     * @var float $_connectTime
     */
    protected float $_connectTime;

    /**
     * @var int $_status
     */
    protected int $_status = self::STATE_INITIAL;

    /**
     * @var array $_context
     */
    protected array $_context = [];

    /**
     * @var int $_reconnectTimer
     */
    protected int $_reconnectTimer = 0;

    /**
     * AsyncTcpConnection constructor.
     * @param string $remote_address
     * @param array $_context
     */
    public function __construct(string $remote_address, $_context = [])
    {
        $this->_remoteAddress = $remote_address;
        // 目标主机和端口初始化
        [$this->_remoteHost, $this->_remotePort] = [$this->getRemoteHost(), $this->getRemotePort()];
        // 连接ID
        $this->id = ++static::$id_record;
        // 统计当前worker 连接总数量
        ++static::$statistics['connection_count'];
        $this->maxPackageSize = static::$defaultMaxPackageSize;
        $this->maxSendBufferSize = static::$defaultMaxSendBufferSize;
        // tcp client 配置
        $this->_context = $_context;
        // 连接保存到当前worker 进程
        static::$connections[$this->id] = $this;
    }

    public function connect()
    {
        if ($this->_status !== self::STATE_INITIAL && $this->_status !== self::STATE_CLOSED &&
            $this->_status !== self::STATE_CLOSING) {
            return;
        }
        // 当前处于正在连接状态
        $this->_status = self::STATE_CONNECTING;

        if ($this->_context) {
            $context = \stream_context_create($this->_context);
            $this->_socket = \stream_socket_client("tcp://{$this->_remoteHost}:{$this->_remotePort}", $error_code, $error_msg, 0, \STREAM_CLIENT_ASYNC_CONNECT, $context);
        } else {
            $this->_socket = \stream_socket_client("tcp://{$this->_remoteHost}:{$this->_remotePort}", $error_code, $error_msg, 0, \STREAM_CLIENT_ASYNC_CONNECT);
        }
        if (!$this->_socket || !is_resource($this->_socket)) {
            $this->connectError($error_code, $error_msg);
            if ($this->_status === self::STATE_CLOSING) {
                $this->destroy();
            }
            if ($this->_status === self::STATE_CLOSED) {
                $this->onConnect = null;
            }
            return;
        }
        $this->_connectTime = \microtime(true);
        //  tcp 握手连接完成
        Worker::$eventLoop->add($this->_socket, LoopInterface::EV_WRITE, array($this, 'checkConnection'));
    }

    public function checkConnection()
    {
        Worker::$eventLoop->del($this->_socket, LoopInterface::EV_WRITE);
        if ($this->_status !== self::STATE_CONNECTING) {
            return;
        }
        // 获取到目标主机地址和端口
        if ($address = \stream_socket_get_name($this->_socket, true)) {
            // 非阻塞
            \stream_set_blocking($this->_socket, false);
            if (\function_exists('socket_import_stream')) {
                $raw_socket = \socket_import_stream($this->_socket);
                \socket_set_option($raw_socket, \SOL_SOCKET, \SO_KEEPALIVE, 1);
                \socket_set_option($raw_socket, \SOL_TCP, \TCP_NODELAY, 1);
            }
            // 不处理ssl 协议

            // 发送缓冲区数据完全发送
            if ($this->writtenDataBuffer) {
                Worker::$eventLoop->add($this->_socket, LoopInterface::EV_WRITE, array($this, 'baseWrite'));
            }
            // 处理目标服务器的数据
            Worker::$eventLoop->add($this->_socket, LoopInterface::EV_READ, array($this, 'baseRead'));
            $this->_status = self::STATE_ESTABLISHED;
            $this->_remoteAddress = $address;

            // 传输层协议处理
            if ($this->onConnect) {
                try {
                    call_user_func($this->onConnect, $this);
                } catch (\Throwable $e) {
                    Worker::stopWorker($e);
                }
            }
            // 不处理应用层协议
            return;
        }
        // connect err.
        $this->connectError(100, 'Try connect ' . $this->_remoteAddress . ' fail after ' . round(\microtime(true) - $this->_connectTime, 4) . ' seconds');
        if ($this->_status === self::STATE_CLOSING) {
            $this->destroy();
        }
        if ($this->_status === self::STATE_CLOSED) {
            $this->onConnect = null;
        }
    }

    /**
     * @param int $after
     * TCP 定时重连
     */
    public function reconnect($after = 0)
    {
        $this->_status = self::STATE_INITIAL;
        static::$connections[$this->id] = $this;
        $this->cancelReconnect();
        if ($after > 0) {
            // 创建定时任务
            $this->_reconnectTimer = Timer::add($after, [$this, 'connect'], null, false);
            return;
        }
        $this->connect();
    }

    /**
     * 删除定时任务
     */
    public function cancelReconnect()
    {
        if ($this->_reconnectTimer) {
            Timer::del($this->_reconnectTimer);
        }
    }

    /**
     * 连接失败错误
     * @param $code
     * @param $msg
     */
    public function connectError($code, $msg)
    {
        $this->_status = self::STATE_CLOSING;
        if ($this->onError) {
            try {
                call_user_func($this->onError, $this, $code, $msg);
            } catch (\Throwable $e) {
                Worker::stopWorker($e);
            }
        }
    }

    /**
     * @return string
     */
    public function getRemoteAddress(): string
    {
        return $this->_remoteAddress;
    }

    /**
     * 目标主机
     * @return string
     */
    public function getRemoteHost(): string
    {
        $remote_host = \parse_url($this->_remoteAddress, PHP_URL_HOST);
        if (\is_string($remote_host)) {
            return $remote_host;
        }
        return 'N/A';
    }

    /**
     * 目标主机端口
     * @return int
     */
    public function getRemotePort(): int
    {
        $remote_port = \parse_url($this->_remoteAddress, PHP_URL_PORT);
        if (\is_int($remote_port) && $remote_port > 0) {
            return $remote_port;
        }
        return 0;
    }
}