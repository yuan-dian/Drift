<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Debug\Session\Thresholds;
use Drift\Dev\Debug\View\Brief;
use Drift\Dev\Debug\View\BriefText;
use Drift\Dev\Debug\View\Facts;
use Drift\Dev\Debug\View\Web;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * S5 的八条判据（见 `DESIGN-EVIDENCE-B4` §9）。
 *
 * 端到端：真起一个 `debug-web` 子进程，从 stderr 读回端口，然后**用浏览器会用的
 * 方式**（HTTP GET）去问它。直接调 `Web::route()` 只能证明路由表写对了，证明不了
 * "浏览器拿得到"——而"拿不到"恰好是这一期最容易出的失败模式（端口绑错、响应头
 * 少了 `Content-Length`、页面白屏而终端一切正常）。
 */
final class WebTest extends SmokeTestCase
{
    /** @var list<array{0: resource, 1: list<resource>}> */
    private array $procs = [];

    protected function tearDown(): void
    {
        foreach ($this->procs as [$proc, $pipes]) {
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    @\fclose($pipe);
                }
            }
            @\proc_terminate($proc, 9);
            @\proc_close($proc);
        }
        $this->procs = [];
        parent::tearDown();
    }

    /** 判据 1：三端同一根因 —— 浏览器拿到的发现与终端 `--json` 的逐值相同。 */
    public function testFindingsMatchTheCliExactly(): void
    {
        // 两边都传 `--slow=5`：默认阈值下"把阈值写死"与"读参数"给出同一份结果，
        // 那条判据就是恒绿的了。参数一给，谁没读就露出来。
        $port = $this->startWeb(['--page=table', '--frames=2', '--slow=5']);
        $web = $this->json($port, '/api/brief');

        $cli = \json_decode($this->runBrief(['--page=table', '--frames=2', '--slow=5', '--json']), true);

        check('阈值一致', $web['thresholds'] === $cli['thresholds'], \json_encode([$web['thresholds']['slowMs'], $cli['thresholds']['slowMs']]));
        check('窗口一致', $web['window'] === $cli['window'], \json_encode([$web['window'], $cli['window']]));
        check('指纹一致', $web['digest'] === $cli['digest'], (string) $web['digest'] . ' vs ' . (string) $cli['digest']);
        check(
            '发现逐值相同',
            $web['findings'] === $cli['findings'],
            'web=' . \count($web['findings']) . ' cli=' . \count($cli['findings']),
        );
        check('发现不是空的（否则上面那条恒绿）', $web['findings'] !== [], '表格页 2 帧该有几何发现');
    }

    /**
     * 判据 2：投影里没有排版。
     *
     * 这条是结构断言，不是行为断言：`lines` 一旦回到 `project()`，第二个消费者就
     * 又有了一条"直接渲染终端文本"的捷径，而那条路会让"三端一致"退化成一句话。
     *
     * 判据 4：裁剪账在排版层，且可逆。
     */
    public function testProjectionCarriesNoLayout(): void
    {
        $facts = Facts::gather($this->queriesFor(), 'table');
        $projection = Brief::project($facts);

        foreach (['lines', 'width', 'budget', 'omitted'] as $key) {
            check("投影不含 {$key}", !\array_key_exists($key, $projection), \implode(',', \array_keys($projection)));
        }
        check('投影带阈值', isset($projection['thresholds']['slowMs']));
        check('投影带覆盖度', isset($projection['coverage']['complete']));
        check('投影只回发现的对象', $projection['findings'] === [] || $projection['findings'][0] instanceof \Drift\Dev\Debug\View\Finding);

        $tight = BriefText::render($facts, $projection, 8, 100);
        $wide = BriefText::render($facts, $projection, 300, 100);
        check('宽预算不裁发现', $wide['omitted']['findings'] === 0, (string) $wide['omitted']['findings']);
        check('宽预算把发现全放出来', $this->findingLines($wide['lines']) === \count($projection['findings']));
        // 具体"裁几条"由 BriefTest 的人造 facts 咬（真实页面只有 2 条发现，挤不出裁剪）；
        // 这里咬的是账与话一致：记了账就必须在尾行说出来，没记就不许说。
        $tail = (string) \end($tight['lines']);
        $saysFindings = (bool) \preg_match('/发现 \d+ 条未显示/u', $tail);
        check(
            '裁了发现就说裁了发现（没裁不许报）',
            ($tight['omitted']['findings'] > 0) === $saysFindings,
            $tail,
        );
        check('阈值默认值没被渲染层改动', (new Thresholds())->slowMs === 16.7);
    }

    /** 判据 3：阈值是数据 —— `--slow=5` 必须出现在响应里，而不是页面里。 */
    public function testThresholdsFollowTheCommandLine(): void
    {
        $def = $this->json($this->startWeb(['--page=table', '--frames=1']), '/api/brief');
        $tight = $this->json($this->startWeb(['--page=table', '--frames=1', '--slow=5']), '/api/brief');

        check('默认慢帧阈值', (float) $def['thresholds']['slowMs'] === 16.7, (string) $def['thresholds']['slowMs']);
        check('命令行改阈值后响应跟着改', (float) $tight['thresholds']['slowMs'] === 5.0, (string) $tight['thresholds']['slowMs']);
        check(
            '阈值收紧后慢帧发现变多或不变',
            \count($tight['findings']) >= \count($def['findings']),
            \count($def['findings']) . ' → ' . \count($tight['findings']),
        );
    }

    /** 判据 5：地址可反查 —— `node:341` 里的 341 问得回这个节点自己的事实。 */
    public function testNodeAddressIsResolvable(): void
    {
        $port = $this->startWeb(['--page=table', '--frames=2']);
        $boxes = $this->json($port, '/api/boxes?frame=1');
        check('几何有行', ($boxes['rows'] ?? []) !== [], '表格页第 1 帧该有几何');

        $node = (int) $boxes['rows'][0]['node'];
        $card = $this->json($port, "/api/node?node={$node}&frame=1");
        check('回显的就是问的那个节点', (int) $card['node'] === $node, (string) $card['node']);
        check('几何查得到', ($card['boxModel']['found'] ?? false) === true, \json_encode($card['boxModel']));
        check('几何的节点号与问的一致', (int) $card['boxModel']['node'] === $node);

        $bad = $this->json($port, '/api/node');
        check('不给 node 说清缺什么', ($bad['error'] ?? '') === 'bad_request', \json_encode($bad));
    }

    /** 树的行与几何来自同一帧、同一查询面；页面不再自己走 parent 指针。 */
    public function testTreeRouteShipsTheSameFrameFacts(): void
    {
        $port = $this->startWeb(['--page=table', '--frames=2']);
        $tree = $this->json($port, '/api/tree?frame=1&limit=40');
        $boxes = $this->json($port, '/api/boxes?frame=1');
        $rows = $tree['rows'] ?? [];
        check('树有行', $rows !== [], \json_encode($tree));

        $boxByNode = [];
        foreach ($boxes['rows'] ?? [] as $row) {
            $boxByNode[(int) $row['node']] = $row;
        }
        $joined = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if (!isset($boxByNode[$id])) {
                continue;
            }
            ++$joined;
            check(
                "树上节点 #{$id} 的几何与 /api/boxes 同一份",
                ($row['geometry']['size'] ?? null) === ($boxByNode[$id]['size'] ?? null)
                    && ($row['geometry']['rect'] ?? null) === ($boxByNode[$id]['rect'] ?? null),
                \json_encode($row['geometry'] ?? null),
            );
        }
        check('确实对齐了盒节点', $joined > 0, 'joined=' . $joined);
        check('覆盖率随树自报', isset($tree['coverage']['active'], $tree['coverage']['roots']));

        $node = (int) ($rows[0]['id'] ?? 0);
        $sub = $this->json($port, "/api/tree?frame=1&root={$node}");
        check(
            '按根取子树仍从该节点开始',
            (int) ($sub['rows'][0]['id'] ?? 0) === $node,
            \json_encode($sub['rows'][0] ?? []),
        );
    }

    /** 报文附件走独立出口：没开采集要说清，不能拿空文本冒充空 body。 */
    public function testBodyRouteKeepsTheCaptureMiss(): void
    {
        $port = $this->startWeb(['--page=table', '--frames=1']);
        $bad = $this->json($port, '/api/body');
        check('缺 request 是 400', ($bad['error'] ?? '') === 'bad_request', \json_encode($bad));

        $miss = $this->json($port, '/api/body?request=1');
        check('没开正文采集时明确报 miss', ($miss['found'] ?? true) === false, \json_encode($miss));
        check('miss 原因不是空 body', ($miss['miss'] ?? '') === 'bodies-not-captured', \json_encode($miss));
        check('窗口自报未采集', ($miss['window']['captured'] ?? true) === false, \json_encode($miss['window'] ?? []));
    }

    /**
     * 搜索与节点卡是同一个循环：搜到的地址必须能被卡片直接解析。
     *
     * 这条判据比"路由返回 200"有用得多 —— 页面点一条结果就直接发 `/api/node`，
     * 地址解析不开时页面只会显示一张空卡片，而没有任何地方报错。
     */
    public function testSearchReturnsAddressesTheNodeCardAccepts(): void
    {
        $port = $this->startWeb(['--page=table', '--frames=2']);

        $found = $this->json($port, '/api/find?pattern=Table');
        $matches = $found['matches'] ?? [];
        check('搜得到节点', $matches !== [], \json_encode($found));
        check('命中项是地址', \str_starts_with((string) ($matches[0]['address'] ?? ''), 'node:'), \json_encode($matches[0] ?? []));

        $node = (int) \substr((string) $matches[0]['address'], \strlen('node:'));
        $card = $this->json($port, "/api/node?node={$node}&frame=1");
        check('搜到的地址能被节点卡解析', ($card['boxModel']['node'] ?? 0) === $node, \json_encode($card['boxModel'] ?? []));

        $missing = $this->json($port, '/api/find');
        check('缺 pattern 说清缺什么', ($missing['error'] ?? '') === 'bad_request', \json_encode($missing));

        $badDomain = $this->json($port, '/api/find?pattern=x&domain=frame');
        check('未知域是 400 不是空结果', ($badDomain['error'] ?? '') === 'bad_request', \json_encode($badDomain));
        check('未知域列出可用值', \str_contains((string) ($badDomain['message'] ?? ''), 'request'), \json_encode($badDomain));
    }

    /** 判据 6：画布上的盒都在同一帧的几何里 —— 三张脸指同一个节点集。 */
    public function testEveryBoxBelongsToTheSameFrame(): void
    {
        $port = $this->startWeb(['--page=table', '--frames=2']);
        $boxes = $this->json($port, '/api/boxes?frame=1');
        $rows = $boxes['rows'] ?? [];

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['node']] = true;
        }

        $orphans = [];
        $noRect = [];
        foreach ($rows as $row) {
            $parent = (int) $row['parent'];
            if ($parent > 0 && !isset($ids[$parent])) {
                $orphans[] = (int) $row['node'] . '→' . $parent;
            }
            $rect = $row['rect'] ?? null;
            if (!\is_array($rect) || !isset($rect['x'], $rect['y'], $rect['w'], $rect['h'])) {
                $noRect[] = (int) $row['node'];
            }
        }

        check('每个父都在同一帧里', $orphans === [], \implode(', ', \array_slice($orphans, 0, 5)));
        check('每行都有绝对矩形', $noRect === [], \implode(', ', \array_slice($noRect, 0, 5)));
        check(
            '子盒不跑出父盒太远（坐标累加没算错）',
            $this->childrenInsideParents($rows),
            '子盒的 x 比父盒的 x 小超过 1px',
        );
    }

    /** 判据 7：HTTP 解析有边界 —— 头部超限断开，路径穿越拒绝，服务还活着。 */
    public function testServerSurvivesMalformedRequests(): void
    {
        $port = $this->startWeb(['--page=table', '--frames=1']);

        $raw = $this->raw($port, "GET / HTTP/1.1\r\nHost: x\r\nX-Big: " . \str_repeat('a', 9000) . "\r\n\r\n");
        check('超长头被拒或断开', \str_contains($raw, '431') || $raw === '', \substr($raw, 0, 40));

        $traversal = $this->json($port, '/../autoload.php');
        check('路径穿越 404', ($traversal['error'] ?? '') === 'not_found', \json_encode($traversal));

        $after = $this->json($port, '/api/state');
        check('两次坏请求之后服务还在', isset($after['state']), \json_encode($after));
    }

    /** 判据 8：默认只绑 loopback —— 这个服务没有密码。 */
    public function testDefaultBindIsLoopback(): void
    {
        $ref = new \ReflectionClass(\Drift\Dev\Debug\HttpServer::class);
        $src = (string) \file_get_contents((string) $ref->getFileName());

        check(
            '构造器默认值是 127.0.0.1',
            (bool) \preg_match('/\$host\s*=\s*\'127\.0\.0\.1\'/', $src),
            '默认值不是 loopback',
        );
        check('没有把 0.0.0.0 当默认', !\preg_match('/\$host\s*=\s*\'0\.0\.0\.0\'/', $src));
    }

    // ------------------------------------------------------------ 辅助

    /** @param list<string> $args */
    private function startWeb(array $args): int
    {
        $root = \dirname(__DIR__, 3);
        $cmd = \array_merge([\PHP_BINARY, $root . '/tools/debug-web.php', '--port=0'], $args);
        $proc = \proc_open($cmd, [
            0 => ['file', 'NUL', 'r'],
            1 => ['pipe', 'r'],
            2 => ['pipe', 'w'],
        ], $pipes, $root, null, ['bypass_shell' => true]);

        self::assertIsResource($proc, 'debug-web 起不来');
        $this->procs[] = [$proc, $pipes];

        \stream_set_timeout($pipes[2], 20);
        $line = (string) \fgets($pipes[2]);
        if (!\preg_match('#:(\d+)/#', $line, $m)) {
            $rest = (string) \stream_get_contents($pipes[2]);
            self::fail("没拿到端口：{$line}{$rest}");
        }

        return (int) $m[1];
    }

    /** @return array<string, mixed> */
    private function json(int $port, string $path): array
    {
        $raw = $this->raw($port, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");
        $body = (string) \substr($raw, (int) \strpos($raw, "\r\n\r\n") + 4);
        $decoded = \json_decode($body, true);
        self::assertIsArray($decoded, "不是 JSON：{$path} → " . \substr($body, 0, 200));

        return $decoded;
    }

    private function raw(int $port, string $request): string
    {
        $sock = @\stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 5);
        if ($sock === false) {
            self::fail("连不上 {$port}：{$errstr}");
        }

        \stream_set_timeout($sock, 10);
        @\fwrite($sock, $request);
        $out = '';
        while (!\feof($sock)) {
            $chunk = @\fread($sock, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $out .= $chunk;
            if (\str_contains($out, "\r\n\r\n") && \strlen($out) > 0) {
                // 响应头里有 Content-Length，读够了就停（对端发完会关，但不等它）。
                $split = (int) \strpos($out, "\r\n\r\n");
                if (\preg_match('/content-length:\s*(\d+)/i', \substr($out, 0, $split), $m)
                    && \strlen($out) - $split - 4 >= (int) $m[1]) {
                    break;
                }
                if (!\preg_match('/content-length:/i', \substr($out, 0, $split))) {
                    break;
                }
            }
        }
        @\fclose($sock);

        return $out;
    }

    /** @param list<string> $args */
    private function runBrief(array $args): string
    {
        $root = \dirname(__DIR__, 3);
        $proc = \proc_open(
            \array_merge([\PHP_BINARY, $root . '/tools/debug-brief.php'], $args),
            [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($proc);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        @\fclose($pipes[1]);
        @\fclose($pipes[2]);
        \proc_close($proc);

        if ($out === '') {
            self::fail('debug-brief 没输出：' . $err);
        }

        return $out;
    }

    private function queriesFor(): \Drift\Debug\Session\SessionQueries
    {
        $script = \Drift\Dev\Debug\Plan::build('table', 800.0, 600.0, ['page' => 1], 2, 1, []);
        $open = \Drift\Dev\Debug\Runner::open(
            $script->target,
            $script->width,
            $script->height,
            $script->meta(),
            $script->fork(),
        );
        $session = $open['session'];
        for ($i = 0; $i < 2 && $session->canAdvance(); ++$i) {
            $session->pump($i * 16.0);
        }

        return $session;
    }

    /** @param list<string> $lines */
    private function findingLines(array $lines): int
    {
        $n = 0;
        foreach ($lines as $line) {
            if (\str_starts_with($line, '  · ')) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function childrenInsideParents(array $rows): bool
    {
        $byNode = [];
        foreach ($rows as $row) {
            $byNode[(int) $row['node']] = $row;
        }

        foreach ($rows as $row) {
            $parent = (int) $row['parent'];
            if ($parent <= 0 || !isset($byNode[$parent])) {
                continue;
            }
            $p = $byNode[$parent]['rect'];
            $c = $row['rect'];
            // 允许 1px 的边框/取整误差；超出说明累加公式用反了（比如忘了父的 padding）。
            if ($c['x'] < $p['x'] - 1.0 || $c['y'] < $p['y'] - 1.0) {
                return false;
            }
        }

        return true;
    }
}
