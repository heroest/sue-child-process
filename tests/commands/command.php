<?php

$options = getopt('s:c:');
$seconds = $options['s'];
$context = $options['c'];

$st = time();
do {
    echo $context . "\n";
    sleep(1);
} while (time() - $st < $seconds);