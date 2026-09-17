#!/usr/bin/env python3
"""
parse-sub-documents.py
解析 gazette-detail-html/{gazette_id}.html，提取每本公報的子文件 (doc, pdf, 頁碼, 日期)，
寫入 gazette-data/{gazette_id}.json 的 sub_documents 欄位。

Usage:
  python3 parse-sub-documents.py             # 更新所有
  python3 parse-sub-documents.py 1149101 ... # 更新指定 gazette_id
"""

import sys, json, os, re, glob
from bs4 import BeautifulSoup

BASE       = os.path.dirname(os.path.abspath(__file__))
HTML_DIR   = os.path.join(BASE, 'gazette-detail-html')
DATA_DIR   = os.path.join(BASE, 'gazette-data')


def parse_sub_documents(gazette_id):
    html_path = os.path.join(HTML_DIR, f'{gazette_id}.html')
    if not os.path.exists(html_path):
        return None
    html = open(html_path, encoding='utf-8').read()
    soup = BeautifulSoup(html, 'html.parser')

    results = []
    seen = set()
    for div in soup.find_all('div', class_=lambda c: c and 'd-grid' in c.split()):
        hrefs = [a['href'] for a in div.find_all('a', href=True)
                 if 'LCIDC01_' in a.get('href', '') and '_00' in a.get('href', '')]
        if not hrefs:
            continue
        docs = [h for h in hrefs if h.endswith('.doc')]
        pdfs = [h for h in hrefs if h.endswith('.pdf')]
        if not (docs and pdfs):
            continue

        # Grandparent contains the page range and date text
        p = div.parent
        gp = p.parent if p else None
        text = (gp or p).get_text(' ', strip=True) if (gp or p) else ''

        pages  = re.search(r'(\d+)-(\d+)頁', text)
        date_m = re.search(r'(\d{3})年(\d+)月(\d+)日', text)

        doc_suffixes = []
        doc_urls = []
        for d in docs:
            m = re.search(r'LCIDC01_\d+_(\d+)\.doc', d, re.IGNORECASE)
            if m:
                doc_suffixes.append(m.group(1))
                doc_urls.append(d)

        pdf_suffix = None
        pdf_url    = None
        for p2 in pdfs:
            m = re.search(r'LCIDC01_\d+_(\d+)\.pdf', p2, re.IGNORECASE)
            if m:
                pdf_suffix = m.group(1)
                pdf_url    = p2
                break

        if not doc_suffixes or not pdf_suffix:
            continue

        key = (tuple(sorted(doc_suffixes)), pdf_suffix)
        if key in seen:
            continue
        seen.add(key)

        entry = {
            'doc_suffixes': doc_suffixes,
            'doc_urls':     doc_urls,
            'pdf_suffix':   pdf_suffix,
            'pdf_url':      pdf_url,
        }
        if pages:
            entry['page_start'] = int(pages.group(1))
            entry['page_end']   = int(pages.group(2))
        if date_m:
            y  = int(date_m.group(1)) + 1911
            mo = int(date_m.group(2))
            da = int(date_m.group(3))
            entry['date'] = f'{y}-{mo:02d}-{da:02d}'

        results.append(entry)

    return results


def update_gazette(gazette_id):
    subs = parse_sub_documents(gazette_id)
    if subs is None:
        return False, 'no_html'

    data_path = os.path.join(DATA_DIR, f'{gazette_id}.json')
    if not os.path.exists(data_path):
        return False, 'no_data'

    gd = json.load(open(data_path, encoding='utf-8'))
    old = gd.get('sub_documents')
    if old == subs:
        return True, 'unchanged'

    gd['sub_documents'] = subs
    with open(data_path, 'w', encoding='utf-8') as f:
        json.dump(gd, f, ensure_ascii=False)
    return True, 'updated'


if __name__ == '__main__':
    if len(sys.argv) > 1:
        ids = sys.argv[1:]
    else:
        ids = [os.path.basename(p).replace('.html', '')
               for p in glob.glob(os.path.join(HTML_DIR, '*.html'))]

    updated = unchanged = skipped = 0
    for gid in ids:
        ok, status = update_gazette(gid)
        if status == 'updated':
            updated += 1
        elif status == 'unchanged':
            unchanged += 1
        else:
            skipped += 1
            print(f'  skip {gid}: {status}')

    print(f'Done: {updated} updated, {unchanged} unchanged, {skipped} skipped')
