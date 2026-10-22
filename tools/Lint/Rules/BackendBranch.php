<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;

/**
 * 控件 / 布局层不许出现具体画布后端的类型名（G2：测试与真机必须同一套行为）。
 *
 * 背景：`05` §2.5 判据推论 1。控件里写 `if ($canvas instanceof RasterCanvas)`
 * 分叉 ⇒ 同一种 Widget 在 preview / golden / smoke（软光栅）与真机（Skia）
 * 下走两条路。这正是"双轨"的起点：两条路各自演进，某一天其中一条坏了
 * 而测试全绿（因为测试走的是另一条）。
 *
 * 判据（两条同时满足才报）：
 *   ① 文件在 `src/Widget/` 或 `src/Layout/` 下（控件与渲染层；`src/Rendering/`
 *      是后端自己的家，随便提；`apps/` 的 demo 页允许查能力位，见下）；
 *   ② 出现了 `RasterCanvas` / `SkiaCanvas` / `RecordingCanvas` 的**类型名**
 *      （短名 / 限定名 / 全限定名的末段，大小写不敏感）。
 *
 * ★ 只扫类型名（`T_STRING` / `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED`），
 *   **注释与字符串里提到不算** —— docblock 写"软光栅画布"是文档不是分叉。
 * ★ `apps/` 不在判据内：demo 页**允许**用 `capabilities()` 查位后决定画什么
 *   （那是 `05` §2.5 认可的正确形态），但 `instanceof` 分叉同样禁止 ——
 *   由 `optional-op-guard` 从另一个方向守"发起 op 前查能力位"。
 * ★ 现在是 0 违规，但没人守。规则的价值在"下一个想分叉的人"。
 * ★ **豁免机制**（文件级）：文件里出现 `@backend-branch-allowed:` + 理由即
 *   整份跳过。给"用 RecordingCanvas 当命令缓冲"（`RenderText` / `RenderTextField`
 *   的既定形态 —— 那不是分叉，是 DisplayList 的正常消费）用；**新增**豁免
 *   要在注释里写清"为什么这不是双轨"，否则宁可以报推着人改成查能力位。
 */
final class BackendBranch implements Rule
{
    /**
     * 三个具体后端的类名（小写）。
     *
     * @var array<string, true>
     */
    private const BACKENDS = [
        'rastercanvas' => true,
        'skiacanvas' => true,
        'recordingcanvas' => true,
    ];

    /** 文件级豁免标记（与 `internal-leak` 的 `@internal-use-allowed:` 同形态） */
    private const EXEMPT_MARK = '@backend-branch-allowed:';

    public function name(): string
    {
        return 'backend-branch';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return 'src/Widget 与 src/Layout 不许出现具体画布后端的类型名（禁 instanceof 分叉）';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            $relative = $file->path();
            if (!str_starts_with($relative, 'src/Widget/') && !str_starts_with($relative, 'src/Layout/')) {
                continue;
            }
            if (str_contains($file->code(), self::EXEMPT_MARK)) {
                continue; // 显式豁免（见类 docblock）
            }

            foreach ($file->tokens() as $token) {
                if (!is_array($token)) {
                    continue; // 字符串/字符字面量：`'RasterCanvas'` 是数据不是类型
                }
                if ($token[0] !== T_STRING
                    && $token[0] !== T_NAME_QUALIFIED
                    && $token[0] !== T_NAME_FULLY_QUALIFIED
                ) {
                    continue;
                }
                $short = strtolower(self::shortName((string) $token[1]));
                if (!isset(self::BACKENDS[$short])) {
                    continue;
                }
                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $relative,
                    $token[2],
                    sprintf(
                        '控件/布局层出现了具体后端类型 %s —— 按后端分叉会让 preview/golden/smoke（软光栅）'
                        . '与真机（Skia）走两条路，某天其中一条坏了而测试全绿。两条修法：'
                        . '① 删掉分叉，用 Canvas 接口的通用能力（能力位见 capabilities()）；'
                        . '② 确实要按能力取舍就查能力位，不要判类型。',
                        (string) $token[1]
                    )
                );
            }
        }

        return $found;
    }

    /** 取限定名的末段（`A\B\RasterCanvas` → `RasterCanvas`） */
    private static function shortName(string $name): string
    {
        $pos = strrpos($name, '\\');

        return $pos === false ? $name : substr($name, $pos + 1);
    }
}
