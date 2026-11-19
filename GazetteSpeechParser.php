<?php

/**
 * 公報發言索引（speech index）第二階段：把一份公報子文件（LCIDC01_*.doc 的 tikahtml）切成「一次發言」一筆。
 *
 * 原則（2026-11-03 定案，見 PLAN.md「規劃中：會議屬性欄位與公報發言索引」）：
 * - 公報每個字都要落在某個 block，發言者可以是空的；所以全文搜尋在這一階段就有。
 * - 單位是「一次發言」不是「一句」。切分沿用 GazetteTranscriptParser::parse() 的 block。
 * - 區塊種類（kind）和發言者分開存：發言／主席程序／附件／議事錄／宣讀表決／標題。
 * - 時間先填規則就能確定的兩種來源：公報時鐘戳記（gazette-clock）與 Clip 起訖（clip），其他 block 用前一個
 *   錨點（interpolated）。ASR 對齊之後另外補，更新同一批欄位。
 */
class GazetteSpeechParser
{
    const VERSION = 4; // 2: 原住民族姓名（羅馬拼音、「．」）的委員／官員正規化、委員名對回 legislator 寫法

    // 官員／其他身分的職稱，放在姓和名之間（例：林部長佳龍、曾主任委員智勇）
    // 職稱前可加修飾（榮譽理事長、兼任副教授、常務理事、創會理事長、代理主任委員）
    const TITLE_MODIFIER = '(?:榮譽|名譽|創會|兼任|常務|代理|首席|資深|客座|特聘|助理|講座)?';
    const TITLE_PATTERN = self::TITLE_MODIFIER . '副?(?:主任委員|主任秘書|主任檢察官|委員長|監察委員|大法官|部長|次長|署長|局長|司長|處長|院長|秘書長|祕書長|總裁|董事長|總經理|主計長|人事長|審計長|參謀長|總談判代表|談判代表|高級管理師|管理師|執行長|執行秘書|廳長|校長|館長|所長|組長|科長|主任|代表|理事長|理事主席|理事|監事|董事|市長|縣長|檢察長|檢察官|法官|參謀總長|總長|司令|廠長|課長|專門委員|參事|顧問|研究員|召集人|幹事長|書記長|總召|總幹事|總監|發言人|會長|教授|講師|律師|醫師|建築師|會計師|技師|社工師|護理師|藥師|心理師|牧師|博士|秘書|祕書|經理|創辦人|負責人|記者|先生|女士|小姐)';
    const NON_OFFICIAL_TITLES = ['教授', '講師', '律師', '醫師', '建築師', '會計師', '技師', '社工師', '護理師', '藥師', '心理師', '牧師', '博士', '先生', '女士', '小姐', '會長', '理事長', '理事主席', '理事', '監事', '董事', '代表', '召集人', '研究員', '經理', '創辦人', '負責人', '記者'];

    /**
     * 發言者原文 → [name, role, title]
     * role: 主席／委員／官員／其他
     */
    public static function normalizeSpeaker($raw, $chair_name = null, $term = null)
    {
        // 去空白，但羅馬拼音字與字之間的空白保留（Sra Kacaw、Kolas Yotaka）
        $raw = trim(preg_replace('#(?<![A-Za-z])[\s　]+|[\s　]+(?![A-Za-z])#u', '', $raw));
        if ($raw === '主席') {
            return [$chair_name ?: '主席', '主席', '主席'];
        }
        if (preg_match('#^主席[（(](.+?)代?[）)]$#u', $raw, $m)) {
            // 主席（賴委員惠員代）／主席（吳委員秉叡）
            [$name] = self::normalizeSpeaker($m[1], null, $term);
            return [$name, '主席', '主席'];
        }
        if (preg_match('#^委員(.+?)書面(質詢|意見)$#u', $raw, $m)) {
            return [self::legislatorName($m[1], $term), '委員', '書面' . $m[2]];
        }
        if (preg_match('#^(.{1,2})委員(.+)$#u', $raw, $m)) {
            return [self::legislatorName($m[1] . $m[2], $term), '委員', '委員'];
        }
        // 公聽會附的「兼任副教授李得全書面資料」「內政部書面資料」「秘書長彭揚凱書面資料」
        if (preg_match('#^(.+?)書面(資料|意見|報告)$#u', $raw, $m)) {
            [$name, $role] = self::normalizeSpeaker($m[1], $chair_name, $term);
            return [$name, $role, '書面' . $m[2]];
        }
        // 職稱在前、全名在後：「秘書長彭揚凱」「兼任副教授李得全」
        if (preg_match('#^(' . self::TITLE_PATTERN . ')([^\s]{2,4})$#u', $raw, $m) and !preg_match('#[A-Za-z．‧·]#u', $m[2])) {
            return [$m[2], self::titleRole($m[1]), $m[1]];
        }
        // 人事同意權案的「徐錫祥被提名人」：名字在前
        if (preg_match('#^(.{2,4})被提名人$#u', $raw, $m)) {
            return [$m[1], '其他', '被提名人'];
        }
        // 原住民族官員公報寫「夷將‧拔路兒主任委員」「羅赫踛Helu Chiu處長」：整個名字在前、職稱在後
        if (preg_match('#^(.{2,20}?)(' . self::TITLE_PATTERN . ')$#u', $raw, $m) and preg_match('#[A-Za-z．‧·]#u', $m[1])) {
            $title = $m[2];
            return [$m[1], self::titleRole($title), $title];
        }
        // 原住民族委員公報寫「鄭天財Sra Kacaw委員」「Kolas Yotaka委員」「伍麗華Saidhai．Tahovecahe委員」：整個名字在前、委員在後
        if (preg_match('#^(.+?)委員$#u', $raw, $m) and preg_match('#[A-Za-z．‧·]#u', $m[1])) {
            return [self::legislatorName($m[1], $term), '委員', '委員'];
        }
        if (preg_match('#^(.{1,3}?)(' . self::TITLE_PATTERN . ')(.{1,8})$#u', $raw, $m)) {
            $title = $m[2];
            return [$m[1] . $m[3], self::titleRole($title), $title];
        }
        return [$raw, '其他', null];
    }

    // 職稱 → 身分：政府機關的職稱算官員，學者、專業人士、民間團體算其他
    public static function titleRole($title)
    {
        $base = preg_replace('#^' . self::TITLE_MODIFIER . '副?#u', '', $title);
        return in_array($base, self::NON_OFFICIAL_TITLES) ? '其他' : '官員';
    }

    // 姓名比對用的 key：去空白、音節點、括號，英文轉小寫（IVOD 寫「鄭天財Sra．Kacaw」「鄭天財(Sra．Kacaw)」，公報寫「鄭天財Sra Kacaw」）
    public static function nameKey($name)
    {
        return strtolower(preg_replace('#[\s　．‧·()（）]+#u', '', (string)$name));
    }

    protected static $_legislator_keys = [];

    /**
     * 委員姓名對回 legislator 資料的寫法（GazetteParser::getNameList 含別名）；對不到就原樣回傳。
     * 羅馬拼音的名字公報有時只寫拼音（Kolas Yotaka）或只寫漢名，所以 key 相互為前綴／後綴且唯一也算對到。
     */
    public static function legislatorName($name, $term)
    {
        if (!$term) return $name;
        if (!isset(self::$_legislator_keys[$term])) {
            $keys = [];
            try {
                foreach (GazetteParser::getNameList($term) as $alias => $full) {
                    $keys[self::nameKey($alias)] = $full;
                }
            } catch (Throwable $e) {
                // 沒有 ES（離線測試）就不對照
            }
            self::$_legislator_keys[$term] = $keys;
        }
        $keys = self::$_legislator_keys[$term];
        $q = self::nameKey($name);
        if ($q === '') return $name;
        if (isset($keys[$q])) return $keys[$q];
        if (preg_match('#[a-z]#', $q)) {
            $found = [];
            foreach ($keys as $k => $full) {
                if (str_starts_with($k, $q) or str_ends_with($k, $q) or str_starts_with($q, $k) or str_ends_with($q, $k)) {
                    $found[$full] = true;
                }
            }
            if (count($found) == 1) return array_key_first($found);
        }
        return $name;
    }

    /**
     * PDF 經 tika 轉出的 HTML（黨團協商紀錄：ppg 會議頁的附件或公報分冊 PDF）重排成 .doc 轉出的樣子，
     * 讓 GazetteTranscriptParser::parse 吃得下。PDF 版是 <div class="page"> 包著一行一個 <p>，
     * 有「協 1」「黨團協商紀錄」這類頁首頁尾，數字前後還會多空白（113年 2 月 5日）。
     * 作法：去頁首頁尾、去中文與數字之間的空白、把實體行接回段落
     * （「某某：」開頭的行另起一段；上一行比整頁常見寬度短表示段落結束）。
     */
    public static function reflowPdfHtml($html)
    {
        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $lines = [];
        foreach ($doc->getElementsByTagName('p') as $p) {
            $line = trim(preg_replace('#[\x{0}\x{a0}\x{3000}]+#u', ' ', $p->textContent));
            // 羅馬拼音字之間的空白保留，其餘（中文、數字、標點之間）去掉
            $line = trim(preg_replace('#(?<![A-Za-z])[ \t]+|[ \t]+(?![A-Za-z])#u', '', $line));
            if ($line === '') continue;
            // 頁首頁尾：「協1」「黨團協商紀錄」「立法院公報 第115卷 第78期 黨團協商紀錄」、純頁碼
            if (preg_match('#^(協|委|院)?\d{1,4}$#u', $line)) continue;
            if (preg_match('#^(立法院公報.*)?黨團協商紀錄$#u', $line)) continue;
            $lines[] = $line;
        }
        if (!$lines) return '<html><body></body></html>';
        // 常見行寬：取各行長度的眾數附近（最長的幾行），短於它 2 個字以上的行視為段落結尾
        $lens = array_map('mb_strlen', $lines);
        rsort($lens);
        $width = $lens[min(intdiv(count($lens), 10), count($lens) - 1)];
        $paras = [];
        $cur = '';
        $prev_short = true;
        foreach ($lines as $line) {
            $starts_new = $prev_short
                or preg_match('#^(主席|[^：，、。；]{2,20})：#u', $line)
                or preg_match('#^(時間|地點|主席|出席|列席|協商主題|決議|決定|協商結論|散會)#u', $line);
            if ($starts_new and $cur !== '') {
                $paras[] = $cur;
                $cur = '';
            }
            $cur .= $line;
            $prev_short = mb_strlen($line) < $width - 2;
        }
        if ($cur !== '') $paras[] = $cur;
        // 第一個「主席：」之前是標頭（時間、地點、主席、協商主題），「協商主題：…」的冒號不能被當成發言人
        foreach ($paras as $i => $para) {
            if (preg_match('#^主席：#u', $para)) break;
            $paras[$i] = str_replace('：', '︰', $para);
        }
        $out = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>';
        foreach ($paras as $para) {
            $out .= '<p>' . htmlspecialchars($para, ENT_QUOTES, 'UTF-8') . "</p>\n";
        }
        return $out . '</body></html>';
    }

    /**
     * @param string $html   tikahtml 內容
     * @param array  $meta   [
     *   'meet_id' => '委員會-11-5-20-18', 'meet_type' => '委員會', 'term' => 11, 'sessionPeriod' => 5,
     *   'committees' => [20], 'date' => '2026-06-10'（沒給就用公報文件的時間行）,
     *   'clips' => [['id' => 169894, 'name' => '林德福', 'start' => '2026-06-10T09:21:33+08:00', 'end' => '...'], ...],
     *   'fulls' => [['id' => 17504, 'start' => '...', 'end' => '...'], ...],
     * ]
     * @return array [docs(list of array), info(array)]
     */
    public static function build($html, $meta)
    {
        // 不解析表決（parseVote 遇到人名對不上會丟例外，表決另有 gazette_vote index）
        $ret = GazetteTranscriptParser::parse($html, null, null, false);
        $date = $meta['date'] ?? ($ret->date ?? null);
        $chair_raw = $ret->{'主席'} ?? null;
        $chair_name = null;
        if ($chair_raw) {
            // 「吳委員秉叡」「吳委員秉叡（召集委員）」
            $chair_raw = preg_replace('#[（(].*$#u', '', $chair_raw);
            [$chair_name] = self::normalizeSpeaker($chair_raw, null, $meta['term'] ?? null);
        }
        $persons = (array)($ret->person_count ?? []);

        $clips = $meta['clips'] ?? [];
        $fulls = $meta['fulls'] ?? [];
        $used_clips = [];
        $find_full = function($time) use ($fulls) {
            if (!$time) return null;
            $t = strtotime($time);
            foreach ($fulls as $full) {
                $s = strtotime($full['start']); $e = strtotime($full['end']);
                // 來源偶有壞掉的開始時間（ivod 13762 年份是 0021、一批舊影片是 1970），這種 Full 不採用；
                // 跨日的 Full（例如 2020-07 臨時會通宵審國民法官法的 12999）是真的，不設長度上限
                if ($s < 946684800 or $e <= $s) continue;
                if ($t >= $s and $t <= $e) {
                    return ['ivod_id' => intval($full['id']), 'offset' => $t - $s];
                }
            }
            return null;
        };
        $find_clip = function($name, $anchor) use ($clips, &$used_clips, $date) {
            $best = null; $best_diff = null;
            $name_key = self::nameKey($name);
            foreach ($clips as $clip) {
                if (self::nameKey($clip['name']) !== $name_key) continue;
                if ($date and substr($clip['start'], 0, 10) !== $date) continue;
                if (isset($used_clips[$clip['id']])) continue;
                $diff = $anchor ? abs(strtotime($clip['start']) - strtotime($anchor)) : 0;
                if (is_null($best) or $diff < $best_diff) { $best = $clip; $best_diff = $diff; }
            }
            // 時鐘錨點和 Clip 開始差超過 30 分鐘就不認（同一位委員一天可能質詢兩輪）
            if ($best and $anchor and $best_diff > 1800) return null;
            if ($best) $used_clips[$best['id']] = true;
            return $best;
        };
        $clock_to_time = function($h, $m) use ($date) {
            if (!$date) return null;
            return sprintf('%sT%02d:%02d:00+08:00', $date, intval($h), intval($m));
        };

        $docs = [];
        $seq = 0;
        $section = null;
        $in_minutes = false;
        $turn_seq = 0; $turn_speaker = null; $turn_clock = null; $turn_first = false; $turn_clip = null;
        $anchor = null;           // 最近一個確定時間
        $anchor_source = null;
        $counts = ['gazette-clock' => 0, 'clip' => 0, 'interpolated' => 0, 'none' => 0];
        $turns_with_clip = 0;

        foreach ($ret->blocks as $block_idx => $lines) {
            $lines = array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));
            if (!count($lines)) continue;
            $first = $lines[0];

            // 段落標記（parser 合成的，不是公報文字）：更新狀態，不產生 doc
            if (strpos($first, '段落：') === 0 and count($lines) == 1) {
                $label = mb_substr($first, 3);
                if (preg_match('#^質詢：(.+)：(\d+):(\d+)$#u', $label, $m)) {
                    $turn_seq ++;
                    [$turn_speaker] = self::normalizeSpeaker($m[1], $chair_name, $meta['term'] ?? null);
                    $turn_clock = $clock_to_time($m[2], $m[3]);
                    $turn_first = true;
                    $turn_clip = $find_clip($turn_speaker, $turn_clock);
                    if ($turn_clip) $turns_with_clip ++;
                    if ($turn_clock) { $anchor = $turn_clock; $anchor_source = 'gazette-clock'; }
                    elseif ($turn_clip) { $anchor = $turn_clip['start']; $anchor_source = 'clip'; }
                    $in_minutes = false;
                } elseif ($label === '質詢結束') {
                    $turn_speaker = null; $turn_clip = null;
                } elseif (strpos($label, '議事錄：') === 0) {
                    $in_minutes = true;
                    $section = mb_substr($label, 4);
                } else {
                    $section = $label;
                }
                continue;
            }

            $kind = null; $speaker_raw = null; $speaker_name = null; $speaker_role = null; $speaker_title = null;
            $time = null; $time_source = null; $clip_id = null;
            $text_lines = $lines;

            if ($block_idx == 0 and !count($docs)) {
                $kind = '標題';
            } elseif (strpos($first, '段落：議事錄：') === 0) {
                $kind = '議事錄';
                $in_minutes = true;
                $section = mb_substr($first, 7);
                array_shift($text_lines);
            } elseif (preg_match('#^([^：]{1,25})：(.*)$#us', $first, $m) and array_key_exists($m[1], $persons)) {
                $speaker_raw = $m[1];
                [$speaker_name, $speaker_role, $speaker_title] = self::normalizeSpeaker($speaker_raw, $chair_name, $meta['term'] ?? null);
                $body = $m[2];
                // 公報時鐘戳記：「林委員德福：（9時21分）謝謝主席」
                if (preg_match('#^[（(](\d+)時(\d+)分[）)]#u', $body, $t)) {
                    $time = $clock_to_time($t[1], $t[2]);
                    $time_source = 'gazette-clock';
                    $body = preg_replace('#^[（(]\d+時\d+分[）)]#u', '', $body);
                } elseif (preg_match('#^[（(](\d+)時[）)]#u', $body, $t)) {
                    $time = $clock_to_time($t[1], 0);
                    $time_source = 'gazette-clock';
                    $body = preg_replace('#^[（(]\d+時[）)]#u', '', $body);
                }
                $body = str_replace('（發言結束）', '', $body);
                $text_lines[0] = trim($body);
                $in_minutes = false;
                if ($speaker_title and strpos($speaker_title, '書面') === 0) {
                    $kind = '附件';
                } elseif ($speaker_role === '主席' and mb_strlen(implode('', $text_lines)) <= 60) {
                    $kind = '主席程序';
                } else {
                    $kind = '發言';
                }
                // 這一輪質詢的第一個 block：沒有公報戳記就用 Clip 起點
                if ($turn_first and $speaker_name === $turn_speaker) {
                    $turn_first = false;
                    if (!$time and $turn_clip) { $time = $turn_clip['start']; $time_source = 'clip'; }
                }
                if ($turn_clip and $turn_speaker) {
                    $clip_id = intval($turn_clip['id']);
                }
            } else {
                $prev = count($docs) ? $docs[count($docs) - 1] : null;
                if ($in_minutes) {
                    $kind = '議事錄';
                } elseif (preg_match('#表決結果|記名表決|贊成者|反對者|棄權者|表決時間|表決議題#u', implode("\n", $lines))) {
                    $kind = '宣讀表決';
                } elseif ($prev and $prev['speaker_raw'] and in_array($prev['kind'], ['發言', '附件'])) {
                    // 同一人發言被報告內的小標題切開（例：央行總裁報告的「四、金融業務檢查」），接續前一個 block 的發言者
                    $kind = $prev['kind'];
                    $speaker_raw = $prev['speaker_raw']; $speaker_name = $prev['speaker_name'];
                    $speaker_role = $prev['speaker_role']; $speaker_title = $prev['speaker_title'];
                    $clip_id = $prev['clip_id'];
                } else {
                    $kind = '附件';
                }
            }

            // 休息／繼續開會／散會的時鐘也是錨點
            foreach ($lines as $l) {
                if (preg_match('#^(休息|繼續開會|散會|現在開會|開會)[（(](\d+)時(\d+)分[）)]#u', $l, $t)) {
                    $clock = $clock_to_time($t[2], $t[3]);
                    if ($clock) { $anchor = $clock; $anchor_source = 'gazette-clock'; }
                    if (!$time and $kind !== '附件' and $kind !== '議事錄') { $time = $clock; $time_source = 'gazette-clock'; }
                }
            }
            if ($time) {
                $anchor = $time; $anchor_source = $time_source;
            } elseif ($anchor and in_array($kind, ['發言', '主席程序', '宣讀表決'])) {
                $time = $anchor; $time_source = 'interpolated';
            }
            $counts[$time_source ?? 'none'] ++;

            $text = implode("\n", $text_lines);
            $doc = [
                'id' => sprintf('%s-%04d', $meta['lcidc_id'], $seq),
                'lcidc_id' => $meta['lcidc_id'],
                'gazette_id' => $meta['gazette_id'] ?? null,
                'agenda_id' => $meta['agenda_id'] ?? null,
                'meet_id' => $meta['meet_id'] ?? null,
                'meet_type' => $meta['meet_type'] ?? null,
                'term' => $meta['term'] ?? null,
                'sessionPeriod' => $meta['sessionPeriod'] ?? null,
                'committees' => $meta['committees'] ?? [],
                'date' => $date,
                'seq' => $seq,
                'block_line' => $ret->block_lines[$block_idx] ?? null,
                'kind' => $kind,
                'section' => $section,
                'speaker_raw' => $speaker_raw,
                'speaker_name' => $speaker_name,
                'speaker_role' => $speaker_role,
                'speaker_title' => $speaker_title,
                'turn_seq' => $turn_speaker ? $turn_seq : null,
                'turn_speaker' => $turn_speaker,
                'text' => $text,
                'chars' => mb_strlen(preg_replace('#\s#u', '', $text)),
                'time_start' => $time,
                'time_source' => $time_source,
                'clip_id' => $clip_id,
                'video' => $find_full($time),
                'page_start' => $meta['page_start'] ?? null,
                'page_end' => $meta['page_end'] ?? null,
                'source_url' => $meta['source_url'] ?? null,
            ];
            $docs[] = $doc;
            $seq ++;
        }

        $info = [
            'title' => $ret->title ?? null,
            'date' => $date,
            'chair' => $chair_name,
            'blocks' => count($docs),
            'turns' => $turn_seq,
            'turns_with_clip' => $turns_with_clip,
            'time' => $counts,
            'kinds' => array_count_values(array_column($docs, 'kind')),
        ];
        return [$docs, $info];
    }
}
