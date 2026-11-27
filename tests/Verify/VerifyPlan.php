<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Verify;

/**
 * V2 验证计划的**唯一事实源**：层 → step（一条判据通道）。
 *
 * ★ 为什么住 `tests/` 不住 `tools/`：runner 与清单是验证产品本身（对标 Flutter
 *   的 `dev/bots`），S4 删的是 V1 编排层 / 元判据 / 归档，不是 `tools/` 整体。
 * ★ S4 裁决（重编排不重判据）：V2 的判据**实现**继续住 `tools/`（Lint 规则、
 *   Smoke 夹具、静态对账、真机探针），本文件只声明命令，不复制 V1 编排层的逻辑；
 *   V1 的组内自证由题库取代。
 *
 * ★ 重编排不重判据：这里没有元判据递归 —— 不给每条判据配 `--selftest`，
 *   判据有没有牙由 `tests/Contracts/run-corpus.php` 的缺陷题库统一回答。
 *   `run-corpus.php --suite=v2` 直接读这份表生成通道集合，不会出现第二份清单。
 *
 * ★ 四层：
 *   static   —— 语言级静态检查 + 集合封闭（编译器没有的能力）
 *   behavior —— PHPUnit / golden / smoke，按 `tests/Unit|Feature/*` 目录分片
 *   device   —— AOT 构建，人工触发，绝不进日常闸门
 *   corpus   —— 缺陷题库对照（独立量具，见 `bin/drift verify --layer=corpus`）
 *
 * ★ tier：`fast` = 日常；`full` = `--full` 才跑（CI 只认这个）；`manual` = `--device`。
 *   文档检查已按 §8.1 显式退役，V2 里**没有** `docref` / `apidoc` 的位置。
 */
final class VerifyPlan
{
    /** @var list<string> --list 的层顺序 */
    public const LAYERS = ['static', 'behavior', 'device'];

    /**
     * @return array<string, array{
     *     id: string,
     *     layer: string,
     *     tier: string,
     *     class: string,
     *     label: string,
     *     command: list<string>
     * }>
     */
    public static function steps(string $root, string $app = 'hello'): array
    {
        $php = PHP_BINARY;

        return [
            'lint' => [
                'id'      => 'lint',
                'layer'   => 'static',
                'tier'    => 'fast',
                'class'   => 'static',
                'label'   => 'lint 规则集（含 S4 迁入的 project-name；条数看 tools/lint.php --list）',
                'command' => [$php, $root . '/tools/lint.php'],
            ],
            'workflows' => [
                'id'      => 'workflows',
                'layer'   => 'static',
                'tier'    => 'fast',
                'class'   => 'static',
                // 与 verify.yml 同一份脚本：不进计划就只能靠 CI 发现漂移（A28 删类时红过一次）。
                'label'   => 'CI workflow 结构判据（与 verify.yml 同一份脚本）',
                'command' => [$php, $root . '/.github/scripts/assert-workflows.php'],
            ],
            'sceneset' => [
                'id'      => 'sceneset',
                'layer'   => 'static',
                'tier'    => 'fast',
                'class'   => 'set',
                'label'   => '场景集合三向封闭',
                'command' => [$php, $root . '/tools/scene-set-check.php', '--json'],
            ],
            // ── S4：V1 tooling 组的 5 对静态对账，主判据提升为 V2 static step ──
            //   ★ 组内 `--selftest`（变异自证）不搬：V2 的判据可信度只由题库回答，
            //     不再给每条判据配一个自证（设计稿 §5）。脚本本身一行未改。
            'abi' => [
                'id'      => 'abi',
                'layer'   => 'static',
                'tier'    => 'fast',
                'class'   => 'static',
                'label'   => 'ABI 契约静态对账',
                'command' => [$php, $root . '/tools/abi-check.php'],
            ],
            'cppbridge' => [
                'id'      => 'cppbridge',
                'layer'   => 'static',
                'tier'    => 'fast',
                'class'   => 'static',
                'label'   => 'C++ 桥接结构对账',
                'command' => [$php, $root . '/tools/cpp-bridge-check.php'],
            ],
            'dxdiag' => [
                'id'      => 'dxdiag',
                'layer'   => 'static',
                'tier'    => 'fast',
                'class'   => 'static',
                'label'   => 'DX 退化判据对账',
                'command' => [$php, $root . '/tools/diagnostic-check.php'],
            ],
            'stalepolicy' => [
                'id'      => 'stalepolicy',
                'layer'   => 'static',
                'tier'    => 'fast',
                'class'   => 'static',
                'label'   => '构建 stale 决策接线',
                'command' => [$php, $root . '/tools/build-stale-check.php'],
            ],
            'errshape' => [
                'id'      => 'errshape',
                'layer'   => 'static',
                'tier'    => 'fast',
                'class'   => 'static',
                'label'   => '错误码贯通接线',
                'command' => [$php, $root . '/tools/error-shape-check.php'],
            ],
            'version' => [
                'id'      => 'version',
                'layer'   => 'static',
                'tier'    => 'fast',
                'class'   => 'static',
                'label'   => '版本真相源一致性',
                'command' => [$php, $root . '/tools/version.php', '--check'],
            ],
            'genversion' => [
                'id'      => 'genversion',
                'layer'   => 'static',
                'tier'    => 'fast',
                'class'   => 'static',
                'label'   => '版本派生副本接线',
                'command' => [$php, $root . '/tools/gen-version.php', '--check'],
            ],
            'devshell' => [
                'id'      => 'devshell',
                'layer'   => 'static',
                'tier'    => 'fast',
                'class'   => 'static',
                'label'   => '开发态壳红线（src/ 不进 sources）',
                'command' => [$php, $root . '/tools/drift-dev.php', '--selftest'],
            ],
            'theme' => [
                'id'      => 'theme',
                'layer'   => 'behavior',
                'tier'    => 'fast',
                'class'   => 'behavior',
                'label'   => '语义 token 有真实消费者（feature 切片）',
                'command' => [...self::phpunitBase($root), $root . '/tests/Feature/Table/ThemeMetricTest.php'],
            ],
            'ir' => [
                'id'      => 'ir',
                'layer'   => 'behavior',
                'tier'    => 'fast',
                'class'   => 'ir',
                'label'   => 'IR 快照（绘制命令流结构，锚点从像素上移）',
                'command' => [$php, $root . '/tests/Verify/ir-snapshot.php'],
            ],
            'unit' => [
                'id'      => 'unit',
                'layer'   => 'behavior',
                'tier'    => 'fast',
                'class'   => 'unit',
                'label'   => 'PHPUnit unit 全量',
                'command' => [...self::phpunitBase($root), '--testsuite=unit'],
            ],
            'feature' => [
                'id'      => 'feature',
                'layer'   => 'behavior',
                'tier'    => 'full',
                'class'   => 'behavior',
                'label'   => 'PHPUnit feature 全量（最贵，也最不可替代）',
                'command' => [...self::phpunitBase($root), '--testsuite=feature'],
            ],
            'pages' => [
                'id'      => 'pages',
                'layer'   => 'behavior',
                'tier'    => 'full',
                'class'   => 'pixel',
                'label'   => '整页像素基线（独立把 RGBA 算出来）',
                'command' => [$php, $root . '/tools/golden-raster.php', '--check', '--pages', '--json'],
            ],
            'smoke' => [
                'id'      => 'smoke',
                'layer'   => 'behavior',
                'tier'    => 'full',
                'class'   => 'behavior',
                'label'   => '行为 smoke（渲染 / 布局 / 输入 / 异步）',
                'command' => [$php, $root . '/tools/smoke.php'],
            ],
            // 重开判据：不比对基线，只要求"三次重开得到同一份因果与结构"。
            // 它咬的是跨会话残留（单例没清、静态缓存跨页漂），不是像素或数值阈值。
            'reopen' => [
                'id'      => 'reopen',
                'layer'   => 'behavior',
                'tier'    => 'full',
                'class'   => 'behavior',
                'label'   => '重开同一页三次：因果 / 结构 / 录下的脚本逐值相同（--assert）',
                'command' => [$php, $root . '/devtools/bin/debug-loop.php', '--page=table', '--runs=3', '--assert'],
            ],
            'aot' => [
                'id'      => 'aot',
                'layer'   => 'device',
                'tier'    => 'manual',
                'class'   => 'aot',
                'label'   => 'AOT 真机构建（需 MSVC + SDK；真机探针走 bin/drift probe）',
                'command' => [$php, $root . '/tools/drift-build.php', $app, '--full'],
            ],
            // ── S4：需 MSVC / WIC 或较贵的判据挂 device/manual，绝不进日常闸门 ──
            'sym' => [
                'id'      => 'sym',
                'layer'   => 'device',
                'tier'    => 'manual',
                'class'   => 'device',
                'label'   => '崩溃符号翻译（合成 map 自证）',
                'command' => [$php, $root . '/tools/rva2sym.php', '--selftest'],
            ],
            'crashctx' => [
                'id'      => 'crashctx',
                'layer'   => 'device',
                'tier'    => 'manual',
                'class'   => 'device',
                'label'   => '原生崩溃日志附上 PHP 黑匣子 sidecar',
                'command' => [$php, $root . '/tools/crash-context-selftest/run.php'],
            ],
            'decode' => [
                'id'      => 'decode',
                'layer'   => 'device',
                'tier'    => 'manual',
                'class'   => 'device',
                'label'   => '图片通道序（WIC 缩放器不认 RGBA）',
                'command' => [$php, $root . '/tools/decode-selftest/run.php'],
            ],
            'm2' => [
                'id'      => 'm2',
                'layer'   => 'device',
                'tier'    => 'manual',
                'class'   => 'device',
                'label'   => 'M2 conformance（W1–W8）',
                'command' => [$php, $root . '/tools/M2Conformance.php'],
            ],
        ];

    }

    /**
     * 分片 = `tests/Unit/*` 与 `tests/Feature/*` 的一级子目录，只用于增量选跑。
     *
     * ★ 分片结果**不能当验收报告**（与 `--defect` / `--probe` 裁剪同红线）：
     *   `--shard` 只回答「我改的这块还绿吗」，覆盖率由 `--full` + corpus 回答。
     *
     * @return array<string, array{
     *     id: string,
     *     layer: string,
     *     tier: string,
     *     class: string,
     *     label: string,
     *     command: list<string>
     * }>
     */
    public static function shards(string $root): array
    {
        $out = [];
        foreach (['unit' => 'tests/Unit', 'feature' => 'tests/Feature'] as $kind => $rel) {
            foreach (glob($root . '/' . $rel . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $name = basename($dir);
                $id   = $kind . ':' . $name;
                $out[$id] = [
                    'id'      => $id,
                    'layer'   => 'behavior',
                    'tier'    => $kind === 'feature' ? 'full' : 'fast',
                    'class'   => 'behavior',
                    'label'   => '分片 ' . $rel . '/' . $name,
                    'command' => [...self::phpunitBase($root), $dir],
                ];
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function phpunitBase(string $root): array
    {
        return [
            PHP_BINARY,
            $root . '/vendor/bin/phpunit',
            '-c', $root . '/phpunit.xml',
            '--no-coverage',
            '--do-not-cache-result',
        ];
    }
}
