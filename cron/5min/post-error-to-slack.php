<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../../imports/Importer.php');

$error_log_cursor_file = __DIR__ . '/../../cache/error-log-cursor';
if (!file_exists($error_log_cursor_file)) {
    file_put_contents($error_log_cursor_file, '0');
}
$error_log_cursor = floatval(file_get_contents($error_log_cursor_file));

$ret = Elastic::dbQuery('/{prefix}logs-cron-*/_search', 'POST', json_encode([
    'query' => [
        'bool' => [
            'filter' => [
                'range' => [
                    'log_at' => [
                        'gt' => $error_log_cursor,
                    ],
                ],
            ],
        ],
    ],
    'size' => 1000,
    'sort' => [
        'log_at' => 'asc',
    ],
]));

$hit = null;
foreach ($ret->hits->hits as $hit) {
    $source = $hit->_source;
    if ($source->code == 0) {
        continue;
    }

    $stdout = $source->output->stdout;
    $stderr = $source->output->stderr;
    // only leave last 10 lines
    $stdout = implode("\n", array_slice(explode("\n", $stdout), -10));
    $stderr = implode("\n", array_slice(explode("\n", $stderr), -10)); 

    Importer::postToSlack(getenv('SLACK_CRON_ERROR_HOOK'), sprintf(
        "[CronError]: ID: %s Start: %s, End: %s\n"
        . "stdout:\n```%s\n```\n"
        . "stderr:\n```%s\n```",
        $source->id,
        $source->start, $source->end,
        $stdout, $stderr
    ));
}
if (!is_null($hit)) {
    $error_log_cursor = $hit->_source->log_at;
    file_put_contents($error_log_cursor_file, $error_log_cursor);
}
