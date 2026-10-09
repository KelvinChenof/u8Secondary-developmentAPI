<?php

namespace Ext\U8Open\service;

/**
 * 付款申请单
 * 付款申请服务类
 * @package Ext\U8Open\service
 */
class PayrequestService extends U8OpenBaseService
{
    const PAYREQUESTLIST = 'api/payrequestlist/batch_get';
    const PAYREQUESTDETAIL = 'api/payrequest/get';
    const AUDITPAYREQUEST = 'api/payrequest/audit';
    const LIST_KEY = 'payrequestlist';

    /**
     * 获取列表数据
     * @param $param
     * @return array
     */
    public function getList($param)
    {
        $list = [];
        $error = $this->fetchAllList(self::PAYREQUESTLIST, self::LIST_KEY, $param, $list);
        if ($error !== null) {
            return $error;
        }
        return $list;
    }

    /**
     * 获取详情
     * @param $param
     * @return array
     */
    public function getDetail($param)
    {
        return $this->baseDetail($param, self::PAYREQUESTDETAIL);
    }

    /**
     * 审核付款申请单（调用 api/payrequest/audit）
     * @param array $param 审核参数：voucher_code、agree、user_id 等
     * @param array $extra 登录扩展：userId、password
     * @return array
     */
    public function audit($param, $extra = [])
    {
        if (!$param || !is_array($param)) {
            return ['errcode' => 1, 'errmsg' => '审核参数为空'];
        }
        if (!isset($param['payrequest'])) {
            $param = ['payrequest' => $param];
        }
        $order = $param['payrequest'];
        if (empty($order['voucher_code'])) {
            return ['errcode' => 1, 'errmsg' => '缺少单据编号 voucher_code'];
        }
        if (!isset($order['agree']) || $order['agree'] === '') {
            return ['errcode' => 1, 'errmsg' => '缺少是否同意 agree'];
        }
        return $this->baseVerify($param, self::AUDITPAYREQUEST, $extra);
    }
}
