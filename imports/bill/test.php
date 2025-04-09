<?php

include(__DIR__ . '/../../init.inc.php');

$billNo = $_SERVER['argv'][1];
if (!file_exists(__DIR__ . "/bill-html/{$billNo}.gz")) {
    throw new Exception("{$billNo} html not exists");
}
$content = gzdecode(file_get_contents(__DIR__ . "/bill-html/{$billNo}.gz"));
try {
    $values = BillParser::parseBillDetail($billNo, $content);
} catch (Exception $e) {
    error_log("{$billNo} error: " . $e->getMessage());
}
$types = BillParser::getBillTypes();
$sources = BillParser::getBillSources();
$values = BillParser::addBillInfo($values);
echo json_encode($values, JSON_UNESCAPED_UNICODE) . "\n";

$obj = null;
$doc_file = __DIR__ . "/bill-docgz/{$billNo}.doc.gz";
if (file_exists(__DIR__ . '/bill-doc-parsed/tikahtml/' . $billNo . '.doc.gz')) {
    $file = __DIR__ . '/bill-doc-parsed/tikahtml/' . $billNo . '.doc.gz';
    $content = gzdecode(file_get_contents($file));
} else if (file_exists(__DIR__ . '/bill-doc-parsed/html/' . $billNo . '.doc.gz')) {
    $file = __DIR__ . '/bill-doc-parsed/html/' . $billNo . '.doc.gz';
    $obj = json_decode(gzdecode(file_get_contents($file)));
    $content = (base64_decode($obj->content));
} else if (file_exists($doc_file) and filesize($doc_file) == 5 and file_get_contents($doc_file) == 'array') {
    $content = '';
    foreach (glob(__DIR__ . "/bill-doc-parsed/tikahtml/{$billNo}-*.doc.gz") as $file) {
        error_log($file);
        $html = gzdecode(file_get_contents($file));
        if ($content) {
            $pos = strpos($content, '</body>');
            $content = substr($content, 0, $pos);

            $pos = strpos($html, '<body>');
            $html = substr($html, $pos + 6);
            $content .= $html;
        } else {
            $content = $html;
        }
    }
    file_put_contents('tmp-content.html', $content);
} else {
    exit;
}
echo json_encode($values, JSON_UNESCAPED_UNICODE) . "\n";
echo "=======\n";
$docdata = BillParser::parseBillDoc($billNo, $content, $obj, $values->first_time);
echo json_encode($docdata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
