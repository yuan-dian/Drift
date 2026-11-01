<?php
declare(strict_types=1);

namespace Drift\Dev;

use Drift\App\Application;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;
use Drift\Input\ScrollEvent;
use Drift\Layout\RenderBox;
use Drift\Rendering\Color;
use Drift\Widget\Element;

/**
 * 声明式应用行为场景（`07` §4.2 / §4.3）。
 *
 * ```php
 * TestApp::scenario('虚拟列表只造可见行')
 *     ->app(new LazyApplication())
 *     ->constraints(400.0, 200.0)
 *     ->pump(1)
 *     ->expect('行数 < 20', fn(LazyApplication $a): bool => …)
 *     ->scroll(120.0)
 *     ->settle()          // 滚轮带动画 ⇒ 要跑到帧时钟空闲
 *     ->expectBox('LazyColumn', 0.0, 0.0, 400.0, 200.0)
 *     ->run();
 * ```
 *
 * ★ **不用 `eval`**：`expect()` 收闭包（`nano-hygiene` 禁 `eval`，不给测试开后门）。
 *   描述串只进失败消息，与 `check(name, bool, detail)` 同形。
 *
 * ★ **R1 查树不查 Widget**：`expectBox()` 从 `Element::effectiveBox()` 出发 ——
 *   Widget 每次 `build()` 都是新对象，身份不稳定；盒树才是稳定观察面。
 * ★ **R2 默认要求唯一**：选择器命中 0 个或 ≥2 个都**记失败**，不是取第一个。
 *   多义选择器是测试脆弱的第一来源。
 * ★ **R4 必须显式 `pump()`**：不自动跑帧 —— 帧是测试的时钟，测试必须控制它。
 *
 * ★ **像素可观察**（2026-11-01）：`pixelAt()` / `expectPixel()` 让"画出来对不对"
 *   也能离线断言。此前 `attach(new SurfaceProbe(), …)` 把表面当场丢掉、且从未
 *   `resize()`（像素恒 0×0）⇒ 任何"这里是不是被遮罩盖住了"的问题只能去真机
 *   开窗抓像素（`tools/probe-modal.py` 391 行大半在做这件事）。
 *   ★ 能问树/命令的**仍然问树**：`expectPixel` 只服务于像素独有的事
 *   （透明度 / 叠色 / AA 边缘 / 被裁掉的痕迹）。
 *
 * ★★ **只实现"被场景走通过"的动词**。`type()` 暂不提供：没有场景用到它，
 *   先写就是"写了但没被走通过"的分支（§24.2 #25）。要加它，先加一个走通过
 *   它的场景。
 *
 * @internal 测试宿主
 */
final class TestApp
{
    /** @var list<array{0: string, 1: callable(): void}> 待执行步骤（声明序 = 执行序） */
    private array $steps = [];

    private ?Application $app = null;
    private float $width = 320.0;
    private float $height = 240.0;

    /**
     * 帧表面（**必须留着**：`pixelAt()` 从它读像素）。
     *
     * ★ 别像旧版那样 `attach(new SurfaceProbe(), …)` 当场丢掉 —— 丢掉之后
     *   "这一帧画成什么样"就没有任何观察面了，只剩真机一条路。
     */
    private ?SurfaceProbe $surface = null;

    /** 帧时钟（毫秒）。每帧 +`$frameMs`，由测试控制 —— 不读系统钟（G3） */
    private float $clock = 0.0;
    private float $frameMs = 16.0;

    /**
     * `attach()` 只做一次，且**算第一帧**。
     *
     * ★ 依据：`attach()` 走与 `frame()` 同一条管线（smoke 侧实测"首帧就推进了
     *   动画"）。所以 `pump(1)` = 挂载后的首帧状态，`pump(3)` = 首帧 + 两帧。
     */
    private bool $attached = false;

    private int $passed = 0;
    private int $failed = 0;

    /** @var list<string> */
    private array $failures = [];

    private function __construct(private readonly string $name)
    {
    }

    public static function scenario(string $name): self
    {
        return new self($name);
    }

    public function app(Application $app): self
    {
        $this->app = $app;

        return $this;
    }

    /** 客户区尺寸（tight 约束：视口就是它，不用 `loose` —— 布局断言要可预测） */
    public function constraints(float $width, float $height): self
    {
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    /** 每帧推进的毫秒数（默认 16ms ≈ 60fps） */
    public function frameMs(float $ms): self
    {
        $this->frameMs = $ms;

        return $this;
    }

    /**
     * 跑 `$frames` 帧（第一帧由 `attach()` 完成，见 `$attached` 的注释）。
     */
    public function pump(int $frames = 1): self
    {
        $this->steps[] = ['pump ' . $frames, function () use ($frames): void {
            for ($i = 0; $i < $frames; $i++) {
                $this->nextFrame();
            }
        }];

        return $this;
    }

    /**
     * 断言：`$predicate` 收到被测应用，返回 true 即通过。
     *
     * ★ 闭包抛异常 ⇒ **记失败**（不中止整个场景）：一个场景里的断言互不牵连，
     *   否则第一条炸了后面的信息全丢（`07` §9 第 7 条）。
     */
    public function expect(string $what, callable $predicate): self
    {
        $this->steps[] = ['expect', function () use ($what, $predicate): void {
            $app = $this->requireApp($what);
            if ($app === null) {
                return;
            }
            try {
                $ok = (bool) $predicate($app);
            } catch (\Throwable $e) {
                $this->fail($what, '闭包抛了 ' . $e::class . '：' . $e->getMessage());

                return;
            }
            if ($ok) {
                $this->pass($what);

                return;
            }
            $this->fail($what, '谓词返回 false');
        }];

        return $this;
    }

    /**
     * 几何断言：选择器命中的盒的**绝对矩形**（`07` §5.2）。
     *
     * 严格 `===`，不加容差（布局是确定性的）。
     */
    public function expectBox(string $selector, float $x, float $y, float $w, float $h): self
    {
        $what = '盒 ' . $selector . ' = (' . $x . ', ' . $y . ') ' . $w . '×' . $h;
        $this->steps[] = ['expectBox', function () use ($selector, $x, $y, $w, $h, $what): void {
            $box = $this->boxOf($selector, $what);
            if ($box === null) {
                return;
            }
            $size = $box->size();
            if ($size === null) {
                $this->fail($what, '盒 size() 为 null（layout 没跑）');

                return;
            }
            [$ax, $ay] = RenderBox::absoluteOrigin($box);
            if ($ax === $x && $ay === $y && $size->width === $w && $size->height === $h) {
                $this->pass($what);

                return;
            }
            $this->fail($what, sprintf(
                '实际 (%.4g, %.4g) %.4g×%.4g',
                $ax, $ay, $size->width, $size->height
            ));
        }];

        return $this;
    }

    /**
     * 取客户区 `($x, $y)` 处的**像素色**（`null` = 还没跑过帧 / 坐标越界）。
     *
     * ★ 返回的是**合成后**的颜色（已过背景、遮罩、叠加），不是某个控件的源色。
     *   想知道"遮罩盖上去这里多暗"就用它；想断言"按钮背景是蓝的"得先确认
     *   没有别的东西压在上面。
     * ★ 一次 `raster()` 回放整帧命令（480×360 ≈ 17 万像素），同一帧内多次调用
     *   共用 `SurfaceProbe` 的缓存，连着取几个点不亏。
     */
    public function pixelAt(float $x, float $y): ?Color
    {
        return $this->pixelOf($x, $y);
    }

    /**
     * 像素断言：`($x, $y)` 处颜色逐通道差 ≤ `$tolerance`。
     *
     * ★★ `$expected` 必须是**合成后的最终色**，不是源色。例：`Color(0,0,0,90)`
     *   的半透明遮罩压在白底上，像素是 `(165,165,165)` —— 写 `(0,0,0)` 会红，
     *   而且红得让人以为"遮罩根本没画"。第一次用就会踩（smoke 那条场景的注释
     *   记了算式）。
     * ★ 默认容差 0：纯色区（遮罩、面板底、边框内缩处）都是确定的。**压在字上
     *   或 AA 边缘时才需要容差** —— 而那种位置本来就不该用单点断言。
     */
    public function expectPixel(float $x, float $y, Color $expected, int $tolerance = 0): self
    {
        $what = sprintf('像素 (%g, %g) = %s', $x, $y, self::colorText($expected));
        $this->steps[] = ['expectPixel', function () use ($x, $y, $expected, $tolerance, $what): void {
            $actual = $this->pixelOf($x, $y);
            if ($actual === null) {
                $this->fail($what, $this->surface === null || $this->surface->frames === 0
                    ? '一帧都没跑过（先 pump()）'
                    : sprintf('坐标 (%g, %g) 越出 %g×%g 客户区', $x, $y, $this->width, $this->height));

                return;
            }
            $delta = max(
                abs($actual->r - $expected->r),
                abs($actual->g - $expected->g),
                abs($actual->b - $expected->b),
                abs($actual->a - $expected->a),
            );
            if ($delta <= $tolerance) {
                $this->pass($what);

                return;
            }
            $this->fail($what, sprintf(
                '实际 %s（最大通道差 %d > 容差 %d）',
                self::colorText($actual),
                $delta,
                $tolerance
            ));
        }];

        return $this;
    }

    /**
     * 点一下选择器命中的盒（中心点），一次完整按下 + 抬起。
     *
     * ★ **不自动 `pump()`**：R4 的纪律是"帧是测试的时钟，测试必须控制它"。
     *   点击本身在 `dispatchInput()` 里同步生效（状态、命令都当场变），但布局/
     *   绘制要等下一帧 ⇒ 要断言几何就得紧接着 `->pump(1)`。与 `scroll()` 同规矩。
     * ★ 中心点而不是原点：控件有边框/内边距时原点可能落在边框上，命中不稳定
     *   （`ModalTest` 自己也是按 `panelRect` 中心算的）。
     * ★ `down` + `up` 都发：真实一次点击就是两相。只发 `down` 在多数控件上也能
     *   触发（`ModalTest` 就只发 down），但那是"按下即激活"的实现细节，
     *   依赖它会让场景在换成"抬起才激活"的控件上假绿。
     */
    public function tap(string $selector): self
    {
        $what = 'tap ' . $selector;
        $this->steps[] = [$what, function () use ($selector, $what): void {
            $box = $this->boxOf($selector, $what);
            if ($box === null) {
                return;
            }
            $size = $box->size();
            if ($size === null) {
                $this->fail($what, '盒 size() 为 null（layout 没跑）');

                return;
            }
            [$x, $y] = RenderBox::absoluteOrigin($box);
            $this->pointerClick($x + $size->width / 2.0, $y + $size->height / 2.0, $what);
        }];

        return $this;
    }

    /**
     * 在客户区坐标 `($x, $y)` 点一下。
     *
     * 选择器表达不了时才用它（遮罩、空白区、某个像素位置）。能用 `tap()` 就用
     * `tap()` —— 写死坐标的断言会在布局一改时红得莫名其妙。
     */
    public function tapAt(float $x, float $y): self
    {
        $what = sprintf('tapAt (%g, %g)', $x, $y);
        $this->steps[] = [$what, function () use ($x, $y, $what): void {
            $this->pointerClick($x, $y, $what);
        }];

        return $this;
    }

    /**
     * 在视口中心发一次滚轮事件。
     *
     * ★★ 单位是**格**（不是像素）：`ScrollEvent::$delta` 是平台量化出的格数，
     *   "格 → 像素"只在 `Application` 里换算一次（`WHEEL_NOTCH_PX = 48`）。
     *   参数名用 `$notches` 就是为了不让调用方以为能传像素 —— 传 120 会被当成
     *   120 格（= 5760px，直接顶到底），而断言写成"滚了 120px"就永远红。
     *
     * ★ 只给纵向：横向滚动在本仓的应用里没有使用者，参数留着就是没人走的分支。
     */
    public function scroll(float $notches): self
    {
        $this->steps[] = ['scroll ' . $notches, function () use ($notches): void {
            $app = $this->requireApp('滚轮');
            if ($app === null) {
                return;
            }
            $x = $this->width / 2.0;
            $y = $this->height / 2.0;
            $app->dispatchInput(ScrollEvent::make($x, $y, $notches));
        }];

        return $this;
    }

    /**
     * 跑到**动画收口**：一直 `nextFrame()` 到框架自己说"不用下一帧了"。
     *
     * ★ 为什么需要这个动词：滚轮是**带动画**的（`Scrollable::glideByAxis()`），
     *   `scroll()` 之后偏移**不会当场到位** —— 拍一帧只走一部分。写 `pump(60)`
     *   也行，但那个数字是从"半衰期 55ms"推出来的，曲线一调就得跟着改，
     *   且改错了的现象是"断言偶尔红"，很难查。这里直接问 `wantsFrame()`：
     *   框架说停就是停。
     * ★ 上界 `$maxFrames`：`wantsFrame()` 恒真（真 bug，比如帧时钟停不下来）
     *   时不能把整条 smoke 挂死 —— 有界失败比无限等待好。
     * ★ 它等的是"帧时钟空闲"，比"滚动动画走完"更宽（滚动条淡出还要 900ms），
     *   所以 `scroll()` 之后用它比用固定帧数稳。
     * ★★ **必须先跑一帧再问**：`wantsFrame()` 反映的是**上一帧**的结果
     *   （`animating` 在 `frame()` 里才被赋值），`scroll()` 刚 dispatch 完还没跑过帧
     *   时它一律是 `false` —— 先问就会"立刻收工"，偏移停在 0 而断言全绿到红。
     *   这是本方法第一版真踩到的（smoke 里 `offset` 一直是 0）。
     */
    public function settle(int $maxFrames = 240): self
    {
        $this->steps[] = ['settle', function () use ($maxFrames): void {
            for ($i = 0; $i < $maxFrames; ++$i) {
                $this->nextFrame();
                $app = $this->requireApp('settle');
                if ($app === null || !$app->wantsFrame()) {
                    return;
                }
            }
        }];

        return $this;
    }

    /**
     * 执行全部步骤（声明序）。
     *
     * @return array{name: string, passed: int, failed: int, failures: list<string>}
     */
    public function run(): array
    {
        $this->passed = 0;
        $this->failed = 0;
        $this->failures = [];

        foreach ($this->steps as [, $step]) {
            $step();
        }

        return [
            'name' => $this->name,
            'passed' => $this->passed,
            'failed' => $this->failed,
            'failures' => $this->failures,
        ];
    }

    // ── 内部 ────────────────────────────────────────────────────────────

    /** 读最后一帧的像素（`null` = 没跑过帧 / 越界） */
    private function pixelOf(float $x, float $y): ?Color
    {
        $canvas = $this->surface?->raster();
        if ($canvas === null) {
            return null;
        }
        // TestApp 的 dpr 恒 1 ⇒ 逻辑坐标就是物理坐标，上界直接用逻辑尺寸比
        $px = (int) floor($x);
        $py = (int) floor($y);
        if ($px < 0 || $py < 0 || $px >= (int) round($this->width) || $py >= (int) round($this->height)) {
            return null;
        }

        return $canvas->pixelAt($px, $py);
    }

    private static function colorText(Color $color): string
    {
        return $color->a === 255
            ? sprintf('#%02X%02X%02X', $color->r, $color->g, $color->b)
            : sprintf('#%02X%02X%02X/%d', $color->r, $color->g, $color->b, $color->a);
    }

    /** 一次指针点击（down → up）。坐标是**逻辑客户区**坐标（`PointerEvent` 的口径）。 */
    private function pointerClick(float $x, float $y, string $what): void
    {
        $app = $this->requireApp($what);
        if ($app === null) {
            return;
        }
        $app->dispatchInput(PointerEvent::down($x, $y));
        $app->dispatchInput(PointerEvent::up($x, $y));
    }

    private function nextFrame(): void
    {
        $app = $this->requireApp('pump');
        if ($app === null) {
            return;
        }
        $constraints = BoxConstraints::tight(new Size($this->width, $this->height));
        if (!$this->attached) {
            // ★ resize 必须在 attach 之前：attach 内部会直接跑首帧，
            //   表面尺寸没设就画了 ⇒ 像素恒 0×0，`raster()` 一读就越界。
            $this->surface = new SurfaceProbe();
            $this->surface->resize((int) round($this->width), (int) round($this->height), 1.0);
            $app->attach($this->surface, $constraints);
            $this->attached = true;

            return;
        }
        $this->clock += $this->frameMs;
        $app->frame($this->clock, $constraints);
    }

    private function requireApp(string $what): ?Application
    {
        if ($this->app !== null) {
            return $this->app;
        }
        $this->fail($what, '场景 "' . $this->name . '" 没有 ->app()（G2：配错了要响亮）');

        return null;
    }

    private function boxOf(string $selector, string $what): ?RenderBox
    {
        $element = $this->elementOf($selector, $what);
        if ($element === null) {
            return null;
        }
        $box = $element->effectiveBox();
        if ($box === null) {
            $this->fail($what, '元素没有盒（无绘制内容 / 没 layout）');

            return null;
        }

        return $box;
    }

    /**
     * 按选择器找**唯一**的 Element（R2：0 个或 ≥2 个都算失败）。
     *
     * ★★ 遍历面 = **主树 + 全部浮层**。浮层（模态 / 菜单 / Snackbar / Tooltip）
     *   挂在自己的 Element 上，**不在 `root()` 的子树里** ⇒ 只看主树的话，
     *   `tap('IconButton')` 点模态右上角那个 X 会报"一个都没匹配到"，
     *   而现象看起来像"模态根本没开"（假失败比漏测更贵）。
     *   同一份选择器口径必须同时服务 `tap()` 与 `expectBox()`，所以修在这里
     *   而不是在 `tap()` 里另开一条查找。
     */
    private function elementOf(string $selector, string $what): ?Element
    {
        $app = $this->requireApp($what);
        if ($app === null) {
            return null;
        }
        $root = $app->root();
        if ($root === null) {
            $this->fail($what, '应用还没挂载（先 pump()）');

            return null;
        }

        $matches = [];
        $walk = function (Element $element) use (&$walk, &$matches, $selector): void {
            if (self::matches($element, $selector)) {
                $matches[] = $element;
            }
            $element->visitChildren($walk);
        };
        $walk($root);
        foreach ($app->overlays()->all() as $entry) {
            if ($entry->element !== null) {
                $walk($entry->element);
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }
        $this->fail($what, count($matches) === 0
            ? '选择器 ' . $selector . ' 一个都没匹配到'
            : '选择器 ' . $selector . ' 匹配到 ' . count($matches) . ' 个（R2 要求唯一）');

        return null;
    }

    /**
     * 选择器两种形态：
     * - `#foo` ⇒ `Widget::key('foo')` 的身份（`Key::value` 转字符串比）
     * - `LazyColumn` ⇒ Widget 的**短类名**（不带命名空间）
     */
    private static function matches(Element $element, string $selector): bool
    {
        if (str_starts_with($selector, '#')) {
            $key = $element->widget()->identity();

            return $key !== null && (string) $key->value === substr($selector, 1);
        }
        $class = $element->widget()::class;
        // ★ `strrpos` 找不到时返回 false，`(int) false === 0` ⇒ 会吃掉首字母
        //   （`SourceFile::registerUse()` 踩过同一个坑）
        $sep = strrpos($class, '\\');

        return ($sep === false ? $class : substr($class, $sep + 1)) === $selector;
    }

    private function pass(string $what): void
    {
        ++$this->passed;
    }

    private function fail(string $what, string $detail): void
    {
        ++$this->failed;
        $this->failures[] = $what . ' —— ' . $detail;
    }
}
