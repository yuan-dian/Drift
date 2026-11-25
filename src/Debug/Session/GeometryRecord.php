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

    /**
     * 从活盒取一份构造字段，不先造记录。
     *
     * 未变几何只需要签名，造 `GeometryRecord` 是白付的分配；字段清单留在这里，
     * 签名与记录共用同一份，避免两处漏字段却都"看起来正常"。
     *
     * @param object $box `Element::renderObject()` 的返回；Debug 层不 import Layout
     * @return array<string, mixed>
     */
    public static function fieldsOf(object $box, int $frame, int $node, int $parent): array
    {
        $size = $box->size();
        $mods = $box->mods();
        $style = $mods?->boxStyle();
        $align = $mods !== null ? $mods->alignment() : null;

        return [
            'frame' => $frame,
            'node' => $node,
            'parent' => $parent,
            'applied' => self::pack($box->appliedConstraints()),
            'inner' => self::pack($box->constraints()),
            'size' => ['w' => $size->width, 'h' => $size->height],
            'offset' => ['x' => $box->offsetX(), 'y' => $box->offsetY()],
            'pad' => self::edges(
                $mods !== null && $mods->hasPadding() ? $mods->padLeft() : 0.0,
                $mods !== null && $mods->hasPadding() ? $mods->padTop() : 0.0,
                $mods !== null && $mods->hasPadding() ? $mods->padRight() : 0.0,
                $mods !== null && $mods->hasPadding() ? $mods->padBottom() : 0.0,
            ),
            'inset' => self::edges(
                $mods !== null && $mods->hasInset() ? $mods->insetLeft() : 0.0,
                $mods !== null && $mods->hasInset() ? $mods->insetTop() : 0.0,
                $mods !== null && $mods->hasInset() ? $mods->insetRight() : 0.0,
                $mods !== null && $mods->hasInset() ? $mods->insetBottom() : 0.0,
            ),
            'box' => $mods !== null && ($mods->hasBoxWidth() || $mods->hasBoxHeight())
                ? ['w' => $mods->hasBoxWidth() ? $mods->boxWidth() : null,
                   'h' => $mods->hasBoxHeight() ? $mods->boxHeight() : null]
                : null,
            'aspect' => $mods !== null && $mods->hasAspect() ? $mods->aspect() : null,
            'align' => $align !== null ? ['x' => $align->x, 'y' => $align->y] : null,
            'border' => $style !== null && $style->hasBorder(),
            'borderWidth' => $style?->borderWidth ?? 0.0,
            'flex' => ['grow' => $mods?->flexGrow() ?? 0, 'shrink' => $mods?->flexShrink() ?? 0],
        ];
    }

    /** @param array<string, mixed> $f */
    public static function fromFields(array $f): self
    {
        return new self(
            (int) $f['frame'], (int) $f['node'], (int) $f['parent'],
            (array) $f['applied'], (array) $f['inner'], (array) $f['size'], (array) $f['offset'],
            (array) $f['pad'], (array) $f['inset'],
            \is_array($f['box']) ? $f['box'] : null,
            isset($f['aspect']) ? (float) $f['aspect'] : null,
            \is_array($f['align']) ? $f['align'] : null,
            (bool) $f['border'], (float) $f['borderWidth'], (array) $f['flex'],
        );
    }

    /** @param array<string, mixed> $f */
    public static function signatureOf(array $f): string
    {
        return \json_encode([
            'a' => $f['applied'], 'i' => $f['inner'], 's' => $f['size'], 'o' => $f['offset'],
            'p' => $f['pad'], 'n' => $f['inset'], 'b' => $f['box'], 'x' => $f['aspect'] ?? null,
            'g' => $f['align'], 'd' => $f['border'], 'w' => $f['borderWidth'], 'f' => $f['flex'],
        ], \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
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
        return self::signatureOf([
            'applied' => $this->applied, 'inner' => $this->inner,
            'size' => $this->size, 'offset' => $this->offset,
            'pad' => $this->pad, 'inset' => $this->inset,
            'box' => $this->box, 'aspect' => $this->aspect, 'align' => $this->align,
            'border' => $this->border, 'borderWidth' => $this->borderWidth, 'flex' => $this->flex,
        ]);
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

    /**
     * 证据包里的形状：**无损**，字段名与构造器一致。
     *
     * 与 {@see self::toArray()} 的差别是有意的：那边把 `inset` 叫 `margin`、把
     * `border` / `borderWidth` 拆成 `{present, width}`（投影层的读法），这边原样。
     * 若拿 `toArray()` 序列化，解码端就要记一张改名表，而改名表会漂。
     *
     * @return array<string, mixed>
     */
    public function encode(): array
    {
        return [
            'frame' => $this->frame,
            'node' => $this->node,
            'parent' => $this->parent,
            'applied' => $this->applied,
            'inner' => $this->inner,
            'size' => $this->size,
            'offset' => $this->offset,
            'pad' => $this->pad,
            'inset' => $this->inset,
            'box' => $this->box,
            'aspect' => $this->aspect,
            'align' => $this->align,
            'border' => $this->border,
            'borderWidth' => $this->borderWidth,
            'flex' => $this->flex,
        ];
    }

    /** @param array<string, mixed> $raw */
    public static function decode(array $raw): self
    {
        $box = $raw['box'] ?? null;
        $align = $raw['align'] ?? null;

        return new self(
            (int) ($raw['frame'] ?? 0),
            (int) ($raw['node'] ?? 0),
            (int) ($raw['parent'] ?? 0),
            self::bound((array) ($raw['applied'] ?? [])),
            self::bound((array) ($raw['inner'] ?? [])),
            self::pair((array) ($raw['size'] ?? []), 'w', 'h'),
            self::pair((array) ($raw['offset'] ?? []), 'x', 'y'),
            self::quad((array) ($raw['pad'] ?? []), 'l', 't', 'r', 'b'),
            self::quad((array) ($raw['inset'] ?? []), 'l', 't', 'r', 'b'),
            \is_array($box) ? [
                'w' => isset($box['w']) ? (float) $box['w'] : null,
                'h' => isset($box['h']) ? (float) $box['h'] : null,
            ] : null,
            isset($raw['aspect']) ? (float) $raw['aspect'] : null,
            \is_array($align) ? self::pair($align, 'x', 'y') : null,
            (bool) ($raw['border'] ?? false),
            (float) ($raw['borderWidth'] ?? 0.0),
            ['grow' => (int) ($raw['flex']['grow'] ?? 0), 'shrink' => (int) ($raw['flex']['shrink'] ?? 0)],
        );
    }

    /**
     * 一维约束四元组：`INF` 保持字符串。
     *
     * 不能一律 `(float)`：`INF` 会被转成 0，于是"父允许任意尺寸"变成"父要求 0 宽"，
     * 而两者推导出的布局结论相反。
     *
     * @param array<string, mixed> $raw
     * @return array{minW: float|string, maxW: float|string, minH: float|string, maxH: float|string}
     */
    private static function bound(array $raw): array
    {
        $out = [];
        foreach (['minW', 'maxW', 'minH', 'maxH'] as $key) {
            $value = $raw[$key] ?? 0.0;
            $out[$key] = $value === 'INF' ? 'INF' : (float) $value;
        }

        /** @var array{minW: float|string, maxW: float|string, minH: float|string, maxH: float|string} $out */
        return $out;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, float>
     */
    private static function pair(array $raw, string $a, string $b): array
    {
        return [$a => (float) ($raw[$a] ?? 0.0), $b => (float) ($raw[$b] ?? 0.0)];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, float>
     */
    private static function quad(array $raw, string $a, string $b, string $c, string $d): array
    {
        return [
            $a => (float) ($raw[$a] ?? 0.0),
            $b => (float) ($raw[$b] ?? 0.0),
            $c => (float) ($raw[$c] ?? 0.0),
            $d => (float) ($raw[$d] ?? 0.0),
        ];
    }
}
