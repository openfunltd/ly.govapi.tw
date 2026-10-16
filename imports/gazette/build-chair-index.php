<?php
/**
 * build-chair-index.php
 * 掃 agenda-txt 裡所有「會議紀錄」文件的標頭，解析出「這場會議的主席是誰」，
 * 輸出成查找用的索引檔 chair-index.json，key 是 "{meet_id}:{date}"，供
 * IVodParser::getSessionChair() 讀取。
 *
 * 院會、委員會的公報標頭格式相同：
 *   立法院第9屆第4會期教育及文化委員會第10次全體委員會議紀錄
 *   時　　間　中華民國106年11月20日（星期一）9時1分至17時29分
 *   主　　席　張廖委員萬堅
 *
 * 同一天可能換手不只一位主席（例如院長主持一段、交給副院長代理一段後再
 * 換回來），標頭會列出當天所有出現過的主席，如：
 *   主　　席　韓院長國瑜
 *
 *   江副院長啟臣
 *   秘書長　周萬來
 * 這裡會把「主席」到「秘書長」之間的每一行都當成候選主席解析，一天可能
 * 對應到 2 位（甚至更多）人選；chair-index.json 每個 key 存的是陣列。
 * 無法從標頭取得確切的換手時間點，所以 is_chair 判斷只能做到「當天名單
 * 內」的粒度，不到分鐘級的精確對應。
 *
 * 標題行直接丟給既有的 LYLib::meetNameToId() 解析（跟 IVodParser 解析 IVOD
 * 會議名稱用同一套邏輯、同一套委員會代碼對照表），確保這裡產生的 meet_id
 * 跟 ivod-data 裡 meet.id 的格式完全一致（院會不需要委員會代碼消歧義，
 * 委員會則會自動帶出正確的委員會代碼，如「委員會-11-5-22-29」）。
 *
 * 用法：
 *   php build-chair-index.php
 */

include(__DIR__ . '/../../init.inc.php');

$base = __DIR__ . '/agenda-txt';
$TITLE_RE = '/委員|院長|部長|署長|局長|主委|主任委員|副主任委員|代理主任委員|' .
            '秘書長|副秘書長|司長|處長|次長|副部長|副院長|副署長|副局長|副主委|' .
            '總裁|副總裁|總長|主計長|組長|參事|顧問|專委|執行長|總經理|董事長|' .
            '理事長|檢察總長|代理董事長|代理署長|代理秘書長|代理司長|代理局長/u';

$index = [];
$stats = ['scanned' => 0, 'has_header' => 0, 'meet_resolved' => 0, 'chair_parsed' => 0];

foreach (glob("{$base}/*.doc") as $path) {
    $stats['scanned']++;
    // mb_substr（非 substr）：中文為多位元組，按位元組切會切到字元中間，
    // 產生無效 UTF-8，導致後面所有 /u regex 整段都比對不到（此檔沒抓到主席的根因）
    $head = mb_substr(file_get_contents($path), 0, 300, 'UTF-8');

    if (!preg_match('/^立法院第\d+屆[^\n]*$/mu', $head, $tm)) continue;
    if (!preg_match('/時\s*間\s*中華民國(\d+)年(\d+)月(\d+)日/u', $head, $dm)) continue;
    // 「主席」到「秘書長」（院會）或下一個「主席：」發言開頭（委員會沒有秘書長
    // 這一行）之間的每一行都是候選主席（可能不只一位，見上方說明）
    if (!preg_match('/主\s*席\s+(.+?)(?:秘書長|主席[：:])/su', $head, $block_m)) continue;
    $stats['has_header']++;

    $title_line = trim($tm[0]);
    try {
        $meet = LYLib::meetNameToId($title_line);
    } catch (Exception $e) {
        continue;
    }
    if (!isset($meet->id)) continue;
    $stats['meet_resolved']++;

    $date_str = sprintf('%04d-%02d-%02d', intval($dm[1]) + 1911, intval($dm[2]), intval($dm[3]));

    $chairs = [];
    foreach (preg_split('/\n+/u', trim($block_m[1])) as $raw) {
        $raw = trim($raw);
        if ($raw === '') continue;
        if (!preg_match($TITLE_RE, $raw, $tmatch, PREG_OFFSET_CAPTURE)) continue;
        $pos     = mb_strpos($raw, $tmatch[0][0]);
        $surname = mb_substr($raw, 0, $pos);
        $given   = mb_substr($raw, $pos + mb_strlen($tmatch[0][0]));
        $given   = preg_replace('/代理$/u', '', $given);  // 「吳委員思瑤代理」→ 代理主席，姓名本身不含「代理」
        if (!$surname || !$given) continue;
        // 少數委員會首次會議現場推選主席（無預填姓名），格式跟一般標頭不同，
        // 會擷取到整句話而非姓名，用長度擋掉這種雜訊（真實姓名不會這麼長）
        if (mb_strlen($surname . $given) > 10) continue;
        $chairs[] = ['label' => $raw, 'person_name' => $surname . $given];
    }
    if (!$chairs) continue;
    $stats['chair_parsed']++;

    $key = "{$meet->id}:{$date_str}";
    $index[$key] = $chairs;
}

file_put_contents(__DIR__ . '/chair-index.json', json_encode($index, JSON_UNESCAPED_UNICODE));
echo json_encode($stats, JSON_UNESCAPED_UNICODE) . ", index_size=" . count($index) . "\n";
