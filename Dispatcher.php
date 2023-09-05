<?php

/**
 * @OA\Info(
 *   title="立法院 API", version="1.0.0"
 * )
 * @OA\Tag(name="legislator", description="立法委員")
 * @OA\Tag(name="committee", description="委員會")
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
        $page = @intval($_GET['page']) ?: 1;
        $limit = @intval($_GET['limit']) ?: 100;

        $cmd = [
            'query' => [
                'bool' => [
                    'must' => [],
                ],
            ],
            'size' => $limit,
            'from' => ($page - 1) * $limit,
        ];

        $records = new StdClass;
        $records->total = 0;
        $records->page = 0;

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
        $records->page = $page;
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

    public static function json_output($obj)
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        echo json_encode($obj, JSON_UNESCAPED_UNICODE);
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

        $terms = explode('/', trim($uri, '/'));
        $method = array_shift($terms);
        $terms = array_map('urldecode', $terms);

        if ('legislator' == $method) {
            self::legislator($terms);
        } else if ('committee' == $method) {
            self::committee($terms);
        }
    }
}
