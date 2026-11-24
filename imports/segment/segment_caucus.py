#!/usr/bin/env python3
"""
黨團協商切分（第一版，2026-10-02）。

採用的單位（見 findings/caucus-negotiation/NEEDS-HUMAN-CHECK.md）：
  session  S-A：一場會議紀錄＝一個 session（以公報的「立法院第○屆第○會期黨團協商會議紀錄」標題切，
           不是以文件切——一份文件可能裝好幾場）。
           〔A1 待 Ronny 拍板。S-A 是三個選項的共同底層：S-B 在 S-A 裡再切、S-C 把 S-A 合起來，選哪個都用得到〕
  section  被主席點名的人＋之後的往返，到下一次點名或換題為止（比照公聽會 2026-09-19 的 section 定義）。
           主席換題（換案、換條）也切 section；換案／換條／輪次／結論／無共識／簽名一律做成 section 上的**標籤**，
           不另切一層。〔A2 ✅ James 2026-10-02 確認，比照院會「階段改成標籤」〕
  程序協商  院長主持、談院會議程或時程的協商：同一套切法，session 加「程序協商」標籤。〔A5 ✅ 2026-10-02 確認〕
  書面結論  散會後的「立法院（朝野）黨團協商結論」頁＋簽名 → attachment，不切 section。〔A6 ✅ 2026-10-02 確認 (c)〕

發言人判斷：findings/caucus-negotiation/scripts/negotiation.py 的 speaker_paras()（底層是 scripts/speakers.py）。

用法：
  python3 scripts/segment_caucus.py <lcidc>                 # 單份文件，印 JSON
  python3 scripts/segment_caucus.py --check                 # 全部已快取的協商文件跑一遍，印統計與警告
  python3 scripts/segment_caucus.py --browser OUT [lcidc…]  # 給人看的預覽頁（預設：每屆抽樣 10 份＋深讀 4 份＋B1 兩份）
  python3 scripts/segment_caucus.py --images                # 對有書面結論頁的文件抓 /txt，數結論本文是不是圖（B3）
  python3 scripts/segment_caucus.py --viewer OUT [lcidc…]   # Ronny 版閱覽器（左側列表、右側逐字稿），預設全部 343 份
"""
import argparse, collections, html, importlib.util, json, os, re, sys, time, urllib.request
from urllib.parse import quote

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
_spec = importlib.util.spec_from_file_location(
    "negotiation", os.path.join(HERE, "negotiation.py"))
NG = importlib.util.module_from_spec(_spec); _spec.loader.exec_module(NG)
SPKMOD = NG.SPK

CN = "一二三四五六七八九十百零〇"
A = {n: re.compile(r) for n, r, _ in NG.ANCHORS}
DEEP = ["1049805_00004", "1086006_00005", "1100602_00008", "1149701_00014",   # README 第 2 節深讀
        "1024701_00013", "1045102_00009"]                                       # B1（2026-10-02 讀過全文）

# 主席點名：「請○○發言」「請監察院劉副秘書長說明」「先請提案的…張啓楷委員…」
R_CALL = re.compile(r'請[^。？]{0,40}?(?:發言|說明|表示意見|表達意見|補充|回應|報告|答復|答覆|講話|表示一下)')
# 主席換題：換案／換條（negotiation.py 的 T1、T2）＋ 用法律名稱換案（「接下來處理大陸委員會組織法草案」「繼續審查…設置條例第四條」）
# 「法」要是法律名稱的結尾，不收「立法／辦法／做法／看法／說法／合法／違法」（1149701_00014 [25]「來做立法的工作」）
OBJ = rf'(?:第[{CN}\d]+(?:個)?(?:案|協商主題|主題|個案子|條)|(?<![立方辦做看想說合違])法|條例|通則|規則|草案|部分|主題|案子)'
R_TOPIC = re.compile(
    # 往下走的字＋換題動詞＋對象：「接下來處理大陸委員會組織法草案」「繼續審查…設置條例第四條」
    rf'(?:接下來|再來|接著|繼續|下一個|首先|先來)[^。？]{{0,8}}(?:進行|處理|討論|審查|針對|進入|協商)[^。？]{{0,40}}{OBJ}'
    # 「現在針對海洋委員會組織法的部分進行協商」；不收「現在我們在討論的是國家公園法」（提醒目前議題，不是換題）
    rf'|現在(?:針對|進行|處理|進入)[^。？]{{0,40}}{OBJ}'
    # 序數 ≥2：「我們現在協商的第二個案子是有關發展觀光條例…」（1149701_00014）
    rf'|(?:^|[。！？])[^。，]{{0,12}}?第[二三四五六七八九十]+個?(?:案子|協商主題|主題|案)(?:是|：)'
    # 「首先，我要請教各位委員，有關勞動部…」「再來，有關內政部組織法修正草案的部分」（1024701_00013）
    rf'|(?:首先|再來|接下來|接著)[，,]?[^。？]{{0,16}}有關[^。？]{{2,40}}(?:法|條例|草案|部分)'
    # 「處理第二十四條。」「處理第二十八條之一。」（1045102_00009，主席逐條處理時的短句）
    rf'|^(?:現在)?(?:處理|進行|討論|審查)第[{CN}\d]+條(?:之[{CN}\d]+)?[。，]?$')
R_OPEN = re.compile(r'現在開會|開始開會|開始協商')       # 開場白裡的「今天我們協商…」不是換題
R_INTERRUPTED = re.compile(r'(?:……|…|⋯⋯)\s*$')        # 話被打斷的換題句（1024701_00013 [158][176]），後面其實還在談上一案
R_LAW = re.compile(r'「([^」]{2,40})」|([一-鿿、]{2,30}?(?:組織法|條例|通則|法|預算案))')


R_LAWKEY = re.compile(r'([一-鿿]{2,16}?(?:組織法|條例|通則|法))(?=第|草案|修正|條文|部分|的|，|。|、|$)')


def law_key(sentence):
    """換題句裡的法律名稱，用來判斷主席是不是在重申同一案（1149701_00014 [103] 與 [118] 都在講發展觀光條例）。"""
    m = R_LAWKEY.search(sentence)
    if not m:
        return None
    return re.sub(r'^.*(?:是|有關|針對|處理|討論|審查|進行|就是|關於)', '', m.group(1)) or m.group(1)


def topic_name(sentence):
    rest = re.sub(r'^.*?(?:處理|討論|針對|審查|有關|進行|進入)(?:的是|一下)?', '', sentence) or sentence
    m = R_LAW.search(rest)
    return (m.group(1) or m.group(2)) if m else rest.strip("，, ")[:24]


R_ARTICLE = re.compile(rf'第[{CN}\d]+條(?:之[{CN}\d]+)?')
R_READ_NOW = re.compile(r'(?:宣讀|報告|唸|念)[^。，？]{0,14}結論|結論(?:草稿)?(?:如下)?[：:]|結論如下|[做作]成(?:協商)?結論如下|試擬結論'
                        r'|(?:本案|今天的?|今日|最後的?)協商結論(?:是|為)(?!不是)')
R_READ_CONC = re.compile(r'協商結論(?:如下|是|為|：)|[做作]成(?:協商)?結論|試擬結論|宣讀[^。]{0,8}結論')
# A5：程序協商＝談院會議程或時程，不是法案本身。「研商『○○法草案』相關事宜」是院長主持法案協商的固定用語，不算
R_PROC = re.compile(r'議程|時程|開議|臨時會|延會|延長會期|停開|院會[^。]{0,10}(?:進行|處理)方式|審查程序|行使同意權')
# 散會：「散會（16時29分）」或一句話以「散會」收尾。只寫「散會」會命中話中順帶提到的散會（1071801_00009 開了 12 小時，誤判後 472 段被算成散會之後）
R_ADJ = re.compile(r'散會[（(]\s*\d|散會[。！!]?\s*(?:謝謝[^。]{0,8}[。！!]?\s*)?$')
R_ADJ_LINE = re.compile(r'^散會[（(]\s*\d')
R_SAYLIKE = re.compile(r'[。？！]')
R_DOCHEAD = re.compile(r'共同聲明|附錄|附件|更\s*正\s*本|黨團協商結論')
R_TIME = re.compile(r'(上午|中午|下午|晚上)?(\d{1,2})時(?:(\d{1,2})分)?')
R_DATE = re.compile(r'(\d{2,3})年(\d{1,2})月(\d{1,2})日')


def is_say(who):
    return bool(who) and not who.startswith("〔")


def minutes(time_line):
    """「時　間　中華民國104年5月12日（星期二）12時45分至13時48分」→ 63。抓不到回 None。"""
    t = R_TIME.findall(time_line.split("日", 1)[-1])
    if len(t) < 2:
        return None
    def m(x):
        ap, h, mi = x
        h = int(h) + (12 if ap in ("下午", "晚上") and int(h) < 12 else 0)
        return h * 60 + int(mi or 0)
    d = m(t[-1]) - m(t[0])
    return d if d >= 0 else None


def header(rec):
    """會議紀錄的標頭：標題、時間、主席、協商主題（逐項）。"""
    h = {"標題": "", "時間": "", "主席": "", "協商主題": []}
    on = False
    for who, t in rec:
        if is_say(who):
            break
        if A["S1 會議紀錄標題"].match(t) and not h["標題"]:
            h["標題"] = t
        elif (A["S2 時間行"].match(t) or re.match(r'^時[\s　]*間', t)) and not h["時間"]:   # 「時　　間中華民國107年…」間後沒空白（1079902_00013）
            h["時間"] = t
        elif (A["S3 主席行"].match(t) or re.match(r'^主\s*持\s*人[\s　]', t)) and not h["主席"]:
            h["主席"] = re.sub(r'^主\s*(?:席|持\s*人)[\s　]+', '', t)   # 「主 持 人　蘇院長嘉全」（10610801_00013）
        elif t.startswith("協商主題"):
            on = True
            body = t[4:].strip(" 　：:")
            if body:
                h["協商主題"].append(body)
        elif on and t:
            h["協商主題"].append(t)
    # 「（壹）：一、…」這種兩層編號只是排版，逐項保留原文
    return h


# ── 主持層級、委員會、非協商代表發言（2026-10-05 James 要求）──────────────
# 紀錄標頭只有時間、地點、主席、協商主題，標題一律「立法院第N屆第N會期黨團協商會議紀錄」，不寫委員會。
# 委員主持的場次：委員會取主席在該屆該會期所屬的常設委員會（立法院 API /legislators/{屆}/{姓名} 的「委員會」欄）；
# 協商主題、主席開場白直接寫出的委員會只能抽到 49/172 場，所以只當備援。
STANDING = ["內政", "外交及國防", "經濟", "財政", "教育及文化", "交通", "司法及法制", "社會福利及衛生環境"]
R_TERM_SESS = re.compile(r'第(\d+)屆第(\d+)會期')


def person_names(label):
    """主席欄可能有兩位：「羅委員智強、黃委員國昌」→ [羅智強, 黃國昌]（第 9–11 屆共 4 場）。
    「張委員慶忠（吳委員育昇代理）」→ [張慶忠]（括號裡的代理人不算）；「鄭天財Sra Kacaw委員」→ [鄭天財Sra Kacaw]"""
    out = []
    for part in re.split(r'[、]', re.sub(r'[（(].*$', '', label or "")):
        part = part.strip().rstrip("）)")     # non_rep_speakers() 切「（吳委員育昇代理）」後會留下「吳委員育昇）」
        m = re.match(r'^(.{1,2}?)委員(.+)$', part) or re.match(r'^(.+?)委員()$', part)
        if m and m.group(1) + m.group(2) not in out:
            out.append(m.group(1) + m.group(2))
    return out


def person_name(label):
    """單一發言人或主席欄的第一位：「陳委員鎮湘」→ 陳鎮湘。主席欄要全部的人用 person_names()。"""
    names = person_names(label)
    return names[0] if names else None


def committees_text(t):
    """協商主題／開場白直接寫出的常設委員會：「本院經濟、財政兩委員會」「本（經濟）委員會」"""
    found = []
    for pat in (r'本院([一-鿿、及]{2,40}?)(?:[兩二三四五六七八]個?)?委員會', r'本[（(]([一-鿿、及]{2,30})[）)]委員會'):
        for m in re.finditer(pat, t):
            for part in m.group(1).split("、"):
                if part.strip() in STANDING and part.strip() not in found:
                    found.append(part.strip())
    return found


def legislator(term, name):
    """立法院 API 的委員資料，快取檔名保留中文（共用 fetch() 會把中文換成底線，同屆三字姓名全撞成同一檔）。"""
    key = os.path.join(NG.CACHE, f"legislator_{term}_{name}.json")
    if os.path.exists(key):
        return json.load(open(key, encoding="utf-8"))
    for a in range(6):
        try:
            req = urllib.request.Request(f"{NG.API}/legislators/{term}/{quote(name)}", headers={"User-Agent": "research-ly-meet/1.0"})
            d = json.loads(urllib.request.urlopen(req, timeout=60).read())
            json.dump(d, open(key, "w", encoding="utf-8"), ensure_ascii=False)
            time.sleep(1.5)          # 節流：API 會回 429
            return d
        except Exception as e:
            if a == 5:
                print(f"  [legislator 失敗] {term} {name} → {e}", file=sys.stderr)
                return None
            time.sleep(5 * (a + 1))


def chair_committees(chair, title):
    """主席在該屆該會期所屬的常設委員會（API）。主席欄有兩位時兩位都查。
    回傳 (去重後的委員會, {姓名: [委員會] 或 None＝API 查不到})；屆會期或姓名抓不到回 (None, {})。"""
    m = R_TERM_SESS.search(title or "")
    names = person_names(chair)
    if not m or not names:
        return None, {}
    key = f"第{m.group(1)}屆第{m.group(2)}會期："
    per, union = {}, []
    for name in names:
        d = legislator(m.group(1), name)
        coms = ((d or {}).get("data") or {}).get("委員會") or []
        if isinstance(coms, str):
            coms = re.findall(r"'([^']+)'", coms)
        got = [c[len(key):-3] for c in coms if c.startswith(key) and c.endswith("委員會") and c[len(key):-3] in STANDING]
        per[name] = got or None
        union += [c for c in got if c not in union]
    return union or None, per


def host_info(h, rec):
    """院長主持／委員主持，以及主席所屬委員會（委員主持才有）。議案審查委員會另見 review_info()。"""
    ch = h.get("主席") or ""
    if "院長" in ch:
        return {"主持層級": "院長主持", "委員會": [], "委員會依據": "", "主席委員會明細": {}}
    if not ch:
        return {"主持層級": "（無主席欄）", "委員會": [], "委員會依據": "", "主席委員會明細": {}}
    api, per = chair_committees(ch, h.get("標題"))
    if api:
        return {"主持層級": "委員主持", "委員會": api, "委員會依據": "主席該會期所屬委員會", "主席委員會明細": per}
    said = " ".join(t for w, t in rec if w == "主席" and is_say(w))[:1500]
    txt = committees_text(" ".join(h.get("協商主題") or [])) or committees_text(said)
    return {"主持層級": "委員主持", "委員會": txt, "委員會依據": "協商主題／開場白" if txt else "查不到",
            "主席委員會明細": per}


# ── 議案審查委員會（2026-10-05 James C1）────────────────────────────────
# 「主席所屬委員會」≠「議案是哪個委員會審查的」（1086006_00007 趙天麟屬外交及國防，營造業法是內政委員會審查）。
# 依據各自標出來：①②③＋④的審查報告 取聯集；都沒有才用 ④的個別議案；再沒有才用 ⑤（推定）。
#   ① 協商主題文字：「本院司法及法制、內政兩委員會報告併案審查…」「一、協商司法及法制委員會併案審查…」——紀錄本身寫的。
#   ② API 協商會議的承辦委員會：立法院 API 黨團協商 meet 的「委員會代號:str」（會議單位「朝野黨團協商(內政委員會)」）。
#      只有委員主持的場次有；聯席審查時只列一個（主審）委員會。
#   ③ API 議案：協商 meet 的「議事網資料.關係文書.議案」逐案查——標題是「本院○○委員會報告…」就直接取，
#      否則查 /bills/{議案編號} 的「議案流程」（「排入院會 (交財政委員會)」、委員會審查那幾步的「院會/委員會」）。
#      逕付二讀、沒交付委員會的議案不算委員會，另外記下來。
#   ④⑤ 見下方 law_link()、law_infer()。
#   有直接依據（①～④）的場次，協商主題寫出、但直接依據沒涵蓋到的法律，另跑 ⑤ 推定（supplement_infer()），
#   推定多出的委員會另列一行「其他法律的審查委員會（推定）」，不併進「議案審查委員會」；
#   預算、人事、程序等不是法案的項目也在那一行註明「沒有審查委員會可列」，免得看起來像完整答案。
# 公報紀錄 ↔ API 協商 meet 的對應：同一天（紀錄標頭「時間」行的日期 ∈ meet「日期」）＋協商主題文字相似
# （兩字詞重疊係數 ≥0.5、重疊 ≥3 個；同一天有好幾場時要和第二名拉開）。對不上就不配，不用公報目錄日期（那是刊登日）。
# 第 8 屆 API 沒有黨團協商 meet（total=0），只剩①④⑤。
# 〔不用 meet「議事網資料.連結」裡的「公報紀錄」LCIDC 檔號當對應鍵：和本 repo 的 343 份協商紀錄只交集 78 份，
#   而且其中 37 份指向的是隔一場（日期不同）的協商 meet，像是錯一格；同日＋主題比對在這 37 份都對到同一天、重疊係數 ≥0.89 的 meet。〕
R_COM_ALT = "|".join(STANDING + ["社福"])
COM_ABBR = {"社福": "社會福利及衛生環境"}
R_REVIEW_TXT = re.compile(
    rf'((?:(?:{R_COM_ALT})、?)+)(?:[兩二三四五六七八]個?)?委員會(?:(?:報告)?(?:併案)?審查|於[^。，；]{{0,12}}審查|會議)')
R_REPORT_TITLE = re.compile(rf'^本院((?:(?:{R_COM_ALT})、?)+)(?:[兩二三四五六七八]個?)?委員會(?:報告|函送)')
R_REFER = re.compile(rf'交((?:(?:{R_COM_ALT})、?)+)(?:[兩二三四五六七八]個?)?委員會')
REVIEW_SRC = {"text": "協商主題文字", "unit": "API 協商會議承辦委員會", "bill": "API 協商會議的議案",
              "report": "API 委員會審查報告（案名列有協商主題的提案）",
              "law": "API 議案（議案流程記有這場協商，或提案人與法案名稱相同）",
              "infer": "推定（依協商主題的法律與協商日期）"}


def split_coms(s):
    out = []
    for p in (s or "").split("、"):
        p = COM_ABBR.get(p.strip(), p.strip())
        if p in STANDING and p not in out:
            out.append(p)
    return out


def review_committees_text(t):
    """協商主題裡「○○委員會（報告）（併案）審查」「○○、○○兩委員會於12月4日審查」寫出的審查委員會；
    「研商勞動基準法修正審查相關事宜（105年10月31日社會福利及衛生環境委員會會議…）」（1057701_00011）也算。
    不收「向本院經濟委員會提出全面檢討報告」「經本院經濟委員會審議通過前」（1133501_00013 決議文內文）、
    「修憲委員會」「程序委員會」「董、監事審查委員會」（不是常設委員會）。"""
    found = []
    for m in R_REVIEW_TXT.finditer(re.sub(r'\s', '', t or "")):
        found += [c for c in split_coms(m.group(1)) if c not in found]
    return found


_MEETS = None
STOP_BIGRAMS = {"委員", "條文", "修正", "草案", "審議", "研商", "相關", "事宜", "黨團", "提議", "分別", "擬具", "本院",
                "併案", "審查", "報告", "繼續", "協商"}


def caucus_meets_by_date():
    """立法院 API 第 9–11 屆黨團協商 meet，依日期索引（negotiation.meets()，查詢參數經 urlencode，快取檔名保留 %XX，不會撞檔；已全數快取）。"""
    global _MEETS
    if _MEETS is None:
        _MEETS = collections.defaultdict(list)
        for m in NG.meets():
            for d in m.get("日期") or []:
                _MEETS[d].append(m)
    return _MEETS


def meet_bills(m):
    return [b for x in m.get("議事網資料") or [] for b in ((x or {}).get("關係文書") or {}).get("議案") or []
            if b.get("議案編號")]


def meet_text(m):
    t = " ".join((x or {}).get("內容") or "" for x in m.get("議事網資料") or []) \
        or " ".join(x.get("會議事由") or "" for x in m.get("會議資料") or [])
    return t + " " + " ".join(b.get("標題") or "" for b in meet_bills(m))


def bigrams(t):
    t = re.sub(r'[^一-鿿A-Za-z]', '', t or "")
    return {t[i:i + 2] for i in range(len(t) - 1)} - STOP_BIGRAMS


def record_date(time_line):
    m = R_DATE.search(time_line or "")
    return f"{int(m.group(1)) + 1911}-{int(m.group(2)):02d}-{int(m.group(3)):02d}" if m else None


def match_meet(h):
    """公報紀錄 → API 黨團協商 meet。回傳 (meet 或 None, 說明)。"""
    d = record_date(h.get("時間"))
    if not d:
        return None, "紀錄沒有日期"
    cands = caucus_meets_by_date().get(d) or []
    if not cands:
        return None, "API 沒有同一天的協商會議"
    A = bigrams(" ".join(h.get("協商主題") or []))
    sc = []
    for m in cands:
        B = bigrams(meet_text(m))
        common = len(A & B)
        sc.append((common / max(1, min(len(A), len(B))), common / max(1, len(A | B)), common, m))
    sc.sort(key=lambda x: (x[0], x[1]), reverse=True)
    coef, jac, common, m = sc[0]
    if coef < 0.5 or common < 3:
        return None, f"同一天 {len(cands)} 場，協商主題都對不上"
    if len(sc) > 1 and coef - sc[1][0] < 0.2 and jac - sc[1][1] < 0.08:
        return None, f"同一天 {len(cands)} 場，協商主題相近、分不出是哪一場"
    return m, f"同一天 {len(cands)} 場中協商主題最相近（重疊係數 {coef:.2f}）"


def bill(bid):
    """立法院 API /bills/{議案編號}；快取 .cache/bill_{議案編號}.json（編號只有數字，不會撞檔）。"""
    key = os.path.join(NG.CACHE, f"bill_{bid}.json")
    if os.path.exists(key):
        return json.load(open(key, encoding="utf-8"))
    for a in range(6):
        try:
            req = urllib.request.Request(f"{NG.API}/bills/{quote(bid)}", headers={"User-Agent": "research-ly-meet/1.0"})
            d = json.loads(urllib.request.urlopen(req, timeout=60).read())
            json.dump(d, open(key, "w", encoding="utf-8"), ensure_ascii=False)
            time.sleep(1.5)          # 節流
            return d
        except Exception as e:
            if a == 5:
                print(f"  [bill 失敗] {bid} → {e}", file=sys.stderr)
                return None
            time.sleep(5 * (a + 1))


def bill_committees(b):
    """一個議案的審查委員會。回傳 (委員會, 狀況)；狀況：審查報告標題／議案流程交付／逕付二讀（含自委員會抽出逕付二讀）／無交付紀錄／查不到。"""
    m = R_REPORT_TITLE.match(b.get("標題") or "")
    if m:
        return split_coms(m.group(1)), "審查報告標題"
    x = ((bill(b["議案編號"]) or {}).get("data")) or {}
    if not x:
        return [], "查不到"
    return flow_committees(x)


def flow_committees(x):
    """議案資料（/bills/{id} 或 /bills 列表帶 議案流程）→ (委員會, 狀況)。"""
    m = R_REPORT_TITLE.match("本院" + re.sub(r'^本院', '', x.get("提案單位/提案委員") or "") + "報告") \
        if x.get("提案來源") == "審查報告" else None
    coms = split_coms(m.group(1)) if m else []
    flow = x.get("議案流程") or []
    if not coms and any("抽出逕付二讀" in (s.get("狀態") or "") for s in flow):
        # 先交委員會、後經院會決議「委員會抽出逕付二讀(交付協商)」：委員會沒有完成審查，跟逕付二讀同類
        # （2026-10-06 查核：8 場因此誤列審查委員會）。有審查報告的（上面 m）照樣算委員會審過。
        return [], "逕付二讀"
    for s in flow:
        r = R_REFER.search(s.get("狀態") or "")
        coms += [c for c in (split_coms(r.group(1)) if r else []) if c not in coms]
        u = re.sub(r'(?:[兩二三四五六七八]個?)?委員會$', '', s.get("院會/委員會") or "")
        coms += [c for c in split_coms(u) if c not in coms]
    if coms:
        return coms, "議案流程交付"
    if any("逕付二讀" in (s.get("狀態") or "") for s in flow):
        return [], "逕付二讀"
    return [], "無交付紀錄"


# ④ 備援（①②③都沒有時）：協商主題寫出的法律 → 該屆該法律的全部議案（/bills?屆=&法律編號=，一次帶回議案流程），
#   只採「對得上這場協商」的議案：(a) 議案流程裡記有對到的這場 API 協商會議代碼（只有第 10、11 屆的議案流程有協商這一步）；或
#   (b) 提案人＋人數＋法案名稱與協商主題逐字相同（「委員趙天麟等23人擬具「家庭暴力防治法第五十條條文修正草案」」、
#       「行政院函請審議「○○法草案」」），且提案日不晚於協商日（同一屆行政院可能提兩次同名草案，取協商日前最近的一案）；
#       委員會審查報告的案名列有這些提案（「報告併案審查…委員趙天麟等23人擬具「…」」）也算——聯席審查時，個別議案
#       當初只交一個委員會，審查報告才看得出是幾個委員會（1041107_00015 家庭暴力防治法：個別案交司法及法制，報告是三委員會）。
#   第 8 屆沒有 API 協商會議，只能用 (b)。
# ⑤ 推定（①～④都沒有時，多半是院長主持「研商「○○法修正草案」相關事宜」，沒寫提案人）：
#   協商主題「審議法案」項目裡的每一部法律分開推：該屆、協商日當時「委員會已送出、還沒三讀」的審查報告 → 報告的委員會；
#   沒有這種報告時，取當時「已交付委員會、還沒三讀」的議案的委員會（佔 1/4 以上才算）。各法律取聯集。
#   覆議案、憲法法庭聲請案、建請院會決議等只是引用法律的項目不推（R_NONBILL_ITEM）；
#   API 協商會議的議案全部逕付二讀／沒有交付紀錄時也不推（API 已說沒交委員會審查）。
#   這是依日期推定，不是紀錄或 API 直接寫的，網站上標「推定」。
R_LAWNAME = re.compile(r'^(.{2,30}?(?:法|條例|通則))(?=部分|第|增訂|修正|草案|條文|$)')
R_LAW_UNQ = re.compile(r'([一-鿿]{2,30}?(?:法|條例|通則))(?=部分條文|第[一二三四五六七八九十百]+條|增訂|修正|草案)')
# 提案人：機關、黨團，或前面是「委員」／非中文字的 2–4 字姓名（舊版會把「審查行政院函請審議」抓成「查行政院」，1023803_00008）
R_REF = re.compile(r'(行政院|司法院|考試院|監察院|[一-鿿]{2,8}?黨團|(?:(?<=委員)|(?<![一-鿿]))[一-鿿]{2,4}(?:Sra ?Kacaw)?)'
                   r'(?:等(\d+)人)?(?:分別)?(?:擬具|函請審議)「([^」]+)」')
R_ORG = re.compile(r'^(?:行政院|司法院|考試院|監察院|.+黨團)$')
R_MORE = re.compile(r'等(?:\d+|[一二三四五六七八九十]+)?草?案|如附件|如附表')   # 「等草案」（1090202_00011）也算


def safe_name(s):
    return re.sub(r'[/\\:*?"<>|\s]', '_', s)


def api_get(path, params, key):
    """精準查詢＋自建快取（檔名保留中文）；節流 1.5 秒、失敗退避。"""
    key = os.path.join(NG.CACHE, key)
    if os.path.exists(key):
        return json.load(open(key, encoding="utf-8"))
    from urllib.parse import urlencode
    url = f"{NG.API}{path}?{urlencode(params, doseq=True)}"
    for a in range(6):
        try:
            d = json.loads(urllib.request.urlopen(urllib.request.Request(
                url, headers={"User-Agent": "research-ly-meet/1.0"}), timeout=60).read())
            json.dump(d, open(key, "w", encoding="utf-8"), ensure_ascii=False)
            time.sleep(1.5)
            return d
        except Exception as e:
            if a == 5:
                print(f"  [api 失敗] {url} → {e}", file=sys.stderr)
                return None
            time.sleep(5 * (a + 1))


# 協商主題裡「不是審議法案」的項目：只是引用法條或法律名稱，不能拿來找審查委員會（查核 2026-10-05）。
#   覆議案（1142301_00006 罷免法、1142402_00011 財劃法）、憲法法庭聲請案請本院提供書面意見（1147701_00006）、
#   「立法院職權行使法第71條之1」釐清實務運作（1053001_00014）、「…第二十五條質詢答復之規定」（1113701_00011）、
#   建請院會作成決議、公投提案、人事同意權、開議／延會、專案報告、董監事審查委員會。
R_NONBILL_ITEM = re.compile(r'覆議|聲請|憲法法庭|書面意見|實務運作|質詢|建請|[作做]成決議|公投提案|請公決|同意權|開議|延會'
                            r'|延長會期|停開|專案報告|董、?監事|董事、監察人|審查委員會」')
R_ITEM_SPLIT = re.compile(r'(?<![第（(之一二三四五六七八九十])(?=[一二三四五六七八九十]+、)|；')   # 「第十六條之一、第三十條」不切


def topic_items(h):
    """協商主題切成項目（「一、…二、…」「；」）。"""
    t = re.sub(r'\s', '', " ".join(h.get("協商主題") or []))
    return [x for x in R_ITEM_SPLIT.split(t) if x]


def nonbill_items(h):
    """(協商主題項目數, 其中不是法案審議的項目數)。只有一項時回 (1, 0)。
    不是法案審議＝R_NONBILL_ITEM（覆議、同意權、延會…），或沒寫法律名稱的項目（預算、決算、組成程序委員會…）。
    「（壹）：」「民進黨黨團：」「併案協商」這種只是標頭的片段（不到 10 字）不算一項（1030808_00007、1034304_00007、1023803_00009）。"""
    items = [it for it in topic_items(h) if len(re.sub(r'[（(][^）)]*[）)]', '', it)) >= 10]
    if len(items) < 2:
        return len(items), 0
    return len(items), sum(1 for it in items if R_NONBILL_ITEM.search(it) or not re.search(r'法|條例|通則', it))


def topic_laws(h, with_skipped=False):
    """協商主題裡「審議法案」項目寫出的法律名稱。with_skipped=True 另回傳略過的項目（不是審議法案）數。"""
    laws, skipped = [], 0
    items = topic_items(h)
    keep = []
    for it in items:
        if R_NONBILL_ITEM.search(it):
            skipped += 1
        else:
            keep.append(it)
    laws = laws_in("".join(keep))
    return (laws[:6], skipped) if with_skipped else laws[:6]


def laws_in(t):
    """一段協商主題文字寫出的法律名稱（有引號取引號裡的；沒有引號才抓「○○法修正／第N條…」）。"""
    laws = []
    for q in re.findall(r'「([^」]+)」', t):
        m = R_LAWNAME.match(q)
        if m and not m.group(1).startswith("中華民國憲法") and m.group(1) not in laws:
            laws.append(m.group(1))
    if not laws:     # 沒有引號：「繼續研商勞動基準法修正審查相關事宜」「行政院函請審議國軍退除役官兵輔導條例第三條之一…」
        for m in R_LAW_UNQ.finditer(t):
            name = re.split(r'擬具|審議|研商|協商|審查|處理|有關|關於|、|，|；|。|：|[一二三四五六七八九十]+、|\d', m.group(1))[-1]
            if len(name) >= 3 and name not in laws:
                laws.append(name)
    return laws


def law_id(name):
    d = api_get("/laws", {"q": f'"{name}"', "limit": 20}, f"law_q_{safe_name(name)}.json") or {}
    for x in d.get("laws") or []:
        if x.get("名稱") == name or name in (x.get("其他名稱") or []) or name in (x.get("別名") or []):
            return x.get("法律編號")
    return None


def law_bills(term, lid):
    out, page = [], 1
    while True:
        d = api_get("/bills", {"屆": term, "法律編號": lid, "limit": 200, "page": page,
                               "output_fields": ["議案編號", "議案名稱", "議案流程", "提案單位/提案委員", "提案來源"]},
                    f"bills_law_{term}_{lid}_p{page}.json") or {}
        out += d.get("bills") or []
        if page >= (d.get("total_page") or 0):
            return out
        page += 1


def flow_dates(x):
    """議案流程的 (最早日期, 三讀日期)；日期是 YYYY-MM-DD 字串，沒有就 None。"""
    ds, third = [], []
    for s in x.get("議案流程") or []:
        d = [y for y in s.get("日期") or [] if y]
        ds += d
        if (s.get("狀態") or "") == "三讀" and d:
            third.append(min(d))
    return (min(ds) if ds else None), (min(third) if third else None)


def ref_in_report(ref, name):
    """審查報告案名是否列有協商主題的這個提案（提案人＋人數＋「法案名稱」都要出現）。"""
    p, n, q = ref
    if f"「{q}」" not in name:
        return False
    if R_ORG.match(p):
        return p in name
    return f"{p}等{n}人" in name if n else f"委員{p}" in name


def law_bills_of(h, term):
    """協商主題的法律 → [(法律名稱, 該屆議案列表 或 None＝查不到法律編號)]。"""
    return [(name, law_bills(term, lid) if lid else None) for name, lid in ((n, law_id(n)) for n in topic_laws(h))]


def same_proposer(who, p):
    """議案的提案單位是否就是協商主題寫的提案人。機關可能是聯名：「司法院、行政院」對主題的「司法院、行政院函請審議」
    （R_REF 只抓到緊鄰「函請審議」的「行政院」，1107002_00004／00005；舊版因此對不上、沒發現是逕付二讀）。"""
    return who == p or (bool(R_ORG.match(p)) and p in who.split("、"))


def law_link(h, term, meet_code, date):
    """④：回傳 ({"report": 委員會, "law": 委員會}, {連結方式: 案數}, 法律數, {法律名稱: 連結到的方式})。
    report＝案名列有協商主題提案的委員會審查報告；law＝議案流程記有這場協商、或提案人＋法案名稱相同的個別議案。
    最後一項記哪些法律有連到議案（補充推定時用來判斷「直接依據涵蓋了哪些法律」）。"""
    t = re.sub(r'\s', '', " ".join(h.get("協商主題") or []))
    refs = [(re.sub(r'^本院', '', p), n, q) for p, n, q in R_REF.findall(t)]
    out, how = {"report": [], "law": []}, collections.Counter()
    linked = collections.defaultdict(set)
    laws = law_bills_of(h, term)
    for name, bills in laws:
        if bills is None:
            how["法律查不到編號"] += 1
            continue
        picked = {}          # 議案編號 → (連結方式, 議案)
        best = {}            # (b) 每個提案取協商日前最近的一案：ref → (最早日期, 議案)
        for x in bills:
            first, _ = flow_dates(x)
            if date and first and first > date:
                continue
            bn = re.sub(r'\s', '', x.get("議案名稱") or "")
            if x.get("提案來源") == "審查報告" and any(ref_in_report(r, bn) for r in refs):
                picked[x["議案編號"]] = ("審查報告列有協商主題的提案", x)
                continue
            if meet_code and any(s.get("會議代碼") == meet_code for s in x.get("議案流程") or []):
                picked[x["議案編號"]] = ("議案流程記有這場協商", x)
                continue
            if x.get("提案來源") == "審查報告":
                continue
            who = re.sub(r'^本院', '', re.sub(r'\s', '', x.get("提案單位/提案委員") or ""))
            for r in refs:
                p, n, q = r
                if f"「{q}」" in bn and (who == f"委員{p}等{n}人" if n else same_proposer(who, p)):
                    if r not in best or (first or "") > (best[r][0] or ""):
                        best[r] = (first, x)
        for r, (_, x) in best.items():
            picked.setdefault(x["議案編號"], ("提案人與法案名稱相同", x))
        for k, x in picked.values():
            c, st = flow_committees(x)
            key = "report" if k.startswith("審查報告") else "law"
            how[k if key == "report" else f"{k}：{st}"] += 1
            out[key] += [y for y in c if y not in out[key]]
            linked[name].add(key if c else st)
    return out, dict(how), len(laws), dict(linked)


def topic_quotes(h):
    """審議法案項目裡的引號全文，依法律名稱分組：{"反滲透法": ["反滲透法草案"]}。"""
    out = collections.defaultdict(list)
    t = "".join(it for it in topic_items(h) if not R_NONBILL_ITEM.search(it))
    for q in re.findall(r'「([^」]+)」', t):
        m = R_LAWNAME.match(q)
        if m:
            out[m.group(1)].append(q)
    return out


EXACT_TAG = "（名稱與協商主題相同的議案）"


def law_infer(h, term, date, with_pass2=False, only=None):
    """⑤ 推定：回傳 (委員會, 說明)；with_pass2=True 另回傳「協商的議案是逕付二讀」的法律。
    only＝只推這幾部法律（review_info() 的補充推定：直接依據沒涵蓋到的法律），這時不加結尾的「等案／其他項目」附註。
    協商主題的每一部法律分開推（院長主持常一次談好幾部法律，把各法律的審查報告混在一起的話，
    有報告的法律會蓋掉只有交付議案的法律）：
    先找協商日當時尚未三讀、議案名稱含協商主題引號全文（「反滲透法草案」）的議案；有就只看這些
    （10810601_00013 協商的是民進黨黨團「反滲透法草案」，逕付二讀；舊版拿時代力量另提、交內政的「反境外敵對勢力併吞滲透法草案」推成內政）。
    這些議案有「委員會已送出、尚未三讀」的審查報告 → 取報告的委員會；
    沒有報告 → 取「已交付委員會、尚未三讀」的議案的委員會，但只收佔該法律議案 1/4 以上的委員會
    （菸害防制法 28 案交社福、1 案另併交財政，財政不算，1122005_00008）；
    都沒有交委員會、而且有逕付二讀的 → 記為逕付二讀。"""
    if not date:
        return ([], "", []) if with_pass2 else ([], "")
    coms, parts, pass2 = [], [], []
    quotes = topic_quotes(h)
    for name, bills in law_bills_of(h, term):
        if only is not None and name not in only:
            continue
        pend = []
        for x in bills or []:
            first, third = flow_dates(x)
            if first and first <= date and not (third and third < date):
                pend.append(x)
        exact = [x for x in pend if any(f"「{q}」" in re.sub(r'\s', '', x.get("議案名稱") or "") for q in quotes.get(name, []))]
        if exact:
            pend = exact
        reports, referred = collections.Counter(), collections.Counter()
        nrep = nref = npass = 0
        for x in pend:
            c, st = flow_committees(x)
            if not c:
                npass += st == "逕付二讀"
                continue
            if x.get("提案來源") == "審查報告":
                nrep += 1
                reports.update(c)
            else:
                nref += 1
                referred.update(c)
        tag = EXACT_TAG if exact else ""
        if reports:
            got = [c for c, _ in reports.most_common()]
            parts.append(f"{name}：審查報告{tag} {nrep} 案（" + "、".join(f"{c} {v}" for c, v in reports.most_common()) + "）")
        elif referred:
            got = [c for c, v in referred.most_common() if v * 4 >= nref]
            drop = [f"{c} {v}" for c, v in referred.most_common() if v * 4 < nref]
            parts.append(f"{name}：已交付議案{tag} {nref} 案（" + "、".join(f"{c} {v}" for c, v in referred.most_common() if v * 4 >= nref)
                         + "）" + (f"，少數併交的 {'、'.join(drop)} 不算" if drop else ""))
        elif npass:
            pass2.append(f"「{'」「'.join(quotes.get(name) or [name])}」" if exact else name)
            parts.append(f"{name}：議案{tag} {npass} 案逕付二讀，沒有交委員會")
            continue
        else:
            continue
        coms += [c for c in got if c not in coms]
    if not coms:
        return ([], "", pass2) if with_pass2 else ([], "")
    note = "協商日當時尚未三讀的議案，逐部法律：" + "；".join(parts)
    if only is not None:
        return (coms, note, pass2) if with_pass2 else (coms, note)
    if R_MORE.search(re.sub(r'\s', '', " ".join(h.get("協商主題") or []))):
        # 1104001_00013「文化藝術獎助條例修正草案等四案」：另外三案是組織法，API 協商會議的議案才看得到
        note += "；協商主題寫「等案／如附表」，只推得到主題寫出名稱的法律"
    n_items, n_non = nonbill_items(h)
    if n_non:
        # 院長主持的多項協商：預算、人事、程序項目不是單一委員會審查的法案，推定只涵蓋寫出法律名稱的項目
        note += f"；協商主題 {n_items} 項中 {n_non} 項不是法案審議或沒寫法律名稱（預算、人事、程序等），推定不涵蓋"
    return (coms, note, pass2) if with_pass2 else (coms, note)


R_BILL_LAW = re.compile(r'^(.{2,30}?(?:法|條例|通則))')
# 建請院會作成決議、公投提案：不是法案，API 協商會議登記的承辦委員會不代表有委員會審查（1142402_00010 地震重建決議案登記內政）
R_RESOLUTION = re.compile(r'建請院會|[作做]成決議|公投提案|請公決')
NO_COM = ("逕付二讀", "無交付紀錄", "查不到")


def topic_bills(m, h):
    """API 協商會議附的議案中，和協商主題有關的。回傳 (採用的議案, 略過的議案)。
    議案標題的法律名稱都不在協商主題裡、協商主題又沒寫「等N案／如附表」時略過：
    1104901_00014 主題只寫「文化藝術獎助條例修正草案」，meet 卻附了退輔會組織法、民航局組織條例（上一場「等四案」的另三案），
    逐字稿 0 處提到。標題沒有法律名稱的（人事同意權等）一律保留；全部都對不上主題時也全部保留（可能是名稱寫法不同）。"""
    bills = meet_bills(m)
    topic = re.sub(r'\s', '', " ".join(h.get("協商主題") or []))
    if R_MORE.search(topic):
        return bills, []
    def names(b):
        return [x.group(1) for q in re.findall(r'「([^」]+)」', re.sub(r'\s', '', b.get("標題") or ""))
                for x in [R_BILL_LAW.match(q)] if x]
    rel = [b for b in bills if not names(b) or any(n in topic for n in names(b))]
    if not any(names(b) for b in rel):
        return bills, []
    return rel, [b for b in bills if b not in rel]


def supplement_infer(h, term, date, union, src, linked, bill_seen):
    """補充推定（查核 2026-10-06）：有直接依據的場次，協商主題寫出的法律裡，直接依據沒涵蓋到的，逐部法律另跑 ⑤ 推定。
    院長主持常一次談好幾部法律，直接依據只命中其中一部時（主題文字只寫到第一項、只對到某一提案的審查報告），
    其他法律的委員會會整個漏掉、看起來卻像完整答案（1070402_00006 第二項的二二八條例、證人保護法等六案；
    1084301_00012 只對到王定宇一份報告，政府採購法、跟蹤騷擾防制法都漏）。
    「涵蓋」＝①該法律所在的協商主題項目寫了審查委員會；④有審查報告連到該法律；③協商會議附的議案有該法律的審查報告。
    只有個別議案（當初只交一個委員會）、逕付二讀的議案或承辦委員會的不算涵蓋，照樣推定
    （1094602_00014 協商會議附的槍砲彈藥刀械管制條例 6 案都是委員提案逕付二讀，協商日當時另有內政委員會的審查報告）：
    聯席審查時個別議案只看得到一個委員會（1118105_00007 幼照法：協商會議附的個別議案交教育及文化，審查報告是教文、社福聯席）。
    推定結果不併入「議案審查委員會」，另列一行、標推定。回傳 {} 或
    {"委員會": 推定多出的委員會, "法律": [(法律, 推定委員會, 說明, 是否逕付二讀)], "未涵蓋法律": [...], "非法案項目": N, "項目數": N}。"""
    laws = topic_laws(h)
    items = topic_items(h)
    n_items, nonbill = nonbill_items(h)
    covered, pass_api = set(), set()
    for name in laws:
        if any(name in it and review_committees_text(it) for it in items):
            covered.add(name)
        elif "report" in linked.get(name, set()) or any(name in t and how == "審查報告標題" for t, how in bill_seen):
            covered.add(name)
        elif (linked.get(name) and linked[name] <= set(NO_COM)) or \
                (any(name in t for t, _ in bill_seen) and all(how in NO_COM for t, how in bill_seen if name in t)):
            pass_api.add(name)       # API 連到的該法律議案全部逕付二讀／沒有交付紀錄：照樣推定，推得到審查報告就另外註明
    def subseq(a, b):
        it = iter(b)
        return all(ch in it for ch in a)
    # 紀錄筆誤：「兒童及少年性剝削條例」（1120603_00008，同場其他案寫「…性剝削防制條例」）API 查不到、又是已涵蓋法律的縮寫，不另列
    unc = [n for n in laws if n not in covered
           and not (not law_id(n) and any(subseq(n, c) for c in covered))]
    per, extra = [], []
    if term and date:
        for name in unc:
            c, note, p2 = law_infer(h, term, date, with_pass2=True, only={name})
            if c and EXACT_TAG not in note:
                # 只找到同一法律、名稱和協商主題不同的其他議案：不列入（1069001_00012 協商的「有線廣播電視法第三十三條及
                # 第六十一條條文修正草案（國會頻道）」是當場談定各黨團共同提案、逕付二讀，交通委員會那 5 案是別的修正案）
                per.append((name, [], "名稱不同：" + note, False))
                continue
            if name in pass_api:
                note += "（API 協商會議連到的該法律議案是逕付二讀或沒有交付紀錄，但協商日當時另有交委員會的議案）" if c else ""
            per.append((name, c, note, bool(p2) or name in pass_api))
            extra += [x for x in c if x not in union and x not in extra]
    if not extra and not nonbill and all(c or p2 for _, c, _, p2 in per):
        return {}
    return {"委員會": extra, "法律": per, "未涵蓋法律": unc, "非法案項目": nonbill, "項目數": n_items,
            "查不到法律": [n for n in unc if not law_id(n)]}


def review_info(h):
    """議案審查委員會＋依據。院長主持、委員主持都算。"""
    src = {"text": review_committees_text(" ".join(h.get("協商主題") or []))}
    m, why = match_meet(h)
    date = record_date(h.get("時間"))
    detail = {"對應": why, "議案": collections.Counter()}
    unused = []          # 拿得到、但判斷不代表議案審查委員會而沒採用的資料，網站上照樣寫出來
    no_review = ""       # 有證據顯示「沒有交委員會審查」時的說明（≠ 查不到）
    bill_seen = []       # (協商會議議案標題, 狀況)：補充推定時判斷直接依據涵蓋了哪些法律
    unit = []
    if m:
        unit = split_coms("、".join(re.sub(r'委員會$', '', c) for c in m.get("委員會代號:str") or []))
        bills, off = topic_bills(m, h)
        bc = []
        for b in bills:
            coms, how = bill_committees(b)
            detail["議案"][how] += 1
            bill_seen.append((re.sub(r'\s', '', b.get("標題") or ""), how))
            bc += [c for c in coms if c not in bc]
        src["bill"] = bc
        if off:
            oc = []
            for b in off:
                oc += [c for c in bill_committees(b)[0] if c not in oc]
            unused.append(f"API 協商會議另附 {len(off)} 案協商主題沒寫的議案" + (f"（{'、'.join(oc)}）" if oc else "") + "，不採用")
        st = detail["議案"]
        topic_all = " ".join(h.get("協商主題") or [])
        if bills and not bc and (st["逕付二讀"] or st["無交付紀錄"]):
            # 議案全部逕付二讀或沒有交付紀錄：API 自己說這場協商的議案沒交委員會審查（1151302_00011、1149401_00014、1138801_00011…）
            no_review = "API 協商會議的議案 " + "、".join(f"{k} {v} 案" for k, v in st.items() if k in NO_COM)
        elif not bills and R_RESOLUTION.search(topic_all) and not topic_laws(h):
            no_review = "協商主題是建請院會作成決議／公投提案，API 協商會議也沒有附議案"
    law_how, nlaw, infer_note, linked, p2_all = {}, 0, "", {}, {}
    tm = R_TERM_SESS.search(h.get("標題") or "")
    if tm:
        lk, law_how, nlaw, linked = law_link(h, tm.group(1), m["會議代碼"] if m else None, date)
        src["report"] = lk["report"]                    # 審查報告是精確連結，一律採用（補①只寫到第一項、②只列主審的缺口）
        p2 = {k: v for k, v in law_how.items() if k.endswith("：逕付二讀")}
        p2_all = p2
        tt = re.sub(r'\s', '', " ".join(h.get("協商主題") or []))
        all_refs = not R_MORE.search(tt) and len(R_REF.findall(tt)) == len(re.findall(r'「[^」]+」', tt)) \
            and sum(p2.values()) >= len(R_REF.findall(tt))
        if unit and not no_review and not any(src.get(k) for k in ("text", "bill", "report")) and not lk["law"] and p2 \
                and all_refs:
            # 只有承辦委員會、而協商主題寫的每一個提案（提案人＋法案名稱相同）在 API 都是逕付二讀：承辦委員會不是審查委員會。
            # 主題另有沒寫提案人的議案、或寫「等N案」時不適用（1082903_00014 (一)「兒少法部分條文修正草案」等四十六案是社福委員會
            #   併案審查，只有 (二) 行政院版、(三) 蔣萬安版逕付二讀）
            # （1107002_00004／00005 司法院、行政院「傳染病流行疫情嚴重期間司法程序特別條例草案」：議案流程「院會 逕付二讀」→ 三讀，
            #   逐字稿主席說「經第10屆第3會期第1次臨時會第1次會議決定逕付二讀」）
            no_review = "API 議案：" + "、".join(f"{k.replace('：逕付二讀', '')}的議案逕付二讀 {v} 案" for k, v in p2.items())
    if no_review and unit:
        unused.append(f"API 協商會議登記的承辦委員會：{'、'.join(unit)}（是協商的承辦單位，不是審查委員會，不列入）")
    elif unit:
        src["unit"] = unit
    if tm:
        if not any(src.values()) and not no_review:     # API 已說沒交委員會審查時，不再拿同一部法律的其他議案來推
            src["law"] = lk["law"]                      # 個別議案的交付委員會：①②③審查報告都沒有才用
            if not src["law"] and p2:
                # 協商主題寫的提案（提案人＋法案名稱相同，或議案流程記有這場協商）在 API 是逕付二讀（1055501_00010 徐國勇版組織犯罪防制條例）
                no_review = "API 議案：" + "、".join(f"{k.replace('：逕付二讀', '')}的議案逕付二讀 {v} 案" for k, v in p2.items())
            elif not src["law"]:
                src["infer"], infer_note, pass2 = law_infer(h, tm.group(1), date, with_pass2=True)
                if not src["infer"] and pass2:
                    no_review = (f"推定：協商主題的{'、'.join(pass2)}，協商日當時 API 議案流程是逕付二讀"
                                 "（依法案名稱與協商日期比對，不是 API 協商會議直接連結）")
        else:
            law_how = {k: v for k, v in law_how.items() if k.startswith("審查報告")}
    union = []
    for k in REVIEW_SRC:
        union += [c for c in src.get(k) or [] if c not in union]
    src_used = [k for k in REVIEW_SRC if src.get(k)]
    basis = []
    for k in REVIEW_SRC:
        if src.get(k):
            extra = ""
            if k == "bill":
                n = sum(detail["議案"].values())
                extra = f"（{n} 案中 {n - detail['議案']['逕付二讀'] - detail['議案']['無交付紀錄'] - detail['議案']['查不到']} 案有委員會）"
            if k in ("law", "report"):
                extra = "（" + "、".join(f"{a} {b} 案" for a, b in law_how.items()
                                        if a.startswith("審查報告") == (k == "report")) + "）"
            if k == "infer":
                extra = f"（{infer_note}）"
            basis.append(f"{REVIEW_SRC[k]}：{'、'.join(src[k])}{extra}")
    if not union and no_review:
        basis = ["無：" + no_review]
    elif not union:
        why_not = ["協商主題沒寫審查委員會"] if not src["text"] else []
        why_not.append(why if not m else "API 協商會議沒有承辦委員會也沒有議案資料" if not meet_bills(m)
                       else "API 議案都沒有交付委員會（" + "、".join(f"{k} {v}" for k, v in detail["議案"].items()) + "）")
        topic = " ".join(h.get("協商主題") or [])
        skipped = topic_laws(h, with_skipped=True)[1]
        why_not.append(f"依法律查議案：{'、'.join(f'{a} {b} 案' for a, b in law_how.items()) or '沒有對得上這場協商的議案，協商日當時也沒有交付委員會的議案'}"
                       if nlaw else "協商主題沒有要審議的法案（" + ("預算案" if "預算" in topic else
                                                          "程序事項" if R_PROC.search(topic) else
                                                          "提到法律的項目是覆議、聲請案、決議等" if skipped else "其他") + "）")
        basis = ["查不到：" + "；".join(why_not)]
    elif no_review:
        unused.append(no_review)
    elif p2_all and src_used == ["unit"]:
        # 只有承辦委員會時才註明，例：1082903_00014 (一) 社福併案審查的四十六案之外，(二) 行政院版、(三) 蔣萬安版
        # 在 API 是逕付二讀。有審查報告／協商會議議案的場次不加：「提案人＋法案名稱相同」可能對到同一人另提的同名案，
        # 而協商會議議案的逕付二讀已寫在「N 案中 M 案有委員會」裡。
        unused.append("協商主題寫的提案中，" + "、".join(f"{k.replace('：逕付二讀', '')}的議案 {v} 案在 API 是逕付二讀（沒交委員會）"
                                                for k, v in p2_all.items()))
    basis += [f"（未採用）{x}" for x in unused]
    supp = supplement_infer(h, tm.group(1) if tm else None, date, union, src, linked, bill_seen) \
        if union and not src.get("infer") else {}
    return {"議案審查委員會": union, "議案審查依據": basis, "議案審查來源": {k: v for k, v in src.items() if v},
            "議案審查推定": bool(src.get("infer")), "議案未交委員會審查": no_review if not union else "",
            "議案審查補充推定": supp,
            "協商會議代碼": m["會議代碼"] if m else None, "協商會議對應": why,
            "API 議案狀況": dict(detail["議案"]), "依法律查議案": law_how}


R_SIG_LABEL = re.compile(r'^[^：:\s]{1,10}[：:]')
R_SIG_NOTNAME = re.compile(r'[，。、；「」『』%\d一二三四五六七八九十]|條|決議|動議|INCLUDEPICTURE|案由|說明')
R_HOST_LAB = re.compile(r'^(?:協商)?主\s*持\s*人[：:]')


def sig_names(line):
    """簽名欄一行 → 人名。「吳志揚（代）陳宜民（代）」中間沒空白要拆開；「楊　曜」被全形空白拆成單字要併回。"""
    line = re.sub(r'[（(][^）)]*[）)]', ' ', line)
    toks = [x.strip() for x in re.split(r'[\s　]+', line) if x.strip()]
    out, i = [], 0
    while i < len(toks):
        if len(toks[i]) == 1 and i + 1 < len(toks) and len(toks[i + 1]) == 1:
            out.append(toks[i] + toks[i + 1]); i += 2
        else:
            out.append(toks[i]); i += 1
    return [n for n in out if len(n) >= 2]


def signers(rec):
    """一場紀錄裡所有簽名在案的人：每一份「協商代表：」簽名欄（連續的純人名行，碰到「欄名：」或含標點、條文的行就停），
    加上主持人行（「主持人：蘇嘉全　蔡其昌」；「主持人」常被發言人辨識吃掉，變成緊接在「協商代表：」前一行、標成主席的純人名行）。
    沒有任何協商代表名單回 None。規則沿用 findings/caucus-negotiation/attendance-law-2026-10-05.md 的「全簽名欄版」。"""
    ks = [i for i, (w, t) in enumerate(rec) if re.match(r'^協商代表[：:]', t)]
    if not ks:
        return None
    out = []
    for k in ks:
        for j, (w, t) in enumerate(rec[k:]):
            t = t.strip()
            if j == 0:
                out += sig_names(re.sub(r'^協商代表[：:]', '', t)); continue
            if not t or is_say(w) or R_SIG_LABEL.match(t) or R_SIG_NOTNAME.search(t.replace('‧', '').replace('．', '')):
                break
            out += sig_names(t)
    for i, (w, t) in enumerate(rec):
        eaten = (i + 1 < len(rec) and re.match(r'^協商代表[：:]', rec[i + 1][1]) and w == "主席"
                 and not re.search(r'[，。、；：:？！「」%\d]', t) and len(t) <= 40)
        if R_HOST_LAB.match(t) or eaten:
            for x in re.split(r'[\s　]+', R_HOST_LAB.sub('', t).strip()):
                x = re.sub(r'^(.)召集委員(.+)$', r'\1\2', re.sub(r'[（(][^）)]*[）)]|委員$', '', x))
                if x:
                    out.append(x)
    return out


def non_rep_speakers(h, rec):
    """有協商代表名單時，找出發言但不在任何簽名欄／主持人行的委員（主席本人、帶職稱的官員不算）。
    沒有名單回 None（第 10 屆只有 2 場有、第 11 屆 0 場）。提案人只認得協商主題寫出的第一提案人（「委員○○○等N人」），連署人看不到。
    2026-10-06 改用全簽名欄版（只讀第一份簽名欄時是 48 場／123 人次）。"""
    reps = signers(rec)
    if reps is None:
        return None
    norm = lambda n: re.sub(r'[\s　．・‧.]', '', n)
    repn = {norm(r) for r in reps}
    # 名單寫「鄭天財Sra Kacaw」、發言標籤可能只有「鄭天財」：一方是另一方的開頭（≥3 字）就算同一人
    same = lambda n: norm(n) in repn or any(min(len(r), len(norm(n))) >= 3 and (r.startswith(norm(n)) or norm(n).startswith(r))
                                            for r in repn)
    official = lambda n: re.search(r'(?:副?主任|部長|署長|局長|秘書長|主委)$', n)   # 「伊萬‧納威副主任委員」是官員
    chairs = {person_name(x) for x in re.split(r'[（(]', (h.get("主席") or "").replace("代理", "")) if x}
    topic = re.sub(r'\s', '', " ".join(h.get("協商主題") or []))
    cnt = collections.Counter(person_name(w) for w, t in rec if is_say(w) and person_name(w))
    return [{"委員": n, "段數": c, "提案人": n in topic} for n, c in cnt.most_common()
            if not same(n) and n not in chairs and not official(n)]


def first_sentence(t, pat):
    for s in re.split(r'[。？！]', t):
        if pat.search(s):
            return s.strip()
    return t[:40]


def cut_sections(body):
    """body：[(idx, who, text)]，散會（含）之前的段落。回傳 sections。"""
    bounds = []                       # (pos, kind, label)
    for k, (i, who, t) in enumerate(body):
        if who != "主席":
            if is_say(who) and R_READ_CONC.search(t[:40]) and "如下" in t[:60]:
                bounds.append((k, "結論", f"{who}宣讀協商結論"))    # 議事人員代讀：「報告委員，…協商結論如下：」
            continue
        # 宣讀結論要是「往下念」的說法；只是提到或轉述不算：「徐總召傳來的協商結論是，把…」（1058201_00012）、
        # 「過去其他的協商結論是不是要全部推翻？」（1066801_00014）。不看位置——「…現在就請議事人員宣讀朝野黨團協商結論」常在長段末尾
        if R_READ_NOW.search(t) and (k + 1 >= len(body) or body[k + 1][1] in ("主席", "〔結構〕")):
            bounds.append((k, "結論", "主席宣讀協商結論"))
            continue
        if R_TOPIC.search(t) and not R_OPEN.search(t):
            sent = first_sentence(t, R_TOPIC)
            if k == 0 or all(not is_say(w) or w == "主席" for _, w, _ in body[:k]):
                bounds.append((k, "開場題", sent))   # 第一段主席話：開場白順帶講今天談什麼（1124602_00010），只設定議題
                continue
            # 句尾「……」前是「請」：被打斷的是點名，不是換題（1089803_00008 [74]「…第三案是第三十四條之二…，請……」）
            tail_clause = re.split(r'[，,]', t.strip())[-1]
            if R_INTERRUPTED.search(t.strip()) and len(t) < 60 and not tail_clause.startswith("請"):
                bounds.append((k, "打斷", sent))     # 不切段，只在當下的 section 加標籤
            else:
                bounds.append((k, "換題", sent))
            continue
        if R_CALL.search(t):
            nxt = next((w for _, w, _ in body[k + 1:k + 6] if is_say(w) and w != "主席"), None)
            if nxt:
                bounds.append((k, "點名", nxt))
    interrupted = [(pos, lab) for pos, kind, lab in bounds if kind == "打斷"]
    opening_topic = next((lab for pos, kind, lab in bounds if kind == "開場題"), None)
    bounds = [b for b in bounds if b[1] not in ("打斷", "開場題")]
    # 換題句講的法律跟目前議題相同 → 主席在重申，不切段，只加標籤
    restate, cur_key, cur_art, kept = [], None, None, []
    for b in bounds:
        if b[1] == "換題":
            k = law_key(b[2])
            art = (R_ARTICLE.findall(b[2]) or [None])[0]
            # 同一部法同一條（或沒寫法名、連說兩次同一條：「現在進行第一條。」「進行第一條。」10610801_00013）
            if (k and k == cur_key and (art is None or art == cur_art)) or (k is None and art and art == cur_art):
                restate.append((b[0], b[2]))
                continue
            cur_key, cur_art = (k or cur_key), art
        kept.append(b)
    bounds = kept
    # 換題後 3 段內就點名 → 併成一段（主席先說換題、接著點名第一位發言者）
    merged = []
    for b in bounds:
        if merged and b[1] == "點名" and merged[-1][1] == "換題" and b[0] - merged[-1][0] <= 3:
            merged[-1] = (merged[-1][0], "換題", merged[-1][2], b[2])
            continue
        merged.append(b + (None,) if len(b) == 3 else b)
    if not merged or merged[0][0] > 0:
        merged.insert(0, (0, "開場", "開場", None))
    # 宣讀結論只到主席（或代讀的議事人員）唸完為止：之後第一位其他人開口就另起「宣讀後討論」，
    # 否則試擬結論宣讀後的整段討論都會掛在「宣讀協商結論」底下（1100602_00008 一段 170 段）
    dedup = []
    for b in merged:
        if dedup and b[1] == "結論" and dedup[-1][1] == "結論" and b[0] - dedup[-1][0] <= 2:
            continue
        dedup.append(b)
    merged, out = dedup, []
    for n, b in enumerate(merged):
        out.append(b)
        if b[1] == "結論":
            end = merged[n + 1][0] if n + 1 < len(merged) else len(body)
            reader = body[b[0]][1]
            k = next((k for k in range(b[0] + 1, end) if is_say(body[k][1]) and body[k][1] not in ("主席", reader)), None)
            if k is not None and end - k > 2:
                out.append((k, "討論", "宣讀後討論", None))
    merged = out

    secs, topic = [], (law_key(opening_topic) or topic_name(opening_topic)) if opening_topic else None
    for n, (pos, kind, label, caller) in enumerate(merged):
        end = merged[n + 1][0] if n + 1 < len(merged) else len(body)
        part = body[pos:end]
        if not part:
            continue
        if kind == "換題":
            topic = law_key(label) or topic_name(label)
        spk = collections.Counter(w for _, w, _ in part if is_say(w))
        chair_txt = " ".join(t for _, w, t in part if w == "主席")
        all_txt = " ".join(t for _, w, t in part if is_say(w))
        tags = []
        if kind == "換題":
            arts = R_ARTICLE.findall(label)
            tags.append("換條" if arts and not re.search(r'[法例則]|草案', label.split(arts[0])[0][-12:] or "")
                        else "換案")
        if topic and kind != "換題":
            tags.append(f"議題：{topic[:24]}")
        for ip, lab in interrupted:
            if pos <= ip < end:
                tags.append(f"主席嘗試換題被打斷：{topic_name(lab)[:20]}")
        for ip, lab in restate:
            if pos <= ip < end:
                tags.append("主席重申議題")
        if A["C7 輪次宣告"].search(chair_txt):
            tags.append("輪次")
        if kind == "結論" or R_READ_CONC.search(chair_txt):
            tags.append("協商結論")
        if A["C4 無共識/保留"].search(chair_txt):
            tags.append("無共識／保留")
        if A["C5 請簽名"].search(chair_txt):
            tags.append("簽名")
        if re.search(r'表決', chair_txt) and re.search(r'送院會表決|院會表決', chair_txt):
            tags.append("送院會表決")
        name = {"開場": "開場", "點名": label, "結論": label, "討論": label,
                "換題": f"〔換題〕{label[:36]}" + (f" → {caller}" if caller else "")}[kind]
        secs.append({"name": name, "kind": kind, "start": part[0][0], "end": part[-1][0], "段數": len(part),
                     "發言人": [w for w, _ in spk.most_common()], "tags": tags,
                     "sample": next((t for _, w, t in part if is_say(w) and w != "主席"), part[0][2])[:150],
                     "anchor": part[0][2][:150]})
    return secs


def segment_record(rec):
    h = header(rec)
    idx = [(i, w, t) for i, (w, t) in enumerate(rec)]
    first = next((k for k, (_, w, _) in enumerate(idx) if is_say(w)), None)
    notes, attach = [], []
    if first is None:
        return {**h, "sections": [], "attachments": [], "notes": ["⚠ 這場紀錄沒有任何口說段"], "tags": []}
    # 結束點：最後一個主席「散會」段（含「散會（16時29分）」那一行）
    end = None
    for k in range(len(idx) - 1, first - 1, -1):
        w, t = idx[k][1], idx[k][2].strip()
        if R_ADJ_LINE.match(t) or (w == "主席" and R_ADJ.search(t)):
            end = k      # 「散會（9時58分）」那行有時掛在前一位發言人名下（1073501_00012），不限主席
            break
    if end is None:
        rest = next((k for k in range(len(idx) - 1, first - 1, -1)
                     if idx[k][1] == "主席" and re.search(r'休息', idx[k][2][-20:])), None)
        sig = next((k for k in range(first, len(idx))
                    if A["S5 書面協商結論標題"].match(idx[k][2]) or A["S6 簽名欄"].match(idx[k][2])), None)
        last_talk = max((k for k in range(first, len(idx)) if is_say(idx[k][1]) and R_SAYLIKE.search(idx[k][2])),
                        default=len(idx) - 1)
        if rest is not None and rest >= last_talk - 1:
            notes.append("以休息結束，沒有散會（下半場可能登在另一份紀錄）")
            end = rest
        elif sig is not None:
            notes.append("⚠ 沒有散會句，以簽名欄／書面結論頁之前為結束")
            end = max(first, sig - 1)
        else:
            notes.append("⚠ 沒有散會句，以最後一段口說為結束")
            end = last_talk
    body = idx[first:end + 1]
    tail = idx[end + 1:]
    if tail:
        conc = [t for _, _, t in tail if A["S5 書面協商結論標題"].match(t)]
        sig = [t for _, _, t in tail if A["S6 簽名欄"].match(t)]
        heads = [t for _, _, t in tail if R_DOCHEAD.search(t[:20])]
        # 散會後的簽名人名、掃描圖不算口說；文件標題（共同聲明、附錄…）之後的內容是該文件的本文
        cut = next((k for k, (_, _, t) in enumerate(tail) if R_DOCHEAD.search(t[:20])), len(tail))
        talk = [t for _, w, t in tail[:cut] if is_say(w) and len(t) >= 15 and R_SAYLIKE.search(t)
                and not SPKMOD.IMAGE.search(t)]
        name = "書面協商結論" if conc else (heads[0][:20] if heads else "散會後的排版文字")
        attach.append({"name": name, "段數": len(tail),
                       "結論頁": len(conc), "簽名欄": len(sig), "sample": tail[0][2][:120]})
        if talk:
            notes.append(f"⚠ 散會之後還有 {len(talk)} 段口說，請確認散會判斷：{talk[0][:60]}")
    if any(A["T3 同場另開一場協商"].search(t) for _, w, t in body if w == "主席"):
        notes.append("⚠ 同一份紀錄裡主席又開了下一場協商（沒有新標頭），本版沒有切開")
    secs = cut_sections(body)
    tags = []
    topics = " ".join(h["協商主題"])
    if R_PROC.search(topics):
        tags.append("程序協商")
    if len(h["協商主題"]) >= 2:
        tags.append(f"協商主題 {len(h['協商主題'])} 行")
    host = host_info(h, rec)
    tags.insert(0, host["主持層級"])
    rev = review_info(h) if h.get("主席") else {}
    return {**h, **host, **rev, "非代表發言": non_rep_speakers(h, rec),
            "時長分": minutes(h["時間"]), "段落數": len(rec), "口說段": sum(1 for _, w, _ in body if is_say(w)),
            "tags": tags, "sections": secs, "attachments": attach, "notes": notes}


def segment(lcidc):
    ps = NG.paras(lcidc)
    if not ps:
        return {"lcidc": lcidc, "error": "parsed 空或抓取失敗", "sessions": []}
    sp = NG.speaker_paras(ps)
    recs = NG.records(sp)
    notes = []
    if not any(A["S1 會議紀錄標題"].match(t) for _, t in sp):
        if not any(t.startswith("協商主題") for _, t in sp[:15]):
            # 公報目錄類別代碼 8 底下混了非協商內容：1095401_00015 是「捷克參議院韋德齊議長立法院演說」專載
            return {"lcidc": lcidc, "error": f"不是協商紀錄（沒有紀錄標題也沒有協商主題）：{' '.join(t for _, t in sp[:2])[:40]}",
                    "sessions": []}
        notes.append("⚠ 這份公報文件沒有「會議紀錄」標題，整份當成一場協商")
    sessions = [segment_record(r) for r in recs]
    # B3：/txt 已快取時，標出書面結論本文是不是圖（parsed 會略過圖片，看起來像結論是空的）
    key = os.path.join(NG.CACHE, f"_gazette_agenda_doc_{lcidc}_txt.txt")
    if os.path.exists(key):
        kinds = [k for k in conclusion_images(open(key, encoding="utf-8").read()) if k != "無結論頁"]
        atts = [a for x in sessions for a in x["attachments"] if a["結論頁"] or a["簽名欄"]]
        for a in atts:
            a["結論本文"] = "、".join(sorted(set(kinds))) or "判不出來"
            if "圖" in kinds or "混合" in kinds:
                a["name"] += "（⚠ 本文含圖片，文字層不存在）"
    return {"lcidc": lcidc, "sessions": sessions, "notes": notes}


def all_docs():
    out, seen = [], set()
    for a in NG.catalog():
        d = NG.doc_id(a)
        if d and d not in seen and not NG.JUNK.search(a.get("案由") or ""):
            seen.add(d)
            out.append((a.get("屆"), d, a))
    return out


def cached(d):
    return os.path.exists(os.path.join(NG.CACHE, f"_gazette_agenda_doc_{d}_parsed.json"))


# ---------------------------------------------------------------- B3：書面結論本文是不是圖
def fetch_txt(d):
    key = os.path.join(NG.CACHE, f"_gazette_agenda_doc_{d}_txt.txt")
    if os.path.exists(key):
        return open(key, encoding="utf-8").read()
    for a in range(4):
        try:
            NG._throttle()
            body = urllib.request.urlopen(urllib.request.Request(
                quote(f"{NG.API}/gazette_agenda_doc/{d}/txt", safe=":/"),
                headers={"User-Agent": "research-ly-meet/1.0 (caucus)"}), timeout=120).read().decode("utf-8")
            open(key, "w", encoding="utf-8").write(body)
            return body
        except Exception as e:
            err = e
    print(f"  [txt 失敗] {d} → {err}", file=sys.stderr)
    return None


R_FIELD = re.compile(r'^(?:[一二三四五六七八九十]+、)?\s*(?:時\s*間|地\s*點|協商主題|日\s*期)\s*[：:]?')
R_CONC_FIELD = re.compile(r'^(?:[一二三四五六七八九十]+、)?\s*(?:協商結論|結\s*論|決定事項)\s*[：:]?')
R_DATEONLY = re.compile(r'^\d{2,3}年\d{1,2}月\d{1,2}日')


def conclusion_images(txt):
    """每一個書面結論頁的「結論本文」是圖、文字、混合、還是空。
    範圍：上一個「散會（」（或文件開頭）到「協商主持人」。時間、地點、協商主題欄是打字的，
    只有結論本文會是圖（1049805_00004：時間地點是文字、本文是 image1.jpg），所以欄位不算進本文。"""
    out, lines = [], txt.splitlines()
    sig = re.compile(r'^(?:協商)?主\s*持\s*人[：:]|^協商代表[：:]')   # 簽名欄三種寫法：協商主持人／主持人／只有協商代表
    for k, l in enumerate(lines):
        if not sig.match(l.strip()):
            continue
        if k > 0 and any(sig.match(x.strip()) for x in lines[max(0, k - 3):k]):
            continue
        # 起點：往回最近的「散會（」／「黨團協商結論」標題／上一個簽名欄，限 150 行內；找不到＝簽名欄直接接在逐字稿後，沒有結論頁
        st = None
        for j in range(k - 1, max(-1, k - 151), -1):
            x = lines[j].strip()
            if re.match(r'^散會[（(]', x) or sig.match(x) or re.match(r'^(?:立法院)?.{0,4}黨團協商結論$', x):
                st = j + 1
                break
        if st is None:
            out.append("無結論頁")
            continue
        state, body, imgs = "body", [], 0
        for l2 in lines[st:k]:
            l2 = l2.strip()
            if not l2:
                continue
            if re.search(r'\[image:', l2):
                imgs += len(re.findall(r'\[image:', l2))
                continue
            if re.match(r'^(?:立法院)?.{0,4}黨團協商結論$', l2):
                continue
            if R_FIELD.match(l2):
                state = "field"; continue
            if R_CONC_FIELD.match(l2):
                state = "body"; l2 = R_CONC_FIELD.sub("", l2)
            if state == "field" or R_DATEONLY.match(l2):
                continue
            body.append(l2)
        n = len("".join(body))
        out.append("圖" if imgs and n < 40 else "混合" if imgs else "文字" if n >= 40 else "空")
    # 第 10 屆的書面結論頁有標題、沒有簽名欄：標題後 200 行內沒有簽名欄 → 算到下一場紀錄標題或文件結尾
    title = re.compile(r'^(?:立法院)?.{0,4}黨團協商結論$')
    for j, l in enumerate(lines):
        if not title.match(l.strip()) or any(sig.match(x.strip()) for x in lines[j + 1:j + 201]):
            continue
        body, imgs = [], 0
        for x in lines[j + 1:]:
            x = x.strip()
            if re.match(r'^立法院第.{1,3}屆.{0,12}會議紀錄', x) or title.match(x):
                break
            if "[image:" in x:
                imgs += x.count("[image:"); continue
            if x and not R_FIELD.match(x) and not R_DATEONLY.match(x):
                body.append(R_CONC_FIELD.sub("", x))
        n = len("".join(body))
        out.append("圖" if imgs and n < 40 else "混合" if imgs else "文字" if n >= 40 else "空")
    return out


def cmd_images():
    rows = [(t, d) for t, d, _ in all_docs() if cached(d)]
    by = collections.defaultdict(collections.Counter)
    docs = collections.defaultdict(lambda: collections.Counter())
    examples = collections.defaultdict(list)
    for t, d in rows:
        sp = NG.speaker_paras(NG.paras(d))
        if not any(A["S5 書面協商結論標題"].match(x) or A["S6 簽名欄"].match(x) for _, x in sp):
            continue
        txt = fetch_txt(d)
        if txt is None:
            by[t]["抓取失敗"] += 1
            continue
        kinds = [k for k in conclusion_images(txt) if k != "無結論頁"]
        docs[t]["有結論頁或簽名欄"] += 1
        # 文件層級：有任一頁是圖就算「含圖」；全是文字算「文字」；只有空（簽名欄緊接散會）算「只有簽名」
        doc_kind = ("含圖" if any(k in ("圖", "混合") for k in kinds) else "文字" if "文字" in kinds
                    else "只有簽名" if kinds else "找不到結論頁")
        by[t][doc_kind] += 1
        if len(examples[doc_kind]) < 4:
            examples[doc_kind].append(d)
    print(f"## B3 書面結論本文是圖還是文字（已快取 {len(rows)} 份中，parsed 有結論頁或簽名欄者）")
    for t in sorted(docs):
        print(f"  屆{t}：{docs[t]['有結論頁或簽名欄']} 份 → {dict(by[t])}")
    tot = sum((by[t] for t in by), collections.Counter())
    print(f"  合計（文件數）：{dict(tot)}")
    for k, v in examples.items():
        print(f"  例（{k}）：{', '.join(v)}")


# ---------------------------------------------------------------- 批次檢查
def cmd_check():
    rows = [(t, d) for t, d, _ in all_docs()]
    have = [(t, d) for t, d in rows if cached(d)]
    print(f"## 黨團協商切分 — 已快取 {len(have)}/{len(rows)} 份文件（排除索引／勘誤）")
    st = collections.Counter()
    by_t = collections.defaultdict(collections.Counter)
    sec_n, warn = [], collections.Counter()
    kinds = collections.Counter()
    tags = collections.Counter()
    cs = collections.defaultdict(collections.Counter)
    cs["_雙主席"] = []
    for t, d in have:
        r = segment(d)
        if r.get("error"):
            st["空或失敗"] += 1
            continue
        st["文件"] += 1
        st["session"] += len(r["sessions"])
        by_t[t]["文件"] += 1
        by_t[t]["session"] += len(r["sessions"])
        for n in r["notes"]:
            warn[n[:14]] += 1
        for s in r["sessions"]:
            com_stats(cs, d, s)
            sec_n.append(len(s["sections"]))
            for n in s["notes"]:
                warn[n[:16]] += 1
            for x in s["tags"]:
                tags["session:" + re.sub(r'\d+', 'N', x)] += 1
            if s["attachments"]:
                tags["session:有 attachment"] += 1
            for sec in s["sections"]:
                kinds[sec["kind"]] += 1
                for x in sec["tags"]:
                    tags["section:" + (x.split("：")[0])] += 1
    sec_n.sort()
    print(f"  {dict(st)}")
    for t in sorted(by_t):
        print(f"    屆{t}：{dict(by_t[t])}")
    if sec_n:
        print(f"  每個 session 的 section 數：中位數 {sec_n[len(sec_n)//2]}，範圍 {sec_n[0]}–{sec_n[-1]}；"
              f"只有 1 個 section 的 {sum(1 for x in sec_n if x <= 1)}")
    print(f"  section 起點類型：{dict(kinds)}")
    print(f"  標籤：{dict(tags.most_common())}")
    print(f"  警告：{dict(warn.most_common())}")
    print_com_stats(cs)
    return True


def com_stats(cs, d, s):
    """--check：主席所屬委員會、議案審查委員會的覆蓋率與依據（2026-10-05 C1/C2）。"""
    lv = s.get("主持層級") or "？"
    if lv == "（無主席欄）":
        cs["主持層級"][lv] += 1
        return
    cs["主持層級"][lv] += 1
    if lv == "委員主持":
        cs["主席所屬委員會"][s.get("委員會依據") if s.get("委員會") else "查不到"] += 1
    rev = s.get("議案審查委員會") or []
    cs["議案審查覆蓋"][(lv, "有" if rev else "未交委員會審查" if s.get("議案未交委員會審查") else "查不到")] += 1
    if s.get("議案未交委員會審查"):
        cs["未交委員會審查例"][f"{lv}｜{d}｜{s['議案未交委員會審查'][:50]}"] += 1
    src = s.get("議案審查來源") or {}
    cs["依據組合"][(lv, "＋".join(REVIEW_SRC[k] for k in REVIEW_SRC if k in src) or "（無）")] += 1
    cs["議案審查覆蓋（不含推定）"][(lv, "有" if rev and not s.get("議案審查推定") else "查不到")] += 1
    for k in src:
        cs["各依據"][(lv, REVIEW_SRC[k])] += 1
    cs["meet 對應"][(lv, "對到" if s.get("協商會議代碼") else re.sub(r'\d+', 'N', s.get("協商會議對應") or ""))] += 1
    for k, v in (s.get("API 議案狀況") or {}).items():
        cs["API 議案狀況"][k] += v
    if not rev and not s.get("議案未交委員會審查"):
        cs["查不到原因"][(lv, "；".join(s.get("議案審查依據") or []))[:60]] += 1
    # 協商主題文字 vs API（承辦委員會＋協商會議議案＋審查報告）
    txt, api = set(src.get("text") or []), set((src.get("unit") or []) + (src.get("bill") or []) + (src.get("report") or []))
    if txt and api:
        k = ("一致" if txt == api else "API 是文字的子集（聯席只列主審等）" if api < txt else "文字是 API 的子集（文字只寫到其中幾項等）" if txt < api
             else "部分重疊" if txt & api else "完全不同")
        cs["文字 vs API"][k] += 1
        if k not in ("一致",):
            cs["文字 vs API 例"][f"{k}｜{d}｜文字 {'、'.join(sorted(txt))}｜API {'、'.join(sorted(api))}"] += 1
    own = set(s.get("委員會") or []) if lv == "委員主持" else set()
    if own and rev:
        k = "相同" if own == set(rev) else "完全不同" if not own & set(rev) else "部分不同"
        cs["與主席所屬委員會"][k] += 1
        if k != "相同":
            cs["與主席所屬委員會例"][f"{k}｜{d}｜主席 {'、'.join(own)}｜審查 {'、'.join(rev)}"] += 1
    sp = s.get("議案審查補充推定") or {}
    if sp:
        cs["補充推定"][(lv, "推定多出委員會" if sp.get("委員會") else "有未涵蓋的法律，推定不到或結果已在直接依據內"
                        if sp.get("法律") else "只有非法案項目")] += 1
        if sp.get("委員會"):
            cs["補充推定例"][f"{lv}｜{d}｜直接 {'、'.join(rev)}｜推定多出 {'、'.join(sp['委員會'])}｜未涵蓋 {'、'.join(sp['未涵蓋法律'])}"] += 1
    if len(person_names(s.get("主席"))) > 1:
        cs["_雙主席"].append(f"{d}｜{s['主席']}｜" + "；".join(committee_lines(s)))


def print_com_stats(cs):
    print("\n## 主席所屬委員會／議案審查委員會（C1、C2）")
    print(f"  主持層級（session）：{dict(cs['主持層級'])}")
    print(f"  委員主持的主席所屬委員會依據：{dict(cs['主席所屬委員會'])}")
    for lv in ("院長主持", "委員主持"):
        y, n = cs["議案審查覆蓋"][(lv, "有")], cs["議案審查覆蓋"][(lv, "查不到")]
        z = cs["議案審查覆蓋"][(lv, "未交委員會審查")]
        y2 = cs["議案審查覆蓋（不含推定）"][(lv, "有")]
        print(f"  議案審查委員會覆蓋率｜{lv}：有委員會 {y}/{y + n + z}（不含⑤推定：{y2}/{y + n + z}）；"
              f"API 顯示沒交委員會審查 {z}；查不到 {n}")
        print(f"     依據組合：{ {k[1]: v for k, v in cs['依據組合'].most_common() if k[0] == lv} }")
        print(f"     各依據（可重複）：{ {k[1]: v for k, v in cs['各依據'].most_common() if k[0] == lv} }")
        print(f"     API 協商會議對應：{ {k[1]: v for k, v in cs['meet 對應'].most_common() if k[0] == lv} }")
        print(f"     查不到原因：{ {k[1]: v for k, v in cs['查不到原因'].most_common() if k[0] == lv} }")
    print(f"  API 議案逐案狀況（案次）：{dict(cs['API 議案狀況'])}")
    print(f"  沒交委員會審查的場次（逕付二讀／無交付紀錄／建請院會決議）：{sum(cs['未交委員會審查例'].values())}")
    for k in sorted(cs["未交委員會審查例"]):
        print(f"     {k}")
    vt = cs["文字 vs API"]
    print(f"  協商主題文字 vs API（承辦委員會＋協商會議議案＋審查報告；兩者都有的 {sum(vt.values())} 場）：一致 {vt['一致']}／{sum(vt.values())}；{dict(vt)}")
    for k in sorted(cs["文字 vs API 例"]):
        print(f"     {k}")
    print(f"  委員主持：議案審查委員會 vs 主席所屬委員會（兩者都有的場次）：{dict(cs['與主席所屬委員會'])}")
    for k in sorted(cs["與主席所屬委員會例"]):
        print(f"     {k}")
    print(f"  有直接依據、但只涵蓋部分議題的場次（補充推定另列一行）：")
    for lv in ("院長主持", "委員主持"):
        print(f"     {lv}：{ {k[1]: v for k, v in cs['補充推定'].most_common() if k[0] == lv} }")
    for k in sorted(cs["補充推定例"]):
        print(f"     {k}")
    print(f"  主席欄有兩位的場次：{len(cs['_雙主席'])}")
    for x in cs["_雙主席"]:
        print(f"     {x}")


# ---------------------------------------------------------------- Ronny 版閱覽器（build_browser.py）
SEC_TYPE = {"開場": "開場", "點名": "發言", "換題": "主席換題", "結論": "協商結論", "討論": "宣讀後討論"}


def coms_str(coms):
    return "、".join(f"{c}委員會" for c in coms)


def committee_lines(s):
    """網站 session 內容的兩行：主席所屬委員會、議案審查委員會（分開寫，各附依據）。"""
    lv = s.get("主持層級", "")
    if lv == "院長主持":
        chair = "主席所屬委員會：不適用（院長主持）"
    elif s.get("委員會依據") == "主席該會期所屬委員會":
        per = s.get("主席委員會明細") or {}
        who = "；".join(f"{n}：{'、'.join(c) if c else 'API 查不到'}" for n, c in per.items()) if len(per) > 1 else ""
        chair = f"主席所屬委員會：{coms_str(s['委員會'])}（{who + '；' if who else ''}依立法院 API 委員資料，主席該會期所屬的常設委員會）"
    elif s.get("委員會"):
        chair = f"主席所屬委員會：立法院 API 查不到主席資料（{s['委員會依據']}寫的委員會：{coms_str(s['委員會'])}）"
    else:
        chair = "主席所屬委員會：查不到"
    rev = s.get("議案審查委員會") or []
    basis = s.get("議案審查依據") or []
    used = [x for x in basis if not x.startswith("（未採用）")]
    off = "；".join(x for x in basis if x.startswith("（未採用）"))
    if rev:
        tag = "議案審查委員會（推定）" if s.get("議案審查推定") else "議案審查委員會"
        line = f"{tag}：{coms_str(rev)}（依據：{'；'.join(used)}）"
        own = s.get("委員會") if lv == "委員主持" else None
        if own:
            line += ("　⚠ 和主席所屬委員會完全不同" if not set(own) & set(rev)
                     else "　⚠ 和主席所屬委員會部分不同" if set(own) != set(rev) else "")
        if off:
            line += f"　{off}"
    elif s.get("議案未交委員會審查"):
        line = f"議案審查委員會：無，議案沒有經委員會審查（{s['議案未交委員會審查']}）" + (f"　{off}" if off else "")
    else:
        line = f"議案審查委員會：{'；'.join(used or ['查不到'])}" + (f"　{off}" if off else "")
    return [chair, line] + supplement_lines(s)


def supplement_lines(s):
    """補充推定那一行：直接依據沒涵蓋到的法律、推定多出的委員會、不是法案的項目（見 supplement_infer()）。"""
    sp = s.get("議案審查補充推定") or {}
    if not sp:
        return []
    rev = set(s.get("議案審查委員會") or [])
    parts = []
    same = []
    for name, c, note, p2 in sp.get("法律") or []:
        if not c and note.startswith("名稱不同："):
            other = re.sub(r'^名稱不同：協商日當時尚未三讀的議案，逐部法律：[^：]+：', '', note)
            parts.append(f"{name}：沒有和協商主題同名的議案，只有同一法律的其他議案（{other}），不列入")
            continue
        if not c:
            parts.append(f"{name}：" + ("API 查不到這個法律名稱（紀錄寫法可能和法律全名不同）" if name in sp.get("查不到法律", [])
                                         else "議案逕付二讀或沒有交付紀錄，沒有交委員會" if p2 else "推定不到"))
            continue
        if not [x for x in c if x not in rev]:
            same.append(name)            # 推定結果已在直接依據裡，只列名字
            continue
        why = re.sub(r'^協商日當時尚未三讀的議案，逐部法律：[^：]+：', '', note)
        parts.append(f"{name} → {'、'.join(c)}（{why}）")
    if same:
        parts.append(f"{'、'.join(same)}的推定結果已在上一行")
    tail = []
    if parts:
        tail.append("上一行的直接依據沒涵蓋到的法律，依協商日當時尚未三讀的議案逐部推定：" + "；".join(parts))
    if sp.get("非法案項目"):
        tail.append(f"協商主題 {sp['項目數']} 項中 {sp['非法案項目']} 項不是法案審議或沒寫法律名稱（預算、人事、程序等），沒有審查委員會可列")
    if sp.get("委員會"):
        return [f"其他法律的審查委員會（推定）：{coms_str(sp['委員會'])}（{'。'.join(tail)}）"]
    return [f"⚠ 議案審查委員會只涵蓋部分議題：{'。'.join(tail)}"]


def to_meetjson(lcidc, title_of=None):
    """轉成 scripts/build_browser.py 讀的 meet-json：session＝一場會議紀錄、section＝本檔的 section、blocks＝逐段。"""
    r = segment(lcidc)
    if r.get("error"):
        return None
    recs = NG.records(NG.speaker_paras(NG.paras(lcidc)))
    sessions = []
    for rec, s in zip(recs, r["sessions"]):
        m = R_DATE.search(s["時間"])
        date = f"{int(m.group(1)) + 1911}-{int(m.group(2)):02d}-{int(m.group(3)):02d}" if m else s["時間"][:20]
        secs = []
        for sec in s["sections"]:
            tags = "".join(f"〔{x}〕" for x in sec["tags"])
            secs.append({"type": SEC_TYPE[sec["kind"]], "label": f"{sec['name']}{tags}", "kind": "ivod",
                         "blocks": [{"time": None, "speaker": (w if is_say(w) else ""), "text": t}
                                    for w, t in rec[sec["start"]:sec["end"] + 1] if t]})
        for a in s["attachments"]:
            secs.append({"type": "書面資料", "label": f"{a['name']}（{a['段數']} 段）", "kind": "attachment",
                         "gazette_text": a["sample"]})
        host = s.get("主持層級", "")
        nr = s.get("非代表發言")
        nr_line = ("非協商代表發言：無協商代表名單，無法判斷" if nr is None else
                   "非協商代表發言：無" if not nr else
                   "非協商代表發言：" + "、".join(f"{x['委員']}（{x['段數']} 段{'，提案人' if x['提案人'] else ''}）" for x in nr))
        head = [s["標題"], f"主席：{s['主席']}", f"主持：{host}"] + committee_lines(s) + [nr_line] \
            + [f"協商主題：{x[:120]}" for x in s["協商主題"][:6]] \
            + [f"標籤：{'、'.join(s['tags'])}"] * bool(s["tags"]) + s["notes"]
        sessions.append({"date": date, "content": "\n".join(head), "sections": secs})
    hosts = sorted({x.get("主持層級", "") for x in r["sessions"]} - {""})
    return {"meet_id": lcidc, "meet_type": "黨團協商", "title": (title_of or {}).get(lcidc, ""), "sessions": sessions,
            "host": "／".join(hosts)}


def write_viewer(path, docs):
    spec = importlib.util.spec_from_file_location("build_browser", os.path.join(HERE, "build_browser.py"))
    bb = importlib.util.module_from_spec(spec); spec.loader.exec_module(bb)
    bb.SECTION_COLORS.update({"開場": ["#555", "#eee"], "發言": ["#5a1d8a", "#f0daff"],
                              "主席換題": ["#1d5c8a", "#daeaf7"], "協商結論": ["#1b6b3a", "#e2f5e9"],
                              "宣讀後討論": ["#8a5a1d", "#f7ecda"]})
    titles = {d: f"屆{t}｜{(a.get('案由') or '')[:40]}" for t, d, a in all_docs()}
    data = {}
    for d in docs:
        x = to_meetjson(d, titles)
        if x:
            data[f"{titles.get(d, d)[:46]}｜{d}"] = x
    bb.write_html(data, path, "黨團協商")
    print(f"寫出 {path}（{len(data)} 份）")


# ---------------------------------------------------------------- 預覽頁
def write_html(path, results):
    e = html.escape
    out = ["<!DOCTYPE html><html lang='zh-Hant'><meta charset='utf-8'>"
           "<meta name='viewport' content='width=device-width,initial-scale=1'>"
           "<title>黨團協商切分預覽</title><style>"
           "body{font:15px/1.75 'PingFang TC',sans-serif;max-width:1180px;margin:auto;padding:16px;background:#f7f7f9;color:#1b1f24}"
           ".m{background:#fff;border:1px solid #ddd;border-radius:8px;padding:12px 18px;margin:18px 0}"
           ".s{border-left:4px solid #3b7dd8;padding:6px 0 6px 12px;margin:10px 0}"
           ".k{display:inline-block;font-size:11px;padding:1px 7px;border-radius:9px;background:#eef1f5;color:#445;margin-left:6px}"
           ".t{display:inline-block;font-size:11px;padding:0 6px;border-radius:8px;background:#e8f3ea;color:#24603a;margin-left:4px}"
           ".warn{background:#fff4e5;border-left:4px solid #e08c00;padding:8px 12px;margin:8px 0;font-size:13.5px}"
           ".orig{font-size:13px;color:#333;background:#fbfbfd;border:1px solid #eee;padding:6px 9px;margin:4px 0;border-radius:4px}"
           ".sec{font-size:13.5px;border-top:1px dashed #e3e3e8;padding:5px 0}.sec.換題{background:#f3f7ff}.sec.結論{background:#f2fbf4}"
           ".r{font-size:12px;color:#777}details{margin:4px 0}summary{cursor:pointer;font-size:13.5px}"
           "h2{margin:4px 0 2px;font-size:17px}h1{font-size:21px}</style>",
           "<h1>黨團協商切分預覽（第一版）</h1>",
           "<p class='r'>session＝一場會議紀錄（S-A，A1 待 Ronny 拍板）；section＝被主席點名的人或主席換題，"
           "到下一次點名／換題為止；綠色小標籤是標籤（換案、換條、協商結論…），不另切一層。"
           "藍底 section 是主席換題、綠底是宣讀協商結論。⚠ 橘框是切分器自己標出的不確定處。</p>"]
    for r in results:
        if r.get("error"):
            out.append(f"<div class='m'><h2>{e(r['lcidc'])}</h2><p>{e(r['error'])}</p></div>")
            continue
        out.append(f"<div class='m'><h2>{e(r['lcidc'])}　<span class='r'>{len(r['sessions'])} 場會議紀錄</span></h2>")
        for n in r["notes"]:
            out.append(f"<div class='warn'>{e(n)}</div>")
        for s in r["sessions"]:
            dur = f"{s['時長分']} 分" if s.get("時長分") is not None else "時長不明"
            out.append(f"<div class='s'><b>{e(s['標題'] or '（無標題）')}</b>"
                       f"<span class='k'>{len(s['sections'])} section</span><span class='k'>{dur}</span>"
                       + "".join(f"<span class='t'>{e(x)}</span>" for x in s["tags"])
                       + f"<div class='r'>{e(s['時間'])}　主席：{e(s['主席'])}</div>")
            if s["協商主題"]:
                out.append("<details><summary>協商主題（公報標頭）</summary>"
                           + "".join(f"<div class='orig'>{e(x[:220])}</div>" for x in s["協商主題"]) + "</details>")
            for n in s["notes"]:
                out.append(f"<div class='warn'>{e(n)}</div>")
            for sec in s["sections"]:
                spk = "、".join(sec["發言人"][:6]) + ("…" if len(sec["發言人"]) > 6 else "")
                out.append(f"<div class='sec {sec['kind']}'><b>{e(sec['name'])}</b>"
                           f"<span class='r'>　{sec['段數']} 段｜{e(spk)}</span>"
                           + "".join(f"<span class='t'>{e(x)}</span>" for x in sec["tags"])
                           + f"<div class='orig'>▶ {e(sec['anchor'])}</div></div>")
            for a in s["attachments"]:
                out.append(f"<div class='orig r'>📎 attachment：{e(a['name'])}（{a['段數']} 段；結論頁 {a['結論頁']}、"
                           f"簽名欄 {a['簽名欄']}）　{e(a['sample'])}</div>")
            out.append("</div>")
        out.append("</div>")
    open(path, "w", encoding="utf-8").write("".join(out))
    print(f"寫出 {path}（{len(results)} 份文件）")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("docs", nargs="*")
    ap.add_argument("--check", action="store_true")
    ap.add_argument("--browser", metavar="OUT")
    ap.add_argument("--images", action="store_true")
    ap.add_argument("--viewer", metavar="OUT", help="Ronny 版閱覽器（build_browser.py 的版型）；不給文件就放全部 343 份")
    a = ap.parse_args()
    if a.images:
        return cmd_images()
    if a.viewer:
        return write_viewer(a.viewer, a.docs or [d for _, d, _ in all_docs() if cached(d)])
    if a.browser:
        docs = a.docs or ([NG.doc_id(x) for _, x in NG.sample_docs()] + DEEP)
        seen = []
        for d in docs:
            if d not in seen:
                seen.append(d)
        write_html(a.browser, [segment(d) for d in seen])
        return
    if a.check or not a.docs:
        cmd_check()
        return
    for d in a.docs:
        print(json.dumps(segment(d), ensure_ascii=False, indent=1))


if __name__ == "__main__":
    main()
