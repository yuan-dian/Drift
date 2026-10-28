<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\Widget;

/**
 * `Widget` 的**形状**判据：能力面拆在 trait 里，基类不许再长回上帝类。
 *
 * ## 为什么这也要一条测试（2026-10-28，S2）
 *
 * `Widget.php` 曾经 1265 行 / 66 个 public 方法，其中 58 个是样板
 * （实测：21 写 `mods`、15 写字段、22 纯 getter，真逻辑 10 个）。
 * 拆成 4 个能力面之后，基类只剩身份与生命周期 11 个方法。
 *
 * ★ 光拆一次没用 —— **下一个人往基类里再加三个 setter 就回去了**。
 *   所以钉两条：① 基类自有的 public 方法数有上限；② 四个能力面必须还在。
 * ★ 第三、四条是**量具自证**：如果 `getTraits()` 因为改名/装载问题返回空，
 *   "基类方法数 ≤ 12" 会恒真（0 个 trait 也能过）⇒ 必须同时断言
 *   "trait 提供了 ≥ 50 个方法"。
 */
final class WidgetSurfaceTest extends SmokeTestCase
{
    /** 基类自己声明的 public 方法上限（现状 10：身份/生命周期/帧钩子） */
    private const BASE_PUBLIC_LIMIT = 12;

    /** 四个能力面（顺序无关） */
    private const FACES = ['WidgetModifiers', 'WidgetFocus', 'WidgetInteraction', 'WidgetSemantics'];

    public function testWidgetIsSplitIntoFourFaces(): void
    {
        // `getTraits()` 给的是全限定名 ⇒ 比短名（与 FACES 对齐）
        $traits = [];
        foreach (\array_keys((new \ReflectionClass(Widget::class))->getTraits()) as $fqn) {
            $traits[] = \substr((string) $fqn, (int) \strrpos((string) $fqn, '\\') + 1);
        }
        \sort($traits);

        $expected = self::FACES;
        \sort($expected);

        check(
            'Widget · 能力面拆成 4 个 trait（修饰 / 焦点 / 交互 / 语义）',
            $traits === $expected,
            '实际：' . \implode(',', $traits)
        );

        $this->flushChecks();
    }

    /**
     * ★ 量具自证：拆出去的**公开**方法必须真的多（≥ 50），否则上一条与下一条都可能恒真。
     */
    public function testFacesCarryMostOfTheSurface(): void
    {
        $rc = new \ReflectionClass(Widget::class);
        $fromTraits = 0;
        foreach ($rc->getTraits() as $trait) {
            foreach ($trait->getMethods() as $method) {
                if ($method->isPublic()) {
                    $fromTraits++;
                }
            }
        }

        check(
            'Widget · 能力面承载 ≥ 50 个 public 方法（拆分真的发生了）',
            $fromTraits >= 50,
            '实际 ' . $fromTraits . ' 个'
        );

        $this->flushChecks();
    }

    /**
     * 基类自有的 public 方法上限 —— 这是"不许再养肥"的那道闸。
     *
     * ★ 加控件时**不该**往基类加 fluent setter：要么进对应能力面，要么做成独立控件。
     */
    public function testBaseClassStaysSmall(): void
    {
        $rc = new \ReflectionClass(Widget::class);

        $traitMethods = [];
        foreach ($rc->getTraits() as $trait) {
            foreach ($trait->getMethods() as $method) {
                $traitMethods[$method->getName()] = true;
            }
        }

        $own = [];
        foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!isset($traitMethods[$method->getName()])) {
                $own[] = $method->getName();
            }
        }

        check(
            'Widget · 基类自有的 public 方法 ≤ ' . self::BASE_PUBLIC_LIMIT . '（新 setter 进能力面，别回基类）',
            \count($own) <= self::BASE_PUBLIC_LIMIT,
            \count($own) . ' 个：' . \implode(',', $own)
        );

        $this->flushChecks();
    }

    /**
     * 能力面里的方法**签名里不出现基类私有字段名**这类耦合 —— 只钉一条真正有用的：
     * `mods()` 必须留在基类（`Element::syncBox()` 只认那一个引用）。
     *
     * ★ 这条防的是"把 `mods()` 也搬进 trait"这种看似合理、实则让
     *   `Element::syncBox()` 的注释与实现脱节的搬法。
     */
    public function testModsAccessorStaysOnTheBaseClass(): void
    {
        $rc = new \ReflectionClass(Widget::class);
        $mods = $rc->getMethod('mods');

        $declaring = $mods->getDeclaringClass()->getName();

        check(
            'Widget · `mods()` 声明在基类上（`Element::syncBox()` 的唯一入口）',
            $declaring === Widget::class,
            '声明在 ' . $declaring
        );

        $this->flushChecks();
    }
}
