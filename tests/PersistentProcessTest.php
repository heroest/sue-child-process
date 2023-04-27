<?php

namespace Sue\ChildProcess\Tests;

use React\Promise\Promise;
use React\EventLoop\TimerInterface;
use Sue\ChildProcess\PersistentProcess;
use Sue\ChildProcess\Exceptions\ProcessCancelledException;

use function Sue\EventLoop\cancelTimer;
use function Sue\EventLoop\loop;
use function Sue\EventLoop\setInterval;
use function Sue\EventLoop\setTimeout;

class PersistentProcessTest extends BaseTest
{
    public function testRun()
    {
        $seconds = 5;
        $cmd = $this->cmd(60, 'foo');
        $process = new PersistentProcess($cmd);
        $is_running = $process_terminated = $is_settled = false;
        $process->attach()->always(function () use (&$is_settled) {
            $is_settled = true;
        });
        $timer = setInterval(1, function () use ($process, &$is_running) {
            $is_running = $process->isRunning();
        });
        setTimeout($seconds, function () use (&$process_terminated, $timer, $process) {
            $process->terminate();
            cancelTimer($timer);
            $process_terminated = true;
        });
        loop()->run();
        $this->assertTrue($is_running);
        $this->assertTrue($process_terminated);
        $this->assertTrue($is_settled);
    }

    public function testWithPromiseCancel()
    {
        $cmd = $this->cmd(15, 'foo');
        $process = new PersistentProcess($cmd);
        $promise = $process->attach();
        $has_ran = false;
        setTimeout(0.5, function () use ($process, &$has_ran) {
            $has_ran = $process->isRunning();
        });
        setTimeout(1, function () use ($promise) {
            $promise->cancel();
        });
        loop()->run();
        $this->assertTrue($has_ran);
        $this->assertEquals(
            self::unwrapSettledPromise($promise), 
            new ProcessCancelledException('Process promise has been cancelled')
        );
    }

    public function testRunWithTerminate()
    {
        $cmd = $this->cmd(60, 'foo');
        $process = new PersistentProcess($cmd);
        $settled = false;
        $process->attach()->then(function () use (&$settled) {
            $settled = true;
        });
        setTimeout(1, function () use ($process) {
            $process->terminate();
        });
        loop()->run();
        $this->assertTrue($settled);
    }

    public function testRunTwice()
    {
        $cmd = $this->cmd(60, 'foo');
        $process = new PersistentProcess($cmd);
        $promise = $process->attach();
        $other_promise = $process->attach();
        $another_promise = null;
        $has_ran = false;
        setTimeout(1, function () use ($process, &$another_promise, &$has_ran) {
            $process->terminate();
            $another_promise = $process->attach();
            setTimeout(1, function () use ($process, &$has_ran) {
                $process->isRunning() and $has_ran = true;
            });
        });
        loop()->run();
        $this->assertSame($promise, $other_promise);
        $this->assertSame($promise, $another_promise);
        $this->assertFalse($has_ran);
    }

    public function testOutput()
    {
        if ($this->isWindows()) {
            return $this->markTestSkipped('Windows not supported');
        }

        $seconds = 5;
        $cmd = $this->cmd(60, 'bar');
        $process = new PersistentProcess($cmd);
        $process->attach();
        $storage = [];
        $process->output(function ($chunk) use (&$storage) {
            $storage[] = $chunk;
        });
        setTimeout($seconds, function () use ($process) {
            $process->terminate();
        });
        loop()->run();
        $this->assertCount($seconds, $storage);
        $this->assertEquals('bar', trim($storage[0]));
    }

    public function testOutputWithException()
    {
        if ($this->isWindows()) {
            return $this->markTestSkipped("Windows is not supported");
        }

        $cmd = $this->cmdThrowable(1, 'foo', 'bar');
        $process = new PersistentProcess($cmd);
        $process->setMinUpTime(10)->setMaxRetries(0);
        $content = '';
        $process->output(function ($chunk) use (&$content) {
            $content .= $chunk;
        });
        $process->attach();
        loop()->run();
        $this->assertStringContainsStringIgnoringCase('foo', $content);
        $this->assertStringContainsStringIgnoringCase('bar', $content);
        $this->assertStringContainsStringIgnoringCase('RuntimeException', $content);
    }

    public function testRunWithRestartSuccessfully()
    {
        $seconds = 3;
        $cmd = $this->cmd($seconds);
        $process = new PersistentProcess($cmd);
        $process->setMinUpTime($seconds - 1)->setMaxRetries(1);
        $has_ran = $process_terminated = $is_settled = false;
        $process->attach()
            ->always(function () use (&$is_settled) {
                $is_settled = true;
            });
        $timer = setInterval(1, function () use ($process, &$has_ran) {
            $process->isRunning() and $has_ran = true;
        });
        setTimeout($seconds * 2, function () use (&$process_terminated, $timer, $process) {
            $process->terminate();
            cancelTimer($timer);
            $process_terminated = true;
        });
        loop()->run();
        $this->assertTrue($has_ran);
        $this->assertTrue($process_terminated);
        $this->assertTrue($is_settled);
    }

    public function testRunWithRestartFail()
    {
        $seconds = 3;
        $cmd = $this->cmd($seconds);
        $process = new PersistentProcess($cmd);
        $process->setMinUpTime($seconds + 1)->setMaxRetries(1);
        $has_ran = $process_terminated = $is_settled = false;
        /** @var \Throwable|\Exception|null $throwable */
        $throwable = null;
        /**
         * @var TimerInterface|null $timer_interval
         * @var TimerInterface|null $timer_timeout
         * @var Promise|null $promise
         */
        $timer_interval = $timer_timeout = $promise = null;
        $promise = $process->attach();
        $promise->otherwise(function ($error) use (&$throwable, &$timer_interval, &$timer_timeout) {
            cancelTimer($timer_interval);
            cancelTimer($timer_timeout);
            $throwable = $error;
        });
        $promise->always(function () use (&$is_settled) {
            $is_settled = true;
        });
        $timer_interval = setInterval(1, function () use ($process, &$has_ran) {
            $process->isRunning() and $has_ran = true;
        });
        $timer_timeout = setTimeout($seconds * 3, function () use (&$process_terminated, $timer_interval, $process) {
            $process->terminate();
            cancelTimer($timer_interval);
            $process_terminated = true;
        });
        loop()->run();
        $this->assertTrue($has_ran);
        $this->assertFalse($process_terminated);
        $this->assertTrue($is_settled);
        $this->assertNotNull($throwable);
        $this->assertStringContainsStringIgnoringCase('Exited too quickly', $throwable->getMessage());
    }

    public function testRunWithWithTerminateWhileRestarting()
    {
        $seconds = 3;
        $cmd = $this->cmd($seconds);
        $process = new PersistentProcess($cmd);
        $process->setMinUpTime($seconds + 1)->setMaxRetries(3);
        $has_ran = $process_terminated = $is_settled = false;
        /** @var \Throwable|\Exception|null $throwable */
        $throwable = null;
        /**
         * @var TimerInterface|null $timer_interval
         * @var TimerInterface|null $timer_timeout
         * @var Promise|null $promise
         */
        $timer_interval = $timer_timeout = $promise = null;
        $promise = $process->attach();
        $promise->otherwise(function ($error) use (&$throwable, &$timer_interval, &$timer_timeout) {
            cancelTimer($timer_interval);
            cancelTimer($timer_timeout);
            $throwable = $error;
        });
        $promise->always(function () use (&$is_settled) {
            $is_settled = true;
        });
        $timer_interval = setInterval(1, function () use ($process, &$has_ran) {
            $process->isRunning() and $has_ran = true;
        });
        $timer_timeout = setTimeout(
            $seconds * 3,
            function () use (&$process_terminated, $timer_interval, $process) {
                $process->terminate();
                cancelTimer($timer_interval);
                $process_terminated = true;
            }
        );
        loop()->run();
        $this->assertTrue($has_ran);
        $this->assertTrue($process_terminated);
        $this->assertTrue($is_settled);
        $this->assertNull($throwable);
    }
}
