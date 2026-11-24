# imports/segment — 會議紀錄切分（單元 → 段）

把公報發言索引（ES `speech`，一次發言一筆）再往上疊一層「單元 → 段」的標註，給網站畫段落目錄。
切分規則來自 James 在 research-ly-meet 的研究（院會、委員會預算審查、公聽會、黨團協商、全院委員會人事審查
五類切分器），2026-11-24 以 James 同意開源為前提搬進本 repo。

## 檔案

| 檔案 | 來源 | 說明 |
|---|---|---|
| `speakers.py`、`stages.py`、`hearing.py` | research-ly-meet `scripts/` | 共用：發言人判斷、公報文件取得、公聽會對應 |
| `segment_plenary.py`、`segment_budget.py`、`segment_hearing.py`、`segment_caucus.py` | `scripts/` | 院會、委員會預算審查、公聽會、黨團協商切分器 |
| `segment.py`、`build_term10_11.py`、`build_meetjson.py` | `scripts/` | 全院委員會人事審查切分器（還沒接進 `segment_meet.py`） |
| `plenary.py`、`budget.py`、`negotiation.py` | `findings/*/scripts/` | 各類研究線的資料層與規則 |
| `data/recovered-docs.json`、`data/recovered-lcidc.json` | `findings/` | 人工反查的「meet 沒有公報發言紀錄」文件對應（之後改由 link-meet 產生） |
| `segment_meet.py` | 本 repo | 驅動程式：跑切分器、把段對回 speech 的發言編號、寫 `meet_section` index |
| `lyapi_es.py` | 本 repo | ES 存取（設定從 config.php 讀） |
| `parsed.php` | 本 repo | 本機版 `/gazette_agenda_doc/{id}/parsed`（對本機 tikahtml 跑 GazetteTranscriptParser） |
| `run-segment.py`、`run-segment.php` | 本 repo | cron 用的增量掃描（見下） |

James 的檔案只改了路徑（`.cache` 改在本目錄、`findings/...` 的模組與資料改成本目錄），規則一行沒動；
搬入時的版本是 research-ly-meet `93b0034`（2026-11-23）。之後切分規則的修改請直接在這裡改，不要再從 research-ly-meet 複製。

## 資料流

```
/meet/{id} 公報發言紀錄 ─┐
                         ├→ 切分器（James）→ session／section（段落序）
/gazette_agenda_doc/{lcidc}/parsed ─┘                      │
                                                           ▼
ES speech（import-speech.php，block_line）←─ parsed.block_lines[block 序] 對 block_line ─→ 發言編號 seq
                                                           ▼
                                      ES meet_section（一段一筆）＋ meet-section/{meet_id}.json
```

切分器透過 v2 API 取 meet、meets 清單、公報目錄等（token 從 config.php 的 `OPENFUN_DATA_TOKEN` 拿，快取在 `.cache/`）；
最重的 parsed 不打 API：`segment_meet.py` 把各模組的 `fetch()` 包起來，遇到 `/gazette_agenda_doc/{id}/parsed` 就用
`parsed.php` 對本機 `imports/gazette/agenda-tikahtml/` 跑同一個 `GazetteTranscriptParser`（實測輸出與 API 完全相同，0.05 秒），
寫進切分器原本的快取檔。parsed 和 speech 都是同一次切出來的 block，用 tikahtml 行號 `block_line` 直接對。
「段落：…」合成標記沒有 speech block，對不到就略過。

## 用法

```
python3 segment_meet.py 院會-11-5-18 委員會-11-5-20-18 公聽會-2026102285   # 寫 meet-section/{id}.json
python3 segment_meet.py --index 院會-11-5-18                                 # 同時寫進 ES meet_section
python3 segment_meet.py --caucus 1086006_00005                               # 黨團協商以公報文件編號跑
```

## `meet_section` 欄位

一段一筆：`meet_id`、`segmenter`（院會／委員會預算審查／公聽會／黨團協商）、`session_no`／`session_name`／`session_kind`／`session_dates`、
`section_no`／`section_type`／`name`／`tags`／`vote`、`unit_kind`（person／case／stage／group／procedure／attachment）、
`lcidc_id`、`seq_start`／`seq_end`／`seqs`（發言編號）、`speakers`、`sample`、`segmenter_version`。

`unit_kind` 第一版規則在 `segment_meet.unit_kind()`，是照各切分器的 kind／role 對照出來的，待 James 核。

## 還沒做

- 委員會非預算日（審法案、業務報告）：James 沒有切分，照交接文件只顯示一層單元。
- 全院委員會人事審查：切分器已搬入，驅動程式還沒接。
- 黨團協商：切分器吃公報目錄類別 8 的 LCIDC 文件，本 repo 的黨團協商發言索引是 ppg 附件 PDF（`dangtuan-*`），兩邊要對起來。
- speech block 回寫 單元／段／unit_kind（目前只有 meet_section 這一側有；v2 的 `/meet_sections`、`/meet/{id}/meet_sections` 已上線，lymeet 的段落目錄讀這個）。
- 回歸測試（James 的 `findings/annotations/`、各類驗收數字）。
