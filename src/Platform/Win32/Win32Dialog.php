<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Platform\Dialog;

use function drift_win_alert;
use function drift_win_confirm;
use function drift_win_open_file;
use function drift_win_save_file;

/**
 * Win32 对话框端口（§5.1 壳服务）—— `Dialog` 的第一个实现（A5-①）。
 *
 * ★★ **四个方法都是模态的**：调用即阻塞，直到用户关掉对话框为止。模态期间
 *   系统跑自己的内层消息循环，框架**一帧都跑不到**（`Application::frame()`
 *   不被调用）⇒ 界面在这段时间不重绘。这是原生对话框的固有语义（文档里
 *   写了两遍，因为是唯一一处"框架明知会卡住还要照做"的地方），不是缺陷；
 *   代价的边界是：**别在动画 / 滚动的回调里调它**。
 *
 * ★ **owner 每次现取**（`$this->host->handle()`）而不是构造时缓存：组合根
 *   完全可能在建窗之前就把端口递出去（`$app->buildRoot()` 先于 `createWindow()`
 *   不是错误），缓存下来的会是 `0`。0 = 没有 owner，`MB_TASKMODAL` 只挡本进程，
 *   弹框会跑到别的程序窗口后面去。
 *
 * ★ **`filterText()` 是纯函数**（不碰桥、不碰 Win32）—— 筛选串的编码是这个类
 *   唯一有分支的逻辑，它必须能被单测（`tests/Unit/Platform/Win32DialogTest.php`）。
 *   C 侧只做"分隔符 → 双 NUL"这一道机械转换，见 `buildFilterWide()`。
 *
 * @internal 平台实现；应用只认 `Drift\Platform\Dialog`
 */
final class Win32Dialog implements Dialog
{
    public function __construct(private readonly Win32WindowHost $host)
    {
    }

    public function openFile(string $title, array $filters = []): ?string
    {
        $path = drift_win_open_file($this->owner(), $title, self::filterText($filters), '');

        return $path === '' ? null : $path;
    }

    public function saveFile(string $title, string $defaultName = '', array $filters = []): ?string
    {
        $path = drift_win_save_file($this->owner(), $title, self::filterText($filters), $defaultName);

        return $path === '' ? null : $path;
    }

    public function alert(string $title, string $body): void
    {
        drift_win_alert($this->owner(), $title, $body);
    }

    public function confirm(string $title, string $body): bool
    {
        return drift_win_confirm($this->owner(), $title, $body);
    }

    /** 当前主窗口句柄；未建窗 ⇒ `0`（"没有 owner"，不是错误） */
    private function owner(): int
    {
        $handle = $this->host->handle();

        return $handle === null ? 0 : $handle;
    }

    /**
     * 筛选列表 → 桥的中间形态：**每行 `显示名\t通配符`，行间 `\n`**。
     *
     * ★ 为什么不是直接拼双 NUL：NUL 穿过 ABI 之后在调试器里全是一样的点，
     *   出了错看不出来；`\t` / `\n` 可读、可断言，双 NUL 由 C 侧最后一步拼。
     *
     * ★ **坏项跳过而不是 Fatal**：筛选表是文案，为它中止整个应用与 §1.2 的
     *   中止序列不成比例（少一条筛选，用户还能选"所有文件"）。
     *
     * ★ **空表 ⇒ 一行"所有文件"**：`lpstrFilter = NULL` 时 Win32 对话框
     *   **不显示筛选行**，用户会以为对话框坏了。兜底文案住 PHP 而不是 C，
     *   因为 `drift_win32.cc` 不声明 `/utf-8`（源码里的中文字面量会成乱码）。
     *
     * @param array<array-key, mixed> $filters
     */
    public static function filterText(array $filters): string
    {
        $lines = [];
        foreach ($filters as $filter) {
            if (!\is_array($filter) || \count($filter) < 2) {
                continue;
            }

            $label = (string) ($filter[0] ?? '');
            $pattern = (string) ($filter[1] ?? '');
            if ($label === '' || $pattern === '') {
                continue;
            }

            $lines[] = $label . "\t" . $pattern;
        }

        if ($lines === []) {
            $lines[] = '所有文件 (*.*)' . "\t" . '*.*';
        }

        return \implode("\n", $lines);
    }
}
