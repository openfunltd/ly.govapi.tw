<?php

include(__DIR__ . '/../init.inc.php');
include(__DIR__ . '/Importer.php');

for ($term = 8;; $term ++) {
    error_log($term);
    // https://data.ly.gov.tw/getds.action?id=41
    for ($period = 1; $period <= 8; $period ++) {
        $target = sprintf(__DIR__ . "/../cache/41-gazette-%02d%02d.csv", $term, $period);
        if (file_exists($target) and getenv('term') and $term == getenv('term')) {
            error_log("backup old term: $term");
            rename($target, $target . '.old');
        }
        if (!file_exists($target)) {
            $url = sprintf("https://data.ly.gov.tw/odw/usageFile.action?id=41&type=CSV&fname=41_%02d%02dCSV-1.csv", $term, $period);
            error_log("importing $url");
            try {
                $content = Importer::getURL($url);
            } catch (Exception $e) {
                break 2;
            }
            file_put_contents($target, $content);
        }
        $list_files[] = $target;
    }
}

$intval_and_checking = function ($v) {
    if ('null' === $v) {
        return null;
    }
    if (preg_match('#^[0-9]+$#', $v)) {
        return intval($v);
    }
    throw new Exception("{$v} is not a number");
};

$get_meetingdate = function($agenda) {
    if (preg_match('#^\d+$#', $agenda['meetingDate'], $matches)) {
        if (strlen($agenda['meetingDate']) % 7 != 0) {
            echo json_encode($agenda, JSON_UNESCAPED_UNICODE) . "\n";
            throw new Exception('wrong meetingDate: ' . $agenda['meetingDate']);
        }
        $dates = [];
        foreach (str_split($agenda['meetingDate'], 7) as $date) {
            $dates[] = sprintf("%04d-%02d-%02d", 1911 + intval(substr($date, 0, 3)), substr($date, 3, 2), substr($date, 5, 2));
        }
        return $dates;
    } elseif (trim($agenda['meetingDate']) == '' or 'Wrong' == $agenda['meetingDate']) {
        return [];
    }
    echo json_encode($agenda, JSON_UNESCAPED_UNICODE) . "\n";
    throw new Exception('unknown meetingDate');
};

$agendas = [];
$agendas_doc = [];
$gazettes = [];

foreach ($list_files as $file) {
    $fp = fopen($file, 'r');
    $cols = fgetcsv($fp);
    $cols[0] = 'comYear';

    $lineno = 0;
    while ($rows = fgetcsv($fp)) {
        $lineno ++;
        if (count($cols) != count($rows)) {
            echo json_encode($cols, JSON_UNESCAPED_UNICODE) . "\n";
            echo json_encode($rows, JSON_UNESCAPED_UNICODE) . "\n";
            throw new Exception('cols not match rows: ' . $lineno);
        }
        $agenda = array_combine($cols, $rows);
        // {"comYear":"112","comVolume":"21","comBookId":"01","term":"10","sessionPeriod":"07","sessionTimes":"01","meetingTimes":"null","agendaNo":"1","agendaType":"1","meetingDate":"1120217","subject":"\u5831\u544a\u4e8b\u9805","pageStart":"     1","pageEnd":"     9","docUrl":"https:\/\/ppg.ly.gov.tw\/ppg\/download\/communique1\/work\/112\/21\/LCIDC01_1122101_00002.doc","selectTerm":"1007"}
        $agenda['pageStart'] = trim($agenda['pageStart']);
        $agenda['pageEnd'] = trim($agenda['pageEnd']);
        $agenda['meetingDate'] = $get_meetingdate($agenda);

        foreach (['comYear', 'comVolume', 'comBookId', 'term', 'sessionPeriod', 'sessionTimes', 'meetingTimes', 'agendaNo', 'agendaType', 'pageStart', 'pageEnd'] as $c) {
            try {
                $agenda[$c] = $intval_and_checking($agenda[$c]);
            } catch (Exception $e) {
                echo json_encode($agenda, JSON_UNESCAPED_UNICODE) . "\n";
                throw $e;
            }
        }
        $agenda_id = sprintf("%03d%02d%02d_%05d", $agenda['comYear'], $agenda['comVolume'], $agenda['comBookId'], $agenda['agendaNo']);
        $agenda['agenda_id'] = $agenda_id;
        $gazette_id = sprintf("%03d%02d%02d", $agenda['comYear'], $agenda['comVolume'], $agenda['comBookId']);
        $agenda['gazette_id'] = $gazette_id;
        if (!array_key_exists($agenda_id, $agendas_doc)) {
            $agendas_doc[$agenda_id] = [];
        }
        $agendas_doc[$agenda_id][] = $agenda['docUrl'];

        unset($agenda['docUrl']);
        unset($agenda['']);

        $gazette = [];
        foreach (['comYear', 'comVolume', 'comBookId'] as $c) {
            // 公報可能跨會期，Ex: LCIDC01_1079001 會有 0905, 0906, LCIDC01_1095701 會有 0908, 1001
            $gazette[$c] = $agenda[$c];
        }

        // TODO: 公報可以加上「出版日期」，可以從 https://ppg.ly.gov.tw/ppg/publications/official-gazettes/109/57/01/details 網頁抓取
        if (array_key_exists($agenda['agenda_id'], $agendas)) {
            if ($agendas[$agenda['agenda_id']] != $agenda) {
                echo json_encode($agenda, JSON_UNESCAPED_UNICODE) . "\n";
                echo json_encode($agendas[$agenda['agenda_id']], JSON_UNESCAPED_UNICODE) . "\n";
                throw new Exception('agenda not match');
            }
        } else {
            $agendas[$agenda['agenda_id']] = $agenda;
        }

        // fix data
        if ($agenda['agenda_id'] == '1134703_00002') {
            $agenda['meetingDate'] = ['2024-05-24'];
        } elseif ($agenda['agenda_id'] == '1135102_00004') {
            $agenda['meetingDate'] = ['2024-05-31'];
        }
        $data_file = __DIR__ . "/gazette/gazette-agenda-data/{$agenda_id}.json";
        if (!file_exists($data_file)) {
            file_put_contents($data_file, json_encode($agenda, JSON_UNESCAPED_UNICODE));
            Elastic::dbBulkInsert('gazette_agenda', $agenda['agenda_id'], array_merge($agenda, [
                'docUrls' => $agendas_doc[$agenda['agenda_id']],
            ]));
        }

        if (array_key_exists($agenda['gazette_id'], $gazettes)) {
            if ($gazettes[$agenda['gazette_id']] != $gazette) {
                echo json_encode($gazette, JSON_UNESCAPED_UNICODE) . "\n";
                echo json_encode($gazettes[$agenda['gazette_id']], JSON_UNESCAPED_UNICODE) . "\n";
                throw new Exception('gazette not match');
            }
        } else {
            $gazettes[$agenda['gazette_id']] = $gazette;

            $detail_page_file = __DIR__ . '/gazette/gazette-detail-html/' . $gazette_id . '.html';
            $detail_page_url = sprintf("https://ppg.ly.gov.tw/ppg/publications/official-gazettes/%03d/%02d/%02d/details", $agenda['comYear'], $agenda['comVolume'], $agenda['comBookId']);
            if (!file_exists($detail_page_file)) {
                error_log($detail_page_url);
                $detail_page_content = Importer::getURL($detail_page_url);
                file_put_contents($detail_page_file, $detail_page_content);
            }
            $doc = new DOMDocument;
            @$doc->loadHTMLFile($detail_page_file);
            foreach ($doc->getElementsByTagName('h6') as $h6_dom) {
                if (preg_match('#出版日期\s+(\d{3})年(\d{1,2})月(\d{1,2})日#', $h6_dom->textContent, $matches)) {
                    $gazette['published_at'] = sprintf("%04d-%02d-%02d", 1911 + $matches[1], $matches[2], $matches[3]);
                    break;
                }
            }
            if (!$gazette['published_at']) {
                echo json_encode($gazette, JSON_UNESCAPED_UNICODE) . "\n";
                error_log('no published_at: ' . $detail_page_url);
                //readline('continue?');
                //throw new Exception('no published_at: ' . $detail_page_url);
            }
            $data_file = __DIR__ . "/gazette/gazette-data/{$gazette_id}.json";
            if (!file_exists($data_file)) {
                file_put_contents($data_file, json_encode($gazette, JSON_UNESCAPED_UNICODE));
                Elastic::dbBulkInsert('gazette', $agenda['gazette_id'], $gazette);
                Importer::addImportLog([
                    'event' => 'gazette-new',
                    'group' => 'gazette',
                    'message' => sprintf("公報資料更新，公報編號 %s", $agenda['gazette_id']),
                    'data' => json_encode([
                        'gazette_id' => $agenda['gazette_id'],
                        'published_at' => $gazette['published_at'],
                    ]),
                ]);
            }
        }
    }
}
Elastic::dbBulkCommit();
