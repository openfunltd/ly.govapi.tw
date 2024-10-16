#!/usr/bin/env php
<?php

$type = $_SERVER['argv'][1];

foreach (glob(__DIR__ . '/' . $type . '/*') as $cronfile) {
    $pid = pcntl_fork();
    if ($pid == -1) {
        die('could not fork');
    } else if ($pid) {
        // we are the parent
    } else {
        // we are the child
        system(sprintf("php %s %s &", escapeshellarg(__DIR__ . '/run.php'), escapeshellarg($cronfile)), $ret);
        break;
    }
}
while ($pid = pcntl_waitpid(0, $status)) {
    if ($pid == -1) {
        break;
    }
}
