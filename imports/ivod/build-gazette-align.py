#!/usr/bin/env python3
"""
build-gazette-align.py
將公報 block 對齊 whisper 時間軸，寫入 ivod-data/{id}.json 的 transcript.gazette

使用方式：
  python3 build-gazette-align.py               # 處理所有尚未對齊的 ID
  python3 build-gazette-align.py 170780        # 單筆
  limit=200 python3 build-gazette-align.py     # 批次限制數量
  force=1   python3 build-gazette-align.py     # 強制重跑（覆蓋已有結果）

輸出格式（寫入 transcript.gazette）：
  [
    {
      "speaker": "李委員柏毅",
      "text": "謝謝，有請副主委。",
      "start": "2026-07-30T10:52:42+08:00",
      "end":   "2026-07-30T10:52:51+08:00",
      "quality": 1.0,
      "guessed": false
    },
    ...
  ]
  quality < 0.3 的 block 用前後 block 時間內插，guessed: true
"""

import json, re, sys, glob, os
from datetime import datetime, timedelta
from difflib import SequenceMatcher

IVOD_DIR = os.path.dirname(os.path.abspath(__file__))
QUALITY_THRESHOLD = 0.3   # 低於此值 → 推估時間
CHARS_PER_SEC     = 3.5   # 中文語速估算（無鄰居錨點時使用）
MIN_BLOCK_DUR     = 1.0   # 推估時最短 block 秒數


# ─── 文字正規化 ───────────────────────────────────────────────────────────────

_STRIP = re.compile(r'[，。、；：「」『』【】〔〕《》〈〉！？…—\-\s（）()0-9０-９]')

def clean(s):
    return _STRIP.sub('', s)


# ─── 公報 block 解析 ──────────────────────────────────────────────────────────

_HEADER_TIME = re.compile(r'^(.{1,12})：（\d+時\d+分.*?）(.*)$', re.DOTALL)
_HEADER_BARE = re.compile(r'^(.{1,12})：(.*)$', re.DOTALL)

def parse_blocks(raw_blocks):
    """
    raw_blocks: ivod-gazette blocks（list of str | list of list-of-str）
    returns: [{'speaker': str|None, 'text': str, 'clean': str}]
    """
    result = []
    for block in raw_blocks:
        lines = block if isinstance(block, list) else [block]
        speaker, parts = None, []
        for line in lines:
            m = _HEADER_TIME.match(line)
            if m:
                if not speaker: speaker = m.group(1)
                parts.append(m.group(2).strip())
                continue
            m2 = _HEADER_BARE.match(line)
            if m2:
                if not speaker: speaker = m2.group(1)
                parts.append(m2.group(2).strip())
            else:
                parts.append(line.strip())
        text  = ' '.join(p for p in parts if p)
        c     = clean(text)
        if c:
            result.append({'speaker': speaker, 'text': text, 'clean': c})
    return result


# ─── 時間軸對齊 ───────────────────────────────────────────────────────────────

def align(gazette_blocks, whisper_segs):
    """
    gazette_blocks: parse_blocks 輸出
    whisper_segs:   [{start, end, text}, ...] （相對秒數）
    returns: [{speaker, text, start_rel, end_rel, quality, guessed}, ...]
    """
    # 建立 whisper char → time 對應
    w_buf, w_spans, pos = [], [], 0
    for seg in whisper_segs:
        c = clean(seg.get('text', ''))
        if not c: continue
        w_spans.append((pos, pos + len(c), float(seg['start']), float(seg['end'])))
        w_buf.append(c)
        pos += len(c)
    w_text = ''.join(w_buf)

    # 建立 gazette char spans
    g_buf, g_spans, pos = [], [], 0
    for blk in gazette_blocks:
        c = blk['clean']
        g_spans.append((pos, pos + len(c)))
        g_buf.append(c)
        pos += len(c)
    g_text = ''.join(g_buf)

    def char_to_time(p, spans):
        for cs, ce, ts, te in spans:
            if cs <= p < ce:
                return ts + (p - cs) / max(ce - cs, 1) * (te - ts)
            if p < cs:
                return ts
        return spans[-1][3] if spans else None

    results = []

    if not w_text or not g_text:
        for blk in gazette_blocks:
            results.append({**blk, 'start_rel': None, 'end_rel': None,
                            'quality': 0.0, 'guessed': True})
        return results

    # SequenceMatcher 字元級 LCS
    sm   = SequenceMatcher(None, w_text, g_text, autojunk=False)
    g2w  = {}
    for tag, i1, i2, j1, j2 in sm.get_opcodes():
        if tag == 'equal':
            for k in range(j2 - j1):
                g2w[j1 + k] = i1 + k

    for blk, (gs, ge) in zip(gazette_blocks, g_spans):
        matched = [p for p in range(gs, ge) if p in g2w]
        q       = len(matched) / max(ge - gs, 1)
        if matched:
            t_s = char_to_time(g2w[matched[0]],  w_spans)
            t_e = char_to_time(g2w[matched[-1]], w_spans)
        else:
            t_s = t_e = None
        results.append({**blk, 'start_rel': t_s, 'end_rel': t_e,
                        'quality': round(q, 3), 'guessed': False})

    # 內插推估：對 quality < threshold 的 block 找前後錨點補時間
    _interpolate(results, gazette_blocks)

    return results


def _interpolate(results, gazette_blocks):
    """
    In-place：將 quality < QUALITY_THRESHOLD 的 block 用鄰居時間內插，
    並標記 guessed=True。
    """
    n = len(results)

    # 找連續的「需推估」區段 [i, j)
    i = 0
    while i < n:
        if results[i]['quality'] >= QUALITY_THRESHOLD:
            i += 1
            continue

        # 找區段結尾
        j = i
        while j < n and results[j]['quality'] < QUALITY_THRESHOLD:
            j += 1

        # 前後錨點
        prev_end   = results[i - 1]['end_rel']   if i > 0 else 0.0
        next_start = results[j]['start_rel']      if j < n else None

        count = j - i
        if prev_end is None:
            prev_end = 0.0

        if next_start is not None:
            # 在 prev_end 到 next_start 之間平均分配
            gap = max(next_start - prev_end, count * MIN_BLOCK_DUR)
            for rank, idx in enumerate(range(i, j)):
                results[idx]['start_rel'] = prev_end + gap * rank / count
                results[idx]['end_rel']   = prev_end + gap * (rank + 1) / count
        else:
            # 尾端：用字數估算語速
            cur = prev_end + 0.5
            for idx in range(i, j):
                char_len = len(gazette_blocks[idx]['clean'])
                dur = max(char_len / CHARS_PER_SEC, MIN_BLOCK_DUR)
                results[idx]['start_rel'] = cur
                results[idx]['end_rel']   = cur + dur
                cur = cur + dur + 0.3

        for idx in range(i, j):
            results[idx]['guessed'] = True

        i = j


# ─── 時間轉換 ─────────────────────────────────────────────────────────────────

def rel_to_abs(clip_start_iso, rel_sec):
    if rel_sec is None:
        return None
    dt = datetime.fromisoformat(clip_start_iso)
    return (dt + timedelta(seconds=rel_sec)).isoformat()


# ─── 單筆處理 ─────────────────────────────────────────────────────────────────

def process(vid):
    gazette_path = f'{IVOD_DIR}/ivod-gazette/{vid}.json'
    data_path    = f'{IVOD_DIR}/ivod-data/{vid}.json'

    if not os.path.exists(gazette_path):
        return False, 'no gazette'
    if not os.path.exists(data_path):
        return False, 'no ivod-data'

    g = json.load(open(gazette_path))
    d = json.load(open(data_path))

    blocks      = g.get('blocks', [])
    whisper_segs = d.get('transcript', {}).get('whisperx', [])
    clip_start  = d.get('start_time')

    if not blocks:
        return False, 'no gazette blocks'
    if not clip_start:
        return False, 'no start_time'

    gazette_blocks = parse_blocks(blocks)
    if not gazette_blocks:
        return False, 'parse_blocks empty'

    aligned = align(gazette_blocks, whisper_segs)

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

    if 'transcript' not in d:
        d['transcript'] = {}
    d['transcript']['gazette'] = out

    with open(data_path, 'w', encoding='utf-8') as f:
        json.dump(d, f, ensure_ascii=False, separators=(',', ':'))

    n_guessed = sum(1 for r in out if r['guessed'])
    return True, f'{len(out)} blocks ({n_guessed} guessed)'


# ─── 主程式 ───────────────────────────────────────────────────────────────────

if __name__ == '__main__':
    force = os.environ.get('force', '0') != '0'
    limit = int(os.environ.get('limit', 0))

    if len(sys.argv) > 1:
        # 單筆模式
        vid = int(sys.argv[1])
        ok, msg = process(vid)
        print(f'{"OK" if ok else "SKIP"} {vid}: {msg}')
        sys.exit(0)

    # 批次模式：掃描所有有 gazette 的 ID
    gazette_ids = sorted(
        int(os.path.basename(f).replace('.json', ''))
        for f in glob.glob(f'{IVOD_DIR}/ivod-gazette/*.json')
    )

    done = skipped = errors = 0
    for vid in gazette_ids:
        if limit and done >= limit:
            break

        # 檢查是否已有結果
        if not force:
            data_path = f'{IVOD_DIR}/ivod-data/{vid}.json'
            if os.path.exists(data_path):
                try:
                    d = json.load(open(data_path))
                    if d.get('transcript', {}).get('gazette'):
                        skipped += 1
                        continue
                except Exception:
                    pass

        try:
            ok, msg = process(vid)
            if ok:
                done += 1
                print(f'OK    {vid}: {msg}', flush=True)
            else:
                skipped += 1
        except Exception as e:
            errors += 1
            print(f'ERROR {vid}: {e}', flush=True)

    print(f'\ndone={done} skipped={skipped} errors={errors}')
