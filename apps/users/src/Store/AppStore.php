<?php
declare(strict_types=1);

namespace App\Users\Store;

use Drift\App\Restoration\RestorableValue;
use Drift\Routing\NavigationStack;
use Drift\State\Subject;
use Drift\Theme\SystemTheme;

/** 跨页共享态。导航存档与广播只在 changed() 一处发生，漏一处冷启动就回首页。 */
final class AppStore extends Subject
{
    private string $statusLine = '就绪';

    private SystemTheme $systemTheme = SystemTheme::Light;

    private NavigationStack $nav;

    private ?RestorableValue $navState;

    public function __construct(?NavigationStack $nav = null, ?RestorableValue $navState = null)
    {
        $this->nav = $nav ?? new NavigationStack('users');
        $this->navState = $navState;
    }

    public function currentPage(): string
    {
        return $this->nav->current();
    }

    public function nav(): NavigationStack
    {
        return $this->nav;
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

    /** 导航变更的唯一写者：存档 + 广播一起做。 */
    private function changed(): void
    {
        $this->navState?->set($this->nav->toArray());
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