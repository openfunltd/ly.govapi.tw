<?php

include(__DIR__ . "/../../init.inc.php");

$fp = fopen($_SERVER['argv'][1], 'r');
$seq = 0;
$total = 0;
while ($obj = json_decode(fgets($fp))) {
    $billNo = $obj->id;
    $total ++;
    if (!file_exists(__DIR__ . "/bill-html/{$billNo}.gz")) {
        continue;
    }
	$target = __DIR__ . '/bill-docgz/' . $billNo . '.doc.gz';
    if (file_exists($target) and filesize($target) >= 100) {
        continue;
    }
    $content = gzdecode(file_get_contents(__DIR__ . "/bill-html/{$billNo}.gz"));
    try {
        $values = BillParser::parseBillDetail($billNo, $content);
    } catch (Exception $e) {
        continue;
    }

    $docUrls = array();

	$pdfUrl = $docUrl = false;
	if (!$values->{'相關附件'}) {
		continue;
	}
    foreach ($values->{'相關附件'} as $record) {
        if (!property_exists($record, '名稱')) {
            var_dump($record);
            error_log("$billNo no 名稱: " . json_encode($record, JSON_UNESCAPED_UNICODE));
            continue;
        }
        $record->{'名稱'} = str_replace('(更正版)', '', $record->{'名稱'});
		if ($record->{'名稱'} == '關係文書(PDF)下載') {
			$pdfUrl = $record->{'網址'};
		} else if ($record->{'名稱'} == '關係文書(DOC)下載') {
			$docUrl = $record->{'網址'};
		} elseif ($record->{'名稱'} == '關係文書DOC') {
            $docUrl = $record->{'網址'};
        } elseif (stripos($record->{'名稱'}, '關係文書(含審查報告)DOC') === 0) {
            $docUrls[] = $record->{'網址'};
		} else if (strpos($docUrl, 'http://lci.ly.gov.tw/LyLCEW/LCEWA01') === 0 and strpos($record->{'名稱'}, '檔案上傳時間') === 0) {
			if (strpos($record->{'網址'}, 'http://lci.ly.gov.tw/LyLCEW//LCEWA01') === 0) {              } else {
				$docUrls[$record->{'網址'}] = $record->{'網址'};
			}
		}
	}

	if (count($docUrls)) {
        $docUrls = array_values($docUrls);
        file_put_contents(__DIR__ . "/bill-docgz/{$billNo}.doc.gz", 'array');
		foreach ($docUrls as $idx => $docUrl) {
			$target = __DIR__ . '/bill-docgz/' . $billNo . '-' . $idx . '.doc.gz';
			if (!file_exists($target) or filesize($target) < 1000) {
				error_log("{$docUrl} to {$values->billNo}-{$idx}.doc.gz");
				$docUrl = str_replace('http://', 'https://', $docUrl);
                $docUrl = str_replace('https://lci.ly.gov.tw/LyLCEW/', 'https://ppg.ly.gov.tw/ppg/download/', $docUrl);
                system(sprintf("curl -L --user-agent %s --ipv4 --connect-timeout 10 -o %s %s",
                    escapeshellarg('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3'),
                    escapeshellarg("{$values->billNo}-{$idx}.doc"),
                    escapeshellarg($docUrl)));
				system("gzip " . escapeshellarg("{$values->billNo}-{$idx}.doc"));
				rename("{$values->billNo}-{$idx}.doc.gz", $target);
			}
		}
		continue;
	} else {
		if (preg_match('#http://lci.ly.gov.tw/LyLCEW/agenda1/(\d+)/pdf(/\d+/\d+/\d+(/\d+)?/LCEWA\d+_\d+_\d+)\.pdf#', $pdfUrl, $matches)) {
			if ($docUrl != 'http://lci.ly.gov.tw/LyLCEW/agenda1/' . $matches[1] . '/word' . $matches[2] . '.doc') {
				error_log($values->billNo);
				$docUrl = 'http://lci.ly.gov.tw/LyLCEW/agenda1/' . $matches[1] . '/word' . $matches[2] . '.doc';
			}
		}
	}
	$target = __DIR__ . '/bill-docgz/' . $billNo . '.doc.gz';
    if (!file_exists($target) or filesize($target) < 100) {
		if (!$docUrl) {
			continue;
		}
        error_log("{$docUrl} to {$values->billNo}.doc.gz");
        $docUrl = str_replace('http://', 'https://', $docUrl);
        $docUrl = str_replace('https://lci.ly.gov.tw/LyLCEW/', 'https://ppg.ly.gov.tw/ppg/download/', $docUrl);
        system(sprintf("curl -L --user-agent %s --ipv4 --connect-timeout 10 -o %s %s",
            escapeshellarg('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3'),
            escapeshellarg("{$values->billNo}.doc"),
            escapeshellarg($docUrl)));
        system("gzip " . escapeshellarg("{$values->billNo}.doc"));
        copy("{$values->billNo}.doc.gz", $target);
		unlink("{$values->billNo}.doc.gz");
    }
}
