#!/usr/bin/env python3
"""
全院委員會人事同意權審查 — 第10、11屆清整輸出

在 scripts/segment.py 的規格上，套用 2026-09-18 RD 回覆
（findings/rd-responses/2026-09-18.md）對第10、11屆的兩項細化：

  1. 併審場次：第10、11屆若能穩定判斷主席逐組宣告的組數，改按組切成獨立 session
     （原規則是整案一個 session，組別只當作內部 section 的標籤前綴）。
  2. 逐位審查場次：第10、11屆的公報自帶「段落：質詢：委員：HH:MM」結構性 marker
     （見 findings/personnel-review/README.md 第5節），用它切出「委員詢答」section，
     不留空。第8、9屆沒有這個 marker，不受影響（本次輸出範圍本來就只有10、11屆）。

只動這支新檔，不改 segment.py——那是 James 的研究交付物，改法要不要收進主規格
是他們下一步的事；這裡只是 RD 端先把第10、11屆的資料按已拍板的規則清整出來用。

用法：
  python3 scripts/build_term10_11.py --out ~/testenv/demo/20260909/personnel-review
"""
import argparse, json, os, re, sys, importlib.util

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
sys.path.insert(0, HERE)

_spec = importlib.util.spec_from_file_location("segment", os.path.join(HERE, "segment.py"))
seg = importlib.util.module_from_spec(_spec); _spec.loader.exec_module(seg)

SEG_Q = re.compile(r'^段落[：:]質詢[：:](.{2,12}?)[：:](\d{1,2}:\d{2})?')

# 第10、11屆的全院委員會人事同意權審查（全數 6 場，見 findings/personnel-review/meets.md）
TARGET_MEETS = [
    "全院委員會-11-4-1",
    "全院委員會-11-3-4",
    "臨時會全院委員會-10-7-1-1",
    "全院委員會-10-5-1",
    "臨時會全院委員會-10-1-1-1",
    "臨時會全院委員會-10-1-1-2",
]


def term_of(meet_id):
    m = re.search(r'全院委員會-(\d+)-', meet_id)
    return int(m.group(1)) if m else None


def build_qa_sections(stream, a, b):
    """用「段落：質詢：委員：HH:MM」marker 把 [a,b] 切成委員詢答 section。
    只有第10、11屆的公報才有這個 marker；沒有 marker 就回傳 []（呼叫端保留原狀）。"""
    marks = []
    for i in range(a, b + 1):
        m = SEG_Q.match(stream[i]["raw"])
        if m:
            marks.append((i, m.group(1), m.group(2)))
    if not marks:
        return []
    secs = []
    for k, (i, member, tstamp) in enumerate(marks):
        start = i + 1  # marker 本身不是發言內容，從下一段開始
        end = (marks[k + 1][0] - 1) if k + 1 < len(marks) else b
        if start > end:
            continue
        main, share = seg.dominant_nominee(stream, start, end)
        answerers = seg.nominees_in(stream, start, end)
        tag = (f"主談 {main}（{share:.0f}%）" if share >= 70 else
               f"偏向 {main}（{share:.0f}%）" if share >= 50 else
               (f"{len(answerers)} 位輪答" if answerers else ""))
        label = f"{member}" + (f"　{tstamp}" if tstamp else "") + (f"　{tag}" if tag else "")
        sec = seg.mk_section(stream, start, end, "委員詢答", label.strip(), "content")
        sec["主談被提名人"] = main if share >= 50 else None
        sec["答復被提名人"] = answerers
        sec["marker來源"] = "段落：質詢：（公報自帶結構標記，第10-11屆起）"
        secs.append(sec)
    return secs


def split_groups_into_sessions(meet_id, stream, docs, kind, n_nom):
    """併審場次：第10、11屆改按主席逐組宣告切成獨立 session（取代整案一個）。"""
    chair_idx = [i for i, s in enumerate(stream) if s["chair"]]
    starts_p = [i for i in chair_idx if seg.R_START_PRIMARY.search(stream[i]["text"])]
    starts_f_all = [i for i in chair_idx if seg.R_START_FALLBACK.search(stream[i]["text"])]
    starts_f = [i for i in starts_f_all if not seg.is_adjourn_notice(stream[i]["text"])]
    notices = [i for i in starts_f_all if seg.is_adjourn_notice(stream[i]["text"])]
    ends_all = [i for i in chair_idx if seg.R_END.search(stream[i]["text"])]
    ends = [i for i in ends_all if seg.R_END_NAMED.search(stream[i]["text"])]
    closes = [i for i in chair_idx if seg.R_CLOSE.search(stream[i]["text"])
              or seg.R_RESOLUTION.search(stream[i]["text"])]

    a = starts_f[0] if starts_f else (starts_p[0] if starts_p else chair_idx[0])
    b = ends[-1] if ends else (ends_all[-1] if ends_all else len(stream) - 1)
    groups = seg.group_bounds(stream, starts_f, notices, a, b)

    sessions = []
    for g, gi in enumerate(groups):
        gend = (groups[g + 1] - 1) if g + 1 < len(groups) else b
        intro = [i for i in starts_p if gi <= i <= gend]
        qa = next((i for i in chair_idx if gi <= i <= gend
                   and seg.R_QA_START.search(stream[i]["text"])
                   and (not intro or i > intro[0])), None)

        names = []
        secs = []
        for k, i in enumerate(intro):
            stop = (intro[k + 1] - 1) if k + 1 < len(intro) else ((qa - 1) if qa else gend)
            who = seg.nominee_of(stream[i]["text"], stream, i) or f"被提名人{k + 1}"
            names.append(who)
            secs.append(seg.mk_section(stream, i, stop, "被提名人說明", who, "content"))

        if qa:
            qa_secs = build_qa_sections(stream, qa, gend)
            if qa_secs:
                secs.extend(qa_secs)
            else:
                rounds = [i for i in chair_idx if qa <= i <= gend and seg.R_ROUND.search(stream[i]["text"])]
                if not rounds:
                    who = "、".join(names) if names else "?"
                    secs.append(seg.mk_section(stream, qa, gend, "共同詢答", f"{who}　交錯答詢", "content"))
                else:
                    if rounds[0] > qa:
                        secs.append(seg.mk_section(stream, qa, rounds[0] - 1, "詢答開始", "詢答開始", "content"))
                    for j, ri in enumerate(rounds):
                        rend = (rounds[j + 1] - 1) if j + 1 < len(rounds) else gend
                        mm = seg.R_ROUND.search(stream[ri]["text"])
                        member = mm.group(1) if mm else "委員"
                        main, share = seg.dominant_nominee(stream, ri, rend)
                        answerers = seg.nominees_in(stream, ri, rend)
                        tag = (f"主談 {main}（{share:.0f}%）" if share >= 70 else
                               f"偏向 {main}（{share:.0f}%）" if share >= 50 else
                               f"{len(answerers)} 位輪答")
                        sec = seg.mk_section(stream, ri, rend, "委員詢答", f"{member}　{tag}", "content")
                        sec["主談被提名人"] = main if share >= 50 else None
                        sec["發言量佔比"] = round(share, 1)
                        sec["答復被提名人"] = answerers
                        secs.append(sec)

        label = "、".join(names) if names else f"第{g + 1}組"
        sess = seg.mk_session(stream, gi, gend, label, "ok")
        sess["起點依據"] = "主席逐組宣告（第10、11屆自動分組，見 rd-responses/2026-09-18.md）"
        sess["終點依據"] = "下一組起點前一段，或全場終點"
        sess["組別"] = g + 1
        sess["sections"] = secs
        sessions.append(sess)

    return sessions, groups, (a, b, closes)


def build(meet_id):
    term = term_of(meet_id)
    docs, stream, written = seg.load_stream(meet_id)
    if not stream:
        return None
    kind, n_nom, n_runs = seg.review_type(stream)

    base = seg.segment(meet_id)  # 沿用 segment.py 算好的 other_sections、notes、terminated 偵測等
    if base is None:
        return None

    result = dict(base)
    result["清整規則版本"] = "term10-11-refined-2026-09-18"
    result["term"] = term

    if kind == "併審交錯" and term in (10, 11) and not base["sessions"]:
        # 已被 segment.py 判成 0 sessions（例如程序終止）就不覆寫
        pass
    elif kind == "併審交錯" and term in (10, 11):
        sessions, groups, _ = split_groups_into_sessions(meet_id, stream, docs, kind, n_nom)
        result["sessions"] = sessions
        result["notes"] = list(base["notes"]) + [
            f"★ 已依 2026-09-18 RD 回覆，第{term}屆併審場次改按主席逐組宣告切成 "
            f"{len(sessions)} 個獨立 session（原規格是整案一個 session，見 segment.py）。"
        ]
    elif kind == "逐位審查" and term in (10, 11):
        # 逐位審查：用結構性 marker 補上委員詢答 section（原規格此類完全沒有 section）
        for sess in result["sessions"]:
            a, b = sess["_a"], sess["_b"]
            sess["sections"] = build_qa_sections(stream, a, b)
        if any(s["sections"] for s in result["sessions"]):
            result["notes"] = list(base["notes"]) + [
                f"★ 已依 2026-09-18 RD 回覆，第{term}屆逐位審查場次用公報自帶的"
                f"「段落：質詢：委員：HH:MM」結構標記補上委員詢答 section（原規格此類 sections 為空）。"
            ]

    return result


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", required=True, help="輸出目錄")
    ap.add_argument("meet_id", nargs="*", help="只跑指定場次，預設跑全部 6 場第10、11屆人事審查")
    a = ap.parse_args()
    targets = a.meet_id or TARGET_MEETS

    outdir = os.path.expanduser(a.out)
    os.makedirs(outdir, exist_ok=True)

    summary = []
    for mid in targets:
        r = build(mid)
        if r is None:
            print(f"{mid}: 無公報資料，略過")
            summary.append({"meet_id": mid, "status": "無公報資料"})
            continue
        clean = seg.strip_private(r)
        fname = os.path.join(outdir, mid.replace("/", "_") + ".json")
        with open(fname, "w", encoding="utf-8") as f:
            json.dump(clean, f, ensure_ascii=False, indent=1)
        n_sess = len(r["sessions"])
        n_sec = sum(len(s.get("sections", [])) for s in r["sessions"])
        print(f"{mid}: term={r['term']} 型態={r['審查型態']} sessions={n_sess} sections={n_sec} → {fname}")
        summary.append({
            "meet_id": mid, "term": r["term"], "審查型態": r["審查型態"],
            "被提名人數": r["被提名人數"], "sessions": n_sess, "sections": n_sec,
            "notes": r["notes"], "file": os.path.basename(fname),
        })

    with open(os.path.join(outdir, "_summary.json"), "w", encoding="utf-8") as f:
        json.dump(summary, f, ensure_ascii=False, indent=1)
    print(f"\n共 {len(summary)} 場，摘要寫入 {os.path.join(outdir, '_summary.json')}")


if __name__ == "__main__":
    main()
