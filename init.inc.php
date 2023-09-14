<?php

include(__DIR__ . '/Elastic.php');
include(__DIR__ . '/BillParser.php');
include(__DIR__ . '/GazetteParser.php');
include(__DIR__ . '/LYLib.php');

if (file_exists(__DIR__ . '/config.php')) {
    include(__DIR__ . '/config.php');
}
