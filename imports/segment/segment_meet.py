#!/usr/bin/env python3
"""把 James 的切分器（segment_*.py）跑在一場會議上，輸出「單元 → 段」並對回公報發言索引（ES speech）的發言編號。

資料流
  /meet/{id} 的公報發言紀錄 → 每份文件的 parsed blocks（切分器自己抓）→ 切分器 → session／section
  → 每段涵蓋的 parsed block 序號 → parsed.block_lines[序號] → speech 的 block_line → 發言編號（seq）
  → 一段一筆寫進 ES index `meet_section`，另存 meet-section/{meet_id}.json 方便人看。

parsed 與 speech 都是 GazetteTranscriptParser 同一次切 block 的結果，block_line 是 tikahtml 的行號，兩邊可以直接對。
parsed 裡「段落：…」這類合成標記沒有對應的 speech block，對不到就略過。

用法
  python3 segment_meet.py 院會-11-5-18 委員會-11-5-20-18 公聽會-2026102285   # 跑指定會議，寫 JSON
  python3 segment_meet.py --index 院會-11-5-18                                 # 同時寫進 ES
  python3 segment_meet.py --caucus 1086006_00005                               # 黨團協商以公報文件編號跑
  環境變數 OPENFUN_TOKEN：切分器抓 v2 API 用的 token（沒給就從 config.php 的 OPENFUN_DATA_TOKEN 拿）。

unit_kind（段的種類，交接文件要求資料端給、畫面不從段名猜）：
  person（人段）／case（案段）／stage（階段段）／group（分組標題）／procedure（過場、程序段）／attachment（書面資料）
  第一版規則寫在 UNIT_KIND_RULES，是依 James 各切分器的 kind／role 對照出來的，待 James 核。
"""
import argparse, collections, json, os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import lyapi_es

if not os.environ.get("OPENFUN_TOKEN"):
    os.environ["OPENFUN_TOKEN"] = lyapi_es.config()["token"]

import stages                 # noqa: E402  （要在 OPENFUN_TOKEN 設好之後 import，它 import 時讀環境變數）
import hearing, plenary, budget   # noqa: E402

VERSION = 1                  # 本檔的輸出格式／對應規則版本


# ───────────────────────── parsed 走本機，不打正式 API ─────────────────────────
# 切分器各自的 fetch() 都會抓 /gazette_agenda_doc/{lcidc}/parsed（最重的一種請求：伺服器要從 S3 抓 tikahtml 再切）。
# 匯入端自己就有 tikahtml（imports/gazette/agenda-tikahtml/），用 parsed.php 跑同一個 GazetteTranscriptParser，
# 寫進切分器原本的快取檔（檔名規則相同），其他網址照舊打 API。

_PARSED_URL = re.compile(r'/gazette_agenda_doc/(\d+_\d+)/parsed$')


def local_parsed(lcidc):
    key = stages.parsed_path(lcidc)
    if os.path.exists(key):
        try:
            return json.load(open(key, encoding="utf-8"))
        except ValueError:
            pass
    import subprocess
    r = subprocess.run(["php", os.path.join(HERE, "parsed.php"), lcidc], capture_output=True, text=True)
    if r.returncode != 0 or not r.stdout.startswith("{"):
        return None
    os.makedirs(stages.CACHE, exist_ok=True)
    with open(key, "w", encoding="utf-8") as f:
        f.write(r.stdout)
    return json.loads(r.stdout)


def _wrap_fetch(mod):
    orig = getattr(mod, "fetch", None)
    if orig is None or getattr(orig, "_local_parsed", False):
        return
    def fetch(url, tries=6):
        m = _PARSED_URL.search(url)
        if m:
            d = local_parsed(m.group(1))
            if d is not None:
                return d
        return orig(url, tries)
    fetch._local_parsed = True
    mod.fetch = fetch


for _mod in (stages, hearing, plenary, budget):
    _wrap_fetch(_mod)
SEGMENTER_SOURCE = "research-ly-meet 93b0034 (2026-11-23)"   # James 切分器搬進來時的版本
OUT_DIR = os.path.join(HERE, "meet-section")
INDEX = "meet_section"

MAPPINGS = {
    "properties": {
        "id": {"type": "keyword"},
        "meet_id": {"type": "keyword"},
        "meet_type": {"type": "keyword"},
        "segmenter": {"type": "keyword"},
        "segmenter_version": {"type": "keyword"},
        "term": {"type": "integer"},
        "date": {"type": "date"},
        "session_no": {"type": "integer"},
        "session_name": {"type": "text", "fields": {"keyword": {"type": "keyword", "ignore_above": 256}}},
        "session_kind": {"type": "keyword"},
        "session_dates": {"type": "date"},
        "session_tags": {"type": "keyword"},
        "section_no": {"type": "integer"},
        "section_type": {"type": "keyword"},
        "name": {"type": "text", "fields": {"keyword": {"type": "keyword", "ignore_above": 256}}},
        "tags": {"type": "keyword"},
        "unit_kind": {"type": "keyword"},
        "vote": {"properties": {"item": {"type": "keyword"}, "what": {"type": "text"}, "type": {"type": "keyword"}}},
        "lcidc_id": {"type": "keyword"},
        "seq_start": {"type": "integer"},
        "seq_end": {"type": "integer"},
        "seqs": {"type": "integer"},
        "block_count": {"type": "integer"},
        "speakers": {"type": "keyword"},
        "sample": {"type": "text"},
        "updated_at": {"type": "date"},
    }
}

PROCEDURE_NAME = re.compile(r'^（(?:程序／表決|區段外|點名之外|階段未判定|開場|宣告|宣讀預算數及提案)')
GROUP_NAME = re.compile(r'^(?:〔[^〕]+〕|第[一二三四五六七八九十\d]+目)$')


# ───────────────────────── 對回發言編號 ─────────────────────────

class SeqMap:
    """(lcidc, parsed block 序號) → speech 的發言編號。"""

    def __init__(self):
        self.lines = {}      # lcidc → parsed.block_lines
        self.speech = {}     # lcidc → {block_line: speech doc}

    def load(self, lcidc):
        if lcidc in self.lines:
            return
        d = stages.fetch(f"{stages.API}/gazette_agenda_doc/{lcidc}/parsed") or {}
        self.lines[lcidc] = d.get("block_lines") or []
        self.speech[lcidc] = {b["block_line"]: b for b in lyapi_es.speech_blocks(lcidc) if b.get("block_line") is not None}

    def seq(self, lcidc, block_idx):
        self.load(lcidc)
        lines = self.lines[lcidc]
        if block_idx is None or block_idx >= len(lines):
            return None
        b = self.speech[lcidc].get(lines[block_idx])
        return b["seq"] if b else None

    def doc(self, lcidc, seq):
        for b in self.speech.get(lcidc, {}).values():
            if b["seq"] == seq:
                return b
        return None


# ───────────────────────── 各切分器 → 統一的 session／section ─────────────────────────
# 統一格式：session = {name, kind, tags, note, sections: [{type, name, tags, vote, unit_kind, refs: [(lcidc, block_idx)]}]}

def unit_kind(segmenter, session_kind, sec_type, name, vote=False, role=None):
    if PROCEDURE_NAME.match(name or ""):
        return "procedure"
    if GROUP_NAME.match(name or ""):
        return "group"
    if vote:
        return "case"
    if segmenter == "院會":
        k = session_kind or ""
        if any(x in k for x in ("國是論壇", "質詢", "發言", "演講", "總決算")):
            return "person"
        if any(x in k for x in ("討論事項", "臨時提案", "復議", "覆議")):
            return "case"
        return "stage"
    if segmenter == "委員會預算審查":
        if sec_type in ("report", "qa"):
            return "person"
        if sec_type in ("process", "freeze", "discuss", "motion"):
            return "case"
        return "stage"
    if segmenter == "公聽會":
        if role == "procedural":
            return "procedure"
        return "person"
    if segmenter == "黨團協商":
        return {"開場": "procedure", "討論": "procedure", "點名": "person", "換題": "case", "結論": "stage"}.get(sec_type, "stage")
    return "stage"


def from_plenary(r):
    import segment_plenary as SP
    sessions = []
    for x in r["sessions"]:
        ps = x.get("_paras") or []
        disp = x.get("display") or x["kind"]
        secs = []
        for sec in x.get("sections", []):
            a, b = sec.get("start", 0), sec.get("end", -1)
            refs = [(p["lcidc"], p["block"]) for p in ps[a:b + 1] if p.get("text")]
            vote = {"item": sec.get("vote_item") or "", "what": sec.get("vote_what") or "", "type": sec.get("vote_type") or ""} if sec.get("vote") else None
            secs.append({"type": disp, "name": sec.get("name", ""), "tags": list(sec.get("tags", [])), "vote": vote,
                         "unit_kind": unit_kind("院會", x["kind"], disp, sec.get("name", ""), vote=bool(vote)), "refs": refs})
        if not secs and ps:
            refs = [(p["lcidc"], p["block"]) for p in ps if p.get("text")]
            secs.append({"type": disp, "name": x["name"][:60], "tags": [], "vote": None,
                         "unit_kind": unit_kind("院會", x["kind"], disp, ""), "refs": refs})
        for a in x.get("attachments") or []:
            secs.append({"type": "書面資料", "name": str(a)[:80], "tags": [], "vote": None, "unit_kind": "attachment", "refs": []})
        sessions.append({"name": x["name"][:80], "kind": x["kind"], "tags": [], "note": x.get("note") or "", "sections": secs})
    return "院會", sessions, {"unplaced": None, "notes": r.get("notes") or []}


def from_budget(r):
    import segment_budget as SB
    sessions = []
    for d in r["days"]:
        ps = d["paras"]
        typ = "／".join(d.get("buckets") or []) or "非預算日"
        if d.get("note"):
            sessions.append({"name": f"{d['lcidc']}：{d['note']}", "kind": "note", "tags": [typ], "note": d["note"], "date": d["date"], "sections": []})
            continue
        for s in d["sessions"]:
            secs = []
            for sec in s["sections"]:
                refs = [(ps[j]["lcidc"], ps[j]["block"]) for j in range(sec["start"], sec["end"] + 1)
                        if ps[j]["text"] and ps[j]["kind"] in ("say", "seg_qa", "struct", "seg")]
                secs.append({"type": SB.KIND_ZH.get(s["kind"], s["kind"]), "name": sec["name"], "tags": list(sec["tags"]), "vote": None,
                             "unit_kind": unit_kind("委員會預算審查", s["kind"], s["kind"], sec["name"]), "refs": refs})
            sessions.append({"name": s["name"], "kind": s["kind"], "tags": [typ], "note": "", "date": d["date"], "sections": secs})
    return "委員會預算審查", sessions, {"dates": r.get("dates"), "title": r.get("title")}


def from_hearing(r, mode="C"):
    import segment_hearing as SH
    sessions = []
    for x in r[mode]:
        secs = []
        for s in x["secs"]:
            refs = [(p["doc"], p["block"]) for p in s["paras"] if p["text"]]
            secs.append({"type": SH.ROLE_ZH.get(s["role"], s["role"]), "name": s["name"], "tags": [], "vote": None,
                         "unit_kind": unit_kind("公聽會", x["label"], s["role"], s["name"], role=s["role"]), "refs": refs})
        sessions.append({"name": x["label"], "kind": x["label"], "tags": [], "note": "", "sections": secs})
    if r.get("attachments"):
        secs = [{"type": "書面資料", "name": a["name"], "tags": [], "vote": None, "unit_kind": "attachment",
                 "refs": [(a["doc"], None)]} for a in r["attachments"]]
        sessions.append({"name": "書面資料", "kind": "attachment", "tags": [], "note": "", "sections": secs})
    return "公聽會", sessions, {"docs": r.get("docs"), "note": r.get("note")}


def from_caucus(lcidc, r):
    """黨團協商：切分器以公報文件編號為單位，一份文件可能有好幾場紀錄（records）。
    section 的 start／end 是 record 內的段落序；段落序 = parsed 攤平後的序，換算成 block 序號。"""
    import segment_caucus as SC
    NG = SC.NG
    blocks = NG.blocks(lcidc)
    flat_to_block = [bi for bi, b in enumerate(blocks) for _ in (b or [])]
    sp = NG.speaker_paras(NG.paras(lcidc))
    recs = NG.records(sp)
    offsets, off = [], 0
    for rec in recs:
        offsets.append(off)
        off += len(rec)
    sessions = []
    for rec_no, (rec, s) in enumerate(zip(recs, r["sessions"])):
        base = offsets[rec_no]
        secs = []
        for sec in s["sections"]:
            refs = [(lcidc, flat_to_block[k]) for k in range(base + sec["start"], base + sec["end"] + 1) if k < len(flat_to_block)]
            secs.append({"type": SC.SEC_TYPE.get(sec["kind"], sec["kind"]), "name": sec["name"], "tags": list(sec.get("tags", [])), "vote": None,
                         "unit_kind": unit_kind("黨團協商", "", sec["kind"], sec["name"]), "refs": refs})
        for a in s.get("attachments") or []:
            secs.append({"type": "書面資料", "name": a["name"], "tags": [], "vote": None, "unit_kind": "attachment", "refs": [(lcidc, None)]})
        topic = (s.get("協商主題") or [""])[0][:80]
        sessions.append({"name": topic or s.get("標題", ""), "kind": "紀錄", "tags": list(s.get("tags") or []),
                         "note": "；".join(s.get("notes") or []), "date": _roc_date(s.get("時間", "")),
                         "meet_id": s.get("協商會議代碼"), "sections": secs})
    return "黨團協商", sessions, {"notes": r.get("notes") or []}


def _roc_date(s):
    m = re.search(r'(\d{2,3})年(\d{1,2})月(\d{1,2})日', s or "")
    return f"{int(m.group(1)) + 1911}-{int(m.group(2)):02d}-{int(m.group(3)):02d}" if m else None


# ───────────────────────── 組輸出 ─────────────────────────

def build_docs(meet_id, meet_type, segmenter, sessions, seqmap, doc_dates, term=None):
    docs = []
    for sno, s in enumerate(sessions, 1):
        session_dates = set()
        for sec in s["sections"]:
            for lc, _ in sec["refs"]:
                if doc_dates.get(lc):
                    session_dates.add(doc_dates[lc])
        if s.get("date"):
            session_dates.add(s["date"])
        for cno, sec in enumerate(s["sections"], 1):
            by_doc = collections.OrderedDict()
            for lc, bi in sec["refs"]:
                by_doc.setdefault(lc, [])
                if bi is not None:
                    q = seqmap.seq(lc, bi)
                    if q is not None:
                        by_doc[lc].append(q)
            if not by_doc:
                by_doc[None] = []
            for lc, seqs in by_doc.items():
                seqs = sorted(set(seqs))
                speakers = []
                for q in seqs:
                    b = seqmap.doc(lc, q)
                    if b and b.get("speaker_raw") and b["speaker_raw"] not in speakers:
                        speakers.append(b["speaker_raw"])
                sample = ""
                if seqs:
                    b = seqmap.doc(lc, seqs[0])
                    sample = (b.get("text") or "")[:80] if b else ""
                docs.append({
                    "id": f"{meet_id}|{sno}|{cno}" + (f"|{lc}" if len(by_doc) > 1 else ""),
                    "meet_id": s.get("meet_id") or meet_id,
                    "meet_type": meet_type,
                    "segmenter": segmenter,
                    "segmenter_version": f"{VERSION}/{SEGMENTER_SOURCE}",
                    "term": term,
                    "date": doc_dates.get(lc) or s.get("date") or (sorted(session_dates)[0] if session_dates else None),
                    "session_no": sno,
                    "session_name": s["name"],
                    "session_kind": s["kind"],
                    "session_dates": sorted(session_dates),
                    "session_tags": s.get("tags") or [],
                    "section_no": cno,
                    "section_type": sec["type"],
                    "name": sec["name"],
                    "tags": sec["tags"],
                    "unit_kind": sec["unit_kind"],
                    "vote": sec["vote"],
                    "lcidc_id": lc,
                    "seq_start": seqs[0] if seqs else None,
                    "seq_end": seqs[-1] if seqs else None,
                    "seqs": seqs,
                    "block_count": len(seqs),
                    "speakers": speakers,
                    "sample": sample,
                })
    return docs


def meet_info(meet_id):
    d = stages.fetch(f"{stages.API}/meet/{meet_id}") or {}
    m = d.get("data") or {}
    dates = {}
    for g in m.get("公報發言紀錄") or []:
        for lc in g.get("agenda_lcidc_ids") or []:
            dates.setdefault(lc, (g.get("meetingDate") or [None])[0])
    return m, dates


def run_meet(meet_id, mode=None):
    m, doc_dates = meet_info(meet_id)
    meet_type = m.get("會議種類") or ""
    kind = mode or {"院會": "院會", "委員會": "委員會預算審查", "聯席會議": "委員會預算審查", "公聽會": "公聽會"}.get(meet_type)
    if not kind:
        return None, f"{meet_id}: 會議種類「{meet_type}」還沒有切分器"
    if kind == "院會":
        import segment_plenary as SP
        r = SP.segment_coarse(meet_id, keep_paras=True)
        segmenter, sessions, extra = from_plenary(r)
    elif kind == "委員會預算審查":
        import segment_budget as SB
        try:
            r = SB.segment(meet_id)
        except SystemExit as e:
            return None, f"{meet_id}: {e}"
        segmenter, sessions, extra = from_budget(r)
    elif kind == "公聽會":
        import segment_hearing as SH
        r = SH.segment(meet_id)
        segmenter, sessions, extra = from_hearing(r)
    else:
        return None, f"{meet_id}: 不支援 {kind}"
    docs = build_docs(meet_id, meet_type, segmenter, sessions, SeqMap(), doc_dates, term=m.get("屆"))
    return {"meet_id": meet_id, "meet_type": meet_type, "segmenter": segmenter, "extra": extra, "sections": docs}, None


def run_caucus(lcidc):
    import segment_caucus as SC
    _wrap_fetch(SC.NG)
    r = SC.segment(lcidc)
    if r.get("error"):
        return None, f"{lcidc}: {r['error']}"
    segmenter, sessions, extra = from_caucus(lcidc, r)
    meet_id = next((s.get("meet_id") for s in sessions if s.get("meet_id")), None) or f"公報-{lcidc}"
    docs = build_docs(meet_id, "黨團協商", segmenter, sessions, SeqMap(), {}, term=None)
    return {"meet_id": meet_id, "meet_type": "黨團協商", "segmenter": segmenter, "extra": extra, "sections": docs}, None


def summarize(out):
    secs = out["sections"]
    mapped = sum(1 for d in secs if d["block_count"])
    by_kind = collections.Counter(d["unit_kind"] for d in secs)
    return f"{out['meet_id']} [{out['segmenter']}] 段 {len(secs)}、對到發言 {mapped}、unit_kind {dict(by_kind)}"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("ids", nargs="*", help="會議代碼")
    ap.add_argument("--caucus", nargs="*", default=[], help="黨團協商：公報文件編號")
    ap.add_argument("--index", action="store_true", help="寫進 ES")
    ap.add_argument("--out", default=OUT_DIR)
    a = ap.parse_args()
    os.makedirs(a.out, exist_ok=True)
    if a.index:
        lyapi_es.ensure_index(INDEX, MAPPINGS)
    jobs = [(i, False) for i in a.ids] + [(i, True) for i in a.caucus]
    for ident, is_caucus in jobs:
        out, err = (run_caucus(ident) if is_caucus else run_meet(ident))
        if err:
            print(err, file=sys.stderr)
            continue
        with open(os.path.join(a.out, f"{out['meet_id']}.json"), "w", encoding="utf-8") as f:
            json.dump(out, f, ensure_ascii=False, indent=1)
        print(summarize(out))
        if a.index:
            lyapi_es.delete_by_query(INDEX, {"term": {"meet_id": out["meet_id"]}})
            import datetime
            now = datetime.datetime.now().astimezone().isoformat()
            for d in out["sections"]:
                d["updated_at"] = now
            errs = lyapi_es.bulk(INDEX, out["sections"], refresh=True)
            print(f"  indexed {len(out['sections'])} docs, errors {errs}")


if __name__ == "__main__":
    main()
