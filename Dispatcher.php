<?php

/**
 * @OA\Info(
 *   title="立法院 API", version="1.0.0"
 * )
 * @OA\Tag(name="legislator", description="立法委員")
 * @OA\Tag(name="committee", description="委員會")
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
     */
    public static function legislator($params)
    {
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
     *   path="/committee/{comtCd}", summary="取得特定委員會資料", tags={"committee"},
     *   @OA\Parameter(name="comtCd", in="path", description="委員會 ID", required=true, @OA\Schema(type="integer"), example=15),
     *   @OA\Response(response="200", description="委員會資料", @OA\JsonContent(ref="#/components/schemas/Committee")),
     *   @OA\Response(response="404", description="找不到委員會資料", @OA\JsonContent(ref="#/components/schemas/Error")),
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

        $buildData = function($source) {
            $source->gazette_id = sprintf("LCIDC01_%03d%02d%02d",
                $source->comYear,
                $source->comVolume,
                $source->comBookId
            );
            $source->agenda_api = sprintf("https://%s/gazette_agenda/%s",
                $_SERVER['HTTP_HOST'],
                $source->gazette_id
            );
            return $source;
        };

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
                    self::json_output($buildData($obj->_source));
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
            $records->gazettes[] = $buildData($hit->_source);
        }
        self::json_output($records);
    }

    /**
     * @OA\Get(
     *  path="/gazette_agenda", summary="取得依時間新至舊的公報目錄", tags={"gazette"},
     *  @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *  @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
     *  @OA\Response(response="200", description="公報目錄資料", @OA\JsonContent(ref="#/components/schemas/GazetteAgenda")),
     *  )
     *  @OA\Get(
     *   path="/gazette_agenda/{gazette_id}", summary="取得公報下所有目錄", tags={"gazette"},
     *   @OA\Parameter(name="gazette_id", in="path", description="公報 ID", required=true, @OA\Schema(type="string"), example="LCIDC01_1126203"),
     *   @OA\Parameter(name="page", in="query", description="頁數", required=false, @OA\Schema(type="integer"), example=1),
     *   @OA\Parameter(name="limit", in="query", description="每頁筆數", required=false, @OA\Schema(type="integer"), example=100),
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

        $buildData = function($source) {
            return $source;
        };

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
                    self::json_output($buildData($obj->_source));
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

        $obj = Elastic::dbQuery("/{prefix}gazette_agenda/_search", 'GET', json_encode($cmd));
        $records->total = $obj->hits->total;
        $records->total_page = ceil($records->total->value / $records->limit);
        $records->gazettes = [];
        foreach ($obj->hits->hits as $hit) {
            $records->gazettes[] = $buildData($hit->_source);
        }
        self::json_output($records);
    }

    public static function json_output($obj)
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        if (strpos($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']) !== false) {
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
        } else {
            header('HTTP/1.0 404 Not Found');
            echo '404 Not Found';
        }
    }
}
