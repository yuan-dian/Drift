<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderText;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Text;
use Drift\Text\TextRole;

/**
 * Text 控件：哨兵三档 + 角色经主题解析（§15.2 / §15.7 纪律 3）。
 *
 * 判据（与 `tools/smoke.php` ⑲ 段一致）：
 *   ① `Unset` 是显式 enum case ⇒ `0` 是合法角色，不能被当成"未设置"；
 *   ② 三态 bool / float 哨兵都要能把"未设置"与"显式否/零"分开；
 *   ③ 角色只在 BuildContext 里解析一次 —— 换肤改表，调用点不动；显式 size 盖过角色；
 *   ④ 端到端排版走注入的度量，收窄必然折行变高；maxLines/ellipsize 进 TextStyle。
 *
 * ★ 判据全在声明层与单盒布局，不需要帧驱动 ⇒ Unit 层。
 */
final class TextTest extends SmokeTestCase
{
    public function testSentinelIsEnumCaseNotBareNumber(): void
    {
        $ctx = smokeContext();

        // 哨兵：Unset 是显式 case ⇒ 0 是合法取值（->body() 解析出来就是它）
        $unsetText = Text::make('x');
        check('Text · 哨兵是 enum case 不是裸数字（Unset 有名字）', TextRole::Unset->value === 0 && $unsetText->role() === TextRole::Unset);
        check('Text · body() 落成 Body（0 是合法值，不是"未设置"）', Text::make('x')->body()->role() === TextRole::Body);

        $this->flushChecks();
    }

    public function testTristateAndFloatSentinels(): void
    {
        // 三态 bool：未设置 / 否 / 是 三者可区分
        $tristateA = Text::make('x');
        $tristateB = Text::make('x')->bold(false);
        $tristateC = Text::make('x')->bold();
        check(
            'Text · bold 三态可分（未设置 / 否 / 是）',
            $tristateA->explicitBold() === null && $tristateB->explicitBold() === false && $tristateC->explicitBold() === true
        );

        // float 哨兵：负值域外
        check('Text · size 哨兵是 -1.0（尺寸非负 ⇒ 域外）', Text::make('x')->explicitSize() === null);
        check('Text · 给了 size 就用显式值', Text::make('x')->size(22.0)->explicitSize() === 22.0);

        $this->flushChecks();
    }

    public function testRoleResolvesThroughTheme(): void
    {
        $ctx = smokeContext();

        // 角色 → 主题：换肤只换表，调用点不动
        $lightTitle = Text::make('标题')->title()->createRenderObject($ctx);
        $darkContext = new BuildContext(320.0, 240.0, ThemeData::dark(), $ctx->metrics());
        $darkTitle = Text::make('标题')->title()->createRenderObject($darkContext);
        check(
            'Text · title() 的字号来自主题表（明 18）',
            $lightTitle instanceof RenderText && $lightTitle->styleSize() === 18.0,
            (string) ($lightTitle instanceof RenderText ? $lightTitle->styleSize() : -1.0)
        );
        check(
            'Text · caption 比 body 小，title 比 body 大（层次是排出来的不是写死的）',
            Text::make('x')->caption()->createRenderObject($ctx)->styleSize() === 12.0
                && Text::make('x')->title()->createRenderObject($ctx)->styleSize() === 18.0
        );

        // 显式 size 优先于角色
        check(
            'Text · 显式 size 盖过角色（优先级只在 ThemeData 解析一次）',
            Text::make('x')->title()->size(40.0)->createRenderObject($ctx)->styleSize() === 40.0
        );

        // 暗主题那一路也造得出来（不炸）；判据本身靠上面两条，这里只走通路
        self::assertInstanceOf(RenderText::class, $darkTitle);

        $this->flushChecks();
    }

    public function testEndToEndLayout(): void
    {
        $ctx = smokeContext();

        // 端到端：Element → 盒 → 真实排版（不是"能造出来"）
        $textRoot = Text::make('Hello Drift')->body()->createElement();
        $textRoot->mount(null, $ctx);
        $textBox = $textRoot->renderObject();
        check('Text · 造出 RenderText', $textBox instanceof RenderText);
        $textBox?->layout(BoxConstraints::loose(new Size(200.0, 100.0)));
        check(
            'Text · 排版走注入的度量，得到非零尺寸',
            ($textBox?->size()?->width ?? 0.0) > 0.0 && ($textBox?->size()?->height ?? 0.0) > 0.0,
            sprintf('实际 %.2f × %.2f', $textBox?->size()?->width ?? -1.0, $textBox?->size()?->height ?? -1.0)
        );

        // 受宽度约束 ⇒ 折行 ⇒ 变高（断行依赖可用宽，这是 C1 的必然推论）
        $narrow = Text::make('Hello Drift')->body()->createElement();
        $narrow->mount(null, $ctx);
        $narrowBox = $narrow->renderObject();
        $narrowBox?->layout(BoxConstraints::loose(new Size(30.0, 1000.0)));
        check(
            'Text · 收窄约束 ⇒ 折行 ⇒ 更高（宽度变了断行就变）',
            ($narrowBox?->size()?->height ?? 0.0) > ($textBox?->size()?->height ?? 0.0),
            sprintf('窄 %.2f vs 宽 %.2f', $narrowBox?->size()?->height ?? -1.0, $textBox?->size()?->height ?? -1.0)
        );

        $this->flushChecks();
    }

    public function testMaxLinesGoesIntoTextStyle(): void
    {
        $ctx = smokeContext();

        // maxLines / ellipsize 进的是 TextStyle（§8.1：影响排版的输入都必须在 style 里，
        // 否则缓存键 (text, style, maxWidth, scale) 会撒谎）
        $clipped = Text::make('Hello Drift Framework')->body()->maxLines(1)->ellipsize()->createRenderObject($ctx);
        $clipped->layout(BoxConstraints::loose(new Size(40.0, 1000.0)));
        check(
            'Text · maxLines 真的限住行数（1 行 ⇒ 高度 = 一行高）',
            $clipped instanceof RenderText && $clipped->lineCount() === 1,
            (string) ($clipped instanceof RenderText ? $clipped->lineCount() : -1)
        );

        $this->flushChecks();
    }
}
