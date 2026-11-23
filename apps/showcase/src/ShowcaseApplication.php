<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\App\AppScope;
use Drift\App\Application;
use Drift\App\Io\HttpResponse;
use Drift\Async\Future;
use Drift\Commands\CommandHandler;
use Drift\Input\AcceleratorTable;
use Drift\Input\KeyChord;
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
use App\Showcase\Pages\TablePage;
use App\Showcase\Stores\ButtonsStore;

/**
 * 示例台应用。平台实现只出现在组合根 `bin/win32/Bootstrap.php`。
 *
 * 页切换是纯 UI 态；「刷新」是 `showcase.refresh` 命令。剪贴板由组合根以端口
 * 递进来（Application 自身不持平台），缺省 `null` 时编辑命令回落到加速键。
 */
final class ShowcaseApplication extends Application
{
    public const ROWS_LOCAL = 'local';

    public const ROWS_HTTP = 'http';

    private const ROWS_BASE = 'http://127.0.0.1:8098';

    /** 应用级共享状态：所有窗口 / 所有页拿到同一个实例，切页不丢。 */
    private ButtonsStore $buttons;

    /**
     * 必须调 `parent::__construct()`：基类在其中初始化 router/pumps 等七个拆分片，
     * 漏调则 `attach()` 读未初始化字段 ⇒ AOT 下 exit=255、错误信息无文件名行号。
     * smoke 抓不到（夹具都调了），lint `AppSubclassCtorParent` 守这条。
     *
     * ★ 端口（对话框 / 剪贴板）**不进构造器**：它们由组合根 `setDialog()` /
     *   `setClipboard()` 装进 `AppScope`，页面从门面取。构造器只剩两个业务旋钮。
     */
    public function __construct(
        ?AppScope $scope = null,
        private string $rowsMode = self::ROWS_LOCAL,
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
     * ★ 依赖各有具体类型（`ButtonsStore` / `\Closure(int): Future`），写反是
     *   构造期 Fatal；这替代了原先"6 个同型闭包 + 反射测试钉顺序"的做法。
     *
     * @internal 只给装配契约测试读键集合（漏注册一页 ⇒ 切到那页 Fatal）
     *
     * @return array<class-string, \Closure(): Widget|Page>
     */
    public function pageFactories(): array
    {
        // 页面拿的是**门面本身**，不是从门面上拆下来的几个一等可调用 ——
        // 每页自己从 $env 取它需要的那一条（`$env->delay()` / `notify()` …）。
        $env = $this->environment();

        $buttons = $this->buttons;
        $fetchRows = $this->fetchRows(...);
        $setCloseConfirm = $this->setConfirmClose(...);

        // HTTP 档才注入上传演示：让 N6 第二条分流（multipart → HttpRequestJob）
        // 有真实消费者。local 档 / 无头 wiring 不注入 ⇒ 上传按钮不显示、像素不变。
        $upload = $this->rowsMode === self::ROWS_HTTP
            ? $this->uploadOverHttp(...)
            : null;

        return [
            AsyncTablePage::class => static fn(): Widget|Page => new AsyncTablePage($fetchRows, $upload),
            ButtonsPage::class => static fn(): Widget|Page => new ButtonsPage($buttons),
            ComboBoxPage::class => static fn(): Widget|Page => new ComboBoxPage($env),
            ConcurrentFetchPage::class => static fn(): Widget|Page => new ConcurrentFetchPage($fetchRows),
            DialogPage::class => static fn(): Widget|Page => new DialogPage($env, $setCloseConfirm),
            FeedbackPage::class => static fn(): Widget|Page => new FeedbackPage($env),
            LoadMorePage::class => static fn(): Widget|Page => new LoadMorePage($fetchRows),
            SnackbarPage::class => static fn(): Widget|Page => new SnackbarPage($env),
            // 表格 Ctrl+C → 剪贴板：注入门面才声明 onCopy（golden 默认 new 不注入 ⇒ 行为不变）。
            TablePage::class => static fn(): Widget|Page => new TablePage($env),
        ];
    }

    /**
     * 取第 N 页：来源由组合根选定的 rowsMode 决定，页面只认这个契约。
     *
     * local 档把"慢"放 worker；http 档的小 JSON 走 UI 侧帧内 curl_multi，不占 worker。
     */
    public function fetchRows(int $page): Future
    {
        if ($this->rowsMode === self::ROWS_HTTP) {
            return $this->fetchRowsOverHttp($page);
        }

        return $this->submit(new FetchRowsJob($page, 20));
    }

    /**
     * 真 HTTP 取数：UI 侧发请求，curl_multi 每帧推进，不占 worker。
     *
     * ★ 出错抛异常 ⇒ `AsyncCommand` 进 error 态，页面可重试（不静默画空表）。
     */
    public function fetchRowsOverHttp(int $page): Future
    {
        $url = self::ROWS_BASE . '/api/rows?page=' . $page . '&per=20';

        return $this->http()->get($url, [
            'headers' => ['Accept' => 'application/json'],
            'connectTimeout' => 5.0,
            'timeout' => 10.0,
        ])->then(static function (HttpResponse $res): array {
            $data = $res->json();
            if (!\is_array($data) || empty($data['ok'])) {
                throw new \RuntimeException('取表格失败（服务端）：' . $res->snippet());
            }
            $items = $data['items'] ?? [];

            return [
                'items' => \is_array($items) ? $items : [],
                'total' => (int) ($data['total'] ?? 0),
                'source' => 'HTTP ' . self::ROWS_BASE . '（' . \round($res->elapsedMs()) . 'ms）',
            ];
        });
    }

    /**
     * 上传演示：multipart 大请求 → `AsyncHttp` 分流到 `HttpRequestJob` 进程池。
     *
     * ★ 用来让 N6 第二条分流支路有真实消费者：小 JSON 档走帧内 curl_multi，
     *   这里带 fields + 真文件 ⇒ 必走 worker，进度经 onProgress 回贴。
     * ★ 文件路径按 `<cwd>/assets` 找（与 ImagePage 同款约定，真机从仓库根启动）。
     *   文件不存在时当场抛错进 error 态，不静默传空 —— 否则 mock 回 200、
     *   界面显示"成功"却没传任何字节，是最难查的那种假绿。
     *
     * @param callable(float): void $onProgress
     */
    public function uploadOverHttp(callable $onProgress): Future
    {
        $file = \getcwd() . '/assets/demo/quad.png';
        if (!\is_file($file)) {
            throw new \RuntimeException('上传示例缺素材：<cwd>/assets/demo/quad.png（从仓库根启动）');
        }

        return $this->http()->request('POST', self::ROWS_BASE . '/api/upload', [
            'fields' => ['hello' => 'world', 'n' => '42'],
            'files' => ['file' => $file],
            'connectTimeout' => 5.0,
            'timeout' => 15.0,
            'onProgress' => $onProgress,
        ])->then(static function (HttpResponse $res): int {
            $data = $res->json();
            if (!\is_array($data) || empty($data['ok'])) {
                throw new \RuntimeException('上传失败（服务端）：' . $res->snippet());
            }
            // 带出收到的字节数：校验"服务端真收到了字节"，不只是 mock 回 200。
            // 命令只取它作完成信号；进度 / 完成文案由 onProgress sink 写 status 格。
            $files = $data['files'] ?? [];

            return \is_array($files) && isset($files[0]['size']) ? (int) $files[0]['size'] : 0;
        });
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

        return $this->environment()->confirm(
            '关闭示例台',
            '「关闭前确认」是开着的。点「否」窗口会留着 —— 这是关闭可拦截的演示。',
        );
    }
}
