# 子进程(Child-process)组件
提供基于sue/event-loop的子进程管理工具，需要php >= 5.6.0即可

## What is ReactPHP?
[ReactPHP](https://reactphp.org/)是一款基于PHP的事件驱动的组件。核心是提供EventLoop，然后提供基于EventLoop上的各种组件，比方说I/O处理，定时器等。

* [Sue\ChildProcess\Process](#process)
    * [setMaxRunningSeconds()](#setmaxrunningseconds)
* [Sue\ChildProcess\PersistentProcess](#persistentprocess)
    * [setMaxRetries()](#setmaxretries)
    * [setMinUpTime()](#setminuptime)
* [Sue\ChildProcess\AbstractProcess](#abstractprocess)
    * [attach()](#attach)
    * [promise()](#promise)
    * [terminate()](#terminate)
    * [output()](#output)
    * [isRunning()](#isrunning)
    * [isStopped()](#isstopped)
* [Install](#install)
* [Tests](#tests)
* [License](#license)

### Process
`\Sue\ChildProcess\Process` 为普通进程，拥有最大运行时间，超过时间会被终止，默认最大运行时间1800秒
```php
use Sue\ChildProcess\Process;

use function Sue\EventLoop\loop;

$process = new Process('php demo.php');
$process->attach();
loop()->run();
```

#### setMaxRunningSeconds
`$process->setMaxRunningSeconds(3600)`可以为进程设置最大运行时间(在进程启动后也可调整), 如果超出运行时间则会抛出rejected promise
```php
use Sue\ChildProcess\Process;
use Sue\ChildProcess\Exceptions\TimeoutException;

use function Sue\EventLoop\loop;

$process = new Process('php demo.php');
$process->setMaxRunningSeconds(15);
$process->attach()
    ->then(
        function () {
            echo "process executed successfully\n";
        },
        function (TimeoutException $e) {
            echo "超时: " . $e;
        }
    );
loop()->run();
```

### PersistentProcess
`\Sue\ChildProcess\PersistentProcess`为常驻进程。拥有最小运行时间以及最大重试次数，默认最小运行时间为1秒，默认最大重试次数为10次。
规则是如果进程持续时间小于1秒，则累积重试次数，反之则重置重试次数; 当重试次数超过最大则抛弃rejected promise
```php
use Sue\ChildProcess\PersistentProcess;
use Sue\ChildProcess\Exceptions\ProcessException;

use function Sue\EventLoop\loop;

$process = new PersistentProcess('php consumer.php');
$process->attach()
    ->otherwise(function (ProcessException $exception) {
        echo "fail to start process\n";
    });
loop()->run();

```
#### setMaxRetries
`$process->setMaxRetries(15)`可以设置最大重启次数

#### setMinUpTime
`$process->setMinUpTime(3.14)`可以设置进程最小存活时间

```php
use Sue\ChildProcess\PersistentProcess;

use function Sue\EventLoop\loop;

$process = new PersistentProcess('php consumer.php');
$process->setMinUpTime(2.15)
    ->setMaxRetries(15)
    ->attach()
    ->then(
        function () {
            echo "process exited successfully\n";
        },
        function ($error) {
            echo $error . "\n";
        }
    );
loop()->run();
```
### AbstractProcess
`AbstractProcess`是由`Process`和`PersistentProcess`继承的基础类

#### attach
在AbstractProcess对象生成后需要用`$process->attach()`方法将进程挂载到eventloop上，这样子进程才会启动
```php
$process = new PersistentProcess('php consumer.php');
$process->attach();
loop()->run();
```

#### promise
获取进程运行结果的promise

```php
$process = new Process('php work.php');
$process->attach();
$process->promise()->then(
    function () {
        echo "process end successfully\n"
    },
    function ($error) {
        echo "process stop with error: " . $error . "\n";
    }
);
```

#### terminate
`$process->terminate($signal, $exception)`可以中止正在运行的进程
```php
use Sue\ChildProcess\PersistentProcess;

use function Sue\EventLoop\loop;
use function Sue\EventLoop\setInterval;

$time_start = time();
$process = new PersistentProcess('php consumer.php');
setInterval(1, function () use ($process, $time_start) {
    if (time() - $time_start > 30) {
        $process->terminate();
    }
});
$process->attach();
loop()->run();
```

如果`$process->terminate(null, $exception)`则这个exception会传递到`attach`返回的promise中
```php
$process = new PersistentProcess('php consumer.php');
setInterval(1, function () use ($process, $time_start) {
    if (time() - $time_start > 30) {
        $process->terminate(null, new \RuntimeException('foo'));
    }
});
$process->attach()->then(null, function (\RuntimeException $e) {
    //error handle
});

```

#### output
`$process->output()`可以为进程注册一个回调函数，用以实时接收进程的stdout的输出信息。
> 这个功能在windows的命令行环境中无法使用（方法不报错，但是不会接收到任何数据） 
windows平台可以在 WSL (windows sub linux) 中正常使用
```php
//consumer.php
while (true) {
    echo "hello world\n";
    sleep(1);
}

//process.php
use Sue\ChildProcess\PersistentProcess;
use function Sue\EventLoop\loop;

$process = new PersistentProcess('php consumer.php');
$process->output(function ($chunk) {
    echo $chunk;
});
$process->attach();
loop()->run();
/** expected output:
 * hello world
 * hello world
 * hello world
 * hello world
 * ...
 */
```

#### errorOutput
`$process->errorOutput()`可以为进程注册一个回调函数，用以实时接收进程的stderr的输出信息。
> 这个功能在windows的命令行环境中无法使用（方法不报错，但是不会接收到任何数据） 
windows平台可以在 WSL (windows sub linux) 中正常使用
```php
//consumer.php
while (true) {
    echo "hello world\n";
    sleep(1);
}
throw new Exception('foo');

//process.php
use Sue\ChildProcess\Process;
use function Sue\EventLoop\loop;
$process = new Process('php consumer.php');
$process->errorOutput(function ($chunk) {
    echo $chunk;
});
$process->attach();
loop()->run();
/** expected output:
* Fatal error: Uncaught Exception: foo in consumer.php:3
 */
```

#### isRunning
`$process->isRunning()`可以检测进程是否正在运行
```php
use Sue\ChildProcess\PersistentProcess;

use function Sue\EventLoop\loop;
use function Sue\EventLoop\setInterval;
use function Sue\EventLoop\cancelTimer;

$process = new PersistentProcess('php consumer.php');
setInterval(5, function ($timer) use ($process) {
    if ($process->isRunning()) {
        echo "process looks good\n";
    } else {
        echo "process went wrong\n";
        cancelTimer($timer);
    }
});
```

#### isStopped
`$process->isStopped()`检测进程是否已停止


## install
`$ composer require sue/child-process` 使用composer安装组件

## tests
```bash
$ composer install
$ ./vendor/bin/phpunit
```

## License

The MIT License (MIT)

Copyright (c) 2023 Donghai Zhang

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is furnished
to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.