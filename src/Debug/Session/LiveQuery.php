<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

use Drift\Widget\Element;
use Drift\Widget\StatefulElement;

/**
 * 只在**当下**成立的查询：活树内省、点选反查、高亮状态。
 *
 * ## 为什么它们不跟证据查询住在一起（A55）
 *
 * 判据只有一条：一条事实只有在"AOT 产物里也能用纯标量复现"时才算证据。属性、
 * 内存存活跨度、调用栈位置做不到 —— 那一帧过去后活树已经是新的，把当时的属性
 * 写进证据等于声称一件无法验证的事。它们的位置是 **L2 内省**：只在 dev、只在
 * 当下、不进证据包、不进指纹。
 *
 * 于是这个类有三个别处没有的性质，混在证据查询里就会被慢慢抹平：
 *
 * 1. **必须注入活树**（`props` / `pick` / `memory`）。离线包没有活应用，返回空会被
 *    读成"这个节点没有字段"，所以由派发层在注入回调缺失时明确拒绝。
 * 2. **不写任何记录**：字段用反射现读、命中走生产 `hitTest` 的唯一实现，都不落盘。
 * 3. **帧号不是参数**：给一个历史帧号却返回当前值就是"伪称对齐"。返回值里的
 *    `liveFrame` 明说这是哪一帧读到的。
 *
 * ## 高亮为什么也在这里
 *
 * 它不是证据：高亮不改结构，进 `FrameRecord` 就等于把它算进因果指纹，而"高亮前后
 * 因果逐值相同"正是要证明的东西。它也不读记录，只读会话的叠加层状态。
 *
 * @internal 门面 {@see Query} 的实现细节，不是应用开发者的 API 面
 */
final class LiveQuery
{
    /** L2 内存台账：跨多次 memory() 查询累积同一本 WeakMap，不进证据包（A55）。 */
    private ?MemoryProbe $memoryProbe = null;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly History $history,
    ) {
    }

    /**
     * 进程内存 + 活树对象的跨帧内省（L2，不是证据）。
     *
     * ★ 必须注入活树：内存看的是**现在**有哪些对象、活了多久；离线包没有活树，
     *   返回空会读成"没有对象"，所以由派发层在注入回调缺失时明确拒绝。
     * ★ 不写 Recorder、不进包、不进指纹：只有结构上可复算的标量才能进证据，而
     *   "对象活了几帧"离开当下就无法重算。
     *
     * @param list<Element> $roots
     * @return array<string, mixed>
     */
    public function memory(array $roots, int $limit = 40): array
    {
        if (!MemoryProbe::available()) {
            return ['found' => false, 'reason' => 'memory-unavailable'];
        }
        $this->memoryProbe ??= new MemoryProbe();
        $answer = $this->memoryProbe->sample($roots, $this->history->lastFrameId(), $limit);
        $answer['found'] = true;
        $answer['limit'] = $limit;

        return $answer;
    }

    /**
     * 把 `site`（`file:line`）解释成方法定义位置（L2，不是证据）。
     *
     * 与 `why()` / `hotspots()` 给出的 `site` 同源：那些输出里的是一个地址字符串，
     * 这里把它翻译成"在哪个类的哪个方法里、定义在第几行"。纯读，不碰活树与记录。
     *
     * @return array<string, mixed>
     */
    public function source(string $site): array
    {
        return SourceLocator::locate($site);
    }

    /**
     * 按节点读 Widget / State 配置（dev 反射，纯内省，不是证据）。
     *
     * ★ 帧号不是参数：属性读的是**现在**的活树，给一个历史帧号却返回当前值
     *   就是"伪称对齐"。返回值里的 `liveFrame` 明说这是哪一帧读到的。
     *
     * ★ 不写任何记录（A55）：这一帧过去后对象已变，把字段写进证据包等于声称
     *   一件无法复算的事。字段用 {@see ObjectDump} 的反射原语现读，与 `eval`
     *   的 `$node` 同源 —— 同一个对象在两个入口看到同一份字段。
     *
     * @param list<Element> $roots 全部树根（主树 + 浮层），由派发层给 —— Debug 层不依赖 App
     * @return array<string, mixed>
     */
    public function props(int $node, array $roots, int $maxDepth = 3, int $maxFields = 120): array
    {
        if (!ObjectDump::available()) {
            return ['found' => false, 'node' => $node, 'reason' => 'reflection-unavailable'];
        }

        $element = $this->findElement($roots, $node);
        if ($element === null) {
            // "号没发过"与"号发过但节点已卸载"必须分开：前者是地址写错，后者是活树里已经没有它。
            $status = $this->history->nodeStatus($node);
            $known = $this->history->nodeIndex()[$node] ?? null;

            return [
                'found' => false,
                'node' => $node,
                'reason' => match ($status) {
                    'evicted' => 'node-evicted',
                    'active' => 'node-unmounted',
                    default => 'unknown-node',
                },
                'type' => $known['c'] ?? null,
            ];
        }

        $t0 = \hrtime(true);
        $widget = $element->widget();
        $state = $element instanceof StatefulElement ? $element->state() : null;
        $redact = $this->history->redactionEnabled();

        $w = ObjectDump::fields($widget, $maxDepth, $maxFields, $redact);
        $s = $state === null
            ? ['fields' => [], 'omitted' => 0, 'cut' => 0, 'cycles' => 0, 'redacted' => 0, 'redact' => $redact]
            : ObjectDump::fields($state, $maxDepth, $maxFields, $redact);

        return [
            'found' => true,
            'liveFrame' => $this->history->lastFrameId(),
            'widget' => self::shortClass($widget::class),
            'element' => self::shortClass($element::class),
            'state' => $state === null ? null : self::shortClass($state::class),
            'fields' => \array_merge($w['fields'], $s['fields']),
            'omitted' => $w['omitted'] + $s['omitted'],
            'cut' => $w['cut'] + $s['cut'],
            'cycles' => $w['cycles'] + $s['cycles'],
            'redacted' => $w['redacted'] + $s['redacted'],
            'redact' => $redact,
            'costMs' => (\hrtime(true) - $t0) / 1e6,
        ];
    }

    /**
     * 在多棵树里按节点号找活元素。
     *
     * 查找本身住 {@see Element::findInRoots()}：叠加层（高亮）也要同一个查找，
     * 而它拿不到这个类。两份递归迟早漂，且漂出来的表现是"某个深度以外永远
     * 查不到"——正是这次修掉的那类静默。
     *
     * @param list<Element> $roots
     */
    private function findElement(array $roots, int $node): ?Element
    {
        return Element::findInRoots($roots, $node, fn(Element $e): bool => $this->recorder->owns($e));
    }

    /**
     * 点选反查：视口坐标 ⇒ 命中链（根 → 最深的命中盒）。
     *
     * ★ 不写生产盒字段：命中走 `RenderBox::hitTest()` 的唯一实现，反查走
     *   `Element::boxOwners()` 的按需映射（焦点链已用的那一份）。这两样都只在
     *   查询发生时跑，录制没开也能用，AOT 可达面不变（A44）。
     *
     * ★ `chain` 给的是**完整盒链**，不是只给最底层盒：包装层是谁、自绘内容落在
     *   哪个盒里，正是布局调试要回答的问题。没有 Element 认领的盒照实列出
     *   （`node = null`），不悄悄跳过，也不把父节点冒充成它的拥有者。
     *
     * ★ 未命中与没树根分开报：点空了不是"点到了根节点"。
     *
     * @param list<array{element: Element, x: float, y: float}> $roots 主树在前、浮层在后
     * @return array<string, mixed>
     */
    public function pick(float $x, float $y, array $roots): array
    {
        $base = [
            'x' => $x,
            'y' => $y,
            'liveFrame' => $this->history->lastFrameId(),
            'roots' => 0,
        ];

        $elements = [];
        foreach ($roots as $entry) {
            $element = $entry['element'] ?? null;
            if ($element instanceof Element) {
                $elements[] = $element;
            }
        }
        $base['roots'] = \count($elements);
        if ($elements === []) {
            return $base + [
                'found' => false,
                'reason' => 'no-roots',
                'tree' => null,
                'scope' => null,
                'hit' => null,
                'chain' => [],
                'ownerless' => 0,
            ];
        }

        // 映射建一次，链上每个盒共用；焦点反查走同一个方法，口径不会分叉。
        $owners = Element::boxOwners($elements);

        // 浮层在上、主树在下：与 InputRouter 的命中顺序一致。
        for ($i = \count($roots) - 1; $i >= 0; $i--) {
            $root = $roots[$i];
            $element = $root['element'] ?? null;
            if (!$element instanceof Element) {
                continue;
            }

            // 视口坐标减根原点：浮层不是从 (0,0) 起画，漏了就整体偏一个锚点。
            $hit = $element->effectiveBox()?->hitTest(
                $x - (float) ($root['x'] ?? 0.0),
                $y - (float) ($root['y'] ?? 0.0),
            );
            if ($hit === null) {
                continue;
            }

            $chain = [];
            $ownerless = 0;
            // Debug 层不 import Layout：沿渲染父链走，遇到没有 size() 的对象（sliver 等）
            // 就停。事实仍来自生产盒本身，查询只把它们压成标量。
            for ($box = $hit; $box !== null && \method_exists($box, 'size'); $box = $box->parent()) {
                $owner = $owners[\spl_object_id($box)] ?? null;
                if ($owner === null) {
                    $ownerless++;
                }
                $chain[] = self::boxFact($box, $owner, $this->recorder, $this->history);
            }
            $chain = \array_reverse($chain);
            $last = $chain[\count($chain) - 1];

            return $base + [
                'found' => true,
                'reason' => null,
                'tree' => $i,
                'scope' => $i === 0 ? 'main' : 'overlay',
                'hit' => $last,
                'chain' => $chain,
                'ownerless' => $ownerless,
            ];
        }

        return $base + [
            'found' => false,
            'reason' => 'no-hit',
            'tree' => null,
            'scope' => null,
            'hit' => null,
            'chain' => [],
            'ownerless' => 0,
        ];
    }

    /**
     * 链上一环的标量事实；`node` 只由会话发号，查询不自造地址。
     */
    private static function boxFact(object $box, ?Element $owner, Recorder $recorder, History $history): array
    {
        $size = $box->size();
        $fact = [
            'box' => self::shortClass($box::class),
            'size' => $size === null ? null : ['width' => $size->width, 'height' => $size->height],
            'node' => null,
            'widget' => null,
            'element' => null,
            'state' => null,
            'path' => null,
        ];
        if ($owner === null) {
            return $fact;
        }

        $node = $recorder->idOf($owner);
        $state = $owner instanceof StatefulElement ? $owner->state() : null;
        $fact['node'] = $node;
        $fact['widget'] = $history->typeOf($node);
        $fact['element'] = self::shortClass($owner::class);
        $fact['state'] = $state === null ? null : self::shortClass($state::class);
        $fact['path'] = $history->pathOf($node);

        return $fact;
    }

    /**
     * 高亮状态：**现在高亮谁、什么时候请求的、最后一次真的上屏在哪一帧**。
     *
     * ## 为什么 `onScreen` 要单独算
     *
     * "请求了高亮"与"高亮到了屏幕上"是两件事，中间隔着一次绘制。元素已卸载、
     * 盒没有尺寸、帧被整段跳过，都会让请求停在第一件上 —— 而屏幕上什么都没有，
     * 静默。三个字段一起给，读者能自己判断失败在哪一环：
     *
     * | `node` | `drawnFrame` | 含义 |
     * |---|---|---|
     * | `0` | 任意 | 没有高亮（`drawnFrame` 是历史上最后一次画的，只作参考） |
     * | `>0` | `null` | 请求了，但一帧都还没画过 |
     * | `>0` | `< askedFrame` | 请求了，但还没画到它（`onScreen=false`） |
     * | `>0` | `>= askedFrame` 且 `drawnNode` 相同 | 就在屏幕上（`onScreen=true`） |
     *
     * ★ 号对得上还不够：清掉再一次高亮同一个号时，`drawnFrame` 会停在**上一次**
     *   那个号上屏的帧。比 `askedFrame` 就是为了把这种"旧画"与"新请求"分开。
     *
     * ★ `frame` 是当前帧号（`beginFrame()` 数出来的），不是末个落盘帧：查询发生在
     *   两帧之间时两者相同，而 `frames` 窗口一帧都没落盘时后者是 0。
     *
     * @return array<string, mixed>
     */
    public function highlight(): array
    {
        $node = $this->history->highlightedNode();
        $askedFrame = $this->history->highlightFrame();
        $drawn = $this->history->overlayDrawn();

        return [
            'node' => $node,
            'type' => $node > 0 ? $this->history->typeOf($node) : null,
            'path' => $node > 0 ? $this->history->pathOf($node) : null,
            'askedFrame' => $askedFrame,
            'drawnNode' => $drawn === null ? null : $drawn['node'],
            'drawnFrame' => $drawn === null ? null : $drawn['frame'],
            'onScreen' => $node > 0
                && $drawn !== null
                && $drawn['node'] === $node
                && $drawn['frame'] >= $askedFrame,
            'frame' => $this->history->frameId(),
        ];
    }

    private static function shortClass(string $class): string
    {
        $pos = \strrpos($class, '\\');

        return $pos === false ? $class : \substr($class, $pos + 1);
    }
}
