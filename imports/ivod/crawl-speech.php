<?php
$output = function($result) {
    $date = date('Ymd');
    if (!file_exists(__DIR__ . "/../../cache/ivod-speech/{$date}")) {
        mkdir(__DIR__ . "/../../cache/ivod-speech/{$date}", 0777, true);
    }
    $filename = date('YmdHis') . '.html.gz';
    file_put_contents(__DIR__ . "/../../cache/ivod-speech/{$date}/{$filename}", gzencode($result));
};

for ($retry = 0; $retry < 3; $retry ++) {
    $curl = curl_init();
    // enable cookie
    curl_setopt($curl, CURLOPT_COOKIEJAR, 'cookie.txt');
    curl_setopt($curl, CURLOPT_COOKIEFILE, 'cookie.txt');
    curl_setopt($curl, CURLOPT_URL, 'https://ivod.ly.gov.tw/TotalSpeech');
    curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; rv:8.0) Gecko/20100101 Firefox/8.0');
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    $content = curl_exec($curl);
    if (!preg_match('#<meta name="csrf-token" content="([^"]*)#', $content, $matches)) {
        error_log('Cannot find csrf-token');
        continue;
    }
    $token = $matches[1];

    // get the page
    curl_setopt($curl, CURLOPT_URL, 'https://ivod.ly.gov.tw/TotalSpeech/FetchCommInfoTotal');
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_REFERER, 'https://ivod.ly.gov.tw/TotalSpeech');
    curl_setopt($curl, CURLOPT_POSTFIELDS, 'type=all');
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'X-CSRF-TOKEN: ' . $token,
        'X-Requested-With: XMLHttpRequest',
    ]);
    $result = curl_exec($curl);
    $info = curl_getinfo($curl);
    if ($info['http_code'] !== 200) {
        error_log('HTTP code: ' . $info['http_code']);
        continue;
    }
    $output($result);
    exit;
}

throw new Exception('Cannot fetch the page');
