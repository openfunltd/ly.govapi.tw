<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../Importer.php');

file_put_contents(__DIR__ . '/../../cache/42-meet.jsonl', '');
for ($p = 1; ; $p ++) {
    $url = sprintf("https://data.ly.gov.tw/odw/openDatasetJson.action?id=42&selectTerm=all&page=%d", $p);
    error_log($url);
    for ($retry = 0; $retry < 3; $retry ++) {
        try {
            $content = Importer::getURL($url, 30);
        } catch (Exception $e) {
            continue;
        }
        $json = json_decode($content);
        if (!$json) {
            error_log("fetch $url failed, retry...");
            sleep(60);
            continue;
        }
        break;
    }
    if (!$json) {
        error_log("fetch $url failed");
        break;
    }
    foreach ($json->jsonList as $meet) {
        file_put_contents(__DIR__ . '/../../cache/42-meet.jsonl', json_encode($meet, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }
    if (count($json->jsonList) < 10) {
        break;
    }
}
