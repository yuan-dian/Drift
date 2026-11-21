<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use App\Showcase\Pages\Base\ShowcasePage;
use Drift\Core\Computed;
use Drift\Geometry\EdgeInsets;
use Drift\Route\Route;
use Drift\State\Cell;
use Drift\Widget\Bind;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Divider;
use Drift\Widget\IconName;
use Drift\Widget\LiveText;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * 响应式 Reactive——`Cell` / `Computed` / `Bind` / `LiveText` 的分档示范。
 *
 * ★ **这一页存在的理由**：R0 落地的读点响应式，在此之前只有一个真实消费者
 *   （Buttons 页的 Store），而 `LiveText` 的消费者**只有测试**。按"无调用者 =
 *   潜伏假实现"的纪律，那不算实现过。这一页给它一个真实运行点。
 *
 * ★ **演示的是粒度，不是用法**：同一个高频源（`ticks`，每 100ms +1）同时喂
 *   叶级 `LiveText`、段级 `Bind`、页级 `build()`。三个计数器摆在页面上，
 *   跑起来一眼就能分开：
 *   - `ticks` 飞涨而 `pageBuilds` 不动 ⇒ 叶级真的没走 build；
 *   - 点一次按钮 `pageBuilds` 才 +1 ⇒ 页级读点确实按整页重建。
 *
 * ★ **初始静止**：`running` 默认 false、`tickFrame()` 首句就返回 false ⇒ 首帧
 *   是死值，像素基线才可能稳（与 MotionPage 同理；跑起来相位每帧都不同）。
 */
#[Route(id: 'reactive', title: '响应式 Reactive', icon: IconName::Sliders)]
final class ReactivePage extends ShowcasePage
{
    /** 高频源推进间隔（毫秒）——约 10 次/秒，肉眼能跟住"数值在跳" */
    private const TICK_MS = 100.0;

    /** 自动停止上限：防"点了开始就切走"把帧时钟一直吊着（10/秒 ⇒ 60 秒） */
    private const MAX_TICKS = 600;

    /**
     * 高频源：模拟时钟 / 进度 / 传感器读数这类"一直在变"的值。
     *
     * ★ 它只被 `LiveText` 与 `Bind` 读，**页面 `build()` 一次都不碰** ——
     *   这就是 `pageBuilds` 不跟着涨的唯一原因。读点即边界，读到哪一层就重建到哪一层。
     */
    private Cell $ticks;

    /** 低频源：用户点一次变一次 */
    private Cell $clicks;

    /** 派生值：`clicks × 2`。`Computed` 按依赖版本重算，不是按值变 */
    private Computed $doubled;

    private bool $running = false;

    /** 上一 tick 的帧时间戳；`0` = 还没起步（帧时间戳可能很大，不能用 0 当有效值） */
    private float $lastTickMs = 0.0;

    private int $tickCount = 0;

    /**
     * ★ **判据 1**：整页 `build()` 跑了几次。
     *
     * 高频源变而它不动，才证明 `LiveText` 走的是叶子路径而不是"又一个 build"。
     * 没有这个数，"零重建"只能靠读代码相信。
     */
    private int $pageBuilds = 0;

    /** ★ **判据 2**：`Bind` 的闭包跑了几次 —— 段级，比整页少但比叶子多 */
    private int $bindBuilds = 0;

    public function initState(): void
    {
        $this->ticks = Cell::of(0)->named('ticks');
        $clicks = Cell::of(0)->named('clicks');
        $this->clicks = $clicks;

        // 只钉住那一个格子，不在闭包里用 $this —— 派生值不该把整页钉在依赖图上。
        $this->doubled = Computed::of(static fn(): int => ((int) $clicks->get()) * 2);
    }

    /**
     * 每帧推进（`State::tickFrame()` 入口）。
     *
     * ★ 返回值直接决定"还要不要下一帧"：`running` 为假立刻返回 false ⇒ 帧时钟停摆、
     *   空闲 CPU 归零。跑满上限也主动停，不留一个"忘了点停止"就永远转的页面。
     */
    public function tickFrame(float $timestampMs): bool
    {
        if (!$this->running) {
            return false;
        }

        if ($this->tickCount >= self::MAX_TICKS) {
            $this->running = false;
            $this->markDirty();

            return false;
        }

        if ($this->lastTickMs <= 0.0) {
            $this->lastTickMs = $timestampMs;
        }

        if ($timestampMs - $this->lastTickMs < self::TICK_MS) {
            // 还没到下一个 tick，但仍在跑 ⇒ 继续要帧，别让时钟睡过去。
            return true;
        }

        $this->lastTickMs = $timestampMs;
        ++$this->tickCount;

        // ★ 只写格子、**不 markDirty()**：写了整页就得重建，那这个示范就白做了。
        $this->ticks->update(static fn(int $n): int => $n + 1);

        return true;
    }

    public function build(BuildContext $ctx): Widget
    {
        ++$this->pageBuilds;

        return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
    }

    private function body(): Widget
    {
        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('响应式 Reactive')->title(),
            Text::make('同一个高频源喂三档粒度：叶级 LiveText（零 build）、段级 Bind（只重跑闭包）、页级 build（整页重跑）。下面的计数器是判据，不是装饰。')
                ->caption(),

            Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                Button::make($this->running ? '停止' : '开始')
                    ->variant(ButtonVariant::Primary)
                    ->onPressed(function (): void {
                        $this->running = !$this->running;
                        if ($this->running) {
                            $this->tickCount = 0;
                            $this->lastTickMs = 0.0;
                        }

                        // ★ `markDirty()` 一个就够：它除了标脏**还会把自己登记进动效活跃表**
                        //   （`State::markDirty()` 尾段 `noteActiveAnimation()`），而帧时钟
                        //   只 tick 表里的人。这里不用再补 `requestFrame()` —— 那是给
                        //   "`build()` / `didUpdateWidget()` 这类 settle 期才起飞"的场景的，
                        //   那时本帧 tick 段已跑过，不举手就停在起点。
                        $this->markDirty();
                    }),
                Button::make('重置计数')
                    ->onPressed(function (): void {
                        // ★ 清零后本帧的这次重建会立刻把它记成 1 —— 计数的是"重建次数"，
                        //   不是"内容版本"，所以最小可见值是 1 而不是 0。
                        $this->pageBuilds = 0;
                        $this->bindBuilds = 0;
                        $this->markDirty();
                    }),
            ]),

            Divider::make(),

            // ── A：叶级 ──
            Text::make('A · 叶级：LiveText（零 build）')->heading(),
            Row::make()->spacing(8.0)->children([
                Text::make('ticks = '),
                // ★ 值变只 syncBox() 换渲染盒：build() 永不调用，兄弟节点身份不动。
                LiveText::of($this->ticks)->body(),
            ]),
            Text::make('整页 build 次数：' . $this->pageBuilds)->body(),
            Text::make('这一行读的是页面的 pageBuilds 字段，只有整页重跑才会变。开始后 ticks 每 100ms 跳一次，这一行却定住不动 —— 因为 ticks 的唯一读点在 LiveText 自己那一层。')
                ->caption(),

            Divider::make(),

            // ── B：段级 ──
            Text::make('B · 段级：Bind（只重跑这一小段）')->heading(),
            Bind::make(function (): Widget {
                ++$this->bindBuilds;

                return Text::make('clicks = ' . $this->clicks->get() . ' ｜ 本段 build 次数 = ' . $this->bindBuilds);
            }),
            Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                Button::make('clicks +1')->onPressed(function (): void {
                    // 不 setState：写格子就够了，订阅者自己标脏。
                    $this->clicks->update(static fn(int $n): int => $n + 1);
                }),
            ]),
            Text::make('Bind 的闭包读了什么就依赖什么，不需要手写切片（漏写依赖这个失效模式在这里不存在）。')
                ->caption(),

            Divider::make(),

            // ── C：页级 ──
            Text::make('C · 页级：build() 里直接读')->heading(),
            Text::of($this->doubled, 'clicks × 2 = ')->body(),
            Text::make('这一行走 Text::of(Computed)：读点在本页 State 上，所以 clicks 一变，整页重建一次（整页 build 次数 +1）。')
                ->caption(),

            Divider::make(),

            // ── 整页快照：只在整页 build 时刷新 ──
            Text::make('整页快照（只在整页 build 时刷新）')->heading(),
            Text::make(sprintf(
                'ticks = %d ｜ 整页 build = %d ｜ 派生重算 = %d',
                (int) $this->ticks->peek(),
                $this->pageBuilds,
                $this->doubled->runs(),
            ))->body(),
            Text::make('★ 这一行就是判据：它只在整页 build 时被重新构造。跑起来 A 段的 ticks 飞涨、这里却定住 —— 因为高频值一个读点都不在页面 build 里。点一次 clicks，这一行才跳到新值（且整页 build 次数 +1）。')
                ->caption(),
        ]);
    }
}
