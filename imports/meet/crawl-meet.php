<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../Importer.php');

file_put_contents(__DIR__ . '/meet.jsonl', '');
for ($p = 1; ; $p ++) {
    $url = sprintf("https://data.ly.gov.tw/odw/openDatasetJson.action?id=42&selectTerm=all&page=%d", $p);
    error_log($url);
    $content = Importer::getURL($url);
    $json = json_decode($content);
    foreach ($json->jsonList as $meet) {
        file_put_contents(__DIR__ . '/meet.jsonl', json_encode($meet, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }
    if (count($json->jsonList) < 10) {
        break;
    }
}
