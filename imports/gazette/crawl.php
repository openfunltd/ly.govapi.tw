<?php

include(__DIR__ . '/../../init.inc.php');

$handle_gazette = function($hits) {
    $gazette = null;
    foreach ($hits as $hit) {
        $source = $hit->_source;
        $source = LYLib::buildGazetteAgenda($source);
        $source->isParentAgenda = false;
        $source->parentAgenda = null;

        if (!is_null($gazette) and $gazette->gazette_id != $source->gazette_id) {
            yield $gazette;
            $gazette = null;
        }
        if (is_null($gazette)) {
            $gazette = new StdClass;
            $gazette->gazette_id = $source->gazette_id;
            $gazette->agendas = [];
        }
        $gazette->ppg_full_gazette_url = $source->ppg_full_gazette_url;
        // 如果 pageStart 和 pageEnd 有被包含在之前的 agendas 內的話，就把之前的 agenda 標示為 parentAgenda
        // 並且把現在這個 agenda 的 parentAgenda 設定為之前的 agenda
        foreach ($gazette->agendas as &$agenda) {
            if ($agenda->pageStart <= $source->pageStart and $agenda->pageEnd >= $source->pageEnd) {
                $source->parentAgenda = $agenda->subject;
                $agenda->isParentAgenda = true;
                break;
            }
        }
        $gazette->agendas[] = $source;
    }
    yield $gazette;
};

$cmd = [
    'size' => 10000,
    'query' => [
        'bool' => [
            'must' => [
                [
                    'range' => [
                        'comYear' => [
                            'lte' => 120,
                        ],
                    ],
                ],
            ],
        ],
    ],
    'sort' => [
        'comYear' => 'desc',
        'comVolume' => 'desc',
        'comBookId' => 'asc',
        'agendaNo' => 'asc',
    ],
];
file_put_contents(__DIR__  '/error.log', '');
$obj = Elastic::dbQuery("/{prefix}gazette_agenda/_search", 'GET', json_encode($cmd));
foreach ($handle_gazette($obj->hits->hits) as $gazette) {
    error_log($gazette->gazette_id);
    $gazette_pdffile = __DIR__ . "/gazette-pdf/{$gazette->gazette_id}.pdf";
    if (!file_exists($gazette_pdffile)) {
        system(sprintf("wget -4 -O %s %s", escapeshellarg(__DIR__ . "/tmp.pdf"), escapeshellarg($gazette->ppg_full_gazette_url)), $ret);
        if ($ret) {
            print_r($gazette);
            throw new Exception('wget failed');
        }
        copy(__DIR__ . "/tmp.pdf", $gazette_pdffile);
        unlink(__DIR__ . "/tmp.pdf");
    }

    $gazette_txtfile = __DIR__ . "/gazette-txt/{$gazette->gazette_id}.txt";
    if (filesize($gazette_txtfile) < 1000 and strpos(file_get_contents($gazette_txtfile), '503 Service Unavailable')) {
        unlink($gazette_txtfile);
    }
    if (!file_exists($gazette_txtfile)) {
        system(sprintf("pdftotext -layout %s %s", escapeshellarg($gazette_pdffile), escapeshellarg(__DIR__ . "/tmp.txt")), $ret);
        if ($ret) {
            print_r($gazette);
            throw new Exception('pdftotext failed');
        }
        copy(__DIR__ . "/tmp.txt", $gazette_txtfile);
        unlink(__DIR__ . "/tmp.txt");
    }
    /*try {
        $gazette_pages = GazetteParser::splitPages(file_get_contents($gazette_txtfile), $gazette->gazette_id);
    } catch (Exception $e) {
        echo "gazette_txtfile = " . $gazette_txtfile . "\n";
        echo "gazette_pdffile = " . $gazette_pdffile . "\n";
        echo "pdfurl = " . $gazette->ppg_full_gazette_url . "\n";
        echo "gaette_id = " . $gazette->gazette_id . "\n";
        throw $e;
    }*/

    foreach ($gazette->agendas as $agenda) {
        if ($agenda->isParentAgenda) {
            continue;
        }
        if (strpos($agenda->parentAgenda, '質詢事項') !== false) {
            continue;
        }
        try {
            GazetteParser::getAgendaDocHTMLs($agenda);
        } catch (Exception $e) {
            echo $e->getMessage() . "\n";
            file_put_contents(__DIR__ . '/error.log', $agenda->agenda_id . ':' . $e->getMessage() . "\n", FILE_APPEND);
            //readline('continue');
        }
    }
}

