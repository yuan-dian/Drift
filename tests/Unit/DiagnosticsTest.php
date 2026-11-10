<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Diagnostics;
use Drift\Errors;

/**
 * `Diagnostics` —— 静默退化可观测契约（REVIEW §1.2）。
 *
 * ★ 判据只从 `Errors::recentDiagnostics()` 读，不碰内部状态（`reset()` 除外）：
 *   ring 才是 devtools / gate 真正消费的那一份事实。
 * ★ 断言用「前后条数差」，不用 `Errors::reset()` —— 后者会连带清掉别的用例
 *   留下的现场（TEST-STRATEGY §518）。
 */
final class DiagnosticsTest extends SmokeTestCase
{
    public function testDegradedIsPrefixedDeduplicatedAndQueryable(): void
    {
        Diagnostics::reset();
        $before = \count(Errors::recentDiagnostics());

        Diagnostics::degraded('probe/a', '第一次');
        Diagnostics::degraded('probe/a', '第二次不该再记');
        Diagnostics::degraded('probe/b', '另一个退化');

        $ring = \array_slice(Errors::recentDiagnostics(), $before);
        check(
            'Diagnostics · 同一 key 只记一次',
            \count($ring) === 2,
            'ring=' . \json_encode($ring, \JSON_UNESCAPED_UNICODE)
        );
        check(
            'Diagnostics · 前缀 + key + 首次消息',
            $ring === ['[DX] probe/a: 第一次', '[DX] probe/b: 另一个退化'],
            \json_encode($ring, \JSON_UNESCAPED_UNICODE) ?: ''
        );
        check(
            'Diagnostics · 查询出口按时间正序给出 key',
            Diagnostics::reported() === ['probe/a', 'probe/b'],
            \json_encode(Diagnostics::reported()) ?: ''
        );

        $this->flushChecks();
    }

    public function testNormalDegradationIsNotMisclassified(): void
    {
        Diagnostics::reset();
        $before = \count(Errors::recentDiagnostics());

        Errors::report('字体文件不存在（正常降级）', Errors::DIAGNOSTIC);
        $ring = \array_slice(Errors::recentDiagnostics(), $before);

        check(
            'Diagnostics · 正常降级不带 [DX] 前缀（同一 ring 可分类）',
            !\str_contains(\implode('|', $ring), Diagnostics::PREFIX),
            \implode('|', $ring)
        );
        check('Diagnostics · 正常降级不进 reported()', Diagnostics::reported() === []);

        $this->flushChecks();
    }

    public function testResetClearsOnlyDedupState(): void
    {
        Diagnostics::reset();
        Diagnostics::degraded('probe/reset', '一');
        $before = \count(Errors::recentDiagnostics());

        Diagnostics::reset();
        check('Diagnostics · reset() 清空去重状态', Diagnostics::reported() === []);

        Diagnostics::degraded('probe/reset', '二');
        $ring = \array_slice(Errors::recentDiagnostics(), $before);
        check(
            'Diagnostics · reset() 后同 key 可再记，且不动 Errors 的 ring',
            $ring === ['[DX] probe/reset: 二'],
            \json_encode($ring, \JSON_UNESCAPED_UNICODE) ?: ''
        );

        $this->flushChecks();
    }
}
