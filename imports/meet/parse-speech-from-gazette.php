<?php

include(__DIR__ . '/../../init.inc.php');

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

$start = date('Y');
for ($y = $start; $y >= 2012; $y --) {
    if (getenv('year') and $y < getenv('year')) {
        break;
    }
    error_log($y);
    $ret = Elastic::dbQuery('/{prefix}gazette_agenda/_search', 'GET', json_encode([
        'query' => ['term' => ['comYear' => $y - 1911]],
        'size' => 10000,
    ]));
    $agendas = [];
    foreach ($ret->hits->hits as $hit) {
        $source = $hit->_source;
        $agenda_lcidc_ids = [];
        foreach ($source->docUrls as $docUrl) {
            if (!preg_match('#LCIDC01_(\d+_\d+)\.doc#', $docUrl, $matches)) {
                print_r($source);
                throw new Exception("{$source->gazette_id} 檔名不對");
            }
            $agenda_lcidc_ids[] = $matches[1];
        }
        $source->agenda_lcidc_ids = $agenda_lcidc_ids;
        $agendas["{$source->gazette_id}_{$source->pageStart}_{$source->pageEnd}"] = $source;
    }

    foreach (glob(__DIR__ . sprintf("/../gazette/gazette-txt/%3d*", $y - 1911)) as $txtfile) {
        $cmd = sprintf("grep '發言紀錄索引' %s", escapeshellarg($txtfile));
        $ret = trim(`$cmd`);
        if (!strlen($ret)) {
            continue;
        }

        $empty = true;
        foreach (GazetteParser::getSpeechFromGazette($txtfile) as $idx => $speech) {
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
                if (!$speech->meet_name) {
                    continue;
                }
                var_dump($speech);
                throw new Exception("{$speech->meet_name} {$speech->speakers} {$speech->content}");
            }
            if (!$meet_obj) {
                error_log("找不到 {$speech->meet_name}");
                continue;
                throw new Exception("找不到 {$speech->meet_name}");
            }
            unset($speech->line);
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
                try {
                    throw new Exception("找不到頁次 {$speech->content}");
                } catch (Exception $e) {
                    if ($y < 2016) {
                        error_log($e->getMessage());
                        exit;
                    }
                }
            }
            $agenda_id = "{$speech->gazette_id}_{$speech->page_start}_{$speech->page_end}";
            if (isset($agendas[$agenda_id])) {
                $speech->agenda_id = $agendas[$agenda_id]->agenda_id;
                $speech->agenda_lcidc_ids = $agendas[$agenda_id]->agenda_lcidc_ids;
                $speech->meetingDate = $agendas[$agenda_id]->meetingDate;
            } else {
                error_log("找不到議程 {$agenda_id}");
            }
            $data_key = "公報發言紀錄:" . basename($txtfile, '.txt') . ':' . $idx;

            $speech->content = rtrim($matches[1]);

            $meet_data_file = __DIR__ . sprintf("/../meet/meet-sub-data/%s.json", $meet_obj->id);
            if (!file_exists($meet_data_file)) {
                $meet_data = new StdClass;
            } else {
                $meet_data = json_decode(file_get_contents($meet_data_file));
            }

            if (!property_exists($meet_data, $data_key) or json_encode($speech) != json_encode($meet_data->{$data_key})) {
                error_log("{$txtfile} {$meet_obj->id} {$data_key}");
                $meet_data->{$data_key} = $speech;
                file_put_contents($meet_data_file, json_encode($meet_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
    }
}
