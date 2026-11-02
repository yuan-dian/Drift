<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceFile;

/**
 * **不许再长出"管线记账的静态门面"**（技术债 D3 收口的守卫）。
 *
 * ## 守的是什么
 *
 * 2026-11-02 之前，`State` / `Element` / `RenderBox` / `RenderObject` / `Frame`
 * 上共有 **22 个** `public static function X(): T { return Pipeline::current()->X(); }`。
 * 它们是 D3① "把字段搬进 `Pipeline`"时留下的**半个搬家**：字段走了、名字没走。
 *
 * 后果不是"多几行"，而是**同一份数据有两个名字**：
 *
 * - 新代码写 `State::rebuiltCount()` 也照样能跑 ⇒ 没人有动力学新写法；
 * - 旧名字还在**骗人**：`State::boxEpoch()` 读的是渲染树的全局序号，与"某个
 *   State"毫无关系。两个应用交错跑帧时，静态门面读的是"最近进入帧的那个"，
 *   而不是"我的" —— 这个 bug 只有在多窗口落地后才会显形，届时很难回溯到这一行。
 *
 * ## 判据（结构，不枚举类名）
 *
 * `src/` 里任何 `public static function` 的**函数体只有一句**、且那句话是
 * `Pipeline::current()->…` ⇒ 报错。
 *
 * ★ 为什么是"一句"：`Element::absoluteOrigin()` 这类真函数不转发管线；只转发
 *   一行的才是门面。工厂转发（`make() → PopupPanel::make()`）也不命中 ——
 *   *不* 转发到 `Pipeline`，这是本规则与"所有单行转发"的关键区别（后者会误伤）。
 *
 * ## 正确写法只有两种（不是"三种"）
 *
 * | 位置 | 写法 |
 * |---|---|
 * | 树内对象（`State` / `Element` / `RenderObject` 及其子类） | `$this->pipeline()->…`（`Drift\Core\PipelineAccess`） |
 * | 树外（`Application` / `FocusManager` / 测试 / 探针） | `Pipeline::current()->…` |
 *
 * 前者是"**我的**账本"（多窗口时改成读节点上的引用，调用点不变），后者是
 * "**当前**账本"（观察者视角）。再加一层门面就把这两个语义又搅回一起了。
 */
final class StaticPipelineFacade implements Rule
{
    public function name(): string
    {
        return 'static-pipeline-facade';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return '静态方法一行转发 `Pipeline::current()`（D3 收口：树内走 `$this->pipeline()`，树外直调）';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            if (!$project->isFramework($file)) {
                continue;
            }

            $code = $file->code();

            // 函数体用 `[^}]*` 抓：门面体里没有嵌套花括号，这不是通用解析器，
            // 是"针对一个形状"的判据（多余的命中会被下面的正则第二道筛掉）。
            if (preg_match_all(
                '/^[ \t]*public static function (\w+)\s*\([^)]*\)\s*(?::\s*[^{;]+)?\{([^}]*)\}/m',
                $code,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            ) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $body = $match[2][0];
                $squashed = trim((string) preg_replace('/\s+/', ' ', $body));

                if (preg_match('/^(?:return )?\\\\?Pipeline::current\(\)->\w+\([^;]*\);$/', $squashed) !== 1) {
                    continue;
                }

                $line = substr_count(substr($code, 0, $match[0][1]), "\n") + 1;

                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $line,
                    sprintf(
                        '静态门面 %s::%s() 只是转发 `Pipeline::current()` —— 同一份数据两个名字，'
                        . '多窗口时它读的是"最近进入帧的应用"而不是"我的"。'
                        . '修法：树内对象改用 `$this->pipeline()->%s()`（`PipelineAccess` trait），'
                        . '树外观察者直接写 `Pipeline::current()->%s()`，然后删掉这个静态方法',
                        $this->typeNameOf($file),
                        $match[1][0],
                        $this->forwardedName($match[1][0], $squashed),
                        $this->forwardedName($match[1][0], $squashed)
                    )
                );
            }
        }

        return $found;
    }

    /** 报错文案要能定位到类 —— 同一文件里可能有两个类/trait */
    private function typeNameOf(SourceFile $file): string
    {
        $types = $file->types();

        return $types === [] ? basename($file->path()) : $types[0]['name'];
    }

    /** 转发到了哪个方法（提示里带上，省得人再找） */
    private function forwardedName(string $method, string $squashed): string
    {
        if (preg_match('/Pipeline::current\(\)->(\w+)\(/', $squashed, $m) === 1) {
            return $m[1];
        }

        return $method;
    }
}
