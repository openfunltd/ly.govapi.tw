<?php

class LawLib
{
    public static function getVersionsByDir($dir)
    {
        $versions = [];
        foreach (glob("{$dir}/*.html") as $file) {
            $file_name = basename($file, '.html');
            if (strpos($file_name, '-')) {
                continue;
            }

            if (strpos($dir, '04102') and $file_name == '中華民國80年5月1日') {
                // 動員戡亂時期臨時條款
                $file_name .= '廢止';
            } elseif (strpos($dir, '04560') and $file_name == '中華民國68年12月31日') {
                // 中美共同防禦期間處理在華美軍人員刑事案件條例 (民國55年)
                $file_name .= '廢止';
            } elseif (strpos($dir, '90183') and $file_name == '中華民國23年11月2日') {
                // 民國二十三年江蘇省水利建設公債條例
                $file_name .= '制定';
            } elseif (strpos($dir, '90174') and $file_name == '中華民國27年7月26日') {
                // 國立北平故宮博物院暫行組織條例
                $file_name .= '修正';
            }

            if (preg_match('#^中華民國(\d+)年(\d+)月(\d+)日(制定|修正|全文修正|廢止|期滿廢止|停止適用)#u', $file_name, $matches)) {
                $date = sprintf("%04d-%02d-%02d", $matches[1] + 1911, $matches[2], $matches[3]);
                $action = $matches[4];
                $versions[] = [
                    'version_id' => "{$date}-{$action}",
                    'date' => $date,
                    'action' => $action,
                ];
            } else {
                error_log("{$dir} has invalid file name: {$file_name}");
                exit;
            }
        }
        usort($versions, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });
        return $versions;
    }
}
