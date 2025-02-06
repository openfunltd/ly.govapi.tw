<?php

$progress_target = __DIR__ . '/../../cache/lis-progress.jsonl';
$list_target = __DIR__ . '/../../cache/lis-cache.json';

// 先讀取議事及發言系統內的資料，查看哪些議案狀態是三讀
$fp = fopen($progress_target, 'r');
$records = [];
$ids = [];
while ($line = fgets($fp)) {
    $data = json_decode($line);
    $data->進度 = intval(explode(' ', $data->進度)[0]);
    $data->進度 += 19110000;
    $ids[$data->{'提案編號'}] = date('Y-m-d', strtotime($data->進度));
    $records[] = $data;
}

usort($records, function($a, $b) {
    return $b->{'進度'} <=> $a->{'進度'};
});

// 先抓取現在議案的法律代碼和時間
$law_data = [];
foreach (array_chunk(array_keys($ids), 100) as $chunked_ids) {
    $terms = [];
    $terms[] = 'output_fields=法律編號';
    $terms[] = 'output_fields=議案名稱';
    $terms[] = 'output_fields=議案編號';
    $terms[] = 'output_fields=關連議案';
    foreach ($chunked_ids as $id) {
        $terms[] = "提案編號=" . urlencode($id);
    }
    $url = "https://v2.ly.govapi.tw/bills?" . implode('&', $terms);
    $obj = json_decode(file_get_contents($url));
    foreach ($obj->bills as $bill) {
        $progress = $ids[$bill->提案編號];
        unset($ids[$bill->提案編號]);
        if (!$bill->{'法律編號'}) {
            continue;
        }
        $law_id = $bill->{'法律編號'}[0];
        if (!array_key_exists($law_id, $law_data)) {
            $law_data[$law_id] = [
                'progress' => $progress,
                'bills' => [],
            ];
        }
        if (strtotime($progress) > strtotime($law_data[$law_id]['progress'])) {
            $law_data[$law_id]['progress'] = $progress;
            $law_data[$law_id]['bills'] = [
                $bill,
            ];
        } elseif (strtotime($progress) == strtotime($law_data[$law_id]['progress'])) {
            $law_data[$law_id]['bills'][] = $bill;
        }
    }
}

// 檢查法律資料是否夠新
$law_params = [];
$output = new StdClass; // 輸出結果
$reports = []; // 儲存待抓資料的審查報告
foreach (array_keys($law_data) as $lawNo) {
    $law_params[] = "法律編號=" . urlencode($lawNo);
}
$url = "https://v2.ly.govapi.tw/laws?" . implode('&', $law_params);
$obj = json_decode(file_get_contents($url));
foreach ($obj->laws as $law) {
    $progress = $law_data[$law->法律編號]['progress'];
    if (strtotime($law->最新版本->日期) > strtotime($progress)) {
        unset($law_data[$law->法律編號]);
        continue;
    }
    if ($progress == $law->最新版本->日期 and ($law->最新版本->動作??false)) {
        unset($law_data[$law->法律編號]);
        continue;
    }

    foreach ($law_data[$law->法律編號]['bills'] as $bill) {
        // 抓審查報告
        $hit_bill = null;
        foreach ($bill->關連議案 ?? [] as $related) {
            if (strpos($related->議案名稱, '審查') !== false) {
                $reports[$related->議案編號] = $progress;
                $hit_bill = $related;
                break;
            }
        }
        if (is_null($hit_bill)) {
            $output->bills[] = [
                '議案編號' => $bill->議案編號,
                '進度' => $progress,
                '法律編號' => $bill->{'法律編號'}[0],
            ];
            error_log("{$bill->議案編號}  {$bill->{'法律編號:str'}[0]} 沒有報告併案 => {$progress}");
        }
    }
}

if ($reports) {
    // 抓取三讀的議案資訊 及 關聯議案
    $terms = [];
    $terms[] = 'output_fields=關連議案';
    $terms[] = 'output_fields=提案編號';
    $terms[] = 'output_fields=議案名稱';
    $terms[] = 'output_fields=議案編號';
    $terms[] = 'output_fields=法律編號';
    foreach ($reports as $billNo => $law_progress) {
        list($lawNo, $progress) = $law_progress;
        $terms[] = "議案編號=" . urlencode($billNo);
    }
    $url = "https://v2.ly.govapi.tw/bills?" . implode('&', $terms);
    $obj = json_decode(file_get_contents($url));
    foreach ($obj->bills as $bill) {
        $progress = $reports[$bill->議案編號];
        unset($reports[$bill->議案編號]);
        error_log("{$bill->議案編號} {$bill->{'法律編號:str'}[0]} 審查報告狀態未三讀 => {$progress}");

        $output->bills[] = [
            '議案編號' => $bill->議案編號,
            '進度' => $progress,
            '法律編號' => $bill->{'法律編號'}[0],
        ];
    }
}


$output->missing_reports = array_keys($reports);
$output->missing_ids = array_keys($ids);
file_put_contents($list_target, json_encode($output, JSON_UNESCAPED_UNICODE));
