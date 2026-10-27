<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 单行文本的**编辑状态机**：缓冲 + 插入符 + 选区，零 Widget / 零 State 依赖。
 *
 * ## 为什么它单独存在（而不是留在 `TextFieldState` 里）
 *
 * 文本编辑这件事有两个消费面，而它们**只剩"文本状态机"这一半相同**：
 *
 * | | 文本状态机 | 焦点 / 通知 | 渲染 | 键位 |
 * |---|---|---|---|---|
 * | `TextFieldState` | ✔ 同一份 | 自己有焦点节点 | 输入"井" | 编辑键 + `Esc` 吃选区 |
 * | `TableState`（单元格编辑） | ✔ 同一份 | 焦点在**表**上（一格一个 Tab 位会被虚拟化打碎） | 原地无 chrome | 编辑键 + `Enter` 提交 / `Esc` 取消 |
 *
 * 若把状态机复制一份到表格里，UTF-8 边界换算、选区锚点/活动端、"
 * 文本一变就折叠选区"这三条不变量就有两处真相 —— 而它们的错误形态是
 * **按一下退格删掉半个汉字**这种，不会报错。
 *
 * ⇒ 状态机下沉到这里，两个面各自只保留"焦点怎么来 / 改完通知谁 / 画成什么样 / 哪个键收口"。
 *
 * ## 三条不变量（全部由本类维护，调用方不用管）
 *
 * 1. **插入符与选区端点恒在字符边界上**（字节偏移，按 UTF-8 码位吸附）。
 * 2. **锚点 == 活动端 ⇔ 没有选区**（没有第三个"无选区"标志）。
 * 3. **文本一变就折叠选区**：选区描述的是**旧文本**的一段字节区间，
 *    替换之后那段区间已经不存在了（留着 = 高亮一片来路不明的文本）。
 *
 * ## 它不做什么
 *
 * 不通知、不重画、不认识焦点、不认识键位。每个动作返回 {@see TextChange}，
 * 由调用方决定"要不要通知 / 要不要重画"。
 *
 * @api
 */
final class TextDraft
{
    /** 文本（UTF-8 字节串） */
    private string $buffer;

    /** 插入符字节偏移；恒为字符边界 */
    private int $caret;

    /** 选区锚点（字节偏移，恒字符边界）；与 `$extent` 相等 ⇔ 无选区 */
    private int $anchor;

    /** 选区活动端（字节偏移，恒字符边界）—— Shift+方向键 / 指针拖动挪的是它 */
    private int $extent;

    /**
     * @param string $text 初值；插入符落在末尾（与"外部推来一个值"同一条约定）
     */
    public function __construct(string $text = '')
    {
        $this->buffer = $text;
        $this->caret = \strlen($text);
        $this->anchor = $this->caret;
        $this->extent = $this->caret;
    }

    // ── 读 ──────────────────────────────────────────────────────────────

    /** 当前文本（**已包含未提交的编辑**） */
    public function text(): string
    {
        return $this->buffer;
    }

    public function caretOffset(): int
    {
        return $this->caret;
    }

    /** 有没有选区（锚点 ≠ 活动端） */
    public function hasSelection(): bool
    {
        return $this->anchor !== $this->extent;
    }

    /** 选区起点（两个端里小的那个 —— 展示与删除用它，别用锚点） */
    public function selectionStart(): int
    {
        return $this->anchor < $this->extent ? $this->anchor : $this->extent;
    }

    /** 选区终点（两个端里大的那个） */
    public function selectionEnd(): int
    {
        return $this->anchor > $this->extent ? $this->anchor : $this->extent;
    }

    /** 选中的文本（无选区 ⇒ 空串） */
    public function selectedText(): string
    {
        if ($this->anchor === $this->extent) {
            return '';
        }

        $start = $this->selectionStart();

        return \substr($this->buffer, $start, $this->selectionEnd() - $start);
    }

    // ── 外部推值 ────────────────────────────────────────────────────────

    /**
     * 外部推来一个新值 ⇒ 采纳它，插入符移到末尾、选区作废。
     *
     * ★ **同值不动**：否则调用方每帧重传同一个值都会把插入符打回末尾，
     *   "用户正在中间插入时跟手不丢"这条承诺直接失效。
     */
    public function setText(string $text): TextChange
    {
        if ($text === $this->buffer) {
            return TextChange::None;
        }

        $this->buffer = $text;
        $this->caret = \strlen($text);
        $this->collapse();

        return TextChange::Text;
    }

    // ── 编辑动作（每个都返回"改到了什么"）────────────────────────────────

    /**
     * 在插入符处插入文本（IME 提交 / 直接键入的落点）。
     *
     * ★ **有选区 ⇒ 整段替换**：键入覆盖选中的文本（与各平台编辑器的默认行为
     *   一致）。于是调用方不必先问"现在有没有选区"。
     */
    public function insert(string $text): TextChange
    {
        if ($text === '') {
            return TextChange::None;
        }

        if ($this->hasSelection()) {
            return $this->replaceSelection($text);
        }

        return $this->applyText(
            \substr($this->buffer, 0, $this->caret) . $text . \substr($this->buffer, $this->caret),
            $this->caret + \strlen($text),
        );
    }

    /**
     * 退格：**有选区 ⇒ 删掉整段**；否则删掉插入符前一个字符（按码位，不是按字节）。
     */
    public function deleteBackward(): TextChange
    {
        if ($this->hasSelection()) {
            return $this->deleteSelection();
        }

        $previous = $this->previousBoundary();
        if ($previous === $this->caret) {
            return TextChange::None;   // 已在最前 ⇒ 无字符可删
        }

        return $this->applyText(
            \substr($this->buffer, 0, $previous) . \substr($this->buffer, $this->caret),
            $previous,
        );
    }

    /** Delete：**有选区 ⇒ 删掉整段**；否则删掉插入符后一个字符 */
    public function deleteForward(): TextChange
    {
        if ($this->hasSelection()) {
            return $this->deleteSelection();
        }

        if ($this->caret >= \strlen($this->buffer)) {
            return TextChange::None;   // 已在最后 ⇒ 无字符可删
        }

        return $this->applyText(
            \substr($this->buffer, 0, $this->caret) . \substr($this->buffer, $this->nextBoundary()),
            $this->caret,
        );
    }

    /**
     * 移动插入符（**按字节偏移**，会吸附到不大于它的最近字符边界）。
     *
     * 落点即字符内部 ⇒ 吸附而不是报错：输入层给的是"点到了哪个像素"，
     * 换算成字节偏移时天然可能落在字符中间。
     */
    public function moveCaret(int $offset): TextChange
    {
        $snapped = $this->snap($offset);

        // ★ 早退条件必须带着选区看：偏移没变但**有选区**时也得走下去 ——
        //   "在同一位置再点一下取消选择"是可见变化（高亮消失）。
        if ($snapped === $this->caret && !$this->hasSelection()) {
            return TextChange::None;
        }

        $this->caret = $snapped;
        $this->collapse();   // 不带 Shift 的移动 = 折叠选区

        return TextChange::Caret;
    }

    public function moveCaretToEnd(): TextChange
    {
        return $this->moveCaret(\strlen($this->buffer));
    }

    /** 全选（`Ctrl+A`）；空文本没有"全选"这回事 */
    public function selectAll(): TextChange
    {
        $end = \strlen($this->buffer);
        if ($end === 0) {
            return TextChange::None;
        }

        if ($this->selectionStart() === 0 && $this->selectionEnd() === $end && $this->caret === $end) {
            return TextChange::None;   // 已经全选 ⇒ 无变化不重画（G2：宁可少报）
        }

        $this->anchor = 0;
        $this->extent = $end;
        $this->caret = $end;

        return TextChange::Caret;
    }

    /** 折叠选区（两端都归位到插入符）；无选区 ⇒ 无事发生 */
    public function clearSelection(): TextChange
    {
        if (!$this->hasSelection()) {
            return TextChange::None;
        }

        $this->collapse();

        return TextChange::Caret;
    }

    /**
     * 扩展选区到 `$offset`（吸附到字符边界）—— Shift+方向键的手感来源。
     *
     * ★ **只挪活动端，锚点不动** ⇒ "从起点一路选到当前"与"从当前往回选"是同一个
     *   模型。指针拖动框选给的也是这个形状。
     */
    public function extendSelectionTo(int $offset): TextChange
    {
        $snapped = $this->snap($offset);

        // 活动端没动、且本来就没选区 ⇒ 真的无事发生
        if ($snapped === $this->extent && !$this->hasSelection()) {
            return TextChange::None;
        }

        $this->caret = $snapped;
        $this->extent = $snapped;

        return TextChange::Caret;
    }

    /**
     * 用 `$text` 整段替换选区；无选区 ⇒ 退化成在插入符处插入。
     *
     * ★ 键入 / 粘贴 / 剪切三方共用这一个落点 ⇒ 不可能走出两种语义。
     */
    public function replaceSelection(string $text): TextChange
    {
        if (!$this->hasSelection()) {
            return $this->insert($text);
        }

        $start = $this->selectionStart();
        $next = \substr($this->buffer, 0, $start) . $text . \substr($this->buffer, $this->selectionEnd());

        if ($next === $this->buffer) {
            // 替换成同一段文本 ⇒ 缓冲没变，但选区必须收口（用户确实按了删除）
            $this->caret = $start + \strlen($text);
            $this->collapse();

            return TextChange::Caret;
        }

        return $this->applyText($next, $start + \strlen($text));
    }

    /** 删掉选区（无选区 ⇒ 无事发生） */
    public function deleteSelection(): TextChange
    {
        if (!$this->hasSelection()) {
            return TextChange::None;
        }

        return $this->replaceSelection('');
    }

    // ── 边界换算 ────────────────────────────────────────────────────────

    /**
     * 插入符**前一个**字符边界（0 = 已在最前）。
     *
     * ★ 不能写成 `moveCaret($caret - 1)` 的镜像：字节偏移差 1 很可能落在多字节
     *   字符**中间**，而 `moveCaret()` 吸附到"不大于偏移的最近边界" ⇒ 把 -1 吸回
     *   原地（按了没反应）。所以向左必须自己找上一个边界。
     */
    public function previousBoundary(): int
    {
        $previous = 0;
        foreach ($this->boundaries() as $bound) {
            if ($bound >= $this->caret) {
                break;
            }
            $previous = $bound;
        }

        return $previous;
    }

    /** 插入符**后一个**字符边界（末尾 = 已在最后） */
    public function nextBoundary(): int
    {
        foreach ($this->boundaries() as $bound) {
            if ($bound > $this->caret) {
                return $bound;
            }
        }

        return \strlen($this->buffer);
    }

    /** @return list<int> 所有合法插入点的字节偏移（含 0 与末尾），升序 */
    public function boundaries(): array
    {
        $bounds = [0];
        foreach (Utf8::decode($this->buffer) as $glyph) {
            $bounds[] = $glyph[1] + $glyph[2];
        }

        return $bounds;
    }

    // ── 内部 ────────────────────────────────────────────────────────────

    /** 把任意字节偏移夹进 `[0, len]` 并吸附到不大于它的最近字符边界 */
    private function snap(int $offset): int
    {
        $limit = \strlen($this->buffer);
        if ($offset < 0) {
            $offset = 0;
        }
        if ($offset > $limit) {
            $offset = $limit;
        }

        $snapped = 0;
        foreach ($this->boundaries() as $bound) {
            if ($bound > $offset) {
                break;
            }
            $snapped = $bound;
        }

        return $snapped;
    }

    /** 选区两端归位到插入符（"折叠"只有这一种写法，三处调用共用） */
    private function collapse(): void
    {
        $this->anchor = $this->caret;
        $this->extent = $this->caret;
    }

    /**
     * 落一次**文本改动**：改缓冲、移插入符、折叠选区。
     *
     * ★ 所有改文本的路径都收口到这里 ⇒ "文本一变就折叠选区"只有一处实现。
     */
    private function applyText(string $next, int $caret): TextChange
    {
        $this->buffer = $next;
        $this->caret = $caret;
        $this->collapse();

        return TextChange::Text;
    }
}
