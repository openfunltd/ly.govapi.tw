<?php

include(__DIR__ . '/../init.inc.php');
include(__DIR__ . '/Importer.php');
// https://data.ly.gov.tw/getds.action?id=16
// ID: {term}-{name}
$content = Importer::getURL('https://data.ly.gov.tw/odw/usageFile.action?id=16&type=CSV&fname=16_CSV.csv');
file_put_contents(__DIR__ . '/16_CSV.csv', $content);
$fp = fopen(__DIR__ . '/16_CSV.csv', 'r');
$columns = fgetcsv($fp);
$columns[0] = 'term';
while ($rows = fgetcsv($fp)) {
    $values = array_combine($columns, $rows);
    unset($values['']);
    if ($values['name'] == '') {
        continue;
    }
    $values['committee'] = explode(';', trim($values['committee'], ';'));
    $values['degree'] = explode(';', trim($values['degree'], ';'));
    $values['experience'] = explode(';', trim($values['experience'], ';'));
    $values['term'] = intval($values['term']);
    Elastic::dbBulkInsert('legislator', intval($values['term']) . '-' . $values['name'], $values);
}
Elastic::dbBulkCommit();

unlink(__DIR__ . '/16_CSV.csv');
