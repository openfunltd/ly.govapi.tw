#!/usr/bin/env python3
"""
upsert-chair-embed.py
幫院會主席（院長／副院長）建立具名聲紋。

院會主席跟委員會主席不同：委員會主席是輪值的召委，身分真的不固定，
所以 upsert-speaker-embed.py 刻意排除「主席」標籤；但院會主席是每場
公報開頭明確點名的（如「主　　席　韓院長國瑜」），人選固定在一小群人
裡，不需要用 gazette-align 就能知道是誰。

做法：
  1. 從該場次公報第一份子文件（agendaNo 最小）的標頭解析主席姓名
  2. 用幾句「只有主席會說」的高精準度片語（現在開會、報告表決結果…）
     在 whisper 逐字稿裡定位，加總跟 ivod-transcript 原始 pyannote
     diarization 的重疊秒數，找出哪個 SPEAKER_XX 是主席
  3. 再用時間重疊把這個 transcript SPEAKER_XX 對應回 ivod-pyannote 的
     embedding speaker（兩者編號不保證相同，見
     IVodParser::alignPyannoteIdentities 的說明）
  4. 把這個 embedding upsert 進 ES speaker_identity index（跟委員共用
     同一個 index，doc_id 用 "{term}:{公報標頭原文}"）

使用：
  python3 upsert-chair-embed.py               # 全部院會 Full
  python3 upsert-chair-embed.py --dry-run
  python3 upsert-chair-embed.py 17688          # 只處理單支（測試用）
"""
import argparse, base64, glob, json, os, re, sys
from datetime import date
from collections import defaultdict

import numpy as np
import requests
from requests.auth import HTTPBasicAuth

IVOD_DIR    = os.path.dirname(os.path.abspath(__file__))
GAZETTE_DIR = os.path.normpath(os.path.join(IVOD_DIR, '../gazette'))
TERM        = 11
MAX_RECENT  = 20

# 只有主席會說的高精準度片語（避免誤抓到委員引用這些話）
CHAIR_PHRASES = ['現在開會', '現在散會', '繼續開會', '現在休息', '報告表決結果']
_TITLE_RE = re.compile(r'院長|副院長')


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


def extract_chair_name(label):
    """'韓院長國瑜' → ('韓院長國瑜', '韓國瑜')；找不到職稱回傳 None"""
    m = _TITLE_RE.search(label)
    if not m:
        return None
    surname, given = label[:m.start()], label[m.end():]
    if not surname or not given:
        return None
    return label, surname + given


_GAZETTE_INDEX = None  # {(sessionPeriod, sessionTimes, date): [(agendaNo, docUrls), ...]}，首次用時建好快取


def _build_gazette_index():
    global _GAZETTE_INDEX
    if _GAZETTE_INDEX is not None:
        return _GAZETTE_INDEX
    index = {}
    for f in glob.glob(f'{GAZETTE_DIR}/gazette-agenda-data/*.json'):
        try:
            d = json.load(open(f))
        except Exception:
            continue
        sp, st = d.get('sessionPeriod'), d.get('sessionTimes')
        if sp is None or st is None:
            continue
        for date_str in (d.get('meetingDate') or []):
            index.setdefault((sp, st, date_str), []).append(
                (d.get('agendaNo', 999), d.get('gazette_id', ''), d.get('docUrls') or []))
    _GAZETTE_INDEX = index
    return index


def find_gazette_chair(date_str, session_period, session_times):
    """找該日期＋會期＋次數裡 (agendaNo, gazette_id) 最小的文件，解析標頭主席姓名。
    同一天可能有多個 gazette_id（不同冊別/附屬文件）agendaNo 都是 1，
    用 gazette_id 當 tie-break 取最小的那個（院會正式紀錄的 gazette_id 最小）。"""
    index = _build_gazette_index()
    candidates = index.get((session_period, session_times, date_str))
    if not candidates:
        return None
    candidates.sort(key=lambda c: (c[0], c[1]))
    doc_urls = candidates[0][2]
    if not doc_urls:
        return None
    fname = os.path.basename(doc_urls[0])
    txt_path = f'{GAZETTE_DIR}/agenda-txt/{fname}'
    if not os.path.exists(txt_path):
        return None
    head = open(txt_path, encoding='utf-8', errors='replace').read(1000)
    m = re.search(r'主\s*席\s+(\S+)', head)
    if not m:
        return None
    return extract_chair_name(m.group(1))


def dominant_transcript_speaker(pr, segs):
    """用主席專屬片語在 whisper 文字裡定位時間點，加總 pyannote 重疊找出 SPEAKER_XX"""
    ov = defaultdict(float)
    for s in segs:
        if not any(p in s['text'] for p in CHAIR_PHRASES):
            continue
        t0, t1 = s['start'] - 3, s['end'] + 3  # 留緩衝涵蓋實際開口時間
        for r in pr:
            o = max(0, min(t1, r[1]) - max(t0, r[0]))
            if o > 0:
                ov[r[2]] += o
    if not ov:
        return None
    return max(ov.items(), key=lambda x: x[1])[0]


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


def process_ivod(vid):
    data_path  = f'{IVOD_DIR}/ivod-data/{vid}.json'
    trans_path = f'{IVOD_DIR}/ivod-transcript/{vid}.json'
    pyann_path = f'{IVOD_DIR}/ivod-pyannote/{vid}.json'
    for p in (data_path, trans_path, pyann_path):
        if not os.path.exists(p):
            return None

    d = json.load(open(data_path))
    if d.get('type') != 'Full':
        return None
    meet = d.get('meet') or {}
    if meet.get('type') != '院會':
        return None
    date_str = (d.get('date') or '')[:10]
    sp, st = meet.get('sessionPeriod'), meet.get('sessionTimes')
    if not date_str or sp is None or st is None:
        return None

    chair = find_gazette_chair(date_str, sp, st)
    if not chair:
        return None
    label, person_name = chair

    t = json.load(open(trans_path))
    pr = ((t.get('pyannote') or {}).get('result') or {}).get('result') or []
    if not pr:
        return None
    try:
        wxj = json.loads(t['whisperx']['result']['json'])
    except Exception:
        return None
    segs = wxj.get('segments', [])

    transcript_label = dominant_transcript_speaker(pr, segs)
    if not transcript_label:
        return None

    pyann = json.load(open(pyann_path))
    eid = align_label(pr, pyann.get('speakers', []), transcript_label)
    if not eid:
        return None

    emb_speaker = next((s for s in pyann['speakers'] if s['id'] == eid), None)
    if not emb_speaker or not emb_speaker.get('embedding'):
        return None

    emb = decode_emb(emb_speaker['embedding'])
    return {
        'label': label, 'person_name': person_name,
        'embedding': l2norm(emb).tolist(),
        'seconds': emb_speaker.get('total_seconds', 0),
        'date': date_str, 'ivod_id': vid,
    }


def find_existing_doc_id(cfg, person_name):
    """
    先找有沒有這個人既有的 speaker_identity 紀錄（不論當初是用什麼 label
    建的，例如某位副院長本來就有一般委員身分的聲紋）——有的話合併進去，
    而不是另外開一筆「主席」專用的紀錄，避免同一個人身分被拆成兩筆。
    """
    index = f"{cfg['prefix']}speaker_identity"
    auth  = HTTPBasicAuth(cfg['user'], cfg['password'])
    url   = f"{cfg['url']}/{index}/_search"
    body  = {
        'query': {'bool': {'must': [
            {'term': {'term': TERM}},
            {'match': {'person_name': person_name}},
        ]}},
        'size': 5, '_source': ['person_name'],
    }
    try:
        r = requests.post(url, auth=auth, json=body, timeout=10)
        for hit in r.json().get('hits', {}).get('hits', []):
            if hit['_source'].get('person_name') == person_name:
                return hit['_id']
    except Exception as e:
        print(f'  find_existing_doc_id error: {e}', file=sys.stderr)
    return None


def upsert_to_es(cfg, doc_id, label, person_name, new_clips, dry_run):
    index = f"{cfg['prefix']}speaker_identity"
    auth  = HTTPBasicAuth(cfg['user'], cfg['password'])
    url   = f"{cfg['url']}/{index}/_doc/{doc_id}"

    r = requests.get(url, auth=auth, timeout=10)
    if r.status_code == 200:
        existing = r.json()['_source']
        recent, count = existing.get('recent_clips', []), existing.get('count', 0)
        label = existing.get('label', label)  # 沿用既有紀錄的 label，不要覆蓋成主席專用文字
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
    count += len(new_clips)

    recent.sort(key=lambda c: c['date'], reverse=True)
    recent = recent[:MAX_RECENT]

    arrs = [np.array(c['embedding'], dtype=np.float32) for c in recent]
    mean_emb = l2norm(np.mean(arrs, axis=0)).tolist()

    doc = {
        'term': TERM, 'label': label, 'person_name': person_name,
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
    ap.add_argument('vid', nargs='?', type=int, help='只處理單支影片（測試用）')
    ap.add_argument('--dry-run', action='store_true')
    args = ap.parse_args()

    if args.vid:
        ids = [args.vid]
    else:
        ids = []
        for f in glob.glob(f'{IVOD_DIR}/ivod-pyannote/*.json'):
            name = os.path.basename(f)[:-5]
            if name.isdigit() and len(name) <= 5:   # Full ID（5 碼或更短）
                ids.append(int(name))
        ids.sort()

    by_person = defaultdict(list)  # person_name → [clips]，以人為單位累積，不分「主席」「委員」
    label_of = {}
    for i, vid in enumerate(ids):
        r = process_ivod(vid)
        if r:
            by_person[r['person_name']].append(r)
            label_of[r['person_name']] = r['label']
            print(f"{vid}  {r['date']}  {r['label']}（{r['person_name']}）  {r['seconds']:.0f}s")
        if (i + 1) % 50 == 0:
            print(f'  ...{i + 1}/{len(ids)} scanned', file=sys.stderr)

    print(f'\n找到 {len(by_person)} 位主席，開始 upsert...')
    if not by_person:
        return
    cfg = load_config()
    for person_name, clips in by_person.items():
        label = label_of[person_name]
        doc_id = find_existing_doc_id(cfg, person_name) or f'{TERM}:{person_name}'
        added = upsert_to_es(cfg, doc_id, label, person_name, clips, args.dry_run)
        print(f'  {person_name} ({doc_id}): {len(clips)} 場, +{added} upsert')


if __name__ == '__main__':
    main()
