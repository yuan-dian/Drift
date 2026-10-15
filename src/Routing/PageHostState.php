<?php
declare(strict_types=1);

namespace Drift\Routing;

use Drift\State\Subject;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\Offstage;
use Drift\Widget\State;
use Drift\Widget\Widget;

/**
 * `PageHost` 的 State：页名 + 页面实例缓存（§12.1 第 4/7 条）。
 *
 * 两件事必须由它（而不是控件）持有：
 * ① **页面 Widget 缓存** —— 控件每次重建都可能是新实例描述，缓存放控件上会在
 *    重建时被重新填；放 State 上才跨重建活着。
 * ② **当前页名** —— 订阅通知改它，`build()` 按它排 Offstage。
 *
 * @internal
 */
final class PageHostState extends State
{
    private ?Subject $subject = null;

    /** @var null|callable(Subject): string */
    private $select = null;

    private ?RouteTable $table = null;

    private string $page = '';

    /** @var array<string, Widget> 页名 → 页面 Widget。**只造一次** */
    private array $pages = [];

    public function initState(): void
    {
        /** @var PageHost $widget */
        $widget = $this->widget();
        $this->subject = $widget->sourceSubject();
        $select = $widget->selectFn();
        $this->select = $select;
        $this->table = $widget->routeTable();

        $subject = $this->subject;
        if ($subject === null || $select === null) {
            return; // 没给 source/select ⇒ 不订阅（Pages 一个都不显示）
        }

        $this->page = (string) $select($subject);
        $this->watch($subject, function (): void {
            $next = (string) ($this->select)($this->subject);
            // ★ 同值不重建（§11.3.3 门控）：比的是两次 select 输出，不是 Store 新旧。
            //   不设这道门 ⇒ 任一字段变都换一次页，"同值 notify"全变成重建。
            if ($next === $this->page) {
                return;
            }
            $this->setState(function () use ($next): void {
                $this->page = $next;
            });
        });
    }

    public function page(): string
    {
        return $this->page;
    }

    /** @internal 冒烟夹具读它，证明缓存真的只造一次 */
    public function cachedCount(): int
    {
        return count($this->pages);
    }

    public function build(BuildContext $ctx): Widget
    {
        $current = $this->page;
        $table = $this->table;

        if ($table === null || $current === '') {
            return Offstage::make(new NoPage(), true);
        }

        $hosted = [];
        foreach ($table->names() as $name) {
            $hosted[] = Offstage::make($this->pageFor($name), $name === $current);
        }

        // 当前页不在表里 ⇒ 什么都不显示（开放域，§12.1 第 2 条）
        if (!in_array($current, $table->names(), true)) {
            $hosted[] = Offstage::make(new NoPage(), true);
        }

        return Column::make()->children($hosted);
    }

    private function pageFor(string $name): Widget
    {
        if (!isset($this->pages[$name])) {
            $page = $this->table?->build($name) ?? new NoPage();
            $this->pages[$name] = $page;
        }

        return $this->pages[$name];
    }
}
