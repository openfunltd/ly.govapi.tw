#!/usr/bin/env python3
"""
Parse 黨團協商 gazette tikahtml files and extract speaker-turn anchors.

Two input formats:
  doc (.doc.html):  tika from unoserver→docx; has <p class="內文"> + <b>NAME：</b>
  pdf (.pdf.html):  tika from PDF; plain <p> tags, speaker label embedded in text

Usage:
    python3 parse-dangtuan-anchors.py <tikahtml_file>
    python3 parse-dangtuan-anchors.py --agenda-id 1157202_00007
    python3 parse-dangtuan-anchors.py --test
    python3 parse-dangtuan-anchors.py --stats
"""

import re
import sys
import json
import os
import argparse
from html.parser import HTMLParser


# ─────────────────────────────────────────────────────────────────────────────
# Person name extraction
# ─────────────────────────────────────────────────────────────────────────────

_TITLE_LIST = (
    '委員', '部長', '院長', '主委', '署長', '局長', '次長', '主任',
    '秘書長', '總裁', '副主委', '主計長', '司長', '政務委員', '召集人',
    '政委', '理事長', '執行長', '處長', '主席', '召委', '副署長',
    '副局長', '副主任', '副秘書長',
)
_TITLES_PAT = '|'.join(_TITLE_LIST)

# Pattern 1: title in middle: 廖司長高賢, 王委員鴻薇
_TITLE_MID_RE = re.compile(
    r'^(?P<sur>[一-鿿]{1,2})'
    r'(?:' + _TITLES_PAT + r')'
    r'(?P<given>[一-鿿]{1,4})$'
)

# Pattern 2: CJK name (+ optional romanized suffix) + title at end
# e.g. 伍麗華Saidhai Tahovecahe委員, 謝龍介委員, 葉委員元之 won't match this
_TITLE_END_RE = re.compile(
    r'^([一-鿿]{2,5})'             # CJK name
    r'(?:[A-Za-z\s·À-ɏ]*)?'     # optional romanized name
    r'(?:' + _TITLES_PAT + r')$'
)


def extract_person_name(speaker_label: str) -> str | None:
    """
    '廖司長高賢' → '廖高賢'
    '伍麗華Saidhai Tahovecahe委員' → '伍麗華'
    '主席' → None
    '葉委員元之' → '葉元之'
    """
    if speaker_label == '主席':
        return None
    m = _TITLE_MID_RE.match(speaker_label)
    if m:
        return m.group('sur') + m.group('given')
    m = _TITLE_END_RE.match(speaker_label)
    if m:
        return m.group(1)
    # Fallback: CJK characters only
    cjk = re.sub(r'[^一-鿿]', '', speaker_label)
    return cjk or speaker_label


# ─────────────────────────────────────────────────────────────────────────────
# HTML paragraph extractor
# ─────────────────────────────────────────────────────────────────────────────

class _ParagraphExtractor(HTMLParser):
    """
    Extract paragraphs from tika HTML.
    Each paragraph is {'cls': str, 'speaker': str|None, 'text': str}
    where speaker is set when the paragraph opens with <b>NAME：</b>.
    """

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.paragraphs = []
        self._in_p = False
        self._p_class = ''
        self._in_bold = False
        self._bold_done = False      # only first <b> in a <p> matters
        self._pre_bold_text = ''     # text accumulated before first <b>
        self._bold_buf: list[str] = []
        self._text_buf: list[str] = []

    def handle_starttag(self, tag, attrs):
        if tag == 'p':
            self._in_p = True
            self._p_class = dict(attrs).get('class', '')
            self._in_bold = False
            self._bold_done = False
            self._pre_bold_text = ''
            self._bold_buf = []
            self._text_buf = []
        elif tag == 'b' and self._in_p and not self._bold_done:
            # Record text seen before this <b>
            self._pre_bold_text = ''.join(self._text_buf).strip()
            self._in_bold = True

    def handle_endtag(self, tag):
        if tag == 'b' and self._in_bold:
            self._in_bold = False
            self._bold_done = True
        elif tag == 'p' and self._in_p:
            bold_text = ''.join(self._bold_buf).strip()
            full_text = ''.join(self._text_buf).strip()
            # Speaker: first <b>...</b> with no prior text, ending in ：
            speaker = None
            if not self._pre_bold_text and bold_text.endswith('：'):
                speaker = bold_text[:-1].strip()
            self.paragraphs.append({
                'cls':     self._p_class,
                'speaker': speaker,
                'text':    full_text,
            })
            self._in_p = False

    def handle_data(self, data):
        if not self._in_p:
            return
        if self._in_bold:
            self._bold_buf.append(data)
        self._text_buf.append(data)


def _extract_paragraphs(html_text: str) -> list[dict]:
    p = _ParagraphExtractor()
    p.feed(html_text)
    return p.paragraphs


# ─────────────────────────────────────────────────────────────────────────────
# PDF paragraph extractor (plain <p> tags, no CSS classes)
# ─────────────────────────────────────────────────────────────────────────────

def _detect_pdf_speaker(text: str) -> str | None:
    """Return speaker label if paragraph starts with NAME：, else None."""
    if '：' not in text:
        return None
    label = text.split('：', 1)[0].strip()
    if len(label) > 20:
        return None
    if label == '主席':
        return label
    if not re.search('[一-鿿]', label):
        return None
    if any(t in label for t in _TITLE_LIST):
        return label
    # Short CJK-only name (2–3 chars) without explicit title
    if re.match(r'^[一-鿿]{2,3}$', label):
        return label
    return None


class _PdfParagraphExtractor(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.paragraphs = []
        self._in_p = False
        self._buf: list[str] = []

    def handle_starttag(self, tag, attrs):
        if tag == 'p':
            self._in_p = True
            self._buf = []

    def handle_endtag(self, tag):
        if tag == 'p' and self._in_p:
            text = ''.join(self._buf).strip()
            if text:
                speaker = _detect_pdf_speaker(text)
                self.paragraphs.append({'cls': '', 'speaker': speaker, 'text': text})
            self._in_p = False

    def handle_data(self, data):
        if self._in_p:
            self._buf.append(data)


def _extract_paragraphs_pdf(html_text: str) -> list[dict]:
    p = _PdfParagraphExtractor()
    p.feed(html_text)
    return p.paragraphs


# ─────────────────────────────────────────────────────────────────────────────
# Session-break markers (flush current anchor, don't start new speaker)
# ─────────────────────────────────────────────────────────────────────────────

_BREAK_LABELS = {'休息', '繼續開會', '散會'}

# CSS classes that belong to the header / agenda section (before discussion)
_HEADER_CLASSES = {
    '主席(多位主席)', '朝野協商(次標題)', '朝野協商(內文)',
    '頁首', '列席官員(多位官員)', '出席委員', '請假委員',
}

# CSS classes for content we want to skip entirely
_SKIP_CLASSES = {
    '提案連署人', '圖片內文', '頁首',
    '報告事項(項目符號)',
}


# ─────────────────────────────────────────────────────────────────────────────
# Core parser
# ─────────────────────────────────────────────────────────────────────────────

def detect_meeting_type(paragraphs: list[dict]) -> str:
    """
    Return 'dangtuan' if the paragraphs look like a 黨團協商 transcript.

    Uses title text as the primary signal: real 黨團協商 docs have '黨團協商'
    in the first few paragraphs (e.g. '黨團協商紀錄' or
    '立法院第11屆第5會期黨團協商會議紀錄').

    CSS classes ('朝野協商(次標題)', '朝野協商(內文)') are NOT reliable — they
    appear in committee meetings and public hearings too, used as generic
    sub-heading styles for time/location or attendance metadata.
    """
    for p in paragraphs[:10]:
        if '黨團協商' in p['text']:
            return 'dangtuan'
    return 'unknown'


def parse_anchors(paragraphs: list[dict]) -> list[dict]:
    """
    Parse speaker-turn anchors from 黨團協商 paragraphs.

    Returns a list of anchors:
        {
          'seq':          int,
          'speaker':      str,       # e.g. '廖司長高賢'
          'person_name':  str|None,
          'time_str':     None,      # no timestamps in 黨團協商
          'time_minutes': None,
          'text':         str,
          'is_chair':     bool,
        }
    """
    anchors: list[dict] = []
    seq = 0
    current_speaker: str | None = None
    current_lines: list[str] = []

    def flush():
        nonlocal seq, current_speaker, current_lines
        if current_speaker is None:
            current_lines = []
            return
        text_block = '\n'.join(current_lines).strip()
        if text_block:
            seq += 1
            anchors.append({
                'seq':          seq,
                'speaker':      current_speaker,
                'person_name':  extract_person_name(current_speaker),
                'time_str':     None,
                'time_minutes': None,
                'text':         text_block,
                'is_chair':     current_speaker == '主席',
            })
        current_speaker = None
        current_lines = []

    # Track whether we've passed the header/agenda section
    past_header = False

    for para in paragraphs:
        cls  = para['cls']
        text = para['text'].strip()
        if not text:
            continue

        # Skip non-content classes
        if cls in _SKIP_CLASSES:
            continue

        # Header classes: agenda / time / location block
        if cls in _HEADER_CLASSES:
            continue

        # After header classes, we're in content mode
        # (朝野協商(次標題/內文) paragraphs mark the agenda section)
        if '朝野協商' in cls:
            past_header = True
            continue

        if cls != '內文':
            # Other classes (e.g. 議事時間, table content): add to current anchor if present
            if current_speaker is not None:
                current_lines.append(text)
            continue

        # cls == '內文'
        speaker = para['speaker']

        # Session break markers (休息, 繼續開會, 散會)
        # These appear as <b>休息</b>（...）without ：
        first_bold_word = text.split('（')[0].split('　')[0].strip()
        if first_bold_word in _BREAK_LABELS and not speaker:
            flush()
            continue

        if speaker:
            flush()
            current_speaker = speaker
            # Text of paragraph = full text (includes speaker label)
            current_lines = [text]
        elif current_speaker is not None:
            # Continuation of current speaker's turn
            current_lines.append(text)
        else:
            # Before first speaker (header prose, intro text): skip
            if past_header:
                # These are chair narration paragraphs before the first labeled speaker
                # Attribute to implicit 主席 context? Better to skip for now.
                pass

    flush()

    # Remove short chair-only announcements (noise)
    result = []
    for a in anchors:
        if a['is_chair']:
            clean = re.sub(r'^主席：', '', a['text']).strip()
            if len(clean) < 15:
                continue
        result.append(a)

    # Re-sequence
    for i, a in enumerate(result):
        a['seq'] = i + 1

    return result


def parse_anchors_pdf(paragraphs: list[dict]) -> list[dict]:
    """
    Parse speaker-turn anchors from PDF 黨團協商 paragraphs.
    Simpler than parse_anchors(): no CSS class filtering, speaker from text prefix.
    """
    anchors: list[dict] = []
    seq = 0
    current_speaker: str | None = None
    current_lines: list[str] = []

    def flush():
        nonlocal seq, current_speaker, current_lines
        if current_speaker is None:
            current_lines = []
            return
        text_block = '\n'.join(current_lines).strip()
        if text_block:
            seq += 1
            anchors.append({
                'seq':          seq,
                'speaker':      current_speaker,
                'person_name':  extract_person_name(current_speaker),
                'time_str':     None,
                'time_minutes': None,
                'text':         text_block,
                'is_chair':     current_speaker == '主席',
            })
        current_speaker = None
        current_lines = []

    for para in paragraphs:
        text    = para['text'].strip()
        speaker = para['speaker']

        if speaker:
            flush()
            current_speaker = speaker
            current_lines   = [text]
        elif current_speaker is not None:
            current_lines.append(text)
        # else: before first speaker (header lines) — skip

    flush()

    # Remove short chair announcements (noise)
    result = []
    for a in anchors:
        if a['is_chair']:
            clean = re.sub(r'^主席：', '', a['text']).strip()
            if len(clean) < 15:
                continue
        result.append(a)

    for i, a in enumerate(result):
        a['seq'] = i + 1

    return result


# ─────────────────────────────────────────────────────────────────────────────
# File-level entry point
# ─────────────────────────────────────────────────────────────────────────────

def parse_file(path: str) -> dict:
    with open(path, encoding='utf-8', errors='replace') as f:
        html = f.read()
    is_pdf = path.endswith('.pdf.html')
    paragraphs = _extract_paragraphs_pdf(html) if is_pdf else _extract_paragraphs(html)
    meeting_type = detect_meeting_type(paragraphs)
    anchors = []
    if meeting_type == 'dangtuan':
        anchors = parse_anchors_pdf(paragraphs) if is_pdf else parse_anchors(paragraphs)
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
    ap = argparse.ArgumentParser(description='Parse 黨團協商 gazette tikahtml anchors')
    ap.add_argument('file', nargs='?', help='tikahtml file path')
    ap.add_argument('--agenda-id', help='agenda_id, e.g. 1157202_00007')
    ap.add_argument('--test',  action='store_true', help='run against known examples')
    ap.add_argument('--stats', action='store_true', help='show stats over all tikahtml files')
    args = ap.parse_args()

    base_dir   = os.path.dirname(__file__)
    tika_dir   = os.path.join(base_dir, 'agenda-tikahtml')
    meta_dir   = os.path.join(base_dir, 'gazette-agenda-data')

    if args.test:
        examples = ['LCIDC01_1157202_00007.doc.html']
        for fname in examples:
            path = os.path.join(tika_dir, fname)
            if not os.path.exists(path):
                print(f'MISSING: {path}')
                continue
            result = parse_file(path)
            print(f'\n=== {fname} ===')
            print(f'  meeting_type : {result["meeting_type"]}')
            print(f'  anchor_count : {result["anchor_count"]}')
            for a in result['anchors'][:8]:
                preview = a['text'][:100].replace('\n', ' ')
                print(f'  [{a["seq"]:2d}] {a["speaker"]} (→{a["person_name"]}) │ {preview}…')
        return

    if args.stats:
        from collections import Counter
        import glob
        counts = Counter()
        meta_dt = set()
        for f in glob.glob(os.path.join(meta_dir, '*.json')):
            try:
                m = json.load(open(f))
                if m.get('import_source') == '黨團協商':
                    meta_dt.add(os.path.basename(f).replace('.json', ''))
            except Exception:
                pass
        for fname in os.listdir(tika_dir):
            if not fname.endswith('.doc.html'):
                continue
            agenda_id = fname.replace('LCIDC01_', '').replace('.doc.html', '')
            if agenda_id not in meta_dt:
                continue
            path = os.path.join(tika_dir, fname)
            try:
                result = parse_file(path)
                counts[result['meeting_type']] += 1
                if result['meeting_type'] == 'dangtuan':
                    counts['total_anchors'] += result['anchor_count']
            except Exception as e:
                counts['error'] += 1
                print(f'ERROR {fname}: {e}')
        print(dict(counts))
        return

    if args.agenda_id:
        fname = f'LCIDC01_{args.agenda_id}.doc.html'
        path  = os.path.join(tika_dir, fname)
    elif args.file:
        path  = args.file
    else:
        ap.print_help()
        sys.exit(1)

    result = parse_file(path)
    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
