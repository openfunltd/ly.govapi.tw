<?php

include(__DIR__ . '/../../init.inc.php');

$list = BillParser::getListFromFileAndDir($_SERVER['argv'][1], __DIR__ . "/bill-html");
/*$ret = Elastic::dbQuery("/bill/_search?format=json&human", "GET", json_encode([
    'size' => 0,
    'aggs' => [
        'max_mtime' => [ 'max' => [ 'field' => 'mtime']],
    ],
]));*/
$max_value = 0;
//$max_value = json_decode($ret)->aggregations->max_mtime->value;

$types = BillParser::getBillTypes();
$sources = BillParser::getBillSources();
foreach ($list as $idx => $v) {
    list($filename, $time, $obj) = $v;
    if ($time < $max_value) {
        //continue;
    }
    list($billNo) = explode('.', $filename);
    error_log($idx . ' ' . $billNo);
    $mtime = filemtime(__DIR__ . "/bill-html/{$billNo}.gz");
    $content = gzdecode(file_get_contents(__DIR__ . "/bill-html/{$billNo}.gz"));
    try {
        $values = BillParser::parseBillDetail($billNo, $content);
    } catch (Exception $e) {
        error_log("{$billNo} error: " . $e->getMessage());
        continue;
    }
    $values->{'議案類別'} = $types[$obj->billType];
    $values->{'提案來源'} = $sources[$obj->proposalType];
    $values = BillParser::addBillInfo($values);
    $values->mtime = date('c', $mtime);

    Elastic::dbBulkInsert('bill', $billNo, $values);
}
Elastic::dbBulkCommit();
