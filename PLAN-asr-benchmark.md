# PLAN-asr-benchmark.md
# 立法院 IVOD 語音辨識模型評測計劃

## 目標

找出最適合「立法院 IVOD 音訊」的開放權重 ASR 模型，評估是否需要從目前的 Whisper large-v3 切換。

---

## 評測設計

### Ground Truth 來源

**公報原文 = 標準答案**，不使用 Whisper 輸出文字。

流程：
1. 從 `agenda-anchor/` 中選取已有質詢型 anchor 的段落
2. 用既有的 ivod-gazette-align 結果，找到「公報某 anchor → IVOD 對應音訊時間區間」
3. 截取 IVOD 影片的對應音訊片段
4. 公報文字（去標點、normalize 後）作為該片段的 reference transcript

> Whisper 在這裡只扮演「對齊定位」工具，不貢獻到 ground truth 文字本身，不存在循環偏差。

### CER 計算的前處理（統一套用於所有模型輸出與 reference）

1. 移除所有標點符號（，。、！？；：「」『』〔〕【】…—）
2. 數字全半形統一（１２３ → 123）
3. 中國模型輸出：簡體 → 繁體轉換（opencc 或同等工具）
4. 移除多餘空白、統一換行

指標：**CER**（字元錯誤率），不用 WER（中文無詞界）。

---

## 測試立委（10 位）

選取原則：男女平衡、原住民、台語/口音多樣性。

| # | 姓名 | 性別 | 特徵 |
|---|------|------|------|
| 1 | 謝龍介 | 男 | 強台語，頻繁台語切換（用戶指定） |
| 2 | 賴士葆 | 男 | 特殊口音（用戶指定） |
| 3 | 伍麗華 Saidhai·Tahovecahe | 女 | 山地原住民（排灣族） |
| 4 | 鄭天財 Sra Kacaw | 男 | 平地原住民（布農族） |
| 5 | 陳亭妃 | 女 | 台語為主 |
| 6 | 陳瑩 | 女 | 平地原住民 |
| 7 | 黃捷 | 女 | 年輕、標準台灣腔普通話 |
| 8 | 范雲 | 女 | 不分區，口條清晰 |
| 9 | 柯建銘 | 男 | 老資格，台語腔普通話 |
| 10 | 韓國瑜 | 男 | 院長主席（場景三主要聲音），強口音普通話 |

---

## 測試場景（3 種）

| 場景 | 說明 | 聲學特性 | 難點 |
|------|------|----------|------|
| A：一般質詢 | 立委在委員會/院會質詢官員 | 相對清晰，單一主說話者 | 專有名詞、人名、法規名 |
| B：黨團協商 | 各黨團代表協商法案 | 多說話者、非正式、可能插話 | 噪音、語速不規律 |
| C：院會主席+司儀宣讀 | 韓國瑜主持院會；司儀朗讀議程 | 清晰朗讀，但大量法律/政治專有名詞 | 禁伐補償、三讀、附帶決議等罕見詞彙 |

每位立委盡量涵蓋 A + B 或 A + C，共約 20–30 個片段。
場景 C 主要針對韓國瑜（主席）及院會司儀（可不限定特定委員）。

**注意**：各場景單獨出分，不合並成總分，最終呈現為模型 × 委員 × 場景的矩陣。

---

## 受測模型

### 可用於生產

| 模型 | 來源 | 架構 | 中文支援 | License |
|------|------|------|----------|---------|
| **Whisper large-v3**（baseline） | OpenAI | Encoder-Decoder Transformer | ✓ | MIT |
| **SeamlessM4T v2 Large** | Meta | Unified S2TT | ✓ 普通話 | CC-BY-NC 4.0 |
| **Taiwan Tongues ASR CE** | 數位部（台大哥執行） | Whisper fine-tune | ✓ 普通話+台語+客語 | MIT |

> Taiwan Tongues ASR：HuggingFace `adi-gov-tw`，針對台灣口音 fine-tune，有獨立的 `evaluate_asr.py`，CPU 約 7min/5min 音訊，GPU（RTX 3080）約 30s。

### 僅供評比參考（中國公司，不可用於本專案生產環境）

| 模型 | 來源 | WenetSpeech Meeting CER | 備註 |
|------|------|------------------------|------|
| **FireRedASR-AED 1.1B** | FireRed（中國） | 4.76% | 非自迴歸，支援 word-level timestamp |
| **FunASR Paraformer-zh** | Alibaba（中國） | 6.97% | 整合 VAD+diarization+標點 |

---

## 實作步驟

### Step 1：建立 test segment 清單

```
scripts/asr-bench/01-select-segments.py
```

- 掃描 `agenda-anchor/` 中 term=11 的 anchors
- 對照 `ivod-gazette-align/` 找已對齊的 anchor（有 start/end 時間戳）
- 依 10 位委員 × 3 場景篩選，每格至少 1 個片段（優先選 30–120 秒長度）
- 輸出：`bench-segments.json`（含 ivod_id, anchor_id, start_sec, end_sec, reference_text）

### Step 2：截取音訊

```
scripts/asr-bench/02-extract-audio.sh
```

- 從 IVOD mp4/m3u8 下載對應時間段，輸出 wav（16kHz mono）
- 命名：`{委員}_{場景}_{seq}.wav`

### Step 3：Reference 前處理

```
scripts/asr-bench/03-normalize-ref.py
```

- 讀取 `bench-segments.json` 的 `reference_text`
- 套用統一前處理（去標點、數字normalize）
- 輸出：`bench-segments-normalized.json`

### Step 4：跑各模型推論

```
scripts/asr-bench/04-run-{model}.py  # 各模型一支腳本
```

- 讀入 wav，輸出 raw transcript
- 輸出到 `results/{model}/{segment_id}.txt`

### Step 5：計算 CER 並輸出報告

```
scripts/asr-bench/05-score.py
```

- 各模型輸出套用相同前處理（+簡繁轉換）
- 與 reference 算 CER
- 輸出 CSV 及摘要：模型 × 委員 × 場景矩陣

---

## 目錄結構

```
scripts/asr-bench/
├── 01-select-segments.py
├── 02-extract-audio.sh
├── 03-normalize-ref.py
├── 04-run-whisper.py
├── 04-run-seamless.py
├── 04-run-twtonges.py      # Taiwan Tongues ASR CE
├── 04-run-firered.py       # 參考用，不可生產
├── 04-run-paraformer.py    # 參考用，不可生產
├── 05-score.py
bench/
├── bench-segments.json
├── bench-segments-normalized.json
├── audio/
│   └── {委員}_{場景}_{seq}.wav
└── results/
    └── {model}/
        └── {segment_id}.txt
```

---

## 預期產出

- CER 矩陣：模型（列）× 場景（欄），各委員獨立一表
- 各場景最佳模型推薦
- 若 Taiwan Tongues ASR 在台語/原住民腔明顯優於 Whisper → 評估 fine-tune 方向
- 若所有非中國模型都輸 Whisper → 結論為維持現狀
