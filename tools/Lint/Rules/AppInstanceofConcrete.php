<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceQuery;

/**
 * `src/App/` 不得用 `instanceof` 认**下层实现的具体类**（I2 闸门，S1 第 10 步）。
 *
 * ## 判据为什么不是"只许接口"
 *
 * 原计划（`ARCH-REVIEW-2026-10-24` §3.2）写的是「`src/App/` 里 `instanceof` 只许出现
 * 在接口上」。**这条字面判据对两类合法用法是错的**，照写会逼出过度设计：
 *
 * ① **事件类型分派**。`InputRouter` 按 `KeyEvent` / `PointerEvent` / `ScrollEvent`
 *    分派是输入域的**本职** —— App 层是这些事件的消费者，不是它们的抽象者。
 *    要"只许接口"就得给每个事件类加 `accept()` 做双分派（访问者模式），
 *    而收益只是让一条闸门变绿。
 * ② **返回值类型收窄**。`$result instanceof CursorShape`（enum）、
 *    `$local instanceof Rect`（Geometry 值对象）是"确认拿到的就是声明的类型"，
 *    与"框架认识某个实现"毫无关系。
 *
 * ⇒ 判据改成**表达本意**的那条：**App 层要抽象的是下层实现**，所以它不得认识
 * `Widget` / `Layout` / `Rendering` 三个目录下的**具体类**（class / trait / enum）。
 * 这三层之上才是 App —— 它认识它们的**接口**（`EditableText` / `Scrollable` /
 * `Activatable`）是正常的，那是能力契约。
 *
 * 好处是**零白名单**：不是"记住今天的例外"，而是"把规则本身写对"。加一种新事件
 * 类型、加一个值对象都不用改这条规则；只有"App 又去认具体控件/盒了"才红。
 *
 * ## 边界（宁漏勿误）
 *
 * - 右侧类型**不在本仓符号表**（PHP 内建 / 第三方）⇒ 不判（静态无从判断）。
 * - `kind === 'interface'` ⇒ 放行（能力契约）。
 * - 类型声明在 `Geometry` / `Input` / `Commands` 等**非禁止目录** ⇒ 放行。
 *   注意这是**按物理路径**判的，不按命名空间 —— `ResizeHook.php` 刻意无命名空间的
 *   先例（见 `Project::isFramework()`）说明"命名空间 = 层"在本仓不成立。
 * - `$x instanceof $y`（变量右侧）/ 全限定名 / `self` 等 ⇒ 不判（`SourceQuery` 已滤）。
 *
 * @api 闸门：`tools/lint.php`
 */
final class AppInstanceofConcrete implements Rule
{
    /** 被检查的目录（物理路径前缀） */
    private const APP_DIR = 'src/App/';

    /**
     * App 层要**抽象掉**的下层目录 => 层名（报错文案用）。
     *
     * ★ 只列这三层，不是"越低越禁"：`Geometry`（Point/Size/Rect）与 `Input`
     *   （事件 / 光标）是 App 的**输入与值类型**，认识它们是本职；`Rendering`
     *   与 `Layout` 是 App 要驱动的**实现**，`Widget` 是它要编排的**控件**。
     */
    private const FORBIDDEN = [
        'src/Widget/' => 'Widget',
        'src/Layout/' => 'Layout',
        'src/Rendering/' => 'Rendering',
    ];

    public function name(): string
    {
        return 'app-instanceof-concrete';
    }

    public function serves(): string
    {
        return 'G1';
    }

    public function summary(): string
    {
        return 'App 层不得 instanceof 下层（Widget/Layout/Rendering）的具体类 —— 抽能力接口或能力方法';
    }

    public function check(Project $project): array
    {
        $found = [];
        $seen = [];

        foreach ($project->files() as $file) {
            if (!str_starts_with($file->path(), self::APP_DIR)) {
                continue;
            }

            foreach (SourceQuery::instanceofNames($file->tokens()) as [$name, $line]) {
                $resolved = $file->resolveName($name);
                $symbol = $project->symbol($resolved);

                if ($symbol === null) {
                    continue; // 内建 / 第三方 ⇒ 诚实的边界
                }
                if ($symbol['kind'] === 'interface') {
                    continue; // 能力契约：正是 App 该认识的
                }

                $layer = $this->forbiddenLayer($symbol['file']);
                if ($layer === null) {
                    continue; // 值类型 / 事件 / 命令参数 ⇒ 本职
                }

                // 一个 (文件, 类型) 只报一次 —— 同一类型在文件里出现多次不是多条问题
                $key = $file->path() . ':' . $resolved;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $line,
                    sprintf(
                        'App 层不得 instanceof %s 层的具体类 %s（kind=%s）—— App 要抽象下层：抽能力接口（如 Activatable）或能力方法（如 RenderObject::panelRect()）',
                        $layer,
                        $resolved,
                        $symbol['kind'],
                    ),
                );
            }
        }

        return $found;
    }

    /** 该类型声明在哪个禁止目录下；`null` = 不在禁止目录 */
    private function forbiddenLayer(string $path): ?string
    {
        foreach (self::FORBIDDEN as $dir => $layer) {
            if (str_starts_with($path, $dir)) {
                return $layer;
            }
        }

        return null;
    }
}
