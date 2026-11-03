<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;

/**
 * 构造滚动轴（`ScrollLane`）必须把宿主 `State` 传进去。
 *
 * ## 背景（2026-11-03）
 *
 * `ScrollLane::move()` 滚过之后要 `noteScroll()` 点亮滚动条，而条要在 900ms 后
 * 淡下去 —— 那 900ms **本身要帧**（`ScrollBarFade::tick()` 的 ★★）。帧时钟只 tick
 * **动效活跃表**里的节点，入表只有两条路：`markDirty()`（要重建）与
 * `requestFrame()`（不重建）。
 *
 * 滚轮那条路是**碰巧**活的：`ScrollMotion::glideBy()` → `AnimatedValue::toward()`
 * 目标真的变了 ⇒ `requestAnimationScan()`。而 `move()`（拖条 / 键盘翻页 /
 * `ensureVisible()`）只 `noteScroll()`，`fade->toward(1.0)` 在"本来就全亮"时
 * **同值早退** ⇒ 没有人报告"我在动" ⇒ `tickFrame()` 一次都不来 ⇒ 条**永远全亮**。
 *
 * 修法是 `ScrollLane` 收一个宿主，`move()` / `glide()` 里 `$this->owner?->requestFrame()`。
 *
 * ## 为什么要有这条规则
 *
 * 这个宿主是**可选参数**（`ScrollLane` 的单测不需要它），所以漏传是**合法的
 * PHP**，症状是"滚动条不淡出"——界面上只是一点点不对，没有任何东西会红。
 * 而它的写入点在四个地方（`ScrollView` / `LazyList` / `Table` ×2 轴 /
 * `TextArea`），第五个 `Scrollable` 实现出现时最自然的动作是复制邻近那个的
 * `lane()`。
 *
 * ★ 与 `PaintShift` 同一条理由：**约定挡不住，闸门挡得住**。
 *
 * ## 判据
 *
 * `src/Widget/` 下任何 `new ScrollLane(...)`，实参**少于三个** ⇒ ERROR。
 * 只数顶层逗号（括号/方括号/花括号里的逗号不算），所以
 * `new ScrollLane(0.0, $this->bar(), $this)` 判为三个。
 *
 * ★ 不管 `tests/`：`ScrollLaneTest` 就是要在没有 `State` 的前提下量偏移算术，
 *   那是正确用法，不该被这条规则推着造一个假 State。
 *
 * 服务 G2（静默失败）。
 */
final class LaneOwner implements Rule
{
    /** `new ScrollLane(` 的起点 */
    private const OPEN = '/new\s+ScrollLane\s*\(/';

    /** 需要的实参个数（`$start` / `$fade` / `$owner`） */
    private const ARITY = 3;

    public function name(): string
    {
        return 'lane-owner';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return 'src/Widget 构造 ScrollLane 必须传宿主 State（否则滚动条不淡出，且不报错）';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            $relative = str_replace('\\', '/', $file->path());
            if (!str_starts_with($relative, 'src/Widget/')) {
                continue;
            }

            $code = $file->code();
            $offset = 0;

            while (preg_match(self::OPEN, $code, $match, PREG_OFFSET_CAPTURE, $offset)) {
                $start = $match[0][1];
                $open = $start + \strlen($match[0][0]) - 1;

                [$commas, $close] = self::countTopLevelCommas($code, $open);

                if ($commas < self::ARITY - 1) {
                    $line = substr_count($code, "\n", 0, $start) + 1;
                    $found[] = new Diagnostic(
                        $this->name(),
                        Diagnostic::ERROR,
                        $relative,
                        $line,
                        '构造滚动轴没传宿主（`new ScrollLane($start, $fade)` 少了第三个实参 `$this`）：'
                        . '滚过之后没人 `requestFrame()` ⇒ 这个 State 进不了动效活跃表 ⇒ '
                        . '`tickFrame()` 不来 ⇒ 滚动条的 900ms 空闲计时永远不推进 ⇒ '
                        . '条**永远全亮**（滚轮会淡、拖条不会淡）。'
                        . '改成 `new ScrollLane(0.0, null, $this)` / `new ScrollLane(0.0, $fade, $this)`。'
                    );
                }

                $offset = $close + 1;
            }
        }

        return $found;
    }

    /**
     * 从开括号位置 `$open` 起，数到配对的闭括号，返回 `[顶层逗号数, 闭括号位置]`。
     *
     * ★ 三种括号都当一层：实参里可能有 `$this->bar()` / `[Axis::Vertical]` /
     *   `new Foo(...)` —— 只看最外层那一段（深度 == 1）的逗号。
     *
     * @return array{0: int, 1: int}
     */
    private static function countTopLevelCommas(string $code, int $open): array
    {
        $depth = 0;
        $commas = 0;
        $length = \strlen($code);

        for ($i = $open; $i < $length; $i++) {
            $char = $code[$i];

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;

                continue;
            }

            if ($char === ')' || $char === ']' || $char === '}') {
                $depth--;

                if ($depth === 0) {
                    return [$commas, $i];
                }

                continue;
            }

            if ($char === ',' && $depth === 1) {
                $commas++;
            }
        }

        return [$commas, $length - 1];   // 括号没闭合 —— 交给 PHP 自己的语法错误
    }
}
