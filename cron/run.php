#!/usr/bin/env php
<?php

include(__DIR__ . '/../init.inc.php');

$cmd = $_SERVER['argv'][1];

$start = microtime(true);
if (!is_file($cmd)) {
    throw new Exception("File not found: $cmd");
}
$cmd_id = implode('_', array_slice(explode('/', $cmd), -2));

if (!is_executable($cmd)) {
    $cmd = "/usr/bin/env php $cmd";
}
$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (is_resource($proc)) {
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $ret = proc_close($proc);
} else {
    $ret = 1;
}

$delta = microtime(true) - $start;

$data = [
    'id' => "{$cmd_id}-{$start}",
    "cmd_id" => $cmd_id,
    "start" => date('c', $start),
    'end' => date('c'),
    "delta" => $delta,
    'output' => [
        'stdout' => $stdout,
        'stderr' => $stderr,
    ],
    'code' => $ret,
];

Elastic::dbBulkInsert('logs-cron-' . date('Y'), null, $data);
Elastic::dbBulkCommit();
