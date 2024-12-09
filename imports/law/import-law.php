<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../../LawLib.php');

$laws = [];
$fp = fopen(__DIR__ . "/law-data/laws.csv", 'r');
$cols = fgetcsv($fp);
while ($rows = fgetcsv($fp)) {
    $laws[$rows[0]] = array_combine($cols, $rows);
    $laws[$rows[0]]['categories'] = [];
}
fclose($fp);

$fp = fopen(__DIR__ . "/law-data/laws-category.csv", 'r');
$cols = fgetcsv($fp);
while ($rows = fgetcsv($fp)) {
    $laws[$rows[3]]['categories'][] = $rows[0];
}
fclose($fp);

$fp = fopen(__DIR__ . "/law-data/laws-versions.csv", 'r');
$cols = fgetcsv($fp);
while ($rows = fgetcsv($fp)) {
    $laws[$rows[0]]['version'] = LawLib::getVersionIdFromString($rows[2], $rows[0]);
    $laws[$rows[0]]['version']['version_id'] = "{$laws[$rows[0]]['version']['date']}-{$laws[$rows[0]]['version']['action']}";
}
fclose($fp);

$url = 'https://data.ly.gov.tw/odw/usageFile.action?id=301&type=CSV&fname=301_CSV.csv';
$target = __DIR__ . '/../../cache/301-law.csv';

$fp = fopen($target, 'r');
$cols = fgetcsv($fp);
$cols[0] = 'lawNumber';
error_log("start");
while ($rows = fgetcsv($fp)) {
    $values = array_combine($cols, $rows);
    $id = $values['lawNumber'];
    $name = $values['law'];
    $names = $values['usedFor'] ? explode(';', $values['usedFor']) : [];
    $name_aka = $values['lawSynonym'] ? explode(';', $values['lawSynonym']) : [];

    if (!preg_match('#^\d\d\d\d\d\d\d\d$#', $id)) {
        var_dump($rows);
        throw new Exception("id not found: " . $id);
    }
    $data = [
        'id' => $id,
        'type' => '',
        'parent' => '',
        'name' => $name,
        'name_other' => $names,
        'name_aka' => $name_aka,
    ];

    if ($id % 1000 == 0) {
        $data['id'] = sprintf("%05d", $id / 1000);
        $data['type'] = '母法';
    } else {
        $data['parent'] = sprintf("%05d", floor($id / 1000));
        $data['type'] = '子法';
    }
    $law_data_dir = __DIR__ . "/law-data/laws/{$data['id']}";
    if (array_key_exists($data['id'], $laws)) {
        $data['categories'] = $laws[$data['id']]['categories'];
        $data['status'] = $laws[$data['id']]['狀態'];
        $data['latest_version'] = $laws[$data['id']]['version'];
        unset($laws[$data['id']]);
    }
    Elastic::dbBulkInsert('law', $data['id'], $data);
}
error_log("laws not found: " . json_encode(array_keys($laws)));
Elastic::dbBulkCommit();
