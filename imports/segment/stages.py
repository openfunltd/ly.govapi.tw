#!/usr/bin/env python3
"""
列出一場會議的公報階段結構：主席程序宣告、公報段落 marker、被提名人／質詢委員區段。

用法：
  python3 scripts/stages.py 全院委員會-11-4-1            # 階段時序（主席宣告 + 段落 marker）
  python3 scripts/stages.py 全院委員會-11-3-4 --docs      # 各公報子文件的被提名人／質詢委員對照
  python3 scripts/stages.py --census                     # 16 場人事審查會議的 anchor 覆蓋率普查

資料來源（皆為公開 API）：
  https://v2.ly.govapi.tw/meet/<meet_id>                        取 公報發言紀錄 → agenda_lcidc_ids
  https://v2.ly.govapi.tw/gazette_agenda_doc/<lcidc>/parsed     取已依發言人切好的 blocks
  https://v2.ly.govapi.tw/gazette_agenda_doc/<lcidc>/tikahtml   parsed 穩定 500 時的備援（scripts/fetch_tikahtml.py 先抓進快取）

快取在 .cache/ 下，重跑不會重打 API。API 會回 429，本檔已內建重試與節流。
"""
import argparse, html.parser, json, os, re, sys, time, urllib.request
from urllib.parse import quote

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
API = "https://v2.ly.govapi.tw"
CACHE = os.path.join(os.path.dirname(os.path.abspath(__file__)), ".cache")
TOKEN = os.environ.get("OPENFUN_TOKEN", "")

# 16 場全院委員會人事同意權審查（第 8-11 屆，2026-09-11 查證）
PERSONNEL_MEETS = [
    "全院委員會-11-4-1", "全院委員會-11-3-4", "臨時會全院委員會-10-7-1-1", "全院委員會-10-5-1",
    "臨時會全院委員會-10-1-1-2", "臨時會全院委員會-10-1-1-1", "全院委員會-9-8-1",
    "臨時會全院委員會-9-7-1-1", "臨時會全院委員會-9-4-1-1", "全院委員會-9-3-1",
    "全院委員會-9-2-1", "全院委員會-8-7-1", "臨時會全院委員會-8-5-1-2",
    "臨時會全院委員會-8-5-1-1", "全院委員會-8-4-1", "全院委員會-8-1-1",
]

# 階段 anchor。★ 者為人事審查專屬、對切分有直接用途的。
ANCHORS = [
    ("開會",              r"(?:現在)?開會|已足法定人數"),
    ("報告事項/議事錄",    r"進行報告事項|宣讀.{0,20}議事錄"),
    ("討論事項",          r"進行討論事項"),
    ("★審查某被提名人",    r"審查.{0,60}?被提名人.{0,20}?之資格|就審查被提名人|進行審查.{0,30}被提名人"),
    ("★請被提名人說明",    r"請被提名人.{0,25}說明|請.{2,12}(?:先生|女士|審計長|法官|檢察官|教授|院長|部長).{0,12}說明，時間"),
    ("★進行委員詢問",      r"進行委員詢問|開始進行委員詢問|進行委員的詢問"),
    ("委員輪次點名",       r"請.{2,10}委員(?:詢問|質詢)"),
    ("★詢答結束（單人）",  r"詢答(?:的)?部分到此為止|詢答到此為止"),
    ("★審查完竣（全案）",  r"審查完竣|均已審查完畢|已經審查完竣|審查完畢"),
    ("★決議（記名投票）",  r"作以下決議|提報院會|記名投票表決"),
    ("休息/繼續開會",      r"現在休息|繼續開會"),
    ("散會",              r"現在散會|^散會"),
]

STRUCT = re.compile(r'^(段落|項目|提案人|連署人|決議|附件|程序委員會意見|議事處意見|案由|說明)[：:]')
NOMINEE = re.compile(r'^([^：:]{2,12}?)(?:被提名人|先生|女士)[：:]')
SEG_Q = re.compile(r'^段落[：:]質詢[：:](.{2,12}?)[：:]')

# 段落開頭的發言人標記。parsed 的 block 粒度在不同屆不一致：
#   第 10-11 屆：一個 block 一位發言人（實測 0% 的 block 混多人）
#   第 8-9 屆  ：一個 block 可含多位發言人，標記在段落開頭（實測 9-22%）
# 因此判斷「這句是不是主席說的」必須切到段落層，不能只看 block[0]。
SPEAKER = re.compile(r'^([^：:，、。？！]{2,14}?)[：:]')
# ★ 職稱可能在姓名「中間」：公報寫法是「鍾委員佳濱：」（姓＋委員＋名），不是
#   「鍾佳濱委員：」。只用 `(委員|…)$` 收尾錨點會認不出這種標記，狀態機就不換人，
#   該段落被繼續歸給主席。實測 16 場人事審查共 380 段委員發言被誤掛在主席名下
#   （占主席段的 13.5%，單場最高 23.5%），172 種標記幾乎全是這個型態，
#   含兩字姓（「周陳委員秀霞」）。修正後主席段 2,816 → 2,213：掉的比那 380 段多，
#   因為狀態機一旦認出換人，後面沒有標記的段落也跟著不再算主席（那才是對的）。
#   且沒有任何真正的主席 anchor 消失（只掉了 2 個本來就誤中的），
#   8 場已標注場次的切分結果逐字不變。
#   重跑：python3 scripts/audit_speakers.py（修正後應為 0 段誤掛）
SPEAKER_OK = re.compile(r'(委員|先生|女士|被提名人|部長|次長|署長|局長|處長|'
                        r'秘書長|院長|主委|主任委員|法官|檢察官|教授|審計長)$'
                        r'|委員[\u4e00-\u9fff‧・]{1,5}$')


def chair_paras(bs):
    """回傳主席實際說的段落。2026-09-19 起改用共用模組 speakers.py
    （五類會議對帳過：python3 scripts/audit_speakers_all.py）。舊版的狀態機認不出
    原住民委員（「伍麗華Saidhai Tahovecahe委員：」）與「○○委員書面詢問：」，
    會把這些段落算成主席講的。"""
    import speakers
    return speakers.chair_paras(bs)


def fetch(url, tries=6):
    if "/ASR_" in url:
        return None            # AI 逐字稿轉接層的假文件 id（ASR_日期_序號）不在 API 上，不要打、不要重試（2026-11-03：每個 404 要等 60 秒）
    os.makedirs(CACHE, exist_ok=True)
    key = os.path.join(CACHE, re.sub(r'[^A-Za-z0-9_.-]', '_', url.replace(API, "")) + ".json")
    if os.path.exists(key):
        return json.load(open(key))
    for a in range(tries):
        try:
            headers = {"User-Agent": "research-ly-meet/1.0"}
            if TOKEN:
                headers["Authorization"] = f"Bearer {TOKEN}"
            req = urllib.request.Request(quote(url, safe=":/?&=%,"), headers=headers)
            body = urllib.request.urlopen(req, timeout=120).read()
            d = json.loads(body)
            open(key, "wb").write(body)
            time.sleep(1.2)          # 節流：API 會回 429
            return d
        except Exception as e:
            if a == tries - 1:
                print(f"  [fetch 失敗] {url} → {e}", file=sys.stderr)
                return None
            time.sleep(4 * (a + 1))


def meet_docs(meet_id):
    """回傳 [{lcidc, date, gazette, page_start, page_end}]，依公報發言紀錄順序。"""
    d = fetch(f"{API}/meet/{meet_id}")
    if not d or not d.get("data"):
        return []
    out = []
    for g in d["data"].get("公報發言紀錄") or []:
        for lc in g.get("agenda_lcidc_ids") or []:
            out.append({"lcidc": lc, "date": (g.get("meetingDate") or [None])[0],
                        "gazette": g.get("gazette_id"),
                        "page_start": g.get("page_start"), "page_end": g.get("page_end")})
    return out


def parsed_path(lcidc):
    return os.path.join(CACHE, f"_gazette_agenda_doc_{lcidc}_parsed.json")


def tika_path(lcidc):
    return os.path.join(CACHE, f"_gazette_agenda_doc_{lcidc}_tikahtml.html")


def parsed_ok(lcidc):
    """parsed 快取存在而且是正常的 JSON 物件。parsed 端點壞掉時會回 200 加一個字串（議程標題或 PHP 除錯輸出，
    例 1095601_00002 回「八本院時代力量黨團擬具…」），快取檔開頭是 `"` 不是 `{`。"""
    try:
        with open(parsed_path(lcidc), encoding="utf-8") as f:
            return f.read(1) == "{"
    except OSError:
        return False


def worddoc_path(lcidc):
    return os.path.join(CACHE, f"_gazette_agenda_doc_{lcidc}_worddoc.txt")


def doc_source(lcidc):
    """文字來源，依序備援：parsed → tikahtml（scripts/fetch_tikahtml.py）→ 原始 Word 檔（scripts/worddoc.py）。"""
    if parsed_ok(lcidc):
        return "parsed"
    if os.path.exists(tika_path(lcidc)):
        return "tikahtml"
    if os.path.exists(worddoc_path(lcidc)):
        return "worddoc"
    return None


def has_doc(lcidc):
    """快取裡有這份文件的文字。各切分器判斷「文件齊全」用這個。"""
    return doc_source(lcidc) is not None


class _TikaP(html.parser.HTMLParser):
    """tikahtml → 段落清單：一個 <p> 一段（段內 <span>/<b> 不斷行）；<img> 轉成 parsed 的圖片寫法，讓圖片判斷照常運作。"""
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.paras, self.buf, self.in_p, self.skip, self.img = [], [], 0, 0, 0

    def handle_starttag(self, tag, attrs):
        if tag in ("head", "style", "script"):
            self.skip += 1
        elif tag == "p":
            self.in_p += 1
        elif tag == "img":
            self.img += 1
            src = dict(attrs).get("src") or f"image{self.img}.jpg"
            self._flush()
            self.paras.append(f'INCLUDEPICTURE "{src}"')

    def handle_endtag(self, tag):
        if tag in ("head", "style", "script"):
            self.skip = max(0, self.skip - 1)
        elif tag == "p":
            self.in_p = max(0, self.in_p - 1)
            self._flush()

    def handle_data(self, data):
        if not self.skip and self.in_p:
            self.buf.append(data)

    def _flush(self):
        s = re.sub(r'[ \t\r\n]+', ' ', "".join(self.buf)).strip()
        self.buf = []
        if s:
            self.paras.append(s)


def tika_blocks(lcidc):
    """tikahtml 轉成和 parsed 同格式的 blocks（list of list of str）。
    parsed 一個 block ≈ 一位發言人的一段話，這裡用 speakers.walk() 的「新發言人／結構標記」切 block。
    「主席：」單獨一段（Word 樣式把標記與內文分開）時，與下一段併成一段。"""
    p = _TikaP()
    p.feed(open(tika_path(lcidc), encoding="utf-8", errors="ignore").read())
    return paras_to_blocks(p.paras)


def paras_to_blocks(raw):
    import speakers
    paras = []
    for s in raw:
        if paras and re.fullmatch(r'[^：:，。、？！「」]{1,40}[：:]', paras[-1]) and not s.startswith("INCLUDEPICTURE"):
            paras[-1] += s
        else:
            paras.append(s)
    out = []
    for w in speakers.walk(paras):
        if w["new"] or not out:
            out.append([])
        out[-1].append(w["raw"])
    return out


def blocks(lcidc):
    src = doc_source(lcidc)
    if src == "tikahtml":
        return tika_blocks(lcidc)
    if src == "worddoc":
        return paras_to_blocks(open(worddoc_path(lcidc), encoding="utf-8").read().split("\n"))
    d = fetch(f"{API}/gazette_agenda_doc/{lcidc}/parsed")
    if not isinstance(d, dict):          # API 偶爾回字串（錯誤訊息），當成抓取失敗，不要讓整批中斷
        return []
    return d.get("blocks") or []


def timeline(meet_id, show_all=False):
    docs = meet_docs(meet_id)
    if not docs:
        print(f"{meet_id}：公報發言紀錄為空，無法取得公報子文件")
        return
    for doc in docs:
        bs = blocks(doc["lcidc"])
        print("─" * 92)
        print(f"{doc['lcidc']}  {doc['date']}  公報{doc['gazette']} p{doc['page_start']}-{doc['page_end']}  {len(bs)} blocks")
        cur = None
        for i, blk in enumerate(bs):
            for para in blk or []:
                m = SPEAKER.match(para)
                if m:
                    name = m.group(1)
                    if name == "主席":
                        cur, para = "主席", para[m.end():]
                    elif SPEAKER_OK.search(name) or STRUCT.match(para):
                        cur = name
                if STRUCT.match(para):
                    print(f"  [{i:>4}] 《{para[:84]}》")
                elif cur == "主席":
                    t = para.strip()
                    if t and (show_all or len(t) > 6):
                        print(f"  [{i:>4}] 主席｜{t[:96]}")


def doc_map(meet_id):
    docs = meet_docs(meet_id)
    print(f"{meet_id}：{len(docs)} 個公報子文件")
    for doc in docs:
        bs = blocks(doc["lcidc"])
        noms, qs = [], []
        for blk in bs:
            if not blk:
                continue
            m = NOMINEE.match(blk[0])
            if m and m.group(1) not in noms:
                noms.append(m.group(1))
            q = SEG_Q.match(blk[0])
            if q:
                qs.append(q.group(1))
        print(f"  {doc['lcidc']} {doc['date']} p{doc['page_start']}-{doc['page_end']} "
              f"blocks={len(bs):>4} 被提名人={noms or '—'} 質詢{len(qs)}位")


def census():
    names = [n for n, _ in ANCHORS]
    rows = []
    for m in PERSONNEL_MEETS:
        docs = meet_docs(m)
        if not docs:
            rows.append((m, None))
            continue
        chair, seg_q, nom_seq = [], 0, []
        for doc in docs:
            bs = blocks(doc["lcidc"])
            chair.extend(chair_paras(bs))       # 段落層，不是 block 層
            for blk in bs:
                if not blk:
                    continue
                if SEG_Q.match(blk[0]):
                    seg_q += 1
                nm = NOMINEE.match(blk[0])
                if nm:
                    nom_seq.append(nm.group(1))
        txt = "\n".join(chair)
        runs = []
        for s in nom_seq:
            if not runs or runs[-1] != s:
                runs.append(s)
        n_nom = len(set(nom_seq))
        kind = "—" if not n_nom else ("逐位審查" if len(runs) <= n_nom * 1.5 else "併審/交錯")
        rows.append((m, {"docs": len(docs), "seg_q": seg_q, "n_nom": n_nom,
                         "runs": len(runs), "kind": kind,
                         "hits": {n: len(re.findall(p, txt)) for n, p in ANCHORS}}))

    print(f"{'meet_id':<24}{'冊':>3}{'質詢marker':>10}{'被提名人':>7}{'區段':>5}  {'審查型態':<10}")
    for m, r in rows:
        if r is None:
            print(f"{m:<24}{'—':>3}{'—':>10}{'—':>7}{'—':>5}  {'無公報資料':<10}")
        else:
            print(f"{m:<24}{r['docs']:>3}{r['seg_q']:>10}{r['n_nom']:>7}{r['runs']:>5}  {r['kind']:<10}")

    have = [r for _, r in rows if r]
    print(f"\n各 anchor 在有公報資料的 {len(have)} 場中的命中場次：")
    for n in names:
        c = sum(1 for r in have if r["hits"][n] > 0)
        print(f"  {n:<20} {c}/{len(have)}  ({c / len(have) * 100:.0f}%)")


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("meet_id", nargs="?")
    ap.add_argument("--docs", action="store_true", help="列出各公報子文件的被提名人／質詢委員")
    ap.add_argument("--census", action="store_true", help="16 場人事審查的 anchor 覆蓋率普查")
    ap.add_argument("--all", action="store_true", help="時序模式下連極短的主席發言也列出")
    a = ap.parse_args()
    if a.census:
        census()
    elif a.meet_id and a.docs:
        doc_map(a.meet_id)
    elif a.meet_id:
        timeline(a.meet_id, a.all)
    else:
        ap.print_help()
