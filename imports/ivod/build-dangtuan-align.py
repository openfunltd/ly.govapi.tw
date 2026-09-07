#!/usr/bin/env python3
"""
build-dangtuan-align.py
將黨團協商公報 anchor 對齊 Full IVOD 的 whisper 時間軸。

與 build-gazette-align.py 的差異：
  - 輸入來源：agenda-anchor/{agenda_id}.json（由 parse-dangtuan-anchors.py 產生）
  - IVOD 類型：Full（整場會議錄影，非 Clip）
  - 配對方式：從 tikahtml 抽取實際會議日期，與 IVOD 日期比對
    （不使用 ppg_meet_page_json 連結，因為該連結有時指向錯誤文件）

使用方式：
  python3 build-dangtuan-align.py              # 處理所有尚未對齊的
  python3 build-dangtuan-align.py 17702        # 單筆
  limit=50   python3 build-dangtuan-align.py   # 批次限制數量
  force=1    python3 build-dangtuan-align.py   # 強制重跑
  dry_run=1  python3 build-dangtuan-align.py   # 只印擬處理清單
"""

import json, re, sys, glob, os, importlib.util

IVOD_DIR    = os.path.dirname(os.path.abspath(__file__))
GAZETTE_DIR = os.path.normpath(os.path.join(IVOD_DIR, '../gazette'))
ANCHOR_DIR  = os.path.join(GAZETTE_DIR, 'agenda-anchor')
TIKA_DIR    = os.path.join(GAZETTE_DIR, 'agenda-tikahtml')

# ─── 從 build-gazette-align.py 載入共用函式 ──────────────────────────────────

_spec = importlib.util.spec_from_file_location(
    'build_gazette_align',
    os.path.join(IVOD_DIR, 'build-gazette-align.py'),
)
_bga = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_bga)

load_whisper_words = _bga.load_whisper_words
parse_blocks       = _bga.parse_blocks
align              = _bga.align
rel_to_abs         = _bga.rel_to_abs


# ─── tikahtml 日期抽取 ────────────────────────────────────────────────────────

_ROC_DATE_RE = re.compile(r'中華民國(\d+)年(\d+)月(\d+)日')


def tikahtml_date(agenda_id: str) -> str | None:
    """
    從 agenda-tikahtml/LCIDC01_{agenda_id}.doc.html 的標頭抽取實際會議日期。
    回傳 'YYYY-MM-DD' 或 None。
    （比 gazette-agenda-data 的 meetingDate 更可靠，因為後者來自 ppg meet page，
      有時 SittingCappInfo 連結指向錯誤文件。）
    """
    tika_path = os.path.join(TIKA_DIR, f'LCIDC01_{agenda_id}.doc.html')
    if not os.path.exists(tika_path):
        return None
    try:
        head = open(tika_path, encoding='utf-8', errors='replace').read(4096)
    except Exception:
        return None
    m = _ROC_DATE_RE.search(head)
    if not m:
        return None
    y, mo, d = int(m.group(1)), int(m.group(2)), int(m.group(3))
    return f'{y + 1911}-{mo:02d}-{d:02d}'


# ─── 日期索引：建立 date → [agenda_id, ...] 的對應表 ─────────────────────────

def build_date_index() -> dict[str, list[str]]:
    """
    掃描所有 meeting_type=dangtuan 的 agenda-anchor 檔，
    用 tikahtml 日期（非 gazette-agenda-data 的 meetingDate）建立索引。
    回傳 {'YYYY-MM-DD': ['agenda_id', ...]}
    """
    index: dict[str, list[str]] = {}
    for anchor_f in glob.glob(os.path.join(ANCHOR_DIR, '*.json')):
        try:
            meta = json.load(open(anchor_f))
        except Exception:
            continue
        if meta.get('meeting_type') != 'dangtuan':
            continue
        agenda_id = meta.get('agenda_id') or os.path.basename(anchor_f).replace('.json', '')
        date = tikahtml_date(agenda_id)
        if date:
            index.setdefault(date, []).append(agenda_id)
    return index


# ─── 單筆處理 ─────────────────────────────────────────────────────────────────

def process(vid: int, date_index: dict[str, list[str]]) -> tuple[bool, str]:
    data_path = os.path.join(IVOD_DIR, f'ivod-data/{vid}.json')
    if not os.path.exists(data_path):
        return False, 'no ivod-data'

    try:
        d = json.load(open(data_path))
    except Exception as e:
        return False, f'json error: {e}'

    if d.get('type') != 'Full':
        return False, 'not Full type'

    meet = d.get('meet') or {}
    if meet.get('type') != '黨團協商':
        return False, 'not 黨團協商 meet'

    # Only 11th-term IVODs: older ppg_meet_page_json SittingCappInfo links are
    # unreliable and point to wrong LCIDC01 IDs (e.g. committee meetings / 公聽會).
    if meet.get('term') != 11:
        return False, f'skipping pre-11th term (term={meet.get("term")})'

    ivod_date = d.get('date')  # 'YYYY-MM-DD'
    if not ivod_date:
        return False, 'no date'

    agenda_ids = date_index.get(ivod_date, [])
    if not agenda_ids:
        return False, f'no dangtuan anchor for date={ivod_date}'

    # 收集全場 anchors（同一天可能有多份文件，依 agenda_id 排序）
    all_anchors = []
    used_ids    = []
    for agenda_id in sorted(agenda_ids):
        try:
            a_data = json.load(open(os.path.join(ANCHOR_DIR, f'{agenda_id}.json')))
        except Exception:
            continue
        anchors = a_data.get('anchors', [])
        if not anchors:
            continue
        all_anchors.extend(anchors)
        used_ids.append(agenda_id)

    if not all_anchors:
        return False, f'no anchors in {agenda_ids}'

    clip_start = d.get('start_time')
    if not clip_start:
        return False, 'no start_time'

    whisper_words = load_whisper_words(vid)
    word_level    = bool(whisper_words)
    if not whisper_words:
        segs = d.get('transcript', {}).get('whisperx', [])
        whisper_words = [{'word': s['text'], 'start': s['start'], 'end': s['end']}
                         for s in segs if s.get('text')]
    if not whisper_words:
        return False, 'no whisper data'

    # 轉換 anchor 為 parse_blocks 格式（text 已含 "SPEAKER：content"）
    raw_blocks     = [[a['text']] for a in all_anchors]
    gazette_blocks = parse_blocks(raw_blocks)
    if not gazette_blocks:
        return False, 'parse_blocks empty'

    aligned = align(gazette_blocks, whisper_words)

    out = []
    for r in aligned:
        out.append({
            'speaker': r['speaker'],
            'text':    r['text'],
            'start':   rel_to_abs(clip_start, r['start_rel']),
            'end':     rel_to_abs(clip_start, r['end_rel']),
            'quality': r['quality'],
            'guessed': r['guessed'],
        })

    # 寫 ivod-gazette-align/{id}.json
    align_dir = os.path.join(IVOD_DIR, 'ivod-gazette-align')
    os.makedirs(align_dir, exist_ok=True)
    with open(os.path.join(align_dir, f'{vid}.json'), 'w', encoding='utf-8') as f:
        json.dump(out, f, ensure_ascii=False, separators=(',', ':'))

    # 同步寫回 ivod-data
    if 'transcript' not in d:
        d['transcript'] = {}
    d['transcript']['gazette'] = out
    features = d.get('features', [])
    if 'gazette' not in features:
        d['features'] = features + ['gazette']
    with open(data_path, 'w', encoding='utf-8') as f:
        json.dump(d, f, ensure_ascii=False, separators=(',', ':'))

    n_good    = sum(1 for r in out if not r['guessed'])
    n_guessed = sum(1 for r in out if r['guessed'])
    mode      = 'word' if word_level else 'seg'
    src       = ', '.join(used_ids)
    q_avg     = sum(r['quality'] for r in out) / max(len(out), 1)
    return True, (f'{len(out)} blocks ({n_good} good / {n_guessed} guessed) '
                  f'avg_q={q_avg:.2f} [{mode}-level] src={src}')


# ─── 主程式 ───────────────────────────────────────────────────────────────────

if __name__ == '__main__':
    force   = os.environ.get('force',   '0') != '0'
    limit   = int(os.environ.get('limit', 0))
    dry_run = os.environ.get('dry_run', '0') != '0'

    print('Building date index...', flush=True)
    date_index = build_date_index()
    print(f'  {len(date_index)} dates, {sum(len(v) for v in date_index.values())} agenda files')

    if len(sys.argv) > 1:
        vid = int(sys.argv[1])
        ok, msg = process(vid, date_index)
        print(f'{"OK" if ok else "SKIP"} {vid}: {msg}')
        sys.exit(0)

    # 批次：掃描所有 type=Full, meet.type=黨團協商 的 ivod-data
    full_ids = []
    for f in glob.glob(os.path.join(IVOD_DIR, 'ivod-data/*.json')):
        try:
            d = json.load(open(f))
            meet = d.get('meet') or {}
            if d.get('type') == 'Full' and meet.get('type') == '黨團協商':
                full_ids.append(int(os.path.basename(f).replace('.json', '')))
        except Exception:
            pass
    full_ids.sort()
    print(f'Found {len(full_ids)} 黨團協商 Full IVODs')

    done = skipped = errors = 0
    for vid in full_ids:
        if limit and done >= limit:
            break

        if not force:
            data_path = os.path.join(IVOD_DIR, f'ivod-data/{vid}.json')
            if os.path.exists(data_path):
                try:
                    d = json.load(open(data_path))
                    if d.get('transcript', {}).get('gazette'):
                        skipped += 1
                        continue
                except Exception:
                    pass

        if dry_run:
            try:
                d = json.load(open(os.path.join(IVOD_DIR, f'ivod-data/{vid}.json')))
                dt = d.get('date', '')
                ids = date_index.get(dt, [])
                if ids:
                    print(f'  {vid} {dt} → {ids}')
                    done += 1
            except Exception:
                pass
            continue

        try:
            ok, msg = process(vid, date_index)
            if ok:
                done += 1
                print(f'OK    {vid}: {msg}', flush=True)
            else:
                skipped += 1
                # 只印非預期的 skip
                if 'no dangtuan anchor' not in msg and 'not 黨' not in msg:
                    print(f'SKIP  {vid}: {msg}', flush=True)
        except Exception as e:
            errors += 1
            print(f'ERROR {vid}: {e}', flush=True)

    print(f'\ndone={done} skipped={skipped} errors={errors}')
