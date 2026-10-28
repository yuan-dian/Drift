<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Scale;
use Drift\Widget\BuildContext;
use Drift\Widget\InlineEditorBox;

/**
 * 编辑态里的**选词（双击）/ 选段（三击）**。
 *
 * ## 三种连击各自回答什么
 *
 * | 连击 | 还没编辑 | 已在**这一格**编辑 |
 * |---|---|---|
 * | 2 | 进编辑 + 落插入符（阶段 7b，不变） | **选中词** |
 * | ≥3 | 进编辑 + **选中整段** | **选中整段** |
 *
 * ## ★ 第三条用例是**回归**，不是新能力
 *
 * 旧实现里"双击同一格"会走 `beginEdit()`，而它第一句是 `commitEditing()` ——
 * 用户刚输入的字被当成"提交"发出去。若应用没写回行数据，`editableText()` 又取回
 * 旧文本 ⇒ **双击一下，输入的内容消失**，不报错、不写日志。
 * 现在断言的是"编辑中双击**不产生提交**"，把这条路径钉住。
 *
 * ## 为什么断 `selectionRect()`（几何）而不是"选了几个字节"
 *
 * `InlineEditorBox` 没有暴露选区端点（它是值快照，不是状态机），而往 Widget 上开
 * 一个只给测试用的读口，等于把"盒代表哪一帧"的语义搅浑。几何是**真实产物**：
 * 宽度不等于词的宽度就说明选区错了，且一眼能看出是"多选了一个字"还是"少选了"。
 *
 * ★ 几何口径：表头 32 + 行高 40 ⇒ 行 1 的 y ∈ [72,112)；id 列 [0,100)、姓名列
 *   [100,300)。编辑盒的左缘在 108（列起点 100 + 内边距 8），而 `selectionRect()`
 *   是**盒内**坐标 ⇒ 从 0 起算。
 */
final class WordSelectTest extends SmokeTestCase
{
    private const ROW_Y = 92.0;

    private const TEXT_LEFT = 108.0;

    /** 被点的那一行的姓名（行 1）—— 三行是**必须**的，见 {@see self::rows()} */
    private const NAME = '用户 2';

    /**
     * 三行数据，行 1 的姓名是 `用户 2`（词 = `用户`，空白，数字）。
     *
     * ★ 不能只给一行：行区只有那一行时，`y=92`（行 1）落在**最后一行下方的空白**
     *   上 —— 表格盒仍然是 240 高，但那一带没有行容器 ⇒ 命中不到任何行，
     *   `focusedCell()` 保持默认的 `[0,0]`，看着像"点击没生效"。
     *
     * @return list<array{id: int, name: string}>
     */
    private static function rows(): array
    {
        return [
            ['id' => 1, 'name' => '用户 1'],
            ['id' => 2, 'name' => '用户 2'],
            ['id' => 3, 'name' => '用户 3'],
        ];
    }

    public function testDoubleClickWhileEditingSelectsWord(): void
    {
        $s = EditSession::open(self::rows());

        $wordW = self::advance($s, '用户');
        $glyphW = self::advance($s, '用');
        // 点「用」的左半边 ⇒ 插入符落在词首 ⇒ 取侧规则选右边这个 CJK 段
        $x = self::TEXT_LEFT + $glyphW * 0.25;

        // 第一次双击：进编辑（阶段 7b 的既有手感，这里只是把会话打开）
        $s->tap($x, self::ROW_Y, 1000.0);
        $s->tap($x, self::ROW_Y, 1100.0);

        // 隔开 500ms 的连击窗口后，再一次双击 ⇒ 这次是"编辑态里的双击"
        $s->tap($x, self::ROW_Y, 2000.0);
        $s->tap($x, self::ROW_Y, 2100.0);

        $rect = $s->editBox()?->selectionRect();

        check(
            'Table · 编辑中双击 ⇒ 选中指针所在的词（「用户」）',
            $rect !== null && \abs($rect->width - $wordW) < 0.01 && \abs($rect->left) < 0.01,
            '期望 width=' . \sprintf('%.2f', $wordW) . ' left=0'
                . '；实际 ' . ($rect === null ? 'null（没有选区）' : \sprintf('width=%.2f left=%.2f', $rect->width, $rect->left)),
        );

        $this->flushChecks();
    }

    /**
     * 三击 ⇒ 整段（单行编辑器里就是整串 `用户 1`）。
     *
     * ★ 这条同时证明"三击没有被 `isDoubleClick()`（`=== 2`）吃掉"：旧实现里第三下
     *   什么都不做、也没有选区 —— 不是"选错"，是**彻底没有反应**。
     */
    public function testTripleClickSelectsWholeCell(): void
    {
        $s = EditSession::open(self::rows());

        $allW = self::advance($s, self::NAME);
        $glyphW = self::advance($s, '用');
        $x = self::TEXT_LEFT + $glyphW * 0.25;

        $s->tap($x, self::ROW_Y, 1000.0);
        $s->tap($x, self::ROW_Y, 1100.0);
        $s->tap($x, self::ROW_Y, 1200.0);

        $rect = $s->editBox()?->selectionRect();

        check(
            'Table · 三击 ⇒ 选中整段（单行编辑器 = 整串，宽度 = 全文宽）',
            $rect !== null && \abs($rect->width - $allW) < 0.01,
            '期望 width=' . \sprintf('%.2f', $allW)
                . '；实际 ' . ($rect === null ? 'null（没有选区）' : \sprintf('width=%.2f', $rect->width)),
        );

        $this->flushChecks();
    }

    /**
     * 三击进编辑：**还没编辑**时三击也要落到"进编辑 + 选整段"，而不是空转。
     *
     * ★ 与上一条是两条路：上一条是"已在编辑"的第三下，这一条是"一次三连击"直接
     *   进编辑（`handleMultiClick()` 的两个分支）。只测一条时另一条可以整段没写。
     */
    public function testTripleClickFromIdleEntersEditAndSelectsAll(): void
    {
        $s = EditSession::open(self::rows());

        $allW = self::advance($s, self::NAME);
        $glyphW = self::advance($s, '用');
        $x = self::TEXT_LEFT + $glyphW * 0.25;

        $s->tap($x, self::ROW_Y, 1000.0);
        $s->tap($x, self::ROW_Y, 1100.0);
        $s->tap($x, self::ROW_Y, 1200.0);

        $rect = $s->editBox()?->selectionRect();

        check(
            'Table · 未编辑时三连击 ⇒ 进编辑且整段选中',
            $s->isEditing() && $rect !== null && \abs($rect->width - $allW) < 0.01,
            'editing=' . ($s->isEditing() ? 'y' : 'n')
                . ' width=' . ($rect === null ? 'null' : \sprintf('%.2f', $rect->width)),
        );

        $this->flushChecks();
    }

    /**
     * **回归**：编辑中双击**不得**产生一次提交。
     *
     * ★ 旧路径 `beginEdit()` → `commitEditing()` 会回调 `onCellEdit`。这条断言在旧
     *   实现上是红的（`editLog` 拿到一条），所以它能证伪"回到旧写法"。
     * ★ 同时断言"草稿里的字还在"：提交后重开草稿会从 `editableText()` 取值，
     *   应用若没写回行数据，用户看到的字就变回去了 —— 判据要落在**用户看得见的
     *   东西**上，不只是内部标志。
     */
    public function testDoubleClickWhileEditingDoesNotCommitDraft(): void
    {
        $s = EditSession::open(self::rows());

        $glyphW = self::advance($s, '用');
        $x = self::TEXT_LEFT + $glyphW * 0.25;

        $s->tap($x, self::ROW_Y, 1000.0);
        $s->tap($x, self::ROW_Y, 1100.0);

        $s->type('X');

        $s->tap($x, self::ROW_Y, 2000.0);
        $s->tap($x, self::ROW_Y, 2100.0);

        $text = $s->editWidget()?->text();

        check(
            'Table · 编辑中双击 ⇒ **不提交**（没有 onCellEdit 回调，草稿不重建）',
            $s->app->editLog === [],
            'editLog=' . \json_encode($s->app->editLog, \JSON_UNESCAPED_UNICODE),
        );
        check(
            'Table · 编辑中双击后刚输入的字还在（「X用户 2」）',
            $text === 'X' . self::NAME,
            'text=' . \json_encode($text, \JSON_UNESCAPED_UNICODE),
        );

        $this->flushChecks();
    }

    /** 度量取不到字宽时不能静默返回 0（那会让"宽度相等"的断言恒真） */
    private static function advance(EditSession $s, string $text): float
    {
        $probe = new BuildContext(
            Fixtures::VIEW_W,
            Fixtures::VIEW_H,
            $s->app->theme(),
            $s->app->metrics(),
        );

        $width = $s->app->metrics()->advance($text, InlineEditorBox::editorStyle($probe), new Scale(1.0));
        self::assertGreaterThan(0.0, $width, '度量给出的字宽必须为正：' . $text);

        return $width;
    }
}
