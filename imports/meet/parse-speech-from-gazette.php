<?php

include(__DIR__ . '/../../init.inc.php');

$start = date('Y');
for ($y = $start; $y >= 2012; $y --) {
    error_log($y);

    $transnumber = function($s) {
        $s = str_replace('委', '', $s);
        $s = str_replace('院', '', $s);
        $s = str_replace(' ', '', $s);
        //全形數字轉半形
        $s = str_replace('０', '0', $s);
        $s = str_replace('１', '1', $s);
        $s = str_replace('２', '2', $s);
        $s = str_replace('３', '3', $s);
        $s = str_replace('４', '4', $s);
        $s = str_replace('５', '5', $s);
        $s = str_replace('６', '6', $s);
        $s = str_replace('７', '7', $s);
        $s = str_replace('８', '8', $s);
        $s = str_replace('９', '9', $s);
        if (!preg_match('#^[0-9]+$#', $s, $matches)) {
            throw new Exception("數字轉換錯誤 $s");
        }
        return intval($s);
    };
    $meets = [];
    foreach (glob(__DIR__ . sprintf("/../gazette/gazette-txt/%3d*", $y - 1911)) as $txtfile) {
        //if (strpos($txtfile, '1115403') == 0) continue;
        $cmd = sprintf("grep '發言紀錄索引' %s", escapeshellarg($txtfile));
        $ret = trim(`$cmd`);
        if (!strlen($ret)) {
            continue;
        }

        error_log($txtfile);
        $empty = true;
        foreach (GazetteParser::getSpeechFromGazette($txtfile) as $speech) {
            $empty = false;
            $speech->speakers = str_replace('（主席）', '', $speech->speakers);
            $speech->speakers = str_replace('發言者', '', $speech->speakers);
            try {
                $meet_obj = LYLib::meetNameToId($speech->meet_name);
            } catch (Exception $e) {
                error_log("找不到 {$speech->meet_name}");
                continue;
            }
            if (!$speech->content) {
                print_r($speech);
                throw new Exception("{$speech->meet_name} {$speech->speakers} {$speech->content}");
            }
            if (!$meet_obj) {
                error_log("找不到 {$speech->meet_name}");
                continue;
                throw new Exception("找不到 {$speech->meet_name}");
            }
            $speech->speakers = GazetteParser::parsePeople($speech->speakers, $meet_obj->term);
            $speech->meet_id = $meet_obj->id;
            $speech->gazette_id = basename($txtfile, '.txt');
            if (preg_match('#(.*)[\(（]頁次：?(.*)[─－]([^）]*)#us', $speech->content, $matches)) {
                $speech->page_start = $transnumber($matches[2]);
                $speech->page_end = $transnumber($matches[3]);
            } else if (preg_match('#(.*)（頁次：([^－]*)）$#us', $speech->content, $matches)) {
                $speech->page_start = $transnumber($matches[2]);
                $speech->page_end = $transnumber($matches[2]);
            } else {
                print_r($speech);
                throw new Exception("找不到頁次 {$speech->content}");
            }
            $speech->content = rtrim($matches[1]);
            if (!isset($meets[$meet_obj->id])) {
                $meets[$meet_obj->id] = [
                    $meet_obj,
                    [],
                ];
            }
            $meets[$meet_obj->id][1][] = clone $speech;
            echo json_encode($speech, JSON_UNESCAPED_UNICODE) . "\n";
        }
        if ($empty) {
            throw new Exception("找不到發言紀錄索引 $txtfile");
        }
    }
    $ret = Elastic::dbQuery('/{prefix}meet/_search', 'GET', json_encode([
        'query' => ['terms' => ['_id' => array_keys($meets)]],
        'size' => 1000,
    ]));

    foreach ($ret->hits->hits as $hit) {
        $meets[$hit->_id][2] = $hit->_source;
    }

    foreach ($meets as $meet) {
        list($meet_obj, $info, $meet_data) = $meet;
        if ($meet_data) {
            $meet = $meet_data;
        } else {
            $meet = new StdClass;
        }
        $meet->meet_id = $meet_obj->id;
        $meet->term = $meet_obj->term;
        $meet->meet_type = $meet_obj->type;
        if (property_exists($meet_obj, 'committees')) {
            $meet->committees = $meet_obj->committees;
        }
        $meet->sessionPeriod = $meet_obj->sessionPeriod;
        $meet->sessionTimes = $meet_obj->sessionTimes;

        $meet->{'公報發言紀錄'} = $info;

        Elastic::dbBulkInsert('meet', $meet->meet_id, $meet);
    }
}
Elastic::dbBulkCommit();
