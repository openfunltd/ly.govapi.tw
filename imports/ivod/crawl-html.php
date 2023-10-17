<?php

$current_id = intval(file_get_contents('current-id'));
//for ($v = max($current_id, 146300); ; $v ++) {
for ($v = 146300; ; $v --) {
    $url = sprintf("https://ivod.ly.gov.tw/Play/Clip/1M/%d", $v);
    $html_target = __DIR__ . "/html/{$v}.html";
    if (file_exists($html_target)) {
        continue;
    }
    error_log($url);
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; rv:8.0) Gecko/20100101 Firefox/8.0');
    // ipv4 only
    curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    $content = curl_exec($curl);
    if (!preg_match('#readyPlayer\("([^"]*)"#', $content, $matches)) {
        throw new Exception("readyPlayer not found {$url}");
    }
    if ($v > $current_id) {
        file_put_contents('current-id', $v);
    }
    file_put_contents($html_target, $content);
}
