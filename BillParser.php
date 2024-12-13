<?php

class BillParser
{
    public static function getListFromWeb($dir)
    {
        foreach (glob($dir . "/*.gz") as $f) {
		    yield [basename($f), filemtime($f)];
	    }
    }

    public static function getListFromFileAndDir($list_file, $dir)
    {
        $fp = fopen($list_file, 'r');
        while ($line = fgets($fp)) {
            $obj = json_decode($line);
            $id = $obj->id;
            if (!file_Exists($dir . "/{$id}.gz")) {
                continue;
            }

            $f = $dir . "/{$id}.gz";
		    yield [basename($f), filemtime($f), $obj];

        }
    }

    public static function parsePerson($person)
    {
        $persons = preg_split('#　　#u', $person);
        $persons = array_map(function($s) {
            return trim(str_replace('　', '', $s));
        }, $persons);
        $persons = array_values(array_filter($persons, 'strlen'));
        return $persons;
    }

    public static function parseOldBillDetail($billno, $doc)
    {
        $th_dom = $doc->getElementById('t1');
        $tbody_dom = $th_dom->parentNode;
        while ($tbody_dom->nodeName != 'tbody') {
            $tbody_dom = $tbody_dom->parentNode;
            if (!$tbody_dom) {
                throw new Exception($billno);
            }
        }
        $obj = new StdClass;
        $obj->billNo = $billno;

        foreach ($tbody_dom->childNodes as $tr_dom) {
            if ($tr_dom->nodeName != 'tr') {
                continue;
            }
            $th_dom = $tr_dom->getElementsByTagName('th')->item(0);
            $key = trim($th_dom->nodeValue);

            if (in_array($key, array('審查委員會', '議案名稱', '提案單位/提案委員', '議案狀態', '交付協商'))) {
                $td_dom = $tr_dom->getElementsByTagName('td')->item(0);
                $value = trim($td_dom->nodeValue);
                if ($key == '議案狀態' and preg_match('#三讀 (\d+/\d+/\d+通過) #', $value, $matches)) {
                    $value = '三讀';
                }
                $obj->{$key} = $value;
            } else if ($key == '相關附件') {
                $obj->{'相關附件'} = array();
                preg_match_all('/<a class="[^"]*"[^>]*href="([^"]*)"\s+title="([^"]*)"/', $doc->saveHTML($tr_dom), $matches);
                foreach ($matches[0] as $idx => $m) {
                    $o = new StdClass;
                    $o->{'網址'} = trim($matches[1][$idx]);
                    $o->{'名稱'} = trim($matches[2][$idx]);
                    $obj->{'相關附件'}[] = $o;
                }
            } else if ($key == '關連議案') {
                $obj->{'關連議案'} = array();
                foreach ($tr_dom->getElementsByTagName('a') as $a_dom) {
                    $name = preg_split("/\s+/", trim($a_dom->nodeValue));
                    $billno = explode("'", $a_dom->getAttribute('onclick'))[1];
                    $obj->{'關連議案'}[] = array(
                        'billNo' => $billno,
                        '提案人' => $name[0],
                        '議案名稱' => $name[1],
                    );
                }
            } else if ('提案人' == $key or '連署人' == $key) {
                $obj->{$key} = '';
                if (preg_match("/getLawMakerName\('([^']*)', '([^']*)'\);/", $doc->saveHTML($tr_dom), $matches)) {
                    $obj->{$key} = self::parsePerson(trim($matches[2]));
                }
            } else if ('議案流程' == $key) {
                $obj->{'議案流程'} = array();
                foreach ($tr_dom->getElementsByTagName('tbody')->item(0)->getElementsByTagName('tr') as $sub_tr_dom) {
                    $record = array();
                    $sub_td_doms = $sub_tr_dom->getElementsByTagName('td');
                    $record['會期'] = trim($sub_td_doms->item(0)->nodeValue);
                    $record['日期'] = array();
                    foreach ($sub_td_doms->item(1)->getElementsByTagName('div')->item(0)->childNodes as $dom) {
                        if ($dom->nodeName == 'a') {
                            $d = $dom->nodeValue;
                        } else if ($dom->nodeName == '#text' and trim($dom->nodeValue)) {
                            $d = $dom->nodeValue;
                        } else {
                            continue;
                        }
                        if (!preg_match('#(\d+)/(\d+)/(\d+)#', $d, $matches)) {
                            continue;
                        }
                        $record['日期'][] = sprintf("%04d-%02d-%02d", 1911 + intval($matches[1]), $matches[2], $matches[3]);
                    }
                    $record['院會/委員會'] = trim($sub_td_doms->item(2)->nodeValue);
                    $record['狀態'] = '';
                    foreach ($sub_td_doms->item(3)->childNodes as $n) {
                        if ($n->nodeName == '#text') {
                            $record['狀態'] .= trim($n->nodeValue);
                        }
                    }
                    $record['狀態'] = preg_replace('/\s+/', ' ', $record['狀態']);
                    $obj->{'議案流程'}[] = $record;
                }
            } else {
                $td_dom = $tr_dom->getElementsByTagName('td')->item(0);
                throw new Exception("{$key} 找不到");
            }
        }
        return $obj;
    }

    public static function parseBillDetail($billno, $content)
    {
        $doc = new DOMDocument;
        $content = str_replace('<head>', '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">', $content);
        @$doc->loadHTML($content);
        if ($th_dom = $doc->getElementById('t1')) {
            return self::parseOldBillDetail($billno, $doc);
        }
        $h4_dom = $doc->getElementsByTagName('h4')->item(0);
        if (!$h4_dom) {
            foreach ($doc->getElementsByTagName('span') as $span_dom) {
                if ($span_dom->getAttribute('class') == 'card-title fs-5 mb-3') {
                    $h4_dom = $span_dom;
                    break;
                }
            }
        }
        if (!$h4_dom) {
            throw new Exception("unknown {$billno}: h4 not found");
        }
        $obj = new StdClass;
        $obj->billNo = $billno;
        $obj->{'相關附件'} = [];
        $obj->{'議案流程'} = [];
        $obj->{'關連議案'} = [];
        $obj->{'議案名稱'} = $h4_dom->nodeValue;
        $dom = $h4_dom;
        while ($dom = $dom->nextSibling) {
            if ($dom->nodeName == 'div' and $dom->getAttribute('class') == 'row' and $h6_dom = $dom->getElementsByTagName('h6')->item(0)) {
                $obj->{'提案單位/提案委員'} = $h6_dom->nodeValue;
                break;
            }
        }
        if (!$dom) {
            foreach ($h4_dom->parentNode->getElementsByTagName('span') as $span_dom) { 
                if (strpos($span_dom->getAttribute('class'), 'text-grey mb-2') === 0) {
                    $dom = $span_dom;
                    $obj->{'提案單位/提案委員'} = $span_dom->nodeValue;
                    break;
                }
            }
        }
        if (!$dom) {
            throw new Exception("unknown {$billno}: no 提案單位");
        }
        while ($dom = $dom->nextSibling) {
            if ($dom->nodeName == 'div' and $span_dom = $dom->getElementsByTagName('span')->item(0) and (strpos($span_dom->getAttribute('class'), 'fw-bolder') !== false)) {
                $obj->{'議案狀態'} = $span_dom->nodeValue;
                break;
            }
        }
        if (!$dom) {
            foreach ($h4_dom->parentNode->getElementsByTagName('span') as $span_dom) { 
                if (strpos($span_dom->getAttribute('class'), 'mb-2  fw-bolder') !== false) {
                    $dom = $span_dom->parentNode;
                    $obj->{'議案狀態'} = $span_dom->nodeValue;
                    break;
                }
            }
        }
        if (preg_match('#三讀 \(\d+/\d+/\d+通過\)#', $obj->{'議案狀態'}, $matches)) {
            $obj->{'議案狀態'} = '三讀';
        }
        if (!$dom) {
            throw new Exception("unknown {$billno}: no 議案狀態");
        }
        foreach ($dom->parentNode->getElementsByTagName('a') as $a_dom) {
            if (strpos($a_dom->getAttribute('class'), 'Ur-BadgeLink') !== false) {
                $f = new StdClass;
                $f->{'名稱'} = trim($a_dom->nodeValue);
                $f->{'網址'} = $a_dom->getAttribute('href');
                $obj->{'相關附件'}[] = $f;
            }
        }

        if (preg_match('#click="handleClick\(&\#39;(.*)&\#39;,&\#39;(.*)&\#39;\)"\s+class="[^"]*" title="([^"]*)"#', $content, $matches)) {
            $billNo = $matches[1];
            $docuNo = $matches[2];
            $doc_title = $matches[3];
            $target = __DIR__ . "/imports/bill/bill-html/dpaper-list/{$billNo}-{$docuNo}.json";
            if (!file_exists($target)) {
                $url = "https://ppg.ly.gov.tw/ppg/api/v1/getDpaperList?billNo={$billNo}&docuNo={$docuNo}";
                error_log("download {$url}");
                $curl = curl_init($url);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                // ipv4
                curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                $content = curl_exec($curl);
                file_put_contents($target, $content);
            }

            if ($doc_title == '審查報告 發文附件') {
                $doc_obj = json_decode(file_get_contents($target));
                // 如果另外有審查報告，那就把原先的審查報告移除
                $obj->{'相關附件'} = array_values(array_filter($obj->{'相關附件'}, function($f) {
                    return strpos($f->{'名稱'}, '含審查報告') === false;
                }));

                foreach ($doc_obj as $type => $urls) {
                    foreach (explode(',', $urls) as $idx => $url) {
                        $f = new StdClass;
                        $f->{'名稱'} = "關係文書(含審查報告)" . strtoupper($type) . ($idx + 1);
                        $f->{'網址'} = $url;
                        $obj->{'相關附件'}[] = $f;
                    }
                }
            }
        }

        $list_groups = [];
        foreach ($doc->getElementsByTagName('h3') as $h3_dom) {
            $list_groups[] = $h3_dom;
        }

        if (!$list_groups) {
            foreach ($doc->getElementsByTagName('span') as $span_dom) {
                if (strpos($span_dom->getAttribute('class'), 'text-lagoon') === false) {
                    continue;
                }
                $dom = $span_dom;
                while ($dom = $dom->parentNode) {
                    if ($dom->nodeName == 'article') {
                        $list_groups[] = $span_dom;
                        break;
                    }
                }
            }
        }

        foreach ($list_groups as $h3_dom) {
            if ($h3_dom->nodeValue == '關聯議案') {
                $dom = $h3_dom->parentNode;
                foreach ($dom->getElementsByTagName('a') as $a_dom) {
                    if (!preg_match('#/ppg/bills/(.*)/details#', $a_dom->getAttribute('href'), $matches)) {
                        throw new Exception("unknown {$billno}: wrong link " . $a_dom->getAttribute('href'));
                    }
                    $b = new StdClass;
                    $b->billNo = $matches[1];
                    $b->{'議案名稱'} = $a_dom->nodeValue;
                    $obj->{'關連議案'}[] = $b;
                }
            } else if (in_array($h3_dom->nodeValue, ['提案人', '連署人'])) {
                $type = $h3_dom->nodeValue;
                $obj->{$type} = [];
                foreach ($h3_dom->parentNode->getElementsByTagName('a') as $a_dom) {
                    $obj->{$type}[] = $a_dom->nodeValue;
                }
            } else if ($h3_dom->nodeValue == '審議進度') {
                $dom = $h3_dom->parentNode;
                foreach ($dom->getElementsByTagName('dl') as $dl_dom) {
                    $p = new StdClass;
                    $p->{'日期'} = [];
                    $dt_dom = $dl_dom->getElementsByTagName('dt')->item(0);
                    if ($dt_dom->nodeValue == '') {
                        continue;
                    }
                    if ($dt_dom->getElementsByTagName('h5')->length) {
                        $p->{'狀態'} = $dt_dom->getElementsByTagName('h5')->item(0)->nodeValue;
                    } else {
                        $p->{'狀態'} = $dt_dom->getElementsByTagName('span')->item(0)->nodeValue;
                    }

                    $dd_dom = $dl_dom->getElementsByTagName('dd')->item(0);
                    $text = '';
                    if ($dd_dom->getElementsByTagName('h5')->length) {
                        $text = trim($dd_dom->getElementsByTagName('h5')->item(0)->nodeValue);
                    } else {
                        $text = trim($dd_dom->getElementsByTagName('span')->item(0)->nodeValue);
                    }
                    if ($text == '') {
                        // TODO: 委員會發文？
                    } else if (preg_match('#^(.*) (\d*-.*)$#', $text, $matches)) {
                        $p->{'會期'} = $matches[2];
                        $p->{'院會/委員會'} = trim($matches[1]);
                    } elseif (in_array($text, ['議事處', '資訊處']) or preg_match('#^[^\s]+委員會$#u', trim($text))) {
                        $p->{'院會/委員會'} = trim($text);
                    } else {
                        //throw new Exception("unknown {$billno}: wrong text {$text}");
                    }
                    foreach ($dl_dom->getElementsByTagName('p') as $p_dom) {
                        if (strpos($p_dom->getAttribute('class'), 'card-text') !== false) {
                            if (preg_match('#(\d+)年(\d+)月(\d+)日#', $p_dom->nodeValue, $matches)) {
                                if ($matches[1] > 150) {
                                    $p->{'日期'}[] = sprintf("%04d-%02d-%02d", $matches[1], $matches[2], $matches[3]);
                                } else {
                                    $p->{'日期'}[] = sprintf("%04d-%02d-%02d", 1911 + intval($matches[1]), $matches[2], $matches[3]); 
                                }
                            } else {
                                throw new Exception("unknown {$billno}: wrong date {$p_dom->nodeValue}");
                            }
                        }
                    }

                    $obj->{'議案流程'}[] = $p;
                }
            }
        }
        return $obj;
    }

    public static function onlystr($str)
    {
        return preg_Replace('/\s+/', '', $str);
    }

    public static function parseTikaBillDoc($billNo, $content, $obj)
    {
        $record = new StdClass;
        $record->billNo = $billNo;

        $doc = new DOMDocument;
        if (!$content) {
            throw new Exception("{$billNo} no content");
        }
        $content = str_replace('<head>', '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">', $content);
        @$doc->loadHTML($content);

        if ($obj->proposalType != 3) {
            // 處理院總號
            foreach ($doc->getElementsByTagName('p') as $p_dom) {
                if ($p_dom->getAttribute('class') == '院總號') {
                    $record->{'字號'} = preg_replace('#\s#', '', $p_dom->parentNode->parentNode->nodeValue);
                }
            }
        }

        if ($obj->proposalType == 2) { // 委員提案
            foreach ($doc->getElementsByTagName('p') as $p_dom) {
                $text = ltrim($p_dom->nodeValue);
                if (preg_match('#立法院第(\d+)屆第(\d+)會期第(\d+)次會議議案關係文書#', $text, $matches)) {
                    $record->term = intval($matches[1]);
                    $record->sessionPeriod = intval($matches[2]);
                    $record->sessionTimes = intval($matches[3]);
                } elseif (preg_match('#立法院第(\d+)屆第(\d+)會期第(\d+)次臨時會第(\d+)次會議議案關係文書#', $text, $matches)) {
                    $record->term = intval($matches[1]);
                    $record->sessionPeriod = intval($matches[2]);
                    $record->sessionTimes = intval($matches[3]);
                } elseif (preg_match('#立法院第(\d+)屆第(\d+)會期第(\d+)次全院委員會議案關係文書#', $text, $matches)) {
                    $record->term = intval($matches[1]);
                    $record->sessionPeriod = intval($matches[2]);
                    $record->sessionTimes = intval($matches[3]);
                } else if (strpos($text, '案由：') === 0) {
                    $record->{'案由'} = trim(mb_substr($text, 3));
                } elseif (strpos($text, '說明：') === 0) {
                    $record->{'說明'} = trim(mb_substr($text, 3));
                    while ($p_dom = $p_dom->nextSibling) {
                        if ($p_dom->nodeName != 'p') {
                            continue;
                        }
                        if (strpos($p_dom->getAttribute('class'), '說明') === 0) {
                            $record->{'說明'} .= "\n" . trim($p_dom->nodeValue);
                        }
                    }
                    $record->{'說明'} = trim($record->{'說明'});
                } elseif (strpos($text, '提案人：') === 0 or strpos($text, '連署人：') === 0) {
                    $type = explode('：', $text)[0];
                    if ($record->term) {
                        $term = $record->term;
                    } elseif ($obj->term) {
                        $term = $obj->term;
                    } else {
                        throw new Exception("{$billNo} no term");
                    }
                    $record->{$type} = GazetteParser::parsePeople(mb_substr($text, 3), $term, '提案');
                }
            }
        }

        $skip_table = false;
        foreach ($doc->getElementsByTagName('table') as $table_dom) {
            $tr_doms = [];
            if (!$table_dom->getElementsByTagName('td')->length) {
                continue;
            }
            $parent_dom = $table_dom;
            // 跳過 table 內的 table
            while ($parent_dom = $parent_dom->parentNode) {
                if ($parent_dom->nodeName == 'table') {
                    continue 2;
                }
            }

            $tr_dom = $table_dom->getElementsByTagName('tr')->item(0);
            $first_td_doms = $tr_dom->getElementsByTagName('td');

            $title = trim($table_dom->getElementsByTagName('td')->item(0)->nodeValue);
            $title = str_replace('　', '', $title);
            if ($p_dom = $table_dom->getElementsByTagName('p')->item(0)) {
                if ($p_dom->getAttribute('class') == '院總號') {
                    continue;
                }
                if ($p_dom->getAttribute('class') == '官員提案') {
                    continue;
                }
                if ($p_dom->getAttribute('class') == '表格內文頂頭') {
                    error_log("skip $title");
                    continue;
                }
            }
            if (strpos($title, '附表：') === 0 or strpos($title, '院總第') === 0) {
                continue;
            }

            if ($first_td_doms->length == 1) {
                if (preg_match('#.*(草案|草案對照表|條文對照表)$#u', $title)) {
                    $skip_table = false;
                } elseif (strpos($title, '審查會通過條文') !== false and strpos($title, '條文對照表') !== false) {
                    $skip_table = false;
                } elseif (preg_match('#委員.*擬具.*案#u', $title)) {
                    $skip_table = false;
                } else {
                    $skip_table = true;
                    error_log($title);
                    continue;
                }
            }

            if ($skip_table) {
                continue;
            }

            foreach ($table_dom->getElementsByTagName('tr') as $tr_dom) {
                $tr_doms[] = $tr_dom;
            }
            if ($tr_doms) {
                $record->{'對照表'} = $record->{'對照表'} ?? [];
                try {
                    foreach (self::getDiffTableFromTrs($tr_doms, $doc, $billNo) as $diff) {
                        if (!is_null($diff)) {
                            $record->{'對照表'}[] = $diff;
                        }
                    }
                } catch (Exception $e) {
                    error_log("{$billNo} {$e->getMessage()}");
                }
            }
        }


        return $record;
    }

    public static function getDiffTableFromTrs($tr_doms, $doc, $billNo)
    {
        if (in_array($billNo, [
            "1010224070100900", // 缺少欄位？
            "1000309070100300", 
            "1000331070100400",
            "1101206070200300",
            "1090707070200200",
        ])) {
            return;
        }
        $diff = null;
        $diff_type = '一般';
        $cols = null;

        $lineno = 0;
        while (count($tr_doms)) {
            $lineno ++;
            $tr_dom = array_shift($tr_doms);
            $td_doms = $tr_dom->getElementsByTagName('td');

            if ($td_doms->length == 1) {
                $title = trim($td_doms->item(0)->nodeValue);
                if (!is_null($diff) and !is_null($diff->{'立法種類'})) {
                    yield $diff;
                }
                if (strpos($title, '審查通過') !== false or strpos($title, '審查會通過') !== false) {
                    $title = '審查會通過';
                }
                $diff = new StdClass;
                $diff->title = $title;
                $diff->{'立法種類'} = null;
                continue;
            }

            $values = [];
            foreach ($td_doms as $td_dom) {
                $v = trim($td_dom->nodeValue);
                $v = preg_replace('#（.*）#u', '', $v);
                $v = trim($v);
                $v = str_replace('　', '', $v);
                $values[] = $v;
            }
            if (implode('', $values) == '') {
                continue;
            }

            switch (implode(',', $values)) {
            case '再修正條文,修正條文,現行條文,說明':
                if (!is_null($diff->{'立法種類'})) {
                    yield $diff;
                    $title = $diff->title;
                    $diff = new StdClass;
                    $diff->title = $title;
                }
                $diff->{'立法種類'} = '再修正條文';
                $cols = ['再修正', '修正', '現行', '說明'];
                continue 2;

            case '修正條文,現行條文,說明':
            case '修正條文,現行條文,修正說明':
            case '修正條文,現行規定,說明':
            case '修正規定,現行規定,說明':
            case '修正前言,現行前言,說明':
            case '修正條文,現行公布條文,說明':
            case '修正草案,現行條文,說明':
                if ($billNo == '1050225070201400') {
                    $diff = new StdClass;
                    $diff->title = '立法院職權行使法部分條文修正草案對照表';
                } else if (is_null($diff)) {
                    throw new Exception("{$billNo} 沒有標題");
                }
                if (!is_null($diff->{'立法種類'})) {
                    yield $diff;
                    $title = $diff->title;
                    $diff = new StdClass;
                    $diff->title = $title;
                }
                $diff->{'立法種類'} = '修正條文';
                $cols = ['修正', '現行', '說明'];
                continue 2;
            case '增訂條文,說明':
            case '條文,說明':
                if ($billNo == '1010307070200700') {
                    $diff = new StdClass;
                    $diff->title = '立法院職權行使法增訂第八條之一及第八條之二條文草案';
                } else if (is_null($diff)) {
                    throw new Exception("{$billNo} 沒有標題");
                }
                if (!is_null($diff->{'立法種類'})) {
                    yield $diff;
                    $title = $diff->title;
                    $diff = new StdClass;
                    $diff->title = $title;
                }
                $diff->{'立法種類'} = '增訂條文';
                $cols = ['增訂', '說明'];
                continue 2;
            case '名稱,說明':
                if (is_null($diff)) {
                    throw new Exception("{$billNo} 沒有標題");
                }
                if (!is_null($diff->{'立法種類'})) {
                    yield $diff;
                    $title = $diff->title;
                    $diff = new StdClass;
                    $diff->title = $title;
                }
                $diff->{'立法種類'} = '增訂條文名稱';
                $cols = ['名稱', '說明'];
                continue 2;

            case '修正名稱,現行名稱,說明':
            case '修正名稱,現行條文,說明':
                if (!is_null($diff->{'立法種類'})) {
                    yield $diff;
                    $title = $diff->title;
                    $diff = new StdClass;
                    $diff->title = $title;
                }
                $diff->{'立法種類'} = '修正名稱';
                $cols = ['修正', '現行', '說明'];
                continue 2;
            }

            if (is_null($cols) and in_array('說明', $values) and (
                in_array('審查會通過條文', $values)
                or in_array('審查會通過', $values)
                or in_array('審查會條文', $values)
            )) {
                $col_pos = [
                    '說明' => array_search('說明', $values),
                ];
                if (is_null($diff)) {
                    $diff = new StdClass;
                    $diff->title = '審查會通過條文';
                }
                $diff_type = '審查會通過條文';
                if (in_array('現行法', $values)) {
                    $col_pos['現行法'] = array_search('現行法', $values);
                    $diff->{'立法種類'} = '修正條文';
                    $cols = ['修正', '現行法', '說明'];
                } elseif (in_array('現行條文', $values)) {
                    $col_pos['現行法'] = array_search('現行條文', $values);
                    $diff->{'立法種類'} = '修正條文';
                    $cols = ['修正', '現行法', '說明'];
                } else {
                    $diff->{'立法種類'} = '增訂條文';
                    $cols = ['增訂', '說明'];
                }
                if (in_array('審查會通過條文', $values)) {
                    $col_pos[$cols[0]] = array_search('審查會通過條文', $values);
                } elseif (in_array('審查會通過', $values)) {
                    $col_pos[$cols[0]] = array_search('審查會通過', $values);
                } elseif (in_array('審查會條文', $values)) {
                    $col_pos[$cols[0]] = array_search('審查會條文', $values);
                }
                continue;
            }

            if (is_null($cols)) {
                if (!in_array('說明', $values)) {
                    continue;
                } elseif (trim($values[0]) == '') {
                    continue;
                }
                error_log("{$billNo} unknown cols: " . implode(',', $values));
                throw new Exception("{$billNo} no cols: " . implode(',', $values));
            }


            if ($diff_type == '審查會通過條文') {
                $origin_values = $values;
                $values = [];
                foreach ($col_pos as $k => $pos) {
                    if ($col_pos['說明'] != $td_doms->length - 1) {
                        // 如果說明所在的位置不是最後一欄，表示可能欄位有合併儲存格
                        if ($k == '說明' or $k == '現行法') {
                            $pos ++;
                        }
                    }
                    $v = trim($td_doms->item($pos)->nodeValue);
                    $v = preg_replace("#\n +#", '', $v);
                    if ($k == '說明') {
                        $values[$k] = '';
                        foreach ($td_doms->item($pos)->childNodes as $n) {
                            $values[$k] .= $doc->saveHTML($n);
                        }
                    } else if ($k == $cols[0] and preg_match('#^\(([^\)]*)\)(.*)$#us', $v, $matches)) {
                        $values["審查會通過條文:備註"] = trim($matches[1]);
                        $values[$k] = trim($matches[2]);
                    } else if ($k == $cols[0] and preg_match('#^（([^）]*)）(.*)$#us', $v, $matches)) {
                        $values["審查會通過條文:備註"] = trim($matches[1]);
                        $values[$k] = trim($matches[2]);
                    } else {
                        $values[$k] = $v;
                    }
                }
                // 處理說明只留下審查會說明
                $lines = explode("\n", $values['說明']);
                $readme_doc = new DOMDocument;
                $readme_doc->loadHTML("<html><head><meta http-equiv='Content-Type' content='text/html; charset=utf-8'></head><body>{$values['說明']}</body></html>");
                // utf-8
                $readme_doc->encoding = 'utf-8';
                $p_doms = [];
                foreach ($readme_doc->getElementsByTagName('p') as $p_dom) {
                    $p_doms[] = $p_dom;
                }

                $values['說明'] = '';
                while ($p_dom = array_shift($p_doms)) {
                    if ($p_dom->getElementsByTagName('b')->item(0) and strpos($p_dom->nodeValue, '審查會：') !== false) {
                        break;
                    }
                }
                while ($p_dom = array_shift($p_doms)) {
                    if ($p_dom->getElementsByTagName('b')->item(0) and strpos($p_dom->nodeValue, '：') !== false) {
                        break;
                    }
                    $values['說明'] .= $p_dom->nodeValue;
                }
                $values['說明'] = trim($values['說明']);
                if (!array_key_exists('現行法', $values) or !$values['現行法']) {
                    $content = '';
                    foreach ($td_doms as $pos => $td_dom) {
                        if (in_array($pos, $col_pos) and ($pos == $col_pos[$cols[0]] or $pos == $col_pos['說明'])) {
                            continue;
                        }
                        $part_content = '';
                        foreach ($td_dom->getElementsByTagName('p') as $p_dom) {
                            if ($p_dom->getElementsByTagName('b')->item(0)) {
                                $part_content .= $p_dom->getElementsByTagName('b')->item(0)->nodeValue;
                            } else {
                                $part_content .= $p_dom->nodeValue;
                            }
                        }
                        if (strpos($part_content, '第') === 0) {
                            $part_content = "版本：" . $part_content;
                        }
                        $content .= $part_content;
                    }
                    $content = str_replace("\n", "", $content);
                    preg_match_all('#：([^　]*)#u', $content, $matches);
                    $ruleno_values = array_count_values($matches[1]);
                    arsort($ruleno_values);
                    $values['條號'] = key($ruleno_values);
                } else {
                    $values['條號'] = explode('　', $values['現行法'])[0];
                }

                $diff->rows[] = $values;
            } else {
                if ($td_doms->length != count($cols)) {
                    continue;
                    echo implode(',', $cols) . "\n";
                    echo $doc->saveHTML($tr_dom);
                    throw new Exception("{$billNo} unknown td length");
                }
                $values = [];
                foreach ($td_doms as $td_dom) {
                    $v = trim($td_dom->nodeValue);
                    $v = preg_replace("#\n +#", '', $v);
                    $values[] = $v;
                }
                $diff->rows[] = array_combine($cols, $values);
            }
        }
        if (!is_null($diff) and !is_null($diff->{'立法種類'})) {
            yield $diff;
        }
    }

    public static function parseBillDoc($billNo, $content, $obj = null)
    {
        if (strpos($content, 'org.apache.tika.parser.CompositeParser')) {
            return self::parseTikaBillDoc($billNo, $content, $obj);
        }
        $record = new StdClass;
        $record->billNo = $billNo;

        $doc = new DOMDocument;
        if (!$content) {
            throw new Exception("{$billNo} no content");
        }
        $content = preg_replace('#<img src="([^"]*)" name="DW\d+" alt="DW\d+" align="left" hspace="12" width="610"/>\n#', '', $content);
        @$doc->loadHTML($content);
        foreach ($doc->getElementsByTagName('meta') as $meta_dom) {
            if ($meta_dom->getAttribute('name') == 'created') {
                $record->created_at = $meta_dom->getAttribute('content');
            }
        }
        file_put_contents("tmp.html", $content);
        foreach ($doc->getElementsByTagName('p') as $p_dom) {
            if (strpos(trim($p_dom->nodeValue), '院總第') === 0) {
                $tr_dom = $p_dom->parentNode;
                while ('tr' != $tr_dom->nodeName) {
                    $tr_dom = $tr_dom->parentNode;
                }
                // TODO: 審查報告的字號可能會有多筆
                $record->{'字號'} = self::onlystr($tr_dom->nodeValue);
            } else if (strpos(trim($p_dom->nodeValue), '案由：') === 0) {
                $record->{'案由'} = preg_replace('/^案由：/u', '', trim($p_dom->nodeValue));
            } else if (strpos(trim($p_dom->nodeValue), '提案人：') === 0) {
                $record->{'提案人'} = preg_replace('/^提案人：/u', '', trim($p_dom->nodeValue));
            } else if (strpos(trim($p_dom->nodeValue), '連署人：') === 0) {
                $record->{'連署人'} = preg_replace('/^連署人：/u', '', trim($p_dom->nodeValue));
            } else if (in_array(self::onlystr($p_dom->nodeValue), array('修正條文', '增訂條文', '條文', '審查會通過條文', '審查會通過', '審查會條文'))) {
                if (in_array(self::onlystr($p_dom->nodeValue), array('審查會通過', '審查會條文', '審查會通過條文'))) {
                    $record->{'立法種類'} = '審查會版本';
                    // TODO: 審查會通過條文 (處理多筆字號)
                    unset($record->{'字號'});
                }
                //往上找 table 位置
                $table_dom = $p_dom->parentNode;
                while ('table' != $table_dom->nodeName) {
                    $table_dom = $table_dom->parentNode;
                    if (!$table_dom) {
                        continue 2;
                        throw new Exception("table not found");
                    }
                }
                $record->{'修正記錄'} = array();
                $tr_doms = array();
                foreach ($table_dom->childNodes as $tbody_dom) {
                    if ('tbody' == $tbody_dom->nodeName) {
                        foreach ($tbody_dom->childNodes as $tr_dom) {
                            if ('tr' != $tr_dom->nodeName) {
                                continue;
                            }
                            $tr_doms[] = $tr_dom;
                        }
                    } else if ('tr' == $tbody_dom->nodeName) {
                        $tr_doms[] = $tbody_dom;
                    }
                }
                $columns = array();
                while ($tr_dom = array_shift($tr_doms)) {
                    $td_doms = array();
                    $only_first = true;
                    foreach ($tr_dom->childNodes as $td_dom) {
                        if ('td' != $td_dom->nodeName) {
                            continue;
                        }
                        if (!count($td_doms) and trim($td_dom->nodeValue) == '') {
                            continue;
                        }
                        if (count($td_doms) and trim($td_dom->nodeValue) != '') {
                            $only_first = false;
                        }
                        if ($td_dom->getAttribute('rowspan')) {
                            for ($i = 0; $i < $td_dom->getAttribute('rowspan') - 1; $i ++) {
                                array_shift($tr_doms);
                            }
                            continue 2;
                        }
                        $td_doms[] = $td_dom;
                    }
                    if (!count($td_doms)) {
                        continue;
                    }
                    if ($only_first) {
                        $record->{'對照表標題'} = self::onlystr($td_doms[0]->nodeValue);
                    } else if (in_array(self::onlystr($td_doms[0]->nodeValue), array('審查會通過條文', '審查會通過', '審查會條文'))) {
                        // TODO: 審查會通過條文 (處理多筆字號)
                        unset($record->{'字號'});
                        foreach ($td_doms as $idx => $td_dom) {
                            if (in_array(self::onlystr($td_dom->nodeValue), array('審查會通過條文', '審查會通過', '審查會條文'))) {
                                $columns['審查會通過條文'] = $idx;
                            } else if (in_array(self::onlystr($td_dom->nodeValue), array('現行條文', '現行法條文', '現行法'))) {
                                $columns['現行條文'] = $idx;
                            } else if (self::onlystr($td_dom->nodeValue) == '說明') {
                                $columns['說明'] = $idx;
                            }
                        }
                        $record->{'立法種類'} = '審查會版本';
                        if (!array_key_exists('審查會通過條文', $columns) or !array_key_exists('說明', $columns)) {
                            throw new Exception("找不到審查會通過條文和說明欄位");
                            //echo $doc->saveHTML($tr_dom);
                            //echo json_encode($columns, JSON_UNESCAPED_UNICODE) . "\n";
                            //exit;
                        }
                    } else if (count($td_doms) >= 2 and trim($td_doms[0]->nodeValue) == '修正條文') {
                        $record->{'立法種類'} = '修正條文';
                    } else if (count($td_doms) == 2 and self::onlystr($td_doms[0]->nodeValue) == '增訂條文') {
                        $record->{'立法種類'} = '增訂條文';
                    } else if (count($td_doms) == 3 and self::onlystr($td_doms[0]->nodeValue) == '條文' and trim($td_doms[1]->nodeValue) == '現行條文') {
                        $record->{'立法種類'} = '修正條文';
                    } else if (count($td_doms) == 3 and self::onlystr($td_doms[0]->nodeValue) == '條文' and self::onlystr($td_doms[1]->nodeValue) == '參考條文' and self::onlystr($td_doms[2]->nodeValue) == '說明') {
                        $record->{'立法種類'} = '制定條文';
                        $columns['條文'] = 0;
                        $columns['說明'] = 2;
                    } else if (count($td_doms) == 2 and self::onlystr($td_doms[0]->nodeValue) == '條文') {
                        $record->{'立法種類'} = '制定條文';
                        $columns['條文'] = 0;
                        $columns['說明'] = 1;
                    } else if (count($td_doms) == 3 and trim($td_doms[0]->nodeValue) == '修正名稱') {
                        $tr_dom = array_shift($tr_doms);
                        $td_doms = $tr_dom->getElementsByTagName('td');
                        $record->{'名稱修正'} = array(
                            '修正名稱' => trim($td_doms->item(0)->nodeValue),
                            '現行名稱' => trim($td_doms->item(1)->nodeValue),
                            '說明' => str_replace("\t", "", trim($td_doms->item(2)->nodeValue)),
                        );

                    } else if (count($td_doms) == 2 and in_array(trim($td_doms[0]->nodeValue), array('名稱', '法案名稱'))) {
                        $tr_dom = array_shift($tr_doms);
                        $td_doms = $tr_dom->getElementsByTagName('td');
                        $record->{'名稱說明'} = str_replace("\t", "", trim($td_doms->item(1)->nodeValue));
                    } else if ('審查會版本' == $record->{'立法種類'}) {
                        $record->{'修正記錄'}[] = array(
                            '修正條文' => str_replace("\t", "", trim($td_doms[$columns['審查會通過條文']]->nodeValue)),
                            '現行條文' => array_key_exists('現行條文', $columns) ? str_replace("\t", "", trim($td_doms[$columns['現行條文']]->nodeValue)) : '',
                            '說明' => str_replace("\t", "", trim($td_doms[$columns['說明']]->nodeValue)),
                        );
                    } else if ('修正條文' == $record->{'立法種類'}) { // and $td_doms->length == 3) {
                        $record->{'修正記錄'}[] = array(
                            '修正條文' => str_replace("\t", "", trim($td_doms[0]->nodeValue)),
                            '現行條文' => str_replace("\t", "", trim($td_doms[1]->nodeValue)),
                            '說明' => str_replace("\t", "", trim($td_doms[2]->nodeValue)),
                        );
                    } else if ('增訂條文' == $record->{'立法種類'} and count($td_doms) == 2) {
                        $record->{'修正記錄'}[] = array(
                            '增訂條文' => str_replace("\t", "", trim($td_doms[0]->nodeValue)),
                            '說明' => str_replace("\t", "", trim($td_doms[1]->nodeValue)),
                        );
                    } else if ('制定條文' == $record->{'立法種類'}) {
                        $record->{'修正記錄'}[] = array(
                            '條文' => str_replace("\t", "", trim($td_doms[$columns['條文']]->nodeValue)),
                            '說明' => str_replace("\t", "", trim($td_doms[$columns['說明']]->nodeValue)),
                        );
                    } else {
                        if ($record->{'立法種類'} == '審查會版本') {
                           // == '1070321070300100') {
                            continue;
                        }
                        continue;
                        echo $doc->saveHTML($tr_dom);
                        echo 'trim($td_doms[0]->nodeValue) => ' .trim($td_doms[0]->nodeValue) . "\n";
                        throw new Exception("error");
                        exit;
                    }
                }
            }
        }

        $record->{'總說明'} = '';
        if (property_exists($record, '對照表標題')) {
            foreach ($doc->getElementsByTagName('span') as $span_dom) {
                if ($span_dom->nodeValue == $record->{'對照表標題'} . '總說明') {
                    $p_dom = $span_dom;
                    while ($p_dom = $p_dom->parentNode) {
                        if ($p_dom->nodeName == 'p') {
                            break;
                        }
                    }
                    if ($p_dom) {
                        while ($p_dom = $p_dom->nextSibling) {
                            if ($p_dom->nodeName == '#text') {
                                continue;
                            }
                            if ($p_dom->nodeName != 'p') {
                                break;
                            }
                            $record->{'總說明'} .= trim($p_dom->nodeValue) . "\n";
                        }
                    }
                }
            }
            $record->{'總說明'} = trim($record->{'總說明'});
        }

        return $record;
    }

    public static function getBillTypes()
    {
        // https://data.ly.gov.tw/odw/BillNo.pdf
        return [
            '10' => '修憲案',
            '11' => '不信任案',
            '12' => '覆議案',
            '13' => '緊急命令等',
            '20' => '法律案',
            '21' => '一般提案',
            '22' => '臨時提案',
            '23' => '質詢答復',
            '30' => '中央政府總預算案',
            '31' => '預(決) 算決議案、定期報告',
            '32' => '法人預(決)算案',
            '40' => '條約案',
            '50' => '同意權案',
            '60' => '--',
            '70' => '行政命令(層級)',
            '80' => '請願案',
            '90' => '院內單位來文',
            '99' => '其他',
        ];
    }

    public static function getBillSources()
    {
        return [
            '1' => '政府提案',
            '2' => '委員提案',
            '3' => '審查報告',
            '4' => '請願案',
        ];
    }

    protected static $_law_names = null;
    public static function searchLaw($s)
    {
        if (is_null(self::$_law_names)) {
            $cmd = [
                'query' => [
                    'bool' => [
                        'must' => [
                            ['term' => ['type.keyword' => '母法']],
                        ],
                    ],
                ],
                'size' => 10000,
            ];
            $obj = Elastic::dbQuery("/{prefix}law/_search", 'GET', json_encode($cmd));
            self::$_law_names = [];
            foreach ($obj->hits->hits as $hit) {
                $source = $hit->_source;
                $id = $source->id;
                $name = $source->name;
                $name_other = $source->name_other;
                foreach (array_merge([$name], $name_other) as $n) {
                    if (!array_key_exists($n, self::$_law_names)) {
                        self::$_law_names[$n] = [];
                    }
                    self::$_law_names[$n][$id] = $id;
                }
            }
        }
        for ($i = 0; $i < mb_strlen($s); $i ++) {
            if ($i == 0) {
                $cs = $s;
            } else {
                $cs = mb_substr($s, 0, -1 * $i);
            }
            if (!array_key_exists($cs, self::$_law_names)) {
                continue;
            }
            $ids = self::$_law_names[$cs];
            if (count($ids) == 1) {
                return array_values($ids)[0];
            }
            error_log("{$s} 有多個可能的法律名稱: " . implode(',', $ids));
            return null;
            throw new Exception("{$s} 有多個可能的法律名稱: " . implode(',', $ids));
        }
        file_put_contents(__DIR__ . '/imports/bill/missing_law.txt', $s . "\n", FILE_APPEND);
        return null;
    }

    public static function parseLaws($name)
    {
        $ret = [];
        preg_match_all('#「([^」]+)」#u', $name, $matches);
        foreach ($matches[1] as $n) {
            if ($id = self::searchLaw($n)) {
                $ret[] = $id;
            }
        }
        $ret = array_values(array_unique($ret));
        return $ret;
    }

    public static function addBillInfo($values)
    {
        $types = self::getBillTypes();
        $sources = self::getBillSources();
        $billNo = $values->billNo;
        if (strlen($billNo) == 15) {
            $type = substr($billNo, 0, 2);
            $source = substr($billNo, 2, 1);
            $term = substr($billNo, 3, 2);
            $no = substr($billNo, 5, 6);
            $subno1 = substr($billNo, 11, 2);
            $subno2 = substr($billNo, 13, 2);
            if (array_key_exists($type, $types) and array_key_exists($source, $sources)) {
                $values->{'議案類別'} = $types[$type];
                $values->{'提案來源'} = $sources[$source];
            }
        }

        if (property_exists($values, '議案流程') and $values->{'議案流程'}) {
            $first_period = null;
            foreach ($values->{'議案流程'} as $flow) {
                $period = null;
                if (is_object($flow)) {
                    $date = $flow->{'日期'};
                    if (property_exists($flow, '會期')) {
                        $period = $flow->{'會期'};
                    }
                } else {
                    $date = $flow['日期'];
                    if (array_key_exists('會期', $flow)) {
                        $period = $flow['會期'];
                    }
                }
                if ($period) {
                    $terms = explode('-', $period);
                    if (count($terms) > 0 and intval($terms[0]) > 0) {
                        $values->{'屆期'} = intval($terms[0]);
                    }
                    if (count($terms) > 1 and intval($terms[1])) {
                        $values->{'會期'} = intval($terms[1]);
                    }
                    if (is_null($first_period)) {
                        $first_period = $period;
                    }
                }
                if ($date) {
                    foreach ($date as $d) {
                        if (!property_exists($values, 'first_time')) {
                            $values->first_time = $d;
                        }
                        $values->last_time = $d;
                    }
                }
            }

            if (!is_null($first_period)) {
                if (preg_match('#^\d+-\d+-\d+$#', $first_period)) {
                    $first_period = implode('-', array_map('intval', explode('-', $first_period)));
                    $values->meet_id = '院會-' . $first_period;
                } elseif (preg_match('#^\d+-\d+-\d+-\d+$#', $first_period)) {
                    $first_period = implode('-', array_map('intval', explode('-', $first_period)));
                    $values->meet_id = '臨時會院會-' . $first_period;
                } elseif (preg_match('#^\d+-\d+-T\d+\.\d+$#', $first_period)) { // 09-03-T02.01
                    $first_period = str_replace('T', '', $first_period);
                    $first_period = str_replace('.', '-', $first_period);
                    $first_period = implode('-', array_map('intval', explode('-', $first_period)));
                    $values->meet_id = '臨時會院會-' . $first_period;
                } else {
                    //print_r($first_period);
                    //exit;
                }
            }
        }

        // 處理提案人、連署人
        foreach (['提案人', '連署人'] as $k) {
            if (property_exists($values, $k)) {
                $values->{$k} = self::filterPerson($values->{$k}, $values->{'屆期'});
            }
        }

        // 處理法案
        if ($values->{'議案類別'} == '法律案' or $values->{'議案類別'} == '--') {
            $values->laws = self::parseLaws($values->{'議案名稱'});
        }

        return $values;
    }

    public static function linkToLawContent($data)
    {
        $tables = $data->{'對照表'} ?? false;
        $billNo = $data->billNo;
        if (!$tables) {
            // TODO: 需要處理沒有對照表的
            return $data;
        }
        $first_time = $data->first_time;
        $changed = false;
        foreach ($tables as $table_idx => $table) {
            $law_id = BillParser::parseLaws("「{$table->title}」");
            if (!$law_id) {
                if (getenv('LOG_RESULT')) {
                    file_put_contents(__DIR__ . '/cache/log-' . getenv('LOG_RESULT'), json_encode([
                        'status' => 'error',
                        'message' => '法案對照表中找不到對應的法律',
                        'billNo' => $billNo,
                        'title' => $table->title,
                    ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                }
                error_log("{$billNo} 的法案對照表中找不到對應的法律");
                continue;
            }
            $law_id = $law_id[0];
            foreach ($table->rows as $row_idx => $row) {
                if (array_key_exists('現行', $row) and $row['現行']) {
                    $rule_no = explode('　', $row['現行'], 2)[0];
                    try {
                        $law_content_id = self::findLawContentID($law_id, $row['現行'], $first_time);
                        $data->{'對照表'}[$table_idx]->rows[$row_idx]['law_content_id'] = $law_content_id;
                        $changed = true;
                        if (getenv('LOG_RESULT')) {
                            file_put_contents(__DIR__ . '/cache/log-' . getenv('LOG_RESULT'), json_encode([
                                'status' => 'ok',
                                'billNo' => $billNo,
                                'title' => $table->title,
                                'law_id' => $law_id,
                                'rule_no' => $rule_no,
                                'law_content_id' => $law_content_id,
                            ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                        }
                    } catch (Exception $e) {
                        error_log("連結失敗：{$billNo} title={$table->title} law_id={$law_id} rule_no={$rule_no} error={$e->getMessage()}");
                        if (getenv('LOG_RESULT')) {
                            file_put_contents(__DIR__ . '/cache/log-' . getenv('LOG_RESULT'), json_encode([
                                'status' => 'error',
                                'billNo' => $billNo,
                                'title' => $table->title,
                                'law_id' => $law_id,
                                'rule_no' => $rule_no,
                                'error' => $e->getMessage(),
                            ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                        }
                        continue;
                    }
                    $rule_no = explode('　', $row['現行'], 2)[0];
                } elseif (array_key_exists('現行', $row) and $row['現行'] == '') {
                    // do nothing
                } elseif (array_key_exists('增訂', $row)) {
                    // do nothing
                } else {
                    error_log("{$billNo} 的法案對照表中找不到現行法");
                    if (getenv('LOG_RESULT')) {
                        file_put_contents(__DIR__ . '/cache/log-' . getenv('LOG_RESULT'), json_encode([
                            'status' => 'error',
                            'message' => '法案對照表中找不到現行法',
                            'billNo' => $billNo,
                            'title' => $table->title,
                        ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                    }
                    continue;
                }
            }
        }
        return $data;
    }

    public static function filterPerson($names, $term)
    {
        if (!is_array($names)) {
            if ($names === '') {
                return [];
            }
            var_dump($names);
            throw new Exception('提案人不是陣列');
        }
        return GazetteParser::parsePeople(implode('', $names), $term, '提案');
    }

    protected static $law_rule_cache = [];

    protected static function stdLawContentText($str)
    {
        $str = preg_replace('/\s+/', '', $str);
        $str = str_replace('　', '', $str);
        $str = preg_replace('#\[附表[^]]+\]#', '', $str);
        return $str;
    }

    protected static function getLawHistory($law_id, $rule_no)
    {
        if (array_key_exists("{$law_id}:{$rule_no}", self::$law_rule_cache)) {
            return self::$law_rule_cache["{$law_id}:{$rule_no}"];
        }

        $history = [];

        foreach (glob(__DIR__ . "/imports/law/law-data/laws-result/{$law_id}:*") as $f) {
            $obj = json_decode(file_get_contents($f));
            foreach ($obj->contents as $content) {
                if (($content->rule_no ?? false) != $rule_no) {
                    continue;
                }
                if ($content->version_trace != 'new') {
                    continue;
                }
                $content->std_content = self::stdLawContentText($content->content);
                $history[] = $content;
            }
        }
        usort($history, function ($a, $b) {
            return $a->version_id <=> $b->version_id;
        });
        if (!$history) {
            throw new Exception("{$law_id}:{$rule_no} not found");
        }
        self::$law_rule_cache["{$law_id}:{$rule_no}"] = $history;
        return $history;
    }

    public static function searchLawVersion($history, $content)
    {
        $matches = [];
        $std_content = self::stdLawContentText($content);
        foreach ($history as $version) {
            $similarity = similar_text($version->std_content, $std_content, $percent);
            $matches[] = [
                $version,
                $similarity,
                $percent,
            ];
        }

        usort($matches, function ($a, $b) {
            return ($b[2]  - $a[2]) * 10000;
        });

        if ($matches[0][2] == 100) {
            return $matches[0][0]->law_content_id;
        }
        foreach ($matches as $match) {
            echo "====== percent: {$match[2]}\n";
            echo "bill    : $std_content\n";
            echo "law     : {$match[0]->std_content}\n";
            echo "======\n";
        }
        //readline('press any key to continue');
        throw new Exception("not found match");
    }

    public static function findLawContentID($law_id, $content, $first_time)
    {
        list($rule_no, $content) = explode('　', $content, 2);
        $history = self::getLawHistory($law_id, $rule_no);
        $version_id = self::searchLawVersion($history, $content);

        return $version_id;
    }
}
