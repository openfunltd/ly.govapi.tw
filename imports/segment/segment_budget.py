#!/usr/bin/env python3
"""
委員會預算審查切分（第一版，2026-10-07，粗顆粒）。

母體：`會議種類=委員會／聯席會議`、至少一天的會議事由含「預算／決算」且判得出型的會次
（分型規則＝ findings/committee-budget/scripts/budget.py 的 inventory_rows()）。
文件：meet API 的 `公報發言紀錄.agenda_lcidc_ids`；API 沒掛公報的天另用
findings/committee-budget/recovered-docs.json（依會議日期反查公報目錄、逐份比對過紀錄標題與日期）。

切法（依 DECISIONS「session＝在處理什麼議題、不確定時退回粗顆粒」；A1／A2 尚待 James 拍板，本檔先採建議）：
  session＝一天裡的程序大段，以主席宣告為界：
    報告事項（開會、議事錄、解凍案備查）→ 機關報告 → 委員詢答 → 詢答後處理與決定 → 預算提案處理／討論事項（法案）
    （2026-10-29 下午 James：凍結案處理不另成 session，「不分了」——院會交付的解凍案也是預算提案處理，解凍只做 section 標籤）
    （2026-10-29 James 拍板 Q1–Q5、P1–P3，見 DECISIONS.md：session 維持一天的處理階段、機關做標籤；群組內單案唱名不切；
      「詢答結束後」改名「詢答後處理與決定」；解凍書面報告案備查句留在報告事項、口頭逐案的「預算解凍案處理」才開凍結案處理；
      處理中途的「提案委員預算減列、凍結案部分」不切凍結案處理）
  section：
    機關報告   ＝ 一位首長一段（主席「請○○報告」之後的發言人）
    委員詢答   ＝ 一位委員一輪（公報「段落：質詢：○委員」標記；沒有標記時用主席「請○委員發言／質詢」），
                  委員與官員的往返、主席插話都算這位的
    預算提案處理＝ 主席的案號宣告（「進行第6案到第16案」「第41案。」「第2目」）一段；
                  主席宣告換機關／基金（句子裡含會議事由列的機關或基金名稱）只做 section 標籤，不另切一層
                  宣告前的「宣讀預算數及委員提案」自成一段
    （凍結案處理已併入預算提案處理，2026-10-29）
    報告事項、討論事項（法案）＝ 不細切
  不進 section、只計數：書面提案（含「委員提案：」名下與案由／說明／提案人格式）、掃描圖（INCLUDEPICTURE）、
  議事錄（上次會議紀錄全文）、書面質詢。

用法：
  python3 scripts/segment_budget.py 委員會-11-5-23-20            # 單場大綱
  python3 scripts/segment_budget.py --check --term 11            # 全屆統計（文件沒快取的會先抓）
  python3 scripts/segment_budget.py --json out.json 委員會-11-5-23-20 …   # 輸出 meet-json
"""
import argparse, collections, json, os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
sys.path.insert(0, HERE)
import speakers as SP
import stages
import budget as B

RECOVERED = os.path.join(HERE, "data", "recovered-docs.json")
NUM = r"[0-9０-９一二三四五六七八九十百零〇]+(?:[-－之][0-9０-９一二三四五六七八九十]+)?"   # 「第60-1案」「第3之1案」

# ───────────────────────── 主席宣告（session 邊界） ─────────────────────────
REPORT_ITEMS = re.compile(r'(?:現在|接著|接下來)?(?:處理|進行)報告事項')
AGENDA_RECITAL = re.compile(r'^(?:報告委員會[，,])?\s*(?:本日|今日|今天)(?:會議)?(?:的)?議程(?:係|為|是|所列|如下|有)')   # 主席唸議程的句子不是宣告
REPORT_START = re.compile(r'(?:現在|接下來|首先|先)?(?:開始)?(?:進行|請)[^。]{0,30}?(?:主委|主任委員|部長|次長|署長|局長|首長|機關|單位|董事長|所長|處長|總裁|院長|秘書長|代表)[^。]{0,20}?報告'
                          r'|(?:現在|開始|接下來)(?:開始)?進行(?:機關|業務|預算|列席機關)?報告|請列席機關報告')
QA_START = re.compile(r'(?:現在|接下來)?(?:開始)?進行(?:綜合)?詢答|開始詢答|詢答時間[，,]?(?:出席|每位)|現在(?:開始)?進行質詢|請登記第[1１一]位')
PROC_START = re.compile(r'(?:現在|接下來|接著|繼續)?(?:開始)?(?:進行|處理)[^。]{0,60}?(?:之處理|的處理|提案之處理|預算(?:案)?之?處理|逐案處理|進行協商|處理預算提案)'
                        r'|現在(?:開始)?進行協商|現在進行處理|今[天日](?:要)?依序進行審查|進行(?:預算)?提案之?處理|開始處理(?:委員)?提案'
                        r'|宣讀[^。]{0,24}?(?:預算數|委員(?:的)?提案|審查項目)|預算(?:的|之)?協商|(?:開始|進行|進入)[^。]{0,30}?協商|逐案(?:審查|處理|協商)')
# 預算處理的開場句（2026-10-19 補）：第 11 屆 21 天主席沒說「處理」就直接審案（續會日最常見），案號宣告整天落在報告事項。
# 只在還沒進預算處理時用、優先序最低（處理中途說「從第1案開始。第1案改成凍結案」不該再切一段）。
PROC_OPEN = re.compile(r'逐案討論|每案(?:先)?(?:請)?[^。]{0,4}?(?:行政(?:單位|機關)|機關)[^。]{0,6}?說明'             # 「每案先請行政單位說明，接著請委員表示意見」
                       r'|從(?:委員)?(?:提案)?第\s*' + NUM + r'\s*案[^。]{0,8}?(?:開始|繼續|起)'          # 「從委員提案第251案開始」「從第1案、第2案開始」
                       r'|(?:上次|上一次|昨天|先前|之前)[^。]{0,16}?(?:審查|處理|審)到[^。]{0,16}?第\s*' + NUM + r'\s*案'   # 「先前處理到第351案」
                       r'|(?:繼續審查|續審)[^。，,；]{0,20}?預算提案'                                    # 「繼續審查勞動部主管公務預算提案」（「另定期繼續審查，預算提案於…截止」不算）
                       r'|(?:處理|審查|從)[^。]{0,12}?(?:歲入|歲出)'                                        # 「先處理教育部的歲入」「接著處理歲出的提案」「歲入科目以項為處理單位」
                       r'|(?:委員)?(?:無|沒有)(?:相關)?提案[，,]?(?:預算)?(?:均)?照列'                         # 「委員無相關提案，預算照列」
                       r'|處理第\s*' + NUM + r'\s*目|協商桌(?:上)?處理提案')                                   # 「現在要處理第7目」「請移至協商桌處理提案」
READ_CMD = B.READ_CMD
HEADER = re.compile(r'^時[\s　]*間')
FREEZE = re.compile(r'(?:解凍|凍結)案')
DISCUSS = re.compile(r'(?:現在|接下來|接著)?(?:進行|處理)(?:本日(?:會議)?)?討論事項')
FREEZE_START = re.compile(r'(?:現在|接下來|接著|再來)[^。]{0,12}?處理[^。]{0,40}?(?:解凍|凍結)案')
FREEZE_PROC = re.compile(r'^(?:好[，,]?)?(?:現在|接下來|接著|再來)?(?:開始)?進行(?![^。，,]*(?:審查|提案))[^。，,]{0,16}?(?:預算)?解凍案(?:之|的)?處理')   # 「現在進行議程所列預算解凍案處理，處理前…」只看第一子句（P1，11-1-36-21 05-09）；「進行預算案審查及解凍案處理」是提案處理日，不算
FREEZE_RPT = re.compile(r'(?:現在|接下來|接著|再來|我們)[^。]{0,12}?處理[^。]{0,24}?解凍(?:專案|書面)?報告[0-9０-９]{0,3}案')   # 2026-10-29 下午 James：詢答後口頭逐案處理解凍專案報告案的天（11-3-22-10 等 8 天）切成預算提案處理
FREEZE_REAL = re.compile(r'解凍|解除凍結|院會交付|預算凍結案|凍結案(?:的)?書面報告')   # 院會交付的凍結案（要解凍的）；相對於委員提的凍結提案「第N案凍結案」「提案委員…減列、凍結案」
QA_END = re.compile(r'(?:^|[。；，\s])(?:今天的|本日|現在)?(?:詢答|質詢)(?:到此|部分到此)|詢答(?:完畢|結束)(?:[。，；]|$)|(?:質詢|登記(?:發言|質詢)?(?:之)?)委員(?:均)?已(?:全部)?發言完畢')
QA_NOT_END = re.compile(r'之後|以後|截止|請[^。]{0,12}委員[^。]{0,6}(?:質詢|發言)')   # 「詢答完畢之後再處理」是預告，不是結束
MOTION = re.compile(r'(?:現在|接下來|接著|我們)[^。]{0,8}?(?:處理|進行)臨時提案')
CONCLUDE = re.compile(r'協商結論|授權(?:主席及|主席|由)?議事人員|今天(?:的)?(?:會議|議程)(?:進行)?到此|本日(?:會議|議程)[^。]{0,10}(?:到此|處理完畢|審查完畢)|散會')
# section 邊界
CASE = re.compile(r'(?:進行|處理|接下來|再來|現在|先看|看一下|進入|來看|我們看|看到|協商|繼續)[^。]{0,14}?(第\s*' + NUM + r'\s*案(?:\s*[至到]\s*第?\s*' + NUM + r'\s*案)?)')
_BARE_HEAD = r'(?:^|(?<=[。；？！]))\s*(?:再來|接下來|好，?|那麼?，?|下一案|再來是|接著是|然後)?(第\s*[（(]?' + NUM + r'[）)]?\s*案(?:\s*[至到]\s*第?\s*' + NUM + r'\s*案)?)'
CASE_BARE = re.compile(_BARE_HEAD + r'(?:[。，、]|$|[^。]{0,12}?(?:同意動支|有無異議|有沒有異議|有沒有問題|有無問題|照案通過|有沒有意見|同意解凍|准予動支))')
CASE_TAIL = re.compile(r'(?<=[，,])\s*(第\s*' + NUM + r'\s*案)[。]?\s*$')   # 「主決議的部分，第118案。」段末只剩案號   # 「第41案。」「第(一)案，就同意動支有無異議？」（2026-10-29 起也認句首，不只段首）
CASE_DECIDE = re.compile(_BARE_HEAD + r'[^。]{0,12}?(?:是第\s*' + NUM + r'\s*目|在同一目|一起處理|一併處理|併案處理|併|合併|[跟與及和]第|是|文字修正|提案委員|不在場|請|部分|解凍|改主決議|改為主決議|改成主決議|凍結|減列|刪減|刪除|照列|照案|修正通過|不予處理|保留|撤案|通過|改為|改成|不處理|照案辦理)')   # 2026-10-29 主席只講裁示不宣告：「第4案修正通過」「第27案就改為主決議」「第59案至第64案是第8目」（1b 抽讀 10-4-15-25、8-6-23-6）；James 10-29 看網站補「第7案併第8案處理」「第7案跟第8案…」；只在案號往前走時才開段
CASE_LIST = re.compile(r'第\s*' + NUM + r'\s*案(?:\s*(?:[、，,及和與跟]|併)\s*第\s*' + NUM + r'\s*案)+')
MERGE_MAIN = re.compile(r'以\s*(第\s*' + NUM + r'\s*案)\s*為主')   # 「第8案、第9案、第15案併案…以第9案為主」（James 10-29：併案的要寫在一起）
MERGE_WORD = re.compile(r'併案|併在一起|一併處理|合併處理|併到|併入|併凍|以第\s*' + NUM + r'\s*案\s*為主')   # 「第5案、第6案、第7案先保留」「第173案、第174案及第175案」一句處理好幾案：標籤列全部
ITEM_BARE = re.compile(r'(?:^|(?<=[。；？！]))\s*(第\s*' + NUM + r'\s*目(?:\s*(?:的)?「[^」]{1,20}」)?)(?:[^。]{0,16}?部分|[，,：:])')   # 「第3目農業管理部分，原列…從第28案到第59案我們一起處理」
CASE_BY = re.compile(r'[一-鿿]{2,4}委員(?:等|們)?(?:所提|提出|提|的)?(?:的)?(第\s*' + NUM + r'\s*案(?:\s*[、及和與跟]\s*第\s*' + NUM + r'\s*案)*)[^。]{0,12}?(?:改(?:成|為)?主決議|撤案|撤回|照案通過|照列|凍結|減列|刪減|保留|通過|不予處理|同意|修正)')   # 「陳靜敏委員的第12案也撤案」「陳超明委員所提第71案改成主決議」（跳號普查，James 10-29 繼續拉）
ITEM_RANGE = re.compile(r'(第\s*' + NUM + r'\s*案\s*[至到]\s*第?\s*' + NUM + r'\s*案)')   # 目宣告句裡的「相關提案為第10案至第42案」
SUMMARY_WORD = re.compile(r'改(?:為|成)?主決議|照案通過|保留|凍結|減列|撤案|照列|通過|不予處理')
CASE_ASK = re.compile(r'(?:請問各位[，,]?)?對(?:第\s*' + NUM + r'\s*目)?(第\s*' + NUM + r'\s*案(?:\s*[至到]\s*第?\s*' + NUM + r'\s*案)?)[，,]?(?:有無|有沒有)(?:意見|問題|異議)')   # 「請問各位，對第2目第158案至第164案，有無意見？」（9-8-19-7）
ITEM = re.compile(r'(?:進行|處理|接下來|再來|現在|來看|看一下|進入|繼續)[^。]{0,14}?(第\s*' + NUM + r'\s*目(?:\s*(?:的)?「[^」]{1,20}」)?)')   # 2026-10-29 補「繼續討論第7目」（9-8-19-7）
ORG_END = re.compile(r'(?:審查|處理|協商)(?:完畢|完竣)')
ORG_START = re.compile(r'(?:現在|先|接下來|繼續|再來|接著|我們|開始)[^。]{0,14}?(?:協商|處理|審查|進行|來看|進入|看)[^。]{0,60}?(?:單位預算|基金|預算|公司|部分|收支)')
DECIDE = [("解凍", r'解凍案|解除凍結|准予動支|(?<!不)同意動支|(?<!不)同意解凍|准予解凍|(?<![後再得])(?:予以|同意|准予)解凍'), ("凍結", r'凍結'),   # 「凍結5%，書面報告後解凍」是凍結提案的條件，不是解凍裁示（James 10-29） ("減列", r'減列|刪減|刪除'), ("改主決議", r'改(?:列|為|成)?主決議|主決議'), ("照列", r'照列|照案通過|照原列|維持原列'),
          ("修正通過", r'修正通過'), ("撤案", r'撤案|撤回'), ("保留送院會", r'保留[^。]{0,8}(?:送|提)?(?:院會|黨團)?協商|保留[^。]{0,4}送院會|送(?:交)?院會(?:處理|協商)'),
          ("不予處理", r'不予處理|不處理'), ("備查", r'備查')]
CALL_LEG = re.compile(r'請(?:登記)?(?:第\s*' + NUM + r'\s*位)?[^。，]{0,8}?([一-鿿]{1,2}委員[一-鿿‧・ ]{1,10}|[一-鿿]{2,4}委員|[一-鿿]{2,4}召委)[^。]{0,6}(?:發言|質詢|詢問)')
SEG_QA = re.compile(r'^段落[：:]質詢[：:]\s*(.{2,14}?)(?:[：:]|\s*$)')
ORG_RE = [re.compile(r'(?:關於|有關)([^，。；（）()]{2,120}?)(?:及所屬|及其所屬)?(?:單位預算|預算案|預算書|預算|收支|部分|歲入|歲出)'),   # 2026-10-29 60→120：財政「主計總處暨審計部、審計部臺北市審計處、…審計部高雄市審計處部分」整串超過 60 字，之前一個機關都抓不到
          re.compile(r'([^，。；（）()、及暨：]{2,24}?)(?:及所屬|及其所屬)?(?:單位預算|預算案)'),     # 「…第2目暨國家發展委員會及所屬單位預算」
          re.compile(r'([^，。；：、（）()─—－]{2,30}?基金)(?:分預算|預算)?'),
          re.compile(r'([一-鿿]{2,20}?(?:股份有限公司|有限公司|公司))')]
ORG_SUFFIX = re.compile(r'(?:部|委員會|總處|總署|署|局|處|院|所|館|中心|基金|基金會|公司|大學|學校|總隊|銀行|會)$')
ORG_SPLIT = re.compile(r'函送|一般性補助款|[、及暨]|主管|所屬|關於|有關|分預算|預算書|預算')
ORG_ALIAS = {"國家通訊傳播委員會": ["通傳會", "NCC"], "行政院公共工程委員會": ["工程會"], "公共工程委員會": ["工程會"], "中央氣象署": ["氣象署"],
             "國軍退除役官兵輔導委員會": ["退輔會"], "衛生福利部": ["衛福部"], "原住民族委員會": ["原民會"], "客家委員會": ["客委會"],
             "行政院農業委員會": ["農委會"], "國家發展委員會": ["國發會"], "金融監督管理委員會": ["金管會"], "大陸委員會": ["陸委會"],
             "僑務委員會": ["僑委會"], "行政院環境保護署": ["環保署"], "公平交易委員會": ["公平會"], "海洋委員會": ["海委會"],
             "中央選舉委員會": ["中選會"], "國家科學及技術委員會": ["國科會"], "行政院主計總處": ["主計總處", "主計處"],
             "行政院人事行政總處": ["人事總處"], "核能安全委員會": ["核安會"], "行政院原子能委員會": ["原能會"], "國家運輸安全調查委員會": ["運安會"],
             "經濟部水利署": ["水利署"], "行政院消費者保護處": ["消保處"], "國家安全局": ["國安局"], "臺灣鐵路管理局": ["臺鐵局", "台鐵局", "臺鐵"],
             "國營臺灣鐵路股份有限公司": ["臺鐵公司", "台鐵公司", "臺鐵"], "中央健康保險署": ["健保署"], "勞動基金運用局": ["勞金局"],
             "臺灣港務股份有限公司": ["港務公司"], "桃園國際機場股份有限公司": ["機場公司", "桃機公司"], "高速公路局": ["高公局"],
             "交通部觀光署": ["觀光署"], "交通部公路局": ["公路局"], "交通部航港局": ["航港局"], "交通部鐵道局": ["鐵道局"], "交通部運輸研究所": ["運研所"]}
PARENT = re.compile(r'^(?:行政院|交通部|經濟部|內政部|財政部|教育部|法務部|外交部|國防部|勞動部|文化部|衛生福利部|數位發展部|農業部|環境部|'
                    r'考試院|監察院|司法院|總統府|國家發展委員會|國軍退除役官兵輔導委員會)(?:主管|所屬)?')
PROP_LINE = re.compile(r'^(?:案由|提案人|連署人|提案委員|單位|科目)[：:]'); PROP_WORD = B.PROP_WORD; MONEY = B.MONEY
CN = {c: i for i, c in enumerate("零一二三四五六七八九")}; CN["〇"] = 0


def num(s):
    """「十六」「１２」「12」→ int"""
    s = s.translate(str.maketrans("０１２３４５６７８９", "0123456789"))
    s = re.split(r'[-－之]', s)[0]          # 「60-1」取主號
    if s.isdigit():
        return int(s)
    n, tmp = 0, 0
    for ch in s:
        if ch == "百":
            n += (tmp or 1) * 100; tmp = 0
        elif ch == "十":
            n += (tmp or 1) * 10; tmp = 0
        elif ch in CN:
            tmp = CN[ch]
    return n + tmp


def case_range(label):
    ns = [num(x) for x in re.findall(NUM, label)]
    return (ns[0], ns[-1]) if ns else None


def _recovered():
    try:
        return json.load(open(RECOVERED, encoding="utf-8"))
    except OSError:
        return {}


_REC = None


def meet_days(meet_id, meet=None):
    """這一場的文件：[{lcidc, date, src}]（依日期、文件編號排）。meet API 的公報發言紀錄優先；沒有的天用反查結果。"""
    global _REC
    if _REC is None:
        _REC = _recovered()
    docs = []
    seen = set()
    for d in B.meet_docs(meet_id):
        if d["lcidc"] in seen:
            continue
        seen.add(d["lcidc"]); docs.append({"lcidc": d["lcidc"], "date": d["date"], "src": "meet"})
    for date, lcs in (_REC.get(meet_id) or {}).items():
        for lc in lcs:
            if lc in seen:
                continue
            seen.add(lc); docs.append({"lcidc": lc, "date": date, "src": "recovered"})
    docs.sort(key=lambda x: (x["date"] or "", x["lcidc"]))
    return docs


def budget_meets(terms=(8, 9, 10, 11)):
    """母體：{meet_id: {"meet": m, "days": {date: buckets}}}"""
    out = {}
    for t in terms:
        ms = {m["會議代碼"]: m for m in B.list_meets(t)}
        for r in B.inventory_rows(t):
            if not r["buckets"]:
                continue
            x = out.setdefault(r["meet"], {"meet": ms[r["meet"]], "days": {}, "reasons": {}, "term": t})
            x["days"][r["date"]] = r["buckets"]
            x["reasons"][r["date"]] = (x["reasons"].get(r["date"], "") + " " + r["reason"]).strip()
    return out


def org_names(meet, reasons=None):
    """會議事由裡列的機關／基金名稱（給「換機關」標籤比對用）。reasons 有給就只用那幾天的事由（多天一次會每天審不同機關，
    用整個會次的名單會把主席提到別天機關的話當成換機關；2026-10-08 經濟 12-05 審國發會被標成農業部）。"""
    names = set()
    BAD = re.compile(r'預算|總預算|中央政府|立法院|本院|行政院函|年度|事由|簽署|進行|僅|附屬單位|營業部分|非營業部分|決算|審核報告|^公務|^基金$|^所屬')   # 「衛生福利部主管公務及基金預算」拆出的「公務及基金」不是機關

    alias = {}       # 簡稱／去掉上級機關的短名 → 正式名（同一簡稱對到兩個正式名時取排序在前的，見函式末）

    def add(n):
        n = re.sub(r'^(?:關於|有關|審查|處理|繼續審查|繼續|主管|所屬|會及|及)+', '', n).strip("：:，、 ")
        n = re.sub(r'(?:及所屬|及其所屬|等\d+家財團法人|等)$', '', n)
        if not (2 <= len(n) <= 24) or BAD.search(n) or not ORG_SUFFIX.search(n):
            return
        names.add(n)
        for al in ORG_ALIAS.get(n, []):
            alias.setdefault(al, n)
        short = PARENT.sub('', n)                     # 「交通部中央氣象署」→「中央氣象署」
        if short != n and len(short) >= 2 and ORG_SUFFIX.search(short):
            alias.setdefault(short, n)
            for al in ORG_ALIAS.get(short, []):
                alias.setdefault(al, n)
        for k, v in ORG_ALIAS.items():               # 「交通部主管國營臺灣鐵路股份有限公司」裡含完整名
            if k in n and k != n:
                alias.setdefault(k, n)
                for al in v:
                    alias.setdefault(al, n)
    texts = list(reasons) if reasons else [x.get("會議事由") or "" for x in (meet.get("會議資料") or [])]
    for text in texts:
        for it in B.split_items(text):
            for pat in ORG_RE:
                for m in pat.finditer(it):
                    add(m.group(1))
                    for part in ORG_SPLIT.split(m.group(1)):     # 「審計部、財政部」「內政部函送財團法人○○基金會」拆開
                        if 2 <= len(part) <= 24:
                            add(part)
    # 回 [(名稱或簡稱, 正式名)]，長的先比對；簡稱對回正式名，標籤才不會在「交通部鐵道局」「鐵道局」之間跳
    out = {n: n for n in sorted(names)}
    for a, n in sorted(alias.items()):
        out.setdefault(a, n)
    # 同長的名稱再比字串：排序確定、不吃 set 順序（2026-10-29 前換 PYTHONHASHSEED 會讓 11-2-23-17 12-26 的 section 在 57／58 跳）
    return sorted(out.items(), key=lambda kv: (-len(kv[0]), kv[0]))


# ───────────────────────── 逐段 ─────────────────────────
def paragraphs(lcidc):
    """speakers.walk 之上再標兩類：議事錄（「段落：議事錄」後到下一位發言人）、書面提案（格式特徵）。"""
    ps = SP.walk_blocks(stages.blocks(lcidc))
    in_minutes = in_prop = in_read = False
    for p in ps:
        p["lcidc"] = lcidc
        t = p["text"] or ""
        if p["kind"] == "seg":
            if "議事錄" in t[:12]:
                in_minutes = True                    # 議事錄裡也有自己的「段落：討論事項」標記，所以只在主席再開口時結束
            in_prop = in_read = False
            if SEG_QA.match(t):
                p["kind"] = "seg_qa"
            continue
        if p["kind"] == "say" and p["new"]:
            in_prop = in_read = False
            if p["speaker"] == "主席":
                in_minutes = False                   # 議事錄到主席再開口為止（議事錄裡的「決定：」「提案人：」都不是口說）
        if in_minutes:
            p["kind"] = "minutes"
            continue
        if p["kind"] == "image":
            continue
        # 書面提案：「委員提案：」名下、案由／提案人等欄位、或「爰…」＋金額的長段；之後到下一位發言人前都算提案
        # （第 8–9 屆提案是文字、常接在主席段落後；第 11 屆是掃描圖，文字裡沒有）
        if p["kind"] == "proposal" or PROP_LINE.match(t) or (len(t) > 60 and PROP_WORD.search(t) and MONEY.search(t)):
            p["kind"] = "proposal"; in_prop = True
            continue
        if in_prop and p["kind"] in ("struct", "none", "say") and not p["new"]:
            p["kind"] = "proposal"
            continue
        # 宣讀文件：主席「請宣讀…」之後、下一位發言人之前（預算數、審查項目、協商結論），不算口說
        if p["kind"] == "say" and p["speaker"] == "主席" and READ_CMD.search(t):
            in_read = True
            continue
        if in_read and not p["new"] and p["kind"] in ("say", "struct", "none"):
            p["kind"] = "reading"
    return ps


def is_chair_say(p):
    return p["speaker"] == "主席" and p["kind"] == "say" and p["text"]


# ───────────────────────── session ─────────────────────────
def initial_kind(ps, buckets):
    """文件開頭沒有「時間／地點」標頭（續冊「（前接第四冊）」、或同一天拆成宣讀冊＋口頭冊）→ 直接以當天主型起算。"""
    head = any(HEADER.match(p["text"] or "") for p in ps[:8])
    if head:
        return ("報告事項", "report_items")
    b = "".join(buckets or [])
    if "②" in b or "⑤" in b:
        return ("預算提案處理", "process")
    if "④" in b and "①" not in b:
        return ("凍結案處理", "freeze")
    if "①" in b:
        return ("委員詢答", "qa")
    return ("報告事項", "report_items")


def sessions_of(ps, buckets):
    """主席宣告切 session。回 [{name, kind, start, end}]（段落 index 範圍，含）。"""
    out = []
    name0, kind0 = initial_kind(ps, buckets)
    cur = {"name": name0, "kind": kind0, "start": 0}
    b = "".join(buckets or [])
    open_kind = ("凍結案處理", "freeze") if ("④" in b and "②" not in b and "⑤" not in b) else ("預算提案處理", "process")   # 只有凍結案的天，開場句開的是凍結案處理
    # 討論事項接預算處理的天原本就由 PROC_START 切對（10-19 實測）；非預算日（buckets 空，含 ASR 側）不用：
    # 法案逐條審查「直接在協商桌上進行逐條審查」、臨時提案「現在我們逐案討論」會誤觸（10-21 實測 4 天）
    open_from = ("report_items", "report", "after_qa", "motion") if b else ()
    for i, p in enumerate(ps):
        if not is_chair_say(p):
            continue
        t = p["text"]
        new = None
        if AGENDA_RECITAL.match(t):
            # 議程和開場常唸在同一段（「本日會議議程為繼續審查…，現在就昨天保留的提案繼續進行審查，每案先請行政單位說明」）
            tail = re.split(r'[，,。](?=現在|接下來|今天)', t, maxsplit=1)
            if len(tail) == 2 and PROC_OPEN.search(tail[1]) and cur["kind"] in open_from and "報告事項" not in tail[1]:
                cur["end"] = i - 1
                out.append(cur)
                cur = {"name": open_kind[0], "kind": open_kind[1], "start": i}
            continue
        if REPORT_ITEMS.search(t) and cur["kind"] == "report_items":
            pass
        elif QA_START.search(t) and cur["kind"] != "qa":
            new = ("委員詢答", "qa")              # 詢答宣告常順帶預告「詢答後處理解凍案及臨時提案」，要先於處理判
        elif MOTION.search(t) and cur["kind"] != "motion":
            new = ("臨時提案", "motion")
        elif FREEZE_RPT.search(t) and cur["kind"] in ("qa", "after_qa", "motion") and "備查" not in t:
            new = ("預算提案處理", "process")        # 詢答後「我們現在來處理解凍專案報告案第一案至第二十案」；開會時宣讀後「均予備查」的一句不算
        elif FREEZE_PROC.match(t.strip()) and cur["kind"] != "freeze":
            new = ("凍結案處理", "freeze")          # P1：口頭逐案的「進行…預算解凍案處理」，後面子句提到「宣讀報告事項…之解凍案」也算
        elif FREEZE_START.search(t) and "報告事項" not in t and cur["kind"] != "report_items" and (cur["kind"] != "process" or FREEZE_REAL.search(t)):
            new = ("凍結案處理", "freeze")          # P2：已在預算處理中，只有講到院會交付的凍結案（解凍／院會交付／「○年度預算凍結案」）才開凍結案處理；
                                                    # 「現在處理有關提案委員預算減列、凍結案部分」「現在處理凍結案第20案至第28案」是委員的凍結提案，仍屬預算提案處理
        elif PROC_START.search(t) and "報告事項" not in t:
            is_freeze = FREEZE.search(t) and not re.search(r'預算(?:案)?(?:之|的)?處理|提案', t) and (cur["kind"] != "process" or FREEZE_REAL.search(t))
            new = ("凍結案處理", "freeze") if is_freeze else ("預算提案處理", "process")
        elif QA_END.search(t) and cur["kind"] == "qa" and not QA_NOT_END.search(t):
            new = ("詢答後處理與決定", "after_qa")       # 2026-10-29 Q5：名稱改「詢答後處理與決定」，kind 不變
        elif REPORT_START.search(t) and cur["kind"] in ("report_items", "discuss") and "報告事項" not in t and "議事錄" not in t:
            new = ("機關報告", "report")
        elif DISCUSS.search(t) and (cur["kind"] == "report_items" or ("草案" in t and cur["kind"] in ("process", "freeze", "after_qa", "qa"))):
            new = ("討論事項", "discuss")             # 預算處理完接著審法案（「接著進行討論事項四、併案審查…草案」）另開一段
        elif PROC_OPEN.search(t) and cur["kind"] in open_from and "報告事項" not in t:
            new = open_kind
        if new and new[1] != cur["kind"]:
            cur["end"] = i - 1
            out.append(cur)
            cur = {"name": new[0], "kind": new[1], "start": i}
    cur["end"] = len(ps) - 1
    out.append(cur)
    # 2026-10-29 下午 James：凍結案處理不另成 session（「不分了」）——委員的凍結提案與院會交付的解凍案都算預算提案處理，
    # 解凍只做 section 標籤。上面仍算出 freeze 邊界，這裡併回 process（相鄰的合成一段）。
    merged = []
    for s in out:
        if s["end"] < s["start"]:
            continue
        if s["kind"] == "freeze":
            s["kind"], s["name"] = "process", "預算提案處理"
        if merged and merged[-1]["kind"] == "process" and s["kind"] == "process":
            merged[-1]["end"] = s["end"]
        else:
            merged.append(s)
    return merged


# ───────────────────────── section ─────────────────────────
def _tags_of(texts):
    tags = []
    joined = "\n".join(texts)
    for name, pat in DECIDE:
        if re.search(pat, joined):
            tags.append(name)
    return tags


def sections_of(ps, s, orgs):
    """依 session 類型切 section。回 [{name, start, end, tags}]。"""
    a, b = s["start"], s["end"]
    rng = range(a, b + 1)
    secs = []

    def push(name, start, tags=None):
        if secs:
            secs[-1]["end"] = start - 1
        secs.append({"name": name, "start": start, "end": b, "tags": list(tags or [])})

    if s["kind"] == "report":
        push("（宣告）", a)
        last_call = None
        for i in rng:
            p = ps[i]
            if is_chair_say(p) and re.search(r'請[^。]{0,30}報告|邀請', p["text"]):
                last_call = i
            elif p["kind"] == "say" and p["new"] and p["speaker"] not in (None, "主席"):
                if last_call is not None and (not secs or secs[-1]["name"] != p["speaker"]):
                    push(p["speaker"], last_call)
                    last_call = None
    elif s["kind"] == "qa":
        push("（宣告）", a)
        pending = None   # (委員名, 主席點名的段落 i)
        n_mk = sum(1 for j in rng if ps[j]["kind"] == "seg_qa" and re.sub(r'\s+', '', (SEG_QA.match(ps[j]["text"]) or re.match(r'(.*)', '')).group(1)) not in ("休息", "主席", "散會", "繼續開會"))
        n_call = sum(1 for j in rng if is_chair_say(ps[j]) and CALL_LEG.search(ps[j]["text"]))
        has_markers = n_mk >= 2 and n_mk * 2 >= n_call   # 標記夠多才只用標記切（第 11 屆 182/185 場數一致）；第 9–10 屆有 64 天只有 1 個標記、30 天標記遠少於點名（2026-10-29 普查），退回用點名切
        for i in rng:
            p = ps[i]
            if p["kind"] == "seg_qa":
                m = SEG_QA.match(p["text"])
                name = re.sub(r'\s+', '', m.group(1))
                if name in ("休息", "主席", "散會", "繼續開會"):
                    continue                                  # 「段落：質詢：休息」不是人
                # 標記前一段若是主席點名，從點名那段起算
                start = pending[1] if pending and pending[0] and pending[0] in name or (pending and i - pending[1] <= 1) else i
                push(name, start); pending = None
                continue
            if is_chair_say(p):
                mc = CALL_LEG.search(p["text"])
                if mc:
                    pending = (re.sub(r'\s+', '', mc.group(1)), i)
                continue
            if p["kind"] == "say" and p["new"] and p["speaker"] not in (None, "主席") and pending:
                nm = pending[0]
                if SP.role_of(p["speaker"]) == "legislator" and (secs[-1]["name"] != p["speaker"]):
                    # 沒有「段落：質詢」標記的場次才用主席點名開段
                    if not has_markers:
                        push(p["speaker"], pending[1])
                pending = None
    elif s["kind"] in ("process", "freeze"):
        push("（宣讀預算數及提案）" if s["kind"] == "process" else "（宣告）", a)
        org, cur_range = None, None
        concl = next((i for i in range(b, max(a, b - 60), -1) if is_chair_say(ps[i]) and CONCLUDE.search(ps[i]["text"])), None)
        # session 第一句常把今天要處理的機關全列出來（「今天處理國家發展委員會及所屬單位預算含非營業基金…」）：
        # 取第一個當起始機關，只做標籤不開段
        first = next((ps[i]["text"] for i in rng if is_chair_say(ps[i])), "")
        pos = [(first.find(n), c) for n, c in orgs if n in first]
        if pos:
            org = min(pos)[1]
        for i in rng:
            p = ps[i]
            if not is_chair_say(p):
                continue
            t = p["text"]
            if i != a and not re.match(r'^[一二三四五六七八九十]+、', t) and (ORG_START.search(t) or (len(t) < 60 and re.search(r'(?:的)?部分|單位預算|基金|預算', t))):   # 主席逐條唸議程「七十一、繼續審查…」不是換機關
                hits = [(t.find(n), -len(n), c) for n, c in orgs if n in t and (len(t) < 80 or t.find(n) < 30)]   # 長句裡順帶提到的不算
                hit = min(hits)[2] if hits else None   # 句子裡最先出現的機關（同位置取最長名），不依名單順序（2026-10-29：之前同長名稱誰先比到看 set 順序）
                if hit and hit != org and not ORG_END.search(t) and not re.search(r'俟|稍後再|等一下再|之後再|再提出|待會再', t):   # 「有關港務公司部分，俟處理港務公司預算時再提出」是延後不是換機關（1b 8-6-23-11）
                    # 換機關／基金：開一段（名稱＝機關），之後的案號段都掛「機關：X」標籤；session 第一句列全部機關的不算
                    org, cur_range = hit, None
                    push(f"〔{org}〕", i)
                elif hit and hit == org and ORG_END.search(t) and len(t) < 80:
                    org = None                        # 「○○預算案審查完畢」
            # 案號宣告的候選（依出現位置排）：動詞型（CASE／ITEM）可在段落任何位置；句首型（CASE_BARE／CASE_ASK／CASE_DECIDE）
            # 只認段落前 24 字內（協商結論整段列幾十個案號，不能逐個開段）。逐一看候選：講的是目前這案就跳到下一個候選
            # （「第33案照案通過。處理第34案。」要取第34案）
            cands = [mm for mm in (CASE.search(t), ITEM.search(t), CASE_BARE.search(t), CASE_ASK.search(t), CASE_DECIDE.search(t), ITEM_BARE.search(t), CASE_TAIL.search(t), CASE_BY.search(t))
                     if mm and (mm.re is CASE or mm.re is ITEM or mm.re is CASE_TAIL or (mm.start() <= (40 if mm.re is CASE_BY else 24) and not (concl is not None and i >= concl)))]   # 結論句之後主席逐行唸「第四四九案照案通過。」不開段
            if not cands and not (concl is not None and i >= concl):
                nums = []
                for mm in re.finditer(r'第\s*(' + NUM + r')\s*案', t):
                    n0 = num(mm.group(1))
                    if n0 not in nums:
                        nums.append(n0)
                if len(nums) >= 3 and SUMMARY_WORD.search(t) and len(t) >= 20:
                    # 彙整宣告（2026-10-29 跳號普查）：「第1目…審查結果改為主決議：第3案、第4案、第5案…」「提案改主決議的部分：第1案、第2案…」
                    # 一段裡宣布好幾案的結果，不逐案開段，開一個「彙整」段並把案號全列上，讓每一案都找得到結果
                    mm_main = MERGE_MAIN.search(t)
                    if mm_main or MERGE_WORD.search(t):
                        nums = sorted(nums)
                        lab = "併案：" + "、".join(f"第{n0}案" for n0 in nums[:12]) + (f"…等{len(nums)}案" if len(nums) > 12 else "") + (f"（以{mm_main.group(1).replace(' ', '')}為主）" if mm_main else "")
                    else:
                        lab = "彙整：" + "、".join(f"第{n0}案" for n0 in nums[:12]) + (f"…等{len(nums)}案" if len(nums) > 12 else "")
                    if not (secs and secs[-1]["name"] == lab):
                        push(lab, i, [f"機關：{org}"] if org else [])
                    continue
            for m in sorted(cands, key=lambda mm: (0 if (mm.re is CASE or mm.re is ITEM) else 1, mm.start())):
                if m.re is CASE_TAIL and len(t) > 40:
                    continue                          # 段末案號只認短句（「主決議的部分，第118案。」）   # 動詞宣告優先（「第5案照案通過。處理第7案」取第7案）
                if "事項" in m.group(0):
                    continue                          # 「討論事項第一案已處理完畢」是議程項目不是預算案
                label = re.sub(r'\s+', '', m.group(1))
                label = re.sub(r'(?:的|之)?部分$', '', label)
                ml = CASE_LIST.match(t, m.start(1)) if "案" in label and "[至到]" and not re.search(r'[至到]', label) else None
                if ml:                                # 一句列好幾案（2026-10-29 跳號普查）：標籤列全部，連號的當群組範圍
                    ns = list(dict.fromkeys(num(x) for x in re.findall(NUM, ml.group(0))))   # 「第59案及第60案，第59案…」去重
                    mm_main = MERGE_MAIN.search(t)
                    if mm_main or MERGE_WORD.search(t):   # 併案的統一寫法（James 10-29）：「併案：第7案、第8案（以第7案為主）」
                        label = "併案：" + "、".join(f"第{x}案" for x in sorted(set(ns))) + (f"（以{mm_main.group(1).replace(' ', '')}為主）" if mm_main else "")
                    else:
                        label = "、".join(f"第{x}案" for x in dict.fromkeys(re.findall(NUM, ml.group(0))))
                    rg = (min(ns), max(ns)) if sorted(ns) == list(range(min(ns), max(ns) + 1)) else (ns[0], ns[0])
                elif "目" in label and m.re in (ITEM, ITEM_BARE) and ITEM_RANGE.search(t):
                    rr = ITEM_RANGE.search(t).group(1).replace(" ", "")
                    label = f"{label}（{rr}）"; rg = case_range(rr)   # 「處理第2目…相關提案為第10案至第42案」：目標籤附案號範圍
                else:
                    rg = case_range(label) if "案" in label else None
                if secs and secs[-1]["name"] == label:
                    continue
                # 群組內的單案唱名（「第41案。」在「第40案到第56案」裡）不另切（Q3）；「現在處理第41案」這種動詞宣告仍切；「第128-1案」是另一案
                if rg and cur_range and rg[0] == rg[1] and cur_range[0] <= rg[0] <= cur_range[1] and not re.search(r'[-－之]', label) \
                        and not (m.re is CASE and re.search(r'處理|進行|協商|進入|繼續', m.group(0))):
                    continue
                # 只講裁示不宣告的句子只在案號往前走時才開段（「第19案已經改成主決議；第20案…」回頭提的前案不切）；第1案視為重新起算
                if m.re in (CASE_DECIDE, CASE_BY) and rg and cur_range and rg[0] <= cur_range[1] and rg[0] != 1:
                    continue
                cur_range = rg if rg and rg[0] != rg[1] else (rg if not cur_range else cur_range if (rg and cur_range[0] <= rg[0] <= cur_range[1]) else rg)
                if rg and rg[0] != rg[1] and re.search(r'逐案|一案一案|逐一', t):
                    cur_range = None                  # 「第24案至第38案，逐案處理」：接下來的「處理第26案」各自成段，不算群組內唱名
                push(label, i, [f"機關：{org}"] if org else [])
                break
        if len(secs) <= 2 and (b - a) > 80:
            # 沒有案號宣告（財政委員會用「土1」「輸1」等公司代號、或整段自由協商）→ 退回依主席點名委員切
            secs = [{"name": "（宣讀預算數及提案）" if s["kind"] == "process" else "（宣告）", "start": a, "end": b, "tags": []}]
            for i in rng:
                p = ps[i]
                if is_chair_say(p):
                    mc = CALL_LEG.search(p["text"])
                    if mc:
                        nm = re.sub(r'\s+', '', mc.group(1))
                        if secs[-1]["name"] != nm:
                            push(nm, i, ["無案號宣告，依點名切"])
        for sec in secs:
            sec["tags"] += _tags_of([ps[j]["text"] for j in range(sec["start"], sec["end"] + 1) if is_chair_say(ps[j])])
    else:
        push(s["name"], a)
    # 結論：主席講協商結論／散會那一段起另開「（結論）」
    for i in range(b, max(a, b - 60), -1):
        if is_chair_say(ps[i]) and CONCLUDE.search(ps[i]["text"]) and s["kind"] in ("process", "freeze"):
            if secs and secs[-1]["start"] < i:
                push("（結論／散會）", i)
            break
    return [x for x in secs if x["end"] >= x["start"]]


def counts(ps, a, b):
    c = collections.Counter(ps[i]["kind"] for i in range(a, b + 1))
    return {"proposal": c["proposal"], "written": c["written"], "reading": c["reading"], "image": c["image"], "minutes": c["minutes"], "say": c["say"],
            "cases": sum(1 for i in range(a, b + 1) if ps[i]["kind"] == "proposal" and re.match(r'^案由[：:]', ps[i]["text"] or ""))}


# ───────────────────────── 一場 ─────────────────────────
def segment(meet_id, meets=None):
    meets = meets or budget_meets()
    info = meets.get(meet_id)
    if not info:
        raise SystemExit(f"{meet_id} 不在母體（會議事由沒有預算／決算）")
    meet = info["meet"]
    days = []
    for d in meet_days(meet_id, meet):
        buckets = info["days"].get(d["date"]) or []
        if not buckets:
            days.append({**d, "sessions": [], "note": "這一天的會議事由不含預算／決算，未切分", "paras": []}); continue
        if not stages.has_doc(d["lcidc"]):
            bs = stages.blocks(d["lcidc"])          # 沒快取就抓
            if not bs:
                days.append({**d, "sessions": [], "note": "文件抓不到", "paras": []}); continue
        ps = paragraphs(d["lcidc"])
        rs = info.get("reasons", {}).get(d["date"])
        orgs = org_names(meet, [rs] if rs else None)
        ss = sessions_of(ps, buckets)
        for s in ss:
            s["sections"] = sections_of(ps, s, orgs)
            s["counts"] = counts(ps, s["start"], s["end"])
        days.append({**d, "buckets": buckets, "sessions": ss, "paras": ps})
    return {"meet": meet_id, "title": meet.get("會議標題") or "", "dates": sorted(meet.get("日期") or []),
            "day_types": info["days"], "orgs": org_names(meet), "days": days}


def outline(r):
    print(f"== {r['meet']} {r['title']}  日期 {r['dates']}  機關名 {r['orgs'][:8]}")
    for d in r["days"]:
        print(f"-- {d['date']} {d['lcidc']} [{d['src']}] 型 {d.get('buckets')} {d.get('note', '')}")
        for s in d["sessions"]:
            c = s["counts"]
            print(f"   ▌{s['name']}  段 {s['start']}–{s['end']}  口說 {c['say']} 提案段 {c['proposal']}（案由 {c['cases']}） 宣讀 {c['reading']} 書面 {c['written']} 圖 {c['image']} 議事錄 {c['minutes']}  section {len(s['sections'])}")
            for sec in s["sections"][:40]:
                tg = "".join(f"〔{t}〕" for t in sec["tags"])
                print(f"      {sec['name']}{tg}  ({sec['end'] - sec['start'] + 1} 段)")
            if len(s["sections"]) > 40:
                print(f"      … 共 {len(s['sections'])} 段")


# ───────────────────────── meet-json ─────────────────────────
KIND_ZH = {"report_items": "報告事項", "report": "機關報告", "qa": "委員詢答", "process": "預算提案處理", "freeze": "凍結案處理",
           "discuss": "討論事項", "motion": "臨時提案", "after_qa": "詢答後處理與決定"}


def to_meetjson(r):
    sessions = []
    first = True
    for d in r["days"]:
        ps = d["paras"]
        typ = "／".join(d.get("buckets") or []) or "非預算日"
        if d.get("note"):
            sessions.append({"date": d["date"] or "", "content": f"{d['lcidc']}：{d['note']}", "sections": []}); continue
        for s in d["sessions"]:
            c = s["counts"]
            extra = []
            if c["proposal"]: extra.append(f"書面提案 {c['proposal']} 段" + (f"（案由 {c['cases']} 件）" if c["cases"] else ""))
            if c["reading"]: extra.append(f"宣讀文件 {c['reading']} 段")
            if c["written"]: extra.append(f"書面資料 {c['written']} 段")
            if c["image"]: extra.append(f"掃描圖 {c['image']} 張")
            if c["minutes"]: extra.append(f"議事錄 {c['minutes']} 段")
            head = f"{r['title']}\n" if first else ""
            first = False
            content = f"{head}{d['date'] or ''}｜{typ}｜{s['name']}（{len(s['sections'])} 段）" + ("；不進 section：" + "、".join(extra) if extra else "")
            secs = []
            for sec in s["sections"]:
                blocks = [{"time": None, "speaker": ps[j]["speaker"] or "", "text": ps[j]["text"]}
                          for j in range(sec["start"], sec["end"] + 1)
                          if ps[j]["text"] and ps[j]["kind"] in ("say", "seg_qa", "struct", "seg")]
                label = sec["name"] + "".join(f"〔{t}〕" for t in sec["tags"])
                secs.append({"type": KIND_ZH[s["kind"]], "label": label, "kind": "ivod", "blocks": blocks})
            sessions.append({"date": d["date"] or "", "content": content, "sections": secs})
    return {"meet_id": r["meet"], "meet_type": "委員會預算審查", "title": r["title"], "dates": r["dates"], "sessions": sessions}


# ───────────────────────── 統計 ─────────────────────────
def check(terms):
    meets = budget_meets(terms)
    st = collections.Counter(); kinds = collections.Counter(); secs = collections.Counter()
    nodoc, crash = [], []
    for n, mid in enumerate(sorted(meets), 1):
        try:
            r = segment(mid, meets)
        except Exception as e:
            crash.append((mid, repr(e)[:80])); continue
        if not r["days"]:
            nodoc.append(mid); continue
        for d in r["days"]:
            st["天"] += 1
            if d.get("note"):
                st["文件抓不到"] += 1; continue
            for s in d["sessions"]:
                kinds[s["kind"]] += 1; secs[s["kind"]] += len(s["sections"])
        if n % 50 == 0:
            print(f"  {n}/{len(meets)}", file=sys.stderr, flush=True)
    print(f"母體 {len(meets)} 場，沒有任何文件 {len(nodoc)} 場，crash {len(crash)}")
    print(f"天數 {st['天']}，文件抓不到 {st['文件抓不到']}")
    for k in KIND_ZH:
        print(f"  {KIND_ZH[k]:<8} session {kinds[k]:>5}  section {secs[k]:>6}  平均 {secs[k] / max(kinds[k], 1):.1f}")
    for mid, e in crash[:20]:
        print("  crash", mid, e)
    return meets


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("meets", nargs="*")
    ap.add_argument("--check", action="store_true")
    ap.add_argument("--term", type=int, action="append")
    ap.add_argument("--json")
    a = ap.parse_args()
    terms = tuple(a.term) if a.term else (8, 9, 10, 11)
    if a.check:
        check(terms); return
    meets = budget_meets(terms)
    out = []
    for mid in a.meets:
        r = segment(mid, meets)
        outline(r)
        out.append(to_meetjson(r))
    if a.json:
        json.dump(out, open(a.json, "w"), ensure_ascii=False, indent=1)
        print("→", a.json)


if __name__ == "__main__":
    main()
