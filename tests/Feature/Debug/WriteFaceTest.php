<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\Session\History;
use Drift\Debug\Session\Recorder;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 写面的接缝判据：`Recorder` 上不许重新长出读语义。
 *
 * ## 为什么这组判据值得存在
 *
 * 读语义搬去 {@see History} 之前，`Recorder.cc` 5786 行里有 1388 行（24%）是那些
 * 方法 —— 发布态一次都执行不到，却跟着 exe 走（tpc 全量编译、类可达即整类进产物）。
 * 这不是一次长成的：每加一个查询都可能"顺手"在记录器上加一个读方法，因为它手上
 * 正好有那些表。搬完如果不钉结构，下一个人会以完全正当的理由（"就近取数"）把
 * 第一块砖搬回来，而产物只增不减、报告看起来一切正常。
 *
 * 四条判据：
 *
 * 1. 那批读方法**不许**回到 `Recorder` 上（名单就是搬走的那批）；
 * 2. `Recorder` 的**代码**（去注释）不许引用任何审查面类 —— 一旦引用，tpc 顺着
 *    引用把那个类整类编进产物，"写面只有采集"当场失效。注释里的 `{@see}` 不算：
 *    它不进代码，也不产生可达边；
 * 3. `History` 的构造只收 `Recorder`（读面认识写面，反过来不行）；
 * 4. `History` 从 `ledger()` 取的每个键，都必须在 `Recorder::ledger()` 里真的存在
 *    —— 加一张新表却忘了加进出口，症状是查询答"这一域没有数据"而不是报错。
 */
final class WriteFaceTest extends SmokeTestCase
{
    /** 搬去读面的那批方法：任何一个人把它们加回写面，这条就红。 */
    private const MOVED_TO_HISTORY = [
        'geometryAt', 'geometryRows', 'geometrySamples', 'geometryFrames', 'geometryCoverage',
        'isBoxless', 'firstBoxedChild', 'isFlex', 'nodeStatus', 'nodeIndex', 'evictedNodes',
        'goneNodes', 'typeOf', 'pathOf', 'why', 'requestsLandingOn', 'boxlessNodes',
        'paintFrames', 'paintFrame', 'paintCoverage',
        'bodyRequests', 'bodyAt', 'bodyCoverage',
        'networkRows', 'networkAt', 'networkCoverage',
        'asyncRows', 'asyncAt', 'asyncCoverage',
        'highlightedNode', 'highlightFrame', 'overlayDrawn', 'redactionEnabled',
    ];

    /**
     * 审查面（A54 的 L3）里会被写面"意外拖进产物"的类。
     *
     * 写面引用谁，tpc 就把谁编进去 —— 包括只被 `new` 过一次的、只当类型标注的。
     * `EvidenceBag` 不在此列：它是 `hydrate()` 的参数类型，本来就在写面白名单里。
     */
    private const REVIEW_CLASSES = [
        'History', 'Query', 'QueryRouter', 'Evidence', 'Digest',
        'CausalQuery', 'GeometryQuery', 'PaintQuery', 'TrafficQuery', 'StructureQuery', 'LiveQuery',
        'SessionDriver', 'SessionQueries', 'Session', 'ObjectDump', 'MemoryProbe', 'SourceLocator',
        'DebugSession', 'Replay', 'NodeContext', 'SourceCatalog', 'SourceLink',
    ];

    public function testReadMethodsDoNotComeBack(): void
    {
        $ref = new \ReflectionClass(Recorder::class);

        $back = [];
        foreach (self::MOVED_TO_HISTORY as $method) {
            if ($ref->hasMethod($method)) {
                $back[] = $method;
            }
        }

        check(
            'Recorder 上没有读方法（它们属于 History）',
            $back === [],
            '回来了：' . \implode(', ', $back) . ' —— 写面每多一个方法，发布态就多一段死代码',
        );
    }

    public function testRecorderCodeReferencesNoReviewClass(): void
    {
        $ref = new \ReflectionClass(Recorder::class);
        $code = self::codeOf($ref->getFileName() ?: '');

        $leaks = [];
        foreach (self::REVIEW_CLASSES as $class) {
            if (\preg_match('~(?<![\w\\\\])' . \preg_quote($class, '~') . '(?![\w])~', $code) === 1) {
                $leaks[] = $class;
            }
        }

        check(
            'Recorder 的代码不引用审查面类',
            $leaks === [],
            '引用了：' . \implode(', ', $leaks) . ' —— tpc 会顺着这条边整类编进产物',
        );
    }

    public function testHistoryOnlyKnowsTheRecorder(): void
    {
        $ref = new \ReflectionClass(History::class);
        check('History 是 final', $ref->isFinal());

        $ctor = $ref->getConstructor();
        $params = $ctor === null ? [] : $ctor->getParameters();
        $types = [];
        foreach ($params as $param) {
            $type = $param->getType();
            $types[] = $type instanceof \ReflectionNamedType ? $type->getName() : '?';
        }

        check(
            'History 的构造只收 Recorder',
            $types === [Recorder::class],
            '实际：' . \implode(', ', $types),
        );
    }

    /**
     * 读面从 `ledger()` 取的键必须真的存在。
     *
     * 反过来的漏洞没有对应判据是刻意的：写面多导出一张没人读的表只是一个数组键，
     * 而少导出会让查询答"这一域没数据"—— 后者是假答案，必须咬住。
     */
    public function testEveryLedgerKeyTheHistoryReadsExists(): void
    {
        $ledgerSrc = self::bodyOf(new \ReflectionMethod(Recorder::class, 'ledger'));
        \preg_match_all("~'([a-zA-Z]+)'\\s*=>~", $ledgerSrc, $m);
        $provided = \array_unique($m[1]);
        \sort($provided);

        $historyRef = new \ReflectionClass(History::class);
        $historySrc = (string) \file_get_contents($historyRef->getFileName() ?: '');
        \preg_match_all("~ledger\\(\\)\\[\\s*'([a-zA-Z]+)'\\s*\\]~", $historySrc, $n);
        $used = \array_unique($n[1]);
        \sort($used);

        $missing = \array_values(\array_diff($used, $provided));

        check(
            'History 读的每个 ledger 键都存在',
            $missing === [],
            '缺：' . \implode(', ', $missing) . '（Recorder::ledger() 只给了 ' . \implode(', ', $provided) . '）',
        );
    }

    /** 去掉注释后的源码：`{@see}` 不产生可达边，不能拿它判泄漏。 */
    private static function codeOf(string $file): string
    {
        $out = '';
        foreach (\token_get_all((string) \file_get_contents($file)) as $token) {
            if (\is_array($token)) {
                if ($token[0] === \T_COMMENT || $token[0] === \T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /** 一个方法体（不含签名与首尾花括号）的源码。 */
    private static function bodyOf(\ReflectionMethod $method): string
    {
        $lines = \file($method->getFileName() ?: '');
        $slice = \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        );
        $src = \implode('', $slice);
        $open = \strpos($src, '{');
        if ($open === false) {
            return '';
        }
        $src = \substr($src, $open + 1);
        $close = \strrpos($src, '}');

        return $close === false ? $src : \substr($src, 0, $close);
    }
}
