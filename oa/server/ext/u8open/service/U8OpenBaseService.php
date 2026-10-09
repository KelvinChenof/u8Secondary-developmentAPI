<?php

namespace Ext\U8Open\service;

use App\EofficeApp\YonyouU8OpenApi\Services\ApiService\CommonService;

/**
 * U8Open 扩展基础服务
 * U8 扩展基础服务类
 * @package Ext\U8Open\service
 */
class U8OpenBaseService extends CommonService
{
    /**
     * 列表类请求（扩展订单状态等查询条件）
     * @param $url
     * @param $param
     * @return array
     */
    public function handleListRequest($url, $param)
    {
        $token = $this->getToken();
        if (!isset($token['token'])) {
            return ['errcode' => 1, 'errmsg' => eo_trans('integrationCenter.u8+openapi.get_token_failed')];
        }
        $params = [
            'from_account' => $this->fromAccount,
            'to_account' => $this->toAccount,
            'app_key' => $this->appKey,
            'token' => $token['token'],
        ];
        if (isset($param['name']) && !empty($param['name'])) {
            $params['name'] = urlencode($param['name']);
        }
        if (isset($param['cexch_name']) && !empty($param['cexch_name'])) {
            $params['cexch_name'] = urlencode($param['cexch_name']);
        }
        if (isset($param['cwhname']) && !empty($param['cwhname'])) {
            $params['cwhname'] = urlencode($param['cwhname']);
        }
        if (isset($param['cwhcode']) && !empty($param['cwhcode'])) {
            $params['cwhcode'] = $param['cwhcode'];
        }
        if (isset($param['rsflag'])) {
            $params['rsflag'] = $param['rsflag'];
        }
        if (isset($param['iverifystate'])) {
            $params['iverifystate'] = $param['iverifystate'];
        }
        if (isset($param['istate'])) {
            $params['istate'] = $param['istate'];
        }
        if (isset($param['whname']) && !empty($param['whname'])) {
            $params['whname'] = $param['whname'];
        }
        if (isset($param['whcode']) && !empty($param['whcode'])) {
            $param['whcode'] = str_replace(['"', '[', ']'], '', $param['whcode']);
            $params['whcode_begin'] = $param['whcode'];
            $params['whcode_end'] = $param['whcode'];
        }
        if (isset($param['invname']) && !empty($param['invname'])) {
            $params['invname'] = $param['invname'];
        }
        if (isset($param['invcode']) && !empty($param['invcode'])) {
            if (is_array($param['invcode'])) {
                $param['invcode'] = $param['invcode'][0];
            }
            $params['invcode_begin'] = $param['invcode'];
            $params['invcode_end'] = $param['invcode'];
        }
        if (isset($param['batch']) && !empty($param['batch'])) {
            $params['batch'] = $param['batch'];
        }
        if (isset($param['state']) && $param['state'] !== '') {
            $params['state'] = urlencode($param['state']);
        }
        $params['page_index'] = $param['page'] ?? 1;
        $params['rows_per_page'] = $param['limit'] ?? 10;
        $requestUrl = $this->apiUrl . $url;
        $requestUrl = $this->parseParamUrl($requestUrl, $params);
        // U8ScheduleService::logText('请求URL：' . $requestUrl);
        // U8ScheduleService::logText('请求参数：' . json_encode($params));
        return $this->request($requestUrl, self::HEADERS);
    }

    /**
     * 分页拉取全部列表数据
     * @param $url
     * @param $listKey
     * @param $param
     * @param $list
     * @return array|null
     */
    protected function fetchAllList($url, $listKey, $param, &$list)
    {
        $result = $this->handleListRequest($url, $param);
        if (!isset($result['errcode']) || $result['errcode'] !== '0') {
            return $result;
        }
        $orderList = $result[$listKey] ?? [];
        $list = array_merge($list, $orderList);
        if (($result['page_index'] ?? 1) < ($result['page_count'] ?? 1)) {
            $param['page'] = ($param['page'] ?? 1) + 1;
            return $this->fetchAllList($url, $listKey, $param, $list);
        }
        return null;
    }
}
