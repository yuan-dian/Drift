<?php
declare(strict_types=1);

namespace Drift\Core;

/**
 * 一帧管线的**可变状态**：结构/内容版本号、脏记账、两个换代序号、以及对外的量具。
 *
 * ★★ 存在的理由（2026-11-02，技术债 D3）：这些数字原先散在 `State` / `Element` /
 *   `RenderBox` / `RenderObject` / `Frame` 五处、一律 `private static`。在"单应用 +
 *   单窗口 + 串行测试"的前提下它是**对的**（一条自增，零间接）。前提变了它变成硬
 *   阻塞：两个 `Application` 会互相加计数、互相清 `pendingDirty`，`docs/MULTI-WINDOW`
 *   的 M1 一步都走不动。搬到这里之后，**一个应用一份**，互不干扰。
 *
 * ★★ 为什么是**一个**对象而不是"量具"与"管线状态"两个（技术债报告 §期一 原计划
 *   `AppMetrics` + `Pipeline` 两个类）：这两类数字的**写点完全重叠** ——
 *   `layoutEpoch` 与 `layoutCount` 同在 `RenderBox::layout()` 里一行之隔。拆成两个
 *   对象只意味着热路径上多一次反查、多一条"这个字段该进哪个"的判断，换不到任何
 *   隔离（它们本来就要一起被一个应用独占）。Flutter 的 `PipelineOwner` 也是
 *   "脏列表 + 调试计数"同住。
 *
 * ★★ 为什么读写要经过 `current()`（而不是把对象一路传下去）：
 *   `RenderObject` / `State` 在树里，**没有、也不该有**指向 `Application` 的引用
 *   （§4.3 的分层纪律，`grep` 今天仍是 0 命中）。要让 `layout()` 里的一个自增
 *   找到"本帧属于哪个应用"，只有三条路：
 *
 *   | 做法 | 代价 |
 *   |---|---|
 *   | ① 逐节点带一个 `Pipeline` 引用 | 每盒多 8 字节 + `mount()` 签名变（碰 471 条 smoke） |
 *   | ② 沿 `parent` 走到根再取 | 热路径 O(深度)，且**树外构造的盒**（单测）没有根 |
 *   | ③ 一个"当前管线"指针，由帧入口切换 | 一个静态字段；不在帧里的代码落到默认管线 |
 *
 *   选 ③。它是 Dart 的 `Zone.current`、Chromium 的 trace 当前 tracer、
 *   Flutter 的 `WidgetsBinding.instance` 同一个形状：**单 UI 线程**下"当前是谁"
 *   是一个合法的问题，代价是一次静态读。② 的那个"树外的盒"缺陷是决定性的 ——
 *   `tests/Feature/Rendering/LayerCacheTest` 直接 `new SolidBox()` 然后读计数，
 *   它不在任何树里。
 *
 * ★ 诚实记账（这条要留给下一个人看）：③ 仍然是**一个**进程级字段。它是指针不是
 *   状态 —— 两个应用各跑各的帧时互不干扰（各自帧入口切自己的管线），但
 *   **帧外**的代码（构造期、`dispose()`、纯单测）写的是"最近一次帧的管线"或默认
 *   管线。这类写入都是**测试与自检**，不是业务状态；真业务状态在 `Store` 里。
 *   要彻底没有它，得让每个节点带引用（①），那是 M2 之后与"每窗一引擎"一起做的事。
 *
 * ★ 仍然**故意留在静态**的（不搬进来，各有理由，别再"顺手"搬）：
 *   · `State::$buildingState` —— build 期重入守卫。build 是**同步**的，两个应用
 *     不可能交错进入 ⇒ 它是"调用栈标记"而不是"状态"，共享无副作用。
 *   · `Errors::$ring` / `Log::$*` —— 真进程单例（日志与崩溃环跨应用共用一份才对）。
 *   · `LayoutTolerance::$noted` —— "同一句提示只打一次"，进程级去重正是本意。
 *
 * @internal 只给框架内部与测试 / devtools 用；应用代码不该读它（`@api` 没有它）。
 */
final class Pipeline
{
    /**
     * 最近一次**进入帧**的管线（帧入口切换它，见类注释的 ③）。
     *
     * ★ 惰性建默认实例：`current()` 可能在任何帧之前被调到（纯单测直接
     *   `layout()`），那时没有"本应用的管线"这回事，落到一份进程默认的计数上 ——
     *   与搬迁之前的全局静态**行为完全一致**，所以老用例不必改。
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
     *
     * ★ **不恢复**上一个：帧是唯一写者，帧跑完之后测试/探针要读的正是**刚跑完
     *   那一帧**的计数。恢复会把这些读全部打回默认管线（读回 0），而"多应用交错"
     *   只可能发生在测试里，那时应当用 `$app->pipeline()` 显式读，不靠"当前"。
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
     * @internal 节点**增删或重排**了（`Element` 的三处 `$this->pipeline()->bumpStructure()`）
     *
     * ★ **只在真的变了时 +1**：`updateChildren()` 每次 rebuild 都会被调，
     *   无条件 +1 会让版本号每帧都变 ⇒ "结构没变"永远判不出来（等于没做）。
     * ★ 唯一消费者是语义推送的档位选择：结构变了 ⇒ 必须推 `full`（增量表达
     *   不了"多了一个节点"）。没有它就只能靠"有 State 脏"猜 —— 而脏不等于
     *   结构变（改个 label 是脏，不是结构变）。
     */
    public function bumpStructure(): void
    {
        ++$this->structureVersion;
        ++$this->contentVersion;
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
     *
     * ★★ **帧外必须自己叫醒帧时钟**（2026-11-02，D1）：帧内的脏由本帧 settle 消费，
     *   帧外（输入回调 / 定时器 / 异步完成之后）的脏**没有任何泵会顺带消费它** ——
     *   不 schedule 就是"改了状态、画面不动、不报错"。这正是 `EventLoop` 里
     *   `dispatchInput()` 之后 `$app->wantsFrame()` 必须为真的原因。
     */
    public function markDirty(): void
    {
        ++$this->pendingDirty;
        ++$this->markedCount;

        if (!$this->inFrame) {
            $this->scheduleFrame();
        }
    }

    /** @internal 脏标记被帧消费（`State::consumeDirty()` 调） */
    public function consumeDirty(): void
    {
        --$this->pendingDirty;
    }

    /** @internal 未消费的脏数 —— `settle()` 收敛循环的 O(1) 提前退出条件 */
    public function pendingDirty(): int
    {
        return $this->pendingDirty;
    }

    /** @internal **只增不减**的标脏序号（`Frame` 判"本帧有没有新标脏"） */
    public function markedCount(): int
    {
        return $this->markedCount;
    }

    /** @internal 计数不漂移的保护：`settle()` 用前后差判"有没有重建" */
    public function rebuiltCount(): int
    {
        return $this->rebuiltCount;
    }

    /** @internal 重建过一次（`State::noteRebuilt()` 调） */
    public function noteRebuilt(): void
    {
        ++$this->rebuiltCount;
    }

    // ─────────────────────────────────────────────────────────────────
    // ②b 帧调度：**"下一帧要不要跑"的唯一真相源**
    //
    //   ★★ 2026-11-02（技术债 D1）。此前这件事散在 `Application::wantsFrame()` /
    //     `wantsFrameRhythm()` / `needsFrameNow()` 三处、共 6 个异质判据 —— 每次
    //     多出一种"只有系统自己知道的变更源"，都要回去教调度器问一个新问题。
    //     `Frame::hasPendingPaint()` 就是 2026-11-01 才补的（补之前悬停零反馈、
    //     滚动条淡出从没跑过）。现在只有一个计数器：**谁想要下一帧，就在自己的
    //     变更点 +1**，调度器不需要认识那个变更源。
    //
    //   ★ 为什么是三个数字而不是一个 bool（Flutter 的 `_hasScheduledFrame`）：
    //     必须区分"**立刻**跑"（一次性欠账：像素脏 / 面板落后）与"**按节奏**跑"
    //     （动画 / 只能轮询的后端）—— 两者在等待层的回答分别是 `0` 与 `15ms`。
    //     混成一个 bool，动画期间就退化成 300fps 空转（见 `FrameClock::nextWaitMs()`
    //     对 §3.5 初稿的推翻）。
    //   ★ 为什么是单调计数 + 帧开始快照，而不是"置位 / 清位"：帧**内**新增的请求
    //     要留给下一帧，快照天然做到；顺带还能观测"这一帧消费了几笔"（空转诊断）。
    //     Flutter 的 `_hasScheduledFrame` 也在 `handleBeginFrame()` 里清，同一个理由。
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
     *
     * ★ 等待层对它的回答是 `0`（马上去跑）—— 多等一个帧间隔就是肉眼可见的延迟。
     * ★ 幂等成本是一次自增；同一帧内调 100 次与 1 次对调度**完全等价**。
     */
    public function scheduleFrame(): void
    {
        ++$this->frameRequests;
        ++$this->frameUrgent;
    }

    /**
     * **要下一帧，但按帧节奏**（15ms）：动画还在跑 / 只能轮询的后端在途。
     *
     * ★ 与 `scheduleFrame()` 的差别只在"等多久"，不在"要不要"。
     * ★★ **帧内调它是合法的，且是必需的**：`State::requestFrame()` 的全部意义就是
     *   "这一帧跑完了，下一帧还要"（settle 期间起飞的动画）。所以它**不看**
     *   `$inFrame` —— 只有 `markDirty()` / `bumpPaintEpoch()` 这些"本帧就消费掉"
     *   的写点才看。
     */
    public function scheduleNextFrame(): void
    {
        ++$this->frameRequests;
    }

    /** @internal 帧开始：清账（帧内新增的请求自动留给下一帧）+ 落"帧内"标志 */
    public function beginFrame(): void
    {
        $this->frameConsumed = $this->frameRequests - $this->frameServed;
        $this->frameServed = $this->frameRequests;
        $this->inFrame = true;
    }

    /** @internal 帧末：落"帧内"标志 —— 之后发生的变更算帧外，必须自己 schedule */
    public function endFrame(): void
    {
        $this->inFrame = false;
    }

    /** @internal 是不是正在跑帧 */
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
     * @internal 欠的是**急账**吗（等待层给 `0`；否则按帧节奏给 15ms）
     *
     * ★ 判据写全了：`urgent > served` 表示"有一笔急账还没被任何一帧消费"。
     *   帧内新增的急账也算 —— 那一帧已经画过了，所以确实该再来一帧。
     */
    public function wantsFrameImmediately(): bool
    {
        return $this->frameUrgent > $this->frameServed;
    }

    /** @internal 量具：累计请求数（含已消费的） */
    public function frameRequestCount(): int
    {
        return $this->frameRequests;
    }

    /** @internal 空转诊断：连续"要了帧却什么也没干"的帧数 */
    public function idleSpinFrames(): int
    {
        return $this->idleSpinFrames;
    }

    /**
     * @internal 空转记账（`Application::frame()` 每帧调一次）
     *
     * ★ 为什么要有它：推模型把"漏调 `scheduleFrame()`"从静默缺陷变成了功能不刷新
     *   （好定位），但换来了一个**新**的失败形态 —— 某个变更点**无条件**调
     *   `scheduleFrame()`（比如在 `paint()` 里标脏）⇒ 每帧都被要 ⇒ 空转 + 输入饥饿。
     *   这条诊断抓的正是它：要了帧，却连 settle 与 paint 都整段跳过。
     */
    public function noteIdleSpin(bool $idle): void
    {
        $this->idleSpinFrames = $idle ? $this->idleSpinFrames + 1 : 0;
    }

    /** @internal "这一帧刚挂上的 State"登记（`State::callInit()` 调） */
    public function queueMounted(object $state): void
    {
        $this->mounted[] = $state;
    }

    /**
     * 取走本帧挂载的 State 并清空登记表。
     *
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
    // ③ 两个换代序号（`Frame` 的"整段跳过"判据）
    // ─────────────────────────────────────────────────────────────────

    private int $layoutEpoch = 0;

    private int $paintEpoch = 0;

    /**
     * @internal 换过盒（`Element::syncBox()` 经 `$this->pipeline()->bumpLayoutEpoch()`）
     *
     * ★ 存在的理由（2026-10-27，A 批第 1 项）：`Frame` 跳过 layout 的判据是
     *   "没重建 + 约束没变 + 盒没换代"。前两条覆盖不了**盒就地更新** ——
     *   滚动偏移改成推给盒之后，本帧既没有 `rebuild()`、也没有换盒，
     *   而盒里那两个数变了 ⇒ 不重排就是**画面停在旧位置**（且不报错）。
     * ★ **它不触发绘制**：重排过 ⇒ `Frame` 本帧的 `layout` 段跑过 ⇒ 必然要画
     *   （`Frame::run` 的 paint 判据含"本帧排过"）。**不要**顺手 bump paint：
     *   那会让"换盒"同时推进两个序号，将来要按序号定位"到底什么变了"时分不清。
     * ★ **滚动偏移不走这里**（2026-10-27 第二版）：偏移只改子盒的 `offset`，
     *   不改任何盒的 `size` ⇒ 由 `applyScrollOffsets()` 就地应用即可；借这条通道
     *   去"让偏移生效"会让 `Frame` 重排整棵树（实测滚动帧 `layout` 3.65ms）。
     */
    public function bumpLayoutEpoch(): void
    {
        ++$this->layoutEpoch;
    }

    /** @internal "渲染树有东西变了、必须重排"的绝对序号 */
    public function layoutEpoch(): int
    {
        return $this->layoutEpoch;
    }

    /**
     * @internal 画面变过（`RenderObject::markPaintChanged()` 是这一笔的唯一入口）
     *
     * ★★ **帧外自动叫醒帧时钟**（2026-11-02，D1）：这条以前是
     *   `Application::needsFrameNow()` 的第八条判据（2026-11-01 补的第八条 ——
     *   补之前悬停零反馈、滚动条淡出从没跑过）。现在变成写点的副作用，
     *   新增"只改像素"的通道再也不必回去教调度器问一个新问题。
     * ★ 帧内不 schedule：那一帧的 paint 门控比的就是这个序号（`paintEpoch` 变了
     *   就一定重画），再要一帧就是纯粹的空喊。
     */
    public function bumpPaintEpoch(): void
    {
        ++$this->paintEpoch;

        if (!$this->inFrame) {
            $this->scheduleFrame();
        }
    }

    /** @internal "画面变了、下一帧必须真的画"的绝对序号 */
    public function paintEpoch(): int
    {
        return $this->paintEpoch;
    }

    // ─────────────────────────────────────────────────────────────────
    // ④ 量具（只增不减；消费者是测试 / 探针 / 真机诊断）
    //
    //   ★ 这些数字**不是**业务状态：删掉它们处处都不变，只是"优化有没有生效"
    //     变成不可断言（滚动帧"重排了"与"没重排"画面上完全一样 ⇒ 只能计数证伪）。
    //   ★ 成本是一次自增（AOT 下就是一条 add），留在热路径上是划算的。
    //
    //   各计数的**独有理由**（都不重复，别合并）：
    //   · `layoutCount`   —— "滚动不重排"的唯一可断言形式（行为上看不出来）
    //   · `paintCount` / `replayCount` —— 层缓存命中率 = replay / (paint + replay)。
    //     缓存命中与否在**画面上完全相同**（判据就是"等价替换"），同一个困境。
    //   · `pairChecks`    —— **防 `O(n²)` 回潮**。复用行为对不对，smoke 用"元素实例
    //     还是不是同一个"断言；但"是不是又退回平方扫描"没有任何行为差异，只在树大
    //     了以后表现为慢 ⇒ 一次重构把 `O(n)` 改回 `O(n²)` 会**全绿通过**。
    //   · `settle*` / `paint*` 四件 —— 帧的"整段跳过"有没有真的生效（D1 的空转
    //     诊断也读它们：要了帧却连 settle 与 paint 都跳过 ⇒ 有人无条件空喊）。
    // ─────────────────────────────────────────────────────────────────

    private int $layoutCount = 0;

    private int $paintCount = 0;

    private int $replayCount = 0;

    private int $pairChecks = 0;

    private int $settleRunCount = 0;

    private int $settleSkipCount = 0;

    private int $paintRunCount = 0;

    private int $paintSkipCount = 0;

    /** @internal 量具：`layout()` 累计次数（"滚动不重排"的唯一可断言形式） */
    public function bumpLayout(): void
    {
        ++$this->layoutCount;
    }

    /** @internal 量具：`layout()` 累计次数 */
    public function layoutCount(): int
    {
        return $this->layoutCount;
    }

    /** @internal 量具：层缓存**真画**次数 */
    public function bumpPaint(): void
    {
        ++$this->paintCount;
    }

    /** @internal 量具：层缓存**真画**次数 */
    public function paintCount(): int
    {
        return $this->paintCount;
    }

    /** @internal 量具：层缓存**重放**次数（命中率 = replay / (paint + replay)） */
    public function bumpReplay(): void
    {
        ++$this->replayCount;
    }

    /** @internal 量具：层缓存**重放**次数 */
    public function replayCount(): int
    {
        return $this->replayCount;
    }

    /** @internal 量具：`updateChildren()` 的配对尝试次数（防 O(n²) 回潮） */
    public function bumpPairCheck(): void
    {
        ++$this->pairChecks;
    }

    /** @internal 量具：配对尝试累计次数 */
    public function pairChecks(): int
    {
        return $this->pairChecks;
    }

    /** @internal 量具：settle 段**真跑**的帧数 */
    public function bumpSettleRun(): void
    {
        ++$this->settleRunCount;
    }

    /** @internal 量具：settle 段**真跑**的帧数 */
    public function settleRunCount(): int
    {
        return $this->settleRunCount;
    }

    /** @internal 量具：settle 段**整段跳过**的帧数 */
    public function bumpSettleSkip(): void
    {
        ++$this->settleSkipCount;
    }

    /** @internal 量具：settle 段**整段跳过**的帧数 */
    public function settleSkipCount(): int
    {
        return $this->settleSkipCount;
    }

    /** @internal 量具：paint 段**真跑**的帧数 */
    public function bumpPaintRun(): void
    {
        ++$this->paintRunCount;
    }

    /** @internal 量具：paint 段**真跑**的帧数 */
    public function paintRunCount(): int
    {
        return $this->paintRunCount;
    }

    /** @internal 量具：paint 段**整段跳过**的帧数 */
    public function bumpPaintSkip(): void
    {
        ++$this->paintSkipCount;
    }

    /** @internal 量具：paint 段**整段跳过**的帧数 */
    public function paintSkipCount(): int
    {
        return $this->paintSkipCount;
    }
}
