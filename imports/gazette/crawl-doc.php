<?php

include(__DIR__ . '/../../init.inc.php');

$cmd = [
    'size' => 10000,
    'sort' => [
        'comYear' => 'desc',
        'comVolume' => 'desc',
        'comBookId' => 'desc',
        'agendaNo' => 'desc',
    ],
];
$obj = Elastic::dbQuery("/{prefix}gazette_agenda/_search", 'GET', json_encode($cmd));
foreach ($obj->hits->hits as $hit) {
    if (getenv('year') and $hit->_source->comYear + 1911 < getenv('year')) {
        continue;
    }
    $source = $hit->_source;
    $source = LYLib::buildGazetteAgenda($source);
    if (strpos($source->subject, '質詢事項') !== false or
        strpos($source->subject, '行政院答復部分') !== false or
        strpos($source->subject, '本院委員質詢部分') !== false or
        false) {
        // 質詢相關另外在 interpellation 處理
        continue;
    }
    if (strpos($source->subject, '本期委員發言紀錄索引') !== false) {
        // 發言索引不處理
        continue;
    }
    foreach ($source->docUrls as $docUrl) {
        $docfilename = basename($docUrl);

        $docfilepath = __DIR__ . "/docfile/{$docfilename}";
        if (!file_exists($docfilepath)) {
            system(sprintf("wget -4 -O %s %s", escapeshellarg(__DIR__ . "/tmp.doc"), escapeshellarg($docUrl)), $ret);
            if ($ret) {
                print_r($source);
                throw new Exception('wget failed');
            }
            copy(__DIR__ . "/tmp.doc", $docfilepath);
            unlink(__DIR__ . "/tmp.doc");
        }

        if (!file_exists(__DIR__ . "/agenda-html/{$docfilename}.html") or filesize(__DIR__ . "/agenda-html/{$docfilename}.html") == 0){
            LYLib::getAgendaHTML($docUrl);
            //exit;
        }
        if (!file_exists(__DIR__ . "/agenda-tikahtml/{$docfilename}.html") or filesize(__DIR__ . "/agenda-tikahtml/{$docfilename}.html") == 0){
            error_log("tika " . $docfilename);
            $cmd = sprintf("curl -T %s https://tika.openfun.dev/tika -H 'Accept: text/html' > %s", escapeshellarg($docfilepath), escapeshellarg(__DIR__ . '/tmp.txt'));
            system($cmd, $ret);
            if ($ret) {
                throw new Exception('tika failed');
            }
            rename(__DIR__ . '/tmp.txt', __DIR__ . "/agenda-tikahtml/{$docfilename}.html");
        }
        continue;

        try {
            LYLib::parseTxtFile($docfilename, __DIR__);
        } catch (Exception $e) {
            print_r($source);
            throw $e;
        }

        $txtfile = __DIR__ . "/txtfile/{$docfilename}";
        if (!file_exists($txtfile)) {
            continue;
        }
        $info = GazetteParser::parse(file_get_contents($txtfile));
        $info->blocks = array_slice($info->blocks, 0, 3);
        if (!property_exists($info, 'subject')) {
            $info->title = $source->subject;
        }
        unset($info->block_lines);
        echo json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        echo json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        readline('continue');
    }
}
