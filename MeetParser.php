<?php

class MeetParser
{
    public static function parseMeetPage($content, $dir, $meetingNo, $url)
    {
        $doc = new DOMDocument;
        $content = str_replace('立', '立', $content);
        @$doc->loadHTML($content);
        $ret = new STdClass;
        $ret->title = trim($doc->getElementsByTagName('title')->item(0)->textContent);
        $ret->features = [];
        $ret->dates = [];
        $checking_features = [];

        // get div.Page-Strip-Scol
        $xpath = new DOMXPath($doc);
        $div = $xpath->query('//div[contains(@class, "Page-Strip-Scol")]')->item(0);
        foreach ($div->getElementsByTagName('a') as $a_dom) {
            $ret->features[] = $a_dom->textContent;
            $checking_features[$a_dom->textContent] = ltrim($a_dom->getAttribute('href'), '#');
        }

        // get div.Detail-MainCard
        $xpath = new DOMXPath($doc);
        $div = $xpath->query('//div[contains(@class, "Detail-MainCard")]')->item(0);
        // get div.row in $div
        $rows = $xpath->query('.//div[contains(@class, "row")]', $div);
        $spans = [];
        foreach ($rows->item(0)->getElementsByTagName('span') as $span_dom) {
            $spans[] = $span_dom;
        }
        $title = trim(array_shift($spans)->textContent);
        if ($title != $ret->title) {
            throw new Exception('title not match: ' . $title . ' vs ' . $ret->title);
        }
        while ($span = array_shift($spans)) {
            $text = $span->textContent;
            if (preg_match('#^\d+年\d+月\d+日#u', $text)) {
                $ret->dates[] = $text;
                continue;
            }
            // if has i.fa-map-pin, save to place
            if ($span->getElementsByTagName('i')->length and strpos($span->getElementsByTagName('i')->item(0)->getAttribute('class'), 'fa-map-pin') !== false) {
                $ret->place = trim($text);
                continue;
            }

            if (preg_match('#【 召集人：(.*) 】#u', $text, $matches)) {
                $ret->{'召集人'} = $matches[1];
                continue;
            }

            throw new Exception('unknown span: ' . $text);
        }

        // find span.card-title
        $ret->content = '';
        foreach ($div->getElementsByTagName('span') as $span_dom) {
            if (strpos($span_dom->getAttribute('class'), 'card-title') !== false) {
                $ret->content = $span_dom->textContent;
                break;
            }
        }

        // find div.card-footer 
        foreach ($div->getElementsByTagName('div') as $div_dom) {
            if (strpos($div_dom->getAttribute('class'), 'card-footer') !== false) {
                foreach ($div_dom->getElementsByTagName('a') as $a_dom) {
                    // check href
                    $href = $a_dom->getAttribute('href');
                    if ($href == 'https://ppg.ly.gov.tw/ppg/SittingCommitteesInfo/download/') {
                        // FIXME: 沒有連結?
                        if (strpos($a_dom->getAttribute('title'), '公報紀錄') === 0) {
                            continue;
                        }
                    }
                    if (strpos($href, 'http') === 0) {
                    } elseif (preg_match("#^javascript:if\(confirm\('批次下載需等待較長時間,是否繼續下載\?'\)\)location='([^']+)'#u", $href, $matches)) {
                        $href = "https://ppg.ly.gov.tw" . $matches[1];
                    } elseif (strpos($a_dom->getAttribute('v-on:click'), 'handleClick') === 0) {
                        $proceedings_target = $dir . "/ppg_meet_page/{$meetingNo}-proceedings.json";
                        if (!file_exists($proceedings_target) or filesize($proceedings_target) < 100) {
                            $proceed_url = "https://ppg.ly.gov.tw/ppg/api/v1/getProceedingsList?meetingNo=" . urlencode($meetingNo);
                            error_log("fetch proceedings $proceed_url");
                            $curl = curl_init($proceed_url);
                            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                            // ipv4
                            curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                            $json = curl_exec($curl);
                            if (curl_errno($curl)) {
                                throw new Exception(curl_error($curl));
                            }
                            file_put_contents($proceedings_target, $json);
                        }
                        $ret->proceedings = json_decode(file_get_contents($proceedings_target));
                        continue;
                    } else {
                        throw new Exception('href not match: ' . $href);
                    }

                    // check title
                    $title = trim($a_dom->textContent);
                    $a_title = $a_dom->getAttribute('title');
                    if (strpos($a_title, $title) !== false) {
                    } elseif ($title == '本次會議議事錄' and strpos($a_title, '議事錄') !== false) {
                        $title = '議事錄';
                    } else {
                        throw new Exception('title not match: ' . $title . ' vs ' . $a_title);
                    }
                    $a_title = substr($a_title, strlen($title));
                    $a_title = str_replace('(另開視窗)', '', $a_title);

                    $filetype = null;
                    if (preg_match('#^([a-z]+)下載$#u', strtolower($a_title), $matches)) {
                        $filetype = $matches[1];
                        $a_title = '';
                    }

                    if ($a_title) {
                        throw new Exception('title not match: ' . $title . ' vs ' . $a_title);
                    }

                    $ret->links[] = [
                        'title' => trim($a_dom->textContent),
                        'href' => $href,
                        'type' => str_replace('fas fa-', '', $a_dom->getElementsByTagName('i')->item(0)->getAttribute('class')),
                        'filetype' => $filetype,
                    ];
                }
                break;
            }
        }

        unset($checking_features['會議']);

        if (array_key_exists('附件', $checking_features)) {
            $dom = $doc->getElementById($checking_features['附件']);
            $ret->attachments = [];
            unset($checking_features['附件']);

            foreach ($dom->getElementsByTagName('a') as $a_dom) {
                // get parent li
                $li_dom = $a_dom->parentNode;
                while ($li_dom and $li_dom->tagName != 'li') {
                    $li_dom = $li_dom->parentNode;
                }
                if (!$li_dom) {
                    throw new Exception('li not found');
                }
                $group = trim($li_dom->getElementsByTagName('span')->item(0)->textContent);
                $a_title = trim($a_dom->getAttribute('title'));
                $filetype = '';
                $title = trim($a_dom->textContent);

                if (strpos($a_title, $title) === 0) {
                    $a_title = substr($a_title, strlen($title));
                }
                if (preg_match('#^[a-z]+$#', strtolower(trim($a_title)))) {
                    $filetype = strtolower(trim($a_title));
                } else if ($a_title == '') {
                } else {
                    throw new Exception('title not match: ' . $title . ' vs ' . $a_title);
                }
                $ret->attachments[] = [
                    'title' => $title,
                    'href' => $a_dom->getAttribute('href'),
                    'group' => $group,
                    'filetype' => $filetype,
                ];
            }
        }

        if (array_key_exists('關係文書', $checking_features)) {
            $dom = $doc->getElementById($checking_features['關係文書']);
            $ret->{'關係文書'} = new STdClass;
            unset($checking_features['關係文書']);
            // get div.Ur-SecBadges
            $div = $xpath->query('.//div[contains(@class, "Ur-SecBadges")]', $dom)->item(0);
            $types = [];
            foreach ($div->getElementsByTagName('button') as $button_dom) {
                $types[trim($button_dom->childNodes->item(0)->textContent)] = ltrim($button_dom->getAttribute('data-bs-target'), '#');
            }
            $ret->{'關係文書'}->bills = [];
            if (!count($types)) {
                $id = 'pills-b0';
                if (!$group_dom = $doc->getElementById($id)) {
                    throw new Exception('group not found: ' . $id);
                }
                foreach ($group_dom->getElementsByTagName('span') as $span_dom) {
                    if (strpos($span_dom->getAttribute('class'), 'card-title') === false) {
                        continue;
                    }
                    $text = trim($span_dom->textContent);
                    if (strpos($text, '一、宣讀') === 0) {
                        $ret->{'關係文書'}->bills[] = [
                            'title' => $text,
                        ];
                        continue;
                    }
                    $record = [
                        'title' => $text,
                    ];

                    if ($a_dom = $span_dom->getElementsByTagName('a')->item(0)) {
                    } else if (!preg_match('#/ppg/bills/(\d+)/details#u', $a_dom->getAttribute('href'), $matches)) {
                    } else {
                        $record['billNo'] = $matches[1];
                    }

                    foreach ($span_dom->parentNode->parentNode->parentNode->getElementsByTagName('span') as $comment_dom) {
                        if (false === strpos($comment_dom->getAttribute('class'), 'text-grey')) {
                            continue;
                        }
                        $record['comment'] = trim($comment_dom->textContent);
                        break;
                    }
                    $ret->{'關係文書'}->bills[] = $record;
                }
            }

            foreach (['同意權行使', '報告事項', '討論事項', '臨時提案'] as $type) {
                if (!array_key_exists($type, $types)) {
                    continue;
                }

                $group_dom = $doc->getElementById($types[$type]);
                foreach ($group_dom->getElementsByTagName('span') as $span_dom) {
                    if (strpos($span_dom->getAttribute('class'), 'card-title') === false) {
                        continue;
                    }
                    $text = trim($span_dom->textContent);
                    if (strpos($text, '一、宣讀') === 0) {
                        $ret->{'關係文書'}->bills[] = [
                            'title' => $text,
                            'type' => $type,
                        ];
                        continue;
                    }
                    $record = [
                        'title' => $text,
                        'type' => $type,
                    ];
                    if (!$a_dom = $span_dom->getElementsByTagName('a')->item(0)) {
                    } else if (!preg_match('#/ppg/bills/(\d+)/details#u', $a_dom->getAttribute('href'), $matches)) {
                    } else {
                        $record['billNo'] = $matches[1];
                    }

                    foreach ($span_dom->parentNode->parentNode->parentNode->getElementsByTagName('span') as $comment_dom) {
                        if (false === strpos($comment_dom->getAttribute('class'), 'text-grey')) {
                            continue;
                        }
                        $record['comment'] = trim($comment_dom->textContent);
                        break;
                    }
                    $ret->{'關係文書'}->bills[] = $record;
                }
                unset($types[$type]);
            }

            foreach (['質詢事項-行政院答復部分', '質詢事項-本院委員質詢部分'] as $type) {
                if (!array_key_exists($type, $types)) {
                    continue;
                }
                $ret->{'關係文書'}->{'質詢事項'} = [];

                $group_dom = $doc->getElementById($types[$type]);
                foreach ($group_dom->getElementsByTagName('span') as $span_dom) {
                    if (strpos($span_dom->getAttribute('class'), 'card-title') === false) {
                        continue;
                    }
                    $text = trim($span_dom->textContent);
                    $record = [
                        'title' => $text,
                        'type' => explode('-', $type)[1],
                    ];
                    foreach ($span_dom->parentNode->parentNode->parentNode->getElementsByTagName('a') as $a_dom) {
                        $record['href'] = $a_dom->getAttribute('href');
                        break;
                    }
                    $ret->{'關係文書'}->{'質詢事項'}[] = $record;
                }
                unset($types[$type]);
            }

            if (count($types)) {
                echo json_encode($ret, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                error_log($url);
                throw new Exception('TODO types: ' . json_encode($types, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

        }

        // TODO:
        unset($checking_features['委員發言片段']);
        unset($checking_features['出席委員']);
        unseT($checking_features['議案表決']);
        if (count($checking_features)) {
            echo json_encode($ret, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            error_log($url);
            throw new Exception('TODO features: ' . json_encode($checking_features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return $ret;
    }

    public static function checkData($meet_data, $page_data)
    {
        if (trim($page_data->title) != $meet_data->meetingName) {
            echo json_encode($page_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            echo json_encode($meet_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            var_dump($meet_data->meetingName);
            var_dump($page_data->title);
            readline('continue?');
        }
    }

    public static function addPageData($meet_data, $dir)
    {
        $meetingNos = [];
        foreach ($meet_data->meet_data as $meet) {
            $meetingNos[$meet->meetingNo] = $meet;;
        }
        foreach ($meetingNos as $meetingNo => $meet) {
            $date = $meet->date;
            $target = $dir . "/ppg_meet_page_json/{$meetingNo}-{$meet->date}.json";
            if (!file_exists($target)) {
                continue;
            }
            $ppg_data = json_decode(file_get_contents($target));
            $meet_data->ppg_data = $meet_data->ppg_data ?? [];
            $meet_data->ppg_data[] = $ppg_data;
        }
        return $meet_data;
    }
}
