<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Verify;

/**
 * V2 验证计划的**唯一事实源**：层 → step（一条判据通道）。
 *
 * ★ 为什么住 `tests/` 不住 `tools/`：S4 要删 `tools/` 的 292 个判据层文件，
 *   而 runner 与清单是验证产品本身（对标 Flutter 的 `dev/bots`），不能跟着被删。
 *   判据**实现**暂时仍在 `tools/`，由 S4 逐条迁移；本文件只声明命令，不复制
 *   `gate.php` / `group-run.php` / `CheckRegistry` 的任何判据逻辑。
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
                'label'   => 'lint 32 规则（编译期保证的文本替身）',
                'command' => [$php, $root . '/tools/lint.php'],
            ],
            'sceneset' => [
                'id'      => 'sceneset',
                'layer'   => 'static',
                'tier'    => 'fast',
                'class'   => 'set',
                'label'   => '场景集合三向封闭',
                'command' => [$php, $root . '/tools/scene-set-check.php', '--json'],
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
            'aot' => [
                'id'      => 'aot',
                'layer'   => 'device',
                'tier'    => 'manual',
                'class'   => 'aot',
                'label'   => 'AOT 真机构建（需 MSVC + SDK；真机探针走 bin/drift probe）',
                'command' => [$php, $root . '/tools/drift-build.php', $app, '--full'],
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
