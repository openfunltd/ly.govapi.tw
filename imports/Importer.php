<?php

class Importer
{
    public static function getURL($url)
    {
        $curl = curl_init($url);
        // only ipv4
        curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        // agent chrome
        curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        return curl_exec($curl);
    }

    public static function addImportLog($data)
    {
        $data['log_at'] = date('c');
        Elastic::dbBulkInsert('logs-import-' . date('Y'), null, $data);
        Elastic::dbBulkCommit();
    }
}
