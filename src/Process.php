<?php

namespace Sue\ChildProcess;

use Sue\ChildProcess\AbstractProcess;
use Sue\ChildProcess\Exceptions\ProcessException;
use Sue\ChildProcess\Exceptions\TimeoutException;

use function Sue\EventLoop\setTimeout;
use function Sue\EventLoop\cancelTimer;

/**
 * 非常驻进程，设置最大运行时间，如果超过的话会被自动中止，默认最大执行时间1800秒
 */
class Process extends AbstractProcess
{
    /** @var float $maxRunningSeconds 最大执行时间 */
    private $maxRunningSeconds = 1800;

    /** @var \React\Eventloop\TimerInterface $timeout */
    private $timeout = null;

    /**
     * 设置最大运行时间（秒）
     *
     * @param int|float $seconds
     * @return self
     */
    public function setMaxRunningSeconds($seconds)
    {
        $this->maxRunningSeconds = floatval($seconds);
        //运行中调整最大时间
        if ($this->timeout) {
            cancelTimer($this->timeout);
            $interval = (float) bcsub($seconds, $this->getUpTime(), 4);
            $this->timeout = setTimeout(
                $interval,
                function () {
                    $this->terminateWithTimeout();
                }
            );
        }
        return $this;
    }

    /** @inheritDoc */
    public function attach($interval = 0.1)
    {
        if ($this->attached) {
            return $this->deferred->promise();
        }

        $this->attached = true;
        $interval = (float) $interval;
        $this->on('exit', function ($error_code) {
            cancelTimer($this->timeout);
            if (0 === bccomp(0, $error_code)) {
                $this->deferred->resolve(null);
            } else {
                $throwable = $this->throwable
                    ?: new ProcessException("Process exit with code: {$error_code}");
                $this->deferred->reject($throwable);
            }
        });
        $this->execute($interval)
            ->then(
                function () {
                    $this->timeout = setTimeout(
                        $this->maxRunningSeconds,
                        function () {
                            $this->terminateWithTimeout();
                        }
                    );
                },
                function ($error) {
                    $this->deferred->reject($error);
                }
            );
        return $this->deferred->promise();
    }

    /**
     * 中止裕兴并抛出超时异常
     *
     * @return void
     */
    private function terminateWithTimeout()
    {
        $exception = new TimeoutException("Timeout after {$this->maxRunningSeconds} seconds");
        $this->terminate(null, $exception);
    }
}
