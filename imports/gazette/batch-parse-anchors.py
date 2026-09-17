#!/usr/bin/env python3
"""
Batch-parse gazette agenda files and save anchors.

Handles three meeting types:
  - 質詢型:       reads agenda-txt/ plain-text files
  - 黨團協商(doc): reads agenda-tikahtml/ HTML files (legacy, doc format)
  - 黨團協商(pdf): reads dangtuan-tikahtml/ HTML files → dangtuan-anchor/

Usage:
    python3 batch-parse-anchors.py               # process all (質詢型 + doc 黨團協商)
    python3 batch-parse-anchors.py --gazette-id 1131001
    python3 batch-parse-anchors.py --dry-run     # count only
    python3 batch-parse-anchors.py --force       # overwrite existing
    python3 batch-parse-anchors.py --type dangtuan   # only 黨團協商 (doc)
    python3 batch-parse-anchors.py --type questioning # only 質詢型
    python3 batch-parse-anchors.py --dangtuan-pdf    # 黨團協商 PDF pipeline
"""

import os
import sys
import json
import argparse
import importlib.util
import re

def _load_module(name, rel_path):
    spec = importlib.util.spec_from_file_location(
        name, os.path.join(os.path.dirname(__file__), rel_path))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod

_q  = _load_module('parse_gazette_anchors',  'parse-gazette-anchors.py')
_dt = _load_module('parse_dangtuan_anchors', 'parse-dangtuan-anchors.py')

BASE              = os.path.dirname(__file__)
META_DIR          = os.path.join(BASE, 'gazette-agenda-data')
TXT_DIR           = os.path.join(BASE, 'agenda-txt')
TIKA_DIR          = os.path.join(BASE, 'agenda-tikahtml')
OUT_DIR           = os.path.join(BASE, 'agenda-anchor')
DT_DATA_DIR       = os.path.join(BASE, 'dangtuan-data')
DT_TIKA_DIR       = os.path.join(BASE, 'dangtuan-tikahtml')
DT_ANCHOR_DIR     = os.path.join(BASE, 'dangtuan-anchor')


def _doc_txt_path(url):
    fname = url.rstrip('/').split('/')[-1]
    return os.path.join(TXT_DIR, fname), fname


def process_agenda(meta, dry_run=False, force=False):
    """
    Return (status, anchor_count).
    Dispatches to 質詢型 or 黨團協商 parser based on import_source / doc format.
    status: 'ok' | 'skip-exists' | 'skip-not-questioning' | 'skip-no-doc' | 'error'
    """
    agenda_id = meta['agenda_id']
    out_path  = os.path.join(OUT_DIR, f'{agenda_id}.json')

    if not force and os.path.exists(out_path):
        return 'skip-exists', 0

    doc_urls = meta.get('docUrls', [])
    if not doc_urls:
        return 'skip-no-doc', 0

    # ── 黨團協商 path (tikahtml) ─────────────────────────────────────────────
    if meta.get('import_source') == '黨團協商':
        url   = doc_urls[0]
        fname = url.rstrip('/').split('/')[-1]
        tika_path = os.path.join(TIKA_DIR, fname + '.html')
        if not os.path.exists(tika_path):
            return 'skip-no-doc', 0
        try:
            with open(tika_path, encoding='utf-8', errors='replace') as f:
                html = f.read()
        except Exception:
            return 'error', 0
        paragraphs = _dt._extract_paragraphs(html)
        mt = _dt.detect_meeting_type(paragraphs)
        if mt != 'dangtuan':
            # Remove stale anchor file if it previously had meeting_type=dangtuan
            if os.path.exists(out_path):
                try:
                    old = json.load(open(out_path))
                    if old.get('meeting_type') == 'dangtuan':
                        os.remove(out_path)
                except Exception:
                    pass
            return 'skip-not-questioning', 0
        anchors = _dt.parse_anchors(paragraphs)
        if not anchors:
            return 'skip-not-questioning', 0
        result = {
            'agenda_id':    agenda_id,
            'gazette_id':   meta.get('gazette_id'),
            'term':         meta.get('term'),
            'meeting_type': 'dangtuan',
            'doc_files':    [fname],
            'anchor_count': len(anchors),
            'anchors':      anchors,
        }
        if not dry_run:
            os.makedirs(OUT_DIR, exist_ok=True)
            with open(out_path, 'w', encoding='utf-8') as f:
                json.dump(result, f, ensure_ascii=False)
        return 'ok', len(anchors)

    # ── 質詢型 path (agenda-txt) ─────────────────────────────────────────────
    all_anchors = []
    doc_names   = []
    for url in doc_urls:
        path, fname = _doc_txt_path(url)
        if not os.path.exists(path):
            continue
        try:
            text = open(path, encoding='utf-8', errors='replace').read()
        except Exception:
            continue
        mt = _q.detect_meeting_type(text)
        if mt != 'questioning':
            continue
        anchors = _q.parse_anchors(text)
        all_anchors.extend(anchors)
        doc_names.append(fname)

    if not all_anchors:
        return 'skip-not-questioning', 0

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


_ROC_TIME_RE = re.compile(r'時\s*間[^\n]*?(\d+)\s*時(?:\s*(\d+)\s*分)?')


def _extract_start_time(tika_path: str) -> str | None:
    """Parse meeting start time from PDF tika HTML header. Returns 'HH:MM' or None."""
    try:
        head = open(tika_path, encoding='utf-8', errors='replace').read(3000)
    except Exception:
        return None
    m = _ROC_TIME_RE.search(head)
    if not m:
        return None
    h  = int(m.group(1))
    mi = int(m.group(2) or 0)
    return f'{h:02d}:{mi:02d}'


def process_dangtuan_pdf_meeting(meeting_no: str, meeting_meta: dict,
                                  dry_run=False, force=False):
    """
    Parse all PDF tikahtml files for one 黨團協商 meeting, save to dangtuan-anchor/.
    Returns (status, anchor_count).
    """
    out_path = os.path.join(DT_ANCHOR_DIR, f'{meeting_no}.json')
    if not force and os.path.exists(out_path):
        return 'skip-exists', 0

    all_anchors: list  = []
    pdf_files: list    = []
    start_time: str | None = None

    for pdf_info in meeting_meta.get('pdfs', []):
        pdf_file  = pdf_info.get('pdf_file', '')
        tika_path = os.path.join(DT_TIKA_DIR, pdf_file + '.html')
        if not os.path.exists(tika_path):
            continue
        try:
            html = open(tika_path, encoding='utf-8', errors='replace').read()
        except Exception:
            continue
        paragraphs = _dt._extract_paragraphs_pdf(html)
        mt = _dt.detect_meeting_type(paragraphs)
        if mt != 'dangtuan':
            continue
        anchors = _dt.parse_anchors_pdf(paragraphs)
        all_anchors.extend(anchors)
        pdf_files.append(pdf_file)
        # Take start time from the first valid PDF
        if start_time is None:
            start_time = _extract_start_time(tika_path)

    if not all_anchors:
        return 'skip-no-anchors', 0

    for i, a in enumerate(all_anchors):
        a['seq'] = i + 1

    result = {
        'meeting_no':    meeting_no,
        'date':          meeting_meta.get('date'),
        'start_time':    start_time,
        'term':          meeting_meta.get('term'),
        'sessionPeriod': meeting_meta.get('sessionPeriod'),
        'meeting_type':  'dangtuan',
        'pdf_files':     pdf_files,
        'anchor_count':  len(all_anchors),
        'anchors':       all_anchors,
    }
    if not dry_run:
        os.makedirs(DT_ANCHOR_DIR, exist_ok=True)
        with open(out_path, 'w', encoding='utf-8') as f:
            json.dump(result, f, ensure_ascii=False)
    return 'ok', len(all_anchors)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--gazette-id',   help='Process only this gazette_id (質詢型/doc-dangtuan)')
    ap.add_argument('--dry-run',      action='store_true')
    ap.add_argument('--force',        action='store_true', help='Overwrite existing files')
    ap.add_argument('--type', choices=['questioning', 'dangtuan'],
                    help='Limit to one meeting type (gazette-agenda-data pipeline)')
    ap.add_argument('--dangtuan-pdf', action='store_true',
                    help='Run 黨團協商 PDF pipeline (dangtuan-data/ → dangtuan-anchor/)')
    args = ap.parse_args()

    # ── 黨團協商 PDF pipeline ────────────────────────────────────────────────
    if args.dangtuan_pdf:
        stats = {'ok': 0, 'skip-exists': 0, 'skip-no-anchors': 0, 'error': 0}
        total_anchors = 0
        for fname in sorted(os.listdir(DT_DATA_DIR)):
            if not fname.endswith('.json'):
                continue
            meeting_no = fname[:-5]
            try:
                meta = json.load(open(os.path.join(DT_DATA_DIR, fname)))
            except Exception:
                stats['error'] += 1
                continue
            status, cnt = process_dangtuan_pdf_meeting(
                meeting_no, meta, dry_run=args.dry_run, force=args.force)
            stats[status] = stats.get(status, 0) + 1
            if status == 'ok':
                total_anchors += cnt
                if not args.dry_run:
                    print(f'ok {meeting_no} {meta.get("date","")} anchors={cnt}')
        label = '[dry-run] ' if args.dry_run else ''
        print(f'\n{label}Done. stats={stats} total_anchors={total_anchors}')
        return

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

        # --type filter
        if args.type:
            is_dt = meta.get('import_source') == '黨團協商'
            if args.type == 'dangtuan' and not is_dt:
                continue
            if args.type == 'questioning' and is_dt:
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
