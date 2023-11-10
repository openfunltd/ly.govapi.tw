<?php

include(__DIR__ . '/../../init.inc.php');

$fp = fopen(__DIR__ . '/meet.jsonl', 'r');
$meet_map = new StdClass;
$ids = [];
while ($line = fgets($fp)) {
    $meet = json_decode($line);
    $meet = LYLib::filterMeetData($meet);
    $o = new StdClass;
    $o->meet_data = [$meet];
    $meet = LYLib::buildMeet($o)->meet_data[0];
    try {
        $meet_obj = LYLib::meetNameToId($meet->meetingName);
        if (!$meet_obj) {
            continue;;
        }
    } catch (Exception $e) {
        continue;
    }
    if ($meet_obj->term != 10) {
        continue;
    }
    if ($meet_obj->type == '院會') {
        continue;
    }
    $doc_target = __DIR__ . "/meet-proceeding-doc/{$meet_obj->id}.doc";
    if (!file_exists($doc_target)) {
        $url = "https://ppg.ly.gov.tw/ppg/api/v1/getProceedingsList?meetingNo=" . urlencode($meet->meetingNo);
        error_log($url);
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        // IPv4
        curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        $json = curl_exec($curl);
        $obj = json_decode($json);
        if (!property_exists($obj, 'doc') or !$obj->doc) {
            continue;
        }
        $url = $obj->doc;
        if ($meet->meetingNo == '2020022715') {
            $url = 'https://ppg.ly.gov.tw/ppg/SittingAttachment/download/2020022715/File_276599.doc';
        } elseif ($meet->meetingNo == '2020022501') {
            $url = 'https://ppg.ly.gov.tw/ppg/SittingAttachment/download/2020022501/File_256227.doc';
        } elseif ($meet->meetingNo == '2020052203') {
            $url = 'https://ppg.ly.gov.tw/ppg/SittingAttachment/download/2020052203/File_264987.doc';
        } elseif ($meet->meetingNo == '2020092917') {
            $url = 'https://ppg.ly.gov.tw/ppg/SittingAttachment/download/2020092917/File_278705.doc';
        } elseif ($meet->meetingNo == '2021031119') {
            $url = 'https://ppg.ly.gov.tw/ppg/SittingAttachment/download/2021031119/File_1657437.doc';
        }
        if (strpos($url, ',')) {
            $urls = explode(',', $url);
            $urls = array_values(array_filter($urls, function($url_title) {
                list($url, $title) = explode(';', $url_title, 2);
                if (strpos($title, '召委會議議事錄') !== false) {
                    return false;
                }
                if (strpos($title, '召集委員會議議事錄') !== false) {
                    return false;
                }
                return true;
            }));
            if (count($urls) != 1) {
                print_r(explode(',', $url));
                print_r($meet);
                //readline('continue');
                continue;
            }
            error_log("{$url} => {$urls[0]}");
            $url = $urls[0];
        }
        echo $url . "\n";
        $url = explode(';', $url)[0];
        $cmd = sprintf("wget -4 -O %s %s", escapeshellarg(__DIR__ . '/tmp.doc'), escapeshellarg($url));
        system($cmd, $ret);
        if ($ret != 0) {
            throw new Exception("Error: $cmd");
        }
        copy(__DIR__ . '/tmp.doc', $doc_target);
        unlink(__DIR__ . '/tmp.doc');
    }

    $txt_target = __DIR__ . "/meet-proceeding-txt/{$meet_obj->id}.txt";
    if (!file_exists($txt_target)) {
        $cmd = sprintf("curl -T %s https://tika.openfun.dev/tika -H 'Accept: text/plain' > %s", escapeshellarg($doc_target), escapeshellarg(__DIR__ . '/tmp.txt'));
        system($cmd, $ret);
        if ($ret) {
            throw new Exception("轉檔失敗: " . $doc_file);
        }
        copy(__DIR__ . '/tmp.txt', $txt_target);
        unlink(__DIR__ . '/tmp.txt');
    }

    $html_target = __DIR__ . "/meet-proceeding-html/{$meet_obj->id}.html";
    if (!file_exists($html_target)) {
        $cmd = sprintf("curl -T %s https://tika.openfun.dev/tika -H 'Accept: text/html' > %s", escapeshellarg($doc_target), escapeshellarg(__DIR__ . '/tmp.html'));
        system($cmd, $ret);
        if ($ret) {
            throw new Exception("轉檔失敗: " . $doc_file);
        }
        copy(__DIR__ . '/tmp.html', $html_target);
        unlink(__DIR__ . '/tmp.html');
    }
}
