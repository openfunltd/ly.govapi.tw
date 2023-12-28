<?php

include(__DIR__ . '/../init.inc.php');
include(__DIR__ . '/Importer.php');
// https://data.ly.gov.tw/getds.action?id=13
// ID: {comtcd}
$content = Importer::getURL('https://data.ly.gov.tw/odw/usageFile.action?id=13&type=CSV&fname=13_CSV.csv');
file_put_contents(__DIR__ . '/13_CSV.csv', $content);
$fp = fopen(__DIR__ . '/13_CSV.csv', 'r');
$columns = fgetcsv($fp);
$columns[0] = 'comtCd';
Elastic::$_show_log = false;
while ($rows = fgetcsv($fp)) {
    $values = array_combine($columns, $rows);
    unset($values['']);
    $values['comtCd'] = intval($values['comtCd']);
    $values['comtType'] = intval($values['comtType']);
    Elastic::dbBulkInsert('committee', intval($values['comtCd']), $values);
}
Elastic::dbBulkCommit();

unlink(__DIR__ . '/13_CSV.csv');
