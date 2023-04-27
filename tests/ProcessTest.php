<?php

namespace Sue\ChildProcess\Tests;

use Sue\ChildProcess\Exceptions\TimeoutException;
use Sue\ChildProcess\Exceptions\ProcessCancelledException;
use Sue\ChildProcess\Process;

use function Sue\EventLoop\loop;
use function Sue\EventLoop\setTimeout;

class ProcessTest extends BaseTest
{
    public function testRun()
    {
        $seconds = 2;
        $st = time();
        $cmd = $this->cmd($seconds, '');
        $resolved = $error = false;
        $process = new Process($cmd);
        $process->attach()
            ->then(
                function ($value) use (&$resolved) {
                    $resolved = $value;
                },
                function ($e) use (&$error) {
                    $error = $e;
                }
            );
        loop()->run();
        $this->assertTrue(time() - $st >= 2);
        $this->assertFalse($error);
        $this->assertNull($resolved);
    }

    public function testRunTwice()
    {
        $cmd = $this->cmd(1, '');
        $resolved = $error = false;
        $process = new Process($cmd);
        $promise = $process->attach();
        $promise->then(
                function ($value) use (&$resolved) {
                    $resolved = $value;
                },
                function ($e) use (&$error) {
                    $error = $e;
                }
            );
        $other_promise = $process->attach();
        loop()->run();
        $this->assertNull($resolved);
        $this->assertFalse($error);
        $this->assertSame($promise, $other_promise);
    }

    public function testWithPromiseCancel()
    {
        $cmd = $this->cmd(15, 'foo');
        $process = new Process($cmd);
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

    public function testOutput()
    {
        if ($this->isWindows()) {
            return $this->markTestSkipped("Windows is not supported");
        }
        $seconds = 3;
        $cmd = $this->cmd($seconds, 'foo');
        $process = new Process($cmd);
        $storage = [];
        $process->output(function ($chunk) use (&$storage) {
            $storage[] = $chunk;
        });
        $process->attach();
        loop()->run();
        $this->assertCount($seconds, $storage);
        $this->assertTrue(trim($storage[0]) === 'foo');
    }

    public function testOutputWithException()
    {
        if ($this->isWindows()) {
            return $this->markTestSkipped("Windows is not supported");
        }
        $cmd = $this->cmdThrowable(1, 'foo', 'bar');
        $process = new Process($cmd);
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

    public function testTimeout()
    {
        $cmd = $this->cmd(15, '');
        $process = new Process($cmd);
        /** @var \Throwable|\Exception|null $error */
        $error = $resolved = null;
        $process->setMaxRunningSeconds(5)
            ->attach()
            ->then(
                function () use (&$resolved) {
                    $resolved = true;
                },
                function ($throwable) use (&$error) {
                    $error = $throwable;
                }
            );
        loop()->run();
        $this->assertNull($resolved);
        $this->assertTrue(get_class($error) === TimeoutException::class);
    }

    public function testChangeMaxRunningSecondsWhileRunning()
    {
        $cmd = $this->cmd(15, '');
        $process = new Process($cmd);
        /** @var \Throwable|\Exception|null $error */
        $error = $resolved = null;
        $process->setMaxRunningSeconds(5)
            ->attach()
            ->then(
                function () use (&$resolved) {
                    $resolved = true;
                },
                function ($throwable) use (&$error) {
                    $error = $throwable;
                }
            );
        setTimeout(3, function () use ($process) {
            $process->setMaxRunningSeconds(20);
        });
        loop()->run();
        $this->assertTrue($resolved);
        $this->assertNull($error);
    }

    public function testInTime()
    {
        $cmd = $this->cmd(2, '');
        $process = new Process($cmd);
        /** @var \Throwable|\Exception|null $error */
        $error = $resolved = null;
        $process->setMaxRunningSeconds(5)
            ->attach()
            ->then(
                function () use (&$resolved) {
                    $resolved = true;
                },
                function ($throwable) use (&$error) {
                    $error = $throwable;
                }
            );
        loop()->run();
        $this->assertTrue($resolved);
        $this->assertNull($error);
    }
}