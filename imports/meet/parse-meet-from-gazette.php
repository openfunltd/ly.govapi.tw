<?php

include(__DIR__ . '/../../init.inc.php');

// 因為公報裡面不會有最後一次會議的議事錄，改從議事錄抓取
//throw new Exception("已停用，改用 parse-meet-proceeding.php");
// TODO: 因為院會的議事錄還是只有這邊才能處理， parse-meet-proceeding.php 只能處理委員會
// 因此這邊先恢復運作

$start = date('Y');
$end_year = 2017;  // TODO: 2016 有些資料錯誤，之後需要手動處理 ex: agenda-txt/LCIDC01_1056801_00008.doc
for ($y = $start; $y >= $end_year; $y --) {
    if (getenv('year') and $y < getenv('year')) {
        continue;
    }
    error_log($y);
   
    foreach (glob(__DIR__ . sprintf("/../gazette/agenda-txt/LCIDC01_%3d*", $y - 1911)) as $txtfile) {
        $cmd = sprintf("grep '會議議事錄' %s | grep 立法院", escapeshellarg($txtfile));
        $ret = trim(`$cmd`);
        if (!strlen($ret)) {
            continue;
        }
        preg_match('#LCIDC01_([0-9]+)_(\d+)#', $txtfile, $matches);
        $comYear = intval(substr($matches[1], 0, 3));
        $comVolume = intval(substr($matches[1], 3, -2));
        $comBookId = intval(substr($matches[1], -2));
        $agenda_lcidc_id = "{$matches[1]}_{$matches[2]}";

        preg_match_all('#立法院.*會議議事錄#u', $ret, $matches);
        foreach ($matches[0] as $subject) {
            try {
                $meet_obj = LYLib::meetNameToId($subject);
            } catch (Exception $e) {
                continue;
            }

            $meet_data_file = __DIR__ . sprintf("/../meet/meet-sub-data/%s.json", $meet_obj->id);
            if (!file_exists($meet_data_file)) {
                $meet_data = new StdClass;
            } else {
                $meet_data = json_decode(file_get_contents($meet_data_file));
            }

            try {
                $info = GazetteParser::parseAgendaWholeMeetingNote($txtfile, $meet_obj->id);
            } catch (Exception $e) {
                if (strpos($e->getMessage(), '找不到議事錄標題') !== false) {
                    error_log($txtfile . ' ' . $e->getMessage());
                    continue;
                }
                error_log("{$txtfile} {$meet_obj->id} {$e->getMessage()}");
                continue;
                throw $e;
            }
            $info->comYear = $comYear;
            $info->comVolume = $comVolume;
            $info->comBookId = $comBookId;
            $info->agenda_lcidc_id = $agenda_lcidc_id;

            if (!property_exists($meet_data, '議事錄') or json_encode($info) != json_encode($meet_data->{'議事錄'})) {
                if (property_exists($meet_data, '議事錄') and $meet_data->{'議事錄'}->agenda_lcidc_id > $agenda_lcidc_id) {
                    // 表示同一份議事錄有出現在兩個以上不同的地方，那以新的為主
                    continue;
                }
                error_log("{$txtfile} {$meet_obj->id}");
                $meet_data->{'議事錄'} = $info;
                file_put_contents($meet_data_file, json_encode($meet_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
    }
}
