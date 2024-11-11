<?php

class LawLib
{
    public static function getVersionIdFromString($str, $law_id = null)
    {
        $custom_map = [
            "04102-中華民國80年5月1日" => "廢止", // 動員戡亂時期臨時條款
            "04560-中華民國68年12月31日" => "廢止", // 中美共同防禦期間處理在華美軍人員刑事案件條例 (民國55年)
            "90183-中華民國23年11月2日" => "制定", // 民國二十三年江蘇省水利建設公債條例
            "90174-中華民國27年7月26日" => "修正", // 國立北平故宮博物院暫行組織條例
        ];

        if (array_key_exists("{$law_id}-{$str}", $custom_map)) {
            $str .= $custom_map["{$law_id}-{$str}"];
        }

        if (!preg_match('#^中華民國(\d+)年(\d+)月(\d+)日(制定|修正|全文修正|廢止|期滿廢止|停止適用)#u', $str, $matches)) {
            throw new Exception("Invalid version string: {$str}");
        }
        $date = sprintf("%04d-%02d-%02d", $matches[1] + 1911, $matches[2], $matches[3]);
        $action = $matches[4];
        return [
            'date' => $date,
            'action' => $action,
        ];
    }

    public static function getVersionsByDir($dir)
    {
        $versions = [];
        foreach (glob("{$dir}/*.html") as $file) {
            $file_name = basename($file, '.html');
            if (strpos($file_name, '-')) {
                continue;
            }

            $law_id = basename($dir);

            $date_action = self::getVersionIdFromString($file_name, $law_id);
            $versions[] = [
                'version_id' => "{$date_action['date']}-{$date_action['action']}",
                'date' => $date_action['date'],
                'action' => $date_action['action'],
            ];
        }
        usort($versions, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });
        return $versions;
    }
}
