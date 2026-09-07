<?php

include(__DIR__ . '/../../init.inc.php');

$script = __DIR__ . '/../../imports/ivod/upsert-speaker-embed.py';
$log    = sys_get_temp_dir() . '/speaker-embed.log';

$cmd = sprintf('python3 %s --days 50 >> %s 2>&1',
    escapeshellarg($script),
    escapeshellarg($log)
);

error_log('speaker-embed: start');
system($cmd, $ret);
error_log(sprintf('speaker-embed: done (exit=%d)', $ret));
