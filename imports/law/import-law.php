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

$read_line = function($fp){
    $id = null;
    while ($line = fgets($fp)) {
        $line = str_replace('像先總統 蔣公遺像 蔣故總統 經國先生遺像暨元首玉照辦法,機關學校團體懸掛國旗 國父遺像總統 蔣公遺像暨元首玉照辦法', '像先總統蔣公遺像蔣故總統經國先生遺像暨元首玉照辦法,機關學校團體懸掛國旗國父遺像總統蔣公遺像暨元首玉照辦法', $line);
        $line = str_replace('機關學校團體懸掛國旗 國父', '機關學校團體懸掛國旗國父', $line);

        if (preg_match('#(\d\d\d\d\d\d\d\d)\s+(.*)#', $line, $matches)) {
            if (!is_null($id)) {
                $name = preg_replace_callback('#\s*([A-Z0-9]+)\s*#', function($matches){
                    return $matches[1];
                }, $name);
                yield $id => $name;
            }
            $id = $matches[1];
            $name = ltrim($matches[2]);
        } else {
            // remove ^L
            $line = preg_replace('#\x0c#', '', $line);
            $name .= trim($line);
        }
    }
    yield $id => $name;
};

$filename = __DIR__ . '/law.pdf';

if (false) {
$url = "https://data.ly.gov.tw/odw/LawNo.pdf";
system(sprintf("wget -4 -O %s %s", escapeshellarg($filename), escapeshellarg($url)), $ret);
if ($ret != 0) {
    die("download failed\n");
}

system(sprintf("pdftotext -layout %s %s", escapeshellarg($filename), escapeshellarg($filename . '.txt')), $ret);
}

$fp = fopen($filename . '.txt', 'r');
foreach ($read_line($fp) as $id => $name) {
    $names = preg_split('#\s+#', $name);
    if (count($names) > 2) {
        print_r($names);
        exit;
    }
    if (count($names) == 1) {
        $names[] = [];
    } else {
        $names[1] = explode(',', $names[1]);
    }
    $data = [
        'id' => $id,
        'type' => '',
        'parent' => '',
        'name' => $names[0],
        'name_other' => $names[1],
    ];

    if ($id % 1000 == 0) {
        $data['id'] = sprintf("%05d", $id / 1000);
        $data['type'] = '母法';
    } else {
        $data['parent'] = sprintf("%05d", floor($id / 1000));
        $data['type'] = '子法';
    }
    $law_data_dir = __DIR__ . "/law-data/laws/{$data['id']}";
    if (file_exists($law_data_dir)) {
        $data['versions'] = LawLib::getVersionsByDir($law_data_dir);
    }
    if (array_key_exists($data['id'], $laws)) {
        $data['categories'] = $laws[$data['id']]['categories'];
        $data['status'] = $laws[$data['id']]['狀態'];
        unset($laws[$data['id']]);
    }
    Elastic::dbBulkInsert('law', $data['id'], $data);
}
error_log("laws not found: " . json_encode(array_keys($laws)));
Elastic::dbBulkCommit();
