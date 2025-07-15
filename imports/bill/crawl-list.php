<?php

include(__DIR__ . "/../../init.inc.php");
include(__DIR__ . "/../Importer.php");

$term = getenv('term');

foreach (BillParser::getBillTypes() as $billType => $bill_type) {
    foreach ([1,2,3,4] as $proposalType) {
        for ($p = 1; ; $p ++) {
            if ($term) {
                $url = sprintf("https://ppg.ly.gov.tw/ppg/api/v1/all-bills?size=1000&term=%d&page=%d&sortCode=11&billType=%d&proposalType=%d", $term, $p, $billType, $proposalType);
            } else {
                $url = sprintf("https://ppg.ly.gov.tw/ppg/api/v1/all-bills?size=1000&page=%d&sortCode=11&billType=%d&proposalType=%d", $p, $billType, $proposalType);
            }
            error_log($url);
            $content = Importer::getURL($url, 300, 3);
            $ret = json_decode($content);
            $empty = true;
            foreach ($ret->items as $item) {
                $empty = false;
                $item->billType = $billType;
                $item->proposalType = $proposalType;
                echo json_encode($item, JSON_UNESCAPED_UNICODE) . "\n";
            }
            if ($empty) {
                break;
            }
        }
    }
}
