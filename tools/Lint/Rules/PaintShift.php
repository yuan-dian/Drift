<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;

/**
 * 父容器画子盒必须走 `RenderBox::paintChildAt()`，不许自己拼那四行。
 *
 * ## 背景（2026-11-03）
 *
 * `save` → `translate($kid->offsetX(), $kid->offsetY())` → `paintComposed` →
 * `restore` 这四行原先在 **10 处**逐字重复：`RenderFlex` / `RenderStack` /
 * `RenderWrap` / `RenderDock` / `RenderSegmentedControl` / `RenderProxyBox` /
 * `RenderScrollView` / `RenderPopupPanel` / `RenderTable` ×2。
 *
 * 收成一处之后，**它会再长回来** —— 下一个写容器的人（或下一个 AI）复制邻近
 * 容器的 `paintContent()` 是最自然的动作，而复制出来的东西看起来完全正确。
 * 约定挡不住（`probe-audit` 的类注释已立过这条：约定挡不住，闸门挡得住）。
 *
 * ## 为什么这四行值得单独一条规则（失败形态）
 *
 * | 写错的方式 | 报错吗 | 现象 |
 * |---|---|---|
 * | 漏 `restore()` | `DisplayList::assertBalanced()` 报，但帧已画完 | 只知道"某处漏了" |
 * | `translate` 写在 `save` 之前 | **不报**（栈是配平的） | 后面所有兄弟整体偏移 |
 * | 漏 `translate` | **不报** | 全叠在盒左上角 |
 * | 少一个 `save` 但保留平移 | **不报** | 平移累积，越往后越偏（像布局错） |
 *
 * 后三种都不会让任何一道闸门变红：栈配平、命令合法、像素只是"不对"。
 * 错的那块代码完全正确，偏的是**别人**的位置 —— 定位成本是"逐盒二分"。
 *
 * ## 判据
 *
 * 文件在 `src/Layout/` 下，且出现 `->translate($X->offsetX(), $X->offsetY())`
 * （`\1` 反向引用保证两边是**同一个**变量）⇒ ERROR。
 *
 * ★ 只认这个形状，不认"出现 paintComposed 就算" —— `RenderButton` /
 *   `RenderMenuItem` 的子盒 offset 恒 0，它们**不需要**平移（直接
 *   `paintComposed()`），那是正确的，不该被这条规则推着改成 `paintChildAt`。
 * ★ `src/Layout/RenderBox.php` 整份豁免：`paintChildAt()` 的实现本体就在那里，
 *   它就是这四行的**唯一**合法载体。
 *
 * 服务 G2（静默失败）：这是本仓第四次同形态的债（段名五份、键位表两份、
 * 键名大小写两种、这个四行十份）—— 共同点都是"错了不响"。
 */
final class PaintShift implements Rule
{
    /** 允许自己写这四行的文件（`paintChildAt()` 的家） */
    private const HOME = 'src/Layout/RenderBox.php';

    /** `->translate($x->offsetX(), $x->offsetY())`；`\1` 保证两个参数同一个变量 */
    private const PATTERN = '/->translate\s*\(\s*(\$[A-Za-z_]\w*)->offsetX\s*\(\s*\)\s*,\s*\1->offsetY\s*\(\s*\)\s*\)/';

    public function name(): string
    {
        return 'paint-shift';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return 'src/Layout 不许手写「按子盒 offset 平移再画」—— 一律用 paintChildAt()';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            $relative = str_replace('\\', '/', $file->path());
            if (!str_starts_with($relative, 'src/Layout/') || $relative === self::HOME) {
                continue;
            }

            $code = $file->code();
            if (!preg_match_all(self::PATTERN, $code, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$hit, $offset]) {
                $line = substr_count($code, "\n", 0, $offset) + 1;
                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $relative,
                    $line,
                    sprintf(
                        '手写了「平移后画子盒」（%s）—— 改用 $this->paintChildAt($canvas, $child)。'
                        . '这四行在任何容器里都一样，手写一份就多一个入口：将来脏矩形（M5）要在'
                        . '这里加"与脏区域不相交就跳过"，漏一处 = 那类容器画错且不报错。'
                        . '另一个理由更现实：translate 写在 save 之前、或漏 restore，'
                        . '栈是配平的、整帧不报错，错的是后面所有兄弟的位置。',
                        $hit
                    )
                );
            }
        }

        return $found;
    }
}
