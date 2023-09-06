<?php

include(__DIR__ . '/Elastic.php');
include(__DIR__ . '/BillParser.php');

if (file_exists(__DIR__ . '/config.php')) {
    include(__DIR__ . '/config.php');
}
