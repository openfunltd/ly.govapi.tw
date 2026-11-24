#!/usr/bin/env python3
"""
院會切分研究工具（只用標準函式庫、帶快取）。

一行重跑所有 [實測] 數字：
  python3 findings/plenary/scripts/plenary.py --all

分項：
  --inventory        第 8–11 屆院會 公報發言紀錄[].content 分型盤點（只用 /meets 清單，不抓全文）
  --others           列出分不出型的「其他」全部原文
  --anchors          在抽樣場次的逐字稿（parsed）上量各切點 anchor 命中率
  --read MEET TYPE   印出某場某型段落的逐字稿骨架（發言人輪替＋主席宣告），供人工深讀
  --compare MEET     現行 meet-json 切分 vs 本研究提的結構

資料來源（公開 API）：
  https://v2.ly.govapi.tw/meets?會議種類=院會&屆=N&limit=50&page=P   （公報發言紀錄內嵌在清單中）
  https://v2.ly.govapi.tw/gazette_agenda_doc/<lcidc>/parsed
快取：repo 根目錄 .cache/（與 scripts/stages.py 共用、同一套檔名規則）。
節流：每次實際打 API 間隔 2 秒（有其他 agent 同時在打）。
"""
import argparse, collections, json, os, re, sys, time, urllib.request
from urllib.parse import quote

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
API = "https://v2.ly.govapi.tw"
CACHE = os.path.join(os.path.dirname(os.path.abspath(__file__)), ".cache")
TOKEN = os.environ.get("OPENFUN_TOKEN", "")
TERMS = [8, 9, 10, 11]


# ───────────────────────── fetch ─────────────────────────
def fetch(url, tries=6):
    """與 scripts/stages.py 相同的快取鍵，先查快取；打 API 後睡 2 秒。"""
    os.makedirs(CACHE, exist_ok=True)
    key = os.path.join(CACHE, re.sub(r'[^A-Za-z0-9_.-]', '_', url.replace(API, "")) + ".json")
    if os.path.exists(key):
        try:
            return json.load(open(key))
        except Exception:
            pass
    for a in range(tries):
        try:
            headers = {"User-Agent": "research-ly-meet/1.0"}
            if TOKEN:
                headers["Authorization"] = f"Bearer {TOKEN}"
            req = urllib.request.Request(quote(url, safe=":/?&=%,"), headers=headers)
            body = urllib.request.urlopen(req, timeout=120).read()
            d = json.loads(body)
            open(key, "wb").write(body)
            time.sleep(2.0)
            return d
        except Exception as e:
            if a == tries - 1:
                print(f"  [fetch 失敗] {url} → {e}", file=sys.stderr)
                return None
            time.sleep(5 * (a + 1))


def list_meets(term):
    out, page = [], 1
    while True:
        d = fetch(f"{API}/meets?會議種類=院會&屆={term}&limit=50&page={page}")
        if not d or not d.get("meets"):
            break
        out.extend(d["meets"])
        if page >= (d.get("total_page") or 1):
            break
        page += 1
    return out


def all_meets():
    ms = []
    for t in TERMS:
        ms.extend(list_meets(t))
    return ms


def parsed(lcidc):
    d = fetch(f"{API}/gazette_agenda_doc/{lcidc}/parsed")
    return d if isinstance(d, dict) else {}


# ───────────────────────── 盤點：content 分型 ─────────────────────────
# 公報發言紀錄 是依公報順序排列的議程項目。content 第一行若是「段落標題」
# （討論事項／報告事項…），代表進入新的大段；之後沒有標題的項目沿用上一個標題。
# 標題清單是 2026-09-19 從第 8–11 屆全部 content 的短第一行歸納的（--headers 可重看）。
HEADERS = {
    "報告事項": "報告事項", "質詢事項": "質詢事項", "施政質詢": "施政質詢",
    "國是論壇": "國是論壇", "討論事項": "討論事項", "臨時提案": "臨時提案",
    "同意權之行使事項": "同意權行使", "覆議案之處理事項": "覆議案",
    "國賓演講": "國賓演講", "總統彈劾案事項": "總統彈劾案",
    # 公報 content 的字元錯置（同 findings/bug-reports 的造字問題家族），照原意歸位
    "施政質詣": "施政質詢", "討事項論": "討論事項", "臨時規案": "臨時提案", "同意之權行使": "同意權行使",
}
DASH = r"[─—－\-]"
TAIL = re.compile(DASH + r"{1,2}\s*([^─—－]{1,24}?)\s*" + DASH + r"{0,2}\s*$")

# 狀態（content 結尾 ─xxx─）→ 粗分：當天有沒有實質處理
TAIL_DONE = {"完成三讀", "通過", "通過廢止", "不通過", "同意", "不同意", "修正通過", "照案通過",
             "不予修正", "不予增訂", "不予刪除", "不予廢止", "二讀通過", "經過二讀", "撤回", "退回",
             "准予備查", "維持本院原決議", "依協商結論處理", "照審查會決議通過", "不予審議", "交付審查",
             "進行二讀", "完成立法程序後發言", "不再處理", "重付審查"}
TAIL_DEFER = {"協商後處理", "交黨團協商", "另定期處理", "留待處理", "另定期處"}
TAIL_ITEMIZE = {"逐款討論", "逐條討論", "逐項討論", "逐款審查", "營業及非營業部分逐項討論", "廣泛討論", "逐案處理"}
TAIL_QA = {"繼續質詢", "詢答完畢", "進行質詢", "詢答結束"}


def norm(s):
    return re.sub(r"\s+", "", s or "")


def tail_of(content):
    c = norm(content)
    c = re.sub(r"[（(](?:前接|後接|接續|續接)[^）)]*[）)]$", "", c)      # 跨冊註記
    c = re.sub(r"一([^一]{2,8})一$", r"─\1─", c)                       # 破折號被印成「一」
    m = TAIL.search(c)
    return m.group(1) if m else ""


def classify_meet(recs):
    """回傳 [(段落類型, 細分, 記錄)]。段落類型＝沿用的標題；細分＝依 content 內容。"""
    out, cur = [], None
    for r in recs:
        c = r.get("content") or ""
        lines = [x.strip() for x in c.split("\n") if x.strip()]
        first = lines[0] if lines else ""
        body = norm("".join(lines[1:])) if first in HEADERS else norm(c)
        if first in HEADERS:
            cur = HEADERS[first]
        top = cur
        t = tail_of(c)
        # 無標題、但 content 自己說得出是什麼的項目（不沿用上一個標題）
        if re.search(r"施政方針|施政報告.{0,6}(?:並備)?質詢", body):
            top = "施政質詢"
        elif re.search(r"行政院院長[、，].{0,60}(?:主計|財政部).{0,160}(?:總預算|預算案).{0,60}質詢", body):
            top = "總預算案報告質詢"
        elif re.search(r"行政院院長.{0,60}(?:專案報告|報告).{0,40}備質詢", body):
            top = "專案報告質詢"
        elif re.search(r"審計長列席報告.{0,80}總決算", body):
            top = "總決算審核報告"
        elif re.match(r"附錄", body):
            top = "附錄"
        elif top is None:
            top = "（無標題）"
        # 沿用「施政質詢」標題、但其實是當天另外處理的復議案／黨團提案（例：院會-11-3-5 第 5 筆）
        if top in ("施政質詢", "專案報告質詢", "總預算案報告質詢") and first not in HEADERS \
                and re.search(r"復議|^本院.{0,20}黨團|^本院委員", body):
            top = "討論事項"
        # 細分
        if top == "討論事項":
            if re.search(r"總預算案", body):
                sub = "總預算案"
            elif re.search(r"^本院.{0,30}黨團.{0,60}(?:建請|提出|針對).{0,200}(?:復議|覆議)", body) or re.search(r"提出復議", body):
                sub = "復議案"
            elif re.search(r"^本院.{0,20}黨團|^本院委員.{0,40}(?:建請|提案)", body):
                sub = "黨團／委員建請決議案"
            elif re.search(r"黨團協商結論|共同聲明", body):
                sub = "協商結論／聲明"
            else:
                sub = "法案／預算書等議案"
            if t in TAIL_DONE:
                sub += "｜當天處理完"
            elif t in TAIL_DEFER:
                sub += "｜延後（協商後處理等）"
            elif t in TAIL_ITEMIZE:
                sub += "｜逐條／逐款中"
            else:
                sub += "｜狀態不明"
        elif top in ("施政質詢", "專案報告質詢", "總預算案報告質詢"):
            sub = t or "（無狀態）"
        else:
            sub = ""
        out.append((top, sub, r, t))
    return out


def inventory(show_others=False):
    meets = all_meets()
    by_term = collections.Counter()
    top_meets, top_recs = collections.defaultdict(set), collections.Counter()
    top_term = collections.defaultdict(collections.Counter)
    sub_recs, sub_meets = collections.Counter(), collections.defaultdict(set)
    others = []
    n_meet_with = 0
    for m in meets:
        recs = m.get("公報發言紀錄") or []
        t = m["屆"]
        by_term[(t, "場")] += 1
        if not recs:
            by_term[(t, "空")] += 1
            continue
        n_meet_with += 1
        for top, sub, r, tl in classify_meet(recs):
            top_meets[top].add(m["會議代碼"])
            top_recs[top] += 1
            top_term[top][t] += 1
            if sub:
                sub_recs[(top, sub)] += 1
                sub_meets[(top, sub)].add(m["會議代碼"])
            if top == "（無標題）" or (top == "討論事項" and sub.endswith("狀態不明")):
                others.append((m["會議代碼"], top, sub, norm(r.get("content"))[:140]))
    print("## 母體（/meets 清單，會議種類=院會，含臨時會院會）")
    for t in TERMS:
        print(f"  第{t}屆：{by_term[(t,'場')]} 場，其中 公報發言紀錄為空 {by_term[(t,'空')]} 場")
    tot = sum(by_term[(t, '場')] for t in TERMS)
    print(f"  合計 {tot} 場，有公報發言紀錄 {n_meet_with} 場，記錄 {sum(top_recs.values())} 筆\n")
    print("## 段落類型（依 content 標題沿用分型）")
    print(f"  {'類型':<14}{'場次':>6}{'筆數':>7}   各屆筆數 8/9/10/11")
    for top, n in top_recs.most_common():
        tt = "/".join(str(top_term[top][t]) for t in TERMS)
        print(f"  {top:<14}{len(top_meets[top]):>6}{n:>7}   {tt}")
    print("\n## 細分")
    for (top, sub), n in sorted(sub_recs.items(), key=lambda x: (x[0][0], -x[1])):
        print(f"  {top:<10} {sub:<30}{len(sub_meets[(top,sub)]):>6} 場 {n:>6} 筆")
    # 結構性事實
    st = collections.Counter()
    nested = collections.Counter()
    qday = collections.Counter()
    for m in meets:
        recs = m.get("公報發言紀錄") or []
        if not recs:
            continue
        st["meets"] += 1
        st["multi_date"] += len(m.get("日期") or []) > 1
        cl = classify_meet(recs)
        docs_count = collections.Counter(x for r in recs for x in set(r.get("agenda_lcidc_ids") or []))
        for k, (top, sub, r, tl) in enumerate(cl):
            st["recs"] += 1
            ids = r.get("agenda_lcidc_ids") or []
            st["no_doc"] += not ids
            st["shared_doc"] += bool(ids) and any(docs_count[x] > 1 for x in ids)
            st["cross_vol"] += bool(re.search(r"[（(](?:前接|後接|接續|續接)", norm(r.get("content"))))
            if top == "施政質詢":
                nd = len(r.get("meetingDate") or [])
                st["iq"] += 1; st["iq_1day"] += nd == 1; st["iq_0day"] += nd == 0
                qday[(m["會議代碼"], tuple(r.get("meetingDate") or []))] += 1
            if top == "國是論壇":
                st["forum"] += 1
                st["forum_last"] += (k == len(cl) - 1)
                st["forum_excl"] += bool(ids) and all(docs_count[x] == 1 for x in ids)
        pages = [(r.get("page_start"), r.get("page_end"), r.get("gazette_id")) for r in recs]
        for a in range(len(pages)):
            for b in range(len(pages)):
                pa, pb = pages[a], pages[b]
                if a != b and None not in pa and None not in pb and pa[2] == pb[2] and pa[0] < pb[0] and pb[1] < pa[1]:
                    st["nested_pages"] += 1
                    nested[(cl[a][0], cl[b][0])] += 1
    print("\n## 結構性事實（全部有公報發言紀錄的場次）")
    print(f"  跨多日的場次（一次院會含 2 天以上）：{st['multi_date']}/{st['meets']}")
    print(f"  記錄沒有文件 id（agenda_lcidc_ids 空）：{st['no_doc']}/{st['recs']}")
    print(f"  記錄與其他記錄共用至少一份文件：{st['shared_doc']}/{st['recs']}")
    print(f"  記錄標註跨冊（前接／後接）：{st['cross_vol']}/{st['recs']}")
    print(f"  施政質詢記錄只對應 1 天：{st['iq_1day']}/{st['iq']}（沒有日期 {st['iq_0day']}）；"
          f"同一（場, 日）只有 1 筆施政質詢記錄：{sum(1 for v in qday.values() if v == 1)}/{len(qday)}")
    print(f"  國是論壇記錄排在該場最後一筆：{st['forum_last']}/{st['forum']}；文件不與其他記錄共用：{st['forum_excl']}/{st['forum']}")
    print(f"  頁碼區間被另一筆記錄完整包住（同一期公報）：{st['nested_pages']} 組；外層→內層：" +
          "、".join(f"{a}→{b}×{n}" for (a, b), n in nested.most_common(6)))

    print(f"\n## 分不出來（無標題＋討論事項狀態不明）：{len(others)} 筆")
    for o in (others if show_others else others[:15]):
        print("  ", *o)
    return meets


# ───────────────────────── 逐字稿：發言人狀態機（段落層） ─────────────────────────
# 同 scripts/stages.py 的 chair_paras()：parsed 的 block 可能含多位發言人，
# 必須逐段落看開頭的「XXX：」標記；且標記可能是「姓＋委員＋名」（鍾委員佳濱：）。
SPEAKER = re.compile(r'^([^：:，、。？！「」（）()]{2,16}?)[：:]')
SPEAKER_OK = re.compile(r'(委員|先生|女士|部長|次長|署長|局長|處長|秘書長|院長|主委|主任委員|主計長|'
                        r'審計長|總裁|總長|司令|首長|代表|議長|總統|副院長|政務委員|發言人)$'
                        r'|(委員|院長|部長|主委|秘書長|主計長|署長|主任委員|政務委員|次長|局長|審計長|副院長)[一-鿿‧・]{1,6}$')
STRUCT = re.compile(r'^(段落|項目|提案人|連署人|決議|附件|程序委員會意見|議事處意見|案由|說明|表決結果名單|主文)[：:]')
SEG = re.compile(r'^段落[：:](.*)', re.S)
SEG_Q = re.compile(r'^段落[：:]質詢[：:]([^：:]{2,16})[：:]?\s*([0-9０-９:：]*)')


def meet_docs(meet):
    """依公報發言紀錄順序，回傳去重後的 lcidc 清單（用 agenda_lcidc_ids，不用 agenda_id）。"""
    out = []
    for r in meet.get("公報發言紀錄") or []:
        for x in r.get("agenda_lcidc_ids") or []:
            if x not in out:
                out.append(x)
    return out


def walk(meet):
    """逐段落走完整場逐字稿，產出 (lcidc, block_idx, speaker, kind, text)。
    kind: seg＝公報段落標記、struct＝其他結構標記、say＝發言。"""
    for lc in meet_docs(meet):
        bs = parsed(lc).get("blocks") or []
        cur = None
        for bi, blk in enumerate(bs):
            for para in blk or []:
                if not isinstance(para, str):
                    continue
                if SEG.match(para):
                    yield lc, bi, cur, "seg", para
                    continue
                if STRUCT.match(para):
                    cur = "（書面）"
                    yield lc, bi, cur, "struct", para
                    continue
                m = SPEAKER.match(para)
                if m:
                    name = m.group(1)
                    if name == "主席" or SPEAKER_OK.search(name):
                        cur = name
                        para = para[m.end():]
                yield lc, bi, cur, "say", para


def skeleton(meet_id, doc=None, width=90):
    """人工深讀用的骨架：公報段落標記、主席每一句、其他發言人壓成「誰講了幾輪」。"""
    meet = find_meet(meet_id)
    print(f"# {meet_id}  日期 {meet['日期']}")
    for top, sub, r, t in classify_meet(meet.get("公報發言紀錄") or []):
        print(f"  [{top}{('｜'+sub) if sub else ''}] p{r.get('page_start')}-{r.get('page_end')} "
              f"{r.get('agenda_lcidc_ids')} {norm(r.get('content'))[:60]}")
    buf, last, first_line = collections.Counter(), None, {}

    def flush():
        if buf:
            items = "、".join(f"{k}×{v}" for k, v in buf.items())
            print(f"      … {sum(buf.values())} 輪：{items}")
            fl = [f"{k}「{v}」" for k, v in first_line.items()][:2]
            if fl:
                print(f"        首句：{' / '.join(fl)}")
        buf.clear(); first_line.clear()

    for lc, bi, spk, kind, text in walk(meet):
        if doc and lc != doc:
            continue
        if kind == "seg":
            flush()
            print(f"\n  ■ {lc}[{bi}] {norm(text)[:width]}")
            last = None
            continue
        if spk == "主席":
            flush()
            t = norm(text)
            if t:
                print(f"    主席[{bi}]｜{t[:width]}")
            last = spk
            continue
        if spk != last:
            buf[spk or "（未標記）"] += 1
            first_line.setdefault(spk or "（未標記）", norm(text)[:40])
        last = spk
    flush()


_MEETS = None


def find_meet(meet_id):
    global _MEETS
    if _MEETS is None:
        _MEETS = {m["會議代碼"]: m for m in all_meets()}
    return _MEETS[meet_id]


# ───────────────────────── anchor 量測 ─────────────────────────
SEED = 20260919
TS = re.compile(r'^[（(]\s*\d{1,2}\s*時\s*\d{1,2}\s*分\s*[）)]')
CALL = re.compile(r'請(?:登記(?:第)?[^，。、]{1,4}[號位](?:的)?)?([一-鿿‧・A-Za-z\s]{1,16}?)委員([一-鿿‧・A-Za-z]{0,12}?)'
                  r'(?:，[^。]{0,12})?(?:開始)?(?:進行)?(質詢|發言)')
INTERP_START = r"施政方針.{0,12}質詢|施政報告.{0,10}質詢"
IS_MEMBER = re.compile(r'^[^：:]{1,3}委員[\u4e00-\u9fff]{1,3}$|^[^：:]{2,8}委員$')
MARK_Q = re.compile(r'^段落[：:]質詢[：:]([^：:]{2,24})')


def pname(s):
    """人名正規化：「王委員育敏」「王育敏委員」「今天最後一位林岱樺委員」「鄭天財SraKacaw委員」→ 3 個中文字以內。"""
    s = re.sub(r'\s', '', s or '')
    m = re.match(r'^(.*?)委員(.*)$', s)
    if m and re.search(r'[\u4e00-\u9fff]', m.group(2) or ''):       # 姓＋委員＋名
        pre = re.sub(r'[^\u4e00-\u9fff]', '', m.group(1))
        post = re.sub(r'[^\u4e00-\u9fff]', '', m.group(2))
        s = pre[-1:] + post
    else:
        s = re.sub(r'[^\u4e00-\u9fff]', '', s.replace('委員', ''))
        s = s[-3:] if len(s) > 3 else s
    return s[:3]


def lines_of(lcs):
    """[(lc, bi, spk, kind, text)]，給定文件清單。"""
    fake = {"公報發言紀錄": [{"agenda_lcidc_ids": lcs}]}
    return list(walk(fake))


def turns(ls):
    """把逐段落壓成發言輪次：[(idx_in_ls, spk, first_text)]，只在換人時開新輪。"""
    out, last = [], object()
    for i, (lc, bi, spk, kind, text) in enumerate(ls):
        if kind != "say":
            if kind == "seg":
                last = object()
            continue
        if spk != last:
            out.append((i, spk, norm(text)))
            last = spk
    return out


def sample(pop, k, salt):
    import random
    rnd = random.Random(f"{SEED}-{salt}")
    pop = sorted(pop)
    return sorted(rnd.sample(pop, min(k, len(pop))))


def records_by_type():
    """{類型: [(meet_id, idx, 記錄, 細分, 屆)]}"""
    out = collections.defaultdict(list)
    for m in all_meets():
        for i, (top, sub, r, t) in enumerate(classify_meet(m.get("公報發言紀錄") or [])):
            out[top].append((m["會議代碼"], i, r, sub, m["屆"]))
    return out


def qa_region(ls, start_re, stop_re=None):
    """從第一句符合 start_re 的主席發言起算的段落範圍，排除 stop 區間（臨時提案）。"""
    on, skip, keep = False, False, []
    for x in ls:
        lc, bi, spk, kind, text = x
        t = norm(text)
        if spk == "主席" and kind == "say":
            if re.search(start_re, t):
                on = True
            if stop_re and re.search(r"處理臨時提案", t) and not re.search(r"不(?:進行|處理)臨時提案|沒有.{0,8}臨時提案", t):
                skip = True
            if skip and re.search(r"臨時提案(?:均已)?處理完畢|臨時提案.{0,6}結束", t):
                skip = False
                continue
        if on and not skip:
            keep.append(x)
    return keep


NAME_A = re.compile(r'([\u4e00-\u9fff]{1,2})委員(?!進行|請|質詢|發言|聯合|開始)([\u4e00-\u9fff]{1,3}?)(?=[、及和與，,]|聯合|進行|質詢|發言|開始|上台|$)')
NAME_B = re.compile(r'([\u4e00-\u9fffA-Za-z‧・]{2,20})委員(?=[、及和與，,]|請|聯合|進行|質詢|發言|開始|上台|$)')


def called_names(t, verb=r"質詢|發言"):
    """主席點名句 →被點到的委員（可多位：聯合質詢）。排除「請準備」「謝謝…的質詢」「以書面提出」。"""
    out = []
    t = re.sub(r"位委員", "位", t)
    t = re.sub(r"[\u4e00-\u9fff]{1,5}委員[\u4e00-\u9fff]{0,3}[，,]?請準備", "", t)
    for sent in re.split(r"[。；！？]", t):
        if "書面" in sent or not re.search(verb, sent):
            continue
        k = sent.find("請")
        if k < 0:
            m0 = re.search(r"[\u4e00-\u9fff]{2,4}委員[，,]?請(?:開始)?(?:進行)?(?:" + verb + ")", sent)
            if not m0:
                continue
            k = m0.start()
        sub = sent[k:]
        mv = re.search(verb, sub)
        sub = sub[:mv.start()] if mv else sub
        found = [pname(a + "委員" + b) for a, b in NAME_A.findall(sub)]
        if not found:
            found = [pname(re.sub(r"^.*[號位的是由第請]", "", a) + "委員") for a in NAME_B.findall(sub)]
        if not found:   # 「羅明才委員請質詢」：名字在「請」之前
            m0 = re.search(r"([\u4e00-\u9fff]{2,4})委員[，,]?請", sent)
            if m0:
                found = [pname(re.sub(r"^.*[號位的是由第請]", "", m0.group(1)) + "委員")]
        out.extend(x for x in found if x)
    return out


def signals(ls):
    """三種「換下一位委員」訊號，各回傳依序的姓名清單。"""
    C, M, T = [], [], []
    for lc, bi, spk, kind, text in ls:
        t = norm(text)
        if kind == "seg":
            mq = MARK_Q.match(t)
            if mq and "委員" in mq.group(1):
                M.append(pname(mq.group(1)))
        elif kind == "say" and spk == "主席":
            for nm in called_names(t):
                if not C or C[-1] != nm:
                    C.append(nm)       # 同一人被連續點名多次（議場騷動時常見）只算一次
    for i, spk, first in turns(ls):
        if spk and IS_MEMBER.match(spk) and "主任委員" not in spk and TS.match(first):
            T.append(pname(spk))
    return C, M, T


def agree(ref, other):
    """以 ref 為分母，other 命中幾個（多重集合比對）。"""
    pool = collections.Counter(other)
    hit = 0
    for x in ref:
        if pool[x] > 0:
            hit += 1
            pool[x] -= 1
    return hit


def measure_interpellation(k_per_term=4, verbose=False):
    recs = records_by_type()["施政質詢"]
    meets_by_term = collections.defaultdict(set)
    for mid, i, r, sub, term in recs:
        if r.get("agenda_lcidc_ids"):
            meets_by_term[term].add(mid)
    print("## 施政質詢：委員換人訊號（抽樣）")
    print(f"  母體：有施政質詢且有文件 id 的場次 各屆 " +
          "／".join(f"{t}屆{len(meets_by_term[t])}" for t in TERMS) + f"；每屆抽 {k_per_term} 場（seed {SEED}）")
    tot = collections.Counter()
    for t in TERMS:
        for mid in sample(meets_by_term[t], k_per_term, f"interp-{t}"):
            lcs = []
            for m2, i, r, sub, term in recs:
                if m2 == mid:
                    for x in r.get("agenda_lcidc_ids") or []:
                        if x not in lcs:
                            lcs.append(x)
            ls = qa_region(lines_of(lcs), INTERP_START, stop_re=True)
            C, M, T = signals(ls)
            tc, tm = agree(T, C), agree(T, M)
            tot[f"T{t}"] += len(T); tot[f"TM{t}"] += tm
            for _, _, spk, kind, text in ls:
                tx = norm(text)
                if spk == "主席" and kind == "say":
                    tot["joint"] += len(re.findall(r"聯合質詢", tx)) and bool(re.search(r"請", tx))
                    tot["written"] += bool(re.search(r"質詢以書面提出|改以書面|書面質詢", tx))
                    for mm in re.findall(r"詢答時間(?:共)?(?:為)?(\d+)分鐘", tx):
                        tot[f"min{mm}"] += 1
            tot["T"] += len(T); tot["C"] += len(C); tot["M"] += len(M); tot["TC"] += tc; tot["TM"] += tm
            tot["meets"] += 1
            print(f"  {mid:<16} 時間戳委員輪次 T={len(T):>3}  主席點名 C={len(C):>3}（T 中被點到 {tc}）"
                  f"  段落：質詢 標記 M={len(M):>3}（T 中有標記 {tm}）")
            if verbose:
                print("     T-C 未被點名：", [x for x in T if x not in C][:10])
                print("     C-T 點名但無時間戳：", [x for x in C if x not in T][:10])
    print(f"  合計 {tot['meets']} 場：T={tot['T']}，主席點名覆蓋 {tot['TC']}/{tot['T']} = {tot['TC']/max(1,tot['T']):.1%}，"
          f"段落標記覆蓋 {tot['TM']}/{tot['T']} = {tot['TM']/max(1,tot['T']):.1%}")
    print("  段落標記覆蓋（分屆）：" + "；".join(f"{t}屆 {tot[f'TM{t}']}/{tot[f'T{t}']}" for t in TERMS))
    print(f"  主席提到「聯合質詢」的點名句 {tot['joint']} 句；提到書面質詢 {tot['written']} 句；"
          "主席宣告的詢答分鐘數：" + "、".join(f"{k[3:]}分×{v}" for k, v in sorted(tot.items()) if k.startswith("min")))
    return tot


def forum_region(ls):
    """國是論壇區段：從「段落：國是論壇」或主席提到國是論壇起，到主席宣告論壇結束。"""
    on, keep = False, []
    for x in ls:
        lc, bi, spk, kind, text = x
        t = norm(text)
        if not on and ((kind == "seg" and "國是論壇" in t) or (spk == "主席" and kind == "say" and re.search(r"國是論壇", t))):
            on = True
        if on:
            keep.append(x)
            if spk == "主席" and re.search(r"國是論壇.{0,12}(?:告一段落|發言完畢|結束|到此)|登記.{0,10}發言.{0,10}(?:均已)?發言完畢", t):
                break
    return keep


def measure_forum(k_per_term=10, verbose=False):
    recs = [x for x in records_by_type()["國是論壇"] if x[2].get("agenda_lcidc_ids")]
    by_term = collections.defaultdict(list)
    for x in recs:
        by_term[x[4]].append(x)
    print("## 國是論壇：起點與換人訊號" + ("（普查）" if k_per_term >= 1000 else "（抽樣）"))
    print("  母體：content 標題為國是論壇且有文件 id 的記錄 各屆 " + "／".join(f"{t}屆{len(by_term[t])}" for t in TERMS)
          + (f"；每屆抽 {k_per_term} 筆（seed {SEED}）" if k_per_term < 1000 else "；全部普查"))
    tot = collections.Counter()
    mins = collections.Counter()
    for t in TERMS:
        keys = sample([f"{x[0]}#{x[1]}" for x in by_term[t]], k_per_term, f"forum-{t}")
        for key in keys:
            mid, i = key.split("#")
            x = next(y for y in by_term[t] if y[0] == mid and y[1] == int(i))
            ls = lines_of(x[2]["agenda_lcidc_ids"])
            seg = any(k == "seg" and "國是論壇" in norm(tx) for _, _, _, k, tx in ls)
            chair_all = [norm(tx) for _, _, s, k, tx in ls if s == "主席" and k == "say"]
            ann = any(re.search(r"進行國是論壇", c) for c in chair_all)
            ment = any("國是論壇" in c for c in chair_all)
            reg = forum_region(ls)
            C, M, T = signals(reg)
            for c in chair_all:
                mm = re.search(r"每位委員(?:發言)?(?:時間)?(?:為)?(\d+)分鐘", c)
                if mm and "國是論壇" in c:
                    mins[mm.group(1)] += 1
                    break
            else:
                mins["（沒講）"] += 1
            tot["n"] += 1; tot["seg"] += seg; tot["ann"] += ann; tot["ment"] += ment
            tot["T"] += len(T); tot["TC"] += agree(T, C); tot["TM"] += agree(T, M); tot["noT"] += (len(T) == 0)
            if verbose:
                print(f"  {mid:<16} 段落標記={int(seg)} 主席說『進行國是論壇』={int(ann)} 主席提到={int(ment)} "
                      f"T={len(T)} C∩T={agree(T, C)} M∩T={agree(T, M)}")
    n = tot["n"]
    print(f"  {n} 筆：公報「段落：國是論壇」標記 {tot['seg']}/{n}；主席說「進行國是論壇」{tot['ann']}/{n}；"
          f"主席有提到國是論壇 {tot['ment']}/{n}")
    print(f"  發言委員（時間戳輪次）T={tot['T']}：主席點名覆蓋 {tot['TC']}/{tot['T']} = {tot['TC']/max(1,tot['T']):.1%}；"
          f"段落：質詢 標記覆蓋 {tot['TM']}/{tot['T']} = {tot['TM']/max(1,tot['T']):.1%}；T=0 的記錄 {tot['noT']} 筆")
    print(f"  主席宣告的每人發言分鐘數：{dict(mins)}")
    return tot


def bill_name(r):
    c = norm(r.get("content"))
    c = re.sub(r"^(討論事項|討事項論)", "", c)
    c = re.sub(r"[（(](?:前接|後接|接續|續接)[^）)]*[）)]$", "", c)
    c = re.sub(DASH + r"{1,2}[^─—－]{1,24}" + DASH + r"{0,2}$", "", c)
    c = re.sub(r"一[^一]{2,8}一$", "", c)
    return c


def short_key(name):
    """比對用短名：取第一個「」內的法案名，否則取前 8 字（去掉 修正／增訂 等動詞前綴）。"""
    m = re.search(r"「([^」]{2,30})」", name)
    s = m.group(1) if m else name
    s = re.sub(r"^(?:修正|增訂|廢止|制定)", "", s)
    return s[:8]


def measure_discussion(k_per_term=10, verbose=False):
    recs = records_by_type()["討論事項"]
    groups = {"當天處理完": [], "延後": []}
    for x in recs:
        mid, i, r, sub, term = x
        if not r.get("agenda_lcidc_ids") or not sub.startswith("法案"):
            continue
        if sub.endswith("當天處理完"):
            groups["當天處理完"].append(x)
        elif "延後" in sub:
            groups["延後"].append(x)
    print("## 討論事項：逐案起點與內部階段（抽樣，文件層）")
    for g, pool in groups.items():
        by_term = collections.defaultdict(list)
        for x in pool:
            by_term[x[4]].append(x)
        print(f"  [{g}] 母體 各屆 " + "／".join(f"{t}屆{len(by_term[t])}" for t in TERMS) + f"；每屆抽 {k_per_term} 筆")
        tot = collections.Counter()
        for t in TERMS:
            keys = sample([f"{x[0]}#{x[1]}" for x in by_term[t]], k_per_term, f"disc-{g}-{t}")
            for key in keys:
                mid, i = key.split("#")
                x = next(y for y in by_term[t] if y[0] == mid and y[1] == int(i))
                r = x[2]
                sk = short_key(bill_name(r))
                ls = lines_of(r["agenda_lcidc_ids"])
                segs = [norm(tx) for _, _, _, k, tx in ls if k == "seg"]
                chair = [norm(tx) for _, _, s, k, tx in ls if s == "主席" and k == "say"]
                seg_hit = [s for s in segs if sk in s]
                f = {
                    "段落標記含案名": bool(seg_hit),
                    "段落標記（二讀）": any("二讀" in s for s in seg_hit),
                    "段落標記（三讀）": any("三讀" in s for s in seg_hit),
                    "主席句含案名": any(sk in c for c in chair),
                    "主席『討論事項第○案』(文件內)": any(re.search(r"討論事項第", c) for c in chair),
                    "主席『廣泛討論』(文件內)": any("廣泛討論" in c for c in chair),
                    "主席『逐條討論』(文件內)": any(re.search(r"逐條(?:討論|審查)", c) for c in chair),
                    "主席『三讀』(文件內)": any(re.search(r"進行三讀|三讀條文", c) for c in chair),
                    "主席『尚待協商／協商後再』(文件內)": any(re.search(r"尚待協商|協商後(?:再|處理)|交.{0,4}協商", c) for c in chair),
                    "完成立法程序後委員發言(文件內)": any("完成立法程序後" in c for c in chair),
                }
                tot["n"] += 1
                for k2, v in f.items():
                    tot[k2] += v
                if verbose:
                    print(f"    {mid:<14} {sk:<10} " + " ".join(k2[:6] + "=" + str(int(v)) for k2, v in f.items()))
        n = tot.pop("n")
        for k2, v in tot.items():
            print(f"    {k2:<28} {v}/{n}")


CASE_ANN = re.compile(r"(?:進行|進入|處理|現在是|繼續進行)討論事項第([一二三四五六七八九十百零〇○\d]+)案")


def measure_cases(k_per_term=5, verbose=False):
    """記錄層：每一筆討論事項記錄，能不能在逐字稿找到自己的起點？
    (a) 主席宣告「進行討論事項第○案」後 6 段內出現本案短名
    (b) 公報「段落：」標記含本案短名"""
    meets = [m for m in all_meets() if any(t == "討論事項" for t, _, r, _ in classify_meet(m.get("公報發言紀錄") or [])
                                           if r.get("agenda_lcidc_ids"))]
    by_term = collections.defaultdict(list)
    for m in meets:
        by_term[m["屆"]].append(m["會議代碼"])
    print("## 討論事項：每筆記錄能否找到自己的起點（記錄層，抽樣場次內全部討論事項記錄）")
    print("  母體：有討論事項記錄的場次 各屆 " + "／".join(f"{t}屆{len(by_term[t])}" for t in TERMS) + f"；每屆抽 {k_per_term} 場")
    tot = collections.defaultdict(collections.Counter)
    misses = []
    for t in TERMS:
        for mid in sample(by_term[t], k_per_term, f"cases-{t}"):
            m = find_meet(mid)
            recs = [(sub, r) for top, sub, r, tl in classify_meet(m["公報發言紀錄"]) if top == "討論事項"]
            keys = [(short_key(bill_name(r)), sub, r) for sub, r in recs]
            ls = list(walk(m))
            ann_hit, seg_hit = set(), set()
            for i, (lc, bi, spk, kind, text) in enumerate(ls):
                tx = norm(text)
                if kind == "seg":
                    for j, (sk, sub, r) in enumerate(keys):
                        if sk and sk in tx:
                            seg_hit.add(j)
                elif spk == "主席" and CASE_ANN.search(tx):
                    window = " ".join(norm(x[4]) for x in ls[i:i + 7])
                    for j, (sk, sub, r) in enumerate(keys):
                        if sk and sk in window:
                            ann_hit.add(j)
            for j, (sk, sub, r) in enumerate(keys):
                g = "當天處理完" if sub.endswith("當天處理完") else ("延後" if "延後" in sub else "其他")
                c = tot[g]
                c["n"] += 1; c["a"] += j in ann_hit; c["b"] += j in seg_hit; c["ab"] += (j in ann_hit or j in seg_hit)
                if j not in ann_hit and j not in seg_hit:
                    misses.append((mid, g, sk, norm(r.get("content"))[:60]))
    for g, c in tot.items():
        n = c["n"]
        print(f"  [{g}] {n} 筆：主席逐案宣告後見案名 {c['a']}/{n} = {c['a']/n:.0%}；"
              f"公報段落標記含案名 {c['b']}/{n} = {c['b']/n:.0%}；兩者任一 {c['ab']}/{n} = {c['ab']/n:.0%}")
    print(f"  兩者皆無：{len(misses)} 筆" + ("" if verbose else "（--verbose 列出）"))
    if verbose:
        for x in misses:
            print("    ", *x)
    return tot


# ───────────────────────── 對照現行程式 ─────────────────────────
def compare(meet_id):
    path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data", "meet-json", f"{meet_id}.json")
    meet = find_meet(meet_id)
    print(f"# {meet_id} 現行切分 vs 本研究提的結構")
    cur = json.load(open(path)) if os.path.exists(path) else None
    if not cur:
        print("  （repo 內沒有這場的 meet-json）")
    else:
        print(f"  【現行】{len(cur['sessions'])} 個 session")
        sigs = {}
        for i, se in enumerate(cur["sessions"]):
            secs = se["sections"]
            nb = sum(len(x["blocks"]) for x in secs)
            sig = tuple((b.get("para"), b.get("text")) for x in secs for b in x["blocks"])
            dup = sigs.get(sig)
            sigs.setdefault(sig, i)
            types = collections.Counter(x["type"] for x in secs)
            print(f"    s{i} {se.get('date')} blocks={nb:<5} sections={dict(types)}"
                  f"{'  ← 內容與 s%d 完全相同' % dup if dup is not None else ''}")
            print(f"       content: {norm(se.get('content'))[:70]}")
    print("  【本研究】依公報發言紀錄＋逐字稿訊號")
    cl = classify_meet(meet.get("公報發言紀錄") or [])
    for top, sub, r, t in cl:
        ids = r.get("agenda_lcidc_ids") or []
        note = ""
        if top in ("施政質詢", "專案報告質詢", "總預算案報告質詢", "國是論壇") and ids:
            ls = lines_of(ids)
            reg = forum_region(ls) if top == "國是論壇" else qa_region(ls, INTERP_START + r"|備質詢|進行質詢", stop_re=True)
            C, M, T = signals(reg)
            note = f"→ section 候選：主席點名 {len(C)} 位／時間戳委員輪次 {len(T)}"
        elif top == "討論事項":
            ls = lines_of(ids) if ids else []
            sk = short_key(bill_name(r))
            has = any(k == "seg" and sk and sk in norm(tx) for _, _, _, k, tx in ls)
            note = f"→ 公報段落標記{'有' if has else '無'}本案（{sk}）" + ("；延後處理，當天無實質討論" if "延後" in sub else "")
        print(f"    [{top}{('｜' + sub) if sub else ''}] {r.get('meetingDate')} {ids} {note}")


def run_all():
    inventory()
    print()
    measure_interpellation(4)
    print()
    measure_forum(1000)          # 每屆上限 1000 ＝ 全部有文件 id 的國是論壇記錄（普查）
    print()
    measure_discussion(10)
    print()
    measure_cases(5)
    for mid in ("院會-11-4-8", "院會-11-5-18"):
        print()
        compare(mid)


if __name__ == "__main__":
    ap = argparse.ArgumentParser(description="院會切分研究工具")
    ap.add_argument("--all", action="store_true", help="重跑全部 [實測] 數字")
    ap.add_argument("--inventory", action="store_true")
    ap.add_argument("--others", action="store_true")
    ap.add_argument("--anchors", action="store_true")
    ap.add_argument("--read", nargs=1, metavar="MEET")
    ap.add_argument("--doc", help="--read 時只看某份文件（lcidc）")
    ap.add_argument("--compare", nargs=1, metavar="MEET")
    ap.add_argument("--verbose", action="store_true")
    a = ap.parse_args()
    if a.all:
        run_all()
    elif a.inventory or a.others:
        inventory(a.others)
    elif a.anchors:
        measure_interpellation(4, a.verbose); print()
        measure_forum(1000, a.verbose); print()
        measure_discussion(10, a.verbose); print()
        measure_cases(5, a.verbose)
    elif a.read:
        skeleton(a.read[0], a.doc)
    elif a.compare:
        compare(a.compare[0])
    else:
        ap.print_help()
