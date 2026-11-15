<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 单行文本的编辑状态机：缓冲 + 插入符 + 选区，零 Widget / 零 State 依赖。
 *
 * 三条不变量（错则静默：按退格删掉半个汉字、选区高亮来路不明的文本）：
 * ① 插入符与选区端点恒在字符边界（UTF-8 码位吸附）；
 * ② 锚点 == 活动端 ⇔ 无选区（无第三个标志）；
 * ③ 文本一变就折叠选区。
 *
 * 不通知、不重画、不认识焦点 / 键位；每个动作返回 {@see TextChange}。
 *
 * @api
 */
final class TextDraft
{
    /** 文本（UTF-8 字节串） */
    private string $buffer;

    /** 插入符字节偏移；恒为字符边界 */
    private int $caret;

    /** 选区锚点（字节偏移，恒字符边界）；与 $extent 相等 ⇔ 无选区 */
    private int $anchor;

    /** 选区活动端（字节偏移，恒字符边界）—— Shift+方向键 / 指针拖动挪的是它 */
    private int $extent;

    /**
     * 撤销栈：每项 = 一次可撤销动作之前的完整状态 `[文本, 插入符, 锚点, 活动端]`。
     *
     * ★ 快照而非逆操作：逆操作写错的表现是"撤销之后文本坏了"，比多占内存坏得多。
     *
     * @var list<array{0: string, 1: int, 2: int, 3: int}>
     */
    private array $undo = [];

    /** @var list<array{0: string, 1: int, 2: int, 3: int}> 重做栈（结构同 $undo） */
    private array $redo = [];

    /**
     * 上一次入栈的动作类别，只用于合并连续同类动作：`'type'` / `'delete'` / `null`。
     *
     * 合并按"同类且连续"而非时间窗：纯逻辑、可测，且 IME 组合串每更新都是一次
     * `'type'` 连续 ⇒ 自动并成一步（撤销一次不会只退掉一个拼音字母）。
     */
    private ?string $lastKind = null;

    /**
     * 输入长度上限（码位）；`null` = 不限。
     *
     * ★ 只截用户输入，不截 `setText()`：后者是外部受控值，应用是真相源。
     */
    private ?int $maxLength = null;

    /** 用户输入格式化器（按顺序执行）；外部推值与撤销/重做都不过它们 */
    private array $formatters = [];

    /**
     * 撤销栈上限，超过丢最旧的。100 而非无限：每页几十个输入框，无限栈是隐蔽内存增长。
     */
    private const int UNDO_LIMIT = 100;

    /**
     * @param string $text 初值；插入符落在末尾（与外部推值同约定）
     */
    public function __construct(string $text = '')
    {
        $this->buffer = $text;
        $this->caret = \strlen($text);
        $this->anchor = $this->caret;
        $this->extent = $this->caret;
    }

    /** 设输入长度上限（码位）；`null` 解除，负数按 0。不回头删已有文本（那是应用的裁决） */
    public function limit(?int $max): void
    {
        $this->maxLength = $max === null ? null : \max(0, $max);
    }

    /** @param list<TextInputFormatter> $formatters 空数组 = 不格式化 */
    public function setFormatters(array $formatters): void
    {
        $this->formatters = $formatters;
    }

    // ── 读 ──────────────────────────────────────────────────────────────

    /** 当前文本（已包含未提交的编辑） */
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

    /** 选区起点（两个端里小的；展示与删除用，别用锚点） */
    public function selectionStart(): int
    {
        return $this->anchor < $this->extent ? $this->anchor : $this->extent;
    }

    /** 选区终点（两个端里大的） */
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
     * 外部推新值 ⇒ 采纳，插入符移到末尾、选区作废。
     *
     * ★ 同值不动：否则每帧重传同一值会把插入符打回末尾，用户中间插入跟手不丢失效。
     * 不进撤销栈且清空栈：受控组件每次重建都灌 value，留旧历史会让撤销退到已被外部
     * 取代的文本（"撤销把新数据撤没了"）；浏览器对 input.value = x 也重置 undo 栈。
     */
    public function setText(string $text): TextChange
    {
        if ($text === $this->buffer) {
            return TextChange::None;
        }

        $this->buffer = $text;
        $this->caret = \strlen($text);
        $this->collapse();
        $this->forgetHistory();

        return TextChange::Text;
    }

    // ── 编辑动作（每个都返回"改到了什么"）────────────────────────────────

    /** 在插入符处插入；有选区 ⇒ 整段替换（覆盖选中文本） */
    public function insert(string $text): TextChange
    {
        if ($text === '') {
            return TextChange::None;
        }

        if ($this->hasSelection()) {
            return $this->replaceSelection($text);
        }

        $text = $this->clampInput($text);
        if ($text === '') {
            return TextChange::None;
        }

        return $this->commitInput(
            \substr($this->buffer, 0, $this->caret) . $text . \substr($this->buffer, $this->caret),
            $this->caret + \strlen($text),
            'type',
        );
    }

    /** 退格：有选区 ⇒ 删整段；否则删插入符前一个字符（按码位，非字节） */
    public function deleteBackward(): TextChange
    {
        return $this->deleteBackwardTo($this->previousBoundary());
    }

    /**
     * 向后删到 $start（Ctrl+Backspace 传 previousWordStart()）。
     *
     * 字符级与词级删除只差落点算法，其余相同 ⇒ 共用此层，不抄第二份。
     */
    public function deleteBackwardTo(int $start): TextChange
    {
        if ($this->hasSelection()) {
            return $this->deleteSelection();
        }

        $start = $this->snap($start);
        if ($start >= $this->caret) {
            return TextChange::None;   // 已在最前 / 落点就在插入符处 ⇒ 无字符可删
        }

        return $this->applyText(
            \substr($this->buffer, 0, $start) . \substr($this->buffer, $this->caret),
            $start,
            'delete',
        );
    }

    /** Delete：有选区 ⇒ 删整段；否则删插入符后一个字符 */
    public function deleteForward(): TextChange
    {
        return $this->deleteForwardTo($this->nextBoundary());
    }

    /**
     * 向前删到 $end（Ctrl+Delete 传 nextWordEnd()）。
     *
     * @param int $end 删除区间的右端（字节偏移，不含）
     */
    public function deleteForwardTo(int $end): TextChange
    {
        if ($this->hasSelection()) {
            return $this->deleteSelection();
        }

        $end = $this->snap($end);
        if ($end <= $this->caret) {
            return TextChange::None;   // 已在最后 / 落点就在插入符处 ⇒ 无字符可删
        }

        return $this->applyText(
            \substr($this->buffer, 0, $this->caret) . \substr($this->buffer, $end),
            $this->caret,
            'delete',
        );
    }

    /**
     * 移动插入符（按字节偏移，吸附到不大于它的最近字符边界）。
     *
     * 落点在字符内部 ⇒ 吸附而非报错（输入层给的是像素，天然可能落字符中间）。
     */
    public function moveCaret(int $offset): TextChange
    {
        $snapped = $this->snap($offset);

        // ★ 早退要带着选区看：偏移没变但有选区时也得走（再点一下取消选择是可见变化）。
        if ($snapped === $this->caret && !$this->hasSelection()) {
            return TextChange::None;
        }

        $this->caret = $snapped;
        $this->collapse();   // 不带 Shift 的移动 = 折叠选区
        $this->breakMerge();

        return TextChange::Caret;
    }

    public function moveCaretToEnd(): TextChange
    {
        return $this->moveCaret(\strlen($this->buffer));
    }

    /** 全选（Ctrl+A）；空文本没有全选 */
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
        $this->breakMerge();

        return TextChange::Caret;
    }

    /** 折叠选区（两端归位插入符）；无选区 ⇒ 无事发生 */
    public function clearSelection(): TextChange
    {
        if (!$this->hasSelection()) {
            return TextChange::None;
        }

        $this->collapse();
        $this->breakMerge();

        return TextChange::Caret;
    }

    /**
     * 扩展选区到 $offset（吸附）—— Shift+方向键的手感来源。
     *
     * 只挪活动端、锚点不动：从起点选到当前、从当前往回选是同一模型。
     */
    public function extendSelectionTo(int $offset): TextChange
    {
        $snapped = $this->snap($offset);

        // 活动端没动、且本来就没选区 ⇒ 真的无事发生。
        if ($snapped === $this->extent && !$this->hasSelection()) {
            return TextChange::None;
        }

        $this->caret = $snapped;
        $this->extent = $snapped;
        $this->breakMerge();

        return TextChange::Caret;
    }

    /**
     * 选中 [start, end)（两端吸附；顺序反了换过来）。
     *
     * 活动端落 end，与 extendSelectionTo() 同模型；start === end ⇒ 折叠不报错。
     */
    public function selectRange(int $start, int $end): TextChange
    {
        $from = $this->snap($start);
        $to = $this->snap($end);
        if ($to < $from) {
            $swap = $from;
            $from = $to;
            $to = $swap;
        }

        if ($this->anchor === $from && $this->extent === $to) {
            return TextChange::None;
        }

        $this->anchor = $from;
        $this->extent = $to;
        $this->caret = $to;
        $this->breakMerge();

        return TextChange::Caret;
    }

    /** 选中插入符处的词（双击）；边界算法在 TextBoundary（纯函数），这里只做转换 */
    public function selectWordAt(int $offset): TextChange
    {
        $range = TextBoundary::wordAt($this->buffer, $this->snap($offset));

        return $this->selectRange($range[0], $range[1]);
    }

    /** 选中插入符处的段（三击；单行编辑器里就是整串） */
    public function selectParagraphAt(int $offset): TextChange
    {
        $range = TextBoundary::paragraphAt($this->buffer, $this->snap($offset));

        return $this->selectRange($range[0], $range[1]);
    }

    /**
     * 用 $text 整段替换选区；无选区 ⇒ 退化成在插入符处插入。
     *
     * 键入 / 粘贴 / 剪切共用这一个落点 ⇒ 不可能走出两种语义。
     */
    public function replaceSelection(string $text): TextChange
    {
        if (!$this->hasSelection()) {
            return $this->insert($text);
        }

        $start = $this->selectionStart();
        $text = $this->clampInput($text, $start, $this->selectionEnd());
        $next = \substr($this->buffer, 0, $start) . $text . \substr($this->buffer, $this->selectionEnd());

        // $text === '' 归 'delete'：语义上替换成空串就是删除；kind 不给默认值，
        // 每个调用点必须表态。
        return $this->commitInput($next, $start + \strlen($text), $text === '' ? 'delete' : 'type');
    }

    /** 删掉选区（无选区 ⇒ 无事发生） */
    public function deleteSelection(): TextChange
    {
        if (!$this->hasSelection()) {
            return TextChange::None;
        }

        return $this->replaceSelection('');
    }

    // ── 撤销 / 重做 ──────────────────────────────────────────────────────

    /**
     * 撤销一步（Ctrl+Z）；没有可撤的 ⇒ null。
     *
     * 返回 null 而非 TextChange::None：前者"这一步不存在"（栈空），后者"撤了但文本没变"
     * （不可能发生，快照不变量保证每次撤销都改文本）。撤销也恢复选区，不只文本。
     */
    public function undo(): ?TextChange
    {
        $snapshot = \array_pop($this->undo);
        if ($snapshot === null) {
            return null;
        }

        $this->redo[] = $this->capture();
        $this->lastKind = null;   // 撤销之后的新编辑不许和前一段合并

        return $this->restore($snapshot);
    }

    /** 重做一步（Ctrl+Y / Ctrl+Shift+Z）；没有可重做的 ⇒ null。 */
    public function redo(): ?TextChange
    {
        $snapshot = \array_pop($this->redo);
        if ($snapshot === null) {
            return null;
        }

        $this->undo[] = $this->capture();
        $this->lastKind = null;

        return $this->restore($snapshot);
    }

    public function canUndo(): bool
    {
        return $this->undo !== [];
    }

    public function canRedo(): bool
    {
        return $this->redo !== [];
    }

    // ── 边界换算 ────────────────────────────────────────────────────────

    /**
     * 插入符前一个字符边界（0 = 已在最前）。
     *
     * 不能写成 moveCaret($caret - 1)：字节差 1 可能落多字节字符中间，吸附会吸回原地
     * （按了没反应）。向左必须自己找上一个边界。
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

    /** 插入符后一个字符边界（末尾 = 已在最后） */
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

    /**
     * 插入符左侧最近的词首（Ctrl+←）；已在开头 ⇒ 0。
     *
     * 在此包一层而非让 TextKeymap 直接调 TextBoundary：避免把"插入符口径"漏给调用方。
     * 与 previousBoundary() 区别只在粒度（字符 vs 同类段）。
     */
    public function previousWordStart(): int
    {
        return TextBoundary::previousWordStart($this->buffer, $this->caret);
    }

    /** 插入符右侧最近的词首（Ctrl+→）；已在末尾 ⇒ 文本长度 */
    public function nextWordStart(): int
    {
        return TextBoundary::nextWordStart($this->buffer, $this->caret);
    }

    /** 插入符右侧最近的词尾（Ctrl+Delete）；已在末尾 ⇒ 文本长度。 */
    public function nextWordEnd(): int
    {
        return TextBoundary::nextWordEnd($this->buffer, $this->caret);
    }

    // ── 内部 ────────────────────────────────────────────────────────────

    /**
     * 把一次用户输入截到"还能放几个码位"。
     *
     * @param int $selectedStart 将被替换掉的选区的字节起止（无选区传相等值）
     * @param int $selectedEnd
     *
     * ★ 按码位截：按字节截会切出半个汉字（与 snap() 同一条红线）。
     */
    private function clampInput(string $text, int $selectedStart = 0, int $selectedEnd = 0): string
    {
        if ($this->maxLength === null) {
            return $text;
        }

        $selected = $selectedEnd > $selectedStart
            ? Utf8::length(\substr($this->buffer, $selectedStart, $selectedEnd - $selectedStart))
            : 0;
        $available = $this->maxLength - (Utf8::length($this->buffer) - $selected);

        if ($available <= 0) {
            return '';
        }

        $codepoints = Utf8::decode($text);
        if (\count($codepoints) <= $available) {
            return $text;
        }

        // 取第 $available 个码位的起始字节：比逐码位重编码更忠实（非法字节原样保留）
        return \substr($text, 0, $codepoints[$available][1]);
    }

    /** 把任意字节偏移夹进 [0, len] 并吸附到不大于它的最近字符边界 */
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

    // ── 撤销栈（applyText() 是唯一入栈点）────────────────────────────────

    /**
     * 入栈"这次动作之前"的状态；同类且连续的动作不重复入栈（合并成一步）。
     *
     * 合并条件只有"上一次也是同一类"；中间有过光标移动 / 选区变化 / 撤销 / 外部推值
     * 就重开一步。
     */
    private function snapshot(string $kind): void
    {
        if ($this->lastKind === $kind && $this->undo !== []) {
            return;   // 栈顶已经是这一串连续动作开始前的状态
        }

        $this->undo[] = $this->capture();
        if (\count($this->undo) > self::UNDO_LIMIT) {
            \array_shift($this->undo);
        }

        $this->lastKind = $kind;
    }

    /** @return array{0: string, 1: int, 2: int, 3: int} */
    private function capture(): array
    {
        return [$this->buffer, $this->caret, $this->anchor, $this->extent];
    }

    /** @param array{0: string, 1: int, 2: int, 3: int} $snapshot */
    private function restore(array $snapshot): TextChange
    {
        // 逐字段赋值，不用解构写法（全仓无先例，AOT 转译过不去）。
        $text = $snapshot[0];
        $this->caret = $snapshot[1];
        $this->anchor = $snapshot[2];
        $this->extent = $snapshot[3];

        $changed = $this->buffer !== $text;
        $this->buffer = $text;

        // 比较而非直接报 Text：快照不变量保证文本一定变，但"保证"≠"断言"；
        // 直接报会在不变量被破坏时撒谎、让调用方少通知一次（静默）。
        return $changed ? TextChange::Text : TextChange::Caret;
    }

    /** 丢弃全部历史 —— 外部推值后栈里快照与当前文本不再连续 */
    private function forgetHistory(): void
    {
        $this->undo = [];
        $this->redo = [];
        $this->lastKind = null;
    }

    /** 打断合并：发生非文本改动（光标 / 选区），下一次文本改动独立成一步 */
    private function breakMerge(): void
    {
        $this->lastKind = null;
    }

    /**
     * 用户输入的唯一落点：先格式化，再入撤销栈。
     *
     * ★ `setText()`（外部受控值）与 `undo()` / `redo()` 都不走这里 ⇒ 应用是真相源，
     *   撤销也不会被格式化重排。格式化后的整次输入只占一步撤销。
     */
    private function commitInput(string $next, int $caret, string $kind): TextChange
    {
        if ($this->formatters !== []) {
            $edit = new TextEdit($next, $caret);
            foreach ($this->formatters as $formatter) {
                $edit = $formatter->format($edit);
            }
            $next = $edit->text;
            $caret = $edit->caret;
        }

        $caret = \max(0, \min($caret, \strlen($next)));
        if ($next === $this->buffer) {
            // 格式化把输入全删了（或替换成同一段）⇒ 文本没变，但选区必须收口
            $this->caret = $this->snap($caret);
            $this->collapse();
            $this->breakMerge();

            return TextChange::Caret;
        }

        return $this->applyText($next, $caret, $kind);
    }

    /**
     * 落一次文本改动：改缓冲、移插入符、折叠选区。
     *
     * 所有改文本的路径收口到此 ⇒ "文本一变就折叠选区"与撤销入栈点都只有一处。
     *
     * @param string $kind 动作类别（'type' / 'delete'）—— 不给默认值：加新动作时
     *        由编译器逼着表态该和谁合并
     */
    private function applyText(string $next, int $caret, string $kind): TextChange
    {
        $this->snapshot($kind);

        $this->buffer = $next;
        $this->caret = $this->snap($caret);
        $this->collapse();

        // 新编辑让重做失去意义（撤销树分叉只有从当前点重开一支这一种解）。
        $this->redo = [];

        return TextChange::Text;
    }
}
