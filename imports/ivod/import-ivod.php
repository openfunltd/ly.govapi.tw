<?php

include(__DIR__ . '/../../init.inc.php');
$crawled = 0;

$v = max(intval(file_get_contents('current-id')), 146312);
$error_name = [];
for (; $v > 0; $v --) {
    //error_log($v);
    $url = sprintf("https://ivod.ly.gov.tw/Play/Clip/1M/%d", $v);
    $html_target = __DIR__ . "/html/{$v}.html";
    if (!file_exists($html_target)) {
        break;
    }
    $content = file_get_contents($html_target);
    if (!preg_match('#readyPlayer\("([^"]*)"#', $content, $matches)) {
        throw new Exception("readyPlayer not found {$url}");
    }
    $ivod = new StdClass;
    $ivod->id = intval($v);
    $ivod->url = sprintf("https://ivod.ly.gov.tw/Play/Clip/1M/%d", $v);
    $ivod->video_url = $matches[1];
    if (!preg_match('#<strong>會議時間：</strong>([0-9-: ]+)#', $content, $matches)) {
        throw new Exception("會議時間 not found: $url");
    }
    $doc = new DOMDocument;
    @$doc->loadHTML($content);
    // 處理所有 strong tag
    foreach ($doc->getElementsByTagName('strong') as $strong_dom) {
        if (strpos($strong_dom->textContent, '：') === false) {
            continue;
        }
        $key = trim(str_replace('：', '', $strong_dom->textContent));
        $value = '';
        $dom = $strong_dom->nextSibling;
        while ($dom) {
            if ($dom->nodeType === XML_TEXT_NODE) {
                $value .= $dom->textContent;
            }
            $dom = $dom->nextSibling;
        }
        $ivod->{$key} = trim($value);
    }
    $ivod->{'會議時間'} = date('c', strtotime($ivod->{'會議時間'}));
    $ivod->date = date('Y-m-d', strtotime($ivod->{'會議時間'}));

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
    try {
        //print_r($ivod);
        $meet_obj = LYLib::meetNameToId($name);
        $ivod->meet = $meet_obj;
        //print_r($meet_obj);
    } catch (Exception $e) {
        error_log($name);
        error_log($e->getMessage());
        $error_name[$name] ++;
    }
    Elastic::dbBulkInsert('ivod', $ivod->id, $ivod);
}
Elastic::dbBulkCommit();
print_r(array_keys($error_name));
