<?php

class LYLib
{
    protected static $_committeeIdMap = null;
    public static function getCommitteeIdMap()
    {
        if (is_null(self::$_committeeIdMap)) {
            $cmd = [
                'size' => 100,
                // without source data
                '_source' => false,
                'fields' => ['comtCd', 'comtName'],
            ];
            $obj = Elastic::dbQuery("/{prefix}committee/_search", 'POST', json_encode($cmd));
            self::$_committeeIdMap = [];
            foreach ($obj->hits->hits as $hit) {
                $comtCd = $hit->fields->comtCd[0];
                $comtName = str_replace('委員會', '', $hit->fields->comtName[0]);
                self::$_committeeIdMap[$comtName] = intval($comtCd);
            }
        }
        return self::$_committeeIdMap;
    }

    public static function getCommitteeId($name)
    {
        $committeeIdMap = self::getCommitteeIdMap();
        $name =  str_replace('委員會', '', $name);
        if (!isset($committeeIdMap[$name])) {
            throw new Exception("找不到 {$name} 的委員會代碼");
        }
        return $committeeIdMap[$name];
    }

    public static function filterMeetData($meet)
    {
        if ($meet->attendLegislator == '') {
            $meet->attendLegislator = [];
        } else {
            $meet->attendLegislator = array_values(array_unique(explode(',', $meet->attendLegislator)));
        }
        if (preg_match('#^第(\d+)屆第(\d+)會期第(\d+)次全體委員會#', $meet->meetingName, $matches)) {
            $meet->meetingName = sprintf("第%d屆第%d會期%s第%d次全體委員會", $matches[1], $matches[2], $meet->meetingUnit, $matches[3]);
        }
        $meet->meetingName = str_replace('(變更議程)', '', $meet->meetingName);
        $meet->meetingName = str_replace('立', '立', $meet->meetingName);
        $meet->meetingName = str_replace('會體委員會議', '全體委員會議', $meet->meetingName);
        $meet->meetingName = preg_replace('#全體委$#', '全體委員會', $meet->meetingName);
        $meet->meetingName = str_replace('社會福利及衛生環境及經濟', '社會福利及衛生環境、經濟', $meet->meetingName);
        $meet->meetingName = preg_replace_callback('#第(\d+)期#', function ($matches) {
            return '第' . intval($matches[1]) . '會期';
        }, $meet->meetingName);
        $meet->meetingName = preg_replace_callback('#第(\d+)全體委員會議#', function ($matches) {
            return '第' . intval($matches[1]) . '次全體委員會議';
        }, $meet->meetingName);
        $meet->meetingName = trim($meet->meetingName);

        if (!preg_match('#^(\d+)/(\d+)/(\d+) (\d+):(\d+)-?((\d+):(\d+))?$#', $meet->meetingDateDesc, $matches)) {
            print_r($meet);
            readline('press any key to continue :');
        }
        $meet->date = date('Y-m-d', mktime(0, 0, 0, $matches[2], $matches[3], $matches[1] + 1911));
        $meet->startTime = date('Y-m-d\TH:i:s', mktime($matches[4], $matches[5], 0, $matches[2], $matches[3], $matches[1] + 1911));
        if (isset($matches[6])) {
            $meet->endTime = date('Y-m-d\TH:i:s', mktime($matches[7], $matches[8], 0, $matches[2], $matches[3], $matches[1] + 1911));
        } else {
            $meet->endTime = null;
        }
        $meet->term = intval($meet->term);
        $meet->sessionPeriod = intval($meet->sessionPeriod);

        if (is_null($meet->meetingNo)) {
            $meet->meetingNo = date('YmdHi', strtotime($meet->startTime));

            if (preg_match('#紅樓(\d+)#', $meet->meetingRoom, $matches)) {
                $meet->meetingNo .= sprintf("01%03d", $matches[1]);
            } elseif (preg_match('#群賢樓(\d+)#', $meet->meetingRoom, $matches)) {
                $meet->meetingNo .= sprintf("02%03d", $matches[1]);
            } elseif (preg_match('#請願接待室$#', $meet->meetingRoom, $matches)) {
                $meet->meetingNo .= sprintf("03%03d", 0);
            } elseif (preg_match('#青島三館(\d+)會議室$#', $meet->meetingRoom, $matches)) {
                $meet->meetingNo .= sprintf("04%04d", $matches[1]);
            } else {
                echo $meet->meetingRoom . "\n";
                throw new Exception("{$meet->meetingRoom} 有問題");
            }
            $meet->meetingType = '其他會議'; // Ex: 公聽會 記者會 座談會 等等
            return $meet;
        }

        return $meet;
    }

    public static $consult_meets = null;
    public static function getConsultMeets()
    {
        if (is_null(self::$consult_meets)) {
            $cmd = [
                'size' => 10000,
                'query' => [
                    'bool' => [
                        'must' => [
                            ['terms' => ['meet_type.keyword' => [
                                '黨團協商', '公聽會'
                            ]]],
                        ],
                    ],
                ],
            ];
            $obj = Elastic::dbQuery("/{prefix}meet/_search", 'POST', json_encode($cmd));
            self::$consult_meets = [];
            foreach ($obj->hits->hits as $hit) {
                self::$consult_meets[$hit->_id] = $hit->_source;
            }
        }
        return self::$consult_meets;
    }
    /**
     * consultToId 處理把黨團協商轉換成 id
     */
    public static function consultToId($from, $data, $type = '黨團協商')
    {
        $ret = new StdClass;
        $ret->tmpMeeting = null;
        $ret->committees = [];
        if ($type == '黨團協商') {
            $ret->type = $type;
            $ret->title = '立法院朝野黨團協商';
        } elseif ($type == '公聽會') {
            $ret->type = '公聽會';
            if (property_exists($data, 'meetingName')) {
                $ret->title = $data->meetingName;
            } elseif (property_exists($data, '會議名稱')) {
                $ret->title = $data->會議名稱;
            } else {
                // title 只能從 meet_data 抓
                print_r($data);
                throw new Exception("公聽會抓不到名稱");
            }
        } else {
            print_r($data);
            exit;
        }

        if ($from == 'meet_data') { // 處理來自 open data
            $ret->id = "{$ret->type}-{$data->meetingNo}";
            $ret->term = intval(substr($data->selectTerm, 0, 2));
            $ret->sessionPeriod = intval(substr($data->selectTerm, 2, 2));
            if ($ret->type == '黨團協商') {
                if ($data->meetingUnit == '朝野黨團協商') {
                } elseif (preg_match('#^朝野黨團協商\(.*黨團\)$#', $data->meetingUnit)) {
                } elseif (preg_match('#^朝野黨團協商\((.*委員會)\)$#', $data->meetingUnit, $matches)) {
                    $committee_id = self::getCommitteeId($matches[1]);
                    $ret->committees[] = $committee_id;
                } else {
                    print_r($data);
                    print_r($ret);
                    throw new Exception("{$data->meetingUnit} 有問題");
                }
            } elseif ('公聽會' == $ret->type) {
                if (strpos($data->meetingUnit, '委員會') and $data->meetingUnit != '全院委員會') {
                    try {
                        $ret->committees[] = self::getCommitteeId($data->meetingUnit);
                    } catch (Exception $e) {
                    }
                }
                if (property_exists($data, 'meetingContent') and !is_null($data->meetingContent)) {
                    $ret->title = $data->meetingContent;
                } elseif (property_exists($data, 'meetingName') and !is_null($data->meetingName)) {
                    $ret->title = $data->meetingName;
                } else {
                    print_r($data);
                    exit;
                }
                // TODO: 處理會議相關法律
            } else {
                print_r($data);
                exit;
            }
            return $ret;
        } elseif ('ivod' == $from) {
            $meets = self::getConsultMeets();
            $data->{'會議名稱'} = str_replace("\n", '', $data->{'會議名稱'});
            $data->{'會議名稱'} = str_replace("\r", '', $data->{'會議名稱'});
            $data->{'會議名稱'} = str_replace(' ', '', $data->{'會議名稱'});
            $data->{'會議名稱'} = str_replace('　', '', $data->{'會議名稱'});
            $data->{'會議名稱'} = str_replace('(變更議程)', '', $data->{'會議名稱'});
            if (preg_match('#立法院(朝野)?黨團協商（事由：(.*)）$#um', $data->{'會議名稱'}, $matches)) {
                $title = $matches[2];
            } elseif (preg_match('#立法院第\d+屆第\d+會期.*委員會公聽會（事由：(.*)）#um', $data->{'會議名稱'}, $matches)) {
                $title = $matches[1];
            } else {
                throw new Exception("{$data->{'會議名稱'}} 有問題");
            }

            $title = preg_replace('#\([^(]*提議\)$#', '', $title);
            $title = preg_replace('#。$#u', '', $title);

            $match_meets = [];
            $mismatch_meets = [];
            foreach ($meets as $meet) {
                foreach ($meet->meet_data as $meet_data) {
                    if ($meet_data->date != $data->date) {
                        continue;
                    }
                    $meet_data->meetingContent = str_replace("\n", '', $meet_data->meetingContent);
                    $meet_data->meetingContent = str_replace("\r", '', $meet_data->meetingContent);
                    $meet_data->meetingContent = str_replace(' ', '', $meet_data->meetingContent);  
                    $meet_data->meetingContent = str_replace('　', '', $meet_data->meetingContent);
                    // 繼續研商「農業部組織法草案」等>案(如附表)(民進黨黨團提議) => 
                    //   繼續研商「農業部組織法草案」等>案(如附表)
                    $meet_data->meetingContent = preg_replace('#\([^(]*提議\)$#', '', $meet_data->meetingContent);
                    $meet_data->meetingContent = preg_replace('#。$#u', '', $meet_data->meetingContent);
                    if ($meet_data->meetingContent != $title) {
                        $mismatch_meets[] = $meet_data->meetingContent;
                        continue;
                    }
                    $match_meets[] = $meet;
                    break;
                }
            }
            if (count($match_meets) != 1) {
                echo "match_meets=" . json_encode($match_meets, JSON_UNESCAPED_UNICODE) . "\n";
                echo "mismatch_meets=" . json_encode($mismatch_meets, JSON_UNESCAPED_UNICODE) . "\n";
                echo "data=" . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
                echo "title={$title}\n";
                //exit;
                throw new Exception("對應到的黨團協商不是一個");
            }
            $meet = $match_meets[0];
            $ret->id = $meet->meet_id;
            $ret->committees = $meet->committees;
            $ret->term = $meet->term;
            $ret->sessionPeriod = $meet->sessionPeriod;
            return $ret;
        }
        return null;
    }

    public static function meetToId($type, $meet)
    {
        if (strpos($meet->meetingName , '黨團協商')) {
            return LYLib::consultToId($type, $meet, '黨團協商');
        } elseif (strpos($meet->meetingName, '公聽會')) {
            return LYLib::consultToId($type, $meet, '公聽會');
        } elseif (strpos($meet->meetingName, '次全體')) {
            // do nothing
            // Ex: 第11屆第2會期財政委員會第5次全體委員會議 的 meetingContent 包含「公聽會」
        } elseif (strpos($meet->meetingContent, '公聽會')) {
            return LYLib::consultToId($type, $meet, '公聽會');
        }

        $meet_obj = null;
        if ($type == 'meet_data') {
            try {
                $meet_obj = LYLib::meetNameToId($meet->meetingName);
            } catch (Exception $e) {
                error_log("{$meet->meetingName} 有問題");
                return null;
            }
        }
        return $meet_obj;
    }

    public static function meetNameToId($oname, $extra_data = null)
    {
        $ret = new StdClass;
        $ret->tmpMeeting = null;

        $committees = [];
        $name = $oname;
        $name = str_replace(' ', '', $name);
        $name = str_replace('：', '', $name);
        $name = str_replace(':', '', $name);
        $name = preg_replace('#（.*）$#', '', $name);
        $name = preg_replace('#\(.*\)$#', '', $name);
        $name = preg_replace('#【.*】$#', '', $name);
        //$name = preg_replace('#「.*」#', '', $name);
        $name = preg_replace('#議事日程$#', '', $name);
        $name = str_replace('第六次', '第6次', $name);
        $name = preg_replace('#^立法院#', '', $name);
        $name = preg_replace('#議事錄$#', '', $name);
        $name = preg_replace('#會議紀錄$#', '會議', $name);

        $ret->title = '立法院' . $name;
        // 第8屆第5會期第4次會議 -> all-8-5-4
        // 第8屆第1會期第1次全院委員會會議 -> all-8-1-1
        // 第8屆第1會期第1次臨時會第1次會議 -> temp-8-1-1-1
        // 立法院第8屆第5會期交通委員會第4次全體委員會議 -> committee-8-5-23-4
        // 立法院第8屆第1會期財政、經濟委員會第1次聯席會議 -> committees-8-1-19,20-1
        // 第8屆第4會期第2次全院委員會議
        if (preg_match('/^第(\d+)屆第(\d+)會期第(\d+)次(全院委員會?)?會議$/u', $name, $matches)) {
            $ret->title = $matches[0];
            if (count($matches) > 4 and $matches[4]) {
                $ret->id = '全院委員會-' . $matches[1] . '-' . $matches[2] . '-' . $matches[3];
                $ret->type = '全院委員會';
                $ret->term = intval($matches[1]);
                $ret->sessionPeriod = intval($matches[2]);
                $ret->sessionTimes = intval($matches[3]);
                return $ret;
            } else {
                $ret->id = '院會-' . $matches[1] . '-' . $matches[2] . '-' . $matches[3];
                $ret->type = '院會';
                $ret->term = intval($matches[1]);
                $ret->sessionPeriod = intval($matches[2]);
                $ret->sessionTimes = intval($matches[3]);
                return $ret;
            }
        }

        if (preg_match('/^第(\d+)屆第(\d+)會期第(\d+)次臨時會第(\d+)次(全院委員會)?(會議)?$/', $name, $matches)) {
            $ret->title = $matches[0];
            if ($matches[5]) {
                $ret->id = '臨時會全院委員會-' . $matches[1] . '-' . $matches[2] . '-' . $matches[3] . '-' . $matches[4];
                $ret->type = '全院委員會';
                $ret->term = intval($matches[1]);
                $ret->sessionPeriod = intval($matches[2]);
                $ret->tmpMeeting = intval($matches[3]);
                $ret->sessionTimes = intval($matches[4]);
                return $ret;

            } else {
                $ret->id = '臨時會院會-' . $matches[1] . '-' . $matches[2] . '-' . $matches[3] . '-' . $matches[4];
                $ret->type = '院會';
                $ret->term = intval($matches[1]);
                $ret->sessionPeriod = intval($matches[2]);
                $ret->tmpMeeting = intval($matches[3]);
                $ret->sessionTimes = intval($matches[4]);
                return $ret;
            }
        }
        // 立法院第8屆第2會期財政委員會第5次全體委員會議
        // 立法院第8屆第5會期第2次臨時會內政委員會第1次全體委員會議
        // 立法院第8屆第6會期財政委員會第6次全體委員會議
        // 立法院第9屆第1會期社會福利及衛生環境委員會31次全體委員會議
        // 第10屆第3會期程序委員會第6次會議
        if (preg_match('/^程序委員會第(\d+)屆第(\d+)會期第(\d+)次會議/u', $name, $matches) or
            preg_match('/^第(\d+)屆第(\d+)會期程序委員會第(\d+)次會議/u', $name, $matches)
        ) {
            $committee_id = self::getCommitteeId('程序');
            $ret->type = '委員會';
            $ret->title = $matches[0];
            $ret->term = intval($matches[1]);
            $ret->sessionPeriod = intval($matches[2]);
            $ret->sessionTimes = intval($matches[3]);
            $ret->committees[] = $committee_id;
            $ret->id = '委員會-' . $matches[1] . '-' . $matches[2] . '-' . $committee_id . '-' . $matches[3];
            return $ret;
        }
        // 第121屆經費稽核委員會第7次會議
        if (preg_match('/^第(\d+)屆經費稽核委員會第(\d+)次會議/u', $name, $matches)) {
            $committee_id = self::getCommitteeId('經費稽核');
            $ret->title = $matches[0];
            $ret->committees[] = $committee_id;
            $ret->type = '委員會';
            if ($matches[1] >= 129) {
                $ret->term = 11;
            } elseif ($matches[1] >= 125) {
                $ret->term = 10;
            } elseif ($matches[1] >= 121) {
                $ret->term = 9;
            } else {
                throw new Exception("unknown term: {$matches[1]}");
            }
            $ret->sessionPeriod = 0;
            $ret->sessionTimes = intval($matches[2]);

            $ret->id = '委員會-' . $ret->term . '-' . $matches[1] . '-' . $committee_id . '-' . $matches[2];
            return $ret;
        }

        if (preg_match('/^第(\d+)屆第(\d+)會期(第(\d+)次臨時會)?([^第0-9]*)第?(\d+)次全體委員會議?/u', $name, $matches)) {
            $committeeIdMap = self::getCommitteeIdMap();
            $ret->title = $matches[0];
            try {
                $committee_id = self::getCommitteeId($matches[5]);
                $ret->term = intval($matches[1]);
                $ret->type = '委員會';
                $ret->sessionPeriod = intval($matches[2]);
                $ret->sessionTimes = intval($matches[6]);
                $ret->committees[] = $committee_id;
                if ($matches[3]) {
                    $ret->tmpMeeting = intval($matches[4]);
                    $ret->id = '臨時會委員會-' . $matches[1] . '-' . $matches[2] . '-' . $matches[4] . '-' . $committee_id . '-' . $matches[6]; 
                } else {
                    $ret->id = '委員會-' . $matches[1] . '-' . $matches[2] . '-' . $committee_id . '-' . $matches[6];
                }
                return $ret;
            } catch (Exception $e) {
            }
        }
        // 立法院第8屆修憲委員會第1次全體委員會議
        if (preg_match('/^第(\d+)屆([^第]*)委員會第(\d+)次全體委員會議?/u', $name, $matches)) {
            $ret->title = $matches[0];
            $committeeIdMap = self::getCommitteeIdMap();
            $ret->type = '委員會';
            $ret->term = intval($matches[1]);
            try {
                $committee_id = self::getCommitteeId($matches[2]);
                $ret->committees[] = $committee_id;
                $ret->sessionTimes = intval($matches[3]);
                $ret->id = '委員會-' . $matches[1] . '-' . $committee_id . '-' . $matches[3];
                return $ret;
            } catch (Exception $e) {
            }
        }
        // 立法院第8屆第5會期第1次臨時會經濟、財政、內政三委員會第1次聯席會議
        // 立法院第8屆第6會期社會福利及衛生環境、司法及法制二委員會第1次聯席會議
        // 立法院第9屆第4會期社會福利及衛生環境及經濟二委員會第1次聯席會議
        if (preg_match('/^第(\d+)屆第(\d+)會期(第(\d+)次臨時會)?(.*)第(\d+)次(聯席|全體委員)會議?/u', $name, $matches)) {
            $ret->title = $matches[0];
            $ret->type = '聯席會議';
            $ret->term = intval($matches[1]);
            $ret->sessionPeriod = intval($matches[2]);
            $ret->sessionTimes = intval($matches[6]);
            $committeeIdMap = self::getCommitteeIdMap();
            $committee_ids = [];
            foreach (explode('、', $matches[5]) as $committee_name) {
                $committee_name = str_replace('兩委員會', '', $committee_name);
                $committee_name = str_replace('二委員會', '', $committee_name);
                $committee_name = str_replace('三委員會', '', $committee_name);
                $committee_name = str_replace('五委員會', '', $committee_name);
                $committee_name = str_replace('六委員會', '', $committee_name);
                $committee_name = str_replace('８委員會', '', $committee_name);
                $committee_name = str_replace('8委員會', '', $committee_name);
                $committee_ids[] = self::getCommitteeId($committee_name);
            }
            if (count($committee_ids) < 2) {
                throw new Exception("{$name} 有問題");
            }
            $ret->committees = $committee_ids;
            if ($matches[3]) {
                $ret->tmpMeeting = intval($matches[4]);
                $ret->id = '臨時會聯席會議-' . $matches[1] . '-' . $matches[2] . '-' . $matches[4] . '-' . implode(',', $committee_ids) . '-' . $matches[6];
            } else {
                $ret->id = '聯席會議-' . $matches[1] . '-' . $matches[2] . '-' . implode(',', $committee_ids) . '-' . $matches[6];
            }
            return $ret;
        }
        if (strpos($name, '公聽會') !== false) {
            return null;
        }
        if (strpos($name, '考察') !== false) {
            return null;
        }
        if (strpos($name, '參訪') !== false) {
            return null;
        }
        if ($name == '') {
            return null;
        }
        if (strpos($name, '黨團協商') !== false) {
            if (!($extra_data['meetingNo'] ?? false)) {
                throw new Exception("{$oname} 有問題");
            }
            $ret->title = $oname;
            $ret->type = '黨團協商';
            $ret->id = '黨團協商-' . $extra_data['meetingNo'];
            if ($extra_data['meetingUnit'] ?? false) {
                if ($extra_data['meetingUnit'] == '朝野黨團協商') {
                } elseif (preg_match('#^朝野黨團協商\((.*委員會)\)$#', $extra_data['meetingUnit'], $matches)) {
                    $committee_id = self::getCommitteeId($matches[1]);
                    $ret->committees[] = $committee_id;
                } else {
                    throw new Exception("{$extra_data['meetingUnit']} 有問題");
                }
            }
            return $ret;
        }
        throw new Exception("{$oname} 有問題");
    }

    public static function buildLegislator($source)
    {
        $source->picUrl = str_replace('http://', 'https://', $source->picUrl);
        return $source;
    }

    public static function buildBill($source)
    {
        $source->ppg_url = sprintf("https://ppg.ly.gov.tw/ppg/bills/%s/details", $source->billNo);
        return $source;
    }

    public static function buildInterpellation($source)
    {
        $source->ppg_url = sprintf("https://ppg.ly.gov.tw/ppg/sittings/%s/details?meetingDate=%d/%02d/%02d",
            $source->meetingNo,
            date('Y', strtotime($source->meetingDate)) - 1911,
            date('m', strtotime($source->meetingDate)),
            date('d', strtotime($source->meetingDate))
        );
        return $source;
    }

    public static function buildLaw($source)
    {
        return $source;
    }

    public static function buildMeet($source, $target = 'api')
    {
        $source->dates = [];
        if ($source->{'議事錄'} ?? false) {
            $source->name = str_replace('議事錄', '', $source->{'議事錄'}->title);
            $source->name = str_replace('立法院', '' , $source->name);
        } elseif (property_exists($source, 'meet_data') and is_array($source->meet_data) and count($source->meet_data)) {
            $source->name = $source->meet_data[0]->meetingName;
        } elseif (is_array($source->{'發言紀錄'}) and count($source->{'發言紀錄'})) {
            $source->name = $source->{'發言紀錄'}[0]->meetingName;
        }
        $source->name = str_replace('立法院', '' , $source->name);


        if (property_exists($source, 'meet_data') and is_array($source->meet_data) and count($source->meet_data)) {
            foreach ($source->meet_data as $meet) {
                $source->dates[] = $meet->date;
            }
            $source->dates = array_values(array_unique($source->dates));
        } elseif (is_array($source->{'發言紀錄'}) and count($source->{'發言紀錄'})) {
            foreach ($source->{'發言紀錄'} as $agenda) {
                $source->dates[] = $agenda->smeetingDate;
            }
            $source->dates = array_values(array_unique($source->dates));
        }
        if ($target == 'api' and is_array($source->meet_data) and count($source->meet_data)) {
            foreach ($source->meet_data as $idx => $meet_data) {
                if (strlen($meet_data->meetingNo) < 15) {
                    $meet_data->ppg_url = sprintf("https://ppg.ly.gov.tw/ppg/sittings/%s/details?meetingDate=%d/%02d/%02d",
                        $meet_data->meetingNo,
                        date('Y', strtotime($meet_data->date)) - 1911,
                        date('m', strtotime($meet_data->date)),
                        date('d', strtotime($meet_data->date))
                    );
                    if ($source->{'議事錄'} ?? false) {
                        $source->{'議事錄'}->ppg_url = $meet_data->ppg_url;
                    }
                }
                $source->meet_data[$idx] = $meet_data;
            }
        }
        if ($target == 'api' and $source->{'議事錄'} ?? false) {
            $source->{'議事錄'}->doc_file = sprintf("https://lydata.ronny-s3.click/meet-proceeding-doc/%s.doc", urlencode($source->meet_id));
            $source->{'議事錄'}->txt_file = sprintf("https://lydata.ronny-s3.click/meet-proceeding-txt/%s.txt", urlencode($source->meet_id));
            $source->{'議事錄'}->html_file = sprintf("https://lydata.ronny-s3.click/meet-proceeding-html/%s.html", urlencode($source->meet_id));
        }

        if ($target == 'api' and $source->{'公報發言紀錄'} ?? false) {
            foreach ($source->{'公報發言紀錄'} as &$agenda) {
                $agenda->transcript_api = sprintf("https://%s/meet/%s/transcript/%s",
                    $_SERVER['HTTP_HOST'],
                    urlencode($source->meet_id),
                    urlencode($agenda->agenda_id)
                );
                $agenda->html_files = [];
                $agenda->txt_files = [];
                foreach ($agenda->agenda_lcidc_ids ?? [] as $id ){
                    $agenda->html_files[] = sprintf("https://%s/gazette_agenda/%s/html", $_SERVER['HTTP_HOST'], urlencode($id));
                    $agenda->txt_files[] = sprintf("https://lydata.ronny-s3.click/agenda-txt/LCIDC01_%s.doc", urlencode($id));
                }
                // https://ppg.ly.gov.tw/ppg/publications/official-gazettes/106/15/01/details
                $comYear = substr($agenda->gazette_id, 0, 3);
                $comVolume = substr($agenda->gazette_id, 3, strlen($agenda->gazette_id) - 5);
                $comBookId = substr($agenda->gazette_id, -2);
                $agenda->ppg_gazette_url = sprintf("https://ppg.ly.gov.tw/ppg/publications/official-gazettes/%d/%02d/%02d/details",
                    $comYear,
                    $comVolume,
                    $comBookId
                );
            }
        }

        return $source;
    }

    public static function buildGazette($source)
    {
        $source->gazette_id = sprintf("%03d%02d%02d",
            $source->comYear,
            $source->comVolume,
            $source->comBookId
        );
        $source->agenda_api = sprintf("https://%s/gazette_agenda/%s",
            $_SERVER['HTTP_HOST'],
            $source->gazette_id
        );
        $source->ppg_pdf_url = sprintf("https://ppg.ly.gov.tw/ppg/PublicationBulletinDetail/download/communique1/final/pdf/%d/%02d/LCIDC01_%03d%02d%02d.pdf",
            $source->comYear,
            $source->comVolume,
            $source->comYear,
            $source->comVolume,
            $source->comBookId
        );
        $source->ppg_gazette_url = sprintf("https://ppg.ly.gov.tw/ppg/publications/official-gazettes/%03d/%02d/%02d/details",
            $source->comYear,
            $source->comVolume,
            $source->comBookId
        );
        return $source;
    }

    public static function buildGazetteAgenda($source)
    {
        $source->ppg_gazette_url = sprintf("https://ppg.ly.gov.tw/ppg/publications/official-gazettes/%03d/%02d/%02d/details",
            $source->comYear,
            $source->comVolume,
            $source->comBookId
        );

        $source->ppg_full_gazette_url = sprintf("https://ppg.ly.gov.tw/ppg/PublicationBulletinDetail/download/communique1/final/pdf/%d/%02d/LCIDC01_%03d%02d%02d.pdf",
            $source->comYear,
            $source->comVolume,
            $source->comYear,
            $source->comVolume,
            $source->comBookId
        );
        $source->htmlUrls = [];
        foreach ($source->docUrls as $doc_url) {
            if (!preg_match('#LCIDC01_([0-9_]+)#', $doc_url, $matches)) {
                continue;
            }
            $source->htmlUrls[] = sprintf("https://%s/gazette_agenda/%s/html", $_SERVER['HTTP_HOST'], urlencode($matches[1]));
        }
        return $source;
    }

    public static function parseTxtFile($basename, $dir)
    {
        $docfile = $dir . "/docfile/{$basename}";
        if (!file_exists($dir . "/txtfile/" . $basename) or filesize($dir . "/txtfile/{$basename}") == 0) {
            system(sprintf("antiword %s > %s", escapeshellarg($docfile), escapeshellarg($dir . "/txtfile/{$basename}")));
        }

        if (file_exists($dir . "/txtfile/" . $basename)) {
            // 檢查是否有圖片，有的話就解出來轉檔
            $cmd = sprintf("grep --quiet %s %s", escapeshellarg('\[pic\]'), escapeshellarg($dir . '/txtfile/' . $basename));
            system($cmd, $ret);
            if ($ret) {
                return;
            }
            $pics = LYLib::parseDoc($docfile, $dir);
            $pic_count = 0;
            $content = file_get_contents($dir . "/txtfile/{$basename}");
            $uploading_pics = [];
            $content = preg_replace_callback('/\[pic\]/', function($matches) use (&$pics, $basename, &$uploading_pics, &$pic_count) {
                if (!$pics) {
                    print_r($pics);
                    throw new Exception("圖片數量不正確: {$basename} (pic_count = {$pic_count})");
                }
                $pic = array_shift($pics);
                $pic_count ++;
                if ($pic[2] < 10) {
                    return '==========';
                }
                $uploading_pics[$pic[0]] = true;
                return "[pic:https://lydata.ronny-s3.click/picfile/{$basename}-{$pic[0]}]";
            }, $content);

            file_put_contents($dir . "/txtfile/{$basename}", $content);
        }
    }

    public static function parseDoc($file, $dir)
    {
        error_log("parse doc $file");
        $basename = basename($file);
        if (file_exists($dir . "/agenda-html/{$basename}.html") and filesize($dir . "/agenda-html/{$basename}.html") > 0){
            return;
        }
        $cmd = sprintf("curl --output %s --request POST --url 'https://unoserver2.openfun.dev/request' --compressed --header 'Content-Type: multipart/form-data' --form %s --form 'convert-to=html'",
            escapeshellarg('tmp.html'),
            escapeshellarg('file=@' . $file)
        );

        system($cmd, $ret);
        if ($ret) {
            error_log("curl error: {$file}");
            return;
        }
        $content = file_get_contents('tmp.html');

        ini_set("pcre.backtrack_limit", "10000000");
        $content = preg_replace_callback('#<img ([^>]*)src="data:([^"]+)"#u', function($matches) use ($file, $basename, $dir) {
            $attr = $matches[1];
            $data_uri = $matches[2];

            if (preg_match('#^image/png;base64,(.*)$#', $data_uri, $matches)) {
                $type = 'png';
            } elseif (preg_match('#^image/jpeg;base64,(.*)$#', $data_uri, $matches)) {
                $type = 'jpg';
            } elseif (preg_match('#^image/gif;base64,(.*)$#', $data_uri, $matches)) {
                $type = 'gif';
            } elseif (preg_match('#^;base64,(.*)$#', $data_uri, $matches)) {
                $type = '';
            } elseif (preg_match('#image/([^;]*);base64,(.*)$#', $data_uri, $matches)) {
                if ($matches[1] == 'x-emf') {
                    $type = 'emf';
                } else {
                    $img = base64_decode($matches[2]);
                    file_put_contents('tmpimg', $img);
                    $type = $matches[1];
                    throw new Exception("{$file} no png data image: {$type}");
                }
            } else {
                throw new Exception("{$file} no png data image: " . substr($data_uri, 0, 100));
            }
            $img = base64_decode($matches[1]);
            $hash = md5($img);
            $target = "{$dir}/agenda-pic/{$basename}-{$hash}.{$type}";
            error_log("write $target");
            file_put_contents($target, $img);

            $s = "<img {$attr}src=\"pic://{$basename}-{$hash}.{$type}\"";
            error_log($s);
            return $s;
        }, $content);

        if (!$content) {
            var_dump($content);
            $ret = preg_last_error_msg();
            error_log("preg_last_error: $ret");
            throw new Exception("{$file} no content");
        }

        file_put_contents($dir . "/agenda-html/{$basename}.html", $content);
    }

    public static function getAgendaHTML($url)
    {
        $basename = basename($url);
        if (!file_exists(__DIR__ . "/imports/gazette/agenda-doc/")) {
            throw new Exception("no agenda-doc directory");
        }
        $agenda_docfile = __DIR__ . "/imports/gazette/agenda-doc/{$basename}";
        if (!file_exists($agenda_docfile)) {
            error_log("download $url");
            system(sprintf("curl -4 -o %s %s", escapeshellarg(__DIR__ . "/tmp.doc"), escapeshellarg($url)), $ret);
            if ($ret) {
                throw new Exception("curl error: {$url}");
            }
            copy(__DIR__ . "/tmp.doc", $agenda_docfile);
            unlink(__DIR__ . "/tmp.doc");
        }
        $agenda_docxfile = __DIR__ . "/imports/gazette/agenda-docx/{$basename}";
        $agenda_htmlfile = __DIR__ . "/imports/gazette/agenda-html/{$basename}.html";
        if (!file_exists($agenda_docxfile)) {
            error_log("to docx $agenda_docfile");
            // curl -s -v --request POST --url https://unoserver.openfun.dev/request --header 'Content-Type: multipart/form-data'  --form "file=@LCIDC01_1016201_00006.doc" --form 'convert-to=txt' --output test.txt
            system(sprintf("curl --request POST --url https://unoserver2.openfun.dev/request --compressed --header 'Content-Type: multipart/form-data'  --form file=@%s --form 'convert-to=docx' --output %s", escapeshellarg($agenda_docfile), escapeshellarg(__DIR__ . '/tmp.docx')), $ret);
            clearstatcache();
            if (filesize(__DIR__ . '/tmp.docx') < 1000) {
                copy(__DIR__ . "/tmp.docx", $agenda_docxfile);
                touch($agenda_htmlfile);
                error_log("to docx error: $agenda_docfile: " . file_get_contents(__DIR__ . '/tmp.docx'));
                //throw new Exception("to docx error: $agenda_docfile: " . file_get_contents(__DIR__ . '/tmp.docx'));
            }
            copy(__DIR__ . "/tmp.docx", $agenda_docxfile);
            unlink(__DIR__ . "/tmp.docx");
            error_log("to docx done: " . ($agenda_docxfile));
        }
        if (!file_exists($agenda_htmlfile) or filesize($agenda_htmlfile) == 0 ){
            error_log("to html $agenda_docxfile");
            self::parseDoc($agenda_docxfile, __DIR__ . '/imports/gazette/');
        }
        return $agenda_htmlfile;
    }

    public static function getIVODTranscript($ivod_id)
    {
        $url = sprintf("https://lydata.ronny-s3.click/ivod-transcript/%d.json", $ivod_id);
        $obj = json_decode(file_get_contents($url));
        if (is_null($obj)) {
            return null;
        }

        // split transcript
        $whisperx = json_decode($obj->whisperx->result->json);
        $segments = [];
        foreach ($whisperx->segments as $segment) {
            $segments[] = [
                'start' => $segment->start,
                'end' => $segment->end,
                'text' => $segment->text,
            ];
        }
        $obj->whisperx->result->segments = $segments;
        
        return $obj;
    }

    public static function getIVODGazette($ivod, $from_cache = false)
    {
        if ($from_cache) {
            $ivod_id = $ivod->id;
            $url = sprintf("https://lydata.ronny-s3.click/ivod-gazette/%d.json", $ivod_id);
            $obj = json_decode(file_get_contents($url));
            if (is_null($obj)) {
                return null;
            }
            return $obj;
        }

        $ret = new StdClass;
        $meet_id = $ivod->meet->id ?? false;
        if (!$meet_id) {
            $ret->error = true;
            $ret->message = '無公報發言紀錄';
            error_log(json_encode($ivod->meet, JSON_UNESCAPED_UNICODE));
            return $ret;
        }
        $obj = Elastic::dbQuery("/{prefix}meet/_doc/{$meet_id}");
        $date = $ivod->date;
        $agendas= $obj->_source->{'公報發言紀錄'} ?? [];
        if (!is_array($agendas) or !count($agendas)) {
            $ret->error = true;
            $ret->message = '無公報發言紀錄';
            return $ret;
        }
        $speaker = $ivod->{'委員名稱'};
        $agendas = array_filter($agendas, function($record) use ($date, $speaker) {
            if (!property_exists($record, 'meetingDate')) {
                return false;
                echo json_encode($record, JSON_UNESCAPED_UNICODE) . "\n";
                throw new Exception('no meetingDate');
            }
            if (!in_array($date, $record->meetingDate)) {
                return false;
            }
            if (!in_array($speaker, $record->speakers)) {
                //return false;
            }
            return true;
        });
        $agendas = array_values($agendas);
        if (!is_array($agendas) or !count($agendas)) {
            $ret->error = true;
            $ret->message = '篩選後找不到對應的公報';
            return $ret;
        }
        if ($ivod->id == 153018) {
            // https://ivod.ly.gov.tw/Play/Clip/1M/153018 # 前面先講了一堆話才開始質詢
            $ivod->start_time = '2024-05-27 11:02:00';
        }
        $contents = [];
        $urls = [];
        foreach ($agendas as $agenda) {
            foreach ($agenda->agenda_lcidc_ids as $lcidc_id) {
                $target = __DIR__ . sprintf('/imports/gazette/agenda-tikahtml/LCIDC01_%s.doc.html', $lcidc_id);
                $url = sprintf("https://lydata.ronny-s3.click/agenda-tikahtml/LCIDC01_%s.doc.html", urlencode($lcidc_id));
                $urls[] = $url;

                if (file_exists($target)) {
                    $content = file_get_contents($target);
                } else {
                    error_log($url);
                    $curl = curl_init();
                    curl_setopt($curl, CURLOPT_URL, $url);
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    $content = curl_exec($curl);
                    $info = curl_getinfo($curl);
                    if ($info['http_code'] != 200) {
                        continue;
                    }
                }
                $content = GazetteTranscriptParser::parse($content);
                while (count($content->blocks)) {
                    $block = array_shift($content->blocks);
                    $lineno = array_shift($content->block_lines);
                    if (!$block) {
                        continue;
                    }
                    $block[0] = str_replace('委員', '', $block[0]);
                    if (strpos($block[0], "段落：質詢：{$speaker}") === false) {
                        continue;
                    }
                    $time = explode('：', $block[0])[3];
                    $time = strtotime($time, strtotime($ivod->start_time));
                    error_log("checking: " . json_encode([
                        'ivod_start_time' => $ivod->start_time,
                        'block' => $block[0],
                        'speaker_time' => date('c', $time),
                        'url' => $url,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    if (abs($time - strtotime($ivod->start_time)) > 180) {
                        continue;
                    }
                    $ret->lineno = $lineno;
                    $ret->blocks = [];
                    $ret->agenda = $agenda;
                    while (count($content->blocks)) {
                        $block = array_shift($content->blocks);
                        if (strpos($block[0], "段落：") !== false) {
                            return $ret;
                        }
                        $ret->blocks[] = $block;
                        foreach ($block as $line) {
                            if (strpos($block[0], '主席') === 0 and strpos($line, '報告院會') !== false) {
                                return $ret;
                            }
                            if (strpos($block[0], '主席') === 0 and preg_match('#(發言|詢答)完畢[，。]#u', $line)) {
                                return $ret;
                            }
                        }
                    }
                }
            }
        }
        if (property_exists($ret, 'blocks')) {
            $ret->error = true;
            $ret->message = '不確定有沒有抓到正確的結束點';
            return $ret;
            echo json_encode($ret->blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $input = readline('press yes for save');
            if ($input == 'yes') {
                return $ret;
            }
        }
        $ret->error = true;
        $ret->message = '找不到對應的段落: ' . implode("\n", $urls);

        return $ret;
    }

    protected static $_meet_cache = null;

    public static function getMeetsByDate($date, $type)
    {
        // 透過 API 去抓符合日期的會議，為了提升效率一次會抓一個月並 cache 起來
        // 節省存取資料庫的時間

        $year_month = date('Y-m', strtotime($date));
        if (is_null(self::$_meet_cache)) {
            self::$_meet_cache = [];
        }
        // 取得該月資料
        if (!array_key_exists($year_month, self::$_meet_cache)) {
            self::$_meet_cache[$year_month] = [];
            $terms = [];
            $terms[] = sprintf('日期:%s,%s',
                "{$year_month}-01",
                date('Y-m-d', strtotime("{$year_month}-01 +1 month -1 day"))
            );
            $terms[] = 'output_fields=name';
            $terms[] = 'output_fields=會議代碼';
            $terms[] = 'output_fields=委員會代號';
            $terms[] = 'output_fields=議事網資料';
            $terms[] = 'output_fields=日期';
            $terms[] = 'limit=1000';

            $url = "https://v2.ly.govapi.tw/meets?" . implode('&', $terms);
            $json = file_get_contents($url);
            $data = json_decode($json);
            foreach ($data->meets as $meet) {
                $billNos = [];
                foreach ($meet->議事網資料 ?? [] as $record) {
                    foreach ($record->關係文書->議案 ?? [] as $bill) {
                        if ($bill->議案編號 ?? false) {
                            $billNos[] = $bill->議案編號;
                        }
                    }
                }
                $meet->議事網資料 = $billNos;
                foreach ($meet->日期 as $d) {
                    if (!array_key_exists($d, self::$_meet_cache[$year_month])) {
                        self::$_meet_cache[$year_month][$d] = [];
                    }
                    self::$_meet_cache[$year_month][$d][] = $meet;
                }
            }
        }

        // 篩選種類
        $meets = self::$_meet_cache[$year_month][$date] ?? [];
        $meets = array_filter($meets, function($meet) use ($type) {
            if ($type == '院會') {
                return (explode('-', $meet->會議代碼)[0] == '院會');
            } elseif ($type == '黨團協商') {
                return (explode('-', $meet->會議代碼)[0] == '黨團協商');
            } else {
                $committees = explode(',', $type);
                foreach ($committees as $committee) {
                    if (!in_array($committee, $meet->{'委員會代號:str'} ?? [])) {
                        return false;
                    }
                }
                return true;
            }
            return true;
        });

        // 排序最吻合的
        usort($meets, function($a, $b) use ($type) {
            if (in_array(explode('-', $a->會議代碼)[0], ['院會', '黨團協商'])) {
                return -1;
            }
            $count_a = count($a->{'委員會代號:str'});
            $count_b = count($b->{'委員會代號:str'});
            $count_ans = count(explode(',', $type));
            return ($count_a - $count_ans) - ($count_b - $count_ans);
        });
        return array_values($meets);
    }
}
