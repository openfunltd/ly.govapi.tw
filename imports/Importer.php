<?php

class Importer
{
    public static function getURL($url, $timeout = 10, $retry_max = 3)
    {
        for ($retry = 0; $retry < $retry_max; $retry++) {
            $curl = curl_init($url);
            // only ipv4
            curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
            // agent chrome
            curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $content = curl_exec($curl);
            $info = curl_getinfo($curl);
            $error = curl_error($curl);
            // if timeout,  continue
            if ($info['http_code'] == 200) {
                curl_close($curl);
                return $content;
            }
            error_log(sprintf("Failed to get URL: %s retry: %d error:(%d) %s",
                $url,
                $retry,
                $info['http_code'],
                $error));
            error_log('Failed to get URL: ' . $url . ' retry: ' . $retry . ' error: ' . $error);
            sleep($retry + 1);
            curl_close($curl);
        }
        throw new Exception('Failed to get URL: ' . $url);
    }

    public static function addImportLog($data, $commit = true)
    {
        if (!is_null($data)) {
            $data['log_at'] = microtime(true);
            Elastic::dbBulkInsert('logs-import-' . date('Y'), null, $data);
        }
        if ($commit) {
            Elastic::dbBulkCommit();
        }
    }

    public static function postToSlack($slack_url, $message)
    {
        $data = [
            'text' => $message,
        ];
        $data_string = json_encode($data);
        $ch = curl_init($slack_url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string),
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

}
