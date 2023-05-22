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
    protected $maxRunningSeconds = 1800;

    /** @var \React\Eventloop\TimerInterface|null $timeout */
    protected $timeout = null;

    /**
     * 设置最大运行时间（秒）
     *
     * @param int|float $seconds
     * @return static
     */
    public function setMaxRunningSeconds($seconds)
    {
        $seconds = (float) $seconds;

        $this->maxRunningSeconds = $seconds;
        $this->setProcessTimeout();
        return $this;
    }

    /** @inheritDoc */
    public function attach($interval = 0.1)
    {
        if ($this->attached) {
            return $this->promise();
        }
        $this->attached = true;
        
        $interval = (float) $interval;
        $this->on('exit', function ($error_code) {
            $this->timeout and cancelTimer($this->timeout);
            if (0 === bccomp(0, $error_code)) {
                $this->deferred->resolve(null);
            } else {
                $this->deferred
                    ->reject(new ProcessException("Process exit with code: {$error_code}"));
            }
        });
        $this->execute($interval)
            ->then(function () {
                $this->setProcessTimeout();
            });
        return $this->promise();
    }

    /**
     * 设置进程超时时间
     *
     * @return void
     */
    protected function setProcessTimeout()
    {
        $this->timeout and cancelTimer($this->timeout);
        if (false === $this->timeStart) { //还没启动过
            return;
        }

        $this->timeout = setTimeout(
            $this->maxRunningSeconds,
            function () {
                $exception = new TimeoutException("Timeout after {$this->maxRunningSeconds} seconds");
                $this->terminate(null, $exception);
            }
        );
    }
}
