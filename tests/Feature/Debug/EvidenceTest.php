<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\Debug\Json;
use Drift\Debug\Session\Digest;
use Drift\Debug\Session\Edge;
use Drift\Debug\Session\Evidence;
use Drift\Debug\Session\EvidenceBag;
use Drift\Debug\Session\FrameRecord;
use Drift\Debug\Session\GeometryRecord;
use Drift\Debug\Session\NetworkBodyRecord;
use Drift\Debug\Session\NetworkRecord;
use Drift\Debug\Session\PropSnapshot;
use Drift\Debug\Session\Query;
use Drift\Debug\Session\QueryRouter;
use Drift\Debug\Session\Recorder;
use Drift\Debug\Session\Script;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 证据包判据：一份记录离开产生它的进程之后，还是不是同一份证据。
 *
 * ## 钉什么
 *
 * - **往返逐值相同**：不只是 PHP 数组层面，要过一遍真实 JSON —— `16.0` 写成 `16`
 *   读回 int 这类错只在编码层显形；
 * - **导出 → 导入 → 再导出，字节相同**：字段顺序不稳、派生量被写进包，都会破这条；
 * - **导入后查询逐值相同**：这是"证据可携带"的最终检验。前面两条只证明"字节没变"，
 *   这条证明"读出来的结论没变"；
 * - **三指纹各管一段**：改驱动只红 `drive`、改外部只红 `external`、改因果只红 `facts`。
 *   三条一旦重叠，两份证据不同时分不清该改脚本还是改 mock，诊断价值归零；
 * - **坏包一律报错**：版本不认识、未知记录类型、缺必需字段、声明条数与实读不符、
 *   帧号乱序。全部"报错"而不是"尽力读"——半份证据的读者无法分辨"没有"与"没写"；
 * - **读的过程不复制整包**：守的是 `fgets` 而不是先读完整串再切（见该用例注释）。
 */
final class EvidenceTest extends SmokeTestCase
{
    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(200.0, 120.0));
    }

    private function boot(): Application
    {
        $app = new EvidenceApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());

        return $app;
    }

    /** 过一遍真实 JSON：类型漂移只在这一层显形。 */
    private static function throughJson(array $raw): array
    {
        return Json::decode(Json::encode($raw));
    }

    private static function tempFile(string $suffix): string
    {
        $path = \sys_get_temp_dir() . '/drift-evidence-' . \bin2hex(\random_bytes(6)) . $suffix;
        \touch($path);

        return $path;
    }

    /**
     * 六种记录 + 脚本，过一遍 JSON 再回来，逐值相同。
     *
     * 比"对象相等"更强的写法：比 `encode()` 的输出。对象相等会被 float/int 的宽松
     * 比较骗过去（`==` 判 `16` 与 `16.0` 相等），而证据包的判据恰恰是严格的。
     */
    public function testEveryRecordSurvivesJsonRoundTrip(): void
    {
        $edge = new Edge('state', 3, 7, 'src/App/Foo.php:12', 'CounterState');
        check(
            '边往返（from / site 都在）',
            Edge::decode(self::throughJson($edge->encode()))->encode() === $edge->encode(),
        );

        // 关键位：`stageNs` 是整数纳秒（`toArray()` 会给四位小数的毫秒，那是投影），
        // `t = 16.0` 是整数值的浮点（不带 PRESERVE_ZERO_FRACTION 就会变 int）。
        $frame = new FrameRecord(
            2,
            16.0,
            3.5,
            [$edge],
            [7, 9],
            ['settle', 'layout'],
            ['settle' => 1234567, 'layout' => 800],
            false,
            true,
            [FrameRecord::ABORTED],
        );
        $frameBack = FrameRecord::decode(self::throughJson($frame->encode()));
        check('帧往返（纳秒与 incomplete 都在）', $frameBack->encode() === $frame->encode());
        check('帧时间仍是浮点', \is_float($frameBack->t), \get_debug_type($frameBack->t));
        check('纳秒精度没丢', $frameBack->stageNs === ['settle' => 1234567, 'layout' => 800]);

        // `INF` 必须保持字符串：转成 float 就变 0，"父允许任意尺寸"成了"父要求 0 宽"。
        $geometry = new GeometryRecord(
            1, 11, 2,
            ['minW' => 0.0, 'maxW' => 'INF', 'minH' => 0.0, 'maxH' => 'INF'],
            ['minW' => 240.0, 'maxW' => 240.0, 'minH' => 0.0, 'maxH' => 100.0],
            ['w' => 240.0, 'h' => 100.0],
            ['x' => 12.5, 'y' => 0.0],
            ['l' => 1.0, 't' => 2.0, 'r' => 3.0, 'b' => 4.0],
            ['l' => 5.0, 't' => 6.0, 'r' => 7.0, 'b' => 8.0],
            ['w' => null, 'h' => 40.0],
            1.5,
            ['x' => 0.5, 'y' => 0.5],
            true,
            2.0,
            ['grow' => 1, 'shrink' => 0],
        );
        $geometryBack = GeometryRecord::decode(self::throughJson($geometry->encode()));
        check('几何往返', $geometryBack->encode() === $geometry->encode());
        check('无界约束仍是 INF', $geometryBack->inner['maxW'] === 'INF' || $geometryBack->applied['maxW'] === 'INF');

        $network = new NetworkRecord(
            3, 'GET', 'https://api.example.com/items?page=1', 'api.example.com', '/items',
            4, 9, 200, 1024, 12.5, 'TableState', true, [11, 12], '0.5',
        );
        check(
            '网络往返',
            NetworkRecord::decode(self::throughJson($network->encode()))->encode() === $network->encode(),
        );

        $script = new Script('table', 800.0, 600.0, ['rows' => 6]);
        check(
            '脚本往返',
            Script::fromArray(self::throughJson(['kind' => 'script'] + $script->toArray()))->toArray() === $script->toArray(),
        );

        // 报文：`encode()` 不能走 `select()`（那会按查询预算再截 64KB），否则导出的是片段。
        $longBody = \str_repeat('x', 200);
        $body = new NetworkBodyRecord(
            3,
            '{"a":1}',
            ['bytes' => 7, 'truncated' => false, 'redacted' => 0, 'redact' => true, 'format' => 'json'],
            $longBody,
            ['bytes' => 200, 'truncated' => false, 'redacted' => 0, 'redact' => true, 'format' => 'text'],
        );
        $bodyBack = NetworkBodyRecord::decode(self::throughJson($body->encode()));
        check('报文往返（原样，不按查询预算再截）', $bodyBack->responseBody === $longBody);
        check('报文 meta 往返（redact 位也在）', $bodyBack->response === $body->response);

        // ★ 唯一真正依赖 `JSON_PRESERVE_ZERO_FRACTION` 的地方：`fields` 是"带 tag 的
        //   自由结构"，decode 只能原样保留、没法逐字段强转。所以 `24.0` 一旦编成 `24`，
        //   往返后就变成 int —— 而"值是 24.0 的浮点"与"值是 24 的整数"在面板上是
        //   两种显示，在配置比对里是两回事。
        //
        //   其它记录的 float 字段都有 `(float)` 兜底，所以它们**测不出**这个标志的作用。
        $snapshot = new PropSnapshot(
            7,
            2,
            'Table',
            'StatefulElement',
            'TableState',
            [
                ['name' => 'rows', 'owner' => 'TableState', 'value' => ['t' => 'float', 'v' => 24.0]],
                ['name' => 'ratio', 'owner' => 'TableState', 'value' => ['t' => 'float', 'v' => 0.5]],
                ['name' => 'tags', 'owner' => 'TableState', 'value' => ['t' => 'array', 'n' => 3]],
            ],
            0,
            0,
            0,
            2,
            true,
            0.75,
        );
        $snapshotBack = PropSnapshot::decode(self::throughJson($snapshot->encode()));
        check('属性快照往返', $snapshotBack->encode() === $snapshot->encode());
        check(
            '自由结构里的浮点没被编成整数',
            $snapshotBack->fields[0]['value']['v'] === 24.0,
            \get_debug_type($snapshotBack->fields[0]['value']['v']),
        );
    }

    /**
     * 导出 → 导入 → 再导出：两份文件逐字节相同。
     *
     * 防两件事：字段顺序不稳（同一个对象两次编码不同），以及派生量被写进包
     * （`pendingFrames` / `unattributedMs` 这类，改了源头忘了改派生，包就自相矛盾）。
     */
    public function testWholePackageIsByteStableAcrossExportImportExport(): void
    {
        $app = $this->boot();
        $recorder = Recorder::install();

        $first = self::tempFile('.evidence');
        $second = self::tempFile('.evidence');

        try {
            $app->frame(0.0, $this->constraints());
            EvidenceState::$last?->bump();
            $app->frame(16.0, $this->constraints());
            EvidenceState::$last?->bump();
            $app->frame(32.0, $this->constraints());

            $manifest = Evidence::write($recorder, $first);
            check('manifest 声明了帧数', ($manifest['counts']['frame'] ?? 0) === 3, \json_encode($manifest['counts'] ?? []));
            check('三指纹都在', \array_keys($manifest['digests'] ?? []) === ['drive', 'facts', 'external'], \json_encode($manifest['digests'] ?? []));

            $rehydrated = Recorder::hydrate(Evidence::read($first));
            Evidence::write($rehydrated, $second);

            $a = (string) \file_get_contents($first);
            $b = (string) \file_get_contents($second);
            check('两份文件逐字节相同', $a === $b, 'a=' . \strlen($a) . 'B b=' . \strlen($b) . 'B');
            check('首行是 manifest', \str_starts_with($a, '{"kind":"manifest"'), \substr($a, 0, 40));
        } finally {
            Recorder::uninstall();
            @\unlink($first);
            @\unlink($second);
        }
    }

    /**
     * 导入后**每个查询的答案**与在线时逐值相同。
     *
     * 前两条只证明"字节没变"，这条证明"读出来的结论没变"。`digest` 那一项其实是
     * 全家桶：它把节点表与逐帧因果一起哈希了，一项相同代表整片事实相同。
     */
    public function testImportedRecorderAnswersTheSameQueries(): void
    {
        $app = $this->boot();
        $recorder = Recorder::install();
        $path = self::tempFile('.evidence');

        try {
            $app->frame(0.0, $this->constraints());
            EvidenceState::$last?->bump();
            $app->frame(16.0, $this->constraints());
            $app->frame(32.0, $this->constraints());

            Evidence::write($recorder, $path);
            $offline = Recorder::hydrate(Evidence::read($path));

            check('指纹逐值相同', Digest::of($recorder) === Digest::of($offline), '两边 digest 不同');
            // 对象不能用 `===`（那是同一实例），比 encode 的输出才是逐值。
            check(
                '帧表相同',
                \array_map(static fn(FrameRecord $f): array => $f->encode(), \array_values($recorder->frames()))
                === \array_map(static fn(FrameRecord $f): array => $f->encode(), \array_values($offline->frames())),
            );
            check('节点表相同', $recorder->nodeIndex() === $offline->nodeIndex());
            check('几何相同', $recorder->geometryFrames() === $offline->geometryFrames());

            $online = new Query($recorder);
            $remote = new Query($offline);

            check('stats 相同', $online->stats() === $remote->stats());
            check('frames 相同', $online->frames(0) === $remote->frames(0));
            check('explain 相同', $online->explain(2) === $remote->explain(2));
            check('cascade 相同', $online->cascade(2) === $remote->cascade(2));
            check('hotspots 相同', $online->hotspots(0, 0) === $remote->hotspots(0, 0));
            check('nodes 相同', $online->nodes(0, 0) === $remote->nodes(0, 0));
            check('boxes 相同', $online->boxes(2) === $remote->boxes(2));

            $node = (int) ($offline->frames()[2]->rebuilt[0] ?? 0);
            if ($node > 0) {
                check('why 相同', $online->why($node, 2) === $remote->why($node, 2));
                check('boxModel 相同', $online->boxModel($node, 2) === $remote->boxModel($node, 2));
                check('constraints 相同', $online->constraints($node, 2) === $remote->constraints($node, 2));
            }
        } finally {
            Recorder::uninstall();
            @\unlink($path);
        }
    }

    /**
     * 三指纹各管一段：改一处只红一条。
     *
     * 用 `hydrate` 直接造两份只差一处的证据，比跑两次会话更精确 —— 跑会话时
     * 引擎的浮点、时序都可能插一脚，"只差一处"变成了"差三处"。
     */
    public function testThreeDigestsCoverDisjointInputs(): void
    {
        $nodes = [1 => ['p' => -1, 'c' => 'PageShell', 'x' => false]];
        $frames = [new FrameRecord(1, 0.0, 1.0, [], [], ['settle'], ['settle' => 1], false, false)];

        $base = new EvidenceBag(
            nodes: $nodes,
            frames: $frames,
            script: new Script('table', 800.0, 600.0),
        );
        $sameFactsOtherTarget = new EvidenceBag(
            nodes: $nodes,
            frames: $frames,
            script: new Script('form', 800.0, 600.0),
        );

        $a = Recorder::hydrate($base);
        $b = Recorder::hydrate($sameFactsOtherTarget);
        check(
            '换目标：因果指纹不变',
            Digest::of($a)['digest'] === Digest::of($b)['digest'],
            'facts 不该因为目标变了就红',
        );
        check(
            '换目标：驱动指纹变',
            $base->script?->digest() !== $sameFactsOtherTarget->script?->digest(),
        );

        $otherFacts = new EvidenceBag(
            nodes: $nodes,
            frames: [new FrameRecord(1, 0.0, 1.0, [], [1], ['settle'], ['settle' => 1], false, false)],
            script: new Script('table', 800.0, 600.0),
        );
        check(
            '改因果：指纹变（驱动相同）',
            Digest::of(Recorder::hydrate($otherFacts))['digest'] !== Digest::of($a)['digest']
            && $otherFacts->script?->digest() === $base->script?->digest(),
        );
    }

    /**
     * 外部指纹只看"给了什么"：URL 的签名参数与数字段不算变化，`status` 算。
     *
     * 不含 query 是因为 `?sig=…&ts=…` 每跑一次都不同，带上它这条指纹就**恒红**；
     * 不含 `elapsedMs` / 帧号是因为那是"多久回来"，属于这一次运行的运气。
     */
    public function testExternalDigestIgnoresTimingAndSignatureButKeepsStatus(): void
    {
        $record = static fn(string $url, int $status, float $elapsed, int $settled): NetworkRecord => new NetworkRecord(
            1, 'GET', $url, 'api.example.com', '/items', 1, $settled, $status, 10, $elapsed, 'Table', true,
        );

        $digestOf = static function (NetworkRecord $one): string {
            return Digest::ofExternal(Recorder::hydrate(new EvidenceBag(network: [1 => $one])));
        };

        $plain = $digestOf($record('https://api.example.com/items', 200, 12.0, 4));
        check(
            '签名参数与非数字 query 不算变化',
            $digestOf($record('https://api.example.com/items?sig=abc&ts=1', 200, 12.0, 4)) === $plain,
        );
        check(
            '数字路径段收成 :n',
            $digestOf($record('https://api.example.com/items/123', 200, 12.0, 4))
            === $digestOf($record('https://api.example.com/items/456', 200, 12.0, 4)),
        );
        check('耗时与落地帧不算变化', $digestOf($record('https://api.example.com/items', 200, 99.0, 40)) === $plain);
        check('status 变了要红', $digestOf($record('https://api.example.com/items', 500, 12.0, 4)) !== $plain);
        check('host 变了要红', $digestOf($record('https://other.example.com/items', 200, 12.0, 4)) !== $plain);
    }

    /** 默认包不带报文：它体积最大且隐私最敏感，要显式要。 */
    public function testBodiesAreExcludedByDefault(): void
    {
        $app = $this->boot();
        $recorder = Recorder::install();
        $path = self::tempFile('.evidence');

        try {
            $app->frame(0.0, $this->constraints());
            $manifest = Evidence::write($recorder, $path);

            check(
                '默认不声明报文域',
                !\in_array(Evidence::D_BODIES, $manifest['domains'], true),
                \json_encode($manifest['domains']),
            );
            check('也就没有 body 记录', !\array_key_exists('body', $manifest['counts']), \json_encode($manifest['counts']));

            $all = Evidence::write($recorder, $path, Evidence::ALL_DOMAINS);
            check('显式要才带上', \in_array(Evidence::D_BODIES, $all['domains'], true));
        } finally {
            Recorder::uninstall();
            @\unlink($path);
        }
    }

    /**
     * 判据 7：读包的全过程不存在"整包文本副本"。
     *
     * 这条判据的第一版写的是"峰值增幅 < 包大小的 3 倍"，实测立刻推翻：14560 个节点 /
     * 0.79 MB 的包，逐行读是 7.74 倍，整包 `json_decode` 是 12.97 倍。那个倍数不是
     * 实现的错——`EvidenceBag` 必须全量驻留，而 PHP 对象本来就比 JSON 文本贵约 8 倍。
     * 写成绝对倍率只会逼人把判据删掉。
     *
     * 真正该守的是**实现方式**：`fgets` 逐行，还是先 `file_get_contents` 再 `explode`。
     * 两者差的恰好是一份完整文本副本，所以看 `峰值 - 驻留`：
     *
     *   - 逐行：峰值 ≈ 驻留 + 一行的大小（几百字节）
     *   - 整包再切分：峰值 ≥ 驻留 + 包大小（整份文本还在内存里）
     *
     * 阈值取包大小的一半，两侧余量都在一个数量级上，不依赖 PHP 版本的对象尺寸。
     * 行数多、每行小才测得出——换成少量超长行，逐行读自己的行缓冲就等于整包了。
     */
    public function testReadingDoesNotHoldAWholeCopyOfThePackage(): void
    {
        $path = self::tempFile('.evidence');
        $rows = 20000;

        try {
            $handle = \fopen($path, 'wb');
            \fwrite($handle, Json::encode([
                'kind' => 'manifest',
                'schema' => Evidence::SCHEMA,
                'version' => Evidence::VERSION,
                'domains' => [Evidence::D_NODES],
                'counts' => ['node' => $rows],
                'capacity' => 600,
                'redact' => true,
            ]) . "\n");
            for ($id = 1; $id <= $rows; $id++) {
                \fwrite($handle, '{"kind":"node","id":' . $id . ',"p":-1,"c":"Row' . $id . '","x":false}' . "\n");
            }
            \fclose($handle);

            \gc_collect_cycles();
            $before = \memory_get_usage();
            \memory_reset_peak_usage();

            $bag = Evidence::read($path);

            $peak = \memory_get_peak_usage() - $before;
            $resident = \memory_get_usage() - $before;
            $size = \filesize($path);

            check('包读得出来（否则下面测的是失败路径）', \count($bag->nodes) === $rows, (string) \count($bag->nodes));
            check(
                '峰值溢出的部分远小于整包大小',
                ($peak - $resident) < $size / 2,
                \sprintf(
                    '峰值 %.2f MB / 驻留 %.2f MB / 溢出 %.2f MB / 包 %.2f MB',
                    $peak / 1048576,
                    $resident / 1048576,
                    ($peak - $resident) / 1048576,
                    $size / 1048576,
                ),
            );
        } finally {
            @\unlink($path);
        }
    }

    /**
     * 离线会话自报 `offline`，且**没有令牌**。
     *
     * 令牌空串是刻意的：它不是"某个进程里的会话"，而是一份已经结束的记录的读视图。
     * 给它一个真令牌，观察者就会拿它的帧号去跟活会话比，而那条比较永远得出
     * "落后了几千帧"这种看起来像故障、实际毫无意义的结论。
     */
    public function testOfflineSessionReportsItselfAsOffline(): void
    {
        $app = $this->boot();
        $recorder = Recorder::install();
        $path = self::tempFile('.evidence');

        try {
            $app->frame(0.0, $this->constraints());
            $app->frame(16.0, $this->constraints());
            Evidence::write($recorder, $path);
            $last = $recorder->lastFrameId();

            $offline = QueryRouter::offline(Recorder::hydrate(Evidence::read($path)));
            $state = $offline->query('state');

            check('状态是 offline', $state['state'] === 'offline', (string) $state['state']);
            check('没有令牌', $state['session'] === '', \json_encode($state['session']));
            check('offline 没有原因', $state['reason'] === null, \json_encode($state['reason']));
            check('末帧仍是包里的末帧', $state['lastFrame'] === $last, \sprintf('state=%d 记录=%d', $state['lastFrame'], $last));
        } finally {
            Recorder::uninstall();
            @\unlink($path);
        }
    }

    /**
     * 坏包一律报错，不"尽力读"。
     *
     * 半份证据最坏的地方不是缺数据，是读者**无法分辨**"这份证据里没有网络域"与
     * "这个版本不写网络域"。所以宁可整个拒绝。
     */
    public function testBrokenPackagesAreRejected(): void
    {
        $cases = [
            '空文件' => '',
            '首行不是 manifest' => '{"kind":"frame","id":1,"t":0.0,"cost":1.0,"rebuilt":[],"causes":[]}',
            '版本不认识' => '{"kind":"manifest","schema":"drift-evidence","version":99,"domains":[],"counts":{}}',
            'schema 不认识' => '{"kind":"manifest","schema":"other","version":1,"domains":[],"counts":{}}',
            '未知记录类型' => "{\"kind\":\"manifest\",\"schema\":\"drift-evidence\",\"version\":1,\"domains\":[\"frames\"],\"counts\":{\"paint\":1}}\n{\"kind\":\"paint\",\"id\":1}",
            '缺必需字段' => "{\"kind\":\"manifest\",\"schema\":\"drift-evidence\",\"version\":1,\"domains\":[\"frames\"],\"counts\":{\"frame\":1}}\n{\"kind\":\"frame\",\"id\":1,\"t\":0.0,\"rebuilt\":[],\"causes\":[]}",
            '声明与实读不符（截断）' => "{\"kind\":\"manifest\",\"schema\":\"drift-evidence\",\"version\":1,\"domains\":[\"frames\"],\"counts\":{\"frame\":2}}\n{\"kind\":\"frame\",\"id\":1,\"t\":0.0,\"cost\":1.0,\"rebuilt\":[],\"causes\":[]}",
            '帧号乱序' => "{\"kind\":\"manifest\",\"schema\":\"drift-evidence\",\"version\":1,\"domains\":[\"frames\"],\"counts\":{\"frame\":2}}\n"
                . "{\"kind\":\"frame\",\"id\":2,\"t\":0.0,\"cost\":1.0,\"rebuilt\":[],\"causes\":[]}\n"
                . "{\"kind\":\"frame\",\"id\":1,\"t\":16.0,\"cost\":1.0,\"rebuilt\":[],\"causes\":[]}",
            '记录属于未声明的域' => "{\"kind\":\"manifest\",\"schema\":\"drift-evidence\",\"version\":1,\"domains\":[\"frames\"],\"counts\":{}}\n"
                . '{"kind":"node","id":1,"p":-1,"c":"PageShell"}',
        ];

        foreach ($cases as $label => $content) {
            $path = self::tempFile('.evidence');
            \file_put_contents($path, $content);

            $threw = '';
            try {
                Evidence::read($path);
            } catch (\InvalidArgumentException $e) {
                $threw = $e->getMessage();
            } finally {
                @\unlink($path);
            }

            check("坏包被拒绝：{$label}", $threw !== '', '没有抛异常');
        }
    }
}

/** @internal 判据夹具：一层有状态节点 + 一个由父产生的子节点 */
final class EvidenceApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Column::make()->children([new EvidenceProbe()]);
    }
}

/** @internal 判据夹具 */
final class EvidenceProbe extends StatefulWidget
{
    public function createState(): State
    {
        return new EvidenceState();
    }
}

/** @internal 判据夹具：`build()` 每次产出新的 `Text`，于是子元素走 update → rebuild */
final class EvidenceState extends State
{
    public static ?self $last = null;

    public int $count = 0;

    public function initState(): void
    {
        self::$last = $this;
    }

    public function bump(): void
    {
        $this->setState(function (): void {
            ++$this->count;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        return Text::make('e=' . $this->count);
    }
}
