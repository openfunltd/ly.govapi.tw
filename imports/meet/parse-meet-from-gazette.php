<?php

include(__DIR__ . '/../../init.inc.php');

// 因為公報裡面不會有最後一次會議的議事錄，改從議事錄抓取
//throw new Exception("已停用，改用 parse-meet-proceeding.php");
// TODO: 因為院會的議事錄還是只有這邊才能處理， parse-meet-proceeding.php 只能處理委員會
// 因此這邊先恢復運作

$start = date('Y');
for ($y = $start; $y >= 2012; $y --) {
    if (getenv('year') and $y < getenv('year')) {
        continue;
    }
    error_log($y);
   
    $meets = [];
    foreach (glob(__DIR__ . sprintf("/../gazette/agenda-txt/LCIDC01_%3d*", $y - 1911)) as $txtfile) {
        $cmd = sprintf("grep '會議議事錄' %s | grep 立法院", escapeshellarg($txtfile));
        $ret = trim(`$cmd`);
        if (!strlen($ret)) {
            continue;
        }
        preg_match_all('#立法院.*會議議事錄#u', $ret, $matches);
        foreach ($matches[0] as $subject) {
            try {
                $meet_obj = LYLib::meetNameToId($subject);
            } catch (Exception $e) {
                continue;
            }
            
            $meets[$meet_obj->id] = [$meet_obj->id, $txtfile, $meet_obj, $subject, null];
        }
    }
    $ret = Elastic::dbQuery('/{prefix}meet/_search', 'GET', json_encode([
        'query' => ['terms' => ['_id' => array_keys($meets)]],
        'size' => 1000,
    ]));

    foreach ($ret->hits->hits as $hit) {
        $meets[$hit->_id][4] = $hit->_source;
    }

    foreach ($meets as $meet) {
        list($meet_id, $txtfile, $meet_obj, $subject, $meet_data) = $meet;
        preg_match('#LCIDC01_([0-9]+)#', $txtfile, $matches);
        $comYear = intval(substr($matches[1], 0, 3));
        $comVolume = intval(substr($matches[1], 3, -2));
        $comBookId = intval(substr($matches[1], -2));


        error_log("parsing $txtfile");
        if ($meet_data) {
            $meet = $meet_data;
        } else {
            $meet = new StdClass;
        }
        try {
            $info = GazetteParser::parseAgendaWholeMeetingNote($txtfile, $meet_obj->id);
        } catch (Exception $e) {
            throw $e;
        }
        $meet->meet_id = $meet_obj->id;
        $meet->term = $meet_obj->term;
        $meet->meet_type = $meet_obj->type;
        $meet->committees = $meet_obj->committees;
        $meet->sessionPeriod = $meet_obj->sessionPeriod;
        $meet->sessionTimes = $meet_obj->sessionTimes;
        $meet->title = str_replace('議事錄', '', $info->title);
        $info->comYear = $comYear;
        $info->comVolume = $comVolume;
        $info->comBookId = $comBookId;
        $meet->{'議事錄'} = $info;
        unset($info->meet_id);
        //echo json_encode($meet, JSON_UNESCAPED_UNICODE) . "\n";
        //continue;
            
        $meet = LYLib::buildMeet($meet, 'db');
        Elastic::dbBulkInsert('meet', $meet->meet_id, $meet);
    }
}
Elastic::dbBulkCommit();
