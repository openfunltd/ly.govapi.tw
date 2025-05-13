<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../../LawLib.php');

date_default_timezone_set('Asia/Taipei');

class CustomError extends Exception{
    public function setMessage($message){
        $this->message = $message;
    }
}

class Exporter
{
    public static function file_get_contents($file)
    {
        //error_log($file);
        return file_get_contents($file);
    }

    public static function trim($str)
    {
        $str = str_replace('&nbsp;', '', $str);
        return trim($str, " \t\n" . html_entity_decode('&nbsp;'));
    }

    public static function getDataFromCache($id, $versions, $types, $title)
    {
        if (!file_exists(__DIR__ . "/law-data/law_cache")) {
            mkdir(__DIR__ . "/law-data/law_cache");
        }
        $cache_file = __DIR__ . "/law-data/law_cache/{$id}-{$versions[0]}.json";
        if (file_exists($cache_file) and filemtime($cache_file) > strtotime('2025-01-16 16:00')) {
            $obj = json_decode(file_get_contents($cache_file));
            $hit = false;
            if ($obj->types == $types) {
                $hit = true;
            }
            // check invalid date
            foreach ($obj->law_history ?? []  as $history) {
                if (!preg_match('#^\d+-\d+-\d+$#', $history->{'會議日期'})) {
                    $hit = false;
                }
                if ($history->{'會議日期'} == '2532-22-28') {
                    $hit = false;
                }
            }

            if ($hit) {
                return $obj;
            }
        }
        $content = self::file_get_contents(__DIR__ . "/law-data/laws/{$id}/{$versions[0]}.html");
        $obj = new StdClass;
        $obj->title = $title;
        $obj->versions = $versions;
        $obj->types = $types;
        if (preg_match('#<td class="law_NA">([^<]+)#u', $content, $matches)) {
            $obj->title = $matches[1];
        }
        try {
            $obj->law_data = self::parseLawHTML($content);
        } catch (CustomError $e) {
            $e->setMessage("{$title} {$id} {$versions[0]} {$e->getMessage()}");
            throw $e;
        }

        $billNos = [];
        foreach ($types as $type) {
            try {
                if ($type == '異動條文') {
                    // do nothing, 因為異動條文直接 diff 就可以得到了
                } elseif ($type == '立法歷程') {
                    $content = self::file_get_contents(__DIR__ . "/law-data/laws/{$id}/{$versions[0]}-立法歷程.html");
                    $obj->law_history = self::parseHistoryHTML($content, $committees);
                    $obj->committees = $committees;
                    $obj->law_history = self::handleHistoryData($obj->law_history, $id, $versions[0], $committees, $billNos);
                } elseif ($type == '異動條文及理由') {
                    $content = self::file_get_contents(__DIR__ . "/law-data/laws/{$id}/{$versions[0]}-異動條文及理由.html");
                    $obj->law_reasons = self::parseReasonHTML($content);
                } elseif ($type == '廢止理由') {
                    $content = self::file_get_contents(__DIR__ . "/law-data/laws/{$id}/{$versions[0]}-{$type}.html");
                    $obj->deprecated_reason = self::parseDeprecatedHTML($content);
                } else {
                    error_log("{$title} {$id} {$versions[0]} {$type}");
                    continue;
                    throw new Exception("TODO {$type} 未處理");
                }
            } catch (CustomError $e) {
                $e->setMessage("{$title} {$id} {$versions[0]} {$type} {$e->getMessage()}");
                throw $e;
            } catch (Exception $e) {
                throw new Exception("{$title} {$id} {$versions[0]} {$type} {$e->getMessage()}");
            }
        }

        if (!preg_match('#中華民國(.*)年(.*)月(.*)日#', $obj->versions[count($obj->versions) - 1], $matches)) {
            throw new CustomError("找不到最後時間");
        }
        $commit_at = sprintf("%04d%02d%02d", $matches[1] + 1911, $matches[2], $matches[3]);

        if (!json_encode($obj)) {
            throw new Exception("{$title} " .date('c', $commit_at));
        }
        if (!file_exists(__DIR__ . "/law-data/law_cache")) {
            mkdir(__DIR__ . "/law-data/law_cache");
        }
        file_put_contents(__DIR__ . "/law-data/law_cache/{$id}-{$versions[0]}.json", json_encode($obj));
        return $obj;
    }

    public static function parseDeprecatedHTML($content)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);

        foreach ($doc->getElementsByTagName('td') as $td_dom) {
            if ($td_dom->getAttribute('class') == 'artipud_RS_2') {
                return trim($td_dom->nodeValue);
            }
        }
        return '';
        throw new CustomError("找不到 td.artipud_RS_2");
    }

    public function parseRelateHTML($content)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);

        $ret = array();
        $table_dom = $doc->getElementsByTagName('font')->item(0)->parentNode->nextSibling;
        while ($table_dom = $table_dom->nextSibling) {
            $group = null;
            $law = null;
            foreach ($table_dom->getElementsByTagName('font') as $font_dom) {
                if ($font_dom->getAttribute('color') == 'blue') {
                    $text = trim($font_dom->nodeValue);
                    $text = trim($text, ':');
                    if (in_array($text, array('引用條文', '被引用條文'))) {
                        $group = $text;
                        $law = null;
                        if (array_key_exists($group, $ret)) {
                            throw new Exception("$group 出現兩次");
                        }
                        $ret[$group] = array();
                    } elseif (preg_match('#^(.*)\((.*)\)$#', $text, $matches)) {
                        if (is_null($group)) {
                            print_r($ret);
                            echo $doc->saveHTML($table_dom);
                            throw new Exception("錯誤");
                        }
                        $law_name = $matches[1];
                        $law = $matches[2];
                        $ret[$group][$law] = array(
                            'law_no' => $law,
                            'law_name' => $law_name,
                            'numbers' => array(),
                        );
                    } else {
                        echo $doc->saveHTML($font_dom);
                        throw new Exception("不明格式");
                    }
                } elseif ($font_dom->getAttribute('color') == 'c000ff') {
                    if (is_null($group) or is_null($law)) {
                        echo $doc->saveHTML($font_dom);
                        throw new Exception("不明格式");
                    }
                    $ret[$group][$law]['numbers'][] = trim($font_dom->nodeValue);
                } else {
                    echo $doc->saveHTML($font_dom);
                    throw new Exception("不明格式");
                }
            }
        }
        return array_map('array_values', $ret);
    }

    public static function parseLawHTML($content)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);
        $table_dom = $doc->getElementById('C_box')->getElementsByTagName('table')->item(1);

        $lines = array();

        foreach ($table_dom->childNodes as $tr_dom) {
            if ($tr_dom->nodeName != 'tr') {
                continue;
            }
            $td_doms = $tr_dom->childNodes;

            // 正常來說 <tr> 裡面只會有一個 <td>
            if ($td_doms->length != 1) {
                echo $doc->saveHtml($tr_dom);
                throw new CustomError("td 應該只有一個, 但是有 {$td_doms->length} 個");
            }
            $td_dom = $td_doms->item(0);
            $name = null;

            $line = new StdClass;
            while (true) {
                $pos = 0;
                while ($n = $td_dom->childNodes->item($pos) and $n->nodeName == '#text' and trim($n->nodeValue) == '') {
                    $pos ++;
                }

                // 如果 <td> 內純文字開頭，表示進入法案內容，就可以跳出了
                if ($pos >= $td_dom->childNodes->length or $td_dom->childNodes->item($pos)->nodeName == '#text') {
                    $line->rule_no = str_replace(' ', '', trim($name));
                    $line->content = self::trim($td_dom->nodeValue);
                    $lines[] = $line;
                    break;
                }

                // 第一個 node 一定是 <font> 並且裡面會有章節條名稱
                $cnode = $td_dom->childNodes->item($pos);
                if ($cnode->nodeName == 'font') {
                    $pos ++;
                    if (!is_null($name)) {
                        $line->section_name = $name;
                        $lines[] = $line;
                        $line = new StdClass;
                    }
                    $name = $cnode->nodeValue;

                    // 下一個 node 如果是純文字，就是備註，要不然就是法條內容
                    $cnode = $td_dom->childNodes->item($pos);
                    if ($cnode->nodeName == '#text') {
                        $line->note = self::trim($cnode->nodeValue);
                        $pos ++;
                    }
                }

                // 下一個一定是 <table>
                $cnode = $td_dom->childNodes->item($pos);
                if ($cnode->nodeName != 'table') {
                    echo $doc->saveHtml($tr_dom);
                    throw new CustomError("tr 下應該要有 <font/> 和 <table />");
                }

                $td_dom = $cnode->getElementsByTagName('td')->item(1);

            }
        }
        return $lines;
    }

    public static function parseHistoryHTML($content, &$committees)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);
        $committees = [];
        foreach ($doc->getElementsByTagName('b') as $b_dom) {
            if ($b_dom->nodeValue == '委員會：') {
            } elseif ($b_dom->nodeValue == '審查委員會：') {
            } else {
                continue;
            }

            $font_dom = $b_dom->nextSibling;
            while ($font_dom = $font_dom->nextSibling) {
                if ($font_dom->nodeName != 'font') {
                    continue;
                }
                $committees = explode(' ', trim($font_dom->nodeValue));
                $committees = array_map(function($a) {
                    return $a . '委員會';
                }, $committees);
                break;
            }
        }
        $records = array();

        foreach ($doc->getElementsByTagName('table') as $table_dom) {
            if ($table_dom->getAttribute('class') == 'sumtab04') {
                foreach ($table_dom->getElementsByTagName('div') as $div_dom) {
                    if ($div_dom->getAttribute('id') == 'all') {
                        continue;
                    }
                    foreach ($div_dom->getElementsByTagName('tr') as $tr_dom) {
                        $td_doms = $tr_dom->getElementsByTagName('td');
                        if (!$td_doms->item(0)) {
                            continue;
                        }
                        if ($td_doms->item(0)->getAttribute('class') != 'sumtd1') {
                            continue;
                        }
                        $record = new StdClass;
                        $record->{'進度'} = $td_doms->item(0)->nodeValue;
                        $record->{'會議日期'} = $td_doms->item(1)->nodeValue;
                        if (!$td_doms->item(2)) {
                            throw new CustomError("找不到立法紀錄的表格");
                        }
                        $record->{'立法紀錄'} = $td_doms->item(2)->nodeValue;
                        if ($td_doms->item(2)->getElementsByTagName('a')->item(0)) {
                            $record->{'立法紀錄連結'} = $td_doms->item(2)->getElementsByTagName('a')->item(0)->getAttribute('href');
                        }
                        if ($p = self::trim($td_doms->item(3)->nodeValue)) {
                            $record->{'主提案'} = iconv('UTF-8', 'UTF-8//IGNORE', $p);
                        }
                        $record->{'關係文書'} = array();
                        if (!$td_doms->item(4)) {
                            $a_doms = array();
                        } else {
                            $a_doms = $td_doms->item(4)->getElementsByTagName('a');
                        }
                        foreach ($a_doms as $a_dom) {
                            $href = $a_dom->getAttribute('href');
                            if (strpos($href, '/') === 0) {
                                $href = 'http://lis.ly.gov.tw' . $href;
                            }
                            if (!$text_dom = $a_dom->nextSibling or $text_dom->nodeType != XML_TEXT_NODE) {
                                $record->{'關係文書'}[] = array(
                                    'null',
                                    $href,
                                );
                                continue;
                            }
                            $text = trim($text_dom->nodeValue, html_entity_decode('&nbsp;') . "\n\r");
                            $text = preg_replace('#\s#', '', $text);
                            if (!preg_match('#^\((.*)\),?$#', $text, $matches)) {
                                $text = '';
                            } else {
                                $text = $matches[1];
                            }
                            $record->{'關係文書'}[] = array(
                                $text,
                                $href,
                            );
                        }
                        $records[] = $record;
                    }
                }
            }
        }
        return $records;
    }

    public static function parseReasonHTML($content)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);
        $reasons = new StdClass;
        foreach ($doc->getElementsByTagName('td') as $td_dom) {
            if ($td_dom->getAttribute('class') != 'artipud_RS_2') {
                continue;
            }
            $reason = $td_dom->nodeValue;
            $tr_dom = $td_dom->parentNode;
            while ($tr_dom = $tr_dom->previousSibling) {
                if ($tr_dom->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }
                $font_dom = $tr_dom->getElementsByTagName('font')->item(0);
                if (!$font_dom) {
                    continue;
                }
                if ($font_dom->getAttribute('color') == '#8600B3') {
                    break;
                }
            }
            if (!$font_dom) {
                throw new CustomError("找不到 {$reason} 對應到的 .artiupd_TH_1 是哪一條");
            }
            $reasons->{trim($font_dom->nodeValue)} = $reason;
        }
        return $reasons;
    }

    protected static $_lcew_map = null;
    protected static $_word_map = null;
    public static function loadLCEW()
    {
        if (is_null(self::$_lcew_map)) {
            self::$_lcew_map = new StdClass;
        }
        if (file_exists(__DIR__ . "/../../cache/word-map.json")) {
            self::$_word_map = json_decode(file_get_contents(__DIR__ . "/../../cache/word-map.json"));
        } else {
            self::$_word_map = new StdClass;
        }

        foreach (glob(__DIR__ . "/../../cache/bill-list.jsonl.00.*") as $f) {
            if (file_exists("{$f}.lcewmap")) {
                self::$_lcew_map = json_decode(file_get_contents("{$f}.lcewmap"));
                break;
            }
            $fp = fopen($f, 'r');
            while ($line = fgets($fp)) {
                $obj = json_decode($line);
                foreach ($obj->attachments as $attachment) {
                    if (!preg_match('#(LCEWA01_\d+_\d+)#', $attachment->link, $matches)) {
                        continue;
                    }

                    if (!property_exists(self::$_lcew_map, $matches[1])) {
                        self::$_lcew_map->{$matches[1]} = [];
                    }
                    self::$_lcew_map->{$matches[1]}[] = $obj->id;
                }
            }
            fclose($fp);
            file_put_contents("{$f}.lcewmap", json_encode(self::$_lcew_map));
            break;
        }
    }

    public static function getBillNoFromId($id)
    {
        if (!file_exists(__DIR__ . "/../../cache/mtcdoc/{$id}.doc")) {
            // curl ipv4
            $cmd = sprintf("curl -4 -o %s %s",
                escapeshellarg(__DIR__ . "/../../cache/mtcdoc/{$id}.doc"),
                escapeshellarg("https://lis.ly.gov.tw/lygazettec/mtcdoc?{$id}")
            );
            system($cmd, $ret);
            if ($ret != 0) {
                throw new CustomError("{$cmd} failed");
            }
        }
        if (!file_exists(__DIR__ . "/../../cache/mtcdoc/{$id}.txt")) {
            system(sprintf("curl -T %s https://tika.openfun.dev/tika -H 'Accept: text/plain' > %s",
                escapeshellarg(__DIR__ . "/../../cache/mtcdoc/{$id}.doc"),
                escapeshellarg(__DIR__ . '/tmp.txt')), $ret);
            if ($ret) {
                throw new CustomError("curl failed");
            }
            rename(__DIR__ . '/tmp.txt', __DIR__ . "/../../cache/mtcdoc/{$id}.txt");
        }

        $content = file_get_contents(__DIR__ . "/../../cache/mtcdoc/{$id}.txt");
        if (preg_match('#議案編號：(\d+)#', $content, $matches)) {
            return [$matches[1]];
        }
        if (preg_match('#院總第\d+號\s+委員\s+提案第\s+(\d+)\s+號#', $content, $matches)) {
            $word = preg_replace('#\s+#', '', $matches[0]);
            if (property_exists(self::$_word_map, $word)) {
                return self::$_word_map->{$word};
            }
            $url = "https://v2.ly.govapi.tw/bills?字號=" . urlencode($word);
            error_log("query $word");
            $obj = json_decode(file_get_contents($url));
            $ret = [];
            foreach ($obj->bills as $bill) {
                $ret[] = $bill->{'議案編號'};
            }
            self::$_word_map->{$word} = $ret;
            file_put_contents(__DIR__ . "/../../cache/word-map.json", json_encode(self::$_word_map));
            return $ret;
        }
        return [];
    }

    public static function updateDocData($doc, $law_id, $version_id)
    {
        if (strpos($doc->{'連結'}, 'https://lis.ly.gov.tw/lgcgi/lgmeetimage') === 0) {
            return $doc;
        }

        if (strpos($doc->{'連結'}, 'https://lis.ly.gov.tw/lgcgi/lylgmeet_newimg?') === 0) {
            return $doc;
        }

        if (strpos($doc->{'連結'}, 'https://lis.ly.gov.tw/lygazettec/mtcdoc?DN') === 0) {
            $lcew_id = explode(':', $doc->{'連結'})[2];
            if (preg_match('#^\d+(_\d)?$#', $lcew_id)) {
                $bills = self::getBillNoFromId(explode('?', $doc->{'連結'})[1]);
            } else if (property_exists(self::$_lcew_map, $lcew_id)) {
                $bills = self::$_lcew_map->{$lcew_id};
            } else {
                $bills = self::getBillNoFromId(explode('?', $doc->{'連結'})[1]);
            }
            $hit_bill = null;
            foreach ($bills as $billNo) {
                if (!file_exists(__DIR__ . "/../bill/bill-data/{$billNo}.json.gz")) {
                    continue;
                }
                $data = json_decode(gzdecode(file_get_contents(__DIR__ . "/../bill/bill-data/{$billNo}.json.gz")));
                if (!property_exists($data, 'laws')) {
                    continue;
                }
                if (!is_array($data->laws)) {
                    throw new CustomError("{$billNo} 沒有 laws");
                }
                if (!in_array($law_id, $data->laws)) {
                    continue;
                }
                $hit_bill = $billNo;
                break;
            }

            if (is_null($hit_bill)) {
                error_log("找不到 {$doc->{'連結'}} 對應的法案 law_id = {$law_id}");
                //readline('continue');
                return $doc;
                throw new CustomError("找不到 {$doc->{'連結'}} 對應的法案");
            }
            $doc->billNo = $hit_bill;
            return $doc;
        }
        print_r($doc);
        var_dump($law_id);
        var_dump($version_id);
        exit;
    }

    public static function handleHistoryData($records, $law_id, $version_id, $committees, &$billNos)
    {
        $ret = [];
        foreach ($records as $record) {
            unset($record->{'立法紀錄連結'});
            if ($record->{'會議日期'} == '6212228') {
                // 01530:1978-05-19-修正
                $record->{'會議日期'} = '661228';
            }
            $record->{'會議日期'} = trim($record->{'會議日期'});
            if (preg_match('#^\d+$#', $record->{'會議日期'})) {
                $d = $record->{'會議日期'}; // YYMMDD or YYYMMDD
                $record->{'會議日期'} = sprintf("%04d-%02d-%02d",
                    substr($d, 0, -4) + 1911, substr($d, -4, 2), substr($d, -2, 2));
            } else {
                unset($record->{'會議日期'});
            }
            if (preg_match('#^(\d+)卷(\d+)期(\d*)號((.*)冊)? (\d+)-(\d+)#u', $record->{'立法紀錄'}, $matches)) {
                $matches[5] = trim($matches[5]);
                if ($matches[5] == '一' or $matches[5] == '上') {
                    $book_id = 1;
                } elseif ($matches[5] == '二' or $matches[5] == '中') {
                    $book_id = 2;
                } elseif ($matches[5] == '三' or $matches[5] == '下') {
                    $book_id = 3;
                } elseif ($matches[5] == '四') {
                    $book_id = 4;
                } elseif ($matches[5] == '五') {
                    $book_id = 5;
                } elseif ($matches[5] == '六') {
                    $book_id = 6;
                } elseif ($matches[5] == '七') {
                    $book_id = 7;
                } elseif ($matches[5] == '八') {
                    $book_id = 8;
                } elseif ($matches[5] == '九') {
                    $book_id = 9;
                } elseif ($matches[5] == '十') {
                    $book_id = 10;
                } elseif ($matches[5] == '十一') {
                    $book_id = 11;
                } elseif ($matches[5] == '十二') {
                    $book_id = 12;
                } elseif ($matches[5] == '十三') {
                    $book_id = 13;
                } elseif ($matches[5] == '十四') {
                    $book_id = 14;
                } elseif ($matches[5] == '十五') {
                    $book_id = 15;
                } elseif ($matches[5] == '十六') {
                    $book_id = 16;
                } elseif ($matches[5] == '十七') {
                    $book_id = 17;
                } elseif ($matches[5] == '十八') {
                    $book_id = 18;
                } elseif ($matches[5] == '十九') {
                    $book_id = 19;
                } elseif ($matches[5] == '二十') {
                    $book_id = 20;
                } elseif (preg_match('#^\d+$#', $matches[5])) {
                    $book_id = $matches[5];
                } elseif ('' == $matches[4]) {
                    $book_id = 0;
                } else {
                    $book_id = 0;
                    print_R($matches);
                    error_log("立法紀錄冊數不正確: " . $record->{'立法紀錄'});
                    //throw new CustomError("立法紀錄冊數不正確: " . $record->{'立法紀錄'});
                }
                $record->{'公報編號'} = sprintf("%d%02d%02d",
                    $matches[1], $matches[2], $book_id);
            } else {
                error_log("立法紀錄格式不正確: " . $record->{'立法紀錄'});
                //readline('continue');
            }

            $docs = [];
            foreach ($record->{'關係文書'} ?? [] as $old_doc) {
                $doc = new StdClass;
                $doc->{'類型'} = $old_doc[0];
                $doc->{'連結'} = $old_doc[1];
                $doc = self::updateDocData($doc, $law_id, $version_id);
                if ($doc->billNo ?? false) {
                    $billNos[$doc->billNo] = true;
                }
                $docs[] = $doc;
            }

            if ($record->{'會議日期'} ?? false) {
                if (strpos($record->{'進度'}, '委員會') !== false) {
                    $type = implode(',', $committees);
                } elseif ($record->{'進度'} == '黨團協商') {
                    $type = '黨團協商';
                } else {
                    $type = '院會';
                }
                $meets= LYLib::getMeetsByDate($record->{'會議日期'}, $type);
                if ($meets) {
                    if ($type == '黨團協商') {
                        usort($meets, function($a, $b) use ($billNos, $committees) {
                            // 檢查 $billNos 有沒有在 $meets->議事網資料 裡面，有越多越前面
                            $a_hit = 0;
                            $b_hit = 0;
                            foreach (array_keys($billNos) as $billNo) {
                                if (in_array($billNo, $a->議事網資料 ?? [])) {
                                    $a_hit ++;
                                }
                                if (in_array($billNo, $b->議事網資料 ?? [])) {
                                    $b_hit ++;
                                }
                            }

                            if ($a_hit != $b_hit) {
                                return $b_hit - $a_hit;
                            }

                            // 再來比較 $committees 有沒有在 $meets->{'委員會代號:str'} 裡面，有越多越前面
                            $a_hit = 0;
                            $b_hit = 0;
                            foreach ($committees as $committee) {
                                if (in_array($committee, $a->{'委員會代號:str'} ?? [])) {
                                    $a_hit ++;
                                }
                                if (in_array($committee, $b->{'委員會代號:str'} ?? [])) {
                                    $b_hit ++;
                                }
                            }
                            if ($a_hit != $b_hit) {
                                return $b_hit - $a_hit;
                            }
                        });
                        $record->{'會議代碼'} = $meets[0]->會議代碼;
                    } else {
                        $record->{'會議代碼'} = $meets[0]->會議代碼;
                    }
                }
            }
            $record->{'關係文書'} = $docs;

            $ret[] = $record;
        }
        return $ret;
    }

    public function main()
    {
        self::loadLCEW();

        $fp = fopen(__DIR__ . '/law-data/laws-versions.csv', 'r');
        fgetcsv($fp);
        $law_latest_versions = [];
        while ($rows = fgetcsv($fp)) {
            list($id, $title, $versions, $types) = $rows;
            $law_latest_versions[$id] = $versions;
        }
        fclose($fp);

        $fp = fopen(__DIR__ . '/law-data/laws-versions.csv', 'r');
        fgetcsv($fp);

        $reasons = null;
        $reason_law = null;
        $content_version = null;
        while ($rows = fgetcsv($fp)) {
            list($id, $title, $versions, $types) = $rows;

            $version_data = new StdClass;

            if ($reason_law === null) {
                $reason_law = $id;
                $reasons = [];
                $content_version = [];
            } else if ($reason_law != $id) {
                $reason_law = $id;
                $reasons = [];
                $content_version = [];
            }

            $versions = explode(';', $versions);
            if (strlen($types)) {
                $types = explode(';', $types);
            } else {
                $types = array();
            }

            $obj = self::getDataFromCache($id, $versions, $types, $title);
            $date_action = LawLib::getVersionIdFromString($obj->versions[0], $id);
            $version_id = "{$id}:{$date_action['date']}-{$date_action['action']}";

            $current = '非現行';
            if (implode(';', $versions) == $law_latest_versions[$id]) {
                $current = '現行';
            }

            $law_version_data = [
                'version_id' => $version_id,
                'law_id' => $id,
                'date' => $date_action['date'],
                'action' => $date_action['action'],
                'current' => $current,
            ];
            $version_data->data = $law_version_data;
            $version_data->contents = [];
            if ($obj->law_history ?? false) {
                $law_version_data['history'] = $obj->law_history;
            }
            if ($obj->committees ?? false) {
                $law_version_data['committees'] = $obj->committees;
            }
            Elastic::dbBulkInsert('law_version', $version_id, $law_version_data);

            $law_content_id = "{$id}:{$version_id}:0";
            $law_content_data = [
                'law_content_id' => $law_content_id,
                'law_id' => $id,
                'version_id' => $version_id,
                'idx' => 0,
                'rule_no' => '法律名稱',
                'content' => $obj->title,
                'current' => $current,
            ];
            $version_data->contents[] = $law_content_data;
            Elastic::dbBulkInsert('law_content', $law_content_id, $law_content_data);

            foreach ($obj->law_data as $idx => $law_data) {
                $idx ++;
                $law_content_id = "{$id}:{$version_id}:{$idx}";

                $law_content_data = [
                    'law_content_id' => $law_content_id,
                    'law_id' => $id,
                    'version_id' => $version_id,
                    'idx' => $idx,
                    'current' => $current,
                ];
                if (property_exists($law_data, 'rule_no')) {
                    $law_content_data['rule_no'] = str_replace(' ', '', trim($law_data->rule_no));
                    $law_data->content = preg_replace('#^　　#', '', $law_data->content);
                    $law_content_data['content'] = $law_data->content;

                    $key = $law_data->rule_no . '-' . crc32($law_data->content);
                    if (($obj->law_reasons ?? false) and ($obj->law_reasons->{$law_data->rule_no} ?? false)) {
                        $obj->law_reasons->{$law_data->rule_no} = preg_replace('#^　　#', '', $obj->law_reasons->{$law_data->rule_no});

                        $reasons[$key] = $obj->law_reasons->{$law_data->rule_no};
                    }

                    if (array_key_exists($key, $reasons)) {
                        $law_content_data['reason'] = $reasons[$key];
                    }
                    if (array_key_exists($key, $content_version)) {
                        $law_content_data['version_trace'] = $content_version[$key];
                    } else {
                        $content_version[$key] = $version_id;
                        $law_content_data['version_trace'] = 'new';
                    }
                } elseif (property_exists($law_data, 'section_name')) {
                    list($rule_no, $content) = explode(' ', $law_data->section_name, 2);
                    $key = $rule_no . '-' . crc32($content);

                    if (array_key_exists($key, $content_version)) {
                        $law_content_data['version_trace'] = $content_version[$key];
                    } else {
                        $content_version[$key] = $version_id;
                        $law_content_data['version_trace'] = 'new';
                    }
                    $law_content_data['section_name'] = $law_data->section_name;
                } else {
                    print_r($obj);
                    throw new Exception("找不到 rule_no 或 section_name");
                }
            
                $version_data->contents[] = $law_content_data;
                Elastic::dbBulkInsert('law_content', $law_content_id, $law_content_data);
            }
            file_put_contents(__DIR__ . "/law-data/laws-result/{$version_id}.json", json_encode($version_data, JSON_UNESCAPED_UNICODE));
        }
        fclose($fp);
        Elastic::dbBulkCommit();
    }
}

$e = new Exporter;
$e->main();
