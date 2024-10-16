<?php

include(__DIR__ . '/../../init.inc.php');

if (!file_exists(__DIR__ . "/bill-doc-parsed/tikahtml")) {
	mkdir(__DIR__ . "/bill-doc-parsed/tikahtml");
}
if (!file_exists(__DIR__ . "/bill-doc-parsed/html")) {
    mkdir(__DIR__ . "/bill-doc-parsed/html");
}

$list = BillParser::getListFromFileAndDir($_SERVER['argv'][1], __DIR__ . "/bill-html");
foreach ($list as $idx => $v) {
    list($filename, $time, $obj) = $v;
    $billNo = $obj->id;
    $input_file = __DIR__ . "/bill-docgz/{$billNo}.doc.gz";
    if (!file_exists($input_file) or filesize($input_file) < 100) {
        continue;
    }
	if (array_key_exists(2, $_SERVER['argv'])) {
		if ($idx % $_SERVER['argv'][2] != $_SERVER['argv'][1]) {
			continue;
		}
	}
    $filename = basename($input_file);
    $billno = explode('.', $filename)[0];

    if (file_exists(__DIR__ . "/bill-doc-parsed/tikahtml/{$filename}") and filesize(__DIR__ . "/bill-doc-parsed/tikahtml/{$filename}") > 100) {
    } else {
        error_log($filename);
        $cmd = sprintf("zcat %s > %s", escapeshellarg($input_file), escapeshellarg(__DIR__ . '/tmp.doc'));
        system($cmd);
        system(sprintf("curl -T %s https://tika.openfun.dev/tika -H 'Accept: text/html' > %s", escapeshellarg(__DIR__ . '/tmp.doc'), escapeshellarg(__DIR__ . '/tmp.html')), $ret);
        if ($ret) {
            throw new Exception('curl failed');
        }
        file_put_contents(__DIR__ . "/bill-doc-parsed/tikahtml/{$filename}", gzencode(file_get_Contents(__DIR__ . '/tmp.html')));
    }
}
