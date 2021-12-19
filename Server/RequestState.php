<?php
namespace Socks5\Server;

/**
 * 枚举类
 * Class RequestState
 * @package Socks5-Proxy\Server
 */
final class RequestState
{
    /**
     * 初始化
     * @var int
     */
    public const INIT = 0;

    /**
     * 无需认证
     * @var int
     */
    public const PASSWORD_NO = '\x01';

    /**
     * 需要密码
     * @var int
     */
    public const PASSWORD_YES = '\x02';


}