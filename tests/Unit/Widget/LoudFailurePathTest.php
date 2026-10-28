<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * **响亮失败路径自己不能是坏的**（2026-10-28，A4 第二件顺带挖出）。
 *
 * ## 挖出它的经过
 *
 * 给 `Wrap::alignment(Stretch)` 写响亮失败时，第一次跑出来的是
 * `Error: Class "Drift\Widget\Traits\Errors" not found` —— 不是设计好的
 * "退出码 3 + 人话 + 修法"。
 *
 * 顺着查到 `WidgetModifiers`：**上一轮 S2 把方法搬进 trait 时，没把
 * `use Drift\Errors;` 一起搬过去**。于是 `grow(-1)` / `shrink(-1)` / `opacity(1.5)`
 * 三条响亮失败路径**一直是坏的** —— 用户看到的是 PHP 的类找不到，没有日志、
 * 没有修法、退出码也不是 3。
 *
 * ⇒ 这是最坏的一类静默：**失败路径自己失败，而且没人会去测失败路径**。
 *   （`InstanceofMissingUse` 那条 lint 规则守的是 `instanceof` 漏 `use` ⇒ 恒 false；
 *    静态调用漏 `use` 是同族，但它是"命中才炸"，更不容易被发现。）
 *
 * ## 两条判据
 *
 * ① **表驱动**：Widget 层的每个响亮失败入口都跑子进程，断言退出码 `=== 3`
 *    **且**日志里含那段人话（新增入口 ⇒ 必须登记）。
 * ② **静态扫描**：任何出现裸 `Errors::` 的源文件，必须有 `use Drift\Errors;`
 *    （或自己就在 `Drift` 命名空间下）。—— 这条是"下次再搬 trait 还会丢"的闸。
 *
 * ★ ① 为什么必须两条一起：只验退出码 3 会奖励"崩了但什么也没说"。
 * ★ ② 为什么是扫描而不是 lint Rule：这条只对**一个**类名（`Errors`）成立，
 *   做成通用规则要处理"别名 / 全局命名空间 / 同命名空间"三种例外，成本不划算
 *   ⇒ 收在测试里，边界诚实。
 */
final class LoudFailurePathTest extends SmokeTestCase
{
    /**
     * Widget 层的响亮失败入口：[case 名, 日志里必须出现的那句话]。
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function cases(): array
    {
        return [
            'mod-grow-negative' => ['mod-grow-negative', 'grow 份额不能为负'],
            'mod-opacity-range' => ['mod-opacity-range', 'opacity 应在'],
            'wrap-stretch'      => ['wrap-stretch', '尚未实现'],
        ];
    }

    public function testEveryLoudFailureSaysHowToFixIt(): void
    {
        $log = \sys_get_temp_dir() . '/drift-loud-' . \getmypid() . '.log';
        $bad = [];

        foreach (self::cases() as $name => [$case, $needle]) {
            @\unlink($log);
            [$code, $out] = runFatalCase($case, $log);
            $text = \is_file($log) ? (string) \file_get_contents($log) : '';

            if ($code !== 3) {
                $bad[] = $name . '：退出码 ' . $code . '（不是 3）' . ($out === '' ? '' : ' out=' . \trim($out));
            } elseif (!\str_contains($text, $needle)) {
                $bad[] = $name . '：日志里没有「' . $needle . '」' . ($text === '' ? '（日志为空）' : '');
            }
        }

        @\unlink($log);

        check(
            '响亮失败 · 每个入口都是退出码 3 + 人话（不是"崩了什么也没说"）',
            $bad === [],
            \implode('；', $bad),
        );

        $this->flushChecks();
    }

    /**
     * 静态闸门：出现裸 `Errors::` 的文件必须 `use Drift\Errors;`（或本就在 `Drift` 下）。
     *
     * ★ 这条防的是**下一次搬 trait / 抽文件**又把 `use` 丢掉 —— 那次的症状还是
     *   `Class not found`，而且只在"用户真的踩到校验"时才出现。
     * ★ 只扫 `src/`：工具与测试允许各有各的写法，管它们是"用一个规则覆盖两个语义"。
     */
    public function testNoBareErrorsReferenceWithoutUse(): void
    {
        $root = repoRoot() . '/src';
        $missing = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) \file_get_contents($file->getPathname());
            if (!self::callsErrorsInCode($src)) {
                continue;
            }

            // 自己就在 `Drift` 命名空间下（`src/Errors.php` 自己、同命名空间的文件）
            $inDrift = \preg_match('/^namespace\s+Drift\s*;/m', $src) === 1;
            $hasUse = \preg_match('/^use\s+Drift\\\\Errors\s*;/m', $src) === 1;
            $fullyQualified = \preg_match('/\\\\Drift\\\\Errors::/', $src) === 1;

            if (!$inDrift && !$hasUse && !$fullyQualified) {
                $missing[] = \str_replace($root . '/', '', $file->getPathname());
            }
        }

        \sort($missing);

        check(
            '响亮失败 · 裸 `Errors::` 必须带 `use Drift\Errors;`（S2 拆 trait 时丢过一次 ⇒ 校验路径全变成 Class not found）',
            $missing === [],
            $missing === [] ? '' : '缺 use：' . \implode(', ', $missing),
        );

        $this->flushChecks();
    }

    /**
     * 源码里**真的**调了 `Errors::`（不是注释里提到它）。
     *
     * ★ 第一版用 `str_contains($src, 'Errors::')` ⇒ 命中 7 个文件，其中 4 个只是
     *   docblock 里的文字（"`=> Errors::report('尚未实现')` 的死分支"这类）。
     *   把它们也算进来是典型的**误报**：会逼人给注释里的类名补 `use`。
     * ⇒ 逐行判：跳过 `*`（docblock 行）、`//`、`/*` 开头的行。
     */
    private static function callsErrorsInCode(string $src): bool
    {
        foreach (\explode("\n", $src) as $line) {
            $trim = \trim($line);
            if ($trim === '') {
                continue;
            }
            if (\str_starts_with($trim, '*')
                || \str_starts_with($trim, '/*')
                || \str_starts_with($trim, '//')
                || \str_starts_with($trim, '#')) {
                continue;   // 注释行
            }
            if (\str_contains($trim, 'Errors::')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 量具自证：扫描真的看到了"用了 `Errors::`"的文件（≥ 3 个）。
     *
     * ★ 没有这条，路径写错 / 正则写坏时"0 个文件"也能让上一条恒真。
     */
    public function testTheGaugeSeesRealUsers(): void
    {
        $root = repoRoot() . '/src';
        $users = 0;

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                if (self::callsErrorsInCode((string) \file_get_contents($file->getPathname()))) {
                    ++$users;
                }
            }
        }

        check(
            '响亮失败 · 量具自证：src 里真的有 ≥ 3 个文件在调 Errors::（扫描没写坏）',
            $users >= 3,
            '实际 ' . $users . ' 个',
        );

        $this->flushChecks();
    }
}
