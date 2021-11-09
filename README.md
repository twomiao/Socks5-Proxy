## Workerman 设计 + Swoole扩展实现，命名为“Swoman”
- 为了探索Workerman 实现原理，使用Swoole4 + Pcntl 实现核心功能。
- 为了更方便了解原理，实现的单实例多进程Server 非多实例，当然这并不影响，掌握它的原理。
- 为了更方便解决实际遇见的问题，拥有改造Workerman的能力。
- 为了探索“浏览器到服务器”，这中间发生了什么。
- 当然，能给Workerman 开源社区贡献一份的力量。[ 还在做梦实现中 ..... ]
## 笔记本配置：
    CPU I7U + 16GB RAM + 8 核心
## 测试代码：
    <?php
    require __DIR__ . "/../vendor/autoload.php";
    
    $server = new \Swoman\Poll\TcpServer("tcp://127.0.0.1:19000");
    
    $server->onMessage = function (\Swoman\Poll\TcpConnection $connection, $buffer) {
        $connection->send("HTTP/1.1 200 OK
        Server: Swoman
        Connection: keep-alive
        Content-Type: text/html;charset=utf-8
        Content-Length: 12\r\n
        ".str_repeat("Hello,Swoman", 795));
    };
    $server->count = 8; 
    $server->name = "meows";
    $server->group = "meows";
    $server->start();

## 性能测试结果：

    root@LAPTOP-8LA5CDLH:/usr/local# ab -n 10000 -c 1500 -k http://127.0.0.1:19000/
    This is ApacheBench, Version 2.3 <$Revision: 1807734 $>
    Copyright 1996 Adam Twiss, Zeus Technology Ltd, http://www.zeustech.net/
    Licensed to The Apache Software Foundation, http://www.apache.org/
    
    Benchmarking 127.0.0.1 (be patient)
    Completed 1000 requests
    Completed 2000 requests
    Completed 3000 requests
    Completed 4000 requests
    Completed 5000 requests
    Completed 6000 requests
    Completed 7000 requests
    Completed 8000 requests
    Completed 9000 requests
    Completed 10000 requests
    Finished 10000 requests
    
    
    Server Software:        Swoman
    Server Hostname:        127.0.0.1
    Server Port:            19000
    
    Document Path:          /
    Document Length:        4800 bytes
    
    Concurrency Level:      1500
    Time taken for tests:   0.981 seconds
    Complete requests:      10000
    Failed requests:        0
    Keep-Alive requests:    10000
    Total transferred:      49160000 bytes
    HTML transferred:       48000000 bytes
    Requests per second:    10188.77 [#/sec] (mean)
    Time per request:       147.221 [ms] (mean)
    Time per request:       0.098 [ms] (mean, across all concurrent requests)
    Transfer rate:          48914.04 [Kbytes/sec] received
    
    Connection Times (ms)
    min  mean[+/-sd] median   max
    Connect:        0   22  55.1      0     229
    Processing:    31   96  35.7     92     180
    Waiting:       31   96  35.7     92     180
    Total:         31  118  57.6    104     287
    
    Percentage of the requests served within a certain time (ms)
    50%    104
    66%    129
    75%    151
    80%    163
    90%    212
    95%    237
    98%    265
    99%    275
    100%    287 (longest request)

#### 测试评价
    发送数据包逐渐增大，导致CPU性能负载。并发性能逐渐急速下降，不过这与PHP 底层计算能力相关。
