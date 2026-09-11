#!/usr/bin/env python3
"""
write-meet-section-preview.py
對指定 meet_id 列表，產生 imports/meet/meet-json/{meet_id}.json

資料來源：
  - meet-data/{meet_id}.json  → 會議日期 + 公報 lcidc_ids
  - gazette/agenda-html/      → 公報全文，用於 section 結構和 block 明細
  - API ly.govapi.tw/v2/ivods → 所有 clips（gazette=✓ 和 gazette=✗）的時間和影片連結

使用：
  python3 write-meet-section-preview.py --default   # 預設 10 場
  python3 write-meet-section-preview.py 院會-11-5-20 委員會-11-3-19-4 ...
"""

import sys, json, re, os, ast, urllib.request, urllib.parse
from collections import defaultdict
from bs4 import BeautifulSoup

IVOD_DIR     = os.path.dirname(os.path.abspath(__file__))
GAZETTE_HTML = os.path.join(IVOD_DIR, '../../imports/gazette/agenda-html')
GAZETTE_DATA   = os.path.join(IVOD_DIR, '../../imports/gazette/gazette-data')
MEET_DATA    = os.path.join(IVOD_DIR, '../../imports/meet/meet-data')
MEET_JSON    = os.path.join(IVOD_DIR, '../../imports/meet/meet-json')

# ─── Anchors & sub-section regex ─────────────────────────────────────────────

_RESET    = '__RESET__'
_NO_MATCH = object()

_REST_RE = re.compile(r'^休息\s*[（(]')

ANCHORS_YUANHUI = [
    ('國是論壇',     re.compile(r'現在進行國是論壇')),
    ('報告事項',     re.compile(r'現在進行報告事項|先進行報告事項')),
    ('討論事項',     re.compile(r'現在請宣讀討論事項|現在進行討論事項')),
    ('廣泛討論',     re.compile(r'現在進行廣泛討論|報告院會.{0,10}現在進行廣泛討論|進行廣泛討論')),
    ('逐條討論',     re.compile(r'現在進行逐條討論')),
    ('休息',         _REST_RE),
    (_RESET,         re.compile(r'^散會')),
]

ANCHORS_COMMITTEE_BILL = [
    ('提案委員說明', re.compile(
        r'邀請提案委員說明|請.{0,15}委員說明提案要旨|先請提案委員說明'
        r'|現在請提案委員說明|進行提案說明.*委員|委員.{0,5}提案說明'
        r'|首先進行提案說明|先請提案.{0,5}委員'
    )),
    ('機關報告',     re.compile(
        r'現在先進行機關報告|接下來請機關代表.{0,3}報告|先進行機關報告'
        r'|請機關代表報告|接下來我們請機關代表'
        r'|(首先|現在|接下來.*?我們).{0,3}請.{0,30}(部長|主委|主任委員|次長|署長|局長|處長).{0,30}(進行報告|進行簡單報告|報告)'
        r'|現在請列席機關報告'
    )),
    ('廣泛討論省略', re.compile(r'不進行廣泛討論|依慣例不進行廣泛討論|直接進行逐條|廣泛討論沒有人登記|廣泛討論無人登記')),
    ('廣泛討論',     re.compile(r'進行廣泛討論|本案是否要廣泛討論|討論事項（廣泛討論）|現在進行詢答|進行詢答|進行綜合詢答')),
    ('法案條文宣讀', re.compile(r'現在進行法案條文宣讀|請宣讀.*法案.*條文')),
    ('逐條討論',     re.compile(r'現在進入逐條討論|現在進行逐條討論|繼續進行逐條審查|現在進行協商|【逐條討論】|\[逐條討論\]')),
    ('休息',         _REST_RE),
    (_RESET,         re.compile(r'^散會')),
]

# 細粒度子段落識別
_MINS_START   = re.compile(r'宣讀上次|請宣讀議事錄')
_PROP_SPEAKER = re.compile(
    r'(?:第一(?:位|個)(?:.*?)請|首先請|下一位(?:我們)?請|接下來(?:有)?請|現在(?:有)?請)'
    r'(?:.*?代表)?([^\s，。、]{2,4})委員(?:進行)?(?:提案說明|說明提案)'
)
_WRITTEN_RE   = re.compile(r'^(.{2,20})書面資料[：:]')
_FORUM_SPEAKER  = re.compile(r'請([一-鿿]{2,4})委員發言|請登記[^委\n]*?位([一-鿿]{2,4})委員發言')
_ARTICLE_ANCHOR = re.compile(
    r'^(?:主席：\s*)?'
    r'(?:(?:先進行\S{0,10}?|接下來(?:我們)?處理|現在(?:我們)?處理|請處理|處理)\s*)?'
    r'第([一二三四五六七八九十百千零〇]+)條(?:條文)?[。，？、\s]'
)
_QAQUERY_SPEAKER = re.compile(
    r'(?:有)?請(?:發言)?(?:登記第\s*\S+\s*位\s*的?\s*)?([^\s，。、的]{2,5})委員(?:質詢|發言|繼續質詢)'
)
_TIME_EMBEDDED = re.compile(r'[（(]\s*(\d{1,2})\s*時\s*(?:(\d{1,2})\s*分)?\s*[）)]')
_SPEAKER_COLON = re.compile(r'^.{1,30}[：:]\s*')

# ─── 工具函數 ─────────────────────────────────────────────────────────────────

def detect_meet_type(meet_id, content):
    if meet_id.startswith(('院會', '臨時會院會')):
        return '院會'
    if meet_id.startswith(('委員會', '聯席會議', '臨時會委員會', '臨時會聯席')):
        if re.search(r'審查.{0,80}草案', content) and '預算' not in content:
            return '委員會-法案'
        return '委員會-其他'
    return '其他'

def extract_paragraphs(lcidc_ids):
    paras = []
    for lid in lcidc_ids:
        path = os.path.join(GAZETTE_HTML, f'LCIDC01_{lid}.doc.html')
        if not os.path.exists(path):
            continue
        soup = BeautifulSoup(open(path, encoding='utf-8').read(), 'html.parser')
        paras += [p.get_text(' ', strip=True) for p in soup.find_all('p') if p.get_text(strip=True)]
    return paras

def parse_embedded_time(text):
    """從公報段落提取嵌入時間，如 '（ 9 時 26 分）' → '09:26'。"""
    m = _TIME_EMBEDDED.search(text)
    if not m:
        return None
    h, mi = int(m.group(1)), int(m.group(2) or 0)
    return f'{h:02d}:{mi:02d}'

def speaker_from_line(line):
    m = re.match(r'^(.{1,30})：', line)
    return m.group(1) if m else None

def gazette_paras_to_blocks(paras, p0, p1):
    """公報段落 p0~p1 → block 列表，含 speaker / time / text。"""
    blocks = []
    last_time = None
    for i in range(p0, min(p1 + 1, len(paras))):
        p = paras[i]
        speaker = speaker_from_line(p)
        t = parse_embedded_time(p)
        if t:
            last_time = t
        # 清除發言者前綴和時間標記，留下純內文
        text = _TIME_EMBEDDED.sub('', p).strip()
        text = _SPEAKER_COLON.sub('', text, count=1).strip()
        if not text:
            continue
        blocks.append({
            'para':    i,
            'speaker': speaker,
            'time':    last_time,
            'text':    text[:200],
        })
    return blocks

def compute_sub_keys(paras, para_sections):
    sub_keys = []
    cur = None
    prev_major = None
    for text, major in zip(paras, para_sections):
        if major is None:
            if prev_major is not None:
                cur = None  # 散會後重置
            if _MINS_START.search(text):
                cur = '報告事項:上次議事錄'
        elif major == '提案委員說明':
            m = _PROP_SPEAKER.search(text)
            if m:
                cur = f'提案委員說明:{m.group(1).strip()}'
            elif not (cur and cur.startswith('提案委員說明:')):
                cur = '提案委員說明'
        elif major == '機關報告':
            m = _WRITTEN_RE.match(text)
            if m:
                cur = f'機關報告:書面資料:{m.group(1).strip()}'
            elif major != prev_major:
                cur = '機關報告:口頭'
        elif major == '廣泛討論':
            m = _QAQUERY_SPEAKER.search(text)
            if m:
                name = (m.group(1) or '').strip()
                cur = f'廣泛討論:{name}' if name else '廣泛討論'
            elif major != prev_major or cur is None:
                cur = '廣泛討論'
        elif major == '國是論壇':
            m = _FORUM_SPEAKER.search(text)
            if m:
                cur = f'國是論壇:{m.group(1) or m.group(2)}'
            elif major != prev_major or cur is None:
                cur = '國是論壇'
        elif major == '逐條討論':
            m = _ARTICLE_ANCHOR.match(text)
            if m:
                cur = f'逐條討論:第{m.group(1)}條'
            elif major != prev_major or cur is None:
                cur = '逐條討論:協商' if '協商' in text else '逐條討論'
        elif major == '休息':
            if _REST_RE.match(text) or text.startswith('繼續開會'):
                cur = '休息'
            else:
                m = _QAQUERY_SPEAKER.search(text)
                if m:
                    name = m.group(1).strip()
                    cur = f'廣泛討論:{name}' if name else '廣泛討論'
                elif cur and cur.startswith('廣泛討論:'):
                    pass
                else:
                    cur = '休息'
        else:
            cur = major
        sub_keys.append(cur)
        prev_major = major
    return sub_keys

def sub_key_to_meta(sub_key):
    """sub_key → (type, label, kind)。kind: 'ivod' | 'attachment' | 'meta'"""
    if sub_key is None:
        return None, None, None
    if sub_key.startswith('報告事項:'):
        return '報告事項', sub_key[len('報告事項:'):], 'attachment'
    if sub_key.startswith('提案委員說明:'):
        return '提案委員說明', sub_key[len('提案委員說明:'):], 'ivod'
    if sub_key == '提案委員說明':
        return '提案委員說明', None, 'ivod'
    if sub_key == '機關報告:口頭':
        return '機關報告', '口頭報告', 'ivod'
    if sub_key.startswith('機關報告:書面資料:'):
        return '機關報告', sub_key[len('機關報告:書面資料:'):] + '書面資料', 'attachment'
    if sub_key.startswith('國是論壇:'):
        return '國是論壇', sub_key[len('國是論壇:'):], 'ivod'
    if sub_key == '國是論壇':
        return '國是論壇', None, 'ivod'
    if sub_key == '廣泛討論省略':
        return '廣泛討論', '（省略）', 'meta'
    if sub_key.startswith('廣泛討論:'):
        return '質詢', sub_key[len('廣泛討論:'):], 'ivod'
    if sub_key == '廣泛討論':
        return '廣泛討論', None, 'ivod'
    if sub_key == '法案條文宣讀':
        return '法案條文宣讀', None, 'attachment'
    if sub_key.startswith('逐條討論:'):
        return '逐條討論', sub_key[len('逐條討論:'):], 'ivod'
    if sub_key == '休息':
        return '休息', None, 'meta'
    return sub_key, sub_key, 'ivod'

def section_items_from_sub_keys(sub_keys):
    items = []
    if not sub_keys:
        return items
    cur_key, cur_start = sub_keys[0], 0
    for i, k in enumerate(sub_keys[1:], 1):
        if k != cur_key:
            if cur_key is not None:
                items.append({'sub_key': cur_key, 'para_start': cur_start, 'para_end': i - 1})
            cur_key, cur_start = k, i
    if cur_key is not None:
        items.append({'sub_key': cur_key, 'para_start': cur_start, 'para_end': len(sub_keys) - 1})
    return items

def find_anchor(para, anchor_list):
    for name, pattern in anchor_list:
        if pattern.search(para):
            return name
    return _NO_MATCH

def fetch_clips_for_meet_date(meet_id, date):
    """從公開 API 取得某 meet+date 的所有 Clip clips（含 gazette=✗）。"""
    params = urllib.parse.urlencode({'會議資料.會議代碼': meet_id, '日期': date})
    url = f'https://ly.govapi.tw/v2/ivods?{params}'
    try:
        with urllib.request.urlopen(url, timeout=10) as resp:
            d = json.loads(resp.read())
        return [iv for iv in d.get('ivods', []) if iv.get('影片種類') == 'Clip']
    except Exception:
        return []

def load_meet_gazette_info(meet_id):
    """從 meet-data 讀取公報 PDF 連結和頁碼。"""
    path = os.path.join(MEET_DATA, f'{meet_id}.json')
    if not os.path.exists(path):
        return []
    d = json.load(open(path))

    lcidc_to_pdf = {}
    lcidc_to_doc = {}
    fallback_pdf = None
    for pp in d.get('ppg_data', []):
        pdf_url = None
        doc_url_ppg = None
        doc_lcidc = None
        for link in pp.get('links', []):
            href = link.get('href', '')
            ft   = link.get('filetype')
            if ft == 'pdf':
                if 'LCIDC01_' in href:
                    pdf_url = href
                elif not fallback_pdf and ('SittingYuanInfo' in href or 'agendarec' in href):
                    fallback_pdf = href
            elif ft == 'doc':
                m = re.search(r'LCIDC01_(\w+)\.doc', href, re.IGNORECASE)
                if m:
                    doc_lcidc = m.group(1)
                    doc_url_ppg = href
        if doc_lcidc and pdf_url:
            lcidc_to_pdf[doc_lcidc] = pdf_url
        if doc_lcidc and doc_url_ppg:
            lcidc_to_doc[doc_lcidc] = doc_url_ppg

    # For 院會 sessions, ppg_data only has the combined 議事錄 PDF (no per-lcidc PDFs).
    # Use gazette-data sub_documents (parsed from gazette-detail-html) to map each record's
    # page_start to the correct communique1 PDF file and sub-document offset.
    gazette_subs = {}  # gid → list of sub_document dicts from gazette-data
    if not lcidc_to_pdf:
        for rec in d.get('公報發言紀錄', []):
            gid = rec.get('gazette_id')
            if not gid or gid in gazette_subs:
                continue
            gpath = os.path.join(GAZETTE_DATA, f'{gid}.json')
            if os.path.exists(gpath):
                gd = json.load(open(gpath))
                gazette_subs[gid] = gd.get('sub_documents', [])

    def _find_sub(gid, ps):
        """Return (pdf_url, doc_url, sub_page_start) for the sub-document covering printed page ps."""
        if ps is None:
            return None, None, None
        ps_int = int(ps)
        subs = gazette_subs.get(gid, [])
        def _extract(sub):
            doc_u = sub.get('doc_urls', [None])[0]
            return sub['pdf_url'], doc_u, sub['page_start']
        for sub in subs:
            if sub.get('page_start') == ps_int:
                return _extract(sub)
        for sub in subs:
            s0, s1 = sub.get('page_start'), sub.get('page_end')
            if s0 is not None and s1 is not None and s0 <= ps_int < s1:
                return _extract(sub)
        for sub in subs:
            s0, s1 = sub.get('page_start'), sub.get('page_end')
            if s0 is not None and s1 is not None and s0 <= ps_int <= s1:
                return _extract(sub)
        return None, None, None

    result = []
    seen = {}
    for rec in d.get('公報發言紀錄', []):
        lids = _to_list(rec.get('agenda_lcidc_ids', []))
        ps   = rec.get('page_start')
        pe   = rec.get('page_end')
        gid  = rec.get('gazette_id')
        key  = tuple(lids)

        if lcidc_to_pdf:
            pdf        = next((lcidc_to_pdf[lid] for lid in lids if lid in lcidc_to_pdf), fallback_pdf)
            doc        = next((lcidc_to_doc[lid] for lid in lids if lid in lcidc_to_doc), None)
            agendarec  = None
            pdf_sub_ps = None
        else:
            pdf, doc, pdf_sub_ps = _find_sub(gid, ps)
            agendarec  = fallback_pdf
            if pdf is None:
                pdf = fallback_pdf

        pdf_offset = (pdf_sub_ps - 1) if pdf_sub_ps is not None else ((int(ps) - 1) if ps else 0)

        if key not in seen:
            seen[key] = len(result)
            result.append({
                'lcidc_ids':       lids,
                'gazette_id':      gid,
                'page_start':      ps,
                'page_end':        pe,
                'pdf_url':         pdf,
                'doc_url':         doc,
                'agendarec_url':   agendarec,
                'pdf_page_offset': pdf_offset,
            })
        else:
            idx = seen[key]
            if ps and (result[idx]['page_start'] is None or int(ps) < int(result[idx]['page_start'])):
                result[idx]['page_start'] = ps
                result[idx]['pdf_page_offset'] = pdf_offset
            if pe and (result[idx]['page_end'] is None or int(pe) > int(result[idx]['page_end'])):
                result[idx]['page_end'] = pe
    return result

def _to_list(v):
    if isinstance(v, list):
        return v
    try:
        return ast.literal_eval(v)
    except Exception:
        return []

# ─── 核心：分析整場會議 ────────────────────────────────────────────────────────

def analyze_meet(meet_id):
    # ── 1. 從 meet-data 讀取 sessions（date + lcidc_ids）────────────────────
    meet_path = os.path.join(MEET_DATA, f'{meet_id}.json')
    if not os.path.exists(meet_path):
        return None
    meet_d = json.load(open(meet_path))

    sessions_meta = []
    seen_sessions = set()
    for rec in meet_d.get('公報發言紀錄', []):
        lids    = _to_list(rec.get('agenda_lcidc_ids', []))
        dates   = _to_list(rec.get('meetingDate', []))
        content = rec.get('content', '')
        mt      = detect_meet_type(meet_id, content)
        if mt not in ('院會', '委員會-法案'):
            continue
        date = dates[0] if dates else None
        if not date or not lids:
            continue
        key = (date, tuple(lids))
        if key in seen_sessions:
            continue
        seen_sessions.add(key)
        sessions_meta.append({
            'date':       date,
            'lcidc_ids':  lids,
            'content':    content,
            'gazette_id': rec.get('gazette_id'),
            'meet_type':  mt,
        })

    if not sessions_meta:
        return None

    meet_type   = sessions_meta[0]['meet_type']
    anchor_list = ANCHORS_YUANHUI if meet_type == '院會' else ANCHORS_COMMITTEE_BILL

    # ── 2. 公報 cache ─────────────────────────────────────────────────────────
    gazette_cache = {}

    def build_gazette(lcidc_ids):
        key = tuple(lcidc_ids)
        if key in gazette_cache:
            return gazette_cache[key]
        paras = extract_paragraphs(lcidc_ids)
        if not paras:
            gazette_cache[key] = None
            return None
        cur = None
        p_sections, events = [], []
        for i, p in enumerate(paras):
            anc = find_anchor(p, anchor_list)
            if anc is not _NO_MATCH:
                if anc is _RESET:
                    cur = None
                else:
                    cur = anc
                    events.append({'para': i, 'section': anc, 'text': p[:60]})
            p_sections.append(cur)
        sub_keys = compute_sub_keys(paras, p_sections)
        gazette_cache[key] = {
            'paras': paras, 'para_sections': p_sections,
            'events': events, 'sub_keys': sub_keys,
        }
        return gazette_cache[key]

    # ── 3. gazette_info（PDF/頁碼）────────────────────────────────────────────
    gazette_info = load_meet_gazette_info(meet_id)

    def find_gi(lcidc_ids):
        lids_set = set(lcidc_ids)
        for gi in gazette_info:
            if set(gi['lcidc_ids']) == lids_set:
                return gi
        for gi in gazette_info:
            if lids_set & set(gi['lcidc_ids']):
                return gi
        return gazette_info[0] if gazette_info else None

    # ── 4. 逐 session 處理 ────────────────────────────────────────────────────
    sessions          = []
    total_clips       = 0
    _written_dates_done = set()  # 每日書面質詢只加一次

    for sm in sessions_meta:
        date      = sm['date']
        lcidc_ids = sm['lcidc_ids']
        if not date or not lcidc_ids:
            continue

        gazette = build_gazette(lcidc_ids)
        if not gazette:
            continue

        gi    = find_gi(lcidc_ids)
        paras = gazette['paras']

        # ── 4a. 從 API 取得所有 clips ────────────────────────────────────────
        api_clips = fetch_clips_for_meet_date(meet_id, date)
        api_clips.sort(key=lambda x: x.get('開始時間', ''))
        total_clips += len(api_clips)

        # 建立兩層索引：全名 + 漢字前綴（處理原住民族附名如「伍麗華Saidhai…」）
        _HAN_RE = re.compile(r'^([一-鿿]{2,4})')
        name_clips = defaultdict(list)
        name_clips_short = defaultdict(list)
        for iv in api_clips:
            n = iv.get('委員名稱', '')
            if not n:
                continue
            name_clips[n].append(iv)
            m = _HAN_RE.match(n)
            if m and m.group(1) != n:
                name_clips_short[m.group(1)].append(iv)

        # ── 4b. 建立 sections ────────────────────────────────────────────────
        sections_out = []
        for item in section_items_from_sub_keys(gazette['sub_keys']):
            sk       = item['sub_key']
            p0, p1   = item['para_start'], item['para_end']
            type_, label, kind = sub_key_to_meta(sk)
            if type_ is None:
                continue

            entry = {
                'type':       type_,
                'label':      label,
                'kind':       kind,
                'para_start': p0,
                'para_end':   p1,
            }

            if kind in ('attachment', 'meta'):
                entry['gazette_text'] = '\n'.join(paras[p0:p1+1])
                entry['blocks'] = []
            else:
                blocks = gazette_paras_to_blocks(paras, p0, p1)
                entry['blocks'] = blocks
                if not blocks:
                    entry['gazette_text'] = '\n'.join(paras[p0:p1+1])

            sections_out.append(entry)

        # ── 4c. 從公報提取 q_start（廣泛討論最早嵌入時間）──────────────────
        q_start_str = None
        for sec in sections_out:
            if sec['type'] == '質詢':
                for b in sec['blocks']:
                    if b['time']:
                        full_t = f'{date}T{b["time"]}:00+08:00'
                        if q_start_str is None or full_t < q_start_str:
                            q_start_str = full_t
                        break  # 只取第一個 block 的時間

        # ── 4d. 為 ivod sections 配對 API clip ──────────────────────────────
        for sec in sections_out:
            if sec['kind'] != 'ivod':
                continue
            lbl = sec.get('label') or ''
            if not lbl:
                continue
            candidates = name_clips.get(lbl) or name_clips_short.get(lbl)
            if not candidates:
                continue
            matched    = None
            if sec['type'] == '提案委員說明':
                for iv in candidates:
                    if q_start_str is None or iv.get('開始時間', '') < q_start_str:
                        matched = iv
                        break
            elif sec['type'] == '質詢':
                for iv in candidates:
                    if q_start_str is None or iv.get('開始時間', '') >= q_start_str:
                        matched = iv
                        break
            else:
                matched = candidates[0]
            if matched:
                sec['ivod_clip'] = {
                    'id':    matched.get('IVOD_ID'),
                    'url':   matched.get('IVOD_URL', ''),
                    'start': (matched.get('開始時間') or '')[:19],
                    'end':   (matched.get('結束時間') or '')[:19],
                }

        # ── 4e. 計算各 section 的時間範圍 ────────────────────────────────────
        # Step 0: 休息段落從公報文字提取時間
        _TIG = re.compile(r'[（(]\s*(\d{1,2})\s*時\s*(?:(\d{1,2})\s*分)?\s*[）)]')
        for sec in sections_out:
            if sec.get('type') != '休息':
                continue
            for line in (sec.get('gazette_text') or '').split('\n'):
                m = _TIG.search(line)
                if not m:
                    continue
                h, mi = int(m.group(1)), int(m.group(2) or 0)
                t = f'{h:02d}:{mi:02d}'
                if '休息' in line and 'time_start' not in sec:
                    sec['time_start'] = t
                elif '繼續開會' in line and 'time_end' not in sec:
                    sec['time_end'] = t

        # Step 1: 其他 section 的 time_start
        for sec in sections_out:
            if 'time_start' in sec:
                continue
            ts = None
            if sec.get('ivod_clip'):
                ts = (sec['ivod_clip'].get('start') or '')[11:16] or None
            elif sec.get('blocks'):
                ts = next((b['time'] for b in sec['blocks'] if b.get('time')), None)
            if ts:
                sec['time_start'] = ts

        # Step 2: time_end = 下一個有 time_start 的 section 的 time_start
        prev = None
        for sec in sections_out:
            if prev and 'time_start' in sec:
                if 'time_end' not in prev:
                    prev['time_end'] = sec['time_start']
            if 'time_start' in sec:
                prev = sec
        if prev and 'time_end' not in prev and prev.get('ivod_clip'):
            c  = prev['ivod_clip']
            te = (c.get('end') or '')[11:16] or None
            if te and te != prev.get('time_start'):
                prev['time_end'] = te

        # Step 3: 清掉相同的 start=end
        for sec in sections_out:
            if 'time_end' in sec and sec['time_end'] == sec.get('time_start'):
                del sec['time_end']

        # ── 4f. 書面質詢 section ─────────────────────────────────────────────
        # 只在有公報內容的 session 加一次（避免同一天多個 session 重複加）
        n_blocks_gazette = sum(len(s['blocks']) for s in sections_out)
        if n_blocks_gazette > 0 and date not in _written_dates_done:
            written_blocks = []
            for rec in meet_d.get('發言紀錄', []):
                if rec.get('speechType') != '書面質詢':
                    continue
                rec_date = rec.get('smeetingDate', '')
                if isinstance(rec_date, list):
                    rec_date = rec_date[0] if rec_date else ''
                if rec_date and rec_date != date:
                    continue
                for name in rec.get('legislatorNameList', []):
                    written_blocks.append({'para': None, 'speaker': name, 'time': None, 'text': ''})
            if written_blocks:
                sections_out.append({
                    'type':       '書面質詢',
                    'label':      None,
                    'kind':       'written',
                    'para_start': None,
                    'para_end':   None,
                    'blocks':     written_blocks,
                })
                _written_dates_done.add(date)

        # ── 4g. 統計 ──────────────────────────────────────────────────────────
        n_blocks    = sum(len(s['blocks']) for s in sections_out)
        n_sectioned = sum(len(s['blocks']) for s in sections_out if s['kind'] == 'ivod')
        sec_dist    = {
            (s['type'] + (':' + s['label'] if s['label'] else '')): len(s['blocks'])
            for s in sections_out if s['kind'] == 'ivod' and s['blocks']
        }
        pct = int(n_sectioned * 100 / n_blocks) if n_blocks else 0
        if n_blocks == 0:
            print(f'  [{date}] 跳過（無公報段落）')
            continue
        print(f'  [{date}] clips={len(api_clips)}, blocks={n_blocks}, '
              f'ivod={n_sectioned} ({pct}%), sections={sec_dist}')

        sessions.append({
            'date':       date,
            'lcidc_ids':  lcidc_ids,
            'content':    sm['content'].strip(),
            'gazette_id': sm['gazette_id'],
            'pdf_url':         gi['pdf_url']          if gi else None,
            'doc_url':         gi.get('doc_url')      if gi else None,
            'agendarec_url':   gi.get('agendarec_url') if gi else None,
            'page_start':      gi['page_start']       if gi else None,
            'page_end':        gi['page_end']          if gi else None,
            'pdf_page_offset': gi['pdf_page_offset']  if gi else 0,
            'clip_count': len(api_clips),
            'anchors':    gazette['events'],
            'sections':   sections_out,
        })

    if not sessions:
        return None

    sessions.sort(key=lambda s: s['date'])

    return {
        'meet_id':    meet_id,
        'meet_type':  meet_type,
        'clip_count': total_clips,
        'sessions':   sessions,
    }

# ─── Main ─────────────────────────────────────────────────────────────────────

DEFAULT_MEETS = [
    '院會-11-5-21',
    '院會-11-5-20',
    '院會-11-5-19',
    '院會-11-5-18',
    '院會-11-4-8',
    '委員會-11-3-15-28',
    '委員會-11-3-15-13',
    '委員會-11-3-15-12',
    '委員會-11-3-19-4',
    '委員會-11-3-22-3',
]

def main():
    args     = sys.argv[1:]
    meet_ids = DEFAULT_MEETS if ('--default' in args or not args) else [a for a in args if not a.startswith('--')]

    os.makedirs(MEET_JSON, exist_ok=True)

    for meet_id in meet_ids:
        print(f'\n處理 {meet_id}...')
        result = analyze_meet(meet_id)
        if not result:
            print(f'  跳過（無符合資料）')
            continue
        out_path = os.path.join(MEET_JSON, f'{meet_id}.json')
        with open(out_path, 'w', encoding='utf-8') as f:
            json.dump(result, f, ensure_ascii=False, indent=2)
        print(f'  → 共 {len(result["sessions"])} 個 session，寫入 {out_path}')

if __name__ == '__main__':
    main()
