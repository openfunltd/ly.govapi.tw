#!/usr/bin/env python3
"""ly.govapi.tw 匯入端的 Elasticsearch 存取（給 imports/segment/ 用）。

設定從 config.php 讀（跟 imports/ivod/upsert-speaker-embed.py 同一招：用 php 把 getenv 印出來），
不另外放一份密碼。index 名稱一律加 ELASTIC_PREFIX。
"""
import json, os, subprocess

import requests
from requests.auth import HTTPBasicAuth

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))

_cfg = None


def config():
    global _cfg
    if _cfg is None:
        php = ("include '%s/init.inc.php'; echo getenv('ELASTIC_URL').PHP_EOL.getenv('ELASTIC_USER').PHP_EOL"
               ".getenv('ELASTIC_PASSWORD').PHP_EOL.getenv('ELASTIC_PREFIX').PHP_EOL.getenv('OPENFUN_DATA_TOKEN');" % ROOT)
        out = subprocess.run(["php", "-r", php], capture_output=True, text=True, check=True).stdout.split("\n")
        _cfg = {"url": out[0].rstrip("/"), "user": out[1], "password": out[2], "prefix": out[3], "token": out[4]}
    return _cfg


def _auth():
    c = config()
    return HTTPBasicAuth(c["user"], c["password"])


def index_name(name):
    return config()["prefix"] + name


def search(index, body, timeout=120):
    r = requests.get(f"{config()['url']}/{index_name(index)}/_search", json=body, auth=_auth(), timeout=timeout)
    r.raise_for_status()
    return r.json()


def scroll_all(index, body, size=5000):
    """把符合條件的全部撈回來（用 search_after，不開 scroll）。body 要有 sort。"""
    body = dict(body, size=size)
    out, after = [], None
    while True:
        if after is not None:
            body["search_after"] = after
        res = search(index, body)
        hits = res["hits"]["hits"]
        if not hits:
            break
        out.extend(hits)
        after = hits[-1]["sort"]
        if len(hits) < size:
            break
    return out


def speech_blocks(lcidc_id):
    """一份公報文件的發言索引 block：[{seq, block_line, kind, speaker_raw, date, meet_id}]，依 seq。"""
    hits = scroll_all("speech", {
        "sort": [{"seq": "asc"}],
        "_source": ["seq", "block_line", "kind", "speaker_raw", "speaker_name", "date", "meet_id", "text"],
        "query": {"term": {"lcidc_id": lcidc_id}},
    })
    return [h["_source"] for h in hits]


def bulk(index, docs, id_field="id", refresh=False):
    """index 一批文件（以 id_field 當 _id）。回傳錯誤數。"""
    if not docs:
        return 0
    lines = []
    for d in docs:
        lines.append(json.dumps({"index": {"_index": index_name(index), "_id": d[id_field]}}, ensure_ascii=False))
        lines.append(json.dumps(d, ensure_ascii=False))
    url = f"{config()['url']}/_bulk" + ("?refresh=true" if refresh else "")
    r = requests.post(url, data=("\n".join(lines) + "\n").encode("utf-8"),
                      headers={"Content-Type": "application/x-ndjson"}, auth=_auth(), timeout=300)
    r.raise_for_status()
    res = r.json()
    errors = [it["index"] for it in res.get("items", []) if it["index"].get("error")]
    for e in errors[:3]:
        print("bulk error:", json.dumps(e.get("error"), ensure_ascii=False)[:300])
    return len(errors)


def delete_by_query(index, query, refresh=True):
    url = f"{config()['url']}/{index_name(index)}/_delete_by_query" + ("?refresh=true" if refresh else "")
    r = requests.post(url, json={"query": query}, auth=_auth(), timeout=300)
    r.raise_for_status()
    return r.json().get("deleted", 0)


def ensure_index(index, mappings):
    url = f"{config()['url']}/{index_name(index)}"
    r = requests.head(url, auth=_auth(), timeout=30)
    if r.status_code == 200:
        return False
    r = requests.put(url, json={"mappings": mappings}, auth=_auth(), timeout=60)
    r.raise_for_status()
    return True
