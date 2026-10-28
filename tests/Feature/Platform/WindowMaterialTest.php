<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Platform;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Platform\Backdrop;
use Drift\Platform\Win32\Win32WindowHost;
use Drift\Platform\WindowMaterial;
use Drift\Rendering\Color;

/**
 * 窗口外观材质（`WindowMaterial` / `Win32WindowHost::applyMaterial()`，从 smoke 迁出）。
 *
 * ## 这一组钉什么
 *
 * ① `WindowMaterial` 是**纯值语义**：`make()` 全中性、链式返回新实例（不改原对象）、
 *    `wantsPixelAlpha()` 只由底色 alpha 决定 —— 这是跨桥唯一的底色衍生量
 *    （`Color` 本身不过桥，§5.1 只传原语）。
 * ② 意图 → ABI 编号的映射，包括 `null` 必须**发 0**而不是"不发调用"。
 * ③ 能力位掩码的**位 ↔ getter 名字**对应关系。
 * ④ ★★ `applyMaterial()` 的硬校验：带 alpha 却没 `setFrameless()` ⇒ 当场响亮失败。
 *
 * ## 迁移时的有效性审计（2026-10-28 第 25 批）
 *
 * - ★★ **修一处假绿（重要）**：原「materialCapabilities 解析位掩码」的夹具让假桥
 *   恒返"全能力"（`0x1F`），然后断言**五项全 true** —— 把位 1 和位 2 的解析写反，
 *   五项仍然全 true，**红不了**。改为两组**部分能力**掩码（见
 *   `testCapabilitiesParseBitMask`），每一项的 true/false 都被钉住。
 *   为此 `drift_win_material_caps()` 改成读 `$GLOBALS['DRIFT_SMOKE_WIN_CAPS']`。
 * - ★ **补一处缺口**：`applyMaterial()` 里那条 ★★ 硬校验（带 alpha 必须无边框）
 *   在 smoke 里**一条都没有**（只有正路径）。它的失效形态正是本仓最讨厌的
 *   "透明没生效且无任何报错"，所以补成子进程用例（Fatal 落 `exit(3)`，进程内
 *   `try/catch` 接不住）。
 * - **有效**：其余每条都落在被测语义（值语义 / ABI 编号 / 快照内容 / 逐像素声明）。
 * - **合并**：`make()` 的中性与链式不可变原本是一条 8 条件的巨型断言，语义上是
 *   "值语义"一件事，保持一条（拆开反而看不出"原对象没被改"这层）。
 */
final class WindowMaterialTest extends SmokeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        winMaterialReset();
        winMaterialCapsFake(null);
    }

    protected function tearDown(): void
    {
        winMaterialReset();
        winMaterialCapsFake(null);
        parent::tearDown();
    }

    public function testValueSemantics(): void
    {
        $base = WindowMaterial::make();
        $alpha = $base->background(new Color(0xFF, 0xFF, 0xFF, 0xB0));

        check(
            'WindowMaterial · make() 全中性 / 链式不改原对象 / wantsPixelAlpha 只由底色 alpha 决定',
            $base->backgroundColor() === null
                && $base->opacityValue() === 1.0
                && $base->backdropKind() === null
                && $base->wantsPixelAlpha() === false
                && $alpha->wantsPixelAlpha() === true
                && $base->wantsPixelAlpha() === false            // 原对象不可变
                && $alpha->background(Color::white())->wantsPixelAlpha() === false
                && WindowMaterial::make()->background(new Color(0, 0, 0, 0))->wantsPixelAlpha() === true,
            'base=' . \var_export($base->wantsPixelAlpha(), true)
                . ' alpha=' . \var_export($alpha->wantsPixelAlpha(), true),
        );
    }

    public function testAlphaBackgroundDeclaresPixelAlpha(): void
    {
        // 声明：带 alpha 底色 ⇒ 逐像素模式；再设不透明 ⇒ 明确关掉（不能只开不关）。
        // ★ 逐像素要无边框是硬前提，故先 setFrameless()。
        $host = new Win32WindowHost();
        $host->setFrameless(9001, 0);
        $host->applyMaterial(9001, WindowMaterial::make()->background(new Color(0xFF, 0xFF, 0xFF, 0xB0)));
        $host->applyMaterial(9001, WindowMaterial::make()->background(Color::white()));

        check(
            'Win32WindowHost · alpha<255 声明逐像素、再设不透明声明关掉（pixelAlpha true→false）',
            winMaterialCalls() === [[9001, true, 1.0, 0], [9001, false, 1.0, 0]],
            \var_export(winMaterialCalls(), true),
        );
    }

    public function testFullyTransparentStillDeclaresPixelAlpha(): void
    {
        // ★ 反例自检：`a === 0` 是"全透明"而不是"没设"，必须照样声明
        //   （用 `> 0` 判"有没有 alpha"会漏掉全透明窗口 —— 那正是最想透明的那种）
        $host = new Win32WindowHost();
        $host->setFrameless(777, 0);
        $host->applyMaterial(777, WindowMaterial::make()->background(new Color(0, 0, 0, 0)));

        check(
            'Win32WindowHost · 全透明底色（a=0）照样声明逐像素',
            winMaterialCalls() === [[777, true, 1.0, 0]],
            \var_export(winMaterialCalls(), true),
        );
    }

    public function testNeutralMaterialSendsSnapshot(): void
    {
        // 零回归：中性材质（不声明任何旋钮）⇒ 发一次完整快照，但 pixelAlpha=false /
        //   opacity=1.0 / backdrop=0（壳据此把窗口调回默认；与 Surface::setBackground 解耦）。
        $host = new Win32WindowHost();
        $host->applyMaterial(1, WindowMaterial::make());

        check(
            'Win32WindowHost · 中性材质 ⇒ 发一次快照、pixelAlpha=false / opacity=1.0 / backdrop=0',
            winMaterialCalls() === [[1, false, 1.0, 0]],
            \var_export(winMaterialCalls(), true),
        );
    }

    public function testBackdropMapsToAbiNumber(): void
    {
        // 意图 → 编号：三种都要过一遍（走 applyMaterial 一次快照）。
        // ★ `null` 必须是**发 0**，不能是"不发调用" —— 不发的话窗口上已经生效的
        //   材质会一直留着，"关掉"静默失效（本仓最讨厌的那种 bug）。
        $host = new Win32WindowHost();
        $host->applyMaterial(9001, WindowMaterial::make()->backdrop(Backdrop::Blur));
        $host->applyMaterial(9001, WindowMaterial::make()->backdrop(Backdrop::Acrylic));
        $host->applyMaterial(9001, WindowMaterial::make()->backdrop(null));

        check(
            'Win32WindowHost · applyMaterial 映射 Blur→1 / Acrylic→2 / null→0（关也要发调用）',
            winMaterialCalls() === [[9001, false, 1.0, 1], [9001, false, 1.0, 2], [9001, false, 1.0, 0]],
            \var_export(winMaterialCalls(), true),
        );
    }

    public function testBackdropEnumValuesAreAbi(): void
    {
        // ABI 护栏：枚举 `value` **就是**桥的编号（`0` 留给"关"）⇒ 改它等于改 ABI，
        //   而改了之后"磨砂变轻模糊"这种错**不报错、只是看着不对**。
        check(
            'Backdrop · value 就是桥的 ABI 编号（Blur=1 / Acrylic=2，0 留给"关"）',
            Backdrop::Blur->value === 1 && Backdrop::Acrylic->value === 2,
            \sprintf('%d / %d', Backdrop::Blur->value, Backdrop::Acrylic->value),
        );
    }

    public function testCapabilitiesParseBitMask(): void
    {
        // ★★ 判据必须能分辨"哪一位对应哪个名字"。
        //   旧夹具恒返全能力 + 断言五项全 true ⇒ 把位 1/位 2 的解析写反也红不了。
        //   这里用两组互补的部分能力掩码，让每一项的 true **和** false 都被钉住：
        //     A = 位2|位8  ⇒ pixelAlpha + acrylic
        //     B = 位1|位4|位16 ⇒ opacity + blur + 圆角
        $host = new Win32WindowHost();

        winMaterialCapsFake(0x02 | 0x08);
        $a = $host->materialCapabilities(9001);
        check(
            'Win32WindowHost · materialCapabilities 位解析 A（只报 pixelAlpha+acrylic ⇒ F/T/F/T/F）',
            $a->opacity() === false && $a->pixelAlpha() === true
                && $a->backdropBlur() === false && $a->backdropAcrylic() === true
                && $a->roundedCorners() === false,
            \sprintf(
                '%d/%d/%d/%d/%d',
                (int) $a->opacity(), (int) $a->pixelAlpha(), (int) $a->backdropBlur(),
                (int) $a->backdropAcrylic(), (int) $a->roundedCorners(),
            ),
        );

        winMaterialCapsFake(0x01 | 0x04 | 0x10);
        $b = $host->materialCapabilities(9001);
        check(
            'Win32WindowHost · materialCapabilities 位解析 B（只报 opacity+blur+圆角 ⇒ T/F/T/F/T）',
            $b->opacity() === true && $b->pixelAlpha() === false
                && $b->backdropBlur() === true && $b->backdropAcrylic() === false
                && $b->roundedCorners() === true,
            \sprintf(
                '%d/%d/%d/%d/%d',
                (int) $b->opacity(), (int) $b->pixelAlpha(), (int) $b->backdropBlur(),
                (int) $b->backdropAcrylic(), (int) $b->roundedCorners(),
            ),
        );
    }

    public function testPixelAlphaWithoutFramelessFailsLoudly(): void
    {
        // ★★ 补的缺口：带 alpha 却忘了 setFrameless() ⇒ 必须当场响亮失败。
        //   失效形态是"壳静默回落 GDI ⇒ 透明没生效且无任何报错"，属本仓最讨厌的一类。
        //   Fatal 落 `exit(3)`，进程内 try/catch 接不住 ⇒ 走子进程。
        $log = \sys_get_temp_dir() . '/drift-alpha-noframeless-' . \getmypid() . '.log';
        @\unlink($log);
        [$code, $out] = runFatalCase('material-alpha-no-frameless', $log);
        $text = \is_file($log) ? (string) \file_get_contents($log) : '';
        @\unlink($log);

        check(
            'Win32WindowHost · 带 alpha 却没 setFrameless ⇒ 退出码 3 且点名"必须先 setFrameless"',
            $code === 3 && \str_contains($text, '必须先 setFrameless'),
            'code=' . $code . ' out=' . $out,
        );
    }
}
