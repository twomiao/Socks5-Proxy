<?php
namespace Swoman\Poll;

trait EventServer
{
    public ?\Closure $onMessage = null;

    public  ?\Closure $onClose = null;

    public  ?\Closure $onWorkerStart = null;

    public  ?\Closure $onWorkerStop = null;

    public  ?\Closure $onError = null;

    public  ?\Closure $onBufferFull = null;

    public  ?\Closure $onBufferDrain = null;

    public  ?\Closure $onConnect = null;

    public  ?\Closure $onMasterStop = null;
}