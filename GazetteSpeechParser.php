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
    const VERSION = 1;

    // 官員／其他身分的職稱，放在姓和名之間（例：林部長佳龍、曾主任委員智勇）
    const TITLE_PATTERN = '副?(?:主任委員|主任秘書|主任檢察官|委員長|監察委員|大法官|部長|次長|署長|局長|司長|處長|院長|秘書長|祕書長|總裁|董事長|總經理|主計長|執行長|廳長|校長|館長|所長|組長|科長|主任|代表|理事長|市長|縣長|檢察長|檢察官|法官|參謀總長|總長|司令|廠長|課長|專門委員|參事|顧問|研究員|召集人|幹事長|書記長|總召|總幹事|發言人|會長|教授|律師|醫師|秘書|祕書|先生|女士|小姐)';
    const NON_OFFICIAL_TITLES = ['教授', '律師', '醫師', '先生', '女士', '小姐', '會長', '理事長', '代表', '召集人', '研究員'];

    /**
     * 發言者原文 → [name, role, title]
     * role: 主席／委員／官員／其他
     */
    public static function normalizeSpeaker($raw, $chair_name = null)
    {
        $raw = trim(str_replace(['　', ' '], '', $raw));
        if ($raw === '主席') {
            return [$chair_name ?: '主席', '主席', '主席'];
        }
        if (preg_match('#^主席[（(](.+?)代?[）)]$#u', $raw, $m)) {
            // 主席（賴委員惠員代）／主席（吳委員秉叡）
            [$name] = self::normalizeSpeaker($m[1]);
            return [$name, '主席', '主席'];
        }
        if (preg_match('#^委員(.+?)書面(質詢|意見)$#u', $raw, $m)) {
            return [$m[1], '委員', '書面' . $m[2]];
        }
        if (preg_match('#^(.{1,2})委員(.+)$#u', $raw, $m)) {
            return [$m[1] . $m[2], '委員', '委員'];
        }
        if (preg_match('#^(.{1,3}?)(' . self::TITLE_PATTERN . ')(.{1,8})$#u', $raw, $m)) {
            $title = $m[2];
            $base = preg_replace('#^副#u', '', $title);
            $role = in_array($base, self::NON_OFFICIAL_TITLES) ? '其他' : '官員';
            return [$m[1] . $m[3], $role, $title];
        }
        return [$raw, '其他', null];
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
            [$chair_name] = self::normalizeSpeaker($chair_raw);
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
            foreach ($clips as $clip) {
                if ($clip['name'] !== $name) continue;
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
                    [$turn_speaker] = self::normalizeSpeaker($m[1], $chair_name);
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
                [$speaker_name, $speaker_role, $speaker_title] = self::normalizeSpeaker($speaker_raw, $chair_name);
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
