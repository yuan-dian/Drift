<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

use Drift\Debug\Json;

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
    public const string D_FRAMES = 'frames';
    public const string D_GEOMETRY = 'geometry';
    public const string D_NETWORK = 'network';
    public const string D_BODIES = 'bodies';
    public const string D_PROPS = 'props';
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
        self::D_FRAMES,
        self::D_GEOMETRY,
        self::D_NETWORK,
        self::D_PROPS,
        self::D_SCRIPT,
    ];

    /** @var list<string> */
    public const array ALL_DOMAINS = [
        self::D_NODES,
        self::D_BOXLESS,
        self::D_FRAMES,
        self::D_GEOMETRY,
        self::D_NETWORK,
        self::D_BODIES,
        self::D_PROPS,
        self::D_SCRIPT,
    ];

    private const string K_MANIFEST = 'manifest';
    private const string K_NODE = 'node';
    private const string K_BOXLESS = 'boxless';
    private const string K_FRAME = 'frame';
    private const string K_GEOMETRY = 'geometry';
    private const string K_NETWORK = 'network';
    private const string K_BODY = 'body';
    private const string K_PROP = 'prop';
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
        self::K_FRAME => ['domain' => self::D_FRAMES, 'keys' => ['id', 't', 'cost', 'rebuilt', 'causes']],
        self::K_GEOMETRY => ['domain' => self::D_GEOMETRY, 'keys' => ['frame', 'node', 'parent']],
        self::K_NETWORK => ['domain' => self::D_NETWORK, 'keys' => ['request', 'method', 'url']],
        self::K_BODY => ['domain' => self::D_BODIES, 'keys' => ['request']],
        self::K_PROP => ['domain' => self::D_PROPS, 'keys' => ['node', 'widget']],
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
                foreach ($recorder->nodeIndex() as $id => $meta) {
                    self::put($handle, [
                        'kind' => self::K_NODE,
                        'id' => (int) $id,
                        'p' => $meta['p'],
                        'c' => $meta['c'],
                        'x' => $meta['x'],
                    ]);
                }
            }

            if (\in_array(self::D_BOXLESS, $domains, true)) {
                // 一行一个节点，不做"一行一批"：格式的基本单位是"一行一条记录"，
                // 批量行会让"行数 = 条数"这条规则失效，而计数账正是靠它判截断的。
                foreach ($recorder->boxlessNodes() as $id) {
                    self::put($handle, ['kind' => self::K_BOXLESS, 'node' => $id]);
                }
            }

            if (\in_array(self::D_SCRIPT, $domains, true)) {
                $script = $recorder->script();
                if ($script !== null) {
                    self::put($handle, ['kind' => self::K_SCRIPT] + $script->toArray());
                }
            }

            if (\in_array(self::D_FRAMES, $domains, true)) {
                foreach ($recorder->frames() as $frame) {
                    self::put($handle, ['kind' => self::K_FRAME] + $frame->encode());
                }
            }

            if (\in_array(self::D_GEOMETRY, $domains, true)) {
                foreach ($recorder->geometryFrames() as $frame) {
                    // 用"该帧新采的"而不是 `geometryRows()`：后者是累积视图（到该帧为止
                    // 每节点最近一次），拿去导出会把前序记录重复写 N 遍。
                    foreach ($recorder->geometrySamples($frame) as $row) {
                        self::put($handle, ['kind' => self::K_GEOMETRY] + $row->encode());
                    }
                }
            }

            if (\in_array(self::D_NETWORK, $domains, true)) {
                foreach ($recorder->networkRows() as $row) {
                    self::put($handle, ['kind' => self::K_NETWORK] + $row->encode());
                }
            }

            if (\in_array(self::D_BODIES, $domains, true)) {
                foreach ($recorder->bodyRequests() as $requestId) {
                    $body = $recorder->bodyAt($requestId);
                    if ($body !== null) {
                        self::put($handle, ['kind' => self::K_BODY] + $body->encode());
                    }
                }
            }

            if (\in_array(self::D_PROPS, $domains, true)) {
                foreach ($recorder->propNodes() as $node) {
                    $snapshot = $recorder->propAt($node);
                    if ($snapshot !== null) {
                        self::put($handle, ['kind' => self::K_PROP] + $snapshot->encode());
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
        /** @var array{domains: list<string>, counts: array<string, int>, capacity: int, redact: bool}|null $manifest */
        $manifest = null;
        /** @var array<string, int> $seen */
        $seen = [];
        /** @var array<int, array{p: int, c: string, x: bool}> $nodes */
        $nodes = [];
        /** @var list<FrameRecord> $frames */
        $frames = [];
        /** @var array<int, array<int, GeometryRecord>> $geometry */
        $geometry = [];
        /** @var array<int, NetworkRecord> $network */
        $network = [];
        /** @var array<int, NetworkBodyRecord> $bodies */
        $bodies = [];
        /** @var array<int, PropSnapshot> $props */
        $props = [];
        /** @var array<int, true> $boxless */
        $boxless = [];
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

            switch ($kind) {
                case self::K_NODE:
                    $nodes[(int) $raw['id']] = [
                        'p' => (int) $raw['p'],
                        'c' => (string) $raw['c'],
                        'x' => (bool) ($raw['x'] ?? false),
                    ];
                    break;

                case self::K_BOXLESS:
                    $boxless[(int) $raw['node']] = true;
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

                case self::K_NETWORK:
                    $one = NetworkRecord::decode($raw);
                    $network[$one->requestId] = $one;
                    break;

                case self::K_BODY:
                    $one = NetworkBodyRecord::decode($raw);
                    $bodies[$one->requestId] = $one;
                    break;

                case self::K_PROP:
                    $one = PropSnapshot::decode($raw);
                    $props[$one->node] = $one;
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
            network: $network,
            bodies: $bodies,
            props: $props,
            boxless: $boxless,
            script: $script,
            capacity: $manifest['capacity'],
            redactBodies: $manifest['redact'],
            domains: $manifest['domains'],
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{domains: list<string>, counts: array<string, int>, capacity: int, redact: bool}
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

        /** @var list<string> $domains */
        return [
            'domains' => $domains,
            'counts' => $clean,
            'capacity' => (int) ($raw['capacity'] ?? Recorder::DEFAULT_CAPACITY),
            'redact' => (bool) ($raw['redact'] ?? true),
        ];
    }

    /**
     * @param list<string> $domains
     * @return array<string, mixed>
     */
    private static function manifest(Recorder $recorder, array $domains): array
    {
        $open = \array_flip($domains);
        $counts = [];

        // counts 与写入取自同一份来源（Recorder 的访问器），所以"写漏一段"会让
        // 读取时的条数账立刻红 —— 那正是这张账的用处。
        $plan = [
            self::K_NODE => [self::D_NODES, \count($recorder->nodeIndex())],
            self::K_BOXLESS => [self::D_BOXLESS, \count($recorder->boxlessNodes())],
            self::K_SCRIPT => [self::D_SCRIPT, $recorder->script() === null ? 0 : 1],
            self::K_FRAME => [self::D_FRAMES, \count($recorder->frames())],
            self::K_GEOMETRY => [self::D_GEOMETRY, $recorder->geometryCoverage()['nodes']],
            self::K_NETWORK => [self::D_NETWORK, \count($recorder->networkRows())],
            self::K_BODY => [self::D_BODIES, \count($recorder->bodyRequests())],
            self::K_PROP => [self::D_PROPS, \count($recorder->propNodes())],
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
            'capacity' => $recorder->stats()['capacity'],
            'redact' => $recorder->redactionEnabled(),
            'digests' => [
                'drive' => $recorder->script()?->digest() ?? '',
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
