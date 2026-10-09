<?php

namespace Ext\U8Open\service;

use App\EofficeApp\YonyouU8OpenApi\Repositories\U8OpenApiApplicationRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class U8ScheduleService
{
    /** @var int|string|null 当前同步的 U8 应用 ID */
    protected $appId;

    const SYNC_TABLE = 'u8_openapi_sync_order';

    const INVALID_RUN_ID_REASON = 'OA建流成功但缺少有效run_id，需人工核对';

    const SERVICE_MAP = [
        'purchaseOrder' => PurchaseorderService::class,
        'outsourceOrder' => OutsourceOrderService::class,
        'payrequest' => PayrequestService::class,
    ];

    const MARKER_FIELD_KEY = [
        'venPriceAdjust' => 'cmaker',
        'payrequest' => 'coperator',
    ];

    const DETAIL_KEY_MAP = [
        'purchaseOrder' => 'purchaseorder',
        'outsourceOrder' => 'omorder',
        'payrequest' => 'payrequest',
    ];

    /**
     * 多应用入口：查询启用应用后依次执行同步任务
     */
    public function runAllApps()
    {
        $this->ensureSyncTable();

        $jobs = $this->getSyncFlowConfig();
        if (!$jobs) {
            self::logText('status=no_sync_jobs');
            return;
        }

        $apps = $this->getEnabledApplications();
        if (!$apps) {
            self::logText('status=no_enabled_apps');
            return;
        }

        foreach ($apps as $app) {
            $appId = $app['app_id'] ?? null;
            if (!$appId) {
                continue;
            }
            self::logText('app_id=' . self::normalizeLogValue($appId) . ' status=app_sync_start');
            try {
                $this->appId = $appId;
                foreach ($jobs as $job) {
                    if (empty($job['enabled'])) {
                        continue;
                    }
                    $type = $job['type'] ?? '';
                    $flowId = $job['flow_id'] ?? 0;
                    if (!$type || !$flowId) {
                        self::logText('app_id=' . self::normalizeLogValue($appId) . ' status=invalid_job_config');
                        continue;
                    }
                    $allowAppIds = $job['app_ids'] ?? [];
                    if ($allowAppIds && !in_array($appId, $allowAppIds, false) && !in_array((string) $appId, array_map('strval', $allowAppIds), true)) {
                        continue;
                    }
                    $extra = $job['extra'] ?? [];
                    $extra['app_id'] = $appId;
                    $this->scheduleOaFlow($type, $flowId, $job['param'] ?? [], $extra);
                }
            } catch (\Exception $e) {
                self::logText(
                    'app_id=' . self::normalizeLogValue($appId)
                    . ' status=app_sync_error error=' . self::safeErrorSummary($e)
                );
            }
            self::logText('app_id=' . self::normalizeLogValue($appId) . ' status=app_sync_end');
        }
    }

    /**
     * 查询启用的 U8 OpenAPI 应用
     */
    public function getEnabledApplications()
    {
        try {
            return app(U8OpenApiApplicationRepository::class)->getList([
                'page' => 0,
                'limit' => 9999,
                'search' => [
                    'enabled_toggle' => [1, '='],
                ],
                'order_by' => ['app_id' => 'asc'],
            ]);
        } catch (\Exception $e) {
            self::logText('status=application_query_error error=' . self::safeErrorSummary($e));
            return [];
        }
    }

    /**
     * 采购订单定时获取创建流程
     * 1.批量获取待办采购订单
     * 2.根据订单号获取订单详情
     * 3.匹配流程表单字段，创建流程
     */
    public function scheduleOaFlow($type, $flowId, $param, $extra = [])
    {
        if (!empty($extra['app_id'])) {
            $this->appId = $extra['app_id'];
        }
        if ($type === 'outsourceOrder') {
            self::checkOutsourceSyncContext($this->getOmConfig(), $this->appId, $flowId);
        }
        $checkVerify = [
            'check' => $extra['checkVerify'] ?? false,
            'field' => $extra['checkVerifyField'] ?? '',
        ];
        $codeField = $extra['codeField'] ?? 'code';
        $detailFilters = is_array($extra['detailFilters'] ?? null) ? $extra['detailFilters'] : [];
        $excludeDetailFilters = is_array($extra['excludeDetailFilters'] ?? null) ? $extra['excludeDetailFilters'] : [];
        $forceCodeFromList = !empty($extra['forceCodeFromList']);
        $this->logSyncSummary('sync_start', $type, $flowId);

        $orderIds = $this->getOrder($type, $flowId, $param, $checkVerify, $codeField);
        if ($orderIds === false) {
            $this->logSyncSummary('list_failed', $type, $flowId);
            return false;
        }
        if (!$orderIds) {
            $this->logSyncSummary('list_empty', $type, $flowId);
            return false;
        }

        $flowConfigFile = __DIR__ . '/../config/flow/' . $flowId . '.ini';
        if (!file_exists($flowConfigFile)) {
            $this->logSyncSummary('flow_config_missing', $type, $flowId);
            return false;
        }
        $flowConfig = parse_ini_file($flowConfigFile, true);
        if (!$flowConfig) {
            $this->logSyncSummary('flow_config_invalid', $type, $flowId);
            return false;
        }

        $this->removeSyncedOrder($type, $flowId, $flowConfig, $orderIds);
        if (!$orderIds) {
            $this->logSyncSummary('all_orders_synced', $type, $flowId);
            return false;
        }

        $openApiService = $this->createOpenApiService();
        if ($type === 'outsourceOrder' && !self::hasOutsourceUniqueIndex(
            DB::select('SHOW INDEX FROM u8_openapi_sync_order')
        )) {
            throw new \RuntimeException('OM_SYNC_UNIQUE_INDEX_REQUIRED');
        }
        foreach ($orderIds as $orderId) {
            $outsourceClaimed = false;
            try {
                $detail = $this->getDetail($orderId, $type);
                if (isset($detail['errcode']) && (string) $detail['errcode'] !== '0') {
                    $this->logSyncSummary('detail_failed', $type, $flowId, $orderId, null, $detail);
                    continue;
                }
                $detailKey = self::DETAIL_KEY_MAP[$type] ?? strtolower($type);
                $orderDetail = $detail[$detailKey] ?? [];
                if (!$orderDetail) {
                    $failReason = '订单详情数据为空';
                    $this->logSyncSummary('detail_empty', $type, $flowId, $orderId, null, ['message' => $failReason]);
                    if ($type !== 'outsourceOrder') {
                        $this->saveSyncedOrder($type, $flowId, $orderId, null, 0, $failReason);
                    }
                    continue;
                }

                if (!self::matchesDetailFilters($orderDetail, $detailFilters)) {
                    $this->logSyncSummary('detail_filtered', $type, $flowId, $orderId);
                    continue;
                }
                if ($excludeDetailFilters && self::matchesDetailFilters($orderDetail, $excludeDetailFilters)) {
                    $this->logSyncSummary('detail_excluded', $type, $flowId, $orderId);
                    continue;
                }
                if ($type === 'outsourceOrder') {
                    if (($orderDetail['state'] ?? null) !== '开立') {
                        $this->logSyncSummary('outsource_not_open', $type, $flowId, $orderId);
                        continue;
                    }
                    $orderDetail = self::prepareOutsourceOrderDetail($orderDetail, (string) $orderId, $forceCodeFromList);
                }

                // 付款申请：详情常用 cvouchid / coperator，映射为调度与外发统一的 code / maker
                if ($type === 'payrequest') {
                    $codeVal = $orderDetail['code'] ?? '';
                    if (($codeVal === '' || $codeVal === null) && isset($orderDetail['cvouchid']) && $orderDetail['cvouchid'] !== '' && $orderDetail['cvouchid'] !== null) {
                        $orderDetail['code'] = $orderDetail['cvouchid'];
                    }
                    $makerVal = $orderDetail['maker'] ?? '';
                    if (($makerVal === '' || $makerVal === null) && isset($orderDetail['coperator']) && $orderDetail['coperator'] !== '' && $orderDetail['coperator'] !== null) {
                        $orderDetail['maker'] = $orderDetail['coperator'];
                    }
                }

                $markerField = self::MARKER_FIELD_KEY[$type] ?? 'maker';
                $maker = $type === 'outsourceOrder'
                    ? self::resolveUserId($orderDetail[$markerField] ?? '')
                    : self::getUser($orderDetail[$markerField] ?? '');
                if ($type === 'outsourceOrder' && !$maker) {
                    throw new \RuntimeException('OM_MAKER_NOT_MAPPED');
                }
                if ($maker) {
                    $openApiService->initUser($maker);
                }
                $orderDetail['app_id'] = $this->appId;
                $requestData = $this->buildFormData($type, $orderDetail, $flowId, $flowConfig);
                if ($type === 'outsourceOrder') {
                    // 建流前先持久化领取，崩溃或超时后不能重复创建流程。
                    $outsourceClaimed = $this->claimOutsourceOrder($flowId, (string) $orderId);
                    if (!$outsourceClaimed) {
                        $this->logSyncSummary('outsource_already_claimed', $type, $flowId, $orderId);
                        continue;
                    }
                }
                $res = $openApiService->createFlow($requestData);
                if ($res && isset($res['status']) && $res['status'] == 1) {
                    $runId = self::normalizeRunIdFromCreateResponse($res);
                    if ($runId === null) {
                        $this->logSyncSummary(
                            'flow_create_missing_run_id',
                            $type,
                            $flowId,
                            $orderId,
                            null,
                            ['message' => self::INVALID_RUN_ID_REASON]
                        );
                        $this->saveSyncedOrder(
                            $type,
                            $flowId,
                            $orderId,
                            null,
                            0,
                            self::INVALID_RUN_ID_REASON
                        );
                        continue;
                    }

                    $this->logSyncSummary('flow_created', $type, $flowId, $orderId, $runId);
                    $this->saveSyncedOrder($type, $flowId, $orderId, $runId, 1, '');
                } else {
                    $this->logSyncSummary('flow_create_failed', $type, $flowId, $orderId, null, $res);
                }
            } catch (\Exception $exception) {
                $safeException = self::safeErrorSummary($exception);
                $this->logSyncSummary('sync_exception', $type, $flowId, $orderId, null, ['message' => $safeException]);
                if ($type === 'outsourceOrder') {
                    // 不覆盖其他工作进程的领取记录，也不覆盖本次未知或成功的结果。
                    continue;
                }
                $this->saveSyncedOrder($type, $flowId, $orderId, null, 0, ['message' => $safeException]);
                continue;
            }
        }
        $this->logSyncSummary('sync_end', $type, $flowId);
        return true;
    }

    /**
     * 拉取订单列表并提取订单号
     */
    public function getOrder($type, $flowId, $param, $checkVerify, $codeField)
    {
        try {
            $service = $this->resolveU8Service($type);
            $list = $service->getList($param);
            if (!is_array($list)) {
                return false;
            }
            if (isset($list['errcode'])) {
                $this->logSyncSummary('list_api_error', $type, $flowId, '', null, $list);
                return false;
            }
            $orderIds = [];
            foreach ($list as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = $row[$codeField] ?? ($row['code'] ?? '');
                if ($code !== '' && $code !== null) {
                    $orderIds[] = (string) $code;
                }
            }
            return array_values(array_unique($orderIds));
        } catch (\Exception $e) {
            $this->logSyncSummary('list_exception', $type, $flowId, '', null, $e);
            return false;
        }
    }

    /**
     * 按订单号获取详情
     */
    public function getDetail($orderId, $type)
    {
        $service = $this->resolveU8Service($type);
        return $service->getDetail(['id' => $orderId]);
    }

    /**
     * 按类型解析 U8 服务（绑定当前 appId）
     */
    protected function resolveU8Service($type)
    {
        if (!$this->appId) {
            throw new \Exception('未指定U8应用app_id');
        }
        $class = self::SERVICE_MAP[$type] ?? null;
        if (!$class) {
            throw new \Exception('不支持的订单类型：' . $type);
        }
        return new $class($this->appId);
    }

    public static function matchesDetailFilters(array $orderDetail, array $filters): bool
    {
        foreach ($filters as $field => $expectedValue) {
            if (!array_key_exists($field, $orderDetail) || (string) $orderDetail[$field] !== (string) $expectedValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * 创建 OA OpenAPI 服务；保留生产调用接口，并提供最小测试替换点。
     */
    protected function createOpenApiService()
    {
        return new OpenApiService();
    }

    /**
     * 从 OA 建流成功响应中取得正整数运行编号。
     */
    public static function normalizeRunIdFromCreateResponse($response): ?int
    {
        if (!is_array($response) || (string) ($response['status'] ?? '') !== '1') {
            return null;
        }

        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        foreach (['run_id', 'flow_run_id'] as $runIdKey) {
            $value = $data[$runIdKey] ?? null;
            if (is_int($value)) {
                if ($value > 0) {
                    return $value;
                }

                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $digits = trim($value);
            if (preg_match('/\A[0-9]+\z/D', $digits) !== 1) {
                continue;
            }

            $digits = ltrim($digits, '0');
            if ($digits === '') {
                continue;
            }

            $maxInteger = (string) PHP_INT_MAX;
            if (strlen($digits) > strlen($maxInteger)
                || (strlen($digits) === strlen($maxInteger) && strcmp($digits, $maxInteger) > 0)) {
                continue;
            }

            return (int) $digits;
        }

        return null;
    }

    public static function prepareOutsourceOrderDetail(array $orderDetail, string $listCode, bool $forceCode): array
    {
        if ($listCode === '' || ($orderDetail['code'] ?? null) !== $listCode) {
            throw new \InvalidArgumentException('委外订单列表与详情编号不一致');
        }

        $orderDetail['outsource_flag'] = '委外';
        $orderDetail['maker_raw'] = (string) ($orderDetail['maker'] ?? '');
        $orderDetail['maker_code'] = (string) ($orderDetail['maker_code']
            ?? $orderDetail['makercode']
            ?? $orderDetail['cmakercode']
            ?? '');

        return $orderDetail;
    }

    public static function getUser($maker)
    {
        if ($maker === null || $maker === '') {
            return '';
        }
        $maker = (string) $maker;
        if ($maker == 'demo') {
            return 'admin';
        }
        if (substr($maker, -1) === '$') {
            $maker = rtrim($maker, '$');
        }
        return $maker;
    }

    /**
     * 将 U8 制单人（姓名/账号/user_id）解析为 OA user_id
     */
    public static function resolveUserId($maker)
    {
        $maker = self::getUser($maker);
        if ($maker === '') {
            return '';
        }
        $user = DB::table('user_infos')
            ->whereNull('deleted_at')
            ->where(function ($query) use ($maker) {
                $query->where('user_id', $maker)
                    ->orWhere('user_accounts', $maker)
                    ->orWhere('user_name', $maker);
            })
            ->first(['user_id']);
        return $user ? (string) $user->user_id : '';
    }

    /**
     * 确保同步记录表存在，并补齐新增字段
     */
    public function ensureSyncTable()
    {
        if (!has_table(self::SYNC_TABLE)) {
            Schema::create(self::SYNC_TABLE, function (Blueprint $table) {
                $table->increments('id')->comment('主键');
                $table->unsignedInteger('app_id')->comment('U8应用ID');
                $table->string('order_type', 64)->comment('订单类型，如 purchaseOrder');
                $table->string('order_no', 100)->comment('U8订单号');
                $table->unsignedInteger('flow_id')->default(0)->comment('OA流程ID');
                $table->unsignedInteger('run_id')->nullable()->default(null)->comment('创建成功的流程run_id');
                $table->tinyInteger('sync_result')->default(0)->comment('同步结果：1成功 0失败');
                $table->text('fail_reason')->nullable()->comment('失败原因');
                $table->dateTime('sync_time')->comment('同步时间');
                $table->timestamps();
                $table->unique(['app_id', 'order_type', 'order_no', 'flow_id'], 'uk_app_type_no_flow');
                $table->index(['app_id', 'order_type'], 'idx_app_type');
                $table->index(['sync_result'], 'idx_sync_result');
            });
            return;
        }
    }

    /**
     * 从待同步列表中剔除已有同步记录的订单。
     * 若成功记录的 run_id 在 flow_run 中已不存在或已软删除，则清除同步记录并允许重新创建流程。
     */
    public function removeSyncedOrder($type, $flowId, $flowConfig, &$orderIds)
    {
        if (!$orderIds || !$this->appId) {
            return;
        }
        $this->ensureSyncTable();
        $syncedRows = DB::table(self::SYNC_TABLE)
            ->where('app_id', $this->appId)
            ->where('order_type', $type)
            ->where('flow_id', $flowId)
            ->whereIn('order_no', $orderIds)
            ->get(['order_no', 'run_id', 'sync_result']);
        if ($syncedRows->isEmpty()) {
            return;
        }
        if ($type === 'outsourceOrder') {
            // 委外流程被删除或领取未完成时，仍保留单据防重记录。
            $orderIds = array_values(array_diff($orderIds, $syncedRows->pluck('order_no')->map(function ($value) {
                return (string) $value;
            })->toArray()));
            return;
        }

        $runIds = $syncedRows->pluck('run_id')->filter(function ($v) {
            return $v !== null && $v !== '' && (int) $v > 0;
        })->map(function ($v) {
            return (int) $v;
        })->unique()->values()->toArray();

        // flow_run 软删除：仅 whereNull(deleted_at) 视为仍有效
        $aliveRunIds = [];
        if ($runIds) {
            $aliveRunIds = DB::table('flow_run')
                ->whereIn('run_id', $runIds)
                ->whereNull('deleted_at')
                ->pluck('run_id')
                ->map(function ($v) {
                    return (int) $v;
                })
                ->flip()
                ->toArray();
        }

        $skipOrderNos = [];
        $staleOrderNos = [];
        foreach ($syncedRows as $row) {
            $orderNo = (string) $row->order_no;
            $runId = (int) ($row->run_id ?? 0);
            // 成功同步且绑定了 run_id，但运行流程已删 → 清旧记录、允许重建
            if ((int) $row->sync_result === 1 && $runId > 0 && !isset($aliveRunIds[$runId])) {
                $staleOrderNos[] = $orderNo;
                continue;
            }
            $skipOrderNos[] = $orderNo;
        }

        if ($staleOrderNos) {
            $staleOrderNos = array_values(array_unique($staleOrderNos));
            DB::table(self::SYNC_TABLE)
                ->where('app_id', $this->appId)
                ->where('order_type', $type)
                ->where('flow_id', $flowId)
                ->whereIn('order_no', $staleOrderNos)
                ->delete();
            foreach ($staleOrderNos as $staleOrderNo) {
                $this->logSyncSummary('stale_sync_removed', $type, $flowId, $staleOrderNo);
            }
        }

        if ($skipOrderNos) {
            $orderIds = array_values(array_diff($orderIds, array_unique($skipOrderNos)));
        }
    }

    /**
     * 写入同步记录
     * @param int $syncResult 1成功 0失败
     * @param string $failReason 失败原因
     */
    public function saveSyncedOrder($type, $flowId, $orderId, $runId = null, $syncResult = 1, $failReason = '')
    {
        $this->ensureSyncTable();
        $orderId = (string) $orderId;
        $now = date('Y-m-d H:i:s');
        $failReason = $syncResult == 1 ? '' : self::safeErrorSummary($failReason);
        $data = [
            'run_id' => $runId,
            'sync_result' => (int) $syncResult,
            'fail_reason' => $syncResult == 1 ? '' : $failReason,
            'sync_time' => $now,
            'updated_at' => $now,
        ];
        $exists = DB::table(self::SYNC_TABLE)
            ->where('app_id', $this->appId)
            ->where('order_type', $type)
            ->where('order_no', $orderId)
            ->where('flow_id', $flowId)
            ->exists();
        if ($exists) {
            DB::table(self::SYNC_TABLE)
                ->where('app_id', $this->appId)
                ->where('order_type', $type)
                ->where('order_no', $orderId)
                ->where('flow_id', $flowId)
                ->update($data);
            return;
        }
        DB::table(self::SYNC_TABLE)->insert(array_merge($data, [
            'app_id' => $this->appId,
            'order_type' => $type,
            'order_no' => $orderId,
            'flow_id' => $flowId,
            'created_at' => $now,
        ]));
    }

    protected function claimOutsourceOrder($flowId, string $orderId): bool
    {
        if ((int) $flowId !== 2001 || !$this->appId) {
            throw new \RuntimeException('OM_CLAIM_IDENTITY_INVALID');
        }
        $now = date('Y-m-d H:i:s');
        try {
            // 使用既有唯一索引 uk_app_type_no_flow 防止多个进程重复领取。
            return DB::table(self::SYNC_TABLE)->insert([
                'app_id' => $this->appId, 'order_type' => 'outsourceOrder', 'order_no' => $orderId,
                'flow_id' => 2001, 'run_id' => null, 'sync_result' => 0,
                'fail_reason' => 'OM_CREATE_CLAIMED_REQUIRES_REVIEW', 'sync_time' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (\Throwable $failure) {
            $this->logSyncSummary('outsource_claim_failed_or_exists', 'outsourceOrder', 2001, $orderId);
            return false;
        }
    }

    public static function hasOutsourceUniqueIndex(array $indexes): bool
    {
        $columns = [];
        foreach ($indexes as $row) {
            $row = (array) $row;
            if (($row['Key_name'] ?? '') !== 'uk_app_type_no_flow') continue;
            if ((string) ($row['Non_unique'] ?? '') !== '0' || ($row['Sub_part'] ?? null) !== null) return false;
            $position = (int) ($row['Seq_in_index'] ?? 0);
            if ($position < 1 || isset($columns[$position])) return false;
            $columns[$position] = $row['Column_name'] ?? '';
        }
        ksort($columns);
        return array_values($columns) === ['app_id', 'order_type', 'order_no', 'flow_id'];
    }

    public function buildFormData($type, $orderDetail, $flowId, $flowConfig)
    {
        $mainFields = $flowConfig['u8MainField'] ?? [];
        $entryFields = $flowConfig['u8EntryField'] ?? [];
        if (!$orderDetail || !$mainFields || !$entryFields) {
            throw new \Exception('订单信息、U8字段配置异常！');
        }
        $formData = [];
        foreach ($mainFields as $mainKey => $mainField) {
            if ($mainField) {
                $mainFieldArray = explode(',', $mainField);
                foreach ($mainFieldArray as $item) {
                    $formData[$item] = $orderDetail[$mainKey] ?? '';
                    if (!$formData[$item] && $mainKey == 'currency_name') {
                        $formData[$item] = '人民币';
                    }
                    if (!$formData[$item] && $mainKey == 'currency_rate') {
                        $formData[$item] = 1;
                    }
                }
            }
        }
        $detailField = $entryFields['detailField'] ?? '';
        if (!$detailField) {
            throw new \Exception('U8表体明细字段配置异常！');
        }
        $entryDatas = $orderDetail['entry'] ?? [];
        if (!$entryDatas) {
            throw new \Exception('U8表体数据异常！');
        }
        foreach ($entryDatas as $entryData) {
            $entry = [];
            foreach ($entryFields as $entryKey => $entryField) {
                if ($entryKey == 'detailField') {
                    continue;
                }
                if ($entryField) {
                    $entryFieldArray = explode(',', $entryField);
                    foreach ($entryFieldArray as $item) {
                        $entry[$item] = $entryData[$entryKey] ?? '';
                        if (in_array($entryKey, ['dpremodatebt', 'dpredatebt']) && !$entry[$item]) {
                            $entry[$item] = $orderDetail[$entryKey] ?? '';
                        }
                    }
                }
            }
            $formData[$detailField][] = $entry;
        }
        $flowRunName = self::buildRunName($type, $flowId, $mainFields, $entryFields, $formData);
        // maker 需要替换为对应人员的用户ID（OA 人员控件存 user_id）
        $markerField = self::MARKER_FIELD_KEY[$type] ?? 'maker';
        $makerFieldConfig = $mainFields[$markerField] ?? ($mainFields['maker'] ?? '');
        if ($makerFieldConfig) {
            $userId = self::resolveUserId($orderDetail[$markerField] ?? '');
            if ($userId) {
                foreach (explode(',', $makerFieldConfig) as $field) {
                    $field = trim($field);
                    if ($field !== '') {
                        $formData[$field] = $userId;
                    }
                }
            }
        }

        return ['form_data' => $formData, 'flow_id' => $flowId, 'flow_run_name' => $flowRunName];
    }

    public static function buildRunName($type, $flowId, $mainFields, $entryFields, $formData)
    {
        $markerField = self::MARKER_FIELD_KEY[$type] ?? 'maker';
        $flowNameKey = DB::table('flow_type')->where('flow_id', $flowId)->value('flow_name');
        $flowName = mulit_trans_dynamic('flow_type.flow_name.' . $flowNameKey);
        switch ($type) {
            case 'purchaseOrder':
            case 'outsourceOrder':
                $vendorname = $formData[$mainFields['vendorname'] ?? ''] ?? '';
                $details = $formData[$entryFields['detailField']] ?? [];
                $total = array_sum(array_column($details, $entryFields['sum'] ?? ''));
                $maker = $formData[$mainFields[$markerField] ?? ''] ?? '';
                return $flowName . '+' . $vendorname . '+' . $total . '+' . $maker;
            case 'saleOrder':
                $cusname = $formData[$mainFields['cusabbname'] ?? ''] ?? '';
                $details = $formData[$entryFields['detailField']] ?? [];
                $total = array_sum(array_column($details, $entryFields['sum'] ?? ''));
                $maker = $formData[$mainFields[$markerField] ?? ''] ?? '';
                return $flowName . '+' . $cusname . '+' . $total . '+' . $maker;
            case 'venPriceAdjust':
                $maker = $formData[$mainFields[$markerField] ?? ''] ?? '';
                return $flowName . '+' . $maker;
            case 'payrequest':
                $vendorname = $formData[$mainFields['cvenname'] ?? ($mainFields['vendorname'] ?? '')] ?? '';
                $details = $formData[$entryFields['detailField'] ?? ''] ?? [];
                $sumField = $entryFields['iapplyamt_f'] ?? ($entryFields['sum'] ?? '');
                $total = $sumField ? array_sum(array_column($details, $sumField)) : '';
                $maker = $formData[$mainFields[$markerField] ?? ($mainFields['maker'] ?? '')] ?? '';
                if ($vendorname !== '' || $total !== '' || $maker !== '') {
                    return $flowName . '+' . $vendorname . '+' . $total . '+' . $maker;
                }
                return $flowName . '+' . $maker;
            default:
                $maker = $formData[$mainFields[$markerField] ?? ''] ?? '';
                return $flowName . '+' . $maker;
        }
    }

    /**
     * 读取 schedule.php 完整配置
     */
    public function getScheduleConfig()
    {
        $file = __DIR__ . '/../config/schedule.php';
        if (!file_exists($file)) {
            return [];
        }
        $config = include $file;
        return is_array($config) ? $config : [];
    }

    /**
     * 读取同步任务列表
     */
    public function getSyncFlowConfig()
    {
        $config = $this->getScheduleConfig();
        $jobs = $config['jobs'] ?? [];
        return self::configureOutsourceJobs(is_array($jobs) ? $jobs : [], $this->getOmConfig());
    }

    protected function getOmConfig(): array
    {
        return require __DIR__ . '/../config/om-api.php';
    }

    public static function checkOutsourceSyncContext(array $config, $appId, $flowId): void
    {
        if (($config['sync_enabled'] ?? false) !== true || ($config['read_enabled'] ?? false) !== true) {
            throw new \RuntimeException('OM_SYNC_DISABLED');
        }
        $id = filter_var($appId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id || $id !== ($config['app_id'] ?? 0) || (int) $flowId !== 2001) {
            throw new \RuntimeException('OM_SYNC_IDENTITY_INVALID');
        }
    }

    // 保留采购及付款任务的配置数组，不改动 schedule.php。
    public static function configureOutsourceJobs(array $jobs, array $config): array
    {
        $result = []; $added = false;
        $enabled = ($config['sync_enabled'] ?? false) === true && ($config['read_enabled'] ?? false) === true
            && is_int($config['app_id'] ?? null) && $config['app_id'] > 0;
        foreach ($jobs as $job) {
            if (($job['type'] ?? '') !== 'outsourceOrder') { $result[] = $job; continue; }
            if ($added) continue;
            $added = true;
            $job['enabled'] = $enabled;
            $job['flow_id'] = 2001;
            $job['app_ids'] = $enabled ? [$config['app_id']] : [];
            $job['param'] = ['state' => '开立', 'limit' => 100];
            $job['extra'] = ['codeField' => 'code', 'detailFilters' => ['operation_type_code' => '委外加工', 'state' => '开立']];
            $result[] = $job;
        }
        return $result;
    }

    /** 仅预览字段，不调用调度、建流、令牌获取或同步写入。 */
    public function previewOutsourceOrder(int $appId, string $code): array
    {
        $config = $this->getOmConfig();
        if ($appId < 1 || $appId !== ($config['app_id'] ?? 0) || ($config['read_enabled'] ?? false) !== true) {
            throw new \RuntimeException('OM_PREVIEW_CONFIG_INVALID');
        }
        $this->appId = $appId;
        $detail = $this->getDetail(OutsourceOrderService::orderCode($code), 'outsourceOrder');
        $order = self::prepareOutsourceOrderDetail($detail['omorder'] ?? [], $code, false);
        $order['app_id'] = $appId;
        $maker = self::resolveUserId($order['maker'] ?? '');
        $existing = DB::table(self::SYNC_TABLE)->where('app_id', $appId)->where('order_type', 'outsourceOrder')
            ->where('order_no', $code)->where('flow_id', 2001)->exists();
        $reason = ($order['state'] ?? null) !== '开立' ? 'OM_ORDER_NOT_OPEN'
            : ($existing ? 'OM_ALREADY_SYNCED_OR_CLAIMED' : (!$maker ? 'OM_MAKER_NOT_MAPPED' : 'READY_FOR_SYNC'));
        $mapped = $this->buildFormData('outsourceOrder', $order, 2001, parse_ini_file(__DIR__ . '/../config/flow/2001.ini', true));
        return ['code' => $code, 'app_id' => $appId, 'flow_id' => 2001, 'reason' => $reason,
            'maker_raw' => $order['maker_raw'], 'oa_maker_id' => $maker, 'existing_sync_record' => $existing,
            'mapped_main_fields' => count($mapped['form_data']) - 1, 'detail_rows' => count($mapped['form_data']['DATA_1002']),
            'flow_created' => false, 'audit_attempted' => false];
    }

    /**
     * 生成同步日志和失败记录可使用的安全错误摘要。
     *
     * @param mixed $value
     */
    public static function safeErrorSummary($value): string
    {
        if ($value === self::INVALID_RUN_ID_REASON) {
            return self::INVALID_RUN_ID_REASON;
        }

        if ($value instanceof \Throwable) {
            $value = ['message' => $value->getMessage()];
        }

        if (self::containsSensitiveMarker($value)) {
            return '同步失败（敏感信息已隐藏）';
        }

        if (is_array($value)) {
            foreach (['errmsg', 'message', 'msg', 'errors'] as $messageKey) {
                if (!array_key_exists($messageKey, $value)) {
                    continue;
                }

                $message = $value[$messageKey];
                if (is_string($message) || is_numeric($message)) {
                    return self::normalizeErrorMessage((string) $message);
                }
            }
        }

        return '同步失败';
    }

    /**
     * @param mixed $value
     */
    private static function containsSensitiveMarker($value): bool
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (self::containsSensitiveMarker((string) $key)
                    || self::containsSensitiveMarker($item)) {
                    return true;
                }
            }

            return false;
        }

        if (!is_scalar($value) && $value !== null) {
            return true;
        }

        $text = (string) $value;
        $trimmedText = trim($text);
        if ($trimmedText !== ''
            && ((str_starts_with($trimmedText, '{') && str_ends_with($trimmedText, '}'))
                || (str_starts_with($trimmedText, '[') && str_ends_with($trimmedText, ']'))
                || str_starts_with($trimmedText, '\\\\'))) {
            return true;
        }

        return preg_match(
            '~password|token|secret|authorization|bearer|app[_-]?key|api[_-]?key|cookie|session[\s_-]*id|credentials?|form[\s_-]*data|\bdata_[a-z0-9_]+\b|https?://|stack\s+trace|sqlstate\b|\bselect\b[\s\S]*\bfrom\b|\binsert\s+into\b|\bupdate\b[\s\S]*\bset\b|\bdelete\s+from\b|(?:^|[\s(=:])/(?:var|etc|usr|home|opt|srv|tmp|root|proc|sys|dev)(?:/|\b)|<\s*/?\s*[a-z][^>]*>|[a-z]:\\\\~i',
            $text
        ) === 1;
    }

    private static function normalizeErrorMessage(string $message): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]+/u', '', $message) ?? '';
        $message = trim($message);
        if ($message === '') {
            return '同步失败';
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
     * 清理日志标识字段，避免换行注入并限制长度。
     *
     * @param mixed $value
     */
    private static function normalizeLogValue($value): string
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }

        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', (string) $value) ?? '';
        $value = trim($value);

        if (strlen($value) <= 100) {
            return $value;
        }

        if (function_exists('mb_strcut')) {
            return mb_strcut($value, 0, 100, 'UTF-8');
        }

        return substr($value, 0, 100);
    }

    /**
     * 仅写入同步定位字段和固定状态，不记录业务载荷。
     *
     * @param mixed $orderNo
     * @param mixed $runId
     * @param mixed $error
     */
    private function logSyncSummary(
        string $status,
        string $type = '',
        $flowId = 0,
        $orderNo = '',
        $runId = null,
        $error = null
    ): void {
        $fields = [
            'app_id=' . self::normalizeLogValue($this->appId),
            'type=' . self::normalizeLogValue($type),
            'order_no=' . self::normalizeLogValue($orderNo),
            'flow_id=' . self::normalizeLogValue($flowId),
            'status=' . self::normalizeLogValue($status),
        ];

        if ($runId !== null && $runId !== '') {
            $fields[] = 'run_id=' . self::normalizeLogValue($runId);
        }
        if ($error !== null) {
            $fields[] = 'error=' . self::safeErrorSummary($error);
        }

        self::logText(implode(' ', $fields));
    }

    public static function logText($data)
    {
        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $file = $dir . '/' . date('Ymd') . '.log';
        if (is_array($data) || is_object($data)) {
            $data = self::safeErrorSummary($data);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $data . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND);
    }
}
