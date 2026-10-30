<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\Async\Future;
use Drift\Platform\Dialog;
use Drift\Widget\Modal;
use Drift\Widget\Snackbar;

/**
 * **组合根交给页面的那一束能力**（2026-10-30）—— 页面自己够不到、只能被递进来
 * 的东西（§4.3：`Widget → App` 是禁的）。
 *
 * ## 为什么把它们打包（不是"顺手抽个类"）
 *
 * 这一束是**示例台的组合根契约**：它逐条对应"浮层 / 定时器 / 模态 / 对话框端口 /
 * 关闭确认开关 / 后台取数"这六样页面够不到的能力。散成六个构造参数时，
 * **每加一样能力要改三处签名**（`ShowcaseRoot::make()` → `ShowcaseRoot` 构造 →
 * `ShowcaseState` 构造）+ 两处装配点；打包之后只改本类一处 + 两处装配点，
 * 而且"示例台到底要接几样"这个问题**有了一个可以读的地方**。
 *
 * ## 它**不是**依赖注入容器，也不做"沿树查找"
 *
 * ★ 页面拿到能力仍然是**由组合根显式递进去**（`ShowcaseState` → 页工厂闭包 →
 *   页构造参数），跳数一处没少。这是有意的：`BuildContext` 三条边界里的第 ②③
 *   条明写"无业务数据、无向上查找"（§18 已否决 Flutter 的 provider soup）。
 *   ⇒ 打包降的是**噪音**（每跳携带的参数个数），不是**跳数**；
 *     跳数换来的好处是"谁给页面的什么东西"在组合根一眼看全，且可被 lint 检查。
 *
 * ## 六项里每一项为什么必须由组合根给
 *
 * | 能力 | 页面为什么够不到 |
 * |---|---|
 * | `notify` | `Snackbar` 挂在浮层栈上，而浮层归 `Application`（§5.1 裁决 1） |
 * | `delay` | 定时器是 `Application::after()`；控件侧没有时钟（§14.1） |
 * | `showModal` | `Modal` 是 Widget，但"打开"是 `OverlayManager` 的动作 |
 * | `dialog` | 平台对话框是**端口**（`@api platform`），不是页面能 new 的东西 |
 * | `setCloseConfirm` | 方向相反（页面 → 应用）：改 `onCloseRequested()` 的答案 |
 * | `fetchRows` | 后台任务端口在 `AppScope`，页面只认"取第 N 页"这一个闭包 |
 *
 * ★ `dialog` 不包成闭包：它不是"做一件事"，是有四种返回语义的服务
 *   （`?string` × 2 / `void` / `bool`）。包成闭包只会让调用点看不出返回值是什么。
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
