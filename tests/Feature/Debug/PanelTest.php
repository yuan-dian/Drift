<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\DevTools\App\DebugSession;
use Drift\DevTools\View\Fit;
use Drift\DevTools\View\Panel;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\FakePanelSession;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\Padding;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * D4 判据：一屏装得下 2000 行，且折叠与选中不随重算丢失。
 *
 * ## 钉什么
 *
 * - 屏幕恒定 `height` 行（少一行，提示语每按一次键就上下跳）；
 * - 光标按**行键**走：折叠、换帧、行表重算之后指向的还是同一个节点；
 * - 选中项消失时**出声**：沿父链退回最近的祖先，并写在状态栏；
 * - 行表有 2000 行时，输出仍然只有一屏（虚拟化是"先摊平、后格式化"，不是"先格式化再切片"）；
 * - 原因浮层问的是**屏幕上那一帧**，不是"最近一次重建它的帧"。
 *
 * ## 为什么用真会话 + 假会话两套夹具
 *
 * 交互语义要用真的录制器：折叠、换帧、缓冲裁剪都是记录层的行为，假会话没法证明
 * "面板读错了数据"。而 2000 行那条要用假会话：它只关心"行表 2000 行时输出还是
 * 24 行"，与这一页碰巧重建了多少个节点无关。
 */
final class PanelTest extends SmokeTestCase
{
    private ?DebugSession $session = null;

    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(160.0, 120.0));
    }

    /**
     * 挂载 + 四帧：1 挂载、2 `GrowProbe` 第一次写、3 它第二次写（孩子少一个）、
     * 4 `SteadyProbe` 写（另一支，与前两支不相交）。
     */
    private function boot(int $capacity = 240): Panel
    {
        $c = $this->constraints();
        $app = new PanelApplication();
        $app->attach(new SurfaceProbe(), $c);

        $session = DebugSession::open($app, $c, $capacity);
        $this->session = $session;

        $session->pump(0.0);          // 1 挂载
        GrowState::$last?->grow();     // → 2：长出 ExtraProbe（挂载，不进重建集合）
        $session->pump(16.0);
        GrowState::$last?->touch();   // → 3：父与子**同一帧**都重建（真实页面的常态）
        ExtraState::$last?->bump();
        $session->pump(32.0);
        // → 4：Extra 整支被摘掉，同时另一支重建（它更大 ⇒ 排在前面）。
        //   这样"退回祖先"的结果不会是第一行，判据才分得清"祖先"与"随便一行"。
        GrowState::$last?->grow();
        SteadyState::$last?->grow();
        $session->pump(48.0);
        SteadyState::$last?->grow();   // → 5：再推一帧，给时间轴多一点内容
        $session->pump(64.0);

        return new Panel($session, 'fixture', 60, 12);
    }

    protected function tearDown(): void
    {
        $this->session?->close();
        $this->session = null;
        parent::tearDown();
    }

    public function testTimelineDrillsIntoTheFrameAndWhyComesFromThatFrame(): void
    {
        $panel = $this->boot();
        $panel->boot();

        $lines = $panel->lines();
        check('时间轴是首屏', $panel->tab() === Panel::TAB_FRAMES, $panel->tab());
        check('首屏行数正好等于屏幕高度', \count($lines) === 12, 'lines=' . \count($lines));
        check(
            '光标落在有重建的帧上（挂载帧没什么可看的）',
            $panel->cursorKey() !== null && \str_starts_with((string) $panel->cursorKey(), 'f:'),
            (string) $panel->cursorKey(),
        );

        $picked = (int) \substr((string) $panel->cursorKey(), 2);
        $panel->press('enter');
        check('⏎ 进入这一帧的树', $panel->tab() === Panel::TAB_TREE, $panel->tab());
        check('进的是光标那一帧', $panel->frame() === $picked, $panel->frame() . ' vs ' . $picked);

        $rows = $panel->rows();
        check('树里有行', $rows !== [], 'rows=' . \count($rows));
        $cursor = $panel->cursorKey();
        check('光标是树里的一个节点', $cursor !== null && \str_starts_with((string) $cursor, 't:'), (string) $cursor);

        $panel->press('w');
        $overlay = \implode("\n", $panel->lines());
        check('w 打开原因浮层', \str_contains($overlay, '为什么'), \substr($overlay, 0, 60));
        check(
            '问的是屏幕上那一帧',
            self::whyLine($panel) !== '' && \str_contains(self::whyLine($panel), '帧 #' . $panel->frame()),
            self::whyLine($panel),
        );

        $panel->press('esc');
        check('esc 关掉浮层', !\str_contains((string) $panel->lines()[0], '为什么'), (string) $panel->lines()[0]);
        check('关浮层不换选中项', $panel->cursorKey() === $cursor, (string) $panel->cursorKey());

        $panel->press('esc');
        check('再 esc 回时间轴', $panel->tab() === Panel::TAB_FRAMES, $panel->tab());
        check(
            '回去时停在刚才看的那一帧',
            $panel->cursorKey() === 'f:' . $panel->frame(),
            (string) $panel->cursorKey(),
        );
    }

    public function testCollapsingKeepsTheCursorOnTheSameNode(): void
    {
        $panel = $this->boot();
        $panel->boot(3);

        $open = $panel->rows();
        check('第 3 帧的树有分支', \count($open) >= 2, 'rows=' . \count($open));
        $key = $panel->cursorKey();
        check('光标在根上', $key !== null && $key === $open[0]->key(), (string) $key);

        $panel->press('enter');
        $folded = $panel->rows();
        check('折叠后子行消失', \count($folded) < \count($open), \count($open) . ' → ' . \count($folded));
        check('折叠不换选中项', $panel->cursorKey() === $key, (string) $panel->cursorKey());

        $panel->press('enter');
        check('再按一次展开', \count($panel->rows()) === \count($open), 'rows=' . \count($panel->rows()));
        check('展开也不换选中项', $panel->cursorKey() === $key, (string) $panel->cursorKey());
    }

    /**
     * 选中的节点在下一帧消失了（这里：`GrowProbe` 把 `ExtraProbe` 整支摘掉）⇒ 退回
     * 最近的还活着的祖先，并且**说出来**。静默跳到第一行，读者会以为选中的还是原来那个。
     *
     * 判据不是"退到了第几行"，而是"退到的是不是一个祖先"：父链从旧行表里现算，
     * 光标必须落在链上的某一环。写死"父键"会过严 —— 单链折叠会让祖先**并进**
     * 孩子的行（`Column > Text` 一行代表两个节点），于是它自己那一行的键在下一帧
     * 不存在了，而节点本身还在。
     */
    public function testSelectionFallsBackToTheNearestSurvivingAncestor(): void
    {
        $panel = $this->boot();
        $panel->boot(3);

        $rows = $panel->rows();
        $target = $rows[0];
        foreach ($rows as $row) {
            if ($row->depth > $target->depth) {
                $target = $row;
            }
        }
        check('第 3 帧有下层节点可选', $target->depth > 0 && \count($rows) >= 3, 'depth=' . $target->depth . ' rows=' . \count($rows));

        /** @var array<string, ?string> $parentOf 旧行表的父链 */
        $parentOf = [];
        foreach ($rows as $row) {
            $parentOf[$row->key()] = $row->parentKey;
        }
        $ancestors = [];
        $cursor = $target->parentKey;
        while ($cursor !== null) {
            $ancestors[] = $cursor;
            $cursor = $parentOf[$cursor] ?? null;
        }
        check('这一目标确实有祖先链', $ancestors !== [], 'target=' . $target->key());

        // 走到最深那一行：它在第 4 帧连同 `ExtraProbe` 整支一起消失
        while ($panel->cursorKey() !== $target->key()) {
            $panel->press('j');
        }
        $panel->press(']');

        check('换到第 4 帧', $panel->frame() === 4, 'frame=' . $panel->frame());
        $after = $panel->rows();
        $keys = [];
        foreach ($after as $row) {
            $keys[] = $row->key();
        }
        check(
            '光标落到一个这一帧真有的行上',
            \in_array($panel->cursorKey(), $keys, true),
            (string) $panel->cursorKey(),
        );
        check(
            '退回的是它的某个祖先（不是随便第一行）',
            \in_array($panel->cursorKey(), $ancestors, true),
            (string) $panel->cursorKey() . ' 不在 [' . \implode(', ', $ancestors) . ']',
        );
        check(
            '状态栏说明了这件事',
            \str_contains($panel->status(), '上层'),
            $panel->status(),
        );
    }
    public function testEvictedFrameIsAnnouncedInsteadOfSilentlyJumping(): void
    {
        // 容量 4 而 boot 产出 5 个真实帧（#1 已被裁）。停在 #3 之后再来 5 次真实变化，
        // #3 被挤出窗口 —— 面板必须说出来，不能静默跳走。
        //
        // ★ 不用 `n` 连推来制造溢出：`n` 推出来的多半是静止帧，而静止帧现在先于实帧
        //   淘汰（`Recorder::trim()`），推不出"正在看的那一帧被挤掉"。
        $panel = $this->boot(4);
        $panel->boot(3);
        check('先停在 #3', $panel->frame() === 3, 'frame=' . $panel->frame());

        for ($i = 0; $i < 5; ++$i) {
            SteadyState::$last?->grow();
            $this->session?->pump(100.0 + $i * 16.0);
        }
        // `n` 才会重拉帧列表（`advance()` 里 refreshFrames）；单纯移动光标用的是上一次的
        // 快照，看不出来"这一帧已经没了"。
        $panel->press('n');

        check(
            '看的那一帧已经滚出缓冲',
            $panel->frame() !== 3,
            'frame=' . $panel->frame(),
        );
        check(
            '状态栏说清是缓冲裁掉的',
            \str_contains($panel->status(), '滚出缓冲'),
            $panel->status(),
        );
        check(
            '播报点名了滚掉的那一帧',
            \str_contains($panel->status(), '#3'),
            $panel->status(),
        );
    }

    public function testTheScreenIsAlwaysFullAndNeverWiderThanTheTerminal(): void
    {
        $panel = $this->boot();
        $panel->boot(3);

        foreach (['j', 'G', 'g', 'J', 'K', 'enter', 'tab'] as $key) {
            $panel->press($key);
            $lines = $panel->lines();
            check("按 {$key} 之后仍是 12 行", \count($lines) === 12, 'lines=' . \count($lines));
            $tooWide = 0;
            foreach ($lines as $line) {
                if (Fit::width($line) > 60) {
                    ++$tooWide;
                }
            }
            check("按 {$key} 之后没有超宽的行", $tooWide === 0, "wide={$tooWide}");
        }
    }

    /**
     * 虚拟化：行表 2000 行，输出仍是一屏。
     *
     * 判据分两步：`rows()` 真的数得出 2000+，`lines()` 仍是 24 —— 少任何一条都
     * 证明不了"先摊平、后格式化"。末尾再按 `G`，确认视口能滚到最后一行的位置
     * （否则"只有一屏"也可以靠"根本不显示后面的"来骗过）。
     */
    public function testTwoThousandRowsStillTakeOneScreen(): void
    {
        $children = [];
        for ($i = 0; $i < 2000; ++$i) {
            $children[] = [
                'id' => 100 + $i,
                'type' => 'W' . $i, // 类型各不相同 ⇒ 不会被同形折叠
                'kind' => 'parent',
                'count' => 1,
                'subtree' => 1,
                'site' => '',
                'detail' => '',
                'children' => [],
                'omitted' => 0,
                'hidden' => 0,
                'cut' => false,
            ];
        }
        $root = [
            'id' => 1,
            'type' => 'Root',
            'kind' => 'state',
            'count' => 1,
            'subtree' => 2001,
            'site' => 'f.php:1',
            'detail' => 'RootState',
            'children' => $children,
            'omitted' => 0,
            'hidden' => 0,
            'cut' => false,
        ];

        $session = new FakePanelSession(
            [['id' => 1, 't' => 0.0, 'cost' => 3.0, 'rebuilt' => 2001, 'causes' => 2001, 'stageMs' => [], 'unattributedMs' => 0.0, 'settleSkipped' => false, 'paintSkipped' => false]],
            [1 => ['frame' => 1, 'rebuilt' => 2001, 'depthMax' => 1, 'roots' => [$root], 'omitted' => 0, 'hidden' => 0, 'writes' => [], 'byType' => []]],
        );
        $panel = new Panel($session, 'fixture', 60, 24);
        $panel->boot(1);

        check('行表有 2001 行', \count($panel->rows()) === 2001, 'rows=' . \count($panel->rows()));
        check('输出仍然是一屏', \count($panel->lines()) === 24, 'lines=' . \count($panel->lines()));

        $panel->press('G');
        $screen = \implode("\n", $panel->lines());
        $last = $panel->rows()[2000];
        check(
            'G 能滚到最后一行（不是"后面的根本不显示"）',
            \str_contains($screen, $last->label),
            $last->label,
        );
        check('光标就是最后一行', $panel->cursorKey() === $last->key(), (string) $panel->cursorKey());
    }

    /**
     * 原因浮层问的是**屏幕上这一帧**，不是"最近一次重建它的帧"。
     *
     * 树是按某一帧画的；原因若来自另一帧，两者对不上，读者会把别处的原因当这里的。
     * 这里选的根节点在第 2/3/4 帧都重建过 —— 只要实现里漏掉 `frame` 参数，
     * 回答就会变成第 4 帧，而屏幕上是第 2 帧。
     */
    public function testWhyAnswersForTheFrameOnScreen(): void
    {
        $panel = $this->boot();
        $panel->boot(2);

        $panel->press('w');

        $why = self::whyLine($panel);
        check('浮层说的是屏幕上这一帧', \str_contains($why, '帧 #2'), $why);
    }

    /** 行键由节点 id 决定 ⇒ 同一帧重算多少次都是同一串键。 */
    public function testRowKeysAreStableNodeIdentities(): void
    {
        $panel = $this->boot();
        $panel->boot(3);

        $first = \array_map(static fn($row): string => $row->key(), $panel->rows());
        $panel->press('enter');
        $panel->press('enter');
        $again = \array_map(static fn($row): string => $row->key(), $panel->rows());

        check('重算之后行键逐项相同', $first === $again, \count($first) . ' vs ' . \count($again));
        check('行键没有重复', \count(\array_unique($first)) === \count($first), 'keys=' . \count($first));
        foreach ($panel->rows() as $row) {
            check(
                '行键带的是 debugId',
                $row->key() === 't:' . $row->nodeId,
                $row->key() . ' vs ' . $row->nodeId,
            );
        }
    }

    /**
     * 同一个面板**隔着一条管道**跑，读到的因果树逐行相同。
     *
     * 这是 D3 那条判据搬到消费者身上：协议层"两边值相同"是一回事，面板在两边
     * 画出同一棵树是另一回事 —— 前者靠 `query` 的返回值，后者还要证明视图没有
     * 依赖"记录就在手边"（比如偷偷读 `recorder()`）。
     *
     * 只比正文与选中状态：表头有时间（挂载毫秒、帧耗时）与宿主 pid，那是这台
     * 机器这一秒的运气，比进去只会永远红。
     */
    public function testThePanelShowsTheSameTreeThroughAPipe(): void
    {
        $args = ['--page=table', '--frames=1', '--click=400,300', '--screen=80x16', '--keys=enter,w'];
        $local = $this->runPanel($args);
        $remote = $this->runPanel([...$args, '--remote']);

        check('本地跑通了', $local !== null, 'local=null');
        check('远端跑通了', $remote !== null, 'remote=null');
        if ($local === null || $remote === null) {
            return;
        }

        check('同一个 tab', $local['tab'] === $remote['tab'], $local['tab'] . ' vs ' . $remote['tab']);
        check('同一帧', $local['frame'] === $remote['frame'], $local['frame'] . ' vs ' . $remote['frame']);
        check('同一个光标', $local['cursor'] === $remote['cursor'], (string) $local['cursor'] . ' vs ' . (string) $remote['cursor']);

        $localBody = \array_slice($local['lines'], 2);
        $remoteBody = \array_slice($remote['lines'], 2);
        $at = null;
        foreach ($localBody as $i => $line) {
            if (($remoteBody[$i] ?? null) !== $line) {
                $at = $i;
                break;
            }
        }
        check(
            '正文逐行相同',
            $at === null,
            $at === null ? '' : "#{$at}: {$localBody[$at]}  vs  " . ($remoteBody[$at] ?? '（没有这一行）'),
        );
        check(
            '远端那份的表头说明它来自另一个进程',
            \str_contains((string) $local['lines'][0], '挂载') && \str_contains((string) $remote['lines'][0], '宿主'),
            (string) $remote['lines'][0],
        );
        check('原因浮层在两边的状态栏里都留着', \str_contains((string) $local['status'], '原因') && \str_contains((string) $remote['status'], '原因'), $remote['status']);
    }

    /**
     * 浮层里"为什么 …"那一行；没开浮层时为空串。
     *
     * ★ 不能拿 `lines()[0]` 当浮层的标题：第 0 行永远是表头，而表头本来就有
     *   `帧 #N` —— 于是"浮层问的是哪一帧"这条判据会永远绿，连变异都咬不红。
     */
    private static function whyLine(Panel $panel): string
    {
        foreach ($panel->lines() as $line) {
            if (\str_contains($line, '为什么')) {
                return $line;
            }
        }

        return '';
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, mixed>|null
     */
    private function runPanel(array $args): ?array
    {
        $command = [\PHP_BINARY, \dirname(__DIR__, 3) . '/bin/drift', 'debug', 'panel', ...$args, '--json'];
        $pipes = [];
        $process = \proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            return null;
        }

        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($process);

        if ($code !== 0) {
            \fwrite(\STDERR, "panel CLI 退出码 {$code}：{$err}\n");
        }

        $decoded = \json_decode($out, true);

        return \is_array($decoded) ? $decoded : null;
    }
}

// ---------------------------------------------------------------------- 夹具

final class PanelApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Column::make()->children([
            new GrowProbe(),
            new SteadyProbe(),
        ]);
    }
}

/**
 * 两支互不相干的子树：`GrowProbe` 会在第 4 帧把 `ExtraProbe` 整支**摘掉**。
 *
 * `ExtraProbe` 必须是**有状态**的：因果树里只有"重建过"的节点，新挂上去的节点
 * 只在 mount 时出现、从不进重建集合 —— 用普通 `Text` 当那个"会消失的孩子"，
 * 它在树里压根没出现过，也就谈不上"选中项消失"。
 */
final class GrowProbe extends StatefulWidget
{
    public function createState(): State
    {
        return new GrowState();
    }
}

final class GrowState extends State
{
    public static ?self $last = null;

    public int $n = 0;

    public function initState(): void
    {
        self::$last = $this;
    }

    public function grow(): void
    {
        $this->setState(function (): void {
            ++$this->n;
        });
    }

    /** 只标脏、不改值：让它在某一帧和子节点**一起**重建（不动树的形状）。 */
    public function touch(): void
    {
        $this->setState(function (): void {
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        $kids = [Text::make('a' . $this->n)];
        if ($this->n === 1) {
            $kids[] = new ExtraProbe();
        }

        return Column::make()->children($kids);
    }
}

/** 只在第 3 帧重建、第 4 帧被摘掉的那一支。 */
final class ExtraProbe extends StatefulWidget
{
    public function createState(): State
    {
        return new ExtraState();
    }
}

final class ExtraState extends State
{
    public static ?self $last = null;

    public int $k = 0;

    public function initState(): void
    {
        self::$last = $this;
    }

    public function bump(): void
    {
        $this->setState(function (): void {
            ++$this->k;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->children([Text::make('x' . $this->k)]);
    }
}

/** 另一支：与 `GrowProbe` 不相交，用来验证"换帧后选中的节点可能不在了"。 */
final class SteadyProbe extends StatefulWidget
{
    public function createState(): State
    {
        return new SteadyState();
    }
}

final class SteadyState extends State
{
    public static ?self $last = null;

    public int $n = 0;

    public function initState(): void
    {
        self::$last = $this;
    }

    public function grow(): void
    {
        $this->setState(function (): void {
            ++$this->n;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        // 三行文字：子树比 Grow 那一支大 ⇒ 在森林里排在它前面（判据靠这个区分
        // "退回祖先"与"落到第一行"）。
        return Column::make()->children([
            Text::make('b' . $this->n),
            Text::make('b2'),
            Text::make('b3'),
        ]);
    }
}