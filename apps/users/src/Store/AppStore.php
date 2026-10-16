<?php
declare(strict_types=1);

namespace App\Users\Store;

use Drift\State\Subject;
use Drift\Theme\SystemTheme;

/**
 * 跨页共享态（§A.2.7）：当前页 / 状态栏 / 系统主题。
 *
 * ★ **业务层不认识任何 GUI 类型**（连 `Drift\Widget` 都不 import）—— 所以同一份
 *   store 能在单测里跑，也能在真机上跑（§11.3）。
 */
final class AppStore extends Subject
{
    private string $currentPage = 'users';

    private string $statusLine = '就绪';

    private SystemTheme $systemTheme = SystemTheme::Light;

    public function currentPage(): string
    {
        return $this->currentPage;
    }

    public function statusLine(): string
    {
        return $this->statusLine;
    }

    public function systemTheme(): SystemTheme
    {
        return $this->systemTheme;
    }

    public function navigate(string $page): void
    {
        if ($page === $this->currentPage) {
            return; // ★ 相等门控：同页不广播（否则每帧都重建）
        }

        $this->currentPage = $page;
        $this->notifyChanged();
    }

    public function say(string $line): void
    {
        $this->statusLine = $line;
        $this->notifyChanged();
    }

    public function setSystemTheme(SystemTheme $t): void
    {
        $this->systemTheme = $t;
        $this->notifyChanged();
    }
}
