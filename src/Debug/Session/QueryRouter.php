<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 查询的**协议面**：按名字分派一次查询，拿回一份纯数组。
 *
 * ## 为什么从 `DebugSession` 搬出来
 *
 * 这个类原来长在 `App\DebugSession` 里，与"驱动应用"混在一起。但两件事的依赖面
 * 完全不同：分派只要一个 `Recorder` + 两个取活树根的回调，而驱动要 `Application`、
 * `BoxConstraints`、输入派发。
 *
 * 混在一起的代价在"离线复算"上现形：导入的证据包里没有活应用，于是要么给
 * `DebugSession` 塞一个假应用，要么另写一份分派 —— 而后者会让"离线复算与在线
 * 逐字节相同"这条判据退化成"两份实现碰巧一致"，它本来要证明的是"同一份实现"。
 *
 * ## 活树相关的那两个查询怎么办
 *
 * `props` / `pick` 需要活树（反射读配置、命中测试）。离线时闭包为 null，它们明确
 * 报"离线不可用"，而不是崩在一个 null 上——也**不**假装返回空：`props` 返回空会
 * 被读成"这个节点没有字段"。
 *
 * @api
 */
final class QueryRouter implements SessionQueries
{
    /**
     * 查询名 => 一句话说明。这是可遍历的能力面，`QUERY_SPECS` 是形状，两者必须
     * 双向闭合（判据在 `DebugSessionTest`）。
     *
     * @var array<string, string>
     */
    public const array QUERIES = [
        'stats' => '录制概览：帧数 / 节点数 / 容量 / 最近帧',
        'frames' => '最近 N 帧摘要（可给 since 增量游标），可按“有重建”过滤',
        'explain' => '单帧展开：重建节点（带路径）与根因分布',
        'why' => '某节点在某帧为什么重建',
        'hotspots' => '跨帧聚合根因（kind=state）：调用点 + State 类',
        'nodes' => '跨帧聚合节点：重建次数、自身写入与父传播',
        'cascade' => '单帧因果森林：结构根、同形兄弟折叠、子树规模、state 写入点、按类型构成',
        'boxes' => '一帧全部盒的几何摘要（只读几何记录，供投影层跨盒比较约束）',
        'boxModel' => '单节点盒模型：margin / border / padding / content 四层与位置（几何快照）',
        'constraints' => '单节点约束链：逐跳复算并与快照交叉验证，标出归不到基类默认的跳',
        'paint' => '一帧的绘制事实：重画节点集合、层缓存命中数、各边界重录原因（独立 60 帧窗口）',
        'repaints' => '跨帧重画聚合：谁每帧都在画、哪些边界反复重录、按原因分布与命中率',
        'network' => '网络请求骨架：请求 → 帧对齐 → 落地节点（不含报文，独立 500 条窗口）',
        'networkBody' => '按 requestId 取请求 / 响应报文（脱敏 + 截断，独立 200 条窗口，默认不进证据面）',
        'async' => '异步任务时间线：提交 / 派发 / 结算帧、状态、耗时与落地节点（默认关）',
        'tree' => '结构树：第 N 帧那棵树（按出生/离开帧过滤，已回收节点在它活着的帧里照样画出）',
        'treeDiff' => '两帧结构对比：出生 / 离开 / 重建事件（区间左开右闭，不假装记录了父与类型的旧值）',
        'align' => '跨域对齐：同一节点与显式帧的因果 / 几何 / 网络三域事实（各自回显实际帧号与窗口）',
        'find' => '在证据里按字面子串搜索：节点自身的类型 / 网络方法·URL·状态，回一串可追问的地址',
        'props' => '按节点读 Widget / State 配置（dev 反射活树，即时内省、不是证据、不进包）',
        'memory' => '进程内存与活树对象存活跨度（dev 内省，WeakMap 台账，不是证据、不进包）',
        'source' => '把 site（file:line）解释成方法定义位置（dev 反射，即时内省、不是证据）',
        'pick' => '按视口坐标点选：命中链（根→最深的盒）+ 每层节点 / Widget / 路径，含浮层原点',
        'highlight' => '高亮状态：现在高亮谁 / 何时请求 / 最后一次真的上屏在哪帧',
        'digest' => '记录指纹：节点表 + 逐帧因果哈希（不含时间，可跨进程比对）',
        'script' => '本次会话录下的驱动脚本：目标 / 视口 / 环境 / 步骤',
        'state' => '会话身份与状态：令牌 / 末帧 / running|paused|finished|offline',
        'capabilities' => '协议版本与能力目录：方法 / 查询形状 / 错误码（离线也能问）',
    ];

    /**
     * 没有 `Query` 方法可转发、由会话自己答的四条查询。
     *
     * `digest` 算指纹、`script` 回脚本、`state` 回会话身份与状态、`capabilities`
     * 回协议目录（{@see \Drift\Debug\Protocol\Capabilities}）。它们都不属于任何
     * 记录域：给 `Query` 造四个假方法只会让门面多四条空转分派。
     *
     * 这张表同时被协议目录与闭合判据消费，别再在别处写第四遍。
     *
     * @var list<string>
     */
    public const array SELF_SERVED = ['digest', 'script', 'state', 'capabilities'];

    /**
     * 查询的**协议形状**：转发到哪个 `Query` 方法、按什么顺序收哪些参数。
     *
     * 参数默认值原先写两处：一次在 `Query` 的方法签名里（给同进程直调），一次在
     * 分派处（给协议面填缺省）。两处一旦漂开**不会报错**，只会让"同一次调用在
     * 本地与远端给出不同结果"——而两条路径各自看起来都正常。
     *
     * 表里只有一处是权威：按它把命名参数摊成位置参数。`Query` 签名里的默认值仍在
     * （它就是 PHP 默认参数，直调时是便利），两者的一致性由判据用反射钉住。
     *
     * ## 写法
     *
     * `参数名 => [类型, 默认值?]`，顺序即调用位置顺序：
     *
     * - `['int']` —— 必填
     * - `['int', 30]` —— 可选，缺省 30
     * - `['?int']` —— 可选，缺省 `null`（**"没给"与"给了 0"是两件事**）
     * - `['inject', 'treeRoots']` —— 由会话注入，不暴露给协议面
     *
     * 参数名必须与 `Query` 方法的形参名**逐字相同**：协议键、规格键、形参名三处
     * 同一个词，规格表才不需要一张别名映射，而别名表本身就会漂。
     *
     * @var array<string, array{0: string, 1: array<string, array{0: string, 1?: mixed}>}>
     */
    public const array QUERY_SPECS = [
        'stats' => ['stats', []],
        'frames' => ['frames', [
            'limit' => ['int', 30],
            'rebuiltOnly' => ['bool', false],
            'since' => ['?int'],
        ]],
        'explain' => ['explain', ['frame' => ['int']]],
        'why' => ['why', ['node' => ['int'], 'frame' => ['?int']]],
        'hotspots' => ['hotspots', ['frames' => ['int', 300], 'limit' => ['int', 10]]],
        'nodes' => ['nodes', ['frames' => ['int', 300], 'limit' => ['int', 10]]],
        'cascade' => ['cascade', [
            'frame' => ['int'],
            'maxDepth' => ['int', 5],
            'maxChildren' => ['int', 8],
        ]],
        'boxes' => ['boxes', ['frame' => ['?int']]],
        'boxModel' => ['boxModel', ['node' => ['int'], 'frame' => ['?int']]],
        'constraints' => ['constraints', [
            'node' => ['int'],
            'frame' => ['?int'],
            'maxDepth' => ['int', 12],
        ]],
        'paint' => ['paint', ['frame' => ['?int']]],
        'repaints' => ['repaints', ['frames' => ['int', 300], 'limit' => ['int', 10]]],
        'network' => ['network', ['request' => ['?int'], 'limit' => ['int', 50]]],
        'networkBody' => ['networkBody', [
            'request' => ['int'],
            'part' => ['string', 'both'],
            'maxBytes' => ['int', 8192],
        ]],
        'async' => ['async', ['job' => ['?int'], 'limit' => ['int', 50]]],
        'tree' => ['tree', [
            'root' => ['int', 0],
            'depth' => ['int', 6],
            'limit' => ['int', 200],
            'frame' => ['?int'],
        ]],
        'treeDiff' => ['treeDiff', [
            'base' => ['int', 0],
            'frame' => ['?int'],
            'limit' => ['int', 200],
        ]],
        'align' => ['align', ['node' => ['int'], 'frame' => ['int']]],
        'find' => ['find', [
            'pattern' => ['string'],
            'domain' => ['string', 'all'],
            'limit' => ['int', 50],
            'caseSensitive' => ['bool', false],
        ]],
        'props' => ['props', [
            'node' => ['int'],
            'roots' => ['inject', 'treeRoots'],
            'maxDepth' => ['int', 3],
            'maxFields' => ['int', 120],
        ]],
        'memory' => ['memory', ['roots' => ['inject', 'treeRoots'], 'limit' => ['int', 40]]],
        'source' => ['source', ['site' => ['string']]],
        'pick' => ['pick', [
            'x' => ['float'],
            'y' => ['float'],
            'roots' => ['inject', 'treeRootOrigins'],
        ]],
        'highlight' => ['highlight', []],
    ];

    /** 规格表里的参数项没有第二元素 = 必填。用哨兵而不是 `null`：`?int` 的缺省正是 `null`。 */
    private const string REQUIRED = "\0required";

    /** 读面：除了 `Digest::of()` 要原始录制器，本类其余读都走它。 */
    private readonly History $history;

    /**
     * @param (callable(): array<int, mixed>)|null $treeRoots       活树根（离线为 null）
     * @param (callable(): array<int, mixed>)|null $treeRootOrigins 活树根原点（离线为 null）
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Query $queries,
        private readonly mixed $treeRoots = null,
        private readonly mixed $treeRootOrigins = null,
        private readonly mixed $state = null,
    ) {
        $this->history = new History($recorder);
    }

    /**
     * 只要读能力的会话：给导入的证据包用（没有活应用，也不需要驱动力）。
     *
     * 状态是 `offline` 且令牌为空：它不是"某个进程里的会话"，看它的人不该拿它
     * 去跟活会话的游标比。
     */
    public static function offline(Recorder $recorder): self
    {
        $state = new SessionState('');
        $state->state = 'offline';

        return new self(
            $recorder,
            new Query($recorder),
            state: static fn(): SessionState => $state,
        );
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function query(string $name, array $args = []): array
    {
        $spec = self::QUERY_SPECS[$name] ?? null;
        if ($spec !== null) {
            [$method, $params] = $spec;
            $result = $this->queries->{$method}(...$this->positional($name, $args, $params));

            // 帧序列是唯一的"带游标的增量流"，也就只有它会被跨会话误用：老 `since`
            // 在新会话上把所有帧都当已读，于是静默返回空 —— 与"真的没有新帧"分不开。
            // 其他查询是问一次拿一份，误用表现为数据变了，不会静默丢东西，所以不给
            // 所有响应都塞这个键（那会让证据面的形状白白多一列）。
            if ($name === 'frames') {
                $result['session'] = $this->sessionToken();
            }

            return $result;
        }

        return match ($name) {
            'digest' => Digest::of($this->recorder),
            // 包没带脚本域时返回空数组：明确"这次录制没有脚本"，而不是崩在 null 上。
            'script' => $this->history->script()?->toArray() ?? [],
            // 会话状态不是证据事实（`Query` 只认 `Recorder`），所以走这里而不是
            // `stats`：`stats` 答的是"录到了什么"，`state` 答的是"这个会话还在动吗"。
            'state' => $this->stateQuery(),
            // 能力目录不依赖会话状态；离线包也能问。真正"这个宿主现在能不能用"
            // 由 Methods::CAPABILITIES 的回执加 host 一栏回答 —— 那栏需要宿主，
            // 不属于查询面。
            'capabilities' => \Drift\Debug\Protocol\Capabilities::describe(),
            default => throw new \InvalidArgumentException(
                "未知查询：{$name}（可用：" . \implode(' / ', \array_keys(self::QUERIES)) . '）',
            ),
        };
    }

    /**
     * 会话身份 + 末帧号 + 状态。
     *
     * 末帧放在这里而不是让观察者从 `frames()` 自己数：两个数必须**同一时刻读**，
     * 分两次查时中间可能又推进了一帧，于是"观察者看到的末帧"与"宿主报的末帧"
     * 天然对不上——而那条判据（#2）正是要证明它们同源。
     *
     * @return array{session: string, state: string, reason: ?string, lastFrame: int}
     */
    private function stateQuery(): array
    {
        $state = $this->state === null ? null : ($this->state)();

        return [
            'session' => $this->sessionToken(),
            'state' => $state instanceof SessionState ? $state->state : 'offline',
            'reason' => $state instanceof SessionState ? $state->reason : null,
            'lastFrame' => $this->history->lastFrameId(),
        ];
    }

    /** 离线会话与没有令牌的会话都报空串 —— 空串不是任何真实令牌，比不了就等于不符。 */
    private function sessionToken(): string
    {
        $state = $this->state === null ? null : ($this->state)();

        return $state instanceof SessionState ? $state->token : '';
    }

    /**
     * 按规格把命名参数摊成位置参数。
     *
     * 顺序来自规格表的键序（PHP 关联数组保序），所以调用形态是
     * `$this->queries->frames(...)` —— 没有第二套位置映射要维护。
     *
     * @param array<string, mixed>                       $args
     * @param array<string, array{0: string, 1?: mixed}> $params
     * @return list<mixed>
     */
    private function positional(string $name, array $args, array $params): array
    {
        $out = [];
        foreach ($params as $key => $spec) {
            $type = $spec[0];

            // 注入参数不出现在协议面上：调用方无从伪造一棵树的根。
            if ($type === 'inject') {
                $out[] = $this->injected($name, (string) ($spec[1] ?? ''));
                continue;
            }

            // `?int` 不带第二元素就是"可选、缺省 null"—— 可空类型本身已经表达了缺省。
            $absent = \array_key_exists(1, $spec)
                ? $spec[1]
                : (\str_starts_with($type, '?') ? null : self::REQUIRED);
            $out[] = self::coerce($name, $key, $type, $absent, $args);
        }

        return $out;
    }

    private function injected(string $name, string $source): array
    {
        $callback = match ($source) {
            'treeRoots' => $this->treeRoots,
            'treeRootOrigins' => $this->treeRootOrigins,
            default => throw new \LogicException('规格表里的未知注入源：' . $source),
        };

        if (!\is_callable($callback)) {
            // 离线会话没有活树。这里必须明确失败：返回空数组会让 `props` 被读成
            // "这个节点没有配置字段"，而真相是"这份证据里根本没有活树"。
            throw new \InvalidArgumentException(
                "查询 {$name} 需要活树（{$source}），离线会话没有——它只在 --page / --remote 下可用",
            );
        }

        return $callback();
    }

    /**
     * 取一个参数并按规格转成目标类型。
     *
     * `?int` 缺省为 `null` 而不是 `0`：游标 `since=0` 是"从头给"，"没给 since"
     * 是"给最近 N 帧"，合并这两件事会让"从头取"变成"取最近的"，差集正好是历史帧。
     *
     * @param mixed                $absent 缺省值，或 {@see self::REQUIRED}
     * @param array<string, mixed> $args
     */
    private static function coerce(string $name, string $key, string $type, mixed $absent, array $args): mixed
    {
        $nullable = \str_starts_with($type, '?');
        $base = $nullable ? \substr($type, 1) : $type;

        if (!\array_key_exists($key, $args)) {
            if ($absent !== self::REQUIRED) {
                return $absent;
            }
            throw new \InvalidArgumentException("查询 {$name} 需要参数 {$key}");
        }

        $value = $args[$key];
        if ($value === null) {
            if ($nullable) {
                return null;
            }
            throw new \InvalidArgumentException("查询 {$name} 的参数 {$key} 不能为空");
        }

        return match ($base) {
            'int' => self::asInt($name, $key, $value),
            'float' => self::asFloat($name, $key, $value),
            'bool' => (bool) $value,
            'string' => (string) $value,
            default => throw new \LogicException("规格表里的未知参数类型：{$base}"),
        };
    }

    private static function asInt(string $name, string $key, mixed $value): int
    {
        if (!\is_int($value) && !\is_string($value)) {
            throw new \InvalidArgumentException("查询 {$name} 的参数 {$key} 必须是整数");
        }

        return (int) $value;
    }

    /** 坐标必须是数；`0` 是合法坐标，不能用 `?:` 兜成缺失。 */
    private static function asFloat(string $name, string $key, mixed $value): float
    {
        if (!\is_int($value) && !\is_float($value) && !\is_string($value)) {
            throw new \InvalidArgumentException("查询 {$name} 需要数值参数 {$key}");
        }
        if (\is_string($value) && !\is_numeric($value)) {
            throw new \InvalidArgumentException("查询 {$name} 的参数 {$key} 必须是数值");
        }

        return (float) $value;
    }
}
