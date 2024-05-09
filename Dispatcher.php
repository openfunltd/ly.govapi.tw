<?php

/**
 * @OA\Info(
 *   title="立法院 API", version="1.0.0"
 * )
 * @OA\Tag(name="stat", description="統計")
 * @OA\Tag(name="legislator", description="立法委員")
 * @OA\Tag(name="committee", description="委員會")
 * @OA\Tag(name="meet", description="會議")
 * @OA\Tag(name="bill", description="議案")
 * @OA\Tag(name="law", description="法規")
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
     *   path="/legislator/{bioId}", summary="取得特定立委歷次擔任紀錄", tags={"legislator"},
     *   @OA\Parameter(name="bioId", in="path", description="立委 ID", required=true, @OA\Schema(type="integer"), example="0952"),
     *   @OA\Response(response="200", description="歷屆立法委員資料", @OA\JsonContent(ref="#/components/schemas/Legislator")),
     *   @OA\Response(response="404", description="找不到立法委員資料", @OA\JsonContent(ref="#/components/schemas/Error")),
     * )
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
                self::setParam('proposer', $params[1]);

                return self::bill([$params[0]]);
            } elseif ($params[2] == 'cosign_bill') {
                self::setParam('cosignatory', $params[1]);

                return self::bill([$params[0]]);
            } elseif ($params[2] == 'meet') {
                self::setParam('legislator', $params[1]);
                self::setParam('term', $params[0]);

                return self::meet([$params[0]]);
            } elseif ($params[2] == 'interpellation') {
                self::setParam('legislator', $params[1]);
                self::setParam('term', $params[0]);

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
            if (preg_match('#^\d\d\d\d$#', $params[0])) {
                $records->bioId = $params[0];
                $records->name = '';
                $records->ename = '';
                $records->sex = '';
                $cmd['query']['bool']['must'][] = [
                    'term' => [
                        'bioId' => intval($records->bioId),
                    ],
                ];
            } else {
                $term = intval($params[0]);
                $records->term = $term;
                $cmd['query']['bool']['must'][] = [
                    'term' => [
                        'term' => $term,
                    ],
                ];
            }
        }

        if (self::hasParam('term')) {
            $term = self::getParam('term', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
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
        if (property_exists($records, 'bioId')) {
            unset($records->total);
            unset($records->total_page);
            unset($records->page);
            unset($records->limit);
            if (!count($records->legislators)) {
                header('HTTP/1.0 404 Not Found');
                self::json_output(['error' => 'not found']);
                return;
            }
            foreach (['name', 'ename', 'sex'] as $k) {
                $records->{$k} = $records->legislators[count($records->legislators) - 1]->{$k};
            }
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
            self::setParam('committee_id', $params[0]);

            if (count($params) > 2) {
                self::setParam('term', $params[2]);
            }
            if (count($params) > 3) {
                self::setParam('sessionPeriod', $params[3]);
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
     *   @OA\Parameter(name="gazette_id", in="path", description="公報 ID", required=true, @OA\Schema(type="string"), example="1126203"),
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

        if (self::hasParam('gazette_id')) {
            $gazette_id = self::getParam('gazette_id', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    '_id' => $gazette_id,
                ],
            ];
            $records->gazette_id = $gazette_id;
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
     *   @OA\Parameter(name="gazette_id", in="path", description="公報 ID", required=true, @OA\Schema(type="string"), example="1126203"),
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *  @OA\Parameter(name="date", in="query", description="會議日期", required=false, @OA\Schema(type="string"), example="2017-01-19"),
     *  @OA\Parameter(name="date_start", in="query", description="會議日期起", required=false, @OA\Schema(type="string"), example="2017-01-01"),
     *  @OA\Parameter(name="date_end", in="query", description="會議日期迄", required=false, @OA\Schema(type="string"), example="2017-01-31"),
     *   @OA\Response(response="200", description="公報目錄資料", @OA\JsonContent(ref="#/components/schemas/GazetteAgenda")),
     *  )
     *  @OA\Get(
     *   path="/gazette_agenda/{agenda_id}", summary="取得特定公報目錄資料", tags={"gazette"},
     *   @OA\Parameter(name="agenda_id", in="path", description="公報目錄 ID", required=true, @OA\Schema(type="string"), example="1126203_0001"),
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
            if (preg_match('/^(\d+)$/', $params[0], $matches) and strlen($params[0]) > 3) {
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
            } elseif (preg_match('/^\d+_\d+$/', $params[0], $matches)) {
                $obj = Elastic::dbQuery("/{prefix}gazette_agenda/_doc/" . urlencode($params[0]));
                if (isset($obj->found) && $obj->found) {
                    self::json_output(LYLib::buildGazetteAgenda($obj->_source));
                } else {
                    header('HTTP/1.0 404 Not Found');
                    self::json_output(['error' => 'not found']);
                }
                return;
            } else {
                $cmd['query']['bool']['must'][] = [
                    'term' => [
                        'comYear' => $records->comYear,
                    ],
                ];
            }
        }
        if (self::hasParam('date')) {
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'meetingDate' => self::getParam('date', ['array' => true]),
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
        if (array_key_exists('q', $_GET)) {
            $records->q = '"' . $_GET['q'] . '"';
            $cmd['query']['bool']['must'][] = [
                'query_string' => [
                    'query' => $records->q,
                    'fields' => ['subject'],
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
     *   @OA\Parameter(name="agenda_id", in="path", description="公報目錄 ID", required=true, @OA\Schema(type="string"), example="1077502_00003"),
     *   @OA\Response(response="200", description="公報目錄 HTML"),
     *   @OA\Response(response="404", description="找不到公報目錄資料", @OA\JsonContent(ref="#/components/schemas/Error")),
     * )
     */
    public static function gazette_agenda_html($params)
    {
        // agenda_id: LCIDC01_1077502_00003 
        $agenda_id = 'LCIDC01_' . $params[0] . '.doc.html';
        if ($_GET['tika'] ?? false) {
            $url = 'https://lydata.ronny-s3.click/agenda-tikahtml/' . urlencode($agenda_id);
        } else {
            $url = 'https://lydata.ronny-s3.click/agenda-html/' . urlencode($agenda_id);
        }
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $content = curl_exec($curl);
        $info = curl_getinfo($curl);
        if ($info['http_code'] != 200) {
            header('HTTP/1.1 404 Not Found');
            echo '404 not found';
            return;
        }

        if (!($_GET['tika'] ?? false)) {
        $content = preg_replace_callback('#<img ([^>]*)src="([^"]*)"#', function($matches) use ($agenda_id) {
            $attr = $matches[1];
            $src = $matches[2];
            if (!preg_match('#pic://(.*)\.([^.]*)$#', $src, $matches)) {
                return $matches[0];
            }
            $src = sprintf("https://lydata.ronny-s3.click/agenda-pic/%s.%s", $matches[1], $matches[2]);
            return "<img $attr src=\"$src\"";
        }, $content);
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $content;
    }


    /**
     * @OA\Get(
     *   path="/bill", summary="取得依時間新至舊的議案", tags={"bill"},
     *   @OA\Parameter(name="proposer", in="query", description="提案人(Ex: 黃國昌)", required=false, @OA\Schema(type="string")),
     *   @OA\Parameter(name="cosignatory", in="query", description="連署人(Ex: 黃國昌)", required=false, @OA\Schema(type="string")),
     *   @OA\Parameter(name="law", in="query", description="法案代碼(Ex: 01010)", required=false, @OA\Schema(type="string")),
     *   @OA\Parameter(name="meet_id", in="query", description="會議 ID(Ex: 院會-10-1-1)", required=false, @OA\Schema(type="string")),
     *   @OA\Parameter(name="term", in="query", description="屆期(Ex: 9)", required=false, @OA\Schema(type="integer")),
     *   @OA\Parameter(name="sessionPeriod", in="query", description="會期(Ex: 1)", required=false, @OA\Schema(type="integer")),
     *   @OA\Parameter(name="bill_type", in="query", description="議案類別(Ex: 法律案, 臨時提案)", required=false, @OA\Schema(type="array", items={"type":"string"}, @OA\Items(type="string"))),
     *   @OA\Parameter(name="proposal_type", in="query", description="提案類別(Ex: 委員提案, 政府提案, 審查報告)", required=false, @OA\Schema(type="integer")),
     *   @OA\Parameter(name="q", in="query", description="關鍵字搜尋", required=false, @OA\Schema(type="string")),
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer", default=1)),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer", default=100)),
     *   @OA\Response(response="200", description="議案資料", @OA\JsonContent(ref="#/components/schemas/Bill")),
     *  )
     *  @OA\Get(
     *    path="/bill/{billNo}", summary="取得特定議案資料", tags={"bill"},
     *    @OA\Parameter(name="billNo", in="path", description="議案編號", required=true, @OA\Schema(type="string"), example="1111102070100100"),
     *    @OA\Response(response="200", description="議案資料", @OA\JsonContent(ref="#/components/schemas/Bill")),
     *    @OA\Response(response="404", description="找不到議案資料", @OA\JsonContent(ref="#/components/schemas/Error")),
     *  )
     *  @OA\Get(
     *    path="/bill/{billNo}/html", summary="取得特定議案 HTML", tags={"bill"},
     *    @OA\Parameter(name="billNo", in="path", description="議案編號", required=true, @OA\Schema(type="string"), example="1111102070100100"),
     *    @OA\Response(response="200", description="議案 HTML"),
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

        $all_fields = [
            'billNo' => [],
            '相關附件' => [],
            '議案名稱' => [],
            '提案單位/提案委員' => [],
            '議案狀態' => ['aggs' => true, 'type' => 'keyword'],
            'mtime' => ['aggs' => true, 'type' => 'datetime'],
            '屆期' => ['aggs' => true, 'type' => 'integer'],
            '會期' => ['aggs' => true, 'type' => 'integer'],
            '議案類別' => ['aggs' => true, 'type' => 'keyword'],
            '提案來源' => ['aggs' => true, 'type' => 'keyword'],
            'meet_id' => [],
            '字號' => [],
            '提案編號' => [],
            '議案流程' => [],
            '關連議案' => [],
            '提案人' => ['aggs' => true, 'type' => 'keyword'],
            '連署人' => ['aggs' => true, 'type' => 'keyword'],
            'first_time' => ['aggs' => true, 'type' => 'datetime'],
            'last_time' => ['aggs' => true, 'type' => 'datetime'],
            'laws' => ['aggs' => true, 'type' => 'keyword'],
            '案由' => [],
            '說明' => [],
            '對照表' => [],
        ];

        $displayFields = [
            'billNo',
            '相關附件',
            '議案名稱',
            '提案單位/提案委員',
            '議案狀態',
            'mtime',
            '屆期',
            '議案類別',
            '提案來源',
            'meet_id',
            '會期',
            '字號',
            '提案編號',
        ];


        if (count($params) == 2 and $params[1] == 'html') {
            return self::bill_html($params[0]);
        } else if (count($params) == 2 and $params[1] == 'related_bills') {
            return self::bill_related_bills($params[0]);
        }

        $records = new StdClass;
        $records->total = 0;
        $records->page = @intval($_GET['page']) ?: 1;
        $records->limit = @intval($_GET['limit']) ?: 100;

        if (self::hasParam('aggs')) {
            foreach (self::getParam('aggs', ['array' => true]) as $agg) {
                if (!array_key_exists($agg, $all_fields)) {
                    header('HTTP/1.0 404 Not Found');
                    self::json_output(['error' => 'not found']);
                }
                if (!array_key_exists('aggs', $all_fields[$agg])) {
                    header('HTTP/1.0 404 Not Found');
                    self::json_output(['error' => 'not found']);
                }
                if (in_array($all_fields[$agg]['type'], ['integer'])) {
                    $cmd['aggs'][$agg] = [
                        'terms' => [
                            'field' => $agg,
                        ],
                    ];
                } else {
                    $cmd['aggs'][$agg] = [
                        'terms' => [
                            'field' => $agg . '.keyword',
                        ],
                    ];
                }
            }
        }

        if (self::hasParam('proposer')) {
            array_push($displayFields, '提案人');
            $records->proposer = self::getParam('proposer', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    '提案人.keyword' => $records->proposer,
                ],
            ];
        }
        if (self::hasParam('law')) {
            array_push($displayFields, 'laws');
            $records->law = self::getParam('law', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'laws.keyword' => $records->law,
                ],
            ];
        }
        if (self::hasParam('cosignatory')) {
            array_push($displayFields, '連署人');
            $records->cosignatory = self::getParam('cosignatory', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    '連署人.keyword' => $records->cosignatory,
                ],
            ];
        }
        if (self::hasParam('議案狀態')) {
            array_push($displayFields, '議案狀態');
            $records->{'議案狀態'} = self::getParam('議案狀態', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    '議案狀態.keyword' => $records->議案狀態,
                ],
            ];
        }

        if (self::hasParam('meet_id')) {
            $records->meet_id = self::getParam('meet_id', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'meet_id.keyword' => $records->meet_id,
                ],
            ];
        }

        if (self::hasParam('bill_type')) {
            $records->bill_type = self::getParam('bill_type', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    '議案類別.keyword' => $records->bill_type,
                ],
            ];
        }

        if (self::hasParam('proposal_type')) {
            $records->proposal_type = self::getParam('proposal_type', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    '提案來源.keyword' => $records->proposal_type,
                ],
            ];
        }

        if (self::hasParam('term')) {
            $records->term = self::getParam('term', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    '屆期' => $records->term,
                ],
            ];
        }

        if (self::hasParam('sessionPeriod')) {
            $records->sessionPeriod = self::getParam('sessionPeriod', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    '會期' => $records->sessionPeriod,
                ],
            ];
        }

        if (self::hasParam('billWord')) {
            $records->billWord = self::getParam('billWord', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    '提案編號.keyword' => $records->billWord,
                ],
            ];
        }

        if (array_key_exists('q', $_GET)) {
            $records->q = $_GET['q'];
            $cmd['query']['bool']['must'][] = [
                'query_string' => [
                    'query' => $records->q,
                    'fields' => ['議案名稱', '提案單位/提案委員', '提案人', '連署人', '案由', '說明'],
                ],
            ];
        }

        if (self::hasParam('field')) {
            $displayFields = array_merge($displayFields, self::getParam('field', ['array' => true]));
        }

        if (!in_array('all', $displayFields)) {
            $records->field = $displayFields;
            $cmd['_source'] = $displayFields;
        }
        $cmd['size'] = $records->limit;
        $cmd['from'] = ($records->page - 1) * $records->limit;

        if (count($params) > 0 and (strpos($params[0], '委') or strpos($params[0], '政'))) {
            $billWord = $params[0];
            $obj = Elastic::dbQuery("/{prefix}bill/_search", 'GET', json_encode([
                'query' => [
                    'term' => [
                        '提案編號.keyword' => $billWord,
                    ],
                ],
            ]));
            if (count($obj->hits->hits) == 1) {
                return self::json_output($obj->hits->hits[0]->_source);
            } elseif (count($obj->hits->hits) > 1) {
                $records->total = $obj->hits->total;
                $records->total_page = ceil($records->total->value / $records->limit);
                $records->bills = [];
                foreach ($obj->hits->hits as $hit) {
                    $records->bills[] = LYLib::buildBill($hit->_source);
                }
                return self::json_output($records);
            } else {
                header('HTTP/1.0 404 Not Found');
                self::json_output(['error' => 'not found']);
            }
            self::json_output($obj->hits->hits);
            exit;
        } else if (count($params) > 0 and strlen($params[0] > 10)) {
            $billNo = $params[0];
            $obj = Elastic::dbQuery("/{prefix}bill/_doc/" . urlencode($billNo));
            if (isset($obj->found) && $obj->found) {
                self::json_output(LYLib::buildBill($obj->_source));
            } else {
                header('HTTP/1.0 404 Not Found');
                self::json_output(['error' => 'not found']);
            }
            return;

        }

        $obj = Elastic::dbQuery("/{prefix}bill/_search", 'GET', json_encode($cmd));
        $records->total = $obj->hits->total;
        $records->total_page = ceil($records->total->value / $records->limit);
        $records->bills = [];
        foreach ($obj->hits->hits as $hit) {
            $records->bills[] = $hit->_source;
        }
        if (self::hasParam('aggs')) {
            foreach (self::getParam('aggs', ['array' => true]) as $agg) {
                $records->aggs[$agg] = [];
                foreach ($obj->aggregations->$agg->buckets as $bucket) {
                    $records->aggs[$agg][] = [
                        'value' => $bucket->key,
                        'count' => $bucket->doc_count,
                    ];
                }
            }
        }
        self::json_output($records);
    }

    public static function bill_related_bills($billNo)
    {

        $obj = Elastic::dbQuery("/{prefix}bill/_doc/" . urlencode($billNo));
        $source = $obj->_source;
        if ($source->{'議案狀態'} == '三讀') {
            // 如果是三讀的議案，查找相同法條並且同一天三讀通過的法條
            $ret = Elastic::dbQuery("/{prefix}bill/_search", 'GET', json_encode([
                'query' => [
                    'bool' => [
                        'must' => [
                            [
                                'term' => [
                                    'laws' => $source->laws[0],
                                ],
                            ],
                            [
                                'term' => [
                                    'last_time' => $source->last_time,
                                ],
                            ],
                        ],
                    ],
                ],
            ]));
            $pools = [];
            foreach ($ret->hits->hits as $hit) {
                $pools[$hit->_id] = $hit->_source;
                foreach ($hit->_source->{'關連議案'} as $relbill) {
                    if (!array_key_exists($relbill->billNo, $pools)) {
                        $pools[$relbill->billNo] = true;
                    }
                }
                unset($pools[$hit->_id]->{'關連議案'});
            }

        } else if ($source->{'議案狀態'} == '審查完畢') {
            // 先找找看關聯議案中有沒有三讀通過的
            $pools = [];
            $pools[$billNo] = $source;
            $fetching_bills = [];
            foreach ($source->{'關連議案'} as $relbill) {
                $pools[$relbill->billNo] = true;
                $fetching_bills[] = $relbill->billNo;
            }
            unset($pools[$billNo]->{'關連議案'});
            $ret = Elastic::dbQuery("/{prefix}bill/_search", 'GET', json_encode([
                'query' => [
                    'terms' => [
                        'billNo.keyword' => $fetching_bills,
                    ],
                ],
            ]));
            foreach ($ret->hits->hits as $hit) {
                $pools[$hit->_id] = $hit->_source;
                $source = $hit->_source;
                foreach ($hit->_source->{'關連議案'} as $relbill) {
                    if (!array_key_exists($relbill->billNo, $pools)) {
                        $pools[$relbill->billNo] = true;
                    }
                }
                unset($pools[$hit->_id]->{'關連議案'});
                if ($hit->_source->{'議案狀態'} == '三讀') {
                    $ret = Elastic::dbQuery("/{prefix}bill/_search", 'GET', json_encode([
                        'query' => [
                            'bool' => [
                                'must' => [
                                    [
                                        'term' => [
                                            'laws' => $source->laws[0],
                                        ],
                                    ],
                                    [
                                        'term' => [
                                            'last_time' => $source->last_time,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ]));
                    foreach ($ret->hits->hits as $hit) {
                        $pools[$hit->_id] = $hit->_source;
                        foreach ($hit->_source->{'關連議案'} as $relbill) {
                            if (!array_key_exists($relbill->billNo, $pools)) {
                                $pools[$relbill->billNo] = true;
                            }
                        }
                        unset($pools[$hit->_id]->{'關連議案'});
                    }
                    break;
                }
            }
        } else {
            // 找同一條法律並且提案時間在兩個月內的
            if (!count($source->laws)) {
                return self::json_output([
                    'error' => true,
                    'message' => '找不到法律代碼，無法查詢',
                ]);
            }
            $ret = Elastic::dbQuery("/{prefix}bill/_search", 'GET', json_encode([
                'query' => [
                    'bool' => [
                        'must' => [
                            [
                                'terms' => [
                                    'laws' => $source->laws,
                                ],
                            ],
                            [
                                'range' => [
                                    'first_time' => [
                                        'gte' => date('Y-m-d', strtotime($source->first_time. ' -2 month')),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]));
            $pools = [];
            foreach ($ret->hits->hits as $hit) {
                if ($hit->_id == $billNo) {
                    continue;
                }
                $pools[$hit->_id] = $hit->_source;
                $source = $hit->_source;
                foreach ($hit->_source->{'關連議案'} as $relbill) {
                    if (!array_key_exists($relbill->billNo, $pools)) {
                        $pools[$relbill->billNo] = true;
                    }
                }
                unset($pools[$hit->_id]->{'關連議案'});
                if ($hit->_source->{'議案狀態'} == '三讀') {
                    $ret = Elastic::dbQuery("/{prefix}bill/_search", 'GET', json_encode([
                        'query' => [
                            'bool' => [
                                'must' => [
                                    [
                                        'term' => [
                                            'laws' => $source->laws[0],
                                        ],
                                    ],
                                    [
                                        'term' => [
                                            'last_time' => $source->last_time,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ]));
                    foreach ($ret->hits->hits as $hit) {
                        $pools[$hit->_id] = $hit->_source;
                        foreach ($hit->_source->{'關連議案'} as $relbill) {
                            if (!array_key_exists($relbill->billNo, $pools)) {
                                $pools[$relbill->billNo] = true;
                            }
                        }
                        unset($pools[$hit->_id]->{'關連議案'});
                    }
                    break;
                }
            }
        }

        // 把關連議案都補齊
        while (true) {
            $fetching_bills = [];
            foreach ($pools as $billNo => $bill) {
                if ($bill === true) {
                    $fetching_bills[] = $billNo;
                    $pools[$billNo] = $bill;
                }
            }
            if (!count($fetching_bills)) {
                break;
            }

            $ret = Elastic::dbQuery("/{prefix}bill/_search", 'GET', json_encode([
                'query' => [
                    'terms' => [
                        'billNo.keyword' => $fetching_bills,
                    ],
                ],
            ]));
            foreach ($ret->hits->hits as $hit) {
                $pools[$hit->_id] = $hit->_source;
                foreach ($hit->_source->{'關連議案'} as $relbill) {
                    if (!array_key_exists($relbill->billNo, $pools)) {
                        $pools[$relbill->billNo] = true;
                    }
                }
                unset($pools[$hit->_id]->{'關連議案'});
            }
        }

        return self::json_output([
            'error' => false,
            'bills' => array_values($pools),
        ]);
    }

    public static function bill_html($billNo)
    {
        // agenda_id: LCIDC01_1077502_00003 
        $agenda_id = $params[0] . '.doc';
        header('Content-Type: text/html');
        $content = file_get_contents(sprintf("https://lydata.ronny-s3.click/bill-doc-parsed/html/%s.doc.gz", $billNo));
        $content = gzdecode($content);
        if (strpos($content, '{') === 0) {
            $content = json_decode($content);
            $content = $content->content;
            echo base64_decode($content);
        } else {
            echo $content;
        }
    }

    public static function meet_transcript()
    {
        $meet_id = self::getParam('meet_id');
        $agenda_id = self::getParam('agenda_id');
        $meet_query = Elastic::dbQuery("/{prefix}meet/_doc/" . urlencode($meet_id));
        if (!$meet_query->found) {
            header('HTTP/1.0 404 Not Found');
            self::json_output(['error' => 'not found']);
        }

        $hit_agenda = null;
        foreach ($meet_query->_source->{'公報發言紀錄'} ?? [] as $record) {
            if ($record->agenda_id == $agenda_id) {
                $hit_agenda = $record;
                break;
            }
        }

        if (is_null($hit_agenda)) {
            header('HTTP/1.0 404 Not Found');
            self::json_output(['error' => 'not found']);
        }

        $agenda_query = Elastic::dbQuery("/{prefix}gazette_agenda/_search", "POST", json_encode([
            'query' => [
                'term' => [
                    'gazette_id' => explode('_', $agenda_id)[0],
                ],
            ],
            'size' => 100,
            'sort' => [
                'agendaNo' => 'asc',
            ],
        ]));
        $agendas = [];
        foreach ($agenda_query->hits->hits as $hit) {
            $agendas[] = $hit->_source;
        }

        $content = '';
        foreach ($hit_agenda->agenda_lcidc_ids as $lcidc_id) {
            $content .= file_get_contents(sprintf("https://lydata.ronny-s3.click/agenda-tikahtml/LCIDC01_%s.doc.html", $lcidc_id));
        }

        $ret = new StdClass;
        $ret->agenda = $hit_agenda;
        $blocks = GazetteTranscriptParser::parse($content, $agendas);
        //$blocks = GazetteTranscriptParser::filterBlockByTitle($blocks, $record->content);
        $ret->blocks = $blocks;
        echo json_encode($ret);
        exit;

        print_r($meet_query);
        print_r($meet_id);
        print_r($agenda_id);
        exit;
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
     * @OA\Get(
     *   path="/meet/{meet_id}", summary="取得特定會議資料", tags={"meet"},
     *   @OA\Parameter(name="meet_id", in="path", description="會議 ID", required=true, @OA\Schema(type="string"), example="院會-10-5-1"),
     *   @OA\Response(response="200", description="會議資料", @OA\JsonContent(ref="#/components/schemas/Meet")),
     *   @OA\Response(response="404", description="找不到會議資料", @OA\JsonContent(ref="#/components/schemas/Error")),
     * )
     * @OA\Get(
     *   path="/meet/{meet_id}/ivod", summary="取得特定會議的 iVod 資料", tags={"meet"},
     *   @OA\Parameter(name="meet_id", in="path", description="會議 ID", required=true, @OA\Schema(type="string"), example="院會-10-5-1"),
     *   @OA\Response(response="200", description="iVod 資料"),
     * )
     * @OA\Get(
     *   path="/meet/{meet_id}/bill", summary="取得特定會議的議案資料", tags={"meet"},
     *   @OA\Parameter(name="meet_id", in="path", description="會議 ID", required=true, @OA\Schema(type="string"), example="院會-10-5-1"),
     *   @OA\Response(response="200", description="議案資料", @OA\JsonContent(ref="#/components/schemas/Bill")),
     * )
     * @OA\Get(
     *   path="/meet/{meet_id}/interpellation", summary="取得特定會議的質詢資料", tags={"meet"},
     *   @OA\Parameter(name="meet_id", in="path", description="會議 ID", required=true, @OA\Schema(type="string"), example="院會-10-5-1"),
     *   @OA\Response(response="200", description="質詢資料", @OA\JsonContent(ref="#/components/schemas/Interpellation")),
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
            'sort' => [
                'term' => 'desc',
                'sessionPeriod' => 'desc',
                'dates' => 'desc',
            ],
            'size' => 100,
        ];

        $records = new StdClass;
        $records->total = 0;
        $records->page = @intval($_GET['page']) ?: 1;
        $records->limit = @intval($_GET['limit']) ?: 100;
        $cmd['size'] = $records->limit;
        $cmd['from'] = ($records->page - 1) * $records->limit;
        if (count($params) > 0 and strpos($params[0], '-') !== false) {
            $meet_id = $params[0];
            if (count($params) == 1) {
                $obj = Elastic::dbQuery("/{prefix}meet/_doc/" . urlencode($meet_id));
                if (isset($obj->found) && $obj->found) {
                    self::json_output(LYLib::buildMeet($obj->_source));
                } else {
                    header('HTTP/1.0 404 Not Found');
                    self::json_output(['error' => 'not found']);
                }
                return;
            } else if ($params[1] == 'ivod') {
                self::setParam('meet_id', $meet_id);
                return self::ivod([]);
            } else if ($params[1] == 'bill') {
                self::setParam('meet_id', $meet_id);
                return self::bill([]);
            } else if ($params[1] == 'interpellation') {
                self::setParam('meet_id', $meet_id);
                return self::interpellation([]);
            } else if ($params[1] == 'transcript') {
                self::setParam('meet_id', $meet_id);
                self::setParam('agenda_id', $params[2]);
                return self::meet_transcript([]);
            }
        }
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

        if (self::hasParam('term')) {
            $records->term = self::getParam('term', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'term' => $records->term,
                ],
            ];
        }
        if (self::hasParam('sessionPeriod')) {
            $records->sessionPeriod = self::getParam('sessionPeriod', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'sessionPeriod' => $records->sessionPeriod,
                ],
            ];
        }
        if (self::hasParam('meet_type')) {
            $records->meet_type = self::getParam('meet_type', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'meet_type.keyword' => $records->meet_type,
                ],
            ];
        }
        if (self::hasParam('legislator')) {
            $records->legislator = self::getParam('legislator', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'attendLegislator.keyword' => $records->legislator,
                ],
            ];
        }
        if (self::hasParam('date')) {
            $records->date = self::getParam('date', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'dates' => $records->date,
                ],
            ];
        }

        if (self::hasParam('committee_id')) {
            $records->committee_id = self::getParam('committee_id', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
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
     *   path="/ivod/", summary="從新到舊列出IVOD", tags={"ivod"},
     *   @OA\Parameter(name="term", in="query", description="屆期", required=false, @OA\Schema(type="integer"), example=9),
     *   @OA\Parameter(name="sessionPeriod", in="query", description="會期", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *   @OA\Response(response="200", description="iVod資料")
     * )
     */
    public static function ivod($params)
    {
        $cmd = [
            'query' => [
                'bool' => [
                    'must' => [],
                ],
            ],
            'sort' => [
                '會議時間' => 'desc',
            ],
            'size' => 100,
        ];

        $records = new StdClass;
        $records->total = 0;
        $records->page = @intval($_GET['page']) ?: 1;
        $records->limit = @intval($_GET['limit']) ?: 100;
        $cmd['size'] = $records->limit;
        $cmd['from'] = ($records->page - 1) * $records->limit;

        if (self::hasParam('term')) {
            $records->term = self::getParam('term', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'meet.term' => $records->term,
                ],
            ];
        }
        if (self::hasParam('sessionPeriod')) {
            $records->sessionPeriod = self::getParam('sessionPeriod', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'meet.sessionPeriod' => $records->sessionPeriod,
                ],
            ];
        }
        if (self::hasParam('meet_id')) {
            $records->meet_id = self::getParam('meet_id', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'meet.id.keyword' => $records->meet_id,
                ],
            ];
        }
        if (self::hasParam('legislator')) {
            $records->legislator = self::getParam('legislator', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    '委員名稱.keyword' => $records->legislator,
                ],
            ];
        }
        if (self::hasParam('committee_id')) {
            $records->committee_id = self::getParam('committee_id', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'meet.committees' => $records->committee_id,
                ],
            ];
        }

        if (array_key_exists('date_start', $_GET) and array_key_exists('date_end', $_GET)) {
            $records->date_start = $_GET['date_start'];
            $records->date_end = $_GET['date_end'];
            $cmd['query']['bool']['must'][] = [
                'range' => [
                    '會議時間' => [
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
                    'fields' => ['會議名稱'],
                ],
            ];
        }
        $all_fields = [
            'meet_id' => ['aggs' => true, 'field' => 'meet.id.keyword', 'agg_size' => 100],
            'legislator' => ['aggs' => true, 'field' => '委員名稱.keyword', 'agg_size' => 100],
            'date' => ['aggs' => true, 'field' => 'date', 'agg_size' => 100],
        ];

        if (self::hasParam('aggs')) {
            foreach (self::getParam('aggs', ['array' => true]) as $agg) {
                if (strpos($agg, ',')) {
                    $aggs = explode(',', $agg);
                    foreach ($aggs as $agg) {
                        if (!array_key_exists($agg, $all_fields)) {
                            header('HTTP/1.0 404 Not Found');
                            self::json_output(['error' => 'not found']);
                        }
                        if (!array_key_exists('aggs', $all_fields[$agg])) {
                            header('HTTP/1.0 404 Not Found');
                            self::json_output(['error' => 'not found']);
                        }
                    }
                    if (count($aggs) != 2) {
                        header('HTTP/1.0 404 Not Found');
                        self::json_output(['error' => 'not found']);
                    };
                    $cmd['aggs'][implode(',', $aggs)] = [
                        'terms' => [
                            'field' => $all_fields[$aggs[0]]['field'],
                        ],
                        'aggs' => [
                            $aggs[1] => [
                                'terms' => [
                                    'field' => $all_fields[$aggs[1]]['field'],
                                ],
                            ],
                        ],
                    ];
                    if (array_key_exists('agg_size', $all_fields[$agg])) {
                        $cmd['aggs'][implode(',', $aggs)]['terms']['size'] = $all_fields[$agg]['agg_size'];
                    }
                    if (array_key_exists('agg_size', $all_fields[$aggs[1]])) {
                        $cmd['aggs'][implode(',', $aggs)]['aggs'][$aggs[1]]['terms']['size'] = $all_fields[$aggs[1]]['agg_size'];
                    }

                    continue;
                }
                if (!array_key_exists($agg, $all_fields)) {
                    header('HTTP/1.0 404 Not Found');
                    self::json_output(['error' => 'not found']);
                }
                if (!array_key_exists('aggs', $all_fields[$agg])) {
                    header('HTTP/1.0 404 Not Found');
                    self::json_output(['error' => 'not found']);
                }
                $field = $all_fields[$agg]['field'] ?? $agg;
                $cmd['aggs'][$agg] = [
                    'terms' => [
                        'field' => $field,
                    ],
                ];
                if (array_key_exists('agg_size', $all_fields[$agg])) {
                    $cmd['aggs'][$agg]['terms']['size'] = $all_fields[$agg]['agg_size'];
                }
            }
        }

        $obj = Elastic::dbQuery("/{prefix}ivod/_search", 'GET', json_encode($cmd));
        $records->total = $obj->hits->total;
        $records->total_page = ceil($records->total->value / $records->limit);
        $records->ivods = [];
        foreach ($obj->hits->hits as $hit) {
            $hit->_source->id = $hit->_id;
            $records->ivods[] = ($hit->_source);
        }
        if (self::hasParam('aggs')) {
            foreach (self::getParam('aggs', ['array' => true]) as $agg) {
                $records->aggs[$agg] = [];
                if (strpos($agg, ',')) {
                    $aggs = explode(',', $agg);
                    $agg_id = $agg;
                    foreach ($obj->aggregations->{$agg_id}->buckets as $bucket) {
                        foreach ($bucket->{$aggs[1]}->buckets as $sub_bucket) {
                            $records->aggs[$agg][] = [
                                $aggs[0] => $bucket->key,
                                $aggs[1] => $sub_bucket->key,
                                'count' => $sub_bucket->doc_count,
                            ];
                        }
                    }
                } else {
                    foreach ($obj->aggregations->$agg->buckets as $bucket) {
                        $records->aggs[$agg][] = [
                            'value' => $bucket->key,
                            'count' => $bucket->doc_count,
                        ];
                    }
                }
            }
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
     *   @OA\Parameter(name="meet_id", in="query", description="會議 ID", required=false, @OA\Schema(type="string"), example="院會-10-5-1"),
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
     *   @OA\Parameter(name="meet_id", in="query", description="會議 ID", required=false, @OA\Schema(type="string"), example="院會-10-5-1"),
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
     *   @OA\Parameter(name="meet_id", in="query", description="會議 ID", required=false, @OA\Schema(type="string"), example="院會-10-5-1"),
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
     *   @OA\Parameter(name="meet_id", in="query", description="會議 ID", required=false, @OA\Schema(type="string"), example="院會-10-5-1"),
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

        if (self::hasParam('term')) {
            $records->term = self::getParam('term', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'term' => $records->term,
                ],
            ];
        }
        if (self::hasParam('meet_id')) {
            $records->meet_id = self::getParam('meet_id', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'meet_id.keyword' => $records->meet_id,
                ],
            ];
        }
        if (self::hasParam('sessionPeriod')) {
            $records->sessionPeriod = self::getParam('sessionPeriod', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'sessionPeriod' => $records->sessionPeriod,
                ],
            ];
        }

        if (self::hasParam('legislator')) {
            $records->legislator = self::getParam('legislator', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
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

        $all_fields = [
            'meet_id' => ['aggs' => true, 'field' => 'meet_id.keyword', 'agg_size' => 100],
            'legislator' => ['aggs' => true, 'field' => 'legislators.keyword', 'agg_size' => 100],
            'date' => ['aggs' => true, 'field' => 'date', 'agg_size' => 100],
        ];

        if (self::hasParam('aggs')) {
            foreach (self::getParam('aggs', ['array' => true]) as $agg) {
                if (!array_key_exists($agg, $all_fields)) {
                    header('HTTP/1.0 404 Not Found');
                    self::json_output(['error' => 'not found']);
                }
                if (!array_key_exists('aggs', $all_fields[$agg])) {
                    header('HTTP/1.0 404 Not Found');
                    self::json_output(['error' => 'not found']);
                }
                $field = $all_fields[$agg]['field'] ?? $agg;
                $cmd['aggs'][$agg] = [
                    'terms' => [
                        'field' => $field,
                    ],
                ];
                if (array_key_exists('agg_size', $all_fields[$agg])) {
                    $cmd['aggs'][$agg]['terms']['size'] = $all_fields[$agg]['agg_size'];
                }
            }
        }


        $obj = Elastic::dbQuery("/{prefix}interpellation/_search", 'GET', json_encode($cmd));
        $records->total = $obj->hits->total;
        $records->total_page = ceil($records->total->value / $records->limit);
        $records->interpellations = [];
        foreach ($obj->hits->hits as $hit) {
            $hit->_source->id = $hit->_id;
            $records->interpellations[] = LYLib::buildInterpellation($hit->_source);
        }
        if (self::hasParam('aggs')) {
            foreach (self::getParam('aggs', ['array' => true]) as $agg) {
                $records->aggs[$agg] = [];
                foreach ($obj->aggregations->$agg->buckets as $bucket) {
                    $records->aggs[$agg][] = [
                        'value' => $bucket->key,
                        'count' => $bucket->doc_count,
                    ];
                }
            }
        }
        self::json_output($records);
    }

    /**
     * @OA\Get(
     *   path="/law", summary="搜尋法案資料", tags={"law"},
     *   @OA\Parameter(name="type", in="query", description="法案類型（Ex: 母法、子法）", required=false, @OA\Schema(type="string")),
     *   @OA\Parameter(name="parent", in="query", description="母法 ID（Ex: 01014）", required=false, @OA\Schema(type="string")),
     *   @OA\Parameter(name="q", in="query", description="搜尋法案名稱或其他名稱", required=false, @OA\Schema(type="string")),
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *   @OA\Response(response="200", description="法案資料", @OA\JsonContent(ref="#/components/schemas/Law")),
     * )
     * @OA\Get(
     *   path="/law/{law_id}", summary="取得特定法案資料", tags={"law"},
     *   @OA\Parameter(name="law_id", in="path", description="法案 ID", required=true, @OA\Schema(type="string"), example="01014"),
     *   @OA\Response(response="200", description="法案資料", @OA\JsonContent(ref="#/components/schemas/Law")),
     *   @OA\Response(response="404", description="找不到法案資料", @OA\JsonContent(ref="#/components/schemas/Law")),
     * )
     * @OA\Schema(
     *   schema="Law", type="object", required={"id", "type", "parent", "name", "name_other"},
     *   @OA\Property(property="id", type="string", description="法案 ID"),
     *   @OA\Property(property="type", type="string", description="法案類型"),
     *   @OA\Property(property="parent", type="string", description="母法 ID"),
     *   @OA\Property(property="name", type="string", description="法案名稱"),
     *   @OA\Property(property="name_other", type="array", description="其他名稱", @OA\Items(type="string")),
     * )
     */
    public static function law($params)
    {
        // sample: {"id":"01014","type":"母法","parent":"","name":"國防部組織法","name_other":[]}
        $cmd = [
            'query' => [
                'bool' => [
                    'must' => [],
                ],
            ],
            'sort' => ['id.keyword' => 'asc'],
            'size' => 100,
        ];

        $records = new StdClass;
        $records->total = 0;
        $records->page = @intval($_GET['page']) ?: 1;
        $records->limit = @intval($_GET['limit']) ?: 100;
        $cmd['size'] = $records->limit;
        $cmd['from'] = ($records->page - 1) * $records->limit;

        if (count($params) == 1) {
            $id = $params[0];
            $obj = Elastic::dbQuery("/{prefix}law/_doc/{$id}");
            if (isset($obj->found) && $obj->found) {
                self::json_output(LYLib::buildLaw($obj->_source));
            } else {
                header('HTTP/1.0 404 Not Found');
                self::json_output(['error' => 'not found']);
            }
            return;
        }
        if (count($params) == 2 and $params[1] == 'bill') {
            self::setParam('law', $params[0]);
            return self::bill([]);
        }

        if (self::hasParam('type')) {
            $records->type = self::getParam('type', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'type.keyword' => $records->type,
                ],
            ];
        }

        if (self::hasParam('parent')) {
            $records->parent = self::getParam('parent', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'parent.keyword' => $records->parent,
                ],
            ];
        }

        if (self::hasParam('id')) {
            $records->id = self::getParam('id', ['array' => true]);
            $cmd['query']['bool']['must'][] = [
                'terms' => [
                    'id.keyword' => $records->id,
                ],
            ];
        }

        if (array_key_exists('q', $_GET)) {
            $records->q = '"' . $_GET['q'] . '"';
            $cmd['query']['bool']['must'][] = [
                'query_string' => [
                    'query' => $records->q,
                    'fields' => ['name', 'name_other'],
                ],
            ];
        }

        $obj = Elastic::dbQuery("/{prefix}law/_search", 'GET', json_encode($cmd));
        $records->total = $obj->hits->total;
        $records->total_page = ceil($records->total->value / $records->limit);
        $records->laws = [];
        foreach ($obj->hits->hits as $hit) {
            $hit->_source->id = $hit->_id;
            $records->laws[] = LYLib::buildLaw($hit->_source);
        }
        self::json_output($records);
    }

    /**
     * @OA\Get(
     *   path="/stat", summary="取得統計資料", tags={"stat"},
     *   @OA\Response(response="200", description="統計資料")
     * )
     */
    public static function stat()
    {
        $records = new StdClass;
        // bill
        $ret = Elastic::dbQuery("/{prefix}bill/_search", 'GET', json_encode([
            'size' => 0,
            'aggs' => [
                'term_count' => [
                    'terms' => [
                        'field' => '屆期',
                        'order' => [ '_key' => 'desc' ],
                    ],
                    'aggs' => [
                        'sessionPeriod_count' => [
                            'terms' => [
                                'field' => '會期',
                                'order' => [ '_key' => 'desc' ],
                            ],
                        ],
                    ],
                ],
                'max_mtime' => [
                    'max' => [
                        'field' => 'mtime',
                    ],
                ],
            ]
        ]));
        $records->bill = new StdClass;
        $records->bill->total = 0;
        $records->bill->terms = [];
        foreach ($ret->aggregations->term_count->buckets as $bucket) {
            $records->bill->total += $bucket->doc_count;
            $records->bill->terms[] = [
                'term' => $bucket->key,
                'count' => $bucket->doc_count,
                'sessionPeriod_count' => [],
            ];
            foreach ($bucket->sessionPeriod_count->buckets as $sessionPeriod_bucket) {
                $records->bill->terms[count($records->bill->terms) - 1]['sessionPeriod_count'][] = [
                    'sessionPeriod' => $sessionPeriod_bucket->key,
                    'count' => $sessionPeriod_bucket->doc_count,
                ];
            }
        }
        $records->bill->max_mtime = $ret->aggregations->max_mtime->value;
        $records->bill->max_mtime_human = date('Y-m-d H:i:s', $records->bill->max_mtime / 1000);

        // legislator
        $ret = Elastic::dbQuery("/{prefix}legislator/_search", 'GET', json_encode([
            'size' => 0,
            'aggs' => [
                'term_count' => [
                    'terms' => [
                        'field' => 'term',
                        'order' => [ '_key' => 'desc' ],
                    ],
                ],
            ]
        ]));
        $records->legislator = new StdClass;
        $records->legislator->total = 0;
        $records->legislator->terms = [];
        foreach ($ret->aggregations->term_count->buckets as $bucket) {
            $records->legislator->total += $bucket->doc_count;
            $records->legislator->terms[] = [
                'term' => $bucket->key,
                'count' => $bucket->doc_count,
            ];
        }

        // gazette
        $ret = Elastic::dbQuery("/{prefix}gazette/_search", 'GET', json_encode([
            'size' => 0,
            'aggs' => [
                'year_count' => [
                    'terms' => [
                        'field' => 'comYear',
                        'order' => [ '_key' => 'desc' ],
                    ],
                ],
            ],
        ]));
        $records->gazette = new StdClass;
        $records->gazette->total = 0;
        $records->gazette->agenda_total = 0;
        $records->gazette->max_meeting_date = 0;
        $records->gazette->max_meeting_date_human = '';
        $records->gazette->comYears = [];
        foreach ($ret->aggregations->year_count->buckets as $bucket) {
            $records->gazette->total += $bucket->doc_count;
            $records->gazette->comYears[$bucket->key] = [
                'year' => $bucket->key,
                'count' => $bucket->doc_count,
            ];
        }

        // gazette_agenda
        $ret = Elastic::dbQuery("/{prefix}gazette_agenda/_search", 'GET', json_encode([
            'size' => 0,
            'aggs' => [
                'year_count' => [
                    'terms' => [
                        'field' => 'comYear',
                        'order' => [ '_key' => 'desc' ],
                    ],
                    'aggs' => [
                        'max_meeting_date' => [
                            'max' => [ 'field' => 'meetingDate' ],
                        ],
                    ],
                ],
            ],
        ]));

        foreach ($ret->aggregations->year_count->buckets as $bucket) {
            $records->gazette->agenda_total += $bucket->doc_count;
            $records->gazette->comYears[$bucket->key]['agenda_count'] = $bucket->doc_count;
            $records->gazette->comYears[$bucket->key]['max_meeting_date'] = $bucket->max_meeting_date->value;
            $records->gazette->comYears[$bucket->key]['max_meeting_date_human'] = date('Y-m-d H:i:s', $bucket->max_meeting_date->value / 1000);
        }
        $records->gazette->comYears = array_values($records->gazette->comYears);
        $records->gazette->max_meeting_date = $ret->aggregations->year_count->buckets[0]->max_meeting_date->value;
        $records->gazette->max_meeting_date_human = date('Y-m-d H:i:s', $records->gazette->max_meeting_date / 1000);

        // meet
        $ret = Elastic::dbQuery("/{prefix}meet/_search", 'GET', json_encode([
            'size' => 0,
            'aggs' => [
                'term_count' => [
                    'terms' => [
                        'field' => 'term',
                        'order' => [ '_key' => 'desc' ],
                    ],
                    'aggs' => [
                        'max_meeting_date' => [
                            'max' => [ 'field' => 'meet_data.date'],
                        ],
                        'term_meetdata_count' => [
                            'filter' => [
                                'exists' => ['field' => 'meet_data.date' ],
                            ],
                        ],
                        'term_議事錄_count' => [
                            'filter' => [
                                'exists' => ['field' => '議事錄' ],
                            ],
                        ],
                        'sessionPeriod_count' => [
                            'terms' => [
                                'field' => 'sessionPeriod',
                                'order' => [ '_key' => 'desc' ],
                            ],
                        ],
                    ],
                ],
            ],
        ]));
        $records->meet = new StdClass;
        $records->meet->total = 0;
        $records->meet->terms = [];
        foreach ($ret->aggregations->term_count->buckets as $bucket) {
            $records->meet->total += $bucket->doc_count;
            $records->meet->terms[$bucket->key] = [
                'term' => $bucket->key,
                'count' => $bucket->doc_count,
            ];
            $records->meet->terms[$bucket->key]['max_meeting_date'] = $bucket->max_meeting_date->value;
            $records->meet->terms[$bucket->key]['max_meeting_date_human'] = date('Y-m-d H:i:s', $bucket->max_meeting_date->value / 1000);
            $records->meet->terms[$bucket->key]['meetdata_count'] = $bucket->term_meetdata_count->doc_count;
            $records->meet->terms[$bucket->key]['議事錄_count'] = $bucket->term_議事錄_count->doc_count;
            $records->meet->terms[$bucket->key]['sessionPeriod_count'] = [];
            foreach ($bucket->sessionPeriod_count->buckets as $sessionPeriod_bucket) {
                $records->meet->terms[$bucket->key]['sessionPeriod_count'][] = [
                    'sessionPeriod' => $sessionPeriod_bucket->key,
                    'count' => $sessionPeriod_bucket->doc_count,
                ];
            }
        }
        $records->meet->terms = array_values($records->meet->terms);
        return self::json_output($records);

    }

    public static function json_output($obj)
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        header('Content-Type: application/json; charset=utf-8');
        if (@strpos($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']) !== false) {
            echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        } else {
            echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    public static function hasParam($key)
    {
        return array_key_exists($key, $_GET) && $_GET[$key];
    }

    protected static $_params = [];
    public static function setParam($key, $value)
    {
        self::$_params[$key] = $value;
        $_GET[$key] = $value;
    }

    public static function getParam($key, $opt = null)
    {
        if (array_key_exists($key, self::$_params)) {
            $matches = [self::$_params[$key]];
        } else {
            $uri = explode('?', $_SERVER['REQUEST_URI'])[1];
            $matches = [];
            foreach (explode('&', $uri) as $term) {
                list($k, $v) = explode('=', $term);
                $k = urldecode($k);
                $v = urldecode($v);
                if ($k == $key) {
                    $matches[] = $v;
                }
            }
        }
        if (is_array($opt) and array_key_exists('array', $opt) and $opt['array']) {
            return $matches;
        }
        if (count($matches) == 1){
            return $matches[0];
        }
        return $matches;
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
        } else if ('ivod' == $method) {
            self::ivod($terms);
        } else if ('interpellation' == $method) {
            self::interpellation($terms);
        } else if ('law' == $method) {
            self::law($terms);
        } else if ('stat' == $method) {
            self::stat();
        } else {
            header('HTTP/1.0 404 Not Found');
            echo '404 Not Found';
        }
    }
}
