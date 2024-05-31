<?php

include(__DIR__ . '/Elastic.php');
include(__DIR__ . '/BillParser.php');
include(__DIR__ . '/GazetteParser.php');
include(__DIR__ . '/GazetteTranscriptParser.php');
include(__DIR__ . '/LYLib.php');

// timezone Asia/Taipei
date_default_timezone_set('Asia/Taipei');
if (!($_SERVER['HTTP_HOST'] ?? false)) {
    $_SERVER['HTTP_HOST'] = 'ly.govapi.tw';
}

if (file_exists(__DIR__ . '/config.php')) {
    include(__DIR__ . '/config.php');
}
