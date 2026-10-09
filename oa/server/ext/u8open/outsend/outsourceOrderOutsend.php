<?php

declare(strict_types=1);

use Ext\U8Open\service\PurchaseorderService;
use Illuminate\Support\Facades\DB;

/**
 * 校验回调信封，只接受委外流程 31 和正整数运行编号。
 *
 * @throws InvalidArgumentException
 */
function outsourceOrderValidateEnvelope(array $request): int
{
    $flowId = $request['flow_id'] ?? null;
    if (!is_int($flowId) && !is_string($flowId)) {
        throw new InvalidArgumentException('回调流程无效');
    }

    if ((string) $flowId !== '31') {
        throw new InvalidArgumentException('回调流程无效');
    }

    $runId = $request['run_id'] ?? null;
    if (!is_int($runId) && !is_string($runId)) {
        throw new InvalidArgumentException('回调运行编号无效');
    }

    $validatedRunId = filter_var(
        $runId,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if ($validatedRunId === false) {
        throw new InvalidArgumentException('回调运行编号无效');
    }

    return $validatedRunId;
}

/**
 * 以可信同步记录为准，并和流程表单中的关键字段进行交叉校验。
 *
 * @throws InvalidArgumentException
 */
function outsourceOrderBuildAuditContext(array $sync, array $form): array
{
    $appIdValue = $sync['app_id'] ?? null;
    if (!is_int($appIdValue) && !is_string($appIdValue)) {
        throw new InvalidArgumentException('同步应用编号无效');
    }

    $appId = filter_var(
        $appIdValue,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if ($appId === false) {
        throw new InvalidArgumentException('同步应用编号无效');
    }

    $orderNoValue = $sync['order_no'] ?? null;
    if (!is_scalar($orderNoValue)) {
        throw new InvalidArgumentException('同步订单号无效');
    }

    $orderNo = (string) $orderNoValue;
    if ($orderNo === '') {
        throw new InvalidArgumentException('同步订单号无效');
    }

    $formFlowId = $form['flow_id'] ?? null;
    if ((!is_int($formFlowId) && !is_string($formFlowId)) || (string) $formFlowId !== '31') {
        throw new InvalidArgumentException('表单流程不匹配');
    }

    $formAppId = $form['DATA_236'] ?? null;
    if ((!is_int($formAppId) && !is_string($formAppId)) || (string) $formAppId !== (string) $appId) {
        throw new InvalidArgumentException('表单应用编号不匹配');
    }

    $formOrderNo = $form['DATA_162'] ?? null;
    if (!is_scalar($formOrderNo) || (string) $formOrderNo !== $orderNo) {
        throw new InvalidArgumentException('表单订单号不匹配');
    }

    $isOutsource = ($form['DATA_263'] ?? null) === '委外'
        || ($form['DATA_237'] ?? null) === '委外加工';
    if (!$isOutsource) {
        throw new InvalidArgumentException('表单不是委外订单');
    }

    // 审核参数只取自可信同步记录，保留订单号中的前导零。
    return [
        'app_id' => $appId,
        'voucher_code' => $orderNo,
    ];
}

/**
 * 判断 U8 返回是否表示订单已审核，以保证重复回调幂等成功。
 *
 * @param mixed $result
 */
function outsourceOrderAlreadyAudited($result): bool
{
    if (is_string($result)) {
        $message = trim($result);

        return preg_match('/\A(?:该)?订单(?:已经审核|已审核)[。！!.]?\z/u', $message) === 1;
    }

    if (!is_array($result)) {
        return false;
    }

    foreach (['errmsg', 'message', 'msg', 'errors'] as $messageKey) {
        if (!array_key_exists($messageKey, $result)) {
            continue;
        }

        if (outsourceOrderAlreadyAudited($result[$messageKey])) {
            return true;
        }
    }

    return false;
}

/**
 * 递归检查错误内容是否出现敏感标记，避免任何原值被输出。
 *
 * @param mixed $value
 */
function outsourceOrderContainsSensitiveMarker($value): bool
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            if (outsourceOrderContainsSensitiveMarker((string) $key)
                || outsourceOrderContainsSensitiveMarker($item)) {
                return true;
            }
        }

        return false;
    }

    if (!is_scalar($value) && $value !== null) {
        return true;
    }

    return preg_match(
        '~password|token|secret|authorization|bearer|app[_-]?key|api[_-]?key|cookie|session[\s_-]*id|credentials?|https?://|stack\s+trace|[a-z]:\\\\~i',
        (string) $value
    ) === 1;
}

/**
 * 清理并限制可对外展示的普通错误摘要。
 */
function outsourceOrderNormalizeErrorMessage(string $message): string
{
    $message = preg_replace('/[\x00-\x1F\x7F]+/u', '', $message) ?? '';
    $message = trim($message);
    if ($message === '') {
        return '委外订单审核失败';
    }

    if (strlen($message) <= 480) {
        return $message;
    }

    if (function_exists('mb_strcut')) {
        return mb_strcut($message, 0, 480, 'UTF-8');
    }

    return substr($message, 0, 480);
}

/**
 * 生成对外安全错误摘要；未知结构不做序列化或完整回显。
 *
 * @param mixed $value
 */
function outsourceOrderSafeError($value): string
{
    if (outsourceOrderContainsSensitiveMarker($value)) {
        return '委外订单审核失败（敏感信息已隐藏）';
    }

    if (is_array($value)) {
        foreach (['errmsg', 'message', 'msg', 'errors'] as $messageKey) {
            if (!array_key_exists($messageKey, $value)) {
                continue;
            }

            $message = $value[$messageKey];
            if (is_string($message) || is_numeric($message)) {
                return outsourceOrderNormalizeErrorMessage((string) $message);
            }
        }
    }

    return '委外订单审核失败';
}

/**
 * 按运行编号读取已成功同步的可信委外订单记录。
 *
 * @throws RuntimeException
 */
function outsourceOrderLoadSyncRecord(int $runId): array
{
    $record = DB::table('u8_openapi_sync_order')
        ->where('run_id', $runId)
        ->where('order_type', 'outsourceOrder')
        ->where('flow_id', 31)
        ->where('sync_result', 1)
        ->first(['app_id', 'order_no', 'run_id']);

    if ($record === null) {
        throw new RuntimeException('未找到可信同步记录');
    }

    return (array) $record;
}

/**
 * 校验流程已正常办结，拒绝运行中、未写办结时间、已作废或无效的流程。
 *
 * @throws RuntimeException
 */
function outsourceOrderValidateCompletedFlow(array $flow): array
{
    $currentStep = $flow['current_step'] ?? null;
    $transactTime = $flow['transact_time'] ?? null;
    $isCancel = $flow['is_cancel'] ?? null;
    $isEffect = $flow['is_effect'] ?? null;

    $hasCompletedStep = (is_int($currentStep) || is_string($currentStep))
        && (string) $currentStep === '0';
    $hasTransactTime = is_scalar($transactTime) && trim((string) $transactTime) !== '';
    $isNotCancelled = (is_int($isCancel) || is_string($isCancel))
        && (string) $isCancel === '0';
    $isEffective = (is_int($isEffect) || is_string($isEffect))
        && (string) $isEffect === '1';

    if (!$hasCompletedStep || !$hasTransactTime || !$isNotCancelled || !$isEffective) {
        throw new RuntimeException('委外流程尚未有效办结');
    }

    return $flow;
}

/**
 * 按运行编号联查流程和委外表单，排除已删除数据并验证流程终态。
 *
 * @throws RuntimeException
 */
function outsourceOrderLoadFormRecord(int $runId): array
{
    $record = DB::table('flow_run')
        ->join('form_data_429', 'flow_run.data_id', '=', 'form_data_429.data_id')
        ->where('flow_run.run_id', $runId)
        ->where('flow_run.flow_id', 31)
        ->whereNull('flow_run.deleted_at')
        ->whereNull('form_data_429.deleted_at')
        ->first([
            'flow_run.flow_id',
            'flow_run.current_step',
            'flow_run.transact_time',
            'flow_run.is_cancel',
            'flow_run.is_effect',
            'form_data_429.DATA_236',
            'form_data_429.DATA_162',
            'form_data_429.DATA_263',
            'form_data_429.DATA_237',
        ]);

    if ($record === null) {
        throw new RuntimeException('未找到有效委外表单');
    }

    return outsourceOrderValidateCompletedFlow((array) $record);
}

/**
 * 从 HTTP POST 表单或 JSON 中仅提取回调信封字段。
 * “流程外发 → 来自文件”实际为 OA 发起的表单 POST，不是 PHP include。
 *
 * @throws InvalidArgumentException
 */
function outsourceOrderParseRequest(
    string $method,
    string $contentType,
    array $post,
    string $body
): array
{
    if (strtoupper($method) !== 'POST') {
        throw new InvalidArgumentException('仅接受 POST 回调');
    }

    $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
    $request = $post;

    if ($mediaType === 'application/json') {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('JSON 回调格式无效');
        }

        $request = $decoded;
    }

    return [
        'flow_id' => $request['flow_id'] ?? null,
        'run_id' => $request['run_id'] ?? null,
    ];
}

/**
 * 读取 OA“来自文件”发起的 HTTP POST，不接受查询参数或 Cookie 回退。
 *
 * @throws InvalidArgumentException
 */
function outsourceOrderReadRequest(): array
{
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
    $body = '';

    if ($mediaType === 'application/json') {
        $input = file_get_contents('php://input');
        $body = $input === false ? '' : $input;
    }

    return outsourceOrderParseRequest($method, $contentType, $_POST, $body);
}

/**
 * 执行 OA“流程外发 → 来自文件”的委外原单审核。
 */
function outsourceOrderRunFileOutsend(): void
{
    try {
        $runId = outsourceOrderValidateEnvelope(outsourceOrderReadRequest());
        $sync = outsourceOrderLoadSyncRecord($runId);
        $form = outsourceOrderLoadFormRecord($runId);
        $context = outsourceOrderBuildAuditContext($sync, $form);

        $service = new PurchaseorderService($context['app_id']);
        $result = $service->audit(['voucher_code' => $context['voucher_code']]);

        if ((is_array($result) && (string) ($result['errcode'] ?? '') === '0')
            || outsourceOrderAlreadyAudited($result)) {
            echo '1';
            return;
        }

        http_response_code(400);
        echo '400:' . outsourceOrderSafeError($result);
    } catch (Throwable $throwable) {
        http_response_code(400);
        echo '400:' . outsourceOrderSafeError($throwable->getMessage());
    }
}

if (!defined('OUTSOURCE_ORDER_OUTSEND_TEST_MODE')) {
    require __DIR__ . '/../../../bootstrap/app.php';
    outsourceOrderRunFileOutsend();
}
