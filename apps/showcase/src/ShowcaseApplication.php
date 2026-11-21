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
use Drift\Widget\Page;
use Drift\Widget\Snackbar;
use Drift\Widget\Widget;

use App\Showcase\Commands\ShowcaseCommands;
use App\Showcase\Jobs\FetchRowsJob;
use App\Showcase\Pages\AsyncTablePage;
use App\Showcase\Pages\ButtonsPage;
use App\Showcase\Pages\ComboBoxPage;
use App\Showcase\Pages\ConcurrentFetchPage;
use App\Showcase\Pages\DialogPage;
use App\Showcase\Pages\FeedbackPage;
use App\Showcase\Pages\LoadMorePage;
use App\Showcase\Pages\SnackbarPage;
use App\Showcase\Stores\ButtonsStore;

/**
 * 示例台应用。平台实现只出现在组合根 `bin/win32/Bootstrap.php`。
 *
 * 页切换是纯 UI 态；「刷新」是 `showcase.refresh` 命令。剪贴板由组合根以端口
 * 递进来（Application 自身不持平台），缺省 `null` 时编辑命令回落到加速键。
 */
final class ShowcaseApplication extends Application
{
    /** 应用级共享状态：所有窗口 / 所有页拿到同一个实例，切页不丢。 */
    private ButtonsStore $buttons;

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
        $this->buttons = new ButtonsStore();
    }

    public function buildRoot(): Widget
    {
        return ShowcaseRoot::make($this->pageFactories());
    }

    /**
     * 页面工厂表：**只有需要依赖的页**在这里出现，其余页走默认无参构造。
     *
     * ★ 加一个领域依赖只改这里一行 —— 不穿透 Root / State / Nav，也不改任何契约。
     * ★ 依赖各有具体类型（`Dialog` / `ButtonsStore` / `\Closure(int): Future`），写反是
     *   构造期 Fatal；这替代了原先"6 个同型闭包 + 反射测试钉顺序"的做法。
     *
     * @internal 只给装配契约测试读键集合（漏注册一页 ⇒ 切到那页 Fatal）
     *
     * @return array<class-string, \Closure(): Widget|Page>
     */
    public function pageFactories(): array
    {
        // 环境能力从门面取具名方法（一等可调用）—— 页面只拿用得到的那一条。
        $env = $this->environment();
        $delay = $env->delay(...);
        $showModal = $env->showModal(...);
        $notify = $env->notify(...);

        $buttons = $this->buttons;
        $dialog = $this->dialog;
        $fetchRows = fn(int $page): Future => $this->submit(
            new FetchRowsJob($page, 20, $this->rowsMode)
        );
        $setCloseConfirm = $this->setConfirmClose(...);

        return [
            AsyncTablePage::class => static fn(): Widget|Page => new AsyncTablePage($fetchRows),
            ButtonsPage::class => static fn(): Widget|Page => new ButtonsPage($buttons),
            ComboBoxPage::class => static fn(): Widget|Page => new ComboBoxPage($delay),
            ConcurrentFetchPage::class => static fn(): Widget|Page => new ConcurrentFetchPage($fetchRows),
            DialogPage::class => static fn(): Widget|Page => new DialogPage($dialog, $setCloseConfirm),
            FeedbackPage::class => static fn(): Widget|Page => new FeedbackPage($showModal),
            LoadMorePage::class => static fn(): Widget|Page => new LoadMorePage($fetchRows),
            SnackbarPage::class => static fn(): Widget|Page => new SnackbarPage($notify),
        ];
    }

    /** 页面工厂装配用：对话框端口只在组合根可见。 */
    public function dialog(): Dialog
    {
        return $this->dialog;
    }

    /** 页面工厂装配用：取数模式由 Bootstrap 决定。 */
    public function rowsMode(): string
    {
        return $this->rowsMode;
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
