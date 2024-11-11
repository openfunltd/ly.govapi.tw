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

    public function trim($str)
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
        if (file_exists($cache_file)) {
            $obj = json_decode(file_get_contents($cache_file));
            if ($obj->types == $types) {
                return $obj;
            }
        }
        $content = self::file_get_contents(__DIR__ . "/law-data/laws/{$id}/{$versions[0]}.html");
        $obj = new StdClass;
        $obj->title = $title;
        $obj->versions = $versions;
        $obj->types = $types;
        try {
            $obj->law_data = $this->parseLawHTML($content);
        } catch (CustomError $e) {
            $e->setMessage("{$title} {$id} {$versions[0]} {$e->getMessage()}");
            throw $e;
        }

        foreach ($types as $type) {
            try {
                if ($type == '異動條文') {
                    // do nothing, 因為異動條文直接 diff 就可以得到了
                } elseif ($type == '立法歷程') {
                    $content = self::file_get_contents(__DIR__ . "/law-data/laws/{$id}/{$versions[0]}-立法歷程.html");
                    $obj->law_history = $this->parseHistoryHTML($content);
                } elseif ($type == '異動條文及理由') {
                    $content = self::file_get_contents(__DIR__ . "/law-data/laws/{$id}/{$versions[0]}-異動條文及理由.html");
                    $obj->law_reasons = $this->parseReasonHTML($content);
                } elseif ($type == '廢止理由') {
                    $content = self::file_get_contents(__DIR__ . "/law-data/laws/{$id}/{$versions[0]}-{$type}.html");
                    $obj->deprecated_reason = $this->parseDeprecatedHTML($content);
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

    public function parseDeprecatedHTML($content)
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

    public function parseLawHTML($content)
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
                    $line->rule_no = trim($name);
                    $line->content = $this->trim($td_dom->nodeValue);
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
                        $line->note = $this->trim($cnode->nodeValue);
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

    public function parseHistoryHTML($content)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($content);
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
                        if ($p = $this->trim($td_doms->item(3)->nodeValue)) {
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

    public function parseReasonHTML($content)
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

    public function main()
    {
        $fp = fopen(__DIR__ . '/law-data/laws-versions.csv', 'r');
        fgetcsv($fp);

        $reasons = null;
        $reason_law = null;
        while ($rows = fgetcsv($fp)) {
            list($id, $title, $versions, $types) = $rows;

            if ($reason_law === null) {
                $reason_law = $id;
                $reasons = [];
            } else if ($reason_law != $id) {
                $reason_law = $id;
                $reasons = [];
            }

            $versions = explode(';', $versions);
            if (strlen($types)) {
                $types = explode(';', $types);
            } else {
                $types = array();
            }

            $obj = self::getDataFromCache($id, $versions, $types, $title);
            foreach ($obj->law_data as $idx => $law_data) {
                $date_action = LawLib::getVersionIdFromString($obj->versions[0], $id);
                $version_id = "{$date_action['date']}-{$date_action['action']}";
                $law_content_id = "{$id}:{$version_id}:{$idx}";

                $law_content_data = [
                    'law_content_id' => $law_content_id,
                    'law_id' => $id,
                    'version_id' => $version_id,
                    'idx' => $idx,
                ];
                if (property_exists($law_data, 'rule_no')) {
                    $law_content_data['rule_no'] = $law_data->rule_no;
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

                } elseif (property_exists($law_data, 'section_name')) {
                    $law_content_data['section_name'] = $law_data->section_name;
                } else {
                    print_r($obj);
                    throw new Exception("找不到 rule_no 或 section_name");
                }
            
                Elastic::dbBulkInsert('law_content', $law_content_id, $law_content_data);
            }
        }
        fclose($fp);
        Elastic::dbBulkCommit();
    }
}

$e = new Exporter;
$e->main();
