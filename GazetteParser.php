<?php

class GazetteParser
{
    public static $_name_list = null;

    public static function getNameList($term)
    {
        if (is_null(self::$_name_list)) {
            self::$_name_list = new StdClass;
        }
        if (property_exists(self::$_name_list, $term)) {
            return self::$_name_list->{$term};
        }
        self::$_name_list->{$term} = [];

        $cmd = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'term' => [
                                'term' => $term,
                            ],
                        ],
                    ],
                ],
            ],
            'fields' => ['name'],
            '_source' => false,
            'size' => 1000,
        ];
        $obj = Elastic::dbQuery("/{prefix}legislator/_search", 'GET', json_encode($cmd));
        foreach ($obj->hits->hits as $hit) {
            $name = $hit->fields->name[0];
            $queryname = str_replace('　', '', $name);
            $queryname = str_replace(' ', '', $queryname);
            $queryname = strtolower($queryname);
            $queryname = str_replace('‧', '', $queryname);
            $queryname = str_replace('．', '', $queryname);
            self::$_name_list->{$term}[$queryname] = $hit->fields->name[0];
        }
        if ($term == 8) {
            self::$_name_list->{$term}['(SraKacaw)'] = '鄭天財 Sra Kacaw';
            self::$_name_list->{$term}['鄭天財'] = '鄭天財 Sra Kacaw';
        }

        if ($term == 9) {
            self::$_name_list->{$term}['KolasYotaka'] = '高潞．以用．巴魕剌Kawlo．Iyun．Pacidal';
            self::$_name_list->{$term}['高潞以用巴魕剌KawloIyunPacida'] = '高潞．以用．巴魕剌Kawlo．Iyun．Pacidal';
            self::$_name_list->{$term}['簡東明UliwAljupayare'] = '簡東明Uliw．Qaljupayare';
        }
        if ($term == 10) {
            self::$_name_list->{$term}['葉毓蘭'] = '游毓蘭';
        }

        return self::$_name_list->{$term};
    }

    public static function parsePeople($str, $term)
    {
        $str = str_replace('　', '', $str);
        $str = str_replace("\r", '', $str);
        $str = str_replace("\n", '', $str);
        $str = str_replace(' ', '', $str);
        $str = str_replace('‧', '', $str);
        $str = str_replace('．', '', $str);
        $str = str_replace('&nbsp;', '', $str);
        $hit = [];

        $names = self::getNameList($term);

        while (strlen($str)) {
            foreach ($names as $qname => $name) {
                if (stripos($str, $qname) === 0) {
                    $str = substr($str, strlen($qname));
                    $hit[] = $name;
                    continue 2;
                }
            }
            if (preg_match('#^（\d+月\d+日）#', $str, $matches)) {
                // TODO: 有部份委員只出席一天，需要特別處理
                $str = substr($str, strlen($matches[0]));
                continue;
            }
            if (preg_match('#^（[^）]+）#u', $str, $matches)) {
                // TODO: 一些備註
                $str = substr($str, strlen($matches[0]));
                continue;
            }
            if (preg_match('#^(委員請假|委員出席)\d+人#', $str, $matches)) {
                $str = substr($str, strlen($matches[0]));
                continue;
            }
            var_dump($str);
            echo $term . "\n";
            error_log(json_encode($names, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            exit;
            $str = mb_substr($str, 1, 0, 'UTF-8');
        }
        return $hit;
    }

    public static function matchFirstLine($line)
    {
        if (in_array(str_replace('　', '', trim($line)), array('報告事項', '討論事項'))) {
            return ['category', str_replace('　', '', trim($line))];
        }

        if (preg_match('#^([一二三四五六七八九])、#u', trim($line), $matches)) {
            return ['一', $matches[1]];
        }
        if (preg_match('#^(\([一二三四五六七八九十]+\))#u', trim($line), $matches)) {
            return ['(一)', $matches[1]];
        }
        return false;
    }

    public static function parseVote($ret)
    {
        $ret->votes = [];
        foreach ($ret->blocks as $idx => $block) {
            while ($line = array_shift($block)) {
                if (trim($line) === '表決結果名單：') {
                    $vote = new StdClass;
                    $vote->line_no = $ret->block_lines[$idx];
                    $prev_key = null;
                    while ($line = array_shift($block)) {
                        if (preg_match('#^會議名稱：(.*)\s+表決型態：(.*)$#u', trim($line), $matches)) {
                            $vote->{'會議名稱'} = trim($matches[1]);
                            $vote->{'表決型態'} = $matches[2];
                        } else if (preg_match('#^(表決時間|表決議題)：(.*)#u', trim($line), $matches)) {
                            $prev_key = $matches[1];
                            $vote->{$matches[1]} = trim($matches[2]);
                        } else if (preg_match('#^表決結果：出席人數：(\d+)\s*贊成人數：(\d+)\s*反對人數：(\d+)\s*棄權人數：(\d+)#u', trim($line), $matches)) {
                            $vote->{'表決結果'} = [
                                '出席人數' => intval($matches[1]),
                                '贊成人數' => intval($matches[2]),
                                '反對人數' => intval($matches[3]),
                                '棄權人數' => intval($matches[4]),
                            ];
                            $prev_key = '表決結果';
                        } elseif ($prev_key == '表決議題' and strpos($line, '：') === false) {
                            $vote->{$prev_key} .= trim($line);
                        } elseif (preg_match('#^(贊成|反對|棄權)：$#u', trim($line), $matches)) {
                            $prev_key = null;
                            $content = '';
                            while (count($block) and (strpos($block[0], '：') === false)) {
                                $content .= str_replace(' ', '', array_shift($block));
                            }
                            $vote->{$matches[1]} = self::parsePeople($content);
                            if ($matches[1] == '棄權') {
                                break;
                            }
                        } else {
                            var_dump($vote);
                            var_dump($line);
                            continue 2;
                            exit;
                        }
                    }
                    $ret->votes[] = $vote;
                }
            }
        }
        return $ret;
    }

    public static function parse($content)
    {
        $blocks = [];
        $block_lines = [];
        $current_block = [];
        $current_line = 1;
        $persons = [];
        $lines = explode("\n", $content);
        $skip = [
            "出席委員", "列席委員", "專門委員", "主任秘書", "決議", "決定", "請假委員", "說明", "列席官員", "註", "※註", "機關﹙單位﹚名稱", "單位", "在場人員", "歲出協商結論", "案由", "備註", "受文者", "發文日期", "發文字號", "速別", "附件", "主旨", "正本", "副本", "列席人員",
        ];
        $idx = 0;
        while (count($lines)) {
            $idx ++;
            $line = array_shift($lines);
            $line = str_replace('　', '  ', $line);
            $line = trim($line, "\n");

            if (trim($line) == '') {
                continue;
            }

            // 處理開頭是「國是論壇」
            if (!count($blocks) and $line == '國是論壇') {
                $current_block[] = $line;
                while (count($lines)) {
                    if (strpos($lines[0], '：')) {
                        break;
                    }
                    $idx ++;
                    $line = array_shift($lines);
                    $current_block[] = $line;
                }
                continue;
            }

            if (strpos($line, '|') === 0) {
                $current_block[] = $line;
                continue;
            }

            if (preg_match('#^[一二三四五六七八]、#u', $line)) {
                $blocks[] = $current_block;
                $block_lines[] = $current_line;
                $current_line = $idx;
                $current_block = ['段落：' . $line];
                continue;
            }

            if (preg_match('#^立法院.*議事錄$#', $line)) {
                $current_block[] = $line;
                while (count($lines)) {
                    $idx ++;
                    $line = array_shift($lines);
                    $line = str_replace('　', '  ', $line);
                    $line = trim($line, "\n");
                    $current_block[] = $line;
                    if (strpos($line, '散會') === 0) {
                        break;
                    }
                }
                continue;
            }
            if (!preg_match('#^([^　 ：]+)：(.+)#u', $line, $matches)) {
                if (!count($blocks) and (strpos($line, '（續接') === 0 or strpos($line, '（上接') === 0 or strpos($line, '[pic') === 0)) {
                    $blocks[] = $current_block;
                    $block_lines[] = $current_line;
                    $current_line = $idx;
                    $current_block = ['段落：' . $line];
                    continue;
                }
                $current_block[] = $line;
                continue;
            }
            $person = $matches[1];
            if (in_array($person, $skip) or strpos($person, '、')) {
                $current_block[] = $line;
                continue;
            }
            if (!array_key_Exists($person, $persons)) {
                $persons[$person] = 0;
            }
            $persons[$person] ++;
            $blocks[] = $current_block;
            $current_block = [$line];
            $block_lines[] = $current_line;
            $current_line = $idx;
        }
        $blocks[] = $current_block;
        $block_lines[] = $current_line;
        $ret = new StdClass;
        $ret->blocks = $blocks;
        $ret->block_lines = $block_lines;
        $ret->person_count = $persons;
        $ret->persons = array_keys($persons);

        while (count($blocks[0])) {
            $line = array_shift($blocks[0]);
            $line = str_replace('　', '  ', $line);
            if (trim($line) == '') {
                continue;
            }
            if (trim($line) == '委員會紀錄') {
                $ret->type = trim($line);
                continue;
            } else if (trim($line) == '國是論壇') {
                $ret->type = $ret->title = trim($line);
                continue;
            }
            if (strpos($line, '立法院第') === 0) {
                $ret->title = $line;
                $first_line = $line;
                $block_tmp = [];
                $origin_block = json_decode(json_encode($blocks[0]));
                while (trim($blocks[0][0]) != '') {
                    if (strpos(str_replace(' ', '', $blocks[0][0]), '時間') === 0) {
                        break;
                    }
                    $line = array_shift($blocks[0]);
                    $ret->title .= $line;
                    $block_tmp[] = $line; 
                }
                if (strlen($ret->title) > 5000) {
                    $ret->title = $first_line;
                    foreach ($block_tmp as $blk) {
                        array_push($blocks[0], $blk);
                    }
                    break;
                }
                continue;
            }
            $columns = array('時間', '地點', '主席');
            mb_internal_encoding('UTF-8');
            foreach ($columns as $c) {
                if (strpos(preg_replace('/[ 　]/u', '', $line), $c) === 0) {
                    $c_len = mb_strlen($c);
                    for ($i = 0; $i < mb_strlen($line); $i ++) {
                        if (in_array(mb_substr($line, $i, 1), array(' ', '　'))) {
                            continue;
                        }
                        $c_len --;
                        if ($c_len == 0) {
                            $ret->{$c} = ltrim(mb_substr($line, $i + 1));
                            break;
                        }
                    }
                    continue 2;
                }
            }
        }
        array_shift($ret->blocks);
        array_shift($ret->block_lines);
        return self::parseVote($ret);
    }

    public static function parseInterpellation($content)
    {
        $current_page = 1;
        $content = rtrim($content);
        if (strpos($content, '專案賥詢') !== false) {
            $content = str_replace('賥', '質', $content);
        }
        if (strpos($content, '案由〆本') !== false) {
            $content = str_replace('〆', '：', $content);
        }
        if (strpos($content, '中華术國') !== false) {
            $content = str_replace('术', '民', $content);
        }
        if (strpos($content, '職後，行政院派了與花蓮縣並無淵源的法務部次長蔡碧仲代') !== false) {
            $content = str_replace("職後，行政院派了與花蓮縣並無淵源的法務部次長蔡碧仲代", "案由：本院許委員淑華，鑒於花蓮縣前縣長傅崐萁被判刑定讞而解\n職後，行政院派了與花蓮縣並無淵源的法務部次長蔡碧仲代", $content);
        }

        if (strpos($content, '各約十萬輛次，在尖峰時，台北到花蓮花了 8 小時，因國五') !== false) {
            $content = str_replace('各約十萬輛次，在尖峰時，台北到花蓮花了 8 小時，因國五', "案由：本院傅委員崐萁，針對蘇花改今年通車，春節期間南下北上\n各約十萬輛次，在尖峰時，台北到花蓮花了 8 小時，因國五", $content);
        }

        if (strpos($content, '史、感染原因不明的死亡個案，建請行政院防疫作戰勢必更') !== false) {
            $content = str_replace('史、感染原因不明的死亡個案，建請行政院防疫作戰勢必更', '案由：本院傅委員崐萁，針對日前台灣已經出首例無接觸史、旅遊\n史、感染原因不明的死亡個案，建請行政院防疫作戰勢必更', $content);
        }

        if (strpos($content, '求，民眾因恐慌性瘋搶口罩，更使得口罩供應嚴重不足，進') !== false) {
            $content = str_replace("求，民眾因恐慌性瘋搶口罩，更使得口罩供應嚴重不足，進",
                "案由：本院傅委員崐萁，針對行政院因應「新冠肺炎」口罩供不應\n"
                . "求，民眾因恐慌性瘋搶口罩，更使得口罩供應嚴重不足，進", $content);
        }

        if (strpos($content, '國民宿業者住房率下降，花蓮民宿業者影響尤其嚴重，營業') !== false) {
            $content = str_replace("國民宿業者住房率下降，花蓮民宿業者影響尤其嚴重，營業",
                "案由：本院傅委員崐萁，針對新冠肺炎重挫我國觀光產業，以致全\n"
                . "國民宿業者住房率下降，花蓮民宿業者影響尤其嚴重，營業", $content);
        }

        if (strpos($content, '光市場，交通部目前紓困計畫有 5 個項目，已經籌編新台幣') !== false) {
            $content = str_replace("光市場，交通部目前紓困計畫有 5 個項目，已經籌編新台幣",
                "案由：本院傅委員崐萁，針對交通部因應「新冠肺炎」衝擊台灣觀\n"
                . "光市場，交通部目前紓困計畫有 5 個項目，已經籌編新台幣", $content);
        }

        if (strpos($content, '炎」疫情急速升溫，國內許多產業因嚴重特殊傳染性肺炎疫') !== false) {
            $content = str_replace("炎」疫情急速升溫，國內許多產業因嚴重特殊傳染性肺炎疫",
                "案由：本院傅委員崐萁，針對勞動部近日發函指出，因應「新冠肺\n"
                . "炎」疫情急速升溫，國內許多產業因嚴重特殊傳染性肺炎疫", $content);
        }

        if (strpos($content, '                                             中政策」宣') !== false) {
            $content = str_replace("                                             中政策」宣",
                "案由：本院傅委員崐萁，針對日前菲律賓衛生部以「一中政策」宣", $content);
        }

        if (strpos($content, '                                 型冠狀肺炎疫情日趨嚴重，') !== false) {
            $content = str_replace("                                 型冠狀肺炎疫情日趨嚴重，",
                "案由：本院陳委員秀寳，有鑑於目前新型冠狀肺炎疫情日趨嚴重，", $content);
        }

        if (strpos($content, '本院陳委素月，針對政府目')) {
            $content = str_replace('本院陳委素月，針對政府目', '本院陳委員素月，針對政府目', $content);
        }

        $lines = explode("\n", $content);
        $ret = new StdClass;
        $ret->doc_title = trim(array_shift($lines));

        $get_newline = function() use (&$lines, &$current_page, $ret, &$get_newline) {
            if (!count($lines)) {
                return null;
            }
            while (trim($lines[0]) == '') {
                if (!count($lines)) {
                    return null;
                }
                array_shift($lines);
            }

            if (preg_match('#^質 (\d+)$#u', trim($lines[0]), $matches) and trim(str_replace("\f", "", $lines[1])) == '') {
                return null;
            }
            while (preg_match('#^質 (\d+)$#u', trim($lines[0]), $matches) and strpos($lines[1], $ret->doc_title) !== false) {
                $current_page = intval($matches[1]) + 1;
                array_shift($lines);
                array_shift($lines);
                while (trim($lines[0]) == '') {
                    if (!count($lines)) {
                        return null;
                    }
                    array_shift($lines);
                }
                return $get_newline();

            }
            $line = array_shift($lines);
            if (strpos(trim($line), '案 由 ： 本 院 ') === 0) {
                $line = preg_replace('#([^ ])[ ]#u', "$1", $line);
            }
            if (strpos($line, '案由：本院陳委員學聖針對行政院回覆本席書面質詢之關係文書編') !== false) {
                $line = '案由：本院陳委員學聖，針對行政院回覆本席書面質詢之關係文書編';
            }
            if (strpos($line, '立法院議案關係文書 中華民國 104 年 10 月 14 印發') !== false) {
                $line = '立法院議案關係文書 中華民國 104 年 10 月 14 日印發';
            }
            return $line;
        };

        $pop_line = function($line) use (&$lines) {
            array_unshift($lines, $line);
        };

        $ret->interpellations = [];
        $interpellation = null;
        // 第一行會是 立法院第 8 屆第 1 會期第 1 次會議議案關係文書
        if (!preg_match('#立法院第 ([0-9]+) 屆第 ([0-9]+) 會期第 ([0-9]+) 次會議議案關係文書#u', $ret->doc_title, $matches)) {
            throw new Exception("找不到屆期次: " . $ret->doc_title);
        }
        $ret->term = intval($matches[1]);
        $ret->sessionPeriod = intval($matches[2]);
        $ret->sessionTimes = intval($matches[3]);

        while (count($lines)) {
            $line = $get_newline();
            if (is_null($line)) {
                break;
            }
            // 專案質詢\n8－1－1－0001
            if (trim($line) == '專案質詢' and preg_match('#^(\d+)－(\d+)－(\d+)－(\d+)$#', trim($lines[0]), $matches)) {
                if (!is_null($interpellation)) {
                    $interpellation->page_end = $current_page;
                    $ret->interpellations[] = $interpellation;
                }
                $interpellation = new StdClass;
                $interpellation->id = implode('-', array_map('intval', array_slice($matches, 1)));
                $interpellation->page_start = $current_page;
                $interpellation->page_end = $current_page;
                array_shift($lines);

                $line = $get_newline();
                // 立法院議案關係文書 中華民國 101 年 2 月 22 日印發
                if (preg_match('#立法院議案關係文書 中華民國 ([0-9]+) 年 ([0-9]+) 月 ([0-9]+) 日印發#u', $line, $matches)) {
                    $interpellation->printed_at = sprintf("%04d-%02d-%02d", $matches[1] + 1911, $matches[2], $matches[3]);
                } else {
                    $pop_line($line);
                }
                continue;
            }

            if (preg_match('#^案由：(.*)$#u', trim($line), $matches)) {
                $interpellation->reason = $matches[1];
                if (preg_match('#^本院([^，、]+)委員([^，、]+)[，、]#u', $interpellation->reason, $matches)) {
                    $interpellation->legislators = [$matches[1] . $matches[2]];
                } elseif (preg_match('#^本院委員(.*)，#u', $interpellation->reason, $matches)) {
                    //本院委員鄭麗君、李俊俋，
                    $interpellation->legislators = explode('、', $matches[1]);
                } elseif (preg_match('#^本院([^，]*黨團)，#u', $interpellation->reason, $matches)) {
                    //本院台灣團結聯盟黨團，
                    $interpellation->legislators = [$matches[1]];
                } elseif (preg_match('#^本院([^，]*)委員，#u', $interpellation->reason, $matches)) {
                    //本院江惠貞委員，
                    $interpellation->legislators = [$matches[1]];
                } else {
                    throw new Exception("找不到委員: " . $interpellation->reason);
                }

                while ($line = $get_newline()) {
                    if (strpos(trim($line), '說明：') === 0) {
                        $pop_line($line);
                        break;
                    }
                    $interpellation->reason .= trim($line);
                }
                continue;
            }

            if (preg_match('#^說明：(.*)$#u', trim($line), $matches)) {
                $interpellation->description = $matches[1];
                if ($matches[1]) {
                    $interpellation->description .= "\n";
                }
                while ($line = $get_newline()) {
                    if (strpos(trim($line), '專案質詢') === 0) {
                        $pop_line($line);
                        break;
                    }
                    $interpellation->description .= trim($line) . "\n";
                }
                continue;
            }

            print_r($ret);
            echo json_encode($interpellation, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            echo "line: " . json_encode($line, JSON_UNESCAPED_UNICODE) . "\n";
            throw new Exception("unknown line");
        }
        if (!is_null($interpellation)) {
            $interpellation->page_end = $current_page;
            $ret->interpellations[] = $interpellation;
        }
        return $ret;
    }
}
