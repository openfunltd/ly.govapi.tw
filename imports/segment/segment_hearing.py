#!/usr/bin/env python3
"""
公聽會切分（第一版，2026-09-19）。

已拍板（DECISIONS.md 2026-09-19）：
  section＝被主席點名的人。點名之後、下一次點名之前，這個人與其他人的往返
          （含部長回答、主席追問）都算這位的 section。
  session＝程序階段。**「階段」依什麼判定還沒定**，本檔同時實作三種，給人比較：
    A  主席宣告：只在主席明講換階段時切（「進行專家學者之發言」「第一輪發言都結束…請行政機關回應」「第二輪」「結論」）
    B  角色（立委也切）：連續被點名者的角色（機關／專家／立委）相同就併成一段，角色一換就切
    C  角色（立委不切）：同 B，但立委不形成自己的階段，併入當時的階段（立委在公聽會多半穿插在專家之間）

尚未拍板、本檔先採的預設（見 findings/public-hearing/NEEDS-HUMAN-CHECK.md A2-1～A2-4、A3）：
  - 主席在某人講完後的點評，歸前一位（直到主席點名下一位的那一段為止）
  - 同一人被拆成兩次點名 → 兩個 section
  - 代理主席一律視為主席
  - 書面資料不進 section，另列 attachments（只留標題與段落數；A3 待與 Ronny 討論）

發言人判斷用 scripts/speakers.py。

用法：
  python3 scripts/segment_hearing.py 公聽會-2020100720          # 單場，三種 session 切法並列
  python3 scripts/segment_hearing.py --sample                  # 40 場樣本，三種切法的 session 數統計
  python3 scripts/segment_hearing.py --html out.html [meet...]  # 輸出給人看的對照頁（三種判法並列）
  python3 scripts/segment_hearing.py --browser out.html        # 40 場樣本用 C 切，輸出和人事審查同款的預覽頁
  python3 scripts/segment_hearing.py --all --stats             # 全部委員會主辦且對得到公報的公聽會，C2 全量統計（2026-10-02）
  python3 scripts/segment_hearing.py --all --browser out.html  # 同上，輸出 Ronny 版閱覽器

2026-09-20 James：先採 C 看成果（DECISIONS.md）。
"""
import argparse, collections, html, importlib.util, json, os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
sys.path.insert(0, HERE)
import speakers as SP
import stages


def _hearing():
    spec = importlib.util.spec_from_file_location("hearing", os.path.join(HERE, "hearing.py"))
    m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
    return m


# ───────────────────────── 角色 ─────────────────────────
# 用完整機關名比對；單字「部／司」會誤中「部落客」「教學研究部」「司法改革國是會議」（2026-09-20 實測）
GOV_ORG = re.compile(r'(?:內政|外交|國防|財政|教育|法務|經濟|交通|勞動|衛生福利|衛福|文化|數位發展|數發|農業|環境|考選|銓敘|僑務|國軍退除役官兵輔導|海洋|原住民族|客家|經濟及能源)(?:部|委員會)'
                     r'|[一-鿿]{1,8}(?:署|局)(?!長)|行政院|司法院|考試院|監察院|總統府|中央銀行|檢察署|[一-鿿]{0,8}法院|主計總處|國發會|金管會|原民會|客委會|農委會|陸委會|NCC|通傳會|食安辦|消保處|市政府|縣政府|代表處|海基會|海峽交流基金會')
NON_GOV = re.compile(r'榮總|部落客|國是會議|模擬審判|大學|學院|醫院|協會|基金會|公會|學會|聯盟|事務所|研究院|研究所|中心|公司|工會|聯合會|促進會|總會|團體|律師|教授|醫師')
GOV_TITLE = re.compile(r'部長|次長|署長|局長|司長|處長|廳長|主任委員|主委|組長|科長|專門委員|參議|參事|技正|技監|視察|檢察官|簡任|代表')


def role(name, call_text=""):
    """legislator／official／expert。官員 vs 專家看主席點名時念的單位，其次看職稱。"""
    r = SP.role_of(name)
    if r in ("chair", "legislator"):
        return r
    if "前" in name:                  # 「張桂林前處長」「許玉秀前大法官」：卸任者以專家身分出席
        return "expert"
    ctx = call_text or ""
    if GOV_ORG.search(ctx) and not NON_GOV.search(ctx):
        return "official"
    if not ctx and GOV_TITLE.search(name):
        return "official"
    if GOV_TITLE.search(name) and not NON_GOV.search(ctx + name):
        return "official"
    return "expert"


ROLE_ZH = {"official": "機關", "expert": "專家", "legislator": "立委", "chair": "主席", "procedural": "程序"}


def name_keys(name):
    """主席點名時念的寫法常跟標記不同（標記「王委員正旭」、點名「王正旭委員」），產生幾個比對鍵。"""
    keys = {name[:2], name[:3]}
    m = re.match(r'^([一-鿿]{1,2})委員([一-鿿]{1,3})', name)
    if m:
        keys |= {m.group(1) + m.group(2), m.group(1) + "委員"}
    m = re.match(r'^([一-鿿]{2,4}?)(?:前|副|兼任|特聘|講座|助理|專案)?(?:教授|醫師|律師|理事長|董事長|執行長|秘書長|研究員|主任|院長|所長|校長)', name)
    if m:
        keys.add(m.group(1))
    # 姓＋職稱＋名（「吳律師佳霖」「林特聘教授志潔」「陳副廳長信旗」）→ 點名常念成「吳佳霖律師」「林志潔教授」
    tail = [mm for mm in SP.TITLE.finditer(name) if mm.start() > 0 and 1 <= len(name) - mm.end() <= 3]
    if tail:
        given = name[tail[-1].end():]
        if re.fullmatch(r'[一-鿿]{1,3}', given):
            keys |= {name[0] + given, name[:2] + given}
    # 姓名＋職稱（「許玉秀前大法官」「吳明彥秘書長」）→ 點名常只念「許前大法官」「吳秘書長」
    for k in (2, 3):
        rest = name[k:]
        if rest and SP.TITLE.match(re.sub(r'^(?:前|副|代理|兼任|特聘|講座|助理|專案)+', '', rest)):
            keys.add(name[0] + rest)
    return {k for k in keys if len(k) >= 2}


CALL = re.compile(r'請|邀請|有請')


# ───────────────────────── section 切分 ─────────────────────────
def paragraphs(docs):
    out = []
    for lc in sorted(docs):
        for p in SP.walk_blocks(stages.blocks(lc)):
            p["doc"] = lc
            out.append(p)
    return out


RESPONSE_CALL = re.compile(r'請[^。]{0,40}(?:說明|答復|答覆|回應|回答|補充)')


def sections(ps):
    """回傳 (sections, attachments)。section = {name, role, call, paras:[p...]}

    切點：主席點名某人（「請…○○…發言」），且這個人接著開始講話。
    不切的情況（都併在當前 section）：
      - 沒被點名就插話的人（立委與部長一來一回）
      - 立委講話中，主席請官員「說明／答復」回答這位立委（實測萊豬公聽會：林德福質詢時
        主席「請衛福部陳部長說明」，部長是在回答林德福，不是新的一輪）
        ——但如果主席同時宣告換階段（「…請行政機關回應」），就算新的一輪。
    主席講結論的那一段起，另開「（結論）」section。
    """
    secs, atts = [], []
    cur = {"name": "（開場）", "role": "chair", "call": "", "paras": []}
    secs.append(cur)
    chair_run = []                     # 最近一次非主席發言之後，主席連續講的段落
    for p in ps:
        if p["kind"] in ("written",):
            if p["new"]:
                atts.append({"name": p["speaker"], "doc": p["doc"], "i": p["i"], "paras": 0})
            if atts:
                atts[-1]["paras"] += 1
            continue
        if p["kind"] == "image" and atts and p["speaker"] == atts[-1]["name"]:
            atts[-1]["paras"] += 1
            continue
        spk = p["speaker"]
        if spk == "主席" and p["kind"] == "say":
            if phase_of_text(p["text"]) == "結論" and cur["name"] != "（結論）":
                cur = {"name": "（結論）", "role": "chair", "call": "", "paras": []}
                secs.append(cur)
            chair_run.append(p)
            cur["paras"].append(p)
            continue
        if p["kind"] == "say" and p["new"] and spk and spk != "主席" and cur["name"] != "（結論）":
            called = next((c for c in reversed(chair_run) if CALL.search(c["text"])), None)
            # 名字要出現在主席「請…」那一句裡才算點名（避免主席開場白恰好提到「陳委員」就被當成點名）
            hit = bool(called) and any(re.search(r'請[^。？！]{0,50}' + re.escape(k), called["text"]) for k in name_keys(spk))
            if p["text"].startswith(("（在席位上）", "(在席位上)")):
                hit = False            # 在席位上的插話，不是被點名上台
            if called and not hit and role(spk, called["text"]) == "official" and GOV_ORG.search(called["text"]) \
                    and re.search(r'請[^。]{0,30}(?:報告|說明|補充|發言)', called["text"]):
                hit = True             # 主席只點機關不點名（「接下來請勞動部代表報告」），由官員上台
            phase_ann = any(phase_of_text(c["text"]) for c in chair_run)
            answering = (hit and cur["role"] == "legislator" and RESPONSE_CALL.search(called["text"])
                         and role(spk, called["text"]) == "official" and not phase_ann)
            # 會議紀錄一開頭就是別人講話、主席還沒開口（少數文件缺開場）→ 直接開段；在席位上的插話除外
            opening = (cur["name"] == "（開場）" and not any(q["speaker"] == "主席" for q in cur["paras"])
                       and not p["text"].startswith(("（在席位上）", "(在席位上)")))
            if (hit and not answering and spk != cur["name"]) or opening:
                # 主席連續講的段落中，從「階段宣告或點名句」那一段起，移到新 section 開頭；
                # 之前的（例如「謝謝○○」的點評）留在前一位
                start = next((c for c in chair_run if phase_of_text(c["text"]) or c is called), None)
                moved = []
                if start is not None and start in cur["paras"]:
                    k = cur["paras"].index(start)
                    moved, cur["paras"] = cur["paras"][k:], cur["paras"][:k]
                cur = {"name": spk, "role": role(spk, called["text"] if hit else ""),
                       "call": called["text"] if hit else "", "paras": moved}
                secs.append(cur)
            chair_run = []
        cur["paras"].append(p)
    secs = [s for s in secs if s["paras"]]
    # 開場裡、第一位被正式點名的人之前，如果有非主席的人插話（在席位上抗議、要求程序發言），
    # 從第一句插話起另成「（程序發言）」段（2026-09-21 James：程序發言不能算進第一輪；自經區公聽會實測）
    if secs and secs[0]["name"] == "（開場）":
        ps0 = secs[0]["paras"]
        k = next((j for j, p in enumerate(ps0) if p["kind"] == "say" and p["speaker"] not in (None, "主席")), None)
        # 要整段插話裡真的有人講到「程序發言／程序問題／權宜問題」才算；只是問發言規則（2022-04-21 退撫公聽會
        # 李惠蘭理事長「是全部只給8分鐘嗎？」）留在開場（2026-09-21 James 指正）
        if k is not None and not any(PROCEDURAL.search(p["text"]) for p in ps0[k:]):
            k = None
        if k is not None:
            k0 = k - 1 if k > 0 and ps0[k - 1]["speaker"] == "主席" and PROCEDURAL.search(ps0[k - 1]["text"]) else k
            secs[0]["paras"], rest = ps0[:k0], ps0[k0:]
            secs.insert(1, {"name": "（程序發言）", "role": "procedural", "call": "", "paras": rest})
            secs = [s for s in secs if s["paras"]]
    return secs, atts


# ───────────────────────── session（三種判法） ─────────────────────────
PHASE_ANN = [
    ("機關報告", r'(?:首先|先)請[^。]{0,40}(?:部|署|會|院|局|處|機關)[^。]{0,30}報告|請[^。]{0,10}機關[^。]{0,10}報告'),
    ("專家發言", r'進行(?:專家學者|學者專家)[^。]{0,6}(?:之|的)?發言|開始[^。]{0,6}(?:專家學者|學者專家)[^。]{0,4}發言|(?:專家學者|學者專家)[^。]{0,6}發言，首先'),
    ("立委詢問", r'進行[^。]{0,10}委員[^。]{0,6}(?:的)?(?:詢問|發言)'),
    ("機關回應", r'(?:現在|先|接下來|首先)[^。再]{0,6}(?:請|由)[^。]{0,20}(?:行政機關|政府機關|政府官員|官員|部會|各機關|機關代表|行政部門|政府部門|相關機關)[^。]{0,60}(?:回應|答復|回復|說明)'),
    # 只收「現在就進行」的宣告；「若時間還充裕，我們會再進行第二輪發言」「稍後…可以進行第二輪」是預告（服貿公聽會實測）
    ("第二輪", r'(?:現在|接下來|繼續|開放)(?:[^。若如可稍再]){0,14}第二輪'),
    ("結論", r'(?:作|做)(?:成)?(?:以下|一個|如下)?(?:的)?(?:會議)?結論|結論如下'),
]


def phase_of_text(t):
    for lab, rx in PHASE_ANN:
        if re.search(rx, t):
            return lab
    return None


def sessions_A(secs):
    """主席宣告：看每個 section 開頭（被點名者開口之前）的主席段有沒有階段宣告，有就開新 session。"""
    out = [{"label": "開場", "secs": []}]
    for s in secs:
        lab = "結論" if s["name"] == "（結論）" else None
        for p in s["paras"]:
            if p["speaker"] != "主席":
                break
            lab = phase_of_text(p["text"]) or lab
        if lab and (lab != out[-1]["label"] or lab == "第二輪"):
            out.append({"label": lab, "secs": []})
        out[-1]["secs"].append(s)
    return [x for x in out if x["secs"]]


def sessions_role(secs, legis_split):
    out = []
    for s in secs:
        r = s["role"]
        if s["name"] == "（結論）":
            lab = "結論"
        elif r in ("chair", "procedural"):
            lab = "開場" if not out else out[-1]["label"]
        elif r == "legislator" and not legis_split:
            lab = out[-1]["label"] if out and out[-1]["label"] != "開場" else "專家發言"
        else:
            lab = {"official": "機關", "expert": "專家發言", "legislator": "立委"}[r]
        if not out or out[-1]["label"] != lab:
            out.append({"label": lab, "secs": []})
        out[-1]["secs"].append(s)
    # 機關段依位置命名：專家發言之前＝機關報告，之後＝機關回應
    seen_expert = False
    for x in out:
        if x["label"] == "專家發言":
            seen_expert = True
        if x["label"] == "機關":
            x["label"] = "機關回應" if seen_expert else "機關報告"
    # 最後一段主席講結論
    return out


def lead_phase(s):
    """section 開頭（被點名者開口前）主席宣告的階段。"""
    lab = None
    for p in s["paras"]:
        if p["speaker"] != "主席":
            break
        lab = phase_of_text(p["text"]) or lab
    return lab


PROCEDURAL = re.compile(r'程序發言|程序問題|權宜問題')


def is_procedural(s):
    """開場裡的插話段（role＝procedural）一律算程序發言。
    這一段是不是程序發言：主席點名時說「有程序發言」，或發言者開口前後（含在席位上喊的）提到程序發言／程序問題。
    2026-09-21 James：公聽會開始前常先有委員程序發言（服貿第三場葉津鈴），不能算第一輪發言。"""
    if s["role"] == "procedural":
        return True
    if s["role"] == "chair":
        return False
    if PROCEDURAL.search(s["call"] or ""):
        return True
    own = [p for p in s["paras"] if p["speaker"] == s["name"]][:1]
    lead = [p for p in s["paras"] if p["speaker"] == "主席"][:3]
    return any(PROCEDURAL.search(p["text"][:80]) for p in own + lead)


def sessions_C2(secs):
    """C 的修正版（2026-09-21 James 看萊豬公聽會後）：
    1. 主席宣告「第二輪」「請行政機關回應」「結論」一律切新段（萊豬 [271] 宣告第二輪後才點林奕華）
    2. 立委不併進機關段：接在機關段後面的立委，下一位是專家就歸到下一段，否則自成「委員發言」
    3. 段名用主席自己的說法：「第一輪發言」「第二輪發言」（段內有專家也有立委），不叫「專家發言」
    """
    out, pending, rnd = [], [], 1
    announced_rounds = any(lead_phase(s) == "第二輪" for s in secs)

    def speak_label():
        return f"第{'一二三四五'[min(rnd, 5) - 1]}輪發言" if announced_rounds else "專家學者與委員發言"

    def open_(kind, label):
        out.append({"kind": kind, "label": label, "secs": []})

    def flush_pending():
        if pending:
            open_("委員", "委員發言")
            out[-1]["secs"].extend(pending)
            pending.clear()

    for s in secs:
        ann = lead_phase(s)
        r = s["role"]
        cur = out[-1]["kind"] if out else None
        if s["name"] == "（結論）":
            flush_pending(); open_("結論", "結論"); out[-1]["secs"].append(s); continue
        if r == "chair":
            if not out:
                open_("開場", "開場")
            out[-1]["secs"].append(s); continue
        if is_procedural(s):
            flush_pending()
            if cur != "程序":
                open_("程序", "程序發言")
            out[-1]["secs"].append(s); continue
        if ann == "第二輪":
            flush_pending(); rnd += 1
            open_("發言", speak_label()); out[-1]["secs"].append(s); continue
        if r == "legislator":
            if cur == "發言":
                out[-1]["secs"].append(s)
            else:
                pending.append(s)
            continue
        kind = "機關" if r == "official" else "發言"
        if kind == "機關":
            flush_pending()
            cur = out[-1]["kind"] if out else None
            if cur != "機關" or ann == "機關回應":
                seen_speak = any(x["kind"] == "發言" for x in out)
                open_("機關", "機關回應" if seen_speak else "機關報告")
            out[-1]["secs"].append(s)
        else:
            if cur != "發言" or pending:
                open_("發言", speak_label())
                out[-1]["secs"].extend(pending); pending.clear()
            out[-1]["secs"].append(s)
    flush_pending()
    return out


HEARING_HEAD = re.compile(r'公聽會.{0,12}(?:會議)?紀錄')


def pick_docs(meet, docs):
    """同一天同題的公聽會，比對程式會把上午、下午兩場的公報都掛給同一筆 meet
    （服貿公聽會第三／四場、第五／六場、第七／八場；2026-09-21 實測 17 筆多份公報的 meet 中 6 筆如此）。
    若每份公報開頭各自是一場公聽會的紀錄標題，就用開會時間（幾點開始）挑出屬於這筆 meet 的那份。"""
    if len(docs) < 2:
        return docs, ""
    heads = []
    for d in docs:
        paras = [p for b in (stages.blocks(d) or []) for p in (b or []) if isinstance(p, str)]
        title = paras[0] if paras else ""
        tline = next((p for p in paras[:6] if re.match(r'^時[\s　]*間', p)), "")
        m = re.search(r'(?:星期.）|\)|）)?\s*(?:上午|下午)?\s*(\d{1,2})時', tline)
        hour = int(m.group(1)) if m else None
        if hour is not None and "下午" in tline and hour < 12:
            hour += 12
        heads.append((d, title, hour))
    if sum(1 for _, t, _ in heads if HEARING_HEAD.search(t)) < 2:
        return docs, ""                      # 一場公聽會跨多冊，照舊全部用
    mh = None
    for x in meet.get("會議資料") or []:
        mm = re.search(r'(\d{1,2}):\d{2}-', x.get("會議時間區間") or "")
        if mm:
            mh = int(mm.group(1)); break
    keep = [d for d, t, h in heads if mh is not None and h is not None and abs(h - mh) <= 1]
    if len(keep) > 1 and len({t for d, t, h in heads if d in keep}) == 1:
        keep = keep[:1]                      # 同一場刊了兩次（例：公聽會-2023051088）
    if keep:
        return keep, f"這筆 meet 對到 {len(docs)} 份各自獨立的公聽會紀錄，依開會時間只取 {', '.join(keep)}"
    return docs, "對到多場公聽會紀錄但無法依時間判斷，全部保留"


def segment(meet_id, H=None, link=None, meets=None):
    H = H or _hearing()
    if link is None:
        link, _ = H.link_gazette(H.hearing_meets(), H.gazette_agendas())
    docs = link.get(meet_id, {}).get("docs") or []
    meet = next((m for m in (meets or H.hearing_meets()) if m["會議代碼"] == meet_id), {})
    docs, note = pick_docs(meet, docs)
    ps = paragraphs(docs)
    secs, atts = sections(ps)
    return {"meet": meet_id, "docs": docs, "note": note, "sections": secs, "attachments": atts,
            "A": sessions_A(secs), "B": sessions_role(secs, True), "C": sessions_role(secs, False), "C2": sessions_C2(secs)}


def chair_log(r):
    """主持紀錄：[(主持人, 第一段 i, 最後一段 i, 是否代理)]。依主席段的 chair_as 依序壓縮。
    2026-09-21 James：代理主席要記下來，出狀況時代理人要負責。"""
    log = []
    for s in r["sections"]:
        for p in s["paras"]:
            if p["speaker"] != "主席" or not p.get("chair_as"):
                continue
            if log and log[-1][0] == p["chair_as"] and log[-1][3] == p["doc"]:
                log[-1][2] = p["i"]
            else:
                log.append([p["chair_as"], p["i"], p["i"], p["doc"]])
    first = log[0][0] if log else None
    return [(who, a, b, doc, who != first) for who, a, b, doc in log]


def to_meetjson(r, mode="C", title=""):
    """轉成 build_browser.py 讀的 meet-json：session＝階段，section＝被點名者，blocks＝逐段。"""
    date = ""
    sessions = []
    log = chair_log(r)
    first_chair = log[0][0] if log else None
    acting = [f"{who}代理（第 {a}–{b} 段）" for who, a, b, doc, is_act in log if is_act]
    chair_note = f"主持：{first_chair}" + ("；" + "、".join(acting) if acting else "") if first_chair else ""
    for x in r[mode]:
        secs = []
        for s in x["secs"]:
            label = s["name"] if s["name"].startswith("（") else f"{s['name']}"
            secs.append({"type": ROLE_ZH[s["role"]], "label": label, "kind": "ivod",
                         "blocks": [{"time": None, "speaker": chair_label(p, first_chair) if p["speaker"] == "主席" else (p["speaker"] or ""),
                                     "text": p["text"]} for p in s["paras"] if p["text"]]})
        head = (f"{title}\n{chair_note}\n" if not sessions else "") if (title or chair_note) else ""
        sessions.append({"date": x["label"], "content": f"{head}{x['label']}（{len(x['secs'])} 位）", "sections": secs})
    if r["attachments"]:
        sessions.append({"date": "書面資料", "content": "書面資料（不是發言，只列標題，內文不顯示；要不要另外處理還沒決定）",
                         "sections": [{"type": "書面資料", "label": f"{a['name']}（{a['paras']} 段）", "kind": "attachment",
                                       "gazette_text": ""} for a in r["attachments"]]})
    return {"meet_id": r["meet"], "meet_type": "公聽會", "title": title, "sessions": sessions}


def chair_label(p, first_chair):
    """主席段的顯示名：主持人不是開頭列的主席時，標「主席（王委員正旭代理）」。"""
    who = p.get("chair_as")
    return f"主席（{who}代理）" if who and first_chair and who != first_chair else "主席"


def outline(r, mode):
    lines = []
    for x in r[mode]:
        names = [f"{s['name']}〔{ROLE_ZH[s['role']]}〕" for s in x["secs"]]
        lines.append(f"  【{x['label']}】{len(x['secs'])} 位：" + "、".join(names[:8]) + ("…" if len(names) > 8 else ""))
    return lines


def sample_meets(H):
    ms = H.hearing_meets()
    link, _ = H.link_gazette(ms, H.gazette_agendas())
    return [m["會議代碼"] for t, n, m in H.sample(ms, link, H.legislators())], link


def all_meets(H):
    """全部委員會主辦（常設／全院／特種）且對得到公報的公聽會（2026-10-02 收尾：從 40 場抽樣擴大到全部），
    加上只在公報出現、meet API 沒有的公聽會（2026-10-07）。
    非委員會登記的 287 場、修憲委員會、全院委員會公聽會的範圍仍待 Ronny（NEEDS-HUMAN-CHECK）。"""
    ms = H.hearing_meets()
    link, gh = H.link_gazette(ms, H.gazette_agendas())
    leg = H.legislators()
    out = [m["會議代碼"] for m in ms if link[m["會議代碼"]]["docs"]
           and H.host_type(m, leg) in ("常設委員會", "全院委員會", "其他委員會（特種）")]
    # 公報有逐字稿、meet API 沒有這場的公聽會（2026-10-07 補）：50 則，第 8 屆 2013-09 以前 32、
    # 全院委員會人事同意權公聽會 9、其他 9（findings/coverage-gaps-2026-10-07.md）。
    # 代碼用「公報-<公報議程編號>」，標題與日期取公報目錄。
    for x in gazette_only(H, link, gh):
        out.append(x["id"])
        link[x["id"]] = x
    return sorted(out), link


def gazette_only(H, link, gh):
    """公聽會公報條目中，文件沒有被任何 meet 用到的。"""
    used = {d for v in link.values() for d in v.get("docs") or []}
    out = []
    for g in gh:
        docs = H.doc_ids_of(g)
        if not docs or set(docs) & used:
            continue
        # 公報目錄「案由含公聽會」會誤中一般委員會會議（1031901_00001：議程是請環保署報告它辦的土壤標準「公聽會」），
        # 所以要求文件開頭前 6 段（紀錄標題所在）有「公聽會」。2026-10-07 逐份看過 50 則標題，只排除這 1 則。
        head = [x for b in (stages.blocks(docs[0]) or []) for x in b if isinstance(x, str)][:6]
        if not any("公聽會" in x for x in head):
            continue
        out.append({"id": f"公報-{g['公報議程編號']}", "docs": docs, "how": "僅公報（meet API 沒有這場）",
                    "gazette": [g["公報議程編號"]], "title": re.sub(r'\s+', '', g.get("案由") or ""),
                    "dates": sorted(g.get("會議日期") or [])})
    return out


def stats(rows):
    """C2（已拍板）的全量統計：session／section 數、點名命中、警告。"""
    tot, warn = collections.Counter(), collections.Counter()
    ns, nsec = [], []
    for r in rows:
        if not r["sections"]:
            warn["0 個 section"] += 1
        ns.append(len(r["C2"])); nsec.append(len(r["sections"]))
        tot["sec"] += len(r["sections"]); tot["called"] += sum(1 for x in r["sections"] if x["call"])
        tot["att"] += len(r["attachments"])
        for x in r["C2"]:
            tot["session:" + x["label"]] += 1
        if r.get("note"):
            warn[str(r["note"])[:24]] += 1
    ns.sort(); nsec.sort()
    n = len(rows)
    print(f"{n} 場公聽會（C2）：session 中位數 {ns[n//2]}（{ns[0]}–{ns[-1]}）、section 中位數 {nsec[n//2]}（{nsec[0]}–{nsec[-1]}）")
    print(f"  section 共 {tot['sec']}，有主席點名的 {tot['called']}（{100*tot['called']/max(1,tot['sec']):.1f}%）；書面資料 {tot['att']} 份")
    print("  session 段名：", {k[8:]: v for k, v in tot.most_common() if k.startswith("session:")})
    print("  警告：", dict(warn.most_common(12)))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("meets", nargs="*")
    ap.add_argument("--sample", action="store_true")
    ap.add_argument("--all", action="store_true", help="全部委員會主辦且對得到公報的公聽會（取代 40 場抽樣）")
    ap.add_argument("--stats", action="store_true", help="印 C2 的全量統計")
    ap.add_argument("--html")
    ap.add_argument("--browser")
    a = ap.parse_args()
    H = _hearing()
    sample, link = all_meets(H) if a.all else sample_meets(H)
    targets = a.meets or sample
    rows = []
    for mid in targets:
        r = segment(mid, H, link)
        rows.append(r)
        if not a.sample and not a.html and not a.browser and not a.stats:
            print(f"== {mid}  section {len(r['sections'])}、書面資料 {len(r['attachments'])} 份")
            for mode, lab in (("A", "主席宣告"), ("B", "角色（立委也切）"), ("C", "角色（立委不切）")):
                print(f" [{mode}] {lab}：{len(r[mode])} 個 session")
                print("\n".join(outline(r, mode)))
    if a.sample:
        print(f"{'meet':28s} {'sec':>4s} {'A':>3s} {'B':>3s} {'C':>3s}  點名命中")
        tot = collections.Counter()
        for r in rows:
            called = sum(1 for s in r["sections"] if s["call"])
            print(f"{r['meet']:28s} {len(r['sections']):4d} {len(r['A']):3d} {len(r['B']):3d} {len(r['C']):3d}  {called}/{len(r['sections'])}")
            tot["sec"] += len(r["sections"]); tot["called"] += called
            for m in "ABC":
                tot[m] += len(r[m])
        n = len(rows)
        print(f"\n{n} 場：section 共 {tot['sec']}（有點名 {tot['called']}）；session 平均 A {tot['A']/n:.1f}、B {tot['B']/n:.1f}、C {tot['C']/n:.1f}")
    if a.stats:
        stats(rows)
    if a.html:
        write_html(a.html, rows)
    if a.browser:
        spec = importlib.util.spec_from_file_location("build_browser", os.path.join(HERE, "build_browser.py"))
        bb = importlib.util.module_from_spec(spec); spec.loader.exec_module(bb)
        titles = {m["會議代碼"]: m.get("會議標題") or "" for m in H.hearing_meets()}
        data = {r["meet"]: to_meetjson(r, "C2", titles.get(r["meet"], "")) for r in rows}
        bb.write_html(data, a.browser, "公聽會切分（C2）")


def write_html(path, rows):
    e = html.escape
    h = ["<!DOCTYPE html><html lang='zh-Hant'><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>"
         "<title>公聽會切分試作</title><style>body{font:15px/1.7 'PingFang TC',sans-serif;max-width:1100px;margin:auto;padding:16px;background:#f7f7f9;color:#1b1f24}"
         ".m{background:#fff;border:1px solid #ddd;border-radius:8px;padding:12px 16px;margin:16px 0}"
         ".cols{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.col{border-left:3px solid #ccc;padding-left:8px}"
         ".s{margin:6px 0}.lab{font-weight:600}.r{font-size:12px;color:#666}details{margin:2px 0}summary{cursor:pointer}"
         ".q{font-size:13px;color:#444;margin-left:12px}@media(max-width:800px){.cols{grid-template-columns:1fr}}</style>"
         "<h1>公聽會切分試作：三種「階段」判法並列</h1>"
         "<p>section 規則已拍板（被點名者＋之後的往返）。session 的「階段」有三種判法，同一場並列給你比較：<br>"
         "<b>A 主席宣告</b>：只在主席明講換階段時切。<b>B 角色（立委也切）</b>：被點名者角色一換就切。<b>C 角色（立委不切）</b>：立委併入當時的階段。</p>"]
    for r in rows:
        h.append(f"<div class='m'><h2>{e(r['meet'])}</h2><p class='r'>section {len(r['sections'])} 個；書面資料 {len(r['attachments'])} 份（不進 section）</p><div class='cols'>")
        for mode, lab in (("A", "A 主席宣告"), ("B", "B 角色（立委也切）"), ("C", "C 角色（立委不切）")):
            h.append(f"<div class='col'><div class='lab'>{lab}：{len(r[mode])} 個 session</div>")
            for x in r[mode]:
                h.append(f"<details class='s'><summary><b>{e(x['label'])}</b>（{len(x['secs'])} 位）</summary>")
                for s in x["secs"]:
                    first = next((p['text'] for p in s['paras'] if p['speaker'] == s['name']), "")
                    h.append(f"<div class='q'>{e(s['name'])}〔{ROLE_ZH[s['role']]}〕{len(s['paras'])} 段<br><span class='r'>{e(s['call'][:60])}</span></div>")
                h.append("</details>")
            h.append("</div>")
        h.append("</div></div>")
    open(path, "w", encoding="utf-8").write("".join(h))
    print(f"寫出 {path}")


if __name__ == "__main__":
    main()
