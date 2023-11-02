<?php

include(__DIR__ . '/../../init.inc.php');

$content = gzdecode(file_get_contents($_SERVER['argv'][1]));
$billNo = '';
try {
    $values = BillParser::parseBillDetail($billNo, $content);
    print_r($values);
} catch (Exception $e) {
    error_log("{$billNo} error: " . $e->getMessage());
}
