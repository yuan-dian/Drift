<?php
declare(strict_types=1);

namespace App\Users\Store;

use Drift\App\Restoration\RestorableValue;
use Drift\Core\Readable;
use Drift\Routing\NavigationStack;
use Drift\State\Cell;
use Drift\Theme\SystemTheme;

/**
 * 跨页共享态：导航 + 状态栏 + 主题。
 *
 * ★ **三个独立格子，不冲突**（2026-11-22 从 `extends Subject` 迁来）：它们
 *   变化频率差得远（页名一次点击一个、状态栏每次命令一句、主题几乎不变），
 *   合成一次广播就是让"切页"顺带重建状态栏。
 * ★ **导航存档只在 `changed()` 一处发生**：漏一处冷启动就回首页。
 * ★ 当前页名是**格子而不是从 `nav` 现读**：`NavigationStack` 是可变对象，
 *   现读拿不到"变了"这件事；格子才是可订阅的源。
 */
final class AppStore
{
    private Cell $statusLine;

    /** 主题：`app.theme` 命令写入；Root 目前跟随系统主题（见 `UsersRoot::build()`） */
    private Cell $systemTheme;

    /** 当前页名 —— `PageHost` 的订阅源 */
    private Cell $page;

    private NavigationStack $nav;

    private ?RestorableValue $navState;

    public function __construct(?NavigationStack $nav = null, ?RestorableValue $navState = null)
    {
        $this->nav = $nav ?? new NavigationStack('users');
        $this->navState = $navState;

        $this->statusLine = Cell::of('就绪')->named('users.statusLine');
        $this->systemTheme = Cell::of(SystemTheme::Light)->named('users.systemTheme');
        $this->page = Cell::of($this->nav->current())->named('users.page');
    }

    public function currentPage(): string
    {
        $current = $this->page->get();

        return \is_string($current) ? $current : '';
    }

    /** 页名通道（`PageHost::from()` 用：读它 = 订它） */
    public function pageSource(): Readable
    {
        return $this->page;
    }

    public function nav(): NavigationStack
    {
        return $this->nav;
    }

    public function statusLine(): string
    {
        $line = $this->statusLine->get();

        return \is_string($line) ? $line : '';
    }

    public function systemTheme(): SystemTheme
    {
        $theme = $this->systemTheme->get();

        return $theme instanceof SystemTheme ? $theme : SystemTheme::Light;
    }

    public function navigate(string $page): void
    {
        if ($page === $this->nav->current() || !$this->nav->push($page)) {
            return;
        }
        $this->changed();
    }

    /** 提交表单后回列表：换栈顶而不是再压一层（否则按返回又回到注册页）。 */
    public function replace(string $page): void
    {
        if ($this->nav->replace($page)) {
            $this->changed();
        }
    }

    public function back(): bool
    {
        if (!$this->nav->pop()) {
            return false;
        }
        $this->changed();

        return true;
    }

    public function forward(): bool
    {
        if (!$this->nav->forward()) {
            return false;
        }
        $this->changed();

        return true;
    }

    /** 导航变更的唯一写者：存档 + 页名一起做。 */
    private function changed(): void
    {
        $this->navState?->set($this->nav->toArray());
        $this->page->set($this->nav->current());
    }

    public function say(string $line): void
    {
        $this->statusLine->set($line);
    }

    public function setSystemTheme(SystemTheme $t): void
    {
        $this->systemTheme->set($t);
    }
}
