<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Layout\RenderTextArea;
use Drift\Widget\TextArea;
use Drift\Widget\TextAreaState;
use Drift\Widget\StatefulElement;

/**
 * 多行输入框（`TextArea`）。
 *
 * ★ 分三类，缺任何一类都测不出问题：
 *   ① **布局**（多行真的占多行、超了能滚）—— 坏了的现象是"只有一行高"；
 *   ② **行级按键**（`Enter` 换行、上下移按列、`Home`/`End` 分行）——
 *      坏了的现象是"按了没反应"或"光标斜着跑"；
 *   ③ **与单行共用**的部分（撤销、`onChanged` 只在文本真变时发）——
 *      坏了的现象是"多行框的行为和单行框不一样"，而那正是抽基类要防的。
 *
 * ★ 上下移那条判据用**两种策略的落点对比**做反例前提（见
 *   `testVerticalMoveKeepsGoalColumn`）：字体不是等宽的，所以不能写死"落到第 3 个
 *   字符"。
 */
final class TextAreaTest extends SmokeTestCase
{
    /** 挂一棵 `TextArea` 树并布局，返回 [state, box] */
    private function mount(string $value, float $height = 200.0, int $minLines = 3): array
    {
        $area = TextArea::make()->value($value)->minLines($minLines);
        $root = $area->createElement();
        $root->mount(null, smokeContext());

        $state = $root instanceof StatefulElement ? $root->state() : null;
        $root->settle();
        $root->effectiveBox()?->layout(BoxConstraints::tight(new Size(240.0, $height)));

        $box = $state instanceof TextAreaState
            ? $state->element()?->findBox(static fn($c): bool => $c instanceof RenderTextArea)
            : null;

        return [$state, $box];
    }

    /** 让 State 走一帧（重建 + 布局），返回最新盒 */
    private function frame(TextAreaState $state, float $height = 200.0): ?RenderTextArea
    {
        $root = $state->element();
        $root?->settle();
        $root?->effectiveBox()?->layout(BoxConstraints::tight(new Size(240.0, $height)));

        return $state->element()?->findBox(static fn($c): bool => $c instanceof RenderTextArea);
    }

    /** 多行文本真的占多行：行数、高度都跟着涨 */
    public function testMultipleLinesTakeVerticalSpace(): void
    {
        [$one, $oneBox] = $this->mount('abc', 400.0, 1);
        [$three, $threeBox] = $this->mount("abc\ndef\nghi", 400.0, 1);

        check('TextArea · 单行文本排成 1 行', $oneBox?->textParagraph()?->lineCount() === 1);
        check('TextArea · 三行硬换行排成 3 行', $threeBox?->textParagraph()?->lineCount() === 3);
        check(
            'TextArea · 三行的内容高 > 单行（多行真的占了高度，不是被裁成一行）',
            ($threeBox?->contentHeight() ?? 0.0) > ($oneBox?->contentHeight() ?? 0.0),
            'one=' . ($oneBox?->contentHeight() ?? -1) . ' three=' . ($threeBox?->contentHeight() ?? -1),
        );

        $this->flushChecks();
    }

    /** `minLines` 只托底：空文本不塌，有内容时按内容走 */
    public function testMinLinesIsAFloorNotACap(): void
    {
        [$empty, $emptyBox] = $this->mount('', 400.0, 4);
        [$tall, $tallBox] = $this->mount(str_repeat("x\n", 9), 400.0, 4);

        check(
            'TextArea · 空文本也不低于 minLines',
            ($emptyBox?->contentHeight() ?? 0.0) > 0.0 && $emptyBox?->textParagraph()?->lineCount() === 1,
        );
        check(
            'TextArea · 内容比 minLines 多时按内容走（minLines 是下限不是上限）',
            ($tallBox?->contentHeight() ?? 0.0) > ($emptyBox?->contentHeight() ?? 0.0),
            'empty=' . ($emptyBox?->contentHeight() ?? -1) . ' tall=' . ($tallBox?->contentHeight() ?? -1),
        );

        $this->flushChecks();
    }

    /** `Enter` 换行，且换行算**文本改动**（要通知 `onChanged`） */
    public function testEnterInsertsNewlineAndNotifies(): void
    {
        $seen = [];
        $area = TextArea::make()->value('ab')
            ->onChanged(static function (string $t) use (&$seen): void {
                $seen[] = $t;
            });
        $root = $area->createElement();
        $root->mount(null, smokeContext());
        /** @var TextAreaState $state */
        $state = $root instanceof StatefulElement ? $root->state() : null;
        $state->beginEditing();

        $eaten = $state->handleKey(KeyEvent::down(KeyChord::enter()));

        check('TextArea · Enter 被吃掉（不落到加速键）', $eaten);
        check('TextArea · Enter 插入换行而不是提交', $state->text() === "ab\n", 'text=' . var_export($state->text(), true));
        check('TextArea · 换行算文本改动 ⇒ onChanged 收到一次', $seen === ["ab\n"]);

        $this->flushChecks();
    }

    /** `Ctrl+Enter` 提交：跑回调、**不改文本** */
    public function testCtrlEnterSubmitsWithoutChangingText(): void
    {
        $submitted = [];
        $area = TextArea::make()->value("第一行\n第二行")
            ->onSubmitted(static function (string $t) use (&$submitted): void {
                $submitted[] = $t;
            });
        $root = $area->createElement();
        $root->mount(null, smokeContext());
        /** @var TextAreaState $state */
        $state = $root instanceof StatefulElement ? $root->state() : null;
        $state->beginEditing();

        $eaten = $state->handleKey(KeyEvent::down(KeyChord::make('Enter', ctrl: true)));

        check('TextArea · Ctrl+Enter 被吃掉', $eaten);
        check('TextArea · Ctrl+Enter 交出当前文本', $submitted === ["第一行\n第二行"]);
        check('TextArea · Ctrl+Enter 不往文本里塞换行', $state->text() === "第一行\n第二行");

        $this->flushChecks();
    }

    /**
     * 没接 `onSubmitted()` ⇒ `Ctrl+Enter` **不吞**（放给加速键）。
     *
     * ★ 反例（一律吞掉）的现象：应用把 `Ctrl+Enter` 绑成"保存"，而在多行框里
     *   按它毫无反应 —— 且没有任何报错。
     */
    public function testCtrlEnterFallsThroughWithoutHandler(): void
    {
        [$state] = $this->mount('abc');
        $state->beginEditing();

        $eaten = $state->handleKey(KeyEvent::down(KeyChord::make('Enter', ctrl: true)));

        check('TextArea · 没有 onSubmitted ⇒ Ctrl+Enter 放行给加速键', !$eaten);
        check('TextArea · 放行时文本不变', $state->text() === 'abc');

        $this->flushChecks();
    }

    /**
     * 连续上下移**保持目标列**。
     *
     * ★ 判据形状：先算出"保持列"与"从当前行尾重新出发"两种策略的落点，断言两者
     *   **不同**（否则这条判据没牙），再断言实际结果是"保持列"那个。
     *   字体不是等宽的 ⇒ 不能写死"落到第 3 个字符"。
     */
    public function testVerticalMoveKeepsGoalColumn(): void
    {
        // 第一行长、第二行短、第三行又长：这就是 goal column 存在的理由
        [$state, $box] = $this->mount("abcdef\nxy\nabcdef");
        $state->beginEditing();
        $state->moveCaret(3);   // 第一行的第 4 个字符处

        $state->handleKey(KeyEvent::down(KeyChord::down()));
        $afterFirst = $state->caretOffset();
        $state->handleKey(KeyEvent::down(KeyChord::down()));
        $afterSecond = $state->caretOffset();

        $p = $box?->textParagraph();
        check('TextArea · 上下移的前提：盒已排版出三行', $p !== null && $p->lineCount() === 3);

        if ($p !== null && $p->lineCount() === 3) {
            $xGoal = $p->caretX(3);
            $xDrift = $p->caretX($afterFirst);           // 从第二行行尾重新出发的列
            $y = $p->lineRect(2)->top + $p->lineRect(2)->height * 0.5;
            $withGoal = $p->indexAt($xGoal, $y);
            $withDrift = $p->indexAt($xDrift, $y);

            check(
                'TextArea · 前提：两种策略在第三行落点不同（否则这条判据测不出东西）',
                $withGoal !== $withDrift,
                "goal={$withGoal} drift={$withDrift}",
            );
            check(
                'TextArea · 下移一行到短行的行尾（列超出 ⇒ 夹到行尾）',
                $afterFirst === 9,
                "offset={$afterFirst}",
            );
            check(
                'TextArea · 再下移保持目标列（不是从短行行尾重新出发）',
                $afterSecond === $withGoal,
                "实际={$afterSecond} 保持列={$withGoal} 漂移={$withDrift}",
            );
        }

        $this->flushChecks();
    }

    /** `Home` / `End`：先到行首 / 行尾，再按一次到全文两端 */
    public function testHomeAndEndGoToLineEdgeThenDocumentEdge(): void
    {
        [$state] = $this->mount("abc\ndefgh");
        $state->beginEditing();
        $state->moveCaret(7);   // 第二行中间（"defgh" 的 e 之后）

        $state->handleKey(KeyEvent::down(KeyChord::home()));
        $lineStart = $state->caretOffset();
        $state->handleKey(KeyEvent::down(KeyChord::home()));
        $docStart = $state->caretOffset();

        $state->moveCaret(5);   // 第二行的 d 之后
        $state->handleKey(KeyEvent::down(KeyChord::end()));
        $lineEnd = $state->caretOffset();
        $state->handleKey(KeyEvent::down(KeyChord::end()));
        $docEnd = $state->caretOffset();

        check('TextArea · Home 先到行首', $lineStart === 4, "offset={$lineStart}");
        check('TextArea · 已在行首时 Home 到全文开头', $docStart === 0, "offset={$docStart}");
        check('TextArea · End 先到行尾', $lineEnd === 9, "offset={$lineEnd}");
        check('TextArea · 已在行尾时 End 到全文末尾', $docEnd === strlen("abc\ndefgh"), "offset={$docEnd}");

        $this->flushChecks();
    }

    /**
     * 内容超视口 ⇒ 能滚；插入符跑到末尾 ⇒ 自动跟到可见区。
     *
     * ★ 这是"多行框能不能用"的分界：不能滚就是"打字打到看不见"。
     */
    public function testLongTextScrollsAndCaretStaysVisible(): void
    {
        $text = implode("\n", array_map(static fn(int $i): string => "line {$i}", range(1, 30)));
        [$state, $box] = $this->mount($text, 120.0);

        check('TextArea · 内容超视口 ⇒ 有可滚距离', ($box?->maxScroll(Axis::Vertical) ?? 0.0) > 0.0, 'maxScroll=' . ($box?->maxScroll(Axis::Vertical) ?? -1));
        check('TextArea · 初始偏移为 0（不在底部）', ($box?->appliedOffset(Axis::Vertical) ?? -1) === 0.0);

        $state->beginEditing();
        $state->moveCaret(strlen($text));
        $after = $this->frame($state, 120.0);

        $caret = $after?->caretRect();
        $top = $after?->contentTop() ?? 0.0;
        $viewport = $after?->viewportHeight() ?? 0.0;

        check('TextArea · 插入符到末尾后自动滚动', ($after?->appliedOffset(Axis::Vertical) ?? 0.0) > 0.0, 'offset=' . ($after?->appliedOffset(Axis::Vertical) ?? -1));
        check(
            'TextArea · 插入符落在可视区内（不是滚出了视野）',
            $caret !== null && $caret->top >= $top && $caret->top + $caret->height <= $top + $viewport + 0.01,
            $caret === null ? 'caret=null' : "caretTop={$caret->top} top={$top} viewport={$viewport}",
        );

        $this->flushChecks();
    }

    /** 跨行选区**每行一段**（盒层的观感判据） */
    public function testSelectionSpansPerLine(): void
    {
        [$state] = $this->mount("abc\ndefgh\nij");
        $state->beginEditing();
        $state->moveCaret(0);
        $state->extendSelectionTo(9);

        // ★ 必须走一帧：选区变了要重建盒，`mount()` 拿到的那个还是旧几何
        $box = $this->frame($state);
        $rects = $box?->selectionRects() ?? [];

        check('TextArea · 跨两行的选区断成两段', count($rects) === 2, 'n=' . count($rects));
        check(
            'TextArea · 两段在不同的 y 上（第二段在第一段下方）',
            count($rects) === 2 && $rects[1]->top > $rects[0]->top,
        );

        $this->flushChecks();
    }

    /**
     * 撤销在多行框里**白送**（栈住 `TextDraft`，与单行同一个状态机）。
     *
     * ★ 这条不是"顺便测一下"：它守的是"抽基类 + 共用 `TextDraft`"这个设计本身。
     *   若将来有人给 `TextAreaState` 另起一套缓冲区，这条会红。
     */
    public function testUndoWorksWithoutExtraWiring(): void
    {
        [$state] = $this->mount('');
        $state->beginEditing();

        $state->insert('第一行');
        $state->handleKey(KeyEvent::down(KeyChord::enter()));
        $state->insert('第二行');
        $before = $state->text();

        $undone = $state->handleKey(KeyEvent::down(KeyChord::ctrl('Z')));

        check('TextArea · Ctrl+Z 被吃掉', $undone);
        check(
            'TextArea · 撤销回到输入之前（多行框不用额外接线）',
            $state->text() === '',
            "before=" . var_export($before, true) . " after=" . var_export($state->text(), true),
        );

        $this->flushChecks();
    }

    /**
     * `PageUp` / `PageDown`：一页一跳，列保持不变。
     *
     * ★ 期望值用**字符串算术**（每行 10 字符 + 换行 ⇒ 第 k 行第 3 列 = `k*11+3`），
     *   不用 `indexAt(caretX(3), …)` 复算 —— 那与实现同源，`goalX` 丢了两边一起错。
     * ★ 行数从**真实排版**读（视口高 ÷ 行高）：写死常量在换字号 / 换 DPI 时立刻错。
     */
    public function testPageKeysJumpOnePageAndKeepGoalColumn(): void
    {
        $rows = 30;
        $lines = [];
        for ($i = 0; $i < $rows; $i++) {
            $lines[] = 'abcdefghij';
        }

        [$state, $box] = $this->mount(implode("\n", $lines), 200.0);
        $state->beginEditing();
        $state->moveCaret(3);

        $paragraph = $box?->textParagraph();
        check('TextArea · 翻页前提：盒已排版', $paragraph !== null && $paragraph->lineCount() === $rows);
        if ($paragraph === null) {
            $this->flushChecks();

            return;
        }

        $lineHeight = $paragraph->lineRect(0)->height;
        $page = max(1, (int) \floor(200.0 / $lineHeight));

        $ate = $state->handleKey(KeyEvent::down(KeyChord::make('PageDown')));
        check('TextArea · PageDown 被吃掉', $ate);
        check(
            "TextArea · PageDown 跳一页（{$page} 行），落点 = 目标行第 3 列",
            $state->caretOffset() === $page * 11 + 3,
            'offset=' . $state->caretOffset() . " 期望=" . ($page * 11 + 3) . " page={$page}",
        );

        $state->handleKey(KeyEvent::down(KeyChord::make('PageUp')));
        check(
            'TextArea · PageUp 回到原位（翻页可逆，不是跳一格）',
            $state->caretOffset() === 3,
            'offset=' . $state->caretOffset(),
        );

        // 顶部再 PageUp ⇒ 夹到 0（不出现负偏移 / 不崩）
        $state->handleKey(KeyEvent::down(KeyChord::make('PageUp')));
        check('TextArea · 文首按 PageUp ⇒ 夹在 0', $state->caretOffset() === 0, 'offset=' . $state->caretOffset());

        // 底部：连按到超过末尾 ⇒ 落在文本末尾
        for ($i = 0; $i < 5; $i++) {
            $state->handleKey(KeyEvent::down(KeyChord::make('PageDown')));
        }
        check(
            'TextArea · 文末按 PageDown ⇒ 落在文本末尾',
            $state->caretOffset() === \strlen($state->text()),
            'offset=' . $state->caretOffset() . ' len=' . \strlen($state->text()),
        );

        $this->flushChecks();
    }
}
