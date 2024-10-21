<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../../imports/Importer.php');

$term = getenv('term');
if (!$term) {
    $term = 11;
}

foreach ([
    'gazette.php',
    'gazette/crawl.php',
    'gazette/crawl-doc.php',
] as $script) {
    $cmd = sprintf("php %s",
        escapeshellarg(__DIR__ . '/../../imports/' . $script)
    );
    system($cmd, $ret);
    if ($ret != 0) {
        throw new Exception("Failed to run $cmd");
    }
}
