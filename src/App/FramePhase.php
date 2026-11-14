<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Errors;
use Drift\Rendering\FrameStage;

/**
 * 帧的阶段顺序声明：只有"跨过 settle"是硬约束（A 组在 settle 前、
 * C 组在其后），其余顺序不强制；声明成表后 dev 期可断言谁越界。
 *
 * 越界后果都不是报错，而是慢一帧 / 读屏停住 / 焦点握着死节点。
 *
 * 文件开关：build/frame.trace 存在即开，关时开销可忽略。
 *
 * ★ 违规靠刷报告文件可见：Errors::report(DIAGNOSTIC) 只进进程内环形缓冲，
 *   真机独立进程读不到，"0 条违规"只能相信；每帧刷 build/frame.trace.out。
 *
 * @api dev 量具入口
 */
final class FramePhase
{
    /** 打开后每帧记 trace，帧末断言 */
    public static bool $on = false;

    /**
     * 报告文件（getcwd() 相对）。
     */
    private const string REPORT = 'build/frame.trace.out';

    /** 已断言帧数 */
    private static int $frames = 0;

    /** 违规次数（必须恒 0） */
    public static int $violations = 0;

    /** @var list<string> 最近几条违规的一行摘要 */
    public static array $recent = [];

    /** 初始化守卫（开关只读一次） */
    private static bool $booted = false;

    /**
     * @return list<string>|null
     */
    public static function begin(): ?array
    {
        self::boot();

        return self::$on ? [] : null;
    }

    /**
     * @param list<string> $trace 本帧实际执行过的段名（含 `settle` 标记）
     */
    public static function end(array $trace): void
    {
        self::$frames++;
        self::assertOrder($trace);
        self::writeReport();
    }

    /** 清零（测试用） */
    public static function reset(): void
    {
        self::$on = false;
        self::$booted = false;
        self::$frames = 0;
        self::$violations = 0;
        self::$recent = [];
    }

    /**
     * @param list<string> $trace
     */
    public static function assertOrder(array $trace): void
    {
        $settleIndex = array_search(FrameStage::SETTLE, $trace, true);
        if ($settleIndex === false) {
            self::flag(
                '帧阶段：本帧没有 settle 标记 —— Frame::run() 没被调用，'
                . '整帧的 setState 都没被消费。' . "\n"
                . '修法：确认 frame() 里 $frame->run(...) 那段没被提前 return 掉。',
                '缺 settle 标记'
            );

            return;
        }

        $index = [];
        foreach ($trace as $i => $name) {
            $index[$name] = $i;
        }

        foreach (FrameStage::beforeSettle() as $name) {
            if (!isset($index[$name])) {
                continue; // 缺失由下面的"必须出现"检查报
            }
            if ($index[$name] > $settleIndex) {
                self::flag(\sprintf(
                    "帧阶段：%s 在 settle 之后跑了 —— %s。\n"
                    . '修法：把它挪到 $frame->run(...) 之前（组 A 只做"调回调"，回调里只 setState/markDirty）。',
                    $name,
                    self::why($name),
                ), $name . ' 在 settle 之后');
            }
        }

        foreach (FrameStage::afterSettle() as $name) {
            if (!isset($index[$name])) {
                continue;
            }
            if ($index[$name] < $settleIndex) {
                self::flag(\sprintf(
                    "帧阶段：%s 在 settle 之前跑了 —— %s。\n"
                    . '修法：把它挪到 $frame->run(...) 之后（组 C 观察的是**新**树）。',
                    $name,
                    self::why($name),
                ), $name . ' 在 settle 之前');
            }
        }

        foreach (FrameStage::all() as $name) {
            if (!isset($index[$name])) {
                self::flag(\sprintf(
                    "帧阶段：本帧没跑 %s —— %s。\n"
                    . '修法：确认 frame() 里那段没被条件跳过；若确实要跳过，把名字从 FrameStage::ORDERED 里删掉并写理由。',
                    $name,
                    self::why($name),
                ), '缺 ' . $name);
            }
        }
    }

    /**
     * 后果文案。`null` 说明 `FrameStage` 的表漏了这项 —— 说出来，
     * 不要兜底成"会慢一帧"（那会让漏项看起来和正常一样）。
     */
    private static function why(string $stage): string
    {
        return FrameStage::consequence($stage)
            ?? '（FrameStage 的表里没登记这一段的后果 ⇒ 去补表）';
    }

    /**
     * ★ 摘要与 Diagnostic 是两个出口，缺一不可：
     *   · Diagnostic 进进程内环形缓冲（Errors::recentDiagnostics()，测试读）
     *   · 摘要进报告文件（真机可读）
     *   只有前者 ⇒ 真机上"看得见"等于不存在（见类 docblock）。
     */
    private static function flag(string $message, string $short): void
    {
        self::$violations++;
        self::$recent[] = \sprintf('帧 %d：%s', self::$frames, $short);
        if (count(self::$recent) > 8) {
            self::$recent = array_slice(self::$recent, -8);
        }
        Errors::report($message, Errors::DIAGNOSTIC);
    }

    /**
     * 把计数器刷进报告文件。
     *
     * 开关与报告同在 build/ 下 ⇒ 开关被 is_file() 找到时 build/ 一定存在，
     * 写不会因目录缺失而静默失败。
     */
    private static function writeReport(): void
    {
        $lines = [
            'switch     build/frame.trace',
            'frames     ' . self::$frames,
            'violations ' . self::$violations,
        ];
        foreach (self::$recent as $line) {
            $lines[] = '  · ' . $line;
        }
        \file_put_contents(self::REPORT, implode("\n", $lines) . "\n");
    }

    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        self::$on = self::$on || \is_file('build/frame.trace');
        if (self::$on) {
            // ★ 立刻落一份 frames 0：否则"没有报告文件"与"跑过 300 帧且干净"
            //   两种完全不同的情况在外面看起来一模一样 —— 那正是这个文件要消除的歧义。
            self::writeReport();
        }
    }
}
