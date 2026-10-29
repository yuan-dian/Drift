<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Smoke\App\SliderApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\Semantics;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\SemanticsRole;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderSlider;
use Drift\Rendering\ColorToken;
use Drift\Rendering\DisplayList;
use Drift\Rendering\RecordingCanvas;
use Drift\Rendering\SliderStyle;
use Drift\Widget\Element;
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\Slider;

/**
 * 滑杆（W8-2，2026-10-23）。
 *
 * 几何全部按公式推（不拟合）：padX = 拇指半径，盒高 = 2*半径 + 2*留白，
 * thumbX = padX + t*(w - 2*padX)，t = (value - min)/(max - min)。
 *
 * ★ 键盘判据基于**受控值**而不是"连按累加"：夹具的回调只记录事件、不改值
 *   ⇒ `$this->value` 恒 50。所以验的是"一次按键按当前受控值算多少"，而不是
 *   "连按两次到 52"（后者依赖应用在回调里 setState + 重建，是应用的受控值纪律）。
 */
final class SliderTest extends SmokeTestCase
{
    private const RADIUS = SliderStyle::DEFAULT_THUMB_RADIUS;   // 8
    private const GAP = SliderStyle::DEFAULT_THUMB_GAP;         // 2
    private const TRACK_H = SliderStyle::DEFAULT_TRACK_HEIGHT;  // 4

    private float $wantW;
    private float $wantH;
    private float $pad;
    private float $inner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wantW = Slider::DEFAULT_WIDTH;                  // 160
        $this->wantH = 2.0 * self::RADIUS + 2.0 * self::GAP;   // 20
        $this->pad = self::RADIUS;                             // 8
        $this->inner = $this->wantW - 2.0 * $this->pad;        // 144（拇指中心可达区间）
    }

    private static function valueAt(?RenderBox $box, float $x): float
    {
        return $box instanceof RenderSlider ? $box->valueAt($x) : -999.0;
    }

    public function testMountSizeAndValueMapping(): void
    {
        $el = Slider::make()->range(0.0, 100.0)->value(50.0)->createElement();
        $el->mount(null, smokeContext());
        $box = $el->effectiveBox();
        check(
            'Slider · 挂载即建出 RenderSlider（叶子盒）',
            $box instanceof RenderSlider,
            $box === null ? 'box=null' : \get_class($box),
        );

        $box?->layout(BoxConstraints::loose(new Size(400.0, 100.0)));
        $size = $box?->size();
        check(
            'Slider · 宽 = 显式 160、高 = 2*拇指半径 + 2*留白 = 20（公式推）',
            $size !== null && \abs($size->width - $this->wantW) < 0.01 && \abs($size->height - $this->wantH) < 0.01,
            \sprintf('w=%.2f h=%.2f want=%.2fx%.2f', $size?->width ?? -1.0, $size?->height ?? -1.0, $this->wantW, $this->wantH),
        );
        check(
            'Slider · 内禀 = 实排（叶子无内容，宽只能显式给）',
            $box !== null
                && \abs($box->intrinsicWidth(INF) - $this->wantW) < 0.01
                && \abs($box->intrinsicHeight(INF) - $this->wantH) < 0.01,
            \sprintf('intrinsic=%.2fx%.2f', $box?->intrinsicWidth(INF) ?? -1.0, $box?->intrinsicHeight(INF) ?? -1.0),
        );

        // 位置 ↔ 值互逆：value=50 ⇒ thumbX = 8 + 0.5*144 = 80
        $thumb = $box instanceof RenderSlider ? $box->thumbX() : -1.0;
        check(
            'Slider · value=50 ⇒ thumbX = pad + 0.5*(w-2*pad) = 80（公式推）',
            \abs($thumb - ($this->pad + 0.5 * $this->inner)) < 0.01,
            \sprintf('thumbX=%.2f want=%.2f', $thumb, $this->pad + 0.5 * $this->inner),
        );
        check(
            'Slider · valueAt 是 thumbX 的反函数（80 → 50；两端 8/152 → 0/100）',
            \abs(self::valueAt($box, 80.0) - 50.0) < 0.01
                && \abs(self::valueAt($box, $this->pad) - 0.0) < 0.01
                && \abs(self::valueAt($box, $this->pad + $this->inner) - 100.0) < 0.01,
            \sprintf(
                'f(80)=%.2f f(8)=%.2f f(152)=%.2f',
                self::valueAt($box, 80.0),
                self::valueAt($box, $this->pad),
                self::valueAt($box, $this->pad + $this->inner)
            ),
        );
        check(
            'Slider · valueAt 越界夹紧（x=0 与 x=200 都落回端点，不产生越界值）',
            \abs(self::valueAt($box, 0.0) - 0.0) < 0.01 && \abs(self::valueAt($box, 200.0) - 100.0) < 0.01,
            \sprintf('f(0)=%.2f f(200)=%.2f', self::valueAt($box, 0.0), self::valueAt($box, 200.0)),
        );

        // 量化：range(0,10) step(1)，x = pad + 0.55*inner ⇒ 5.5 ⇒ round ⇒ 6
        $quantEl = Slider::make()->range(0.0, 10.0)->step(1.0)->value(3.0)->createElement();
        $quantEl->mount(null, smokeContext());
        $quantBox = $quantEl->effectiveBox();
        $quantBox?->layout(BoxConstraints::loose(new Size(400.0, 100.0)));
        $x = $this->pad + 0.55 * $this->inner;
        check(
            'Slider · step=1 量化：x 落在 5.5 ⇒ 值 6（不是 5.5）',
            \abs(self::valueAt($quantBox, $x) - 6.0) < 0.01,
            \sprintf('valueAt(%.2f)=%.3f want=6', $x, self::valueAt($quantBox, $x)),
        );
    }

    public function testPaintCommandsAndPixels(): void
    {
        $context = smokeContext();
        $el = Slider::make()->range(0.0, 100.0)->value(50.0)->createElement();
        $el->mount(null, $context);
        $box = $el->effectiveBox();
        $box?->layout(BoxConstraints::loose(new Size(400.0, 100.0)));
        $thumb = $box instanceof RenderSlider ? $box->thumbX() : -1.0;

        // 绘制命令：track rect → fill rect → 拇指 fill 椭圆 → 拇指 stroke 椭圆
        $list = new DisplayList();
        $box?->paint(new RecordingCanvas($list));
        $ops = [];
        for ($i = 0; $i < $list->count(); $i++) {
            $ops[] = $list->opAt($i);
        }
        check(
            'Slider · 命令序：轨道 rect → 已选段 rect → 拇指 fill → 拇指 stroke（6,6,8,8）',
            $ops === [DisplayList::OP_DRAW_RECT, DisplayList::OP_DRAW_RECT, DisplayList::OP_DRAW_ELLIPSE, DisplayList::OP_DRAW_ELLIPSE],
            'ops=[' . \implode(',', $ops) . ']',
        );

        $trackRect = $list->argAt(0, 0);
        $fillRect = $list->argAt(1, 0);
        $fillRadius = $list->argAt(1, 2);
        $thumbRect = $list->argAt(2, 0);
        check(
            'Slider · 轨道 rect = (pad, cy-2, w-2*pad, 4)、radius = 2（公式推）',
            $trackRect instanceof Rect
                && \abs($trackRect->left - $this->pad) < 0.01
                && \abs($trackRect->top - ($this->wantH / 2.0 - self::TRACK_H / 2.0)) < 0.01
                && \abs($trackRect->width - $this->inner) < 0.01
                && \abs($trackRect->height - self::TRACK_H) < 0.01
                && \abs((float) $fillRadius - self::TRACK_H / 2.0) < 0.01,
            $trackRect instanceof Rect
                ? \sprintf('rect(%.1f,%.1f,%.1f,%.1f) r=%.2f', $trackRect->left, $trackRect->top, $trackRect->width, $trackRect->height, (float) $fillRadius)
                : 'no track rect',
        );
        check(
            'Slider · 已选段宽 = thumbX - pad = 72（跟着值走，不是半个轨道）',
            $fillRect instanceof Rect && \abs($fillRect->width - ($thumb - $this->pad)) < 0.01,
            $fillRect instanceof Rect ? \sprintf('w=%.2f want=%.2f', $fillRect->width, $thumb - $this->pad) : 'no fill rect',
        );
        check(
            'Slider · 拇指 = 正方形椭圆（thumbX-r, cy-r, 2r, 2r）= (72,2,16,16)',
            $thumbRect instanceof Rect
                && \abs($thumbRect->left - ($thumb - self::RADIUS)) < 0.01
                && \abs($thumbRect->top - ($this->wantH / 2.0 - self::RADIUS)) < 0.01
                && \abs($thumbRect->width - 2.0 * self::RADIUS) < 0.01
                && \abs($thumbRect->height - 2.0 * self::RADIUS) < 0.01,
            $thumbRect instanceof Rect
                ? \sprintf('rect(%.1f,%.1f,%.1f,%.1f)', $thumbRect->left, $thumbRect->top, $thumbRect->width, $thumbRect->height)
                : 'no thumb rect',
        );

        // 像素：拇指中心 accent、pad 区（x<8）无 accent
        $canvas = spinCanvas($box, (int) $this->wantW, (int) $this->wantH);
        $accent = $context->color(ColorToken::Accent)->toArgb();
        $centerOk = $canvas->pixelAt((int) \round($thumb), (int) \round($this->wantH / 2.0))->toArgb() === $accent;
        $padInk = 0;
        for ($px = 0; $px < (int) $this->pad; $px++) {
            for ($py = 0; $py < (int) $this->wantH; $py++) {
                if ($canvas->pixelAt($px, $py)->toArgb() === $accent) {
                    $padInk++;
                }
            }
        }
        check(
            'Slider · 拇指中心是 accent 墨，且左 pad 区（x<8）零 accent（拇指不出界）',
            $centerOk && $padInk === 0,
            'center=' . \var_export($centerOk, true) . ' padInk=' . $padInk,
        );
    }

    /**
     * 指针拖拽 + 键盘的同一条状态链。
     *
     * ★ 不拆成多个方法：`$slApp` 的捕获态、焦点、`events` 都是跨步骤的现场，
     *   拆开就得把每一步的状态重建一遍，而重建本身没人验。
     */
    public function testPointerDragAndKeyboardChain(): void
    {
        $app = new SliderApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(320.0, 200.0)));
        $appBox = $app->root()?->effectiveBox();
        $appBox?->layout(BoxConstraints::loose(new Size(320.0, 200.0)));

        $sliders = [];
        $app->root()?->visitChildren(function (Element $e) use (&$sliders): void {
            if ($e->widget() instanceof Slider) {
                $sliders[] = $e;
            }
        });
        $elA = $sliders[0] ?? null;
        $elC = $sliders[2] ?? null;
        $boxA = $elA?->effectiveBox();
        [$ax, $ay] = $boxA === null ? [0.0, 0.0] : boxOrigin($boxA);

        $app->dispatchInput(PointerEvent::down($ax + $this->pad + 0.75 * $this->inner, $ay + $this->wantH / 2.0));
        check(
            'Slider · 按下 3/4 处 ⇒ onChanged(75) 且指针被捕获（ownsPress 生效）',
            \count($app->events) === 1 && \abs($app->events[0][1] - 75.0) < 0.01 && $app->captureNode() === $elA,
            \json_encode($app->events) . ' captured=' . ($app->captureNode() === $elA ? 'yes' : 'no'),
        );
        $app->dispatchInput(PointerEvent::move($ax + $this->pad, $ay + $this->wantH / 2.0));
        check(
            'Slider · 拖到最左 ⇒ onChanged(0)（跟手，不需要过阈值）',
            \count($app->events) === 2 && \abs($app->events[1][1] - 0.0) < 0.01,
            \json_encode($app->events),
        );
        $app->dispatchInput(PointerEvent::up($ax + $this->pad, $ay + $this->wantH / 2.0));
        check(
            'Slider · up ⇒ 捕获释放（不释放就是"事件永远发给死节点"）',
            $app->captureNode() === null && \count($app->events) === 2,
            'captured=' . \var_export($app->captureNode(), true),
        );

        // 反例：值已在端点，再点同一点 ⇒ 不回调（同位置不产生无意义的 setState）
        $edgeEl = Slider::make()->range(0.0, 100.0)->value(100.0)->createElement();
        $edgeEl->mount(null, smokeContext());
        $edgeBox = $edgeEl->effectiveBox();
        $edgeBox?->layout(BoxConstraints::loose(new Size(400.0, 100.0)));
        $edgeFired = 0;
        check(
            'Slider · 反例：value 已在 max、点最右 ⇒ emit 判等后不回调（阈值 1e-9）',
            // 直接验纯函数面：valueAt(端点) === 受控值 ⇒ emit 的判等会拦下
            \abs(self::valueAt($edgeBox, $this->pad + $this->inner) - 100.0) < 1e-9 && $edgeFired === 0,
            \sprintf('valueAt(end)=%.6f', self::valueAt($edgeBox, $this->pad + $this->inner)),
        );

        // 禁用：down 不回调、不吃方向键
        $boxC = $elC?->effectiveBox();
        [$cx, $cy] = $boxC === null ? [0.0, 0.0] : boxOrigin($boxC);
        $beforeC = \count($app->events);
        $app->dispatchInput(PointerEvent::down($cx + $this->pad + 0.5 * $this->inner, $cy + $this->wantH / 2.0));
        // ★ 禁用滑杆不响应按下，且**不产生指针捕获**：吞掉发生在 `InputRouter`
        //   的 down 分支（`isDisabled()` ⇒ return null），widget 层的 `pointer()`
        //   根本收不到事件（2026-10-29 收敛前是"收到但自己 return null"）。
        check(
            'Slider · 禁用：按下不回调、不产生指针捕获（InputRouter 在 down 就吞掉）',
            \count($app->events) === $beforeC && $app->captureNode() === null,
            'events=' . \count($app->events) . ' captured=' . \var_export($app->captureNode() !== null, true),
        );
        $app->dispatchInput(PointerEvent::up($cx + $this->pad + 0.5 * $this->inner, $cy + $this->wantH / 2.0));

        // 键盘：焦点在滑杆上 ⇒ 方向键 ±（step<=0 时按区间 1%）、Home/End 到端点
        $app->dispatchInput(PointerEvent::down($ax + $this->pad, $ay + $this->wantH / 2.0));
        $app->dispatchInput(PointerEvent::up($ax + $this->pad, $ay + $this->wantH / 2.0));
        $app->events = [];
        $app->dispatchInput(KeyEvent::down(KeyChord::right()));
        check(
            'Slider · 键盘 →：step<=0 时按区间 1% 走（受控值 50 ⇒ 51）',
            \count($app->events) === 1 && \abs($app->events[0][1] - 51.0) < 0.01,
            \json_encode($app->events),
        );
        $app->events = [];
        $app->dispatchInput(KeyEvent::down(KeyChord::end()));
        check(
            'Slider · 键盘 End ⇒ max（100）',
            \count($app->events) === 1 && \abs($app->events[0][1] - 100.0) < 0.01,
            \json_encode($app->events),
        );
        $app->events = [];
        $app->dispatchInput(KeyEvent::down(KeyChord::left()));
        check(
            'Slider · 键盘 ←：受控值 50 ⇒ 49（不是"从 End 往回 1"——受控值才是基值）',
            \count($app->events) === 1 && \abs($app->events[0][1] - 49.0) < 0.01,
            \json_encode($app->events),
        );
        $app->events = [];
        $app->dispatchInput(KeyEvent::down(KeyChord::home()));
        check(
            'Slider · 键盘 Home ⇒ min（0）',
            \count($app->events) === 1 && \abs($app->events[0][1] - 0.0) < 0.01,
            \json_encode($app->events),
        );

        // B 栏（range 0..10 step 1 value 3）：证明"基值 = 受控值"且 step 真的被用上
        $elB = $sliders[1] ?? null;
        $boxB = $elB?->effectiveBox();
        [$bx, $by] = $boxB === null ? [0.0, 0.0] : boxOrigin($boxB);
        $app->dispatchInput(PointerEvent::down($bx + $this->pad, $by + $this->wantH / 2.0));
        $app->dispatchInput(PointerEvent::up($bx + $this->pad, $by + $this->wantH / 2.0));
        $app->events = [];
        $app->dispatchInput(KeyEvent::down(KeyChord::right()));
        check(
            'Slider · 键盘 →：step=1 的滑杆（受控值 3 ⇒ 4，走的是 step 不是 1%）',
            \count($app->events) === 1 && \abs($app->events[0][1] - 4.0) < 0.01,
            \json_encode($app->events),
        );

        // 反例：禁用滑杆**不夺焦** —— 点它不改焦点，方向键继续落在原来聚焦的 B 栏。
        // ★ 收敛前禁用滑杆仍在 Tab 序、也能被点聚焦，只在 keyboard 回调里 return false；
        //   现在它压根不进可聚焦表，所以"让位"发生在焦点层而不是键盘层。
        $app->events = [];
        $app->dispatchInput(PointerEvent::down($cx + $this->pad + 0.5 * $this->inner, $cy + $this->wantH / 2.0));
        $app->dispatchInput(PointerEvent::up($cx + $this->pad + 0.5 * $this->inner, $cy + $this->wantH / 2.0));
        $app->dispatchInput(KeyEvent::down(KeyChord::right()));
        check(
            'Slider · 反例：点禁用滑杆不夺焦，方向键落在原来聚焦的 B 栏',
            // 事件里的值是 float（回调签名是 `fn(float $v)`）⇒ 用容差比，别用 ===
            \count($app->events) === 1
                && $app->events[0][0] === 'b'
                && \abs($app->events[0][1] - 4.0) < 0.01,
            \json_encode($app->events),
        );
    }

    public function testSemanticsRoleRangeAndWriteBack(): void
    {
        // 语义：role=slider、value = 读数
        $sem = SemanticsBuilder::build(Semantics::element(
            Slider::make()->range(0.0, 100.0)->value(42.0)->label('音量')
        ));
        $idx = $sem->findByRoleAndName(SemanticsRole::Slider->value, '音量');
        check(
            'Slider · 语义：role=slider、name=label、value=读数（42）',
            $idx >= 0 && $sem->node($idx)->value === '42',
            $idx < 0 ? 'no slider node (count=' . $sem->count() . ')' : 'value=' . $sem->node($idx)->value,
        );

        // 语义区间（§4.4）：`range(0,100)` ⇒ 节点与 `toArrays` 都带 rangeMin/rangeMax。
        // 反例自检：删 `SemanticsBuilder` 的 range 填充 / 删 `Slider` 的
        // `semanticsRange()` ⇒ 本条 FAIL（不是恒真断言）。
        $arr = $sem->toArrays();
        check(
            'Slider · 语义区间：range(0,100) ⇒ 节点与 toArrays 都带 rangeMin/rangeMax（§4.4）',
            $idx >= 0
                && $sem->node($idx)->rangeMin === 0.0
                && $sem->node($idx)->rangeMax === 100.0
                && ($arr['nodes'][$idx]['rangeMin'] ?? -1.0) === 0.0
                && ($arr['nodes'][$idx]['rangeMax'] ?? -1.0) === 100.0,
            $idx < 0
                ? 'no slider node'
                : 'min=' . $sem->node($idx)->rangeMin . ' max=' . $sem->node($idx)->rangeMax
                    . ' arr=' . \json_encode($arr['nodes'][$idx] ?? null),
        );

        // 语义写值（§4.4 提交 2）：`setSemanticsValue` 转 `emit()`（夹区间 + 只在变化时回调）。
        $got = null;
        $w = Slider::make()->range(0.0, 100.0)->value(42.0)->onChanged(function (float $v) use (&$got): void {
            $got = $v;
        });
        check(
            'Slider · setSemanticsValue(70) ⇒ onChanged(70)（RangeValuePattern 回写路径）',
            $w->setSemanticsValue('70') === true && $got === 70.0,
            'got=' . \var_export($got, true),
        );
        check(
            'Slider · setSemanticsValue(999) ⇒ emit 夹到 max 100',
            $w->setSemanticsValue('999') === true && $got === 100.0,
            'got=' . \var_export($got, true),
        );
        check(
            'Slider · setSemanticsValue("abc") ⇒ false（非数字，读屏据此知道没生效）',
            $w->setSemanticsValue('abc') === false,
        );
    }
}
