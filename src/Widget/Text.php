<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Core\Readable;
use Drift\Geometry\Scale;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderText;
use Drift\Rendering\Color;
use Drift\Text\TextRole;
use Drift\Text\TextStyle;
use Drift\Widget\Traits\TextStyleResolution;

/**
 * 文本控件（§15.2）。
 *
 * ★ **fluent-first 的唯一表面**：完整控制可走构造器，但**文档只教链式一种**
 *   （避免双表面分叉，A12）。链式方法统一 `mutate()` 开头、`return $this` 收尾。
 *
 * ★ **哨兵三档定则**（§15.2，消灭 A2——把合法值当"未设置"）：
 *   - 枚举语义 ⇒ enum 里加显式 case（`TextRole::Unset`，于是 0 是合法取值）
 *   - float      ⇒ 负值域外（`$size = -1.0`；尺寸非负）
 *   - bool 三态  ⇒ `-1` 未设置 / `0` 否 / `1` 是
 *   - int 有合法负数域 ⇒ 域外负值
 *   ⇒ 判据：**哨兵必须能写成一个"有名字的东西"**。裸 `0` 只允许出现在
 *     "该取值本身无意义"处（`$maxLines = 0` 表示不限 —— "0 行"不存在）。
 *
 * ★ **`->size()` / `->bold()` 优先于 `->role()`**：优先级只在 `ThemeData`
 *   解析一次（§15.7 纪律 3），不在这里做二轮合并。
 *
 * @api
 */
final class Text extends RenderBoxWidget
{
    use TextStyleResolution;

    private string $content;
    private int $role;
    private float $size = -1.0;
    private int $bold = -1;
    private int $maxLines = 0;
    private int $ellipsize = 0;

    public function __construct(string $content)
    {
        parent::__construct();
        $this->content = $content;
        // §15.2 哨兵：enum case 本身可作属性默认值，取 `->value` 不行（tpc gen_stub 只接受
        // ConstFetch/ClassConstFetch）⇒ 移到构造函数，语义不变。
        $this->role = TextRole::Unset->value;
    }

    /** 唯一入口 —— 只吃必填项（§15.2 纪律 1） */
    public static function make(string $content): self
    {
        return new self($content);
    }

    /**
     * 内容来自 `Readable` 的版本：读点落在文本自身，不必写 `Bind` 的 builder 闭包。
     *
     * ```php
     * Text::of($this->count, '计数 ')->body()
     * ```
     *
     * ★ 返回的是 `ReactiveText`（StatefulWidget），不是 `Text`：它要借一个 State
     *   来承载订阅。样式链与 `Text` 一致，运行期产出的仍是普通 `Text` 子树。
     *
     * @api
     */
    public static function of(Readable $source, string $prefix = '', string $suffix = ''): ReactiveText
    {
        return ReactiveText::of($source, $prefix, $suffix);
    }

    public function title(): static   { $this->mutate(); $this->role = TextRole::Title->value;   return $this; }
    public function heading(): static { $this->mutate(); $this->role = TextRole::Heading->value; return $this; }
    public function body(): static    { $this->mutate(); $this->role = TextRole::Body->value;    return $this; }
    public function caption(): static { $this->mutate(); $this->role = TextRole::Caption->value; return $this; }
    public function error(): static   { $this->mutate(); $this->role = TextRole::Error->value;   return $this; }

    public function size(float $pt): static      { $this->mutate(); $this->size = $pt; return $this; }
    public function bold(bool $v = true): static { $this->mutate(); $this->bold = $v ? 1 : 0; return $this; }
    public function maxLines(int $n): static     { $this->mutate(); $this->maxLines = $n; return $this; }
    public function ellipsize(bool $v = true): static { $this->mutate(); $this->ellipsize = $v ? 1 : 0; return $this; }

    public function content(): string
    {
        return $this->content;
    }

    public function role(): TextRole
    {
        return TextRole::from($this->role);
    }

    /** 显式字号；`-1.0` = 没给 ⇒ 由角色的字号接管 */
    public function explicitSize(): ?float
    {
        return $this->size < 0.0 ? null : $this->size;
    }

    /** 三态：`null` = 没给 ⇒ 由角色决定 */
    public function explicitBold(): ?bool
    {
        return match ($this->bold) {
            -1 => null,
            0 => false,
            default => true,
        };
    }

    public function maxLinesValue(): int
    {
        return $this->maxLines;
    }

    public function ellipsizeValue(): bool
    {
        return $this->ellipsize === 1;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderText(
            $this->content,
            $this->resolveStyle($context),
            $this->resolveColor($context),
            $context->metrics(),
        );
    }

    /**
     * 内容与派生样式一个不差才复用；否则交回框架造新盒。
     *
     * 不能就地改写：`paragraph` 只有 `performLayout()` 能刷新，直接换内容会画出旧字。
     *
     * ★ 派生逻辑必须与 `createRenderObject()` 同源（下面两个 resolve）：写成两份必然
     *   漂移 ⇒ "判等说相同、实际样式不同" ⇒ 复用出旧颜色且不报错。
     */
    #[\Override]
    public function updateRenderObject(RenderBox $box, BuildContext $context): bool
    {
        if (!$box instanceof RenderText) {
            return false;
        }

        return $box->matchesConfiguration(
            $this->content,
            $this->resolveStyle($context),
            $this->resolveColor($context),
            $context->metrics(),
            // 与 createRenderObject() 一致：`Text` 不暴露缩放，盒拿默认 1.0。
            Scale::identity(),
        );
    }

    /** §15.7 纪律 3：主题 token 与显式样式只在 trait 里合并一次 */
    private function resolveStyle(BuildContext $context): TextStyle
    {
        return $this->resolvedStyle($context, $this->role());
    }

    private function resolveColor(BuildContext $context): Color
    {
        return $this->resolvedColor($context, $this->role());
    }
}
