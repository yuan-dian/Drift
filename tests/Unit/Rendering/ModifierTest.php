<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Alignment;
use Drift\Geometry\Matrix2D;
use Drift\Layout\RenderBox;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\Color;
use Drift\Rendering\DashPattern;
use Drift\Rendering\Gradient;
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
 * ## ★ 曾经的缺口 `withRepaintBoundary()`（2026-10-28 已补，S1）
 *
 * `isDefault()` 原来读 16 个字段、**漏 `repaintBoundary`** ⇒ 在**不产盒**的控件上
 * 声明 `->repaintBoundary()` 会被静默丢掉（其余修饰都会 Fatal）。当时测试把现状
 * 钉住、把裁决留给下一轮。
 *
 * S1 直接补上了：`isDefault()` 的注释写的是"没有任何修饰"，那它就必须覆盖全部字段；
 * "重绘边界丢了只是慢"是**后果**的辩解，不是"它不算修饰"的理由 —— 而静默失效的
 * 检测成本远高于一次多画的代价。
 *
 * ⇒ 现在表里**每一行都期待 `false`**，没有例外。
 */
final class ModifierTest extends SmokeTestCase
{
    /**
     * 每个 `with*()` 一行：[工厂, 调用后 `isDefault()` 的期望值, 该写法相对空修饰的 `diff()` 位]。
     *
     * ★ 期望值**一律 `false`** —— 没有例外（原来 `withRepaintBoundary` 那一行是
     *   `true`，S1 补字段后改回 `false`，见类注释）。
     * ★ 第三列是**归类判据**：`diff()` 说"几何变了"的会被 `RenderBox::setMods()`
     *   转成"重排"，说"只有绘制变了"的只推一次重画。归错组的后果是**多排一次**
     *   （安全方向）或**漏排**（画面停住且不报错）—— 后者只有这张表能挡住。
     *
     * @return array<string, array{0: callable(): Modifier, 1: bool, 2: int}>
     */
    private static function cases(): array
    {
        return [
            'withPadding' => [static fn(): Modifier => (new Modifier())->withPadding(1.0, 1.0, 1.0, 1.0), false, Modifier::DIFF_GEOMETRY],
            'withSize' => [static fn(): Modifier => (new Modifier())->withSize(10.0, 10.0), false, Modifier::DIFF_GEOMETRY],
            'withWidth' => [static fn(): Modifier => (new Modifier())->withWidth(10.0), false, Modifier::DIFF_GEOMETRY],
            'withHeight' => [static fn(): Modifier => (new Modifier())->withHeight(10.0), false, Modifier::DIFF_GEOMETRY],
            'withAspect' => [static fn(): Modifier => (new Modifier())->withAspect(2.0), false, Modifier::DIFF_GEOMETRY],
            'withFill' => [static fn(): Modifier => (new Modifier())->withFill(Color::fromRgb(1, 2, 3)), false, Modifier::DIFF_VISUAL],
            'withGradient' => [
                static fn(): Modifier => (new Modifier())->withGradient(
                    Gradient::vertical([Color::white(), Color::black()]),
                ),
                false,
                Modifier::DIFF_VISUAL,
            ],
            'withStroke' => [static fn(): Modifier => (new Modifier())->withStroke(Color::fromRgb(1, 2, 3), 1.0), false, Modifier::DIFF_VISUAL],
            'withBorderDash' => [
                static fn(): Modifier => (new Modifier())->withBorderDash(new DashPattern(6.0, 4.0)),
                false,
                Modifier::DIFF_VISUAL,
            ],
            'withRadius' => [static fn(): Modifier => (new Modifier())->withRadius(4.0), false, Modifier::DIFF_VISUAL],
            'withShadow' => [static fn(): Modifier => (new Modifier())->withShadow(0.5), false, Modifier::DIFF_VISUAL],
            'withCompose' => [static fn(): Modifier => (new Modifier())->withCompose(0.5, 1.0, 1.0), false, Modifier::DIFF_VISUAL],
            'withOpacity' => [static fn(): Modifier => (new Modifier())->withOpacity(0.5), false, Modifier::DIFF_VISUAL],
            'withTranslate' => [static fn(): Modifier => (new Modifier())->withTranslate(1.0, 1.0), false, Modifier::DIFF_VISUAL],
            'withClip' => [static fn(): Modifier => (new Modifier())->withClip(true), false, Modifier::DIFF_VISUAL],
            'withDisabled' => [static fn(): Modifier => (new Modifier())->withDisabled(true), false, Modifier::DIFF_VISUAL],
            'withGrow' => [static fn(): Modifier => (new Modifier())->withGrow(1), false, Modifier::DIFF_GEOMETRY],
            'withShrink' => [static fn(): Modifier => (new Modifier())->withShrink(1), false, Modifier::DIFF_GEOMETRY],
            'withBasis' => [static fn(): Modifier => (new Modifier())->withBasis(10.0), false, Modifier::DIFF_GEOMETRY],
            'withAlignSelf' => [static fn(): Modifier => (new Modifier())->withAlignSelf(RenderBox::ALIGN_CENTER), false, Modifier::DIFF_GEOMETRY],
            'withContentAlign' => [static fn(): Modifier => (new Modifier())->withContentAlign(Alignment::topRight()), false, Modifier::DIFF_GEOMETRY],
            // ★ 曾漏在 `isDefault()` 之外一整轮（S1 补上，见类注释）
            'withRepaintBoundary' => [static fn(): Modifier => (new Modifier())->withRepaintBoundary(true), false, Modifier::DIFF_VISUAL],
            'withInset' => [static fn(): Modifier => (new Modifier())->withInset(Modifier::INSET_LEFT, 8.0), false, Modifier::DIFF_GEOMETRY],
            'withTransform' => [static fn(): Modifier => (new Modifier())->withTransform(Matrix2D::rotation(15.0)), false, Modifier::DIFF_VISUAL],
            // ★ 传**非空**样式：传 `BoxStyle::none()` 等于没填，`isDefault()` 该保持真
            'withBoxStyleIfBlank' => [
                static fn(): Modifier => (new Modifier())->withBoxStyleIfBlank(BoxStyle::none()->withRadius(2.0)),
                false,
                Modifier::DIFF_VISUAL,
            ],
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
            // ★ `with*` = **加**一个字段的写入口，必须进表；`without*` = 派生一份
            //   **减**字段的副本（`withoutBoxVisual()`），语义相反 ⇒ 不进表。
            //   第一版只判 `str_starts_with($name, 'with')`，于是 `withoutBoxVisual`
            //   被算成漏行 —— 前缀判据要连"否定前缀"一起想。
            if (\str_starts_with($method->name, 'with') && !\str_starts_with($method->name, 'without')) {
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
     * 每一行都断言 `isDefault()` 的结果（**全部期望"假"，无例外**）。
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
            'Modifier · 每个 with*() 都改 isDefault()（无例外）',
            $wrong === [],
            \implode('；', $wrong)
        );
    }

    /**
     * 每个 `with*()` 的 `diff()` 归类都必须与表里那一列一致，**且两个方向一致**。
     *
     * ★ 反向也要测：`diff()` 里几何维的短路顺序决定了"谁先变"影响返回值。单向测法
     *   遇到"几何维比了 A→B 却漏了 B→A 的某个字段"就绿着过去。
     * ★ 另一条隐含判据：**两列都不能是 0** —— `diff() === 0` 意味着"什么都没变"，
     *   那这一维的改动会被 `setMods()` 整段吞掉（既不重排也不重画）。
     */
    public function testDiffClassifiesEveryWithMethod(): void
    {
        $empty = new Modifier();
        $wrong = [];
        foreach (self::cases() as $name => [$factory, , $expected]) {
            $modifier = $factory();
            $forward = $modifier->diff($empty);
            $backward = $empty->diff($modifier);
            if ($forward !== $expected || $backward !== $expected) {
                $wrong[] = \sprintf('%s（期望 %d，实际 %d / %d）', $name, $expected, $forward, $backward);
            }
        }

        check(
            'Modifier · diff() 的几何 / 绘制归类与表一致（双向）',
            $wrong === [],
            \implode('；', $wrong)
        );
    }

    /**
     * 两个**各自独立构造**的空修饰 ⇒ `diff()` 为 0。
     *
     * ★ 这条守的是 `NAN`：`width` / `height` / `aspect` / `basis` 默认是 NAN，而
     *   `NAN !== NAN` ⇒ 裸比较会把"两个默认修饰"判成几何不同 ⇒ 每次重建都标脏全树，
     *   盒复用整个白做（而且**不报错**，只表现为性能回到优化前）。
     */
    public function testDiffOfTwoIndependentDefaultsIsNone(): void
    {
        $a = new Modifier();
        $b = new Modifier();

        check(
            'Modifier · 两个独立空修饰 diff()=0（NAN 视为未设，不是"变了"）',
            $a->diff($b) === 0 && $b->diff($a) === 0,
            \sprintf('%d / %d', $a->diff($b), $b->diff($a))
        );
    }

    /**
     * 共享的空修饰实例：可从它派生出无数份，但**任何写方法都不得原地改它**。
     *
     * ★ 这是 `Modifier::empty()` 唯一的风险面：一旦某个 `with*()` 漏了 `clone`，
     *   污染是**全局**的 —— 所有"没碰过修饰"的控件会同时带上那份修饰，且每个
     *   后续重建都在放大它。行为级判据比"逐个方法查实现"更耐改。
     */
    public function testSharedEmptyInstanceIsNeverMutated(): void
    {
        check('Modifier · empty() 每次返回同一个实例', Modifier::empty() === Modifier::empty());

        $derived = Modifier::empty()
            ->withPadding(8.0, 8.0, 8.0, 8.0)
            ->withFill(Color::fromRgb(1, 2, 3))
            ->withRadius(4.0)
            ->withOpacity(0.5)
            ->withGrow(1);

        check(
            'Modifier · 派生出一份新修饰',
            $derived !== Modifier::empty() && $derived->isDefault() === false,
        );
        check(
            'Modifier · 共享实例本身未被污染',
            Modifier::empty()->isDefault() === true,
            'empty() 已经不再是"无修饰"了 —— 某个 with*() 漏了 clone',
        );
    }

    /**
     * **每个私有字段**单独改一次，`diff()` 都必须非 0。
     *
     * ★ 上一条（表驱动）守不住这件事：`withPadding()` 一次改四边，删掉其中一条比较
     *   仍然返回"几何变了" ⇒ 绿着过去（实测过，变异没咬红）。字段级反射才是"新增
     *   字段忘了归类"的真正守卫 —— 那条后果是那一维改动被 `setMods()` 整段吞掉，
     *   既不重排也不重画，**不报错**。
     * ★ 反射直接改私有字段（不经过 `with*()`）：这样"漏了一个写方法"也会被抓到。
     */
    public function testEveryFieldParticipatesInDiff(): void
    {
        $base = new Modifier();
        $class = new \ReflectionClass(Modifier::class);
        $wrong = [];

        foreach ($class->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $probe = new Modifier();
            $property->setValue($probe, self::oneStepFrom($property->getValue($probe), $property));

            if ($base->diff($probe) === 0) {
                $wrong[] = $property->getName();
            }
        }

        check(
            'Modifier · 每个字段都参与 diff()（漏一个 ⇒ 那一维改动被静默吞掉）',
            $wrong === [],
            '漏：' . \implode(',', $wrong)
        );
    }

    /** 给一个字段造"与原值必然不同"的替代值（按运行时类型分派） */
    private static function oneStepFrom(mixed $value, \ReflectionProperty $property): mixed
    {
        if (\is_float($value)) {
            return \is_nan($value) ? 5.0 : $value + 1.0;
        }
        if (\is_int($value)) {
            return $value + 1;
        }
        if (\is_bool($value)) {
            return !$value;
        }
        if ($value instanceof BoxStyle) {
            return $value->withRadius(2.0);
        }
        if ($value === null) {
            // 两个可空对象字段：`align`(?Alignment) / `transform`(?Matrix2D)
            return (string) $property->getType() === '?' . Matrix2D::class
                ? Matrix2D::rotation(15.0)
                : Alignment::center();
        }

        return $value;
    }

    /**
     * `withoutBoxVisual()`：**视觉四维全部剥掉，其余字段一个不动**。
     *
     * ## 为什么这条必须有（2026-10-31 修的 bug）
     *
     * 旧实现逐字段剥 `hasBackground` / `hasBorder` / `radius`，**漏了 `hasGradient`**
     * ⇒ 声明了 `->gradient()` 的自绘 chrome 控件（`Button` / `AppBar` / `PopupPanel`）
     * 把同一份渐变画两遍：`paintOwnChrome()` 一遍 + `RenderBox::paint()` 一遍。
     * 半透明渐变叠加后偏暗，**不报错**，而且每帧白画一次。
     *
     * 探针实证（当时的输出）：`hasGradient` 仍为 `true`、`boxStyle()` 仍有值。
     * 现在剥 = `BoxStyle::none()`，**没有字段列表可漏** —— 但"没有列表"这件事本身
     * 也要有判据守着，否则哪天有人手写回"逐字段剥"又漏一个，仍然静默。
     *
     * ★ 表驱动而不是抽样：**每个视觉维度一行**，漏哪一行就是漏哪个维度。
     *
     * @return array<string, array{0: callable(): Modifier}> 每行一个"只设了一维"的修饰
     */
    private static function visualOnlyCases(): array
    {
        return [
            'fill' => [static fn(): Modifier => (new Modifier())->withFill(Color::fromRgb(1, 2, 3))],
            // ★ 漏剥的就是这一行（其余三行旧实现本来就剥了）
            'gradient' => [
                static fn(): Modifier => (new Modifier())->withGradient(
                    Gradient::vertical([Color::fromRgba(9, 9, 9, 100), Color::white()]),
                ),
            ],
            'stroke' => [static fn(): Modifier => (new Modifier())->withStroke(Color::fromRgb(1, 2, 3), 2.0)],
            'radius' => [static fn(): Modifier => (new Modifier())->withRadius(6.0)],
        ];
    }

    public function testWithoutBoxVisualStripsEveryVisualDimension(): void
    {
        $wrong = [];
        foreach (self::visualOnlyCases() as $name => [$factory]) {
            $stripped = $factory()->withoutBoxVisual();

            if ($stripped->boxStyle() !== null) {
                $wrong[] = $name . '：boxStyle() 仍非 null（会被画两遍）';
            }
            if ($stripped->fillColor() !== null) {
                $wrong[] = $name . '：fillColor() 仍非 null';
            }
            if ($stripped->hasGradient()) {
                $wrong[] = $name . '：hasGradient() 仍为真（漏剥渐变 ⇒ 渐变画两遍）';
            }
            if ($stripped->hasVisual()) {
                $wrong[] = $name . '：hasVisual() 仍为真';
            }
        }

        check(
            'Modifier · withoutBoxVisual() 剥掉全部视觉四维（底 / 渐变 / 边 / 圆角）',
            $wrong === [],
            implode('；', $wrong),
        );
    }

    /**
     * 剥的时候**别多剥**：`shadow` 与布局维必须原样留着。
     *
     * ★ 反向判据。只有"剥干净"那一条的话，把 `withoutBoxVisual()` 写成
     *   `return new Modifier()`（全清空）也全绿 —— 而那会静默丢掉用户显式声明的
     *   `->shadow()` / `->padding()` / `->opacity()`。
     */
    public function testWithoutBoxVisualKeepsEverythingElse(): void
    {
        $mods = (new Modifier())
            ->withFill(Color::fromRgb(1, 2, 3))
            ->withShadow(0.5)
            ->withPadding(1.0, 2.0, 3.0, 4.0)
            ->withOpacity(0.25);

        $stripped = $mods->withoutBoxVisual();

        check(
            'Modifier · 剥视觉四维后 shadow 仍在（Button 的浮起影子靠它）',
            $stripped->hasVisual() === true,
            'hasVisual=' . ($stripped->hasVisual() ? '真' : '假'),
        );
        check(
            'Modifier · 剥视觉四维后布局维 / 合成维原样保留',
            $stripped->padLeft() === 1.0
            && $stripped->padTop() === 2.0
            && $stripped->padRight() === 3.0
            && $stripped->padBottom() === 4.0
            && $stripped->composeOpacity() === 0.25,
            sprintf(
                'pad=%s/%s/%s/%s opacity=%s',
                (string) $stripped->padLeft(),
                (string) $stripped->padTop(),
                (string) $stripped->padRight(),
                (string) $stripped->padBottom(),
                (string) $stripped->composeOpacity(),
            ),
        );

        $blank = new Modifier();
        check(
            'Modifier · 本来就没有视觉四维 ⇒ 返回自身（不白 clone）',
            $blank->withoutBoxVisual() === $blank,
        );
    }

    /**
     * `withBoxStyleIfBlank()`：**"用户设过没有"用 `isBlank()` 判，不是 `isNone()`**。
     *
     * ★ 这是"默认样式该不该填"的唯一判据，而它是**结构性**的：写成
     *   `boxStyle() === null`（`isNone()` 口径）时，`->radius(4)` 会被当成"没声明"
     *   ⇒ 默认的底与边**又叠回来**，圆角与 1px 描边打架，且不报错。
     *   `Card` 的"调了任何一个盒样式 ⇒ 整套默认让位"正是靠这条。
     */
    public function testWithBoxStyleIfBlankUsesIsBlankNotIsNone(): void
    {
        $default = BoxStyle::none()->withBackground(Color::white())->withBorder(Color::black(), 1.0);

        // 用户只设了圆角 ⇒ 默认**不填**（`isNone()` 会说"什么也没画"）
        $user = (new Modifier())->withRadius(4.0)->withBoxStyleIfBlank($default);
        check(
            'Modifier · 只设了 ->radius(4) ⇒ 默认样式不填（isBlank 口径，不是 isNone）',
            $user->fillColor() === null && $user->boxStyle()?->radius === 4.0,
            $user->fillColor() === null ? '圆角 ' . (string) ($user->boxStyle()?->radius ?? -1.0) : '默认底被填进来了',
        );

        // 一个字段都没设 ⇒ 填
        $blank = (new Modifier())->withBoxStyleIfBlank($default);
        check(
            'Modifier · 一个盒样式字段都没设 ⇒ 填入默认（底与边都到位）',
            $blank->fillColor() !== null && $blank->boxStyle()?->hasBorder() === true,
        );

        // 已有的样式不被覆盖（幂等：填过之后再填还是第一份）
        $twice = $blank->withBoxStyleIfBlank(BoxStyle::none()->withBackground(Color::black()));
        check(
            'Modifier · 已有样式 ⇒ 再填一次不覆盖（默认只填一次）',
            $twice->fillColor()?->equals(Color::white()) === true,
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
