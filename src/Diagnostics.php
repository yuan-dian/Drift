<?php
declare(strict_types=1);

namespace Drift;

/**
 * DX 类静默退化的唯一出口（REVIEW §1.2）。
 *
 * ★ 与 {@see Errors} 的分工：`Errors` 管「错误与数据降级」，本类管「没报错、
 *   但能力悄悄没了」。两者共用 `Errors::DIAGNOSTIC` 通道，于是诊断能被
 *   `Errors::recentDiagnostics()` 读到，也会随任何一次中止一起落盘。
 *
 * ★ 三条约束：① 统一前缀 —— 同一 ring 里能把「正常降级」与「纪律退化」分开；
 *   ② 按 key 只报一次 —— 一帧内同一退化可能触发几十次，刷屏会把 ring 冲掉
 *   （同 `LayoutTolerance::clipped()` / Chromium `LOG_ONCE`）；③ 不中止 ——
 *   退化不是错误，中止只是把「静默」换成另一种「看不见」。
 *
 * @internal 框架检测点专用；业务代码只用 `Errors::report()`
 */
final class Diagnostics
{
    public const string PREFIX = '[DX] ';

    /** @var array<string, true> 本进程已报过的 key */
    private static array $reported = [];

    private function __construct()
    {
    }

    /**
     * 记一条静默退化；同一个 key 只记一次。
     *
     * @param string $key 稳定分类键（如 `select/unstable`），判据按它断言
     */
    public static function degraded(string $key, string $message): void
    {
        if ($key === '') {
            Errors::report('Diagnostics::degraded() 的 key 不能为空');
        }

        if (isset(self::$reported[$key])) {
            return;
        }

        self::$reported[$key] = true;
        Errors::report(self::PREFIX . $key . ': ' . $message, Errors::DIAGNOSTIC);
    }

    /** @internal 测试与多实例场景：只清去重状态，不动 `Errors` 的 ring */
    public static function reset(): void
    {
        self::$reported = [];
    }

    /** @return list<string> 已报过的 key（时间正序） */
    public static function reported(): array
    {
        return array_keys(self::$reported);
    }
}
