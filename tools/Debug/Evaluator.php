<?php
declare(strict_types=1);

namespace Drift\Dev\Debug;

use Drift\App\DebugSession;
use Drift\Widget\Element;

/**
 * dev 壳的任意表达式求值（A55：内省不是证据）。
 *
 * ## 为什么住在这里，不做成查询名
 *
 * 表达式要摸**活应用**（`$app` / `$node`），而活应用只在宿主进程里；
 * `QueryRouter` 那层刻意不认识应用（离线包没有应用），做成 `query('eval')`
 * 会让"离线复算逐值相同"这条判据当场失效。`effect()` 也不行 —— 它
 * `noteEffect()` 会把求值写进脚本，而 eval 明确不进包、不进指纹。
 * 所以它是协议里的独立动词，执行权只在宿主手里。
 *
 * ## 作用域注入什么
 *
 * 三个变量，够覆盖"临时追问"的绝大多数场景，又不把整个宿主的内部结构
 * 暴露成隐式 API：
 *
 * - `$app`     —— 活应用，能拿到 treeRoots() / root() 等；
 * - `$session` —— 会话门面，能 query() 任何具名查询；
 * - `$node`    —— 给定节点 id 时是解析出的 Element，否则 null。
 *
 * 参照 Chrome Console 的 `$0` 与 Flutter Inspector 的上下文注入：给一个
 * 明确的入口，别让用户猜"我该从哪个全局对象开始"。
 *
 * ## 回值为什么要规范化
 *
 * `eval()` 可能返回对象、闭包、资源，它们编不成 JSON，宿主 `ok()` 只能回
 * 一条 `call_failed`，用户看不到值。这里按 PHP 原语把结果压成 JSON-safe：
 * 标量原样，数组递归，对象走 `__debugInfo()`（有就用）/ 公开属性，其余回
 * 类型占位。这是 A55 说的"交给 PHP 原语"——不自己造一套反射 dump。
 * 深度与宽度有上限，防止一个 `return $app;` 把整棵活树拉进管道。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class Evaluator
{
    /** 结果树的最大深度。超过回 `…` 占位，不继续展开。 */
    private const int MAX_DEPTH = 4;

    /** 每层最多列多少项 / 字段。 */
    private const int MAX_ITEMS = 40;

    /**
     * @param int $nodeId 节点 id；`0` = 不注入 `$node`
     *
     * @return array{
     *   expression: string,
     *   value: mixed,
     *   type: string,
     *   node: int,
     *   evidenceChanged: bool,
     *   truncated: bool
     * }
     */
    public function evaluate(DebugSession $session, string $expression, int $nodeId = 0): array
    {
        $expression = \trim($expression);
        if ($expression === '') {
            throw new \InvalidArgumentException('表达式不能为空');
        }
        // 允许写 `$app->root()` 或 `$app->root();`，也允许贴 `<?php` 前缀。
        // 只剥首尾，不改写中间 —— 表达式的语义必须与原样输入一致。
        $expression = \rtrim($expression, "; \t\n\r\0\x0B");
        if (\str_starts_with($expression, '<?php')) {
            $expression = \ltrim(\substr($expression, 5));
        }
        if ($expression === '') {
            throw new \InvalidArgumentException('表达式不能只有分号');
        }

        $app = $session->app();
        $node = $nodeId > 0 ? self::resolveNode($session, $nodeId) : null;

        // 污染检测：求值前后各取一次指纹。求值本身若改了活应用（比如
        // `$app->setDebugHighlight(...)`），结构因果会变；如实报出来，别让
        // 用户拿着"求值前那份证据"解释求值后的行为。
        $before = $session->query('digest');

        // PHP 的 eval 是语言结构，只能在当前作用域执行；局部变量在这里可见。
        // 用 `return (...)` 包一层，让"表达式"而非"语句"成为契约：分号语句
        // 在这里会语法错，是正确的拒绝（要执行语句就该用驱动脚本，不是内省）。
        // 但表达式内部仍可能改状态（赋值表达式、带副作用的方法调用），那由
        // 上面的指纹对比负责暴露。
        $value = eval('return (' . $expression . ');');

        $after = $session->query('digest');

        $truncated = false;
        $normal = self::normalise($value, 0, $truncated);

        return [
            'expression' => $expression,
            'value' => $normal,
            'type' => \get_debug_type($value),
            'node' => $nodeId,
            'evidenceChanged' => $before !== $after,
            'truncated' => $truncated,
        ];
    }

    /**
     * 解析节点 id。用与 `highlight` 同一条路径（findInRoots + owns），
     * 保证 eval 里看到的 `#N` 与 `props` / `node` 出口看到的是同一个节点。
     */
    private static function resolveNode(DebugSession $session, int $id): ?Element
    {
        $recorder = $session->recorder();

        return Element::findInRoots(
            $session->app()->treeRoots(),
            $id,
            static fn(Element $e): bool => $recorder->owns($e),
        );
    }

    /** 把任意 PHP 值压成 JSON-safe 的形状。 */
    private static function normalise(mixed $value, int $depth, bool &$truncated): mixed
    {
        if ($value === null || \is_scalar($value)) {
            return $value;
        }
        if ($depth >= self::MAX_DEPTH) {
            $truncated = true;

            return '…';
        }

        if (\is_array($value)) {
            $out = [];
            $i = 0;
            foreach ($value as $key => $item) {
                if ($i++ >= self::MAX_ITEMS) {
                    $truncated = true;
                    break;
                }
                $out[$key] = self::normalise($item, $depth + 1, $truncated);
            }

            return $out;
        }

        if (\is_object($value)) {
            // __debugInfo 是 PHP 自己的协议：类怎么呈现自己由类决定，
            // 我们不替它列字段。没有就退回公开属性 —— 与 var_dump 同源。
            $fields = \method_exists($value, '__debugInfo') ? $value->__debugInfo() : \get_object_vars($value);
            if (!\is_array($fields)) {
                // __debugInfo() 约定返回数组；不守约定的类只回类型名，不猜它的内部结构。
                return ['__class' => $value::class];
            }
            $out = ['__class' => $value::class];
            $i = 0;
            foreach ($fields as $key => $item) {
                if ($i++ >= self::MAX_ITEMS) {
                    $truncated = true;
                    break;
                }
                $out[$key] = self::normalise($item, $depth + 1, $truncated);
            }

            return $out;
        }

        // resource / closure / 未知类型：占位，不暴露句柄。
        $truncated = true;

        return '<' . \get_debug_type($value) . '>';
    }
}
