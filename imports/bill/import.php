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
    if (file_exists(__DIR__ . '/bill-doc-parsed/tikahtml/' . $billNo . '.doc.gz')) {
        $file = __DIR__ . '/bill-doc-parsed/tikahtml/' . $billNo . '.doc.gz';
        $content = gzdecode(file_get_contents($file));
    } else if (file_exists(__DIR__ . '/bill-doc-parsed/html/' . $billNo . '.doc.gz')) {
        $file = __DIR__ . '/bill-doc-parsed/html/' . $billNo . '.doc.gz';
        $obj = json_decode(gzdecode(file_get_contents($file)));
        $content = (base64_decode($obj->content));
    } else {
        $content = null;
    }
    if (!is_null($content) and strlen($content) > 10) {
        $docdata = BillParser::parseBillDoc($billNo, $content, $obj);
        if (property_exists($docdata, '字號')) {
            $values->{'字號'} = $docdata->{'字號'};
        }
    }

    Elastic::dbBulkInsert('bill', $billNo, $values);
}
Elastic::dbBulkCommit();
