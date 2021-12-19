<?php
namespace Socks5\Server;

use Swoole\Event;
use Swoole\Timer;

final class Swoole implements LoopInterface
{
    /**
     * milisecond
     * @var int $signalDispatchInterval
     */
    private static int $signalDispatchInterval = 500;

    /**
     * @var bool $_hasSignal
     */
    private $_hasSignal = false;

    /**
     * @var array $_eventClass
     */
    private array $_eventClass = [];

    /**
     * @var array $_timers
     */
    private array $_timers = array();

    /**
     * construct
     * @return void
     */
    public function __construct()
    {
        $this->_eventClass = array
        (
            'event_add' => array('\\Swoole\\Event', "add"),
            'event_set' => array('\\Swoole\\Event', "set")
        );
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see LoopInterface::add()
     */
    public function add($fd, $flag, $func, $args = array())
    {
        switch ($flag) {
            case self::EV_READ:
            case self::EV_WRITE:
                $flag = (self::EV_READ == $flag) ? SWOOLE_EVENT_READ : SWOOLE_EVENT_WRITE;
                if (Event::isset($fd, $flag)) {
                    return false;
                }

                $func = array(
                    SWOOLE_EVENT_READ => array($fd, $func, null),
                    SWOOLE_EVENT_WRITE => array($fd, null, $func),
                );
                $call_func = $func[$flag];
                if (!Event::isset($fd, SWOOLE_EVENT_READ | SWOOLE_EVENT_WRITE)) {
                    $call_func[] = $flag;
                    return call_user_func_array($this->_eventClass['event_add'], $call_func);
                }
                $call_func[] = SWOOLE_EVENT_READ | SWOOLE_EVENT_WRITE;
                return call_user_func_array($this->_eventClass['event_set'], $call_func);
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                $fd_key = (int)$fd;
                if (version_compare(SWOOLE_VERSION, '4.2.10') < 0 && $fd_key > 86400000) {
                    $fd_key = 1;
                }
                $timer_id = $this->createTimer($flag, $fd_key, $func, $args);
                $this->_timers[$timer_id] = $timer_id;
                return $timer_id;
            case self::EV_SIGNAL:
                if (function_exists('pcntl_signal')) {
                    return \pcntl_signal($fd, $func, false);
                }
        }
        return false;
    }

    private function createTimer($flag, $fd, $func, $args)
    {
        $fd = ($fd <= 0 || $fd > PHP_INT_MAX) ? 1 : $fd;

        $method = (self::EV_TIMER_ONCE === $flag) ? 'after' : 'tick';
        return Timer::$method($fd * 1000, function () use ($func, $args, $flag, &$timer_id) {
            try {
                \call_user_func_array($func, (array)$args);
            } catch (\Exception $e) {
                Worker::exit($e);
            } catch (\Error $err) {
                Worker::exit($err);
            } finally {
                if ($flag === self::EV_TIMER_ONCE && isset($this->_timers[$timer_id])) {
                    unset($this->_timers[$timer_id]);
                }
            }
        });
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see LoopInterface::del()
     */
    public function del($fd, $flag)
    {
        switch ($flag) {
            case self::EV_READ:
            case self::EV_WRITE:
                $flag = (self::EV_READ == $flag) ? SWOOLE_EVENT_WRITE : SWOOLE_EVENT_READ;
                if (Event::isset($fd, SWOOLE_EVENT_READ) &&
                    Event::isset($fd, SWOOLE_EVENT_WRITE)
                ) {
                    return Event::set($fd, null, null, $flag);
                }
                return Event::del($fd);
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                $fd = (int)$fd;
                if (isset($this->_timers[$fd]) && Timer::clear($fd)) {
                    unset($this->_timers[$fd]);
                    return true;
                }
            case self::EV_SIGNAL:
                if (function_exists('pcntl_signal')) {
                    return \pcntl_signal($fd, SIG_IGN, false);
                }
        }
        return false;
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see LoopInterface::clearAllTimer()
     */
    public function clearAllTimer()
    {
        foreach ($this->_timers as $timer_id) {
            Timer::clear($timer_id);
        }
        $this->_timers = array();
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see LoopInterface::destroy()
     */
    public function destroy()
    {
        Event::exit();
    }

    public function getTimerCount()
    {
        return \count($this->_timers);
    }

    /**
     * {@inheritdoc}
     *
     * @see LoopInterface::loop()
     */
    public function loop()
    {
        if (!$this->_hasSignal) {
            if (!function_exists('pcntl_signal_dispatch')) {
                return;
            }
            Timer::tick(self::$signalDispatchInterval, function () {
                \pcntl_signal_dispatch();
            });
            $this->_hasSignal = true;
        }

        Event::wait();
    }
}