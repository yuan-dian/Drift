<?php
declare(strict_types=1);

/**
 * `docs/API-SURFACE.md` 与代码是否一致（gate 的 `apidoc` 项）。
 *
 * ```bash
 * php tools/api-doc-check.php                      # 只检查，exit 0/1
 * php tools/api-doc-check.php --write              # 重新生成并写回
 * ```
 *
 * ## 为什么需要它（2026-11-03）
 *
 * `API-SURFACE.md` 的抬头写着"由 `--api` 自动生成，勿手改"—— 但**没有任何东西
 * 检查它是新的**。于是它漂了：上一棒的 `FrameStage`（新加 `@api` 类）没进文档，
 * 而"文档里有的行号"整体偏了几十行（谁增删代码谁就会让它偏）。
 *
 * 这就是本仓第四次同一形态的债：**同一份事实两处维护，两处会漂，且漂了不报错**
 * （段名五份、键位表两份、键名大小写两种、绘制四行十份 —— 这是第五次）。
 *
 * ## 为什么先把行号从文档里去掉，再来做这个检查
 *
 * 带行号时，"重生成"会让每一个改过代码的提交都产生上百行 diff —— 那样的闸门
 * 只有两种结局：要么天天红（人就开始 `--no-verify`），要么被当噪声忽略。
 * 去掉行号后，这份文档只在 **`@api` 增删**时变化，那才是需要人看见的事。
 *
 * ★ 与 `docref` 的分工：`docref` 查"文档里引用的符号/章节**存在吗**"；
 *   这条查"这份**清单**是最新的吗"。前者防指向虚无，后者防内容过期。
 */

require __DIR__ . '/autoload.php';

use Drift\Dev\Lint\ApiSurface;

$root = \dirname(__DIR__);
$target = $root . '/docs/API-SURFACE.md';

$rendered = ApiSurface::render(ApiSurface::scan($root)) . "\n";

if (\in_array('--write', \array_slice($argv, 1), true)) {
    \file_put_contents($target, $rendered);
    echo "已写入 docs/API-SURFACE.md\n";
    exit(0);
}

$current = @\file_get_contents($target);
if ($current === false) {
    echo "docs/API-SURFACE.md 不存在 —— 跑 php tools/api-doc-check.php --write\n";
    exit(1);
}

if ($current === $rendered) {
    echo "API 面清单与代码一致\n";
    exit(0);
}

// 差异行号只打前 10 处：整份 diff 有几百行时，人只需要知道"哪一边多了符号"
$old = \explode("\n", $current);
$new = \explode("\n", $rendered);
$onlyOld = \array_values(\array_diff($old, $new));
$onlyNew = \array_values(\array_diff($new, $old));

echo "docs/API-SURFACE.md 与代码不一致 —— 跑 php tools/api-doc-check.php --write\n";
foreach (\array_slice($onlyOld, 0, 10) as $line) {
    echo "  - 文档有、代码无：{$line}\n";
}
foreach (\array_slice($onlyNew, 0, 10) as $line) {
    echo "  + 代码有、文档无：{$line}\n";
}
if (\count($onlyOld) > 10 || \count($onlyNew) > 10) {
    echo "  …（共 " . \count($onlyOld) . ' / ' . \count($onlyNew) . " 行，只列前 10）\n";
}

exit(1);
