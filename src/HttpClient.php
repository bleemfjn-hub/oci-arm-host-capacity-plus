<?php


namespace Hitrov;

use Hitrov\Exception\ApiCallException;
use Hitrov\Exception\CurlException;
use JsonException;

class HttpClient
{
    /**
     * P1-optimized: reusable curl handle to keep TLS connection alive
     * across multiple API calls within the same PHP process.
     * Saves ~280ms TLS handshake per request.
     */
    private static $curl = null;

    /**
     * @param array $curlOptions
     * @return array
     * @throws ApiCallException
     * @throws JsonException|CurlException
     */
    public static function getResponse(array $curlOptions): array
    {
        if (self::$curl === null) {
            self::$curl = curl_init();
            // P1-optimized: keep-alive defaults for connection reuse
            curl_setopt(self::$curl, CURLOPT_TCP_KEEPALIVE, 1);
            curl_setopt(self::$curl, CURLOPT_TCP_KEEPIDLE, 30);
            curl_setopt(self::$curl, CURLOPT_TCP_KEEPINTVL, 10);
        }
        $curl = self::$curl;
        curl_setopt_array($curl, $curlOptions);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $errNo = curl_errno($curl);
        $info = curl_getinfo($curl);
        // P1-optimized: do NOT close the handle - reuse it for next call

        if ($response === false || ($error && $errNo)) {
            throw new CurlException("curl error occurred: $error, response: $response", $errNo);
        }

        $responseArray = json_decode($response, true);
        $jsonError = json_last_error();
        $logResponse = $response;
        if (!$jsonError) {
            $logResponse = json_encode($responseArray, JSON_PRETTY_PRINT);
        }

        if ($info['http_code'] < 200 || $info['http_code'] >= 300) {
            throw new ApiCallException($logResponse, $info['http_code']);
        }

        if ($jsonError) {
            $jsonErrorMessage = json_last_error_msg();
            throw new JsonException("JSON error occurred: $jsonError ($jsonErrorMessage), response: \n$logResponse");
        }

        return $responseArray;
    }
}
