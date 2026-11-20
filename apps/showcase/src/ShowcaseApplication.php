<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\App\AppScope;
use Drift\App\Application;
use Drift\Async\Future;
use Drift\Commands\CommandHandler;
use Drift\Input\AcceleratorTable;
use Drift\Input\KeyChord;
use Drift\Platform\Clipboard;
use Drift\Platform\Dialog;
use Drift\Widget\Modal;
use Drift\Widget\Snackbar;
use Drift\Widget\Widget;

use App\Showcase\Commands\ShowcaseCommands;
use App\Showcase\Jobs\FetchRowsJob;
use App\Showcase\ShowcaseCapabilities;

/**
 * 示例台应用。平台实现只出现在组合根 `bin/win32/Bootstrap.php`。
 *
 * 页切换是纯 UI 态；「刷新」是 `showcase.refresh` 命令。剪贴板由组合根以端口
 * 递进来（Application 自身不持平台），缺省 `null` 时编辑命令回落到加速键。
 */
final class ShowcaseApplication extends Application
{
    /**
     * 必须调 `parent::__construct()`：基类在其中初始化 router/pumps 等七个拆分片，
     * 漏调则 `attach()` 读未初始化字段 ⇒ AOT 下 exit=255、错误信息无文件名行号。
     * smoke 抓不到（夹具都调了），lint `AppSubclassCtorParent` 守这条。
     */
    public function __construct(
        private Dialog $dialog,
        private ?Clipboard $clipboard = null,
        ?AppScope $scope = null,
        private string $rowsMode = FetchRowsJob::MODE_LOCAL,
    ) {
        // ★ 不传 scope ⇒ 基类自建一个（单窗口时与旧版逐字节相同）。
        //   多窗口时组合根传入**同一个**实例 ⇒ 字体 / 任务池 / IO 轮询器 / 主题
        //   只有一份（见 `AppScope` 类注释的五项表）。
        parent::__construct([], $scope);
    }

    public function buildRoot(): Widget
    {
        // 能力在组合根装配：页面够不到 Application，只拿到自己那一个闭包。
        // dialog 必填且排第一：缺端口要让组合根当场失败（tpc 不许可选参数在必选前）。
        return ShowcaseRoot::make(new ShowcaseCapabilities(
            // 弹通知：浮层归 Application
            function (Snackbar $snackbar): void {
                $this->showSnackbar($snackbar);
            },
            // 延时回调：控件侧没有时钟
            function (int $milliseconds, \Closure $callback): void {
                $this->after($milliseconds, $callback);
            },
            // 开模态：Modal 是 Widget，但"打开"归 Application
            function (Modal $modal): void {
                $this->showModal($modal);
            },
            $this->dialog,
            // 关确认开关：方向相反（页面 → 应用），拦截点只在帧边界一处
            function (bool $enabled): void {
                $this->setConfirmClose($enabled);
            },
            // 取数：submit 在无后台端口时返回 rejected Future，页面落 error 可重试
            function (int $page): Future {
                return $this->submit(new FetchRowsJob($page, 20, $this->rowsMode));
            },
        ));
    }

    public function title(): string
    {
        return 'Drift 示例台';
    }

    public function clipboard(): ?Clipboard
    {
        return $this->clipboard;
    }

    /**
     * 动作缝注册表：框架在 `attach()` 里用它拼命令链，顶条「刷新」是消费者。
     *
     * @return iterable<string, CommandHandler> `命令名 => handler 实例`
     */
    public function commands(): iterable
    {
        $notify = function (Snackbar $snackbar): void {
            $this->showSnackbar($snackbar);
        };

        return [
            'showcase.refresh' => new ShowcaseCommands($notify),
        ];
    }

    /** 菜单 / UIA 都声明 Ctrl+R ⇒ 键表必须真绑，否则按了静默无反应。 */
    public function accelerators(): AcceleratorTable
    {
        return AcceleratorTable::make()->bind(KeyChord::ctrl('R'), 'showcase.refresh');
    }

    /**
     * 「关闭前确认」开关。状态住应用上：回答"要不要关"的是 onCloseRequested()，
     * 页面只表达意图（闭包由组合根递下去）。
     */
    private bool $confirmClose = false;

    /** 由「对话框」页的按钮调（经 `buildRoot()` 递下去的闭包） */
    public function setConfirmClose(bool $enabled): void
    {
        $this->confirmClose = $enabled;
    }

    /**
     * 关闭守卫：开着走原生确认框，拒绝则窗口留着。组合根已 setCloseGuard(true)
     * ⇒ WM_CLOSE 变成 CloseRequestedEvent 落到这里。弹框只在帧边界安全，不在 WndProc 里。
     */
    protected function onCloseRequested(): bool
    {
        if (!$this->confirmClose) {
            return true;
        }

        return $this->dialog->confirm(
            '关闭示例台',
            '「关闭前确认」是开着的。点「否」窗口会留着 —— 这是关闭可拦截的演示。',
        );
    }
}
