#!/usr/bin/env python3
"""
Batch-parse 質詢型 gazette agenda-txt files and save anchors.

For each gazette_agenda that links to a 質詢型 doc file:
  - Parse anchors from the doc file
  - Save to agenda-anchor/{agenda_id}.json

If multiple agenda_ids share the same doc, each gets the same anchors
(the full session's speaker turns).

Usage:
    python3 batch-parse-anchors.py               # process all
    python3 batch-parse-anchors.py --gazette-id 1131001
    python3 batch-parse-anchors.py --dry-run     # count only
    python3 batch-parse-anchors.py --force       # overwrite existing
"""

import os
import sys
import json
import argparse
import importlib.util

# load parse-gazette-anchors.py (hyphen in filename → manual import)
_spec = importlib.util.spec_from_file_location(
    'parse_gazette_anchors',
    os.path.join(os.path.dirname(__file__), 'parse-gazette-anchors.py'),
)
_mod = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_mod)
parse_anchors      = _mod.parse_anchors
detect_meeting_type = _mod.detect_meeting_type

BASE     = os.path.dirname(__file__)
META_DIR = os.path.join(BASE, 'gazette-agenda-data')
TXT_DIR  = os.path.join(BASE, 'agenda-txt')
OUT_DIR  = os.path.join(BASE, 'agenda-anchor')


def doc_local_path(url):
    fname = url.rstrip('/').split('/')[-1]
    return os.path.join(TXT_DIR, fname), fname


def process_agenda(meta, dry_run=False, force=False):
    """
    Return (status, anchor_count).
    status: 'ok' | 'skip-exists' | 'skip-not-questioning' | 'skip-no-doc' | 'error'
    """
    agenda_id = meta['agenda_id']
    out_path  = os.path.join(OUT_DIR, f'{agenda_id}.json')

    if not force and os.path.exists(out_path):
        return 'skip-exists', 0

    doc_urls = meta.get('docUrls', [])
    if not doc_urls:
        return 'skip-no-doc', 0

    # Collect anchors from all linked doc files (usually 1, sometimes many)
    all_anchors = []
    doc_names   = []
    for url in doc_urls:
        path, fname = doc_local_path(url)
        if not os.path.exists(path):
            continue
        try:
            text = open(path, encoding='utf-8', errors='replace').read()
        except Exception as e:
            continue
        mt = detect_meeting_type(text)
        if mt != 'questioning':
            continue
        anchors = parse_anchors(text)
        all_anchors.extend(anchors)
        doc_names.append(fname)

    if not all_anchors:
        return 'skip-not-questioning', 0

    # Re-sequence across concatenated docs
    for i, a in enumerate(all_anchors):
        a['seq'] = i + 1

    result = {
        'agenda_id':    agenda_id,
        'gazette_id':   meta.get('gazette_id'),
        'term':         meta.get('term'),
        'meeting_type': 'questioning',
        'doc_files':    doc_names,
        'anchor_count': len(all_anchors),
        'anchors':      all_anchors,
    }

    if not dry_run:
        os.makedirs(OUT_DIR, exist_ok=True)
        with open(out_path, 'w', encoding='utf-8') as f:
            json.dump(result, f, ensure_ascii=False)

    return 'ok', len(all_anchors)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--gazette-id', help='Process only this gazette_id')
    ap.add_argument('--dry-run',    action='store_true')
    ap.add_argument('--force',      action='store_true', help='Overwrite existing files')
    args = ap.parse_args()

    meta_files = sorted(os.listdir(META_DIR))
    if args.gazette_id:
        meta_files = [f for f in meta_files if f.startswith(args.gazette_id + '_')]

    stats = {'ok': 0, 'skip-exists': 0, 'skip-not-questioning': 0,
             'skip-no-doc': 0, 'error': 0}
    total_anchors = 0
    done = 0

    for fname in meta_files:
        if not fname.endswith('.json'):
            continue
        try:
            meta = json.load(open(os.path.join(META_DIR, fname)))
        except Exception:
            stats['error'] += 1
            continue

        status, cnt = process_agenda(meta, dry_run=args.dry_run, force=args.force)
        stats[status] += 1
        if status == 'ok':
            total_anchors += cnt
        done += 1
        if done % 500 == 0:
            print(f'{done}/{len(meta_files)} done, stats={stats}', flush=True)

    print(f'\nDone. Total={done} stats={stats} total_anchors={total_anchors}')


if __name__ == '__main__':
    main()
