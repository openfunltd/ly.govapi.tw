<?php

include(__DIR__ . '/../../init.inc.php');
$fp = fopen(__DIR__ . '/meet.jsonl', 'r');
while ($line = fgets($fp)) {
    $meet = json_decode($line);
    if ($meet->meetingNo == '2014051608') {
        $meet->meetingName = '立法院第8屆第5會期交通委員會第12次全體委員會議';
    } elseif ($meet->meetingNo == '2014051501') {
        $meet->meetingName = '立法院第8屆第5會期經濟、財政、內政三委員會第4次聯席會議';
    }
    if (in_array($meet->meetingName, [
        "本會101年4月20日台立社字1014500309號開會通知單原訂第8屆第1會期社會福利及衛生環境委員會第13次全體委員會",
        '本會101年5月29日台立社字1014500700號開會通知單原訂第8屆第1會期社會福利及衛生環境委員會第25次全體委員會',
    ])) {
        continue;
    }
    try {
        $meet = LYLib::filterMeetData($meet);
    } catch (Exception $e) {
        print_r($meet);
        readline('press any key to continue :');
        continue;
    }
    Elastic::dbBulkInsert('meet', "{$meet->date}:{$meet->meetingNo}", $meet);
}
Elastic::dbBulkCommit();
