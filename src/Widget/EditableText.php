<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\Rect;
use Drift\Input\KeyEvent;

/**
 * **可编辑文本面**的 State（`TextFieldState`；将来的 `TextArea` / `CodeEditor`）。
 *
 * ★ 存在的理由只有一条：输入环（`FocusManager::dispatchKey()` 的末环、
 *   `Application` 的 IME / 框选 / 剪贴板三处）要"焦点里那个在编辑的字段"。
 *   没有这个接口时那里写死 `instanceof TextFieldState` ⇒ 每加一种文本控件
 *   就得改 `FocusManager` 与 `Application`（框架核心去认具体控件 = 依赖方向
 *   反了，`Scrollable` 的同款理由）。
 *
 * ★ **它不承诺"文本存在哪"**：缓冲区在 `State` 还是 Store、受控还是非受控，
 *   实现自己定 —— 接口只管"编辑动作怎么落"，同 `Scrollable` 的判据。
 *
 * @api
 */
interface EditableText
{
    /** 在编辑中（焦点渲染态；`FocusManager` 经 `State::didChangeFocus()` 驱动） */
    public function isEditing(): bool;

    /**
     * 把一次按键翻译成编辑动作（路由链第 ③ 环的落点）。
     *
     * ★ 返回 `false` = 这一环没吃，**不改任何状态**（不偷偷吞键）——
     *   调用方（加速键环）原样继续。
     */
    public function handleKey(KeyEvent $event): bool;

    /** 插入符矩形（**盒内坐标**；IME 候选窗定位的几何来源） */
    public function caretRect(): ?Rect;

    /** 移动插入符到 `$offset`（**字节偏移**，吸附到字符边界），折叠既有选区 */
    public function moveCaret(int $offset): void;

    /** 扩展选区活动端到 `$offset`（吸附到字符边界）；锚点不动 */
    public function extendSelectionTo(int $offset): void;

    /**
     * 选中 `$offset` 所在的**词**（双击的手感来源）。
     *
     * ★★ **为什么在接口上，而不是让调用方自己算边界**：分词要**全文**，而本接口
     *   明确不承诺"文本存在哪"（见类注释：缓冲区可以在 State、也可以在 Store）。
     *   把算法放调用方就等于让它去要全文 —— 那正是这条抽象要挡住的东西。
     *   ⇒ 与 `moveCaret()` / `extendSelectionTo()` 同一族：**"编辑动作怎么落"**。
     * ★ `$offset` 必须是**指针位置换算出来的偏移**，不是当前插入符：双击发生在
     *   词的中间，而插入符还停在上一次点击处 —— 用后者会"选到另一个词"，且离得
     *   近时（同一个词里）完全看不出错。
     * ★ 落在空白 / 标点上时取**左侧那段**（`TextBoundary::wordAt()` 的两条取侧
     *   规则）；空文本 ⇒ 无事发生。
     */
    public function selectWordAt(int $offset): void;

    /**
     * 选中 `$offset` 所在的**段**（三击的手感来源；单行框里就是整串）。
     *
     * ★ "段"= `\n` 之间那一段（`\r\n` 归前一段），**不是**软换行折出来的视觉行：
     *   三击要的是"这一段话"，而软换行是排版的结果、随宽度变。
     */
    public function selectParagraphAt(int $offset): void;

    /** 有选区（锚点 ≠ 活动端） */
    public function hasSelection(): bool;

    /** 选中的文本（无选区 ⇒ 空串） */
    public function selectedText(): string;

    /** 用 `$text` 整段替换选区；无选区 ⇒ 退化成在插入符处插入 */
    public function replaceSelection(string $text): void;

    /** 删掉选区（无选区 ⇒ 无事发生） */
    public function deleteSelection(): void;
}
