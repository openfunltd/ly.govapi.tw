#!/usr/bin/env python3
"""
公聽會切分研究 — 盤點與 anchor 普查（只用標準函式庫）

用法：
  python3 scripts/hearing.py --inventory     # 盤點：meet API 母體 × 公報目錄 × IVOD
  python3 scripts/hearing.py --census        # anchor 覆蓋率（每屆均勻抽 10 場有公報的＝40 場）
  python3 scripts/hearing.py --read <lcidc>  # 以段落層列出一份公報文件（深讀用）
  python3 scripts/hearing.py --all           # 依序跑 --inventory 與 --census

快取：.cache/（與 scripts/stages.py 共用；ASCII 網址的快取鍵與 stages.py 相同）。
節流：每次實際打 API 後至少間隔 2 秒（其他 agent 同時在打，會 429）。
"""
import argparse, collections, difflib, json, os, random, re, sys, time, urllib.request
from urllib.parse import quote

API = "https://v2.ly.govapi.tw"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CACHE = os.path.join(os.path.dirname(os.path.abspath(__file__)), ".cache")
TOKEN = os.environ.get("OPENFUN_TOKEN", "")
_last = [0.0]


def fetch(url, tries=6):
    os.makedirs(CACHE, exist_ok=True)
    q = quote(url.replace(API, ""), safe="/?&=,")
    key = os.path.join(CACHE, re.sub(r'[^A-Za-z0-9_.-]', '_', q) + ".json")
    if os.path.exists(key):
        return json.load(open(key))
    for a in range(tries):
        wait = 2.0 - (time.time() - _last[0])
        if wait > 0:
            time.sleep(wait)
        try:
            headers = {"User-Agent": "research-ly-meet/1.0 (hearing)"}
            if TOKEN:
                headers["Authorization"] = f"Bearer {TOKEN}"
            req = urllib.request.Request(API + q, headers=headers)
            body = urllib.request.urlopen(req, timeout=180).read()
            _last[0] = time.time()
            d = json.loads(body)
            open(key, "wb").write(body)
            return d
        except Exception as e:
            _last[0] = time.time()
            if a == tries - 1:
                print(f"  [fetch 失敗] {url} → {e}", file=sys.stderr)
                return None
            time.sleep(4 * (a + 1))


# ───────────────────────── 資料取得（列表 API，只取欄位不取全文） ─────────────────────────
TERMS = (8, 9, 10, 11)


def hearing_meets():
    """meet API 的公聽會母體（一次取完，687 筆＠2026-09-19）。"""
    return fetch(f"{API}/meets?會議種類=公聽會&limit=1000")["meets"]


def gazette_agendas():
    """公報目錄第 8–11 屆全部條目（只有目錄欄位，不含全文）。"""
    out = []
    for t in TERMS:
        p = 1
        while True:
            d = fetch(f"{API}/gazette_agendas?屆={t}&limit=1000&page={p}")
            out += d["gazetteagendas"]
            if p >= d["total_page"]:
                break
            p += 1
    return out


def full_ivods(need_dates=None):
    """影片種類=Full 的 IVOD（第 8–11 屆）精簡清單。

    ★ 公聽會的 IVOD 多半**沒有 會議資料.會議代碼**（實測 公聽會-2019120605 的 Full 影片
      會議資料 為空），所以 /meet/<id>/ivods 會回 0 筆，不能據此判定「沒有影片」。
      改用 日期 + 會議名稱 比對，見 link_ivod()。
    ★ 也不能用 屆=N 篩選：屆 是 會議資料 裡的欄位，沒有 會議資料 的影片會被整批濾掉
      （實測 Full 全部 12,216 筆，加上 屆=8..11 只剩 9,727 筆）。
    ★ API 分頁上限是前 10,000 筆（page 11 回 413），依日期新→舊排序。
      前 10,000 筆涵蓋不到的舊日期，改以 日期=YYYY-MM-DD 逐日補查（只查公聽會用得到的日期）。
    """
    def slim(v):
        return {"id": v["IVOD_ID"], "date": v.get("日期"),
                "code": (v.get("會議資料") or {}).get("會議代碼"),
                "name": v.get("會議名稱") or ""}
    out = []
    for p in range(1, 11):
        d = fetch(f"{API}/ivods?影片種類=Full&limit=1000&page={p}")
        out += [slim(v) for v in d["ivods"]]
        if p >= d["total_page"]:
            break
    oldest = min(v["date"] for v in out)
    for day in sorted({x for x in (need_dates or []) if x < oldest}):
        d = fetch(f"{API}/ivods?影片種類=Full&日期={day}&limit=200")
        out += [slim(v) for v in (d or {}).get("ivods") or []]
    return out


def link_ivod(ms, ivods):
    """{會議代碼: {"ivod": [IVOD_ID...], "how": "會議代碼|日期+標題|日期+委員會|無"}}"""
    bycode = collections.defaultdict(list)
    bydate = collections.defaultdict(list)
    for v in ivods:
        if v["code"]:
            bycode[v["code"]].append(v)
        if "公聽會" in v["name"] and (not v["code"] or v["code"].startswith("公聽會")):
            bydate[v["date"]].append(v)
    out = {}
    for m in ms:
        mc = m["會議代碼"]
        if bycode.get(mc):
            out[mc] = {"ivod": [v["id"] for v in bycode[mc]], "how": "會議代碼"}
            continue
        r = m["會議資料"][0]
        cands = [v for d in m["日期"] for v in bydate.get(d, [])]
        text = (m.get("會議標題") or "") + (r.get("會議事由") or "")
        tq = [_norm(x) for x in re.findall(r'「([^」]{4,})」', text)]
        hit = [v for v in cands if any(t and t in _norm(v["name"]) for t in tq)]
        how = "日期+標題"
        if not hit and not tq and m.get("委員會代號:str"):
            com = [c.replace("委員會", "") for c in m["委員會代號:str"]]
            hit = [v for v in cands if all(c in v["name"] for c in com)]
            how = "日期+委員會"
            if len(hit) != 1:
                hit = []
        out[mc] = {"ivod": [v["id"] for v in hit], "how": how if hit else "無"}
    return out


def legislators():
    """{屆: set(姓名)}，用來判斷「會議單位」是不是立委個人。"""
    out = {}
    for t in TERMS:
        d = fetch(f"{API}/legislators?屆={t}&limit=1000")
        out[t] = {norm_name(x["委員姓名"]) for x in d["legislators"]}
    return out


def norm_name(s):
    # 原住民立委名字含羅馬字（「廖國棟Sufin‧Siluko」），只取前段漢字比對
    m = re.match(r'[一-鿿]+', s or "")
    return m.group(0) if m else (s or "")


# ───────────────────────── 分類與比對 ─────────────────────────
def host_type(m, legis):
    r = m["會議資料"][0]
    unit = r.get("會議單位") or ""
    if "修憲委員會" in unit:
        return "其他委員會（特種）"      # 修憲委員會有 委員會代號，但不是常設委員會
    if m.get("委員會代號"):
        return "常設委員會"
    if unit == "全院委員會":
        return "全院委員會"
    if unit.endswith("委員會"):
        return "其他委員會（特種）"
    if "黨團" in unit:
        return "黨團"
    if norm_name(unit) in legis.get(m["屆"], set()) or norm_name(unit) in set().union(*legis.values()):
        return "立委個人"
    if re.search(r'(小姐|先生|主任|秘書|助理)$', unit):
        return "聯絡人（無法判定主辦）"
    return "其他"


def is_cancelled(m):
    s = json.dumps(m.get("議事網資料") or [], ensure_ascii=False) + (m.get("會議標題") or "")
    return bool(re.search(r'取消', s)) and bool(re.search(r'公聽會取消|取消\)|取消）|（取消|\(取消', s))


def direct_docs(m, ga_index):
    """議事網資料 的「公報紀錄」連結 → 文件 id。

    ★ 同一場的連結有兩種檔名，編號差一號：
       「公報紀錄DOC」 …/LCIDC01_1157202_00005.doc  ← 這是文件 id（= parsed 用的 id）
       「公報紀錄」    …/LCIDC01_1157202_00004.pdf  ← 這是公報議程編號，不能直接拿去打 parsed
      PDF 連結一律經公報目錄（公報議程編號 → 處理後公報網址）換成文件 id。
    """
    ids = []
    for w in m.get("議事網資料") or []:
        for l in w.get("連結") or []:
            if "公報" not in (l.get("標題") or ""):
                continue
            url = l.get("連結") or ""
            x = re.search(r'LCIDC01_(\d+_\d+)\.(docx?|pdf)', url)
            if not x:
                continue
            if x.group(2).startswith("doc"):
                cand = [x.group(1)]
            else:
                g = ga_index.get(x.group(1))
                cand = doc_ids_of(g) if g else []
            for i in cand:
                if i not in ids:
                    ids.append(i)
    return ids


def doc_ids_of(g):
    """公報目錄條目 → 文件 id。★ 公報議程編號 ≠ 文件 id（差一號），一律從 處理後公報網址 取。"""
    ids = []
    for u in g.get("處理後公報網址") or []:
        x = re.search(r'gazette_agenda_doc/([^/]+)/parsed', u.get("url") or "")
        if x and x.group(1) not in ids:
            ids.append(x.group(1))
    return ids


def _norm(s):
    return re.sub(r'[\s「」『』、，,．.‧・（）()—─－\-：:？?！!臺台]', '', s or "")


def is_hearing_gazette(g):
    """公報目錄中「這一則是公聽會紀錄」：類別代碼 3 且案由有「公聽會」，
    但排除只是在議程裡「審查某公聽會之會議紀錄」的一般委員會會議（實測 6 則）。"""
    t = g.get("案由") or ""
    if g.get("類別代碼") != 3 or "公聽會" not in t:
        return False
    return bool(re.sub(r'公聽會之?會議紀錄', '', t).count("公聽會"))


def link_gazette(ms, ga):
    """回傳 ({會議代碼: {"docs": [...], "how": ...}}, 公聽會公報條目)。

    比對順序（以公報目錄為準，議事網連結只當備援）：
      1. 同日期 + 會議標題中「」內的題目出現在公報案由           → how = 日期+標題
      2. 同日期 + 主辦委員會名稱出現在公報案由，且當天只有一則     → how = 日期+委員會
      （議事網資料 的公報紀錄連結不採用，只放在 ppg_docs_unverified）
    ★ 議事網連結不能直接信：實測會掛到同日其他會議的公報
      （例：公聽會-2023061417 的 DOC 連結指向程序委員會的文件），見 README 坑 P3。
    """
    gh = [g for g in ga if is_hearing_gazette(g)]
    ga_index = {g["公報議程編號"]: g for g in ga}
    gh_docs = {i for g in gh for i in doc_ids_of(g)}
    bydate = collections.defaultdict(list)
    for g in gh:
        for d in g.get("會議日期") or []:
            bydate[d].append(g)
    out = {}
    for m in ms:
        r = m["會議資料"][0]
        cands = [g for d in m["日期"] for g in bydate.get(d, [])]
        text = (m.get("會議標題") or "") + (r.get("會議事由") or "") + (r.get("會議名稱") or "")
        tq = [_norm(x) for x in re.findall(r'「([^」]{4,})」', text)]
        hit = [g for g in cands if any(t and t in _norm(g["案由"]) for t in tq)]
        how = "日期+標題"
        if not hit and tq:
            # 標題有「」但一字之差（「一」vs「─」、「國人」vs「國內」）：同日＋題目相似度 ≥ 0.85
            hit = [g for g in cands if any(
                difflib.SequenceMatcher(None, t, _norm(q)).ratio() >= 0.85
                for t in tq for q in re.findall(r'「([^」]{4,})」', g["案由"]))]
            how = "日期+標題(近似)"
        if not hit and not tq and m.get("委員會代號:str"):
            # ★ 只有「meet 沒有「」題目」時才退回用委員會名稱比對。
            #   有題目卻對不上時用委員會比，實測會錯配（公聽會-2019120615「中介教育」
            #   被配到同日同委員會的「性別平等教育法施行細則草案」公聽會）。
            com = [c.replace("委員會", "") for c in m["委員會代號:str"]]
            hit = [g for g in cands if all(c in g["案由"] for c in com)]
            how = "日期+委員會"
            if len(hit) != 1:
                hit = []
        if hit:
            out[m["會議代碼"]] = {"docs": [i for g in hit for i in doc_ids_of(g)], "how": how,
                                 "gazette": [g["公報議程編號"] for g in hit]}
            continue
        # 議事網連結不採用（實測唯一會用到它的 公聽會-2013110711「職災」公聽會，
        # 連結指向同日另一場「健保五大皆空」公聽會的公報）。只記下來供人工查。
        out[m["會議代碼"]] = {"docs": [], "how": "無", "gazette": [],
                             "ppg_docs_unverified": direct_docs(m, ga_index)}
    return out, gh


# ───────────────────────── 盤點 ─────────────────────────
def inventory(verbose=False):
    ms = hearing_meets()
    ga = gazette_agendas()
    leg = legislators()
    link, gh = link_gazette(ms, ga)
    iv = full_ivods([d for m in ms for d in m["日期"]])
    li = link_ivod(ms, iv)

    HT = ["常設委員會", "全院委員會", "其他委員會（特種）", "立委個人", "黨團",
          "聯絡人（無法判定主辦）", "其他"]
    rows = collections.defaultdict(lambda: [0, 0, 0])
    for m in ms:
        k = (host_type(m, leg), m["屆"])
        rows[k][0] += 1
        rows[k][1] += bool(link[m["會議代碼"]]["docs"])
        rows[k][2] += bool(li[m["會議代碼"]]["ivod"])

    print(f"== 1. meet API 公聽會母體：{len(ms)} 場（會議種類=公聽會）")
    print(f"{'主辦類型':<16}" + "".join(f"{'第'+str(t)+'屆':>14}" for t in TERMS) + f"{'合計':>16}")
    print(f"{'':<16}" + "".join(f"{'場/公報/IVOD':>14}" for t in TERMS))
    tot = [0, 0, 0]
    for h in HT:
        cells, s = [], [0, 0, 0]
        for t in TERMS:
            r = rows.get((h, t), [0, 0, 0])
            cells.append(f"{r[0]}/{r[1]}/{r[2]}")
            s = [a + b for a, b in zip(s, r)]
        tot = [a + b for a, b in zip(tot, s)]
        print(f"{h:<16}" + "".join(f"{c:>14}" for c in cells) + f"{s[0]:>6}/{s[1]}/{s[2]}")
    tcells = []
    for t in TERMS:
        r = [sum(rows.get((h, t), [0, 0, 0])[i] for h in HT) for i in range(3)]
        tcells.append(f"{r[0]}/{r[1]}/{r[2]}")
    print(f"{'合計':<16}" + "".join(f"{c:>14}" for c in tcells) + f"{tot[0]:>6}/{tot[1]}/{tot[2]}")
    print(f"  有公報 {tot[1]}/{tot[0]} = {tot[1]/tot[0]:.0%}；有 Full IVOD {tot[2]}/{tot[0]} = {tot[2]/tot[0]:.0%}")
    com = [m for m in ms if host_type(m, leg) in ("常設委員會", "全院委員會", "其他委員會（特種）")]
    nonc = [m for m in ms if m not in com]
    print(f"  非委員會登記（立委個人／黨團／聯絡人／其他）{len(nonc)} 場，最早日期 "
          f"{min(m['日期'][0] for m in nonc)}，有公報 {sum(bool(link[m['會議代碼']]['docs']) for m in nonc)} 場")
    c1 = sum(bool(link[m["會議代碼"]]["docs"]) for m in com)
    c2 = sum(bool(li[m["會議代碼"]]["ivod"]) for m in com)
    print(f"  只看委員會主辦（含全院、特種）：有公報 {c1}/{len(com)} = {c1/len(com):.0%}；"
          f"有 IVOD {c2}/{len(com)} = {c2/len(com):.0%}")

    print("\n== 2. 公報比對方式")
    for k, v in collections.Counter(x["how"] for x in link.values()).most_common():
        print(f"  {k:<10}{v:>5}")
    print("   IVOD 比對方式")
    for k, v in collections.Counter(x["how"] for x in li.values()).most_common():
        print(f"  {k:<10}{v:>5}")

    print("\n== 3. 委員會主辦卻找不到公報的場次")
    reason = collections.Counter()
    for m in com:
        if link[m["會議代碼"]]["docs"]:
            continue
        unit = m["會議資料"][0].get("會議單位") or ""
        why = ("會議取消" if is_cancelled(m) else
               "修憲委員會（公報目錄無此類條目）" if "修憲" in unit else "查無公報（原因未查）")
        reason[why] += 1
        if verbose:
            print(f"  {m['會議代碼']:<28}{m['日期'][0]}  {unit}  {why}  {(m['會議標題'] or '').strip()[:40]}")
    for k, v in reason.most_common():
        print(f"  {k:<30}{v:>4}")

    print("\n== 4. 非委員會主辦卻有公報的場次（登記人不是委員會，但其實是委員會公聽會）")
    for m in ms:
        if host_type(m, leg) not in ("常設委員會", "全院委員會", "其他委員會（特種）") and link[m["會議代碼"]]["docs"]:
            print(f"  {m['會議代碼']}  會議單位={m['會議資料'][0].get('會議單位')}  → {link[m['會議代碼']]['docs']}")

    used = collections.Counter(d for x in link.values() for d in x["docs"])
    print(f"\n== 5. 反方向：公報目錄的公聽會條目 {len(gh)} 則（類別代碼 3、案由含「公聽會」、"
          f"排除「審查公聽會會議紀錄」）")
    print("   屆別：" + "、".join(f"第{t}屆 {sum(g['屆'] == t for g in gh)}" for t in TERMS))
    un = [g for g in gh if not any(i in used for i in doc_ids_of(g))]
    print(f"   對得到 meet 的 {len(gh) - len(un)} 則；對不到的 {len(un)} 則：")
    first8 = min(m["日期"][0] for m in ms if m["屆"] == 8)
    cat = collections.Counter()
    for g in un:
        d = g["會議日期"][0]
        if "全院委員會" in g["案由"]:
            k = f"第{g['屆']}屆 全院委員會公聽會（meet API 無對應公聽會）"
        elif g["屆"] == 8 and d < "2013-09-01":
            k = "第8屆 2013-09 以前（meet API 該期間公聽會很少）"
        else:
            k = f"第{g['屆']}屆 其他（比對失敗或 meet 缺漏，未逐筆查）"
        cat[k] += 1
        if verbose:
            print(f"    {g['公報議程編號']:<16}{d}  {g['案由'][:60]}")
    for k, v in sorted(cat.items()):
        print(f"    {k:<46}{v:>4}")
    n_early = sum(1 for m in ms if m["屆"] == 8 and m["日期"][0] < "2013-09-01")
    print(f"   （meet API 第 8 屆最早一場公聽會日期：{first8}；2013-09-01 以前只有 {n_early} 場）")
    amb = {d: n for d, n in used.items() if n > 1}
    print(f"   同一份公報被多場 meet 對到（同日同題，例：服貿公聽會第五、六場）：{len(amb)} 份")

    multi = [g for g in gh if len(doc_ids_of(g)) > 1]
    mm = [m for m in ms if len(link[m["會議代碼"]]["docs"]) > 1]
    print(f"\n== 6. 跨冊／多文件：公報條目含 >1 份文件 {len(multi)}/{len(gh)}；"
          f"meet 對到 >1 份文件 {len(mm)}/{sum(bool(x['docs']) for x in link.values())}")
    return ms, ga, link, li


# ───────────────────────── 段落層解析 ─────────────────────────
# 段落開頭的發言人標記。公聽會的發言人大多**不是立委**，標記型態比人事審查多：
#   「主席：」「林委員奕華：」（姓＋委員＋名）
#   「許教授○○：」「台灣大學法律學系○○○教授：」「教育部國民及學前教育署署長○○○：」
#   「駱警政監立凡：」（姓＋職稱＋名）
# 因此不用職稱白名單，而是「段首 2–40 字、不含句讀、接全形冒號」一律視為標記候選，
# 再用 NOT_SPEAKER 排除已知的非發言人標記（討論提綱、本部意見…）。
SPK = re.compile(r'^([^：:，。、？！「」（）()；;\s]{2,60}?)[：:]')
NOT_SPEAKER = re.compile(r'^(?:討論提綱|討論題綱|討論議題|本部意見|說明|時間|地點|列席|出席|'
                         r'發言學者|會議紀錄|備註|附件|附錄|案由|決議|結論|'
                         r'問題|議題|提綱|題綱|建議|意見|結語|摘要|前言|註\d|'
                         r'(?:[一二三四五六七八九十]+|[壹貳參肆伍陸柒捌玖拾]+)(?:[、.．]|$))')


def paras(lcidc):
    """攤平成 [(block_idx, para_idx_in_doc, text)]。"""
    d = fetch(f"{API}/gazette_agenda_doc/{lcidc}/parsed") or {}
    out, k = [], 0
    for bi, blk in enumerate(d.get("blocks") or []):
        for p in blk or []:
            out.append((bi, k, p))
            k += 1
    return out, d


CHAIR = re.compile(r'^主席(?:（[^）]{0,15}）|\([^)]{0,15}\))?[：:]')   # 「主席（王委員正旭代）：」也算主席
# 長句中間剛好有冒號會被誤認成標記（實測「我們在整個運用方面分四個部分：…」）。
# 發言人標記是人名＋職稱或機關名，不會出現這些虛詞。
NOT_NAME_CHARS = re.compile(r'[我你們的了是就要把這請]|各位|以下|如下|宣告|結論|程序|附錄|'
                            r'\d|第.{1,5}條|草案|建議|人員$|說$')


def speaker_of(p):
    """段首發言人標記；回傳 "主席"、其他名字、或 None（不是新發言人）。"""
    if CHAIR.match(p):
        return "主席"
    m = SPK.match(p)
    if not m:
        return None
    name = m.group(1)
    if is_written(name) and len(name) <= 60:
        return name                       # 「○○公會○○主任委員書面意見」可以很長
    if len(name) > 20 or NOT_SPEAKER.match(name) or NOT_NAME_CHARS.search(name):
        return None
    return name


def is_written(name):
    """「環境部書面資料：」「委員盧縣一書面資料：」這類附件標記（不是口頭發言）。"""
    return bool(name) and name.endswith(("書面資料", "書面意見", "書面報告", "書面發言"))


def read(lcidc, width=110):
    ps, d = paras(lcidc)
    print(f"{lcidc}  {d.get('title')}  {d.get('時間')}  blocks={len(d.get('blocks') or [])} paras={len(ps)}")
    print(f"persons(API)={d.get('persons')}")
    for bi, k, p in ps:
        s = speaker_of(p)
        tag = f"【{s}】" if s else ""
        print(f"[{k:>4}|b{bi:>3}] {tag}{p[:width]}{'…' if len(p) > width else ''}  ({len(p)}字)")


# ───────────────────────── anchor 普查 ─────────────────────────
# 主席宣告（段落層、只看主席說的話）
CHAIR_ANCHORS = [
    ("A1 開會",              r"現在開會|開始開會|公聽會(?:現在|正式)?開始|開始(?:進行)?公聽會|宣布.{0,4}開會"),
    ("A2 說明主旨",          r"(?:今天|本日|今日)[^。]{0,40}(?:舉行|舉辦|召開|辦理)[^。]{0,80}公聽會"),
    ("A3 提綱（主席提到）",   r"提綱|題綱"),
    ("A4 程序宣告（發言規則）", r"發言時間|每位[^。]{0,12}\d+\s*分鐘|簽到[^。]{0,6}順序|登記[^。]{0,6}順序|依[^。]{0,6}順序"),
    ("A5 請機關先報告",       r"(?:先|首先)[^。]{0,6}請[^。]{0,40}(?:部|署|會|院|局|處|機關)[^。]{0,30}(?:報告|說明|發言)"),
    ("A6 開始專家發言",       r"(?:現在|開始|首先|接下來)[^。]{0,10}請[^。]{0,10}(?:學者)?專家[^。]{0,10}(?:發言|表達|表示)"),
    ("A7 點名下一位",         r"請[^。]{2,60}?(?:發言|說明|報告|回應)|(?:接下來|下一位|繼續)[^。]{0,6}(?:邀)?請"),
    ("A8 發言完畢",           r"(?:均已|都已|已經|皆已)[^。]{0,6}發言完畢|發言完畢"),
    ("A9 請機關回應",         r"(?:機關|部會|政府|單位)[^。]{0,10}(?:回應|答復|綜合)|請[^。]{0,30}(?:回應|答復)"),
    ("A10 第二輪",            r"第二(?:輪|次)(?:的)?發言"),
    ("A11 委員登記／詢問",     r"委員[^。]{0,10}(?:登記|詢問|發言)"),
    ("A12 書面資料列入紀錄",   r"書面(?:資料|意見|報告)[^。]{0,30}(?:列入|刊登|刊載)"),
    ("A13 結論",              r"結論"),
    ("A14 公聽會報告",         r"公聽會(?:的)?報告|職權行使法"),
    ("A15 休息／繼續開會",     r"現在休息|休息\s*\d+\s*分鐘|繼續開會"),
    ("A16 散會",              r"散會|會議結束|公聽會[^。]{0,6}(?:到此)?結束"),
]
# 非主席的結構性標記（逐字稿中的排版／附件）
HEADER_LIST = re.compile(r'^(?:發言學者專家|學者專家(?:[\s　]|$)|出席學者專家|學者專家及機關團體代表)')
OUTLINE = re.compile(r'^(?:討論提綱|討論題綱|討論議題|提綱)[：:]?\s*$|^(?:討論提綱|討論題綱|討論議題)[：:]')
APPENDIX = re.compile(r'^附\s*錄(?:[：:]|\s*$)|^附件[：:]?\s*$')
IMG = re.compile(r'INCLUDEPICTURE|\.jpg|\.png', re.I)


# 主席「點名下一位」：用來判斷一個發言輪次是不是被主席正式請上台的
CALL = re.compile(r'請[^。]{0,60}?(?:發言|說明|報告|回應|答復|補充)'
                  r'|(?:接下來|下一位|繼續|依序|再來|首先)[^。]{0,40}?(?:發言|請|邀請)'
                  r'|發言的?委員是|請[^。]{2,40}?[。！]?$')


def meet_paras(docs):
    """一場公聽會的所有文件攤平成段落（依文件 id 排序；文件間以 None 分隔）。"""
    out = []
    for lc in sorted(docs):
        ps, _ = paras(lc)
        out += [(lc, k, p) for _, k, p in ps]
    return out


def analyse(docs):
    ps = meet_paras(docs)
    cur, chair, turns, written, written_idx = None, [], [], [], []
    wtext, wimg = 0, 0
    header_list, outline, appendix = False, False, False
    first_chair = None
    prev_chair_tail = ""          # 發言人換人前，主席最後一段（用來判斷是否有點名）
    for i, (lc, k, p) in enumerate(ps):
        s = speaker_of(p)
        if first_chair is None and s is None and HEADER_LIST.match(p):
            header_list = True
        if OUTLINE.match(p):
            outline = True
        if APPENDIX.match(p.strip()):
            appendix = True
        if s:
            if s == "主席":
                cur = "主席"
                if first_chair is None:
                    first_chair = i
                p = CHAIR.sub("", p, count=1)
            else:
                if is_written(s):
                    written.append(s)
                    written_idx.append(i)
                elif s != cur:
                    seen = any(t["name"] == s for t in turns)
                    turns.append({"name": s, "seen_before": seen,
                                  "called": bool(CALL.search(prev_chair_tail)) if cur == "主席" else None})
                cur = s
                continue
        if cur and is_written(cur):
            if IMG.search(p):
                wimg += 1
            else:
                wtext += len(p)
        if cur == "主席":
            t = p.strip()
            if t:
                chair.append(t)
                prev_chair_tail = t
    txt = "\n".join(chair)
    img = sum(1 for _, _, p in ps if IMG.search(p))
    # 書面資料是不是在「散會／結束」之後才出現
    #   以主席第一次說出「散會／公聽會…結束」的段落為準（不是最後一行「散會（12時8分）」）
    end_idx = min((i for i, (_, _, p) in enumerate(ps)
                   if CHAIR.match(p) and re.search(r'現在散會|散會[，。！]|公聽會[^。]{0,8}結束|會議結束', p)),
                  default=None)
    return {
        "written_after_end": sum(1 for i in written_idx if end_idx is not None and i > end_idx
                                 or end_idx is None),
        "written_before_end": sum(1 for i in written_idx if end_idx is not None and i < end_idx),
        "written_text_chars": wtext, "written_img_paras": wimg,
        "paras": len(ps), "docs": len(docs), "chair_paras": len(chair),
        "hits": {n: len(re.findall(r, txt)) for n, r in CHAIR_ANCHORS},
        "header_list": header_list, "outline": outline, "appendix": appendix,
        "turns": turns, "written": written, "img_paras": img,
        "legis_turns": sum(1 for t in turns if re.search(r'^[一-鿿]{1,2}委員[一-鿿]{1,3}$', t["name"])),
        "odd_committee": [t["name"] for t in turns if re.search(r'[一-鿿]{2,4}委員$', t["name"])],
    }


def sample(ms, link, leg, per_term=10):
    """每屆均勻抽樣：只取委員會主辦（常設／全院／特種）且對得到公報者，依日期排序後等距取 per_term 場。"""
    out = []
    for t in TERMS:
        pool = sorted([m for m in ms if m["屆"] == t and link[m["會議代碼"]]["docs"]
                       and host_type(m, leg) in ("常設委員會", "全院委員會", "其他委員會（特種）")],
                      key=lambda m: (m["日期"][0], m["會議代碼"]))
        n = len(pool)
        idx = sorted({int((i + 0.5) * n / per_term) for i in range(per_term)})
        out += [(t, n, pool[i]) for i in idx]
    return out


def census(per_term=10, verbose=False):
    ms = hearing_meets()
    ga = gazette_agendas()
    leg = legislators()
    link, _ = link_gazette(ms, ga)
    smp = sample(ms, link, leg, per_term)
    pools = {t: n for t, n, _ in smp}
    print(f"== anchor 普查樣本：每屆等距抽 {per_term} 場（委員會主辦且有公報）")
    print("   母體：" + "、".join(f"第{t}屆 {pools[t]} 場" for t in TERMS) + f"；樣本 {len(smp)} 場")
    res = []
    for t, n, m in smp:
        r = analyse(link[m["會議代碼"]]["docs"])
        res.append((t, m, r))
        if verbose:
            called = sum(1 for x in r["turns"] if x["called"])
            print(f"  {m['會議代碼']:<28}{m['日期'][0]} 冊{r['docs']} 段{r['paras']:>4} 主席段{r['chair_paras']:>3} "
                  f"發言輪{len(r['turns']):>3}(點名{called}) 立委輪{r['legis_turns']:>2} 書面{len(r['written']):>2} "
                  f"圖{r['img_paras']:>3} 名單{'Y' if r['header_list'] else '-'} 提綱{'Y' if r['outline'] else '-'} "
                  f"附錄{'Y' if r['appendix'] else '-'}  {(m['會議標題'] or '').strip()[:24]}")
    N = len(res)
    print(f"\n== 主席宣告 anchor：命中場次（分母 {N} 場；括號內為各屆 8/9/10/11）")
    for name, _ in CHAIR_ANCHORS:
        c = [sum(1 for t, _, r in res if t == tt and r["hits"][name] > 0) for tt in TERMS]
        print(f"  {name:<22}{sum(c):>3}/{N} ({sum(c) / N:.0%})   {'/'.join(map(str, c))}")
    print(f"\n== 結構性標記（非主席）")
    for key, label in [("header_list", "開頭發言名單（發言學者專家…）"), ("outline", "討論提綱段落"),
                       ("appendix", "附錄")]:
        c = [sum(1 for t, _, r in res if t == tt and r[key]) for tt in TERMS]
        print(f"  {label:<26}{sum(c):>3}/{N}   {'/'.join(map(str, c))}")
    c = [sum(1 for t, _, r in res if t == tt and r["written"]) for tt in TERMS]
    print(f"  {'有「○○書面資料／意見：」標記':<24}{sum(c):>3}/{N}   {'/'.join(map(str, c))}")
    c = [sum(1 for t, _, r in res if t == tt and r["img_paras"]) for tt in TERMS]
    print(f"  {'有圖片引用段（INCLUDEPICTURE）':<22}{sum(c):>3}/{N}   {'/'.join(map(str, c))}")
    c = [sum(1 for t, _, r in res if t == tt and r["docs"] > 1) for tt in TERMS]
    print(f"  {'跨多份文件':<26}{sum(c):>3}/{N}   {'/'.join(map(str, c))}")
    wr = [r for _, _, r in res if r["written"]]
    print(f"   書面標記共 {sum(len(r['written']) for r in wr)} 個（{len(wr)} 場）：在主席宣布散會／結束之前 "
          f"{sum(r['written_before_end'] for r in wr)} 個、之後 {sum(r['written_after_end'] for r in wr)} 個；"
          f"書面內容為文字的共 {sum(r['written_text_chars'] for r in wr):,} 字，圖片引用段 {sum(r['written_img_paras'] for r in wr)} 段")
    print(f"   有書面標記但標記後沒有任何文字內容（只剩標題或只有圖片）的場次：" 
          f"{sum(1 for r in wr if r['written_text_chars'] == 0)}/{len(wr)}")
    tt_all = [x for _, _, r in res for x in r["turns"]]
    per = sorted(len(r["turns"]) for _, _, r in res)
    big = [(m["會議代碼"], len(r["turns"]), sum(1 for x in r["turns"] if x["called"])) for _, m, r in res if len(r["turns"]) > 50]
    print(f"\n== 每場發言輪次：中位數 {per[len(per) // 2 - 1] / 2 + per[len(per) // 2] / 2:g}、最少 {per[0]}、最多 {per[-1]}；"
          f"超過 50 輪 {len(big)} 場：" + "、".join(f"{c}({n}輪/點名{k})" for c, n, k in big))
    called = [x for x in tt_all if x["called"] is not None]
    print(f"\n== section 切點：非主席發言輪次 {len(tt_all)} 輪；其中緊接在主席段之後的 {len(called)} 輪，"
          f"前一段主席話有點名的 {sum(1 for x in called if x['called'])} 輪 "
          f"({sum(1 for x in called if x['called']) / max(1, len(called)):.0%})")
    unc = [x for x in called if not x["called"]]
    print(f"   沒點名的 {len(unc)} 輪中，該發言人先前已發言過（多為詢答往返）的 {sum(1 for x in unc if x['seen_before'])} 輪；"
          f"第一次出場就沒被點名的 {sum(1 for x in unc if not x['seen_before'])} 輪")
    first = [x for x in called if not x["seen_before"]]
    print(f"   只看「第一次出場」的輪次：{len(first)} 輪，有點名 {sum(1 for x in first if x['called'])} 輪 "
          f"({sum(1 for x in first if x['called']) / max(1, len(first)):.0%})")
    print(f"   立委發言輪次（姓＋委員＋名 標記）：{sum(r['legis_turns'] for _, _, r in res)} 輪，"
          f"出現在 {sum(1 for _, _, r in res if r['legis_turns'])}/{N} 場")
    odd = collections.Counter(n for _, _, r in res for n in r["odd_committee"])
    print(f"   「○○委員：」（名＋委員，非立委標記格式）：{sum(odd.values())} 輪／{len(odd)} 人："
          f"{'、'.join(list(odd)[:12])}")
    # ── 坑的實測：block 粒度、stages.chair_paras 能不能直接沿用 ──
    import importlib.util
    spec = importlib.util.spec_from_file_location("stages", os.path.join(os.path.dirname(os.path.abspath(__file__)), "stages.py"))
    st = importlib.util.module_from_spec(spec); spec.loader.exec_module(st)
    mix, tot, acting = collections.Counter(), collections.Counter(), collections.Counter()
    st_chair = my_chair = 0
    for t, _, m in smp:
        for lc in link[m["會議代碼"]]["docs"]:
            bl = (fetch(f"{API}/gazette_agenda_doc/{lc}/parsed") or {}).get("blocks") or []
            cur = None
            for blk in bl:
                sp = {speaker_of(p) for p in blk or []} - {None}
                tot[t] += 1
                mix[t] += len(sp) > 1
                for p in blk or []:
                    acting[t] += bool(re.match(r'^主席[（(]', p))
                    s = speaker_of(p)
                    if s:
                        cur = s
                    my_chair += cur == "主席" and bool(p.strip())
            st_chair += len(st.chair_paras(bl))
    print(f"\n== 坑的實測")
    print("   parsed 的 block 含 >1 位發言人：" + "、".join(
        f"第{t}屆 {mix[t]}/{tot[t]} ({mix[t] / max(1, tot[t]):.0%})" for t in TERMS))
    print("   代理主席標記「主席（○委員○○代）：」段數：" + "、".join(f"第{t}屆 {acting[t]}" for t in TERMS))
    print(f"   主席段數：stages.chair_paras() {st_chair} 段 vs 本檔 {my_chair} 段"
          f"（stages 版多算 {st_chair - my_chair} 段 = {(st_chair - my_chair) / max(1, st_chair):.0%}，"
          f"因為它認不出「彭署長富源」「廖庭農主任」這類非立委標記，換人後仍歸給主席）")
    return res


if __name__ == "__main__":
    ap = argparse.ArgumentParser(description="公聽會盤點與 anchor 普查")
    ap.add_argument("--inventory", action="store_true", help="盤點母體、公報、IVOD")
    ap.add_argument("--census", action="store_true", help="40 場樣本的 anchor 覆蓋率")
    ap.add_argument("--read", metavar="LCIDC", help="以段落層列出一份公報文件")
    ap.add_argument("--all", action="store_true", help="--inventory + --census")
    ap.add_argument("-v", "--verbose", action="store_true", help="列出逐場／逐筆明細")
    a = ap.parse_args()
    if a.read:
        read(a.read)
    if a.inventory or a.all:
        inventory(a.verbose)
    if a.census or a.all:
        print()
        census(verbose=a.verbose)
    if not (a.read or a.inventory or a.census or a.all):
        ap.print_help()
