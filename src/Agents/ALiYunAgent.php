<?php

namespace Laravelsms\Sms\Agents;

use Laravelsms\Sms\Contracts\Sms;
use Illuminate\Support\Arr;

class ALiYunAgent extends Sms
{
    private $config = [];

    private $appKey;
    private $appSecret;

    private $host = 'https://sms.aliyuncs.com/?';
    private $singleSendUrl = 'SingleSendSms';
    private $method = "GET";

    public function __construct($config)
    {
        $this->config = $config;
        $this->transformConfig();
    }

    protected function transformConfig()
    {
        $credentials = Arr::pull($this->config, 'credentials');
        $this->appKey = Arr::pull($credentials, 'appKey');
        $this->appSecret = Arr::pull($credentials, 'appSecret');
        $this->setSignName();
        $this->setTemplateId(Arr::pull($this->config, 'templateId'));
    }

    protected function percentEncode($str)
    {
        $res = urlencode($str);
        $res = preg_replace('/\+/', '%20', $res);
        $res = preg_replace('/\*/', '%2A', $res);
        $res = preg_replace('/%7E/', '~', $res);
        return $res;
    }

    private function computeSignature($parameters)
    {
        ksort($parameters);
        $canonicalizedQueryString = '';
        foreach ($parameters as $key => $value) {
            $canonicalizedQueryString .= '&' . $this->percentEncode($key) . '=' . $this->percentEncode($value);
        }
        $stringToSign = $this->method . '&%2F&' . $this->percentEncode(substr($canonicalizedQueryString, 1));
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->appSecret . '&', true));

        return $signature;
    }

    public function singlesSend($mobile, $send = true)
    {
        $url = $this->host;

        $requestParams = array(
            'ParamString' => json_encode($this->templateVar),
            'RecNum' => $mobile,
            'SignName' => $this->signName,
            'TemplateCode' => $this->templateId
        );

        if ($send) {
            return $this->curl($url, $requestParams);
        }

        return $requestParams;
    }

    /**
     * @param $url
     * @param array $requestParams
     * @return array $result
     * @return int $result[].code 返回0则成功，返回其它则错误
     * @return string $result[].msg 返回消息
     * @return string $result[].verifyCode 验证码
     */
    protected function curl($url, $requestParams)
    {
        // 注意使用GMT时间
        date_default_timezone_set("GMT");
        $dateTimeFormat = 'Y-m-d\TH:i:s\Z';

        // 其他请求参数公共参数
        $publicParams = array(
            'Format' => 'JSON',
            'Version' => '2016-09-27',
            'SignatureVersion' => '1.0',
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureNonce' => uniqid(),
            'AccessKeyId' => $this->appKey,
            'Timestamp' => date($dateTimeFormat),
            'Action' => $this->singleSendUrl
        );

        $publicParams = array_merge($requestParams, $publicParams);
        $signature = $this->computeSignature($publicParams);
        $publicParams = array_merge(['Signature' => $signature], $publicParams);

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url . http_build_query($publicParams));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $httpResponse = $this->httpResponse($ch);
        $result = $this->transformerResponse($httpResponse);
        curl_close($ch);

        return $result;
    }

    protected function transformerResponse($httpResponse)
    {
        if (empty($httpResponse['error'])) {
            $response = json_decode($httpResponse['jsonData'], true);
            if ($response['Model']) {
                $result = ['code' => 0, 'msg' => '发送成功', 'verifyCode' => $this->verifyCode];
            } else {
                $result = ['code' => time(), 'msg' => $response['Code']];
            }
            unset($response);
        } else {
            $result = ['code' => time(), 'msg' => $httpResponse['error']];
        }

        return $result;
    }
}
