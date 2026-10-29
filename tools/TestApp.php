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
 * ★★ **只实现"被场景走通过"的动词**。`tap()` / `type()` 暂不提供：三个场景
 *   都没用到它们，先写就是"写了但没被走通过"的分支（§24.2 #25）。要加它们，
 *   先加一个走通过它们的场景。
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

    private function nextFrame(): void
    {
        $app = $this->requireApp('pump');
        if ($app === null) {
            return;
        }
        $constraints = BoxConstraints::tight(new Size($this->width, $this->height));
        if (!$this->attached) {
            $app->attach(new SurfaceProbe(), $constraints);
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

    /** 按选择器找**唯一**的 Element（R2：0 个或 ≥2 个都算失败） */
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
