<?php
declare(strict_types=1);

namespace Drift\Dev\Debug;

use Drift\App\Application;
use Drift\Dev\Smoke\App\PageHostApplication;
use Drift\Dev\Smoke\App\PendingRows;

/**
 * 目标的**唯一解析处**：一个标签字符串 ⇒ 一个挂好依赖的应用实例。
 *
 * ## 为什么不能各写一份
 *
 * "怎么造这一页"原本散在 `debug-report.php` 里，宿主进程又要一份 —— 两份一旦分叉，
 * 同一份脚本在本地跑出表格页、在宿主里跑出别的页，而两边报告都"正常"。
 * 这不是假设：`--page=async-table` 造不出来却不报错那次，根因就是"解析规则"存在两处。
 *
 * ## 两条寻址路
 *
 * - 标签是 `Application` 子类名（`Drift\Dev\Smoke\App\*` 可省命名空间）；
 * - 标签是示例台**路由 id**（`table` / `async-table` / …）⇒ 走 `PageHostApplication`，
 *   依赖页由组合根同款的页面工厂表装配。
 *
 * 两个候选集的交集是空的（路由 id 不像类名，类名不像路由 id），所以"先类名后路由"
 * 不会把意图判歪。`$page` 只是"别试类名"，用来表达调用方的确定判断。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class Target
{
    /**
     * @param int $rows 取数替身的行数（0 = 不用替身，页面走自己的源）
     *
     * @return array{0: Application, 1: PendingRows|null}
     */
    public static function app(string $name, bool $page, int $rows): array
    {
        $source = $rows > 0 ? new PendingRows($rows) : null;
        $fetch = $source === null ? null : $source->fetch(...);

        if (!$page) {
            $class = self::resolveApp($name);
            if ($class !== null) {
                try {
                    return [new $class(), $source];
                } catch (\ArgumentCountError $e) {
                    throw new \RuntimeException(
                        "{$class} 的构造函数需要参数：本命令只跑无参应用（装配归组合根）。\n"
                        . '要跑示例台的页面请用 --page=<路由 id>：' . \implode(' / ', PageHostApplication::routes()),
                        0,
                        $e,
                    );
                }
            }
        }

        try {
            return [PageHostApplication::forRoute($name, $fetch), $source];
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException(
                "找不到目标：{$name}\n"
                . "  作为应用类：试过 Drift\\Dev\\Smoke\\App\\ 与 Drift\\Dev\\Smoke\\\n"
                . '  作为页面路由：' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /** @return class-string<Application>|null */
    public static function resolveApp(string $name): ?string
    {
        $candidates = \str_contains($name, '\\')
            ? [$name]
            : ['Drift\\Dev\\Smoke\\App\\' . $name, 'Drift\\Dev\\Smoke\\' . $name];

        foreach ($candidates as $candidate) {
            if (\class_exists($candidate) && \is_subclass_of($candidate, Application::class)) {
                return $candidate;
            }
        }

        return null;
    }
}
