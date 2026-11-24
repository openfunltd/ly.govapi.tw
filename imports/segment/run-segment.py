#!/usr/bin/env python3
"""常態掃描：公報發言索引有更新的會議就重跑切分，寫進 ES meet_section。排在 cron/3hr/meet.php 的 import-speech 之後。

怎麼判斷要不要重跑
  imports/gazette/speech-state/{lcidc}.json 記每份文件的 md5、parser 版本與所屬會議；
  本檔在 segment-state/{meet_id}.json 記上次切分時用到的文件 md5 與 segment_meet.VERSION。
  任一文件 md5 變了、版本變了、或這場還沒切過，就重跑；切不了的（會議種類沒有切分器、委員會非預算日）
  也記下來（status=skipped＋原因），版本不變就不再試。

環境變數
  limit=N        這一輪最多處理幾場（預設 30；切分器抓 API 有節流，一場幾秒到兩分鐘）
  only_meet=…    只跑這場（忽略狀態）
  dry=1          只列出要跑的，不跑
"""
import datetime, glob, json, os, sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import lyapi_es
import segment_meet as SM

SPEECH_STATE = os.path.join(HERE, "..", "gazette", "speech-state")
STATE_DIR = os.path.join(HERE, "segment-state")
SUPPORTED = ("院會", "委員會", "聯席會議", "公聽會")


def speech_docs_by_meet():
    """{meet_id: {lcidc: md5}}，從 speech-state 讀。"""
    out = {}
    for f in glob.glob(os.path.join(SPEECH_STATE, "*.json")):
        try:
            st = json.load(open(f, encoding="utf-8"))
        except (OSError, ValueError):
            continue
        lcidc = os.path.basename(f)[:-5]
        for m in st.get("meets") or []:
            out.setdefault(m, {})[lcidc] = st.get("md5")
    return out


def load_state(meet_id):
    p = os.path.join(STATE_DIR, f"{meet_id}.json")
    try:
        return json.load(open(p, encoding="utf-8"))
    except (OSError, ValueError):
        return None


def save_state(meet_id, state):
    os.makedirs(STATE_DIR, exist_ok=True)
    with open(os.path.join(STATE_DIR, f"{meet_id}.json"), "w", encoding="utf-8") as f:
        json.dump(state, f, ensure_ascii=False)


def needs_run(meet_id, docs, state):
    if not state:
        return "新會議"
    if state.get("version") != SM.VERSION:
        return f"版本 {state.get('version')} → {SM.VERSION}"
    if state.get("docs") != docs:
        return "公報文件有更新"
    return None


def main():
    limit = int(os.environ.get("limit") or 30)
    only = os.environ.get("only_meet")
    dry = bool(os.environ.get("dry"))
    by_meet = speech_docs_by_meet()
    todo = []
    for meet_id, docs in sorted(by_meet.items()):
        if only and meet_id != only:
            continue
        if meet_id.startswith("dangtuan-") or meet_id.startswith("黨團協商-"):
            continue      # 黨團協商走 ppg 附件 PDF，切分器還沒接（見 README）
        if not meet_id.startswith(SUPPORTED):
            continue
        why = "指定" if only else needs_run(meet_id, docs, load_state(meet_id))
        if why:
            todo.append((meet_id, docs, why))
    print(f"要重跑 {len(todo)} 場（這一輪最多 {limit}）")
    if dry:
        for meet_id, docs, why in todo[:limit]:
            print(f"  {meet_id}: {why}（{len(docs)} 份文件）")
        return
    lyapi_es.ensure_index(SM.INDEX, SM.MAPPINGS)
    done = 0
    for meet_id, docs, why in todo:
        if done >= limit:
            break
        now = datetime.datetime.now().astimezone().isoformat()
        try:
            out, err = SM.run_meet(meet_id)
        except Exception as e:      # 切分器對某一場炸掉不要擋住整輪；記下來下次版本變了再試
            out, err = None, f"{meet_id}: 例外 {type(e).__name__}: {str(e)[:200]}"
        if err:
            print("skip", err, file=sys.stderr)
            save_state(meet_id, {"version": SM.VERSION, "docs": docs, "status": "skipped", "reason": err, "at": now})
            done += 1
            continue
        os.makedirs(SM.OUT_DIR, exist_ok=True)
        with open(os.path.join(SM.OUT_DIR, f"{meet_id}.json"), "w", encoding="utf-8") as f:
            json.dump(out, f, ensure_ascii=False, indent=1)
        lyapi_es.delete_by_query(SM.INDEX, {"term": {"meet_id": meet_id}})
        for d in out["sections"]:
            d["updated_at"] = now
        errs = lyapi_es.bulk(SM.INDEX, out["sections"], refresh=True)
        print(f"{why}: {SM.summarize(out)}" + (f"，ES 錯誤 {errs}" if errs else ""))
        if not errs:
            save_state(meet_id, {"version": SM.VERSION, "docs": docs, "status": "ok", "sections": len(out["sections"]), "at": now})
        done += 1
    print(f"done: {done} 場")


if __name__ == "__main__":
    main()
