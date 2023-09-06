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
    $values = BillParser::parseBillDetail($billNo, $content);
    if ($values->{'議案狀態'} != $obj->content2) {
        error_log("{$values->billNo} {$values->{'議案狀態'}} {$obj->content2}");
        rename(__DIR__ . "/bill-html/{$billNo}.gz", __DIR__ . "/bill-html/old/{$billNo}.gz");
    }
}
