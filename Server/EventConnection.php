<?php
namespace Socks5\Server;

trait EventConnection
{
    public ?\Closure $onClose = null;

    public ?\Closure $onError = null;

    public ?\Closure $onBufferFull = null;

    public ?\Closure $onBufferDrain = null;

    public ?\Closure $onMessage = null;


    protected function clearCallEvent()
    {
        $this->onClose = $this->onError = $this->onBufferFull = $this->onBufferDrain = $this->onMessage = null;
    }
}