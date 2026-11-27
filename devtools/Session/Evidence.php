<?php
declare(strict_types=1);

namespace Drift\DevTools\Session;

use Drift\Debug\Session\AsyncRecord;
use Drift\Debug\Session\EvidenceBag;
use Drift\Debug\Session\FrameRecord;
use Drift\Debug\Session\GeometryCapture;
use Drift\Debug\Session\GeometryRecord;
use Drift\Debug\Session\NetworkBodyRecord;
use Drift\Debug\Session\NetworkRecord;
use Drift\Debug\Session\PaintRecord;
use Drift\Debug\Session\RecordOptions;
use Drift\Debug\Session\Recorder;
use Drift\Debug\Session\Script;
use Drift\DevTools\Json;

/**
 * 证据包的编解码：一份会话记录 ⇄ 一个文件。
 *
 * ## 格式：NDJSON，首行 manifest，其余一行一条记录
 *
 * ```
 * {"kind":"manifest","schema":"drift-evidence","version":1,"domains":[...],"counts":{...},"digests":{...}}
 * {"kind":"node","id":1,"p":-1,"c":"PageShell","x":false}
 * {"kind":"frame","id":41,"t":656.0,"cost":12.34,"stageNs":{"settle":9876543},...}
 * ```
 *
 * ## 为什么不是"一个 JSON 对象"
 *
 * 三条，任何一条单独成立都够：
 *
 * 1. **加域会破兼容。** 嵌套的 `facts: {frames, geometry}` 是固定键的对象：S3d 加
 *    绘制域时，v1 的读者要么忽略它（不知道自己漏了一域），要么报错（等于破兼容）。
 *    一行一个 `kind` 则加域只是多一种记录类型，老读者遇到**不认识的 kind 报错**，
 *    于是"漏了"永远是显式的。
 * 2. **内存随包线性翻倍。** 600 帧全量包几十 MB，`json_decode` 整包到 PHP 数组的
 *    峰值是文件大小的 5–10 倍。逐行读 `fgets` 的峰值与包大小无关。
 * 3. **不可 diff、不可 grep。** 单行 30 MB，`git diff` 无意义，查"第 41 帧重建了谁"
 *    只能写程序。NDJSON 下 `head -1` 看版本、`grep '"kind":"frame"'` 数帧、
 *    `diff` 两份证据按行对齐。
 *
 * 代价：不是严格 JSON，第三方工具读不了。可以接受 —— 第一消费者是我们自己的
 * `Query`，而"能被 shell 处理"在这个场景里比"能被 jq 处理"更值钱。
 *
 * ## 包里只装事实，不装发现
 *
 * `Brief::findings()` 是事实的纯函数输出。把发现写进包就是第二份真相：包里的发现
 * 与现算的发现一旦不同，读者无从判断该信哪一个。而"导出 → 导入 → 简报逐字节相同"
 * 这条判据，正是靠**现算**才测得到"事实是否完整"。
 *
 * ## 不写生成时间与工具版本
 *
 * 证据包要能被逐字节比较。任何"生成时刻"都会让两份内容相同的证据看起来不同 ——
 * 与 {@see Digest::hash()} 排除时间的理由同源。谁生成的可以从脚本的 `target` 与
 * 帧指纹推。
 *
 * @api
 */
final class Evidence
{
    public const string SCHEMA = 'drift-evidence';

    public const int VERSION = 1;

    public const string D_NODES = 'nodes';
    public const string D_BOXLESS = 'boxless';
    public const string D_EVICTED = 'evicted';
    public const string D_FRAMES = 'frames';
    public const string D_GEOMETRY = 'geometry';
    public const string D_PAINT = 'paint';
    public const string D_NETWORK = 'network';
    public const string D_ASYNC = 'async';
    public const string D_BODIES = 'bodies';
    public const string D_SCRIPT = 'script';

    /**
     * 默认导出的域：**除报文外全给**。
     *
     * 不按"只导慢帧"划，是因为那样导入后的简报与在线简报会不同 —— 几何规则看不到
     * 别的帧，结论就变了，而"离线复算 = 在线"是这份格式存在的理由。窗口本身已经是
     * 规模的闸门（帧 600 / 几何 60 帧 / 网络 500 条）。
     *
     * 报文单独一刀：它体积最大（64KB × 200）且隐私最敏感，要显式要。
     *
     * @var list<string>
     */
    public const array DEFAULT_DOMAINS = [
        self::D_NODES,
        self::D_BOXLESS,
        self::D_EVICTED,
        self::D_FRAMES,
        self::D_GEOMETRY,
        self::D_PAINT,
        self::D_NETWORK,
        self::D_SCRIPT,
    ];

    /** @var list<string> */
    public const array ALL_DOMAINS = [
        self::D_NODES,
        self::D_BOXLESS,
        self::D_EVICTED,
        self::D_FRAMES,
        self::D_GEOMETRY,
        self::D_PAINT,
        self::D_NETWORK,
        self::D_ASYNC,
        self::D_BODIES,
        self::D_SCRIPT,
    ];

    private const string K_MANIFEST = 'manifest';
    private const string K_NODE = 'node';
    private const string K_BOXLESS = 'boxless';
    private const string K_EVICTED = 'evicted';
    private const string K_FRAME = 'frame';
    private const string K_GEOMETRY = 'geometry';
    private const string K_PAINT = 'paint';
    private const string K_NETWORK = 'network';
    private const string K_ASYNC = 'async';
    private const string K_BODY = 'body';
    private const string K_SCRIPT = 'script';

    /**
     * 记录类型 ⇒ 所属域 + 必需键。
     *
     * 一张表两列，而不是"kind → domain"与"kind → keys"两张：两张表就会漂，而漂的
     * 后果是某类记录缺键却照样被接受（半份证据）。
     *
     * 必需键只列"缺了会读出错误语义"的那些：`x`（是否 Flex）缺了按 false 走，
     * 影响的是归因归类，不影响能不能读；`id` / `p` 缺了节点表就是错的。
     *
     * @var array<string, array{domain: string, keys: list<string>}>
     */
    private const array RECORDS = [
        self::K_NODE => ['domain' => self::D_NODES, 'keys' => ['id', 'p', 'c']],
        self::K_BOXLESS => ['domain' => self::D_BOXLESS, 'keys' => ['node']],
        self::K_EVICTED => ['domain' => self::D_EVICTED, 'keys' => ['node']],
        self::K_FRAME => ['domain' => self::D_FRAMES, 'keys' => ['id', 't', 'cost', 'rebuilt', 'causes']],
        self::K_GEOMETRY => ['domain' => self::D_GEOMETRY, 'keys' => ['frame', 'node', 'parent']],
        // 绘制一行一帧：一帧的重画集合与命中数是**同一个**事实，拆成多行要么重复
        // 记账（`hits` 只能挂在其中一行），要么让"这个集合属于哪帧"要靠行序推。
        // `truncated` 也算必需：缺了按 false 走，一份被截断的集合会被读成完整集合。
        self::K_PAINT => ['domain' => self::D_PAINT, 'keys' => ['frame', 'painted', 'misses', 'paintedTotal', 'hits', 'truncated']],
        self::K_NETWORK => ['domain' => self::D_NETWORK, 'keys' => ['request', 'method', 'url']],
        self::K_ASYNC => ['domain' => self::D_ASYNC, 'keys' => ['id', 'backend', 'job', 'state']],
        self::K_BODY => ['domain' => self::D_BODIES, 'keys' => ['request']],
        self::K_SCRIPT => ['domain' => self::D_SCRIPT, 'keys' => ['version', 'target', 'viewport']],
    ];

    /**
     * 写一份证据包。
     *
     * @param list<string> $domains 要导出哪些域，值域见 {@see self::ALL_DOMAINS}
     *
     * @return array<string, mixed> 写进首行的 manifest（调用方用它回显指纹）
     *
     * @throws \RuntimeException    写不了文件
     * @throws \InvalidArgumentException 域名字不认识
     */
    public static function write(Recorder $recorder, string $path, array $domains = self::DEFAULT_DOMAINS): array
    {
        // 读语义全在 History（审查面）：本类只管把环写成文件这个动作。
        $history = new History($recorder);
        foreach ($domains as $domain) {
            if (!\in_array($domain, self::ALL_DOMAINS, true)) {
                throw new \InvalidArgumentException('不认识的域：' . \json_encode($domain));
            }
        }

        $handle = @\fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('写不了证据包：' . $path);
        }

        try {
            $manifest = self::manifest($recorder, $domains);
            self::put($handle, $manifest);

            if (\in_array(self::D_NODES, $domains, true)) {
                // 生命周期两端（`f` 出生 / `gone` 离开）是**字段级扩展**，不是新 kind：
                // 旧读者忽略不认识的字段，新读者见到缺失就报"不可回放"，两边都不用改
                // 解码骨架。新增 kind 会让所有旧工具读新包直接报错 —— 那对"加一域"
                // 是对的，对"给已有记录补一个可选字段"就过头了。
                $goneAt = $history->goneNodes();
                foreach ($history->nodeIndex() as $id => $meta) {
                    self::put($handle, [
                        'kind' => self::K_NODE,
                        'id' => (int) $id,
                        'p' => $meta['p'],
                        'c' => $meta['c'],
                        'x' => $meta['x'],
                        's' => (int) ($meta['s'] ?? 0),
                        'f' => (int) ($meta['f'] ?? 0),
                        'gone' => (int) ($goneAt[(int) $id] ?? 0),
                    ]);
                }
            }

            if (\in_array(self::D_BOXLESS, $domains, true)) {
                // 一行一个节点，不做"一行一批"：格式的基本单位是"一行一条记录"，
                // 批量行会让"行数 = 条数"这条规则失效，而计数账正是靠它判截断的。
                foreach ($history->boxlessNodes() as $id) {
                    self::put($handle, ['kind' => self::K_BOXLESS, 'node' => $id]);
                }
            }

            if (\in_array(self::D_EVICTED, $domains, true)) {
                foreach ($history->evictedNodes() as $id => $meta) {
                    self::put($handle, [
                        'kind' => self::K_EVICTED,
                        'node' => (int) $id,
                        'seen' => $meta['s'],
                        'first' => $meta['f'],
                        'gone' => $meta['g'],
                        'p' => $meta['p'],
                        'c' => $meta['c'],
                        'x' => $meta['x'],
                    ]);
                }
            }

            if (\in_array(self::D_SCRIPT, $domains, true)) {
                $script = $history->script();
                if ($script !== null) {
                    self::put($handle, ['kind' => self::K_SCRIPT] + $script->toArray());
                }
            }

            if (\in_array(self::D_FRAMES, $domains, true)) {
                foreach ($history->frames() as $frame) {
                    self::put($handle, ['kind' => self::K_FRAME] + $frame->encode());
                }
            }

            if (\in_array(self::D_GEOMETRY, $domains, true)) {
                foreach ($history->geometryFrames() as $frame) {
                    // 用"该帧新采的"而不是 `geometryRows()`：后者是累积视图（到该帧为止
                    // 每节点最近一次），拿去导出会把前序记录重复写 N 遍。
                    foreach ($history->geometrySamples($frame) as $row) {
                        self::put($handle, ['kind' => self::K_GEOMETRY] + $row->encode());
                    }
                }
            }

            if (\in_array(self::D_PAINT, $domains, true)) {
                foreach ($history->paintFrames() as $frame) {
                    $record = $history->paintFrame($frame);
                    if ($record !== null) {
                        self::put($handle, ['kind' => self::K_PAINT] + $record->encode());
                    }
                }
            }

            if (\in_array(self::D_NETWORK, $domains, true)) {
                foreach ($history->networkRows() as $row) {
                    self::put($handle, ['kind' => self::K_NETWORK] + $row->encode());
                }
            }

            if (\in_array(self::D_ASYNC, $domains, true)) {
                foreach ($history->asyncRows() as $row) {
                    self::put($handle, ['kind' => self::K_ASYNC] + $row->encode());
                }
            }

            if (\in_array(self::D_BODIES, $domains, true)) {
                foreach ($history->bodyRequests() as $requestId) {
                    $body = $history->bodyAt($requestId);
                    if ($body !== null) {
                        self::put($handle, ['kind' => self::K_BODY] + $body->encode());
                    }
                }
            }

        } finally {
            \fclose($handle);
        }

        return $manifest;
    }

    /**
     * 读一份证据包。**任何不自洽都报错，不给出半份。**
     *
     * @throws \InvalidArgumentException 首行不是 manifest、版本 / schema / kind 不认识、
     *                                   缺必需键、帧号乱序、声明的条数与实读不符（截断）
     */
    public static function read(string $path): EvidenceBag
    {
        $handle = @\fopen($path, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException('读不了证据包：' . $path);
        }

        try {
            return self::parse($handle, $path);
        } finally {
            \fclose($handle);
        }
    }

    /**
     * @param resource $handle
     */
    private static function parse($handle, string $path): EvidenceBag
    {
        /** @var array{domains: list<string>, counts: array<string, int>, options: array<string, mixed>}|null $manifest */
        $manifest = null;
        /** @var array<string, int> $seen */
        $seen = [];
        /** @var array<int, array{p: int, c: string, x: bool, s: int, f: int}> $nodes */
        $nodes = [];
        /** @var array<int, int> $gone 节点 ⇒ 离开树的那一帧；缺省表示"没记录到" */
        $gone = [];
        /** @var list<FrameRecord> $frames */
        $frames = [];
        /** @var array<int, array<int, GeometryRecord>> $geometry */
        $geometry = [];
        /** @var array<int, PaintRecord> $paint */
        $paint = [];
        /** @var array<int, NetworkRecord> $network */
        $network = [];
        /** @var array<int, AsyncRecord> $async */
        $async = [];
        /** @var array<int, NetworkBodyRecord> $bodies */
        $bodies = [];
        /** @var array<int, true> $boxless */
        $boxless = [];
        /** @var array<int, array{f: int, s: int, g: int}|int> $evicted */
        $evicted = [];
        $script = null;
        $lineNo = 0;
        $lastFrame = 0;

        while (($line = \fgets($handle)) !== false) {
            ++$lineNo;
            $line = \rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }

            try {
                $raw = Json::decode($line);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException("{$path} 第 {$lineNo} 行：{$e->getMessage()}", 0, $e);
            }

            $kind = (string) ($raw['kind'] ?? '');

            if ($manifest === null) {
                if ($kind !== self::K_MANIFEST) {
                    throw new \InvalidArgumentException(
                        "{$path} 第 {$lineNo} 行：首行必须是 manifest，实际是 {$kind}",
                    );
                }
                $manifest = self::acceptManifest($raw, $path);
                continue;
            }

            if ($kind === self::K_MANIFEST) {
                throw new \InvalidArgumentException("{$path} 第 {$lineNo} 行：manifest 只能有一行");
            }

            $record = self::RECORDS[$kind] ?? null;
            if ($record === null) {
                // 未知 kind 必须报错。跳过它等于交出一份少一域却不自知的证据，
                // 而读者无法分辨"这份证据没有绘制域"和"这个版本不写绘制域"。
                throw new \InvalidArgumentException(
                    "{$path} 第 {$lineNo} 行：不认识的记录类型 {$kind}"
                    . '（本工具认识：' . \implode(' / ', \array_keys(self::RECORDS)) . '）',
                );
            }
            if (!\in_array($record['domain'], $manifest['domains'], true)) {
                throw new \InvalidArgumentException(
                    "{$path} 第 {$lineNo} 行：记录 {$kind} 属于域 {$record['domain']}，但 manifest 没有声明它",
                );
            }
            foreach ($record['keys'] as $key) {
                if (!\array_key_exists($key, $raw)) {
                    throw new \InvalidArgumentException("{$path} 第 {$lineNo} 行：记录 {$kind} 缺少必需字段 {$key}");
                }
            }

            // 各 case 用独立变量名：tpc 不按 case 分作用域，复用 $one 会被判成跨类型重赋值。
            switch ($kind) {
                case self::K_NODE:
                    $nodes[(int) $raw['id']] = [
                        'p' => (int) $raw['p'],
                        'c' => (string) $raw['c'],
                        'x' => (bool) ($raw['x'] ?? false),
                        's' => (int) ($raw['s'] ?? 0),
                        // 缺 `f` 的旧包不补一个猜测值：`0` 表示"出生帧未知"，由查询层
                        // 报"这一帧不可回放"。拿 `s` 顶上会让静止节点只在最后一帧存在。
                        'f' => (int) ($raw['f'] ?? 0),
                    ];
                    if ((int) ($raw['gone'] ?? 0) > 0) {
                        $gone[(int) $raw['id']] = (int) $raw['gone'];
                    }
                    break;

                case self::K_BOXLESS:
                    $boxless[(int) $raw['node']] = true;
                    break;

                case self::K_EVICTED:
                    $evicted[(int) $raw['node']] = [
                        'p' => (int) ($raw['p'] ?? -2),
                        'c' => (string) ($raw['c'] ?? ''),
                        'x' => (bool) ($raw['x'] ?? false),
                        'f' => (int) ($raw['first'] ?? $raw['seen'] ?? 0),
                        's' => (int) ($raw['seen'] ?? 0),
                        'g' => (int) ($raw['gone'] ?? 0),
                    ];
                    break;

                case self::K_SCRIPT:
                    $script = Script::fromArray($raw);
                    break;

                case self::K_FRAME:
                    $frame = FrameRecord::decode($raw);
                    // 帧号必须严格升序：因果依赖上一帧，乱序的包已经不可信。
                    if ($frame->id <= $lastFrame) {
                        throw new \InvalidArgumentException(
                            "{$path} 第 {$lineNo} 行：帧号 {$frame->id} 没有严格大于上一帧 {$lastFrame}",
                        );
                    }
                    $lastFrame = $frame->id;
                    $frames[] = $frame;
                    break;

                case self::K_GEOMETRY:
                    $row = GeometryRecord::decode($raw);
                    $geometry[$row->frame][$row->node] = $row;
                    break;

                case self::K_PAINT:
                    $paintRow = PaintRecord::decode($raw);
                    // 同帧两条 = 包里自相矛盾（后一条会静默盖掉前一条，而 counts 照样对）。
                    if (isset($paint[$paintRow->frame])) {
                        throw new \InvalidArgumentException(
                            "{$path} 第 {$lineNo} 行：帧 {$paintRow->frame} 的绘制记录重复",
                        );
                    }
                    $paint[$paintRow->frame] = $paintRow;
                    break;

                case self::K_NETWORK:
                    $networkRow = NetworkRecord::decode($raw);
                    $network[$networkRow->requestId] = $networkRow;
                    break;

                case self::K_ASYNC:
                    $asyncRow = AsyncRecord::decode($raw);
                    $async[$asyncRow->id] = $asyncRow;
                    break;

                case self::K_BODY:
                    $bodyRow = NetworkBodyRecord::decode($raw);
                    $bodies[$bodyRow->requestId] = $bodyRow;
                    break;
            }

            $seen[$kind] = ($seen[$kind] ?? 0) + 1;
        }

        if ($manifest === null) {
            throw new \InvalidArgumentException("{$path}：空文件，没有 manifest");
        }

        // 条数账：把"写入路径漏了一段"与"文件被截断"变成可检的。声明 41 帧、实读 40 行，
        // 两种原因都会到这里，而它们都意味着"这份证据不完整"，不能当完整的用。
        foreach ($manifest['counts'] as $kind => $expected) {
            $actual = $seen[$kind] ?? 0;
            if ($actual !== $expected) {
                throw new \InvalidArgumentException(
                    "{$path}：manifest 声明 {$kind} 有 {$expected} 条，实际读到 {$actual} 条（文件被截断？）",
                );
            }
        }
        foreach ($seen as $kind => $actual) {
            if (!\array_key_exists($kind, $manifest['counts'])) {
                throw new \InvalidArgumentException(
                    "{$path}：读到 {$actual} 条 {$kind}，但 manifest 的 counts 没有列它",
                );
            }
        }

        return new EvidenceBag(
            nodes: $nodes,
            frames: $frames,
            geometry: $geometry,
            paint: $paint,
            network: $network,
            async: $async,
            bodies: $bodies,
            boxless: $boxless,
            evicted: $evicted,
            gone: $gone,
            script: $script,
            options: RecordOptions::fromManifest($manifest['options']),
            domains: $manifest['domains'],
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{domains: list<string>, counts: array<string, int>, options: array<string, mixed>}
     */
    private static function acceptManifest(array $raw, string $path): array
    {
        if (($raw['schema'] ?? '') !== self::SCHEMA) {
            throw new \InvalidArgumentException(
                "{$path}：不认识的 schema " . \json_encode($raw['schema'] ?? null)
                . '（本工具只读 ' . self::SCHEMA . '）',
            );
        }
        if (($raw['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException(
                "{$path}：证据包版本不认识 " . \json_encode($raw['version'] ?? null)
                . '（本工具只读 ' . self::VERSION . '）',
            );
        }

        $domains = $raw['domains'] ?? null;
        if (!\is_array($domains) || !\array_is_list($domains)) {
            throw new \InvalidArgumentException("{$path}：manifest.domains 必须是字符串列表");
        }
        foreach ($domains as $domain) {
            if (!\in_array($domain, self::ALL_DOMAINS, true)) {
                throw new \InvalidArgumentException(
                    "{$path}：manifest 声明了不认识的域 " . \json_encode($domain),
                );
            }
        }

        $counts = $raw['counts'] ?? null;
        if (!\is_array($counts)) {
            throw new \InvalidArgumentException("{$path}：manifest.counts 必须是映射");
        }
        $clean = [];
        foreach ($counts as $kind => $value) {
            $clean[(string) $kind] = (int) $value;
        }

        $optionsRaw = $raw['options'] ?? null;
        if (!\is_array($optionsRaw)) {
            // 老包没有 options：按声明的域反推，不把"域不在包里"读成"采了但为空"。
            $optionsRaw = [
                'capacity' => $raw['capacity'] ?? Recorder::DEFAULT_CAPACITY,
                'redact' => $raw['redact'] ?? true,
                'geometry' => \in_array(self::D_GEOMETRY, $domains, true)
                    ? GeometryCapture::Changed->value
                    : GeometryCapture::Off->value,
                'network' => \in_array(self::D_NETWORK, $domains, true),
                'bodies' => \in_array(self::D_BODIES, $domains, true),
                'paint' => \in_array(self::D_PAINT, $domains, true),
                'async' => \in_array(self::D_ASYNC, $domains, true),
            ];
        }

        /** @var list<string> $domains */
        return [
            'domains' => $domains,
            'counts' => $clean,
            'options' => RecordOptions::fromManifest($optionsRaw)->toManifest(),
        ];
    }

    /**
     * @param list<string> $domains
     * @return array<string, mixed>
     */
    private static function manifest(Recorder $recorder, array $domains): array
    {
        // 读语义全在 History（审查面）：本类只管把环写成文件这个动作。
        $history = new History($recorder);
        $open = \array_flip($domains);
        $counts = [];

        // counts 与写入取自同一份来源（Recorder 的访问器），所以"写漏一段"会让
        // 读取时的条数账立刻红 —— 那正是这张账的用处。
        $plan = [
            self::K_NODE => [self::D_NODES, \count($history->nodeIndex())],
            self::K_BOXLESS => [self::D_BOXLESS, \count($history->boxlessNodes())],
            self::K_EVICTED => [self::D_EVICTED, \count($history->evictedNodes())],
            self::K_SCRIPT => [self::D_SCRIPT, $history->script() === null ? 0 : 1],
            self::K_FRAME => [self::D_FRAMES, \count($history->frames())],
            self::K_GEOMETRY => [self::D_GEOMETRY, $history->geometryCoverage()['nodes']],
            self::K_PAINT => [self::D_PAINT, \count($history->paintFrames())],
            self::K_NETWORK => [self::D_NETWORK, \count($history->networkRows())],
            self::K_ASYNC => [self::D_ASYNC, \count($history->asyncRows())],
            self::K_BODY => [self::D_BODIES, \count($history->bodyRequests())],
        ];
        foreach ($plan as $kind => [$domain, $count]) {
            if (isset($open[$domain])) {
                $counts[$kind] = $count;
            }
        }

        $facts = Digest::of($recorder);

        return [
            'kind' => self::K_MANIFEST,
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'domains' => $domains,
            'counts' => $counts,
            'options' => $history->options()->toManifest(),
            'digests' => [
                'drive' => $history->script()?->digest() ?? '',
                'facts' => $facts['digest'],
                'external' => Digest::ofExternal($recorder),
            ],
        ];
    }

    /**
     * @param resource             $handle
     * @param array<string, mixed> $record
     */
    private static function put($handle, array $record): void
    {
        if (\fwrite($handle, Json::encode($record) . "\n") === false) {
            throw new \RuntimeException('写证据包失败（磁盘满或句柄已关？）');
        }
    }
}
