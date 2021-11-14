<?php
namespace Swoman\Poll;

use Swoole\Process;

class TcpServer
{
    use EventServer;

    /**
     * 启动状态
     * @int
     */
    const STATE_STARTING = 1;

    /**
     * 运行中状态
     * @int
     */
    const STATE_RUNNING = 2;

    /**
     * 停止成功
     * @int
     */
    const STATE_STOPPED = 4;

    /**
     * @var int
     */
    const SIG_KILL_TIMEOUT = 3;

    /**
     * @var int
     */
    const DEFAULT_BACKLOG = 102400;

    /**
     * @var string $logFile
     */
    public static string $logFile;

    /**
     * @var bool $daemonize
     */
    public static bool $daemonize = false;

    /**
     * @var bool $smoothStop
     */
    protected static bool $smoothStop = false;

    /**
     * ['pid'=>'pid', 'pid'=>'pid',....]
     *
     * @var array $pidMap
     */
    protected static array $pidMap = [];

    /**
     * @var array $idMap
     */
    protected static array $idMap = [];

    /**
     * @var ?LoopInterface $eventLoop
     */
    public static ?LoopInterface $eventLoop = null;

    /**
     *
     * @var string $pidFile
     */
    protected static string $pidFile;

    /**
     * @var int $masterPid
     */
    protected static int $masterPid;

    /**
     * @var array $connections
     */
    public array $connections = [];

    /**
     * 运行状态
     * @var int $stateCurrent
     */
    protected static int $stateCurrent = self::STATE_STARTING;

    /**
     * @var int $count
     */
    public int $count = 0;

    /**
     * @var string $name
     */
    public string $name = 'none';

    /**
     * 进程所属用户
     * @var string $user
     */
    public string $user = '';

    /**
     * 进程组所属组
     * @var string $group
     */
    public string $group = '';

    /**
     * @var bool $stopped
     */
    protected bool $stopped = false;

    /**
     * @var resource $mainSocket
     */
    protected $mainSocket;

    /**
     * @var int $workerId
     */
    public int $workerId;

    /**
     * Application layer protocol
     * @var string $layerProtocol
     */
    public string $layerProtocol = '';

    /**
     * @var string $socketName
     */
    protected string $socketName;

    /**
     * @var $contextOption
     */
    protected $contextOption;

    /**
     * @var bool $reusePort
     */
    protected bool $reusePort = true;

    /**
     * @var bool $pauseAccept
     */
    protected bool $pauseAccept = true;

    /**
     * @var string $processTitle
     */
    protected static string $processTitle = 'Swoman';

    /**
     * @param string $socket_name
     * @param array $context_option
     */
    public function __construct(string $socket_name = '', array $context_option = [])
    {
        if (PHP_OS !== 'Linux') {
            throw new \RuntimeException(sprintf("Cannot run on [%s] operating system.", PHP_OS));
        }

        // Context for socket.
        if ($socket_name) {
            $this->socketName = $socket_name;
            if (!isset($context_option['socket']['backlog'])) {
                $context_option['socket']['backlog'] = static::DEFAULT_BACKLOG;
            }
            $this->contextOption = \stream_context_create($context_option);
        }
    }

    protected function unlisten()
    {
        $this->pauseAccept();
        if ($this->mainSocket) {
            @\fclose($this->mainSocket);
            $this->mainSocket = null;
        }
    }

    /**
     * @throws \Exception
     */
    protected function listen()
    {
        if (!$this->socketName) {
            return;
        }

        if ($this->layerProtocol && !class_exists($this->layerProtocol)) {
            throw new \Exception("[ {$this->layerProtocol} ] Application layer protocol not found.");
        }

        if (!$this->mainSocket) {
            if ($this->reusePort) {
                \stream_context_set_option($this->contextOption, 'socket', 'so_reuseport', 1);
            }

            $this->mainSocket = \stream_socket_server($this->socketName, $error_code, $error_msg, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $this->contextOption);
            if (!$this->mainSocket) {
                throw new \Exception(sprintf("Failed to listen on port: %s, error code: %s.",
                        $this->getLocalPort(),
                        $error_msg,
                        $error_code
                    )
                );
            }

            // Try to open keepalive for tcp and disable Nagle algorithm.
            if (\function_exists('socket_import_stream')) {
                $socket = @\socket_import_stream($this->mainSocket);
                @\socket_set_option($socket, \SOL_SOCKET, \SO_KEEPALIVE, 1);
                @\socket_set_option($socket, \SOL_TCP, \TCP_NODELAY, 1);
            }
            \stream_set_blocking($this->mainSocket, false);
        }
    }


    public function start()
    {
        $this->initialization();
        $this->parseCommand();
        $this->daemonize();
        $this->saveMasterPid();
        $this->installSignal();
        $this->forkWorkers();
        $this->monitorWorkers();
    }

    protected function installSignal()
    {
        $signalDispatcher = array($this, 'signalDispatcher');

        \pcntl_signal(\SIGHUP, $signalDispatcher, false);
        \pcntl_signal(\SIGINT, $signalDispatcher, false);
        \pcntl_signal(\SIGTERM, $signalDispatcher, false);
    }

    protected function reinstallSignal()
    {
        $signal_dispatcher = array($this, 'signalDispatcher');

        \pcntl_signal(\SIGHUP, \SIG_IGN, false);
        \pcntl_signal(\SIGINT, \SIG_IGN, false);
        \pcntl_signal(\SIGTERM, \SIG_IGN, false);

        static::$eventLoop->add(\SIGHUP, LoopInterface::EV_SIGNAL, $signal_dispatcher);
        static::$eventLoop->add(\SIGINT, LoopInterface::EV_SIGNAL, $signal_dispatcher);
        static::$eventLoop->add(\SIGTERM, LoopInterface::EV_SIGNAL, $signal_dispatcher);
    }

    public function signalDispatcher($signal)
    {
        switch ($signal) {
            case \SIGTERM: // stop
            case \SIGINT:
                static::$smoothStop = false;
                $this->stopAll();
                break;
            case \SIGHUP: // stop -g
                static::$smoothStop = true;
                $this->stopAll();
                break;
        }
    }

    public function stopAll($code = 0)
    {
        static::$stateCurrent = static::STATE_STOPPED;

        // for master
        if (static::$masterPid === \posix_getpid()) {
            foreach (static::$pidMap as $worker_pid) {
                $sigo = static::$smoothStop ? \SIGHUP : \SIGINT;
                \posix_kill($worker_pid, $sigo);
                if (!static::$smoothStop) {
                    Timer::add(self::SIG_KILL_TIMEOUT, '\posix_kill', array($worker_pid, $sigo), false);
                }
            }
            $timer_id = Timer::add(1, array($this, 'checkIfChildRunning'), [&$timer_id]);
        } else {
            //for worker
            if (!$this->stopped) {
                if ($this->onWorkerStop) {
                    try {
                        call_user_func($this->onWorkerStop, $this);
                    } catch (\Throwable $e) {
                        exit($e->getCode());
                    }
                }
                $this->unlisten();
                if (!static::$smoothStop) {
                    foreach ($this->connections as $connection) {
                        $connection->close();
                    }
                    $this->onWorkerStart = $this->onWorkerStop = $this->onMessage = $this->onBufferDrain = $this->onBufferFull = null;
                }
                $this->stopped = true;
            }

            if (!static::$smoothStop || \count($this->connections) <= 0) {
                if (static::$eventLoop) {
                    static::$eventLoop->destroy();
                }
                try {
                    exit(0);
                } catch (\Swoole\ExitException $e) {

                }
            }
        }
    }

    public function checkIfChildRunning($timer_id)
    {
        foreach (static::$pidMap as $id => $worker_pid) {
            if (!\posix_kill($worker_pid, 0)) {
                unset(static::$pidMap[$id]);
            }
        }
        if (empty(static::$pidMap)) {
            Timer::del($timer_id);
        }
    }

    protected function forkWorkers()
    {
        for ($worker_id = 0; $worker_id < static::workerCount($this->count); $worker_id++) {
            $this->forkOneWorker($worker_id);
        }
    }

    /**
     * @param int $count
     * @return int
     */
    protected static function workerCount(int $count = 5): int
    {
        if ($count < 1) {
            if (\function_exists('swoole_cpu_num')) {
                return \swoole_cpu_num();
            }

            return 1;
        }
        return $count;
    }

    protected function forkOneWorker(int $workerId)
    {
        $process = new Process(function () use ($workerId) { // for worker
            \srand();
            \mt_srand();
            // Clear data
            static::$pidMap = [];
            Timer::delAll();
            if ($this->reusePort) {
                $this->listen();
            }
            \swoole_set_process_name(static::$processTitle . ': worker process');
            $this->workerId = $workerId;
            $this->setUserAndGroup();
            echo '工作进程已启动: ' . getmypid() . PHP_EOL;
            $this->run();
        }, static::$daemonize, 0, false);
        if (!$pid = $process->start()) { // start trigger error.
            exit(swoole_strerror(swoole_errno()));
        }
        // for master
        static::$pidMap[$pid] = $pid;
        static::$idMap[$workerId] = $pid;
    }
    
    protected function parseCommand()
    {
        global $argv;

        $available_commands = ['stop' => ['-d', '-g'], 'start' => ['-d']];
        $usage = <<<HELP
Usage: php Yourfile <command> [mode]
Commands:
start           Start worker in DEBUG mode.
                Use mode -d to start in DAEMON mode.
stop            Stop worker.
                Use mode -g to stop gracefully.\n
HELP;

        $start_file = $argv[0];
        if (!isset($argv[1])) {
            exit($usage);
        }
        $command = $argv[1];
        $mode = '';
        if (!in_array($command, array_keys($available_commands), true)) {
            exit($usage);
        }
        if (isset($argv[2])) {
            if (!in_array($argv[2], $available_commands[$command], true)) {
                exit($usage);
            }
            $mode = $argv[2];
        }

        if (posix_getuid() !== 0) {
            exit("You are not currently root user.\n");
        }

        $master_pid = \is_file(static::$pidFile) ? (int)\file_get_contents(static::$pidFile) : 0;
        $is_alive_master = $master_pid && posix_kill($master_pid, 0);
        if ($command === 'start' && $is_alive_master) {
            exit("Swoman [ {$start_file} ] is already running.\n");
        } elseif ($command === 'stop' && !$is_alive_master) {
            exit("Swoman [ {$start_file} ] not run.\n");
        }

        switch ($command) {
            case 'start':
                $mode_str = 'in DEBUG mode';
                if ($mode === '-d') {
                    static::$daemonize = true;
                    $mode_str = 'in DAEMON mode';
                }
                echo "Swoman {$mode_str}" . PHP_EOL;
                break;
            case 'stop':
                if (static::$smoothStop = ($mode === '-g')) {
                    $sig = \SIGHUP;
                    echo "Swoman is gracefully stopping ...\n";
                } else {
                    $sig = \SIGINT;
                    echo "'Swoman is stopping ...'\n";
                }

                $stop_at = \time() + 5;
                while (1) {
                    $is_alive_master = \posix_kill($master_pid, 0);
                    if (!$is_alive_master) {
                        exit("Swoman [ {$start_file} ] stop success.\n");
                    }

                    if (static::$smoothStop === false && $stop_at < \time() && $is_alive_master) {
                        exit("Swoman [ {$start_file} ] stop fail.\n");
                    }
                    $master_pid && \posix_kill($master_pid, $sig);
                    \usleep(100000);
                }
            default:
                exit($usage);
        }
    }

    protected function monitorWorkers() :void
    {
        static::$stateCurrent = static::STATE_RUNNING;
        while (1) {
            $status = 0;
            \pcntl_signal_dispatch();
            $pid = \pcntl_wait($status, \WUNTRACED);
            \pcntl_signal_dispatch();
            if ($pid > 0) {
                if (static::$stateCurrent !== static::STATE_STOPPED) {
                    if (isset(static::$pidMap[$pid])) {
                        // 异常退出进程
                        if ($status !== 0) {
                            echo "Worker process {$pid} exited abnormally: {$status}\n";
                        }

                        $workerId = array_search($pid, static::$idMap);
                        if ($workerId === false) {
                            continue;
                        }
                        $this->forkOneWorker($workerId);
                    }
                }
                // 统计worker 退出次数
                // 清除主进程残留数据
                unset(static::$pidMap[$pid]);
            }

            // for master clear data
            if (static::$stateCurrent === static::STATE_STOPPED && empty(static::$pidMap)) {
                // 因为不支持UnixSocket监听，因此不考虑删除.sock文件
                \clearstatcache(static::$pidFile);
                if (\file_exists(static::$pidFile)) {
                    @\unlink(static::$pidFile);
                }
                echo "Swoman has been stopped.\n";
                // 主进程自定义清理工作
                if ($this->onMasterStop) {
                    \call_user_func($this->onMasterStop);
                }
                exit(0);
            }
        }
    }

    protected function run()
    {
        // Update the running status of the child process
        static::$stateCurrent = static::STATE_RUNNING;
        \register_shutdown_function(array($this, 'checkErrors'));
        static::$eventLoop = new Swoole();
        $this->resumeAccept();
        $this->reinstallSignal();
        Timer::init(static::$eventLoop);
        // Set an empty onMessage callback.
        if (empty($this->onMessage)) {
            $this->onMessage = function () {
            };
        }

        try {
            if ($this->onWorkerStart) {
                call_user_func($this->onWorkerStart, $this);
            }
        } catch (\Exception $e) {
            $this->stopAll($e->getCode());
        } catch (\Error $e) {
            $this->stopAll($e->getCode());
        }

        static::$eventLoop->loop();
    }

    public static function checkErrors()
    {
        if (static::$stateCurrent !== static::STATE_STOPPED) {
            echo 'Please do not use exit() or die();' . PHP_EOL;
        }
    }

    /**
     * Save pid.
     *
     * @throws \RuntimeException
     */
    protected function saveMasterPid()
    {
        static::$masterPid = \posix_getpid();
        if (false === \file_put_contents(static::$pidFile, static::$masterPid)) {
            throw new \RuntimeException('Failed to save the main process file:' . static::$pidFile);
        }
    }

    protected function daemonize()
    {
        if (static::$daemonize) {
            Process::daemon(true, false);
        }
    }

    /**
     * Get unix user of current porcess.
     *
     * @return string
     */
    public static function getCurrentUser()
    {
        $user_info = \posix_getpwuid(\posix_getuid());
        return $user_info['name'];
    }

    protected function initialization()
    {
        if (empty($this->user)) {
            $this->user = static::getCurrentUser();
        } else {
            if (\posix_getuid() !== 0 && $this->user !== static::getCurrentUser()) {
                echo 'Warning: You must have the root privileges to change uid and gid.' . PHP_EOL;
            }
        }

        if (empty(self::$pidFile)) {
            self::$pidFile = \sys_get_temp_dir() . '/' . self::$processTitle . '.pid';
        }

        if (empty(self::$logFile)) {
            self::$logFile = \sys_get_temp_dir() . '/' . self::$processTitle . '_' . date('Ymd') . '.log';
        }

        if (!\file_exists(self::$logFile)) {
            \touch(self::$logFile);
            \chmod(self::$logFile, 0622);
        }

        \swoole_set_process_name(static::$processTitle . ': master process');

        $this->initIdMap();
        Timer::init(new Swoole());
        static::$stateCurrent = static::STATE_STARTING;
    }

    protected function initIdMap()
    {
        for ($i = 0; $i < $this->count; $i++) {
            static::$idMap[$i] = 0;
        }
    }

    public function resumeAccept()
    {
        if (static::$eventLoop && $this->mainSocket && $this->pauseAccept) {
            static::$eventLoop->add($this->mainSocket, LoopInterface::EV_READ, array($this, 'acceptTcpConnection'));
            $this->pauseAccept = false;
        }
    }

    public function pauseAccept()
    {
        if (static::$eventLoop && $this->mainSocket && !$this->pauseAccept) {
            static::$eventLoop->del($this->mainSocket, LoopInterface::EV_READ);
            $this->pauseAccept = true;
        }
    }

    public function acceptTcpConnection($socket)
    {
        $new_socket = @\stream_socket_accept($socket, 0, $remote_address);
        if (!$new_socket) {
            return;
        }

        $connection = new TcpConnection($new_socket, $remote_address);
        $this->connections[$connection->id] = $connection;
        $connection->onMessage = $this->onMessage;
        $connection->tcpServer = $this;
        $connection->onClose = $this->onClose;
        $connection->onError = $this->onError;
        $connection->onBufferDrain = $this->onBufferDrain;
        $connection->onBufferFull = $this->onBufferFull;
        $connection->layerProtocol = $this->layerProtocol;

        if ($this->onConnect) {
            try {
                \call_user_func($this->onConnect, $connection);
            } catch (\Exception $e) {
                $this->stopAll($e->getCode());
            } catch (\Error $e) {
                $this->stopAll($e->getCode());
            }
        }
    }

    public function getLocalAddress()
    {
        return parse_url($this->socketName, PHP_URL_HOST);
    }

    public function getLocalPort()
    {
        return parse_url($this->socketName, PHP_URL_PORT);
    }

    public function setUserAndGroup()
    {
        // Get uid.
        $user_info = \posix_getpwnam($this->user);
        if (!$user_info) {
            echo "Warning: User {$this->user} not exsits" . PHP_EOL;
            return;
        }
        $uid = $user_info['uid'];
        // Get gid.
        if ($this->group) {
            $group_info = \posix_getgrnam($this->group);
            if (!$group_info) {
                echo "Warning: Group {$this->group} not exsits" . PHP_EOL;
                return;
            }
            $gid = $group_info['gid'];
        } else {
            $gid = $user_info['gid'];
        }

        // Set uid and gid.
        if ($uid !== \posix_getuid() || $gid !== \posix_getgid()) {
            if (!\posix_setgid($gid) || !\posix_initgroups($user_info['name'], $gid) || !\posix_setuid($uid)) {
                echo "Warning: change gid or uid fail." . PHP_EOL;
            }
        }
    }
}