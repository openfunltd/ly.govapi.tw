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
                self::$_committeeIdMap[$comtName] = $comtCd;
            }
            self::$_committeeIdMap['社會福利及衛生環境'] = 37;
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
        if (!property_exists($meet, 'alias')) {
            $meet->alias = [];
        }
        if (!property_exists($meet, 'committees')) {
            $meet->committees = [];
        }
        if ($meet->attendLegislator == '') {
            $meet->attendLegislator = [];
        } else {
            $meet->attendLegislator = explode(',', $meet->attendLegislator); 
        }
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
            } else {
                echo $meet->meetingRoom . "\n";
                exit;
            }
            $meet->meetingType = '其他會議'; // Ex: 公聽會 記者會 座談會 等等
            return $meet;
        }

        try {
            $l = LYLib::meetNameToId($meet->meetingName, $type, $committees);
            $meet->meetingType = $type;
            $meet->committees = $committees;
        } catch (Exception $e) {
            if (strpos($meet->meetingName, '黨團協商') !== false) {
                $meet->meetingType = '黨團協商';
                return $meet;
            }
            if (strpos($meet->meetingContent, '考察') !== false or
                strpos($meet->meetingContent, '公聽會') !== false
            ) {
                $meet->meetingType = '其他會議';
                return $meet;
            }
            // TODO: log it
            $meet->meetingType = '其他會議';
            return $meet;
            throw new Exception('meetNameToId error');
        }

        if (is_null($l)) {
            if (trim($meet->meetingName) == '') {
                $meet->meetingName = $meet->meetingContent;
            }

            if (strpos($meet->meetingName, '黨團協商') !== false) {
                $meet->meetingType = '黨團協商';
                return $meet;
            }
            if (strpos($meet->meetingName, '公聽會') !== false or 
                strpos($meet->meetingName, '參訪') !== false or
                strpos($meet->meetingName, '考察') !== false
            ) {
                $meet->meetingType = '其他會議';
                return $meet;
            }
            // TODO: log it
            $meet->meetingType = '其他會議';
            return $meet;
            throw new Exception('meetNameToId error');
        } else {
            $meet->alias[] = $l;
        }
        return $meet;
    }

    public static function meetNameToId($oname, &$type, &$committees)
    {
        $committees = [];
        $name = $oname;
        $name = str_replace(' ', '', $name);
        $name = str_replace('：', '', $name);
        $name = str_replace(':', '', $name);
        $name = preg_replace('#（.*）$#', '', $name);
        $name = preg_replace('#\(.*\)$#', '', $name);
        $name = preg_replace('#【.*】$#', '', $name);
        $name = preg_replace('#「.*」#', '', $name);
        $name = preg_replace('#議事日程$#', '', $name);
        $name = str_replace('第六次', '第6次', $name);
        // 第8屆第5會期第4次會議 -> all-8-5-4
        // 第8屆第1會期第1次全院委員會會議 -> all-8-1-1
        // 第8屆第1會期第1次臨時會第1次會議 -> temp-8-1-1-1
        // 立法院第8屆第5會期交通委員會第4次全體委員會議 -> committee-8-5-23-4
        // 立法院第8屆第1會期財政、經濟委員會第1次聯席會議 -> committees-8-1-19,20-1
        // 第8屆第4會期第2次全院委員會議
        if (preg_match('/^第(\d+)屆第(\d+)會期第(\d+)次(全院委員會?)?會議$/u', $name, $matches)) {
            $type = '全院委員會';
            return 'all-' . $matches[1] . '-' . $matches[2] . '-' . $matches[3];
        } elseif (preg_match('/^第(\d+)屆第(\d+)會期第(\d+)次全體委員會$/u', $name, $matches)) {
            $type = '全院委員會';
            // 第8屆第5會期第18次全體委員會
            return 'all-' . $matches[1] . '-' . $matches[2] . '-' . $matches[3];
        }

        if (preg_match('/^第(\d+)屆第(\d+)會期第(\d+)次臨時會第(\d+)次(全院委員會)?會議$/', $name, $matches)) {
            $type = '全院委員會';
            return 'tempall-' . $matches[1] . '-' . $matches[2] . '-' . $matches[3] . '-' . $matches[4];
        }
        // 立法院第8屆第2會期財政委員會第5次全體委員會議
        // 立法院第8屆第5會期第2次臨時會內政委員會第1次全體委員會議
        // 立法院第8屆第6會期財政委員會第6次全體委員會議
        // 立法院第9屆第1會期社會福利及衛生環境委員會31次全體委員會議
        if (preg_match('/^立法院第(\d+)屆第(\d+)會期(第(\d+)次臨時會)?([^第0-9]*)第?(\d+)次全體委員會議?/u', $name, $matches)) {
            $committeeIdMap = self::getCommitteeIdMap();
            try {
                $committee_id = self::getCommitteeId($matches[5]);
                $type = '委員會';
                if ($matches[3]) {
                    return 'tempcommittee-' . $matches[1] . '-' . $matches[2] . '-' . $matches[4] . '-' . $committee_id . '-' . $matches[6];
                }
                $committees[] = $committee_id;
                return 'committee-' . $matches[1] . '-' . $matches[2] . '-' . $committee_id . '-' . $matches[6];
            } catch (Exception $e) {
            }
        }
        // 立法院第8屆修憲委員會第1次全體委員會議
        if (preg_match('/^立法院第(\d+)屆([^第]*)委員會第(\d+)次全體委員會議?/u', $name, $matches)) {
            $committeeIdMap = self::getCommitteeIdMap();
            $type = '委員會';
            try {
                $committee_id = self::getCommitteeId($matches[2]);
                $committees[] = $committee_id;
                return 'committee-' . $matches[1] . '-' . $committee_id . '-' . $matches[3];
            } catch (Exception $e) {
            }
        }
        // 立法院第8屆第5會期第1次臨時會經濟、財政、內政三委員會第1次聯席會議
        // 立法院第8屆第6會期社會福利及衛生環境、司法及法制二委員會第1次聯席會議
        // 立法院第9屆第4會期社會福利及衛生環境及經濟二委員會第1次聯席會議
        if (preg_match('/^立法院第(\d+)屆第(\d+)會期(第(\d+)次臨時會)?(.*)第(\d+)次(聯席|全體委員)會議?/u', $name, $matches)) {
            $type = '聯席會議';
            $committeeIdMap = self::getCommitteeIdMap();
            $committee_ids = [];
            foreach (explode('、', $matches[5]) as $committee_name) {
                $committee_name = str_replace('兩委員會', '', $committee_name);
                $committee_name = str_replace('二委員會', '', $committee_name);
                $committee_name = str_replace('三委員會', '', $committee_name);
                $committee_name = str_replace('六委員會', '', $committee_name);
                $committee_name = str_replace('８委員會', '', $committee_name);
                $committee_name = str_replace('8委員會', '', $committee_name);
                $committee_ids[] = self::getCommitteeId($committee_name);
            }
            if (count($committee_ids) < 2) {
                throw new Exception("{$name} 有問題");
            }
            sort($committee_ids);
            $committees = $committee_ids;
            if ($matches[3]) {
                return 'tempcommittees-' . $matches[1] . '-' . $matches[2] . '-' . $matches[4] . '-' . implode(',', $committee_ids) . '-' . $matches[6];
            }
            return 'committees-' . $matches[1] . '-' . $matches[2] . '-' . implode(',', $committee_ids) . '-' . $matches[6];
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
        throw new Exception("{$oname} 有問題");
    }
}
