#!/usr/bin/env python3
"""黨團協商切分研究：盤點、抽樣 anchor 普查、單份閱讀。只用標準函式庫，帶快取。

用法（在 repo 根目錄執行）：
  python3 findings/caucus-negotiation/scripts/negotiation.py all          # 重跑 README 所有 [實測] 數字
  python3 findings/caucus-negotiation/scripts/negotiation.py inventory    # 1. 盤點（meet 母體／公報目錄／IVOD）
  python3 findings/caucus-negotiation/scripts/negotiation.py sample       # 4. 每屆抽 10 份公報，量 anchor 覆蓋率
  python3 findings/caucus-negotiation/scripts/negotiation.py census       # 4'. 同 sample，但跑全部 344 份（2026-10-02）
  python3 findings/caucus-negotiation/scripts/negotiation.py read <lcidc> # 2. 印出一份公報全文（依段落，標發言人）
  python3 findings/caucus-negotiation/scripts/negotiation.py link         # 5. 協商 → 院會的可串欄位檢查

快取：與 scripts/stages.py 共用 repo 根目錄的 .cache/（同一套檔名規則），先查快取再打 API。
節流：跨行程檔案鎖，兩次實際請求間隔 ≥ 2 秒（有其他 agent 同時在打）；429 / 錯誤退避重試。
全部跑完（冷快取）約 400 次請求、15 分鐘；熱快取 < 1 秒。

抽樣皆用固定種子（SEED），重跑得到同一批樣本：
  inventory：meet 詳細／IVOD 每屆抽 MEET_SAMPLE 場
  sample   ：公報每屆抽 PER_TERM 份（排除索引／勘誤、同文件去重）
  link     ：帶議案編號的協商 meet 每屆抽 LINK_SAMPLE 場
"""
import collections, fcntl, json, os, random, re, sys, time, urllib.request
from urllib.parse import quote, urlencode

API = "https://v2.ly.govapi.tw"
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
CACHE = os.path.join(os.path.dirname(os.path.abspath(__file__)), ".cache")
TERMS = [8, 9, 10, 11]
SLEEP = 2.0
SEED = 20260919          # 抽樣固定種子，重跑結果一致
PER_TERM = 10            # 每屆抽樣份數（公報）
LINK_SAMPLE = 10         # 每屆抽樣場數（議案編號反查院會）
MEET_SAMPLE = 30         # 每屆抽樣場數（meet 詳細 / IVOD）
BASE_KEYS = {"屆", "會議代碼", "會議種類", "委員會代號", "委員會代號:str", "會期", "會次", "日期",
             "會議標題", "會議資料", "議事網資料", "name"}
# 公報目錄類別代碼 8 之下混入的非協商內容（索引、勘誤）
JUNK = re.compile(r"發言紀錄索引|勘誤")


# ---------------------------------------------------------------- fetch
def _throttle():
    """跨行程節流：本工具同時開多個行程時，也保證兩次實際請求間隔 ≥ SLEEP 秒。"""
    os.makedirs(CACHE, exist_ok=True)
    with open(os.path.join(CACHE, ".caucus_throttle"), "a+") as f:
        fcntl.flock(f, fcntl.LOCK_EX)
        f.seek(0)
        try:
            last = float(f.read().strip() or 0)
        except ValueError:
            last = 0
        wait = last + SLEEP - time.time()
        if wait > 0:
            time.sleep(wait)
        f.seek(0); f.truncate(); f.write(str(time.time())); f.flush()
        fcntl.flock(f, fcntl.LOCK_UN)


def fetch(url, tries=6):
    os.makedirs(CACHE, exist_ok=True)
    key = os.path.join(CACHE, re.sub(r'[^A-Za-z0-9_.-]', '_', url.replace(API, "")) + ".json")
    if os.path.exists(key):
        try:
            return json.load(open(key))
        except Exception:
            pass
    for a in range(tries):
        try:
            _throttle()
            req = urllib.request.Request(quote(url, safe=":/?&=%,"),
                                         headers={"User-Agent": "research-ly-meet/1.0 (caucus)"})
            body = urllib.request.urlopen(req, timeout=120).read()
            d = json.loads(body)
            open(key, "wb").write(body)
            return d
        except Exception as e:
            if a == tries - 1:
                print(f"  [fetch 失敗] {url} → {e}", file=sys.stderr)
                return None
            time.sleep(SLEEP * 2 * (a + 1))


def paged(path, params, key, limit):
    out, page = [], 1
    while True:
        q = urlencode({**params, "limit": limit, "page": page})
        d = fetch(f"{API}/{path}?{q}") or {}
        out += d.get(key) or []
        if page >= (d.get("total_page") or 0):
            return out
        page += 1


# ---------------------------------------------------------------- 母體
def catalog():
    """公報目錄第 8–11 屆、類別代碼 8（黨團協商）的議程。"""
    rows = []
    for t in TERMS:
        rows += [a for a in paged("gazette_agendas", {"屆": t}, "gazetteagendas", 500)
                 if a.get("類別代碼") == 8]
    return rows


R_DOCID = re.compile(r'gazette_agenda_doc/(\d+_\d+)/')


def doc_id(agenda):
    """文件 id 從 處理後公報網址 取（公報議程編號 ≠ 文件 id）。"""
    for u in agenda.get("處理後公報網址") or []:
        if isinstance(u, dict) and u.get("type") == "parsed":
            m = R_DOCID.search(u.get("url", ""))
            if m:
                return m.group(1)
    return None


def meets():
    rows = []
    for t in TERMS:
        rows += paged("meets", {"會議種類": "黨團協商", "屆": t}, "meets", 100)
    return rows


def meet_detail(mid):
    return ((fetch(f"{API}/meet/{mid}") or {}).get("data")) or {}


def blocks(lcidc):
    return ((fetch(f"{API}/gazette_agenda_doc/{lcidc}/parsed") or {}).get("blocks")) or []


def paras(lcidc):
    return [p for b in blocks(lcidc) for p in (b or [])]


# ---------------------------------------------------------------- 發言人（段落層）
SPEAKER = re.compile(r'^([^：:，、。？！「」（）()]{2,16}?)[：:]')
STRUCT = re.compile(r'^(段落|項目|提案人|連署人|決議|附件|協商主題|協商結論|案由|說明|主旨|日期|時間|地點|'
                    r'主席|出席|列席|紀錄|記錄|附記)[：:]')
# 發言人標記三種型態：姓名＋職稱（鍾佳濱委員）、姓＋委員＋名（鍾委員佳濱）、
# 職稱＋名（劉副秘書長文仕、張副廳長松鈞——官員常見）。
# 只用「職稱收尾」會漏掉後兩種，段落被繼續歸給上一位（實測：屆9 監察法協商的官員發言
# 全被掛在主席名下）。因此改成「名稱中任一處含職稱字」即視為發言人。
SPEAKER_OK = re.compile(r'委員|長|主任|代表|主委|顧問|參事|專員|先生|女士|法官|檢察官|教授|總召|'
                        r'召委|書記|執行|經理|律師|議員|處|官|人員|視察|科長')


# 公報排版標頭（不是口說）。注意：不可把「一、二、」編號行一律當標頭——
# 主席宣讀協商結論時會逐點念「一、…二、…」，一律重設會把後面的「有無異議？散會」誤歸結構。
# 只收「編號＋紀錄標題／時間」這種屆 8 多場合刊時的標題前綴。
NUM = r"(?:[一二三四五六七八九十]+、)?"
HEADER = re.compile(rf'^{NUM}(?:黨團協商紀錄|立法院.{{0,30}}(?:會議紀錄|協商結論|協商紀錄)|時\s*間|地\s*點|'
                    rf'主\s+席[\s　]|協商主題|協商主持人|協商代表|協商時間|協商地點|法案名稱|段落[：:])')
R_CHAIRLINE = re.compile(r"^主\s+席[\s　]+(\S+)")
CHAIR_PAREN = re.compile(r'^主席[（(]([^）)]{2,12})[）)][：:]')


sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
R_READ = re.compile(r"^[^：:\n]{0,14}協商結論[：:]")
R_REC_CONC = re.compile(r"^(?:段落[：:])?立法院.{0,4}黨團協商結論")   # 書面結論頁標題（S5），不是口頭宣讀
import speakers as SPK   # 2026-10-02：改用五類會議共用的發言人辨識（findings/speakers/README.md）


def speaker_paras(ps):
    """[(speaker, text)]，用共用模組 scripts/speakers.py（chair_alias=True：標頭主席以本名發言也算主席）。
    排版／結構段落一律標「〔結構〕」，與舊版介面相同，下游的 anchor 與 records() 不用改。"""
    out, last, reading = [], None, False
    for p in SPK.walk(ps, chair_alias=True):
        # 主席口頭宣讀結論：「第一案協商結論：」「宣讀協商結論：…」「教保條例協商結論：」。共用模組把它當文件標題
        # （其他會議類型這樣才對），但黨團協商裡這是主席在唸，之後沒有新標記的「現在協商結論已經簽好了…散會」
        # 也還是主席。只在前一位口說者是主席時成立，遇到下一個發言人標記或書面結論頁標題（S5）就結束。
        # [判讀|讀過 4/40 份抽樣中出現此型的全部]：1089803_00006、1080606_00007、1118105_00007、1150701_00013
        if p["kind"] == "struct" and p["new"]:
            reading = bool(last == "主席" and R_READ.match(p["raw"]) and not R_REC_CONC.match(p["raw"]))
        elif p["new"]:
            reading = False
        if reading and p["kind"] in ("struct", "image"):
            out.append(("主席", p["text"]))
            last = "主席"
            continue
        last = p["speaker"] if p["kind"] == "say" else None   # 只看緊鄰的上一段
        if p["kind"] in ("struct", "seg"):
            out.append(("〔結構〕", p["text"]))
        elif p["kind"] == "none":
            out.append((None, p["text"]))
        else:
            out.append((p["speaker"], p["text"]))
    return out


def speaker_paras_old(ps):
    """（2026-09-19 舊版，保留給 scripts/audit_speakers_all.py 對帳用，本檔不再使用）
    [(speaker, text)]；發言人標記跑狀態機，無標記段落沿用上一位。
    公報標頭（紀錄標題、時間、地點、協商主題、協商結論…）會把狀態重設為〔結構〕，
    否則標頭與書面結論會被誤算成主席發言。"""
    out, cur, chairs = [], None, set()
    for p in ps:
        if HEADER.match(p):
            mh = R_CHAIRLINE.match(p)
            if mh:   # 主席行列出的人名：之後以本名標記發言（「羅委員智強：」）也算主席
                chairs = set(re.split(r"[、，,（）()]", re.sub(r"^主\s+席[\s　]+", "", p))) - {"", "代理"}
            cur = "〔結構〕"
            out.append((cur, p.strip()))
            continue
        mc = CHAIR_PAREN.match(p)          # 共同主持：「主席（廖委員正井）：」
        if mc:
            cur = "主席"
            out.append((cur, p[mc.end():].strip()))
            continue
        m = SPEAKER.match(p)
        if m:
            name = m.group(1)
            if name == "主席" or name in chairs:
                cur = "主席"
                p = p[m.end():]
            elif SPEAKER_OK.search(name):
                cur = name
                p = p[m.end():]
            elif STRUCT.match(p):
                cur = "〔" + name + "〕"
        out.append((cur, p.strip()))
    return out


# ---------------------------------------------------------------- anchors
# 以「份」為單位計命中（任一段落命中即算）；「主席」欄只看主席段。所有正則皆在本檔，改了就重跑。
CN = "一二三四五六七八九十"
ANCHORS = [
    # 結構層（公報排版，不是口說）
    ("S1 會議紀錄標題",   r"^(?:[一二三四五六七八九十]+、)?立法院第\S{1,3}屆第\S{1,3}會期.{0,12}黨團協商會議紀錄", "all"),
    ("S2 時間行",         r"^時\s*間[\s　:：]", "all"),
    ("S3 主席行",         r"^主\s+席[\s　]", "all"),
    ("S4 協商主題行",     r"^協商主題", "all"),
    ("S5 書面協商結論標題", r"^(?:段落[：:])?立法院.{0,4}黨團協商結論", "all"),
    ("S6 簽名欄",         r"^協商主持人|^協商代表", "all"),
    # 主席口說
    ("C1 開會/開始協商",  r"現在(?:正式)?(?:開始)?開會|開始.{0,6}(?:協商|會議)|(?:協商|會議)開始|進行.{0,8}協商", "chair"),
    ("C2 換主題",         rf"(?:進入|進行|接下來|處理|回到)[^。]{{0,10}}第[{CN}\d]+(?:個)?(?:協商主題|案|個案子|項|主題)", "chair"),
    ("C3 宣告協商結論",   r"協商結論(?:如下|是|為|：)|[做作]成(?:協商)?結論|試擬結論", "chair"),
    ("C4 無共識/保留",    r"沒有(?:辦法)?(?:達成)?共識|無法(?:達成)?共識|保留[^。]{0,10}院會", "chair"),
    ("C5 請簽名",         r"簽名|簽字", "chair"),
    ("C6 散會",           r"散會", "chair"),
    ("C7 輪次宣告",       rf"第[{CN}\d]輪", "chair"),
    ("C8 主席提及第○條",  rf"第[{CN}百零〇\d]+條", "chair"),
    # 候選 session / section anchor（主席口說）
    ("T1 換協商主題/換案", rf"(?:首先|接下來|接著|現在|再來|先)[^。]{{0,6}}第[{CN}\d]+(?:個)?(?:案|議題|主題|協商主題|個案子|項議題)"
                          rf"|(?:進行|處理|進入)[^。]{{0,6}}第[{CN}\d]+(?:個)?(?:案|議題|協商主題|個案子)", "chair"),
    ("T2 換條文",          rf"(?:現在|接著|接下來|再來|先|首先)[^。]{{0,4}}(?:處理|討論|進行|是|看|回到|回歸到)[^。]{{0,4}}第[{CN}百零〇\d]+條", "chair"),
    ("T3 同場另開一場協商", r"接下來(?:召開|進行)[^。]{0,30}協商", "chair"),
    ("T4 附帶決議處理",    r"處理附帶決議|附帶決議[^。]{0,8}第[\d一二三四五六七八九十]+案", "chair"),
]
R_REC = re.compile(ANCHORS[0][1])


def records(sp):
    """依『會議紀錄標題』把一份公報切成多場會議紀錄；標題前的內容歸第 0 段。"""
    out, cur = [], []
    for x in sp:
        if R_REC.match(x[1]) and cur:
            out.append(cur); cur = []
        cur.append(x)
    if cur:
        out.append(cur)
    return [r for r in out if any(R_REC.match(t) for _, t in r)] or out


def topic_items(sp):
    """協商主題行起、到第一個發言人段落前，數『一、二、…』的主題數；沒編號算 1。"""
    n, on = 0, False
    for who, t in sp:
        if t.startswith("協商主題"):
            on = True
            body = t[4:].strip(" 　：:")
            n = 1 if not re.match(rf"^[{CN}]+、", body) else 1
            continue
        if on:
            if who and not who.startswith("〔"):
                break
            if re.match(rf"^[{CN}]+、", t):
                n += 1
    return n


def dump(lcidc, maxlen=0):
    for i, (sp, t) in enumerate(speaker_paras(paras(lcidc))):
        s = t if not maxlen else t[:maxlen]
        print(f"{i:4d} [{sp or '-'}] {s}")


# ---------------------------------------------------------------- 指令
def cmd_inventory():
    cat = catalog()
    print(f"\n## 公報目錄 類別代碼=8（黨團協商）第 8–11 屆")
    c = collections.Counter(a.get("屆") for a in cat)
    print("  依屆：", dict(sorted(c.items())), "合計", len(cat))
    nodoc = [a for a in cat if not doc_id(a)]
    print("  沒有 parsed 文件網址：", len(nodoc))
    ids = [doc_id(a) for a in cat]
    print("  文件 id 重複：", len(ids) - len(set(ids)))
    offs = collections.Counter(
        int(doc_id(a).split("_")[1]) - int(a["公報議程編號"].split("_")[1]) for a in cat if doc_id(a))
    print("  文件 id − 議程編號 的偏移分布：", dict(offs))
    dates = collections.Counter(len(a.get("會議日期") or []) for a in cat)
    print("  每筆議程的會議日期數：", dict(dates))
    pages = [(a.get("結束頁碼") or 0) - (a.get("起始頁碼") or 0) + 1 for a in cat]
    pages.sort()
    print(f"  頁數：中位數 {pages[len(pages)//2]}，最小 {pages[0]}，最大 {pages[-1]}")
    # 案由開頭型態
    heads = collections.Counter(re.sub(r'[（(].*', '', (a.get("案由") or ""))[:12] for a in cat)
    print("  案由前 12 字 top10：")
    for h, n in heads.most_common(10):
        print(f"    {n:4d}  {h}")

    ms = meets()
    print(f"\n## meets API 會議種類=黨團協商")
    c = collections.Counter(m.get("屆") for m in ms)
    print("  依屆：", dict(sorted(c.items())), "合計", len(ms))
    # 每場的議案數（議事網資料.關係文書.議案）
    nb = collections.Counter()
    by_term_nb = collections.defaultdict(list)
    for m in ms:
        n = sum(len(((x or {}).get("關係文書") or {}).get("議案") or []) for x in m.get("議事網資料") or [])
        by_term_nb[m.get("屆")].append(n)
        nb[n if n < 10 else "10+"] += 1
    print("  每場關係文書議案數分布：", dict(sorted(nb.items(), key=lambda x: (isinstance(x[0], str), x[0]))))
    for t, v in sorted(by_term_nb.items()):
        v = sorted(v)
        print(f"    屆{t}：中位數 {v[len(v)//2]}，0 案 {sum(1 for x in v if x == 0)} 場")
    # 會議事由的「一、二、三、」主題數
    tn = collections.Counter()
    for m in ms:
        s = " ".join(str((x or {}).get("會議事由") or "") for x in m.get("會議資料") or [])
        k = len(re.findall(r'(?:^|[\s。；;])[一二三四五六七八九十]+、', s))
        tn[k if k < 6 else "6+"] += 1
    print("  會議事由「一、二、…」編號數（0＝未編號，視為單一主題）：",
          dict(sorted(tn.items(), key=lambda x: (isinstance(x[0], str), x[0]))))
    units = collections.Counter(str(((m.get("會議資料") or [{}])[0] or {}).get("會議單位")) for m in ms)
    print("  會議單位 top8：")
    for u, n in units.most_common(8):
        print(f"    {n:4d}  {u}")

    # ---- 公報 ↔ meet：用日期能不能對上（純列表欄位，不另打 API）
    print(f"\n## 公報目錄 ↔ meets：以會議日期對應")
    mdates = collections.defaultdict(list)
    for m in ms:
        for dt in m.get("日期") or []:
            mdates[dt].append(m["會議代碼"])
    real = [a for a in cat if not JUNK.search(a.get("案由") or "")]
    print(f"  公報目錄中非協商內容（{JUNK.pattern}）：{len(cat) - len(real)} 筆，其餘 {len(real)} 筆")
    for t in TERMS:
        rows = [a for a in real if a.get("屆") == t]
        n0 = n1 = nm = 0
        for a in rows:
            k = len({x for dt in a.get("會議日期") or [] for x in mdates.get(dt, [])})
            if k == 0: n0 += 1
            elif k == 1: n1 += 1
            else: nm += 1
        print(f"  屆{t}：{len(rows)} 筆；同日 0 場 meet {n0}、恰 1 場 {n1}、2 場以上 {nm}")

    # ---- meet 詳細與 IVOD：分層抽樣（每屆 MEET_SAMPLE 場）
    print(f"\n## meet 詳細資料與 IVOD（每屆隨機抽 {MEET_SAMPLE} 場，seed={SEED}）")
    rnd = random.Random(SEED)
    for t in TERMS:
        pool = sorted([m for m in ms if m.get("屆") == t], key=lambda m: m["會議代碼"])
        if not pool:
            print(f"  屆{t}：meets API 無資料")
            continue
        smp = rnd.sample(pool, min(MEET_SAMPLE, len(pool)))
        g = iv = 0
        extra = collections.Counter()
        lens = []
        for m in smp:
            d = meet_detail(m["會議代碼"])
            for k in d:
                if k not in BASE_KEYS:
                    extra[k] += 1
            if d.get("公報發言紀錄"):
                g += 1
            q = urlencode({"會議資料.會議代碼": m["會議代碼"], "limit": 50})
            v = (fetch(f"{API}/ivods?{q}") or {}).get("ivods") or []
            if v:
                iv += 1
                lens.append(max(x.get("影片長度") or 0 for x in v))
        lens.sort()
        med = f"{lens[len(lens)//2]/60:.0f} 分" if lens else "-"
        rng = f"{lens[0]/60:.0f}–{lens[-1]/60:.0f} 分" if lens else "-"
        print(f"  屆{t}：抽 {len(smp)}/{len(pool)} 場；detail 有公報發言紀錄 {g}；有 IVOD {iv}；"
              f"IVOD 最長片長 中位數 {med}（範圍 {rng}）；detail 額外欄位 {dict(extra)}")


def sample_docs():
    cat = catalog()
    rnd = random.Random(SEED)
    out = []
    for t in TERMS:
        pool = sorted({doc_id(a): a for a in cat if a.get("屆") == t and doc_id(a)
                       and not JUNK.search(a.get("案由") or "")}.values(), key=lambda a: a["公報議程編號"])
        out += [(t, a) for a in rnd.sample(pool, min(PER_TERM, len(pool)))]
    return out


def all_docs():
    """全部協商紀錄（排除索引／勘誤、同文件去重），2026-10-02 全量普查用。"""
    cat = catalog()
    out = []
    for t in TERMS:
        pool = sorted({doc_id(a): a for a in cat if a.get("屆") == t and doc_id(a)
                       and not JUNK.search(a.get("案由") or "")}.values(), key=lambda a: a["公報議程編號"])
        out += [(t, a) for a in pool]
    return out


def cmd_sample(full=False):
    rows = all_docs() if full else sample_docs()
    if full:
        print(f"\n## 全量普查：第 8–11 屆全部協商紀錄（排除索引/勘誤、同文件去重），共 {len(rows)} 份")
    else:
        print(f"\n## 抽樣：每屆 {PER_TERM} 份公報（seed={SEED}，排除索引/勘誤），共 {len(rows)} 份")
    hit = collections.defaultdict(collections.Counter)
    n_by_t = collections.Counter()
    print("  屆 | lcidc | 案由日期 | 紀錄場數 | 紀錄日期 | 主席 | 主題數 | 段落 | 主席段 | 發言人數 | 書面結論 | 簽名欄")
    agg = collections.Counter()
    agg_mis = []
    chairs = collections.Counter()
    for t, a in rows:
        lc = doc_id(a)
        ps = paras(lc)
        if not ps:
            print(f"  {t} {lc}: parsed 空或失敗")
            continue
        n_by_t[t] += 1
        sp = speaker_paras(ps)
        recs = records(sp)
        rdates = [re.sub(r"（.*", "", x[1]).replace("時　　間", "").strip()[:14]
                  for x in sp if re.match(ANCHORS[1][1], x[1]) and "中華民國" in x[1]]
        ch = [R_CHAIRLINE.match(x[1]).group(1) for x in sp if R_CHAIRLINE.match(x[1])]
        for c in ch:
            chairs[("院長/副院長" if re.search("院長", c) else "委員") + f"|屆{t}"] += 1
        chair_txt = [x for w, x in sp if w == "主席"]
        spk = {w for w, _ in sp if w and not w.startswith("〔") and w != "主席"}
        ntop = [topic_items(r) for r in recs]
        has_conc = any(re.match(ANCHORS[4][1], x) for _, x in sp)
        has_sig = any(re.match(ANCHORS[5][1], x) for _, x in sp)
        rec_ad = set()
        for x in sp:
            m = re.match(r"^時\s*間[\s　:：]*(?:中華民國)?(\d{2,3})年(\d{1,2})月(\d{1,2})日", x[1])
            if m:
                rec_ad.add(f"{int(m.group(1)) + 1911:04d}-{int(m.group(2)):02d}-{int(m.group(3)):02d}")
        cd = (a.get("會議日期") or [""])[0]
        agg["date_parsed"] += bool(rec_ad)
        agg["date_match"] += cd in rec_ad
        if rec_ad and cd not in rec_ad:
            agg_mis.append(f"{lc} 目錄 {cd} vs 紀錄 {sorted(rec_ad)}")
        agg["docs"] += 1
        agg["recs"] += len(recs)
        agg["multi_rec"] += len(recs) > 1
        agg["dialog"] += len(chair_txt) > 0
        agg["multi_topic"] += max(ntop or [0]) > 1
        date_ok = any(dt in (a.get("會議日期") or [""])[0] for dt in []) if False else None
        print(f"  {t} | {lc} | {(a.get('會議日期') or ['-'])[0]} | {len(recs)} | {';'.join(rdates)[:60]} | "
              f"{','.join(ch)[:24]} | {ntop} | {len(ps)} | {len(chair_txt)} | {len(spk)} | "
              f"{'有' if has_conc else '-'} | {'有' if has_sig else '-'}")
        for name, rx, scope in ANCHORS:
            r = re.compile(rx)
            pool = chair_txt if scope == "chair" else [x for _, x in sp]
            if any(r.search(p) for p in pool):
                hit[name][t] += 1
    tot = sum(n_by_t.values())
    # ---- 逐場（會議紀錄）統計
    recstat = collections.Counter()
    durs, nspk = [], []
    rT1 = re.compile(dict((a, b) for a, b, _ in ANCHORS)["T1 換協商主題/換案"])
    rT2 = re.compile(dict((a, b) for a, b, _ in ANCHORS)["T2 換條文"])
    rR = re.compile(dict((a, b) for a, b, _ in ANCHORS)["C7 輪次宣告"])
    rC8 = re.compile(dict((a, b) for a, b, _ in ANCHORS)["C8 主席提及第○條"])
    for t, a in rows:
        sp = speaker_paras(paras(doc_id(a)))
        for r in records(sp):
            recstat["recs"] += 1
            ch = [x for w, x in r if w == "主席"]
            k = topic_items(r)
            if k > 1:
                recstat["multi"] += 1
                recstat["multi_T1"] += any(rT1.search(x) for x in ch)
            recstat["C8"] += any(rC8.search(x) for x in ch)
            recstat["T2"] += any(rT2.search(x) for x in ch)
            recstat["R"] += any(rR.search(x) for x in ch)
            nspk.append(len({w for w, _ in r if w and not w.startswith("〔") and w != "主席"}))
            for w, x in r:
                m = re.search(r"(\d{1,2})時(\d{1,2})?分?至(\d{1,2})時(\d{1,2})?分?", x)
                if w == "〔結構〕" and re.match(r"^時\s*間", x) and m:
                    h1, m1, h2, m2 = (int(g or 0) for g in m.groups())
                    durs.append((h2 * 60 + m2) - (h1 * 60 + m1))
                    break
    durs.sort(); nspk.sort()
    print(f"\n  逐場（會議紀錄）：{recstat['recs']} 場")
    print(f"    多主題紀錄（協商主題行 ≥2 項）{recstat['multi']} 場，其中主席有 T1 換案宣告 {recstat['multi_T1']} 場")
    print(f"    主席提及『第○條』{recstat['C8']} 場；有 T2『處理第○條』句型 {recstat['T2']} 場；有『第○輪』{recstat['R']} 場")
    if durs:
        print(f"    標頭時間可算出時長 {len(durs)} 場：中位數 {durs[len(durs)//2]} 分，範圍 {durs[0]}–{durs[-1]} 分")
    print(f"    非主席發言人數：中位數 {nspk[len(nspk)//2]}，範圍 {nspk[0]}–{nspk[-1]}")
    print(f"\n  份數：{tot}；含 ≥2 場會議紀錄 {agg['multi_rec']}；會議紀錄合計 {agg['recs']} 場；"
          f"有主席口說段 {agg['dialog']}；有 ≥2 個協商主題的紀錄 {agg['multi_topic']}")
    print(f"  公報目錄『會議日期』與紀錄標頭日期一致：{agg['date_match']}/{agg['date_parsed']}（可解析標頭日期的份數）")
    for x in agg_mis:
        print(f"    不一致：{x}")
    print(f"  主席行（逐場）：{dict(sorted(chairs.items()))}")
    print(f"\n  anchor 覆蓋率（命中份數/成功讀取份數），各屆分母 {dict(n_by_t)}")
    for name, _, scope in ANCHORS:
        cells = " ".join(f"屆{t}:{hit[name][t]}/{n_by_t[t]}" for t in TERMS)
        print(f"    {name:22s} [{'主席段' if scope == 'chair' else '全文'}] {sum(hit[name].values()):2d}/{tot}   {cells}")


def cmd_link():
    ms = meets()
    print("\n## 協商 meet → 院會：以 議事網資料.關係文書.議案.議案編號 串接")
    rnd = random.Random(SEED)
    for t in TERMS:
        pool = [m for m in ms if m.get("屆") == t]
        if not pool:
            print(f"  屆{t}：meets API 無資料"); continue
        withbill = [m for m in pool if bills(m)]
        print(f"  屆{t}：{len(withbill)}/{len(pool)} 場協商 meet 帶有議案編號")
        if not withbill:
            continue
        smp = rnd.sample(sorted(withbill, key=lambda m: m["會議代碼"]), min(LINK_SAMPLE, len(withbill)))
        hit = after = 0
        kinds = collections.Counter()
        for m in smp:
            d0 = min(m.get("日期") or ["9999"])
            b = bills(m)[0]
            q = urlencode({"議事網資料.關係文書.議案.議案編號": b, "limit": 100})
            r = (fetch(f"{API}/meets?{q}") or {}).get("meets") or []
            k = collections.Counter(x.get("會議種類") for x in r)
            kinds.update(k.keys())
            hit += k.get("院會", 0) > 0
            after += any(x.get("會議種類") == "院會" and max(x.get("日期") or [""]) > d0 for x in r)
        print(f"    抽 {len(smp)} 場，以第 1 個議案編號反查：同議案出現在院會 meet 的有 {hit} 場"
              f"（其中有院會日期晚於協商日 {after} 場）；"
              f"反查結果含會議種類（場數）{dict(kinds)}")


def bills(m):
    return [b.get("議案編號") for x in m.get("議事網資料") or []
            for b in ((x or {}).get("關係文書") or {}).get("議案") or [] if b.get("議案編號")]


def cmd_read(lcidc):
    dump(lcidc)


if __name__ == "__main__":
    a = sys.argv[1:] or ["all"]
    if a[0] == "inventory":
        cmd_inventory()
    elif a[0] == "sample":
        cmd_sample()
    elif a[0] == "census":
        cmd_sample(full=True)
    elif a[0] == "read":
        cmd_read(a[1])
    elif a[0] == "link":
        cmd_link()
    elif a[0] == "all":
        cmd_inventory(); cmd_sample(); cmd_link()
