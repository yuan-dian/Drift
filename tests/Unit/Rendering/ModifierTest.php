<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Layout\RenderBox;
use Drift\Rendering\Color;
use Drift\Rendering\Modifier;

/**
 * `Modifier::isDefault()`：**每一个 `with*()` 都必须让它变假**。
 *
 * ## 为什么这条判据重要到要单独立文件
 *
 * `isDefault()` 只有一个用途：`Element::syncBox()` 判断"这个控件声明了修饰、但它
 * 自己不产盒"（`StatefulWidget` / `StatelessWidget` 的盒在子控件里，比如
 * `TextField` 的盒在 `TextFieldBox`）。那种情况下修饰**无处可去** ⇒ 必须响亮失败。
 *
 * 漏一个字段的后果是**静默丢掉**：`TextField::make()->paddingAll(8)` 尺寸一点没变、
 * 不报错、不写日志（G2 明令禁止的半静默）。
 *
 * ## 为什么是表驱动 + 完整性自检，不是"抽样四个字段"
 *
 * 原 smoke 的标题写"21 个字段都在判据里"，实际只抽了 4 个 —— 过度承诺。
 * 现在的判据是：① 表必须覆盖**全部** `with*()`（反射比方法名，新增一个 `with*()`
 * 而不补一行就红）；② 每一行都断言 `isDefault()` 的结果。
 *
 * ## ★ 已知缺口：`withRepaintBoundary()`
 *
 * `isDefault()` 读了 17 个字段，**没有读 `repaintBoundary`** ⇒ 表里那一行的期望
 * 值是"仍为真"。后果：在**不产盒**的控件上声明 `->repaintBoundary()` 会被静默丢掉
 * （其余修饰都会 Fatal）。
 *
 * 这条**没有**按"实现即真理"处理 —— 它是一个待裁决的缺口：`repaintBoundary` 是
 * 重绘缓存提示（丢了只是慢，不是画错），可能是有意排除的；但 `isDefault()` 的注释
 * 说的是"没有任何修饰"，按字面它该算。测试把**现状**钉住：哪天有人补上这个字段，
 * 这一行会红，逼一次明确决定，而不是静默改变行为。
 */
final class ModifierTest extends SmokeTestCase
{
    /**
     * 每个 `with*()` 一行：[工厂, 调用后 `isDefault()` 的期望值]。
     *
     * ★ 期望值是 `true` 的那一行是**已知缺口**（见类注释），不是"正确的默认"。
     *
     * @return array<string, array{0: callable(): Modifier, 1: bool}>
     */
    private static function cases(): array
    {
        return [
            'withPadding' => [static fn(): Modifier => (new Modifier())->withPadding(1.0, 1.0, 1.0, 1.0), false],
            'withSize' => [static fn(): Modifier => (new Modifier())->withSize(10.0, 10.0), false],
            'withWidth' => [static fn(): Modifier => (new Modifier())->withWidth(10.0), false],
            'withHeight' => [static fn(): Modifier => (new Modifier())->withHeight(10.0), false],
            'withAspect' => [static fn(): Modifier => (new Modifier())->withAspect(2.0), false],
            'withFill' => [static fn(): Modifier => (new Modifier())->withFill(Color::fromRgb(1, 2, 3)), false],
            'withStroke' => [static fn(): Modifier => (new Modifier())->withStroke(Color::fromRgb(1, 2, 3), 1.0), false],
            'withRadius' => [static fn(): Modifier => (new Modifier())->withRadius(4.0), false],
            'withShadow' => [static fn(): Modifier => (new Modifier())->withShadow(0.5), false],
            'withCenter' => [static fn(): Modifier => (new Modifier())->withCenter(true), false],
            'withCompose' => [static fn(): Modifier => (new Modifier())->withCompose(0.5, 1.0, 1.0), false],
            'withOpacity' => [static fn(): Modifier => (new Modifier())->withOpacity(0.5), false],
            'withTranslate' => [static fn(): Modifier => (new Modifier())->withTranslate(1.0, 1.0), false],
            'withClip' => [static fn(): Modifier => (new Modifier())->withClip(true), false],
            'withGrow' => [static fn(): Modifier => (new Modifier())->withGrow(1), false],
            'withShrink' => [static fn(): Modifier => (new Modifier())->withShrink(1), false],
            'withBasis' => [static fn(): Modifier => (new Modifier())->withBasis(10.0), false],
            'withAlign' => [static fn(): Modifier => (new Modifier())->withAlign(RenderBox::ALIGN_CENTER), false],
            // ★ 已知缺口：`isDefault()` 不读这个字段（见类注释）
            'withRepaintBoundary' => [static fn(): Modifier => (new Modifier())->withRepaintBoundary(true), true],
        ];
    }

    /**
     * 全默认 ⇒ `isDefault()` 为真（表里所有行的对照）。
     *
     * ★ 没有这一条，"每个 `with*()` 都为假"可以靠"`isDefault()` 恒假"通过。
     */
    public function testDefaultIsDefault(): void
    {
        check('Modifier · 全默认 ⇒ isDefault() 为真', (new Modifier())->isDefault() === true);
    }

    /**
     * 表覆盖全部 `with*()`（反射比方法名）—— 新增一个 `with*()` 必须补一行。
     *
     * ★ 这是**完整性**判据，不是行为判据：它挡的是"加了新修饰、忘了它也要参与
     *   `isDefault()`"，而那正是静默丢修饰的入口。
     */
    public function testTableCoversEveryWithMethod(): void
    {
        $declared = [];
        foreach ((new \ReflectionClass(Modifier::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (\str_starts_with($method->name, 'with')) {
                $declared[] = $method->name;
            }
        }
        \sort($declared);

        $covered = \array_keys(self::cases());
        \sort($covered);

        $missing = \array_values(\array_diff($declared, $covered));
        $extra = \array_values(\array_diff($covered, $declared));

        check(
            'Modifier · 表覆盖全部 with*()（新增一个 with*() 必须补一行，否则它可能不参与 isDefault）',
            $missing === [] && $extra === [],
            \sprintf('%d 个 with*() / 表 %d 行', \count($declared), \count($covered))
            . ($missing === [] ? '' : '；缺：' . \implode(',', $missing))
            . ($extra === [] ? '' : '；多：' . \implode(',', $extra))
        );
    }

    /**
     * 每一行都断言 `isDefault()` 的结果（唯一期望"真"的是已知缺口那一行）。
     *
     * ★ 汇总成一条：失败消息列出**所有**不符合的行名，不写"第一个就停" ——
     *   一次跑完能看出"是整个表都没生效"还是"只漏了一个字段"。
     */
    public function testEveryWithMethodChangesIsDefault(): void
    {
        $wrong = [];
        foreach (self::cases() as $name => [$factory, $expected]) {
            $actual = $factory()->isDefault();
            if ($actual !== $expected) {
                $wrong[] = $name . '（期望 ' . ($expected ? '真' : '假') . '，实际 ' . ($actual ? '真' : '假') . '）';
            }
        }

        check(
            'Modifier · 每个 with*() 都改 isDefault()（唯一例外 withRepaintBoundary —— 已知缺口）',
            $wrong === [],
            \implode('；', $wrong)
        );
    }

    /**
     * 下游证据：**不产盒**的控件上声明修饰 ⇒ 退出码 3 + 日志指明修法。
     *
     * ★ 必须子进程：`Errors::report()` 走 `abort()`（`exit(3)`），进程内 `try/catch`
     *   接不住 —— 写在同一进程里的现象是 smoke 静默截断、退出码仍 0。
     * ★ 两条一起才叫"响亮"：只验退出码会奖励"崩了但什么也没说"（用户拿不到修法）。
     * ★ 这条是 `isDefault()` 完整性的**下游**：哪个字段漏进 `isDefault()`，
     *   哪个字段的守卫就静默失效 —— 而 `withRepaintBoundary()` 正是当前漏的那个
     *   （见类注释；这里只钉 `padding`，因为它是唯一有现成守卫证据的字段）。
     */
    public function testDecorationOnNonBoxWidgetFailsLoudly(): void
    {
        $log = \sys_get_temp_dir() . '/drift-modstateful-' . \getmypid() . '.log';
        @\unlink($log);

        [$code, $out] = runFatalCase('modstateful', $log);
        $text = \is_file($log) ? (string) \file_get_contents($log) : '';
        @\unlink($log);

        check(
            'Modifier · 不产盒的控件上声明修饰 ⇒ 退出码 = 3（不静默丢）',
            $code === 3,
            'code=' . $code . ' out=' . \trim($out)
        );
        check(
            'Modifier · 该失败写了日志并指明修法（含"不产渲染盒"）',
            \str_contains($text, '不产渲染盒'),
            $text === '' ? '日志为空' : \mb_substr($text, 0, 120)
        );
    }
}
