<?php

include(__DIR__ . '/../../init.inc.php');

foreach ([
    'crawl.php',
    'import-law-content.php',
    'import-law.php',
] as $filename) {
    system(sprintf("php %s", escapeshellarg(__DIR__ . '/../../imports/law/' . $filename)));
}
