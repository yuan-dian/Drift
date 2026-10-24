<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 选项表的**就绪度**（W8-2，2026-10-24）—— `Select` / `ComboBox` 共用的三态。
 *
 * ```php
 * Select::make($this->cities)
 *     ->status(OptionStatus::loading())              // 取数中
 *     ->status(OptionStatus::error('连接超时'))       // 失败
 *     ->status(OptionStatus::empty('没有匹配的城市')) // 取到了但是空
 *     ->status(OptionStatus::ready());               // 正常（默认）
 * ```
 *
 * ## 为什么是**不可变值**，而不是三个字段
 *
 * ★★ 三个独立字段（`loading` / `emptyText` / `errorText`）必然要回答一个问题：
 *   **同时成立时显示谁**。2026-10-24 之前的答案是"`loading` 优先"，还专门写了一条
 *   smoke 守它。但那个场景本身就不该存在 —— 用户点"重试"时，应用要的是"现在是
 *   加载中"，不是"既是错误又是加载中、让控件挑一个"。
 *   ⇒ 本类把三态做成**互斥的值**：设了 `loading()`，错误态就**不存在了**。
 *   优先级规则从"运行时约定"变成"类型上不可能违反"。
 *
 * ★ 也因此**没有** `setLoading(false)` 这种写法：要回正常态就 `ready()`。
 *   有"关"的开关，就又能拼出"不在加载、也没错、但也不正常"这种第三种含糊状态。
 *
 * ## 为什么是 `int` + `===` 而不是四个子类 / enum with payload
 *
 * ★ 与 `OverlayEntry::BELOW` 同一条纪律：tpc 对 `switch` 生成 C2440，本仓全域是
 *   `int` 常量 + `===` 链。四个子类会引入多态派发（§9 P3 的去虚化缺陷面）。
 *
 * @api
 */
final class OptionStatus
{
    /** 正常：面板画选项行 */
    public const READY = 0;

    /** 取数中：面板只画一行"正在加载…" */
    public const LOADING = 1;

    /** 取到了但是空表：面板只画一行提示 */
    public const EMPTY = 2;

    /** 取数失败：面板只画一行错误 */
    public const ERROR = 3;

    private function __construct(
        private readonly int $kind,
        private readonly string $text,
    ) {
    }

    /** 正常态（`Select::make()` / `ComboBox::make()` 的初始值） */
    public static function ready(): self
    {
        return new self(self::READY, '');
    }

    /**
     * 取数中。文案固定"正在加载…"—— 这是个**事实**，不是可配置的文案
     * （要换语言是 i18n 的事，不是每个调用点自己编一句）。
     */
    public static function loading(): self
    {
        return new self(self::LOADING, '正在加载…');
    }

    /**
     * 空表。默认"没有可选项"。
     *
     * ★ 与"选项表恰好是空的、但状态是 `ready()`"不同：那种情况由控件用**内置**
     *   兜底文案画（`Select` = "没有可选项"，`ComboBox` = "无匹配项"）——
     *   两者语义不同（"没东西可选" vs "搜不到"），所以兜底留在各自控件里，
     *   只有"应用**主动**声明这是空态"才用本方法自定义文案。
     */
    public static function empty(string $text = '没有可选项'): self
    {
        return new self(self::EMPTY, $text);
    }

    /** 取数失败。文案必填：错误信息只有应用知道（"连接超时" / "无权限"…） */
    public static function error(string $text): self
    {
        return new self(self::ERROR, $text);
    }

    public function kind(): int
    {
        return $this->kind;
    }

    public function text(): string
    {
        return $this->text;
    }

    /** 面板该画选项行吗（`false` ⇒ 画 `row()` 那一行） */
    public function showsOptions(): bool
    {
        return $this->kind === self::READY;
    }

    /**
     * 面板里那一行（`showsOptions()` 为真 ⇒ `null`）。
     *
     * ★ 返回 `?Widget` 而不是"总是返回一个 Widget、正常态给个空 `Column`"：
     *   空 `Column` 会在面板里占一行高度，表现为"列表上方莫名多一条空白"。
     */
    public function row(): ?Widget
    {
        if ($this->kind === self::READY) {
            return null;
        }

        return Text::make($this->text)->caption();
    }

    /**
     * 面板内容签名里的那一段（见 `Widget::overlaySignature()`）。
     *
     * ★ 两个控件共用这一处：三态是**受控属性**，变了面板就得重读。分开写两份
     *   迟早在某一处漏掉 `empty` 那一支（漏了的表现：换空态文案，面板不动）。
     */
    public function signature(): string
    {
        if ($this->kind === self::READY) {
            return '';
        }

        if ($this->kind === self::LOADING) {
            return 'loading';
        }

        return $this->kind === self::EMPTY ? 'empty:' . $this->text : 'error:' . $this->text;
    }
}
