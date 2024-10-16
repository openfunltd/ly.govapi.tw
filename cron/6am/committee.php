<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../../imports/Importer.php');
// https://data.ly.gov.tw/getds.action?id=13
// ID: {comtcd}
$content = Importer::getURL('https://data.ly.gov.tw/odw/usageFile.action?id=13&type=CSV&fname=13_CSV.csv');
$target = __DIR__ . '/../../cache/13-committee.csv';
if (file_exists($target) and md5_file($target) == md5($content)) {
    error_log('No update.');
    exit;
}
if (file_exists($target)) {
    $bak_filename = sprintf("%s.bak.%s", $target, date('YmdHi', filemtime($target)));
    rename($target, $bak_filename);
    Importer::addImportLog([
        'event' => 'committees-change',
        'group' => 'committees',
        'message' => sprintf("委員會資料更新，舊資料檔名改為 %s", basename($bak_filename)),
    ]);
}
file_put_contents($target, $content);
$fp = fopen($target, 'r');
$columns = fgetcsv($fp);
$columns[0] = 'comtCd';
while ($rows = fgetcsv($fp)) {
    $values = array_combine($columns, $rows);
    unset($values['']);
    $values['comtCd'] = intval($values['comtCd']);
    $values['comtType'] = intval($values['comtType']);
    Elastic::dbBulkInsert('committee', intval($values['comtCd']), $values);
}
Elastic::dbBulkCommit();
