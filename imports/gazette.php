<?php

include(__DIR__ . '/../init.inc.php');
include(__DIR__ . '/Importer.php');

for ($term = 8;; $term ++) {
    for ($period = 1; $period <= 8; $period ++) {
        $target = sprintf(__DIR__ . "/gazette/%02d%02d.csv", $term, $period);
        if (!file_exists($target)) {
            $url = sprintf("https://data.ly.gov.tw/odw/usageFile.action?id=41&type=CSV&fname=41_%02d%02dCSV-1.csv", $term, $period);
            $content = Importer::getURL($url);
            if (strpos($content, '403 Forbidden') !== false) {
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
$gazettes = [];

foreach ($list_files as $file) {
    $fp = fopen($file, 'r');
    $cols = fgetcsv($fp);
    $cols[0] = 'comYear';

    while ($rows = fgetcsv($fp)) {
        if (count($cols) != count($rows)) {
            echo json_encode($cols, JSON_UNESCAPED_UNICODE) . "\n";
            echo json_encode($rows, JSON_UNESCAPED_UNICODE) . "\n";
            throw new Exception('cols not match rows');
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
        $agenda_id = sprintf("LCIDC01_%03d%02d%02d_%05d", $agenda['comYear'], $agenda['comVolume'], $agenda['comBookId'], $agenda['agendaNo'] + 1);
        $agenda['agenda_id'] = $agenda_id;
        $gazette_id = sprintf("LCIDC01_%03d%02d%02d", $agenda['comYear'], $agenda['comVolume'], $agenda['comBookId']);
        $agenda['gazette_id'] = $gazette_id;
        if (strpos($agenda['docUrl'], $agenda['agenda_id']) === false) {
            continue;
        }

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
            Elastic::dbBulkInsert('gazette_agenda', $agenda['agenda_id'], $agenda);
        }

        if (array_key_exists($agenda['gazette_id'], $gazettes)) {
            if ($gazettes[$agenda['gazette_id']] != $gazette) {
                echo json_encode($gazette, JSON_UNESCAPED_UNICODE) . "\n";
                echo json_encode($gazettes[$agenda['gazette_id']], JSON_UNESCAPED_UNICODE) . "\n";
                throw new Exception('gazette not match');
            }
        } else {
            $gazettes[$agenda['gazette_id']] = $gazette;
            Elastic::dbBulkInsert('gazette', $agenda['gazette_id'], $gazette);
        }
    }
}
Elastic::dbBulkCommit();
