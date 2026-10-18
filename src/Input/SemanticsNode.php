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

    /** 是否可用（声明面；默认 true） */
    public bool $enabled = true;

    public int $x = 0;
    public int $y = 0;
    public int $w = 0;
    public int $h = 0;

    /** 父节点索引，-1 = 树根 */
    public int $parent = -1;

    public int $depth = 0;
}
