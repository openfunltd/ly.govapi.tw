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
| 1hr  | `post-latest-data-to-slack.php` | 將最新匯入資料摘要推 Slack |
| 3hr  | `bill.php` | 議案完整流程 orchestrator |
| 3hr  | `gazette.php` | 公報完整流程 orchestrator |
| 3hr  | `meet.php` | 會議完整流程 orchestrator |
| 6am  | `committee.php` | 委員會資料（open data CSV → ES） |
| 6am  | `legislators.php` | 委員資料（PDF + CSV → ES） |

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

流程順序：`imports/gazette.php` → `gazette/crawl.php` → `gazette/crawl-doc.php`

| 腳本 | 抓取來源 | 讀取目錄 | 寫入目錄 |
|------|---------|---------|---------|
| `imports/gazette.php` | `data.ly.gov.tw/odw/usageFile.action?id=41`（CSV，多屆期） | `/cache/41-gazette-*.csv`、`gazette-detail-html/`、`gazette-data/` | `/cache/41-gazette-*.csv`、`gazette-detail-html/{id}.html`、`gazette-data/`、ES |
| `gazette/crawl.php` | PPG PDF 下載 URL（動態）、`ppg.ly.gov.tw` 議程頁面 | ES gazette_agenda index | `gazette-pdf/`、`gazette-txt/`、`gazette/error.log` |
| `gazette/crawl-doc.php` | PPG 文件 URL（從 ES gazette_agenda 取得）、`tika.openfun.dev/tika` | ES gazette_agenda index、`docfile/`、`agenda-html/`、`agenda-tikahtml/` | `docfile/`（DOC 原檔）、`agenda-html/`（轉 HTML）、`agenda-tikahtml/`（Tika HTML） |

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

流程順序：`crawl-meet` → `crawl-ppg-page` → `crawl-meet-proceeding` → `crawl-meet-speechlist` → `parse-meet-from-gazette` → `parse-meet-proceeding` → `parse-speech-from-gazette` → `link-meet` → `import-meet`

| 腳本 | 抓取來源 | 讀取目錄 | 寫入目錄 |
|------|---------|---------|---------|
| `crawl-meet.php` | `data.ly.gov.tw/odw/openDatasetJson.action?id=42`（分頁） | — | `/cache/42-meet.jsonl` |
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
- `/cache/42-meet.jsonl` — open data 會議清單
- `/cache/221-meet-speech.csv`、`/cache/223-meet-speech.csv` — 發言清單

---

### 4. Law（法律）— 手動執行（未列入 cron）

流程順序：`crawl.php` → `import-law.php` → `import-law-content.php`

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

### 6. iVOD（立法院影片）— `imports/import-all.php`

流程順序：`crawl-html` → `check-gazette` → `whisper-transcript` → `import-ivod`（`crawl-speech` 為補充）

| 腳本 | 抓取來源 | 讀取目錄 | 寫入目錄 |
|------|---------|---------|---------|
| `crawl-html.php` | `ivod.ly.gov.tw/Play/Full/{quality}/{id}`、`ivod.ly.gov.tw/Play/Clip/{quality}/{id}` | `current-full-id`、`current-id`、`html/` | `html/{id}.html`、`current-full-id`、`current-id` |
| `crawl-speech.php` | `ivod.ly.gov.tw/TotalSpeech`（CSRF 認證） | cookie jar | `/cache/ivod-speech/{date}/{ts}.html.gz` |
| `check-gazette.php` | — | `html/`、`/cache/41-gazette-*.csv` | `ivod-gazette/{id}.json` |
| `whisper-transcript.php` | `whisper-api.openfun.dev`（Whisper + pyannote 語音辨識） | `html/`、`current-id`、`current-full-id`、`ivod-transcript/` | `ivod-transcript/{id}.json` |
| `import-ivod.php` | — | `html/`、`ivod-transcript/`、`ivod-gazette/`、`ivod-data/` | `ivod-data/{id}.json`、ES |

**快取目錄**（`imports/ivod/` 下）：
- `html/` — 爬取的 iVOD HTML 頁面
- `ivod-gazette/` — iVOD ↔ 公報對應 JSON
- `ivod-transcript/` — Whisper 逐字稿 JSON
- `ivod-data/` — 最終合併 JSON
- `current-id` — 最後處理的 clip ID
- `current-full-id` — 最後處理的院會 ID

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
| Whisper API | `whisper-api.openfun.dev` | iVOD 語音轉文字（含 pyannote 說話者辨識） |
| Slack | webhook URL（config.php） | 錯誤通知、資料更新摘要 |

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

---

## 維護重點

- **v1 API 已不再更新**：`Dispatcher.php`、`swagger.yaml` 可忽略
- **爬蟲仍持續運作**：`cron/3hr/` 是核心，出問題優先看這裡
- **資料格式**：磁碟快取以 `.json.gz` 或 `.html.gz` 儲存，再批次匯入 ES
- **meet 是最複雜的**：從 5 個以上來源（open data、PPG、公報 txt、公報 gazette、發言清單）合併
- **gazette txt 是 meet 和 ivod 的上游**：gazette 爬蟲失敗會連帶影響 meet 解析和 ivod 對應
- **iVOD Whisper 逐字稿**：相對較新的功能，仍在擴充中，whisper-transcript.php 是非同步的（submit → 輪詢狀態）
- **常見修改類型**（依 git log 觀察）：人名/資料錯誤修正、解析器調整（文件格式變化）、錯誤處理與重試、新增欄位、爬蟲反封鎖（User-Agent、Proxy、IPv4）
