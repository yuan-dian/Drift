<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceQuery;

/**
 * `new X` / `X::` / `catch (X $e)` 的短名必须在本文件可见（use 过 / 同命名空间）。
 *
 * ## 为什么需要它（真事故，2026-11-02）
 *
 * `WatermarkStore` 里有四行 `use App\Watermark\Jobs\*Job`，唯独漏了
 * `DownloadResultsJob` ⇒ `new DownloadResultsJob($urls)` 解析成
 * `App\Watermark\Store\DownloadResultsJob`。真机任务完成、点下载时报
 * `class 'App\Watermark\Store\DownloadResultsJob' is undefined`。
 * （该类已随下载职责迁到 `Image` 而删除；事故形态与现存任何 Job 相同。）
 *
 * **四道闸门全绿**：`php -l` 只查语法；lint 当时只查 `instanceof`；smoke / phpunit
 * 走的是 `$host === null` 的**降级分支**（同步下载），`$host->submit(new …)` 那行
 * 只在**真机**可达。这正是本仓反复记录的同一类病：**未被消费的分支 = 未实现**，
 * 而"漏 use"是它最便宜的触发器。
 *
 * ## 判据（与 `instanceof-missing-use` 同一套，缺一不可）
 *
 *   ① 位置是 `new` / `::` / `catch (…)`（`self`/`static`/`parent`/变量/匿名类不判）；
 *   ② 解析（use 别名 / 同命名空间回退）后的 FQN **不在**本仓符号表；
 *   ③ 但该短名在本仓**某个命名空间下确实存在**。
 * ⇒ ②③ 同时成立 = 类就在仓里、只是这个文件看不到它 ⇒ 运行期炸或静默不命中。
 *
 * ★ 诚实的边界：短名全仓都不存在（PHP 内建 / 第三方）⇒ **不报**。静态无从区分
 *   "漏 use" 与 "就用全局类"，宁漏勿误（误报会训练人忽略规则）。
 * ★ `catch` 那处连错都不抛 —— 类型永远匹配不上，是**静默**分支，与 `instanceof`
 *   同一种病；`X::class` 也不抛（直接返回字符串），三种里最贵。
 */
final class ClassMissingUse implements Rule
{
    public function name(): string
    {
        return 'class-missing-use';
    }

    public function serves(): string
    {
        return 'G1';
    }

    public function summary(): string
    {
        return 'new / :: / catch 的短名类必须在本文件可见（use / 同命名空间）—— 否则运行期 class not found，catch 则静默不命中';
    }

    public function check(Project $project): array
    {
        $byShort = $project->symbolsByShortName();
        $found = [];

        foreach ($project->files() as $file) {
            foreach (SourceQuery::classNames($file->tokens()) as $ref) {
                $name = $ref['name'];
                $resolved = str_starts_with($name, '\\')
                    ? ltrim($name, '\\')          // 写全了不会错 —— 但仍要存在，不存在是真错
                    : $file->resolveName($name);

                if ($project->symbolFqcn($resolved) !== null) {
                    continue; // use 过 / 同命名空间 / 全限定项目类
                }

                $split = strrpos($name, '\\');
                $short = strtolower($split === false ? $name : substr($name, $split + 1));
                $cands = $byShort[$short] ?? [];
                if ($cands === []) {
                    continue; // 内置 / 第三方 ⇒ 诚实的边界
                }

                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $ref['line'],
                    sprintf(
                        '%s %s 运行期找不到类 —— 本仓有该类（%s），但本文件既没 use、也不在它的命名空间 ⇒ 解析落到 %s。补 use 或写全限定名',
                        match ($ref['among']) {
                            'new' => 'new',
                            'static' => '静态访问',
                            default => 'catch',
                        },
                        $name,
                        implode(' / ', $cands),
                        $resolved
                    )
                );
            }
        }

        return $found;
    }
}
