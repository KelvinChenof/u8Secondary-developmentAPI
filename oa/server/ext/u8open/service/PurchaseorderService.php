<?php

namespace Ext\U8Open\service;

/**
 * 采购订单
 * 采购订单服务类
 * @package Ext\U8Open\service
 */
class PurchaseorderService extends U8OpenBaseService
{
    const PURCHASEORDERLIST = 'api/purchaseorderlist/batch_get';
    const PURCHASEORDERDETAIL = 'api/purchaseorder/get';
    const AUDITPURCHASEORDER = 'api/purchaseorder/verify';
    const LIST_KEY = 'purchaseorderlist';

    /**
     * 获取列表数据
     * @param $param
     * @return array
     */
    public function getList($param)
    {
        $list = [];
        $error = $this->fetchAllList(self::PURCHASEORDERLIST, self::LIST_KEY, $param, $list);
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
        return $this->baseDetail($param, self::PURCHASEORDERDETAIL);
    }

    /**
     * 审核订单（调用 api/purchaseorder/audit）
     * @param array $param 审核参数：voucher_code、agree、user_id/person_code、opinion 等
     * @return array
     */
    public function audit($param, $extra = [])
    {
        if (!$param || !is_array($param)) {
            return ['errcode' => 1, 'errmsg' => '审核参数为空'];
        }
        if (!isset($param['purchaseorder'])) {
            $param = ['purchaseorder' => $param];
        }
        $order = $param['purchaseorder'];
        if (empty($order['voucher_code'])) {
            return ['errcode' => 1, 'errmsg' => '缺少单据编号 voucher_code'];
        }
        // if (!isset($order['agree']) || $order['agree'] === '') {
        //     return ['errcode' => 1, 'errmsg' => '缺少是否同意 agree'];
        // }
        return $this->baseVerify($param, self::AUDITPURCHASEORDER, $extra);
    }
}
