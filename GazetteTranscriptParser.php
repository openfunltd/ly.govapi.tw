<?php

class GazetteTranscriptParser
{
    public static function parseVote($ret, $hit_agenda)
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
                            $term = explode('-', $hit_agenda->meet_id)[1];
                            $vote->{$matches[1]} = GazetteParser::parsePeople($content, $term);
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

    /**
     * matchSectionTitle 檢查這一行是否有符合某一個議程的標題
     * 
     */
    public static function matchSectionTitle($p_dom, $agendas)
    {
        if ($p_dom->getElementsByTagName('b')->length > 0) {
            return false;
        }
        $title = trim(str_replace('。', '', $p_dom->textContent));
        $title = str_replace('立法院', '', $title);
        $title = str_replace('案由：', '', $title);
        foreach ($agendas as $agenda) {
            $agenda->subject = str_replace('。', '', $agenda->subject);
            $agenda->subject = str_replace("\n", "", $agenda->subject);
            $agenda->subject = str_replace('立法院', '', $agenda->subject);
            if (strpos($agenda->subject, $title) === 0) {
                return $title;
            }
        }
        return false;
    }

    /**
     * filterAgendaBlock 篩選只有這一章節的內容
     * 
     */
    public static function filterAgendaBlock($blocks, $block_lines, $agendas, $hit_agenda)
    {
        $start_idx = $end_idx = null;

        $content = $hit_agenda->content;
        $content = str_replace('　', '', $content);
        $content = str_replace(' ', '', $content);
        $content = str_replace('。', '', $content);
        $content = str_replace("\n", '', $content);
        $content = str_replace('立法院', '', $content);

        foreach ($blocks as $idx => $block) {
            if (strpos($block[0], '段落：') !== 0) {
                continue;
            }
            $title = explode('：', $block[0], 2)[1];
            if (strpos($content, $title) === false) {
                continue;
            }
            $start_idx = $idx;
            break;
        }
        if (is_null($start_idx)) {
            return [$blocks, $block_lines];
        }
        for ($i = $start_idx + 1; $i < count($blocks); $i ++) {
            if (strpos($blocks[$i][0], '段落：') === 0) {
                $end_idx = $i;
                break;
            }
        }
        if (is_null($end_idx)) {
            $end_idx = count($blocks);
        }
        $blocks = array_slice($blocks, $start_idx, $end_idx - $start_idx);
        $block_lines = array_slice($block_lines, $start_idx, $end_idx - $start_idx);
        return [$blocks, $block_lines];
    }

    public static function parse($content, $agendas, $hit_agenda)
    {
        $doc = new DOMDocument;
        // UTF-8
        $content = str_replace('<head>', '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">', $content);
        if ($_GET['html'] ?? false) {
            echo $content;
            exit;
        }

        @$doc->loadHTML($content);

        $blocks = [];
        $block_lines = [];
        $current_block = [];
        $current_line = 1;
        $persons = [];
        $idx = 0;
        $p_doms = [];
        foreach ($doc->getElementsByTagName('body') as $body_dom) {
            foreach ($body_dom->childNodes as $child) {
                if ($child->nodeName == 'p') {
                    $p_doms[] = $child;
                }
            }
        }
        $section = null;
        while (count($p_doms)) {
            $p_dom = array_shift($p_doms);
            $idx ++;

            $line = trim($p_dom->textContent);
            if (trim($line) == '') {
                continue;
            }

            if (strpos($p_dom->getAttribute('class'), '(標題)')) {
                $blocks[] = $current_block;
                $line = str_replace(' ', '', $line);
                $line = str_replace('　', '', $line);
                $blocks[] = ['段落：' . $line];
                $section = $line;
                $current_block = [];
                $block_lines[] = $current_line;
                $current_line = $idx;
                continue;
            }

            if ($title = self::matchSectionTitle($p_dom, $agendas)) {
                $blocks[] = $current_block;
                $blocks[] = ['段落：' . $title];
                $section = $line;
                $current_block = [];
                $block_lines[] = $current_line;
                $current_line = $idx;
                continue;
            }

            // 如果是「第x案：」開頭，並且接下來五行內有「案由：xxx」，用案由去檢查
            if (preg_match('#^第.*案：#u', $line)) {
                for ($i = 0; $i < 5; $i ++) {
                    if (!preg_match('#^案由：#u', trim($p_doms[$i]->textContent))) {
                        continue;
                    }
                    $title = explode('：', $p_doms[$i]->textContent, 2)[1];
                    if ($title = self::matchSectionTitle($p_doms[$i], $agendas)) {
                        $blocks[] = $current_block;
                        $blocks[] = ['段落：' . $title];
                        $section = $title;
                        $current_block = [$line];
                        for ($j = 0; $j <= $i; $j ++) {
                            $idx ++;
                            $p_dom = array_shift($p_doms);
                            $line = trim($p_dom->textContent);
                            $current_block[] = $line;
                            $block_lines[] = $current_line;
                            $current_line = $idx;
                        }
                        continue 2;
                    }
                }

            }

            // 處理開頭是「國是論壇」
            if (!count($blocks) and $line == '國是論壇') {
                $current_block[] = $line;
                while (count($p_doms)) {
                    $idx ++;
                    $p_dom = array_shift($p_doms);
                    $line = trim($p_dom->textContent);
                    $current_block[] = $line;
                    if (strpos($line, '：') !== false) {
                        break;
                    }
                }
                continue;
            }

            if (strpos($line, '|') === 0) {
                $current_block[] = $line;
                continue;
            }

            if (in_array($section, ['報告事項', '質詢事項'])) {
                if (preg_match('#([一二三四五六七八九十○]+)、#u', $line, $matches)) {
                    if ($current_block) {
                        $blocks[] = $current_block;
                        $current_block = [];
                    }
                    $current_block[] = '項目：' . $line;
                    continue;
                } else {
                    if (strpos($line, '（以上質詢事項') === 0) {
                        $blocks[] = $current_block;
                        $current_block = [];
                        $section = null;
                    }
                    $current_block[] = $line;
                    continue;
                }
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
            $b_dom = $p_dom->getElementsByTagName('b')->item(0);
            if (!$b_dom or strpos($p_dom->textContent, '：') === false) {
                $current_block[] = $line;
                continue;
            }
            $person = str_replace('：', '', $b_dom->textContent);
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

        list($blocks, $block_lines) = self::filterAgendaBlock($blocks, $block_lines, $agendas, $hit_agenda);

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
        return self::parseVote($ret, $hit_agenda);
    }

    public static function trimString($str)
    {
        $str = str_replace('　', '', $str);
        $str = str_replace("\r", '', $str);
        $str = str_replace("\n", '', $str);
        $str = str_replace(' ', '', $str);
        $str = str_replace('‧', '', $str);
        $str = str_replace('．', '', $str);
        $str = str_replace('&nbsp;', '', $str);
        return $str;
    }

    public static function filterBlockByTitle($blocks, $title)
    {
        if ('質詢事項' === $title) { 
            while (count($blocks->blocks)) {
                $first_blocks = $blocks->blocks[0];
                foreach ($first_blocks as &$line) {
                    if (self::trimString($line) != '質詢事項') {
                        array_shift($blocks->blocks[0]);
                        continue;
                    }
                    break 2;
                }
                array_shift($blocks->blocks);
                array_shift($blocks->block_lines);
            }
            return $blocks;
        } else {
            echo $title . "\n";
            print_r($blocks);
            exit;
        }
    }
}
