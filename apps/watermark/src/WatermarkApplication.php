<?php
declare(strict_types=1);

namespace App\Watermark;

use App\Watermark\Api\Config;
use App\Watermark\Store\WatermarkStore;
use Drift\App\Application;
use Drift\App\AppScope;
use Drift\Widget\Widget;

/**
 * 应用（§A.2.5）：title / buildRoot。
 *
 * ★ 基类只有 `__construct(iterable $handlers = [], ?AppScope $scope = null)` ·
 *   `buildRoot()` · `title()` · `commands()` · `accelerators()` · `attach()` ·
 *   `frame()` · `dispatch()` · `router()` ⇒ 三条硬约束（§23.2 O-18 方案 A）：
 *   ① 覆写**全是 public**（子类收窄可见性 ⇒ PHP Fatal）；
 *   ② **没有 `boot()`** —— 依赖装配就在**构造点**（O-15 方案 E）；
 *   ③ `commands()` 返回 `iterable<string, CommandHandler>`。本应用**不注册命令**：
 *      四个动作都是单点的（按钮 / 拖放），没有"从菜单 / 加速键 / 另一页"发起的需求
 *      ⇒ 用 `onPressed` 闭包，基类的默认 `[]` 就够。
 *
 * ★ **端口不进构造器**（2026-11-22）：对话框与后台池都是"一个进程一份"的东西 ⇒
 *   归 `AppScope`，由组合根 `setDialog()` / `setTaskHost()` 装配。本类现在只收
 *   一个作用域，不再有"哪个参数是必填"的问题，也不再需要把 `after()` 包成闭包
 *   往下递 —— 门面（`environment()`）本身就是那条通路。
 */
final class WatermarkApplication extends Application
{
    private WatermarkStore $store;

    /**
     * @param string $apiBase 网关地址（测试传随机端口 / 假网关）
     */
    public function __construct(
        ?AppScope $scope = null,
        string $apiBase = Config::API_BASE,
    ) {
        // ★ 后台端口是**应用级**的（一个进程一个池）⇒ 由组合根放进作用域；
        //   没给就自建一个（解释器 / 单测），与旧版逐字节相同。
        parent::__construct([], $scope ?? AppScope::make());

        // ★ Store 拿到的是门面，不是 `Application` —— 它只要"排个定时器"，
        //   不需要知道门面背后是本窗帧泵还是别的什么。
        $this->store = new WatermarkStore($this->scope()->taskHost(), $apiBase, $this->environment());

        // ★ 首屏预热取一次签名（原文 `DOMContentLoaded` 里就 fetchSign）：失败不打扰
        //   用户（上传时会再取一次），意义是把那一次 RTT 挪到用户还在看界面的时候。
        //   ★ 必须排在 `attach()` 之前 —— 它现在就跑，而帧循环还没起；`after()` 的
        //   定时器与"在途任务"都由 `wantsFrame()` 兜住，所以结果不会丢。
        $this->store->prewarm();
    }

    public function title(): string
    {
        return 'AI 图片处理工具';
    }

    public function buildRoot(): Widget
    {
        return WatermarkRoot::make($this->store, $this->environment());
    }

    /** @internal 测试读法（断言"选图之后 store 里有东西"） */
    public function watermarkStore(): WatermarkStore
    {
        return $this->store;
    }
}
