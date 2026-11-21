<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderText;
use Drift\State\Cell;
use Drift\Text\TextRole;
use Drift\Widget\Traits\TextStyleResolution;

/**
 * 高频值文本：值变只换渲染盒，**不重跑 build、不移动兄弟节点**。
 *
 * ```php
 * LiveText::of($store->count, '计数 ')   // 计数 / 进度 / 时钟这类每次都在变的值
 * ```
 *
 * ★ 与 {@see ReactiveText} 的分工：后者走 build（3 个元素，通用默认）；本类走
 *   {@see Spinner} 已验证的叶子路径（`createRenderObjectFromState()` 直接产盒）——
 *   `build()` 永不调用，值变只 `syncBox()` 换 `RenderText`。兄弟节点身份与布局都不动。
 *
 * ★ 只收 `Cell` 而非任意 `Readable`：高频直写的值必然是可写格子；`Computed` 派生值
 *   变化频率与源一致、用 `ReactiveText` 足够。窄入口让订阅就是一次 `watch()`，不必
 *   展开 `Computed::sources()` 那套（那属于 build 期的 `syncReactiveDeps()`）。
 *
 * ★ 已知边界：值未变（`Cell::set()` 设了同一个值）仍会换盒 —— 等串跳过要改 Cell 的
 *   set 语义（影响全局），本类不动它。需要等串门控的业务用 `Computed` + `ReactiveText`。
 *
 * @api
 */
final class LiveText extends StatefulWidget
{
    use TextStyleResolution;

    public function __construct(
        private readonly Cell $source,
        private readonly string $prefix = '',
        private readonly string $suffix = '',
    ) {
        parent::__construct();
    }

    /** @api */
    public static function of(Cell $source, string $prefix = '', string $suffix = ''): self
    {
        return new self($source, $prefix, $suffix);
    }

    private int $role = TextRole::Unset->value;
    private float $size = -1.0;
    private int $bold = -1;
    private int $maxLines = 0;
    private int $ellipsize = 0;

    public function title(): static { $this->mutate(); $this->role = TextRole::Title->value; return $this; }
    public function heading(): static { $this->mutate(); $this->role = TextRole::Heading->value; return $this; }
    public function body(): static { $this->mutate(); $this->role = TextRole::Body->value; return $this; }
    public function caption(): static { $this->mutate(); $this->role = TextRole::Caption->value; return $this; }
    public function error(): static { $this->mutate(); $this->role = TextRole::Error->value; return $this; }
    public function size(float $pt): static { $this->mutate(); $this->size = $pt; return $this; }
    public function bold(bool $v = true): static { $this->mutate(); $this->bold = $v ? 1 : 0; return $this; }
    public function maxLines(int $n): static { $this->mutate(); $this->maxLines = $n; return $this; }
    public function ellipsize(bool $v = true): static { $this->mutate(); $this->ellipsize = $v ? 1 : 0; return $this; }

    public function source(): Cell { return $this->source; }
    public function prefix(): string { return $this->prefix; }
    public function suffix(): string { return $this->suffix; }

    public function explicitSize(): ?float { return $this->size < 0.0 ? null : $this->size; }
    public function explicitBold(): ?bool { return match ($this->bold) { -1 => null, 0 => false, default => true }; }
    public function maxLinesValue(): int { return $this->maxLines; }
    public function ellipsizeValue(): bool { return $this->ellipsize === 1; }

    public function createState(): State
    {
        return new LiveTextState();
    }

    /**
     * 从 State 直接产盒（叶子路径）：无子、无 build，值变只 `syncBox()` 换盒。
     *
     * 样式解析与 {@see Text} 共用 {@see TextStyleResolution}，避免两份派生漂移。
     */
    #[\Override]
    public function createRenderObjectFromState(BuildContext $context, State $state): ?RenderBox
    {
        if (!$state instanceof LiveTextState) {
            Errors::report('LiveTextState 只能配 LiveText（框架内部不变量被破坏）');

            return null;
        }

        $role = TextRole::from($this->role);

        return new RenderText(
            $this->prefix . (string) $state->value() . $this->suffix,
            $this->resolvedStyle($context, $role),
            $this->resolvedColor($context, $role),
            $context->metrics(),
        );
    }
}

/**
 * `LiveText` 的值订阅：只在 initState 里挂一次，卸载自动退订。
 *
 * ★ 没有 `build()`：本控件走叶子路径，`StatefulElement::rebuild()` 只 `syncBox()`。
 *   `watch()` 的回调标脏本 State，触发的正是那条叶子重建。
 *
 * @internal
 */
final class LiveTextState extends State
{
    private Cell $source;

    public function initState(): void
    {
        $widget = $this->widget();
        if (!$widget instanceof LiveText) {
            Errors::report('LiveTextState 只能配 LiveText（框架内部不变量被破坏）');

            return;
        }

        $this->source = $widget->source();
        $this->watch($this->source, function (): void {
            $this->markDirty();
        });
    }

    /** 帧外读值（叶子路径没有 build 期的 Tracker，不走依赖登记） */
    public function value(): mixed
    {
        return $this->source->peek();
    }
}
