#!/usr/bin/env php
<?php

include(__DIR__ . '/../init.inc.php');

$cmd = $_SERVER['argv'][1];

$start = microtime(true);
if (!is_file($cmd)) {
    throw new Exception("File not found: $cmd");
}
$cmd_id = implode('_', array_slice(explode('/', $cmd), -2));
$pid_file = "/tmp/lycron-{$cmd_id}.pid";
if (file_exists($pid_file)) {
    $pid = file_get_contents($pid_file);
    if ($pid and posix_kill($pid, 0)) {
        $run_time = time() - filemtime($pid_file);
        if ($run_time > 3600) {
            // Kill the stuck process group and remove the PID file so we can re-run.
            posix_kill(-$pid, SIGTERM);   // send SIGTERM to process group
            sleep(3);
            if (posix_kill($pid, 0)) {
                posix_kill(-$pid, SIGKILL); // force kill if still alive
            }
            unlink($pid_file);
            error_log("run.php: killed stuck {$cmd_id} (pid={$pid}, run_time={$run_time}s)");

            $data = [
                'id' => "{$cmd_id}-{$start}",
                "cmd_id" => $cmd_id,
                "start" => date('c', $start),
                'end' => date('c'),
                'log_at' => microtime(true),
                "delta" => 0,
                'output' => [
                    'stdout' => '',
                    'stderr' => "killed after running over 1 hour ({$run_time}s)",
                ],
                'pid' => $pid,
                'code' => 255,
            ];

            Elastic::dbBulkInsert('logs-cron-' . date('Y'), null, $data);
            Elastic::dbBulkCommit();
            // fall through — PID file is gone, will start fresh below
        } else {
            throw new Exception("Already running: $cmd");
        }
    }
}
$pid = getmypid();
file_put_contents($pid_file, $pid);

if (!is_executable($cmd)) {
    $cmd = "/usr/bin/env php $cmd";
}
$proc = proc_open($cmd, [
    0 => ['pipe', 'r'],
    1 => ['file', "/tmp/run-{$pid}-1.log", 'w'],
    2 => ['file', "/tmp/run-{$pid}-2.log", 'w'],
], $pipes);

if (is_resource($proc)) {
    fclose($pipes[0]);
    $ret = proc_close($proc);
    $stdout = file_get_contents("/tmp/run-{$pid}-1.log");
    $stderr = file_get_contents("/tmp/run-{$pid}-2.log");
    unlink("/tmp/run-{$pid}-1.log");
    unlink("/tmp/run-{$pid}-2.log");
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
    'log_at' => microtime(true),
    'output' => [
        'stdout' => $stdout,
        'stderr' => $stderr,
    ],
    'pid' => $pid,
    'code' => $ret,
];

Elastic::dbBulkInsert('logs-cron-' . date('Y'), null, $data);
Elastic::dbBulkCommit();
