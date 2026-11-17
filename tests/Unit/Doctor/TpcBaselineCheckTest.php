<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Doctor;

use Drift\Dev\Doctor\Check;
use Drift\Dev\Doctor\Environment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * tpc 版本基线的三态判据：含基线 / 不含基线 / 无法判定。
 *
 * ## 为什么要有这条判据
 *
 * `tpc --version` 固定报 v0.10.0，区分不了 commit 级编译期修复（#183–#186）。
 * 此前 doctor 只验"入口文件在不在"，旧编译器要到构建期才炸。
 *
 * | 注入的 git 返回 | 期望状态 | 漏了会怎样 |
 * |---|---|---|
 * | 0（HEAD 含基线） | OK | 判据恒红 ⇒ 被当噪音关掉 |
 * | 1（不含） | FAIL | 旧编译器静默通过 ⇒ 构建期才炸 |
 * | 128（无法判定） | WARN | "没测到"被当成"没问题"（假绿） |
 *
 * ★ 用注入回调而非真 git：判据与实现不同源，且三态都能在任意机器咬红。
 *   真 git 的返回码由 gitAncestor() 单独封装，另有一条真机对照。
 * ★ 源码树根同样注入：公开 runner 没有 tpc 源码树，三态用例不能依赖它。
 */
final class TpcBaselineCheckTest extends TestCase
{
    /** 注入的树只要是个 git 仓库即可；本仓自身在任意 clone 上都存在。 */
    private static function tree(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 3));
    }

    #[Test]
    public function head含基线报OK(): void
    {
        $check = Environment::tpcBaselineCheck('', static fn (): int => 0, self::tree());

        self::assertSame(Check::OK, $check->status);
        self::assertStringContainsString(Environment::TPC_MIN_COMMIT, $check->detail);
        self::assertSame(['build'], $check->affects);
    }

    #[Test]
    public function head不含基线报FAIL(): void
    {
        $check = Environment::tpcBaselineCheck('', static fn (): int => 1, self::tree());

        self::assertSame(Check::FAIL, $check->status);
        self::assertStringContainsString('不含基线', $check->detail);
        self::assertNotNull($check->fix, 'FAIL 必须带可执行的修法');
    }

    #[Test]
    public function 无法判定报WARN不报OK(): void
    {
        $check = Environment::tpcBaselineCheck('', static fn (): int => 128, self::tree());

        self::assertSame(Check::WARN, $check->status);
        self::assertNotSame(Check::OK, $check->status, '没测到绝不能算通过');
    }

    #[Test]
    public function 注入参数确实透传给git查询(): void
    {
        $seen = [];
        Environment::tpcBaselineCheck('', function (string $tree, string $commit) use (&$seen): int {
            $seen = [$tree, $commit];

            return 0;
        }, self::tree());

        self::assertSame(self::tree(), $seen[0] ?? null, '注入回调必须拿到传入的源码树根');
        self::assertSame(Environment::TPC_MIN_COMMIT, $seen[1] ?? null);
    }

    #[Test]
    public function 真机git祖先判定与基线一致(): void
    {
        $tree = dirname(dirname((string) realpath(
            dirname(__DIR__, 3) . '/../typephp/bin/tpc.php'
        )));
        $tree = str_replace('\\', '/', $tree);
        if (!is_dir($tree . '/.git')) {
            self::markTestSkipped('本机无 sibling typephp git 源码树；三态判据已在上面用注入覆盖');
        }

        $rc = Environment::gitAncestor($tree, Environment::TPC_MIN_COMMIT);
        self::assertSame(0, $rc, '本仓 AOT 台账验过的基线必须是当前源码树的祖先');
    }
}
