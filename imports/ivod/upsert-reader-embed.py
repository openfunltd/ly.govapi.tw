#!/usr/bin/env python3
"""
upsert-reader-embed.py
找出院會「宣讀人員」（唸黨團提案/議案內容的議事人員，不是委員也不是主席）
的聲紋，做跨場次分群，建立匿名但穩定的身分（宣讀人員1/2/3…）。

宣讀人員不像主席——公報從不會寫出他們的名字，是輪值的議事人員，不只
一位。沒有名字可用，所以做法改成：

  1. 在 whisper 逐字稿裡找「請…宣讀」到「宣讀完畢」的時間區間（whisper
     常把「議事人員」聽成「醫事員」，所以觸發詞只抓「宣讀」本身）
  2. 每個區間內，用跟 ivod-transcript 原始 pyannote diarization 的重疊
     秒數，找出哪個 SPEAKER_XX 在唸
  3. 時間重疊把這個 transcript SPEAKER_XX 對應回 ivod-pyannote 的
     embedding speaker（見 IVodParser::alignPyannoteIdentities 說明）
  4. 收集所有場次、所有區間對應到的 embedding，依 cosine 相似度做簡單
     貪婪分群──同一群視為同一人（人數未知；院會與各委員會的議事人員
     不同，所以掃全部 Full 影片，不只院會）
  5. 分群結果 upsert 進 speaker_identity（跟委員共用 index，但
     role='reader'、label 用占位名「宣讀人員1」這種，不是真實姓名）

品質控制（「請宣讀→完畢」只是弱標籤，收集時要嚴一點）：
  - 視窗長度 >= MIN_WINDOW_SEC，且 dominant speaker 要佔視窗 >= MIN_DOMINANCE
    （避免抓到主席說「請宣讀」那一句或委員插話）
  - 一個新群要在 >= MIN_SUPPORT_VIDEOS 場不同影片出現過才 upsert，
    避免一次性的雜訊變成永久 cluster
  - 重跑時先把 ES 既有 role=reader 的 doc 當群心載入，新樣本往既有群靠，
    編號（宣讀人員N / doc_id）才會跨次執行穩定，不會把兩個人的 clips 混進
    同一個 doc

這裡只負責「收集聲紋」；影片裡的 speaker 要不要標成宣讀人員，跟委員、
官員一樣統一走 kNN（IVodParser::identifySpeaker），內容規則只在 kNN
完全沒比對到時當 fallback。

使用：
  python3 upsert-reader-embed.py 17688 --dry-run   # 先測單支，只印偵測結果
  python3 upsert-reader-embed.py --dry-run         # 全部 Full（院會+委員會），只印分群結果
  python3 upsert-reader-embed.py                   # 正式 upsert
"""
import argparse, base64, glob, json, os, re, sys
from datetime import date
from collections import defaultdict

import numpy as np
import requests
from requests.auth import HTTPBasicAuth

IVOD_DIR   = os.path.dirname(os.path.abspath(__file__))
TERM       = 11
MAX_RECENT = 20
CLUSTER_THRESHOLD = 0.70  # cosine 相似度門檻，同 speaker_identity 既有的 MIN_SCORE 換算
MIN_WINDOW_SEC     = 60    # 宣讀視窗至少要這麼長才採樣
MIN_DOMINANCE      = 0.8   # dominant speaker 至少佔視窗這個比例
MIN_SUPPORT_VIDEOS = 2     # 新群至少要出現在幾場不同影片才 upsert


def load_config():
    cfg_path = os.path.join(IVOD_DIR, '../../config.php')
    out = os.popen(f"php -r \"include '{cfg_path}'; "
                   f"echo getenv('ELASTIC_URL').PHP_EOL.getenv('ELASTIC_USER').PHP_EOL."
                   f"getenv('ELASTIC_PASSWORD').PHP_EOL.getenv('ELASTIC_PREFIX');\"").read()
    lines = out.strip().split('\n')
    if len(lines) < 4:
        sys.exit(f'無法讀取 ES 設定: {out}')
    return {'url': lines[0], 'user': lines[1], 'password': lines[2], 'prefix': lines[3]}


def decode_emb(b64str):
    return np.frombuffer(base64.b64decode(b64str), dtype=np.float32).copy()


def l2norm(arr):
    n = np.linalg.norm(arr)
    return arr / (n + 1e-8)


def cosine(a, b):
    return float(np.dot(a, b) / (np.linalg.norm(a) * np.linalg.norm(b) + 1e-8))


def align_label(pr, pyann_speakers, transcript_label):
    """沿用 IVodParser::alignPyannoteIdentities 的時間重疊邏輯，只需單一 label 的對應"""
    t_segs = [[r[0], r[1]] for r in pr if r[2] == transcript_label]
    if not t_segs:
        return None
    t_total = sum(e - s for s, e in t_segs)
    best_eid, best_ov = None, 0.0
    for spk in pyann_speakers:
        e_total, ov = 0.0, 0.0
        for e0, e1 in spk.get('segments', []):
            e_total += e1 - e0
            for t0, t1 in t_segs:
                o = min(e1, t1) - max(e0, t0)
                if o > 0:
                    ov += o
        if ov > best_ov:
            ratio = ov / max(1, min(e_total, t_total))
            if ratio >= 0.3:
                best_ov, best_eid = ov, spk['id']
    return best_eid


def find_reading_windows(segs):
    """
    回傳 [(start, end), ...]：每次「請…宣讀」到「宣讀完畢」之間的時間區間。
    whisper 常把「議事人員」聽成「醫事員」，觸發詞只看「宣讀」本身，
    終止詞看「完畢」+「宣讀」同時出現在附近（含「宣讀完畢」本身）。
    """
    windows = []
    pending_start = None
    for s in segs:
        text = s['text']
        if '宣讀' in text and '完畢' not in text:
            pending_start = s['end']  # 觸發語句講完後，讀稿才真正開始
        elif '完畢' in text and pending_start is not None:
            if s['start'] - pending_start < 180:  # 180 秒內才視為同一次
                windows.append((pending_start, s['start']))
            pending_start = None
    return windows


def process_ivod(vid):
    """回傳這支影片裡每個偵測到且通過品質過濾的宣讀區間：[{eid, embedding, seconds, window, dominance}, ...]"""
    data_path  = f'{IVOD_DIR}/ivod-data/{vid}.json'
    trans_path = f'{IVOD_DIR}/ivod-transcript/{vid}.json'
    pyann_path = f'{IVOD_DIR}/ivod-pyannote/{vid}.json'
    for p in (data_path, trans_path, pyann_path):
        if not os.path.exists(p):
            return []

    d = json.load(open(data_path))
    if d.get('type') != 'Full':
        return []

    t = json.load(open(trans_path))
    pr = ((t.get('pyannote') or {}).get('result') or {}).get('result') or []
    if not pr:
        return []
    try:
        wxj = json.loads(t['whisperx']['result']['json'])
    except Exception:
        return []
    segs = wxj.get('segments', [])

    windows = find_reading_windows(segs)
    if not windows:
        return []

    pyann = json.load(open(pyann_path))
    speakers = pyann.get('speakers', [])

    results = []
    for w0, w1 in windows:
        wlen = w1 - w0
        if wlen < MIN_WINDOW_SEC:
            continue
        ov = defaultdict(float)
        for r in pr:
            o = max(0, min(w1, r[1]) - max(w0, r[0]))
            if o > 0:
                ov[r[2]] += o
        if not ov:
            continue
        transcript_label, dom_secs = max(ov.items(), key=lambda x: x[1])
        dominance = dom_secs / wlen
        if dominance < MIN_DOMINANCE:
            continue
        eid = align_label(pr, speakers, transcript_label)
        if not eid:
            continue
        emb_speaker = next((s for s in speakers if s['id'] == eid), None)
        if not emb_speaker or not emb_speaker.get('embedding'):
            continue
        results.append({
            'ivod_id': vid, 'window': (w0, w1), 'eid': eid,
            'embedding': decode_emb(emb_speaker['embedding']),
            'seconds': wlen, 'dominance': dominance,
        })
    return results


def load_existing_readers(cfg):
    """ES 裡既有的 role=reader doc，當分群的初始群心：[{doc_id, label, num, centroid}]"""
    index = f"{cfg['prefix']}speaker_identity"
    auth  = HTTPBasicAuth(cfg['user'], cfg['password'])
    r = requests.post(f"{cfg['url']}/{index}/_search", auth=auth, timeout=30, json={
        'size': 200, 'query': {'match': {'role': 'reader'}},
        '_source': ['label', 'embedding'],
    })
    out = []
    for h in r.json().get('hits', {}).get('hits', []):
        m = re.search(r'(\d+)$', h['_id'])
        out.append({
            'doc_id': h['_id'], 'label': h['_source']['label'],
            'num': int(m.group(1)) if m else 0,
            'centroid': l2norm(np.array(h['_source']['embedding'], dtype=np.float32)),
        })
    return out


def cluster_samples(samples, seeds=()):
    """
    以 (video, eid) 為單位先合併（同一支影片同一個 embed speaker 的多個區間
    取加權平均），再跨影片用 cosine 相似度做貪婪分群。
    seeds 是 ES 既有 reader doc 的群心，先放進 clusters，讓新樣本優先往既有群靠。
    回傳 [{centroid, members: [{ivod_id, eid, embedding, seconds}], seed: {...}|None}, ...]
    """
    by_video_eid = defaultdict(list)
    for s in samples:
        by_video_eid[(s['ivod_id'], s['eid'])].append(s)

    merged = []
    for (vid, eid), items in by_video_eid.items():
        total_w = sum(i['seconds'] for i in items)
        mean_emb = sum(i['embedding'] * i['seconds'] for i in items) / total_w
        merged.append({'ivod_id': vid, 'eid': eid, 'embedding': l2norm(mean_emb), 'seconds': total_w})

    merged.sort(key=lambda m: -m['seconds'])  # 時長長的先當群心，較穩定

    clusters = [{'centroid': sd['centroid'], 'members': [], 'seed': sd} for sd in seeds]
    for m in merged:
        best, best_sim = None, 0.0
        for c in clusters:
            sim = cosine(m['embedding'], c['centroid'])
            if sim > best_sim:
                best_sim, best = sim, c
        if best and best_sim >= CLUSTER_THRESHOLD:
            best['members'].append(m)
            # 既有 doc 的群心不隨新樣本漂移（它已經是 ES 裡 recent clips 的平均）
            if not best['seed']:
                arrs = [mm['embedding'] * mm['seconds'] for mm in best['members']]
                w = sum(mm['seconds'] for mm in best['members'])
                best['centroid'] = l2norm(sum(arrs) / w)
        else:
            clusters.append({'centroid': m['embedding'], 'members': [m], 'seed': None})
    return clusters


def upsert_to_es(cfg, doc_id, label, new_clips, dry_run):
    index = f"{cfg['prefix']}speaker_identity"
    auth  = HTTPBasicAuth(cfg['user'], cfg['password'])
    url   = f"{cfg['url']}/{index}/_doc/{doc_id}"

    r = requests.get(url, auth=auth, timeout=10)
    if r.status_code == 200:
        existing = r.json()['_source']
        recent, count = existing.get('recent_clips', []), existing.get('count', 0)
    else:
        recent, count = [], 0

    existing_ids = {c['ivod_id'] for c in recent}
    added = 0
    for clip in new_clips:
        if clip['ivod_id'] in existing_ids:
            continue
        recent.append(clip)
        existing_ids.add(clip['ivod_id'])
        added += 1
    count += added

    recent.sort(key=lambda c: c['date'], reverse=True)
    recent = recent[:MAX_RECENT]

    arrs = [np.array(c['embedding'], dtype=np.float32) for c in recent]
    mean_emb = l2norm(np.mean(arrs, axis=0)).tolist()

    doc = {
        'term': TERM, 'label': label, 'person_name': label, 'role': 'reader',
        'embedding': mean_emb, 'recent_clips': recent, 'count': count,
        'updated_at': date.today().isoformat(),
    }
    if dry_run:
        print(f'  [dry] {doc_id}: +{added} clips, recent={len(recent)}, count={count}')
        return added
    r = requests.put(url, auth=auth, json=doc, timeout=10)
    if r.status_code not in (200, 201):
        print(f'  ES error {r.status_code}: {r.text[:200]}', file=sys.stderr)
        return 0
    return added


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('vid', nargs='?', type=int, help='只處理單支影片（測試用，只印偵測結果不分群）')
    ap.add_argument('--dry-run', action='store_true')
    args = ap.parse_args()

    if args.vid:
        results = process_ivod(args.vid)
        if not results:
            print('找不到通過過濾的宣讀區間')
            return
        for r in results:
            w0, w1 = r['window']
            print(f"{args.vid}  {w0:.1f}-{w1:.1f} ({r['seconds']:.1f}s, dom={r['dominance']:.2f})  embed={r['eid']}")
        by_eid = defaultdict(float)
        for r in results:
            by_eid[r['eid']] += r['seconds']
        print('\n各 embed speaker 累積宣讀秒數：')
        for eid, secs in sorted(by_eid.items(), key=lambda x: -x[1]):
            print(f'  {eid}: {secs:.1f}s')
        return

    cfg = load_config()
    seeds = load_existing_readers(cfg)
    print(f'ES 既有 reader doc: {len(seeds)} 個 ({[s["label"] for s in seeds]})')

    ids = []
    for f in glob.glob(f'{IVOD_DIR}/ivod-pyannote/*.json'):
        name = os.path.basename(f)[:-5]
        if name.isdigit() and len(name) <= 5:
            ids.append(int(name))
    ids.sort()

    all_samples = []
    n_videos = 0
    for i, vid in enumerate(ids):
        r = process_ivod(vid)
        if r:
            n_videos += 1
        all_samples.extend(r)
        if (i + 1) % 100 == 0:
            print(f'  ...{i + 1}/{len(ids)} scanned', file=sys.stderr)

    print(f'\n掃描 {len(ids)} 支 Full，{n_videos} 支有合格宣讀區間，共 {len(all_samples)} 個樣本，開始分群...')
    clusters = cluster_samples(all_samples, seeds)
    clusters.sort(key=lambda c: (c['seed'] is None, -sum(m['seconds'] for m in c['members'])))

    next_num = max([s['num'] for s in seeds] + [0]) + 1
    plan = []  # (doc_id, label, cluster)
    skipped = []
    for c in clusters:
        vids = sorted(set(m['ivod_id'] for m in c['members']))
        total = sum(m['seconds'] for m in c['members'])
        if c['seed']:
            doc_id, label = c['seed']['doc_id'], c['seed']['label']
            tag = '既有'
        elif len(vids) >= MIN_SUPPORT_VIDEOS:
            doc_id, label = f'{TERM}:reader:{next_num}', f'宣讀人員{next_num}'
            next_num += 1
            tag = '新增'
        else:
            skipped.append((vids, total))
            continue
        plan.append((doc_id, label, c))
        print(f'{label} [{tag} {doc_id}]: {len(vids)} 場, 共 {total:.0f}s, videos={vids}')
    if skipped:
        print(f'\n未達 {MIN_SUPPORT_VIDEOS} 場、不建群的單一樣本 {len(skipped)} 個：')
        for vids, total in skipped:
            print(f'  videos={vids} {total:.0f}s')

    if args.dry_run:
        return

    for doc_id, label, c in plan:
        clips = []
        for m in c['members']:
            dp = f'{IVOD_DIR}/ivod-data/{m["ivod_id"]}.json'
            date_str = json.load(open(dp)).get('date', '')[:10]
            clips.append({'ivod_id': m['ivod_id'], 'date': date_str, 'embedding': m['embedding'].tolist()})
        added = upsert_to_es(cfg, doc_id, label, clips, args.dry_run)
        print(f'  {label} ({doc_id}): +{added} upsert')


if __name__ == '__main__':
    main()
