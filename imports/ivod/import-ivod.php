<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/IVodParser.php');
$crawled = 0;

foreach ([
    'Full' => 'current-full-id',
    'Clip' => 'current-id',
    ] as $type => $v_file) {
    $v = intval(file_get_contents(__DIR__ . '/' . $v_file));
    $error_name = [];
    for (; $v > 0; $v --) {
        //error_log($v);
        $url = sprintf("https://ivod.ly.gov.tw/Play/%s/1M/%d", $type, $v);
        $html_target = __DIR__ . "/html/{$v}.html";
        if (!file_exists($html_target)) {
            continue;
            //break;
        }
        $content = file_get_contents($html_target);
        $ivod = IVodParser::parseHTML($v, $content, $type);
        if (getenv('year')) {
            if (strtotime($ivod->start_time) < mktime(0, 0, 0, 1, 1, getenv('year'))) {
                break;
            }
        }

        if (!preg_match('#^[^「（]*#u', $ivod->{'會議名稱'}, $matches)) {
            error_log("會議名稱 not found: " . $ivod->{'會議名稱'});
            readline('continue?');
            continue;
        }
        $name = $matches[0];
        if (strpos($name, '法院') === 0) {
            $name = '立' . $name;
        } elseif ($name == '立法院第10屆第7會期外交及國防委員會第14全體委員會議') {
            $name = '立法院第10屆第7會期外交及國防委員會第14次全體委員會議';
        }
        if (strpos($name, '黨團協商')) {
            try {
                $meet_obj = LYLib::consultToId('ivod', $ivod);
                $ivod->meet = $meet_obj;
            } catch (Exception $e) {
            }
        } elseif (strpos($ivod->{'會議名稱'}, '公聽會')) {
            // TODO: 處理公聽會
            //continue;
        } else {
            try {
                //print_r($ivod);
                $name = str_replace('(變更議程)', '', $name);
                $meet_obj = LYLib::meetNameToId($name);
                $ivod->meet = $meet_obj;
                //print_r($meet_obj);
            } catch (Exception $e) {
                error_log(json_encode($ivod, JSON_UNESCAPED_UNICODE));
                error_log($e->getMessage());
                $error_name[$name] ++;
            }
        }
        $ivod->features = [];
        if (file_exists(__DIR__ . "/ivod-transcript/{$v}.json") and strpos(file_get_contents(__DIR__ . "/ivod-transcript/{$v}.json"), 'status: error') === false) {
            $ivod->features[] = 'ai-transcript';
        }
        Elastic::dbBulkInsert('ivod', $ivod->id, $ivod);
    }
}
Elastic::dbBulkCommit();
print_r(array_keys($error_name));
