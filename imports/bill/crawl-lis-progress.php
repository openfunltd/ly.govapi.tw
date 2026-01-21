<?php

include(__DIR__ . '/../../init.inc.php');
$target = __DIR__ . '/../../cache/lis-progress.jsonl';

// 先進首頁，並從首頁找到「法律提案相關資料及審議進度」的連結
$curl = curl_init('https://lis.ly.gov.tw/lylgmeetc/lgmeetkm');
$referer = 'https://lis.ly.gov.tw/lylgmeetc/lgmeetkm';
// enable cookie
curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
// ipv4
curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
$content = curl_exec($curl);
$info = curl_getinfo($curl);
$doc = new DOMDocument();
@$doc->loadHTML($content);
$hit_img_dom = null;
foreach ($doc->getElementsByTagName('img') as $img_dom) {
    if ($img_dom->getAttribute('title') !== '法律提案相關資料及審議進度') {
        continue;
    }
    $hit_img_dom = $img_dom;
    break;
}
if (is_null($hit_img_dom)) {
    die('找不到圖片');
}
$a_dom = $hit_img_dom->parentNode;
$href = $a_dom->getAttribute('href');

// 在「法律提案相關資料及審議進度」的頁面找到表單，並取得表單的所有 input，並選擇第 11 屆和三讀
error_log("https://lis.ly.gov.tw" . $href);
curl_setopt($curl, CURLOPT_URL, "https://lis.ly.gov.tw" . $href);
curl_setopt($curl, CURLOPT_REFERER, $referer);
$referer = "https://lis.ly.gov.tw" . $href;
$content = curl_exec($curl);
$doc = new DOMDocument();
@$doc->loadHTML($content);
if (!$form_dom = $doc->getElementsByTagName('form')->item(0)) {
    die('找不到表單');
}
$inputs = [];
$input_showed = [];
foreach ($form_dom->getElementsByTagName('input') as $input_dom) {
    if ($input_dom->getAttribute('type') == 'checkbox') {
        continue;
    }
    if ($input_dom->getAttribute('type') == 'radio' and !$input_dom->hasAttribute('checked')) {
        continue;
    }
    if ($input_dom->getAttribute('type') == 'image') {
        if ($input_dom->getAttribute('name') === '_IMG_檢索') {
            if (in_array($input_dom->getAttribute('name'), $input_showed)) {
                continue;
            }
            $input_showed[] = $input_dom->getAttribute('name');
            $inputs[] = urlencode('_IMG_檢索.x') . '=' . rand(0, 30);
            $inputs[] = urlencode('_IMG_檢索.y') . '=' . rand(0, 30);
            continue;
        } else {
            continue;
        }
    }
    $inputs[] = urlencode($input_dom->getAttribute('name')) . '=' . urlencode($input_dom->getAttribute('value'));
}

foreach ($form_dom->getElementsByTagName('select') as $input_dom) {
    $options = [];
    $name = $input_dom->getAttribute('name');
    $value = null;
    foreach ($input_dom->getElementsByTagName('option') as $option_dom) {
        $options[trim($option_dom->nodeValue)] = $option_dom->getAttribute('value');
        if ($option_dom->hasAttribute('selected')) {
            $value = $option_dom->getAttribute('value');
        } elseif (is_null($value)) {
            $value = $option_dom->getAttribute('value');
        }
    }
    if (strpos($input_dom->parentNode->parentNode->nodeValue, '進　　度')) {
        $inputs[] = urlencode($name) . '=' . urlencode($options['三讀']);
    } else if (strpos($input_dom->parentNode->parentNode->nodeValue, '屆　　別')) {
        $inputs[] = urlencode($name) . '=' . urlencode($options['11屆']);
    } else {
        $inputs[] = urlencode($name) . '=' . urlencode($value);
    }
}

$action = $form_dom->getAttribute('action');

// 改成顯示詳細資料
curl_setopt($curl, CURLOPT_URL, "https://lis.ly.gov.tw" . $action);
curl_setopt($curl, CURLOPT_REFERER, $referer);
$referer = "https://lis.ly.gov.tw" . $action;
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, implode('&', $inputs));
$content = curl_exec($curl);
$doc = new DOMDocument();
@$doc->loadHTML($content);

if (!$form_dom = $doc->getElementsByTagName('form')->item(0)) {
    die('找不到表單');
}
$inputs = [];
foreach ($form_dom->getElementsByTagName('input') as $input_dom) {
    if ($input_dom->getAttribute('type') == 'image') {
        if ($input_dom->getAttribute('name') === '_IMG_多筆詳目') {
            $inputs[] = urlencode('_IMG_多筆詳目.x') . '=' . rand(0, 30);
            $inputs[] = urlencode('_IMG_多筆詳目.y') . '=' . rand(0, 30);
            continue;
        } else {
            continue;
        }
    }
    if ($input_dom->getAttribute('type') == 'checkbox') {
        continue;
    }
    if ($input_dom->getAttribute('type') == 'radio' and !$input_dom->hasAttribute('checked')) {
        continue;
    }
    $name = $input_dom->getAttribute('name');
    if (!$name) {
        continue;
    }
    $inputs[] = urlencode($name) . '=' . urlencode($input_dom->getAttribute('value'));
}
foreach ($form_dom->getElementsByTagName('select') as $input_dom) {
    $options = [];
    $value = null;
    $name = $input_dom->getAttribute('name');
    foreach ($input_dom->getElementsByTagName('option') as $option_dom) {
        $options[trim($option_dom->nodeValue)] = $option_dom->getAttribute('value');
        if ($option_dom->hasAttribute('selected')) {
            $value = $option_dom->getAttribute('value');
        } elseif (is_null($value)) {
            $value = $option_dom->getAttribute('value');
        }
    }
    if (!$name) {
        continue;
    }
    $inputs[] = urlencode($name) . '=' . urlencode($value);
}

// 找到一頁 100 筆的連結，一次顯示 100 筆
curl_setopt($curl, CURLOPT_URL, "https://lis.ly.gov.tw" . $action);
curl_setopt($curl, CURLOPT_REFERER, $referer);
$referer = "https://lis.ly.gov.tw" . $action;
error_log("https://lis.ly.gov.tw" . $action);
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, implode('&', $inputs));
$content = curl_exec($curl);
$doc = new DOMDocument();
@$doc->loadHTML($content);
$hit_option = null;
foreach ($doc->getElementsByTagName('option') as $option_dom) {
    if (trim($option_dom->nodeValue) != '100') {
        continue;
    }

    if ($option_dom->getAttribute('value') == 100) {
        continue;
    }

    $hit_option = $option_dom;
    break;
}
if (is_null($hit_option)) {
    die('找不到選項');
}
$href = $hit_option->getAttribute('value');

$page = 1;
$records = [];

// 開始抓資料並下一頁
$post_fields = null;
while (true) {
    error_log("page: $page https://lis.ly.gov.tw" . $href);
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    if (!is_null($post_fields)) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, implode('&', $post_fields));
    }

    curl_setopt($curl, CURLOPT_URL, "https://lis.ly.gov.tw" . $href);
    curl_setopt($curl, CURLOPT_REFERER, $referer);
    $referer = "https://lis.ly.gov.tw" . $href;
    $content = curl_exec($curl);
    $info = curl_getinfo($curl);
    $doc = new DOMDocument();
    @$doc->loadHTML($content);

    foreach ($doc->getElementsByTagName('td') as $td_dom) {
        if ($td_dom->nodeValue != '首次排入院會日期') {
            continue;
        }
        $record = new StdClass;
        foreach ($td_dom->parentNode->parentNode->childNodes as $tr_dom) {
            if ($tr_dom->nodeName != 'tr') {
                continue;
            }
            $td_doms = $tr_dom->getElementsByTagName('td');
            $record->{$td_doms->item(0)->nodeValue} = trim($td_doms->item(1)->nodeValue);
        }
        $records[] = $record;
    }

    // 找下一頁
    $hit_input_dom = null;
    foreach ($doc->getElementsByTagName('input') as $input_dom) {
        if ($input_dom->getAttribute('title') != '次頁') {
            continue;
        }
        $hit_input_dom = $input_dom;
        break;
    }

    if (is_null($hit_input_dom)) {
        break;
    }
    $form = $doc->getElementsByTagName('form')->item(0);
    $href = $form->getAttribute('action');
    $post_fields = [];
    foreach ($form->getElementsByTagName('input') as $input_dom) {
        if ($input_dom->getAttribute('type') == 'image') {
            continue;
        }
        $name = $input_dom->getAttribute('name');
        if (!$name) {
            continue;
        }
        $post_fields[] = urlencode($name) . '=' . urlencode($input_dom->getAttribute('value'));
    }
    $post_fields[] = urlencode('_IMG_次頁.x') . '=' . rand(0, 30);
    $post_fields[] = urlencode('_IMG_次頁.y') . '=' . rand(0, 30);
    $page ++;
}

file_put_contents($target . ".tmp", '');
foreach ($records as $record) {
    file_put_contents($target . ".tmp", json_encode($record, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
}

if (!file_exists($target) or md5_file($target) != md5_file($target . ".tmp")) {
    rename($target . ".tmp", $target);
    error_log("update");
} else {
    unlink($target . ".tmp");
    error_log("not update");
}
