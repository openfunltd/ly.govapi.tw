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

file_put_contents(__DIR__ . '/missing_law.txt', '');
$types = BillParser::getBillTypes();
$sources = BillParser::getBillSources();
$skip = $_SERVER['argv'][2];
foreach ($list as $idx => $v) {
    list($filename, $time, $obj) = $v;
    if ($time < $max_value) {
        //continue;
    }
    list($billNo) = explode('.', $filename);
    if ($skip and $billNo != $skip) {
        continue;
    }
    if ($billNo == $skip) {
        $skip = false;
    }
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
    if ($billNo == '1020304070200400') {
        $values->{'提案人'} = ['親民黨立法院黨團', '李桐豪'];
    }
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
    while (!is_null($content) and strlen($content) > 10) {
        if (in_array($billNo, [
            '1060519070202300', // 看起來對錯文件
            '1021223070201700', // 看起來對錯文件
            '1030102070200300', // 看起來對錯文件
            '1020415070202900', // 看起來對錯文件
        ])) {
            break;
        }
        if (property_exists($values, '屆期') and $values->{'屆期'}) {
            $obj->term = $values->{'屆期'};
        }
        $docdata = BillParser::parseBillDoc($billNo, $content, $obj);
        if (property_exists($docdata, '字號') and $docdata->{'字號'}) {
            $values->{'字號'} = $docdata->{'字號'};
            // 移掉全形空白
            $values->{'字號'} = str_replace('　', '', $values->{'字號'});
            if ($billNo == '1070810071002400') {
                $values->{'字號'} = '院總第887號政府提案第16100號';
            }
            if (preg_match('#^院總第(\d+)號(.*)提案第(\d+)號(之(\d*))?$#u', $values->{'字號'}, $matches)) {
            } else if (preg_match('#^院總第(\d+)號(.*)提案第(\d+)號((\d+))$#u', $values->{'字號'}, $matches)) {
            } else {
                break;
                throw new Exception('字號格式不正確: ' . $values->{'字號'});
            }
            if ($matches[2] == '委員') {
                $type = '委';
            } elseif ($matches[2] == '政府') {
                $type = '政';
            } else {
                throw new Exception('字號格式不正確: ' . $values->{'字號'});
            }
            $values->{'提案編號'} = sprintf("%d%s%d",
                intval($matches[1]),
                $type,
                intval($matches[3])
            );
            if (count($matches) > 4 and $matches[4] and intval($matches[5])) {
                $values->{'提案編號'} .= '之' . intval($matches[5]);
            }
        }

        foreach (['案由', '說明', '提案人', '連署人', '對照表'] as $k) {
            if (property_exists($docdata, $k) and $docdata->{$k} and (!property_exists($values, $k) or !$values->{$k})) {
                $values->{$k} = $docdata->{$k};
            }
        }
        break;
    }

    Elastic::dbBulkInsert('bill', $billNo, $values);
}
Elastic::dbBulkCommit();
