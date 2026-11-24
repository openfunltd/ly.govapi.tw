#!/usr/bin/env python3
"""
委員會預算審查切分研究（盤點＋結構摸底）。只用標準函式庫，共用 repo 的 .cache/。

用法（在 repo 根目錄或任何地方）：
  python3 findings/committee-budget/scripts/budget.py inventory            # 第 11 屆盤點（委員會＋聯席會議）
  python3 findings/committee-budget/scripts/budget.py inventory --term 10  # 其他屆
  python3 findings/committee-budget/scripts/budget.py inventory --show 未標  # 列出某一格的原文
  python3 findings/committee-budget/scripts/budget.py read <meet_id>       # 單場結構：主席宣告＋結構標記時序
  python3 findings/committee-budget/scripts/budget.py measure              # 樣本：口頭 vs 書面篇幅
  python3 findings/committee-budget/scripts/budget.py anchors              # 樣本：主席逐項處理宣告的覆蓋率
  python3 findings/committee-budget/scripts/budget.py all                  # 重跑 README 所有 [實測] 數字

API：
  https://v2.ly.govapi.tw/meets?會議種類=委員會&屆=11&limit=50&page=N
  https://v2.ly.govapi.tw/meet/<meet_id>
  https://v2.ly.govapi.tw/gazette_agenda_doc/<lcidc>/parsed
每次請求間隔 ≥ 2 秒（有其他 agent 同時在打），429 會退避重試。快取在 repo 的 .cache/。
"""
import argparse, collections, json, os, re, sys, time, urllib.request
from urllib.parse import quote

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "../../.."))
CACHE = os.path.join(HERE, ".cache")
API = "https://v2.ly.govapi.tw"
_last = [0.0]


def fetch(url, tries=6):
    os.makedirs(CACHE, exist_ok=True)
    key = os.path.join(CACHE, re.sub(r'[^A-Za-z0-9_.-]', '_', url.replace(API, "")) + ".json")
    if os.path.exists(key):
        return json.load(open(key))
    fail = key[:-5] + ".FAILED"          # 負快取：穩定回 500 的文件不要每次重打 6 次
    if os.path.exists(fail):
        return None
    last_err = None
    for a in range(tries):
        wait = 2.0 - (time.time() - _last[0])
        if wait > 0:
            time.sleep(wait)
        try:
            _last[0] = time.time()
            req = urllib.request.Request(quote(url, safe=":/?&=%,"),
                                         headers={"User-Agent": "research-ly-meet/1.0"})
            body = urllib.request.urlopen(req, timeout=120).read()
            d = json.loads(body)
            open(key, "wb").write(body)
            return d
        except Exception as e:
            last_err = str(e)
            print(f"  [retry {a+1}] {url} → {e}", file=sys.stderr)
            time.sleep(4 * (a + 1))
    if last_err and ("500" in last_err or "404" in last_err):
        open(fail, "w").write(last_err)
    return None


FAILED = set()


# ───────────────────────────── 1. 盤點 ─────────────────────────────

KINDS = ["委員會", "聯席會議"]


def list_meets(term):
    out = []
    for kind in KINDS:
        p = 1
        while True:
            d = fetch(f"{API}/meets?會議種類={kind}&屆={term}&limit=50&page={p}")
            if not d:
                print(f"  [盤點失敗] {kind} 屆{term} p{p}", file=sys.stderr)
                break
            out.extend(d.get("meets") or [])
            if p >= (d.get("total_page") or 1):
                break
            p += 1
    return out


ITEM_SPLIT = re.compile(r'(?:^|(?<=[。\s】）)]))\s*(?=[一二三四五六七八九十]{1,3}、)')

# 議程項目的「標的」（依項目文字判斷；順序＝優先序）
TARGETS = [
    ("凍結案",       r"凍結|解凍"),
    ("總報告彙整",   r"彙總整理|審查總報告"),
    ("執行/決算",    r"預算執行情形|決算"),
    ("特別預算",     r"特別預算"),
    ("附屬單位預算", r"附屬單位預算"),
    ("財團/行政法人", r"(?:財團法人|行政法人).{0,60}(?:預算書|預算案|預算)|預算書"),
    ("總預算單位預算", r"總預算案?(?:[^。]{0,6})?(?:關於|有關|直轄市|行政院歲入|中央政府)|總預算案"),
]
REVIEW_TARGETS = {"特別預算", "附屬單位預算", "財團/行政法人", "總預算單位預算"}

# 階段標註（開會事由裡的括號備註或項目尾註）
ST_QP = re.compile(r"詢答及處理|詢答暨處理|詢答及逐項處理")
ST_Q = re.compile(r"[（(【]詢答[）)】]|僅詢答|僅進行詢答|綜合詢答|只詢答|僅作詢答")
ST_P = re.compile(r"[（(【]處理[）)】]|進行處理|處理預算提案|本日會議進行處理")


def split_items(text):
    t = re.sub(r'[ \t\r]+', ' ', text or "").strip()
    parts = [p.strip() for p in ITEM_SPLIT.split(t) if p.strip()]
    return parts or [t]


def item_target(it):
    if "預算" not in it and "決算" not in it:
        return None
    # 只是質詢／專題報告的題目提到預算，不是審預算
    main = re.split(r"[【]", it)[0]          # 只看項目本文，不看尾端的【…】備註
    if re.search(r"並備質詢|專題報告|業務概況|進行報告", main) and not re.search(r"審查.{0,20}(?:總預算|預算案|預算書)", main):
        return "質詢題目提到預算"
    for name, pat in TARGETS:
        if re.search(pat, it):
            if name == "總預算單位預算" and not re.search(r"審查|處理|討論|總預算案", it):
                continue
            return name
    return "其他含預算字樣"


def day_stage(text):
    """回傳這一天的階段標註：詢答+處理／僅詢答／處理／混合／未標"""
    t = text or ""
    qp, q, p = bool(ST_QP.search(t)), bool(ST_Q.search(t)), bool(ST_P.search(t))
    if qp:
        return "詢答及處理" if not q else "混合(部分僅詢答)"
    if q and p:
        return "混合(部分僅詢答)"
    if q:
        return "僅詢答"
    if p:
        return "處理"
    return "未標"


def bucket(targets, stage):
    """PM 的五型（以一天為單位；一天可命中多型，這裡取主型並另計混合）"""
    rev = [t for t in targets if t in REVIEW_TARGETS]
    tags = []
    if rev:
        if stage in ("僅詢答",):
            tags.append("①詢答")
        elif stage == "處理":
            tags.append("②處理")
        elif stage == "詢答及處理":
            tags.append("①+②同日")
        elif stage.startswith("混合"):
            tags.append("①/②混合")
        else:
            tags.append("?未標階段")
    if "凍結案" in targets:
        tags.append("④凍結案")
    if any(t in targets for t in ("總報告彙整", "執行/決算", "其他含預算字樣")):
        tags.append("⑤其他")
    return tags


def inventory_rows(term):
    rows = []
    for m in list_meets(term):
        for x in m.get("會議資料") or []:
            reason = x.get("會議事由") or ""
            if "預算" not in reason and "決算" not in reason:
                continue
            items = split_items(reason)
            targets = [t for t in (item_target(i) for i in items) if t]
            stage = day_stage(reason)
            rows.append({
                "meet": m["會議代碼"], "kind": m["會議種類"], "date": x.get("日期"),
                "session": m.get("會期"), "gazette": bool(m.get("公報發言紀錄")),
                "reason": re.sub(r'\s+', ' ', reason).strip(),
                "targets": targets, "stage": stage, "buckets": bucket(targets, stage),
                "review_sub": sorted({t for t in targets if t in REVIEW_TARGETS}),
            })
    return rows


def inventory(term, show=None):
    rows = inventory_rows(term)
    all_days = sum(len(m.get("會議資料") or []) for m in list_meets(term))
    print(f"第 {term} 屆 委員會＋聯席會議：會議資料（天）共 {all_days}；會議事由含「預算／決算」 {len(rows)} 天，"
          f"{len({r['meet'] for r in rows})} 場（會議代碼）")
    real = [r for r in rows if any(b != "⑤其他" for b in r["buckets"]) or r["buckets"] == ["⑤其他"]]
    only_q = [r for r in rows if not r["buckets"]]
    print(f"  其中只是質詢／報告題目提到預算（非審預算）：{len(only_q)} 天")

    print("\n[A] PM 五型（以「天」計；一天可多型，故加總 > 天數）")
    c = collections.Counter(b for r in rows for b in r["buckets"])
    cm = collections.Counter()
    for r in rows:
        for b in r["buckets"]:
            cm[(b, r["kind"])] += 1
    for b in ["①詢答", "②處理", "①+②同日", "①/②混合", "?未標階段", "④凍結案", "⑤其他"]:
        print(f"  {b:<10} {c[b]:>4} 天   (委員會 {cm[(b,'委員會')]:>3} ／聯席 {cm[(b,'聯席會議')]:>3})")

    print("\n[B] 審預算的天數，依標的 × 階段（③附屬／特別／法人預算與①②是交叉的，不是並列）")
    rev = [r for r in rows if r["review_sub"]]
    stages = ["僅詢答", "處理", "詢答及處理", "混合(部分僅詢答)", "未標"]
    print(f"  {'標的':<14}" + "".join(f"{s:>12}" for s in stages))
    for t in ["總預算單位預算", "附屬單位預算", "特別預算", "財團/行政法人"]:
        print(f"  {t:<14}" + "".join(f"{sum(1 for r in rev if t in r['review_sub'] and r['stage']==s):>12}" for s in stages))
    print(f"  審預算天數合計 {len(rev)}，其中有公報發言紀錄 {sum(r['gazette'] for r in rev)} 天")

    print("\n[C] 依會期（審預算的天，階段分佈）")
    for s in sorted({r["session"] for r in rev}):
        sub = [r for r in rev if r["session"] == s]
        cc = collections.Counter(r["stage"] for r in sub)
        print(f"  第{s}會期 {len(sub):>3} 天  " + "  ".join(f"{k}{cc[k]}" for k in stages if cc[k]))

    if show:
        print(f"\n[show] {show}")
        for r in rows:
            if show in r["buckets"] or show == r["stage"] or show in r["targets"]:
                print(f"  {r['meet']} {r['date']} {'公報' if r['gazette'] else '無公報'} {r['buckets']}")
                print(f"     {r['reason'][:400]}")
    return rows


def gazette_rows(term):
    """以「公報發言紀錄」一筆（通常＝一天、一份公報子文件）為單位。
    階段先看 content 的註記，沒有就退回同一天的會議事由註記。"""
    rows = []
    for m in list_meets(term):
        reasons = {x.get("日期"): (x.get("會議事由") or "") for x in m.get("會議資料") or []}
        for g in m.get("公報發言紀錄") or []:
            content = re.sub(r"\s+", "", g.get("content") or "")
            if "預算" not in content and "決算" not in content:
                continue
            date = (g.get("meetingDate") or [None])[0]
            items = split_items(re.sub(r"；", " ", content))
            targets = [t for t in (item_target(i) for i in items) if t]
            st_c = day_stage(content)
            st_r = day_stage(reasons.get(date, ""))
            stage, src = (st_c, "content") if st_c != "未標" else (st_r, "會議事由" if st_r != "未標" else "—")
            try:
                pages = int(g.get("page_end")) - int(g.get("page_start")) + 1
            except Exception:
                pages = None
            rows.append({"meet": m["會議代碼"], "kind": m["會議種類"], "date": date,
                         "session": m.get("會期"), "lcidc": (g.get("agenda_lcidc_ids") or [None])[0],
                         "lcidcs": list(g.get("agenda_lcidc_ids") or []),
                         "agenda_id": g.get("agenda_id"), "content": content, "pages": pages,
                         "targets": targets, "stage": stage, "stage_src": src,
                         "buckets": bucket(targets, stage),
                         "speakers": g.get("speakers") or [],
                         "multi_vol": bool(re.search(r"[前後]接第.冊", content)),
                         "review_sub": sorted({t for t in targets if t in REVIEW_TARGETS})})
    return rows


def median(xs):
    xs = sorted(x for x in xs if x is not None)
    if not xs:
        return None
    n = len(xs)
    return xs[n // 2] if n % 2 else (xs[n // 2 - 1] + xs[n // 2]) / 2


def gazette_inventory(term, show=None):
    rows = gazette_rows(term)
    print(f"\n[D] 第 {term} 屆 公報發言紀錄（≈一天一份公報子文件）content 含「預算／決算」：{len(rows)} 筆")
    c = collections.Counter(b for r in rows for b in r["buckets"])
    print("  PM 五型（一筆可多型）：" + "  ".join(f"{b} {c[b]}" for b in
          ["①詢答", "②處理", "①+②同日", "①/②混合", "?未標階段", "④凍結案", "⑤其他"]))
    print(f"  非審預算（只是質詢題目提到預算）：{sum(1 for r in rows if not r['buckets'])} 筆")
    src = collections.Counter(r["stage_src"] for r in rows if r["review_sub"])
    print(f"  審預算的 {sum(1 for r in rows if r['review_sub'])} 筆，階段判斷來源：{dict(src)}")
    print(f"  跨冊（content 有「前接／後接第○冊」）：{sum(r['multi_vol'] for r in rows)} 筆")
    print("  公報頁數（page_end − page_start + 1），依主型：")
    for b in ["①詢答", "②處理", "①+②同日", "?未標階段", "④凍結案"]:
        ps = [r["pages"] for r in rows if b in r["buckets"] and len(r["buckets"]) == 1]
        if ps:
            print(f"    {b:<8} 純型 {len(ps):>3} 筆  頁數中位數 {median(ps):>6}  最小 {min(ps):>4}  最大 {max(ps):>4}")
    if show:
        print(f"\n[show-gazette] {show}")
        for r in rows:
            if show in r["buckets"] or show == r["stage"]:
                print(f"  {r['meet']} {r['date']} lcidc={r['lcidc']} p={r['pages']} {r['buckets']}")
                print(f"     {r['content'][:300]}")
    return rows


def summary_terms(terms):
    print(f"  {'屆':>3}{'審預算天':>8}{'①詢答':>7}{'②處理':>7}{'①+②':>6}{'混合':>5}{'未標':>6}{'未標%':>6}{'④凍結':>7}{'⑤其他':>7}{'有公報':>7}")
    for t in terms:
        rows = inventory_rows(t)
        rev = [r for r in rows if r["review_sub"]]
        c = collections.Counter(b for r in rows for b in r["buckets"])
        un = c["?未標階段"]
        print(f"  {t:>3}{len(rev):>8}{c['①詢答']:>7}{c['②處理']:>7}{c['①+②同日']:>6}{c['①/②混合']:>5}{un:>6}"
              f"{un / max(len(rev), 1) * 100:>5.0f}%{c['④凍結案']:>7}{c['⑤其他']:>7}{sum(r['gazette'] for r in rev):>7}")
    print("  （單位：天＝會議資料一筆；「有公報」＝審預算的天中，該場會議有公報發言紀錄者）")


# ───────────────────────────── 2. 讀單場 ─────────────────────────────

SPEAKER = re.compile(r'^([^：:，、。？！]{2,14}?)[：:]')
SPEAKER_OK = re.compile(r'(委員|先生|女士|部長|次長|署長|局長|處長|秘書長|院長|主委|主任委員|'
                        r'主計長|審計長|總裁|董事長|總經理|主任|司長|執行長|總長|召集委員|首長|代表|副.{1,4})$'
                        r'|委員[一-鿿‧・A-Za-z ]{1,12}$'
                        r'|^[一-鿿]{1,2}(?:部長|次長|署長|局長|處長|主委|主任委員|主計長|審計長|總裁|董事長|總經理|主任|司長|執行長|院長|秘書長|委員)[一-鿿]{1,3}$')
STRUCT = re.compile(r'^(段落|項目|提案人|連署人|決議|附件|案由|說明|主決議|附帶決議)[：:]')


def meet_docs(meet_id):
    d = fetch(f"{API}/meet/{meet_id}")
    if not d or not d.get("data"):
        return []
    out = []
    for g in d["data"].get("公報發言紀錄") or []:
        for lc in g.get("agenda_lcidc_ids") or []:
            out.append({"lcidc": lc, "date": (g.get("meetingDate") or [None])[0],
                        "content": g.get("content"), "gazette": g.get("gazette_id"),
                        "page_start": g.get("page_start"), "page_end": g.get("page_end")})
    return out


def doc_blocks(lcidc):
    d = fetch(f"{API}/gazette_agenda_doc/{lcidc}/parsed")
    if d is None:
        FAILED.add(lcidc)
    return (d or {}).get("blocks") or []


def speaker_kind(raw):
    """段落開頭「XXX：」的 XXX 是哪一種：chair／person／written／prop／seg／None（不是發言人）"""
    if raw != raw.strip() or re.search(r"\s", raw):      # 「主    席：」「時    間：」這類排版欄位
        return None
    if raw in HEADER_FIELDS:
        return None
    if raw == "主席" or raw.startswith("主席（") or raw.startswith("主席("):
        return "chair"
    if "書面" in raw:
        return "written"
    if raw == "委員提案":
        return "prop"
    if raw.startswith("段落"):
        return "seg"
    if len(raw) <= 12 and TITLE.search(raw):
        return "person"
    return None


TITLE = re.compile(r"委員|長|秘書|主任|參事|官|主委|董事|經理|代表|顧問|專員|研究員|教授|律師|先生|女士|總裁|召委|主計")


def label_paras(bs):
    """把每一段貼上 (發言人, 段落文字, block 序號, 類別)。
    發言人標記在段落開頭（第 8–9 屆一個 block 可含多位發言人，所以逐段判斷）；
    沒標記的段落沿用前一位（狀態機，同 stages.chair_paras 的做法）。
    類別：
      口頭        有發言人標記的人講的話
      書面提案    委員預算提案全文（案由／說明／提案人…），不管排在誰名下
      宣讀文件    主席叫「宣讀」之後、下一位發言人之前的段落（預算數、協商結論、新增主決議…）
      議事錄      「段落：議事錄」到下一位發言人之前（上次會議紀錄，含上次的決議全文）
      書面        書面質詢／書面意見（發言人標記含「書面」）
      結構標記    段落：／決議：等排版標記，以及開頭的出席名單
      圖片引用    INCLUDEPICTURE（掃描圖，字數不計）
    """
    out, cur, cat = [], "（開頭）", "結構標記"
    for bi, blk in enumerate(bs):
        for pj, para in enumerate(blk or []):
            m = SPEAKER.match(para)
            body = para
            k = speaker_kind(m.group(1)) if m else None
            if k in ("chair", "person"):
                cur, cat, body = ("主席" if k == "chair" else m.group(1)), "口頭", para[m.end():]
            elif k == "written":
                cur, cat, body = m.group(1), "書面", para[m.end():]
            elif k == "prop":
                cur, cat, body = "〔委員提案〕", "書面提案", para[m.end():]
            elif k == "seg":
                if "議事錄" in para[:12]:
                    cur, cat = "〔議事錄〕", "議事錄"
                elif cat not in ("議事錄", "宣讀文件", "書面提案"):
                    cat = "結構標記"
            elif STRUCT.match(para) and cat == "口頭" and not PROP_MARK.search(para):
                cat = "結構標記"
            t = body.strip()
            # 書面提案：看段落本身的格式特徵，不看排在誰名下（公報常把提案全文接在主席的段落後面）
            if cat not in ("議事錄", "書面") and (PROP_MARK.search(t) or
                                                (len(t) > 60 and PROP_WORD.search(t) and MONEY.search(t))):
                cat = "書面提案"
            c = "圖片引用" if "INCLUDEPICTURE" in t else cat
            out.append((cur, t, bi, c))
            if cat == "口頭" and cur == "主席" and READ_CMD.search(t):
                cat = "宣讀文件"
    return out


# 書面提案的格式特徵（第 9 屆 1052201_00007、1058401_00004 逐段看出來的；見 README 第 5 節）
PROP_MARK = re.compile(r"^(?:案由|提案人|連署人|單位|科目|提案委員)[：:]|^[A-ZＡ-Ｚ]?[0-9０-９]{1,4}[、．.]$|^第[0-9０-９]{1,4}案[：:]?$")
PROP_WORD = re.compile(r"爰(?:提案|建請|請|要求|予以|減列|凍結|刪減|將|此)")
MONEY = re.compile(r"千元|萬元|億元")

HEADER_FIELDS = {"出席委員", "列席委員", "請假委員", "委員出席", "委員列席", "列席官員", "列席人員", "專門委員", "主任秘書",
                 "記錄", "紀錄", "時間", "地點", "決議", "決定", "說明", "案由", "提案人", "連署人", "單位", "科目",
                 "協商結論", "主決議", "附帶決議", "提案委員", "本日議程", "議程", "備註", "附註"}

READ_CMD = re.compile(r"(?:請|一併|現在)?(?:議事人員)?宣讀(?!上次會議議事錄|完畢)[^。]{0,40}(?:提案|預算數|議程|審查項目|協商結論|主決議|決議|附帶決議|報告|公開部分|機密部分)|請宣讀[。，]?$|進行討論事項，請宣讀")


def read(meet_id, maxlen=110, only_chair=True):
    docs = meet_docs(meet_id)
    if not docs:
        print(f"{meet_id}：公報發言紀錄為空")
        return
    for doc in docs:
        bs = doc_blocks(doc["lcidc"])
        print("─" * 100)
        print(f"{doc['lcidc']} {doc['date']} p{doc['page_start']}-{doc['page_end']} blocks={len(bs)}")
        print(f"  content: {(doc['content'] or '')[:200]}")
        prev = None
        for spk, t, bi, cat in label_paras(bs):
            if not t:
                continue
            if cat != "口頭" and cat != prev:
                print(f"  [{bi:>4}] 《{cat}》 {t[:60]}")
                prev = cat
                continue
            if cat != "口頭":
                continue
            if spk == "主席" and (not only_chair or len(t) > 4):
                print(f"  [{bi:>4}] 主席｜{t[:maxlen]}")
            elif spk != prev:
                print(f"  [{bi:>4}] ‹{spk}› {t[:60]}")
            prev = spk


# ───────────────────── 3. 口頭 vs 書面 篇幅量測 ─────────────────────

PIC = re.compile(r"INCLUDEPICTURE")


def doc_profile(lcidc, pages=None):
    """lcidc 可為單一 id 或 list（一筆公報發言紀錄可對應多份子文件，例：1140202_00002＋_00003）。"""
    ids = lcidc if isinstance(lcidc, list) else [lcidc]
    bs = [b for i in ids for b in doc_blocks(i)]
    labs = label_paras(bs)
    by = collections.Counter()
    for spk, t, bi, cat in labs:
        by[cat] += len(t) if cat != "圖片引用" else 0
    pics = sum(len(PIC.findall(t)) for _, t, _, _ in labs)
    total = sum(by.values())
    return {"lcidc": lcidc, "blocks": len(bs), "chars": total, "by": dict(by), "pics": pics,
            "pages": pages, "cpp": round(total / pages) if pages else None}


# 深讀與量測用的樣本（第 11 屆；抽樣方式見 README 第 3 節）
DEEP = {"①": ("委員會-11-5-23-10", "1153601_00002"), "②": ("委員會-11-5-23-20", "1155502_00002")}


def deep():
    """兩份深讀樣本的量化（README 第 2 節）"""
    for k, (mid, lc) in DEEP.items():
        rows = [r for r in gazette_rows(11) if r["meet"] == mid and lc in r["lcidcs"]]
        pages = rows[0]["pages"] if rows else None
        pr = doc_profile(lc, pages)
        bs = doc_blocks(lc)
        labs = label_paras(bs)
        ch = chair_texts(labs)
        marks = [p for b in bs for p in (b or []) if re.match(r"^段落[：:]質詢[：:]", p)]
        print(f"[{k}] {mid} {lc}  公報 {pages} 頁  parsed {pr['chars']:,} 字（{pr['cpp']} 字/頁）  圖片引用 {pr['pics']} 處  blocks {pr['blocks']}")
        for c, v in sorted(pr["by"].items(), key=lambda x: -x[1]):
            print(f"      {c:<8}{v:>8,} 字  {v / max(pr['chars'], 1) * 100:>4.0f}%")
        txt = "\n".join(ch)
        pa = dict(P_ANCHORS)
        n_case = sum(len(re.findall(pa[n], txt, flags=re.M)) for n in ("案號宣告", "單案唱名"))
        n_done = len(re.findall(pa["機關/基金審完"], txt))
        print(f"      主席段落 {len(ch)} 段；質詢 marker {len(marks)} 個；案號宣告 {n_case} 次；「審查完畢/完竣」{n_done} 次")


def sample_docs(term=11, per=1):
    """每個委員會 × 每個會期、每個主型取 per 筆（依 lcidc 排序取第一筆，可重現）。"""
    rows = gazette_rows(term)
    pick = {}
    for r in sorted(rows, key=lambda r: r["lcidc"] or ""):
        if len(r["buckets"]) != 1 or r["buckets"][0] not in ("①詢答", "②處理", "①+②同日", "?未標階段", "④凍結案"):
            continue
        if not r["lcidc"]:
            continue
        comm = r["meet"].split("-")[3] if r["kind"] == "委員會" else "聯席"
        k = (r["buckets"][0], comm, r["session"])
        pick.setdefault(k, []).append(r)
    out = []
    for k in sorted(pick):
        out.extend(pick[k][:per])
    return out


def measure(term=11, only=None):
    docs = sample_docs(term)
    if only:
        seen, keep = set(), []
        for r in docs:
            comm = r["meet"].split("-")[3] if r["kind"] == "委員會" else "聯席"
            if r["buckets"][0] == only and comm not in seen:
                seen.add(comm)
                keep.append(r)
        docs = keep
    print(f"[E] 口頭 vs 非口頭篇幅（第 {term} 屆；每個 主型×委員會×會期 取 1 份，共 {len(docs)} 份）")
    cats = ["口頭", "書面提案", "宣讀文件", "議事錄", "書面", "結構標記"]
    agg = collections.defaultdict(list)
    print(f"  {'主型':<8}{'lcidc':<16}{'頁':>5}{'字數':>8}{'字/頁':>6}{'圖':>5}  " + "".join(f"{c:>8}" for c in cats))
    for r in docs:
        pr = doc_profile(r["lcidcs"], r["pages"])
        if any(i in FAILED for i in r["lcidcs"]):
            print(f"  {r['buckets'][0]:<8}{r['lcidc']:<16}  parsed 端點失敗，略過")
            continue
        b = r["buckets"][0]
        agg[b].append(pr)
        print(f"  {b:<8}{r['lcidc'] + ('+' if len(r['lcidcs']) > 1 else ''):<16}{r['pages'] or 0:>5}{pr['chars']:>8}{pr['cpp'] or 0:>6}{pr['pics']:>5}  " +
              "".join(f"{pr['by'].get(c,0)/max(pr['chars'],1)*100:>7.0f}%" for c in cats))
    print("\n  彙總（各主型；比例＝該類字數／parsed 總字數，不含圖片）：")
    for b, prs in agg.items():
        tot = sum(p["chars"] for p in prs)
        print(f"  {b:<8} {len(prs):>2} 份  頁數中位數 {median([p['pages'] for p in prs])}  字/頁中位數 {median([p['cpp'] for p in prs])}  "
              f"有圖片引用 {sum(1 for p in prs if p['pics'])}/{len(prs)} 份  " +
              "  ".join(f"{c} {sum(p['by'].get(c,0) for p in prs)/max(tot,1)*100:.0f}%" for c in cats))
    return docs



# ───────────────────── 4. anchor 覆蓋率 ─────────────────────

NUM = r"[0-9０-９一二三四五六七八九十百零〇]+"
P_ANCHORS = [
    ("開始處理宣告",   r"現在(?:開始)?進行[^。]{0,60}(?:處理|協商)|今天(?:要)?依序進行審查|今日依序進行審查|進行預算提案之處理|現在進行處理"),
    ("宣讀提案指示",   r"宣讀[^。]{0,30}(?:委員(?:的)?提案|預算數|審查項目)"),
    ("機關/基金起點",  r"(?:現在|先|接下來|繼續|再來|接著)[^。]{0,12}(?:協商|處理|審查|進行)[^。]{0,50}(?:單位預算|基金|部分|預算|公司)"),
    ("機關/基金審完",  r"(?:審查|處理)(?:完畢|完竣)"),
    ("案號宣告",       r"(?:進行|處理|接下來|再來|現在|先看|看一下|進入)[^。]{0,12}第\s*" + NUM + r"\s*案"),
    ("單案唱名",       r"^(?:再來)?第\s*" + NUM + r"\s*案[。，]?$"),
    ("科目(目)",       r"第\s*" + NUM + r"\s*目"),
    ("裁示用語",       r"照列|減列|刪減|凍結|改(?:列|為|成)?主決議|照案通過|修正通過|撤案|不予處理|不處理"),
    ("保留送院會",     r"保留[^。]{0,8}(?:送|提)?(?:院會|黨團)?協商|保留[^。]{0,4}送院會"),
    ("結尾協商結論",   r"協商結論|授權(?:主席及)?議事人員"),
]
Q_ANCHORS = [
    ("機關報告",       r"請[^。]{1,20}(?:報告)|進行(?:機關|業務|預算)?報告|現在(?:先)?(?:請|進行)[^。]{0,20}報告"),
    ("段落：質詢 marker", None),
    ("謝謝X委員",      r"(?:謝謝|感謝)[^。]{1,8}委員(?:質詢)?[。！]?$"),
    ("請X委員質詢",    r"請[^。]{1,8}委員(?:質詢|發言)"),
    ("詢答結束",       r"詢答(?:到此|完畢|結束)|詢答部分(?:到此|結束)"),
]


def chair_texts(labs):
    return [t for spk, t, bi, cat in labs if cat == "口頭" and spk == "主席" and t]


def anchors(term=11):
    rows = gazette_rows(term)
    p_rows = [r for r in rows if r["buckets"] == ["②處理"] and r["lcidcs"]]
    print(f"[F] ②處理 純型的公報發言紀錄全數（第 {term} 屆）：{len(p_rows)} 筆")
    hit = collections.Counter()
    ratio_rows = []
    failed, thin = [], []
    for r in list(p_rows):
        bs = [b for i in r["lcidcs"] for b in doc_blocks(i)]
        if any(i in FAILED for i in r["lcidcs"]):
            failed.append(r)
            continue
        labs = label_paras(bs)
        oral = sum(len(t) for spk, t, bi, cat in labs if cat == "口頭")
        if oral < 3000:
            thin.append((r, oral))
            continue
        ch = chair_texts(labs)
        txt = "\n".join(ch)
        for n, pat in P_ANCHORS:
            if re.search(pat, txt, flags=re.M):
                hit[n] += 1
        n_items = len([i for i in split_items(re.sub(r"；", " ", r["content"])) if "預算" in i])
        n_done = len(re.findall(P_ANCHORS[3][1], txt))
        n_case = len(re.findall(P_ANCHORS[4][1], txt, flags=re.M)) + len(re.findall(P_ANCHORS[5][1], txt, flags=re.M))
        ratio_rows.append((r["meet"], r["date"], n_items, n_done, n_case, len(ch)))
    p_rows = [r for r in p_rows if r not in failed and r not in [x[0] for x in thin]]
    print(f"  口頭發言 < 3,000 字（宣讀冊／流會，另計）：{len(thin)} 筆  " +
          ", ".join(f"{r['meet']}@{r['date']}({o}字)" for r, o in thin))
    print(f"  parsed 端點穩定失敗（HTTP 500，排除於分母）：{len(failed)} 筆  " +
          ", ".join(f"{r['meet']}@{r['date']}({'+'.join(r['lcidcs'])})" for r in failed))
    print(f"  有效分母：{len(p_rows)} 筆")
    for n, _ in P_ANCHORS:
        print(f"  {n:<14} 命中 {hit[n]:>3}/{len(p_rows)} 份  ({hit[n]/max(len(p_rows),1)*100:.0f}%)")
    eq = sum(1 for x in ratio_rows if x[3] >= x[2] > 0)
    print(f"  「審完」宣告次數 ≥ content 議程項數 的份數：{eq}/{len(ratio_rows)}")
    zero_case = [x for x in ratio_rows if x[4] == 0]
    print(f"  完全沒有案號宣告的份數：{len(zero_case)}/{len(ratio_rows)}  " + ", ".join(f"{x[0]}@{x[1]}" for x in zero_case[:8]))
    print(f"  每份案號宣告次數中位數 {median([x[4] for x in ratio_rows])}，最少 {min(x[4] for x in ratio_rows)}，最多 {max(x[4] for x in ratio_rows)}")

    case_p = sorted(x[4] for x in ratio_rows)
    print(f"  各份案號宣告次數（排序）：{case_p}")

    q_rows = [r for r in rows if r["buckets"] == ["①詢答"] and r["lcidcs"]]
    q_s = sample_docs_by(q_rows)
    print(f"\n[G] ①詢答 純型：母體 {len(q_rows)} 筆，抽 每委員會×會期 1 筆 = {len(q_s)} 筆")
    qh = collections.Counter()
    mk_cov = []
    for r in q_s:
        bs = [b for i in r["lcidcs"] for b in doc_blocks(i)]
        labs = label_paras(bs)
        txt = "\n".join(chair_texts(labs))
        marks = [p for b in bs for p in (b or []) if re.match(r"^段落[：:]質詢[：:]", p)]
        spk = len(set(r.get("speakers") or []))
        for n, pat in Q_ANCHORS:
            if pat is None:
                if marks:
                    qh[n] += 1
            elif re.search(pat, txt, flags=re.M):
                qh[n] += 1
        mk_cov.append((r["meet"], r["date"], len(marks), spk,
                       len(re.findall(P_ANCHORS[4][1], txt, flags=re.M)) + len(re.findall(P_ANCHORS[5][1], txt, flags=re.M)),
                       len([i for i in split_items(re.sub(r"；", " ", r["content"])) if item_target(i) in REVIEW_TARGETS]),
                       re.search(r"綜合詢答|合併詢答|一併詢答|併案詢答", txt) is not None))
    for n, _ in Q_ANCHORS:
        print(f"  {n:<16} 命中 {qh[n]:>3}/{len(q_s)} 份")
    print("  質詢 marker 數 vs 公報發言紀錄 speakers 人數／案號宣告次數／議程中的預算項數／主席是否說「綜合（合併）詢答」：")
    for x in mk_cov:
        print(f"    {x[0]:<26}{x[1]}  marker {x[2]:>3}  speakers {x[3]:>3}  案號宣告 {x[4]:>3}  預算項 {x[5]}  綜合詢答 {'是' if x[6] else '—'}")
    print(f"  合計 marker {sum(x[2] for x in mk_cov)} ／ speakers {sum(x[3] for x in mk_cov)}")
    multi = [x for x in mk_cov if x[5] >= 2]
    print(f"  預算項 ≥2 的 {len(multi)} 份中，主席明說綜合／合併詢答：{sum(1 for x in multi if x[6])} 份")
    print(f"  ①的案號宣告次數（排序）：{sorted(x[4] for x in mk_cov)}")
    return ratio_rows


def sample_docs_by(rows):
    pick = {}
    for r in sorted(rows, key=lambda r: r["lcidc"] or ""):
        comm = r["meet"].split("-")[3] if r["kind"] == "委員會" else "聯席"
        pick.setdefault((comm, r["session"]), r)
    return [pick[k] for k in sorted(pick)]


# ───────────────────────────── 主程式 ─────────────────────────────

if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("cmd", choices=["inventory", "read", "measure", "anchors", "deep", "all"])
    ap.add_argument("arg", nargs="?")
    ap.add_argument("--term", type=int, default=11)
    ap.add_argument("--show")
    ap.add_argument("--only", help="measure：只量某一主型（如 ②處理），每個委員會取 1 份")
    ap.add_argument("--all-chair", action="store_true")
    a = ap.parse_args()
    if a.cmd == "inventory":
        inventory(a.term, a.show)
        gazette_inventory(a.term, a.show)
    elif a.cmd == "all":
        print("=" * 30, "1. 盤點（第 11 屆完整）", "=" * 30)
        inventory(11)
        gazette_inventory(11)
        print("\n" + "=" * 30, "1b. 第 8–10 屆盤點摘要", "=" * 30)
        summary_terms([8, 9, 10, 11])
        print("\n" + "=" * 30, "2a. 深讀樣本", "=" * 30)
        deep()
        print("\n" + "=" * 30, "2. 口頭 vs 書面篇幅", "=" * 30)
        measure(11)
        for t in (10, 9, 8):
            measure(t, "②處理")
        print("\n" + "=" * 30, "3. anchor 覆蓋率", "=" * 30)
        anchors(11)
    elif a.cmd == "deep":
        deep()
    elif a.cmd == "anchors":
        anchors(a.term)
    elif a.cmd == "measure":
        measure(a.term, a.only)
    elif a.cmd == "read":
        read(a.arg, only_chair=not a.all_chair)
