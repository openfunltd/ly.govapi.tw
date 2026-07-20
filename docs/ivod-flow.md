# IVOD 逐字稿產製流程

> 本文件說明現有 IVOD（立法院影像點播系統）逐字稿產製的完整架構，供改寫為獨立自動化產品前的架構整理使用。

---

## 整體流程

```
ivod.ly.gov.tw
      │
      ▼
[1] HTML 爬取 (cron/1min/ivod-html.php)
      │  儲存至 imports/ivod/html/{id}.html
      │
      ├─────────────────────────────────────┐
      ▼                                     ▼
[2] AI 逐字稿處理                      [3] 公報文字對應
  auto-loop.sh                        check-gazette.php
  └→ whisper-transcript.php           └→ LYLib::getIVODGazette()
       │ pyannote (誰在說話)                │
       │ whisperx (說了什麼)               │
       ▼                                   ▼
  ivod-transcript/{id}.json         ivod-gazette/{id}.json
      │                                     │
      └─────────────────────────────────────┘
                        │
                        ▼
              [4] 資料合併 & 索引
            cron/5min/import-ivod.php
                        │
               ┌────────┴────────┐
               ▼                 ▼
       ivod-data/{id}.json   ES: ivod index
```

---

## Step 1：HTML 爬取

**腳本**：`cron/1min/ivod-html.php`（內容與 `imports/ivod/crawl-html.php` 相同）

### IVOD 類型

| 類型 | URL 格式 | 說明 |
|------|----------|------|
| **Clip**（個人發言片段） | `ivod.ly.gov.tw/Play/Clip/1M/{id}` | 委員單次發言，有明確開始/結束時間 |
| **Full**（完整會議） | `ivod.ly.gov.tw/Play/Full/1M/{id}` | 整場會議錄影 |

### 執行頻率

- 偵測到 `"rettim":null`（表示有進行中直播）→ 更新 `cache/ivod-latest-rettim` 時間戳
- 30 分鐘內曾有直播：每分鐘執行一次
- 無直播：每 10 分鐘執行一次（以 `date('i') % 10 === 0` 判斷）
- 可用 `force` 參數強制執行

### Full 類型爬取邏輯

從 `current-full-id` 往後掃（起點 - 20 開始，最多到 +5），對每個 ID：
1. 若 HTML 已存在且不含 `"rettim":null`（已結束的直播）→ 更新 `current-full-id`，跳過
2. 若 HTML 不存在或仍是直播狀態 → 重新抓取
3. 若 HTML 含 `readyPlayer(...)` → 有效影片，儲存並更新 `current-full-id`
4. 無 `readyPlayer` → 此 ID 不存在，跳過

### Clip 類型爬取邏輯

從 `max(current-id, 146300)` 往後掃：
1. HTML 已存在 → 跳過
2. 抓取頁面，無 `readyPlayer` → 計入 miss 次數
3. 連續 10 次 miss → 停止（表示到達末端）
4. 有效頁面 → 儲存，更新 `current-id`

### 追蹤檔案

| 檔案 | 說明 | 目前值 |
|------|------|--------|
| `imports/ivod/current-id` | 最新 Clip ID | ~170430 |
| `imports/ivod/current-full-id` | 最新 Full ID | ~1758823462 |

### 資料目錄

- `imports/ivod/html/`：已存 ~104,125 個 HTML 檔案

---

## Step 2：AI 逐字稿處理

**常駐服務**：`imports/ivod/auto-loop.sh`

```sh
#!/bin/sh
cd $(dirname $0)
while true; do
  php whisper-transcript.php
  sleep 1
done
```

每次 `whisper-transcript.php` 執行完畢後立即重啟，確保持續處理。

### whisper-transcript.php 處理流程

**外部服務**：`https://{WHISPERAPI_HOST}`（`whisper-api.openfun.dev`），需 `WHISPERAPI_KEY`

#### Clip 處理（優先）

從 ID 169400 往後掃到 `current-id`，每輪最多處理 3 個 Clip：

1. 無 HTML → 跳過
2. 已有 transcript JSON 且無錯誤 → 跳過
3. 有錯誤的 transcript：超過 20 分鐘才重試；超過一週的失敗影片可選擇放棄
4. 發送兩個非同步 job：
   - `pyannote`：語者分析（誰在說話）
   - `whisperx`：語音轉文字

#### Full 處理（補位）

若 Clip 輪本次處理數 < 1，從 `current-full-id` 往前掃：

1. 含 `"rettim":null`（直播中）→ 跳過
2. 發送三個 job：`pyannote`、`whisperx`、`clean`（目前 clean 在 Clip 已註解掉）

#### Job API 介面

```
GET https://{WHISPERAPI_HOST}/queue/add
  ?key={WHISPERAPI_KEY}
  &url=https://ivod.ly.gov.tw/Play/Clip/1M/{id}   ← 來源影片 URL
  &tool=pyannote|whisperx
  &id={ivod_id}-pyannote|{ivod_id}-whisperx
```

回傳：`{"job_id": "...", "api_url": "https://..."}`

輪詢 `api_url` 直到 `job.status` 為 `done` 或 `error`（timeout 6000 秒）。

#### 輸出格式

`imports/ivod/ivod-transcript/{id}.json`：

```json
{
  "id": "170001",
  "pyannote": {
    "status": "done",
    "data": {
      "id": "170001-pyannote",
      "result": [[0.5, 3.2, "SPEAKER_00"], [3.5, 7.1, "SPEAKER_01"], ...]
    }
  },
  "whisperx": {
    "status": "done",
    "data": {
      "id": "170001-whisperx",
      "result": [
        {"start": 0.5, "end": 3.2, "text": "主席：..."},
        ...
      ]
    }
  }
}
```

- **pyannote** 結果：`[start_sec, end_sec, speaker_label]` 三元組陣列
- **whisperx** 結果：`{start, end, text}` 物件陣列

#### 資料目錄

- `imports/ivod/ivod-transcript/`：已存 ~24,786 個 JSON 檔案

---

## Step 3：公報文字對應

**腳本**：`imports/ivod/check-gazette.php`

### 觸發條件

從 `current-id` 往前掃：
- 已有 `ivod-gazette/{id}.json` → 跳過
- 會議日期超過 `max_meeting_date`（最新公報 CSV 日期）→ 跳過（該會議公報尚未匯入）

### 核心邏輯：`LYLib::getIVODGazette($ivod)`

**目標**：在公報全文中找到與此 IVOD Clip 對應的發言段落

1. 從 ES `meet` index 取得會議資料，找出包含 `公報發言紀錄` 的議程
2. 依日期與委員姓名篩選議程（Full 類型不過濾委員）
3. 對每個議程，從 S3（`lydata.ronny-s3.click`）或本地快取載入 tikahtml 公報全文
4. 用 `GazetteTranscriptParser::parse()` 解析公報
5. 在解析結果中搜尋 `段落：質詢：{speaker}` 標記
6. 時間比對：公報中的發言時間與 IVOD Clip 的 `start_time` 差異需在 **180 秒內**
7. 找到後，擷取到下一個 `段落：`、`報告院會` 或 `發言完畢` 為止的文字區塊

### 輸出格式

`imports/ivod/ivod-gazette/{id}.json`：

```json
{
  "lineno": 1234,
  "blocks": ["質詢內容第一段", "質詢內容第二段", ...],
  "agenda": {
    "meet_id": "LYPL01N01L09D12",
    "speakers": ["王委員"],
    "page_start": 45,
    "page_end": 48,
    "meetingDate": ["2021-03-15"]
  }
}
```

### 資料目錄

- `imports/ivod/ivod-gazette/`：已存 ~103,872 個 JSON 檔案（覆蓋率極高）

---

## Step 4：資料合併與 ES 索引

**腳本**：`cron/5min/import-ivod.php`（內容與 `imports/ivod/import-ivod.php` 相同）

### 執行邏輯

每 5 分鐘執行，從 `current-id` 往前掃，預設只處理 7 天內的資料：

1. 讀取 `imports/ivod/html/{id}.html`，呼叫 `IVodParser::parseHTML()` 解析基本元資料
2. 呼叫 `IVodParser::checkMeetFromIVOD()` 解析對應的會議 ID（`meet_id`）
3. 若 `ivod-transcript/{id}.json` 存在且無錯誤 → 合併 AI 逐字稿，加入 `features: ["ai-transcript"]`
4. 若 `ivod-gazette/{id}.json` 存在 → 合併公報文字，加入 `features: ["gazette"]`
5. 寫入 `ivod-data/{id}.json`
6. 用 `Elastic::dbBulkInsert()` + `Elastic::dbBulkCommit()` 寫入 ES `ivod` index

### IVodParser::parseHTML() 解析欄位

| 欄位 | Clip 來源 | Full 來源 |
|------|-----------|-----------|
| `委員名稱` | HTML `<h4>` | JSON `_movie` |
| `start_time` | 委員發言時間（格式 `HH:MM`） | JSON `rsttim` |
| `end_time` | 委員發言時間（格式 `HH:MM`） | JSON `rettim` |
| `會議名稱` | HTML title | JSON `_movie` |
| `影片長度` | HTML `<span>` | 計算得出 |
| `video_url` | `readyPlayer(...)` 中的 m3u8 URL | 同左 |

### IVodParser::checkMeetFromIVOD() 會議 ID 解析

- 一般議事：`LYLib::meetNameToId(會議名稱, 日期)` → 比對 ES `meet` index
- 黨團協商：`LYLib::consultToId(...)` → 比對 `consultation` index
- 公聽會：另有對應邏輯

### ES ivod index 文件結構（簡化）

```json
{
  "id": "170001",
  "type": "Clip",
  "委員名稱": "王委員",
  "會議名稱": "第10屆第7會期第1次會議",
  "meet_id": "LYPL01N01L10D07",
  "start_time": "2022-03-01 09:30",
  "end_time": "2022-03-01 09:45",
  "影片長度": 900,
  "video_url": "https://...m3u8",
  "transcript": [...],      // 來自 whisperx
  "diarization": [...],     // 來自 pyannote
  "gazette_blocks": [...],  // 來自 check-gazette
  "features": ["ai-transcript", "gazette"]
}
```

### 資料目錄

- `imports/ivod/ivod-data/`：已存 ~104,125 個 JSON 檔案

---

## 各腳本依賴總覽

```
init.inc.php         ← 環境設定、DB 連線、env 變數
IVodParser.php       ← HTML 解析、會議 ID 對應
LYLib.php            ← getIVODGazette()、meetNameToId()
GazetteTranscriptParser.php ← 公報全文解析
Elastic.php          ← ES 讀寫封裝
```

**環境變數**：
- `WHISPERAPI_HOST`：Whisper API 服務主機（`whisper-api.openfun.dev`）
- `WHISPERAPI_KEY`：API 金鑰
- ES 連線設定

---

## 已知限制與改寫建議

### 現有限制

1. **Clip ID 從 169400 掃起**：`whisper-transcript.php` 硬編碼起點，歷史資料無法回補
2. **Full 往前掃但 Clip 往後掃**：兩種類型掃描方向相反，邏輯不對稱
3. **每輪最多 3 個 Clip + 1 個 Full**：處理量受限，如有積壓難以追趕
4. **`auto-loop.sh` 為 busy loop**：只 sleep 1 秒，即使沒有新資料也持續輪詢
5. **公報對應時間容忍度固定 180 秒**：無法動態調整
6. **錯誤重試邏輯分散**：Clip 等 20 分鐘重試、Full 等 5 分鐘，散落在多個地方
7. **`cron/5min/import-ivod.php` 只看 7 天**：預設不重新索引舊資料，需手動改參數

### 改寫為獨立產品的建議方向

1. **任務佇列化**：新增 job queue（Redis/DB），取代目前的 loop + sleep 機制
2. **統一 ID 追蹤**：用 DB 儲存每個 ID 的處理狀態，而非依賴檔案存在與否
3. **分離爬取與處理**：HTML 爬取、AI 處理、公報對應各為獨立服務，透過 queue 串接
4. **錯誤處理集中化**：統一的 retry policy，有明確的 backoff 策略
5. **Whisper API 抽象化**：封裝成可替換的介面，方便切換 local inference 或其他服務
6. **Full IVOD 直播偵測**：把 `rettim:null` 的即時狀態管理獨立為 live-stream monitor
7. **公報對應結果快取**：目前 `ivod-gazette/` 就是快取，但與主流程耦合，建議搬入 DB
