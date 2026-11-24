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

    /** 几何事实保留的帧数；比帧窗口短是刻意的，查询必须自报边界。 */
    public const int GEOMETRY_FRAMES = 60;

    /** 网络请求保留的条数：不受帧数约束（600 帧里可能上万次请求），独立窗口。 */
    public const int NETWORK_CAPACITY = 500;

    /**
     * 报文存档条数：比骨架短得多（每条最大 64KB，500 条就是 32MB）。
     * 报文是**按需查的附件**，不是默认证据面；这个窗口只保证"最近几百次"可回看。
     */
    public const int BODY_CAPACITY = 200;

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
     * `$root` 只在有活元素树时给（应用帧）；headless 的纯因果测试可以不给。几何采样
     * 放在帧末而不是 `tapRebuild`：布局会因为父约束变化而重排，那个节点未必重建，
     * 挂在重建挂点上会静默漏掉"没重建但尺寸变了"这一类最常见的布局问题。
     *
     * @param array{stages?: list<string>, settleSkipped?: bool, paintSkipped?: bool} $facts
     */
    public function endFrame(array $facts, float $costMs, ?Element $root = null): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        if ($root !== null) {
            $this->sampleGeometry($root, $this->frameId);
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
        );
        $this->openCauses = [];
        $this->openRebuilt = [];
        $this->openWrote = [];
        $this->openSaw = [];
        // 归因标记只在结算的同步段内有效：跨帧残留会把下一帧无关的 setState
        // 写进上一帧的响应，制造一条看起来合理、实际不存在的因果边。
        $this->activeRequest = 0;
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

        // 网络归因：此刻正在结算某条请求 ⇒ 这条 state 边就是它的"落地"。写进 detail，
        // 于是 why(node) 得到"因为 api/rows.php 在 #N 帧返回"，而不只是"谁写了状态"。
        // 记在边上的同时回填请求的 landed 列表，两边可互相复核。
        $detail = self::shortClass($state);
        if ($this->activeRequest > 0 && isset($this->network[$this->activeRequest])) {
            $request = $this->network[$this->activeRequest];
            $detail .= ' <- request:' . $request->requestId . ' ' . $request->method . ' ' . $request->url;
            if (!\in_array($id, $request->landed, true)) {
                $landed = $request->landed;
                $landed[] = $id;
                $this->network[$request->requestId] = $request->withLanded($landed);
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

    /**
     * 取某帧某节点的几何事实。
     *
     * 该帧没记不代表"那时没这个节点"——只代表**这一帧几何没变**，所以往前找最近
     * 的一次记录。找不到时返回 null，调用方必须区分"节点不存在"和"窗口外"，不能
     * 把 null 当成默认几何（那会凭空造出一个盒子）。
     */
    public function geometryAt(int $frame, int $node): ?GeometryRecord
    {
        if ($this->geometry === []) {
            return null;
        }

        $keys = \array_keys($this->geometry);
        $min = \min($keys);
        $max = \max($keys);
        for ($f = \min($frame, $max); $f >= $min; --$f) {
            if (isset($this->geometry[$f][$node])) {
                return $this->geometry[$f][$node];
            }
        }

        return null;
    }

    /** 这个节点有没有自己的盒；复合控件（PageShell 等）没有，只能看它的盒子类后代。 */
    public function isBoxless(int $node): bool
    {
        return isset($this->boxless[$node]);
    }

    /** 给无盒节点找一个有盒的直接孩子，供查询给"去查这个"的建议而不是空手。 */
    public function firstBoxedChild(int $node): ?int
    {
        foreach ($this->nodes as $id => $meta) {
            if ($meta['p'] === $node && !isset($this->boxless[$id]) && isset($this->geometryDigest[$id])) {
                return $id;
            }
        }

        return null;
    }

    /**
     * 目标帧上生效的全部盒几何：每个节点取不晚于该帧的最新一条。
     *
     * 与 {@see geometryAt()} 是同一套"往前找最近记录"的语义，只是这里一次给全，
     * 供投影层跨盒扫描（例如比较父给子的约束与子实际收到的约束）。逐节点各问一次
     * 会得到同样的答案，但那要多 N 次往返，而且让消费者自己拼一张表。
     *
     * @return array<int, GeometryRecord> node id => 该帧生效的几何记录
     */
    public function geometryRows(int $frame): array
    {
        $out = [];
        foreach ($this->geometry as $f => $rows) {
            if ($f > $frame) {
                break;
            }
            foreach ($rows as $node => $record) {
                $out[$node] = $record;
            }
        }

        return $out;
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

    /**
     * 存一份报文：只在录制器安装时发生，且按条数独立裁剪。
     *
     * 请求体传进来时在门面就把 callable / 文件句柄剔掉了（它们进不了证据）；
     * multipart 的文件只留字段名与字节数摘要，不读文件内容。
     */
    public function noteNetworkBody(
        int $requestId,
        string $requestRaw,
        string $responseRaw,
    ): void {
        if ($requestId <= 0 || !isset($this->network[$requestId])) {
            return; // 骨架已被裁掉：不复活半份报文
        }
        $this->bodies[$requestId] = NetworkBodyRecord::capture($requestId, $requestRaw, $responseRaw);
    }

    public function bodyAt(int $requestId): ?NetworkBodyRecord
    {
        return $this->bodies[$requestId] ?? null;
    }

    /** @return array{requests: int, capacity: int, from: int, to: int, bytes: int} */
    public function bodyCoverage(): array
    {
        $keys = \array_keys($this->bodies);
        $bytes = 0;
        foreach ($this->bodies as $body) {
            $bytes += $body->request['bytes'] + $body->response['bytes'];
        }

        return [
            'requests' => \count($keys),
            'capacity' => self::BODY_CAPACITY,
            'from' => $keys === [] ? 0 : \min($keys),
            'to' => $keys === [] ? 0 : \max($keys),
            'bytes' => $bytes,
        ];
    }

    /** @return list<NetworkRecord> 按 requestId 升序 */
    public function networkRows(): array
    {
        $rows = \array_values($this->network);
        \usort($rows, static fn(NetworkRecord $a, NetworkRecord $b): int => $a->requestId <=> $b->requestId);

        return $rows;
    }

    public function networkAt(int $requestId): ?NetworkRecord
    {
        return $this->network[$requestId] ?? null;
    }

    /** @return array{requests: int, capacity: int, from: int, to: int, inFlight: int} */
    public function networkCoverage(): array
    {
        $keys = \array_keys($this->network);
        $inFlight = 0;
        foreach ($this->network as $record) {
            if ($record->settledAtFrame === 0) {
                ++$inFlight;
            }
        }

        return [
            'requests' => \count($keys),
            'capacity' => self::NETWORK_CAPACITY,
            'from' => $keys === [] ? 0 : \min($keys),
            'to' => $keys === [] ? 0 : \max($keys),
            'inFlight' => $inFlight,
        ];
    }

    private static function hostOf(string $url): string
    {
        $pos = \strpos($url, '://');
        $rest = $pos === false ? $url : \substr($url, $pos + 3);
        $end = \strcspn($rest, '/?#');

        return $end === 0 ? '' : \substr($rest, 0, $end);
    }

    /** @return array{frames: int, capacity: int, from: int, to: int, nodes: int} */
    public function geometryCoverage(): array
    {
        $keys = \array_keys($this->geometry);
        $nodes = 0;
        foreach ($this->geometry as $rows) {
            $nodes += \count($rows);
        }

        return [
            'frames' => \count($keys),
            'capacity' => self::GEOMETRY_FRAMES,
            'from' => $keys === [] ? 0 : \min($keys),
            'to' => $keys === [] ? 0 : \max($keys),
            'nodes' => $nodes,
        ];
    }

    /** 帧末从根先序采一遍；父指针取最近的**有盒祖先**，约束是沿盒树传的。 */
    private function sampleGeometry(Element $root, int $frame): void
    {
        $this->sampleOne($root, $frame, -1);
    }

    private function sampleOne(Element $element, int $frame, int $parentBoxId): void
    {
        $id = $this->idOf($element);
        $boxId = $parentBoxId;
        $box = $element->renderObject();
        if ($box === null) {
            $this->boxless[$id] = true;
        }
        if ($box !== null) {
            // 采样写在这里而不是 GeometryRecord：Debug 层不得依赖 Layout（lint 的
            // 依赖方向规则）。$box 的类型由 Element::renderObject() 推断，不需要 import。
            $size = $box->size();
            if ($size !== null) {
                $mods = $box->mods();
                $style = $mods?->boxStyle();
                $align = $mods !== null ? $mods->alignment() : null;
                $record = new GeometryRecord(
                    $frame,
                    $id,
                    $parentBoxId,
                    GeometryRecord::pack($box->appliedConstraints()),
                    GeometryRecord::pack($box->constraints()),
                    ['w' => $size->width, 'h' => $size->height],
                    ['x' => $box->offsetX(), 'y' => $box->offsetY()],
                    GeometryRecord::edges(
                        $mods !== null && $mods->hasPadding() ? $mods->padLeft() : 0.0,
                        $mods !== null && $mods->hasPadding() ? $mods->padTop() : 0.0,
                        $mods !== null && $mods->hasPadding() ? $mods->padRight() : 0.0,
                        $mods !== null && $mods->hasPadding() ? $mods->padBottom() : 0.0,
                    ),
                    GeometryRecord::edges(
                        $mods !== null && $mods->hasInset() ? $mods->insetLeft() : 0.0,
                        $mods !== null && $mods->hasInset() ? $mods->insetTop() : 0.0,
                        $mods !== null && $mods->hasInset() ? $mods->insetRight() : 0.0,
                        $mods !== null && $mods->hasInset() ? $mods->insetBottom() : 0.0,
                    ),
                    $mods !== null && ($mods->hasBoxWidth() || $mods->hasBoxHeight())
                        ? ['w' => $mods !== null && $mods->hasBoxWidth() ? $mods->boxWidth() : null,
                           'h' => $mods !== null && $mods->hasBoxHeight() ? $mods->boxHeight() : null]
                        : null,
                    $mods !== null && $mods->hasAspect() ? $mods->aspect() : null,
                    $align !== null ? ['x' => $align->x, 'y' => $align->y] : null,
                    $style !== null && $style->hasBorder(),
                    $style?->borderWidth ?? 0.0,
                    ['grow' => $mods?->flexGrow() ?? 0, 'shrink' => $mods?->flexShrink() ?? 0],
                );
                $signature = $record->signature();
                if (($this->geometryDigest[$id] ?? null) !== $signature) {
                    $this->geometry[$frame][$id] = $record;
                    $this->geometryDigest[$id] = $signature;
                }
                $boxId = $id;
            }
        }

        $element->visitChildren(function (Element $child) use ($frame, $boxId): void {
            $this->sampleOne($child, $frame, $boxId);
        });
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
        $this->network = [];
        $this->bodies = [];
        $this->networkSeq = 0;
        $this->activeRequest = 0;
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
        return self::$current?->noteNetworkStart($method, $url, $path) ?? 0;
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
        // digest 不清：它表示"这个几何已经记过"，即使那条记录因窗口滚动被裁掉。
        // 清了会在下一帧把同一份几何重新记一遍，且报告看不出为什么突然多了记录。
        while (\count($this->geometry) > self::GEOMETRY_FRAMES) {
            $oldest = \array_key_first($this->geometry);
            if ($oldest === null) {
                return;
            }
            unset($this->geometry[$oldest]);
        }
        // 网络是独立窗口：帧窗口 600 裁不掉 500 条请求，必须在这里单独裁。
        while (\count($this->network) > self::NETWORK_CAPACITY) {
            $oldest = \array_key_first($this->network);
            if ($oldest === null) {
                return;
            }
            unset($this->network[$oldest]);
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
     */
    private static function callSite(array $extraSkip = []): string
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
