<?php

namespace Ext\U8Open\service;

class OmApiRemoteException extends \RuntimeException {}

/** 独立委外服务，不继承采购服务。 */
class OutsourceOrderService
{
    private $appId;
    private $config;
    private $transport;

    public function __construct($appId, ?array $config = null, ?callable $transport = null)
    {
        $this->config = $config ?? require __DIR__ . '/../config/om-api.php';
        $this->appId = filter_var($appId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$this->appId || $this->appId !== ($this->config['app_id'] ?? 0)) throw new \RuntimeException('OM_APP_ID_MISMATCH');
        $this->transport = $transport;
    }

    public function getList($param)
    {
        if (($param['state'] ?? '开立') !== '开立') return ['errcode' => 'OM_ONLY_OPEN_ORDERS'];
        $after = '0'; $seen = []; $orders = [];
        for ($page = 0; $page < 1000; $page++) {
            $data = $this->request('GET', '/api/omorder/list?app_id=' . $this->appId . '&after=' . rawurlencode($after));
            if (!is_array($data['orders'] ?? null) || !array_key_exists('next_cursor', $data)) throw new \RuntimeException('OM_LIST_RESPONSE_INVALID');
            foreach ($data['orders'] as $row) {
                $code = self::orderCode($row['code'] ?? null);
                if (isset($seen[$code])) throw new \RuntimeException('OM_DUPLICATE_LIST_CODE');
                $seen[$code] = true; $orders[] = ['code' => $code];
            }
            if ($data['next_cursor'] === null) return $orders;
            $next = $data['next_cursor'];
            if (!is_string($next) || !preg_match('/\A[1-9][0-9]{0,18}\z/D', $next)
                || strlen($next) < strlen($after) || (strlen($next) === strlen($after) && strcmp($next, $after) <= 0)) throw new \RuntimeException('OM_CURSOR_NOT_ADVANCING');
            $after = $next;
        }
        throw new \RuntimeException('OM_SCAN_LIMIT_REACHED');
    }

    public function getDetail($param)
    {
        $code = self::orderCode($param['id'] ?? null);
        $data = $this->request('GET', '/api/omorder/detail?app_id=' . $this->appId . '&id=' . rawurlencode($code));
        $order = $data['omorder'] ?? null;
        if (!is_array($order) || ($order['code'] ?? null) !== $code || ($order['operation_type_code'] ?? null) !== '委外加工'
            || !is_array($order['entry'] ?? null) || !$order['entry']) throw new \RuntimeException('OM_DETAIL_IDENTITY_INVALID');
        return ['errcode' => '0', 'omorder' => $order];
    }

    public function audit($param, $extra = [])
    {
        if (($this->config['audit_enabled'] ?? false) !== true) return ['errcode' => 'OM_AUDIT_DISABLED'];
        $code = self::orderCode($param['voucher_code'] ?? null);
        $runId = filter_var($extra['oa_run_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$runId) return ['errcode' => 'OM_RUN_ID_REQUIRED'];
        $body = json_encode(['app_id' => $this->appId, 'code' => $code, 'oa_run_id' => $runId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        try {
            $result = $this->request('POST', '/api/omorder/audit', $body);
        } catch (\Throwable $failure) {
            // 验签后的明确拒绝与传输结果未知分别处理。
            if ($failure instanceof OmApiRemoteException && in_array($failure->getMessage(), [
                'OM_AUDIT_DISABLED', 'OM_HISTORICAL_TEST_ORDER_PROTECTED', 'OM_ORDER_NOT_OPEN',
                'OM_DOCUMENT_ALREADY_CLAIMED', 'OM_AUDIT_REQUEST_INVALID', 'OM_APP_ID_MISMATCH',
                'OM_LOGIN_EXPIRED_RESTART_REQUIRED', 'OM_SOURCE_IP_DENIED', 'OM_ORDER_NOT_FOUND',
            ], true)) return ['errcode' => $failure->getMessage()];
            // 审核请求可能已到达 U8，只查询原操作结果，不重发审核请求。
            try {
                $result = $this->request('GET', '/api/omorder/operation?app_id=' . $this->appId . '&id=' . rawurlencode($code) . '&oa_run_id=' . $runId);
            } catch (\Throwable $lookupFailure) {
                return ['errcode' => 'OM_OUTCOME_UNKNOWN', 'errmsg' => '审核结果未确认，请在U8核对，勿重复审核'];
            }
        }
        if (($result['app_id'] ?? null) !== $this->appId || ($result['code'] ?? null) !== $code || ($result['oa_run_id'] ?? null) !== $runId) return ['errcode' => 'OM_AUDIT_IDENTITY_INVALID'];
        if (!in_array($result['status'] ?? null, ['audited', 'already_audited'], true)) return ['errcode' => 'OM_OUTCOME_UNKNOWN', 'errmsg' => '审核结果未确认，请在U8核对，勿重复审核'];
        return ['errcode' => '0', 'status' => $result['status']];
    }

    public static function orderCode($code): string
    {
        if (!is_string($code) || !preg_match('/\A[A-Za-z0-9._-]{1,30}\z/D', $code)) throw new \RuntimeException('OM_ORDER_CODE_INVALID');
        return $code;
    }
    public static function signature(string $key, string $method, string $path, string $time, string $nonce, string $body): string
    {
        return hash_hmac('sha256', "om-v1\n$method\n$path\n$time\n$nonce\n" . hash('sha256', $body), $key);
    }
    private function request(string $method, string $path, string $body = ''): array
    {
        $base = $this->config['base_url'] ?? ''; $key = $this->config['hmac_key'] ?? '';
        $parts = is_string($base) ? parse_url($base) : false;
        if (($this->config['read_enabled'] ?? false) !== true || !is_string($key) || strlen($key) < 32 || strlen($key) > 256
            || !is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) throw new \RuntimeException('OM_API_CONFIG_INVALID');
        $time = (string) time(); $nonce = bin2hex(random_bytes(16));
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-OM-Time' => $time, 'X-OM-Nonce' => $nonce,
            'X-OM-Signature' => self::signature($key, $method, $path, $time, $nonce, $body)];
        try {
            if ($this->transport !== null) {
                $response = ($this->transport)($method, rtrim($base, '/') . $path, $headers, $body);
            } else {
                $client = new \GuzzleHttp\Client(['timeout' => 120, 'connect_timeout' => 10, 'http_errors' => false, 'allow_redirects' => false]);
                $http = $client->request($method, rtrim($base, '/') . $path, ['headers' => $headers, 'body' => $body, 'stream' => true]);
                $stream = $http->getBody(); $bytes = '';
                while (!$stream->eof()) {
                    $bytes .= $stream->read(65536);
                    if (strlen($bytes) > 4194304) { $stream->close(); throw new \RuntimeException('OM_RESPONSE_TOO_LARGE'); }
                }
                $stream->close();
                $response = ['status' => $http->getStatusCode(), 'body' => $bytes, 'signature' => $http->getHeaderLine('X-OM-Response-Signature')];
            }
            if (!is_string($response['body'] ?? null) || strlen($response['body']) > 4194304 || !is_string($response['signature'] ?? null)
                || !hash_equals(hash_hmac('sha256', "om-response-v1\n$nonce\n" . hash('sha256', $response['body']), $key), $response['signature'])) throw new \RuntimeException('OM_RESPONSE_SIGNATURE_INVALID');
            $decoded = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || ($decoded['app_id'] ?? null) !== $this->appId || !is_string($decoded['errcode'] ?? null)) throw new \RuntimeException('OM_RESPONSE_INVALID');
            if ($response['status'] !== 200 || $decoded['errcode'] !== '0') {
                $safe = preg_match('/\A[A-Z][A-Z0-9_]{0,80}\z/D', $decoded['errcode']) ? $decoded['errcode'] : 'REMOTE_REJECTED';
                throw new OmApiRemoteException('OM_' . $safe);
            }
            if (!is_array($decoded['data'] ?? null)) throw new \RuntimeException('OM_RESPONSE_INVALID');
            return $decoded['data'];
        } catch (\Throwable $failure) {
            if ($failure instanceof OmApiRemoteException) throw $failure;
            $message = $failure instanceof \RuntimeException ? $failure->getMessage() : '';
            throw new \RuntimeException(preg_match('/\AOM_[A-Z0-9_]{1,90}\z/D', $message) ? $message : 'OM_TRANSPORT_ERROR');
        }
    }
}
