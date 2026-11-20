<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\Async\Future;
use Drift\Platform\Dialog;
use Drift\Widget\Modal;
use Drift\Widget\Snackbar;

/**
 * 组合根交给页面的一束能力：页面够不到、只能被递进来。
 *
 * 打包而非散成 6 个参数：每加一样能力只改这里一处，且"示例台接了哪些能力"
 * 有唯一可读处。仍由组合根逐层显式传递，不做沿树查找。
 *
 * `dialog` 是端口接口不包成闭包：它有 4 种返回语义（`?string`×2 / `void` / `bool`），
 * 包成闭包会让调用点看不出返回值。其余 5 项是单向动作闭包。
 *
 * @api
 */
final readonly class ShowcaseCapabilities
{
    /**
     * @param \Closure(Snackbar): void $notify 弹一条通知
     * @param \Closure(int, \Closure): void $delay 一次性延时回调（模拟服务端延迟）
     * @param \Closure(Modal): void $showModal 开一个模态
     * @param \Closure(bool): void $setCloseConfirm 打开/关闭"关闭前确认"开关
     * @param \Closure(int): Future $fetchRows "取第 N 页"（后台任务的唯一入口）
     */
    public function __construct(
        public \Closure $notify,
        public \Closure $delay,
        public \Closure $showModal,
        public Dialog $dialog,
        public \Closure $setCloseConfirm,
        public \Closure $fetchRows,
    ) {
    }
}
