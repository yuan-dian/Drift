<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

/**
 * 所有控件的根基类（§15.2）。
 *
 * fluent-first：唯一表面，全部 API 活在同一个类上 —— 不搞"构造器 vs 链式"
 * 双表面（那正是 GUI 仓"糖自造状态路径"的根源，A12）。
 *
 * 链式方法统一以 `mutate()` 开头、`return $this` 收尾；挂载时 Element 调
 * `seal()` 封存，封存后再写 ⇒ Fatal（A11 的守卫）。
 *
 * @api
 */
abstract class Widget
{
    /** @internal 0 = 可配置（构建期）；1 = 已封存（挂载后） */
    private int $sealed = 0;

    /** @internal null = 无 Key ⇒ diff 身份由位置决定（§12.3） */
    private ?Key $key = null;

    /**
     * ★ 子类必须调 `parent::__construct()`。
     *
     * @api
     */
    public function __construct()
    {
    }

    /**
     * 给本控件一个跨位置稳定的身份（§12.3）。
     *
     * 不给 ⇒ 身份由在 `children([...])` 里的位置决定；给了 ⇒ 重排也能保住 `State`。
     * `ForEach` 的 key 是**必填**的（§16.6.1 R4），因为列表重排最需要它。
     *
     * @api
     */
    public function key(string|int $value): static
    {
        $this->mutate();
        $this->key = new Key($value);

        return $this;
    }

    /**
     * 子层拿到的渲染环境（§2446：主题 scope 链的扩展点）。
     *
     * 默认原样递下去。**只有"面覆盖"型节点才覆写**（目前 `Drift\Theme\ThemeScope`
     * 换主题；将来方向 / 本地化同形）。返回类型是 `BuildContext` 而不是更具体的
     * 类型 —— 所以 `Drift\Widget` 不需要认识 `Drift\Theme`（§4.3 的协议例外
     * 仍然只有 `BuildContext.php` 一个文件）。
     *
     * @internal Element 挂载 / 更新时调它，用户不需要。
     */
    public function childContext(BuildContext $context): BuildContext
    {
        return $context;
    }

    /**
     * 造 Element —— **唯一入口**（W1 同源：单一造盒路径）。
     *
     * ★ 收口成一处 `instanceof`，而不是让每个控件子类各写一遍 `return new
     *   XxxElement($this)`：45 个控件抄同一句话，漏抄的不会报错，只会静默走错
     *   元素类型。要特殊元素的（容器）可以覆写本方法。
     *
     * @internal 用户不该自己 new Element。
     */
    public function createElement(): Element
    {
        return $this instanceof StatefulWidget
            ? new StatefulElement($this)
            : new StatelessElement($this);
    }

    /**
     * diff 用的身份（§12.3）：`null` = 由位置决定。
     *
     * @internal Element 调和时读它，用户不需要（给了 Key 就已经生效）。
     */
    public function identity(): ?Key
    {
        return $this->key;
    }

    /**
     * 挂载时由 Element 调用：把描述封存为不可变。
     *
     * 幂等 —— 同一实例被复用到多处是**合法**的（Widget 是纯描述，各处各有
     * 自己的 Element），所以这里不做任何 owner 校验。`Column::make()->children([$t, $t])`
     * 常见且合法，做 owner 校验是误杀。
     *
     * @internal 不进公共面：用户不需要它，"挂载时自动封存"已覆盖全部合法场景（§15.6.1）。
     */
    final public function seal(): void
    {
        $this->sealed = 1;
    }

    /** @internal */
    final public function isSealed(): bool
    {
        return $this->sealed === 1;
    }

    /**
     * 链式修饰的统一入口 —— 一个口径一个落点，不是每方法各写一遍守卫。
     *
     * 封存后写入 ⇒ 响亮失败（Fatal 级，release 也中止，§1.2）。
     *
     * @internal
     */
    final protected function mutate(): void
    {
        if ($this->sealed === 1) {
            Errors::report('Widget 已封存，禁止再修改：' . static::class);
        }
    }
}
