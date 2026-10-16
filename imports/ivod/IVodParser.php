<?php

class IVodParser
{
    public static function parseHTML($v, $content, $type = 'Clip')
    {
        if (!preg_match('#readyPlayer\("([^"]*)"#', $content, $matches)) {
            throw new Exception("readyPlayer not found {$url}");
        }
        $ivod = new StdClass;
        $ivod->id = intval($v);
        $ivod->url = $url = sprintf("https://ivod.ly.gov.tw/Play/%s/1M/%d", $type, $v);
        $ivod->video_url = $matches[1];
        if (!preg_match('#<strong>會議時間：</strong>([0-9-: ]+)#', $content, $matches)) {
            throw new Exception("會議時間 not found: $url");
        }
        $doc = new DOMDocument;
        @$doc->loadHTML($content);
        // 處理所有 strong tag
        foreach ($doc->getElementsByTagName('strong') as $strong_dom) {
            if (strpos($strong_dom->textContent, '：') === false) {
                continue;
            }
            $key = trim(str_replace('：', '', $strong_dom->textContent));
            $value = '';
            $dom = $strong_dom->nextSibling;
            while ($dom) {
                if ($dom->nodeType === XML_TEXT_NODE) {
                    $value .= $dom->textContent;
                }
                $dom = $dom->nextSibling;
            }
            $ivod->{$key} = trim($value);
        }
        $ivod->{'會議時間'} = date('c', strtotime($ivod->{'會議時間'}));
        $ivod->type = $type;
        $ivod->date = date('Y-m-d', strtotime($ivod->{'會議時間'}));
        if ('Clip' == $type) {
            if (!preg_match('#(\d+:\d+:\d+) - (\d+:\d+:\d+)#', $ivod->委員發言時間, $matches)) {
                throw new Exception("委員發言時間 not found: {$ivod->id} {$ivod->{'委員發言時間'}}");
            }
            $ivod->start_time = date('c', strtotime($ivod->date . ' ' . $matches[1]));
            $ivod->end_time = date('c', strtotime($ivod->date . ' ' . $matches[2]));
            if (preg_match('#(\d+):(\d+):(\d+)#', $ivod->{'影片長度'}, $matches)) {
                $ivod->duration = $matches[1] * 3600 + $matches[2] * 60 + $matches[3];
            } else {
                throw new Exception("影片長度 not found: $url");
            }
        } else {
            $ivod->{'委員名稱'} = '完整會議';

            // [rsttim] => 2024-06-04 12:29:59
            // [rettim] => 2024-06-04 14:47:00
            $content = str_replace('"metdec":""u', '"metdec":"u', $content);
            if (preg_match('#var _movie = JSON.parse\(\'([^\']+)\'\)#', $content, $matches)) {
                if (!$json = json_decode($matches[1])) {
                    throw new Exception("JSON parse error: {$url}");
                }
            }
            $start_time = strtotime($json->rsttim);
            $end_time = strtotime($json->rettim ?? $json->rsttim);
            $ivod->start_time = date('c', $start_time);
            $ivod->end_time = date('c', $end_time);
            $ivod->duration = $end_time - $start_time;
            $ivod->{'影片長度'} = sprintf("%02d:%02d:%02d", $ivod->duration / 3600, ($ivod->duration % 3600) / 60, $ivod->duration % 60);
            $ivod->{'委員發言時間'} = date('H:i:s', $start_time) . ' - ' . date('H:i:s', $end_time);
        }

        return $ivod;
    }

    public static function checkMeetFromIVOD($ivod, &$error_name)
    {
        if (!preg_match('#^[^「（]*#u', $ivod->{'會議名稱'}, $matches)) {
            error_log("會議名稱 not found: " . $ivod->{'會議名稱'});
            readline('continue?');
            return false;
        }
        $name = $matches[0];
        if (strpos($name, '法院') === 0) {
            $name = '立' . $name;
        } elseif ($name == '立法院第10屆第7會期外交及國防委員會第14全體委員會議') {
            $name = '立法院第10屆第7會期外交及國防委員會第14次全體委員會議';
        }
        if (strpos($name, '黨團協商')) {
            try {
                $meet_obj = LYLib::consultToId('ivod', $ivod, '黨團協商');
                $ivod->meet = $meet_obj;
            } catch (Exception $e) {
            }
        } elseif (strpos($ivod->{'會議名稱'}, '公聽會')) {
            try {
                $meet_obj = LYLib::consultToId('ivod', $ivod, '公聽會');
                $ivod->meet = $meet_obj;
            } catch (Exception $e) {
            }
        } else {
            try {
                //print_r($ivod);
                $name = str_replace('(變更議程)', '', $name);
                $meet_obj = LYLib::meetNameToId($name);
                $ivod->meet = $meet_obj;
                //print_r($meet_obj);
            } catch (Exception $e) {
                error_log(json_encode($ivod, JSON_UNESCAPED_UNICODE));
                error_log($e->getMessage());
                $error_name[$name] ++;
            }
        }
        return $ivod;
    }

    /**
     * ivod-transcript 的原始 pyannote diarization（$transcript_segs）跟
     * ivod-pyannote/{id}.json 的 embedding speakers（$pyann_speakers）
     * 可能來自兩次獨立執行的 pyannote（例如 backfill-pyannote-run.php
     * 為舊影片重新跑了一次），SPEAKER_XX 編號彼此不保證對應同一個人，
     * 必須用時間重疊比對找出真正的對應關係，不能直接假設編號相同。
     *
     * @param array $transcript_segs  [[start, end, label], ...]（來自 ivod-transcript 的 pyannote.result.result）
     * @param array $pyann_speakers   [stdClass{id, segments:[[s,e],...], identity?}, ...]（來自 ivod-pyannote/{id}.json）
     * @return array  [transcript_label => identity(stdClass)]，只回傳比對上且有 identity 的
     */
    public static function alignPyannoteIdentities($transcript_segs, $pyann_speakers)
    {
        if (empty($transcript_segs) || empty($pyann_speakers)) {
            return [];
        }

        // 1. 依 transcript 的 label 分組
        $t_by_label = [];
        foreach ($transcript_segs as $seg) {
            $t_by_label[$seg[2]][] = [$seg[0], $seg[1]];
        }
        $t_total = [];
        foreach ($t_by_label as $label => $segs) {
            $t_total[$label] = array_sum(array_map(fn($s) => $s[1] - $s[0], $segs));
        }

        // 2. overlap[embed_id][transcript_label] = 重疊秒數
        $overlap = [];
        $embed_total = [];
        foreach ($pyann_speakers as $spk) {
            $eid = $spk->id;
            $embed_total[$eid] = 0;
            foreach ($spk->segments ?? [] as $eseg) {
                [$e0, $e1] = $eseg;
                $embed_total[$eid] += $e1 - $e0;
                foreach ($t_by_label as $label => $segs) {
                    foreach ($segs as $tseg) {
                        $ov = min($e1, $tseg[1]) - max($e0, $tseg[0]);
                        if ($ov > 0) {
                            $overlap[$eid][$label] = ($overlap[$eid][$label] ?? 0) + $ov;
                        }
                    }
                }
            }
        }

        // 3. 貪婪二分匹配：重疊秒數最多的先配對，雙方只能各用一次
        $pairs = [];
        foreach ($overlap as $eid => $byLabel) {
            foreach ($byLabel as $label => $ov) {
                $pairs[] = [$ov, $eid, $label];
            }
        }
        usort($pairs, fn($a, $b) => $b[0] <=> $a[0]);

        $used_e = []; $used_t = [];
        $map = []; // transcript_label => embed_id
        foreach ($pairs as $p) {
            [$ov, $eid, $label] = $p;
            if (isset($used_e[$eid]) || isset($used_t[$label])) continue;
            // 重疊時間需佔雙方較短那邊一定比例才採信，避免雜訊配對
            $ratio = $ov / max(1, min($embed_total[$eid], $t_total[$label]));
            if ($ratio < 0.3) continue;
            $map[$label] = $eid;
            $used_e[$eid] = true;
            $used_t[$label] = true;
        }

        // 4. 轉成 transcript_label => identity
        $identities_by_eid = [];
        foreach ($pyann_speakers as $spk) {
            if (!empty($spk->identity)) {
                $identities_by_eid[$spk->id] = $spk->identity;
            }
        }

        $result = [];
        foreach ($map as $label => $eid) {
            if (isset($identities_by_eid[$eid])) {
                $result[$label] = $identities_by_eid[$eid];
            }
        }
        return $result;
    }

    /**
     * 找「請…宣讀」到「…完畢」之間的時間區間——院會議事人員（宣讀人員）
     * 唸黨團提案/議案內容的區段。不辨識是哪一位（他們不只一人、公報也
     * 不會寫名字），只要偵測到這個內容模式，
     * 不管 pyannote 認得的是誰，都直接標成「宣讀人員」這個角色。
     * whisper 常把「議事人員」聽成「醫事員」，所以觸發詞只看「宣讀」本身。
     *
     * @param array $whisperx_segs  [stdClass{start,end,text}, ...]
     * @return array  [[start, end], ...]
     */
    public static function findReadingWindows($whisperx_segs)
    {
        $windows = [];
        $pending_start = null;
        foreach ($whisperx_segs as $seg) {
            $text = $seg->text ?? '';
            if (strpos($text, '宣讀') !== false && strpos($text, '完畢') === false) {
                $pending_start = $seg->end;  // 觸發語句講完後，讀稿才真正開始
            } elseif (strpos($text, '完畢') !== false && $pending_start !== null) {
                if ($seg->start - $pending_start < 180) {  // 180 秒內才視為同一次
                    $windows[] = [$pending_start, $seg->start];
                }
                $pending_start = null;
            }
        }
        return $windows;
    }

    /** 這個時間點落在哪個宣讀區間裡嗎（見 findReadingWindows 說明） */
    public static function isReadingTime($windows, $start, $end)
    {
        $mid = ($start + $end) / 2;
        foreach ($windows as [$w0, $w1]) {
            if ($mid >= $w0 && $mid <= $w1) return true;
        }
        return false;
    }

    /**
     * 同一支影片裡，同一個 SPEAKER_XX（pyannote diarization 編號）本來就代表
     * 同一個人，所以只要這個編號曾經有任一段落落在宣讀視窗內，這整支影片裡
     * 這個編號的所有段落都視為宣讀人員——不需要每一段都重新判斷是否落在
     * 視窗內（公報列舉多案時，只有第一案前有「請宣讀」觸發詞，後面每案都
     * 直接唸案由接「完畢」，findReadingWindows 會抓不到後續案件的視窗，
     * 但其實都是同一位議事人員在唸，用這個方法回頭補齊）。
     *
     * @param array $transcript_segs  [[start, end, label], ...]
     * @param array $windows          findReadingWindows() 回傳的區間
     * @return array  [label => true, ...]
     */
    public static function findReadingSpeakerLabels($transcript_segs, $windows)
    {
        if (!$windows) return [];
        $labels = [];
        foreach ($transcript_segs as $seg) {
            if (self::isReadingTime($windows, $seg[0], $seg[1])) {
                $labels[$seg[2]] = true;
            }
        }
        return $labels;
    }

    protected static $_chairIndex = null;

    /**
     * 查某場會議（meet_id + date）公報標頭記載的主席是誰。
     * 索引由 imports/gazette/build-chair-index.php 預先建好（chair-index.json），
     * 這裡只做 O(1) 查表，不即時重掃公報。
     *
     * 同一天可能不只一位主席（院長主持一段、交給副院長代理一段再換回來），
     * 標頭抓不到確切的換手時間，所以回傳的是「當天出現過的主席名單」，
     * 不是單一一位——is_chair 判斷只能做到「有沒有在當天名單裡」的粒度。
     *
     * @return array  [['label'=>..., 'person_name'=>...], ...]，查無則回傳 []
     */
    public static function getSessionChair($meet_id, $date)
    {
        if (self::$_chairIndex === null) {
            $path = __DIR__ . '/../gazette/chair-index.json';
            self::$_chairIndex = file_exists($path)
                ? json_decode(file_get_contents($path), true)
                : [];
        }
        $date = substr($date, 0, 10);
        return self::$_chairIndex["{$meet_id}:{$date}"] ?? [];
    }

    /** 這個人名是不是今天的主席之一（見 getSessionChair 的說明） */
    public static function isSessionChair($meet_id, $date, $person_name)
    {
        foreach (self::getSessionChair($meet_id, $date) as $chair) {
            if ($chair['person_name'] === $person_name) return true;
        }
        return false;
    }
}
