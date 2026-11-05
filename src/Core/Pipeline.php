<?php
declare(strict_types=1);

namespace Drift\Core;

/**
 * 一帧管线的可变状态（结构/内容版本号、脏记账、换代序号、量具）。
 * 一个应用一份：进程级"当前管线"指针由帧入口切换，帧外落到默认管线。
 * @internal 只给框架内部与测试 / devtools 用；应用代码不该读它（`@api` 没有它）。
 */
final class Pipeline
{
    /**
     * 最近一次进入帧的管线（帧入口切换它）。
     * `current()` 可能在帧之前被调（纯单测直接 `layout()`），此时没有
     * 本应用的管线，惰性建一份进程默认实例顶上。
     */
    private static ?Pipeline $current = null;

    /** 当前管线（没有帧跑过时返回进程默认实例，见 `$current`） */
    public static function current(): Pipeline
    {
        $pipeline = self::$current;
        if ($pipeline === null) {
            $pipeline = new Pipeline();
            self::$current = $pipeline;
        }

        return $pipeline;
    }

    /**
     * 把"当前管线"切到 `$pipeline`（帧入口调）。
     * 不恢复上一个：帧内测试/探针要读的是刚跑完那一帧的计数；恢复会
     * 把读打回默认管线（读回 0）。多应用交错只发生在测试里，用
     * `$app->pipeline()` 显式读。
     */
    public static function enter(Pipeline $pipeline): void
    {
        self::$current = $pipeline;
    }

    // ─────────────────────────────────────────────────────────────────
    // ① 树的结构 / 内容版本（`Element` 写，`Frame` / `FocusManager` 读）
    // ─────────────────────────────────────────────────────────────────

    private int $structureVersion = 0;

    private int $contentVersion = 0;

    /**
     * @internal 节点增删或重排了（`Element` 的 `$this->pipeline()->bumpStructure()`）
     * ★ **只在真的变了时 +1**：`updateChildren()` 每次 rebuild 都会被调，
     *   无条件 +1 会让版本号每帧都变 ⇒ "结构没变"永远判不出来（等于没做）。
     */
    public function bumpStructure(): void
    {
        ++$this->structureVersion;
        ++$this->contentVersion;

        // 新挂载的节点可能一出生就在动（`Spinner` / 入场动画），做一次全树发现。
        $this->animationScan = true;
    }

    /** @internal 结构 ∪ 声明（`Element::update()` 的唯一写点）—— 只动内容号，不动结构号 */
    public function bumpContent(): void
    {
        ++$this->contentVersion;
    }

    /** @internal 只有增删/重排 —— `Frame::needsSettle()` 的判据之一 */
    public function structureVersion(): int
    {
        return $this->structureVersion;
    }

    /** @internal 结构 ∪ 声明 —— "从树里收一张全表"的消费者用（焦点链 / 语义树） */
    public function contentVersion(): int
    {
        return $this->contentVersion;
    }

    // ─────────────────────────────────────────────────────────────────
    // ② 脏记账（`State` 写，`Element::settle()` / `Frame` / `Application` 读）
    // ─────────────────────────────────────────────────────────────────

    private int $pendingDirty = 0;

    private int $markedCount = 0;

    private int $rebuiltCount = 0;

    /** @var list<object> 本帧挂载的 State（`callInit()` 登记，帧 ⑦ 段消费） */
    private array $mounted = [];

    /**
     * @internal 标脏一次（`State::markDirty()` 里"已脏就早退"之后调）
     * ★ **帧外必须自己叫醒帧时钟**：帧外的脏没有任何泵会顺带消费它 ——
     *   不 schedule 就是"改了状态、画面不动、不报错"。
     */
    public function markDirty(): void
    {
        ++$this->pendingDirty;
        ++$this->markedCount;

        if (!$this->inFrame) {
            $this->scheduleFrame();
        }
    }

    public function consumeDirty(): void
    {
        --$this->pendingDirty;
    }

    /** @internal 未消费的脏数 —— `settle()` 收敛循环的 O(1) 提前退出条件 */
    public function pendingDirty(): int
    {
        return $this->pendingDirty;
    }

    /** @internal 只增不减的标脏序号（`Frame` 判"本帧有没有新标脏"） */
    public function markedCount(): int
    {
        return $this->markedCount;
    }

    /** @internal 计数不漂移的保护：`settle()` 用前后差判"有没有重建" */
    public function rebuiltCount(): int
    {
        return $this->rebuiltCount;
    }

    public function noteRebuilt(): void
    {
        ++$this->rebuiltCount;
    }

    // ─────────────────────────────────────────────────────────────────
    // ②b 帧调度：是否跑下一帧的唯一真相源
    // ─────────────────────────────────────────────────────────────────

    /** 累计"要一帧"（两种档位都算） */
    private int $frameRequests = 0;

    /** 其中"要**立刻**一帧"的（子集；`urgent` 与 `requests` 的差 = 只要节奏的） */
    private int $frameUrgent = 0;

    /** **帧开始时**快照的 `$frameRequests` —— 差值就是"还欠几笔" */
    private int $frameServed = 0;

    /** 本帧消费了几笔请求（`beginFrame()` 算出；空转诊断读它） */
    private int $frameConsumed = 0;

    /** 是不是正在跑帧（帧外的 `setState()` 必须自己叫醒帧时钟，帧内的由本帧消费） */
    private bool $inFrame = false;

    /** 连续"要了帧却什么都没干"的帧数 —— 见 `noteIdleSpin()` */
    private int $idleSpinFrames = 0;

    /**
     * **要下一帧**：一次性欠账，画一帧就清（像素脏 / 焦点环没画 / 面板落后）。
     * 等待层对它回 `0`（马上去跑）—— 多等一个帧间隔就是肉眼可见的延迟。
     * 幂等：同一帧内调 100 次与 1 次对调度完全等价。
     */
    public function scheduleFrame(): void
    {
        ++$this->frameRequests;
        ++$this->frameUrgent;
    }

    /**
     * **要下一帧，但按帧节奏**：动画还在跑 / 只能轮询的后端在途。
     * 与 `scheduleFrame()` 差别只在"等多久"，不在"要不要"。
     * 帧内调它是合法的且是必需的：`State::requestFrame()` 的意义就是
     * "这一帧跑完了，下一帧还要" ⇒ 它不看 `$inFrame`。
     */
    public function scheduleNextFrame(): void
    {
        ++$this->frameRequests;
    }

    public function beginFrame(): void
    {
        $this->frameConsumed = $this->frameRequests - $this->frameServed;
        $this->frameServed = $this->frameRequests;
        $this->inFrame = true;
    }

    public function endFrame(): void
    {
        $this->inFrame = false;
    }

    public function inFrame(): bool
    {
        return $this->inFrame;
    }

    /** @internal 本帧消费了几笔请求（`>0` 却没干活 ⇒ 有人空喊） */
    public function frameConsumed(): int
    {
        return $this->frameConsumed;
    }

    /** @internal 还欠帧吗 —— `wantsFrame()` 的唯一判据 */
    public function wantsFrame(): bool
    {
        return $this->frameRequests > $this->frameServed;
    }

    /**
     * @internal 欠的是急账吗（等待层给 `0`；否则按帧节奏给帧间隔）
     * 判据：`urgent > served` 表示有一笔急账还没被任何一帧消费。
     * 帧内新增的急账也算 —— 那一帧已画过，确实该再来一帧。
     */
    public function wantsFrameImmediately(): bool
    {
        return $this->frameUrgent > $this->frameServed;
    }

    public function frameRequestCount(): int
    {
        return $this->frameRequests;
    }

    public function idleSpinFrames(): int
    {
        return $this->idleSpinFrames;
    }

    /**
     * @internal 空转记账（`Application::frame()` 每帧调一次）
     * 抓"要了帧却连 settle 与 paint 都整段跳过"：某变更点无条件调
     * `scheduleFrame()`（如 `paint()` 里标脏）⇒ 每帧都被要 ⇒ 空转 + 输入饥饿。
     */
    public function noteIdleSpin(bool $idle): void
    {
        $this->idleSpinFrames = $idle ? $this->idleSpinFrames + 1 : 0;
    }

    public function queueMounted(object $state): void
    {
        $this->mounted[] = $state;
    }

    /**
     * 取走本帧挂载的 State 并清空登记表。
     * @return list<object>
     * @internal 由 `Application::frame()` 第 ⑦ 段调（每帧一次，必须调）
     */
    public function takeMounted(): array
    {
        $list = $this->mounted;
        $this->mounted = [];

        return $list;
    }

    /** @internal 夹具用：登记表必须被消费干净（不消费会无限累积） */
    public function mountedCount(): int
    {
        return \count($this->mounted);
    }

    // ─────────────────────────────────────────────────────────────────
    // ②b 动效活跃表（"谁在动"）—— 帧时钟的发现通道
    // ─────────────────────────────────────────────────────────────────

    /**
     * 待入"活跃表"的节点（`State::markDirty()` / 挂载补 tick 登记）。
     * 避免每帧递归整棵树问"有没有人在动"：只访问登记过的在动节点。
     * 登记单元是对象而不是 `Element`（`Core` 不能依赖 `Widget`，依赖方向 lint）。
     */
    private array $activeAnimations = [];

    /**
     * 下一帧做一次全树发现（重建活跃表）。
     * 全树只在"可能有新动画起步"时走：构造（首帧）、悬停目标切换、
     * 结构变化。其余帧只走活跃表。
     */
    private bool $animationScan = false;

    /**
     * @internal 登记"这个节点可能要动"（`State::markDirty()` 调，O(1) 追加）
     * 由 `Element` 自己的标记位去重，这里不查重 —— 队列是"待合并"，
     * 真正的去重在 `Application` 收表时做。
     */
    public function noteActiveAnimation(object $node): void
    {
        $this->activeAnimations[] = $node;
    }

    /**
     * 取走待入表的节点并清空。
     * @return list<object>
     * @internal `Application::tickAnimations()` 每帧调（全扫路径也要取走，否则累积）
     */
    public function takeActiveAnimations(): array
    {
        $list = $this->activeAnimations;
        $this->activeAnimations = [];

        return $list;
    }

    public function requestAnimationScan(): void
    {
        $this->animationScan = true;
    }

    public function takeAnimationScan(): bool
    {
        $scan = $this->animationScan;
        $this->animationScan = false;

        return $scan;
    }

    /** @internal 夹具用：待入表的节点必须被消费干净 */
    public function activeAnimationCount(): int
    {
        return \count($this->activeAnimations);
    }

    // ─────────────────────────────────────────────────────────────────
    // ②c 约束反馈登记表（"布局后还要再问一句"的节点）
    // ─────────────────────────────────────────────────────────────────

    /**
     * 登记单元是 `object`（`Core` 不能依赖 `Widget`，依赖方向 lint）；真正的
     * 语义在 `Drift\Widget\ConstraintObserver`，`Frame` 侧做 `instanceof` 过滤。
     * 表通常为空（只有 `LayoutBuilder` / 按尺寸分支的容器在里）。
     *
     * @var list<object>
     */
    private array $constraintObservers = [];

    /**
     * @internal 登记（`LayoutBuilderState::initState()` 调）
     * ★ 重复登记会让同一节点被问两次、标两次脏 ⇒ 身份去重。
     */
    public function addConstraintObserver(object $observer): void
    {
        if (\in_array($observer, $this->constraintObservers, true)) {
            return;
        }

        $this->constraintObservers[] = $observer;
    }

    /**
     * @internal 注销（`dispose()` 调）。**必须调**：漏了就是"卸载的 State 还被问"，
     * 它一标脏就重建一棵已经不在树里的子树（或直接读空 element）。
     */
    public function removeConstraintObserver(object $observer): void
    {
        $at = \array_search($observer, $this->constraintObservers, true);
        if ($at === false) {
            return;
        }

        \array_splice($this->constraintObservers, $at, 1);
    }

    /**
     * @return list<object>
     * @internal `Frame` 每帧 layout 之后遍历
     */
    public function constraintObservers(): array
    {
        return $this->constraintObservers;
    }

    /** @internal 夹具用：登记表必须被注销干净（不泄漏） */
    public function constraintObserverCount(): int
    {
        return \count($this->constraintObservers);
    }

    // ─────────────────────────────────────────────────────────────────
    // ③ 两个换代序号（`Frame` 的"整段跳过"判据）
    // ─────────────────────────────────────────────────────────────────

    private int $layoutEpoch = 0;

    private int $paintEpoch = 0;

    /**
     * @internal 换过盒（`Element::syncBox()` 经 `$this->pipeline()->bumpLayoutEpoch()`）
     * ★ 盒就地更新时（如滚动偏移推给盒）本帧没 rebuild 也没换盒，
     *   不 bump 就是画面停在旧位置（且不报错）。
     * **不触发绘制**，也**不走滚动偏移**：滚动偏移只改子盒 `offset`，
     * 借这条通道会让 `Frame` 重排整棵树。
     */
    public function bumpLayoutEpoch(): void
    {
        ++$this->layoutEpoch;
    }

    public function layoutEpoch(): int
    {
        return $this->layoutEpoch;
    }

    /**
     * @internal 画面变过（`RenderObject::markPaintChanged()` 是这一笔的唯一入口）
     * 帧外自动叫醒帧时钟：这是"只改像素"的通道，新增此类通道不必再
     * 回头教调度器问新问题。帧内不 schedule：本帧 paint 门控比的就是它。
     */
    public function bumpPaintEpoch(): void
    {
        ++$this->paintEpoch;

        if (!$this->inFrame) {
            $this->scheduleFrame();
        }
    }

    public function paintEpoch(): int
    {
        return $this->paintEpoch;
    }

    // ─────────────────────────────────────────────────────────────────
    // ④ 量具（只增不减；消费者是测试 / 探针 / 真机诊断）
    // ─────────────────────────────────────────────────────────────────

    private int $layoutCount = 0;

    private int $paintCount = 0;

    private int $replayCount = 0;

    private int $pairChecks = 0;

    private int $settleRunCount = 0;

    private int $settleSkipCount = 0;

    private int $paintRunCount = 0;

    private int $paintSkipCount = 0;

    /** @internal 量具：`layout()` 累计次数（"滚动不重排"唯一可断言形式） */
    public function bumpLayout(): void
    {
        ++$this->layoutCount;
    }

    public function layoutCount(): int
    {
        return $this->layoutCount;
    }

    /** @internal 量具：层缓存**真画**次数 */
    public function bumpPaint(): void
    {
        ++$this->paintCount;
    }

    public function paintCount(): int
    {
        return $this->paintCount;
    }

    /** @internal 量具：层缓存**重放**次数（命中率 = replay / (paint + replay)） */
    public function bumpReplay(): void
    {
        ++$this->replayCount;
    }

    public function replayCount(): int
    {
        return $this->replayCount;
    }

    /** @internal 量具：`updateChildren()` 的配对尝试次数（防 O(n²) 回潮） */
    public function bumpPairCheck(): void
    {
        ++$this->pairChecks;
    }

    public function pairChecks(): int
    {
        return $this->pairChecks;
    }

    /** @internal 量具：settle 段**真跑**的帧数 */
    public function bumpSettleRun(): void
    {
        ++$this->settleRunCount;
    }

    public function settleRunCount(): int
    {
        return $this->settleRunCount;
    }

    /** @internal 量具：settle 段**整段跳过**的帧数 */
    public function bumpSettleSkip(): void
    {
        ++$this->settleSkipCount;
    }

    public function settleSkipCount(): int
    {
        return $this->settleSkipCount;
    }

    /** @internal 量具：paint 段**真跑**的帧数 */
    public function bumpPaintRun(): void
    {
        ++$this->paintRunCount;
    }

    public function paintRunCount(): int
    {
        return $this->paintRunCount;
    }

    /** @internal 量具：paint 段**整段跳过**的帧数 */
    public function bumpPaintSkip(): void
    {
        ++$this->paintSkipCount;
    }

    public function paintSkipCount(): int
    {
        return $this->paintSkipCount;
    }

    // ─────────────────────────────────────────────────────────────────
    // ⑤ 约束收敛量具（"帧内跑了几轮"与"谁在漂"）
    // ─────────────────────────────────────────────────────────────────

    private int $constraintRounds = 0;

    private string $lastConstraintDrift = '';

    /**
     * @internal 本帧 `settle → layout` 跑了几轮（稳态 1，约束真变 2）。
     * ★ 自动机制必须可 inspect：只收敛不记账 ⇒ 没人看得出"这帧排了两遍"。
     */
    public function noteConstraintRounds(int $rounds): void
    {
        $this->constraintRounds = $rounds;
    }

    public function constraintRounds(): int
    {
        return $this->constraintRounds;
    }

    /**
     * @internal 最近一次约束反馈的漂移描述（`谁: 旧 → 新`；没漂 ⇒ 空串）。
     * ★ 诊断必须带"谁在漂"：只说"没收敛"，等于把定位工作全丢给读代码的人。
     */
    public function noteConstraintDrift(string $drift): void
    {
        $this->lastConstraintDrift = $drift;
    }

    public function lastConstraintDrift(): string
    {
        return $this->lastConstraintDrift;
    }
}
