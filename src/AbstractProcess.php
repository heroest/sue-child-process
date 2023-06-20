<?php

namespace Sue\ChildProcess;

use Exception;
use BadMethodCallException;
use RuntimeException;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Promise\Deferred;
use React\Promise\Promise;
use Sue\ChildProcess\Exceptions\ProcessException;
use Sue\ChildProcess\Exceptions\ProcessCancelledException;

use function Sue\EventLoop\loop;
use function Sue\EventLoop\nextTick;

abstract class AbstractProcess extends \React\ChildProcess\Process
{
    const STDOUT = 'stdout';
    const STDERR = 'stderr';


    /** @var string $name */
    protected $name;

    /** @var Deferred $deferred */
    protected $deferred;

    /** @var Promise|PromiseInterface $promise */
    protected $promise;

    /** @var callable[]|\Closure[] $outputCallbacks */
    protected $outputCallbacks = [];

    /** @var int|false $timeStart 进程启动时间 */
    protected $timeStart = false;

    /** @var bool $settled 进程是否已完成 */
    protected $finished = false;

    /** @var bool $attached 是否已挂载 */
    protected $attached = false;

    public function __construct($cmd, $cwd = null, array $env = null, array $fds = null)
    {
        if (self::isWindows()) {
            $fds = [
                ['file', 'nul', 'r'],
                ['file', 'nul', 'w'],
                ['file', 'nul', 'w']
            ];
        }
        parent::__construct(self::wrapCommand($cmd), $cwd, $env, $fds);
        $this->name = 'default_' . microtime(true);
        $this->deferred = new Deferred(function () {
            $this->terminate(
                null,
                new ProcessCancelledException("Process promise has been cancelled")
            );
        });

        /** @var Promise|PromiseInterface $promise */
        $promise = $this->deferred->promise();
        $this->promise = $promise->always(function () {
            $this->finished = true;
            gc_collect_cycles();
        });
    }

    /**
     * 将进程挂载到eventloop上
     *
     * @param float $interval
     * @return \React\Promise\PromiseInterface|\React\Promise\Promise
     */
    abstract public function attach($interval = 0.1);

    /**
     * 设置名称
     *
     * @param  $name
     * @return static
     */
    public function setName($name)
    {
        $this->name = trim($name);
        return $this;
    }

    /**
     * 获取进程名称
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * 获取promise
     *
     * @return PromiseInterface|Promise
     */
    public function promise()
    {
        return $this->promise;
    }

    /**
     * 绑定stdout输出
     * 
     * 此方法在windows环境下的命令行无效
     *
     * @param callable $callback
     * @return static
     */
    public function output(callable $callback)
    {
        self::isWindows() or $this->outputCallbacks[self::STDOUT][] = $callback;
        return $this;
    }

    /**
     * 绑定stderr输出
     * 
     * 此方法在windows环境下的命令行无效
     *
     * @param callable $callback
     * @return static
     */
    public function errorOutput(callable $callback)
    {
        self::isWindows() or $this->outputCallbacks[self::STDERR][] = $callback;
        return $this;
    }

    /**
     * 返回在线时间（秒）
     *
     * @return float
     */
    public function getUpTime()
    {
        return false === $this->timeStart
            ? 0
            : (float) bcsub(microtime(true), $this->timeStart, 4);
    }

    /** 
     * use attach() instead
     * @inheritDoc 
     */
    public function start(LoopInterface $loop = null, $interval = 0)
    {
        throw new BadMethodCallException("This method cannot be called directly. see attach()");
    }

    /**
     * 终止运行
     *
     * @param mixed|null $signal
     * @param Exception|null $exception
     * @return void
     */
    public function terminate($signal = null, Exception $exception = null)
    {
        foreach ($this->pipes as $pipe) {
            $pipe->close();
        }
        $exception
            ? $this->deferred->reject($exception)
            : $this->deferred->resolve(null);
        return parent::terminate($signal);
    }

    /**
     * 对象销毁时中止运行
     */
    public function __destruct()
    {
        $exception = $this->finished
            ? null
            : new ProcessException('process is terminated because object was destroyed');
        $this->terminate(null, $exception);
    }

    /**
     * 禁止clone
     *
     * @return void
     */
    private function __clone()
    {
    }

    /**
     * 开始运行进程
     * 
     * @param float $interval
     * @param callable $after_started
     *
     * @return \React\Promise\PromiseInterface|\React\Promise\Promise
     */
    protected function execute($interval)
    {
        $this->timeStart = false;
        return nextTick(function () use ($interval) {
            $this->timeStart = microtime(true);
            parent::start(loop(), $interval);
            self::isWindows() or $this->bindProcessOutput();
        })->otherwise(function ($error) {
            $this->terminate(null, $error);
        });
    }

    /**
     * 绑定进程输出
     *
     * @return static
     */
    protected function bindProcessOutput()
    {
        $closure_failure = $this->closureOnFailure();
        $this->stdout
            ->on('data', $this->closureOnSuccess(self::STDOUT))
            ->on('error', $closure_failure);
        $this->stderr
            ->on('data', $this->closureOnSuccess(self::STDERR))
            ->on('error', $closure_failure);
        return $this;
    }

    /**
     * 生成绑定std输出的closure
     *
     * @param string $channel
     * @return \Closure
     */
    protected function closureOnSuccess($channel)
    {
        return function ($chunk) use ($channel) {
            $callbacks = isset($this->outputCallbacks[$channel])
                ? $this->outputCallbacks[$channel]
                : [];
            foreach ($callbacks as $cb) {
                try {
                    call_user_func($cb, $chunk);
                } catch (Exception $e) {
                }
            }
        };
    }

    /**
     * 生成绑定std输出时报错的closure
     *
     * @return \Closure
     */
    protected function closureOnFailure()
    {
        return function ($error) {
            $throwable = self::wrapException($error);
            $this->terminate(null, $throwable);
        };
    }

    /**
     * 是否是windows
     *
     * @return boolean
     */
    protected static function isWindows()
    {
        return strtolower(substr(PHP_OS, 0, 3)) === 'win';
    }

    /**
     * wrap exception
     *
     * @param mixed $error
     * @return Exception
     */
    protected static function wrapException($error)
    {
        $throwable = ($error instanceof Exception)
            ? $error
            : new RuntimeException($error);
        return $throwable;
    }

    /**
     * wrap一个command
     *
     * @param string $cmd
     * @return string
     */
    protected static function wrapCommand($cmd)
    {
        return (self::isWindows() or 0 === stripos($cmd, 'exec'))
            ? $cmd
            : "exec {$cmd}";
    }
}
