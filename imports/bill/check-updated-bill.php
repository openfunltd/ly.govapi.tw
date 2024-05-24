<?php

include(__DIR__ . '/../../BillParser.php');

$fp = fopen($_SERVER['argv'][1], 'r');
$seq = 0;
$total = intval(`wc -l {$_SERVER['argv'][1]}`);
while ($obj = json_decode(fgets($fp))) {
    $billNo = $obj->id;
    $seq ++;
    if ($seq % 1000 == 0) {
        error_log("{$seq} / {$total}");
    }
    if (!file_exists(__DIR__ . "/bill-html/{$billNo}.gz")) {
        continue;
    }
    //error_log($billNo);
    $content = gzdecode(file_get_contents(__DIR__ . "/bill-html/{$billNo}.gz"));
    if (strpos($content, '403 Forbidden')) {
        error_log("{$billNo} 403 Forbidden");
        unlink(__DIR__ . "/bill-html/{$billNo}.gz");
        continue;
    }
    if ($obj->billType == 20 and strpos($content, '委員會發文') and false === strpos($content, '關聯議案')) {
        error_log("{$billNo} 無關聯議案");
        rename(__DIR__ . "/bill-html/{$billNo}.gz", __DIR__ . "/bill-html/old/{$billNo}.gz");
        continue;
    }
    try {
        $values = BillParser::parseBillDetail($billNo, $content);
    } catch (Exception $e) {
        continue;
    }
    $values->{'議案狀態'} = trim(preg_replace('#\(.*\)$#', '', trim($values->{'議案狀態'})));
    $obj->content5 = trim(preg_replace('#\(.*\)$#', '', $obj->content5));
    if ($values->{'議案狀態'} != $obj->content5) {
        error_log(json_encode($obj, JSON_UNESCAPED_UNICODE));
        error_log("{$values->billNo} html={$values->{'議案狀態'}} list={$obj->content5}");
        rename(__DIR__ . "/bill-html/{$billNo}.gz", __DIR__ . "/bill-html/old/{$billNo}.gz");
    }
    if ($obj->billType == 20 and !count($values->{'相關附件'})) {
        error_log("{$billNo} 無相關附件");
        rename(__DIR__ . "/bill-html/{$billNo}.gz", __DIR__ . "/bill-html/old/{$billNo}.gz");
    }
}
