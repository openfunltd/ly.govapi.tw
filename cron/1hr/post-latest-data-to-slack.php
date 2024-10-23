<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../../imports/Importer.php');

$cursor_file = __DIR__ . '/../../cache/import-data-cursor';
if (!file_exists($cursor_file)) {
    file_put_contents($cursor_file, '0');
}
$cursor = floatval(file_get_contents($cursor_file));

$ret = Elastic::dbQuery('/{prefix}logs-import-*/_search', 'POST', json_encode([
    'query' => [
        'bool' => [
            'filter' => [
                'range' => [
                    'log_at' => [
                        'gt' => $cursor,
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
$groups = [];
foreach ($ret->hits->hits as $hit) {
    $source = $hit->_source;
    $groups[$source->group] = $groups[$source->group] ?? [];
    $groups[$source->group][] = $source;
}

foreach ($groups as $group_id => $imports) {
    $list = [];
    foreach ($imports as $import) {
        $list[] = sprintf("- [%s] %s: %s", date('H:i', $import->log_at), $import->event, $import->message);
    }
    $message = sprintf(
        "[%s]: 時間: %s - %s\n",
        $group_id,
        date('H:i', $imports[0]->log_at),
        date('H:i', $imports[count($imports) - 1]->log_at)
    );
    $message .= implode("\n", $list);

    Importer::postToSlack(getenv('SLACK_DATA_IMPORT_HOOK'), $message);
}

if (!is_null($hit)) {
    $cursor = $hit->_source->log_at;
    file_put_contents($cursor_file, $cursor);
}
