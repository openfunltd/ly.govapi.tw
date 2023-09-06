<?php

include(__DIR__ . '/../../init.inc.php');

$list = BillParser::getListFromWeb(__DIR__ . "/bill-html");
/*$ret = Elastic::dbQuery("/bill/_search?format=json&human", "GET", json_encode([
    'size' => 0,
    'aggs' => [
        'max_mtime' => [ 'max' => [ 'field' => 'mtime']],
    ],
]));*/
$max_value = 0;
//$max_value = json_decode($ret)->aggregations->max_mtime->value;

foreach ($list as $idx => $v) {
    list($filename, $time) = $v;
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
    $values->mtime = date('c', $mtime);
    if ($values->{'議案流程'}) {
        foreach ($values->{'議案流程'} as $flow) {
            if (is_object($flow)) {
                $date = $flow->{'日期'};
                $period = $flow->{'會期'};
            } else {
                $date = $flow['日期'];
                $period = $flow['會期'];
            }
            if (!$date) {
                continue;
            }
            if ($period) {
                list($period) = explode('-', $period);
                if (intval($period) > 0) {
                    $values->{'屆期'} = intval($period);
                }
            }
            foreach ($date as $d) {
                if (!property_exists($values, 'first_time')) {
                    $values->first_time = $d;
                }
                $values->last_time = $d;
            }
        }
    }

    Elastic::dbBulkInsert('bill', $billNo, $values);
}
Elastic::dbBulkCommit();
