<?php

namespace Sue\ChildProcess;

use Exception;
use Sue\ChildProcess\AbstractProcess;
use Sue\ChildProcess\Exceptions\ProcessException;

/**
 * 常驻进程，类似supervisor会自动拉起失败的任务
 * 默认最小运行时间 1 秒，可以通过 setMinUpTime() 设置
 * 默认重启次数 10 次，可以通过 setMaxRetries() 设置
 */
class PersistentProcess extends AbstractProcess
{
    /** @var int $maxFailCount 最大错误数 */
    private $maxRetries = 10;

    /** @var int $minUpTime 最短运行时间（秒） */
    private $minUpTime = 1;

    /** @var int $tries 重启次数 */
    private $tries = 0;

    /**
     * 设置重试次数
     * 
     * @param int $count
     * @return self
     */
    public function setMaxRetries($count)
    {
        $this->maxRetries = (int) $count;
        return $this;
    }

    /**
     * 设置最少有效运行时间
     *
     * @param float $seconds
     * @return self
     */
    public function setMinUpTime($seconds)
    {
        $this->minUpTime = (float) $seconds;
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
        $this->on('exit', function ($error_code) use ($interval) {
            if ($this->finished) {
                return;
            }

            if ($this->getUpTime() >= $this->minUpTime) {
                $this->tries = 0;
            } elseif ($this->tries < $this->maxRetries) {
                $this->tries++;
            } else {
                $contents = [];
                $contents[] = "Exited too quickly after {$this->tries} tries";
                $contents[] = "with exit code: {$error_code}";
                $this->throwable and $contents[] = "and exception";
                $throwable = new ProcessException(implode(' ', $contents), 0, $this->throwable);
                $this->deferred->reject($throwable);
                return;
            }

            $this->close();
            $this->execute($interval)
                ->otherwise(function ($exception) {
                    $this->terminate(null, $exception);
                });
        });
        $this->execute($interval)
            ->otherwise(function ($exception) {
                $this->terminate(null, $exception);
            });
        return $this->deferred->promise();
    }

    /**
     * 绑定下stderr输出
     *
     * @return void
     */
    protected function bindStderr()
    {
        $closure = function ($error) {
            $throwable = self::wrapException($error);
            $this->throwable = $throwable;
        };
        $this->stdout->on('error', $closure);
        $this->stderr->on('error', $closure);
    }
}
