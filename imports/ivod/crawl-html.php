<?php

$current_id = intval(file_get_contents(__DIR__ . '/current-full-id'));
$miss = 0;
for ($v = max(4609, $current_id); ; $v ++) {
    $html_target = __DIR__ . "/html/{$v}.html";
    if (file_exists($html_target)) {
        continue;
    }
    $hit = false;
    foreach (['1M', '300K'] as $q) {
        $url = sprintf("https://ivod.ly.gov.tw/Play/Full/{$q}/%d", $v);
        error_log($url);
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; rv:8.0) Gecko/20100101 Firefox/8.0');
        // ipv4 only
        curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        $content = curl_exec($curl);
        if (!preg_match('#readyPlayer\("([^"]*)"#', $content, $matches)) {
            error_log("readyPlayer not found {$url}");
            continue;
        }
        $hit = true;
        $miss = 0;
        break;
    }
    if (!$hit) {
        $miss ++;
        if ($miss > 5) {
            break;
        } else {
            continue;
        }
    }
    if ($v > $current_id) {
        file_put_contents(__DIR__ . '/current-full-id', $v);
    }
    file_put_contents($html_target, $content);
}

$current_id = intval(file_get_contents(__DIR__ . '/current-id'));
for ($v = max($current_id, 146300); ; $v ++) {
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
        error_log("readyPlayer not found {$url}");
        break;
    }
    if ($v > $current_id) {
        file_put_contents(__DIR__ . '/current-id', $v);
    }
    file_put_contents($html_target, $content);
}
