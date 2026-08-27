#!/usr/bin/env python3
"""
快速修正：對所有有 transcript.gazette 的 ivod-data 補上 'gazette' feature
"""
import json, glob, os

IVOD_DIR = os.path.dirname(os.path.abspath(__file__))
fixed = skipped = errors = 0

files = sorted(glob.glob(f'{IVOD_DIR}/ivod-data/*.json'))
for path in files:
    try:
        d = json.load(open(path))
        if not d.get('transcript', {}).get('gazette'):
            skipped += 1
            continue
        features = d.get('features', [])
        if 'gazette' in features:
            skipped += 1
            continue
        d['features'] = features + ['gazette']
        with open(path, 'w', encoding='utf-8') as f:
            json.dump(d, f, ensure_ascii=False, separators=(',', ':'))
        fixed += 1
        if fixed % 1000 == 0:
            print(f'fixed {fixed}...', flush=True)
    except Exception as e:
        print(f'ERROR {path}: {e}', flush=True)
        errors += 1

print(f'fixed={fixed} skipped={skipped} errors={errors}')
