<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\Session\CausalQuery;
use Drift\DevTools\Session\GeometryQuery;
use Drift\DevTools\Session\History;
use Drift\DevTools\Session\LiveQuery;
use Drift\DevTools\Session\PaintQuery;
use Drift\DevTools\Session\Query;
use Drift\DevTools\Session\QueryRouter;
use Drift\DevTools\Session\SemanticsQuery;
use Drift\Debug\Session\Recorder;
use Drift\DevTools\Session\StructureQuery;
use Drift\DevTools\Session\TrafficQuery;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 查询面的接缝判据：门面只分派，各域互不认识。
 *
 * ## 为什么要钉这个
 *
 * `Query` 曾经是 2412 行、42 个方法的上帝类，而这**不是一次性长成的**：每加一个
 * 查询都"顺手"在同一个类里加一个私有助手、一个缓存、一次跨域取数，每一步都很小。
 * 拆完如果只有文件数变了，下一轮同样的"顺手"会把断开的缝重新长回去 —— 而那些
 * 顺手正是跨域口径漂移的来源（早先只有 `align` 判了几何窗口，另三个查询把窗口
 * 末端的旧几何当成目标帧的，答案自洽、可读、全错）。
 *
 * 所以这组判据只钉**结构**，不钉行为（行为由各域的判据管）：
 *
 * 1. 门面**不带可变状态** —— 带状态的门面就是下一轮上帝类的种子；
 * 2. 除 `align` / `find` 两个声明的组合点外，每个查询方法体是**单条分派**；
 * 3. 门面**没有私有 / 受保护方法** —— 逻辑没有地方可藏；
 * 4. 各域类一律经读面 {@see History} 取数（结构域多一个几何域，是唯一允许的
 *    域间边）；只有内省域还认识写面，因为它要给 `pick` 命中的新节点**发号**。
 *
 * 四条都不是风格偏好：去掉任何一条，"读侧的分界线与写侧（`Recorder::ledger()`
 * 那一个出口）一一对应"就退化成一句注释，而注释不会咬人。
 */
final class QuerySeamsTest extends SmokeTestCase
{
    /**
     * 门面上允许"有逻辑"的方法。
     *
     * `align` 同时取因果 / 几何 / 网络，`find` 横跨节点与请求 —— 它们必须认识多个域，
     * 落进任何一个都会让另两个成为那个域的依赖，依赖方向立刻成环。组合者住门面，
     * 三个域彼此仍然互不认识。
     */
    private const COMPOSERS = ['align', 'find'];

    /** 各域实现：门面背后的全部逻辑（无障碍是 A77 新增的 L2 内省域）。 */
    private const DOMAINS = [
        CausalQuery::class,
        GeometryQuery::class,
        PaintQuery::class,
        TrafficQuery::class,
        StructureQuery::class,
        LiveQuery::class,
        SemanticsQuery::class,
    ];

    /**
     * 允许直接持**写面**的域。
     *
     * 只有内省域：`pick()` 命中一个从没发过号的新节点时要给它一个地址（`idOf()`），
     * 而发号会写注册表 —— 那是采集的事，不属于读面。把它塞进 `History` 会让
     * "读面不写"这条失效。
     */
    private const WRITE_AWARE = [LiveQuery::class];

    public function testTheFacadeHoldsNoMutableState(): void
    {
        $ref = new \ReflectionClass(Query::class);

        $mutable = [];
        foreach ($ref->getProperties() as $property) {
            if ($property->isStatic() || !$property->isReadOnly()) {
                $mutable[] = $property->getName();
            }
        }
        check(
            '门面不带可变状态（属性全 readonly、无静态）',
            $mutable === [],
            '可变的：' . \implode(', ', $mutable),
        );

        // 一个读面 + 各域，多一个就说明有人把状态搬回门面了。
        check(
            '门面只持有读面与各域',
            \count($ref->getProperties()) === 1 + \count(self::DOMAINS),
            '实际 ' . \count($ref->getProperties()) . ' 个',
        );

        // ★ 门面**不许**直接持有写面：认识 `Recorder` 只为了构造一次读面。一旦留一份
        //   引用，"顺手在这儿读一下记录"就有了入口，读侧的分界线立刻开始漏。
        $props = [];
        foreach ($ref->getProperties() as $property) {
            $type = $property->getType();
            $props[$property->getName()] = $type instanceof \ReflectionNamedType ? $type->getName() : '?';
        }
        check(
            '门面不持写面（Recorder）',
            !\in_array(Recorder::class, $props, true),
            '实际：' . \json_encode($props),
        );

        check('门面是 final', $ref->isFinal());
    }

    public function testEveryQueryMethodIsASingleDelegation(): void
    {
        $ref = new \ReflectionClass(Query::class);
        $lines = \file($ref->getFileName() ?: '');
        check('读得到门面源码', \is_array($lines) && $lines !== []);

        $bad = [];
        foreach (\array_keys(QueryRouter::QUERY_SPECS) as $name) {
            [$method] = QueryRouter::QUERY_SPECS[$name];
            if (\in_array($method, self::COMPOSERS, true)) {
                continue;
            }
            $body = self::bodyOf($ref->getMethod($method), $lines);
            // 允许的形态只有一种：一条 return，转给记录器或某一个域。
            if (\preg_match('/^\s*return \$this->[a-z]+->\w+\([^;]*\);\s*$/s', $body) !== 1) {
                $bad[] = $method;
            }
        }

        check(
            '每个查询方法体是一条分派',
            $bad === [],
            '这几次带上了逻辑：' . \implode(', ', $bad),
        );
    }

    public function testTheFacadeHasNoPrivateHelpers(): void
    {
        $ref = new \ReflectionClass(Query::class);

        $hidden = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PRIVATE | \ReflectionMethod::IS_PROTECTED) as $method) {
            if ($method->getDeclaringClass()->getName() === Query::class) {
                $hidden[] = $method->getName();
            }
        }

        check(
            '门面上没有私有 / 受保护方法',
            $hidden === [],
            '藏在门面上的逻辑：' . \implode(', ', $hidden),
        );
    }

    public function testDomainsReadThroughTheHistory(): void
    {
        $edges = [];
        foreach (self::DOMAINS as $class) {
            $ref = new \ReflectionClass($class);
            check("{$class} 是 final", $ref->isFinal());

            $ctor = $ref->getConstructor();
            $params = $ctor === null ? [] : $ctor->getParameters();
            $types = [];
            foreach ($params as $param) {
                $type = $param->getType();
                $types[] = $type instanceof \ReflectionNamedType ? $type->getName() : '?';
            }

            if (\in_array($class, self::WRITE_AWARE, true)) {
                // 内省域：写面在前（发号），读面在后。
                check(
                    "{$class} 认识写面也给读面",
                    ($types[0] ?? '') === Recorder::class && \in_array(History::class, $types, true),
                    '实际：' . \implode(', ', $types),
                );
            } else {
                check(
                    "{$class} 的构造参数从读面开始",
                    ($types[0] ?? '') === History::class,
                    '实际：' . \implode(', ', $types),
                );
                // 除读面之外不许再认识别的 —— 尤其不许偷偷把写面拿回来。
                check(
                    "{$class} 不直接持写面",
                    !\in_array(Recorder::class, $types, true),
                    '实际：' . \implode(', ', $types),
                );
            }

            // 除"结构 → 几何"这一条，域之间不许互相认识。多一条就是环的开始：
            // 几何域的 lookup() 一旦要问"这个节点那时在不在"，两边就只能靠深度限制收场。
            // 读面不是域，不计入域间边。
            foreach (\array_slice($types, 1) as $extra) {
                if (self::isDomain($extra)) {
                    $edges[$class] = $extra;
                }
            }
        }

        check(
            '域间只有一条边：结构 → 几何',
            $edges === [StructureQuery::class => GeometryQuery::class],
            '实际：' . \json_encode($edges),
        );
    }

    /** 这个类名是不是六个域之一（读面 / 写面 / 记录类型都不算）。 */
    private static function isDomain(string $type): bool
    {
        return \in_array($type, self::DOMAINS, true);
    }

    /**
     * 一个方法体（不含签名与首尾花括号）的源码。
     *
     * 不解析 token 流：本仓 docblock 与声明紧邻是铁律，方法体按行切足够，且这里
     * 只需要判"是不是一条 return" —— 用 token 流反而是给一条判据背上整台解析器。
     *
     * @param list<string> $lines
     */
    private static function bodyOf(\ReflectionMethod $method, array $lines): string
    {
        $slice = \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        );
        $src = \implode('', $slice);

        // 去掉签名（到第一个 `{` 为止）与末尾的 `}`。
        $open = \strpos($src, '{');
        if ($open === false) {
            return '';
        }
        $src = \substr($src, $open + 1);
        $close = \strrpos($src, '}');

        return $close === false ? $src : \substr($src, 0, $close);
    }
}
