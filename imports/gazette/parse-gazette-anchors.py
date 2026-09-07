#!/usr/bin/env python3
"""
Parse 質詢型 gazette agenda-txt files and extract speaker-turn anchors.

Usage:
    python3 parse-gazette-anchors.py <agenda_txt_file>
    python3 parse-gazette-anchors.py --gazette-id 1131001 --seq 3
    python3 parse-gazette-anchors.py --test          # run against known examples
"""

import re
import sys
import json
import os
import argparse

# ─────────────────────────────────────────────────────────────────────────────
# Patterns
# ─────────────────────────────────────────────────────────────────────────────

# 發言者行：姓名：（H時M分）內容  或  姓名：（H時）內容
_SPEAKER_TIME_RE = re.compile(
    r'^(?P<speaker>[^\n：]{1,20})：（(?P<h>\d+)時(?P<m>\d+)?分?）(?P<rest>.*)',
    re.MULTILINE,
)

# 從 label 中提取 person_name（去掉職稱）
# e.g. 王委員鴻薇→王鴻薇, 陳部長世凱→陳世凱, 韓院長國瑜→韓國瑜
_TITLE_RE = re.compile(
    r'^(?P<sur>.{1,2})(?:委員|部長|院長|主委|署長|局長|次長|主任|秘書長|總裁|副主委|主計長|司長|政務委員)'
    r'(?P<given>.{1,4})$'
)

# 書面質詢：（沒有時間）
_WRITTEN_RE = re.compile(r'^(?P<speaker>[^\n：]{2,20})書面質詢：', re.MULTILINE)

# 主席行（不帶時間）
_CHAIR_RE = re.compile(r'^主席[（(（]?[^：\n]*[）)]?：')

# 識別是否為空白/頁碼行
_BLANK_RE = re.compile(r'^\s*$')
_PAGE_RE  = re.compile(r'^\s*[－-]?\s*\d+\s*[－-]?\s*$')

# 議程斷點：休息、繼續開會、散會 等 → flush current anchor
_BREAK_RE = re.compile(r'^(?:休息|繼續開會|散會)[（(（]')


def parse_time(h_str, m_str):
    """Return (time_str, time_minutes)."""
    h = int(h_str)
    m = int(m_str) if m_str else 0
    return f'{h}時{m}分' if m else f'{h}時', h * 60 + m


def extract_person_name(speaker_label):
    """'王委員鴻薇' → '王鴻薇', '主席' → None"""
    if speaker_label == '主席':
        return None
    m = _TITLE_RE.match(speaker_label)
    if m:
        return m.group('sur') + m.group('given')
    return None


def is_speaker_line(line):
    """Return (speaker, time_str, time_minutes, rest) or None."""
    m = _SPEAKER_TIME_RE.match(line)
    if m:
        time_str, time_min = parse_time(m.group('h'), m.group('m'))
        return m.group('speaker'), time_str, time_min, m.group('rest')
    return None


def parse_anchors(text, max_nonspeaker_lines=150):
    """
    Parse 質詢型 gazette text.

    Returns a list of anchors:
        {
          'seq':          int,
          'speaker':      str,    # e.g. '王委員鴻薇'
          'person_name':  str|None,
          'time_str':     str,    # e.g. '9時1分'
          'time_minutes': int,    # minutes from midnight
          'text':         str,    # full block including official responses
          'is_chair':     bool,
        }

    Chair lines that announce speakers are NOT included as anchors.
    Chair lines that have substantive content (>30 chars) ARE included.

    max_nonspeaker_lines: if this many consecutive non-blank non-speaker lines
    accumulate, the current anchor is flushed (handles mixed 質詢+逐條審查 docs).
    """
    lines = text.splitlines()
    anchors = []
    seq = 0

    current_speaker = None
    current_time_str = None
    current_time_min = None
    current_lines = []
    nonspeaker_count = 0   # consecutive non-speaker lines since last speaker line

    def flush():
        nonlocal seq, current_speaker, current_time_str, current_time_min, current_lines, nonspeaker_count
        if current_speaker is None:
            current_lines = []
            nonspeaker_count = 0
            return
        text_block = '\n'.join(current_lines).strip()
        if not text_block:
            current_speaker = None
            current_lines = []
            nonspeaker_count = 0
            return
        seq += 1
        person_name = extract_person_name(current_speaker)
        anchors.append({
            'seq':          seq,
            'speaker':      current_speaker,
            'person_name':  person_name,
            'time_str':     current_time_str,
            'time_minutes': current_time_min,
            'text':         text_block,
            'is_chair':     current_speaker == '主席',
        })
        current_speaker = None
        current_time_str = None
        current_time_min = None
        current_lines = []
        nonspeaker_count = 0

    for line in lines:
        # skip blank/page-number lines
        if _BLANK_RE.match(line) or _PAGE_RE.match(line):
            if current_speaker and current_lines:
                current_lines.append('')  # preserve paragraph breaks
            continue

        # session break → flush current anchor block
        if _BREAK_RE.match(line):
            flush()
            continue

        parsed = is_speaker_line(line)
        if not parsed and current_speaker is not None:
            nonspeaker_count += 1
            if nonspeaker_count > max_nonspeaker_lines:
                flush()
                continue
        if parsed:
            flush()
            speaker, time_str, time_min, rest = parsed
            current_speaker = speaker
            current_time_str = time_str
            current_time_min = time_min
            nonspeaker_count = 0
            first_line = f'{speaker}：（{time_str}）{rest}'.rstrip()
            current_lines = [first_line] if first_line.rstrip('：').strip() else []
        else:
            if current_speaker is not None:
                current_lines.append(line)
            # Before first anchor: ignore header lines (時間、地點、主席...)

    flush()

    # Remove chair anchors that are just short announcements (請XX委員發言、謝謝XX)
    result = []
    for anchor in anchors:
        if anchor['is_chair']:
            clean = anchor['text'].replace('主席：', '').strip()
            if len(clean) < 40:
                continue  # skip short chair lines
        result.append(anchor)

    # Re-sequence
    for i, anchor in enumerate(result):
        anchor['seq'] = i + 1

    return result


def detect_meeting_type(text):
    """
    Detect if this text is 質詢型.
    Returns 'questioning' | 'review' | 'procedural' | 'unknown'
    """
    time_matches = len(_SPEAKER_TIME_RE.findall(text))
    if time_matches >= 3:
        return 'questioning'

    # 逐條審查型 features
    if re.search(r'第[一二三四五六七八九十百]+條', text):
        review_matches = len(re.findall(r'第[一二三四五六七八九十百]+條', text))
        if review_matches >= 5:
            return 'review'

    # 程序型
    if re.search(r'^[一二三四五六七八九十]+、', text, re.MULTILINE):
        proc_matches = len(re.findall(r'^[一二三四五六七八九十]+、', text, re.MULTILINE))
        if proc_matches >= 5 and time_matches < 2:
            return 'procedural'

    return 'unknown'


def parse_file(path):
    with open(path, encoding='utf-8', errors='replace') as f:
        text = f.read()

    meeting_type = detect_meeting_type(text)
    anchors = parse_anchors(text) if meeting_type == 'questioning' else []

    return {
        'path':         os.path.basename(path),
        'meeting_type': meeting_type,
        'anchor_count': len(anchors),
        'anchors':      anchors,
    }


# ─────────────────────────────────────────────────────────────────────────────
# CLI
# ─────────────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description='Parse 質詢型 gazette anchors')
    parser.add_argument('file', nargs='?', help='agenda-txt file path')
    parser.add_argument('--gazette-id', help='gazette_id (e.g. 1131001)')
    parser.add_argument('--seq', type=int, help='file seq within gazette')
    parser.add_argument('--test', action='store_true', help='run against known examples')
    parser.add_argument('--stats', action='store_true', help='show stats over all files')
    args = parser.parse_args()

    txt_dir = os.path.join(os.path.dirname(__file__), 'agenda-txt')

    if args.test:
        examples = [
            'LCIDC01_1131001_00003.doc',  # 施政質詢
            'LCIDC01_1131001_00004.doc',  # 國是論壇
        ]
        for fname in examples:
            path = os.path.join(txt_dir, fname)
            if not os.path.exists(path):
                print(f'MISSING: {path}')
                continue
            result = parse_file(path)
            print(f'\n=== {fname} ===')
            print(f'  meeting_type: {result["meeting_type"]}')
            print(f'  anchors: {result["anchor_count"]}')
            for a in result['anchors'][:5]:
                preview = a['text'][:80].replace('\n', ' ')
                print(f'  [{a["seq"]}] {a["speaker"]} ({a["time_str"]}) → {preview}…')
        return

    if args.stats:
        from collections import Counter
        types = Counter()
        total = 0
        for fname in os.listdir(txt_dir):
            if not fname.endswith('.doc'):
                continue
            path = os.path.join(txt_dir, fname)
            try:
                with open(path, encoding='utf-8', errors='replace') as f:
                    text = f.read()
                mt = detect_meeting_type(text)
                types[mt] += 1
                total += 1
            except Exception as e:
                types['error'] += 1
        print(f'Total files: {total}')
        for k, v in sorted(types.items(), key=lambda x: -x[1]):
            print(f'  {k}: {v} ({100*v/total:.1f}%)')
        return

    if args.gazette_id and args.seq:
        fname = f'LCIDC01_{args.gazette_id}_{args.seq:05d}.doc'
        path = os.path.join(txt_dir, fname)
    elif args.file:
        path = args.file
    else:
        parser.print_help()
        sys.exit(1)

    result = parse_file(path)
    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
