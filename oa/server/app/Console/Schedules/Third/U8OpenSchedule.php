<?php

namespace App\Console\Schedules\Third;

use App\Console\Schedules\Schedule;
use Ext\U8Open\service\U8ScheduleService;

/**
 * U8 OpenAPI 采购订单定时同步创建流程
 */
class U8OpenSchedule implements Schedule
{
    public function call($schedule)
    {
        $configFile = base_path('ext/u8open/config/schedule.php');
        if (!file_exists($configFile)) {
            return;
        }
        // 每隔 10 分钟执行一次
        $schedule->call(function () {
            (new U8ScheduleService())->runAllApps();
        })->everyTenMinutes()->name('u8open-order')->withoutOverlapping();
    }
}
