<?php

$progress_target = __DIR__ . '/../../cache/lis-progress.jsonl';
$list_target = __DIR__ . '/../../cache/lis-cache.json';

$fp = fopen($progress_target, 'r');
$records = [];
$ids = [];
while ($line = fgets($fp)) {
    $data = json_decode($line);
    $ids[$data->{'提案編號'}] = $data->進度;
    $records[] = $data;
}

usort($records, function($a, $b) {
    return $b->{'進度'} <=> $a->{'進度'};
});

$output = new StdClass;
$reports = [];
foreach (array_chunk(array_keys($ids), 100) as $chunked_ids) {
    $terms = [];
    $terms[] = 'output_fields=關連議案';
    $terms[] = 'output_fields=提案編號';
    $terms[] = 'output_fields=議案名稱';
    $terms[] = 'output_fields=議案編號';
    $terms[] = 'output_fields=法律編號';
    $terms[] = 'output_fields=議案狀態';

    foreach ($chunked_ids as $id) {
        $terms[] = "提案編號=" . urlencode($id);
    }
    $url = "https://v2.ly.govapi.tw/bills?" . implode('&', $terms);
    $obj = json_decode(file_get_contents($url));
    foreach ($obj->bills as $bill) {
        $progress = $ids[$bill->提案編號];
        unset($ids[$bill->提案編號]);
        // 本身就三讀就不用繼續了
        if ($bill->議案狀態 == '三讀') {
            continue;
        }
        $hit_bill = null;
        foreach ($bill->關連議案 ?? [] as $related) {
            if (strpos($related->議案名稱, '審查') !== false) {
                $reports[$related->billNo] = $progress;
                $hit_bill = $related;
                break;
            }
        }
        if (is_null($hit_bill)) {
            $output->bills[] = [
                '議案編號' => $bill->議案編號,
                '進度' => $progress,
                '法律編號' => $bill->{'法律編號'}[0],
                '議案狀態' => $bill->議案狀態,
            ];
            error_log("{$bill->議案編號} {$bill->議案狀態} {$bill->{'法律編號:str'}[0]} 沒有報告併案 => {$progress}");
        }
    }
}

$terms = [];
$terms[] = 'output_fields=關連議案';
$terms[] = 'output_fields=提案編號';
$terms[] = 'output_fields=議案名稱';
$terms[] = 'output_fields=議案編號';
$terms[] = 'output_fields=法律編號';
$terms[] = 'output_fields=議案狀態';
foreach (array_keys($reports) as $billNo) {
    $terms[] = "議案編號=" . urlencode($billNo);
}
$url = "https://v2.ly.govapi.tw/bills?" . implode('&', $terms);
$obj = json_decode(file_get_contents($url));
foreach ($obj->bills as $bill) {
    $progress = $reports[$bill->議案編號];
    unset($reports[$bill->議案編號]);
    if ($bill->議案狀態 == '三讀') {
        continue;
    }
    error_log("{$bill->議案編號} {$bill->議案狀態} {$bill->{'法律編號:str'}[0]} 審查報告狀態未三讀 => {$progress}");
    $output->bills[] = [
        '議案編號' => $bill->議案編號,
        '進度' => $progress,
        '法律編號' => $bill->{'法律編號'}[0],
        '議案狀態' => $bill->議案狀態,
    ];
}
$output->missing_reports = array_keys($reports);
$output->missing_ids = array_keys($ids);
file_put_contents($list_target, json_encode($output, JSON_UNESCAPED_UNICODE));
