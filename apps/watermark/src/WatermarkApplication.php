<?php
declare(strict_types=1);

namespace App\Watermark;

use App\Watermark\Api\Config;
use App\Watermark\Store\WatermarkStore;
use Drift\App\Application;
use Drift\App\AppScope;
use Drift\App\TaskHost;
use Drift\Platform\Dialog;
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
 * ★ **对话框端口不走闭包**（与 `ShowcaseApplication` 同一个理由）：`Dialog` 是一个
 *   有多种返回语义的服务（`?string` / `array` / `bool` / `void`），包成闭包会让调用点
 *   看不出返回值是什么。它是 `@api platform` 的**端口接口**，不是平台实现 ⇒ §4.3 的
 *   边界不变。排在构造器第一个且**必填**：本应用唯一的选图入口就靠它，缺端口应当
 *   在组合根当场失败，而不是让"点击选择图片"点了没反应。
 */
final class WatermarkApplication extends Application
{
    private WatermarkStore $store;

    /**
     * @param null|TaskHost $host 非 null ⇒ 全部走**后台**（§17.4.5：常驻子进程池）；
     *   null ⇒ 同步 `ApiClient`（解释器 / 单测）。★ 组合根的"一行"就在这里。
     * @param string $apiBase 网关地址（测试传随机端口 / 假网关）
     */
    public function __construct(
        private readonly Dialog $dialog,
        ?TaskHost $host = null,
        string $apiBase = Config::API_BASE,
        ?AppScope $scope = null,
    ) {
        // ★ 后台端口是**应用级**的（一个进程一个池）⇒ 放进作用域（M2 起不再靠
        //   覆写 `taskHost()` 递给帧循环）。装配处递了 scope 就以它为准（多窗口：
        //   N 个 Application 共用一个）。
        parent::__construct([], $scope ?? AppScope::make()->setTaskHost($host));

        // ★ 轮询节奏：把 `Application::after()` 包成闭包往下递 —— Store **不持有**
        //   Application（纯状态，与 §4.3"Widget 不许依赖 App"是同一条纪律往上延一层）。
        //   与 `UsersRoot::menuOpener()` 是同一个模式。
        $this->store = new WatermarkStore(
            $host,
            $apiBase,
            function (int $milliseconds, \Closure $callback): int {
                return $this->after($milliseconds, $callback);
            },
        );

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
        // ★ 选图能力以闭包形式递给 Root（Widget 不许依赖 `Drift\Platform`）——
        //   本方法（唯一持有 `$this` 的地方）把"打开对话框 + 收进 store"包成一个
        //   `\Closure(): void`。
        return WatermarkRoot::make($this->store, function (): void {
            $this->pickFiles();
        });
    }

    /**
     * 打开多选对话框并把选中的文件收进 store。
     *
     * ★ 取消 = 空数组（`Dialog::openFiles()` 的契约），**不是失败** ⇒ 静默返回，
     *   不写任何消息。原文的 `<input type="file" multiple>` 取消时也是无事发生。
     */
    private function pickFiles(): void
    {
        $paths = $this->dialog->openFiles('选择图片', self::filters());
        if ($paths === []) {
            return;
        }

        $this->store->addFiles($paths);
    }

    /**
     * 筛选表（`[显示名, 通配符]`，形状见 `Dialog::openFile()` 的文档）。
     *
     * ★ 通配符用 `;` 分隔（Win32 的写法）；`Win32Dialog::filterText()` 负责翻译。
     * ★ 第二行 `*.*` 是**必要的**：原生对话框里"所有文件"这一档不写，用户就看不到
     *   扩展名不常见的图（`.avif` / 无扩展名）—— 而"打不开"看起来像 bug。
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function filters(): array
    {
        return [
            ['图片文件', '*.jpg;*.jpeg;*.png;*.webp;*.bmp;*.gif'],
            ['所有文件', '*.*'],
        ];
    }

    /** @internal 测试读法（断言"选图之后 store 里有东西"） */
    public function watermarkStore(): WatermarkStore
    {
        return $this->store;
    }
}
