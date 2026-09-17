<?php
// Targeted re-import for one IVOD clip (by ID).
// Usage: php reimport-one.php <id>
// Overwrites ivod-data/<id>.json and updates DB regardless of whether data changed.

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/IVodParser.php');
include(__DIR__ . '/../../imports/Importer.php');

$v = intval($argv[1] ?? 0);
if ($v <= 0) {
    fwrite(STDERR, "Usage: php reimport-one.php <clip_id>\n");
    exit(1);
}

$html_target = __DIR__ . "/html/{$v}.html";
if (!file_exists($html_target)) {
    fwrite(STDERR, "HTML not found: $html_target\n");
    exit(1);
}

$content = file_get_contents($html_target);
$ivod = IVodParser::parseHTML($v, $content, 'Clip');
echo "Parsed: 委員名稱={$ivod->{'委員名稱'}}, start={$ivod->start_time}, end={$ivod->end_time}\n";

$error_name = [];
if (!$ivod = IVodParser::checkMeetFromIVOD($ivod, $error_name)) {
    fwrite(STDERR, "checkMeetFromIVOD failed. Errors: " . implode(', ', $error_name) . "\n");
    exit(1);
}
$ivod->features = [];

$ivod_target_file = __DIR__ . "/ivod-data/{$v}.json";
$old_json = file_exists($ivod_target_file) ? file_get_contents($ivod_target_file) : 'null';
$new_json = json_encode($ivod, JSON_UNESCAPED_UNICODE);

if ($new_json === $old_json) {
    echo "No change detected — nothing to do.\n";
    exit(0);
}

file_put_contents($ivod_target_file, $new_json);
echo "Wrote $ivod_target_file\n";

Elastic::dbBulkInsert('ivod', $ivod->id, $ivod);
Elastic::dbBulkCommit();
echo "DB updated for ivod id={$ivod->id}\n";

Importer::addImportLog([
    'event' => 'ivod-change',
    'group' => 'ivod',
    'message' => sprintf("手動重新匯入 ivod: %s", $ivod->id),
    'data' => json_encode([
        'ivod_id' => $ivod->id,
        '委員名稱' => $ivod->{'委員名稱'},
        'meet_id' => $ivod->meet->id,
        '會議名稱' => $ivod->{'會議名稱'},
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
], $commit = true);

echo "Done.\n";
