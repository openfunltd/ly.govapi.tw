<?php

class IVodParser
{
    public static function parseHTML($v, $content)
    {
        if (!preg_match('#readyPlayer\("([^"]*)"#', $content, $matches)) {
            throw new Exception("readyPlayer not found {$url}");
        }
        $ivod = new StdClass;
        $ivod->id = intval($v);
        $ivod->url = sprintf("https://ivod.ly.gov.tw/Play/Clip/1M/%d", $v);
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
        $ivod->date = date('Y-m-d', strtotime($ivod->{'會議時間'}));
        if (preg_match('#(\d+):(\d+):(\d+)#', $ivod->{'影片長度'}, $matches)) {
            $ivod->duration = $matches[1] * 3600 + $matches[2] * 60 + $matches[3];
        } else {
            throw new Exception("影片長度 not found: $url");
        }
        return $ivod;
    }
}
