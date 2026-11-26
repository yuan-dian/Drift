<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

use Drift\Core\PaintObserver;
use Drift\Input\InputEvent;
use Drift\Rendering\SurfaceProfile;
use Drift\Widget\Element;
use Drift\Widget\Flex;
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
final class Recorder implements PaintObserver
{
    /** 保留的最大帧数；超出丢最旧的一帧 */
    public const int DEFAULT_CAPACITY = 600;

    /** 单帧最多记多少条因果边（防御：装了录制却不跑帧） */
    private const int MAX_CAUSES = 512;

    /** 几何事实保留的帧数；比帧窗口短是刻意的，查询必须自报边界。 */
    public const int GEOMETRY_FRAMES = 60;

    /** 网络请求保留的条数：不受帧数约束（600 帧里可能上万次请求），独立窗口。 */
    public const int NETWORK_CAPACITY = 500;

    /** 异步任务保留的条数：独立于帧窗口，和任务数而非帧数成正比。 */
    public const int ASYNC_CAPACITY = 200;

    /**
     * 报文存档条数：比骨架短得多（每条最大 64KB，500 条就是 32MB）。
     * 报文是**按需查的附件**，不是默认证据面；这个窗口只保证"最近几百次"可回看。
     */
    public const int BODY_CAPACITY = 200;

    /** 绘制事实保留的帧数：与几何同量级，逐帧节点集合比几何更大，不能跟 600 帧。 */
    public const int PAINT_FRAMES = 60;

    /** 单帧最多记多少个重画节点；超出标 truncated，paintedTotal 仍给真实总数。 */
    public const int MAX_PAINTED_NODES = 4096;

    /** 单帧最多记多少个层缓存未命中；边界的数量远小于节点数，上限只防异常树。 */
    public const int MAX_PAINT_MISSES = 512;

    /** 节点超过这么多帧没再出现就可以回收（仍在窗口里的引用会保住它）。 */
    /**
     * 节点寿命记录（出生帧 / 离开帧 / 回收表）至少保留多少帧。
     *
     * 从 private 提到 public 只为让审查面的 `treeDiff()` 自报"这一对比区间是否
     * 还在保留期内"——常量不产生机器码，写面的方法面不变（A69）。
     */
    public const int NODE_TTL_FRAMES = 120;

    /** 每 N 帧扫一次节点表：摊薄扫描成本，上界仍由 TTL + 活动窗口决定。 */
    private const int NODE_RECYCLE_EVERY = 60;

    private static ?self $current = null;

    /**
     * 帧内栈：{@see self::enter()} 压、{@see self::leave()} 弹；空 = 不在任何帧里。
     *
     * `null` 也是合法的一层，表示"这一帧的应用没有录制器"。**必须能压 null**：
     * 不压的话，第二个窗口的帧会从栈里拿不到东西，落到 {@see self::$current} 上，
     * 也就是**上一个窗口的录制器** —— 那正是这一层要堵的静默污染。
     *
     * @var list<?self>
     */
    private static array $frameStack = [];

    /**
     * 还活着的**有主**录制器（{@see self::bindTo()} 之后进、{@see self::unbind()} 之后出）。
     *
     * 存在的理由只有一个：某个窗口关掉时，{@see self::$current} 要落到另一个还活着的
     * 会话上，而不是变成 `null`（那时"当前录制器"会指向一个已经关掉的会话，而查询层
     * 各自握着实例，谁也发现不了）。量具同理 —— 最后一个会话关了才关掉。
     *
     * ★ 键是**对象号不是 `tag`**：`reset()` 会换 `tag`（旧号在新会话里指别的节点），
     *   而"这份录制器还活着"跟换没换号无关。按 `tag` 记，`reset()` 之后解绑就删不掉
     *   自己那一行 —— 表现为"关掉会话后 `installed()` 仍是 true"，而 `$current` 指的
     *   是一份已经关掉的记录。
     *
     * @var array<int, self> 对象号 => 实例
     */
    private static array $live = [];

    /**
     * 量具是不是我们打开的。
     *
     * 只有自己打开的量具才能帧首清零、帧末读账：调用方本来开着（bench 的 M4）
     * 时那是他的跨帧累计，清一个字都是替他毁数据 —— 那种情况宁可不记段耗时。
     */
    private static bool $ownsProfile = false;

    /**
     * 这份录制器的**主人**（`spl_object_id` 的 {@see \Drift\App\Application}）；`null` = 无主。
     *
     * ## 为什么录制器要认主
     *
     * "一个进程一份录制器"在单窗口下是对的，在多窗口下是**静默串证据**：第二个窗口
     * 一挂上，它首帧的全量 `build` 就把自己 88 个节点写进第一个窗口的节点表，帧号也
     * 一并推进 —— 而两个消费者各自的报告看起来都正常。实测（两个 `PageHostApplication`
     * 交错推帧）：第一个会话的节点表 361 → 449、帧窗口多出两帧、`digest` 跟着变。
     *
     * 所以录制器属于**应用**，不属于进程：谁在跑帧，帧内 `current()` 就是谁的
     * （见 {@see self::enter()}）。无主的录制器是留给测试 / bench 的显式逃生口 ——
     * 装了却没绑定应用，说明装的人自己承担"谁写进来"的责任。
     */
    private ?int $ownerId = null;

    /** @var array<int, FrameRecord> 帧号 => 记录，键升序 */
    private array $frames = [];

    /**
     * 节点 id => 父 / 类名 / Flex / 最后见到的帧 / 出生帧。
     *
     * `s` 是"最后一次被重建或采样到的那帧"，`f` 是"第一次登记的那帧"。两个合起来
     * 才是一条**生命周期**：回放第 N 帧时，"当时它在不在"只能由它们回答（见
     * {@see Query::tree()}）。只看 `s` 会把静止不动的节点当成刚出生。
     *
     * @var array<int, array{p: int, c: string, x: bool, s: int, f?: int}>
     */
    private array $nodes = [];

    /**
     * 已回收节点 id => `['p' => 父, 'c' => 类名, 'x' => Flex, 'f' => 出生帧,
     * 's' => 最后见到帧, 'g' => 离开树的那帧]`。
     *
     * 旧包里这个值是单个 int（最后见到帧），{@see EvidenceBag::evictedRow()} 在入口归一。
     * 回收之后**节点仍在历史里活着** ——"这个按钮第 40 帧还在、第 60 帧没了"正是
     * 回放最想看的，只留 `s` 就答不了；父与类型也要留，否则能查出它存在过、却画不出
     * 它在哪儿。id 永不复用。
     *
     * @var array<int, array{p: int, c: string, x: bool, f: int, s: int, g: int}|int>
     */
    private array $evicted = [];

    private int $lastRecycleFrame = 0;

    /**
     * 当前高亮的节点号（`0` = 没有）。
     *
     * ★ 它**不是**帧记录的一部分：高亮不改结构，进 `FrameRecord` 就等于把它算进
     *   因果指纹，而"高亮前后因果逐值相同"正是要证明的东西。
     */
    private int $highlightNode = 0;

    /** 这个高亮是在第几帧被请求的；`0` = 本次录制还没请求过。 */
    private int $highlightFrame = 0;

    /**
     * 最近一次**真的画上屏**的叠加：`['frame' => 帧号, 'node' => 节点号]`；没画过为 `null`。
     *
     * ★ 与 {@see self::$highlightNode} 分开记，是为了区分两件事：
     *   "请求了高亮"与"高亮真的到了屏幕上"。中间隔着绘制（元素已卸载、盒没尺寸、
     *   帧被跳过都会让它只停在第一件上）—— 而那种失败是静默的。
     *
     * @var array{frame: int, node: int}|null
     */
    private ?array $overlayDrawn = null;

    /**
     * 几何窗口比帧窗口短得多：600 帧 × 2000 节点全存撑不住，而"这一帧为什么这个尺寸"
     * 几乎总是问最近几十帧。只留最近 GEOMETRY_FRAMES 帧有变化的节点，超出自报。
     *
     * @var array<int, array<int, GeometryRecord>> frame => [node => record]
     */
    private array $geometry = [];

    /** @var array<int, string> node => 上一次记下的几何签名（帧被裁掉也不删：它代表"已经记过"） */
    private array $geometryDigest = [];

    /**
     * node => 最后一次采到的几何。窗口滚动会裁掉旧帧，但稳定祖先（外层容器）只在
     * 首帧采过一次、签名此后不变 —— 没有这份基线，后代的父链查不到祖先，绝对坐标
     * 就丢掉整段祖先偏移，线框整体平移、与真机底图错位。
     *
     * 只作为窗口内找不到时的兜底；记录帧晚于目标帧（查历史帧）或节点已离树时不用。
     *
     * @var array<int, GeometryRecord>
     */
    private array $geometryBaseline = [];

    /**
     * 最后一次真实采样时还在树里的节点。
     *
     * ★ 几何按"每节点最近一次记录"累积，节点从树上消失后旧记录不会自己走 ——
     *   切到另一页/另一个窗口时，两棵树的盒会一起留在投影层。静止帧不重采样，
     *   这份集合正好继承上一帧，不会因为"这一轮没看见"而把画面上的盒清空。
     *
     * @var array<int, true> node => true
     */
    private array $sampledNodes = [];

    /** @var array<int, int> node => 离开树的那一帧；查询帧不早于它就不再输出旧几何 */
    private array $goneAt = [];

    /**
     * 是否已经采过至少一帧几何。
     *
     * settle 与 paint 都整段跳过的帧才算"全静止"：既不重建也不重画，几何没有理由变。
     * 只用 layoutEpoch 会漏掉"就地推盒 + 重画"这一类（滚动偏移），所以这里认的是
     * 两道整段跳过的信号，不是盒换代序号。
     */
    private bool $geometryPrimed = false;

    /** @var array<int, PaintRecord> 帧号 => 绘制事实；独立 60 帧窗口 */
    private array $paint = [];

    /** @var list<int> 本帧真画的盒（spl_object_id，帧末映射成节点号） */
    private array $openPaint = [];

    /** @var array<int, string> 本帧层缓存未命中的盒 => 原因 */
    private array $openPaintMisses = [];

    /** 本帧真画次数（截断前；一个盒可能因多次绘制被重复计入） */
    private int $openPaintTotal = 0;

    /** 本帧层缓存命中次数（命中即整棵子树不画） */
    private int $openPaintHits = 0;

    /** 本帧绘制记录被上限截断（节点集合或未命中表） */
    private bool $paintTruncated = false;

    /**
     * 没有自己 RenderBox 的节点（复合控件，如 PageShell）。
     *
     * 查不到它们的几何是**永久事实**，不是"窗口外，再等等就有了"。混为一谈会让
     * 读者一直等一个永远不会出现的记录——和"裁剪伪装成结论"是同一类错误。
     *
     * @var array<int, true>
     */
    private array $boxless = [];

    /** @var list<Edge> 待归入下一帧的因果边（帧外 `setState` 也算，帧末统一落账） */
    private array $openCauses = [];

    /** @var list<int> 待归入下一帧的重建节点，先序 */
    private array $openRebuilt = [];

    /** @var array<int, true> 本帧已记过 state 边的节点（同帧去重） */
    private array $openWrote = [];

    /** @var array<int, true> 本帧已重建过的节点（同帧去重） */
    private array $openSaw = [];

    /**
     * 本帧有因果边被 `MAX_CAUSES` 拒收了。
     *
     * 不标出来的后果：这一帧的树只画了一半，而报告看不出任何异常 ——
     * "没触发规则"于是变成"没问题"。拒收必须留痕，落到 `FrameRecord::$incomplete`。
     */
    private bool $causesTruncated = false;

    /** @var array<int, NetworkRecord> 请求 id => 记录，按 id 升序（独立 500 条窗口） */
    private array $network = [];

    /**
     * 请求 id => 报文存档（独立 200 条窗口，默认查询不带出）。
     *
     * 不进 {@see Digest}：指纹比的是因果与段结构，报文里一个服务端时间戳就让
     * 整份证据"不同"，指纹就失去意义。也不进 default 查询面，避免隐私进简报。
     *
     * @var array<int, NetworkBodyRecord>
     */
    private array $bodies = [];

    /** 当前正在结算的请求 id，供 `noteStateWrite` 把落地节点写进它的记录。 */
    private int $activeRequest = 0;

    private int $networkSeq = 0;

    /** @var array<int, AsyncRecord> 任务 id => 状态跃迁（独立 200 条窗口） */
    private array $async = [];

    /** 当前正在结算的任务 id，供 `noteStateWrite` 把落地节点写进它的记录。 */
    private int $activeJob = 0;

    private int $asyncSeq = 0;

    /**
     * 本会话发出的节点号序列（每个会话从 1 开始）。
     *
     * ★ 必须是**每会话**计数，不能是全局计数：`Digest` 拿"两次重放的重建集合
     *   逐值相同"当判据，前提就是同一批控件拿到同一批号。全局计数会让第二次
     *   重放的号从上次的末尾接着走，指纹永远对不上 —— 那不是"引擎不确定"，
     *   是编号方式错了。
     */
    private int $seq = 0;

    /**
     * 本轮的**发号批次**；与 {@see Element::$debugSession} 比对，判断元素身上的号是不是这一批发的。
     *
     * 不是 `readonly`：{@see self::reset()} 会开新批次。判"号是不是这一批发的"而不是
     * "元素有没有号"，是因为 reset 之后元素还带着上一批的号、而登记表已经空了——
     * 只看 `debugId === 0` 会把旧号当新号用。
     */
    private int $tag;

    /** 会话代号发号器：只用来区分"谁发的号"，不参与证据。 */
    private static int $sessionSeq = 0;

    private int $frameId = 0;

    /**
     * 代际号：每次 {@see self::reset()} 自增。
     *
     * `reset()` 会把帧号归零，于是下一帧又从 1 开始；任何"按帧号缓存"的派生物
     * （`CausalQuery::$shapeCache`）都会命中上一轮同号帧。缓存以代际为键，
     * 与 A41 的 `debugSession` 戳同源 —— 那条裁决处理的正是"号码重用"。
     */
    private int $generation = 0;

    private bool $open = false;

    private float $openT = 0.0;

    public function __construct(
        private readonly RecordOptions $options = new RecordOptions(),
    ) {
        $this->tag = ++self::$sessionSeq;
    }

    // ---------------------------------------------------------------- 生命周期

    /**
     * 安装录制器。旧的三参数位置 / 命名调用仍可用，但统一归一成 {@see RecordOptions}。
     */
    public static function install(
        RecordOptions|int|null $options = null,
        ?Script $script = null,
        bool $redactBodies = true,
    ): self {
        $options = RecordOptions::fromLegacy($options, $script, $redactBodies);
        // 段耗时不用第二套计时：`SurfaceProfile` 已经是全仓唯一的"计一段"入口
        // （九段里的 settle/layout/paint 在 `Frame::run()` 里记，壳的 stage() 拿不到）。
        // 装录制器就把量具打开，帧首清零、帧末读账 —— 两条驱动路径拿到同一份数据。
        if (self::$current === null && $options->profileStages()) {
            self::$ownsProfile = !SurfaceProfile::$on;
            if (self::$ownsProfile) {
                SurfaceProfile::$on = true;
            }
        }

        return self::$current = new self($options);
    }

    public static function uninstall(): void
    {
        if (self::$current !== null && self::$ownsProfile) {
            SurfaceProfile::$on = false;
            SurfaceProfile::reset();
        }
        self::$ownsProfile = false;
        // 硬重置：测试夹具在 `finally` 里用它把"这个世界"清干净，所以三样一起清。
        // 会话自己关的话走 {@see self::unbind()} —— 那里要照顾"还有别的窗口活着"。
        self::$current = null;
        self::$live = [];
        self::$frameStack = [];
    }

    /**
     * 把这份录制器**绑到一个应用**上（一个窗口一份）。
     *
     * 绑定之后：① 这个应用的帧内 `current()` 一定指向它（见 {@see self::enter()}）；
     * ② 别的应用没有自己的录制器时**借不到**它 —— 借到了就是串证据。
     */
    public function bindTo(object $owner): void
    {
        $this->ownerId = \spl_object_id($owner);
        self::$live[\spl_object_id($this)] = $this;
        self::$current = $this;
    }

    /** 应用与会话解绑：`current()` 落到另一个还活着的会话上，量具只留到最后一个。 */
    public function unbind(): void
    {
        unset(self::$live[\spl_object_id($this)]);
        $this->ownerId = null;

        if (self::$current === $this) {
            $remaining = \end(self::$live);
            self::$current = $remaining === false ? null : $remaining;
        }

        if (self::$live === [] && self::$ownsProfile) {
            SurfaceProfile::$on = false;
            SurfaceProfile::reset();
            self::$ownsProfile = false;
        }
    }

    /** 这份录制器的主人（应用的对象号）；`null` = 无主（测试 / bench 手工装的）。 */
    public function ownerId(): ?int
    {
        return $this->ownerId;
    }

    /**
     * "无主的那一份" —— 没有绑定应用的应用能借用的录制器。
     *
     * 有主的一律不借：那是另一个窗口的证据。这条判断是整个多窗口隔离的**分界点**，
     * 去掉它 {@see self::$current} 就会重新变成"进程里最后装的那份"。
     */
    public static function unowned(): ?self
    {
        return self::$current !== null && self::$current->ownerId === null ? self::$current : null;
    }

    /**
     * 进入一帧：帧内的 {@see self::current()} 变成 `$recorder`（`null` 也算一层）。
     *
     * 由 {@see \Drift\App\Application::frame()} 成对调用，`finally` 里 {@see self::leave()}。
     * 用栈而不是"存一个旧值再写回"：后者在重入（帧里再跑一帧，例如离屏预热）时会把
     * 中间那一层冲掉，而冲掉的表现是"某几帧写进了别人的记录"，事后无从归因。
     */
    public static function enter(?self $recorder): void
    {
        self::$frameStack[] = $recorder;
    }

    public static function leave(): void
    {
        if (self::$frameStack !== []) {
            \array_pop(self::$frameStack);
        }
    }

    public static function current(): ?self
    {
        if (self::$frameStack !== []) {
            return self::$frameStack[\count(self::$frameStack) - 1];
        }

        return self::$current;
    }

    public static function installed(): bool
    {
        return self::current() !== null;
    }

    /** 代际号：`reset()` 自增。派生缓存以它为键，帧号重用也不会命中旧数据。 */
    public function generation(): int
    {
        return $this->generation;
    }

    /**
     * 从一份离线证据建**游离实例**：不装进 {@see self::$current}。
     *
     * 为什么不装：`$current` 是"正在录的那一份"。装进去，后续帧会写进这份外来证据，
     * 而 `Query` 手里的对象与文件内容就开始悄悄分叉 —— 两边都"正常"，结论不同。
     * 导入的证据只被读。
     *
     * 容量取 `max(声明值, 实际帧数)`：全量包可能比导出时的窗口更大。照声明值建实例
     * 会让 `window.capacity` 说 600 而实际有 1200 帧 —— 自报说谎，读者无法判断
     * "窗口满了"与"这份包就是全的"。
     *
     * 派生量一律**重算不存**：`geometryDigest` 是"这个节点记过的最后一次签名"，
     * 存进包里就是第二份真相；`seq` / `networkSeq` 取最大值，避免将来误追加时重号。
     */
    public static function hydrate(EvidenceBag $bag): self
    {
        $options = ($bag->options ?? new RecordOptions())->withScript($bag->script);
        $capacity = \max(1, $options->capacity(), \count($bag->frames));
        if ($capacity !== $options->capacity()) {
            $options = $options->withCapacity($capacity);
        }
        // 包里有报文就说明当时开着；选项缺失的旧包靠这条事实补齐，避免把已有
        // 报文说成"未开启"。
        if ($bag->bodies !== [] && !$options->bodies()) {
            $options = $options->withBodies(true);
        }
        if ($bag->paint !== [] && !$options->paint()) {
            $options = $options->withPaint(true);
        }
        if ($bag->async !== [] && !$options->async()) {
            $options = $options->withAsync(true);
        }
        $recorder = new self($options);

        $recorder->nodes = $bag->nodes;
        // 回收表在**入口**归一成一种形状：`pathOf()` / `why()` 这类热路径要在循环里
        // 逐层读父与类名，不能每次调用都先扫一遍整张表判"这条是 int 还是数组"。
        $recorder->evicted = [];
        foreach ($bag->evicted as $id => $meta) {
            $recorder->evicted[(int) $id] = EvidenceBag::evictedRow($meta);
        }
        $recorder->goneAt = $bag->gone;
        $recorder->frames = [];
        foreach ($bag->frames as $frame) {
            $recorder->frames[$frame->id] = $frame;
        }
        $recorder->frameId = $recorder->lastFrameId();
        $recorder->geometry = $bag->geometry;
        $recorder->geometryPrimed = $bag->geometry !== [];
        $recorder->paint = $bag->paint;
        $recorder->network = $bag->network;
        $recorder->async = $bag->async;
        $recorder->bodies = $bag->bodies;
        $recorder->boxless = $bag->boxless;

        $seq = 0;
        foreach ($bag->nodes as $id => $_) {
            $seq = \max($seq, (int) $id);
        }
        $recorder->seq = $seq;

        $recorder->geometryDigest = [];
        foreach ($bag->geometry as $rows) {
            foreach ($rows as $node => $record) {
                $recorder->geometryDigest[(int) $node] = $record->signature();
            }
        }

        $networkSeq = 0;
        foreach ($bag->network as $requestId => $_) {
            $networkSeq = \max($networkSeq, (int) $requestId);
        }
        $recorder->networkSeq = $networkSeq;
        $asyncSeq = 0;
        foreach ($bag->async as $id => $_) {
            $asyncSeq = \max($asyncSeq, (int) $id);
        }
        $recorder->asyncSeq = $asyncSeq;

        return $recorder;
    }

    /** @return list<int> 没有自己盒的节点（复合控件），导出证据包要逐条带上。 */
    /** @return list<int> 有几何记录的帧号（升序） */
    /** @return list<int> 有绘制记录的帧号（升序） */
    /** @return list<int> 有报文的 requestId（升序） */
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
        $this->options->script()?->add(Step::frame($t));
        // 量具是累计账：不清零就会把上一帧的耗时记到这一帧头上。
        if (self::$ownsProfile) {
            SurfaceProfile::reset();
        }
    }

    /**
     * 帧结束：落成不可变记录并按容量裁剪。
     *
     * `$root` 只在有活元素树时给（应用帧）；headless 的纯因果测试可以不给。几何采样
     * 放在帧末而不是 `tapRebuild`：布局会因为父约束变化而重排，那个节点未必重建，
     * 挂在重建挂点上会静默漏掉"没重建但尺寸变了"这一类最常见的布局问题。
     *
     * @param array{stages?: list<string>, settleSkipped?: bool, paintSkipped?: bool} $facts
     * @param bool $aborted 帧内抛了异常（调用方用 `try/finally` 保证成对）；半截的证据
     *                      必须标出来，否则读者会把"只跑了一半的帧"当成"这帧就变了这些"
     */
    public function endFrame(array $facts, float $costMs, ?Element $root = null, bool $aborted = false): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        $quietFrame = $this->geometryPrimed
            && ($facts['settleSkipped'] ?? false) === true
            && ($facts['paintSkipped'] ?? false) === true;
        if ($root !== null && $this->options->geometry() !== GeometryCapture::Off && !$quietFrame) {
            $this->sampleGeometry($root, $this->frameId);
            $this->geometryPrimed = true;
        }
        if ($this->options->paint()) {
            $this->samplePaint($root, $this->frameId);
        }
        $incomplete = [];
        if ($this->causesTruncated) {
            $incomplete[] = FrameRecord::CAUSES_TRUNCATED;
        }
        if ($aborted) {
            $incomplete[] = FrameRecord::ABORTED;
        }
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
            $incomplete,
        );
        $this->openCauses = [];
        $this->openRebuilt = [];
        $this->openWrote = [];
        $this->openSaw = [];
        $this->causesTruncated = false;
        $this->openPaint = [];
        $this->openPaintMisses = [];
        $this->openPaintTotal = 0;
        $this->openPaintHits = 0;
        $this->paintTruncated = false;
        // 归因标记只在结算的同步段内有效：跨帧残留会把下一帧无关的 setState
        // 写进上一帧的响应，制造一条看起来合理、实际不存在的因果边。
        $this->activeRequest = 0;
        $this->activeJob = 0;

        // 叠加层：**画成功才算**。壳在 afterPaint 里真的描了边才把这一笔报上来；
        // 帧被跳过、paint 抛异常、元素已卸载 ⇒ 这里收不到 ⇒ `overlayDrawn` 停在
        // 上一次成功的那一帧，读者据此能分出"请求了"与"上屏了"。
        if (($facts['overlayDrawn'] ?? false) === true && $this->highlightNode > 0) {
            $this->overlayDrawn = ['frame' => $this->frameId, 'node' => $this->highlightNode];
        }

        $this->trim();
        if ($this->frameId - $this->lastRecycleFrame >= self::NODE_RECYCLE_EVERY) {
            $this->recycleNodes();
            $this->lastRecycleFrame = $this->frameId;
        }
    }

    /**
     * 绘制事实（`Pipeline` 的观察者入口）。
     *
     * 只收盒身份与原因，不在这里解析节点号：绘制发生在元素树之外，帧末才有
     * `Element::boxOwners()` 的活树映射。命中只记次数 —— 命中意味着整棵子树
     * 一条 paint 都没跑，逐个记下来会把"省掉的节点"记成"画过的节点"。
     */
    public function notePaint(object $box, bool $replayed, string $reason): void
    {
        if (!$this->open || !$this->options->paint()) {
            return;
        }

        if ($replayed) {
            ++$this->openPaintHits;

            return;
        }

        ++$this->openPaintTotal;
        $id = \spl_object_id($box);
        if (\count($this->openPaint) < self::MAX_PAINTED_NODES) {
            $this->openPaint[] = $id;
        } else {
            $this->paintTruncated = true;
        }

        if ($reason !== PaintRecord::REASON_DIRECT && $reason !== '') {
            if (\count($this->openPaintMisses) < self::MAX_PAINT_MISSES) {
                $this->openPaintMisses[$id] = $reason;
            } else {
                $this->paintTruncated = true;
            }
        }
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
            // 拒收要留痕：不标的话这一帧的树只是"少了半棵"，而报告显示正常。
            $this->causesTruncated = true;

            return;
        }
        $this->openWrote[$id] = true;
        // `from` 记的是**结构父**，不是"谁让它写的"（那由 `site` 回答）。不记它，
        // "自己写了状态的节点"在树里就没有父，`cascade` 会给它另起一个根 ——
        // 于是同一次级联被画成两棵并行的树。
        $parent = $element->parent();
        $parentId = $parent === null ? 0 : $this->idOf($parent);

        // 网络归因：此刻正在结算某条请求 ⇒ 这条 state 边就是它的"落地"。写进 detail，
        // 于是 why(node) 得到"因为 request:#N 在 #M 帧返回"，而不只是"谁写了状态"。
        // 记在边上的同时回填请求的 landed 列表，两边可互相复核。
        //
        // ★ 只记 id，**不拼 URL**。三条理由：
        //   ① URL 带签名参数（`?sig=…&ts=…`）时每帧都不同，而 detail 进
        //      `Digest::ofFrame()` ⇒ facts 指纹永远对不上，"重放两遍比对"失去意义；
        //   ② URL 里的 token / api_key 会随证据包导出，绕过报文脱敏；
        //   ③ 消费者要 URL 就 `network(request:N)` 查 —— 一份数一个名字，
        //      同一件事存两处必漂。
        $detail = self::shortClass($state);
        if ($this->activeRequest > 0 && isset($this->network[$this->activeRequest])) {
            $request = $this->network[$this->activeRequest];
            $detail .= ' <- request:' . $request->requestId;
            if (!\in_array($id, $request->landed, true)) {
                $landed = $request->landed;
                $landed[] = $id;
                $this->network[$request->requestId] = $request->withLanded($landed);
            }
        }
        if ($this->activeJob > 0 && isset($this->async[$this->activeJob])) {
            $job = $this->async[$this->activeJob];
            $detail .= ' <- job:' . $job->id;
            if (!\in_array($id, $job->landed, true)) {
                $landed = $job->landed;
                $landed[] = $id;
                $this->async[$job->id] = $job->withLanded($landed);
            }
        }

        $this->openCauses[] = new Edge(
            'state',
            $parentId === 0 ? null : $parentId,
            $id,
            self::callSite(),
            $detail,
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
        // ★ 上限必须在这里也查一遍。早先只有 `noteStateWrite()` 查，`noteRebuild()`
        //   不查 —— 一帧里重建的节点远多于写状态的节点（一次写入波及整树），
        //   于是"上限"只管住了一半，`openCauses` 照样能涨到全树规模。
        if (\count($this->openCauses) >= self::MAX_CAUSES) {
            $this->causesTruncated = true;

            return;
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
        $this->options->script()?->add(InputScript::pack($event, $t));
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
        $this->options->script()?->add(Step::effect($name, $args, $t));
    }

    /**
     * 高亮请求：记下当前目标、请求发生的帧号，并写进脚本。
     *
     * ★ 与 {@see self::noteEffect()} 同一个理由进脚本：注解只存在记录层的话，
     *   "同一份脚本跑两遍"会让一次高亮了一次没高亮的两次运行拿到同一个驱动指纹。
     *
     * ★ 帧号取 `$this->frameId`（**两次帧之间**看到的是上一帧的号）。高亮真正上屏
     *   在下一帧，所以 `overlayDrawn().frame >= highlightFrame()` 恒成立；反过来
     *   （画得比请求还早）只能说明记账错了。
     *
     * @throws \InvalidArgumentException 节点号为负
     */
    public function noteHighlight(int $node, ?float $t): void
    {
        if ($node < 0) {
            throw new \InvalidArgumentException("高亮的节点号不能为负：{$node}（0 表示清除）");
        }

        $this->highlightNode = $node;
        $this->highlightFrame = $this->frameId;
        $this->options->script()?->add(Step::highlight($node, $t));
    }

    /** 当前高亮的节点号；`0` = 没有。 */
    /** 当前这个高亮是在第几帧被请求的；`0` = 本次录制还没请求过。 */
    /**
     * 原始帧号：`beginFrame()` 每帧自增，与 `frames` 窗口是否裁剪无关。
     *
     * ★ 不能用 {@see self::lastFrameId()} 代替：后者读 `array_key_last($frames)`，
     *   一帧都还没落盘时返回 0 —— 拿它当"现在第几帧"会让帧号自相矛盾。
     */
    public function frameId(): int
    {
        return $this->frameId;
    }

    // ------------------------------------------------------------ 环的只读视图

    /**
     * 环的当前内容：读面（{@see History}，审查面）从这里一次取全。
     *
     * ## 为什么是写面上唯一的读出口
     *
     * 写面必须薄（A54 的 L1）：它跟着 exe 走，每个多出来的方法都是一段发布态
     * 执行不到的机器码。把读方法搬走**之前**实测：`Recorder.cc` 5786 行里有
     * 1388 行（24%）是它们 —— 那部分产物在发布态一次都不执行。
     *
     * 一个出口而不是十几个 getter：读面要的正是"整环"（`why()` 要帧环、
     * `pathOf()` 要节点表、`geometryRows()` 要几何表加基线）。十几个 getter 会让
     * 写面多扛十几个方法壳，而下一个加域的人要先想"该加哪个 getter"。
     *
     * ## 为什么返回数组而不是对象
     *
     * 返回一个 `Store` 值对象意味着写面引用它 ⇒ 它可达 ⇒ tpc 整类编进产物，白干。
     * 数组没有类型可引用。形状由 `History` 声明，它是唯一消费者。
     *
     * ## 为什么每次调用都重新取
     *
     * `debug serve` 长期运行、每帧都在写；构造时取一次快照，读者拿到的是几帧前
     * 的树 —— 那正是 A64 禁止的"拿现在冒充当时"的镜像。取一次是十几个标量拷贝
     * （PHP 数组赋值是写时复制，不复制元素），比一次查询本身的成本低得多。
     *
     * ★ `evicted` 保持**原始形状**（数组，或旧包的单个 int）：归一在
     *   {@see History::evictedNodes()}，那条路径频率低；而 `pathOf()` 在父链循环里
     *   读的是原始表，每次归一都是白扫一遍整张表。
     *
     * @return array{
     *     nodes: array<int, array{p: int, c: string, x: bool, s: int, f?: int}>,
     *     evicted: array<int, array<string, mixed>|int>,
     *     gone: array<int, int>,
     *     boxless: array<int, true>,
     *     digest: array<int, string>,
     *     geometry: array<int, array<int, GeometryRecord>>,
     *     baseline: array<int, GeometryRecord>,
     *     paint: array<int, PaintRecord>,
     *     network: array<int, NetworkRecord>,
     *     async: array<int, AsyncRecord>,
     *     bodies: array<int, NetworkBodyRecord>,
     *     frameId: int,
     *     highlighted: int,
     *     highlightFrame: int,
     *     overlay: array{frame: int, node: int}|null,
     * }
     */
    public function ledger(): array
    {
        return [
            'nodes' => $this->nodes,
            'evicted' => $this->evicted,
            'gone' => $this->goneAt,
            'boxless' => $this->boxless,
            'digest' => $this->geometryDigest,
            'geometry' => $this->geometry,
            'baseline' => $this->geometryBaseline,
            'paint' => $this->paint,
            'network' => $this->network,
            'async' => $this->async,
            'bodies' => $this->bodies,
            'frameId' => $this->frameId,
            'highlighted' => $this->highlightNode,
            'highlightFrame' => $this->highlightFrame,
            'overlay' => $this->overlayDrawn,
        ];
    }

    // ------------------------------------------------------------------ 查询

    /** 本次录制的采集预算；随证据包导出。 */
    public function options(): RecordOptions
    {
        return $this->options;
    }

    /** 录制中的脚本；没开脚本时为 `null`。 */
    public function script(): ?Script
    {
        return $this->options->script();
    }

    // ------------------------------------------------------------------ 网络

    /**
     * 请求发起：`AsyncHttp::request()` 在分流之前调，返回 requestId 交给结算钩子。
     *
     * 帧号用当前帧（帧外的 setState 同理属于下一帧；请求发起常在输入回调里，
     * 那正是它该归属的帧）。host 从 URL 取，不解析 DNS、不记报文。
     */
    public function noteNetworkStart(
        string $method,
        string $url,
        string $path,
    ): int {
        $id = ++$this->networkSeq;
        $this->network[$id] = new NetworkRecord(
            $id,
            $method,
            $url,
            self::hostOf($url),
            $path,
            $this->frameId,
            0,
            0,
            0,
            0.0,
            // 发起者取业务代码行，跳过门面自身（否则 initiator 永远是 AsyncHttp.php）。
            self::callSite(['Drift\\App\\Io\\']),
            false,
        );

        return $id;
    }

    /**
     * 请求结算：成功失败都记（失败的请求不能永远挂在途）。
     *
     * ★ 压 `activeRequest` 是归因的关键：紧接着的 `then()` 回调里的 setState 会
     *   在 `noteStateWrite()` 里看到它，把这条边标成"响应落地"。发法与三个静态
     *   tap 同款，但这里由门面直接调实例方法（门面本来就持有 scope）。
     */
    public function noteNetworkSettle(
        int $requestId,
        int $status,
        int $bytes,
        float $elapsedMs,
        bool $ok,
    ): void {
        $record = $this->network[$requestId] ?? null;
        if ($record === null) {
            return; // 窗口裁掉了：不复活旧请求，也不误记到当前帧
        }
        // 保留此前记下的进度标量：结算发生在进度之后，不能用空串把进度擦掉。
        $this->network[$requestId] = new NetworkRecord(
            $record->requestId,
            $record->method,
            $record->url,
            $record->host,
            $record->path,
            $record->startedAtFrame,
            $this->frameId,
            $status,
            $bytes,
            $elapsedMs,
            $record->initiator,
            $ok,
            $record->landed,
            $record->progress,
        );
        // 一个 pump 循环里可能连续结算多条：后者覆盖前者是刻意的。归因贴着"此刻
        // 正在处理哪个响应"，一条 state 写入只属于一条请求；前一条的写入已在它
        // 自己的 resolve→links 同步段里发生完，不会串到下一条。
        $this->activeRequest = $requestId;
    }

    /**
     * 异步请求的进度标量（multipart 才有；普通 body 没有中途观察点）。
     * 只留最后一次的百分比：逐帧曲线是抓包的活，证据只要"有没有报过、最后到哪"。
     */
    public function noteNetworkProgress(int $requestId, float $fraction): void
    {
        $record = $this->network[$requestId] ?? null;
        if ($record === null) {
            return;
        }
        $this->network[$requestId] = new NetworkRecord(
            $record->requestId, $record->method, $record->url, $record->host, $record->path,
            $record->startedAtFrame, $record->settledAtFrame, $record->status, $record->bytes,
            $record->elapsedMs, $record->initiator, $record->ok, $record->landed,
            \sprintf('%.0f%%', \max(0.0, \min(1.0, $fraction)) * 100.0),
        );
    }

    /** 存一份报文：只在预算显式开启时发生，且按条数独立裁剪。 */
    public function noteNetworkBody(
        int $requestId,
        string $requestRaw,
        string $responseRaw,
    ): void {
        if (!$this->options->bodies()) {
            return;
        }
        if ($requestId <= 0 || !isset($this->network[$requestId])) {
            return; // 骨架已被裁掉：不复活半份报文
        }
        $this->bodies[$requestId] = NetworkBodyRecord::capture(
            $requestId,
            $requestRaw,
            $responseRaw,
            NetworkBodyRecord::MAX_BYTES,
            $this->options->redact(),
        );
    }

    /** 属性查询是否由本会话发号：旧会话的元素带着旧号，不能拿来查本会话的节点。 */
    public function owns(Element $element): bool
    {
        return $element->debugId > 0 && $element->debugSession === $this->tag;
    }

    /** @return array{captured: bool, requests: int, capacity: int, from: int, to: int, bytes: int} */
    /** @return list<NetworkRecord> 按 requestId 升序 */
    /** @return array{captured: bool, requests: int, capacity: int, from: int, to: int, inFlight: int} */
    // ------------------------------------------------------------------ 异步任务

    public function noteAsyncSubmit(string $backend, string $job, int $worker): int
    {
        $id = ++$this->asyncSeq;
        $this->async[$id] = new AsyncRecord(
            $id,
            $backend,
            $job,
            $worker,
            $this->frameId,
            0,
            0,
            AsyncRecord::QUEUED,
            '',
            0.0,
            // 异步发起者允许回方法名：业务 handler 常由框架反射调用，那一帧的 file
            // 在调用者身上，退回 vendor/TestCase.php 会把归因指到工具而不是业务。
            self::callSite(['Drift\\App\\'], true),
        );

        return $id;
    }

    public function noteAsyncStart(int $id, int $worker): void
    {
        $record = $this->async[$id] ?? null;
        if ($record === null || $record->startedAtFrame > 0) {
            return;
        }
        $this->async[$id] = new AsyncRecord(
            $id, $record->backend, $record->job, $worker,
            $record->submittedAtFrame, $this->frameId, $record->settledAtFrame,
            AsyncRecord::RUNNING, $record->code, $record->elapsedMs, $record->initiator, $record->landed,
        );
    }

    public function noteAsyncSettle(int $id, string $state, string $code, float $elapsedMs): void
    {
        $record = $this->async[$id] ?? null;
        if ($record === null || $record->settledAtFrame > 0) {
            return; // 迟到 / 重复结算不复活旧任务
        }
        $this->async[$id] = new AsyncRecord(
            $id, $record->backend, $record->job, $record->worker,
            $record->submittedAtFrame, $record->startedAtFrame, $this->frameId,
            $state, $code, $elapsedMs, $record->initiator, $record->landed,
        );
        // 结算同帧的 setState 属于这条任务，与 request 落地同款归因。
        $this->activeJob = $id;
    }

    /** @return list<AsyncRecord> 按 id 升序 */
    /** @return array{captured: bool, jobs: int, capacity: int, from: int, to: int, inFlight: int} */
    private static function hostOf(string $url): string
    {
        $pos = \strpos($url, '://');
        $rest = $pos === false ? $url : \substr($url, $pos + 3);
        $end = \strcspn($rest, '/?#');

        return $end === 0 ? '' : \substr($rest, 0, $end);
    }

    /** @return array{mode: string, frames: int, capacity: int, from: int, to: int, nodes: int} */
    /**
     * 帧末把绘制盒映射回节点号。
     *
     * 映射只在开了绘制域时建一次；`boxOwners()` 与焦点反查 / `pick()` 共用同一份
     * 口径，避免"绘制记的节点"和"点选查到的节点"各用一套 `effectiveBox()`。
     * 浮层不在主树映射里 ⇒ 记 `unmapped`，不冒充成某个节点。
     */
    private function samplePaint(?Element $root, int $frame): void
    {
        $owners = $root === null ? [] : Element::boxOwners([$root]);
        $painted = [];
        $seen = [];
        $unmapped = [];
        foreach ($this->openPaint as $boxId) {
            $owner = $owners[$boxId] ?? null;
            if ($owner === null) {
                $unmapped[$boxId] = true;
                continue;
            }
            $node = $this->idOf($owner);
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            if (\count($painted) < self::MAX_PAINTED_NODES) {
                $painted[] = $node;
            } else {
                $this->paintTruncated = true;
            }
        }

        $misses = [];
        foreach ($this->openPaintMisses as $boxId => $reason) {
            $owner = $owners[$boxId] ?? null;
            if ($owner === null) {
                $unmapped[$boxId] = true;
                continue;
            }
            $node = $this->idOf($owner);
            if (\count($misses) < self::MAX_PAINT_MISSES) {
                $misses[$node] = $reason;
            } else {
                $this->paintTruncated = true;
            }
        }

        $this->paint[$frame] = new PaintRecord(
            $frame,
            $painted,
            $misses,
            $this->openPaintTotal,
            $this->openPaintHits,
            \count($unmapped),
            $this->paintTruncated,
        );
    }

    /** 帧末从根先序采一遍；父指针取最近的**有盒祖先**，约束是沿盒树传的。 */
    private function sampleGeometry(Element $root, int $frame): void
    {
        // 离树后重新出现的节点（Offstage 保活的页面切回来）用同一个号：签名还留着，
        // 但旧记录可能已经滚出几何窗口。先撤签名，逼这次采样真的落一条。
        foreach ($this->goneAt as $node => $_) {
            unset($this->geometryDigest[$node]);
        }

        $seen = $this->sampleOne($root, $frame, -1);
        foreach ($this->sampledNodes as $node => $_) {
            if (!isset($seen[$node])) {
                $this->goneAt[$node] = $frame;
            }
        }
        foreach ($seen as $node => $_) {
            unset($this->goneAt[$node]);
        }
        $this->sampledNodes = $seen;
    }

    /**
     * 采一棵子树，返回子树里的节点号。
     *
     * ★ 不收 `array &$seen`：tpc 把按引用传入的数组钉成 fixed `php::Array`，
     *   递归闭包再取引用就编不过。改成"子树自己收账、父级合并"后语义不变，
     *   也没有把一次性采样状态挂到会话对象上。
     *
     * @return array<int, true> 本子树里的节点，由 {@see sampleGeometry()} 收账
     */
    private function sampleOne(Element $element, int $frame, int $parentBoxId): array
    {
        $seen = [];
        $id = $this->idOf($element);
        $seen[$id] = true;
        $boxId = $parentBoxId;
        $box = $element->renderObject();
        if ($box === null) {
            $this->boxless[$id] = true;
        }
        if ($box !== null && $box->size() !== null) {
            // 字段抽取与签名共用 GeometryRecord 的字段表；未变节点不再造记录对象。
            $fields = GeometryRecord::fieldsOf($box, $frame, $id, $parentBoxId);
            $signature = GeometryRecord::signatureOf($fields);
            if ($this->options->geometry() === GeometryCapture::All
                || ($this->geometryDigest[$id] ?? null) !== $signature
            ) {
                $record = GeometryRecord::fromFields($fields);
                $this->geometry[$frame][$id] = $record;
                $this->geometryBaseline[$id] = $record;
                $this->geometryDigest[$id] = $signature;
            }
            $boxId = $id;
        }

        $children = [];
        $element->visitChildren(function (Element $child) use (&$children): void {
            $children[] = $child;
        });
        foreach ($children as $child) {
            foreach ($this->sampleOne($child, $frame, $boxId) as $node => $_) {
                $seen[$node] = true;
            }
        }

        return $seen;
    }

    /**
     * 节点 id；表里没有就登记父链与类名（父 id 解析一次，`-1` = 根）。
     *
     * ★ 判"号是不是**本会话**发的"，不是判"元素有没有号"。会话重开时元素还带着
     *   上一个会话的号、登记表却是空的：只看 `debugId === 0` 会直接读
     *   `$nodes[旧号]` 拿到 `Undefined array key`（该节点从此没有父也没有类型，
     *   `pathOf()` 空串、`cascade` 把它画成根，全都看起来正常）；只看"表里没有"
     *   又会把号重新发一遍，可能撞上本会话已经发给别的元素的号 —— 两个元素共用
     *   一个地址，证据串味。会话戳同时堵住这两个洞。
     */
    public function idOf(Element $element): int
    {
        if ($element->debugSession !== $this->tag || !isset($this->nodes[$element->debugId])) {
            // 本会话的号也可能已被回收：回收只删元数据、不复号，活节点再出现时给新号。
            $element->debugId = ++$this->seq;
            $element->debugSession = $this->tag;
            // 记 **Widget** 名而不是 Element 子类名：面板上要看到 `Table` / `Column`，
            // 不是一屏 `StatefulElement` / `MultiChildElement`。
            //
            // `x` = 这个节点是 Flex 容器。归因要用它（Flex 父在 performLayout 里
            // 按 grow/shrink 给子分配尺寸，`applied` 因此不等于"父 inner 原样传下来"）。
            // 记成**布尔事实**而不是让查询去比对类名清单：`Column extends Flex`
            // 这类继承关系由语言回答，新增 Flex 子类时这里自动覆盖，清单不会腐烂。
            $this->nodes[$element->debugId] = [
                'p' => -2,
                'c' => self::shortClass($element->widget()),
                'x' => $element->widget() instanceof Flex,
                's' => $this->frameId,
                // 出生帧只写一次。它不能从 `s` 反推：静止节点（容器、没换过状态的
                // 文本）的 `s` 一直不动，而它其实从第一帧就在 —— 拿 `s` 当出生帧会
                // 让回放早期帧时整棵静止树凭空消失。
                'f' => $this->frameId,
            ];
        }

        $meta = $this->nodes[$element->debugId];
        $meta['s'] = $this->frameId;
        if ($meta['p'] === -2) {
            $parent = $element->parent();
            $meta['p'] = $parent === null ? -1 : $this->idOf($parent);
            $this->nodes[$element->debugId] = $meta;
        }

        return $element->debugId;
    }

    /** 节点的 Widget 短类名（树按类型展开、按类型聚合都用它）。 */
    /** 从根到该节点的类名路径，用于面板显示与"这是哪个节点"的自证。 */
    /** 最近一次重建过该节点的帧；一次都没有则退回最后一帧（好让 `why` 如实说"没重建"）。 */
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
            'capacity' => $this->options->capacity(),
            'lastCostMs' => $last?->costMs ?? 0.0,
            'lastRebuilt' => $last === null ? 0 : \count($last->rebuilt),
        ];
    }

    /** 清空这一轮的全部记录，节点号从 1 重新发。测试与"重新开始录"用。 */
    public function reset(): void
    {
        ++$this->generation;
        $this->frames = [];
        $this->frameId = 0;
        $this->open = false;
        $this->openCauses = [];
        $this->openRebuilt = [];
        $this->openWrote = [];
        $this->openSaw = [];
        $this->network = [];
        $this->bodies = [];
        $this->networkSeq = 0;
        $this->activeRequest = 0;
        $this->async = [];
        $this->asyncSeq = 0;
        $this->activeJob = 0;
        // ★ 几何与签名必须一起清。`trim()` 里"签名不清"说的是**窗口滚动**
        //   （记录被裁掉了，但这份几何确实记过）；`reset()` 是"重新开始录"，
        //   语义不同。不清签名的话，下一帧 `frameId` 从 1 重新开始，签名说
        //   "这个几何记过" ⇒ 一条都不写，而查询还能从残留的旧 #1..#60 里读到
        //   上上次会话的几何 —— 帧号恰好匹配，`stale` 也不会报，看起来完美。
        $this->geometry = [];
        $this->geometryDigest = [];
        $this->geometryBaseline = [];
        $this->sampledNodes = [];
        $this->goneAt = [];
        $this->geometryPrimed = false;
        $this->paint = [];
        $this->openPaint = [];
        $this->openPaintMisses = [];
        $this->openPaintTotal = 0;
        $this->openPaintHits = 0;
        $this->paintTruncated = false;
        // ★ 节点登记与发号计数器必须一起清，否则「重新开始录」只做到一半：
        //   `$seq` 接着上一轮涨，重跑后的号就从 365 起，而 `Element::$debugId`
        //   的契约是"每个会话从 1 开始、按首次出现顺序递增"——结构指纹跨跑
        //   永远对不上。清表 + 换批次 + 归零，三件事缺一不可：只清表不换 tag，
        //   元素见 `debugSession === tag` 会沿用旧号并撞上已发出的号；只换 tag
        //   不清表，旧号的父指针会指向上一棵树的节点。
        $this->nodes = [];
        $this->evicted = [];
        $this->lastRecycleFrame = 0;
        $this->boxless = [];
        // 高亮同理：旧会话的高亮目标在新会话里是个**别的节点**（号从头发），
        // 留着会让"这页一开就高亮着某个陌生节点"，而叠加层照画不误。
        $this->highlightNode = 0;
        $this->highlightFrame = 0;
        $this->overlayDrawn = null;
        $this->seq = 0;
        $this->tag = ++self::$sessionSeq;
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

    /**
     * 网络请求发起：未安装录制器时返回 0，调用方据此跳过结算记录。
     *
     * 与三个热路径 tap 同款静态入口，只是多一个返回值（requestId 要交给结算钩子）。
     */
    public static function tapNetworkStart(
        string $method,
        string $url,
        string $path,
    ): int {
        $recorder = self::$current;
        if ($recorder === null || !$recorder->options->network()) {
            return 0;
        }

        return $recorder->noteNetworkStart($method, $url, $path);
    }

    public static function tapNetworkSettle(
        int $requestId,
        int $status,
        int $bytes,
        float $elapsedMs,
        bool $ok,
    ): void {
        self::$current?->noteNetworkSettle($requestId, $status, $bytes, $elapsedMs, $ok);
    }

    public static function tapNetworkProgress(int $requestId, float $fraction): void
    {
        self::$current?->noteNetworkProgress($requestId, $fraction);
    }

    public static function tapNetworkBody(int $requestId, string $requestRaw, string $responseRaw): void
    {
        self::$current?->noteNetworkBody($requestId, $requestRaw, $responseRaw);
    }

    public static function tapAsyncSubmit(string $backend, string $job, int $worker): int
    {
        $recorder = self::$current;
        if ($recorder === null || !$recorder->options->async()) {
            return 0;
        }

        return $recorder->noteAsyncSubmit($backend, $job, $worker);
    }

    public static function tapAsyncStart(int $id, int $worker): void
    {
        self::$current?->noteAsyncStart($id, $worker);
    }

    public static function tapAsyncSettle(int $id, string $state, string $code, float $elapsedMs): void
    {
        self::$current?->noteAsyncSettle($id, $state, $code, $elapsedMs);
    }

    // ------------------------------------------------------------------ 内部

    /**
     * 回收长期不出现的节点元数据；id 不复用，回收地址仍可查成 `node-evicted`。
     *
     * 窗口内被引用的节点会保住：帧因果、几何行、属性快照与网络落地都算引用。
     * 否则"旧帧里还有这个节点"会被读成"这个节点从没发过号"。
     */
    private function recycleNodes(): void
    {
        $retain = [];
        foreach ($this->frames as $frame) {
            foreach ($frame->causes as $edge) {
                if ($edge->fromId !== null) {
                    $retain[$edge->fromId] = true;
                }
                $retain[$edge->toId] = true;
            }
            foreach ($frame->rebuilt as $node) {
                $retain[(int) $node] = true;
            }
        }
        foreach ($this->geometry as $rows) {
            foreach ($rows as $node => $_) {
                $retain[(int) $node] = true;
            }
        }
        foreach ($this->network as $record) {
            foreach ($record->landed as $node) {
                $retain[(int) $node] = true;
            }
        }
        foreach ($this->async as $record) {
            foreach ($record->landed as $node) {
                $retain[(int) $node] = true;
            }
        }

        foreach ($this->nodes as $id => $meta) {
            $seen = (int) ($meta['s'] ?? 0);
            if (isset($retain[$id]) || $this->frameId - $seen <= self::NODE_TTL_FRAMES) {
                continue;
            }
            // `g` = 真离树的那一帧，**没有就给 0（未离开）而不是拿 `s` 顶替**。
            //
            // 两种"回收"必须分开：
            //   · 节点确实从树上消失了 ⇒ `goneAt` 有值，回放时它在 `g` 之后就不该出现；
            //   · 只是连续 120 帧没被触达（几何采集关着、或连续静止帧）⇒ `goneAt`
            //     从没写过，节点其实还在树上，回放时必须一直算活着。
            // 拿 `s` 顶替会把后者整批判死，于是关掉几何采集后回放早期帧，整棵树
            // 从某一帧起集体消失 —— 而每个环节看起来都正常。
            //
            // 父与类型也一起留下：回放的**主角**恰恰是"后来消失了的东西"，
            // 只留寿命的话，能查出它曾经存在、却画不出它在哪儿。
            $this->evicted[$id] = [
                'p' => (int) ($meta['p'] ?? -2),
                'c' => (string) ($meta['c'] ?? ''),
                'x' => (bool) ($meta['x'] ?? false),
                'f' => (int) ($meta['f'] ?? $seen),
                's' => $seen,
                'g' => (int) ($this->goneAt[$id] ?? 0),
            ];
            unset($this->nodes[$id], $this->boxless[$id], $this->geometryDigest[$id], $this->geometryBaseline[$id]);
        }
        foreach ($this->goneAt as $node => $gone) {
            if (!isset($retain[$node]) && $this->frameId - $gone > self::NODE_TTL_FRAMES) {
                unset($this->goneAt[$node]);
            }
        }

        while (\count($this->evicted) > self::NETWORK_CAPACITY * 2) {
            $oldest = \array_key_first($this->evicted);
            if ($oldest === null) {
                return;
            }
            unset($this->evicted[$oldest]);
        }
    }

    private function trim(): void
    {
        // 空帧先淘汰。桥给空闲窗口挂了一个 ≤25ms 的唤醒上限（否则观察者的话永远送不
        // 进来），于是静止的应用每秒白跑几十帧：settle 与 paint 都整段跳过，却照样占
        // 一个帧号、一个名额。按到达顺序裁，600 帧的窗口七秒就见底 —— 用户翻开历史
        // 看到几百条"什么都没发生"，而刚才那几次真实的点击早被挤出去了。
        //
        // ★ 最新一帧永远留下：读者把窗口尾当成"现在"，尾被裁掉会读成应用卡住了。
        //   只在超容量时才扫，正常节奏下窗口不满，这一轮不产生额外遍历。
        if (\count($this->frames) > $this->options->capacity()) {
            $newest = \array_key_last($this->frames);
            foreach ($this->frames as $id => $frame) {
                if (\count($this->frames) <= $this->options->capacity()) {
                    break;
                }
                if ($id !== $newest && $frame->settleSkipped && $frame->paintSkipped) {
                    unset($this->frames[$id]);
                }
            }
        }
        // 全是实帧（或空帧已被裁光）⇒ 回到"最老的先走"。窗口上限由这一段保证。
        while (\count($this->frames) > $this->options->capacity()) {
            $oldest = \array_key_first($this->frames);
            if ($oldest === null) {
                return;
            }
            unset($this->frames[$oldest]);
        }
        // digest 不清：它表示"这个几何已经记过"，即使那条记录因窗口滚动被裁掉。
        // 清了会在下一帧把同一份几何重新记一遍，且报告看不出为什么突然多了记录。
        while (\count($this->geometry) > self::GEOMETRY_FRAMES) {
            $oldest = \array_key_first($this->geometry);
            if ($oldest === null) {
                return;
            }
            unset($this->geometry[$oldest]);
        }
        while (\count($this->paint) > self::PAINT_FRAMES) {
            $oldest = \array_key_first($this->paint);
            if ($oldest === null) {
                return;
            }
            unset($this->paint[$oldest]);
        }
        // 网络是独立窗口：帧窗口 600 裁不掉 500 条请求，必须在这里单独裁。
        while (\count($this->network) > self::NETWORK_CAPACITY) {
            $oldest = \array_key_first($this->network);
            if ($oldest === null) {
                return;
            }
            unset($this->network[$oldest]);
        }
        while (\count($this->async) > self::ASYNC_CAPACITY) {
            $oldest = \array_key_first($this->async);
            if ($oldest === null) {
                return;
            }
            unset($this->async[$oldest]);
        }
        // 报文是网络的附件：骨架裁掉后报文留着就是没人能按 requestId 索引的孤儿。
        while (\count($this->bodies) > self::BODY_CAPACITY) {
            $oldest = \array_key_first($this->bodies);
            if ($oldest === null) {
                return;
            }
            unset($this->bodies[$oldest]);
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
     *
     * @param list<string> $extraSkip 额外跳过的类名前缀（网络发起要跳过 IO 门面自身）
     * @param bool $methodFallback file/line 缺失时退回 `Class::method`；异步发起者用，
     *                             业务 handler 常由框架反射调用，否则会指到 vendor 调用者
     */
    private static function callSite(array $extraSkip = [], bool $methodFallback = false): string
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
            if ($class !== '') {
                foreach ($extraSkip as $prefix) {
                    if (\str_starts_with($class, $prefix)) {
                        continue 2;
                    }
                }
            }
            $file = isset($frame['file']) ? (string) $frame['file'] : '';
            if ($file === '') {
                if ($methodFallback && $class !== '' && isset($frame['function'])) {
                    return $class . '::' . (string) $frame['function'];
                }
                continue;
            }
            // 反射调用的那一帧，`file` 指向调用者（测试里就是 vendor/TestCase.php）。
            // 对异步发起者而言，"哪个业务方法交的活"比"框架哪一行调的它"更有用。
            if ($methodFallback && $class !== '' && isset($frame['function'])
                && \str_contains(\str_replace('\\', '/', $file), '/vendor/')) {
                return $class . '::' . (string) $frame['function'];
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
