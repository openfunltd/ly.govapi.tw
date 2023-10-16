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
            $meet = new StdClass;
            try {
                $info = GazetteParser::parseAgendaWholeMeetingNote($htmlfile, $meet_obj->id);
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
            $meet->{'議事錄'} = $info;
            unset($info->meet_id);
            $info->comYear = $comYear;
            $info->comVolume = $comVolume;
            $info->comBookId = $comBookId;
                
            Elastic::dbBulkInsert('meet', $meet->meet_id, $meet);
        }
    }
}
Elastic::dbBulkCommit();
