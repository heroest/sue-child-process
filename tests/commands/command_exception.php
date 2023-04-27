<?php

$options = getopt('s:c:e:');
$seconds = $options['s'];
$context = $options['c'];
$exception = $options['e'];

$st = time();
do {
    echo $context . "\n";
    sleep(1);
} while (time() - $st < $seconds);
throw new \RuntimeException($exception);