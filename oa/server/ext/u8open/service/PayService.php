<?php

namespace Ext\U8Open\service;

/**
 * 付款单
 * 接口文档：https://open.yonyouup.com/apiCenter/pay_add
 * 付款服务类
 * @package Ext\U8Open\service
 */
class PayService extends U8OpenBaseService
{
    const ADDPAY = 'api/pay/add';

    /**
     * 新建付款单
     * @param array $param 付款单数据，可直接传 pay 主体字段，或已包一层 ['pay' => [...]]
     *                     必填参考：vouchtype、customercode/vendorcode、amount、digest，
     *                     entry 明细需含 customercode/vendorcode、amount、iamt_s
     * @return array
     */
    public function add($param)
    {
        if (!$param || !is_array($param)) {
            return ['errcode' => 1, 'errmsg' => '付款单参数为空'];
        }
        if (!isset($param['pay'])) {
            $param = ['pay' => $param];
        }
        $pay = $param['pay'];
        if (empty($pay['vouchtype'])) {
            return ['errcode' => 1, 'errmsg' => '缺少单据类型 vouchtype（48=收款单;49=付款单）'];
        }
        if (empty($pay['customercode']) && empty($pay['vendorcode'])) {
            return ['errcode' => 1, 'errmsg' => '缺少客商编码 customercode/vendorcode'];
        }
        if (!isset($pay['amount']) || $pay['amount'] === '') {
            return ['errcode' => 1, 'errmsg' => '缺少本币金额 amount'];
        }
        if (!isset($pay['digest']) || $pay['digest'] === '') {
            return ['errcode' => 1, 'errmsg' => '缺少摘要 digest'];
        }
        if (empty($pay['entry']) || !is_array($pay['entry'])) {
            return ['errcode' => 1, 'errmsg' => '缺少单据体 entry'];
        }
        foreach ($pay['entry'] as $i => $row) {
            if (!is_array($row)) {
                return ['errcode' => 1, 'errmsg' => 'entry 第' . ($i + 1) . '行格式错误'];
            }
            if (!isset($row['amount']) || $row['amount'] === '') {
                return ['errcode' => 1, 'errmsg' => 'entry 第' . ($i + 1) . '行缺少 amount'];
            }
            if (!isset($row['iamt_s']) || $row['iamt_s'] === '') {
                return ['errcode' => 1, 'errmsg' => 'entry 第' . ($i + 1) . '行缺少 iamt_s'];
            }
            if (empty($row['customercode']) && empty($row['vendorcode'])) {
                return ['errcode' => 1, 'errmsg' => 'entry 第' . ($i + 1) . '行缺少 customercode/vendorcode'];
            }
        }
        return $this->baseAdd($param, self::ADDPAY);
    }
}
