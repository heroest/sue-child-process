<?php

namespace Eainc\ChildProcess\Tests;

use Sue\ChildProcess\PersistentProcess;
use Sue\ChildProcess\Process;
use Sue\ChildProcess\Tests\BaseTest;

use function Sue\EventLoop\cancelTimer;
use function Sue\EventLoop\loop;
use function Sue\EventLoop\setInterval;
use function Sue\EventLoop\setTimeout;

class MemoryTest extends BaseTest
{
    public function testProcess()
    {
        $records = [];
        $time_end = time() + 10;
        setInterval(1.5, function ($timer) use (&$records, $time_end) {
            static $started = false;
            if (time() > $time_end) {
                cancelTimer($timer);
                return;
            }
            $cmd = $this->cmd(1, 'foo');
            $process = new Process($cmd);
            $process->attach();
            if ($started) {
                $records[] = $this->memory();
            } else {
                $started = true;
            }
        });
        loop()->run();
        $this->assertDiffLessThan(100, $records);
    }
    
    public function testPersistentProcessWithTerminate()
    {
        $records = [];
        $time_end = time() + 10;
        setInterval(1.5, function ($timer) use (&$records, $time_end) {
            static $started = false;
            if (time() > $time_end) {
                cancelTimer($timer);
                return;
            }
            $cmd = $this->cmd(2, 'foo');
            $process = new PersistentProcess($cmd);
            $process->attach();
            setTimeout(0.2, function () use ($process) {
                $process->terminate();
            });
            if ($started) {
                $records[] = $this->memory();
            } else {
                $started = true;
            }
        });
        loop()->run();
        $this->assertDiffLessThan(100, $records);
    }

    public function testPersistentProcessWithRestartFail()
    {
        $records = [];
        $time_end = time() + 10;
        setInterval(1.5, function ($timer) use (&$records, $time_end) {
            static $started = false;
            if (time() > $time_end) {
                cancelTimer($timer);
                return;
            }
            $cmd = $this->cmd(1, 'foo');
            $process = new PersistentProcess($cmd);
            $process->setMinUpTime(5)->setMaxRetries(1);
            $process->attach();
            if ($started) {
                $records[] = $this->memory();
            } else {
                $started = true;
            }
        });
        loop()->run();
        $this->assertDiffLessThan(100, $records);
    }

    public function testPersistentProcessWithRestart()
    {
        $records = [];
        $time_end = time() + 10;
        $cmd = $this->cmd(2, 'foo');
        $process = new PersistentProcess($cmd);
        $process->setMinUpTime(1)->attach();
        $time_end = time() + 10;
        setInterval(1, function ($timer) use (&$records, $time_end, $process) {
            if (time() > $time_end) {
                cancelTimer($timer);
                $process->terminate();
                return;
            }

            $records[] = $this->memory();
        });
        loop()->run();
        $this->assertDiffLessThan(100, $records);
    }

    private function assertDiffLessThan(float $diff, array $records)
    {
        asort($records);
        $diff = end($records) - $records[0];
        $this->assertLessThanOrEqual(100, $diff);
    }

    protected function memory()
    {
        return (float) bcdiv(memory_get_usage(), 1024, 2);
    }
}