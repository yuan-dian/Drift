<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 语义树的一个节点（§9 无障碍 / M5）。
 *
 * ★★ **一律标量字段** —— AOT 纪律：对象进数组有退化风险，标量可安全跨
 *   AOT 边界传递、可进关联数组、可作 Key。父子关系用 `parent` **索引**表达
 *   （`SemanticsTree::add()` 的返回值），不持对象引用。
 *
 * ★ `role` 存 **string**（`SemanticsRole->value`）而不是枚举对象 —— 同一个
 *   理由：节点要能进 `toArrays()` 的扁平关联数组。
 *
 * ★ `x/y/w/h` 是**逻辑像素**的客户区坐标（与 `BoxConstraints` / 视口同单位，
 *   §6.1 量化只发生在平台侧）—— 由 `SemanticsBuilder` 从渲染树算出。
 *   未布局 / 无盒的节点四者全 0 ⇒ `hitTest()` 跳过它（存在但不可见）。
 *
 * @api
 */
final class SemanticsNode
{
    /** 角色串（`SemanticsRole::Button->value` 等）；空 = 不该发生（树只收有角色的） */
    public string $role = '';

    /** 控件类名短名（去命名空间），如 `Button` —— 比 role 精确一档 */
    public string $type = '';

    /** 控件 `Key` 的字符串形式；无 Key 则空串 */
    public string $key = '';

    /** 人读 / 断言引用的名字，如按钮的 label、对话框的标题 */
    public string $name = '';

    /** 扁平的取值/状态，如文本框的当前文本 */
    public string $value = '';

    /**
     * 取值区间（只对 `range` 族角色有意义：`slider` / `progress`）。
     *
     * ★ `UIA IRangeValueProvider` 的 `Minimum`/`Maximum` 从这里出；非 range 角色
     *   恒 0/0（客户端不会去问）。与 `value` 同属"值"这一族，但分开存是因为
     *   `value` 是字符串（人读），区间是数值（契约必需）。
     */
    public float $rangeMin = 0.0;

    public float $rangeMax = 0.0;

    /** 是否可用（声明面；默认 true） */
    public bool $enabled = true;

    public int $x = 0;
    public int $y = 0;
    public int $w = 0;
    public int $h = 0;

    /** 是否持有焦点（FocusManager::focused() 命中本节点）；UIA focus changed 的判据 */
    public bool $focused = false;

    /**
     * 能否被 Tab 到（`FocusManager` 的可聚焦表里有它）。
     *
     * ★ 与 `focused` **正交**：这个回答"能不能进去"，那个回答"现在在不在里面"。
     * ★ 由框架填，不由控件声明：控件自己说"我能聚焦"会和 `skipFocus()` /
     *   `disabled()` 打架（`skipFocus()` 只挡 Tab 序、不挡方向键，见 §57.3）。
     */
    public bool $focusable = false;

    /**
     * 选中态三态：`0` = 这个角色没有选中概念 / `1` 未选 / `2` 已选。
     *
     * ★★ 三态是必需的：`0` 与 `1` 混成一个 `false`，每个按钮都会对客户端声称
     *   "我未选中"，UIA 侧就得给所有节点挂 `IsSelected`。
     */
    public int $selectState = 0;

    /** 展开态三态：`0` = 没有展开概念（叶子/非集合）/ `1` 折叠 / `2` 展开 */
    public int $expandState = 0;

    /** 层级（1-based，树行的业务深度）；`0` = 未声明 ⇒ 不给 `UIA_Level` */
    public int $level = 0;

    /** 父节点索引，-1 = 树根 */
    public int $parent = -1;

    public int $depth = 0;
}
