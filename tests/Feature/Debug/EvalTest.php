<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\App\DebugSession;
use Drift\DevTools\Protocol\Methods;
use Drift\DevTools\Protocol\ProtocolError;
use Drift\DevTools\Protocol\Wire;
use Drift\Debug\Session\Script;
use Drift\DevTools\Cli\Commands;
use Drift\DevTools\Cli\Registry;
use Drift\DevTools\Host\Host;
use Drift\DevTools\Host\Plan;
use Drift\DevTools\Host\Runner;
use Drift\DevTools\Host\SingleServer;
use Drift\DevTools\View\EvalText;
use Drift\Dev\Tests\Support\FakeTransport;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `debug eval` 的协议与回值判据（A55：内省不是证据）。
 *
 * ## 判据立在"默认关"上，不是立在"能求值"上
 *
 * "1+1 得到 2"谁都能写对，咬不住任何东西。真正会漂的是安全面与边界：
 * 开关默认关、错误求值不带走进程、结果编得成 JSON、只读求值不改指纹。
 * 所以变异测试改的是这四处，而不是把 `1+1` 改成 `2+2`。
 *
 * ## 用内存通道，不用真 socket
 *
 * 与 `HostTest` 同一条纪律：这里判"哪条消息得到哪个答复"，与消息怎么送到
 * 无关。真会话的往返在 `RemoteSessionTest` 那层判。
 */
final class EvalTest extends SmokeTestCase
{
    private function script(): Script
    {
        return Plan::build('SlowFrameApplication', 320.0, 240.0, [], 1, 1, []);
    }

    /**
     * @param list<array<string, mixed>> $commands
     *
     * @return list<array<string, mixed>>
     */
    private function serve(array $commands, bool $allowEval = true): array
    {
        $lines = \array_map(static fn(array $c): string => Wire::encode($c), $commands);
        $transport = new FakeTransport($lines);
        $host = new Host(new SingleServer($transport), null, null, $allowEval);
        $host->serve(static fn(Script $s): array => Runner::open(
            $s->target,
            $s->width,
            $s->height,
            $s->meta(),
            $s,
        ));

        return $transport->decoded();
    }

    /** @param array<array-key, mixed> $params */
    private static function call(int $id, string $method, array $params = []): array
    {
        return ['id' => $id, 'method' => $method, 'params' => $params];
    }

    /** @param list<string> $cmd @return string 合并 stdout+stderr，帮助文本只认字节 */
    private static function runCli(array $cmd): string
    {
        $p = \proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($p)) {
            return '';
        }
        \fclose($pipes[0]);
        $out = \stream_get_contents($pipes[1]);
        $err = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($p);

        return $out . $err;
    }

    public function testEvalIsRegisteredAsItsOwnVerb(): void
    {
        check('eval 是协议动词', \in_array(Methods::EVAL, Methods::ALL, true));
        check('eval 动词名是 eval', Methods::EVAL === 'eval');
        check('eval 出口已注册', Registry::get('eval')->script === 'debug-eval.php');
        check('eval 不进 schema（协议型出口）', Registry::get('eval')->driver === false);
        check('serve 有 allow-eval 开关', \in_array('allow-eval', Commands::set('serve')->words(), true));
        // 咬住"默认关"本身：不传第 4 个参数时必须拒绝。只测显式 false
        // 的话，把构造默认值改成 true 判据仍绿。
        $t = new FakeTransport([
            Wire::encode(self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()])),
            Wire::encode(self::call(2, Methods::EVAL, ['expression' => '1 + 1'])),
        ]);
        (new Host(new SingleServer($t)))->serve(static fn(Script $s): array => Runner::open(
            $s->target, $s->width, $s->height, $s->meta(), $s,
        ));
        $replies = $t->decoded();
        check('Host 不传参数时 eval 默认关', ($replies[1]['error']['code'] ?? null) === ProtocolError::NOT_APPLICABLE, self::show($replies[1] ?? null));

        // 协议型出口不进 driver 的 help 判据，单独钉直跑与路由两级同源。
        $root = \dirname(__DIR__, 3);
        $direct = self::runCli([PHP_BINARY, $root . '/devtools/bin/debug-eval.php', '--help']);
        $viaDrift = self::runCli([PHP_BINARY, $root . '/bin/drift', 'debug', 'eval', '--help']);
        check('eval 两级 help 逐字节相同', $direct === $viaDrift && $direct !== '', self::show([$direct, $viaDrift]));
        $this->flushChecks();
    }

    public function testDefaultOffRejectsEvalButKeepsHostAlive(): void
    {
        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()]),
            self::call(2, Methods::EVAL, ['expression' => '1 + 1']),
            self::call(3, Methods::QUERY, ['name' => 'stats']),
        ], allowEval: false);

        check('默认关回 not_applicable', ($replies[1]['error']['code'] ?? null) === ProtocolError::NOT_APPLICABLE, self::show($replies[1] ?? null));
        check('拒绝后循环还活着', isset($replies[2]['result']) && \is_array($replies[2]['result']), self::show($replies[2] ?? null));
        $this->flushChecks();
    }

    public function testExpressionSeesAppAndSession(): void
    {
        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()]),
            self::call(2, Methods::FRAME, ['t' => 0.0]),
            self::call(3, Methods::EVAL, ['expression' => '1 + 2']),
            self::call(4, Methods::EVAL, ['expression' => '$app::class']),
            self::call(5, Methods::EVAL, ['expression' => '$session->lastFrameId()']),
            self::call(6, Methods::EVAL, ['expression' => 'count($app->treeRoots())']),
        ]);

        check('算术求值', ($replies[2]['result']['value'] ?? null) === 3, self::show($replies[2] ?? null));
        check('类型名正确', ($replies[3]['result']['type'] ?? null) === 'string', self::show($replies[3] ?? null));
        check('注入 $app', \str_contains((string) ($replies[3]['result']['value'] ?? ''), 'SlowFrameApplication'), self::show($replies[3] ?? null));
        check('注入 $session', ($replies[4]['result']['value'] ?? null) === 1, self::show($replies[4] ?? null));
        check('活树可读', ($replies[5]['result']['value'] ?? null) === 1, self::show($replies[5] ?? null));
        $this->flushChecks();
    }

    public function testBadExpressionIsAResultNotACrash(): void
    {
        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()]),
            self::call(2, Methods::FRAME, ['t' => 0.0]),
            self::call(3, Methods::EVAL, ['expression' => '1 +']),
            self::call(4, Methods::QUERY, ['name' => 'stats']),
        ]);

        check('语法错回错误信封', ($replies[2]['ok'] ?? null) === false && ($replies[2]['error']['code'] ?? null) === ProtocolError::CALL_FAILED, self::show($replies[2] ?? null));
        check('语法错后宿主还活着', isset($replies[3]['result']) && \is_array($replies[3]['result']), self::show($replies[3] ?? null));
        $this->flushChecks();
    }

    public function testResultIsJsonSafeAndBounded(): void
    {
        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()]),
            self::call(2, Methods::FRAME, ['t' => 0.0]),
            // 对象必须走原语规范化（__debugInfo），不能把活对象直接塞进 JSON。
            self::call(3, Methods::EVAL, ['expression' => '$app->root()']),
            // 闭包只回类名，不暴露句柄。
            self::call(4, Methods::EVAL, ['expression' => 'fn() => 1']),
            self::call(5, Methods::EVAL, ['expression' => 'range(1, 100)']),
            // 深嵌套要真超过 MAX_DEPTH=4，否则改深度上限判据不红。
            self::call(6, Methods::EVAL, ['expression' => '[[[[[["deep"]]]]]]']),
        ]);

        $obj = $replies[2]['result'] ?? [];
        check('对象结果能编码', \is_array($obj['value'] ?? null) && isset($obj['value']['__class']), self::show($replies[2] ?? null));
        // 反射展开后能看到基类私有字段（mods / box），比旧的 get_object_vars 信息更全；
        // 代价是一棵活对象树会触顶，所以只钉"有界"：宽度 <= 41 且如实报 truncated。
        check('活对象展开有界', \count($obj['value'] ?? []) <= 41 && ($obj['truncated'] ?? null) === true, self::show($replies[2] ?? null));
        check('反射读到基类私有配置', isset($obj['value']['widget']['mods']) || isset($obj['value']['mods']), self::show($obj));
        // Closure 自带 __debugInfo（空数组），规范化后是 {__class: Closure}：
        // 看得出类型、不暴露句柄，比纯占位符信息更多。
        check('闭包只回类名', ($replies[3]['result']['value']['__class'] ?? null) === 'Closure', self::show($replies[3] ?? null));
        check('闭包不外泄字段', \count($replies[3]['result']['value'] ?? []) === 1, self::show($replies[3] ?? null));
        // 断言*条数*而不只是 truncated 标志：只把标志改成 true 谁都会，
        // 咬住"宽度上限真是 40"要数结果；把 MAX_ITEMS 改成 5 必须红。
        $wide = $replies[4]['result']['value'] ?? [];
        check('宽数组被截断', ($replies[4]['result']['truncated'] ?? null) === true, self::show($replies[4] ?? null));
        check('宽度上限是 40', \is_array($wide) && \count($wide) === 40, '实际 ' . (\is_array($wide) ? \count($wide) : '非数组'));
        // 深度同理：6 层嵌套在 MAX_DEPTH=4 时展开 4 层（第 4 层是 '…'）。
        // 只判 truncated 会在上限改成 1 时仍绿，必须数层数。
        $deep = $replies[5]['result']['value'] ?? null;
        $layers = 0;
        while (\is_array($deep) && $layers < 10) {
            ++$layers;
            $deep = $deep[0] ?? null;
        }
        check('深度上限是 4', $layers === 4, '实际层数 ' . $layers . '：' . self::show($replies[5] ?? null));
        // 整条回执必须编得成 JSON —— 宿主 ok() 已经代验，这里再钉一次形状。
        check('回执 JSON-safe', \json_encode($replies[2]['result'] ?? null) !== false);
        $this->flushChecks();
    }

    public function testReadOnlyEvalDoesNotChangeEvidence(): void
    {
        $replies = $this->serve([
            self::call(1, Methods::OPEN, ['script' => $this->script()->toArray()]),
            self::call(2, Methods::FRAME, ['t' => 0.0]),
            self::call(3, Methods::QUERY, ['name' => 'digest']),
            self::call(4, Methods::EVAL, ['expression' => '1 + 1']),
            self::call(5, Methods::QUERY, ['name' => 'digest']),
        ]);

        // 0-based：index 2 是第 3 条（digest 前），index 3 是 eval，index 4 是 digest 后。
        check('只读求值不改指纹', ($replies[2]['result'] ?? null) === ($replies[4]['result'] ?? null), self::show([$replies[2] ?? null, $replies[4] ?? null]));
        check('只读求值的 evidenceChanged 为假', ($replies[3]['result']['evidenceChanged'] ?? null) === false, self::show($replies[3] ?? null));
        $this->flushChecks();
    }

    public function testEvalTextShowsConditionalHints(): void
    {
        $clean = EvalText::lines([
            'expression' => '1 + 1', 'value' => 2, 'type' => 'int',
            'node' => 0, 'evidenceChanged' => false, 'truncated' => false,
        ]);
        $dirty = EvalText::lines([
            'expression' => '$x', 'value' => 2, 'type' => 'int',
            'node' => 0, 'evidenceChanged' => true, 'truncated' => true,
        ]);

        check('干净求值不报污染', !\str_contains(\implode("\n", $clean), '求值改变了应用状态'));
        check('干净求值不报截断', !\str_contains(\implode("\n", $clean), '结果已截断'));
        check('污染明示', \str_contains(\implode("\n", $dirty), '求值改变了应用状态'));
        check('截断明示', \str_contains(\implode("\n", $dirty), '结果已截断'));
        check('文本含表达式', \str_contains(\implode("\n", $clean), '$ 1 + 1'));
        $this->flushChecks();
    }

    /** 断言细节用：任何值都能显示出来。 */
    private static function show(mixed $value): string
    {
        return \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION) ?: \get_debug_type($value);
    }
}
