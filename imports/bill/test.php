<?php

include(__DIR__ . '/../../init.inc.php');

$billNo = $_SERVER['argv'][1];
if (!file_exists(__DIR__ . "/bill-html/{$billNo}.gz")) {
    throw new Exception("{$billNo} html not exists");
}
if (!file_Exists(__DIR__ . '/output.jsonl')) {
    throw new Exception("output.jsonl not exists");
}
$obj = json_decode(`grep $billNo output.jsonl`);
$content = gzdecode(file_get_contents(__DIR__ . "/bill-html/{$billNo}.gz"));
try {
    $values = BillParser::parseBillDetail($billNo, $content);
    echo json_encode($values, JSON_UNESCAPED_UNICODE) . "\n";
} catch (Exception $e) {
    error_log("{$billNo} error: " . $e->getMessage());
}

if (file_exists(__DIR__ . '/bill-doc-parsed/tikahtml/' . $billNo . '.doc.gz')) {
    $file = __DIR__ . '/bill-doc-parsed/tikahtml/' . $billNo . '.doc.gz';
    error_log($file);
    $content = gzdecode(file_get_contents($file));
} else if (file_exists(__DIR__ . '/bill-doc-parsed/html/' . $billNo . '.doc.gz')) {
    $file = __DIR__ . '/bill-doc-parsed/html/' . $billNo . '.doc.gz';
    $obj = json_decode(gzdecode(file_get_contents($file)));
    $content = (base64_decode($obj->content));
} else {
    exit;
}
echo "=======\n";
$docdata = BillParser::parseBillDoc($billNo, $content, $obj);
echo json_encode($docdata, JSON_UNESCAPED_UNICODE) . "\n";
