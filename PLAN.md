# ly.govapi.tw — 專案說明

## 專案概述

台灣立法院資料爬蟲與 API 系統。原本包含 v1 REST API（以 Swagger/OpenAPI 文件化），但 **v1 API 已停止更新，目前維護重心全部在爬蟲部分**。爬蟲持續抓取立法院各官方系統的資料並儲存至 Elasticsearch。

- **資料儲存**：Elasticsearch（連線設定在 `config.php`，index prefix 在 `ELASTIC_PREFIX`）
- **磁碟快取**：大容量儲存設備，透過 symlink 掛載至各 `imports/` 子目錄（路徑設定在本機）
- **通知**：Slack Webhook（錯誤通知 + 資料更新通知，webhook URL 在 `config.php`）
- **網路**：可透過 HTTP Proxy 對外連線（設定在 `config.php`）

---

## 目錄結構

```
ly.govapi.tw/
├── config.php              # 環境設定（Elastic、Slack、Proxy、Whisper API）
├── init.inc.php            # Bootstrap：載入所有 class + timezone
├── Dispatcher.php          # v1 API 請求處理器（已不再維護）
├── LYLib.php               # 共用工具函式庫
├── BillParser.php          # 議案 HTML 解析
├── GazetteParser.php       # 公報解析
├── GazetteTranscriptParser.php  # 公報速記解析
├── MeetParser.php          # 會議資料解析
├── Elastic.php             # Elasticsearch 封裝
│
├── cron/                   # 排程腳本
│   ├── 5min/post-error-to-slack.php
│   ├── 1hr/post-latest-data-to-slack.php
│   ├── 3hr/bill.php, gazette.php, meet.php
│   └── 6am/committee.php, legislators.php
│
└── imports/                # 各資料來源的匯入腳本
    ├── Importer.php        # 基底 class（getURL、Slack 通知）
    ├── gazette.php         # 公報頂層 orchestrator
    ├── import-all.php      # 批次執行入口（ivod + interpellation）
    ├── bill/
    ├── gazette/
    ├── law/
    ├── meet/
    ├── interpellation/
    └── ivod/
```

---

## Cron 排程總覽

| 頻率 | 腳本 | 功能 |
|------|------|------|
| 5min | `post-error-to-slack.php` | 監控 ES cron log，有錯誤即推 Slack |
| 5min | `ivod-wav.php` | 掃描新 Clip/Full，下載 wav（供 whisper-transcript.php 消化） |
| 1hr  | `post-latest-data-to-slack.php` | 將最新匯入資料摘要推 Slack |
| 3hr  | `bill.php` | 議案完整流程 orchestrator |
| 3hr  | `gazette.php` | 公報完整流程 orchestrator（含記名表決解析） |
| 3hr  | `meet.php` | 會議完整流程 orchestrator |
| 3hr  | `law.php` | 法律完整流程 orchestrator（crawl → import-law-content → import-law） |
| 6am  | `committee.php` | 委員會資料（open data CSV → ES） |
| 6am  | `legislators.php` | 委員資料（PDF + CSV → ES） |
| 3am  | `speaker-embed.php` | 語者身分每日維護：委員聲紋（近 50 天 Clip）→ 宣讀人員聲紋（全部 Full）→ 重建主席索引 chair-index.json → recompute 近 90 天 Full（補 is_chair、新聲紋，不重跑 ASR） |

---

## 各資料來源詳細流程

### 1. Bill（議案）— `cron/3hr/bill.php`

流程順序：`crawl-list` → `crawl-lis-progress` → `check-lis-progress` → `check-updated-bill` → `crawl-entry` → `crawl-doc` → `parse-doc` → `import`

| 腳本 | 抓取來源 | 讀取目錄 | 寫入目錄 |
|------|---------|---------|---------|
| `crawl-list.php` | `ppg.ly.gov.tw/ppg/api/v1/all-bills`（billType 1-3, proposalType 1-4） | — | stdout → `/cache/bill-list.jsonl.*` |
| `crawl-lis-progress.php` | `lis.ly.gov.tw/lylgmeetc/lgmeetkm`（表單翻頁爬取第 11 屆三讀） | — | `/cache/lis-progress.jsonl` |
| `check-lis-progress.php` | `v2.ly.govapi.tw/bills`（內部 v2 API） | `/cache/lis-progress.jsonl` | `/cache/lis-cache.json` |
| `check-updated-bill.php` | `v2.ly.govapi.tw/meets`（內部 v2 API） | `/cache/bill-list.jsonl.*`、`bill-html/`、`bill-data/` | `bill-html/old/`（移除過期）、ES |
| `crawl-entry.php` | `ppg.ly.gov.tw/ppg/bills/{id}/details`（HTML 頁面） | stdin JSONL | `bill-html/{id}.gz` |
| `crawl-doc.php` | `ppg.ly.gov.tw`、`lci.ly.gov.tw`（文件 URL 從 HTML 解析） | `bill-html/{id}.gz` | `bill-docgz/{id}.doc.gz` |
| `parse-doc.php` | `tika.openfun.dev/tika`（DOC → HTML） | `bill-docgz/{id}.doc.gz` | `bill-doc-parsed/tikahtml/{id}.doc.gz` |
| `import.php` | — | `bill-html/{id}.gz`、`bill-doc-parsed/` | `bill-data/{id}.json.gz`、`missing_law.txt`、ES |

**快取目錄**（`imports/bill/` 下的 symlink）：
- `bill-html/` — 爬取的議案 HTML（gzip）
- `bill-docgz/` — 下載的 DOC 文件（gzip）
- `bill-doc-parsed/tikahtml/` — Tika 解析後的 HTML
- `bill-data/` — 最終 JSON 資料
- `/cache/bill-list.jsonl.*` — 議案清單快取
- `/cache/lis-progress.jsonl` — LIS 進度快取

---

### 2. Gazette（公報）— `cron/3hr/gazette.php`

流程順序：`imports/gazette.php` → `gazette/crawl.php` → `gazette/crawl-doc.php` → `gazette/parse-vote.php`

| 腳本 | 抓取來源 | 讀取目錄 | 寫入目錄 |
|------|---------|---------|---------|
| `imports/gazette.php` | `data.ly.gov.tw/odw/usageFile.action?id=41`（CSV，多屆期） | `/cache/41-gazette-*.csv`、`gazette-detail-html/`、`gazette-data/` | `/cache/41-gazette-*.csv`、`gazette-detail-html/{id}.html`、`gazette-data/`、ES |
| `gazette/crawl.php` | PPG PDF 下載 URL（動態）、`ppg.ly.gov.tw` 議程頁面 | ES gazette_agenda index | `gazette-pdf/`、`gazette-txt/`、`gazette/error.log` |
| `gazette/crawl-doc.php` | PPG 文件 URL（從 ES gazette_agenda 取得）、`tika.openfun.dev/tika` | ES gazette_agenda index、`docfile/`、`agenda-html/`、`agenda-tikahtml/` | `docfile/`（DOC 原檔）、`agenda-html/`（轉 HTML）、`agenda-tikahtml/`（Tika HTML） |
| `gazette/parse-vote.php` | — | ES meet index（`公報發言紀錄` → lcidc）、`gazette-txt/`、`vote-processed.json` | ES gazette_vote index、`vote-processed.json` |

**快取目錄**（`imports/gazette/` 下）：
- `gazette-detail-html/` — 公報詳細 HTML（1000+ 頁）
- `gazette-data/` — 解析後的公報 JSON
- `gazette-pdf/` — 下載的公報 PDF
- `gazette-txt/` — PDF 轉文字（pdftotext）
- `docfile/` — 議程 DOC 原始檔
- `agenda-html/` — 議程 HTML
- `agenda-tikahtml/` — Tika 解析的議程 HTML
- `/cache/41-gazette-*.csv` — open data 公報清單快取

---

### 3. Meet（會議）— `cron/3hr/meet.php`

流程順序：`crawl-meet` → `crawl-meet-ppg` → `crawl-ppg-page` → `crawl-meet-proceeding` → `crawl-meet-speechlist` → `parse-meet-from-gazette` → `parse-meet-proceeding` → `parse-speech-from-gazette` → `link-meet` → `import-meet`

| 腳本 | 抓取來源 | 讀取目錄 | 寫入目錄 |
|------|---------|---------|---------|
| `crawl-meet.php` | `data.ly.gov.tw/odw/openDatasetJson.action?id=42`（分頁） | — | `/cache/42-meet.jsonl` |
| `crawl-meet-ppg.php` | `ppg.ly.gov.tw/ppg/api/v1/sitting-date`（每月開會日）、`all-sittings`（每日會議；`id` 即 42 的 `meetingNo`） | `/cache/ppg-meet.jsonl`、`/cache/42-meet.jsonl` | `/cache/ppg-meet.jsonl`（PPG 持久清單）、**重寫** `/cache/42-meet.jsonl`（PPG 列 + open data 裡 PPG 沒有的列） |
| `crawl-ppg-page.php` | `ppg.ly.gov.tw/ppg/sittings/{meetingNo}/details`（HTML） | `/cache/42-meet.jsonl`、`ppg_meet_page/`、`ppg_meet_page_json/` | `ppg_meet_page/{no}-{date}.html`、`ppg_meet_page_json/{no}-{date}.json` |
| `crawl-meet-proceeding.php` | `ppg.ly.gov.tw/ppg/api/v1/getProceedingsList`（文件清單）+ PPG 文件 URL、`tika.openfun.dev/tika` | `/cache/42-meet.jsonl`、`meet-proceeding-doc/`、`meet-proceeding-txt/`、`meet-proceeding-html/` | `meet-proceeding-doc/`（DOC）、`meet-proceeding-txt/`（文字）、`meet-proceeding-html/`（HTML） |
| `crawl-meet-speechlist.php` | `data.ly.gov.tw/odw/usageFile.action?id=221`（院會發言）、`id=223`（委員會發言） | `/cache/221-meet-speech.csv`、`/cache/223-meet-speech.csv`、`meet-sub-data/` | `/cache/221-meet-speech.csv`、`/cache/223-meet-speech.csv`、`meet-sub-data/{id}.json` |
| `parse-meet-from-gazette.php` | — | `gazette/agenda-txt/`、`meet-sub-data/` | `meet-sub-data/{id}.json` |
| `parse-meet-proceeding.php` | — | `/cache/42-meet.jsonl`、`meet-proceeding-txt/`、`meet-sub-data/` | `meet-sub-data/{id}.json` |
| `parse-speech-from-gazette.php` | — | `gazette/gazette-txt/`、`meet-sub-data/` | `meet-sub-data/{id}.json` |
| `link-meet.php` | — | `/cache/42-meet.jsonl`、`ppg_meet_page_json/`、`meet-sub-data/`、`meet-data/` | `meet-data/{id}.json`、ES |
| `import-meet.php` | — | `meet-data/` | ES |

**快取目錄**（`imports/meet/` 下）：
- `ppg_meet_page/` — PPG 會議 HTML
- `ppg_meet_page_json/` — 解析後 JSON
- `meet-proceeding-doc/` — 會議紀錄 DOC
- `meet-proceeding-txt/` — 會議紀錄文字
- `meet-proceeding-html/` — 會議紀錄 HTML
- `meet-sub-data/` — 各來源的補充資料（發言、議程、速記）
- `meet-data/` — 最終合併 JSON
- `/cache/42-meet.jsonl` — 會議清單（下游統一讀這份；2026-10 起為 PPG 日曆 + open data 42 的合併結果，schema 沿用 42）
- `/cache/ppg-meet.jsonl` — PPG 開會日曆抓到的清單（一場會議一天一列），預設每輪抓本月＋下月，`backfill=1` 可從第 8 屆逐月重抓
- `/cache/221-meet-speech.csv`、`/cache/223-meet-speech.csv` — 發言清單

---

### 4. Law（法律）— `cron/3hr/law.php`

流程順序：`crawl.php` → `import-law-content.php` → `import-law.php`

| 腳本 | 抓取來源 | 讀取目錄 | 寫入目錄 |
|------|---------|---------|---------|
| `crawl.php` | `lis.ly.gov.tw/lglawc/`（LIS 法律系統）、`ly.gov.tw/Pages/ashx/LawRedirect.ashx` | `law-data/laws-category.csv`、`law-data/laws.csv`、`law-data/laws-versions.csv` | `law-data/laws/{id}.html`、`law-data/laws-category.csv`、`law-data/laws.csv`、`law-data/laws-versions.csv` |
| `import-law.php` | `data.ly.gov.tw/odw/usageFile.action?id=301`（CSV 法律清單） | `/cache/301-law.csv`、`law-data/laws.csv`、`law-data/laws-category.csv`、`law-data/law_cache/` | `/cache/301-law.csv`、`law-data/law_cache/`、ES（law index） |
| `import-law-content.php` | — | `law-data/laws/`、`law-data/laws-versions.csv`、`law-data/law_cache/`、`bill-data/`（bill 連結）、ES（bill lookup） | `law-data/law_cache/`、`law-data/laws-result/`、ES（law_version、law_content） |

**快取目錄**（`imports/law/` 下）：
- `law-data/laws/` — 爬取的法律 HTML
- `law-data/laws-category.csv` — 法律分類
- `law-data/laws.csv` — 法律清單
- `law-data/laws-versions.csv` — 版本歷程
- `law-data/law_cache/` — 已處理快取
- `law-data/laws-result/` — 最終結果
- `/cache/301-law.csv` — open data 法律清單

---

### 5. Interpellation（質詢）— `imports/import-all.php`

| 腳本 | 抓取來源 | 讀取目錄 | 寫入目錄 |
|------|---------|---------|---------|
| `crawl-interpellation.php` | `ppg.ly.gov.tw/ppg/sittings/{type}/{meetingNo}/details`（PPG 院會頁面，PDF 連結）、pdftotext 本地執行 | ES meet index（取 meetingNo）、`files/`、`interpellation-data/` | `files/{meetingNo}-*.pdf`、`interpellation-data/{id}.json`、ES |

**快取目錄**（`imports/interpellation/` 下）：
- `files/` — 下載的質詢 PDF
- `interpellation-data/` — 解析後 JSON

---

### 6. iVOD（立法院影片）— `imports/import-all.php` + 常駐/批次腳本

流程順序：`crawl-html` → `check-gazette` →（cron 5min）`ivod-wav` 下載 wav → `whisper-transcript`（ASR + pyannote + 語者身分比對）→ `import-ivod`（`crawl-speech` 為補充）

| 腳本 | 抓取來源 | 讀取目錄 | 寫入目錄 |
|------|---------|---------|---------|
| `crawl-html.php` | `ivod.ly.gov.tw/Play/Full/{quality}/{id}`、`ivod.ly.gov.tw/Play/Clip/{quality}/{id}` | `current-full-id`、`current-id`、`html/` | `html/{id}.html`、`current-full-id`、`current-id` |
| `crawl-speech.php` | `ivod.ly.gov.tw/TotalSpeech`（CSRF 認證） | cookie jar | `/cache/ivod-speech/{date}/{ts}.html.gz` |
| `check-gazette.php` | — | `html/`、`/cache/41-gazette-*.csv` | `ivod-gazette/{id}.json` |
| `cron/5min/ivod-wav.php` | `ivod.ly.gov.tw/Play/{type}/{quality}/{id}`（m3u8 + TS 分段，走 proxy） | `html/`、`current-id`、`current-full-id`、`ivod-transcript/` | `wav/{id}.wav` |
| `whisper-transcript.php` | `asr-tool.api.openfun.dev`（Whisper + pyannote 語音辨識，內含語者身分比對） | `wav/`、`html/`、`ivod-transcript/`、ES speaker_identity index | `ivod-transcript/{id}.json`、`ivod-pyannote/{id}.json`（含比對出的 `identity`） |
| `import-ivod.php` | — | `html/`、`ivod-transcript/`、`ivod-gazette/`、`ivod-data/` | `ivod-data/{id}.json`、ES |

**語者身分比對子系統**（speaker identification，較新功能，見下方維護重點）：

| 腳本 | 說明 |
|------|------|
| `build-gazette-align.py` | 把公報逐字發言段落對齊 whisper 逐字稿時間軸（給 quality 分數、guessed 標記），寫入 `ivod-gazette-align/{id}.json` 與 `ivod-data/{id}.json` 的 `transcript.gazette`，是語者身分比對的 ground truth 來源 |
| `upsert-speaker-embed.py`（cron 3am `speaker-embed.php`） | 掃描近 N 天有 gazette-align + pyannote 的 Clip，依公報標註的委員身分把對應 pyannote embedding 平均進 ES speaker_identity index（每人最多保留近 20 筆 `recent_clips`） |
| `upsert-reader-embed.py` | 收集「宣讀人員」（唸議案內容的議事人員，公報不會寫名字、不只一人）的聲紋：在 whisper 逐字稿找「請宣讀→完畢」視窗，取視窗內 dominant speaker 的 embedding，跨場次貪婪分群後以占位名「宣讀人員N」、`role=reader` upsert 進 speaker_identity。品質過濾：視窗 ≥60s、dominant ≥80%、新群至少出現在 2 場；重跑時以 ES 既有 reader doc 為群心，編號跨次穩定。院會與各委員會的議事人員不同，所以掃全部 Full |
| `IVodParser::identifySpeaker()` | 全系統唯一的 kNN 比對入口（whisper-transcript / reprocess-batch / backfill / recompute 都呼叫它）。委員、官員、宣讀人員統一走 kNN；門檻常數在 IVodParser：具名 cosine ≥0.70、宣讀人員 ≥0.75，兩者分數差在 0.02 內時具名優先（誤把具名的人標成宣讀人員代價較大）。「請宣讀→完畢」內容規則只在 kNN 完全沒比對到時當 fallback（標成不帶編號的「宣讀人員」） |
| `backfill-speaker-identity.php` | 批次或指定 id（`only_ids=`）補跑既有 `ivod-pyannote/{id}.json` 裡還沒 identity 的 speaker，常用於門檻調整後重新驗證效果 |
| `reindex-speaker-identity.php` | 把 `ivod-pyannote/` 裡的結果用時間重疊（`IVodParser::alignPyannoteIdentities`）對齊回 `ivod-data` 的 `transcript.pyannote`（speaker_name/score/is_chair），同時把每個 speaker cluster 的 embedding 存進 `transcript.speakers`，再重新 index 進 ES |
| `recompute-speaker-identity.php` | 只讀 ES `transcript.speakers` 裡現成的 embedding，重新對 speaker_identity 做 kNN 並寫回 `speaker_name`/`speaker_score`/`is_chair`；不碰本機檔案、不重跑 ASR。調門檻或新增聲紋後跑這支就能讓全部舊影片套用新規則（`only_ids=`、`limit=`、`dry_run=1`） |
| `reprocess-batch.php` | 對指定 id（`only_ids=`）用新版 asr-tool 重跑 ASR + pyannote，覆蓋 `ivod-transcript/`、`ivod-pyannote/`；需先把 `wav/{id}.wav` 準備好。用於修復舊版 pyannote 把長 Full 影片多人合併成同一 speaker 的結果 |

**歷史資料補齊／維護用腳本**（手動批次，不在 cron）：
- `backfill-pyannote-download.php` / `backfill-pyannote-process.php` / `backfill-pyannote-run.php` — 對舊 transcript 補跑 pyannote（不重跑 whisper），下載/處理兩階段可並行執行，`-run` 為持續執行版本
- `reimport-one.php` — 針對單一 id 強制重新 import
- `reindex-ivod-gazette.php` — 把已有 `transcript.gazette` 的結果重新 index 進 ES
- `remove-ivod.php` — 從 ES + 本機檔案移除指定 id
- `fix-gazette-features.py` — 補 `gazette` feature 標記的一次性修正腳本
- `build-dangtuan-align.py` — 黨團協商版本的公報對齊（來源 `agenda-anchor/`，對象為 Full 整場會議影片）
- `write-meet-section-preview.py` — 產生 `imports/meet/meet-json/{meet_id}.json` 預覽

**開發中（尚未納入 cron，2026-10）**：
- `imports/gazette/build-chair-index.php` → `imports/gazette/chair-index.json`：從公報標頭解析每場會議的主席名單，供 `IVodParser::getSessionChair()` 判斷 `is_chair`
- `upsert-chair-embed.py`：院會主席（院長／副院長）專屬聲紋 embedding

**快取目錄**（`imports/ivod/` 下）：
- `html/` — 爬取的 iVOD HTML 頁面
- `ivod-gazette/` — iVOD ↔ 公報對應 JSON（粗粒度，`check-gazette.php` 產生）
- `ivod-gazette-align/` — 公報發言段落 ↔ whisper 時間軸對齊結果（細粒度，含 quality 分數，語者比對 ground truth）
- `ivod-transcript/` — Whisper/pyannote ASR 原始回傳 JSON
- `ivod-pyannote/` — pyannote 分離結果（speaker 分群、embedding、比對出的 identity）
- `ivod-data/` — 最終合併 JSON（whisperx + pyannote 身分 + gazette 對齊），也是寫入 ES 的來源
- `wav/` — 下載轉檔後的音檔（16kHz mono，whisper-transcript.php 處理完會自動清掉）
- `current-id` — 最後處理的 clip ID
- `current-full-id` — 最後處理的院會 ID

**長 Full 影片分群合併問題（2026-10 已修）**：舊版 pyannote 對數小時級的 Full 影片（整場委員會會議）會把不同人的聲音誤合併成同一個 speaker 標籤，該標籤的 embedding 混了多人聲音，跟任何身分都比對不準（例：17742 原本只分出 2 個巨大 speaker，asr-tool 更新後分出 48 個）。asr-tool 端已於 2026-10 修正，新進影片不受影響；既有舊結果需用 `reprocess-batch.php` 重跑。判斷式：`ivod-pyannote/{id}.json` 裡某 speaker 的首尾時間跨度 ÷ 全片長 ≥ 0.85 且 `total_seconds` ≥ 1800、片長 > 7200s。2026-10-16 掃描本機全部 pyannote 結果找到 123 支疑似受害影片，已重跑其中 21 支（依嚴重度排序的前段），其餘約 100 支尚未處理。

---

### 7. Committee（委員會）— `cron/6am/committee.php`

| 腳本 | 抓取來源 | 讀取目錄 | 寫入目錄 |
|------|---------|---------|---------|
| `committee.php` | `data.ly.gov.tw/odw/usageFile.action?id=13`（CSV） | `/cache/13-committee.csv` | `/cache/13-committee.csv`、ES |

---

### 8. Legislators（委員）— `cron/6am/legislators.php`

| 腳本 | 抓取來源 | 讀取目錄 | 寫入目錄 |
|------|---------|---------|---------|
| `legislators.php` | `data.ly.gov.tw/odw/legislator.pdf`（PDF bioId）、`id=16`（CSV 現任）、`id=9`（CSV 歷屆） | `/cache/legislator.pdf`、`/cache/16-legislator.csv`、`/cache/9-legislator.csv` | 同上 + `/cache/legislator.pdf.txt`（pdftotext）、ES |

---

### 9. 監控通知

| 腳本 | 讀取來源 | 寫入目的 |
|------|---------|---------|
| `5min/post-error-to-slack.php` | ES `logs-cron-*` index、`/cache/error-log-cursor` | `/cache/error-log-cursor`、Slack webhook |
| `1hr/post-latest-data-to-slack.php` | ES `logs-import-*` index、`/cache/import-data-cursor` | `/cache/import-data-cursor`、Slack webhook |

---

## 外部系統清單

| 系統 | 網址 | 用途 |
|------|------|------|
| PPG | `ppg.ly.gov.tw` | 議案清單/詳情、公報文件、會議頁面、質詢 |
| LIS | `lis.ly.gov.tw` | 法律內容、議案進度 |
| data.ly.gov.tw | `data.ly.gov.tw` | open data（公報清單 id=41、會議 id=42、委員會 id=13、委員 id=16、法律 id=301、發言 id=221/223） |
| iVOD | `ivod.ly.gov.tw` | 立法院影片 |
| v2 API | `v2.ly.govapi.tw` | 內部使用（check-updated-bill、check-lis-progress） |
| Tika | `tika.openfun.dev/tika` | DOC/DOCX → HTML 文件解析 |
| ASR Tool | `asr-tool.api.openfun.dev` | iVOD 語音轉文字（Whisper）+ pyannote 說話者分離 |
| Slack | webhook URL（config.php） | 錯誤通知、資料更新摘要 |

---

## ES Index 總覽

供其他專案串接參考，index 實際名稱為 `{ELASTIC_PREFIX}{名稱}`（prefix 設定在 `config.php`）。文件數為概略值，持續成長中。

| Index | 用途 | 主要寫入來源 |
|-------|------|------------|
| `bill` | 議案（法律/預算/人事等提案），含審查進度、關係文書、黨團協商 | `imports/bill/import.php` |
| `meet` | 會議資料（院會/委員會），整合 open data、PPG、公報文字、公報議程、發言清單等 5+ 來源，是 ivod/interpellation 的上游 | `imports/meet/link-meet.php` |
| `gazette` | 公報（期別/卷期 metadata + 內容 HTML） | `imports/gazette.php` |
| `gazette_agenda` | 公報議程/文件層級資料（院會、委員會逐項議程，含來源 PDF/DOC） | `imports/gazette/crawl.php`、`crawl-doc.php` |
| `gazette_vote` | 公報記載的記名表決結果（逐筆立委 + 投票結果，依 line_no 分文件） | `imports/gazette/parse-vote.php` |
| `ivod` | 立法院影片（Clip/Full），含逐字稿、委員質詢對應、語者身分比對結果；`transcript.speakers` 另存每個 pyannote speaker 的 embedding（base64 float32，`index:false` 不可搜尋，只供 `recompute-speaker-identity.php` 讀回重算） | `imports/ivod/import-ivod.php`、`reindex-speaker-identity.php` |
| `speaker_identity` | 語者聲紋身分庫（用於 ivod pyannote embedding 比對，見 iVOD 章節） | `imports/ivod/upsert-speaker-embed.py` |
| `interpellation` | 質詢資料（院會質詢 PDF 解析） | `imports/interpellation/crawl-interpellation.php` |
| `law` | 現行法規清單 | `imports/law/import-law.php` |
| `law_version` | 法規版本歷程 | `imports/law/import-law-content.php` |
| `law_content` | 法規逐條內容（article-level） | `imports/law/import-law-content.php` |
| `committee` | 委員會清單 | `cron/6am/committee.php` |
| `legislator` | 委員資料（含跨屆 bioId） | `cron/6am/legislators.php` |
| `logs-cron-{year}` | cron 執行紀錄（年度切分） | 各 cron 腳本 |
| `logs-import-{year}` | 資料匯入紀錄（年度切分） | 各 import 腳本 |

> v1 API（`Dispatcher.php`）已停止維護，外部串接請直接查 ES 或走 v2 API（`v2.ly.govapi.tw`，非本 repo）。

---

## Milestones（重大里程碑）

### 2023-09 — 專案建立，基礎框架
- 建立 Elastic、Importer 基底 class、Swagger v1 API
- 完成委員（Legislator）、委員會（Committee）資料匯入
- 建立公報（Gazette）匯入器與 API
- 建立議案（Bill）爬蟲：BillParser、crawl-list、crawl-entry、import

### 2023-09（下旬）— 會議與質詢系統
- 建立會議（Meet）系統：crawl-meet、import-meet、meet API
- 建立 GazetteParser，從公報解析質詢（Interpellation）資料
- 加入 Tika 文件解析（DOC/DOCX → HTML），用於議案關係文書

### 2023-10 — iVOD 整合、改以文字公報為會議主來源
- 加入 iVOD 爬蟲（下載 HTML、匯入 ES）
- 停用品質不佳的 open data 會議資料，改從文字公報（gazette-txt）抓取會議內容
- 依議案種類與提案單位分類抓取，補充 BillParser

### 2023-11 — 法律系統、立委永久 ID、會議議事錄
- 建立法律（Law）系統：從 LIS 抓取、匯入 ES、加上 `/law` API
- 將立委匯入搬到 imports/legislators.php，加上永久 bioId（跨屆追蹤）
- 加入直接爬取會議議事錄（meet-proceeding）的爬蟲
- 質詢（Interpellation）爬蟲與 API 上線

### 2023-11 — 議案關係文書完整化
- 補齊關係文書的案由、說明、連署人、提案人等欄位
- 加上議案提案人/連署人名稱修正邏輯（黨團支援）

### 2024-04 — 黨團協商整合、buildMeet 正規化
- 加入黨團協商（billNo、link）進議案流程
- 建立 `LYLib::buildMeet()`，統一會議資料正規化，link-meet 整合多來源

### 2024-05 — 公報逐字稿解析（GazetteTranscriptParser）
- 建立 GazetteTranscriptParser，從公報文字擷取發言紀錄段落
- 加入 `parse-speech-from-gazette.php`，發言紀錄寫入 meet

### 2024-05（下旬）— iVOD Whisper 逐字稿
- 加入 `whisper-transcript.php`，串接 Whisper API 對 iVOD 影片做語音轉文字
- iVOD 資料加入 transcript、發言時間、與公報的對應

### 2024-06 — iVOD 影片爬蟲升級（Clip + Full）
- 加入 clip 與 full session 兩種影片類型爬蟲
- 加入 `check-gazette.php`，將 iVOD 影片對應到公報議程

### 2024-09 — PPG 會議頁面解析（MeetParser）
- 建立 MeetParser，從 PPG 會議頁面解析結構化資料
- `crawl-ppg-page.php` 開始抓取並快取 PPG 會議 HTML + JSON

### 2024-10 — Cron 自動化框架、Slack 監控
- 全面建立 cron 自動化排程（3hr: bill/gazette/meet，5min/1hr: 監控通知）
- 加入 Slack 錯誤通知（post-error-to-slack）與資料更新摘要（post-latest-data-to-slack）
- 質詢（Interpellation）加入 3hr cron 自動執行

### 2024-11 — 法律條文層級匯入、議案審查報告
- 加入 LawLib，從 LIS 爬取完整法律條文（article-level），建立 law_content/law_version index
- 加入議案審查報告（審查版本 doc）解析：審查會條文對照表 parser

### 2024-12 — 議案與法律版本對應
- 建立議案與法律版本的對應邏輯（import-law-content.php 連結 bill-data）
- 法律資料改從新版 open data（id=301）取得

### 2025-01 — LIS 三讀進度爬蟲、黨團協商完整整合
- 加入 `crawl-lis-progress.php`，從國會圖書館議事及發言系統（LIS）爬取三讀進度
- 黨團協商資料完整整合進議案流程（billNo、link、meet_id）

### 2025-03 — v1 API 停止維護聲明
- 在 API 說明加上停止維護警告，正式宣告切換到 v2

### 2025-04～05 — 全面改用 Proxy（curl + IPv4）
- 所有外部 HTTP 請求改用 curl 強制 IPv4，並透過 HTTP Proxy 出去
- 加入 User-Agent header 應對部分網站封鎖

### 2025-07 — LIS 進度抓取加入 cron
- `crawl-lis-progress` 加入 cron/3hr/bill.php 自動執行

### 2026-06 — 加入考察資料
- 議案流程加入「考察」類型資料

### 2026-09～10 — iVOD 語者身分比對（speaker identification）
- 加入 `build-gazette-align.py`，把公報發言段落對齊 whisper 時間軸，產出帶信心分數的 ground truth
- 建立 `speaker_identity` ES index 與 `upsert-speaker-embed.py`，用公報比對確認過身分的 pyannote embedding 建聲紋庫
- `whisper-transcript.php` 加入即時 kNN 身分比對（`identify_speaker()`），搭配 `backfill-speaker-identity.php`/`reindex-speaker-identity.php` 做門檻調整後的回補
- ES `ivod` index 新增 `transcript.speakers` 存 embedding，加入 `recompute-speaker-identity.php`（不重跑 ASR 即可全庫重算身分）與 `reprocess-batch.php`（長 Full 影片用新版 asr-tool 重跑）
- 修正 `config.php` 的 `https_proxy` 被 curl 套用到 ES/asr-tool 請求導致偶發卡住的問題（各 ivod 腳本於 include 後 `putenv('https_proxy')` 清掉）
- 宣讀人員改走聲紋庫：`upsert-reader-embed.py` 從「請宣讀→完畢」視窗收集議事人員聲紋（17 群，院會＋各委員會），kNN 統一到 `IVodParser::identifySpeaker()`，內容規則降為 fallback
- 加入公報記名表決解析（`gazette/parse-vote.php` → `gazette_vote` index）
- 開發中：主席／宣讀人員專屬聲紋（`build-chair-index.php`、`upsert-chair-embed.py`、`upsert-reader-embed.py`），以及長 Full 影片 pyannote 聚類誤合併問題的排查

---

## 維護重點

- **v1 API 已不再更新**：`Dispatcher.php`、`swagger.yaml` 可忽略
- **爬蟲仍持續運作**：`cron/3hr/` 是核心，出問題優先看這裡
- **資料格式**：磁碟快取以 `.json.gz` 或 `.html.gz` 儲存，再批次匯入 ES
- **meet 是最複雜的**：從 5 個以上來源（open data、PPG、公報 txt、公報 gazette、發言清單）合併
- **gazette txt 是 meet 和 ivod 的上游**：gazette 爬蟲失敗會連帶影響 meet 解析和 ivod 對應
- **會議清單以 PPG 為主、open data 42 為輔**：open data 42 常晚到週末才列出下週會議（2026-10 甚至整個第 11 屆第 6 會期都沒列），`crawl-meet-ppg.php` 從 ppg.ly.gov.tw 的開會日曆抓（實測整月比對是 42 的超集合，只少「沒有 meetingNo 的委員自辦公聽會」，那部分仍由 42 補）。PPG 沒有屆／會期欄位，院會與委員會從名稱解析，考察／黨團協商等用同一天或最近一天其他會議的值補。PPG 是網站內部 API，格式無承諾，壞掉時 cron 會 log 失敗但不清舊資料
- **iVOD Whisper 逐字稿**：相對較新的功能，仍在擴充中，whisper-transcript.php 是非同步的（submit → 輪詢狀態）
- **語者身分比對（speaker_identity）更新**：比對門檻只在 `IVodParser::identifySpeaker()` 的常數（`SPEAKER_MIN_SCORE` 0.85 = cosine 0.70、`READER_MIN_SCORE` 0.875 = cosine 0.75、`READER_MARGIN`；ES score = (cosine+1)/2），四支腳本都呼叫它；調整後跑 `recompute-speaker-identity.php`（先 `dry_run=1` 看變更）即可套用到全部既有影片，不需重跑 ASR。宣讀人員的聲紋用 `upsert-reader-embed.py` 收集，可重跑（會往既有 cluster 靠）
- **`whisper-transcript.php` 是常駐 daemon**：由 `imports/ivod/auto-loop.sh`（`while true; php whisper-transcript.php; sleep 1`）在終端機裡跑，不是 cron/systemd；改程式後 kill 掉 php process 會被自動重啟載入新程式
- **`config.php` 的 `https_proxy` 是 process-wide**：只給 ivod.ly.gov.tw CDN 下載用，但 curl 會套用到所有 https 請求。只打 ES/asr-tool 的腳本要在 include `init.inc.php` 後 `putenv('https_proxy')` 清掉，否則 ES bulk 會偶發卡住或失敗
- **語者身分比對待辦（2026-10-16 記錄，尚未處理）**：
  - 具名聲紋被議事人員聲音污染：周秘書長萬來、吳司長宜璇、鄭局長秀絨、盧副組長碧黛、李副委員長姸慧、蔡副主計長鴻坤等 doc 的 embedding 跟宣讀人員群心相似度 0.70–0.77，出現型態是每場 70–100 秒橫跨二三十場，推測 `upsert-speaker-embed.py` 用公報對齊收集時把宣讀段算到官員頭上。目前宣讀人員門檻較高會壓過它們，但這些 doc 在別的影片仍可能誤中。待做：收集時排除「請宣讀→完畢」視窗；稽核所有跟 reader 群心 ≥0.70 的具名 doc 並重建
  - 同一人多個 doc：101 人在 speaker_identity 有 ≥2 個 doc（職稱不同如石部長／石次長崇良，或公報錯字如「桌院長榮泰」），kNN 會把同一人的樣本拆散、label 在版本間跳。待做：以 person_name 合併，或比對時按 person_name 聚合
  - Clip 不存 `transcript.speakers`、不納入 recompute（Clip 以委員質詢為主、沒有宣讀等程序性發言，2026-10-16 決定）；Full 由 import-ivod 匯入時寫入
- **議案三讀狀態的來源有兩條**：PPG 議案頁（`parseBillDetail`）與國會圖書館 LIS（`crawl-lis-progress.php` → `check-lis-progress.php` → `cache/lis-cache.json` → `BillParser::checkLISCLosedBill()` 在 `addBillInfo` 補「議案狀態=三讀」與流程）。PPG 常只把審查報告標三讀、個別提案停在「審查完畢」，LIS 是以提案為單位。`check-lis-progress.php` 只輸出法律版本還沒更新到 LIS 日期之後的議案，所以舊的漏標不會被回補。2026-07-24～10-21 這支因缺 LYLib include 每輪 fatal、`bill.php` 又不檢查 exit code，三個月沒人發現；現在各步驟失敗會 error_log
- **書面質詢（`cron/3hr/crawl-interpellation.php`）**：來源是 PPG 院會頁面的「質詢事項-本院委員質詢部分」分頁裡的關係文書 PDF（LCEWA01_屆會期次_編號.pdf；LCEWA06/07 是目次不收）。這個分頁**會後幾週才出現**，所以 stub／空檔不算抓到，會議日期 180 天內每輪重查（`retry_days=` 可放大做回補）。2025-04～2026-10 因舊版「第一次沒看到就寫 stub 永不重試」加上 PHP stat 快取（下載後 `filesize` 讀到舊值）整整停了一年半，2026-10-28 回補
- **書面質詢的原始來源欄位**：每筆有 `source_page`（PPG 院會頁）、`source_pdf`（關係文書 PDF，直接帶 `#page=page_start`，page_start 是 PDF 實體頁碼；去掉 `#` 之後即純檔案網址）。PDF 網址記在 `files/{meetingNo}-{idx}.pdf.url`，下載時寫入；2026-10-28 以前的舊檔用重抓分頁＋內容比對（頁數＋專案質詢編號集合，PPG 重新產出的 PDF 位元會不同）回補，剩約 50 份（PPG 已無分頁或頁面回 400）為 null。原始資料本身的錯（例如印發日期漏字）不在解析端修，靠這些欄位回報立法院
- **IVOD HLS 下載（`cron/5min/ivod-wav.php`）**：鏈是 playlist.m3u8（主清單）→ chunklist_w<id>.m3u8（媒體清單）→ media_N.ts，網址帶每次不同的 token，分段要沿同一次拿到的媒體清單解析。2026-10-20 事故：舊版把 playlist 改名成 chunklist 去抓，CDN 回「變體就是自己」的主清單，被當成分段餵給 ffmpeg 造成無窮迴圈吃光記憶體、整台機器卡死。現在：拒絕自指的變體、分段只收 .ts、ffmpeg 包 `timeout`（Clip 20 分、Full 90 分）與 `ulimit -v 3GB`、失敗寫 `wav/{id}.wav.failed` 六小時內不重試（`only_ids=` 不受限）
- **常見修改類型**（依 git log 觀察）：人名/資料錯誤修正、解析器調整（文件格式變化）、錯誤處理與重試、新增欄位、爬蟲反封鎖（User-Agent、Proxy、IPv4）
