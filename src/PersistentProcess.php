<?php

namespace Sue\ChildProcess;

use Exception;
use Sue\ChildProcess\AbstractProcess;
use Sue\ChildProcess\Exceptions\ProcessException;

use function Sue\EventLoop\call;

/**
 * 常驻进程，类似supervisor会自动拉起失败的任务
 * 默认最小运行时间 1 秒，可以通过 setMinUpTime() 设置
 * 默认重启次数 10 次，可以通过 setMaxRetries() 设置
 */
class PersistentProcess extends AbstractProcess
{
    /** @var Exception|null $throwable */
    protected $throwable;

    /** @var int $maxFailCount 最大错误数 */
    protected $maxRetries = 10;

    /** @var int $minUpTime 最短运行时间（秒） */
    protected $minUpTime = 1;

    /** @var int $tries 重启次数 */
    protected $tries = 0;

    /**
     * 设置重试次数
     * 
     * @param int $count
     * @return static
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
     * @return static
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
            return $this->promise();
        }
        $this->attached = true;

        $interval = (float) $interval;
        $that = $this;
        $this->on('exit', static function ($error_code) use (&$that, $interval) {
            if ($that->finished) {
                $that = null;
                return;
            }

            if ($that->getUpTime() >= $that->minUpTime) {
                $that->tries = 0;
            } elseif ($that->tries < $that->maxRetries) {
                $that->tries++;
            } else {
                $contents = [];
                $contents[] = "Exited too quickly after {$that->tries} tries";
                $contents[] = "with exit code: {$error_code}";
                $that->throwable and $contents[] = "and exception";
                $throwable = new ProcessException(implode(' ', $contents), 0, $that->throwable);
                $that->deferred->reject($throwable);
                return;
            }

            $that->close();
            $that->execute($interval)
                ->otherwise(static function ($exception) use (&$that) {
                    $that->terminate(null, $exception);
                });
        });
        $this->execute($interval);
        return $this->promise();
    }

    /** @inheritDoc */
    protected function closureOnFailure()
    {
        return function ($error) {
            $this->throwable = self::wrapException($error);
            $this->close();
        };
    }
}
