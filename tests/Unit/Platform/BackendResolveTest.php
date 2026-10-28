<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Platform;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Platform\Win32\Win32SurfaceProvider;

/**
 * 后端解析（§7.2 裁决 4：2026-10-17 默认从 `soft` 翻成 `skia`）。
 *
 * ## 为什么值得单独立项
 *
 * 两条失败都是**静默**的，且症状离原因很远：
 * ① 默认没翻 ⇒ 界面继续锯齿（看着像"字模糙"，其实是走了软光栅）；
 * ② 显式 `'soft'` 被默认值吞掉 ⇒ 用户以为指定了确定性后端，实际跑的是 skia
 *    （golden 对比在某些机器上开始漂，但没有任何一处报错）。
 *
 * ## 表驱动
 *
 * 口径是**枚举性**的（`null` / `'skia'` / `'soft'` / 未知），将来加后端只加一行。
 *
 * ★ 表里必须配一条"默认值不是 `soft`"的前置判据：若哪天默认翻回 `soft`，
 *   第 3 行（显式 soft ⇒ soft）就变成"正解与兜底取值相同" ⇒ 对该值**恒真**，
 *   假绿且不会有人发现。
 */
final class BackendResolveTest extends SmokeTestCase
{
    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    private static function table(): array
    {
        return [
            'null ⇒ 默认' => [null, 'skia'],
            '显式 skia' => ['skia', 'skia'],
            '显式 soft' => ['soft', 'soft'],
            '未知值退回默认' => ['vulkan', 'skia'],
        ];
    }

    public function testResolveBackendTable(): void
    {
        $default = Win32SurfaceProvider::resolveBackend(null);

        check(
            'Platform · 前置：默认后端不是 soft（否则下面"显式 soft"那行对该值恒真）',
            $default !== 'soft',
            '默认实际 ' . $default,
        );

        foreach (self::table() as $label => [$input, $expected]) {
            $actual = Win32SurfaceProvider::resolveBackend($input);
            check(
                'Platform · ' . $label . ' ⇒ ' . $expected,
                $actual === $expected,
                '入参 ' . ($input === null ? 'null' : "'" . $input . "'") . '，实际 ' . $actual,
            );
        }
    }
}
