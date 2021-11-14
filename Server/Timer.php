<?php
namespace Swoman\Server;

class Timer
{
    /**
     * event
     *
     * @var LoopInterface
     */
    protected static LoopInterface $_event;

    /**
     * Init.
     *
     * @param LoopInterface $event
     * @return void
     */
    public static function init($event)
    {
        self::$_event = $event;
    }

    /**
     *  Add a timer.
     *
     * @param $time_interval
     * @param $func
     * @param array $args
     * @param bool $persistent
     * @return bool|void
     */
    public static function add($time_interval, $func, $args = array(), $persistent = true)
    {
        if (self::$_event) {
            return self::$_event->add($time_interval,
                $persistent ? LoopInterface::EV_TIMER : LoopInterface::EV_TIMER_ONCE, $func, $args);
        }
    }


    /**
     * Remove a timer.
     *
     * @param mixed $timer_id
     * @return bool
     */
    public static function del($timer_id)
    {
        if (self::$_event) {
            return self::$_event->del($timer_id, LoopInterface::EV_TIMER);
        }
        return false;
    }

    /**
     * Remove all timers.
     *
     * @return void
     */
    public static function delAll()
    {
        if (self::$_event) {
            self::$_event->clearAllTimer();
        }
    }
}
