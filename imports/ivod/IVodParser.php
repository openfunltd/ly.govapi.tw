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
        $ivod->url = sprintf("https://ivod.ly.gov.tw/Play/%s/1M/%d", $type, $v);
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
            if (preg_match('#var _movie = JSON.parse\(\'([^\']+)\'\)#', $content, $matches)) {
                if (!$json = json_decode($matches[1])) {
                    throw new Exception("JSON parse error: {$url}");
                }
            }
            $start_time = strtotime($json->rsttim);
            $end_time = strtotime($json->rettim);
            $ivod->start_time = date('c', $start_time);
            $ivod->end_time = date('c', $end_time);
            $ivod->duration = $end_time - $start_time;
            $ivod->{'影片長度'} = sprintf("%02d:%02d:%02d", $ivod->duration / 3600, ($ivod->duration % 3600) / 60, $ivod->duration % 60);
            $ivod->{'委員發言時間'} = date('H:i:s', $start_time) . ' - ' . date('H:i:s', $end_time);
        }

        return $ivod;
    }
}
