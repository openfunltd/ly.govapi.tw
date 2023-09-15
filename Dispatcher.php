<?php

/**
 * @OA\Info(
 *   title="立法院 API", version="1.0.0"
 * )
 * @OA\Tag(name="legislator", description="立法委員")
 * @OA\Tag(name="committee", description="委員會")
 * @OA\Tag(name="meet", description="會議")
 * @OA\Tag(name="bill", description="議案")
 * @OA\Tag(name="interpellation", description="質詢")
 * @OA\Tag(name="gazette", description="公報")
 * @OA\Schema(schema="Error", type="object", required={"error"}, @OA\Property(property="error", type="string"))
 *  @OA\Schema(
 *    schema="Legislator",
 *    type="object",
 *    @OA\Property(property="term", type="integer", description="屆別"),
 *    @OA\Property(property="name", type="string", description="姓名"),
 *    @OA\Property(property="ename", type="string", description="英文姓名"),
 *    @OA\Property(property="sex", type="string", description="性別"),
 *    @OA\Property(property="party", type="string", description="黨籍"),
 *    @OA\Property(property="partyGroup", type="string", description="黨團"),
 *    @OA\Property(property="areaName", type="string", description="選區"),
 *    @OA\Property(property="committee", type="array", description="委員會", @OA\Items(type="string")),
 *    @OA\Property(property="onboardDate", type="string", description="就職日期"),
 *    @OA\Property(property="degree", type="array", description="學歷", @OA\Items(type="string")),
 *    @OA\Property(property="experience", type="array", description="經歷", @OA\Items(type="string")),
 *    @OA\Property(property="picUrl", type="string", description="照片連結"),
 *    @OA\Property(property="leaveFlag", type="string", description="離職否"),
 *    @OA\Property(property="leaveDate", type="string", description="離職日期"),
 *    @OA\Property(property="leaveReason", type="string", description="離職原因"),
 *    )
 */
class Dispatcher
{
    /**
     * @OA\Get(
     *   path="/legislator", summary="歷屆立法委員資料", tags={"legislator"},
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *   @OA\Response(response="200", description="歷屆立法委員資料", @OA\JsonContent(ref="#/components/schemas/Legislator")),
     *   @OA\Response(response="404", description="找不到立法委員資料", @OA\JsonContent(ref="#/components/schemas/Error")),
     *  )
     * @OA\Get(
     *   path="/legislator/{term}", summary="第 {term} 屆立法委員資料", tags={"legislator"},
     *   @OA\Parameter(name="term", in="path", description="屆別", required=true, @OA\Schema(type="integer"), example=9),
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *   @OA\Response(response="200", description="第 {term} 屆立法委員資料", @OA\JsonContent(ref="#/components/schemas/Legislator")),
     *   @OA\Response(response="404", description="找不到立法委員資料", @OA\JsonContent(ref="#/components/schemas/Error")),
     *  )
     * @OA\Get(
     *   path="/legislator/{term}/{name}", summary="取得特定立法委員資料", tags={"legislator"},
     *   @OA\Parameter(name="term", in="path", description="屆別", required=true, @OA\Schema(type="integer"), example=9),
     *   @OA\Parameter(name="name", in="path", description="姓名", required=true, @OA\Schema(type="string"), example="王金平"),
     *   @OA\Response(response="200", description="立法委員資料", @OA\JsonContent(ref="#/components/schemas/Legislator")),
     *   @OA\Response(response="404", description="找不到立法委員資料", @OA\JsonContent(ref="#/components/schemas/Error")),
     *  )
     *  @OA\Get(
     *    path="/legislator/{term}/{name}/propose_bill", summary="取得特定委員的提案議案列表", tags={"legislator"},
     *    @OA\Parameter(name="term", in="path", description="屆別", required=true, @OA\Schema(type="integer"), example=9),
     *    @OA\Parameter(name="name", in="path", description="姓名", required=true, @OA\Schema(type="string"), example="王金平"),
     *    @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *    @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *    @OA\Response(response="200", description="提案議案列表", @OA\JsonContent(ref="#/components/schemas/Bill")),
     *  )
     *  @OA\Get(
     *    path="/legislator/{term}/{name}/cosign_bill", summary="取得特定委員的連署議案列表", tags={"legislator"},
     *    @OA\Parameter(name="term", in="path", description="屆別", required=true, @OA\Schema(type="integer"), example=9),
     *    @OA\Parameter(name="name", in="path", description="姓名", required=true, @OA\Schema(type="string"), example="王金平"),
     *    @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *    @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *    @OA\Response(response="200", description="連署議案列表", @OA\JsonContent(ref="#/components/schemas/Bill")),
     *  )
     *  @OA\Get(
     *    path="/legislator/{term}/{name}/meet", summary="取得特定委員的會議紀錄列表", tags={"legislator"},
     *    @OA\Parameter(name="term", in="path", description="屆別", required=true, @OA\Schema(type="integer"), example=9),
     *    @OA\Parameter(name="name", in="path", description="姓名", required=true, @OA\Schema(type="string"), example="王金平"),
     *    @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *    @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *    @OA\Response(response="200", description="會議紀錄列表", @OA\JsonContent(ref="#/components/schemas/Meet")),
     *  )
     *  @OA\Get(
     *    path="/legislator/{term}/{name}/interpellation", summary="取得特定委員的質詢紀錄列表", tags={"legislator"},
     *    @OA\Parameter(name="term", in="path", description="屆別", required=true, @OA\Schema(type="integer"), example=9),
     *    @OA\Parameter(name="name", in="path", description="姓名", required=true, @OA\Schema(type="string"), example="林淑芬"),
     *    @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *    @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *    @OA\Response(response="200", description="質詢紀錄列表", @OA\JsonContent(ref="#/components/schemas/Interpellation")),
     *  )
     */
    public static function legislator($params)
    {
        if (count($params) > 2) {
            if ($params[2] == 'propose_bill') {
                $_GET['proposer'] = $params[1];

                return self::bill([$params[0]]);
            } elseif ($params[2] == 'cosign_bill') {
                $_GET['cosignatory'] = $params[1];

                return self::bill([$params[0]]);
            } elseif ($params[2] == 'meet') {
                $_GET['legislator'] = $params[1];
                $_GET['term'] = $params[0];

                return self::meet([$params[0]]);
            } elseif ($params[2] == 'interpellation') {
                $_GET['legislator'] = $params[1];
                $_GET['term'] = $params[0];

                return self::interpellation([$params[0]]);
            }

        }

        $cmd = [
            'query' => [
                'bool' => [
                    'must' => [],
                ],
            ],
        ];

        $records = new StdClass;
        $records->total = 0;
        $records->total_page = 0;
        $records->page = @intval($_GET['page']) ?: 1;
        $records->limit = @intval($_GET['limit']) ?: 100;
        $cmd['size'] = $records->limit;
        $cmd['from'] = ($records->page - 1) * $records->limit;

        if (count($params) > 0) {
            $term = intval($params[0]);
            $records->term = $term;
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'term' => $term,
                ],
            ];
        }
        if (count($params) > 1) {
            $obj = Elastic::dbQuery("/{prefix}legislator/_doc/" . intval($records->term) . '-' . urlencode($params[1]));
            if (isset($obj->found) && $obj->found) {
                self::json_output($obj->_source);
            } else {
                header('HTTP/1.0 404 Not Found');
                self::json_output(['error' => 'not found']);
            }
            return;
        }

        $obj = Elastic::dbQuery("/{prefix}legislator/_search", 'GET', json_encode($cmd));
        $records->total = $obj->hits->total;
        $records->total_page = ceil($records->total->value / $records->limit);
        $records->legislators = [];
        foreach ($obj->hits->hits as $hit) {
            $records->legislators[] = $hit->_source;
        }
        self::json_output($records);
    }

    /**
     * @OA\Get(
     *   path="/committee", summary="委員會資料", tags={"committee"},
     *   @OA\Response(response="200", description="委員會資料", @OA\JsonContent(ref="#/components/schemas/Committee")),
     *   @OA\Response(response="404", description="找不到委員會資料", @OA\JsonContent(ref="#/components/schemas/Error")),
     *   )
     *   @OA\Get(
     *   path="/committee/{comtCd_or_comtName}", summary="取得特定委員會資料", tags={"committee"},
     *   @OA\Parameter(name="comtCd_or_comtName", in="path", description="委員會 ID 或 名稱", required=true, @OA\Schema(type="string"), example="內政委員會"),
     *   @OA\Response(response="200", description="委員會資料", @OA\JsonContent(ref="#/components/schemas/Committee")),
     *   @OA\Response(response="404", description="找不到委員會資料", @OA\JsonContent(ref="#/components/schemas/Error")),
     *   )
     *   @OA\Get(
     *     path="/committee/{comtCd_or_comtName}/meet", summary="取得特定委員會的會議紀錄列表", tags={"committee"},
     *     @OA\Parameter(name="comtCd_or_comtName", in="path", description="委員會 ID 或 名稱", required=true, @OA\Schema(type="string"), example="內政委員會"),
     *     @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *     @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *     @OA\Response(response="200", description="會議紀錄列表", @OA\JsonContent(ref="#/components/schemas/Meet")),
     *   )
     *   @OA\Get(
     *     path="/committee/{comtCd_or_comtName}/meet/{term}", summary="取得特定委員會的特定屆次的會議紀錄列表", tags={"committee"},
     *     @OA\Parameter(name="comtCd_or_comtName", in="path", description="委員會 ID 或 名稱", required=true, @OA\Schema(type="string"), example="內政委員會"),
     *     @OA\Parameter(name="term", in="path", description="屆次", required=true, @OA\Schema(type="integer"), example=9),
     *     @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *     @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *     @OA\Response(response="200", description="會議紀錄列表", @OA\JsonContent(ref="#/components/schemas/Meet")),
     *   )
     *   @OA\Get(
     *     path="/committee/{comtCd_or_comtName}/meet/{term}/{sessionPeriod}", summary="取得特定委員會的特定屆次的特定會期的會議紀錄列表", tags={"committee"},
     *     @OA\Parameter(name="comtCd_or_comtName", in="path", description="委員會 ID 或 名稱", required=true, @OA\Schema(type="string"), example="內政委員會"),
     *     @OA\Parameter(name="term", in="path", description="屆次", required=true, @OA\Schema(type="integer"), example=9),
     *     @OA\Parameter(name="sessionPeriod", in="path", description="會期", required=true, @OA\Schema(type="integer"), example=1),
     *     @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *     @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *     @OA\Response(response="200", description="會議紀錄列表", @OA\JsonContent(ref="#/components/schemas/Meet")),
     *   )
     *   @OA\Schema(
     *   schema="Committee", type="object", required={"comtCd", "comtName", "comtDesp", "comtType"},
     *   @OA\Property(property="comtCd", type="string", description="委員會代號"),
     *   @OA\Property(property="comtName", type="string", description="委員會名稱"),
     *   @OA\Property(property="comtDesp", type="string", description="委員會/職掌"),
     *   @OA\Property(property="comtType", type="string", description="委員會類別(1:常設委員會,2:特種委員會,3:廢止委員會)"),
     *   )
     */
    public static function committee($params)
    {
        if (count($params) > 0 and !preg_match('#^\d+$#', $params[0])) {
            $name = $params[0];
            $name = str_replace('委員會', '', $name);
            $name .= '委員會';

            $obj = Elastic::dbQuery("/{prefix}committee/_search", 'GET', json_encode([
                'query' => [
                    'bool' => [
                        'must' => [
                            [
                                'match' => [
                                    'comtName.keyword' => $name,
                                ],
                            ],
                        ],
                    ],
                ],
            ]));
            if ($obj->hits->total->value > 0) {
                $params[0] = $obj->hits->hits[0]->_source->comtCd;
                return self::committee($params);
            } else {
                header('HTTP/1.0 404 Not Found');
                return self::json_output(['error' => 'not found']);
            }
        }

        if (count($params) > 1 and $params[1] == 'meet') {
            $_GET['committee_id'] = $params[0];

            if (count($params) > 2) {
                $_GET['term'] = $params[2];
            }
            if (count($params) > 3) {
                $_GET['sessionPeriod'] = $params[3];
            }

            return self::meet([]);
        }


        $cmd = [
            'query' => [
                'bool' => [
                    'must' => [],
                ],
            ],
            'size' => 100,
        ];

        $records = new StdClass;
        $records->total = 0;

        if (count($params) > 0) {
            $obj = Elastic::dbQuery("/{prefix}committee/_doc/" . intval($params[0]));
            if (isset($obj->found) && $obj->found) {
                self::json_output($obj->_source);
            } else {
                header('HTTP/1.0 404 Not Found');
                self::json_output(['error' => 'not found']);
            }
            return;
        }

        $obj = Elastic::dbQuery("/{prefix}committee/_search", 'GET', json_encode($cmd));
        $records->total = $obj->hits->total;
        $records->committees = [];
        foreach ($obj->hits->hits as $hit) {
            $records->committees[] = $hit->_source;
        }
        self::json_output($records);
    }

    /**
     * @OA\Get(
     *   path="/gazette", summary="取得依時間新至舊的公報", tags={"gazette"},
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *   @OA\Response(response="200", description="公報資料", @OA\JsonContent(ref="#/components/schemas/Gazette")),
     *   )
     *   @OA\Get(
     *   path="/gazette/{comYear}", summary="取得特定年度的公報", tags={"gazette"},
     *   @OA\Parameter(name="comYear", in="path", description="年度", required=true, @OA\Schema(type="integer"), example=109),
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *   @OA\Response(response="200", description="公報資料", @OA\JsonContent(ref="#/components/schemas/Gazette")),
     *   )
     *   @OA\Get(
     *   path="/gazette/{comYear}/{comVolume}", summary="取得特定年度卷號的公報", tags={"gazette"},
     *   @OA\Parameter(name="comYear", in="path", description="年度", required=true, @OA\Schema(type="integer"), example=109),
     *   @OA\Parameter(name="comVolume", in="path", description="卷號", required=true, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *   @OA\Response(response="200", description="公報資料", @OA\JsonContent(ref="#/components/schemas/Gazette")),
     *   )
     *   @OA\Get(
     *   path="/gazette/{gazette_id}", summary="取得特定公報資料", tags={"gazette"},
     *   @OA\Parameter(name="gazette_id", in="path", description="公報 ID", required=true, @OA\Schema(type="string"), example="LCIDC01_1126203"),
     *   @OA\Response(response="200", description="公報資料", @OA\JsonContent(ref="#/components/schemas/Gazette")),
     *   @OA\Response(response="404", description="找不到公報資料", @OA\JsonContent(ref="#/components/schemas/Error")),
     *   )
     *   @OA\Schema(
     *   schema="Gazette", type="object", required={"comYear", "comVolume", "comBookId", "comDate", "comTitle", "comUrl"},
     *   @OA\Property(property="comYear", type="integer", description="年度"),
     *   @OA\Property(property="comVolume", type="integer", description="卷號"),
     *   @OA\Property(property="comBookId", type="integer", description="冊號"),
     *   @OA\Property(property="gazette_id", type="string", description="公報 ID"),
     *   @OA\Property(property="agenda_api", type="string", description="公報目錄 API"),
     *   )
     */
    public static function gazette($params)
    {
        $cmd = [
            'query' => [
                'bool' => [
                    'must' => [],
                ],
            ],
            'sort' => [
                'comYear' => 'desc',
                'comVolume' => 'desc',
                'comBookId' => 'desc',
            ],
            'size' => 100,
        ];

        $records = new StdClass;
        $records->total = 0;
        $records->page = @intval($_GET['page']) ?: 1;
        $records->limit = @intval($_GET['limit']) ?: 100;
        $cmd['size'] = $records->limit;
        $cmd['from'] = ($records->page - 1) * $records->limit;


        if (count($params) > 0) {
            if (strpos($params[0], 'LCIDC') === 0) {
                $obj = Elastic::dbQuery("/{prefix}gazette/_doc/" . urlencode($params[0]));
                if (isset($obj->found) && $obj->found) {
                    self::json_output(LYLib::buildGazette($obj->_source));
                } else {
                    header('HTTP/1.0 404 Not Found');
                    self::json_output(['error' => 'not found']);
                }
                return;
            }
            $records->comYear = intval($params[0]);
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'comYear' => $records->comYear,
                ],
            ];
        }
        if (count($params) > 1) {
            $records->comVolume = intval($params[1]);
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'comVolume' => $records->comVolume,
                ],
            ];
        }

        $obj = Elastic::dbQuery("/{prefix}gazette/_search", 'GET', json_encode($cmd));
        $records->total = $obj->hits->total;
        $records->total_page = ceil($records->total->value / $records->limit);
        $records->gazettes = [];
        foreach ($obj->hits->hits as $hit) {
            $records->gazettes[] = LYLib::buildGazette($hit->_source);
        }
        self::json_output($records);
    }

    /**
     * @OA\Get(
     *  path="/gazette_agenda", summary="取得依時間新至舊的公報目錄", tags={"gazette"},
     *  @OA\Parameter(name="date", in="query", description="會議日期", required=false, @OA\Schema(type="string"), example="2017-01-19"),
     *  @OA\Parameter(name="date_start", in="query", description="會議日期起", required=false, @OA\Schema(type="string"), example="2017-01-01"),
     *  @OA\Parameter(name="date_end", in="query", description="會議日期迄", required=false, @OA\Schema(type="string"), example="2017-01-31"),
     *  @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *  @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *  @OA\Response(response="200", description="公報目錄資料", @OA\JsonContent(ref="#/components/schemas/GazetteAgenda")),
     *  )
     *  @OA\Get(
     *   path="/gazette_agenda/{gazette_id}", summary="取得公報下所有目錄", tags={"gazette"},
     *   @OA\Parameter(name="gazette_id", in="path", description="公報 ID", required=true, @OA\Schema(type="string"), example="LCIDC01_1126203"),
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *  @OA\Parameter(name="date", in="query", description="會議日期", required=false, @OA\Schema(type="string"), example="2017-01-19"),
     *  @OA\Parameter(name="date_start", in="query", description="會議日期起", required=false, @OA\Schema(type="string"), example="2017-01-01"),
     *  @OA\Parameter(name="date_end", in="query", description="會議日期迄", required=false, @OA\Schema(type="string"), example="2017-01-31"),
     *   @OA\Response(response="200", description="公報目錄資料", @OA\JsonContent(ref="#/components/schemas/GazetteAgenda")),
     *  )
     *  @OA\Get(
     *   path="/gazette_agenda/{agenda_id}", summary="取得特定公報目錄資料", tags={"gazette"},
     *   @OA\Parameter(name="agenda_id", in="path", description="公報目錄 ID", required=true, @OA\Schema(type="string"), example="LCIDC01_1126203_0001"),
     *   @OA\Response(response="200", description="公報目錄資料", @OA\JsonContent(ref="#/components/schemas/GazetteAgenda")),
     *   @OA\Response(response="404", description="找不到公報目錄資料", @OA\JsonContent(ref="#/components/schemas/Error")),
     *   )
     *  @OA\Schema( 
     *  schema="GazetteAgenda", type="object", required={"comYear", "comVolume", "comBookId", "comDate", "comTitle", "comUrl"},
     *  @OA\Property(property="comYear", type="integer", description="卷"),
     *  @OA\Property(property="comVolume", type="integer", description="期"),
     *  @OA\Property(property="comBookId", type="integer", description="冊"),
     *  @OA\Property(property="term", type="integer", description="屆"),
     *  @OA\Property(property="sessionPeriod", type="integer", description="會期"),
     *  @OA\Property(property="sessionTimes", type="integer", description="會次"),
     *  @OA\Property(property="meetingTimes", type="integer", description="臨時會會次"),
     *  @OA\Property(property="agendaNo", type="integer", description="目錄編號"),
     *  @OA\Property(property="agendaType", type="integer", description="類別代碼(1:院會、2:國是論壇、3:委員會、4:質詢事項、5:議事錄、8:黨團協商紀錄、9:發言索引、10:報告事項、11:討論事項、12:臨時提案)"),
     *  @OA\Property(property="meetingDate", type="array", description="會議日期", @OA\Items(type="string")),
     *  @OA\Property(property="subject", type="string", description="案由"),
     *  @OA\Property(property="pageStart", type="integer", description="起頁"),
     *  @OA\Property(property="pageEnd", type="integer", description="迄頁"),
     *  @OA\Property(property="selectTerm", type="string", description="選定屆別"),
     *  @OA\Property(property="agenda_id", type="string", description="公報目錄 ID"),
     *  @OA\Property(property="gazette_id", type="string", description="公報 ID")
     *  )
     */
    public static function gazette_agenda($params)
    {
        if (count($params) >= 2 and $params[1] == 'html') {
            return self::gazette_agenda_html([$params[0]]);
        }

        $cmd = [
            'query' => [
                'bool' => [
                    'must' => [],
                ],
            ],
            'sort' => [
                'comYear' => 'desc',
                'comVolume' => 'desc',
                'comBookId' => 'desc',
            ],
            'size' => 100,
        ];

        $records = new StdClass;
        $records->total = 0;
        $records->total_page = 0;
        $records->page = @intval($_GET['page']) ?: 1;
        $records->limit = @intval($_GET['limit']) ?: 100;
        $cmd['size'] = $records->limit;
        $cmd['from'] = ($records->page - 1) * $records->limit;

        if (count($params) > 0) {
            if (preg_match('/^LCIDC01_(\d+)$/', $params[0], $matches)) {
                $records->gazette_id = $params[0];
                $records->comYear = intval(substr($matches[1], 0, 3));
                $matches[1] = substr($matches[1], 3);
                $records->comBookId = intval(substr($matches[1], -2));
                $records->comVolume = intval(substr($matches[1], 0, -2));
                $cmd['query']['bool']['must'][] = [
                    'term' => [
                        'comYear' => $records->comYear,
                    ],
                ];
                $cmd['query']['bool']['must'][] = [
                    'term' => [
                        'comVolume' => $records->comVolume,
                    ],
                ];
                $cmd['query']['bool']['must'][] = [
                    'term' => [
                        'comBookId' => $records->comBookId,
                    ],
                ];
            } elseif (preg_match('/^LCIDC01_\d+_\d+$/', $params[0], $matches)) {
                $obj = Elastic::dbQuery("/{prefix}gazette_agenda/_doc/" . urlencode($params[0]));
                if (isset($obj->found) && $obj->found) {
                    self::json_output(LYLib::buildGazetteAgenda($obj->_source));
                } else {
                    header('HTTP/1.0 404 Not Found');
                    self::json_output(['error' => 'not found']);
                }
                return;
            }
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'comYear' => $records->comYear,
                ],
            ];
        }
        if (array_key_exists('date', $_GET)) {
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'meetingDate' => $_GET['date'],
                ],
            ];
        }
        if (array_key_exists('date_start', $_GET) and array_key_exists('date_end', $_GET)) {
            $cmd['query']['bool']['must'][] = [
                'range' => [
                    'meetingDate' => [
                        'gte' => $_GET['date_start'],
                        'lte' => $_GET['date_end'],
                    ],
                ],
            ];
        }

        $obj = Elastic::dbQuery("/{prefix}gazette_agenda/_search", 'GET', json_encode($cmd));
        $records->total = $obj->hits->total;
        $records->total_page = ceil($records->total->value / $records->limit);
        $records->agendas = [];
        foreach ($obj->hits->hits as $hit) {
            $records->agendas[] = LYLib::buildGazetteAgenda($hit->_source);
        }
        self::json_output($records);
    }

    /**
     * @OA\Get(
     *   path="/gazette_agenda/{agenda_id}/html", summary="取得公報目錄 HTML", tags={"gazette"},
     *   @OA\Parameter(name="agenda_id", in="path", description="公報目錄 ID", required=true, @OA\Schema(type="string"), example="LCIDC01_1077502_00003"),
     *   @OA\Response(response="200", description="公報目錄 HTML"),
     *   @OA\Response(response="404", description="找不到公報目錄資料", @OA\JsonContent(ref="#/components/schemas/Error")),
     * )
     */
    public static function gazette_agenda_html($params)
    {
        // agenda_id: LCIDC01_1077502_00003 
        $agenda_id = $params[0] . '.doc';
        $content = file_get_contents('https://lydata.ronny-s3.click/publication-html/' . urlencode($agenda_id));
        if (!$obj = json_decode($content)) {
            header('HTTP/1.1 404 Not Found');
            echo '404 not found';
            return;
        }
        $content = base64_decode($obj->content);
        $content = preg_replace_callback('#<img src="([^"]*)"#', function($matches) use ($agenda_id) {
            $id = explode('_html_', $matches[1])[1];
            return '<img src="https://lydata.ronny-s3.click/picfile/' . $agenda_id. '-' . $id . '"';
        }, $content);

        header('Content-Type: text/html; charset=utf-8');
        echo $content;
    }


    /**
     * @OA\Get(
     *   path="/bill", summary="取得依時間新至舊的議案", tags={"bill"},
     *   @OA\Parameter(name="proposer", in="query", description="提案人", required=false, @OA\Schema(type="string"), example="黃國昌"),
     *   @OA\Parameter(name="cosignatory", in="query", description="連署人", required=false, @OA\Schema(type="string"), example="黃國昌"),
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *   @OA\Response(response="200", description="議案資料", @OA\JsonContent(ref="#/components/schemas/Bill")),
     *  )
     *  @OA\Get(
     *    path="/bill/{billNo}", summary="取得特定議案資料", tags={"bill"},
     *    @OA\Parameter(name="billNo", in="path", description="議案編號", required=true, @OA\Schema(type="string"), example="1090001"),
     *    @OA\Response(response="200", description="議案資料", @OA\JsonContent(ref="#/components/schemas/Bill")),
     *    @OA\Response(response="404", description="找不到議案資料", @OA\JsonContent(ref="#/components/schemas/Error")),
     *  )
     *  @OA\Schema(
     *    schema="Bill", type="object", required={"billNo"},
     *    @OA\Property(property="billNo", type="string", description="議案編號"),
     *    @OA\Property(property="相關附件", type="array", description="相關附件", @OA\Items(type="object", @OA\Property(property="名稱", type="string", description="附件名稱"), @OA\Property(property="網址", type="string", description="附件網址"))),
     *    @OA\Property(property="議案流程", type="array", description="議案流程", @OA\Items(type="object", @OA\Property(property="日期", type="array", description="日期", @OA\Items(type="string")), @OA\Property(property="狀態", type="string", description="狀態"), @OA\Property(property="會期", type="string", description="會期"), @OA\Property(property="院會/委員會", type="string", description="院會/委員會"))),
     *    @OA\Property(property="關連議案", type="array", description="關連議案", @OA\Items(type="string")),
     *    @OA\Property(property="議案名稱", type="string", description="議案名稱"),
     *    @OA\Property(property="提案單位/提案委員", type="string", description="提案單位/提案委員"),
     *    @OA\Property(property="議案狀態", type="string", description="議案狀態"),
     *    @OA\Property(property="提案人", type="array", description="提案人", @OA\Items(type="string")),
     *    @OA\Property(property="連署人", type="array", description="連署人", @OA\Items(type="string")),
     *    @OA\Property(property="mtime", type="string", description="最後更新時間"),
     *    @OA\Property(property="屆期", type="integer", description="屆期"),
     *    @OA\Property(property="first_time", type="string", description="初次提案時間"),
     *    @OA\Property(property="last_time", type="string", description="最後提案時間"),
     *    )
     *  )
     *  @OA\Get(
     *    path="/bill/{term}", summary="取得特定屆期的議案", tags={"bill"},
     *    @OA\Parameter(name="proposer", in="query", description="提案人", required=false, @OA\Schema(type="string"), example="黃國昌"),
     *    @OA\Parameter(name="cosignatory", in="query", description="連署人", required=false, @OA\Schema(type="string"), example="黃國昌"),
     *    @OA\Parameter(name="term", in="path", description="屆期", required=true, @OA\Schema(type="integer"), example=9),
     *    @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *    @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *    @OA\Response(response="200", description="議案資料", @OA\JsonContent(ref="#/components/schemas/Bill")),
     *  )
     *
     */
    public static function bill($params)
    {
        // Bill output sample: {"billNo":"202103161290000","相關附件":[{"名稱":"關係文書PDF","網址":"https://ppg.ly.gov.tw/ppg/download/agenda1/02/pdf/10/07/13/LCEWA01_100713_00007.pdf"},{"名稱":"關係文書DOC","網址":"https://ppg.ly.gov.tw/ppg/download/agenda1/02/word/10/07/13/LCEWA01_100713_00007.doc"}],"議案流程":[{"日期":["2023-05-26","2023-05-29","2023-05-30","2023-05-31"],"狀態":"排入院會","會期":"10-07-13","院會/委員會":"院會"}],"關連議案":[],"議案名稱":"「建築法第九十一條條文修正草案」，請審議案。","提案單位/提案委員":"本院委員賴瑞隆等18人","議案狀態":"排入院會","提案人":["賴瑞隆"],"連署人":["林岱樺","莊競程","王美惠","趙天麟","林淑芬","蘇巧慧","林楚茵","余　天","邱泰源","吳玉琴","吳思瑤","范　雲","湯蕙禎","莊瑞雄","沈發惠","黃秀芳","洪申翰"],"mtime":"2023-05-29T17:13:27+08:00","屆期":10,"first_time":"2023-05-26","last_time":"2023-05-31"}
        $cmd = [
            'query' => [
                'bool' => [
                    'must' => [],
                ],
            ],
            'sort' => [
                'last_time' => 'desc',
            ],
            'size' => 100,
        ];

        $records = new StdClass;
        $records->total = 0;
        $records->page = @intval($_GET['page']) ?: 1;
        $records->limit = @intval($_GET['limit']) ?: 100;
        if (array_key_exists('proposer', $_GET)) {
            $records->proposer = $_GET['proposer'];
            $cmd['query']['bool']['must'][] = [
                'match' => [
                    '提案人.keyword' => $records->proposer,
                ],
            ];
        }
        if (array_key_exists('cosignatory', $_GET)) {
            $records->cosignatory = $_GET['cosignatory'];
            $cmd['query']['bool']['must'][] = [
                'match' => [
                    '連署人.keyword' => $records->cosignatory,
                ],
            ];
        }
        $cmd['size'] = $records->limit;
        $cmd['from'] = ($records->page - 1) * $records->limit;

        if (count($params) > 0 and strlen($params[0] > 10)) {
            $billNo = $params[0];
            $obj = Elastic::dbQuery("/{prefix}bill/_doc/" . urlencode($billNo));
            if (isset($obj->found) && $obj->found) {
                self::json_output($obj->_source);
            } else {
                header('HTTP/1.0 404 Not Found');
                self::json_output(['error' => 'not found']);
            }
            return;
        }

        if (count($params) > 0) {
            $term = intval($params[0]);
            $records->term = $term;
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    '屆期' => $term,
                ],
            ];
        }

        $obj = Elastic::dbQuery("/{prefix}bill/_search", 'GET', json_encode($cmd));
        $records->total = $obj->hits->total;
        $records->total_page = ceil($records->total->value / $records->limit);
        $records->bills = [];
        foreach ($obj->hits->hits as $hit) {
            $records->bills[] = $hit->_source;
        }
        self::json_output($records);
    }

    /**
     * @OA\Get(
     *   path="/meet/", summary="從舊到新列出會議", tags={"meet"},
     *   @OA\Parameter(name="term", in="query", description="屆期", required=false, @OA\Schema(type="integer"), example=9),
     *   @OA\Parameter(name="sessionPeriod", in="query", description="會期", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="meet_type", in="query", description="會議類型", required=false, @OA\Schema(type="string"), example="院會"),
     *   @OA\Parameter(name="legislator", in="query", description="出席立委", required=false, @OA\Schema(type="string"), example="黃國昌"),
     *   @OA\Parameter(name="date", in="query", description="會議日期", required=false, @OA\Schema(type="string"), example="2017-01-19"),
     *   @OA\Parameter(name="committee_id", in="query", description="委員會 ID", required=false, @OA\Schema(type="string"), example="15"),
     *   @OA\Parameter(name="date_start", in="query", description="會議日期起", required=false, @OA\Schema(type="string"), example="2017-01-01"),
     *   @OA\Parameter(name="date_end", in="query", description="會議日期迄", required=false, @OA\Schema(type="string"), example="2017-01-31"),
     *   @OA\Parameter(name="q", in="query", description="搜尋會議名稱或內容", required=false, @OA\Schema(type="string"), example="平等"),
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *   @OA\Response(response="200", description="會議資料", @OA\JsonContent(ref="#/components/schemas/Meet")),
     * )
     * @OA\Get(
     *   path="/meet/{term}", summary="從舊到新列出會議", tags={"meet"},
     *   @OA\Parameter(name="term", in="path", description="屆期", required=true, @OA\Schema(type="integer"), example=9),
     *   @OA\Parameter(name="sessionPeriod", in="query", description="會期", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="meet_type", in="query", description="會議類型", required=false, @OA\Schema(type="string"), example="院會"),
     *   @OA\Parameter(name="legislator", in="query", description="出席立委", required=false, @OA\Schema(type="string"), example="黃國昌"),
     *   @OA\Parameter(name="date", in="query", description="會議日期", required=false, @OA\Schema(type="string"), example="2017-01-19"),
     *   @OA\Parameter(name="committee_id", in="query", description="委員會 ID", required=false, @OA\Schema(type="string"), example="15"),
     *   @OA\Parameter(name="date_start", in="query", description="會議日期起", required=false, @OA\Schema(type="string"), example="2017-01-01"),
     *   @OA\Parameter(name="date_end", in="query", description="會議日期迄", required=false, @OA\Schema(type="string"), example="2017-01-31"),
     *   @OA\Parameter(name="q", in="query", description="搜尋會議名稱或內容", required=false, @OA\Schema(type="string"), example="平等"),
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *   @OA\Response(response="200", description="會議資料", @OA\JsonContent(ref="#/components/schemas/Meet")),
     * )
     * @OA\Get(
     *   path="/meet/{term}/{sessionPeriod}", summary="從舊到新列出會議", tags={"meet"},
     *   @OA\Parameter(name="term", in="path", description="屆期", required=true, @OA\Schema(type="integer"), example=9),
     *   @OA\Parameter(name="sessionPeriod", in="path", description="會期", required=true, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="meet_type", in="query", description="會議類型", required=false, @OA\Schema(type="string"), example="院會"),
     *   @OA\Parameter(name="legislator", in="query", description="出席立委", required=false, @OA\Schema(type="string"), example="黃國昌"),
     *   @OA\Parameter(name="date", in="query", description="會議日期", required=false, @OA\Schema(type="string"), example="2017-01-19"),
     *   @OA\Parameter(name="committee_id", in="query", description="委員會 ID", required=false, @OA\Schema(type="string"), example="15"),
     *   @OA\Parameter(name="date_start", in="query", description="會議日期起", required=false, @OA\Schema(type="string"), example="2017-01-01"),
     *   @OA\Parameter(name="date_end", in="query", description="會議日期迄", required=false, @OA\Schema(type="string"), example="2017-01-31"),
     *   @OA\Parameter(name="q", in="query", description="搜尋會議名稱或內容", required=false, @OA\Schema(type="string"), example="平等"),
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *   @OA\Response(response="200", description="會議資料", @OA\JsonContent(ref="#/components/schemas/Meet")),
     * )
     * @OA\Schema(
     *   schema="Meet", type="object", required={"meetingName", "meetingContent", "meetingType", "meetingDateDesc", "meetingRoom", "attendLegislator"},
     *   @OA\Property(property="meetingName", type="string", description="會議名稱"),
     *   @OA\Property(property="meetingContent", type="string", description="會議內容"),
     *   @OA\Property(property="meetingType", type="string", description="會議類型(全院委員會、委員會、聯席會議、黨團協商、其他會議)"),
     *   @OA\Property(property="meetingDateDesc", type="string", description="會議日期"),
     *   @OA\Property(property="meetingRoom", type="string", description="會議地點"),
     *   @OA\Property(property="attendLegislator", type="array", description="出席立委", @OA\Items(type="string")),
     *   @OA\Property(property="alias", type="array", description="別名", @OA\Items(type="string")),
     *   @OA\Property(property="date", type="string", description="會議日期"),
     *   @OA\Property(property="startTime", type="string", description="會議開始時間"),
     *   @OA\Property(property="endTime", type="string", description="會議結束時間"),
     *   @OA\Property(property="id", type="string", description="會議 ID"),
     *   @OA\Property(property="sessionPeriod", type="integer", description="會期"),
     *   @OA\Property(property="term", type="integer", description="屆期"),
     *   @OA\Property(property="meetingNo", type="string", description="會議編號"),
     *   @OA\Property(property="meetingTimes", type="string", description="會議次數"),
     *   @OA\Property(property="sessionTimes", type="string", description="會期次數"),
     *   @OA\Property(property="jointCommittee", type="string", description="聯席會議"),
     *   @OA\Property(property="coChairman", type="string", description="共同主席"),
     *   @OA\Property(property="meetingUnit", type="string", description="會議單位"),
     * )
     */
    public static function meet($params)
    {
        // meet sample: {"sessionTimes":"null","jointCommittee":null,"sessionPeriod":7,"meetingContent":"研商「性別工作平等法」、「性騷擾防治法」及「性別平等教育法」案相關事宜(民進黨黨團提議)","meetingNo":"2023072573","selectTerm":"1007","meetingName":"立法院黨團協商","term":10,"meetingDateDesc":"112/07/26 15:30","coChairman":"游院長錫堃","meetingUnit":"朝野黨團協商","meetingTimes":"null","meetingRoom":"議場三樓會議室","attendLegislator":[],"alias":[],"date":"2023-07-26","startTime":"2023-07-26T15:30:00","endTime":null,"meetingType":"黨團協商","id":"2023-07-26:2023072573"}
        $cmd = [
            'query' => [
                'bool' => [
                    'must' => [],
                ],
            ],
            'sort' => ['startTime' => 'desc'],
            'size' => 100,
        ];

        $records = new StdClass;
        $records->total = 0;
        $records->page = @intval($_GET['page']) ?: 1;
        $records->limit = @intval($_GET['limit']) ?: 100;
        $cmd['size'] = $records->limit;
        $cmd['from'] = ($records->page - 1) * $records->limit;
        if (count($params) > 0) {
            $term = $params[0];
            $records->term = $term;
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'term' => $term,
                ],
            ];
        }
        if (count($params) > 1) {
            $sessionPeriod = $params[1];
            $records->sessionPeriod = $sessionPeriod;
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'sessionPeriod' => $sessionPeriod,
                ],
            ];
        }

        if (array_key_exists('term', $_GET)) {
            $records->term = $_GET['term'];
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'term' => $records->term,
                ],
            ];
        }
        if (array_key_exists('sessionPeriod', $_GET)) {
            $records->sessionPeriod = $_GET['sessionPeriod'];
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'sessionPeriod' => $records->sessionPeriod,
                ],
            ];
        }
        if (array_key_exists('meet_type', $_GET)) {
            $records->meet_type = $_GET['meet_type'];
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'meetingType.keyword' => $records->meet_type,
                ],
            ];
        }
        if (array_key_exists('legislator', $_GET)) {
            $records->legislator = $_GET['legislator'];
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'attendLegislator.keyword' => $records->legislator,
                ],
            ];
        }

        if (array_key_exists('date', $_GET)) {
            $records->date = $_GET['date'];
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'startTime' => $records->date,
                ],
            ];
        }

        if (array_key_exists('committee_id', $_GET)) {
            $records->committee_id = intval($_GET['committee_id']);
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'committees' => $records->committee_id,
                ],
            ];
        }

        if (array_key_exists('date_start', $_GET) and array_key_exists('date_end', $_GET)) {
            $records->date_start = $_GET['date_start'];
            $records->date_end = $_GET['date_end'];
            $cmd['query']['bool']['must'][] = [
                'range' => [
                    'startTime' => [
                        'gte' => $records->date_start,
                        'lte' => $records->date_end,
                    ],
                ],
            ];
        }

        if (array_key_exists('q', $_GET)) {
            $records->q = '"' . $_GET['q'] . '"';
            $cmd['query']['bool']['must'][] = [
                'query_string' => [
                    'query' => $records->q,
                    'fields' => ['meetingName', 'meetingContent'],
                ],
            ];
        }

        $obj = Elastic::dbQuery("/{prefix}meet/_search", 'GET', json_encode($cmd));
        $records->total = $obj->hits->total;
        $records->total_page = ceil($records->total->value / $records->limit);
        $records->meets = [];
        foreach ($obj->hits->hits as $hit) {
            $hit->_source->id = $hit->_id;
            $records->meets[] = LYLib::buildMeet($hit->_source);
        }
        self::json_output($records);
    }

    /**
     * @OA\Get(
     *   path="/interpellation", summary="搜尋質詢資料", tags={"interpellation"},
     *   @OA\Parameter(name="term", in="query", description="屆期", required=false, @OA\Schema(type="integer"), example=9),
     *   @OA\Parameter(name="sessionPeriod", in="query", description="會期", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="sessionTimes", in="query", description="會期次數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="legislator", in="query", description="提案委員", required=false, @OA\Schema(type="string"), example="黃國昌"),
     *   @OA\Parameter(name="q", in="query", description="搜尋質詢理由或內容", required=false, @OA\Schema(type="string"), example="平等"),
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *   @OA\Response(response="200", description="質詢資料", @OA\JsonContent(ref="#/components/schemas/Interpellation")),
     * )
     * @OA\Get(
     *   path="/interpellation/{term}", summary="搜尋 {term} 屆質詢資料", tags={"interpellation"},
     *   @OA\Parameter(name="term", in="path", description="屆期", required=true, @OA\Schema(type="integer"), example=9),
     *   @OA\Parameter(name="sessionPeriod", in="query", description="會期", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="sessionTimes", in="query", description="會期次數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="legislator", in="query", description="提案委員", required=false, @OA\Schema(type="string"), example="黃國昌"),
     *   @OA\Parameter(name="q", in="query", description="搜尋質詢理由或內容", required=false, @OA\Schema(type="string"), example="平等"),
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *   @OA\Response(response="200", description="質詢資料", @OA\JsonContent(ref="#/components/schemas/Interpellation")),
     * )
     * @OA\Get(
     *   path="/interpellation/{term}/{sessionPeriod}", summary="搜尋第 {term} 屆第 {sessionPeriod} 會期的質詢資料", tags={"interpellation"},
     *   @OA\Parameter(name="term", in="path", description="屆期", required=true, @OA\Schema(type="integer"), example=9),
     *   @OA\Parameter(name="sessionPeriod", in="path", description="會期", required=true, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="sessionTimes", in="query", description="會期次數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="legislator", in="query", description="提案委員", required=false, @OA\Schema(type="string"), example="黃國昌"),
     *   @OA\Parameter(name="q", in="query", description="搜尋質詢理由或內容", required=false, @OA\Schema(type="string"), example="平等"),
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *   @OA\Response(response="200", description="質詢資料", @OA\JsonContent(ref="#/components/schemas/Interpellation")),
     * )
     * @OA\Get(
     *   path="/interpellation/{term}/{sessionPeriod}/{sessionTimes}", summary="搜尋第{term}屆第{sessionPeriod}會期第{sessionTimes}次會議的質詢資料", tags={"interpellation"},
     *   @OA\Parameter(name="term", in="path", description="屆期", required=true, @OA\Schema(type="integer"), example=9),
     *   @OA\Parameter(name="sessionPeriod", in="path", description="會期", required=true, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="sessionTimes", in="path", description="會期次數", required=true, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="legislator", in="query", description="提案委員", required=false, @OA\Schema(type="string"), example="黃國昌"),
     *   @OA\Parameter(name="q", in="query", description="搜尋質詢理由或內容", required=false, @OA\Schema(type="string"), example="平等"),
     *   @OA\Response(response="200", description="質詢資料", @OA\JsonContent(ref="#/components/schemas/Interpellation")),
     * )
     * @OA\Get(
     *   path="/interpellation/{term}/{sessionPeriod}/{sessionTimes}/{interpellation_id}", summary="取得特定次質詢資料", tags={"interpellation"},
     *   @OA\Parameter(name="term", in="path", description="屆期", required=true, @OA\Schema(type="integer"), example=9),
     *   @OA\Parameter(name="sessionPeriod", in="path", description="會期", required=true, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="sessionTimes", in="path", description="會期次數", required=true, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="interpellation_id", in="path", description="質詢 ID", required=true, @OA\Schema(type="integer"), example="1"),
     *   @OA\Response(response="200", description="質詢資料", @OA\JsonContent(ref="#/components/schemas/Interpellation")),
     *   @OA\Response(response="404", description="找不到質詢資料", @OA\JsonContent(ref="#/components/schemas/Interpellation")),
     * )
     * @OA\Schema(
     *   schema="Interpellation", type="object", required={"id", "page_start", "page_end", "printed_at", "reason", "legislators", "description", "meetingNo", "term", "sessionPeriod", "sessionTimes"},
     *   @OA\Property(property="id", type="string", description="質詢 ID"),
     *   @OA\Property(property="page_start", type="integer", description="起始頁數"),
     *   @OA\Property(property="page_end", type="integer", description="結束頁數"),
     *   @OA\Property(property="printed_at", type="string", description="列印日期"),
     *   @OA\Property(property="reason", type="string", description="質詢理由"),
     *   @OA\Property(property="legislators", type="array", description="提案委員", @OA\Items(type="string")),
     *   @OA\Property(property="description", type="string", description="質詢內容"),
     *   @OA\Property(property="meetingNo", type="string", description="會議編號"),
     *   @OA\Property(property="term", type="integer", description="屆期"),
     *   @OA\Property(property="sessionPeriod", type="integer", description="會期"),
     *   @OA\Property(property="sessionTimes", type="integer", description="會期次數"),
     * )
     *
     *
     */
    public static function interpellation($params)
    {
        // output: 
        // {"id":"10-7-11-40","page_start":1,"page_end":4,"printed_at":"2023-05-10","reason":"本院林委員淑芬，針對...","legislators":["林淑芬"],"description":"根據農糧署...","meetingNo":"2023051084","term":10,"sessionPeriod":7,"sessionTimes":11}
        $cmd = [
            'query' => [
                'bool' => [
                    'must' => [],
                ],
            ],
            'sort' => ['printed_at' => 'desc'],
            'size' => 100,
        ];

        $records = new StdClass;
        $records->total = 0;
        $records->page = @intval($_GET['page']) ?: 1;
        $records->limit = @intval($_GET['limit']) ?: 100;
        $cmd['size'] = $records->limit;
        $cmd['from'] = ($records->page - 1) * $records->limit;
        if (count($params) > 0) {
            $term = $params[0];
            $records->term = $term;
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'term' => $term,
                ],
            ];
        }
        if (count($params) > 1) {
            $sessionPeriod = $params[1];
            $records->sessionPeriod = $sessionPeriod;
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'sessionPeriod' => $sessionPeriod,
                ],
            ];
        }
        if (count($params) > 2) {
            $sessionTimes = intval($params[2]);
            $records->sessionTimes = $sessionTimes;
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'sessionTimes' => $sessionTimes,
                ],
            ];
        }
        if (count($params) > 3) {
            $interpellation_id = implode('-', [$term, $sessionPeriod, $sessionTimes, $params[3]]);
            $obj = Elastic::dbQuery("/{prefix}interpellation/_doc/{$interpellation_id}");
            if (isset($obj->found) && $obj->found) {
                self::json_output(LYLib::buildInterpellation($obj->_source));
            } else {
                header('HTTP/1.0 404 Not Found');
                self::json_output(['error' => 'not found']);
            }
            return;
        }

        if (array_key_exists('term', $_GET)) {
            $records->term = $_GET['term'];
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'term' => $records->term,
                ],
            ];
        }
        if (array_key_exists('sessionPeriod', $_GET)) {
            $records->sessionPeriod = $_GET['sessionPeriod'];
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'sessionPeriod' => $records->sessionPeriod,
                ],
            ];
        }

        if (array_key_exists('legislator', $_GET)) {
            $records->legislator = $_GET['legislator'];
            $cmd['query']['bool']['must'][] = [
                'term' => [
                    'legislators.keyword' => $records->legislator,
                ],
            ];
        }

        if (array_key_exists('q', $_GET)) {
            $records->q = '"' . $_GET['q'] . '"';
            $cmd['query']['bool']['must'][] = [
                'query_string' => [
                    'query' => $records->q,
                    'fields' => ['reason', 'description'],
                ],
            ];
        }

        $obj = Elastic::dbQuery("/{prefix}interpellation/_search", 'GET', json_encode($cmd));
        $records->total = $obj->hits->total;
        $records->total_page = ceil($records->total->value / $records->limit);
        $records->interpellations = [];
        foreach ($obj->hits->hits as $hit) {
            $hit->_source->id = $hit->_id;
            $records->interpellations[] = LYLib::buildInterpellation($hit->_source);
        }
        self::json_output($records);
    }

    public static function json_output($obj)
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        if (@strpos($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']) !== false) {
            echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        } else {
            echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    public static function dispatch()
    {
        $uri = $_SERVER['REQUEST_URI'];

        if ($uri == '/swagger.yaml') {
            header('Content-Type: text/plain');
            header('Access-Control-Allow-Origin: *');
            readfile(__DIR__ . '/swagger.yaml');
            return;
        }

        if ($uri == '/') {
            readfile(__DIR__ . '/swagger.html');
            return;
        }

        $uri = explode('?', $uri)[0];
        $terms = explode('/', trim($uri, '/'));
        $method = array_shift($terms);
        $terms = array_map('urldecode', $terms);

        if ('legislator' == $method) {
            self::legislator($terms);
        } else if ('committee' == $method) {
            self::committee($terms);
        } else if ('gazette' == $method) {
            self::gazette($terms);
        } else if ('gazette_agenda' == $method) {
            self::gazette_agenda($terms);
        } else if ('bill' == $method) {
            self::bill($terms);
        } else if ('meet' == $method) {
            self::meet($terms);
        } else if ('interpellation' == $method) {
            self::interpellation($terms);
        } else {
            header('HTTP/1.0 404 Not Found');
            echo '404 Not Found';
        }
    }
}
