#!/usr/bin/env python3
"""
把 build_term10_11.py 的清整結果轉成 production meet-json 的 schema，
好讓 ~/testenv/demo/20260909/meet.php 這支既有的檢視介面可以直接開。

meet-json schema（meet.php 讀的格式）：
  {meet_id, meet_type, clip_count,
   sessions: [{date, content, clip_count,
               sections: [{type, label, kind, para_start, para_end,
                           blocks: [{time, speaker, text}]  // kind=ivod
                           或 gazette_text: "..."            // kind=attachment
                          }]}]}

被提名人單元／併審組 → 一個 session；委員詢答／被提名人說明 → section（kind=ivod，
帶完整逐字稿 blocks，不是只有起訖引文）。沒有「段落：質詢：」marker 的逐位審查（第 8、9 屆等）
按主席點名切委員詢答（split_qa_by_member）；委員以書面提出、列入紀錄的詢問是 section
type=書面資料（kind=attachment），不算口頭輪次。案由宣讀、結案決議、書面資料、程序終止
這些非特定被提名人的內容，掛在最前面一個「會議總覽」pseudo-session 底下（kind=attachment）。

用法：
  python3 scripts/build_meetjson.py --out ~/testenv/demo/20260909/personnel-review-meetjson
"""
import argparse, json, os, re, sys, importlib.util

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)


def _load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


seg = _load("segment", os.path.join(HERE, "segment.py"))
bt = _load("build_term10_11", os.path.join(HERE, "build_term10_11.py"))

# 全部 16 場人事審查（8 場原生有公報 + 8 場 2026-09-17 補回的）。
# 第10、11屆的場次會經過 build_term10_11.build() 的細化（按組切、補委員詢答 section），
# 其餘屆別沿用 segment.py 的原始規格（bt.build() 對非10/11屆等同直接呼叫 seg.segment()）。
ALL_MEETS = seg.MEETS + seg.MEETS_RECOVERED

TIME_RE = re.compile(r'^（(\d{1,2})時(\d{1,2})?分?）')


# 共用的 stages.SPEAKER 名字上限 14 字，抓不到原住民族名＋羅馬字的長標記
# （「高潞‧以用‧巴魕剌Kawlo．Iyun．Pacidal委員：」28 字、「伊萬‧納威Iwan Nawi女士：」16 字），
# 發言人就沿用上一位，網站上她 63 段有 60 段顯示成被提名人在講（2026-10-06 James 同意修）。
# 備援只收以委員／先生／女士／被提名人結尾的標記，避免把一般句子當成發言人。
R_LONG_SPEAKER = re.compile(r'^([^：:，、。？！「」]{2,40}?(?:委員|先生|女士|被提名人))[：:]')


def para_speaker_text(stream, i, state):
    """回傳 (speaker, text, time) 或 None（結構標記行，不是發言內容）。"""
    raw = stream[i]["raw"]
    if seg.st.STRUCT.match(raw):
        return None
    text = raw
    m = seg.st.SPEAKER.match(raw) or R_LONG_SPEAKER.match(raw)
    if m:
        nm = m.group(1)
        if nm == "主席":
            state["cur"] = "主席"
            text = raw[m.end():]
        elif seg.st.SPEAKER_OK.search(nm):
            state["cur"] = nm
            text = raw[m.end():]
    time_s = None
    tm = TIME_RE.match(text)
    if tm:
        h, mi = int(tm.group(1)), int(tm.group(2) or 0)
        time_s = f"{h:02d}:{mi:02d}"
        text = text[tm.end():]
    return state["cur"] or "", text.strip(), time_s


_COVERED = []      # convert() 期間，每個 section 實際涵蓋的 stream 範圍 [(a, b)]（fill_hidden 用）


def blocks_for(stream, a, b, warm_from=None):
    """warm_from：從這段開始先走一遍發言人狀態、不輸出。切在主席續行段（沒有「主席：」前綴，
    例：「請王委員惠美詢問」緊接在「主席：馬委員文君之詢問以書面提出」之後）時，
    才不會把那段的發言人弄丟。"""
    _COVERED.append((a, b))
    state = {"cur": None}
    for i in range(warm_from if warm_from is not None else a, a):
        para_speaker_text(stream, i, state)
    out = []
    for i in range(a, b + 1):
        r = para_speaker_text(stream, i, state)
        if r is None:
            continue
        spk, text, t = r
        if not text:
            continue
        out.append({"time": t, "speaker": spk, "text": text})
    return out


R_INTRO_END = re.compile(r'謝謝.{0,16}說明|進行.{0,16}詢[問答]|開始.{0,6}詢[問答]')


def intro_bounds(stream, a, b, first_sec=None):
    """逐位審查的被提名人說明：主席「請○○說明，時間N分鐘」到詢答開始前一段（2026-10-05 James 發現沒切出來）。
    詢答開始＝主席「謝謝…說明／現在進行…詢問／請○委員詢問」；第 10、11 屆再以第一個委員詢答 section 為上限。
    逐位審查 8 場、24 次說明都在 session 裡，只是原本沒有獨立成 section。"""
    i = next((k for k in range(a, b + 1) if stream[k]["chair"] and seg.R_START_PRIMARY.search(stream[k]["text"])), None)
    if i is None or (first_sec is not None and i >= first_sec):
        return None
    end = next((k - 1 for k in range(i + 1, b + 1) if stream[k]["chair"]
                and (R_INTRO_END.search(stream[k]["text"]) or seg.R_ROUND.search(stream[k]["text"]))), None)
    if first_sec is not None:
        end = min(end, first_sec - 1) if end is not None else first_sec - 1
    if end is None or end <= i:
        return None
    return i, end


# ── 逐位審查、沒有「段落：質詢：」marker 的場次：委員詢答按主席點名切（2026-10-05）────────
# 第 10、11 屆由 build_term10_11.py 用公報 marker 切；第 8、9 屆（和 10-5-1 這種補回來的場次）
# 沒有 marker，原本說明之後只剩一整段「委員詢答（未按委員切）」。改用主席點名切輪次。
#
# 主席點名。segment.R_ROUND 只吃「請黃委員國昌詢問」，實測 16 場另有三種寫法漏接：
#   「首先請親民黨黨團推派之代表周陳委員秀霞詢問」（點名前夾黨團代表）
#   「請高潞‧以用‧巴魕剌Kawlo．Iyun．Pacidal委員詢問」「請Kolas Yotaka委員詢問」（原住民族名、羅馬字）
#   「請陳宜民委員詢問」（名字在委員前面；R_ROUND 吃得到，但抽出來的名字是「陳宜民委員」）
R_ROUND_CALL = re.compile(
    r'請\s*(?:[^，。；：]{0,20}?代表\s*)?'
    r'(?P<m>[\u4e00-\u9fff]{1,3}委員[\u4e00-\u9fff‧・]{0,4}'
    r'|[A-Za-z\u4e00-\u9fff‧・．.\s]{2,40}?委員)\s*'
    r'(?:繼續)?(?:詢問|質詢|發言|提問)')
# 書面詢問：「李委員鴻鈞之詢問以書面提出，列入紀錄」「翁委員重鈞的詢問以書面提出」
# 「陳委員超明之書面詢問，列入紀錄」。不是口頭輪次，公報接著刊出書面詢問全文
# （例：9-2-1 黃瑞明場「李委員鴻鈞書面詢問：」後 26 段），不切開的話全文會算進上一位委員的輪次。
# 這種宣告不一定掛在主席名下（8-4-1 第 525 段「何委員欣純之書面詢問…」接在上一份書面詢問後面，
# 沒有「主席：」），所以不限主席段，但要求不是某位發言人的發言、而且有「列入」。
R_WRITTEN_Q = re.compile(
    r'(?P<m>[\u4e00-\u9fff]{1,3}委員[\u4e00-\u9fff‧・]{0,4}?|[^\s，。、：；（）]{2,40}?委員)'
    r'(?:之|的)(?:書面(?:詢問|質詢)|(?:詢問|質詢)(?:改)?以書面)')
# 點名前一段若是「現在請時代力量黨團推派之代表詢問，詢答時間共為30分鐘。」，那段屬於下一輪
R_PARTY_LEAD = re.compile(r'請[^，。]{1,10}黨團推派之?代表')
# 委員的發言人標記（只用來把「陳宜民委員」換回公報寫法「陳委員宜民」，以及驗證）
R_MEMBER_TAG = re.compile(r'^([^：:，、。？！]{1,40}?委員[^：:，、。？！會]{0,4})[：:]')
_HAN = re.compile(r'[\u4e00-\u9fff]')


def _member_label(stream, k, end, called):
    """點名抽出的名字不是「黃委員國昌」這種公報標準寫法時，換成輪內該委員的發言標記：
    「陳宜民委員」→「陳委員宜民」、「高潞‧以用‧巴魕剌委員」→「高潞‧以用‧巴魕剌Kawlo．Iyun．Pacidal委員」。
    只比漢字集合（相同才換），找不到就維持主席點名的寫法。"""
    if re.fullmatch(r'[\u4e00-\u9fff]{1,3}委員[\u4e00-\u9fff‧・]{1,4}', called):
        return called
    want = set(_HAN.findall(called.replace("委員", "")))
    for x in stream[k:end + 1]:
        m = R_MEMBER_TAG.match(x["raw"])
        if m and set(_HAN.findall(m.group(1).replace("委員", ""))) == want:
            return m.group(1)
    return called


def _nominee_tag(stream, s, e, canon):
    """同 segment.dominant_nominee／nominees_in 的「主談／偏向／N 位輪答」，但先把同一人的不同寫法併起來。
    實測 9-2-1 蔡烱燉場：公報發言標記「蔡燉先生」「蔡烱燉先生」混用（造字被吃掉），
    不併的話呂玉玲那輪會標成「主談 蔡燉（91%）」，其實 100% 都是蔡烱燉。"""
    vol, order = {}, []
    for x in stream[s:e + 1]:
        m = seg.R_NOMINEE_SPEAK.match(x["raw"])
        if m:
            n = seg.norm_name(m.group(1))
            n = canon.get(n, n)
            vol[n] = vol.get(n, 0) + len(x["raw"])
            if n not in order:
                order.append(n)
    if not vol:
        return ""
    main, v = max(vol.items(), key=lambda kv: kv[1])
    share = v / sum(vol.values()) * 100
    return (f"主談 {main}（{share:.0f}%）" if share >= 70 else
            f"偏向 {main}（{share:.0f}%）" if share >= 50 else f"{len(order)} 位輪答")


def member_marks(stream, qa, b):
    """[qa,b] 裡的委員輪次起點：[(段落, 'oral'|'written', 名字)]。"""
    marks = []
    for k in range(qa, b + 1):
        x = stream[k]
        if x["chair"]:
            m = R_ROUND_CALL.search(x["text"])
            if m:
                name = re.sub(r'\s+', ' ', m.group("m")).strip()
                # 同一位委員連續被點名（「請○委員繼續詢問」）是同一輪，不另切
                if marks and marks[-1][1] == "oral" and marks[-1][2] == name:
                    continue
                marks.append((k, "oral", name))
                continue
        if (x["chair"] or not seg.st.SPEAKER.match(x["raw"])) and "列入" in x["raw"]:
            m = R_WRITTEN_Q.search(x["raw"])
            if m:
                marks.append((k, "written", m.group("m")))
    return marks


def split_qa_by_member(stream, qa, b, nominee=None):
    """逐位審查的詢答段 [qa,b] 按委員切。回傳 section 清單（已是 meet-json 格式），
    段落連續、不重疊，合起來剛好是 [qa,b]；找不到任何點名就回傳 None（呼叫端維持整段）。
    - 第一個點名之前的段落（主席宣布進行詢問等）→「詢答開始」
    - 口頭輪次 →「委員詢答」，label 比照第 10、11 屆：○委員○○　主談 ○○（xx%）
    - 書面詢問 →「書面資料」（kind=attachment），不算口頭輪次；附件只收宣告＋全文，
      其後的主席段另成 ivod section（詢答段結尾 →「詢答結束」，否則「主席宣告」）"""
    marks = member_marks(stream, qa, b)
    if not marks:
        return None
    spoken = [seg.norm_name(m.group(1)) for x in stream[qa:b + 1]
              for m in [seg.R_NOMINEE_SPEAK.match(x["raw"])] if m]
    canon = seg.merge_names(spoken + ([nominee] if nominee else []))
    starts = []
    for k, kind, name in marks:
        s = k
        prev_start = starts[-1] if starts else qa - 1
        if (kind == "oral" and k - 1 > prev_start and stream[k - 1]["chair"]
                and R_PARTY_LEAD.search(stream[k - 1]["text"])):
            s = k - 1
        starts.append(s)
    out = []
    if starts[0] > qa:
        out.append({"type": "詢答開始", "label": "詢答開始", "kind": "ivod", "_sa": qa,
                    "para_start": stream[qa]["i"], "para_end": stream[starts[0] - 1]["i"],
                    "blocks": blocks_for(stream, qa, starts[0] - 1, qa)})
    for j, (k, kind, name) in enumerate(marks):
        s = starts[j]
        e = starts[j + 1] - 1 if j + 1 < len(marks) else b
        if kind == "written":
            # 書面全文只到宣告段之後第一個主席段的前一段：之後的主席段是口頭發言，不能藏進附件。
            # 實測 8-4-1 黃昭順是最後一個 mark，宣告＋全文（554–578）之後緊接主席
            # 「詢答部分到此為止」「審查完畢，作如下決議…散會」（579–580），舊版是逐字稿裡的主席 block。
            # 其餘 8 個書面 section 宣告段之後的內文段全是 chair=False，不受影響。
            c = next((q for q in range(k + 1, e + 1) if stream[q]["chair"]), None)
            we = c - 1 if c is not None else e
            _COVERED.append((s, we))
            out.append({"type": "書面資料", "label": f"{name}　書面詢問（列入紀錄，未口頭詢答）",
                        "kind": "attachment", "_sa": s,
                        "para_start": stream[s]["i"], "para_end": stream[we]["i"],
                        "gazette_text": "\n".join(x["raw"] for x in stream[s:we + 1])})
            if c is not None:
                # 落在詢答段結尾 →「詢答結束」；夾在書面詢問和下一次點名之間（16 位被提名人實測 0 次）→「主席宣告」
                t = "詢答結束" if j + 1 == len(marks) else "主席宣告"
                out.append({"type": t, "label": t, "kind": "ivod", "_sa": c,
                            "para_start": stream[c]["i"], "para_end": stream[e]["i"],
                            "blocks": blocks_for(stream, c, e, qa)})
            continue
        member = _member_label(stream, k, e, name)
        tag = _nominee_tag(stream, s, e, canon)
        out.append({"type": "委員詢答", "label": f"{member}　{tag}".strip(), "kind": "ivod", "_sa": s,
                    "para_start": stream[s]["i"], "para_end": stream[e]["i"],
                    "blocks": blocks_for(stream, s, e, qa)})
    return out


def other_sec_bounds(stream, o):
    if "_a" in o and "_b" in o:
        return o["_a"], o["_b"]
    lc = o.get("lcidc")
    idxs = [i for i, x in enumerate(stream) if x["lcidc"] == lc]
    return (idxs[0], idxs[-1]) if idxs else None


HIDDEN_NAME = "（區段外）"


def fill_hidden(stream, sessions_out, spans, overview_secs):
    """沒被任何 section 收到的段落補成看得到（2026-10-08；院會同日的同類修正見 segment_plenary.fill_unplaced）。
    16 場實測 384 段（1.46%）看不到：每場開頭的紀錄標頭（標題、時間、出席、秘書長報告人數、主席「現在開會」）、
    案由（總統咨）、被提名人說明與第一位委員之間的主席過場（「謝謝○○說明…首先請○委員詢問」）、
    休息／繼續開會／散會，以及臨時會全院委員會-10-1-1-2 幾乎整份（49 段只顯示 5 段）。
    量法：scripts/check_hidden.py personnel。

    做法：連續沒被涵蓋的 stream 段（頭尾修到 blocks_for 會輸出的段）：
      - 落在某個 session 的 [_a, _b] 內 → 該 session 加一個「（區段外）」ivod section（依 stream 位置插入）
      - 在第一個 session 之前（紀錄標頭、案由）→ 會議總覽的 attachment（不是發言，整段照登）
      - 其餘（session 之間的過場、最後的散會）→ 接在後一個 session 的最前面；沒有後一個就接在前一個最後
    原本的 section 內容與順序不變。"""
    covered = set()
    for a, b in _COVERED:
        covered |= set(range(a, b + 1))
    state = {"cur": None}
    emits = set()
    for i in range(len(stream)):
        rr = para_speaker_text(stream, i, state)
        if rr is not None and rr[1]:
            emits.add(i)
    runs, i = [], 0
    while i < len(stream):
        if i in covered:
            i += 1; continue
        j = i
        while j + 1 < len(stream) and j + 1 not in covered:
            j += 1
        em = [k for k in range(i, j + 1) if k in emits]
        if em:
            runs.append((em[0], em[-1]))
        i = j + 1
    first_a = min((a for a, _ in spans), default=None)
    for a, b in runs:
        head = next((stream[k]["text"].strip() for k in range(a, b + 1) if stream[k].get("chair") and stream[k]["text"].strip()),
                    stream[a]["raw"].strip())
        host = next((n for n, (sa, sb) in enumerate(spans) if sa <= a and b <= sb), None)
        if host is None and (first_a is None or b < first_a):
            at = next((k for k, x in enumerate(overview_secs) if x.get("_sa", -1) > a), len(overview_secs))
            overview_secs.insert(at, {"type": "（區段外）", "label": HIDDEN_NAME + head[:24], "kind": "attachment", "_sa": a,
                                      "para_start": a, "para_end": b, "filler": True,
                                      "gazette_text": "\n".join(x["raw"] for x in stream[a:b + 1])})
            continue
        if host is None:
            host = next((n for n, (sa, sb) in enumerate(spans) if sa > b), None)
            if host is None:
                host = max(range(len(spans)), key=lambda n: spans[n][1])
        sec = {"type": "（區段外）", "label": HIDDEN_NAME + head[:24], "kind": "ivod", "_sa": a, "filler": True,
               "para_start": stream[a]["i"], "para_end": stream[b]["i"], "blocks": blocks_for(stream, a, b)}
        secs = sessions_out[host]["sections"]
        at = next((k for k, x in enumerate(secs) if x.get("_sa", -1) > a), len(secs))
        secs.insert(at, sec)


def convert(meet_id):
    r = bt.build(meet_id)
    if r is None:
        return None
    _, stream, _ = seg.load_stream(meet_id)
    _COVERED.clear()

    sessions_out, spans = [], []
    for sess in r["sessions"]:
        a, b = sess["_a"], sess["_b"]
        secs = sess.get("sections", [])
        sec_out = []
        for sec in secs:
            sa, sb = sec["_a"], sec["_b"]
            sec_out.append({
                "type": sec["type"], "label": sec.get("label"), "kind": "ivod", "_sa": sa,
                "para_start": sec.get("para_start", sa), "para_end": sec.get("para_end", sb),
                "blocks": blocks_for(stream, sa, sb),
            })
        intro = None
        if not any(s["type"] == "被提名人說明" for s in secs):
            intro = intro_bounds(stream, a, b, secs[0]["_a"] if secs else None)
        if not sec_out and intro and r["審查型態"] == "逐位審查":
            # 沒有「段落：質詢：」marker 的逐位審查（第 8、9 屆、10-5-1）：說明之後按主席點名切委員輪次
            sec_out = split_qa_by_member(stream, intro[1] + 1, b, sess["label"]) or []
        if not sec_out:
            # 沒有 section 切分（例：找不到委員詢答 marker 也找不到主席點名），整段當一個 section
            # （有被提名人說明時，說明之後的部分才是這一段）
            ra = intro[1] + 1 if intro else a
            sec_out = [{
                "type": "被提名人審查", "label": "委員詢答（未按委員切）" if intro else sess["label"], "kind": "ivod", "_sa": ra,
                "para_start": stream[ra]["i"] if intro else sess["start"]["para"], "para_end": sess["end"]["para"],
                "blocks": blocks_for(stream, ra, b),
            }]
        if intro:
            ia, ib = intro
            sec_out.insert(0, {
                "type": "被提名人說明", "label": sess["label"], "kind": "ivod", "_sa": ia,
                "para_start": stream[ia]["i"], "para_end": stream[ib]["i"],
                "blocks": blocks_for(stream, ia, ib),
            })
        content = sess["label"]
        if sess.get("組別"):
            content = f"第{sess['組別']}組｜{content}（併審）"
        sessions_out.append({
            "date": sess.get("date"), "content": content, "clip_count": 0,
            "sections": sec_out,
        })
        spans.append((a, b))

    overview_secs = []
    for o in r["other_sections"]:
        bounds = other_sec_bounds(stream, o)
        if not bounds:
            continue
        oa, ob = bounds
        _COVERED.append((oa, ob))
        overview_secs.append({
            "type": o["type"], "label": o.get("label"), "kind": "attachment", "_sa": oa,
            "para_start": oa, "para_end": ob,
            "gazette_text": "\n".join(x["raw"] for x in stream[oa:ob + 1]),
        })
    fill_hidden(stream, sessions_out, spans, overview_secs)
    for x in sessions_out:
        for sec in x["sections"]:
            sec.pop("_sa", None)
    for sec in overview_secs:
        sec.pop("_sa", None)
    if overview_secs or not sessions_out:
        first_date = stream[0]["date"] if stream else None
        sessions_out.insert(0, {
            "date": first_date,
            "content": f"會議總覽（{r['審查型態']}，被提名人數 {r['被提名人數']}）"
                       + ("；" + "；".join(r["notes"]) if r["notes"] else ""),
            "clip_count": 0, "sections": overview_secs,
        })

    return {"meet_id": meet_id, "meet_type": "全院委員會", "clip_count": 0,
            "sessions": sessions_out}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", required=True)
    ap.add_argument("meet_id", nargs="*")
    a = ap.parse_args()
    targets = a.meet_id or ALL_MEETS
    outdir = os.path.expanduser(a.out)
    os.makedirs(outdir, exist_ok=True)
    for mid in targets:
        d = convert(mid)
        if d is None:
            print(f"{mid}: 無公報資料，略過")
            continue
        fn = os.path.join(outdir, mid + ".json")
        with open(fn, "w", encoding="utf-8") as f:
            json.dump(d, f, ensure_ascii=False)
        n_sess = len(d["sessions"])
        n_blk = sum(len(s2.get("blocks", [])) for s in d["sessions"] for s2 in s["sections"])
        print(f"{mid}: {n_sess} sessions, {n_blk} blocks → {fn}")


if __name__ == "__main__":
    main()
