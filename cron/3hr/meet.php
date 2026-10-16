<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../../imports/Importer.php');

$year = getenv('year');

if (!$year) {
    $year = date('Y');
    putenv('year=' . $year);
}

foreach ([
    'meet/crawl-meet.php',
    'meet/crawl-meet-ppg.php',   // PPG 開會日曆補足 open data 42 的延遲，合併寫回 42-meet.jsonl
    'meet/crawl-ppg-page.php',
    'meet/parse-meet-from-gazette.php',
    'meet/crawl-meet-proceeding.php',
    'meet/parse-meet-proceeding.php',
    'meet/crawl-meet-speechlist.php',
    'meet/parse-speech-from-gazette.php',
    'meet/enrich-gazette-pdf-links.php',
    'meet/link-meet.php',
] as $script) {
    $cmd = sprintf("php %s",
        escapeshellarg(__DIR__ . '/../../imports/' . $script)
    );
    system($cmd, $ret);
    if ($ret != 0) {
        throw new Exception("Failed to run $cmd");
    }
}
