<?php

namespace Ext\U8Open\service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Redis;

class OpenApiService
{
    private $agentId;
    private $secret;
    private $user;
    private $url;
    private $openurls = [
        'token' => 'open-api/get-token',
        'flow_create' => 'flow/new-page/flow-save',
        'flow_submit' => 'flow/run/flow-turning',
    ];

    public function __construct($config = [])
    {
        $openApiConfig = parse_ini_file(__DIR__ . '/../config/openApi.ini');
        $config = array_merge($config, $openApiConfig);
        $this->url = $config['url'] ?? '';
        $this->agentId = $config['agent_id'] ?? '';
        $this->secret = $config['secret'] ?? '';
        $this->user = $config['user'] ?? '';
    }

    public function initUser($user)
    {
        if ($user) {
            $this->user = $user;
        }
    }

    public function getToken($forceRefresh = false)
    {
        if (!$forceRefresh) {
            $token = $this->getTokenByRedis();
            if ($token) {
                return $token;
            }
        } else {
            $this->clearTokenInRedis();
        }
        return $this->getTokenByApi();
    }

    public function getTokenByApi()
    {
        $url = $this->url . $this->openurls['token'];
        $data = [
            'agent_id' => $this->agentId,
            'secret' => $this->secret,
            'user' => $this->user,
        ];
        $result = $this->request('POST', $url, $data);
        if (isset($result['status']) && $result['status'] == 1) {
            $data = $result['data'];
            $this->setTokenInRedis($data);
            return $data['token'] ?? null;
        }
        self::logText('status=oa_token_fetch_failed');
        return null;
    }

    /**
     * 从 Redis 获取当前用户的 OpenAPI token
     */
    public function getTokenByRedis()
    {
        $token = Redis::get($this->getTokenCacheKey());
        return $token ?: '';
    }

    /**
     * 将 token 写入 Redis，TTL 取接口返回的 expired_in（提前 60 秒过期）
     */
    private function setTokenInRedis($data)
    {
        $token = $data['token'] ?? '';
        if (!$token) {
            return;
        }
        $ttl = (int) ($data['expired_in'] ?? 7200);
        $ttl = max(60, $ttl - 60);
        Redis::set($this->getTokenCacheKey(), $token, 'EX', $ttl);
    }

    private function clearTokenInRedis()
    {
        Redis::del($this->getTokenCacheKey());
    }

    private function getTokenCacheKey()
    {
        $user = $this->user ?: 'default';
        $agentId = $this->agentId ?: '0';
        return 'u8open:openapi:token:' . $agentId . ':' . $user;
    }

    public function request($method, $url, $data = null, $heads = null)
    {
        $client = new Client(['http_errors' => false]);
        $option = ['json' => $data];
        if ($heads) {
            $option['headers'] = $heads;
        }
        try {
            $response = $client->request($method, $url, $option);
            $output = $response->getBody()->getContents();
            $decoded = json_decode($output, true);
            if (!is_array($decoded)) {
                return [
                    'errcode' => 1,
                    'status' => 0,
                    'http_status' => $response->getStatusCode(),
                    'msg' => $output,
                ];
            }
            $decoded['http_status'] = $response->getStatusCode();
            return $decoded;
        } catch (\Exception $exc) {
            $body = '';
            if ($exc instanceof RequestException && $exc->hasResponse()) {
                $body = (string) $exc->getResponse()->getBody();
            }
            return [
                'errcode' => 1,
                'status' => 0,
                'errmsg' => $exc->getMessage(),
                'msg' => $body ?: $exc->getMessage(),
            ];
        }
    }

    public function createFlow($data, ?callable $callback = null)
    {
        $url = $this->url . $this->openurls['flow_create'];
        self::logText('status=oa_flow_create_start');
        $result = $this->requestWithTokenRetry($url, $data, function (&$params, $payload) {
            $this->buildFlowParam($params, $payload);
        });
        if ($callback && is_callable($callback)) {
            $callback($result);
        }
        return $result;
    }

    /**
     * 带 token 的请求：遇无效令牌时清缓存、重新取 token 并重试一次
     */
    private function requestWithTokenRetry($url, $data, callable $buildParams)
    {
        $retried = false;
        while (true) {
            $token = $this->getToken($retried);
            if (!$token) {
                throw new \Exception('获取OAOpenApiToken失败');
            }
            $params = ['api_token' => $token];
            $buildParams($params, $data);
            $result = $this->request('POST', $url, $params);
            if (!$retried && $this->isInvalidToken($result)) {
                self::logText('status=oa_token_invalid retry=1');
                $this->clearTokenInRedis();
                $retried = true;
                continue;
            }
            return $result;
        }
    }

    private function isInvalidToken($result)
    {
        if (!is_array($result)) {
            return false;
        }
        $httpStatus = (int) ($result['http_status'] ?? 0);
        if ($httpStatus === 401) {
            return true;
        }
        $errors = $result['errors'] ?? [];
        if (!is_array($errors)) {
            return false;
        }
        foreach ($errors as $error) {
            $code = $error['code'] ?? '';
            // 0x003003 无效令牌；0x003008 令牌过期
            if (in_array($code, ['0x003003', '0x003008'], true)) {
                return true;
            }
        }
        return false;
    }

    public function buildFlowParam(&$params, $data)
    {
        $params['flow_id'] = $data['flow_id'];
        $params['flow_run_name'] = $data['flow_run_name'];
        $params['run_name_html'] = '<div>' . $params['flow_run_name'] . '</div>';
        $params['form_data'] = $data['form_data'];
    }

    public function flowSubmit($data)
    {
        $url = $this->url . $this->openurls['flow_submit'];
        return $this->requestWithTokenRetry($url, $data, function (&$params, $payload) {
            $this->buildSubmitFlowParam($params, $payload);
        });
    }

    public function buildSubmitFlowParam(&$params, $data)
    {
        $params['run_id'] = '';
        $params['process_id'] = '';
        $params['process_transact_user'] = '';
        $params['process_host_user'] = '';
        $params['next_flow_process'] = '';
    }

    public static function logText($data)
    {
        U8ScheduleService::logText($data);
    }
}
