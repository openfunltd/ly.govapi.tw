<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../Importer.php');
$cmd = sprintf("wget -q -4 https://data.ly.gov.tw/odw/legislator.pdf -O %s", escapeshellarg(__DIR__ . '/legislator.pdf'));
system($cmd, $ret);
if ($ret) {
    throw new Exception("wget legislator.pdf failed");
}

$cmd = sprintf("pdftotext -layout %s %s", escapeshellarg(__DIR__ . '/legislator.pdf'), escapeshellarg(__DIR__ . '/legislator.txt'));
system($cmd, $ret);
if ($ret) {
    throw new Exception("pdftotext legislator.pdf failed");
}
unlink(__DIR__ . '/legislator.pdf');

$content = file_get_contents(__DIR__ . '/legislator.txt');
preg_match_all('#(\d{1,2})\s+(\d\d\d\d)\s+([^0-9 ]+)#u', $content, $matches);

$matches[3] = array_map('trim', $matches[3]);

$map_id = [];
foreach ($matches[1] as $k => $v) {
    $term = $v;
    $id = $matches[2][$k];
    $name = $matches[3][$k];
    $map_id["{$term}-{$name}"] = $id;
}
$map_id["鄭天財 Sra Kacaw"] = 1146;
$map_id["簡東明Uliw．Qaljupayare"] = 1103;
$map_id['廖國棟Sufin．Siluko'] = '0964';
$map_id['廖國棟Sufin‧Siluko'] = '0964';
$map_id['伍麗華Saidhai‧Tahovecahe'] = '1217';
$map_id['高潞．以用．巴魕剌Kawlo．Iyun．Pacidal'] = '1181';
$map_id['谷辣斯．尤達卡Kolas Yotaka'] = '1169';
$map_id['傅崐萁'] = '0953';
$map_id['高金素梅'] = '0930';
$map_id['羅淑蕾'] = '1085';
$map_id['章孝嚴'] = '0952';
$map_id['瓦歷斯．貝林'] = '0684';
$map_id['王雪峯'] = '0704';
$map_id['簡錫堦'] = '0763';
$map_id['巴燕．達魯'] = '0702';
$map_id['傅崐成'] = '0745';
$map_id['王建煊'] = '0594';
$map_id['高天來'] = '0548';
$map_id['趙綉娃'] = '0670';
// https://data.ly.gov.tw/getds.action?id=16
// ID: {term}-{name}
$content = Importer::getURL('https://data.ly.gov.tw/odw/usageFile.action?id=16&type=CSV&fname=16_CSV.csv');
file_put_contents(__DIR__ . '/16_CSV.csv', $content);
$fp = fopen(__DIR__ . '/16_CSV.csv', 'r');
$columns = fgetcsv($fp);
$columns[0] = 'term';
Elastic::$_show_log = false;
while ($rows = fgetcsv($fp)) {
    $values = array_combine($columns, $rows);
    unset($values['']);
    if ($values['name'] == '') {
        continue;
    }
    $term = intval($values['term']);
    if (isset($map_id["{$term}-{$values['name']}"])) {
        $bioId = intval($map_id["{$term}-{$values['name']}"]);
    } elseif (isset($map_id[$values['name']])) {
        $bioId = intval($map_id[$values['name']]);
    } else {
        throw new Exception("{$term}-{$values['name']} not found");
    }
    $values['bioId'] = $bioId;
    $values['committee'] = explode(';', trim($values['committee'], ';'));
    $values['degree'] = explode(';', trim($values['degree'], ';'));
    $values['experience'] = explode(';', trim($values['experience'], ';'));
    $values['term'] = intval($values['term']);
    Elastic::dbBulkInsert('legislator', intval($values['term']) . '-' . $values['name'], $values);
}
Elastic::dbBulkCommit();

unlink(__DIR__ . '/legislator.txt');
unlink(__DIR__ . '/16_CSV.csv');
