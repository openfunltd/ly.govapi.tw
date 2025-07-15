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
            $curl = curl_init($url);
            // user-agent
            curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            $content = curl_exec($curl);
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
