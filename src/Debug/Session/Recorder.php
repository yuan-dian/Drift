<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

use Drift\Input\InputEvent;
use Drift\Rendering\SurfaceProfile;
use Drift\Widget\Element;
use Drift\Widget\State;

/**
 * 帧因果录制器：一帧一条记录，边指向"为什么重建"。
 *
 * ## 为什么是这三个挂点
 *
 * 全框架只有三处会产生"可归因的变化"，别处都是它们的下游：
 *
 * | 挂点 | 位置 | 记什么 |
 * |---|---|---|
 * | 状态写入 | `State::markDirty()` 的 clean→dirty 跳变 | 谁写的、在哪一行写的 |
 * | 节点重建 | `Element::settlePass()` 里真正调 `rebuild()` 前 | 本帧重建了哪些节点 |
 * | 帧边界 | `Application::frame()` 进出 | 帧号、跑的段、是否跳过 settle/paint |
 *
 * 写入与重建**不在同一时刻**：`setState` 多数发生在帧外，由下一帧的 settle 消费。
 * 所以边先攒着，帧末统一归到"消费它的那一帧"——这正是因果的方向。
 *
 * 未安装时每个挂点只多一次静态空判断，热路径可忽略。装了才建对象。
 *
 * ## 为什么不持有 Element
 *
 * 记录里只有 id 与父 id：环形缓冲裁剪时不能拖住一棵已卸载的树。元素被销毁后
 * 它的 id 仍能在历史帧里解释清楚（`pathOf()` 读的是登记时记下的类名与父链）。
 *
 * ## 为什么同时记一份脚本
 *
 * 帧记录回答"为什么变成这样"，脚本回答"当时是怎么驱动的"。缺了后者，
 * 记录只可读、不可复现 —— 而"第 37 帧闪了一下"这种问题，重跑一遍才是终点。
 * 脚本在**同一个挂点**上记（帧边界 + 派发入口），不另起一套旁路：
 * 驱动方式与因果事实必须来自同一次运行，否则拼起来的现场是假的。
 *
 * 脚本可以为 `null`（直接 `install()` 的测试只关心因果边）。传了才记，
 * 少一次序列化开销，也避免"没人读的脚本悄悄吃掉内存"。
 *
 * @api
 */
final class Recorder
{
    /** 保留的最大帧数；超出丢最旧的一帧 */
    public const int DEFAULT_CAPACITY = 600;

    /** 单帧最多记多少条因果边（防御：装了录制却不跑帧） */
    private const int MAX_CAUSES = 512;

    private static ?self $current = null;

    /**
     * 量具是不是我们打开的。
     *
     * 只有自己打开的量具才能帧首清零、帧末读账：调用方本来开着（bench 的 M4）
     * 时那是他的跨帧累计，清一个字都是替他毁数据 —— 那种情况宁可不记段耗时。
     */
    private static bool $ownsProfile = false;

    /** @var array<int, FrameRecord> 帧号 => 记录，键升序 */
    private array $frames = [];

    /** @var array<int, array{p: int, c: string}> 节点 id => 父 id（`-1` 根、`-2` 未解析）/ Widget 短类名 */
    private array $nodes = [];

    /** @var list<Edge> 待归入下一帧的因果边（帧外 `setState` 也算，帧末统一落账） */
    private array $openCauses = [];

    /** @var list<int> 待归入下一帧的重建节点，先序 */
    private array $openRebuilt = [];

    /** @var array<int, true> 本帧已记过 state 边的节点（同帧去重） */
    private array $openWrote = [];

    /** @var array<int, true> 本帧已重建过的节点（同帧去重） */
    private array $openSaw = [];

    private int $seq = 0;

    private int $frameId = 0;

    private bool $open = false;

    private float $openT = 0.0;

    public function __construct(
        private readonly int $capacity = self::DEFAULT_CAPACITY,
        private readonly ?Script $script = null,
    ) {
    }

    // ---------------------------------------------------------------- 生命周期

    public static function install(int $capacity = self::DEFAULT_CAPACITY, ?Script $script = null): self
    {
        // 段耗时不用第二套计时：`SurfaceProfile` 已经是全仓唯一的"计一段"入口
        // （九段里的 settle/layout/paint 在 `Frame::run()` 里记，壳的 stage() 拿不到）。
        // 装录制器就把量具打开，帧首清零、帧末读账 —— 两条驱动路径拿到同一份数据。
        if (self::$current === null) {
            self::$ownsProfile = !SurfaceProfile::$on;
            if (self::$ownsProfile) {
                SurfaceProfile::$on = true;
            }
        }

        return self::$current = new self($capacity, $script);
    }

    public static function uninstall(): void
    {
        if (self::$current !== null && self::$ownsProfile) {
            SurfaceProfile::$on = false;
            SurfaceProfile::reset();
        }
        self::$current = null;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function installed(): bool
    {
        return self::$current !== null;
    }

    // -------------------------------------------------------------- 热路径挂点

    /**
     * 帧开始：只记时间，**不清缓冲**。
     *
     * 缓冲里可能有上一帧结束后攒下的状态写入（帧外 `setState`），它们属于本帧。
     */
    public function beginFrame(float $t): void
    {
        ++$this->frameId;
        $this->open = true;
        $this->openT = $t;
        // 帧步骤带的是**注入的时间戳**，不是墙钟：脚本要能在另一台机器上重演出
        // 同一串时刻，而墙钟每跑一次都不一样。
        $this->script?->add(Step::frame($t));
        // 量具是累计账：不清零就会把上一帧的耗时记到这一帧头上。
        if (self::$ownsProfile) {
            SurfaceProfile::reset();
        }
    }

    /**
     * 帧结束：落成不可变记录并按容量裁剪。
     *
     * @param array{stages?: list<string>, settleSkipped?: bool, paintSkipped?: bool} $facts
     */
    public function endFrame(array $facts, float $costMs): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        $this->frames[$this->frameId] = new FrameRecord(
            $this->frameId,
            $this->openT,
            $costMs,
            $this->openCauses,
            $this->openRebuilt,
            $facts['stages'] ?? [],
            // 读累计账而不是 perCall()：一段一帧跑多次时平均会低估，累计才是花掉的时间。
            self::$ownsProfile ? SurfaceProfile::$ns : [],
            (bool) ($facts['settleSkipped'] ?? false),
            (bool) ($facts['paintSkipped'] ?? false),
        );
        $this->openCauses = [];
        $this->openRebuilt = [];
        $this->openWrote = [];
        $this->openSaw = [];
        $this->trim();
    }

    /** 状态写入（clean→dirty 跳变时调；同帧同节点只记第一条）。 */
    public function noteStateWrite(State $state): void
    {
        // ★ **不受 `open` 约束**：`setState` 多数发生在帧外（输入回调 / 定时器 /
        //   异步结算），由**下一帧**的 settle 消费。丢弃它们就等于丢掉了因果链的起点。
        $element = $state->element();
        if ($element === null) {
            return;
        }
        $id = $this->idOf($element);
        if (isset($this->openWrote[$id])) {
            return;
        }
        if (\count($this->openCauses) >= self::MAX_CAUSES) {
            return; // 防御：装了录制却一直不跑帧时，不让缓冲无界增长
        }
        $this->openWrote[$id] = true;
        // `from` 记的是**结构父**，不是"谁让它写的"（那由 `site` 回答）。不记它，
        // "自己写了状态的节点"在树里就没有父，`cascade` 会给它另起一个根 ——
        // 于是同一次级联被画成两棵并行的树。
        $parent = $element->parent();
        $parentId = $parent === null ? 0 : $this->idOf($parent);
        $this->openCauses[] = new Edge(
            'state',
            $parentId === 0 ? null : $parentId,
            $id,
            self::callSite(),
            self::shortClass($state),
        );
    }

    /** 节点真的调了 `rebuild()`。父先于子（settlePass 前序），所以父的分类一定已知。 */
    public function noteRebuild(Element $element): void
    {
        if (!$this->open) {
            return;
        }
        $id = $this->idOf($element);
        if (isset($this->openSaw[$id])) {
            return;
        }
        $this->openSaw[$id] = true;
        $this->openRebuilt[] = $id;

        if (isset($this->openWrote[$id])) {
            return; // 自己的 state 边已经记过
        }

        $parent = $element->parent();
        $parentId = $parent === null ? 0 : $this->idOf($parent);
        $kind = $parentId !== 0 && isset($this->openSaw[$parentId]) ? 'parent' : 'unknown';
        $this->openCauses[] = new Edge($kind, $parentId === 0 ? null : $parentId, $id, '', '');
    }

    /**
     * 引擎收到一条输入（`Application::dispatchInput()` 的入口）。
     *
     * ★ 记**标注前**的事件：`TapTracker` 会在派发链里按时间标"第几次点击"，
     *   这里记原值，重放时由同一条链重新算。记标注后的值会让重放**再标一次**
     *   （双击变三击），而那种偏差在报告里看不出来。
     */
    public function noteInput(InputEvent $event, ?float $t): void
    {
        $this->script?->add(InputScript::pack($event, $t));
    }

    /**
     * 驱动方的具名副作用（异步结算、换页……）。
     *
     * ★ 没有它，异步页的脚本"帧和输入都对、数据永远不到"：报告会显示一整段
     *   静止画面，看起来像"这页什么都不做"。
     *
     * @param array<string, scalar|null> $args
     */
    public function noteEffect(string $name, array $args, ?float $t): void
    {
        $this->script?->add(Step::effect($name, $args, $t));
    }

    // ------------------------------------------------------------------ 查询

    /** 录制中的脚本；没开脚本时为 `null`。 */
    public function script(): ?Script
    {
        return $this->script;
    }

    /**
     * 节点登记表：id ⇒ 父 id（`-1` 根 / `-2` 未解析）+ Widget 短类名。
     *
     * 摘要与将来的跨进程传输都要它：光有一串 id，换台机器就不知道是谁。
     *
     * @return array<int, array{p: int, c: string}>
     */
    public function nodeIndex(): array
    {
        return $this->nodes;
    }

    /** 节点 id；首次见到时登记父链与类名（父 id 解析一次，`-1` = 根）。 */
    public function idOf(Element $element): int
    {
        if ($element->debugId === 0) {
            $element->debugId = ++$this->seq;
            // 记 **Widget** 名而不是 Element 子类名：面板上要看到 `Table` / `Column`，
            // 不是一屏 `StatefulElement` / `MultiChildElement`。
            $this->nodes[$element->debugId] = ['p' => -2, 'c' => self::shortClass($element->widget())];
        }

        $meta = $this->nodes[$element->debugId];
        if ($meta['p'] === -2) {
            $parent = $element->parent();
            $meta['p'] = $parent === null ? -1 : $this->idOf($parent);
            $this->nodes[$element->debugId] = $meta;
        }

        return $element->debugId;
    }

    /** 节点的 Widget 短类名（树按类型展开、按类型聚合都用它）。 */
    public function typeOf(int $nodeId): string
    {
        return $this->nodes[$nodeId]['c'] ?? '';
    }

    /** 从根到该节点的类名路径，用于面板显示与"这是哪个节点"的自证。 */
    public function pathOf(int $nodeId): string
    {
        $parts = [];
        $cursor = $nodeId;
        $guard = 0;
        while ($cursor > 0 && isset($this->nodes[$cursor]) && $guard++ < 32) {
            \array_unshift($parts, $this->nodes[$cursor]['c']);
            $cursor = $this->nodes[$cursor]['p'];
        }

        return \implode(' > ', $parts);
    }

    /**
     * 为什么这个节点在这一帧变了。
     *
     * `trail` 是从目标沿父边一路走到根的链路（目标在前、根在末尾）。
     * 只印直接边等于回答"因为父节点重建了"却不说父是谁 —— 开发者要的正是
     * "哪条链把我带下来的"。链路来自 `FrameRecord::parentOf()`，与 `cascade`
     * 共用同一份拓扑，不另算。
     *
     * @return array<string, mixed>
     */
    public function why(int $nodeId, ?int $frameId = null): array
    {
        // 不指定帧时取**最近一次重建它的帧**，不是最后一帧：多动作场景里最后一帧
        // 往往是静止帧，按最后一帧回答会得到"它没有重建"，把问题原样退回来。
        $frame = $frameId !== null ? $this->frame($frameId) : $this->latestRebuiltFrame($nodeId);
        $causes = [];
        $trail = [];
        if ($frame !== null) {
            foreach ($frame->causes as $edge) {
                if ($edge->toId === $nodeId) {
                    $causes[] = $edge->toArray();
                }
            }

            $parent = $frame->parentOf();
            $causeOf = $frame->causeOf();
            $cursor = $nodeId;
            $guard = 0;
            while ($guard++ < 64) {
                $edge = $causeOf[$cursor] ?? null;
                $trail[] = [
                    'id' => $cursor,
                    'path' => $this->pathOf($cursor),
                    'kind' => $edge?->kind ?? 'none',
                    'site' => $edge?->site ?? '',
                    'detail' => $edge?->detail ?? '',
                ];
                $next = $parent[$cursor] ?? null;
                // 因果链到根因为止：`state` 是"为什么"的答案本身，再往上问的是
                // "谁带着它重建" —— 那是层级问题，由 `cascade` 的树回答。
                if ($edge !== null && $edge->kind === 'state') {
                    break;
                }
                if ($next === null || $next === $cursor) {
                    break;
                }
                $cursor = $next;
            }
        }

        return [
            'node' => $nodeId,
            'path' => $this->pathOf($nodeId),
            'frame' => $frame?->id,
            'rebuilt' => $frame?->rebuilt($nodeId) ?? false,
            'causes' => $causes,
            'trail' => $trail,
        ];
    }

    /** 最近一次重建过该节点的帧；一次都没有则退回最后一帧（好让 `why` 如实说"没重建"）。 */
    private function latestRebuiltFrame(int $nodeId): ?FrameRecord
    {
        foreach (\array_reverse($this->frames, true) as $frame) {
            if ($frame->rebuilt($nodeId)) {
                return $frame;
            }
        }

        return $this->frame($this->lastFrameId());
    }

    public function frame(?int $id = null): ?FrameRecord
    {
        if ($id === null) {
            return null;
        }

        return $this->frames[$id] ?? null;
    }

    public function lastFrameId(): int
    {
        $key = \array_key_last($this->frames);

        return $key === null ? 0 : (int) $key;
    }

    /** @return array<int, FrameRecord> */
    public function frames(): array
    {
        return $this->frames;
    }

    /** 给性能预算与面板概览用：帧数、节点数、最近帧耗时。 */
    public function stats(): array
    {
        $last = $this->frame($this->lastFrameId());

        return [
            'frames' => \count($this->frames),
            'nodes' => \count($this->nodes),
            'capacity' => $this->capacity,
            'lastCostMs' => $last?->costMs ?? 0.0,
            'lastRebuilt' => $last === null ? 0 : \count($last->rebuilt),
        ];
    }

    /** 清空帧记录（保留节点登记，id 仍可用）。测试与"重新开始录"用。 */
    public function reset(): void
    {
        $this->frames = [];
        $this->frameId = 0;
        $this->open = false;
        $this->openCauses = [];
        $this->openRebuilt = [];
        $this->openWrote = [];
        $this->openSaw = [];
    }

    // ------------------------------------------------------------ 静态热路径入口

    public static function tapStateWrite(State $state): void
    {
        self::$current?->noteStateWrite($state);
    }

    public static function tapInput(InputEvent $event, ?float $t): void
    {
        self::$current?->noteInput($event, $t);
    }

    public static function tapRebuild(Element $element): void
    {
        self::$current?->noteRebuild($element);
    }

    // ------------------------------------------------------------------ 内部

    private function trim(): void
    {
        while (\count($this->frames) > $this->capacity) {
            $oldest = \array_key_first($this->frames);
            if ($oldest === null) {
                return;
            }
            unset($this->frames[$oldest]);
        }
    }

    private static function shortClass(object $value): string
    {
        $class = $value::class;
        $pos = \strrpos($class, '\\');

        return $pos === false ? $class : \substr($class, $pos + 1);
    }

    /**
     * 调用点：跳过录制器与 State 自身，取第一个业务帧。
     *
     * AOT 下 `debug_backtrace` 可能缺 file/line，缺了就返回空串——宁可少一条线索，
     * 也不要为了它让热路径报错。
     */
    private static function callSite(): string
    {
        if (!\function_exists('debug_backtrace')) {
            return '';
        }
        $trace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        foreach ($trace as $frame) {
            $class = isset($frame['class']) ? (string) $frame['class'] : '';
            if ($class !== '' && (\str_starts_with($class, 'Drift\\Debug\\') || $class === 'Drift\\Widget\\State')) {
                continue;
            }
            $file = isset($frame['file']) ? (string) $frame['file'] : '';
            if ($file === '') {
                continue;
            }
            $cwd = \getcwd();
            if ($cwd !== false && \str_starts_with($file, $cwd)) {
                $file = \ltrim(\substr($file, \strlen($cwd)), '/\\');
            }

            // 统一成正斜杠：同一份记录在 Windows 与 Linux 上要能逐字比对，
            // 报告里也不会一会儿 `src\Widget` 一会儿 `src/Widget`。
            $file = \str_replace('\\', '/', $file);

            return $file . ':' . (isset($frame['line']) ? (string) $frame['line'] : '0');
        }

        return '';
    }
}
