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
    if (!array_key_exists('first_version', $laws[$rows[0]])) {
        $laws[$rows[0]]['first_version'] = LawLib::getVersionIdFromString($rows[2], $rows[0]);
        $laws[$rows[0]]['first_version']['version_id'] = "{$laws[$rows[0]]['first_version']['date']}-{$laws[$rows[0]]['first_version']['action']}";
    }
    $laws[$rows[0]]['version'] = LawLib::getVersionIdFromString($rows[2], $rows[0]);
    $laws[$rows[0]]['version']['version_id'] = "{$laws[$rows[0]]['version']['date']}-{$laws[$rows[0]]['version']['action']}";
}
fclose($fp);

$target = __DIR__ . '/../../cache/301-law.csv';
$url = 'https://data.ly.gov.tw/odw/usageFile.action?id=301&type=CSV&fname=301_CSV.csv';
$tmp_target = __DIR__ . '/../../cache/301-law.csv.tmp';
$cmd = sprintf("curl -4 -o %s %s", escapeshellarg($tmp_target), escapeshellarg($url));
system($cmd);
if (!filesize($tmp_target)) {
    throw new Exception("download failed");
} elseif (md5_file($tmp_target) != md5_file($target)) {
    rename($tmp_target, $target);
} else {
    unlink($tmp_target);
}

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
        $data['first_version'] = $laws[$data['id']]['first_version'];
        unset($laws[$data['id']]);
    }

    if ($date = BillParser::checkLISCLosedBill($data['id'])) {
        $data['latest_version'] = [
            'date' => $date,
            'version_id' => null,
            'action' => null,
        ];
    }
    Elastic::dbBulkInsert('law', $data['id'], $data);
}
error_log("laws not found: " . json_encode(array_keys($laws)));
Elastic::dbBulkCommit();
