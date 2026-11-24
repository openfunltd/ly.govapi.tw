#!/usr/bin/env python3
"""
全院委員會人事同意權審查 — 切分器（可執行規格）

把 findings/personnel-review/ 歸納出的規則寫成程式，產出 8 場的切分結果。
用途是給 RD 當「可執行的規格 + 驗收測試資料」，不是取代 production parser。

用法：
  python3 scripts/segment.py                 # 跑全部，輸出 findings/expected/*.json
  python3 scripts/segment.py --check         # 只跑機器自檢，不寫檔
  python3 scripts/segment.py 全院委員會-11-3-4 # 只跑一場

★ 段落索引不可直接與 production meet-json 比對。
  實測 委員會-11-3-15-13：production 的 para 索引來自公報 .doc.html（1,488 段），
  本程式來自 gazette_agenda_doc/<id>/parsed（攤平 1,508 段），
  偏移量沿文件累積 1 → 19。因此每個邊界都同時輸出 *_text 文字錨點，
  比對時請用文字錨點，索引僅供人工翻閱。
"""
import argparse, json, os, re, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import speakers as SP
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import importlib.util
_spec = importlib.util.spec_from_file_location(
    "stages", os.path.join(os.path.dirname(os.path.abspath(__file__)), "stages.py"))
st = importlib.util.module_from_spec(_spec); _spec.loader.exec_module(st)

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data", "expected")

MEETS = ["全院委員會-11-4-1", "全院委員會-11-3-4", "臨時會全院委員會-10-7-1-1",
         "全院委員會-9-8-1", "臨時會全院委員會-9-7-1-1", "全院委員會-9-3-1",
         "全院委員會-9-2-1", "全院委員會-8-7-1"]
# 原本公報發言紀錄為空、2026-09-17 用 recover_lcidc.py 補回子文件的 8 場
MEETS_RECOVERED = ["全院委員會-10-5-1", "臨時會全院委員會-10-1-1-2",
                   "臨時會全院委員會-10-1-1-1", "臨時會全院委員會-9-4-1-1",
                   "臨時會全院委員會-8-5-1-2", "臨時會全院委員會-8-5-1-1",
                   "全院委員會-8-4-1", "全院委員會-8-1-1"]

# ── 規則（來源：findings/personnel-review/README.md 第 4、7、9 節）──────────
# 單元起點：優先用「請○○說明，時間○分鐘」。當日審多位時主席會先念一句合併宣告
# （「今天分別審查⋯蕭文生、鄭純惠、林麗瑩之資格」），用它當起點會多切，所以次選。
# 「說明，時間10分鐘」與「說明10分鐘」兩種寫法都有：
# 8-7-1 的四次逐位說明宣告都是後者，原 anchor 全部漏掉，
# 導致該場被誤判為「連說明都分不開」。
R_START_PRIMARY = re.compile(r'請.{0,30}?說明，?\s*(?:時間\s*為?\s*)?\d{1,3}\s*分鐘'
                             r'|請.{0,30}?說明，?\s*時間.{0,8}?分鐘')
# ★ 長度上限不可用 .{0,20}：主席的開場宣告會把當日被提名人**逐一念出來**，
#   名單一長就超過上限而整句漏掉。實測 臨時會全院委員會-9-4-1-1 的第一天宣告
#   「今日審查監察委員被提名人王幼玲、田秋堇、瓦歷斯‧貝林Walis．Perin、林盛豐、
#   高涌誠、張武修等相關事宜」——6 個名字含羅馬字共 40 餘字，被 .{0,20} 切掉；
#   同場第二天只有 5 個名字剛好 20 字就命中。改用 [^。]{0,60}（句內不跨句）。
#   實測 16 場：真正的開場宣告 42 句，原規則命中 36 句、漏 6 句，修正後 42/42。
R_START_FALLBACK = re.compile(r'審查[^。]{0,60}?被提名人[^。]{0,60}?(?:之資格|相關事[項宜])'
                              r'|進行審查[^。]{0,30}?被提名人')
# ★ 「預告下次開會」不是單元起點。散會前主席會先講下次要審誰：
#   「1月15日（星期一）上午9時繼續開會，審查監察委員被提名人其餘5人之相關事宜，現在休息。」
#   這句也含「審查…被提名人…相關事宜」，會被當成起點。實測 16 場有 7 句這種預告，
#   原規則 7 句全部誤中；臨時會全院委員會-9-4-1-1 的 a=starts_f[0] 就落在這裡，
#   整個第一天（932 段）被排除在 session 之外，涵蓋率因此只有 52%。
#   判準：句中出現「○時繼續開會」或「現在休息」，而且沒有「現在繼續開會」→ 是預告。
R_ADJOURN_NOTICE = re.compile(r'\d{1,2}\s*時\s*繼續開會|現在休息')
R_NOW_OPEN = re.compile(r'現在繼續開會|現在開會')
# 單元終點：要吃得下「詢答的部分，到此為止」這種帶逗號的變體
# 「詢答」與「詢問」兩種說法都有：9-8-1 說的是「詢問到此結束」
R_END = re.compile(r'詢[答問].{0,10}?到此(?:為止|結束)|詢[答問].{0,8}?(?:結束|完畢)')
# ★ 中場休息不是單元終點。實測 全院委員會-9-8-1 的
#   「上午詢答進行到此為止，下午2時30分繼續開會。現在休息。」
#   會命中 R_END，但該場只有 1 位被提名人、跨上下午，切在這裡會少掉一半。
#   真正的單元終點會點名被提名人（「對○○詢答部分到此為止，謝謝被提名人列席答詢」）。
#   判準用「列席答詢」而不是「被提名人」——8-7-1 的中場休息宣告
#   「上午詢問到此為止，下午2時30分繼續對⋯被提名人進行詢問，現在休息」
#   也含「被提名人」，會被誤判成單元終點。真正的終點一定會謝人家列席答詢。
R_END_NAMED = re.compile(r'列席.{0,2}答詢')
# 全案結案
R_CLOSE = re.compile(r'審查完竣|均已審查完畢|審查完畢|已完成審查')
R_RESOLUTION = re.compile(r'作(?:以下|如下)(?:的)?決議|提報院會')
R_VOTE = re.compile(r'(無記名|記名)投票表決')
# ★ 審查程序被表決終止：實測 臨時會全院委員會-10-1-1-2（監察院 27 位被提名人）
#   因議事杯葛，民進黨黨團提案「停止說明及詢答」，記名表決 53:4 通過後直接提報院會。
#   27 位被提名人一個都沒說明、沒詢答，所以切不出任何被提名人單元——
#   程式輸出 0 sessions 是**正確的**，不是失敗。要能分辨這兩者。
R_TERMINATED = re.compile(r'停止(?:說明及)?詢答|停止說明及詢答|不再(?:進行|處理)(?:說明|詢答)')

# 議事錄（附件）
R_MINUTES = re.compile(r'宣讀.{0,20}議事錄')
R_MINUTES_END = re.compile(r'議事錄確定')
# 案由宣讀
R_AGENDA = re.compile(r'進行討論事項')
# 併審場次：各被提名人先各自說明，之後才進入四人共同詢答
R_QA_START = re.compile(r'(?:現在)?開始(?:進行)?詢[問答]|現在進行委員詢問|進行委員的?詢[問答]')
# 委員輪次。公報寫法是「請呂委員學樟詢問」（姓＋委員＋名），不是「請呂學樟委員詢問」
R_ROUND = re.compile(r'請\s*([\u4e00-\u9fff]{1,3}委員[\u4e00-\u9fff‧・]{0,4})\s*'
                     r'(?:繼續)?(?:詢問|質詢|發言|提問)')
# 書面資料標題
R_WRITTEN = re.compile(r'書面資料[：:]|書面問題[：:]|書面提問之答復')
# 被提名人姓名
# 上限 12 字擋掉了原住民委員的登記姓名：「瓦歷斯‧貝林Walis．Perin先生：」姓名本身 17 字，
# 於是該位被提名人的發言一段都沒被認出來，被提名人數少算一位
# （實測 9-4-1-1 漏瓦歷斯‧貝林、10-1-1-1 漏伊萬‧納威Iwan Nawi）。放寬到 24 字。
R_NOMINEE_SPEAK = re.compile(r'^([^：:，、。]{2,24}?)(?:被提名人|女士|先生)[：:]')
# ★ 姓名字元集要含羅馬字與全角句點：原住民委員的登記姓名是
#   「瓦歷斯‧貝林Walis．Perin」「伊萬‧納威Iwan Nawi」，只收中日文字會整個抽不到，
#   於是往後找第一個被提名人發言當替代，抽到的是下一位——實測 9-4-1-1 因此把
#   林盛豐標成兩段、瓦歷斯‧貝林整段消失。
# ★ 終止字元不可含「。」、字元集不可含「的」：主席常先謝上一位再請下一位，
#   「謝謝陳被提名人的說明。請被提名人伊萬‧納威女士說明」會先命中前半句，
#   抽出「的說明」當人名（實測 10-1-1-1 第4組）。
R_NOMINEE_IN_ANCHOR = re.compile(
    r'被提名人([一-鿿·‧・A-Za-z．\. ]{2,20}?)'
    r'(?:先生|女士|法官|檢察官|教授|部長|審計長|之|相關|說明|[，、])'
    r'|請([一-鿿·‧・A-Za-z．\. ]{2,20}?)(?:先生|女士|法官|檢察官|教授|審計長)?說明')
PUA = re.compile(r'[-]')


# 職稱前綴要在「被提名人」之前先剝掉，否則會留下「監察委員被提名人王幼玲」這種 label。
# 順序很重要：長的放前面（監察委員 要在 委員 之前）。
R_TITLE_PREFIX = re.compile(r'^(?:第[一二三四五六七八九十\d]+屆|監察委員|考試委員|考選部|'
                            r'司法院大法官並為[院副]*長|司法院大法官|大法官|'
                            r'考試院|監察院|審計部|審計長|[正副]?院長|副院長|'
                            r'被提名人|提名人|委員|並為)+')


def norm_name(s):
    """PUA 造字會讓同一個人名分裂成多個 key（蔡烱燉／蔡[PUA]燉／蔡燉），先去掉造字；
    順便剝掉黏在姓名前面的職稱前綴（「被提名人陳瑞敏」→「陳瑞敏」）。"""
    return R_TITLE_PREFIX.sub("", PUA.sub("", s or "").strip()).strip()


# 複合姓名的分隔符在公報裡不統一：同一場的「瓦歷斯‧貝林Walis．Perin」也寫成
# 「瓦歷斯．貝林Walis．Perin」（U+2027 間隔號 vs U+FF0E 全角句點）。
# 比對時要先抹掉分隔符，否則同一人被算成兩位（實測 9-4-1-1）。
SEP = re.compile(r'[‧・·．.\s]')


def merge_names(names):
    """把同一個人的不同寫法併成一個 key。兩步：
    1. 分隔符不同（瓦歷斯‧貝林 / 瓦歷斯．貝林）→ 抹掉分隔符後相同者視為同一人
    2. 造字被吃掉的殘缺姓名（蔡燉 是 蔡烱燉 的子序列）→ 併回長的那個
    都保留較長的寫法當代表。
    第 2 步不做會讓同一人被算成兩位——實測 全院委員會-9-2-1 會從 7 位變成 8 位。

    ★ 併不回來的：同長度的異體字。實測 臨時會全院委員會-9-4-1-1 的「田秋堇」在
      逐字稿裡也寫成「田秋菫」（堇／菫 字形相近但是不同字），長度相同、非子序列，
      這兩步都攔不到，該場被提名人數因此多算一位。這屬於人名正規化的缺口
      （findings/TASKS-FOR-RD.md B4），應在 import 階段處理，不要在這裡堆特例表。
    """
    uniq = sorted(set(n for n in names if n), key=len, reverse=True)
    canon = {}
    by_sep = {}
    for n in uniq:                       # 步驟 1：分隔符差異
        k = SEP.sub("", n)
        by_sep.setdefault(k, n)          # uniq 已按長度遞減，先進來的最長
        canon[n] = by_sep[k]
    for n in uniq:                       # 步驟 2：子序列
        cur = canon[n]
        for c in set(canon.values()):
            if cur != c and is_subseq(cur, c):
                canon[n] = c; break
    return canon


def is_subseq(short, long_):
    it = iter(long_)
    return len(short) < len(long_) and all(ch in it for ch in short)


def recovered_docs(meet_id):
    """公報發言紀錄為空的 8 場，改讀 findings/recovered-lcidc.json
    （由 scripts/recover_lcidc.py 從 /gazette_agendas 按會議日期反查而來）。"""
    p = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data", "recovered-lcidc.json")
    if not os.path.exists(p):
        return []
    for rec in json.load(open(p)):
        if rec["meet_id"] == meet_id:
            return [{"lcidc": d["lcidc"], "date": d["date"], "gazette": d.get("公報編號"),
                     "page_start": d.get("page_start"), "page_end": d.get("page_end")}
                    for d in rec["docs"]]
    return []


def load_stream(meet_id):
    """把一場會議攤平成段落流：[(lcidc, 本檔段落序, 文字, 是否主席)]，並標出書面資料冊。"""
    docs = st.meet_docs(meet_id) or recovered_docs(meet_id)
    stream, written_docs = [], []
    seen = set()
    for d in docs:
        lc = d["lcidc"]
        if lc in seen:
            continue
        seen.add(lc)
        bs = st.blocks(lc)
        paras = [p for b in bs for p in (b or [])]
        # 書面資料冊：段落極少且幾乎都是標題（實測 11-3-4 的第二至第六冊）
        heads = sum(1 for p in paras if R_WRITTEN.search(p))
        if paras and len(paras) <= 40 and heads >= 2:
            written_docs.append({"lcidc": lc, "paras": len(paras), "headings": heads,
                                 "page_start": d["page_start"], "page_end": d["page_end"]})
        # 發言人判斷改用共用模組 speakers.py（2026-09-19，五類會議對帳過，見 audit_speakers_all.py）
        labeled = {p["i"]: p for p in SP.walk(paras)}
        for i, para in enumerate(paras):
            p = labeled.get(i)
            is_chair = bool(p) and p["speaker"] == "主席" and p["kind"] == "say"
            text = p["text"] if (is_chair and p["new"]) else para
            stream.append({"lcidc": lc, "i": i, "raw": para, "text": text.strip(),
                           "chair": is_chair, "date": d["date"],
                           "gazette_id": d["gazette"]})
    return docs, stream, written_docs


def is_adjourn_notice(text):
    """是不是「散會前預告下次開會要審誰」？那種句子含「審查…被提名人…相關事宜」，
    但它是上一段的結尾、不是下一段的起點。"""
    return bool(R_ADJOURN_NOTICE.search(text)) and not R_NOW_OPEN.search(text)


def group_bounds(stream, starts_real, notices, a, b):
    """併審場次的「組」邊界。組＝主席宣告一次要審的那批被提名人（通常一個半天或一天）。

    以真正的開場宣告為主。預告句只在「下次開會沒有自己的開場宣告」時才拿來當邊界，
    而且邊界取下一個公報子文件的起點，不是預告句本身——預告句屬於前一組的結尾。

    ★ 這個例外是必要的，不能一律把預告句丟掉。實測 全院委員會-8-1-1 第二天
      （4/12 審考試委員趙麗雲、黃錦堂）**完全沒有自己的開場宣告**，唯一的線索就是
      第一天結尾那句「明日（4月12日）⋯繼續開會，審查兩位考試委員被提名人之資格」。
      一律排除預告句會讓該場只剩 1 組，第一天伍錦霖的 13 個委員詢答輪次整批消失
      （sections 27 → 14）。
    ★ 反過來，有自己開場宣告的場次要用那一句：實測預告句到下一句真開場只隔 9–10 段
      （9-4-1-1、8-5-1-1、8-5-1-2、10-1-1-1 共 5 處都在這個距離），取 40 段當門檻。
    """
    doc_starts = []
    seen = set()
    for i, s in enumerate(stream):
        if s["lcidc"] not in seen:
            seen.add(s["lcidc"]); doc_starts.append(i)
    bounds = {i for i in starts_real if a <= i <= b}
    for n in notices:
        if not (a <= n <= b):
            continue
        if any(n < r <= n + 40 for r in starts_real):
            continue                    # 下次開會有自己的開場宣告，用那一句
        nxt = next((d for d in doc_starts if d > n), n + 1)
        if nxt <= b:
            bounds.add(nxt)
    return sorted(bounds) or [a]


def nominee_of(text, fallback_stream, pos):
    """從起點宣告抽被提名人姓名；抽不到就往後找第一個被提名人發言。"""
    m = R_NOMINEE_IN_ANCHOR.search(text)
    if m:
        n = norm_name(m.group(1) or m.group(2))
        if n and len(n) >= 2:
            return n
    for s in fallback_stream[pos:pos + 40]:
        mm = R_NOMINEE_SPEAK.match(s["raw"])
        if mm:
            return norm_name(mm.group(1))
    return None


def review_type(stream):
    """逐位審查 vs 併審交錯：看被提名人發言輪次的切換數是否遠大於人數。"""
    seq = []
    for s in stream:
        m = R_NOMINEE_SPEAK.match(s["raw"])
        if m:
            seq.append(norm_name(m.group(1)))
    canon = merge_names(seq)
    seq = [canon.get(x, x) for x in seq]
    runs = []
    for x in seq:
        if not runs or runs[-1] != x:
            runs.append(x)
    n = len(set(seq))
    if not n:
        return "未知", n, len(runs)
    return ("逐位審查" if len(runs) <= n * 1.5 else "併審交錯"), n, len(runs)


def segment(meet_id):
    docs, stream, written = load_stream(meet_id)
    if not stream:
        return None
    kind, n_nom, n_runs = review_type(stream)
    chair_idx = [i for i, s in enumerate(stream) if s["chair"]]

    starts_p = [i for i in chair_idx if R_START_PRIMARY.search(stream[i]["text"])]
    starts_f_all = [i for i in chair_idx if R_START_FALLBACK.search(stream[i]["text"])]
    starts_f = [i for i in starts_f_all if not is_adjourn_notice(stream[i]["text"])]
    notices = [i for i in starts_f_all if is_adjourn_notice(stream[i]["text"])]
    ends_all = [i for i in chair_idx if R_END.search(stream[i]["text"])]
    ends = [i for i in ends_all if R_END_NAMED.search(stream[i]["text"])]
    closes = [i for i in chair_idx if R_CLOSE.search(stream[i]["text"])
              or R_RESOLUTION.search(stream[i]["text"])]
    # 沒有點名式終點的場次（如 9-8-1），用結案宣告收尾
    stops = sorted(set(ends) | set(closes[-1:] if closes else []))

    sessions, notes, all_names = [], [], []
    if kind == "併審交錯":
        # RD 決定（DECISIONS.md）：整案一個 session，內部按質詢委員切 section
        a = starts_f[0] if starts_f else (starts_p[0] if starts_p else chair_idx[0])
        b = ends[-1] if ends else (ends_all[-1] if ends_all else len(stream) - 1)
        raw_names = [norm_name(m.group(1)) for s in stream
                     for m in [R_NOMINEE_SPEAK.match(s["raw"])] if m]
        canon = merge_names(raw_names)
        names = sorted({canon.get(n, n) for n in raw_names})
        sess = mk_session(stream, a, b, "、".join(names) + "（併審）", "coarse")
        sess["起點依據"] = "整場單一審查宣告"
        sess["終點依據"] = "詢答到此為止"
        # 併審不等於整團黑盒：說明階段仍是逐位進行，可切到被提名人層，
        # 只有詢答階段交錯無法分開。而且「一場併審」不一定只有一組——
        # 10-7-1-1 是上午／下午各一組，主席每組宣告一次，所以要先分組。
        groups = group_bounds(stream, starts_f, notices, a, b)
        secs = []
        for g, gi in enumerate(groups):
            gend = (groups[g + 1] - 1) if g + 1 < len(groups) else b
            gname = f"第{g+1}組" if len(groups) > 1 else ""
            intro = [i for i in starts_p if gi <= i <= gend]
            qa = next((i for i in chair_idx if gi <= i <= gend
                       and R_QA_START.search(stream[i]["text"])
                       and (not intro or i > intro[0])), None)
            for k, i in enumerate(intro):
                stop = (intro[k + 1] - 1) if k + 1 < len(intro) else ((qa - 1) if qa else gend)
                who = nominee_of(stream[i]["text"], stream, i) or f"被提名人{k+1}"
                secs.append(mk_section(stream, i, stop, "被提名人說明",
                                       f"{gname}{who}".strip(), "content"))
            if qa:
                # 詢答階段雖然無法按被提名人切成連續區段，但**可以按委員輪次切**，
                # 而且每一輪多半有主談對象（發言量佔比）。實測：
                #   10-7-1-1  20/21 輪有主談對象（≥50%）
                #   8-7-1      4/15 輪有，其餘 11 輪是「一題四答」（同一題四位依序各答）
                rounds = [i for i in chair_idx if qa <= i <= gend
                          and R_ROUND.search(stream[i]["text"])]
                if not rounds:
                    who = "、".join(nominee_of(stream[i]["text"], stream, i) or "?" for i in intro)
                    secs.append(mk_section(stream, qa, gend, "共同詢答",
                                           f"{gname}{who}　交錯答詢".strip(), "content"))
                else:
                    if rounds[0] > qa:
                        secs.append(mk_section(stream, qa, rounds[0] - 1, "詢答開始",
                                               f"{gname}詢答開始".strip(), "content"))
                    for j, ri in enumerate(rounds):
                        rend = (rounds[j + 1] - 1) if j + 1 < len(rounds) else gend
                        mm = R_ROUND.search(stream[ri]["text"])
                        member = mm.group(1) if mm else "委員"
                        main, share = dominant_nominee(stream, ri, rend)
                        answerers = nominees_in(stream, ri, rend)
                        tag = (f"主談 {main}（{share:.0f}%）" if share >= 70 else
                               f"偏向 {main}（{share:.0f}%）" if share >= 50 else
                               f"{len(answerers)} 位輪答")
                        sec = mk_section(stream, ri, rend, "委員詢答",
                                         f"{gname}{member}　{tag}".strip(), "content")
                        sec["主談被提名人"] = main if share >= 50 else None
                        sec["發言量佔比"] = round(share, 1)
                        sec["答復被提名人"] = answerers
                        secs.append(sec)
        if len(groups) > 1:
            notes.append(f"本場是 {len(groups)} 組併審（主席逐組宣告），"
                         f"組邊界＝{'／'.join(stream[i]['lcidc'] + '#' + str(stream[i]['i']) for i in groups)}；"
                         f"若改切成 {len(groups)} 個 session，比整案一個精確一倍")
        sess["sections"] = secs
        sessions.append(sess)
    else:
        # 逐位審查：以 END 切段，每段取最後一個 primary start（沒有才用 fallback）
        prev = 0
        for e in stops:
            cand = [i for i in starts_p if prev <= i <= e] or \
                   [i for i in starts_f if prev <= i <= e]
            if cand:
                a = cand[-1] if len(cand) == 1 else cand[0]
                name = nominee_of(stream[a]["text"], stream, a) or f"未知{len(sessions)+1}"
                sess = mk_session(stream, a, e, name, "ok")
                sess["起點依據"] = ("請○○說明，時間○分鐘" if a in starts_p
                                    else "審查○○之資格（次選 anchor）")
                sess["終點依據"] = ("詢答到此為止（點名式）" if e in ends
                                    else "結案宣告兜底（本場無點名式終點）")
                sessions.append(sess)
                all_names.append(name)
            prev = e + 1

    if all_names:
        # 以全場被提名人發言的姓名為權威來源：標籤若是其中某個較長姓名的殘缺版
        # （造字被吃掉），併回完整姓名。實測「蔡燉」→「蔡烱燉」。
        spoken = [norm_name(m.group(1)) for x in stream
                  for m in [R_NOMINEE_SPEAK.match(x["raw"])] if m]
        canon = merge_names(spoken + all_names)
        for sess in sessions:
            lab = canon.get(sess["label"], sess["label"])
            if lab == sess["label"]:
                longer = [n for n in set(spoken)
                          if n != sess["label"] and is_subseq(sess["label"], n)]
                if longer:
                    lab = max(longer, key=len)
            sess["label"] = lab

    # 附件與其他 section
    others = []
    for i in chair_idx:
        if R_MINUTES.search(stream[i]["text"]):
            j = next((k for k in chair_idx if k > i
                      and R_MINUTES_END.search(stream[k]["text"])), i + 1)
            others.append(mk_section(stream, i, j, "報告事項", "上次議事錄", "attachment"))
            break
    for i in chair_idx:
        if R_AGENDA.search(stream[i]["text"]):
            others.append(mk_section(stream, i, min(i + 2, len(stream) - 1),
                                     "案由宣讀", "討論事項", "content"))
            break
    for w in written:
        others.append({"type": "書面資料", "label": f"書面問答（公報 p{w['page_start']}-{w['page_end']}）",
                       "kind": "attachment", "lcidc": w["lcidc"],
                       "para_start": 0, "para_end": w["paras"] - 1,
                       "start_text": "（整冊為書面問答，僅有標題層）",
                       "end_text": "", "note": f"{w['paras']} 段全為標題，本文是掃描圖檔無文字層"})
    if closes:
        c = closes[-1]
        vote = R_VOTE.search(stream[c]["text"])
        others.append(mk_section(stream, c, c, "結案決議",
                                 f"提報院會，以{vote.group(1) if vote else '（未載）'}投票表決", "content"))

    terminated = [i for i in chair_idx if R_TERMINATED.search(stream[i]["text"])]
    if terminated and not sessions:
        t = terminated[0]
        notes.append("★ 審查程序經表決終止：主席宣告「" +
                     stream[t]["text"][:60] + "」，被提名人未進行說明與詢答，"
                     "因此沒有可切的被提名人單元。0 sessions 是正確結果，不是切分失敗。")
        others.append(mk_section(stream, t, min(t + 2, len(stream) - 1),
                                 "程序終止", "經表決停止說明及詢答", "content"))

    return {"meet_id": meet_id, "meet_type": "全院委員會",
            "審查型態": kind, "被提名人數": n_nom, "發言輪次切換": n_runs,
            "公報子文件": [d["lcidc"] for d in docs],
            "sessions": sessions, "other_sections": others, "notes": notes,
            "_stream_len": len(stream)}


def nominees_in(stream, a, b):
    """這一輪有哪些被提名人作答（依首次發言順序）。
    不再往下切「問答循環／發問主題」——James 2026-09-14：那個粒度太細。
    「現在是誰在回答」由段落層的發言人標記回答，不需要另外切段。"""
    out = []
    for x in stream[a:b + 1]:
        m = R_NOMINEE_SPEAK.match(x["raw"])
        if m:
            n = norm_name(m.group(1))
            if n not in out:
                out.append(n)
    return out


def dominant_nominee(stream, a, b):
    """一輪詢答裡誰是主談對象：用被提名人的發言字數佔比。
    ≥70% 視為主談、50-70% 偏向、其餘是「一題四答」——
    委員問一題、四位依序各答，此時沒有單一對象（例：8-7-1 葉委員津鈴 #145）。"""
    vol = {}
    for x in stream[a:b + 1]:
        m = R_NOMINEE_SPEAK.match(x["raw"])
        if m:
            n = norm_name(m.group(1))
            vol[n] = vol.get(n, 0) + len(x["raw"])
    if not vol:
        return None, 0.0
    tot = sum(vol.values())
    top = max(vol.items(), key=lambda kv: kv[1])
    return top[0], top[1] / tot * 100


def mk_session(stream, a, b, label, verdict):
    s, e = stream[a], stream[b]
    qs = sorted({m.group(1) for x in stream[a:b + 1]
                 for m in [re.match(r'^段落[：:]質詢[：:](.{2,12}?)[：:]', x["raw"])] if m})
    return {"label": label, "verdict": verdict, "date": s["date"],
            "lcidc_ids": sorted({x["lcidc"] for x in stream[a:b + 1]}),
            "start": {"lcidc": s["lcidc"], "para": s["i"], "text": s["text"][:120]},
            "end": {"lcidc": e["lcidc"], "para": e["i"], "text": e["text"][:120]},
            "涵蓋段落": b - a + 1, "質詢委員": qs, "_a": a, "_b": b}


def mk_section(stream, a, b, typ, label, kind):
    s, e = stream[a], stream[b]
    out = {"type": typ, "label": label, "kind": kind, "lcidc": s["lcidc"],
           "para_start": s["i"], "para_end": e["i"],
           "start_text": s["text"][:110], "end_text": e["text"][:110], "_a": a, "_b": b}
    if e["lcidc"] != s["lcidc"]:
        # 跨子文件：段落編號是各檔本地的，只給 para_start/para_end 會變成
        # 「#28-2」這種看不懂的區間，必須把終點的子文件一起標出來
        out["lcidc_end"] = e["lcidc"]
    return out


# ── 機器自檢 ────────────────────────────────────────────────────────────
def checks(r):
    out = []
    ok = lambda c, m: out.append((c, m))
    ss = r["sessions"]
    ok(bool(ss), f"至少有一個 session（實際 {len(ss)}）")
    for s in ss:
        ok(bool(s["start"]["text"]), f"{s['label']}：有起點引文")
        ok(bool(s["end"]["text"]), f"{s['label']}：有終點引文")
        ok(s["_b"] > s["_a"], f"{s['label']}：終點在起點之後")
    spans = sorted((s["_a"], s["_b"]) for s in ss)
    overlap = [(spans[i], spans[i + 1]) for i in range(len(spans) - 1)
               if spans[i][1] >= spans[i + 1][0]]
    ok(not overlap, f"session 之間不重疊（{len(overlap)} 處重疊）")
    if r["審查型態"] == "逐位審查":
        ok(len(ss) == r["被提名人數"],
           f"session 數 {len(ss)} == 被提名人數 {r['被提名人數']}")
    closing = [o for o in r["other_sections"] if o["type"] == "結案決議"]
    ok(bool(closing), "結案決議有被涵蓋")
    covered = sum(s["_b"] - s["_a"] + 1 for s in ss)
    pct = covered / r["_stream_len"] * 100
    ok(pct >= 60, f"session 涵蓋率 {pct:.0f}%（其餘為附件與過場）")
    return out


def strip_private(o):
    if isinstance(o, dict):
        return {k: strip_private(v) for k, v in o.items() if not k.startswith("_")}
    if isinstance(o, list):
        return [strip_private(x) for x in o]
    return o


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("meet_id", nargs="?")
    ap.add_argument("--check", action="store_true")
    a = ap.parse_args()
    targets = [a.meet_id] if a.meet_id else MEETS
    os.makedirs(OUT, exist_ok=True)
    allpass = True
    for m in targets:
        r = segment(m)
        if not r:
            print(f"{m}: 無公報資料，略過"); continue
        cs = checks(r)
        bad = [c for c in cs if not c[0]]
        allpass &= not bad
        print(f"\n{'='*78}\n{m}  {r['審查型態']}  被提名人 {r['被提名人數']}  "
              f"→ {len(r['sessions'])} sessions + {len(r['other_sections'])} 其他 section")
        for s in r["sessions"]:
            print(f"   [{s['label']}] {s['涵蓋段落']} 段  {s['start']['lcidc']}#{s['start']['para']}"
                  f" → {s['end']['lcidc']}#{s['end']['para']}")
        for c, msg in cs:
            print(f"   {'✓' if c else '✗'} {msg}")
        for n in r["notes"]:
            print(f"   ⚠ {n}")
        if not a.check:
            with open(os.path.join(OUT, m + ".json"), "w") as f:
                json.dump(strip_private(r), f, ensure_ascii=False, indent=1)
    print(f"\n{'='*78}\n機器自檢：{'全部通過' if allpass else '有未通過項目（見上方 ✗）'}")
