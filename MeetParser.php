<?php

class MeetParser
{
    public static function getLawIdByBillNo($billNo)
    {
        $target = __DIR__ . "/imports/bill/bill-data/{$billNo}.json.gz";
        if (!file_exists($target)) {
            return [];
        }
        $obj = json_decode(gzdecode(file_get_contents($target)));
        return $obj->laws ?? [];
    }

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

                    if (!$a_dom = $span_dom->getElementsByTagName('a')->item(0)) {
                    } else if (!preg_match('#/ppg/bills/(\d+)/details#u', $a_dom->getAttribute('href'), $matches)) {
                    } else {
                        $record['billNo'] = $matches[1];
                        $record['laws'] = self::getLawIdByBillNo($record['billNo']);
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
                        $record['laws'] = self::getLawIdByBillNo($record['billNo']);
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
        unset($meet_data->ppg_data);
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

    public static function getLawNameFromString($str)
    {
        $str = preg_replace('#修正草案$#u', '', $str);
        if (preg_match('#(.*)部分條文$#u', $str, $matches)) {
            return $matches[1];
        }
        $str = preg_replace('#第(.*)條.*$#u', '', $str);
        // 遠洋漁業條例增訂第十四條之一及第三十八條之一條文草案
        if (preg_match('#(.*)增訂.*$#', $str, $matches)) {
            return $matches[1];
        }
        return $str;
    }

    public static function getAgendasFromMeetContent($content, $ppg_data, $meet)
    {
        $origin_content = $content;
        if (strpos($content, '一、') === 0) {
            $agendas = [];

            // 分隔 一、 二、 等項目
            $words = ['二', '三', '四', '五', '六', '七', '八', '九'];
            $pos_start = mb_strlen('一、');
            foreach ($words as $w) {
                $offset = 0;
                while (true) {
                    $pos_end = mb_strpos($content, $w . '、', $offset);
                    // 檢查一下，如果前面不是句號或是跳行，就再往下找
                    if (false == $pos_end) {
                        break;
                    }

                    $prev_char = mb_substr($content, $pos_end - 1, 1, 'UTF-8');
                    if (in_array($prev_char, [
                        '。', "\n", '】', ' ',
                    ])) {
                        break;
                    }
                    $offset = $pos_end + 1;
                }
                if (false === $pos_end) {
                    $agendas = array_merge(
                        $agendas,
                        self::getAgendasFromMeetContent(mb_substr($content, $pos_start), $ppg_data, $meet)
                    );
                    break;
                } else {
                    $agendas = array_merge(
                        $agendas,
                        self::getAgendasFromMeetContent(mb_substr($content, $pos_start, $pos_end - $pos_start), $ppg_data, $meet)
                    );
                    $pos_start = $pos_end + mb_strlen($w . '、');
                }
            }
            return $agendas;
        }

        $sub_titles = [];
        $content = preg_replace('#。$#u', '', $content);
        $content = str_replace('（', '(', $content);
        $content = str_replace('）', ')', $content);
        $content = str_replace('(詢答及處理)', '', $content);

        while (true) {
            // 處理結尾的各種備註
            if (preg_match('#^(.*)\([^\)]*\)$#us', trim($content), $matches)) {
                $content = trim($matches[1]);
                continue;
            }
            if (preg_match('#^(.*)【[^】]*】$#us', trim($content), $matches)) {
                $content = trim($matches[1]);
                continue;
            }
            break;
        }
        if (preg_match('#備註：.*$#', $content, $matches)) {
            $content = substr($content, 0, strpos($content, $matches[0]));
            $content = trim($content);
        }

        $content = str_replace('：(一)', '。(一)', $content);
        // 處理包含 "算凍結案計6案。(一)客家委員會 ... " 的情況
        if (preg_match('#。\(一\)#', $content, $matches)) {
            $sub_title_pos = strpos($content, '。(一)');
            $sub_content = substr($content, $sub_title_pos + strlen('。(一)'));
            $content = substr($content, 0, $sub_title_pos);
            $words = ['二', '三', '四', '五', '六', '七', '八', '九'];
            $pos_start = 0;
            foreach ($words as $w) {
                $pos_end = strpos($sub_content, "($w)");
                if (false === $pos_end) {
                    $sub_titles[] = substr($sub_content, $pos_start);
                    break;
                } else {
                    $sub_titles[] = substr($sub_content, $pos_start, $pos_end - $pos_start);
                    $pos_start = $pos_end + strlen("($w)");
                }
            }
        }
        $content = preg_replace('#\s*。$#u', '', trim($content));

        if ($meet->meet_type == '全院委員會') {
            if (preg_match('#^審查(.*)覆議案$#u', $content, $matches)) {
                $agenda = new StdClass;
                $agenda->type = '複議案';
                $agenda->title = '複議案:';
                $agenda->laws = [];
                $agenda->bills = [];
                $law_names = [];
                foreach ($ppg_data->關係文書->bills as $bill) {
                    $agenda->bills[] = [
                        'title' => $bill->title,
                        'billNo' => $bill->billNo ?? null,
                    ];
                    if ($bill->laws ?? false) {
                        $laws = $bill->laws;
                        preg_match_all('#「([^」]+)」#u', $bill->title, $matches);
                        $names = $matches[1];
                    } else {
                        $laws = BillParser::parseLaws($bill->title, $names);
                    }
                    $law_names = array_merge($law_names, $names);
                    foreach ($laws ?? [] as $law) {
                        $agenda->laws[] = $law;
                    }
                }
                $agenda->title = '複議案:' . implode('、', $law_names);
                $agenda->laws = array_values(array_unique($agenda->laws));
                $agendas[] = $agenda;
                return $agendas;
            }
        } elseif ('委員會' == $meet->meet_type) {
            if (preg_match('#^選舉第\d+屆第\d+會期召集委員$#u', $content)) {
                $agenda = new StdClass;
                $agenda->type = '選舉';
                $agenda->title = '選舉召集委員';
                $agendas[] = $agenda;
                return $agendas;
            }

            if (preg_match('#邀請(.*)率同(.*)，並備質詢$#u', $content, $matches)) {
                $agenda = new StdClass;
                $agenda->type = '質詢';
                $agenda->title = '質詢:' . $matches[1];
                $agendas[] = $agenda;
                return $agendas;
            }

            if (preg_match('#^審查(.*)函送(.*)(\d+)年度預算凍結書面報告#u', $content, $matches)) {
                // 審查內政部函送國家住宅及都市更新中心113年度預算凍結書面報告案計1案
                $unit = $matches[2];
                $unit = preg_replace('#及所屬$#u', '', $unit);
                $agenda = new StdClass;
                $agenda->type = '預算解凍';
                $agenda->title = '預算解凍:' . $unit;
                $agendas[] = $agenda;
                return $agendas;
            }

            if (preg_match('#^(審查或處理|處理|審查)\d+年度中央政府總預算案有關(.*)預算凍結#u', $content, $matches)) {
                // 審查或處理113年度中央政府總預算案有關大陸委員會預算凍結案計5案
                // 處理113年度中央政府總預算案有關行政院預算凍結書面報告
                // 審查113年度中央政府總預算案有關行政院預算凍結書面報告案計2案
                $unit = $matches[2];
                $unit = preg_replace('#及所屬$#u', '', $unit);
                $agenda = new StdClass;
                $agenda->type = '預算解凍';
                $agenda->title = '預算解凍:' . $unit;
                $agendas[] = $agenda;
                return $agendas;
            }

            if (preg_match('#^邀請(.*)就「(.*)」進行(專題)?報告，並備質詢$#u', $content, $matches)) {
                // 邀請內政部部長、行政院主計總處副主計長、原住民族委員會、行政院公共工程委員會、交通部、交通部觀光署、環境部、勞動部、農業部、教育部、衛生福利部、經濟部、財政部、國軍退除役官兵輔導委員會、金融監督管理委員會就「0403震災之災後救助、重建規劃及補助方式」及「從東華實驗室失火事件檢討災害防救之完備化」進行專題報告，並備質詢
                // 邀請公平交易委員會主任委員就「外送平台併購是否涉及限制競爭之調查程序、認定原則及對消費者權益影響之配套措施」進行報告，並備質詢
                $matches[2] = str_replace('」及「', '、', $matches[2]);
                $agenda = new StdClass;
                $agenda->type = '專題報告';
                $agenda->title = '專題報告:' . $matches[2];
                $agendas[] = $agenda;
                return $agendas;
            }

            if (preg_match('#^審查(.*)部分條文修正草案$#u', $content, $matches)) {
                // 審查國籍法部分條文修正草案
                $agenda = new StdClass;
                $agenda->type = '法案';
                $agenda->title = '法案:' . $matches[1];
                $agendas[] = $agenda;
                return $agendas;
            }

            if (preg_match('#^「([^」]+)」及「([^」]+)」$#u', $content, $matches)) {
                // 「新住民權益保障法草案」及「新住民基本法草案」
                $agenda = new StdClass;
                $agenda->type = '法案';
                $agenda->title = '法案:' . 
                    self::getLawNameFromString($matches[1]) . '、' . 
                    self::getLawNameFromString($matches[2]);
                $agendas[] = $agenda;
                return $agendas;
            }

            if (preg_match('#^(繼續)?審查(.*)擬具「([^」]+)」案$#u', $content, $matches)) {
                // 繼續審查委員鄭天財Sra Kacaw等20人擬具「原住民族基本法增訂第二十條之一條文草案」案。
                $agenda = new StdClass;
                $agenda->type = '法案';
                $agenda->title = '法案:' . self::getLawNameFromString($matches[3]);
                $agendas[] = $agenda;
                return $agendas;
            }

            if (preg_match('#^討論立法院(.*)委員會「(.*)」草案$#u', $content, $matches)) {
                // 討論立法院內政委員會「數位身分證換發政策及預算執行調閱小組運作要點」草案
                $agenda = new StdClass;
                $agenda->type = '法案';
                $agenda->title = '法案:' . $matches[2];
                $agendas[] = $agenda;
                return $agendas;
            }
        }

        if (preg_match('#^(繼續)?審查「([^」]+)」#u', $content, $matches)) {
            $agenda = new StdClass;
            $agenda->type = '法案';
            $agenda->title = '法案:' . self::getLawNameFromString($matches[2]);
            $agendas[] = $agenda;
            return $agendas;
        }   
        if (preg_match('#^(繼續)?審查：#u', $content) 
            or (strpos($content, '審查') !== false and strpos($content, '草案」') !== false)
        ) {
            preg_match_all('#「([^」]+)」#u', $content, $matches);
            $laws = [];
            foreach ($matches[1] as $law) {
                $laws[] = self::getLawNameFromString($law);
            }
            $laws = array_values(array_unique($laws));

            $agenda = new StdClass;
            $agenda->type = '法案';
            $agenda->title = '法案:' . implode('、', $laws);
            $agenda->laws = $laws;
            $agendas[] = $agenda;
            return $agendas;
        }
        if (mb_strlen($content) < 100) {
            $agenda = new StdClass;
            $agenda->type = '其他';
            $agenda->title = $content;
            $agendas[] = $agenda;
            return $agendas;
        }
        return [];
        echo "TODO: " . ($origin_content) . "\n";
        echo "  content: " . ($content) . "\n";
        echo "  length: " . mb_strlen($content, 'UTF-8') . "\n";
        error_log('meet_id=' . $meet->meet_id);
        readline('continue');
        return [];
    }

    public static function summarizeMeet($meet)
    {
        // 首先根據 ppg_data 來判斷要拆分幾天（因為院會是多日都同個議程，但是委員會可能每天審不同議程）
        $summaries = [];
        foreach ($meet->ppg_data as $ppg_data) {
            $summary = new StdClass;
            $summary->title = '';
            $summary->content = $ppg_data->content;
            $summary->agendas = [];
            $summary->dates = [];
            foreach ($ppg_data->dates as $date) {
                preg_match('#(\d+)年(\d+)月(\d+)日#u', $date, $matches);
                $summary->dates[] = sprintf("%04d-%02d-%02d", $matches[1] + 1911, $matches[2], $matches[3]);
            }

            $ppg_data->content = trim($ppg_data->content);
            $ppg_data->content = preg_replace('#。$#u', '', $ppg_data->content);


            $summary->agendas = self::getAgendasFromMeetContent($ppg_data->content, $ppg_data, $meet);
            $summaries[] = $summary;
        }
        $summaries = array_map(function ($summary) {
            $summary->title = implode('；', array_unique(array_map(function ($agenda) {
                return $agenda->title;
            }, $summary->agendas)));
            return $summary;
        }, $summaries);
        return $summaries;
    }
}
