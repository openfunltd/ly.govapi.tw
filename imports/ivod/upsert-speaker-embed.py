#!/usr/bin/env python3
"""
upsert-speaker-embed.py
掃描近 N 天有 gazette-align + pyannote 的 Clip IVOD，
將說話者 embedding upsert 進 ES speaker_identity index。

使用：
  python3 upsert-speaker-embed.py --days 50
  python3 upsert-speaker-embed.py --days 50 --dry-run
"""

import argparse, base64, glob, json, os, re, sys
from datetime import date, timedelta
from collections import defaultdict

import numpy as np
import requests
from requests.auth import HTTPBasicAuth

# ── 設定 ──────────────────────────────────────────────────────────────────────

IVOD_DIR     = os.path.dirname(os.path.abspath(__file__))
TERM         = 11
MAX_FULL_ID  = 17705
CLIP_MIN_ID  = 149103   # 第 11 屆 Clip 起點
MAX_RECENT   = 20       # recent_clips 最多保留幾筆
QUALITY_THR  = 0.5
DOMINANCE    = 0.5
MIN_SECS     = 2.0

# 職稱 regex（同 research-official-embed.py）
_TITLE_RE = re.compile(
    r'委員|院長|部長|署長|局長|主委|主任委員|副主任委員|代理主任委員|'
    r'秘書長|副秘書長|司長|處長|次長|副部長|副院長|副署長|副局長|副主委|'
    r'總裁|副總裁|總長|主計長|組長|參事|顧問|專委|執行長|總經理|董事長|'
    r'理事長|檢察總長|代理董事長|代理署長|代理秘書長|代理司長|代理局長'
)
# 主席是輪值，不建個人 embedding；含冒號的是解析錯誤
_SKIP_RE = re.compile(r'主席|：|:')


def load_config():
    """從 config.php 讀 ES 設定"""
    result = {}
    cfg_path = os.path.join(IVOD_DIR, '../../config.php')
    try:
        out = os.popen(f"php -r \"include '{cfg_path}'; "
                       f"echo getenv('ELASTIC_URL').PHP_EOL.getenv('ELASTIC_USER').PHP_EOL."
                       f"getenv('ELASTIC_PASSWORD').PHP_EOL.getenv('ELASTIC_PREFIX');\"").read()
        lines = out.strip().split('\n')
        result['url']      = lines[0]
        result['user']     = lines[1]
        result['password'] = lines[2]
        result['prefix']   = lines[3]
    except Exception as e:
        sys.exit(f'無法讀取 ES 設定: {e}')
    return result


def decode_emb(b64str):
    return np.frombuffer(base64.b64decode(b64str), dtype=np.float32).copy()

def encode_emb(arr):
    return base64.b64encode(arr.astype(np.float32).tobytes()).decode()

def l2norm(arr):
    n = np.linalg.norm(arr)
    return arr / (n + 1e-8)

def ts(iso):
    from datetime import datetime
    return datetime.fromisoformat(iso).timestamp()

def overlap(a0, a1, b0, b1):
    return max(0.0, min(a1, b1) - max(a0, b0))


def extract_person_name(label):
    """
    '黃委員國昌' → '黃國昌'
    '卓院長榮泰' → '卓榮泰'
    '張廖次長萬堅' → '張廖萬堅'
    先找職稱位置，職稱前是姓，職稱後是名。
    """
    m = _TITLE_RE.search(label)
    if not m:
        return label
    surname = label[:m.start()]
    given   = label[m.end():]
    return surname + given


def process_ivod(vid):
    """回傳 [{label, person_name, emb(np), seconds, date_str}] 或 []"""
    align_path = f'{IVOD_DIR}/ivod-gazette-align/{vid}.json'
    pyann_path = f'{IVOD_DIR}/ivod-pyannote/{vid}.json'
    data_path  = f'{IVOD_DIR}/ivod-data/{vid}.json'

    for p in (align_path, pyann_path, data_path):
        if not os.path.exists(p):
            return []

    align = json.load(open(align_path))
    pyann = json.load(open(pyann_path))
    data  = json.load(open(data_path))

    clip_start = data.get('start_time')
    if not clip_start:
        return []
    t0       = ts(clip_start)
    date_str = data.get('date', '')[:10]

    spk_list = []
    for spk in pyann.get('speakers', []):
        if not spk.get('embedding'):
            continue
        emb  = decode_emb(spk['embedding'])
        segs = [(t0 + s[0], t0 + s[1]) for s in spk.get('segments', [])]
        spk_list.append({'emb': emb, 'segs': segs})

    if not spk_list:
        return []

    results = []
    for block in align:
        if block.get('guessed'):
            continue
        if block.get('quality', 0) < QUALITY_THR:
            continue
        label = block.get('speaker', '')
        if not label or not _TITLE_RE.search(label):
            continue
        if _SKIP_RE.search(label):
            continue
        if not block.get('start') or not block.get('end'):
            continue

        b0  = ts(block['start'])
        b1  = ts(block['end'])
        dur = b1 - b0
        if dur < MIN_SECS:
            continue

        best, best_ov = None, 0.0
        for spk in spk_list:
            ov = sum(overlap(b0, b1, s, e) for s, e in spk['segs'])
            if ov > best_ov:
                best_ov, best = ov, spk

        if best is None or best_ov / dur < DOMINANCE:
            continue

        results.append({
            'label':       label,
            'person_name': extract_person_name(label),
            'emb':         best['emb'],
            'seconds':     best_ov,
            'date_str':    date_str,
        })

    return results


def compute_mean_emb(clips):
    """clips: [{embedding: [float,...], ...}] → L2-normed mean (list)"""
    arrs = [np.array(c['embedding'], dtype=np.float32) for c in clips]
    mean = np.mean(arrs, axis=0)
    return l2norm(mean).tolist()


def upsert_to_es(cfg, doc_id, label, person_name, new_clips, total_count, dry_run):
    """
    Fetch existing doc, merge new_clips into recent_clips (dedup by ivod_id),
    recompute embedding, PUT back.
    new_clips: [{ivod_id, date, embedding(list)}]
    """
    index = f"{cfg['prefix']}speaker_identity"
    auth  = HTTPBasicAuth(cfg['user'], cfg['password'])
    url   = f"{cfg['url']}/{index}/_doc/{doc_id}"

    # 讀現有 doc
    r = requests.get(url, auth=auth, timeout=10)
    if r.status_code == 200:
        existing = r.json()['_source']
        recent   = existing.get('recent_clips', [])
        count    = existing.get('count', 0)
    else:
        recent = []
        count  = 0

    existing_ids = {c['ivod_id'] for c in recent}
    added = 0
    for clip in new_clips:
        if clip['ivod_id'] in existing_ids:
            continue
        recent.append(clip)
        existing_ids.add(clip['ivod_id'])
        added += 1

    count += total_count

    # 按 date 排序，保留最新的 MAX_RECENT 筆
    recent.sort(key=lambda c: c['date'], reverse=True)
    recent = recent[:MAX_RECENT]

    mean_emb = compute_mean_emb(recent)

    doc = {
        'term':        TERM,
        'label':       label,
        'person_name': person_name,
        'embedding':   mean_emb,
        'recent_clips': recent,
        'count':       count,
        'updated_at':  date.today().isoformat(),
    }

    if dry_run:
        print(f'  [dry] {doc_id}: +{added} clips, recent={len(recent)}, count={count}')
        return added

    r = requests.put(url, auth=auth, json=doc, timeout=10)
    if r.status_code not in (200, 201):
        print(f'  ES error {r.status_code}: {r.text[:200]}', file=sys.stderr)
        return 0
    return added


# ── 主程式 ────────────────────────────────────────────────────────────────────

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--days',    type=int, default=50, help='掃描最近幾天')
    ap.add_argument('--dry-run', action='store_true', help='不寫入 ES')
    args = ap.parse_args()

    cfg = load_config()

    cutoff = (date.today() - timedelta(days=args.days)).isoformat()
    print(f'掃描 {cutoff} 以後、有 gazette-align + pyannote 的 Clip...')

    # 找符合的 IVOD（Clip，第 11 屆）
    align_ids = set()
    for f in glob.glob(f'{IVOD_DIR}/ivod-gazette-align/*.json'):
        try: align_ids.add(int(os.path.basename(f).replace('.json', '')))
        except: pass

    pyann_ids = set()
    for f in glob.glob(f'{IVOD_DIR}/ivod-pyannote/*.json'):
        stem = os.path.basename(f).replace('.json', '')
        try: pyann_ids.add(int(stem))
        except: pass

    common = sorted(align_ids & pyann_ids)
    clip_ids = [v for v in common if v > MAX_FULL_ID and v >= CLIP_MIN_ID]

    # 過濾日期
    target_ids = []
    for vid in clip_ids:
        dp = f'{IVOD_DIR}/ivod-data/{vid}.json'
        if not os.path.exists(dp):
            continue
        try:
            d = json.load(open(dp))
            if d.get('date', '')[:10] >= cutoff:
                target_ids.append(vid)
        except:
            pass

    print(f'符合條件的 IVOD: {len(target_ids)} 支')

    # 收集 embedding，按 label 分組
    # label → {ivod_id: {emb(weighted avg), seconds, date}}
    label_clips = defaultdict(dict)   # label → {ivod_id: clip_info}
    label_names = {}                  # label → person_name
    label_count = defaultdict(int)    # label → block 數

    for i, vid in enumerate(target_ids):
        samples = process_ivod(vid)
        if not samples:
            continue

        # 同一 IVOD 同一 label，取加權平均 embedding
        vid_label = defaultdict(list)
        for s in samples:
            vid_label[s['label']].append((s['emb'], s['seconds']))
            label_names[s['label']] = s['person_name']
            label_count[s['label']] += 1

        for label, pairs in vid_label.items():
            total_w = sum(w for _, w in pairs)
            mean_emb = sum(e * w for e, w in pairs) / total_w
            mean_emb = l2norm(mean_emb)
            # 找日期
            dp = f'{IVOD_DIR}/ivod-data/{vid}.json'
            date_str = json.load(open(dp)).get('date', '')[:10]
            label_clips[label][vid] = {
                'ivod_id':   vid,
                'date':      date_str,
                'embedding': mean_emb.tolist(),
            }

        if (i + 1) % 200 == 0:
            print(f'  {i+1}/{len(target_ids)} scanned')

    print(f'\n找到 {len(label_clips)} 個說話者，開始 upsert...')

    total_added = 0
    for label in sorted(label_clips.keys()):
        person_name = label_names[label]
        doc_id      = f'{TERM}:{label}'
        new_clips   = list(label_clips[label].values())
        count       = label_count[label]

        added = upsert_to_es(cfg, doc_id, label, person_name, new_clips, count,
                             dry_run=args.dry_run)
        total_added += added

    print(f'\n完成：upsert {len(label_clips)} 人，新增 {total_added} 筆 recent_clips')


if __name__ == '__main__':
    main()
