<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/IVodParser.php');
$crawled = 0;
$overtime_limit = 5;
$endtime = $_SERVER['argv'][1] ?? null;
if (!is_null($endtime)) {
    $endtime = strtotime($endtime);
} else {
    $endtime = time() - 6 * 30 * 86400;
}

$jobs = [];

$add_job = function($action, $params) use (&$jobs) {
    $get_params = [];
    $get_params[] = 'key=' . getenv('WHISPERAPI_KEY');
    foreach ($params as $k => $v) {
        $get_params[] = urlencode($k) . '=' . urlencode($v);
    }
    $url = sprintf("https://%s%s?%s", getenv('WHISPERAPI_HOST'), $action, implode('&', $get_params));
    $obj = json_decode(file_get_contents($url));
    $job_id = $obj->job_id ?? false;
    if (!$job_id) {
        throw new Exception("job_id not found: " . $url);
    }
    error_log("add job: {$obj->api_url}");
    $jobs[] = [$job_id, $obj->api_url];
};

$handle_jobs = function() use (&$jobs) {
    error_log('handle_jobs');
    $start_time = null;
    $data = null;
    if (count($jobs) == 0) {
        error_log("no job, sleep 60 seconds");
        sleep(60);
    }
    while (count($jobs) > 0) {
        list($job_id, $api_url) = $jobs[0];
        if (is_null($start_time)) {
            $start_time = time();
            error_log(sprintf("(remain: %d) checking: %s", count($jobs), $api_url));
        }
        $obj = json_decode(file_get_contents($api_url));
        if ($obj->job->status != 'error' and $obj->job->status != 'done') {
            if (time() - $start_time > 6000) {
                throw new Exception("timeout: " . $api_url);
            }
            sleep(1);
            continue;
        }
        $start_time = null;

        $id = $obj->job->data->id;
        list($v, $tool) = explode('-', $id);
        if ($tool == 'clean') {
            $data = null;
            error_log("clean done: {$id}");
            array_shift($jobs);
            continue;
        }
        if (is_null($data)) {
            $data = new StdClass;
            $data->id = $v;
        }
        if ($data->id != $v) {
            throw new Exception("id not match: {$data->id} != $v");
        }
        $data->{$tool} = $obj->job;
        error_log("job done: {$id}");
        array_shift($jobs);

        if ($data->{'whisperx'} and $data->{'pyannote'}) {
            $transcript_target = __DIR__ . '/ivod-transcript/' . $v . '.json';
            file_put_contents($transcript_target, json_encode($data, JSON_UNESCAPED_UNICODE));
            error_log("transcript done: {$v}");
            $data = null;
        }
    }
};

$max_v = $v = max(intval(file_get_contents(__DIR__ . '/current-id')), 146312);
$error_name = [];
$c = 0;
for ($v = 155000; $v <= $max_v; $v ++) {
//for (; $v > 0; $v --) {
    //error_log($v);
    $url = sprintf("https://ivod.ly.gov.tw/Play/Clip/1M/%d", $v);
    $html_target = __DIR__ . "/html/{$v}.html";
    if (!file_exists($html_target)) {
        continue;
    }
    $transcript_target = __DIR__ . '/ivod-transcript/' . $v . '.json';
    $error_retry = false;
    if (file_exists($transcript_target)) {
        $content = file_get_contents($transcript_target);
        if (strpos($content, 'status: error') === false and strpos($content, 'error: get-ly-ivod.php') === false) {
            continue;
        }
        $error_retry = true;
        // 有 error 的話要再重試
        if (time() - filemtime($transcript_target) < 5 * 60) { // 失敗的話五分鐘內不重試
            error_log("skip retry {$v} until: " . date('Y-m-d H:i:s', filemtime($transcript_target) + 5 * 60));
            continue;
        }
    }
    $ivod = IVodParser::parseHTML($v, file_get_contents($html_target));
    if ($error_retry and strtotime($ivod->start_time) < time() - 86400 * 7) { // 如果是一週以上失敗的影片就不再重試
        //continue;
    }
    if ($endtime and strtotime($ivod->start_time) < $endtime) {
        $overtime_limit --;
        if ($overtime_limit <= 0) {
            break;
        }
    }
    $init_prompt = sprintf("會議名稱：%s\n發言委員：%s", $ivod->會議名稱, $ivod->委員名稱);
    error_log("{$v}: ({$ivod->start_time}-{$ivod->end_time}) {$ivod->會議名稱}");

    $add_job('/queue/add', [
        'url' => $url,
        'tool' => 'pyannote',
        'id' => "{$v}-pyannote",
    ]);
    $add_job('/queue/add', [
        'url' => $url,
        'tool' => 'whisperx',
        'id' => "{$v}-whisperx",
        'init_prompt' => mb_substr($init_prompt, 0, 150, 'UTF-8'),
    ]);
    $add_job('/queue/add', [
        'url' => $url,
        'tool' => 'clean',
        'id' => "{$v}-clean",
    ]);
    $c ++;
    if ($c > 2) {
        break;
    }
}

$v = max(intval(file_get_contents(__DIR__ . '/current-full-id')), 15000);
$error_name = [];
for (; $v > 0; $v --) {
    if ($c >= 1) {
        break;
    }
    //error_log($v);
    $url = sprintf("https://ivod.ly.gov.tw/Play/Full/1M/%d", $v);
    $html_target = __DIR__ . "/html/{$v}.html";
    if (!file_exists($html_target)) {
        continue;
    }
    $transcript_target = __DIR__ . '/ivod-transcript/' . $v . '.json';
    $error_retry = false;
    if (file_exists($transcript_target)) {
        $content = file_get_contents($transcript_target);
        if (strpos($content, 'status: error') === false) {
            continue;
        }
        $error_retry = true;
        // 有 error 的話要再重試
        if (time() - filemtime($transcript_target) < 5 * 60) { // 失敗的話五分鐘內不重試
            continue;
        }
    }
    $content = file_get_contents($html_target);
    if (strpos($content, '"rettim":null') !== false) {
        error_log("rettim not found {$url}");
        continue;
    }
    $ivod = IVodParser::parseHTML($v, $content, 'Full');
    if ($error_retry and strtotime($ivod->start_time) < time() - 86400 * 7) { // 如果是一週以上失敗的影片就不再重試
        //continue;
    }
    if ($endtime and strtotime($ivod->start_time) < $endtime) {
        break;
    }
    $init_prompt = sprintf("會議名稱：%s\n發言委員：%s", $ivod->會議名稱, $ivod->委員名稱);
    error_log("{$v}: ({$ivod->start_time}-{$ivod->end_time}) {$ivod->會議名稱}");

    $add_job('/queue/add', [
        'url' => $url,
        'tool' => 'pyannote',
        'id' => "{$v}-pyannote",
    ]);
    $add_job('/queue/add', [
        'url' => $url,
        'tool' => 'whisperx',
        'id' => "{$v}-whisperx",
        'init_prompt' => mb_substr($init_prompt, 0, 150, 'UTF-8'),
    ]);
    $add_job('/queue/add', [
        'url' => $url,
        'tool' => 'clean',
        'id' => "{$v}-clean",
    ]);
    $c ++;
}
$handle_jobs();
