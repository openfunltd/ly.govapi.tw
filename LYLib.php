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

        return $meet;
    }

    public static function meetNameToId($oname)
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
        $name = preg_replace('#「.*」#', '', $name);
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
            if ($matches[1] >= 125) {
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
        throw new Exception("{$oname} 有問題");
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

    public static function buildMeet($source)
    {
        if (is_array($source->meet_data) and count($source->meet_data)) {
            foreach ($source->meet_data as $idx => $meet_data) {
                if (strlen($meet_data->meetingNo) < 15) {
                    $meet_data->ppg_url = sprintf("https://ppg.ly.gov.tw/ppg/sittings/%s/details?meetingDate=%d/%02d/%02d",
                        $meet_data->meetingNo,
                        date('Y', strtotime($meet_data->date)) - 1911,
                        date('m', strtotime($meet_data->date)),
                        date('d', strtotime($meet_data->date))
                    );
                }
                $source->meet_data[$idx] = $meet_data;
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
        if (file_exists($dir . "/htmlfile/{$basename}")) {
            $pics = json_decode(file_get_contents($dir . "/htmlfile/{$basename}"))->pics;
            if ($pics) {
            }
            return $pics;
        }
        $cmd = sprintf("curl -X POST -F %s -F \"output_type=html\" https://soffice.ronny.tw/", escapeshellarg('file=@' . $file));
        $fp = popen($cmd, 'r');
        $base = basename($file);
        $images = new StdClass;
        $ret = new StdClass;
        while ($line = fgets($fp)) {
            if (!$obj = json_decode($line)) {
                echo $line;
                echo 'error line';
                exit;
            }
            if ($obj[0] == 'attachments') {
                $attachment = $obj[1];
                $img_name = explode('_html_', $attachment->file_name)[1];
                file_put_contents($dir . '/picfile/' . $base . '-' . $img_name, base64_decode($attachment->content));
                //S3Lib::put(__DIR__ . "/picfile/{$basename}-{$img_name}", "data/picfile/{$basename}-{$img_name}");
                //unlink(__DIR__ . "/picfile/{$basename}-{$img_name}");

                $images->{$img_name} = true;
            } elseif ($obj[0] == 'content') {
                $ret->content = $obj[1];
                $content = base64_decode($obj[1]);
                preg_match_all('#<img src="([^"]+)"[^>]*"#', $content, $matches);
                $pics = [];
                foreach ($matches[1] as $idx => $file_name) {
                    $img_name = explode('_html_', $file_name)[1];
                    if (!preg_match('/width="(\d+)" height="(\d+)"/', $matches[0][$idx], $matches2)) {
                    }
                    $pics[] = [$img_name, $matches2[1], $matches2[2], $idx];
                }
                $ret->pics = $pics;
            } else {
                $ret->{$obj[0]} = $obj[1];
            }
        }

        file_put_contents($dir . "/htmlfile/{$basename}", json_encode($ret));
        return $pics;
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
            system(sprintf("curl --request POST --url https://unoserver.openfun.dev/request --header 'Content-Type: multipart/form-data'  --form file=@%s --form 'convert-to=docx' --output %s", escapeshellarg($agenda_docfile), escapeshellarg(__DIR__ . '/tmp.docx')), $ret);
            clearstatcache();
            if (filesize(__DIR__ . '/tmp.docx') < 1000) {
                copy(__DIR__ . "/tmp.docx", $agenda_docxfile);
                touch($agenda_htmlfile);
                throw new Exception("to docx error: $agenda_docfile: " . file_get_contents(__DIR__ . '/tmp.docx'));
            }
            copy(__DIR__ . "/tmp.docx", $agenda_docxfile);
            unlink(__DIR__ . "/tmp.docx");
            error_log("to docx done: " . ($agenda_docxfile));
        }
        if (!file_exists($agenda_htmlfile)) {
            error_log("to html $agenda_docxfile");
            system(sprintf("curl -T %s https://tika.openfun.dev/tika -H 'Accept: text/html' > %s", escapeshellarg($agenda_docxfile), escapeshellarg(__DIR__ . '/tmp.html')), $ret);
            if ($ret) {
                print_r($agenda);
                throw new Exception('curl failed');
            }
            copy(__DIR__ . "/tmp.html", $agenda_htmlfile);
            unlink(__DIR__ . "/tmp.html");
        }
        return $agenda_htmlfile;
    }
}
