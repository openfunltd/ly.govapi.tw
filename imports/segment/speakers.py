#!/usr/bin/env python3
"""
共用的發言人辨識：判斷公報逐字稿每一段是誰講的、是不是口說內容。

為什麼要統一（2026-09-19）：人事審查、公聽會、院會、黨團協商、預算審查五條研究線
各寫了一套規則，每一套都各自踩過坑、修過 bug：
  - 「鍾委員佳濱：」姓＋委員＋名（人事審查 09-17，380 段誤掛主席）
  - 「彭署長富源：」「劉副秘書長文仕：」職稱＋名（公聽會、黨團協商）
  - 「廖庭農主任：」「高淑貞教授：」「蘇偉碩醫師：」非立委的專家（公聽會）
  - 「主席（王委員正旭代）：」代理主席（公聽會）
  - 主席以本名發言「羅委員智強：」，名字列在開頭「主　　席　羅委員智強」（黨團協商）
  - 「○○書面資料：」「委員提案：」書面文件不是口說（公聽會、預算審查）
  - 「個人有6點建議：」「草案第十五條：」長句中剛好有冒號（公聽會）
任何一條只有一套規則處理，其他類型的會議就會踩到。這支把它們合在一起，
並用 scripts/audit_speakers_all.py 在五類會議上跟舊規則逐段對帳。

用法（當模組）：
  import speakers
  for p in speakers.walk(paragraphs):        # paragraphs: [str]（已攤平的段落）
      p["speaker"], p["kind"], p["text"]
  speakers.walk_blocks(blocks)               # parsed 的 blocks（[[str]]），多帶 block 序號

kind：
  say       口說（有發言人）
  written   書面資料／書面意見／書面質詢（不是口說）
  proposal  委員提案全文（預算審查）
  seg       公報段落標記「段落：…」
  struct    其他排版／結構欄位（時間、地點、案由、決議、出席名單…）
  image     掃描圖引用（INCLUDEPICTURE）
  none      開頭、還沒有任何發言人標記前的段落

指令列：
  python3 scripts/speakers.py <lcidc>        # 逐段列出判斷結果（人工抽查用）
"""
import re, sys

# ───────────────────────── 標記的形狀 ─────────────────────────
# 主席：含代理「主席（王委員正旭代）：」「主席(廖委員正井)：」
CHAIR = re.compile(r'^主席(?:（([^）]{0,15})）|\(([^)]{0,15})\))?[：:]')
# 一般標記：段首到第一個冒號。允許中間有間隔號（原住民名「Sufin‧Siluko」）與英文字母。
MARK = re.compile(r'^([^：:，。、？！「」『』；;（）()\n]{1,40}?)[：:]')
# 黨團協商等紀錄的開頭「主　　席　羅委員智強」（沒有冒號），列出的人之後以本名發言也算主席
CHAIR_LINE = re.compile(r'^主[\s　]+席[\s　]+(\S.*)$')

# 排版欄位（不是人）。比對前先去掉空白（「時　　間：」）。
STRUCT_FIELDS = {
    "時間", "地點", "主席", "出席委員", "列席委員", "請假委員", "委員出席", "委員列席", "列席官員", "列席人員",
    "出席人員", "專門委員", "主任秘書", "記錄", "紀錄", "記錄人", "決議", "決定", "說明", "案由", "主旨",
    "提案人", "連署人", "單位", "科目", "附件", "附記", "附錄", "備註", "附註", "日期",
    "協商主題", "協商結論", "協商主持人", "協商代表", "協商時間", "協商地點", "法案名稱",
    "主決議", "附帶決議", "提案委員", "本日議程", "議程", "程序委員會意見", "議事處意見",
    "表決結果名單", "主文", "理由", "結論", "討論提綱", "討論題綱", "討論議題", "提綱", "題綱",
    "發言學者專家", "學者專家", "出席學者專家", "會議紀錄", "摘要", "前言", "結語", "預算數",
    # 公文與表決紀錄欄位（院會附的函、表決結果名單）
    "受文者", "發文日期", "發文字號", "正本", "副本", "速別", "密等及解密條件或保密期限",
    "會議名稱", "表決時間", "表決結果", "表決議題", "表決型態", "贊成", "反對", "棄權",
    "資料來源", "註", "質詢日期", "質詢對象", "質詢題目", "受質詢機關", "統計期間",
}
SEG = re.compile(r'^段落[：:]')
IMAGE = re.compile(r'INCLUDEPICTURE|\.(?:jpe?g|png|gif)\b', re.I)
DOC_TITLE = re.compile(r'(?:等提案|提案條文|協商結論|審查結論|修正動議|決定事項|協商條文|審查會條文|院會條文|現行條文|通過條文)$')
ANON = {"在場人員", "在場人士", "與會人員", "現場人員", "現場人士", "旁聽民眾", "民眾"}
# 沒有冒號的文件標頭（黨團協商紀錄常見）：「立法院朝野黨團協商結論」
DOC_HEADER = re.compile(r'^(?:[一二三四五六七八九十]+、)?(?:立法院.{0,30}(?:會議紀錄|協商結論|協商紀錄)|黨團協商紀錄|朝野黨團協商結論)$')
# 「公聽會討論提綱：」「本院意見：」這類是文件標題，不是人
STRUCT_SUFFIX = re.compile(r'(?:提綱|題綱|議題|議程|說明|建議|結論|摘要|名單|附件|附錄)$')
WRITTEN_SUFFIX = re.compile(r'書面.{0,4}(?:資料|意見|報告|發言|質詢|詢問|說明|補充|答復|回應|問題)$|書面$')

# 名稱裡出現這些，就不是人名＋職稱，而是長句中的冒號（公聽會實測「個人有6點建議：」）
NOT_NAME = re.compile(r'[我你他們的了是就要把這請並或但您]|各位|本席|請問|請教|就教|提醒|告訴|呼籲|回答|提到|表示|指出|認為|還有|另外|剛才|剛剛|講得|講的|講了|非常|以下|如下|宣告|程序|'
                      r'[0-9０-９]|第.{1,5}[條項款目案]|草案|建議|意見$|問題|說$|如次|分述|略以|'
                      r'^(?:[一二三四五六七八九十]+|[壹貳參肆伍陸柒捌玖拾]+)(?:[、.．]|$)')
# 人的標記裡會出現的職稱／身分字（任一處出現即可：「鍾委員佳濱」「劉副秘書長文仕」「高淑貞教授」）
TITLE = re.compile(
    r'委員|院長|部長|次長|署長|局長|處長|司長|廳長|科長|組長|課長|股長|秘書|主任|主委|主計|審計長|'
    r'總裁|總長|總經理|經理|董事|理事|監事|會長|社長|校長|館長|所長|站長|隊長|總幹事|幹事|執行長|'
    r'召集人|召委|總召|議長|議員|參事|顧問|專員|視察|技正|技監|研究員|研究生|助理|'
    r'法官|檢察官|檢察長|律師|會計師|醫師|藥師|護理師|營養師|建築師|工程師|技師|老師|教師|'
    r'教授|講師|博士|學者|專家|代表|先生|女士|小姐|同學|學生|家長|記者|作家|牧師|法師|神父|'
    r'里長|村長|鄉長|鎮長|市長|縣長|首長|總統|政務委員|發言人|被提名人|主持人|負責人|創辦人|'
    r'(?<![專成擅延增助生])長|(?:發起|監察|負責|召集|代言|當事|創辦)人|技士|工程司|指揮官|司令|參謀|將軍|參議|編審|發起人|導演|製作|總監|主播|主編|總編|編輯|藝人|演員|醫生|護士|官')
# 立委：姓＋委員＋名（鍾委員佳濱、周陳委員秀霞、廖委員國棟Sufin‧Siluko）或 姓名＋委員
# 公報的立委標記一律是「姓＋委員＋名」；「名字＋委員」只有原住民委員（帶羅馬拼音）。
# 「李建成委員：」這種是公會／審議會的委員，不是立委（公聽會實測）。
LEGISLATOR = re.compile(r'^[一-鿿]{1,2}委員[一-鿿A-Za-z‧・ ]{1,20}$|^[一-鿿‧・．]{0,12}[A-Za-z][A-Za-z‧・．. ]*委員$')
NOT_LEGISLATOR = re.compile(r'主任委員|政務委員|召集委員|專門委員|副主任委員|委員長|主委')


def classify_mark(name):
    """段首「XXX：」的 XXX 是什麼。回傳 kind：chair／say／written／proposal／seg／struct／None（不是標記）。"""
    if name is None:
        return None
    compact = re.sub(r'[\s　]', '', name)
    bare = re.sub(r'^(?:[0-9０-９]+|[一二三四五六七八九十]+|[(（][0-9０-９一二三四五六七八九十]+[)）])[.、．]?', '', compact)
    if bare != compact and (bare in STRUCT_FIELDS or bare == "委員提案"):
        return "struct" if bare in STRUCT_FIELDS else "proposal"   # 「1.預算數：」「2.委員提案：」
    if compact in STRUCT_FIELDS:
        return "struct"
    if compact.startswith("段落"):
        return "seg"
    if compact == "項目":
        return "item"
    if compact == "委員提案":
        return "proposal"
    if WRITTEN_SUFFIX.search(compact) and len(compact) <= 60:
        return "written"
    if re.search(r'[\s　]', name) and not re.search(r'[A-Za-z]', name):
        return None                      # 中文名中間有空白 → 排版欄位（「主    席：」），不是人
    if len(compact) <= 30 and DOC_TITLE.search(compact) and not re.search(r'宣告|以下|如下|現在|作成|做成|宣讀', compact):
        return "struct"                  # 「江委員啟臣等19人提案條文：」「教保條例協商結論：」是宣讀的文件，不是人
    if compact in ANON:
        return "say"                     # 「在場人員：」公報對無法辨識發言者的寫法
    # 原住民名含羅馬拼音（「高潞‧以用‧巴魕剌Kawlo．Iyun．Pacidal委員」）可以很長
    if len(re.sub(r'[A-Za-z．.‧・]', '', compact)) > 20 or len(compact) > 45 or NOT_NAME.search(compact):
        return None
    if len(compact) <= 10 and STRUCT_SUFFIX.search(compact):
        return "struct"                  # 「公聽會討論提綱：」（句子型「我要提出下列建議：」在上一行已排除）
    if TITLE.search(compact):
        return "say"
    # 沒有職稱的短名字（「大龜：」）：由 walk() 判斷主席是不是剛點名過
    return "untitled" if re.fullmatch(r'[一-鿿‧・]{2,8}', compact) else None


def role_of(speaker):
    """粗分角色：chair／legislator／written／other。（官員 vs 專家不在這裡分，要看會議脈絡）"""
    if speaker == "主席":
        return "chair"
    if speaker and WRITTEN_SUFFIX.search(speaker):
        return "written"
    if speaker and LEGISLATOR.match(speaker) and not NOT_LEGISLATOR.search(speaker):
        return "legislator"
    return "other"


def walk(paragraphs, chair_alias=False):
    """逐段判斷。paragraphs 是 [str] 或 [(block_idx, str)]。

    chair_alias=True：開頭「主　　席　羅委員智強」列出的人，之後以本名「羅委員智強：」發言也算主席。
      只有黨團協商需要（主持人以本名發言）。委員會／公聽會不可開：開頭的「主席」是召委，
      代理主持時召委會以委員身分發言（公聽會實測「主席（王委員正旭代）」＋林委員月琴以委員發言）。
    """
    cur, cur_kind, aliases, out = None, "none", set(), []
    chair_as = None                      # 目前實際主持的人；開頭「主　　席　林委員月琴」列的人為預設
    last_chair = ""                      # 主席最近一段話，用來認「沒有職稱、但剛被點名」的人（「大龜：」）
    for i, item in enumerate(paragraphs):
        bi, p = item if isinstance(item, tuple) else (None, item)
        if not isinstance(p, str):
            continue
        raw, text = p, p
        m_line = CHAIR_LINE.match(p.strip())
        mc = CHAIR.match(p)
        m = None if mc else MARK.match(p)
        kind = classify_mark(m.group(1)) if m else None
        if kind == "untitled":
            # 沒有職稱的名字：只有在主席剛點名過（「請…大龜先生發言」）才算發言人
            kind = "say" if (cur == "主席" and re.search(r'請[^。]{0,40}' + re.escape(m.group(1)), last_chair)) else None
        if m and m.group(1) == "主持人":
            mc, m, kind = m, None, None       # 黨團協商：「主持人：」就是主席
        if mc:
            cur, cur_kind, text = "主席", "say", p[mc.end():]
            new = True
            # 「主席（王委員正旭代）：」→ 記下實際主持的人（2026-09-21 James：代理主席出狀況要能究責）
            # 公報只在換人那一刻標括號（[68]「主席（王委員正旭代）」→[75]「主席：」仍是王正旭，因為他接著點名
            # 林月琴發言→[97]「主席（林委員月琴）」召委回來），所以代理人一直算到下一個括號為止
            if mc.re is CHAIR and (mc.group(1) or mc.group(2)):
                chair_as = re.sub(r'代$', '', mc.group(1) or mc.group(2))
        elif DOC_HEADER.match(p.strip()):
            cur, cur_kind = "〔結構〕", "struct"
            out.append({"i": i, "block": bi, "raw": raw, "speaker": None, "kind": "struct", "text": p.strip(), "new": True})
            continue
        elif m_line and not m:
            # 開頭名單（沒有冒號）：記下主持人本名，本段是結構
            if chair_as is None:
                chair_as = re.split(r'[、，,（）()\s　]+', m_line.group(1).strip())[0] or None
            if chair_alias:
                aliases |= {x for x in re.split(r'[、，,（）()\s　]+', m_line.group(1)) if x and x != "代理"}
            cur, cur_kind = "〔結構〕", "struct"
            new = True
        elif kind == "say":
            name = m.group(1)
            cur = "主席" if (chair_alias and name in aliases) else name
            cur_kind, text, new = "say", p[m.end():], True
        elif kind in ("written", "proposal"):
            cur = m.group(1) if kind == "written" else "〔委員提案〕"
            cur_kind, text, new = kind, p[m.end():], True
        elif kind == "item":
            # 「項目：三、…」是轉檔器替條列項目加的標記。在官員報告裡是同一人繼續講（不可中斷，
            # 實測勞動部部長報告被切掉 25 段）；在主席名下多半是宣讀議程條列，視為結構。
            if cur == "主席" or cur_kind != "say":
                cur, cur_kind = "〔結構〕", "struct"
            out.append({"i": i, "block": bi, "raw": raw, "speaker": None if cur_kind != "say" else cur,
                        "kind": "struct" if cur_kind != "say" else "say", "text": p[m.end():].strip(), "new": True})
            continue
        elif kind in ("struct", "seg"):
            # 段落標記不換人（「段落：質詢：某委員」之後的內容仍待下一個發言人標記）；
            # 其他結構欄位會中斷口說（決議、案由、出席名單不是任何人講的）
            if kind == "struct":
                cur, cur_kind = "〔結構〕", "struct"
            out.append({"i": i, "block": bi, "raw": raw, "speaker": None, "kind": kind, "text": p.strip(), "new": True})
            continue
        else:
            new = False
        k = cur_kind
        if IMAGE.search(text):
            k = "image"
        if cur == "主席" and k == "say" and text.strip():
            last_chair = text
        out.append({"i": i, "block": bi, "raw": raw, "speaker": cur if cur_kind != "none" else None,
                    "kind": k, "text": text.strip(), "new": new,
                    "chair_as": chair_as if cur == "主席" else None})
    return out


def walk_blocks(blocks, chair_alias=False):
    return walk([(bi, p) for bi, b in enumerate(blocks or []) for p in (b or [])], chair_alias=chair_alias)


def chair_paras(blocks):
    """相容 stages.chair_paras()：回傳主席口說段落的文字（已去掉「主席：」）。"""
    return [p["text"] for p in walk_blocks(blocks) if p["speaker"] == "主席" and p["kind"] == "say" and p["text"]]


if __name__ == "__main__":
    import os
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    import stages
    if len(sys.argv) < 2:
        print(__doc__); sys.exit()
    alias = "--alias" in sys.argv
    for p in walk_blocks(stages.blocks(sys.argv[1]), chair_alias=alias):
        tag = f"【{p['speaker']}】" if p["new"] and p["speaker"] else ""
        print(f"[{p['i']:>4}|b{p['block']:>3}|{p['kind']:8s}] {tag}{p['text'][:100]}")
