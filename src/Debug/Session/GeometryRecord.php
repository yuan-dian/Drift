<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

use Drift\Geometry\BoxConstraints;

/**
 * 一帧里一个节点的几何事实。
 *
 * 为什么是"事实"而不是"活盒的引用"：证据要能导出、能跨进程读回。若检查器在查询时
 * 去遍历活 Element 树，attach 时能用、导出后全废 —— 那不是证据，是另一个会话的
 * 快照。所以几何在帧边界采成标量，查询只读这份记录。
 *
 * 为什么要签名：一帧 2000 个节点全存撑不住。签名相同（几何没变）就不记这一帧，
 * 查询时从目标帧往前找最近的一条。代价 O(变化量)，不是 O(帧数 × 节点数)。
 *
 * 无界约束用字符串 `INF` 而不是浮点 INF：后者 json_encode 会失败，证据包就导不出。
 * 没设的修饰用 null，不拿 0 顶替 —— "没设宽"和"宽是 0"是两句不同的话。
 *
 * @api
 */
final readonly class GeometryRecord
{
    /**
     * @param array{minW: float, maxW: float, minH: float, maxH: float} $applied
     *        `layout()` 末尾的 `appliedConstraints`：父给的、经本盒 mods.tighten 之后
     * @param array{minW: float, maxW: float, minH: float, maxH: float} $inner
     *        `lastConstraints`：再经 deflate(padding) / loosen(align) 后的内容盒约束
     * @param array{w: float, h: float} $size
     * @param array{x: float, y: float} $offset
     * @param array{l: float, t: float, r: float, b: float} $pad
     * @param array{l: float, t: float, r: float, b: float} $inset
     * @param array{w: float|null, h: float|null}|null $box 单边没设⇒该维 null，不写 NAN
     * @param array{x: float, y: float}|null $align
     * @param array{grow: int, shrink: int} $flex
     */
    public function __construct(
        public int $frame,
        public int $node,
        public int $parent,
        public array $applied,
        public array $inner,
        public array $size,
        public array $offset,
        public array $pad,
        public array $inset,
        public ?array $box,
        public ?float $aspect,
        public ?array $align,
        public bool $border,
        public float $borderWidth,
        public array $flex,
    ) {
    }

    /** @return array{minW: float|string, maxW: float|string, minH: float|string, maxH: float|string} */
    public static function pack(?BoxConstraints $c): array
    {
        if ($c === null) {
            return ['minW' => 0.0, 'maxW' => 'INF', 'minH' => 0.0, 'maxH' => 'INF'];
        }

        return [
            'minW' => $c->minWidth,
            'maxW' => \is_infinite($c->maxWidth) ? 'INF' : $c->maxWidth,
            'minH' => $c->minHeight,
            'maxH' => \is_infinite($c->maxHeight) ? 'INF' : $c->maxHeight,
        ];
    }

    /**
     * 把快照里的约束还原成 BoxConstraints 供复算用。
     *
     * @param array{minW: float|string, maxW: float|string, minH: float|string, maxH: float|string} $c
     */
    public static function unpack(array $c): BoxConstraints
    {
        $num = static fn(float|string $v): float => $v === 'INF' ? INF : (float) $v;

        return new BoxConstraints(
            $num($c['minW']),
            $num($c['maxW']),
            $num($c['minH']),
            $num($c['maxH']),
        );
    }

    /** @return array{l: float, t: float, r: float, b: float} */
    public static function edges(float $l, float $t, float $r, float $b): array
    {
        return ['l' => $l, 't' => $t, 'r' => $r, 'b' => $b];
    }

    /** 不含 frame/node/parent —— 那三个变了不代表几何变了。 */
    public function signature(): string
    {
        return \json_encode([
            'a' => $this->applied, 'i' => $this->inner, 's' => $this->size, 'o' => $this->offset,
            'p' => $this->pad, 'n' => $this->inset, 'b' => $this->box, 'x' => $this->aspect,
            'g' => $this->align, 'd' => $this->border, 'w' => $this->borderWidth, 'f' => $this->flex,
        ], \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'frame' => $this->frame,
            'node' => $this->node,
            'parent' => $this->parent,
            'applied' => $this->applied,
            'inner' => $this->inner,
            'size' => $this->size,
            'offset' => $this->offset,
            'margin' => $this->inset,
            'padding' => $this->pad,
            'box' => $this->box,
            'aspect' => $this->aspect,
            'align' => $this->align,
            'border' => ['present' => $this->border, 'width' => $this->borderWidth],
            'flex' => $this->flex,
        ];
    }
}
