<?php

include(__DIR__ . '/../../init.inc.php');

$start = date('Y');
for ($y = $start; $y >= 2012; $y --) {
    error_log($y);
    foreach (glob(__DIR__ . sprintf("/../gazette/agenda-html/LCIDC01_%3d*", $y - 1911)) as $htmlfile) {
        preg_match('#LCIDC01_([0-9]+)#', $htmlfile, $matches);
        $comYear = intval(substr($matches[1], 0, 3));
        $comVolume = intval(substr($matches[1], 3, -2));
        $comBookId = intval(substr($matches[1], -2));

        $cmd = sprintf("grep '會議議事錄<' %s | grep 立法院", escapeshellarg($htmlfile));
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
            error_log("parsing $htmlfile");
            try {
                $info = GazetteParser::parseAgendaWholeMeetingNote($htmlfile, $meet_obj->id);
            } catch (Exception $e) {
                throw $e;
            }
            $info->comYear = $comYear;
            $info->comVolume = $comVolume;
            $info->comBookId = $comBookId;
            $info->meet_id = $meet_obj->id;
            $info->term = $meet_obj->term;
            $info->meet_type = $meet_obj->type;
            $info->committees = $meet_obj->committees;
            $info->sessionPeriod = $meet_obj->sessionPeriod;
            $info->sessionTimes = $meet_obj->sessionTimes;
                
            Elastic::dbBulkInsert('meet', $info->meet_id, $info);
        }
    }
}
Elastic::dbBulkCommit();
