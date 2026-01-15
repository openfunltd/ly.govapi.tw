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
    error_log("page: $p, items: " . count($json->jsonList));
    foreach ($json->jsonList as $meet) {
        if ($meet->meetingNo == '2026010533') {
            $meet->meetingName = '第11屆第4會期社會福利及衛生環境、司法及法制委員會第2次聯席會議';
        }
        file_put_contents(__DIR__ . '/../../cache/42-meet.jsonl', json_encode($meet, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }
    if (count($json->jsonList) < 10) {
        break;
    }
}
