<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../../imports/Importer.php');

$pdf_target = __DIR__ . '/../../cache/legislator.pdf';
$csv_all_target = __DIR__ . '/../../cache/16-legislator.csv';
$csv_cur_target = __DIR__ . '/../../cache/9-legislator.csv';

$cmd = sprintf("curl -4 -L -o %s https://data.ly.gov.tw/odw/legislator.pdf",
    escapeshellarg('/tmp/legislator.pdf')
);
system($cmd, $ret);
if ($ret) {
    throw new Exception("wget legislator.pdf failed");
}

$content_all = Importer::getURL('https://data.ly.gov.tw/odw/usageFile.action?id=16&type=CSV&fname=16_CSV.csv');
$no_change = false;
if (!file_exists($pdf_target)) {
} elseif (!file_exists($csv_all_target)) {
} elseif (md5_file($pdf_target) != md5_file('/tmp/legislator.pdf')) {
} elseif (md5($content_all) != md5_file($csv_all_target)) {
} else {
    $no_change = true;
}

$content_cur = Importer::getURL('https://data.ly.gov.tw/odw/usageFile.action?id=9&type=CSV&fname=9_CSV.csv');
if (!file_exists($csv_cur_target)) {
    $no_change = false;
} elseif (md5($content_cur) != md5_file($csv_cur_target)) {
    $no_change = false;
}

if ($no_change) {
    error_log("no change");
    exit;
}

file_put_contents($csv_cur_target, $content_cur);
$fp = fopen($csv_cur_target, 'r');
$cols = fgetcsv($fp);
$cols[0] = 'term';
$current_data = [];
while ($rows = fgetcsv($fp)) {
    $values = array_combine($cols, $rows);
    unset($values['']);

    $current_data[$values['term'] . '-' . $values['name']] = $values;
}
fclose($fp);

if (file_exists($pdf_target) and md5_file($pdf_target) != md5_file('/tmp/legislator.pdf')) {
    $bak_filename = sprintf("%s.bak.%s", $pdf_target, date('YmdHi', filemtime($pdf_target)));
    rename($pdf_target, $bak_filename);
    Importer::addImportLog([
        'event' => 'legislators-change',
        'group' => 'legislator',
        'message' => sprintf("立委終身代號更新，舊資料檔名改為 %s", basename($bak_filename)),
    ]);
    rename('/tmp/legislator.pdf', $pdf_target);
} elseif (!file_exists($pdf_target)) {
    rename('/tmp/legislator.pdf', $pdf_target);
}

if (file_exists($csv_all_target) and md5($content_all) != md5_file($csv_all_target)) {
    $bak_filename = sprintf("%s.bak.%s", $csv_all_target, date('YmdHi', filemtime($csv_all_target)));
    rename($csv_all_target, $bak_filename);
    Importer::addImportLog([
        'event' => 'legislators-change',
        'group' => 'legislator',
        'message' => sprintf("立委資料更新，舊資料檔名改為 %s", basename($bak_filename)),
    ]);
    file_put_contents($csv_all_target, $content_all);
} elseif (!file_exists($csv_all_target)) {
    file_put_contents($csv_all_target, $content_all);
}


$cmd = sprintf("pdftotext -layout %s %s",
    escapeshellarg($pdf_target),
    escapeshellarg($pdf_target . '.txt')
);
system($cmd, $ret);
if ($ret) {
    throw new Exception("pdftotext legislator.pdf failed");
}

$content = file_get_contents($pdf_target . '.txt');
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
$map_id['鄭天財Sra Kacaw'] = 1146;
$map_id['江啟臣'] = 1116;
$map_id['沈發惠'] = 1015;
$map_id['林國成'] = 1268;
$map_id['邱若華'] = 1270;
$map_id['陳俊宇'] = '1282';
$map_id['11-黃國昌'] = '1190';
$map_id['賴惠員'] = '1250';
$map_id['11-謝龍介'] = 1298;
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
$fp = fopen($csv_all_target, 'r');
$columns = fgetcsv($fp);
$columns[0] = 'term';
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
        $bioId = null;
        error_log("{$term}-{$values['name']} not found");
    }
    $values['bioId'] = $bioId;
    $values['committee'] = explode(';', trim($values['committee'], ';'));
    $values['degree'] = explode(';', trim($values['degree'], ';'));
    $values['experience'] = explode(';', trim(trim($values['experience']), ';'));
    $values['experience'] = array_values(array_filter($values['experience'], function($v) {
        return trim($v) !== '';
    }));
    $values['term'] = intval($values['term']);
    if (isset($current_data[$values['term'] . '-' . $values['name']])) {
        $cur_values = $current_data[$values['term'] . '-' . $values['name']];
        $values['tel'] = explode(';', trim($cur_values['tel'], ';'));
        $values['fax'] = explode(';', trim($cur_values['fax'], ';'));
        $values['addr'] = explode(';', trim($cur_values['addr'], ';'));
    }
    Elastic::dbBulkInsert('legislator', intval($values['term']) . '-' . $values['name'], $values);
}
Elastic::dbBulkCommit();
