<?php

include(__DIR__ . '/../../BillParser.php');

foreach (BillParser::getBillTypes() as $billType => $bill_type) {
    foreach ([1,2,3,4] as $proposalType) {
        for ($p = 1; ; $p ++) {
            $url = sprintf("https://ppg.ly.gov.tw/ppg/api/v1/all-bills?size=1000&page=%d&sortCode=11&billType=%d&proposalType=%d", $p, $billType, $proposalType);
            error_log($url);
            $curl = curl_init($url);
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
