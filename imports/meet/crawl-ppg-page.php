<?php

include(__DIR__ . '/../../init.inc.php');

$meets = [];
$fp = fopen(__DIR__ . '/meet.jsonl', 'r');
$meet_map = new StdClass;
$ids = [];
while ($line = fgets($fp)) {
    $meet = json_decode($line);
    $meets[] = $meet;
}

usort($meets, function($a, $b) {
    return $b->meetingDateDesc <=> $a->meetingDateDesc;
});

$meet_group = [];
foreach ($meets as $meet) {
    if (!$meet->meetingNo) {
        continue;
    }
    $meet_group[$meet->meetingNo][] = $meet;    
}
foreach ($meet_group as $meetingNo => $meets) {
    $names = [];
    foreach ($meets as $meet) {
        $meet = LYLib::filterMeetData($meet);
        try {
            $meet_obj = LYLib::meetNameToId($meet->meetingName);
        } catch (Exception $e) {
            continue;
        }

        $d = strtotime($meet->date);
        $url = sprintf("https://ppg.ly.gov.tw/ppg/sittings/%s/details?meetingDate=%03d/%02d/%02d",
            $meetingNo,
            date('Y', $d) - 1911,
            date('m', $d),
            date('d', $d)
        );
        $target = __DIR__ . "/ppg_meet_page/{$meetingNo}-{$meet->date}.html";
        if (!file_exists($target) or filesize($target) < 300) {
            error_log("fetch $url");
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            // ipv4
            curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            $html = curl_exec($curl);
            if (curl_errno($curl)) {
                error_log(curl_error($curl));
                continue;
                throw new Exception(curl_error($curl));
            }
            if (filesize($target) < 300) {
                error_log("{$url} is empty");
                continue;
                throw new Exception("{$url} is empty");
            }
            file_put_contents($target, $html);
        }
        if (($_SERVER['argv'][1] ?? false) == 'onlydownload') {
            continue;
        }
        error_log("{$target} {$url}");
        $json_target = __DIR__ . "/ppg_meet_page_json/{$meetingNo}-{$meet->date}.json";
        if (!file_exists($json_target) or filemtime($json_target) < strtotime('2024-09-10 16:46')) {
            try {
                $info = MeetParser::parseMeetPage(file_get_contents($target), __DIR__, $meetingNo, $url);
            } catch (Exception $e) {
                error_log($e->getMessage());
                readline('continue?');
                continue;
            }
            file_put_contents($json_target, json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $info = json_decode(file_get_contents($json_target));
        MeetParser::checkData($meet, $info);
        $names[$meet->meetingName] = true;
    }
    if (count($names) > 1) {
        error_log("{$meetingNo} has different names: " . json_encode($names, JSON_UNESCAPED_UNICODE));
        readline('conitnue');
    }
}
