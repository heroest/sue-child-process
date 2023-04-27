<?php

namespace Sue\ChildProcess\Tests;

use PHPUnit\Framework\TestCase;

abstract class BaseTest extends TestCase
{
    protected function cmd($second, $context = '')
    {
        return "php tests/commands/command.php -s={$second} -c='{$context}'";
    }

    protected function cmdThrowable($second, $context = '', $error = '')
    {
        return "php tests/commands/command_exception.php -s={$second} -c='{$context}' -e='{$error}'";
    }

    protected function isWindows()
    {
        return strtolower(substr(PHP_OS, 0, 3)) === 'win';
    }

    /**
     * 解析promise
     *
     * @param \React\Promise\PromiseInterface|\React\Promise\Promise $promise
     * @return null|mixed;
     */
    protected static function unwrapSettledPromise($promise)
    {
        $result = null;
        $closure = function ($val) use (&$result) {
            $result = $val;
        };
        $promise->done($closure, $closure);
        return $result;
    }
}