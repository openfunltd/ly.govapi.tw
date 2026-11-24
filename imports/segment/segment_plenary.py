#!/usr/bin/env python3
"""
院會切分（第一版，2026-10-02）。

★ 2026-10-02 James 確認：**預設為粗顆粒**（segment_coarse）——
  session＝院會大段（國是論壇／施政質詢／報告事項／討論事項／總預算案／臨時提案），
  討論事項整段一個 session、每個案為 section，案內階段是 section 上的標籤。
  取代下列 C1（session＝一部法律）與 C3（section 按階段）；舊行為保留在 segment()，用 --fine 呼叫。

依 DECISIONS.md 的拍板實作。每條規則都標了出處，改規則前先看 DECISIONS。

已拍板（James 2026-10-01／10-02）：
  C1  討論事項 session＝一筆公報記錄（一部法律）
      例外① 併案審查型：記錄比段落標記細，起點改用主席的逐項裁示句
      例外② 跨冊切分型：同場＋同短名＋不同 gazette_id＋逐字稿有（前接／後接第N冊）→ 合併
  C2  延後案合成一個「未處理案件」session  〔2026-10-02 James：已被粗顆粒取代，只保留處置結果欄位；以下只在 --fine 有作用〕
      ＋逐案保留「處置結果」欄位
      ＋例外：總預算案不併進去，自成 session
      ＋例外：主席用詞「交付黨團協商」者必須切 section（18 筆中 12 筆有真實發言）
  C3  討論事項 section 按階段切（對「section＝誰在發言」破例，僅限院會討論事項）
  C4  施政總質詢 session＝一天一個（＝一筆公報記錄）          〔James 2026-10-02 確認〕
  C5  聯合質詢算一個 section（制度明文合併計時、上限 3 人）
  C6  書面質詢不佔 section                                 〔James 2026-10-02 確認〕
  C7  國是論壇照時間排序（放最前），不照公報順序              〔James 2026-10-02 確認〕
  C8  報告事項做 session 不切 section／質詢事項不做／臨時提案一案一 section 〔James 2026-10-02 確認〕
  C9  書面審查報告標 attachment、不計入正文字數
      ＋去重門檻＝「較小軌被較大軌包含率 ≥85%」，不是 Jaccard ≥95%
      ＋兩軌互有缺漏時不可丟棄任一軌，要並存並標註
  C10 記名表決不當 session，改成 session 內的結構化紀錄
  C12 先寫切分器、邊寫邊補（同意權 37／復議案 205／法案逐條 36 筆尚未讀過）

批次驗證（2026-10-02，64 場文件全快取的院會）：0 crash、1,174 筆記錄 → 1,142 sessions、
39 場觸發跨冊合併、30 場有「未配對」警告、法案 session 沒有 section 的 77/589（13%）。

⚠ 已知限制（不要當成已解決）：
  1. **仍有記錄配不到案次切片**（64 場中 30 場出現）。這些記錄各自成 session 並標「（未配對）」，
     若與已切片的案共用同一份文件，內容會與切片重複。notes 會印出警告，不是無聲失敗。
     配對鍵是案由去標頭後的 6-gram 命中率 ≥30%，門檻是試出來的、沒有調校過。
  2. **去重的比較基準與 B 線研究不同**。本檔比「單冊 vs 其餘各冊的聯集」，
     B 線研究比的是「冊1-7 軌 vs 冊8-14 軌」。兩者問的問題不同、答案也不同：
     院會-10-6-14 研究說兩軌互有缺漏各約 400 行獨有，本檔算出冊1-3 只獨有 27/0/1 行。
     **哪個才是對的取決於「軌」怎麼界定，而軌界目前是人工判斷的。**
     本檔採兩層門檻（≥95% 才丟、85–95% 只標記不丟）避免誤刪，但沒有解決軌界問題。
  3. 法案逐條討論的 section 規則只讀過 1/37 筆（溫管法），是草案。
  4. 臨時提案「一案一 section」2026-10-02 實作（motion_session），只讀過 2 場。

未讀過就不下規則（COLLAB 護欄 1）：同意權行使、專案報告質詢、總預算案報告質詢、
總決算審核報告、覆議案、總統彈劾案、國賓演講 → 一律 kind="unread"，只產生粗顆粒 session，
不切 section，並在 notes 標明「此型尚未人工讀過」。

用法：
  python3 scripts/segment_plenary.py 院會-11-2-18          # 單場
  python3 scripts/segment_plenary.py --check               # 機器自檢（不寫檔，粗顆粒）
  python3 scripts/segment_plenary.py --browser out.html 院會-10-6-14   # 預覽頁（粗顆粒）
  python3 scripts/segment_plenary.py --fine ...            # 舊的細顆粒版
  python3 scripts/segment_plenary.py --json 院會-11-2-18    # 輸出結構
"""
import argparse, collections, importlib.util, json, os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
sys.path.insert(0, HERE)
import speakers as SP
import stages
import plenary as PL

# ───────────────────────── 常數 ─────────────────────────

# 尚未人工讀過的段落型（COLLAB 護欄 1：不下規則）
UNREAD_TYPES = {"同意權行使", "附錄", "（無標題）"}

# 延後：主席用詞分三型（B2b 實測 275 個可靠窗口）
DEFER_SILENT = re.compile(r'協商後(?:再行)?處理|另定期處理|留待處理')      # 241 筆全部零發言
DEFER_SPEAK = re.compile(r'交(?:付)?黨團協商')                           # 18 筆中 12 筆有真實發言

# 跨冊（B1：content 只抓得到 20%，訊號在逐字稿裡）
CROSSVOL = re.compile(r'[（(][前後]接第[^）)]{1,4}冊[）)]')

# 討論事項的階段 anchor（C3）
#   逐條（法案）與逐款（預算）完全不重疊——itemized-and-images-2026-10-02.md 第 2.3 節
ITEMIZE_LAW = re.compile(r'宣讀第[^，。\s]{1,8}條')
ITEMIZE_LAW_END = re.compile(r'第[^，。\s]{1,8}條(?:照[^，。]{0,12}通過|保留)')
ITEMIZE_BUDGET = re.compile(r'宣讀第[^，。\s]{1,8}款')
ITEMIZE_BUDGET_END = re.compile(r'暫行保留')
PHASES = [
    ("宣讀協商結論", re.compile(r'宣讀(?:朝野)?(?:黨團)?協商結論|依協商結論')),
    ("廣泛討論", re.compile(r'進行廣泛討論')),
    ("大體討論", re.compile(r'進行大體討論|說明提案旨趣')),
    # 「宣讀第N條」之外也收「進行逐條討論」：第 8 屆常寫「本案進行逐條討論時，逕依審查會意見處理」，形式上仍走過逐條
    #（2026-10-02 全快取 1,046 案交叉比對：只看「宣讀第N條」漏標 40 案）
    ("逐條討論", re.compile(ITEMIZE_LAW.pattern + r'|進行逐條討論')),
    ("逐款討論", ITEMIZE_BUDGET),
    ("黨團代表發言", re.compile(r'各黨團推派一位委員發言|依政黨比例派員發言')),
    ("表決", re.compile(r'進行表決|宣讀第[^，。\s]{1,8}案')),
    ("三讀", re.compile(r'繼續進行三讀|已全部經過二讀')),
    ("秘密會議", re.compile(r'改開秘密會議|秘密會議結束|以下密')),
]

# 質詢（C4/C5/C6）
Q_CALL = re.compile(r'請([^，。\n]{2,60}?)(?:詢問|質詢|發言|諮詢)')   # 諮詢：總決算審核報告
Q_JOINT = re.compile(r'聯合質詢')
R_Q_END = re.compile(r'(?:質詢|詢答)(?:均已|已)?(?:全部)?(?:完畢|結束)|國是論壇[^。]{0,8}(?:告一段落|完畢|結束)|均已發言完畢|都已經發言完畢|散會')
Q_WRITTEN = re.compile(r'書面質詢')
SEG_Q = re.compile(r'^段落[：:]質詢[：:](.{2,14}?)[：:]')

BUDGET = re.compile(r'總預算')

# 案次宣告（B1：院會-8-8-1 實測 22 筆記錄對 21 次宣告）。
# 必須整句比對，否則會誤中「延長開會處理討論事項第一案…」這種敘述句。
CASE_CALL = re.compile(r'^(?:報告院會[，、])?(?:我們)?(?:現在)?(?:我們)?(?:繼續開會[，、])?(?:現在)?(?:繼續)?(?:接下來|接著)?(?:回頭|回到)?(?:再)?'
                       r'(?P<verb>進行|進入|處理|宣讀)?討論事項第([^，。、\s「]{1,8}?)案'
                       r'(?P<tail>「[^」]*」.*|(?![之的時前後及與並尚仍已還因在如若待均都等暨])[^，。]{2,80})?$')
# 也有只說「討論事項第三案。」（院會-11-5-18）；「現在回頭進行討論事項第一案」（院會-9-6-13）；
# 「我們現在進入討論事項第七案」（院會-11-3-8）。
# tail：案號後直接接案名「進行討論事項第九案產業創新條例部分條文修正草案」（院會-11-3-8）、
# 「回頭處理討論事項第二案『中華民國104年度中央政府總預算案』公開預算部分」（院會-8-6-19）。
# 有 tail 時一定要有動詞，否則「討論事項第一案尚在協商」這種敘述句會被當成換案（臨時會院會-9-1-1-1）
# 討論事項中間穿插的其他大段（跨天院會常見：隔天先同意權、下午才回頭續行討論事項）。案到這裡就結束，
# 這段不屬於任何一案，也不算進討論事項 session（它們各有自己的 session）
CASE_CALL_BARE = re.compile(r'^(?:報告院會)?(?:我們)?(?:現在)?(?:我們)?(?:繼續)?(?:接下來|接著)?(?:進行|處理|進入)?第([一二三四五六七八九十百零]{1,8})案$')   # 「進入第十二案」「好，第八案」
# 只收國字案號：阿拉伯數字的「進行第3案」「處理第10案，請宣讀國民黨黨團提案」是預算案裡的提案編號（臨時會院會-9-2-1-2、院會-9-2-16）
# 「討論事項均已處理完畢」之後不再收 bare 宣告：之後的「進行第五案」是臨時提案（院會-8-6-17、9-4-12）
R_CASES_OVER = re.compile(r'討論事項(?:均|都|已|全部)[^。，]{0,4}處理完畢|(?:處理|進行)臨時提案|(?:處理|進行)復議案|^散會')
R_BUDGET_CASE = re.compile(r'總預算|特別預算')
R_BUDGET_TITLE = re.compile(r'\d+年度中央政府總預算案')


def is_budget_case_line(t):
    """案由／宣告句本身就是總預算案（「即中華民國103年度中央政府總預算案」「(一)本院財政委員會報告彙總完成…總預算案…」）。
    總預算字樣前面有「黨團／有鑑於／建請／針對」的是講到總預算的決議案，不算（院會-11-2-6 等 10 場的譴責案、延會案）"""
    m = R_BUDGET_TITLE.search(t)
    return bool(m) and not re.search(r'黨團|有鑑於|有鑒於|建請|針對|要求', t[:m.start()])


def is_budget_case(ps):
    """宣告句「討論事項第四案，即中華民國103年度中央政府總預算案」：總預算字樣要在案號之後
    （「…總預算案機密預算部分已經處理完畢，現在改開公開會議，繼續進行討論事項第五案」是前一案收尾，院會-8-8-9）；
    案由行要是「八、(一)本院財政委員會報告彙總完成…總預算案…」這種編號開頭的行"""
    if not ps:
        return False
    t0 = ps[0]["text"]
    k = t0.find("討論事項第")
    if k >= 0 and is_budget_case_line(t0[k:]):
        return True
    if any(re.match(r'^(?:[一二三四五六七八九十百]+、)?[（(][一二三四五六七八九十][）)]|^[一二三四五六七八九十百]+、', x["text"].strip())
           and is_budget_case_line(x["text"]) for x in ps[1:3]):
        return True
    # 協商結論標題「中華民國104年度中央政府總預算案附屬單位預算營業及非營業朝野黨團協商結論」（院會-8-7-16 第一案）、
    # 主席「現在處理中華民國104年度中央政府總預算案，本案經提…」（院會-8-6-19 第二案）
    return any(re.match(r'^(?:中華民國)?\d+年度中央政府總預算案', x["text"].strip())
               or (x["speaker"] == "主席" and re.match(r'^(?:報告院會[，、])?(?:現在)?(?:回頭)?(?:處理|進行)(?:中華民國)?\d+年度中央政府總預算案',
                                                     x["text"].strip()))
               for x in ps[1:3])


def _cn_int(x):
    """「二十三」「23」→ 23；認不出回 None。"""
    if x is None:
        return None
    if str(x).isdigit():
        return int(x)
    d = {"零": 0, "一": 1, "二": 2, "三": 3, "四": 4, "五": 5, "六": 6, "七": 7, "八": 8, "九": 9}
    tot, cur = 0, 0
    for ch in str(x):
        if ch in d:
            cur = d[ch]
        elif ch == "十":
            tot += (cur or 1) * 10; cur = 0
        elif ch == "百":
            tot += (cur or 1) * 100; cur = 0
        else:
            return None
    return tot + cur


R_BUDGET_CALL = re.compile(r'請宣讀[^。]{0,12}彙整(?:總)?報告|(?:進行|處理)[^。]{0,20}總預算案(?:審查|之處理|處理)')
R_DISC_OPEN = re.compile(r'報告事項[^。，]{0,6}處理完畢|在進行討論事項之前|現在進行討論事項[。，]?$|進入討論事項[。，]?$')
R_OTHER_SEG = re.compile(r'(?:進行|處理)(?:同意權之行使|國是論壇|報告事項|施政質詢|臨時提案|復議案)|進行質詢事項')


EQ_FIELD = re.compile(r'eq\s*\\o\\ad\(\\s\\up\d+\(([^)]*)\),\s*\\s\\do\d+\(([^)]*)\)\)')


def clean_blocks(bs):
    """Word 注音欄位（原住民姓名的漢名＋族名上下排）攤平成「漢名族名」。
    院會-9-5-11：eq \\o\\ad(\\s\\up6(高天惠),\\s\\do6(Eleng Tjaljimaraw)) → 高天惠ElengTjaljimaraw"""
    out = []
    for b in bs or []:
        out.append([EQ_FIELD.sub(lambda m: m.group(1) + m.group(2).replace(" ", ""), x)
                    if isinstance(x, str) else x for x in (b or [])])
    return out


def doc_paras(lc):
    """一份文件的段落（走發言人狀態機）。"""
    out = []
    for p in SP.walk_blocks(clean_blocks(stages.blocks(lc))):
        p["lcidc"] = lc
        out.append(p)
    return out


def case_slices(lcs):
    """把文件串接後，依主席的案次宣告切成 [(案號, [段落])]。

    回傳 (slices, cover)：cover 是「有抓到案次宣告」的旗標，沒抓到時呼叫端要退回記錄層。
    """
    paras = [p for lc in lcs for p in doc_paras(lc)]
    marks = []
    for idx, p in enumerate(paras):
        if p["speaker"] != "主席" or p["kind"] != "say":
            continue
        for cl in re.split(r'[。\n]', p["text"]):
            m = CASE_CALL.match(cl.strip())
            if m:
                marks.append((idx, m.group(2)))
                break
    if not marks:
        return [], paras
    out = []
    for (a, no), nxt in zip(marks, marks[1:] + [(len(paras), None)]):
        out.append((no, paras[a:nxt[0]]))
    return out, paras


# ───────────────────────── 取資料 ─────────────────────────

def load(meet_id):
    for m in all_meets_fixed():
        if (m.get("會議代碼") or m.get("meet_id")) == meet_id:
            return m
    return None


# ── LYAPI 掛錯場次的公報文件：搬回正確院會（2026-10-06，James 同意；已回報 Ronny）──
# /meet/院會-… 的公報發言紀錄有些文件掛到別場（findings/plenary/lyapi-misaligned-docs-2026-10-06.md 的 A 類）。
# 判斷：公報目錄（/gazette_agendas）的會期／會次跟所掛院會不同，而且
#   (a) 文件開頭寫的場次＝公報目錄（或是臨時會），或 (b) 文件開頭沒寫場次、紀錄自己的 meetingDate 不在所掛院會的日期內。
# 文件開頭寫的就是所掛院會時不動——那是公報目錄的會次寫錯（B 類，API 對）。
# 只看一般院會掛的文件（臨時會院會的會次編法和公報目錄不同）。搬到正確的院會；那場已經有就不重複加；
# 找不到正確院會（委員會紀錄等）就從院會拿掉。每場留下 _doc_fix 紀錄，切分結果的 notes 會列出來。
# LYAPI 修好之後這裡不會再找到任何文件，不衝突。
R_DOC_HEAD = re.compile(r'第\s*(\d+)\s*屆第\s*(\d+)\s*會期(?:第\s*(\d+)\s*次臨時會)?第\s*(\d+)\s*次會議')
_FIXED = None


def _doc_head(lc):
    if not stages.has_doc(lc):
        return None
    for p in doc_paras(lc)[:15]:
        mm = R_DOC_HEAD.search(p["text"])
        if mm and "議事錄" not in p["text"]:
            return mm.groups()       # (屆, 會期, 臨時會次 或 None, 會次)
    return None


def _gazette_meet_of():
    spec = importlib.util.spec_from_file_location("hearing", os.path.join(os.path.dirname(os.path.abspath(__file__)), "hearing.py"))
    H = importlib.util.module_from_spec(spec); spec.loader.exec_module(H)
    out = {}
    for g in H.gazette_agendas():
        for d in H.doc_ids_of(g):
            out.setdefault(d, g)
    return out


def all_meets_fixed():
    global _FIXED
    if _FIXED is not None:
        return _FIXED
    import copy
    meets = copy.deepcopy(PL.all_meets())
    gz = _gazette_meet_of()
    code_of = {}
    for m in meets:
        c = m.get("會議代碼") or ""
        mm = re.match(r'^(臨時會)?院會-(\d+)-(\d+)-(\d+)(?:-(\d+))?$', c)
        if mm and not mm.group(1):
            code_of[(mm.group(2), mm.group(3), None, mm.group(4))] = c
        elif mm and mm.group(5):
            code_of[(mm.group(2), mm.group(3), mm.group(4), mm.group(5))] = c
    by_code = {m.get("會議代碼"): m for m in meets}
    have = collections.defaultdict(set)
    for m in meets:
        for r in m.get("公報發言紀錄") or []:
            have[m.get("會議代碼")].update(r.get("agenda_lcidc_ids") or [])
    adds = collections.defaultdict(list)
    for m in meets:
        c = m.get("會議代碼") or ""
        if not c.startswith("院會-") or not m.get("公報發言紀錄"):
            continue
        term, ses, no = str(m.get("屆")), str(m.get("會期")), str(m.get("會次"))
        days = set(m.get("日期") or [])
        kept = []
        for r in m["公報發言紀錄"]:
            stay, go = [], collections.defaultdict(list)
            md = r.get("meetingDate"); md = set(md if isinstance(md, list) else [md]) - {None}
            for lc in r.get("agenda_lcidc_ids") or []:
                g = gz.get(lc)
                if not g or (str(g.get("會期")), str(g.get("會次"))) == (ses, no):
                    stay.append(lc); continue
                h = _doc_head(lc)
                if h and (h[1], h[3]) == (ses, no) and not h[2]:
                    stay.append(lc); continue                  # B 類：文件開頭＝所掛院會，公報目錄錯
                if not h and (not md or md <= days):
                    stay.append(lc); continue                  # 判不出：不動
                key = (h[0], h[1], h[2], h[3]) if h else (term, str(g.get("會期")), None, str(g.get("會次")) if g.get("會次") is not None else None)
                dest = code_of.get(key)
                # 去處的開會日期要包含紀錄日期；不符時（文件沒寫場次、公報目錄會次又不可靠，例：院會-9-4-7 的
                # 2018-01-08 臨時會紀錄，目錄寫會次 1）改找同屆日期相符的唯一一場，找不到就拿掉
                if md and dest and not md <= set(by_code[dest].get("日期") or []):
                    cand = [x.get("會議代碼") for x in meets if str(x.get("屆")) == term
                            and re.match(r'^(臨時會)?院會-', x.get("會議代碼") or "") and md <= set(x.get("日期") or [])]
                    dest = cand[0] if len(cand) == 1 else None
                go[dest].append(lc)
            if stay or not (r.get("agenda_lcidc_ids") or []):
                kept.append({**r, "agenda_lcidc_ids": stay})     # 本來就沒附文件的紀錄（院會-9-1-1 的施政質詢）照留
            for dest, lcs in go.items():
                m.setdefault("_doc_fix", []).append(("移出", lcs, dest or "（找不到對應院會，拿掉）", (r.get("content") or "")[:30]))
                if dest and dest != c:
                    new = [x for x in lcs if x not in have[dest]]
                    if new:
                        adds[dest].append(({**r, "agenda_lcidc_ids": new}, c))
                        have[dest].update(new)
        m["公報發言紀錄"] = kept
    for dest, items in adds.items():
        dm = by_code[dest]
        recs = list(dm.get("公報發言紀錄") or [])
        for r, src in items:
            recs.append(r)
            dm.setdefault("_doc_fix", []).append(("移入", r["agenda_lcidc_ids"], src, (r.get("content") or "")[:30]))
        # 照文件編號排（公報順序），分類器靠記錄順序沿用標題
        recs.sort(key=lambda r: min(r.get("agenda_lcidc_ids") or ["~"]))
        dm["公報發言紀錄"] = recs
    _FIXED = meets
    return _FIXED


def rec_text(rec):
    """一筆記錄的全部段落（已走發言人狀態機）。"""
    out = []
    for lc in (rec.get("agenda_lcidc_ids") or []):
        bs = stages.blocks(lc)
        for p in SP.walk_blocks(bs):
            p["lcidc"] = lc
            out.append(p)
    return out


def shortname(content):
    """案名短名：去掉段落標題與尾標，取前 12 字。"""
    c = (content or "").strip()
    c = re.sub(r'^(討論事項|報告事項|施政質詢|國是論壇|臨時提案|質詢事項)\s*', '', c)
    c = re.sub(r'─[^─]*─\s*$', '', c).strip()
    first = c.split("\n")[0]
    return first[:12]


# ───────────────────────── 書面 vs 口說（C9）─────────────────────────

def long_lines(paras, minlen=20):
    return {p["text"].strip() for p in paras if len(p["text"].strip()) >= minlen}


def containment(a, b):
    """較小集合被較大集合包含的比率（C9：不是 Jaccard）。"""
    if not a or not b:
        return 0.0
    inter = len(a & b)
    return inter / min(len(a), len(b))


DROP_AT = 0.95       # ≥ 這個比例才敢真的丟（C9：兩軌互有缺漏時不可丟棄任一軌）


def dedupe_tracks(groups, thresh=0.85):
    """groups: [(label, paras)]。回傳 (keep, attachments, notes)。

    C9：門檻用「較小軌被較大軌包含率」≥0.85，不是 Jaccard。
    兩軌互有缺漏（都 <0.85 被包含）時**不可丟棄任一軌**，兩軌並存並標註。

    ⚠ 只比對每一軌與「其餘各軌的聯集」，不做 O(n²) 兩兩比對——
       同一軌內的相鄰冊本來就不重複（是同一份文件的不同部分），兩兩比會得到一堆 0%。
    """
    attach, notes, flagged = [], [], []
    if len(groups) < 2:
        return groups, attach, notes
    sets = {lab: long_lines(ps) for lab, ps in groups}
    for lab, mine in sets.items():
        if not mine:
            continue
        others = set()
        for other, s2 in sets.items():
            if other != lab and other not in attach:
                others |= s2
        if not others:
            continue
        c = len(mine & others) / len(mine)
        uniq = len(mine - others)
        if c >= DROP_AT:
            attach.append(lab)
            notes.append(f"【{lab}】這一冊 {c:.0%} 的內容在其他冊也有、只有 {uniq} 行是獨有的 → 當作重複印出的附件，不當發言")
        elif c >= thresh:
            flagged.append(lab)
            notes.append(f"⚠【{lab}】這一冊 {c:.0%} 的內容與其他冊重複、但有 {uniq} 行是獨有的 → 疑似重複印出，兩邊互有缺漏，"
                         f"所以兩冊都保留、不丟任何一冊")
    if not attach and not flagged:
        notes.append(f"各冊內容互有缺漏（沒有哪一冊有 {thresh:.0%} 以上的內容在其他冊出現），全部保留")
    if flagged:
        notes.append(f"【沒有去重】{len(flagged)} 冊的重複程度在 {thresh:.0%}–{DROP_AT:.0%} 之間，程式不敢判斷哪一冊是附件，"
                     f"要人翻公報 PDF 確認")
    keep = [(lab, ps) for lab, ps in groups if lab not in attach]
    return keep, attach, notes


def written_ratio(paras):
    """口說佔比。image 段（INCLUDEPICTURE）單獨計，不算進文字量。"""
    say = sum(len(p["text"]) for p in paras if p["kind"] == "say")
    img = sum(1 for p in paras if p["kind"] == "image")
    tot = sum(len(p["text"]) for p in paras if p["kind"] != "image")
    return say, tot, img


# ───────────────────────── section 切分 ─────────────────────────

def sections_phase(paras):
    """討論事項：按階段切（C3）。"""
    secs, cur = [], None
    for idx, p in enumerate(paras):
        if p["speaker"] != "主席" or p["kind"] != "say":
            continue
        t = p["text"]
        for name, rx in PHASES:
            if rx.search(t):
                if cur and cur["name"] == name:
                    break
                cur = {"name": name, "start": idx, "anchor": t[:60]}
                secs.append(cur)
                break
    # 同一位委員相鄰兩次：公報「段落：質詢：○○」標記＋主席「請○○質詢」、或主席重點名（「請大家回座。請周陳委員秀霞質詢」）
    # 只留第一次（2026-10-02 全快取場次相鄰同名 181 處）
    dedup = []
    for x in secs:
        if dedup and _same_member(dedup[-1]["name"], x["name"]):
            dedup[-1]["joint"] = dedup[-1]["joint"] or x["joint"]
            continue
        dedup.append(x)
    secs = dedup
    for a, b in zip(secs, secs[1:]):
        a["end"] = b["start"] - 1
    if secs:
        # 最後一位只到「質詢完畢／國是論壇告一段落／均已發言完畢」、散會或下一個大段為止：
        # 這些 session 收的是整份文件，同一份常接著別的大段
        last = secs[-1]["start"]
        stop = next((k for k in range(last + 1, len(paras)) if paras[k]["speaker"] in ("主席", None)
                     and (R_Q_END.search(paras[k]["text"]) or R_OTHER_SEG.search(paras[k]["text"])
                          or re.search(r'(?:進行|進入)討論事項', paras[k]["text"]))), None)
        secs[-1]["end"] = stop if stop is not None else len(paras) - 1
    return secs


def _same_member(a, b):
    """「劉書彬委員」＝「劉委員書彬」＝「登記最後一位劉書彬委員」。"""
    def norm(x):
        x = re.sub(r'^(?:登記)?(?:第[\d一二三四五六七八九十]+位|最後一位|下一位)', '', x or "")
        return re.sub(r'[\s（(].*$|質詢$|委員', '', x)
    na, nb = norm(a), norm(b)
    return bool(na) and na == nb


def sections_questioning(paras):
    """總質詢／國是論壇：一位委員一輪（C4/C5/C6）。"""
    secs = []
    for idx, p in enumerate(paras):
        m = SEG_Q.match(p["raw"]) if p.get("raw") else None
        if m:
            # 官員的「段落：質詢：林審計長慶隆」只在還沒有任何委員 section 時算（開頭的報告）；
            # 詢答中間的官員標記不切——section＝一位委員一輪，含他質詢的所有官員回答（C4）
            if SP.role_of(m.group(1)) != "legislator" and any(x.get("member") for x in secs):
                continue
            secs.append({"name": m.group(1), "start": idx, "kind": "質詢",
                         "joint": False, "anchor": p["raw"][:60],
                         "member": SP.role_of(m.group(1)) == "legislator"})
            continue
        if p["speaker"] == "主席" and p["kind"] == "say":
            t = p["text"]
            if Q_WRITTEN.search(t):
                continue                              # C6：書面質詢不佔 section
            m = Q_CALL.search(t)
            if m and re.search(r'答復|；|「|」|決議|閣員|各黨團|邀請|院會|就質詢', m.group(1)):
                m = None                              # 不是點名：收尾句「請行政院…就未答復部分予以書面答復；…」、
                                                      # 「請院長、閣員就質詢的範圍來答復」、「請各黨團推派代表發言」、提案內文
            if m:
                who = m.group(1)
                joint = bool(Q_JOINT.search(t))       # C5：聯合質詢算一個 section
                who = re.sub(r'^(?:登記)?(?:第[\d一二三四五六七八九十]+位|最後一位|下一位)', '', who)
                secs.append({"name": who[:40], "start": idx, "kind": "質詢",
                             "joint": joint, "anchor": t[:60], "member": "委員" in who})
    # 同一位委員相鄰兩次：公報「段落：質詢：○○」標記＋主席「請○○質詢」、或主席重點名（「請大家回座。請周陳委員秀霞質詢」）
    # 只留第一次（2026-10-02 全快取場次相鄰同名 181 處）
    dedup = []
    for x in secs:
        if dedup and _same_member(dedup[-1]["name"], x["name"]):
            dedup[-1]["joint"] = dedup[-1]["joint"] or x["joint"]
            continue
        dedup.append(x)
    secs = dedup
    for a, b in zip(secs, secs[1:]):
        a["end"] = b["start"] - 1
    if secs:
        # 最後一位只到「質詢完畢／國是論壇告一段落／均已發言完畢」、散會或下一個大段為止：
        # 這些 session 收的是整份文件，同一份常接著別的大段
        last = secs[-1]["start"]
        stop = next((k for k in range(last + 1, len(paras)) if paras[k]["speaker"] in ("主席", None)
                     and (R_Q_END.search(paras[k]["text"]) or R_OTHER_SEG.search(paras[k]["text"])
                          or re.search(r'(?:進行|進入)討論事項', paras[k]["text"]))), None)
        secs[-1]["end"] = stop if stop is not None else len(paras) - 1
    return secs


def _same_member(a, b):
    """「劉書彬委員」＝「劉委員書彬」＝「登記最後一位劉書彬委員」。"""
    def norm(x):
        x = re.sub(r'^(?:登記)?(?:第[\d一二三四五六七八九十]+位|最後一位|下一位)', '', x or "")
        return re.sub(r'[\s（(].*$|質詢$|委員', '', x)
    na, nb = norm(a), norm(b)
    return bool(na) and na == nb


# ───────────────────────── 主流程 ─────────────────────────

def segment(meet_id):
    m = load(meet_id)
    if not m:
        return {"meet_id": meet_id, "error": "找不到此 meet"}
    recs = m.get("公報發言紀錄") or []
    # classify_meet 回傳 [(型, 子型, 記錄, 尾標)]
    typed = PL.classify_meet(recs)
    sessions, notes, unread = [], [], collections.Counter()

    # ── 分組：先把記錄依型歸位 ──
    buckets = collections.defaultdict(list)
    for i, item in enumerate(typed):
        head, sub, r, tail = (list(item) + [None] * 4)[:4]
        head = head or "（無標題）"
        buckets[head].append((i, r, sub or "", tail or ""))

    # ── 1. 討論事項 ──
    disc = buckets.get("討論事項", [])

    # 1a. 討論事項：先把文件依主席的案次宣告切片，再把切片**配對回記錄**。
    #     只切片不配對會讓切片吸收鄰案（院會-11-2-15 實測 3 個切片吃掉 32 筆記錄的內容）；
    #     只用記錄層則多筆記錄共用一份文件時內容會整份重複（院會-10-6-14 三個 session 都是同樣的 444 段）。
    #     配對後：一個切片 = 一個 session，型別繼承配對到的記錄；配不到的記錄另行列出並標記。
    if disc:
        lcs = []
        for _, r, _, _ in disc:
            for lc in (r.get("agenda_lcidc_ids") or []):
                if lc not in lcs:
                    lcs.append(lc)
        slices, all_paras = case_slices(lcs)
        used = set()
        if slices:
            for no, ps in slices:
                # 配對鍵：案由去掉段落標頭與尾標後的前 60 字，拿 6-gram 去切片前段比對。
                # 不可直接用 content（開頭是「討論事項」標頭，與主席口語對不上）。
                body = re.sub(r'\s+', '', "".join((x["text"] or "") for x in ps[:60]))
                best, score = None, 0.0
                for j, (i, r, sub, tail) in enumerate(disc):
                    if j in used:
                        continue
                    c = re.sub(r'^(討論事項|報告事項)\s*', '', (r.get("content") or "").strip())
                    c = re.sub(r'─[^─]*─\s*$', '', c)
                    probe = re.sub(r'[\s（）()「」、，。：]', '', c)[:60]
                    if len(probe) < 10:
                        continue
                    grams = [probe[k:k + 6] for k in range(0, len(probe) - 5, 3)]
                    hit = sum(1 for g in grams if g in body) / len(grams)
                    if hit > score:
                        best, score = j, hit
                if best is not None and score >= 0.3:
                    used.add(best)
                    i, r, sub, tail = disc[best]
                    kind = "總預算案" if BUDGET.search(r.get("content") or "") else (
                        "延後" if "延後" in sub else "法案")
                    nm = f"第{no}案 {shortname(r.get('content'))}"  # 配對度 {score:.0%}
                else:
                    kind, nm = "法案", f"第{no}案 （配不到記錄）"
                    notes.append(f"⚠ 第{no}案的切片配不到任何公報記錄（案由比對不中），型別暫判為法案")
                sess = mk_session(nm, [(0, {}, "", "")], ps, kind, [])
                sess["_short"] = shortname(disc[best][1].get("content")) if best is not None else None
                sess["_paras"] = ps
                if kind == "延後":
                    sess["note"] = "C2：此案當天延後。切片仍保留，未併進「未處理案件」（因已有獨立案次宣告）"
                sessions.append(sess)
            notes.append(f"【案次切片】{len(slices)} 個切片／討論事項 {len(disc)} 筆記錄，"
                         f"配對成功 {len(used)} 筆")
        # 配不到切片的記錄：單獨列出並標記（可能是沒有案次宣告的延後案、或多冊同案）
        left = [(i, r, sub, tail) for j, (i, r, sub, tail) in enumerate(disc) if j not in used]
        budget_recs = [(i, r, sub, tail) for i, r, sub, tail in left if BUDGET.search(r.get("content") or "")]
        defer_cases = [(i, r, sub, tail) for i, r, sub, tail in left
                       if "延後" in sub and not BUDGET.search(r.get("content") or "")]
        orphan = [(i, r, sub, tail) for i, r, sub, tail in left
                  if "延後" not in sub and not BUDGET.search(r.get("content") or "")]
        # 未配對的記錄：先看是不是「跨冊同案」（與已配對 session 同短名）→ 併進去（C1 例外②）
        by_short = {s2.get("_short"): s2 for s2 in sessions if s2.get("_short")}
        merged, still = collections.Counter(), []
        for i, r, sub, tail in orphan:
            sn = shortname(r.get("content"))
            tgt = by_short.get(sn)
            if tgt is not None:
                extra = rec_text(r)
                if any(CROSSVOL.search(x["text"] or "") for x in extra) or True:
                    tgt["_paras"] = (tgt.get("_paras") or []) + extra
                    merged[sn] += 1
                    continue
            still.append((i, r, sub, tail))
        for sn, n in merged.items():
            tgt = by_short[sn]
            ps2 = tgt["_paras"]
            say, tot, img = written_ratio(ps2)
            tgt.update({"段落數": len(ps2), "口說字數": say, "文字總數": tot,
                        "口說佔比": round(100 * say / tot, 1) if tot else 0.0,
                        "圖片段數": img, "sections": sections_phase(ps2)})
            annotate_sections(tgt, ps2)
            notes.append(f"【跨冊合併】「{sn}」另有 {n} 筆續冊記錄併入同一 session（C1 例外②）")
        if still:
            notes.append(f"⚠【未配對】{len(still)} 筆非延後的討論事項記錄配不到案次切片、也不是跨冊同案 → "
                         f"各自成 session；若與已切片的案共用文件，內容會與切片重複")
            for i, r, sub, tail in still:
                sessions.append(mk_session(shortname(r.get("content")) + "（未配對）",
                                           [(i, r, sub, tail)], rec_text(r), "法案", []))
    else:
        budget_recs, defer_cases = [], []

    # 1b. 總預算案：自成 session（C2 例外），兩軌去重（C9）
    if budget_recs:
        groups = [(f"冊{n+1}", rec_text(r)) for n, (_, r, _, _) in enumerate(budget_recs)]
        keep, attach, dn = dedupe_tracks(groups)
        notes += dn
        paras = [p for lab, ps in groups for p in ps]
        s = mk_session("總預算案", budget_recs, paras, "總預算案", attach)
        s["votes"] = collect_votes(budget_recs)
        if s["votes"]["total"]:
            notes.append(f"【表決】{s['votes']['total']} 筆記名表決改成結構化紀錄、不當 session（C10）；"
                         f"⚠ votes 欄位只在帶 title 的文件上有，不可拿它當分母")
        sessions.append(s)

    # 1c. 延後案：合成一個「未處理案件」session（C2），但「交付黨團協商」型要切 section
    if defer_cases:
        paras = [p for _, r, _, _ in defer_cases for p in rec_text(r)]
        cases = []
        for _, r, t, tail in defer_cases:
            cases.append({"案名": shortname(r.get("content")), "處置結果": tail or "（未載）"})
        _say, _tot, _img = written_ratio(paras)
        s = {"name": "未處理案件", "kind": "延後", "記錄數": len(defer_cases),
             "段落數": len(paras), "口說字數": _say, "文字總數": _tot,
             "口說佔比": round(100 * _say / _tot, 1) if _tot else 0.0, "圖片段數": _img,
             "cases": cases, "sections": [], "attachments": [],
             "note": "C2：當天延後的案合成一個 session，逐案保留處置結果"}
        speak_paras, n_speak = [], 0
        for (_, r, t, tail) in defer_cases:
            if DEFER_SPEAK.search(tail or ""):
                n_speak += 1
                speak_paras += rec_text(r)
        if speak_paras:
            s["sections"] = sections_phase(speak_paras)
            annotate_sections(s, speak_paras)
            s["note"] += (f"；其中 {n_speak} 案為「交付黨團協商」型、已切 section"
                          f"（B2b：18 筆中 12 筆有真實發言）。"
                          f"「協商後再行處理／另定期處理」型不切（241 筆實測全部零發言）")
        else:
            s["note"] += "；全部為「協商後再行處理／另定期處理」型，不切 section（B2b 實測零發言）"
        sessions.append(s)

    # ── 2. 施政質詢／國是論壇 ──
    for head in ("施政質詢", "國是論壇", "專案報告質詢", "總預算案報告質詢"):
        for i, r, sub, tail in buckets.get(head, []):
            paras = rec_text(r)
            s = mk_session(head, [(i, r, sub, tail)], paras, head, [])
            s["sections"] = sections_questioning(paras)
            annotate_sections(s, paras)
            s["joint_sections"] = sum(1 for x in s["sections"] if x.get("joint"))
            if head == "國是論壇":
                s["order_hint"] = "first"      # C7：照時間放最前
            sessions.append(s)

    # ── 3. 報告事項／臨時提案（C8）──
    for i, r, sub, tail in buckets.get("報告事項", []):
        sessions.append(report_session(r))
    seen_recon = set()
    for i, r, sub, tail in buckets.get("臨時提案", []):
        c = re.sub(r'\s', '', r.get("content") or "")
        if "復議" in c and not c.startswith("臨時提案"):
            key = tuple(r.get("agenda_lcidc_ids") or [])
            if key not in seen_recon:
                seen_recon.add(key)
                rs = recon_session(r)
                if rs:
                    sessions.append(rs)
            continue
        sessions.append(motion_session(r))

    # ── 4. 質詢事項：不做 session（C8）──
    if buckets.get("質詢事項"):
        notes.append(f"【質詢事項】{len(buckets['質詢事項'])} 筆不做 session（C8，書面清單）")

    # ── 5. 未讀過的型：粗顆粒、不切 section（COLLAB 護欄 1）──
    for head, items in buckets.items():
        if head not in UNREAD_TYPES:
            continue
        for i, r, sub, tail in items:
            unread[head] += 1
            sessions.append({"name": head, "kind": "unread", "記錄數": 1,
                             "sections": [], "attachments": [],
                             "note": f"⚠ 此型尚未人工讀過（母體見 NEEDS-HUMAN-CHECK），"
                                     f"只產生粗顆粒 session、不切 section"})
    if unread:
        notes.append("【未讀過的型】" + "、".join(f"{k} {v} 筆" for k, v in unread.items())
                     + " → 不切 section（C12：邊寫邊補）")

    # C7：國是論壇排到最前
    for s2 in sessions:
        s2.pop("_paras", None); s2.pop("_short", None)
    sessions.sort(key=lambda s: 0 if s.get("order_hint") == "first" else 1)
    return {"meet_id": meet_id, "meet_type": "院會",
            "記錄數": len(recs), "sessions": sessions, "notes": notes}


R_DISC_END = re.compile(r'討論事項[^。，]{0,6}處理完畢|(?:處理|進行)臨時提案|(?:處理|進行)復議案|^散會')
R_DISC_END_OTHER = re.compile(r'(?:處理|進行)臨時提案|進行國是論壇')
R_RECON_START = re.compile(r'(?:處理|進行)復議案')
R_REPORT_START = re.compile(r'進行報告事項')
R_REPORT_END = re.compile(r'報告事項[^。，]{0,6}處理完畢')
R_MOTION_START = re.compile(r'(?:處理|進行)臨時提案')
R_MOTION_END = re.compile(r'臨時提案[^。，]{0,6}處理完畢')   # 均已／都已經／已全部
R_MOTION_CALL = re.compile(r'進行第([一二三四五六七八九十百\d]+)案[，,]?\s*(?:請(?:提案人)?([^，。說]{2,20}?)(?:代表[^，。]{2,10})?說明)?')


def speaker_turn_sections(paras):
    """依發言人換手切 section（主席的串場話歸下一位）：國賓演講這類沒有點名格式的段落用。"""
    secs = []
    for idx, p in enumerate(paras):
        if p["kind"] == "say" and p.get("new") and p["speaker"] and (not secs or secs[-1]["name"] != p["speaker"]):
            secs.append({"name": p["speaker"][:40], "start": idx, "kind": "發言", "joint": False, "anchor": p["text"][:60]})
    for a, b in zip(secs, secs[1:]):
        a["end"] = b["start"] - 1
    if secs:
        secs[-1]["end"] = len(paras) - 1
    return secs


def final_accounts_session(rec):
    """總決算審核報告（2026-10-02 讀過 2/10 筆：院會-8-8-2、9-8-2）：審計長報告 → 委員諮詢 → 審計長答復，
    結構同質詢；主席說「諮詢」不說「質詢」。同一份文件常接著臨時提案，從「進行審計長…報告」起算。"""
    paras = rec_text(rec)
    # 真正的開頭是「進行審計長報告…」或「請審計部…審計長報告」；上午的預告「下午2時30分進行…總決算審核報告…現在休息」不算（院會-9-8-2）
    def _start(rx):
        return next((k for k, p in enumerate(paras) if p["speaker"] == "主席" and not re.search(r'休息|[上下]午\d+時', p["text"])
                     and re.search(rx, p["text"])), None)
    st = _start(r'進行審計長|請審計部[^。]{0,8}審計長報告')
    if st is None:
        st = _start(r'進行[^。]{0,30}總決算[^。]{0,30}報告') or 0
    en = next((k for k in range(st + 1, len(paras)) if paras[k]["speaker"] == "主席"
               and (R_OTHER_SEG.search(paras[k]["text"]) or re.search(r'(?:進行|進入)討論事項|散會', paras[k]["text"]))), len(paras) - 1)
    region = paras[st:en + 1]
    s = mk_session("總決算審核報告", [(None, rec, "", "")], region, "總決算審核報告", [])
    secs = sections_questioning(region)
    if len(secs) <= 1:
        secs = [x for x in speaker_turn_sections(region) if x["name"] != "主席"]
        for a, b in zip(secs, secs[1:]):
            a["end"] = b["start"] - 1
        if secs:
            secs[-1]["end"] = len(region) - 1
    s["sections"] = secs
    annotate_sections(s, region)
    s["note"] = "總決算審核報告：審計長報告後委員諮詢，切法同質詢（讀過 2/10 筆）"
    return s


def guest_speech_session(rec):
    """國賓演講（讀過 1/3 筆：院會-10-8-4）：院長致詞介紹 → 外國貴賓演說 → 院長致謝，依發言人切。"""
    paras = rec_text(rec)
    # 演講後同一份文件常接著別的議程（院會-10-8-4 接同意權投票）：以貴賓講完後的「繼續開會」或下一個大段為界
    # 貴賓＝第一位非主席發言者；以他講完後主席的「繼續開會／請宣讀／下一個大段／休息」為界
    #（院會-10-7-5 演講後同一份文件還有秘書長報告、委員發言，原本找「最後一位非主席」會算到文件結尾）
    g0 = next((k for k, p in enumerate(paras) if p["kind"] == "say" and p["speaker"] not in (None, "主席")), None)
    if g0 is not None:
        gname = paras[g0]["speaker"]
        glast = g0
        for k in range(g0, len(paras)):
            if paras[k]["speaker"] == gname:
                glast = k
            elif paras[k]["kind"] == "say" and paras[k]["speaker"] not in (None, "主席"):
                break
        en = next((k - 1 for k in range(glast + 1, len(paras)) if paras[k]["speaker"] == "主席"
                   and (re.search(r'繼續開會|請宣讀|休息|散會', paras[k]["text"]) or R_OTHER_SEG.search(paras[k]["text"]))),
                  min(len(paras) - 1, glast + 5))
        paras = paras[:en + 1]
    s = mk_session("國賓演講", [(None, rec, "", "")], paras, "國賓演講", [])
    s["sections"] = speaker_turn_sections(paras)
    annotate_sections(s, paras)
    s["note"] = "國賓演講：依發言人切（讀過 1/3 筆）"
    return s


VOTE_START = {"總統彈劾案": re.compile(r'進行總統彈劾案事項|對彈劾案進行記名投票'),
              "覆議案": re.compile(r'覆議案之處理事項|對覆議案進行記名投票|處理覆議案')}
R_BALLOT = re.compile(r'^(?:贊成|反對|廢票|無效票?|棄權)$')                       # 彈劾：「【丁學忠委員】贊成」
R_BALLOT_LINE = re.compile(r'^(.{2,40}?)[\s　]+((?:贊成|反對)維持本院原決議)')   # 名字可含空格：「鄭天財 Sra Kacaw」       # 覆議：「羅美玲　反對維持本院原決議，即贊成覆議案」
R_RESULT_HEAD = re.compile(r'投票表決結果[：:]\s*$')
R_RESULT_LINE = re.compile(r'^((?:贊成|反對)(?:維持本院原決議|彈劾案)?)[^0-9]{0,20}?(\d+)\s*票')  # 「贊成彈劾案56票。」「贊成維持本院原決議，即反對覆議案60票。」


def ballot_session(rec, kind):
    """總統彈劾案、覆議案（2026-10-02 讀過：彈劾 1/1 筆 院會-11-5-10；覆議 3/7 筆 院會-11-3-1、11-2-17、11-4-12）。
    程序同同意權行使：宣讀全院委員會審查報告 → 記名投票 → 開票唱票 → 報告投票表決結果。一個 session、不切 section，
    程序步驟做標籤。唱票「【丁學忠委員】贊成」是投票紀錄不是發言：抽成 ballots、從發言中排除。"""
    paras = rec_text(rec)
    st = next((k for k, p in enumerate(paras) if VOTE_START[kind].search(p["text"])), None)
    note = []
    if st is None:
        st = next((k for k, p in enumerate(paras) if p_is_chair(p) and re.search(r'宣讀全院委員會審查報告', p["text"])), 0)
        if st == 0:
            note.append("⚠ 找不到起點宣告，從文件開頭算")
    # 結果的標題行「…覆議案投票表決結果：」最可靠（院會-11-3-4 沒有「報告投票表決結果」這句；
    # 院會-11-1-19 第二次結果在宣布句後第 9 段）。範圍延伸到最後一個標題之後
    heads = [k for k in range(st, len(paras)) if R_RESULT_HEAD.search((paras[k]["text"] or "").strip())]
    ends = [k for k in range(st, len(paras)) if p_is_chair(paras[k]) and CONSENT_END.search(paras[k]["text"])]
    en = max(heads + ends) if (heads or ends) else len(paras) - 1
    region = paras[st:en + 6]
    ballots = collections.Counter()
    voters = 0
    speech = []
    for p in region:
        t = (p["text"] or "").strip()
        m = R_BALLOT_LINE.match(t)
        if p["kind"] == "say" and p["speaker"] not in (None, "主席") and R_BALLOT.match(t):
            ballots[t] += 1; voters += 1
            continue
        if m:
            ballots[m.group(2)] += 1; voters += 1
            continue
        speech.append(p)
    s = mk_session(kind, [(None, rec, "", "")], speech, kind, [])
    s["tags"] = [name for name, rx in CONSENT_PHASES if any(p_is_chair(x) and rx.search(x["text"]) for x in region)]
    # 表決結果：每一次「報告投票表決結果」之後的「贊成…N票」「反對…N票」；同一場可能投兩次（院會-11-1-19 兩部法各一次）
    result, rounds = {}, 0
    starts = [k for k, x in enumerate(region) if R_RESULT_HEAD.search((x["text"] or "").strip())] or \
             [k for k, x in enumerate(region) if p_is_chair(x) and CONSENT_END.search(x["text"])]
    for k0 in starts:
        got = {}
        for x in region[k0 + 1:k0 + 6]:
            m = R_RESULT_LINE.match((x["text"] or "").strip())
            if m and m.group(1) not in got:
                got[m.group(1)] = int(m.group(2))
        if got:
            rounds += 1
            for kk, vv in got.items():
                result[kk] = result.get(kk, 0) + vv
    if result:
        s["vote_result"] = result
        s["投票次數"] = rounds
    if voters:
        s["ballots"] = {"委員數": voters, "加總": dict(ballots)}
        def key(lbl):
            return "贊成" if lbl.startswith("贊成") else "反對"
        rk = collections.Counter({key(k): v for k, v in result.items()})
        bk = collections.Counter()
        for k, v in ballots.items():
            bk[key(k)] += v
        mism = bool(result) and any(bk.get(x, 0) != rk.get(x, 0) for x in ("贊成", "反對"))
        if mism:
            note.append(f"⚠ 唱票加總 {dict(ballots)} 與表決結果 {result} 不一致")
        else:
            note.append(f"{voters} 位委員的唱票已整理成逐人投票紀錄、不當發言顯示" + ("，加總與主席宣布的表決結果一致" if result else ""))
    s["note"] = "；".join(note + [f"{kind}的程序跟同意權行使一樣（宣讀審查報告→記名投票→開票→宣布結果），整段算一個單元、不細分"])
    return s


def report_session(rec):
    """C8 報告事項：做 session、不切 section。只取「進行報告事項」到「報告事項…處理完畢」（或下一個大段）之間——
    報告事項常與討論事項登在同一份文件，整份當 session 會把討論事項也包進來（院會-9-6-13 報告事項原本 1,171 段）。"""
    paras = rec_text(rec)
    st = next((k for k, p in enumerate(paras) if R_REPORT_START.search(p["text"])), None)
    note = []
    if st is None:
        st = 0
        note.append("⚠ 找不到「進行報告事項」宣告，從文件開頭算")
    en = next((k for k in range(st + 1, len(paras)) if R_REPORT_END.search(paras[k]["text"])), None)
    if en is None:
        # 沒說「報告事項處理完畢」時，以下一個大段的開頭為界：「在進行討論事項之前…」「進行討論事項第八案。」
        en = next((k - 1 for k in range(st + 1, len(paras)) if paras[k]["speaker"] == "主席"
                   and (R_OTHER_SEG.search(paras[k]["text"]) or R_DISC_OPEN.search(paras[k]["text"])
                        or re.search(r'(?:進行|進入|處理)討論事項', paras[k]["text"]))
                   and not R_REPORT_START.search(paras[k]["text"])), len(paras) - 1)
        if en == len(paras) - 1:
            note.append("⚠ 主席沒有說「報告事項處理完畢」，也找不到下一個大段的開頭，這段算到文件最後一行；"
                    "後面接著開的質詢或討論事項可能被包進來（那些內容在它們自己的單元也看得到）")
    region = paras[st:en + 1]
    s = mk_session("報告事項", [(None, rec, "", "")], region, "報告事項", [])
    s["note"] = "；".join(note + ["報告事項整段算一個單元，不再逐案細分（2026-10-02 決定）"])
    return s


def recon_session(rec):
    """記錄層的復議案（分類器把它沿用在「臨時提案」等標題底下，院會-9-1-3、9-1-4）：
    從第一個提到復議的主席段起，到下一個大段／散會為止。🟡 同「討論事項以外的復議案自成 session」。"""
    paras = rec_text(rec)
    st = next((k for k, p in enumerate(paras) if p_is_chair(p) and re.search(r'復議', p["text"])), None)
    if st is None:
        return None
    en = next((k - 1 for k in range(st + 1, len(paras)) if p_is_chair(paras[k])
               and (R_DISC_END_OTHER.search(paras[k]["text"]) or re.search(r'^散會', paras[k]["text"].strip()))), len(paras) - 1)
    s = mk_session("復議案", [(None, rec, "", "")], paras[st:en + 1], "復議案", [])
    s["note"] = "公報把這筆記錄登在上一個標題底下，案由其實是復議案，所以獨立成一個復議案單元（復議案要不要獨立，還沒最後定案）"
    return s


def motion_session(rec):
    """C8 臨時提案：一案一 section（James 2026-10-02 確認）。

    只取「處理臨時提案」到「臨時提案…處理完畢」（＋散會那一行）之間——臨時提案常與隔天的國是論壇
    登在同一份文件（院會-10-7-10 的 1124602_00002），整份文件當 session 會把國是論壇也包進來。
    每一案從主席「進行第N案，請…說明提案旨趣」起算；提案人不在場（「本案暫不予處理」）也算一案、標「不在場」。
    [判讀|讀過 2 場：院會-9-8-11、院會-10-7-10；已快取 49/209 筆]"""
    paras = rec_text(rec)
    # 開始宣告有時被發言人狀態機判成排版（院會-11-5-12「依黨團共識接續處理臨時提案」），不限主席；
    # 但要排除「下午5時繼續開會，處理臨時提案。現在休息。」這種預告
    st = next((k for k, p in enumerate(paras) if R_MOTION_START.search(p["text"])
               and not re.search(r'休息|[上下]午\d+時\d*分?(?:處理|進行)臨時提案', p["text"])), None)
    note = []
    if st is None:
        st = 0
        note.append("⚠ 找不到「處理臨時提案」宣告，從文件開頭算")
    en = next((k for k in range(st, len(paras)) if paras[k]["speaker"] == "主席" and R_MOTION_END.search(paras[k]["text"])),
              None)
    if en is None:
        en = len(paras) - 1
        note.append("⚠ 找不到「臨時提案…處理完畢」，算到文件結尾")
    else:
        while en + 1 < len(paras) and re.match(r'^散會', paras[en + 1]["text"].strip()):
            en += 1
    region = paras[st:en + 1]
    calls = []
    for k, p in enumerate(region):
        if p["speaker"] == "主席" and p["kind"] == "say":
            for m in R_MOTION_CALL.finditer(p["text"]):
                calls.append((k, m.group(1), m.group(2) or ""))
    if not calls and len(note) == 2:
        note.append("⚠ 起點、終點都找不到，這份文件可能沒有臨時提案（不依發言人硬切）")
    elif not calls:
        # 主席不念案號、委員直接輪流說明（院會-10-8-11、11-1-8、11-5-12）：一位委員開口＝一案
        n = 0
        for k, p in enumerate(region):
            if p["kind"] == "say" and p.get("new") and p["speaker"] not in (None, "主席"):
                n += 1
                calls.append((k, str(n), p["speaker"]))
        if calls:
            note.append("主席未念案號，依委員發言輪次切")
    secs = []
    for n, (k, no, who) in enumerate(calls):
        end = calls[n + 1][0] - 1 if n + 1 < len(calls) else len(region) - 1
        end = max(k, end)
        txt = " ".join(x["text"] for x in region[k:end + 1])
        tags = []
        if re.search(r'不在場', txt[:200]) and re.search(r'暫不予處理|不予處理', txt[:200]):
            tags.append("提案人不在場")
        dm = re.search(r'本案(?:作如下|作以下)?(?:決議|決定)[：:]?「([^」]{1,40})', txt)
        if dm:
            tags.append(f"決議：{dm.group(1)}")
        secs.append({"name": f"第{no}案 {who}".strip(), "start": k, "end": end, "tags": tags})
    s = mk_session("臨時提案", [(None, rec, "", "")], region, "臨時提案", [])
    s["sections"] = secs
    annotate_sections(s, region)
    s["note"] = "；".join(note + [f"臨時提案一案一段，共 {len(secs)} 案（2026-10-02 決定）"])
    return s


def mk_session(name, group, paras, kind, attach):
    say, tot, img = written_ratio(paras)
    s = {"name": name, "kind": kind, "記錄數": len(group),
         "段落數": len(paras), "口說字數": say, "文字總數": tot,
         "口說佔比": round(100 * say / tot, 1) if tot else 0.0,
         "圖片段數": img, "attachments": attach, "sections": []}
    if kind in ("法案", "總預算案"):
        s["sections"] = sections_phase(paras)
    if img:
        s["note"] = (f"⚠ 含 {img} 個 INCLUDEPICTURE 段：原始公報那幾頁是嵌入的掃描圖片，"
                     f"文字層取不到（itemized-and-images-2026-10-02.md 第 3 節）")
    # 預覽用：開頭與結尾的主席原話，讓人判斷切點對不對
    chair = [x["text"].strip() for x in paras if x["speaker"] == "主席" and x["kind"] == "say" and x["text"].strip()]
    s["head_text"] = chair[:2]
    s["tail_text"] = chair[-2:] if len(chair) > 2 else []
    s["_paras"] = paras            # 給閱覽器取逐段文字用；--json 輸出前會拿掉
    s["_lcs"] = [lc for g in group for lc in ((g[1] or {}).get("agenda_lcidc_ids") or [])]   # 紀錄附的文件（fill_outside 選宿主用）
    annotate_sections(s, paras)
    return s


def annotate_sections(sess, paras):
    """替 section 補上段數與原文樣本。sections 在 mk_session 之後才指派的型別
    （國是論壇、施政質詢、延後）要另外呼叫一次，否則會顯示 0 段。"""
    for sec in sess.get("sections", []):
        a, b = sec.get("start", 0), sec.get("end", len(paras) - 1)
        said = [x["text"].strip() for x in paras[a:b + 1]
                if x["speaker"] == "主席" and x["kind"] == "say" and x["text"].strip()]
        sec["sample"] = said[0][:120] if said else (sec.get("anchor") or "")
        sec["段數"] = max(0, b - a + 1)


def collect_votes(recs):
    """C10：記名表決當結構化紀錄。"""
    total, docs_with, docs = 0, 0, 0
    for _, r, _, _ in recs:
        for lc in (r.get("agenda_lcidc_ids") or []):
            docs += 1
            d = stages.fetch(f"{stages.API}/gazette_agenda_doc/{lc}/parsed") or {}
            v = d.get("votes") or []
            if v:
                docs_with += 1
            total += len(v)
    return {"total": total, "docs_with_votes": docs_with, "docs": docs,
            "warn": "votes 欄位只在帶 title 的文件上有，缺漏約 30%，不可當分母"}


# ───────────────────────── 粗顆粒模式（2026-10-02 James：院會切太細）─────────────────────────
#
# 細模式的 session＝一筆公報記錄，所以每個案都要跟公報記錄配對；配不到就變「（未配對）」，
# 12 場預覽中 112/297 個 session（38%）是這種。粗模式改成：
#   session＝院會的大段（國是論壇／施政質詢／報告事項／討論事項／總預算案／臨時提案）
#   section＝討論事項裡的一個案，邊界直接取逐字稿主席的「進行討論事項第N案」，
#            案名取主席宣讀的案由（「五、本院財政委員會報告…」），**不跟公報記錄配對**
# 所以不會產生「未配對」。公報記錄只用來列「本段處理了哪些案、處置結果」的清單（C2 的處置結果欄位）。
# 案內的階段（逐條／三讀等）不再切成一層，改成 section 上的標籤。

CASE_TITLE = re.compile(r'^([一二三四五六七八九十百○〇零]+)、\s*(.{4,})')   # 第8屆大院會案號寫成「一○○」


def case_title(ps):
    """案名：主席宣告後最先出現的「N、…」案由行。"""
    for x in ps[1:10]:
        m = CASE_TITLE.match((x["text"] or "").strip())
        if m:
            t = re.sub(r'\s+', '', m.group(2))
            # 優先用「」裡的法案名：「本院財政委員會報告併案審查…擬具『電子支付機構管理條例…』」→ 取法案名
            # 只有法案型（擬具／函請審議／審查）才取「」——黨團決議案的「」多半是案由裡的引語
            # （院會-11-2-18 實測抓到「六氣、六煤」「國會改革」「目的良善」，會誤導）
            LAWLIKE = re.compile(r'(草案|條例|通則|法|預算案|預算書|決算)$')
            q = [x for x in re.findall(r'「([^」]{4,40})」', t)
                 if LAWLIKE.search(re.sub(r'[（(][^）)]*[）)]$', '', x))] \
                if re.search(r'擬具|函請審議|函送|報告(?:併案)?審查', t) else []
            if q:
                law = q[0]
                more = len({re.sub(r'(部分條文|第.{1,20}條條文|條文)?(修正|增訂)?草案$', '', x) for x in q}) - 1
                return law + (f" 等{more + 1}案" if more > 0 else "")
            t = re.sub(r'^[（(][一二三四五六七八九十][）)]', '', t)
            t = re.sub(r'^本院', '', t)
            return t[:40]
    return None


def phase_tags(ps):
    tags = []
    for x in ps:
        if x["speaker"] != "主席" or x["kind"] != "say":
            continue
        for name, rx in PHASES:
            if name not in tags and rx.search(x["text"]):
                tags.append(name)
    return tags


# ───────────────────────── 同意權行使（2026-10-02 讀過 31/31 場）─────────────────────────
#
# 程序固定（主席宣告，30 份文件實測）：宣讀審查報告 → 布置投票所 → 分區領票及投票 →
# 投票截止、布置開票所 → 開票唱票 → 發票報告書 → 報告投票表決結果。
# 表決結果格式：「姓名　同意票N張，不同意票N張，無效票N張。」（院會-9-5-10 有一處誤植「無交票」）
# 第 11 屆另列出每位委員的同意權票（「丁學忠委員同意權票：／張文貞　不同意」），
# 這是投票紀錄不是發言，不可算成發言人；改抽成結構化 ballots，並與表決結果互相核對。

CONSENT_REC = re.compile(r'同意權之行使|請同意|同意案|咨請同意')
CONSENT_START = re.compile(r'進行同意權之行使事項|爰於本次會議(?:進行|提出)投票表決')
CONSENT_END = re.compile(r'(?:報告|宣布)投票表決結果')   # 「現在宣布投票表決結果」（院會-9-6-13）
CONSENT_PHASES = [
    ("宣讀審查報告", re.compile(r'宣讀(?:全院委員會)?審查報告')),
    ("投票", re.compile(r'領票及投票|布置投票所')),
    ("開票唱票", re.compile(r'開始開票|進行唱票|開票、唱票|開啟票匭')),
    ("發票報告書", re.compile(r'發票報告書')),
    ("表決結果", CONSENT_END),
]
RESULT = re.compile(r'([一-鿿\ue000-\uf8ffA-Za-z‧・．　 ]{2,30}?)\s*同意票\s*(\d+)\s*張，\s*不同意票\s*(\d+)\s*張，\s*無[效交]票\s*(\d+)\s*張')
BALLOT_HEAD = re.compile(r'^(.{2,12}?)委員同意權票[：:]?$')
BALLOT_LINE = re.compile(r'^([一-鿿\ue000-\uf8ffA-Za-z‧・．　 ]{2,30}?)[\s　]+(同意|不同意|無效)$')


def consent_session(paras):
    """一場院會的同意權行使 → 一個 session（粗顆粒：不切 section，階段為標籤）。"""
    st = [i for i, p in enumerate(paras) if p["speaker"] == "主席" and p["kind"] == "say"
          and CONSENT_START.search(p["text"])]
    if st:
        a = st[0]
    else:
        # 沒有起點宣告時，從第一個提到同意權／投票所的主席段起算，不從文件開頭（院會-9-4-9 會把議事日程異議包進來）
        a = next((i for i, p in enumerate(paras) if p_is_chair(p) and re.search(r'同意權|布置投票所|領票及投票', p["text"])), 0)
    en = [i for i in range(a, len(paras)) if p_is_chair(paras[i]) and CONSENT_END.search(paras[i]["text"])]
    if en:
        b = en[-1]
    else:
        # 找不到結果宣告時，以下一個大段的開頭為界，不要算到文件結尾（同一份常接著討論事項）
        b = next((k - 1 for k in range(a + 1, len(paras)) if p_is_chair(paras[k])
                  and (R_OTHER_SEG.search(paras[k]["text"]) or re.search(r'(?:進行|進入)討論事項', paras[k]["text"]))
                  and not CONSENT_START.search(paras[k]["text"])), len(paras) - 1)
    region = paras[a:b + 8]
    # 第 11 屆的每位委員同意權票：抽成 ballots，並從發言中排除
    ballots, cur = {}, None
    speech = []
    for p in region:
        sp = p.get("speaker") or ""
        if sp.endswith("同意權票"):
            cur = sp[:-len("同意權票")].replace("委員", "")
            line = (p["raw"] or "").strip()
            m = BALLOT_LINE.match(line.replace("：", ""))
            if m:
                ballots.setdefault(cur, {})[re.sub(r'[\s　]', '', m.group(1))] = m.group(2)
            continue
        speech.append(p)
    # 結果從整段同意權文字抽：只讀「報告投票表決結果」之後幾段會漏（一人一段，7 人以上就截斷），
    # 而且有些場次的結果宣告不用這句話。完全相同（姓名＋三個票數）的重複出現只留一筆。
    text = "\n".join((p["raw"] or "") for p in paras)
    results, seen = [], set()
    for m in RESULT.finditer(text):
        r = {"被提名人": re.sub(r'[\s　]', '', m.group(1)), "同意": int(m.group(2)),
             "不同意": int(m.group(3)), "無效": int(m.group(4))}
        k = tuple(r.values())
        if k not in seen:
            seen.add(k); results.append(r)
    tags = []
    for name, rx in CONSENT_PHASES:
        if any(p["speaker"] == "主席" and p["kind"] == "say" and rx.search(p["text"]) for p in region):
            tags.append(name)
    s = mk_session("同意權行使", [], speech, "同意權行使", [])
    s["sections"] = []
    s["tags"] = tags
    s["results"] = results
    notes = []
    if not st:
        notes.append("⚠ 找不到同意權起點宣告（「進行同意權之行使事項」），以文件開頭為起點")
    if not en:
        notes.append("⚠ 找不到「報告投票表決結果」，結果可能不完整")
    if ballots:
        # 核對：各被提名人的同意／不同意票數，委員逐票加總 vs 主席報告的表決結果
        tally = collections.defaultdict(collections.Counter)
        for who, votes in ballots.items():
            for nom, v in votes.items():
                tally[nom][v] += 1
        diff = []
        for r in results:
            t = tally.get(r["被提名人"])
            if t is None:
                diff.append(f"{r['被提名人']}（委員票中找不到此人）")
            elif t["同意"] != r["同意"] or t["不同意"] != r["不同意"]:
                diff.append(f"{r['被提名人']}：逐票加總 同意{t['同意']}／不同意{t['不同意']}，"
                            f"主席報告 同意{r['同意']}／不同意{r['不同意']}")
        s["ballots"] = {"委員數": len(ballots), "對不上": diff}
        notes.append(f"【記名投票】{len(ballots)} 位委員的同意權票已整理成逐人投票紀錄、不當發言顯示；"
                     + ("逐票加總與主席報告的表決結果**全部一致**" if not diff
                        else f"⚠ 與表決結果有 {len(diff)} 處對不上"))
    if results:
        notes.append(f"【表決結果】{len(results)} 位被提名人："
                     + "、".join(f"{r['被提名人']} {r['同意']}:{r['不同意']}" for r in results[:6])
                     + ("…" if len(results) > 6 else ""))
    s["note"] = "；".join(notes) if notes else ""
    return s


def p_is_chair(p):
    return p["speaker"] == "主席" and p["kind"] == "say"

FILLER_NAME = "（程序／表決）"
# 補段的名稱依 session 型別：討論事項／臨時提案的缺口是程序動議、點名表決、沒獨立紀錄的臨時提案；
# 總預算案的缺口是 sections_phase 沒認出階段的逐款處理（臨時會院會-9-2-1-2 表決前 7,200 段）；
# 質詢型的缺口是點名前的開場（含行政院長的施政報告）、宣告與結尾的散會
FILLER_BY_KIND = {"總預算案": "（階段未判定）", "施政質詢": "（點名之外）", "國是論壇": "（點名之外）",
                  "專案報告質詢": "（點名之外）", "總預算案報告質詢": "（點名之外）"}


def unplaced_keys(sessions):
    """全場所有 session 的 _paras 裡、不在任何 section 範圍內（網站看不到）的段落鍵 {(文件 id, i)}。
    沒切 section 的 session 整段都看得到。"""
    shown, allk = set(), set()
    for x in sessions:
        ps, secs = x.get("_paras") or [], x.get("sections") or []
        allk |= {(p.get("lcidc"), p["i"]) for p in ps}
        rng = [p for s in secs for p in ps[s.get("start", 0):s.get("end", -1) + 1]] if secs else ps
        shown |= {(p.get("lcidc"), p["i"]) for p in rng}
    return allk - shown


def fill_unplaced(sessions):
    """沒歸到任何 section 的口說段落，依原順序補成「（程序／表決）」section（2026-10-08，
    findings/plenary/hidden-paragraphs-2026-10-08.md 第四節的第 3 項）。

    為什麼會有：討論事項的 _paras 是整份文件，案的 section 只是其中幾段；案被提早截斷（11-1-14 第一案
    在「散會動議不通過」停住，之後 1,176 段逐條表決看不到）、案與案之間的程序動議／點名表決、討論事項
    結束後沒有獨立紀錄的臨時提案，都假設「屬於別的大段」卻沒有任何 session 接手。全量 31,590 段（4.7%）。
    這裡不改案的邊界（那是第 1、2 項，下一版），只保證每一段口說都看得到。

    做法：
      - 一個「缺口」＝同一份文件裡連續沒被任何 section 收的段落，頭尾修到第一個／最後一個有字的口說段
        （中間夾的宣讀、書面照收；整段都是書面的不補——那是附件，不是發言）。
      - 缺口可能同時在好幾個 session 的 _paras 裡（討論事項與施政質詢常是同一份文件）：只補到一個 session，
        選 _paras 最小（最貼近那份文件區段的，例：臨時提案只收「處理臨時提案」起的區段）、再選離缺口
        最近的 section 所在的 session；同距離時優先接在前一個 section 後面（當作上一案的延續）。
      - 補進去的 section 標 filler=True，名稱「（程序／表決）」加主席第一句的前 24 字，方便判讀。
    回傳補了幾段。"""
    hidden = unplaced_keys(sessions)
    if not hidden:
        return 0
    # 缺口：以 (session, _paras 位置) 表示；同一份文件的同一段在不同 session 位置不同，用鍵去重
    runs = []          # [(keys tuple, n, j0, j1)]
    for n, x in enumerate(sessions):
        ps, secs = x.get("_paras") or [], x.get("sections") or []
        if not secs or not ps:
            continue
        j = 0
        while j < len(ps):
            if (ps[j].get("lcidc"), ps[j]["i"]) not in hidden:
                j += 1
                continue
            j0 = j
            while (j + 1 < len(ps) and (ps[j + 1].get("lcidc"), ps[j + 1]["i"]) in hidden
                   and ps[j + 1].get("lcidc") == ps[j0].get("lcidc")):
                j += 1
            j1 = j
            j += 1
            say = [k for k in range(j0, j1 + 1) if ps[k]["kind"] == "say" and ps[k]["text"].strip()]
            if not say:
                continue
            a, b = say[0], say[-1]
            runs.append((tuple((ps[k].get("lcidc"), ps[k]["i"]) for k in range(a, b + 1)), n, a, b))
    # 同一個缺口（鍵相同）在多個 session 出現：挑一個
    best = {}
    for keys, n, a, b in runs:
        x = sessions[n]
        ps, secs = x["_paras"], x["sections"]
        before = max((s.get("end", -1) for s in secs if s.get("end", -1) < a), default=None)
        after = min((s.get("start", 0) for s in secs if s.get("start", 0) > b), default=None)
        d_before = a - before if before is not None else 10 ** 9
        d_after = after - b if after is not None else 10 ** 9
        score = (len(ps), min(d_before, d_after), 0 if d_before <= d_after else 1, n)
        if keys not in best or score < best[keys][0]:
            best[keys] = (score, n, a, b)
    # 缺口可能互相包含（A session 的 _paras 比 B 小，B 的缺口被 A 的 section 切成兩半不會發生，
    # 但 A 的缺口是 B 的缺口的子集會）：已補的鍵不再補
    done, added = set(), 0
    for keys, (score, n, a, b) in sorted(best.items(), key=lambda kv: (kv[1][1], kv[1][2])):
        if any(k in done for k in keys):
            continue
        done |= set(keys)
        x = sessions[n]
        ps = x["_paras"]
        first = next((ps[k]["text"].strip() for k in range(a, b + 1)
                      if ps[k]["speaker"] == "主席" and ps[k]["kind"] == "say" and ps[k]["text"].strip()), "")
        sec = {"name": FILLER_BY_KIND.get(x["kind"], FILLER_NAME) + first[:24], "start": a, "end": b, "tags": [], "filler": True}
        pos = next((i for i, s in enumerate(x["sections"]) if s.get("start", 0) > a), len(x["sections"]))
        x["sections"].insert(pos, sec)
        annotate_sections({"sections": [sec]}, ps)
        added += sum(1 for k in range(a, b + 1) if ps[k]["kind"] == "say" and ps[k]["text"].strip())
    return added


OUTSIDE_NAME = "（區段外）"


def is_speech(p):
    """有字的口說段。兩種投票紀錄不算要顯示的發言（已抽成結構化紀錄）：
    委員的同意權票（speaker「○○委員同意權票」，consent_session）、彈劾／覆議的唱票行「【丁學忠委員】贊成」
    「羅美玲　反對維持本院原決議」（ballot_session）。"""
    t = (p.get("text") or "").strip()
    if p["kind"] != "say" or not t or (p.get("speaker") or "").endswith("同意權票"):
        return False
    return not ((p.get("speaker") not in (None, "主席") and R_BALLOT.match(t)) or R_BALLOT_LINE.match(t))


def fill_outside(sessions, docs):
    """文件層：公報紀錄附的文件裡、不在任何 session 的 _paras 裡的口說段落，補進最近的 session（2026-10-08；
    findings/plenary/hidden-paragraphs-2026-10-08.md 第七節）。fill_unplaced() 處理的是「session 有、section 沒有」，
    這裡處理的是「連 session 都沒有」：只取區段的 session（報告事項、臨時提案、復議案、同意權行使、彈劾／覆議、
    總決算）把文件其餘部分切掉（院會-9-4-1 報告事項區段只有 13 段，文件另有 1,600 段）、文件內容跟紀錄型不符
    （掛在報告事項紀錄下的同意權投票、討論事項）、以及「未讀過的型」的空 session（院會-8-7-11 整個下午）。
    全量 14,991 段（2.2%）。

    做法：每份文件依序找連續沒進任何 session 的段落（頭尾修到有字的口說段），補到
      ① _paras 已含同一份文件、且離缺口最近的 session（接在該文件既有段落的前面或後面，原 section 索引順移）；
      ② 否則紀錄附有這份文件的 session（未讀過的型的空 session 也算，內容因此看得到）；
      ③ 都沒有：新開一個「（未歸類的文件）」session。
    沒切 section 的 session 先把原區段變成一個同名 section，補進去的另成「（區段外）」section（filler＋outside），
    原本看得到的內容與順序不變。回傳補了幾段。"""
    inses = {(p.get("lcidc"), p["i"]) for x in sessions for p in (x.get("_paras") or [])}
    added = 0
    for lc in docs:
        ps = doc_paras(lc)
        j = 0
        while j < len(ps):
            if (lc, ps[j]["i"]) in inses:
                j += 1
                continue
            j0 = j
            while j + 1 < len(ps) and (lc, ps[j + 1]["i"]) not in inses:
                j += 1
            j1 = j
            j += 1
            say = [k for k in range(j0, j1 + 1) if is_speech(ps[k])]
            if not say:
                continue
            run = ps[say[0]:say[-1] + 1]
            lo, hi = run[0]["i"], run[-1]["i"]
            # ① _paras 已含這份文件的 session，取最近的
            best = None
            for n, x in enumerate(sessions):
                xs = x.get("_paras") or []
                ii = [p["i"] for p in xs if p.get("lcidc") == lc]
                if not ii:
                    continue
                d = min(ii) - hi if hi < min(ii) else lo - max(ii) if lo > max(ii) else None
                if d is not None and (best is None or d < best[0]):
                    best = (d, n)
            if best is None:                       # ② 紀錄附有這份文件的 session
                n = next((n for n, x in enumerate(sessions) if lc in (x.get("_lcs") or [])), None)
                if n is None:                      # ③ 新開
                    sessions.append({"name": "（未歸類的文件）", "kind": "未歸類", "記錄數": 0, "段落數": 0,
                                     "sections": [], "attachments": [], "_paras": [], "_lcs": [lc],
                                     "note": "公報文件裡有發言、但不屬於任何一個單元，依原本順序補在這裡"})
                    n = len(sessions) - 1
            else:
                n = best[1]
            x = sessions[n]
            xs = x.setdefault("_paras", [])
            if not x.get("sections") and xs:       # 原區段變成一個同名 section，顯示不變
                x["sections"] = [{"name": x["name"][:60], "start": 0, "end": len(xs) - 1, "tags": []}]
                annotate_sections(x, xs)
            ii = [k for k, p in enumerate(xs) if p.get("lcidc") == lc]
            if ii and hi < xs[ii[0]]["i"]:
                pos = ii[0]
            elif ii and lo > xs[ii[-1]]["i"]:
                pos = ii[-1] + 1
            elif ii:
                continue                           # 不該發生：缺口落在既有段落中間
            else:
                pos = next((k for k, p in enumerate(xs) if (p.get("lcidc") or "") > lc), len(xs))
            # 插入點落在跨文件的 section 中間時，改插在那個 section 之後（段落順序略亂，但不會把缺口吞進該案）
            for sec in x["sections"]:
                if sec.get("start", 0) < pos <= sec.get("end", -1):
                    pos = sec["end"] + 1
            n_run = len(run)
            for sec in x["sections"]:
                if sec.get("start", 0) >= pos:
                    sec["start"] += n_run; sec["end"] += n_run
            xs[pos:pos] = run
            first = next((p["text"].strip() for p in run if p["speaker"] == "主席" and p["kind"] == "say" and p["text"].strip()), "")
            sec = {"name": OUTSIDE_NAME + first[:24], "start": pos, "end": pos + n_run - 1, "tags": [], "filler": True, "outside": True}
            at = next((i for i, s_ in enumerate(x["sections"]) if s_.get("start", 0) > pos), len(x["sections"]))
            x["sections"].insert(at, sec)
            annotate_sections({"sections": [sec]}, xs)
            inses |= {(lc, p["i"]) for p in run}
            added += len(say)
    return added


# ───────────────────────── 表決拉成獨立段（2026-10-08 James）─────────────────────────
R_VOTE_RESULT = re.compile(r'表決結果')
R_VOTE_NUM = re.compile(r'\d+\s*(?:人|位|票)|(?:贊成|反對)者?(?:多數|少數)')      # 真的在宣讀結果：有人數或多數／少數
R_VOTE_NOT = re.compile(r'名單|聲明|列入公報紀錄|列入紀錄')                          # 「王委員聲明對方才表決結果與黨團意見一致」不是結果
# 主席宣讀結果但沒說「表決結果」四個字：「報告院會，在場委員111位，贊成者51位，贊成者少數。」（2026-10-08 逐條表決）
R_VOTE_RESULT2 = re.compile(r'(?:在場|出席)(?:委員|人數|人員)?\s*\d+\s*[人位][，,、]?\s*贊成(?:者|人數)?\s*\d+\s*[人位票].{0,40}?(?:多數|少數|通過|維持|否決)')
# 結果整句被當成結構行：「表決結果：出席110位，贊成60位，…」；表決結果名單裡的「表決結果：出席人數：110」數字前有冒號，不算
R_VOTE_STRUCT = re.compile(r'^表決結果[：:]\s*(?:在場|出席)(?:委員|人數|人員)?\s*\d+\s*[人位]')


def is_vote_result(p):
    t = p["text"].strip()
    if R_VOTE_NOT.search(t):
        return False
    if p["kind"] == "say":
        return p["speaker"] == "主席" and bool((R_VOTE_RESULT.search(t) and R_VOTE_NUM.search(t)) or R_VOTE_RESULT2.search(t))
    return bool(R_VOTE_STRUCT.match(t))


R_VOTE_START = re.compile(r'進行(?:重付)?(?:記名|舉手|點名|起立)?表決|進行[^，。]{0,24}?(?:記名|舉手)?表決|採記名表決|請記名表決|重付表決|按鈴|分發表決卡|進行投票'
                          r'|(?:現在|首先|接著|繼續)表決|交付表決|贊成[^。]{0,40}?請(?:按|舉手)')
VOTE_SKIP_KINDS = {"覆議案", "總統彈劾案", "同意權行使"}     # 整個 session 就是一次投票，不再切
VOTE_WINDOW = 40

# 在表決哪一條／哪一案（2026-10-08 James：逐條表決時好多表決都寫同一案，要看得出在表決哪一條）
CN = '一二三四五六七八九十百零\\d'
R_ITEM_BUDGET = re.compile(rf'(第[{CN}]+款第[{CN}]+項第[{CN}]+案)')                               # 總預算案：第3款第1項第26案
R_ITEM_GENERAL = re.compile(rf'(通案第[{CN}]+案)')
R_ITEM_ART = re.compile(rf'(?:進行|處理|宣讀|針對|表決)(?:處理)?(?:增訂|刪除|修正)?\s*(第[{CN}]+[條章節](?:之[{CN}]+)?)')     # 「現在進行處理增訂第五十條之二」「進行增訂第九章之一舉手表決」
R_ITEM_ART2 = re.compile(rf'(?<![法則例憲程點、及])(?:增訂|刪除)?(第[{CN}]+[條章節](?:之[{CN}]+)?)(?:照|保留|不予|條文|章名|節名|，?依序|　)')   # 「第五十一條照國民黨黨團…通過」「第四十三條保留」、條文本文
R_ITEM_CHAPTER = re.compile(rf'(第[{CN}]+[章節][章節]名)')
R_ITEM_CASE = re.compile(rf'(?:進行|處理|宣讀|針對|對)(?:處理)?\s*((?:報告事項|討論事項|臨時提案)?第[{CN}]+案)')          # 預算提案「請宣讀第28案」
R_ITEM_NAME = re.compile(r'(?:進行|表決|處理)(?:本案|法案|條例|本法)?名稱|^名稱(?:保留|照)')
R_ITEM_PART = re.compile(r'(?:進行|處理)((?:通案|[^，。、\s]{2,10}委員會)部分)')                     # 總預算案分委員會處理：「進行內政委員會部分」
R_ITEM_STAGE = re.compile(r'(?:進行|進入|開始)(廣泛討論)|(?:進行|進入|開始)(逐條討論|逐條審查)')     # 廣泛討論階段的表決標「廣泛討論」；進入逐條後清掉、等宣告條號
R_ITEM_SKIP = re.compile(r'擬具|審議案|修正草案|條文修正|請查照|請審議|有關.{0,30}規定')                 # 案由、引法條的句子不算
R_LAWREF = re.compile(rf'[法則例憲程點]\s*(?:增訂|刪除)?第[{CN}]+條')

# 表決什麼
R_W_ROLLCALL = re.compile(r'是否(?:採用|採)?點名表決|提議(?:採用|採)?點名表決|採「?點名表決|要求點名表決|點名表決之提議')
R_W_REVOTE = re.compile(r'重付表決')
R_W_STOP = re.compile(r'停止討論')
R_W_CHAIR = re.compile(r'主席(?:上開|所作|之)?(?:所)?(?:作)?宣告|維持主席')
R_W_PROPOSER = re.compile(r'([^，。、\s（）]{2,8}黨團)(?:提議|要求|提案|所提)')
R_W_VOTE_ON = re.compile(r'(?:現在|首先|接著|繼續)表決([^，。；：、]{2,40})[。，；]')        # 「現在表決民進黨黨團再修正動議條文。」
R_W_FOR = re.compile(r'贊成([^，。]{2,60}?)者?[，,]?請(?:按|舉手)')                          # 「贊成國民黨黨團提議者請舉手」
R_W_PASS = re.compile(r'照([^，。]{2,50}?)通過')                                             # 「第五十一條照國民黨黨團…再修正動議條文通過，有無異議」
R_W_AGAINST = re.compile(r'針對([^，。]{2,50}?)[，,]')
R_W_TOPIC = re.compile(r'^表決議題[：:]\s*(.*)$')                                             # 記名表決名單裡的欄位（最可靠）
R_W_ITEMLIKE = re.compile(rf'^.{{0,24}}?(?:第[{CN}\-]+(?:條(?:之[{CN}]+)?|款|項|案|節|章)|通案\s*\d+|保留(?:部分)?\s*[(（]?-?\d+(?:-\d+)?[)）]?)')


def _norm_item(t):
    """「第　二　條」「第 3 款」→「第二條」「第3款」。"""
    return re.sub(r'第((?:[　 ]*[一二三四五六七八九十百零\d之])+)[　 ]*(條|款|項|案|章|節)',
                  lambda m: '第' + re.sub(r'[　 ]', '', m.group(1)) + m.group(2), t)


def item_in(p, parent=""):
    """這一段有沒有宣告「現在在處理哪一條／哪一案」。回 (item, part)，沒有就 (None, None)。
    只看主席的話和條文本文（結構行、段首是「第X條」）。"""
    t = _norm_item(p["text"].strip())
    if p["kind"] == "say":
        if p["speaker"] != "主席" or R_ITEM_SKIP.search(t):
            return None, None
    elif not re.match(rf'(?:增訂|刪除)?第[{CN}]+條', t):
        return None, None
    part = None
    m = R_ITEM_PART.search(t)
    if m:
        part = m.group(1)
    if R_ITEM_NAME.search(t):
        return "名稱", part
    m = R_ITEM_STAGE.search(t)
    if m:
        return (m.group(1) or "〈清除〉"), part
    for rx in (R_ITEM_BUDGET, R_ITEM_GENERAL, R_ITEM_ART, R_ITEM_CHAPTER):
        m = rx.search(t)
        if m and not (rx is R_ITEM_ART and R_LAWREF.search(t[:m.end()][-12:])):
            return m.group(1), part
    m = R_ITEM_ART2.search(t)
    if m:
        return m.group(1), part
    m = R_ITEM_CASE.search(t)
    if m:
        it = m.group(1)
        if parent and it.endswith(parent):          # 「進行討論事項第一案」就是這個案本身，不算條
            return None, part
        if parent and not re.search(r'報告事項|討論事項|臨時提案', it) and re.match(rf'第[{CN}]+案$', parent):
            return None, part
        return it, part
    return None, part


def vote_summary(text):
    """「出席人數109人，贊成58人，反對51人，贊成多數」→ 「出席109／贊成58／反對51，贊成多數」；抓不到數字就回原句前 40 字。"""
    t = text
    n = {k: m.group(1) for k, rx in (("出席", r'(?:出席|在場)(?:人數|人員|委員)?\s*(\d+)\s*(?:人|位)'),
                                     ("贊成", r'贊成(?:者|人數)?\s*(\d+)\s*(?:人|位|票)'),
                                     ("反對", r'反對(?:者|人數)?\s*(\d+)\s*(?:人|位|票)'), ("棄權", r'棄權(?:者|人數)?\s*(\d+)\s*(?:人|位|票)'))
         for m in [re.search(rx, t)] if m}
    outcome = re.findall(r'贊成者?多數|反對者?多數|贊成者?少數|反對者?少數|本案(?:不)?通過|未獲通過|不通過|照案通過|通過|維持主席(?:之)?宣告', t)
    if not n:
        return re.sub(r'^(?:報告院會[，,]?)?(?:現在)?(?:宣讀|宣告|報告)?表決結果[：:]?', '', t).strip()[:40] or t[:40]
    parts = [f"{k}{v}" for k, v in n.items() if k in ("出席", "贊成", "反對")]
    return "／".join(parts) + (f"，{outcome[-1]}" if outcome else "")


def vote_topic(ps, k, e, parent=""):
    """表決結果名單裡的「表決議題：…」（結果段 k 之後到 e）。回 (item, what, type)。
    「表決議題：行政院提案第一條」＋下一行「台灣民眾黨黨團、國民黨黨團再修正動議」→ item=行政院提案第一條、what=…再修正動議
    「表決議題：重付表決　報告事項第48案」→ type=重付表決、item=報告事項第48案
    「表決議題：內政委員會　保留1」＋「國民黨黨團提案」→ item=內政委員會 保留1。議題太長會折成兩行，先接起來再拆。"""
    for j in range(k, e + 1):
        m = R_W_TOPIC.match(ps[j]["text"].strip())
        if not m:
            continue
        lines = [m.group(1).strip()]
        for jj in range(j + 1, min(e, j + 4) + 1):
            tx = ps[jj]["text"].strip()
            if ps[jj]["kind"] == "say" or re.match(r'(?:表決結果|贊成|反對|棄權|會議名稱|表決時間)', tx):
                break
            lines.append(tx)
        full = _norm_item(re.sub(r'[　\s]+', ' ', " ".join(lines))).strip()
        vtype = ""
        if full.startswith("重付表決"):
            vtype, full = "重付表決", re.sub(r'^重付表決\s*', '', full)
        item, what = "", full
        mi = R_W_ITEMLIKE.search(full)
        if mi and parent and re.sub(r'\s+', '', full[:mi.end()]).endswith(parent):      # 「討論事項第一案　第五條」：案是所屬的案，條才是要的
            full = full[mi.end():].strip(" ：:、")
            mi = R_W_ITEMLIKE.search(full)
            what = full
        if mi and mi.end() <= 30:
            item, what = re.sub(r'\s+', '', full[:mi.end()]), full[mi.end():].strip(" ：:、")    # 折行接回來的空格去掉
        elif "名稱" in full:
            item = "名稱"
            if full.startswith("名稱"):
                what = full[2:].strip(" ：:")
        if "：" not in what and " " in what and item:
            what = what.replace(" ", "：", 1)
        return item, what[:60], vtype
    return None


def clean_what(what, item):
    """「進行通案第125案之表決。」在表決的就是 item 本身 → 空；「名稱：民進黨黨團再修正動議」去掉重複的 item。"""
    if not what:
        return what
    w = what.strip("。，, ")
    if item:
        if w.startswith(item + "："):
            return w[len(item) + 1:]
        core = re.sub(r'^(?:現在|報告院會)?(?:進行|針對|處理|表決)?', '', w)
        core = re.sub(r'(?:之|的)?(?:表決|處理)$', '', core)
        if core == item:
            return ""
    return w


def vote_what(ps, a, k, prev_what):
    """從「進行表決」到結果之間主席的話判斷在表決什麼。回 (what, type)。"""
    said = [ps[j]["text"] for j in range(a, k + 1) if ps[j]["kind"] == "say" and ps[j]["speaker"] == "主席"]
    body = "\n".join(said)
    said = [t for t in said if not is_vote_result({"text": t, "kind": "say", "speaker": "主席"})] or said
    prop = R_W_PROPOSER.search(body)
    who = f"（{re.sub(r'^(?:現在|現有|針對|本院|依|由|有|經|請|對)+', '', prop.group(1))}提議）" if prop else ""
    if R_W_REVOTE.search(body):
        return (f"重付表決：{prev_what}" if prev_what else "重付表決"), "重付表決"
    if R_W_ROLLCALL.search(body):
        return "是否採點名表決" + who, "點名表決提議"
    if R_W_STOP.search(body):
        return "停止討論" + who, "停止討論"
    if R_W_CHAIR.search(body):
        return "維持主席宣告", "主席宣告"
    for rx in (R_W_VOTE_ON, R_W_FOR, R_W_PASS, R_W_AGAINST):
        for s in said:
            m = rx.search(s)
            if m and not re.search(r'結果|請按|請舉手|^請|^本案$', m.group(1)):
                return m.group(1).strip("，,。 ")[:40], ""
    # 起點常是「（進行表決）」「（按鈴）」這種括號行：退而求其次拿第一句有內容的主席話
    first = next((s.strip() for s in said if len(re.sub(r'[（(].*?[）)]', '', s).strip()) > 6), "")
    first = re.sub(r'^(?:報告院會[，,]?)?(?:現在)?', '', first)
    what = re.split(r'[，,。]?(?:請問院會|有無異議|我們進行表決|現在進行|按鈴|進行表決|計時)', first)[0][:30]
    return what, ""


def vote_ranges(ps, lo, hi, parent=""):
    """[lo, hi] 範圍內的表決區段 [(S, E, name, info)]：宣讀表決結果的那一段往前找「進行表決／按鈴／記名表決」的起點
    （最多 {VOTE_WINDOW} 段、不越過上一次表決），往後把表決結果名單（非口說段）收進來。
    info = {item: 在表決哪一條／案, what: 表決什麼, type: 程序性表決的種類}。"""
    out, prev_end, prev_what = [], lo - 1, ""
    cur_item, cur_part = "", ""
    k = lo
    while k <= hi:
        p = ps[k]
        it, part = item_in(p, parent)
        if part:
            cur_part = part
        if it:
            cur_item = "" if it == "〈清除〉" else it
        if not is_vote_result(p):
            k += 1; continue
        a = k
        for j in range(k - 1, max(lo, k - VOTE_WINDOW, prev_end + 1) - 1, -1):
            if is_vote_result(ps[j]):
                break
            if ps[j]["kind"] == "say" and ps[j]["speaker"] == "主席" and R_VOTE_START.search(ps[j]["text"]):
                a = j
        e = k
        while e + 1 <= hi and ps[e + 1]["kind"] != "say":
            e += 1
        what, vtype = vote_what(ps, a, k, prev_what)
        item = cur_item
        topic = vote_topic(ps, k, e, parent)
        if topic:
            t_item, t_what, t_type = topic
            if t_item:
                item = "" if (parent and t_item.endswith(parent)) else t_item
            else:
                # 議題沒寫第幾案／條：前面讀到的案號只有在議題也提到同一個號碼時才沿用（「內政委員會　保留1」對「第1案」）
                num = re.search(r'\d+', cur_item or "")
                item = cur_item if num and re.search(rf'(?<!\d){num.group(0)}(?!\d)', t_what) else ""
            if t_type == "重付表決" and vtype != "重付表決":
                what, vtype = (f"重付表決：{t_what or prev_what}" if (t_what or prev_what) else "重付表決"), "重付表決"
            elif t_what and not vtype:
                what = t_what
        what = clean_what(what, item)
        if item and cur_part and re.match(rf'第[{CN}]+案$', item):
            item = f"{re.sub('部分$', '', cur_part)} {item}"
        name = f"表決：{what}｜{vote_summary(p['text'])}" if what else f"表決：{vote_summary(p['text'])}"
        if item:
            name = f"{item} {name}"
        out.append((a, e, name, {"item": item, "what": what, "type": vtype}))
        prev_end, prev_what = e, (what if vtype != "重付表決" else prev_what)
        k = e + 1
    return out


def split_votes(sessions):
    """每個 section 裡的表決各自拉成一段「第N案 第X條 表決：○○｜出席N／贊成N／反對N，結果」；表決前後的部分維持原名
    （後面的加〔續〕）。只動有切 section 的 session；覆議、彈劾、同意權整個 session 就是一次投票，不切。回傳表決段數。"""
    n = 0
    for x in sessions:
        ps, secs = x.get("_paras") or [], x.get("sections") or []
        if not ps or not secs or x["kind"] in VOTE_SKIP_KINDS:
            continue
        new = []
        for sec in secs:
            a, b = sec.get("start", 0), sec.get("end", -1)
            # 表決段名前面帶所屬的案（「第三案 表決：…」），其他段帶段名前 12 字；補段、本身就叫「表決」的段不帶（2026-10-08 James）
            mcase = re.match(r'^(第[一二三四五六七八九十百\d]+案)', sec["name"])
            parent = (mcase.group(1) if mcase else "" if sec.get("filler") else re.sub(r'〔.*', '', sec["name"]).strip()[:12])
            if parent.startswith("表決"):
                parent = ""
            vs = vote_ranges(ps, a, b, mcase.group(1) if mcase else "")
            if not vs:
                new.append(sec); continue
            cur, piece = a, 0
            for va, ve, name, info in vs:
                if parent:
                    name = f"{parent} {name}"
                if va > cur:
                    part = dict(sec, start=cur, end=va - 1)
                    if piece:
                        part["name"] = f"{sec['name']}〔續〕"
                    new.append(part); piece += 1
                vsec = {"name": name, "start": va, "end": ve, "tags": ["表決"], "vote": True,
                        "vote_item": info["item"], "vote_what": info["what"], "vote_type": info["type"]}
                annotate_sections({"sections": [vsec]}, ps)
                new.append(vsec); n += 1
                cur = ve + 1
            if cur <= b:
                part = dict(sec, start=cur, end=b)
                if piece:
                    part["name"] = f"{sec['name']}〔續〕"
                new.append(part)
            for part in new:
                if not part.get("vote") and part is not sec:
                    annotate_sections({"sections": [part]}, ps)
        x["sections"] = new
    return n


def segment_coarse(meet_id, keep_paras=False):
    m = load(meet_id)
    if not m:
        return {"meet_id": meet_id, "error": "找不到此 meet"}
    recs = m.get("公報發言紀錄") or []
    typed = PL.classify_meet(recs)
    buckets = collections.defaultdict(list)
    for i, item in enumerate(typed):
        head, sub, r, tail = (list(item) + [None] * 4)[:4]
        buckets[head or "（無標題）"].append((i, r, sub or "", tail or ""))
    sessions, notes, unread = [], [], collections.Counter()
    # 立法院 API 把別場院會的公報文件掛在這場（或反過來）的修正，白話一句、同一個方向合併（2026-10-08 James）：
    # 每筆紀錄各指向幾份文件、互相重疊，逐筆列會出現同一份文件好幾次
    agg = collections.OrderedDict()
    for kind, lcs, other, content in m.get("_doc_fix") or []:
        a = agg.setdefault((kind, other), {"docs": [], "titles": []})
        a["docs"] += [x for x in lcs if x not in a["docs"]]
        t = re.sub(r'\s+', '', re.sub(r'[─—－].*$', '', re.sub(r'^(討論事項|報告事項|施政質詢|國是論壇|臨時提案|質詢事項)\s*', '', content.strip())))
        if t and t not in a["titles"]:
            a["titles"].append(t)
    for (kind, other), a in agg.items():
        titles = "、".join(x[:20] for x in a["titles"][:4]) + ("…" if len(a["titles"]) > 4 else "")
        if kind == "移出":
            notes.append(f"【公報文件掛錯場次】立法院 API 把 {len(a['docs'])} 份其實屬於 {other} 的公報文件掛在這場"
                         f"（{titles}），已移到那一場顯示")
        else:
            notes.append(f"【公報文件掛錯場次】立法院 API 把這場的 {len(a['docs'])} 份公報文件掛在 {other}"
                         f"（{titles}），已移回這場顯示")

    # 同意權行使：分類器「沿用上一個標題」會把緊接在後的法案也歸進來（臨時會院會-9-7-1-2 的
    # 工廠管理輔導法、法官法），只留案由真的是同意案的，其餘丟回討論事項
    consent, leaked = [], []
    for x in buckets.get("同意權行使", []):
        (consent if CONSENT_REC.search(x[1].get("content") or "") else leaked).append(x)
    if leaked:
        buckets["討論事項"] = buckets.get("討論事項", []) + leaked
        notes.append(f"【分類修正】{len(leaked)} 筆被歸為同意權行使的記錄案由不是同意案"
                     f"（公報只有每段第一筆帶標題，沿用標題造成誤歸）→ 改歸討論事項")
    buckets.pop("同意權行使", None)
    if consent:
        lcs = []
        for _, r, _, _ in consent:
            lcs += [l for l in (r.get("agenda_lcidc_ids") or []) if l not in lcs]
        cs = consent_session([p for lc in lcs for p in doc_paras(lc)])
        if not lcs:
            tails = "、".join(sorted({t for _, _, _, t in consent if t})) or "（未載）"
            cs["note"] = (f"⚠ 公報記錄沒有對應的逐字稿文件，無法讀到投票過程與票數；"
                          f"只能從公報記錄尾標得知結果：{tails}")
        first = re.sub(r'^同意權之行使事項\s*', '', (consent[0][1].get("content") or "").strip())
        cs["name"] = "同意權行使：" + re.sub(r'\s+', '', first)[:40]
        sessions.append(cs)

    # 國是論壇、施政質詢：與細模式相同（點名錨點 98–99%，本來就穩）
    for head in ("國是論壇", "施政質詢", "專案報告質詢", "總預算案報告質詢"):   # 後兩型 2026-10-02 讀過各 1 場，結構同施政質詢
        for i, r, sub, tail in buckets.get(head, []):
            paras = rec_text(r)
            ss = mk_session(head, [(i, r, sub, tail)], paras, head, [])
            ss["sections"] = sections_questioning(paras)
            annotate_sections(ss, paras)
            if head == "國是論壇":
                ss["order_hint"] = "first"
            # 對行政院院長施政方針／施政報告的質詢就是「總質詢」（2026-10-08 James：質詢行政院長的標成總質詢）。
            # 全量 247 筆施政質詢紀錄有 242 筆案由寫「行政院院長…施政報告」、3 筆是造字問題（「院官」）但仍是施政報告；
            # 另 2 筆（審計長總決算、總預算案編製經過）不是，維持「施政質詢」。kind 不改（其他程式靠它分型），只改顯示
            if head == "施政質詢" and re.search(r'施政(?:方針|報告)', r.get("content") or ""):
                ss["name"] = ss["display"] = "總質詢"
            sessions.append(ss)

    for i, r, sub, tail in buckets.get("報告事項", []):
        sessions.append(report_session(r))

    # 討論事項：整段一個 session，案降為 section
    disc = buckets.get("討論事項", [])
    if disc:
        lcs = []
        for _, r, _, _ in disc:
            for lc in (r.get("agenda_lcidc_ids") or []):
                if lc not in lcs:
                    lcs.append(lc)
        # 文件照編號排＝時間順序；照記錄順序接會錯（院會-9-6-13 第一案的記錄指向隔天的 _00003，被排到最前，同意權投票跑進開場）
        lcs = sorted(lcs)
        paras = [p for lc in lcs for p in doc_paras(lc)]
        marks = []
        said_title = {}       # 宣告句裡直接念的案名（CASE_CALL 的 tail），比 case_title() 從內文猜的準
        over = False          # 「討論事項均已處理完畢／處理臨時提案」之後：不再收 bare 宣告

        def add_mark(idx, no):
            # 同一案重複宣告（「現在繼續開會，處理討論事項第一案」）不另切一段（臨時會院會-9-2-1-2 第一案被切成 4 段）
            if marks and (marks[-1][1] == no or (_cn_int(no) is not None and _cn_int(marks[-1][1]) == _cn_int(no))):
                # 前一次只是預告（「12月17日上午9時繼續開會，進行討論事項第三十八案。現在休息」，院會-9-8-14），
                # 休息後才正式宣告並宣讀案由：起點移到正式宣告，案名才抓得到。
                # 只認預告句（到此為止／休息），不用「相隔幾段」：院會-11-3-20 第五案宣告→宣讀案由→又念一次案號，
                # 依段數移起點會把案由歸到前一案（2026-10-05 查核發現）
                if re.search(r'到此為止|休息', paras[marks[-1][0]]["text"]):
                    marks[-1] = (idx, no)
                return
            marks.append((idx, no))

        def in_budget_case():
            # 上一個宣告後幾段內有宣讀總預算／特別預算案由 → 目前在預算案裡，裡面的「進行第N案」是提案編號
            a = marks[-1][0]
            return any(R_BUDGET_CASE.search(paras[j]["text"]) for j in range(a, min(a + 6, len(paras))))

        for idx, p in enumerate(paras):
            if p["speaker"] == "主席" and p["kind"] == "say":
                # 按句號＋逗號切：「…均已處理完畢，現在進行討論事項第一案」「休息後改開秘密會議，進行討論事項第二案」
                for cl in re.split(r'[。\n，,]', p["text"]):
                    mm = CASE_CALL.match(cl.strip())
                    if mm and (mm.group("verb") or not mm.group("tail")):
                        add_mark(idx, mm.group(2))
                        if mm.group("tail") and marks and marks[-1][0] == idx:
                            t = mm.group("tail")
                            q = re.match(r'「([^」]+)」(?:案)?(等\d+案)?', t)
                            said_title[idx] = (q.group(1) + (" " + q.group(2) if q.group(2) else "")) if q else t
                        over = False
                        break
                    # 沒有「討論事項」四字的「處理第十六案」：前面要有正式宣告過的案、且案號正好是上一案＋1 才算
                    # （放寬成「比上一案大」會把總預算逐款表決的「處理第70案」、開場裡的其他清單也收進來：院會-11-2-18、8-8-1）
                    m2 = CASE_CALL_BARE.match(cl.strip())
                    if (m2 and marks and not over and _cn_int(marks[-1][1])
                            and _cn_int(m2.group(1)) == _cn_int(marks[-1][1]) + 1 and not in_budget_case()):
                        add_mark(idx, m2.group(1))
                        break
                else:
                    if marks and R_CASES_OVER.search(p["text"]):
                        over = True
                    # 總預算案常不念案號，直接「請宣讀財政委員會彙整報告三件」進入逐款（院會-11-5-18）
                    if R_BUDGET_CALL.search(p["text"]) and (not marks or marks[-1][1] != "總預算"):
                        marks.append((idx, "總預算"))
        cases_secs, budget_paras = [], []
        # 最後一案只到「討論事項均已處理完畢／處理臨時提案／處理復議案／散會」為止：三者常登在同一份文件，
        # 不截的話最後一案會吞掉後面的臨時提案（重複）與復議案（院會-9-5-9 第十四案 72 段，實際只有約 7 段）
        disc_end = len(paras)
        if marks:
            disc_end = next((k for k in range(marks[-1][0] + 1, len(paras))
                             if R_DISC_END.search(paras[k]["text"]) and paras[k]["speaker"] in ("主席", None)),
                            len(paras))
        leftover = paras[disc_end:]
        bounds = marks + [(disc_end, None)]
        gaps = []
        if marks and marks[0][0] > 0:
            # 開場只從「報告事項已處理完畢／在進行討論事項之前」這類交接句起算；更前面是同一份文件裡的報告事項等
            # 其他大段（院會-9-6-13 開場原本 805 段，其實是報告事項），不屬於討論事項
            f0 = marks[0][0]
            o0 = max((k for k in range(f0) if R_DISC_OPEN.search(paras[k]["text"])), default=None)
            if o0 is None:
                o0 = max((k + 1 for k in range(f0) if paras[k]["speaker"] == "主席" and R_OTHER_SEG.search(paras[k]["text"])),
                         default=0)
            if o0 > 0:
                gaps.append((0, o0))
            if o0 < f0:
                cases_secs.append({"name": "開場／未宣告案次的部分", "start": o0, "end": f0 - 1, "tags": []})
        stops = [k for k, p in enumerate(paras) if p["speaker"] == "主席" and p["kind"] == "say"
                 and R_OTHER_SEG.search(p["text"]) and not CASE_CALL.match(p["text"].strip())]
        for (a, no), (b, _) in zip(marks, bounds[1:]):
            cut = next((k for k in stops if a < k < b), None)
            if cut is not None:
                gaps.append((cut, b))
                b = cut
            ps = paras[a:b]
            title = said_title.get(a) or case_title(ps) or ""
            # 案名常取到協商結論的第一句（「協商順序為先行討論通案決議…」），再看宣告句與後兩段有沒有總預算案由（院會-8-6-17 第四案）
            if BUDGET.search(title) or no == "總預算" or is_budget_case(ps):
                budget_paras += ps          # 總預算案自成 session（C2 例外）
                continue
            cases_secs.append({"name": f"第{no}案 {title or '（案由未宣讀）'}", "start": a, "end": b - 1,
                               "tags": phase_tags(ps)})
        # 統計（段落數、口說佔比、開頭結尾原話）排除總預算案；section 索引仍對應 paras
        bid = {id(p) for p in budget_paras}
        gap_ids = {id(p) for g0, g1 in gaps for p in paras[g0:g1]}
        body = [p for p in paras[:disc_end] if id(p) not in bid and id(p) not in gap_ids]
        ss = mk_session("討論事項", disc, body, "討論事項", [])
        ss["sections"] = cases_secs
        ss["_paras"] = paras           # 討論事項的 section 索引對應全部 paras（含總預算案），不是 body
        annotate_sections(ss, paras)
        for sec in ss["sections"]:
            if sec.get("tags"):
                sec["name"] = f"{sec['name']}〔{'／'.join(sec['tags'])}〕"
        ss["cases"] = [{"案名": shortname(r.get("content")), "處置結果": tail or "（未載）"}
                       for _, r, _, tail in disc if not BUDGET.search(r.get("content") or "")]
        ss["note"] = (f"案的邊界取主席「進行討論事項第N案」（{len(marks)} 次），"
                      f"案名取主席宣讀的案由，不跟公報記錄配對。"
                      f"下表是公報記錄的案名與處置結果（{len(ss['cases'])} 筆），供對照")
        if not marks:
            ss["note"] = "⚠ 這場逐字稿裡主席沒有說「進行討論事項第N案」，程式切不出各案，討論事項整段放在一起"
        sessions.append(ss)

        # 討論事項之外處理的復議案（「臨時提案均已處理完畢，繼續處理復議案」院會-9-5-9）：自成一個 session 🟡
        rs = next((k for k, p in enumerate(leftover) if R_RECON_START.search(p["text"])), None)
        if rs is not None:
            re_ = next((k for k in range(rs + 1, len(leftover)) if re.match(r'^散會', leftover[k]["text"].strip())
                        or R_DISC_END_OTHER.search(leftover[k]["text"])), len(leftover) - 1)
            rp = leftover[rs:re_ + 1]
            rsess = mk_session("復議案", [], rp, "復議案", [])
            rsess["note"] = ("討論事項之外另外處理的復議案，獨立成一個單元（暫定做法，還沒最後定案）；"
                             "以「討論事項第N案」形式處理的復議案仍放在討論事項底下")
            sessions.append(rsess)

        if budget_paras:
            groups = collections.OrderedDict()
            for p in budget_paras:
                groups.setdefault(p["lcidc"], []).append(p)
            keep, attach, dn = dedupe_tracks([(lc, ps) for lc, ps in groups.items()])
            notes += dn
            bs = mk_session("總預算案", [], budget_paras, "總預算案", attach)
            brecs = [(i, r, sub, tail) for i, r, sub, tail in disc if BUDGET.search(r.get("content") or "")]
            bs["votes"] = collect_votes(brecs)
            sessions.append(bs)

    seen_recon = set()
    for i, r, sub, tail in buckets.get("臨時提案", []):
        c = re.sub(r'\s', '', r.get("content") or "")
        if "復議" in c and not c.startswith("臨時提案"):
            key = tuple(r.get("agenda_lcidc_ids") or [])
            if key not in seen_recon:
                seen_recon.add(key)
                rs = recon_session(r)
                if rs:
                    sessions.append(rs)
            continue
        sessions.append(motion_session(r))
    if buckets.get("質詢事項"):
        notes.append(f"【質詢事項】{len(buckets['質詢事項'])} 筆只是書面質詢清單，沒有發言，不做單元（2026-10-02 決定）")
    for i, r, sub, tail in buckets.get("總決算審核報告", []):
        sessions.append(final_accounts_session(r))
    for kind in ("總統彈劾案", "覆議案"):
        for i, r, sub, tail in buckets.get(kind, []):
            c = re.sub(r'\s', '', r.get("content") or "")
            # 公報只有每段第一筆帶標題，分類器會把後面不相干的記錄沿用進來（院會-11-5-10「總統彈劾案」後的專案報告質詢、復議）
            if kind == "總統彈劾案" and "彈劾" not in c or kind == "覆議案" and "覆議" not in c:
                if "專案報告並備質詢" in c or "報告並備質詢" in c:
                    paras = rec_text(r)
                    ss = mk_session("專案報告質詢", [(i, r, sub, tail)], paras, "專案報告質詢", [])
                    ss["sections"] = sections_questioning(paras)
                    annotate_sections(ss, paras)
                    ss["note"] = f"公報把這筆記錄登在「{kind}」標題底下，案由其實是專案報告質詢，已依案由歸類"
                    sessions.append(ss)
                else:
                    notes.append(f"【{kind}】有 1 筆記錄登在這個標題底下、但案由不是{kind[:2]}案，沒有做成單元：{c[:40]}")
                continue
            sessions.append(ballot_session(r, kind))
    for i, r, sub, tail in buckets.get("國賓演講", []):
        sessions.append(guest_speech_session(r))
    for head, items in buckets.items():
        if head in UNREAD_TYPES:
            for i, r, sub, tail in items:
                unread[head] += 1
                sessions.append({"name": head, "kind": "unread", "記錄數": 1, "sections": [],
                                 "attachments": [], "note": "⚠ 這種類型的紀錄還沒有人工讀過、沒訂切法，整段放在一起",
                                 "_paras": [], "_lcs": r.get("agenda_lcidc_ids") or []})
    if unread:
        notes.append("【還沒訂切法的類型】" + "、".join(f"{k} {v} 筆" for k, v in unread.items()))
    filled = fill_unplaced(sessions)
    if filled:
        notes.append(f"【補段】有 {filled} 段發言不屬於任何一案或任何一位委員的輪次（案被提早截斷、案與案之間的程序動議與表決、"
                     f"討論事項之後沒有獨立紀錄的臨時提案），依原本順序補成「{FILLER_NAME}」段，原本各案的範圍沒有改")
    docs = []
    for r in recs:
        docs += [lc for lc in (r.get("agenda_lcidc_ids") or []) if lc not in docs]
    outside = fill_outside(sessions, docs)
    if outside:
        notes.append(f"【補段】另有 {outside} 段發言在公報文件裡、但原本沒被任何單元收進來（單元只取了文件的一部分、"
                     f"文件內容跟公報登的類型不符、或還沒訂切法的類型），補成「{OUTSIDE_NAME}」段，接在最接近的單元")
    nv = split_votes(sessions)
    if nv:
        notes.append(f"【表決】{nv} 次表決各自拉成一段（主席宣讀表決結果往前到「進行表決」為止，表決結果名單一併收進去）")
    still = unplaced_keys(sessions)
    left = len({(p.get("lcidc"), p["i"]) for x in sessions for p in (x.get("_paras") or [])
                if (p.get("lcidc"), p["i"]) in still and is_speech(p)})
    if left:
        notes.append(f"⚠【仍看不到】有 {left} 段發言補不進任何單元")
    for x in sessions:
        if not keep_paras:             # 閱覽器（write_viewer）要逐段文字
            x.pop("_paras", None)
        x.pop("_short", None)
        x.pop("_lcs", None)
    sessions.sort(key=lambda x: 0 if x.get("order_hint") == "first" else 1)
    return {"meet_id": meet_id, "meet_type": "院會", "mode": "coarse",
            "記錄數": len(recs), "sessions": sessions, "notes": notes}


# ───────────────────────── 自檢 ─────────────────────────

CHECK_MEETS = ["院會-11-2-18", "院會-10-6-14", "院會-11-5-18", "院會-8-8-1"]


def check(fine=False):
    ok = True
    for mid in CHECK_MEETS:
        r = segment(mid) if fine else segment_coarse(mid)
        if r.get("error"):
            print(f"✗ {mid}: {r['error']}"); ok = False; continue
        ss = r["sessions"]
        nsec = sum(len(s["sections"]) for s in ss)
        print(f"✓ {mid}: {len(ss)} sessions / {nsec} sections / {r['記錄數']} 筆記錄")
        for s in ss:
            tag = []
            if s.get("attachments"):
                tag.append(f"attach={','.join(s['attachments'])}")
            if s.get("votes", {}).get("total"):
                tag.append(f"votes={s['votes']['total']}")
            if s.get("圖片段數"):
                tag.append(f"img={s['圖片段數']}")
            print(f"    - {s['name'][:24]:26s} {s['kind']:8s} "
                  f"sec={len(s['sections']):3d} 口說={s.get('口說佔比', 0):5.1f}% {' '.join(tag)}")
        for n in r["notes"]:
            print(f"    · {n}")
        nf = sum(1 for s in ss for sec in s["sections"] if sec.get("filler"))
        if nf:
            print(f"    · 補段 section {nf} 個（{sum(1 for s in ss for sec in s['sections'] if sec.get('outside'))} 個是文件層的）")
    return ok


def write_html(path, results):
    import html as H
    e = H.escape
    out = ["<!DOCTYPE html><html lang='zh-Hant'><meta charset='utf-8'>"
           "<meta name='viewport' content='width=device-width,initial-scale=1'>"
           "<title>院會切分預覽</title><style>"
           "body{font:15px/1.75 'PingFang TC',sans-serif;max-width:1180px;margin:auto;padding:16px;background:#f7f7f9;color:#1b1f24}"
           ".m{background:#fff;border:1px solid #ddd;border-radius:8px;padding:12px 18px;margin:18px 0}"
           ".s{border-left:4px solid #bbb;padding:6px 0 6px 12px;margin:10px 0}"
           ".s.法案{border-color:#3b7dd8}.s.總預算案{border-color:#b8860b}.s.延後{border-color:#999}"
           ".s.國是論壇{border-color:#2f8f4e}.s.討論事項{border-color:#3b7dd8}.s.同意權行使{border-color:#7b4fb0}.s.unread{border-color:#c0392b;background:#fff6f6}"
           ".k{display:inline-block;font-size:11px;padding:1px 7px;border-radius:9px;background:#eef1f5;color:#445;margin-left:6px}"
           ".warn{background:#fff4e5;border-left:4px solid #e08c00;padding:8px 12px;margin:8px 0;font-size:13.5px}"
           ".orig{font-size:13px;color:#333;background:#fbfbfd;border:1px solid #eee;padding:6px 9px;margin:4px 0;border-radius:4px}"
           ".r{font-size:12px;color:#777}table{border-collapse:collapse;font-size:13px;margin:6px 0}"
           "td,th{border:1px solid #ddd;padding:3px 8px}th{background:#f0f0f4}"
           "details{margin:4px 0}summary{cursor:pointer;font-size:13.5px}"
           "h2{margin:4px 0 2px}h1{font-size:21px}</style>",
           "<h1>院會切分預覽</h1>",
           "<p class='r'>每個 session 都附主席原話（開頭／結尾），請直接看原文判斷切點對不對，不用看數字。"
           "⚠ 橘色框是切分器自己標出來的不確定處。</p>"]
    for r in results:
        if r.get("error"):
            out.append(f"<div class='m'><h2>{e(r['meet_id'])}</h2><p>{e(r['error'])}</p></div>"); continue
        ss = r["sessions"]
        out.append(f"<div class='m'><h2>{e(r['meet_id'])}</h2>"
                   f"<p class='r'>{r['記錄數']} 筆公報記錄 → <b>{len(ss)} 個 session</b>／"
                   f"{sum(len(x['sections']) for x in ss)} 個 section</p>")
        for n in r["notes"]:
            cls = "warn" if n.startswith("⚠") else "r"
            out.append(f"<div class='{cls}'>{e(n)}</div>")
        for x in ss:
            out.append(f"<div class='s {x['kind']}'><b>{e(x['name'][:70])}</b>"
                       f"<span class='k'>{e(x['kind'])}</span>"
                       f"<span class='k'>{len(x['sections'])} section</span>"
                       f"<span class='k'>{x.get('段落數', 0)} 段</span>"
                       f"<span class='k'>口說 {x.get('口說佔比', 0)}%</span>")
            if x.get("attachments"):
                out.append(f"<span class='k'>attachment：{e('、'.join(x['attachments']))}</span>")
            if x.get("votes", {}).get("total"):
                out.append(f"<span class='k'>記名表決 {x['votes']['total']} 筆</span>")
            if x.get("note"):
                out.append(f"<div class='warn'>{e(x['note'])}</div>")
            for t in x.get("head_text", []):
                out.append(f"<div class='orig'>▶ {e(t[:160])}</div>")
            if x.get("tags"):
                out.append(f"<div class='r'>程序：{e('　→　'.join(x['tags']))}</div>")
            if x.get("results"):
                out.append("<table><tr><th>被提名人</th><th>同意</th><th>不同意</th><th>無效</th></tr>"
                           + "".join(f"<tr><td>{e(r['被提名人'])}</td><td>{r['同意']}</td><td>{r['不同意']}</td>"
                                     f"<td>{r['無效']}</td></tr>" for r in x["results"]) + "</table>")
            if x.get("ballots"):
                b = x["ballots"]
                out.append(f"<div class='r'>記名投票：{b['委員數']} 位委員的同意權票已結構化；"
                           + ("逐票加總與上表<b>全部一致</b>" if not b["對不上"] else e("⚠ 對不上：" + "；".join(b["對不上"])))
                           + "</div>")
            if x.get("cases"):
                out.append(f"<details><summary>公報記錄 {len(x['cases'])} 筆（案名／處置結果）</summary>"
                           "<table><tr><th>案名</th><th>處置結果</th></tr>"
                           + "".join(f"<tr><td>{e(c['案名'])}</td><td>{e(c['處置結果'])}</td></tr>"
                                     for c in x["cases"]) + "</table></details>")
            if x["sections"]:
                out.append(f"<details><summary>{len(x['sections'])} 個 section</summary>")
                for sec in x["sections"]:
                    nm = sec.get("name", "")
                    out.append(f"<div class='orig'><b>{e(str(nm))}</b> "
                               f"<span class='r'>{sec.get('段數', 0)} 段</span><br>"
                               f"{e((sec.get('sample') or sec.get('anchor') or '')[:150])}</div>")
                out.append("</details>")
            for t in x.get("tail_text", []):
                out.append(f"<div class='orig r'>◀ {e(t[:160])}</div>")
            out.append("</div>")
        out.append("</div>")
    open(path, "w", encoding="utf-8").write("".join(out))
    print(f"寫出 {path}（{len(results)} 場）")


# ───────────────────────── Ronny 版閱覽器（build_browser.py）─────────────────────────
def to_meetjson(r, title="", date_of=None, para_day=None, session_day=None):
    """轉成 build_browser.py 讀的 meet-json：session＝院會大段，section＝案／委員／發言者，blocks＝逐段。
    date_of：{文件 id: {日期}}（公報紀錄的 meetingDate），有給就在 session 標題前加這一段是哪天開的會。

    2026-10-05（A-dates）加兩個參數，由 build_site.plenary() 計算、規則寫在那裡：
      para_day：{(文件 id, 段落 i): (日期集合, 依據)}——逐段依逐字稿推出的日期。有給就以它為準，
                查不到的段落才退回 date_of（meetingDate）。session 的日期＝其段落日期的聯集。
      session_day：{session 序號: (日期集合, 依據)}——沒有逐字稿段落的 session（公報紀錄沒有文件編號、
                文件沒有文字、或「未讀過的型」）另外推的日期；推不出來的不在表裡，標題就不加日期。
    session 另帶 date_basis（日期依據，例「標頭＋表決時間」）。跨兩天以上的 session（多半是討論事項、
    總預算案），每個 section 標題前也加該段落自己的日期，才看得出哪個案在哪天處理。"""
    BASIS = {"header": "標頭", "vote": "表決時間", "dated": "帶日期的休息／繼續開會", "clock": "過夜鐘點",
             "announce": "主席預告隔天", "inherit": "沿用前一份文件", "meetingDate": "meetingDate"}

    def para_days(p):
        if para_day is not None:
            got = para_day.get((p.get("lcidc"), p.get("i")))
            if got and got[0]:
                return set(got[0]), got[1]
        return set((date_of or {}).get(p.get("lcidc"), ())), "meetingDate"

    sessions = []
    for n, x in enumerate(r["sessions"]):
        ps = x.get("_paras") or []
        # 有切 section 時只算 section 範圍內（網站上看得到）的段落：session 的 _paras 常比 section 大
        # （例：臨時會院會-9-3-3-2 討論事項標了 8/30，底下卻沒有任何一段 8/30 的內容；2026-10-06 James 同意改）
        secs_ = x.get("sections") or []
        vis = ([p for sec in secs_ for p in ps[sec.get("start", 0):sec.get("end", -1) + 1]] if secs_ else ps)
        per = [para_days(p) for p in vis if p.get("text")]
        days = sorted(set().union(*[d for d, _ in per])) if per else []
        cnt = collections.Counter(w for d, w in per if d)
        basis = "＋".join(BASIS.get(w, w) for w, _ in cnt.most_common())
        if not days and session_day and n in session_day:
            days, basis = sorted(session_day[n][0]), session_day[n][1]
        multi = len(days) > 1

        def label_day(sub):
            # 跨日的 session：section 標題前加這段自己的日期
            if not multi:
                return ""
            ds = sorted(set().union(*[para_days(p)[0] for p in sub if p.get("text")] or [set()]))
            return f"{'、'.join(ds)}｜" if ds else ""
        secs = []
        disp = x.get("display") or x["kind"]          # 顯示名（總質詢）；kind 留給程式分型
        for sec in x.get("sections", []):
            a, b = sec.get("start", 0), sec.get("end", -1)
            tags = "".join(f"〔{t}〕" for t in sec.get("tags", []) if t not in (sec.get("name") or ""))
            secs.append({"type": disp, "label": f"{label_day(ps[a:b + 1])}{sec.get('name', '')}{tags}", "kind": "ivod",
                         **({"vote": {"item": sec.get("vote_item") or "", "what": sec.get("vote_what") or "",
                                      "type": sec.get("vote_type") or ""}} if sec.get("vote") else {}),
                         "blocks": [{"time": None, "speaker": p["speaker"] or "", "text": p["text"]}
                                    for p in ps[a:b + 1] if p.get("text")]})
        if not secs and ps:              # 不切 section 的（報告事項、復議案、同意權…）整段放一個
            secs.append({"type": disp, "label": f"{label_day(ps)}{x['name'][:60]}", "kind": "ivod",
                         "blocks": [{"time": None, "speaker": p["speaker"] or "", "text": p["text"]} for p in ps if p.get("text")]})
        for a in x.get("attachments") or []:
            secs.append({"type": "書面資料", "label": str(a)[:80], "kind": "attachment", "gazette_text": ""})
        content = "\n".join([x["name"][:80]] + ([x["note"]] if x.get("note") else []))
        sessions.append({"date": f"{'、'.join(days)}｜{disp}" if days else disp, "content": content,
                         "sections": secs, **({"date_basis": basis} if days else {})})
    fix = [x for x in r.get("notes") or [] if x.startswith("【公報文件掛錯場次】")]
    if fix and sessions:      # 網站上看得到這場移入／移出了哪些文件
        sessions[0]["content"] = "\n".join(fix) + "\n" + sessions[0]["content"]
    # 涵蓋率的分母（2026-10-08）：網站的「涵蓋」原本只算 section 內的段落，section 外的看不到也顯示 100%。
    # unplaced＝有字的口說段落、不在任何 section 內的數量，build_browser.meetTotals() 會加進分母。
    # 這裡只算得到 session 的 _paras 裡的（A 型）；build_site.plenary() 會換成整份文件層的數字。
    still = unplaced_keys(r["sessions"])
    unplaced = len({(p.get("lcidc"), p["i"]) for x in r["sessions"] for p in (x.get("_paras") or [])
                    if (p.get("lcidc"), p["i"]) in still and is_speech(p)})
    return {"meet_id": r["meet_id"], "meet_type": "院會", "title": title, "sessions": sessions, "unplaced": unplaced}


def write_viewer(path, meet_ids):
    spec = importlib.util.spec_from_file_location("build_browser", os.path.join(os.path.dirname(os.path.abspath(__file__)), "build_browser.py"))
    bb = importlib.util.module_from_spec(spec); spec.loader.exec_module(bb)
    bb.SECTION_COLORS.update({"國是論壇": ["#2f8f4e", "#e2f5e9"], "施政質詢": ["#5a1d8a", "#f0daff"], "總質詢": ["#5a1d8a", "#f0daff"],
                              "討論事項": ["#1d5c8a", "#daeaf7"], "臨時提案": ["#8a5a1d", "#f7ecda"],
                              "報告事項": ["#555", "#eee"], "總預算案": ["#b8860b", "#fbf1d6"],
                              "復議案": ["#8a1d5c", "#f7dae7"], "同意權行使": ["#7b4fb0", "#efe6fa"]})
    data = {}
    for mid in meet_ids:
        r = segment_coarse(mid, keep_paras=True)
        if r.get("error"):
            continue
        data[mid] = to_meetjson(r)
    bb.write_html(data, path, "院會（粗顆粒）")
    print(f"寫出 {path}（{len(data)} 場）")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("meets", nargs="*")
    ap.add_argument("--check", action="store_true")
    ap.add_argument("--json", action="store_true")
    ap.add_argument("--browser", metavar="OUT", help="輸出給人看的切分預覽頁")
    ap.add_argument("--viewer", metavar="OUT", help="Ronny 版閱覽器；不給場次就放全部文件已快取的院會")
    ap.add_argument("--viewer-by-term", metavar="DIR", help="同上，分屆輸出 DIR/browser-{8..11}.html（全部一個檔約 370MB 打不開）")
    ap.add_argument("--fine", action="store_true",
                    help="舊的細顆粒版（session＝一筆公報記錄）。2026-10-02 起預設為粗顆粒")
    a = ap.parse_args()
    if a.viewer_by_term:
        ok = lambda x: stages.has_doc(x)
        full = [m for m in all_meets_fixed() if m.get("公報發言紀錄")
                and all(ok(x) for r in m["公報發言紀錄"] for x in (r.get("agenda_lcidc_ids") or []))]
        for term in (8, 9, 10, 11):
            write_viewer(os.path.join(a.viewer_by_term, f"browser-{term}.html"),
                         [m["會議代碼"] for m in full if m.get("屆") == term])
        return
    if a.viewer:
        if a.meets:
            ids = a.meets
        else:
            ok = lambda x: stages.has_doc(x)
            ids = [m["會議代碼"] for m in all_meets_fixed() if m.get("公報發言紀錄")
                   and all(ok(x) for r in m["公報發言紀錄"] for x in (r.get("agenda_lcidc_ids") or []))]
        return write_viewer(a.viewer, ids)
    if a.browser:
        meets = a.meets or CHECK_MEETS
        fn = segment if a.fine else segment_coarse
        write_html(a.browser, [fn(m) for m in meets])
        return
    if a.check or not a.meets:
        sys.exit(0 if check(fine=a.fine) else 1)
    for mid in a.meets:
        r = segment(mid) if a.fine else segment_coarse(mid)
        for x in r.get("sessions", []):
            x.pop("_paras", None)
        if a.json:
            print(json.dumps(r, ensure_ascii=False, indent=1))
        else:
            print(json.dumps(r, ensure_ascii=False, indent=1)[:4000])


if __name__ == "__main__":
    main()
