<?php
declare(strict_types=1);

/**
 * "必须响亮失败"用例的子进程入口。
 *
 * ## 为什么必须另起进程
 *
 * `Errors::report()` 的默认级别是 FATAL，落点是 `exit(3)` —— 进程内**抓不到**。
 * 所以"该崩的真的崩了、退出码是 3、错误信息里说了用户该怎么改"这三件事只能在
 * 新进程里验。`tools/smoke.php` 一直用 `runCase(__FILE__, $case)` 做这件事，但
 * 那种写法的 `__FILE__` 必须是 smoke 入口 ⇒ 用例搬到 PHPUnit 就只能整段留在
 * smoke 里（`EachTest` / `StateProducedBoxTest` 早期就是这么留下的）。
 *
 * ★ 2026-10-28 第 39 批之后，smoke 里**不再留任何"响亮失败"用例** —— 剩下的十几处
 *   `runCase` 都是"要真进程/真像素"的桥一致性与 app 级样板（那是分工，不是待办）。
 *
 * 这个脚本把入口从 smoke 里解耦出来：case 名 + 一段会 Fatal 的代码，谁都能加。
 *
 * ```bash
 * php tests/Support/fatal_cases.php --case=optbare            # 日志落 Errors.log
 * php tests/Support/fatal_cases.php --case=optbare /tmp/x.log # 日志落指定路径
 * ```
 *
 * 退出码约定（与 smoke 同口径）：**走了 FATAL ⇒ 3**。没崩的 case 自己 `exit(0)`，
 * 好让"该崩没崩"在调用方那边表现为 `code !== 3` 而不是一句含糊的"失败了"。
 *
 * ★ 调用方请用 `runFatalCase()`（`functions.php`），别在这里 `exec` 一遍。
 * ★ 新增 case 时只往 `$cases` 里加一行 —— 不要再长出 `if ($case === ...)` 链。
 */

require __DIR__ . '/../../tools/autoload.php';
// 桥的解释器替身（与 smoke / phpunit 同一份）。`material-alpha-no-frameless` 在
// 走到桥之前就 Fatal 了，但加载它才能保证"崩在硬校验上"而不是"崩在缺函数上"。
require_once __DIR__ . '/../../tools/fakes/win32.php';
// Skia 帧末自证的反例要真构造 SkiaCanvas；替身必须在用例执行前加载。
require_once __DIR__ . '/../../tools/fakes/skia.php';

use Drift\App\Application;
use Drift\App\Frame;
use Drift\Commands\CommandRouter;
use Drift\Core\Computed;
use Drift\Dev\Smoke\App\ScrollApplication;
use Drift\Dev\Smoke\App\TfApplication;
use Drift\Dev\Smoke\Commands\EchoCommands;
use Drift\Dev\Smoke\ErrorBoundary\BoomPage;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Smoke\Layout\OversizeBox;
use Drift\Dev\Smoke\Layout\SolidBox;
use Drift\Dev\Smoke\LeafProbe;
use Drift\Dev\Smoke\State\BuildWatchWidget;
use Drift\Dev\Smoke\State\ChainPage;
use Drift\Dev\Smoke\State\ChainProbe;
use Drift\Dev\Smoke\State\DoubleMountPage;
use Drift\Dev\Smoke\State\FakeStore;
use Drift\Dev\Smoke\State\LoopPage;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Matrix2D;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Input\AcceleratorTable;
use Drift\Input\KeyChord;
use Drift\Layout\FlexAlign;
use Drift\Layout\LayoutTolerance;
use Drift\Layout\Overflow;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderDock;
use Drift\Layout\RenderFlex;
use Drift\Layout\RenderProxy;
use Drift\Logging\PatternFormatter;
use Drift\Platform\Win32\Win32WindowHost;
use Drift\Platform\WindowMaterial;
use Drift\Rendering\Color;
use Drift\Dev\Smoke\State\CellWritePage;
use Drift\Rendering\Path;
use Drift\Rendering\SkiaCanvas;
use Drift\Routing\RouteTable;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Each;
use Drift\Widget\Expanded;
use Drift\Widget\Grid;
use Drift\Widget\LazyGrid;
use Drift\Widget\LocalDate;
use Drift\Widget\Option;
use Drift\Widget\StatefulElement;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;
use Drift\Widget\TableSelection;
use Drift\Widget\TableSort;
use Drift\Widget\Text;
use Drift\Widget\TextField;
use Drift\Widget\TreeNode;
use Drift\Widget\TreeView;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;
use Drift\Widget\YearMonth;

$case = '';
$logPath = \dirname(__DIR__, 2) . '/Errors.log';

foreach (\array_slice($argv, 1) as $arg) {
    if (\str_starts_with($arg, '--case=')) {
        $case = \substr($arg, 7);

        continue;
    }
    if (!\str_starts_with($arg, '--')) {
        $logPath = $arg;
    }
}

Errors::setLogPath($logPath);
Errors::setDebug(true);

/**
 * 建树用的确定性上下文（320×240）。
 *
 * ★ 为什么不 `require` 那句 `smokeContext()`：子进程只加载 autoload + `tools/fakes/`，
 *   把 `tests/Support/functions.php`（依赖 PHPUnit 的 `SmokeTestCase`）拉进来是污染。
 *   `tools/smoke.php` 与 `functions.php` 各有一份同名实现 —— 这里是**第三处**，
 *   但它是"同一份 12 行"的最后归宿：本文件里 5 个用例都要建树，
 *   再内联 5 遍就没人能保证它们还一致了。
 */
function fatalContext(): BuildContext
{
    return new BuildContext(
        320.0,
        240.0,
        ThemeData::light(),
        new DeterministicMetricsProvider(
            DeterministicFontManager::standard()->collection(),
            new DeterministicShaper(),
        ),
    );
}

/** 挂一棵 `Table` 树 —— Table 的响亮失败全部发生在**挂载期**。 */
$mountTable = static function (Table $table): void {
    $element = $table->createElement();
    $element->mount(null, fatalContext());
};

/** @var array<string, callable(): void> $cases */
$cases = [
    // 选项表给了裸值（`['甲','乙']`）⇒ 身份不明确。`[0 => 'x']` 与 `['x']` 在 PHP 里
    // 是同一个数组，"值 0"与"标签列表"无法区分 ⇒ 猜就是替应用做决定。
    'optbare' => static function (): void {
        Option::normalize(['甲', '乙']);
    },

    // 空字符串键与 `value('')`（"未选"哨兵）语义冲突 ⇒ 必须响亮失败。
    'optemptykey' => static function (): void {
        Option::normalize(['' => '请选择']);
    },

    // 行里没有显示名列 ⇒ 静默变成整列空白（WPF / antd 就是这样）⇒ 这里 Fatal。
    'optnofield' => static function (): void {
        Option::fromRows([['id' => 1, 'title' => '北京市']], 'name', 'id');
    },

    // 行不是数组（比如直接给了 list<string>）⇒ 形状不对，必须响亮失败。
    'optbadrow' => static function (): void {
        Option::fromRows(['北京市', '上海市'], 'name', 'id');
    },

    // W8-3：列数非法（0）⇒ 构造期响亮失败（不留到布局期变成"没格子"）。
    'gridzero' => static function (): void {
        Grid::make(0);
    },

    // W8-3：行间距 ≥ 行节距 ⇒ 格子高 ≤ 0，build() 里响亮失败。
    // ★ 守卫在 build()（mount 时跑）⇒ 这里内联构造 BuildContext（smokeContext() 只在
    //   tests 助手与 smoke.php 各一份，子进程不加载）。
    'lazygridpitch' => static function (): void {
        $bad = LazyGrid::make(3, 96.0)
            ->spacing(96.0)
            ->items([0], static fn(int $i): Widget => Text::make('x'));
        $element = $bad->createElement();
        $element->mount(null, new BuildContext(
            320.0,
            240.0,
            ThemeData::light(),
            new DeterministicMetricsProvider(
                DeterministicFontManager::standard()->collection(),
                new DeterministicShaper(),
            ),
        ));
        $element->effectiveBox()?->layout(BoxConstraints::tight(new Size(336.0, 240.0)));
    },

    // 日志 pattern 未知占位符 ⇒ Fatal（G2：不静默产出缺字段的行）。
    'log-bad-pattern' => static function (): void {
        PatternFormatter::compile('%l %x oops');
    },

    // ── Widget 层的"响亮失败"入口（`LoudFailurePathTest` 的表）──────────
    // ★ 这一组守的不是"参数校验存在"，而是**校验失败时用户拿到的是人话 + 修法**。
    //   2026-10-28 A4：`WidgetModifiers` 在 S2 拆 trait 时丢了 `use Drift\Errors;`
    //   ⇒ 这三条路径实际是 `Class "Drift\Widget\Traits\Errors" not found`（裸 Error，
    //   没有日志、没有修法、退出码也不是 3）。**响亮失败路径自己坏掉是最坏的一种静默。**

    'mod-grow-negative' => static function (): void {
        Text::make('x')->grow(-1);
    },

    'mod-opacity-range' => static function (): void {
        Text::make('x')->opacity(1.5);
    },

    // `Wrap` 的 `Stretch`：**尚未实现**（需要第二遍布局），必须写明理由与修法
    //   —— 不给"静默退化成 Start"这个默认值。
    'wrap-stretch' => static function (): void {
        Wrap::make()->alignment(FlexAlign::Stretch);
    },

    // 修饰落在**不产盒**的控件上（arch-v2 `02` §11.7）：`TextField` 是
    // `StatefulWidget`，盒在 `TextFieldBox` 里 ⇒ 声明的 pad 只留在 Widget 上、
    // 盒永远读不到。现象是"尺寸一点没变"，不报错、不写日志 —— G2 明令禁止的半静默。
    // ★ 判据是 `Element::syncBox()` 里的 `Modifier::isDefault()` 守卫：
    //   声明了修饰却不产盒 ⇒ Fatal。所以这条同时是 `isDefault()` 完整性的**下游**证据
    //   （哪个字段漏进 `isDefault()`，哪个字段的守卫就静默失效）。
    'modstateful' => static function (): void {
        // @nonbox-decoration-allowed: 负向用例 —— 这一行**故意**撞运行期守卫，
        //   `tools/lint.php` 的静态规则对它无意义（不撞才是失败）。
        $element = TextField::make()->value('x')->paddingAll(8.0)->createElement();
        $element->mount(null, new BuildContext(
            240.0,
            80.0,
            ThemeData::light(),
            new DeterministicMetricsProvider(
                DeterministicFontManager::standard()->collection(),
                new DeterministicShaper(),
            ),
        ));
    },

    // UI 线程断言（§17.4.2.1，WPF `VerifyAccess()` 的等价物）在**非 UI 线程**上
    // 必须 Fatal —— 静默放行等于给数据竞争留缝，而那类 bug 只在压力下偶发。
    //
    // ★ 解释器面的 `drift_thread_id()` 是固定值（`tools/autoload.php` 的替身），
    //   造不出第二个真线程 ⇒ 用反射把 `uiThreadId` 改成一个"别的线程"，等价于
    //   "当前线程不是 UI 线程"。
    'ui-thread-mismatch' => static function (): void {
        $app = new ScrollApplication();
        (new \ReflectionProperty(Application::class, 'uiThreadId'))
            ->setValue($app, \drift_thread_id() + 1);
        $app->assertUiThread('fatal-case');
        echo "no-fatal\n";
        exit(0);
    },

    // 带 alpha 的底色却没 setFrameless() ⇒ Fatal。
    // ★ 失效形态是"壳静默回落 GDI ⇒ 透明没生效且无任何报错"，属本仓最讨厌的一类
    //   （`Win32WindowHost::applyMaterial()` 里那条 ★★ 硬校验）。
    'material-alpha-no-frameless' => static function (): void {
        (new Win32WindowHost())->applyMaterial(
            1,
            WindowMaterial::make()->background(new Color(0xFF, 0xFF, 0xFF, 0xB0)),
        );
    },

    // ── Table 的配置面：11 个"静默画错"的入口，全部在挂载期停 ──────────────
    // 共同理由（G2）：这些错的形态都是"界面看起来正常，但某个交互永远不发生"
    // —— 比崩溃难查得多，所以必须当场响。

    // `keyOf` 是列宽覆盖 / 选择集 / Shift 锚点的**唯一身份**。缺了若退化成"按序号"，
    // 数据一变选中行就指向另一行，而界面上完全正常。
    'table-nokey' => static function () use ($mountTable): void {
        $mountTable(Table::make(
            [TableColumn::make('a', 'A')->field('a')],
            [['a' => 1]],
        ));
    },

    // 0 宽的列让 flex 分配出现除零 / 零宽格。
    'table-width-zero' => static function (): void {
        TableColumnWidth::fixed(0.0);
    },

    // `field()` 与 `cell()` 都写 ⇒ 两条取值路都声明了（"后写的赢"是静默坑）。
    'table-field-and-cell' => static function () use ($mountTable): void {
        $mountTable(Table::make(
            [
                TableColumn::make('a', 'A')
                    ->field('a')
                    ->cell(static fn(array $row): Widget => Text::make('x')),
            ],
            [['a' => 1]],
        )->keyOf(static fn(array $row): int => (int) $row['a']));
    },

    // 字段拼错 ⇒ 必须报"第几行 / 缺哪个 / 有哪些"，不是整列空白。
    'table-missing-field' => static function () use ($mountTable): void {
        $mountTable(Table::make(
            [TableColumn::make('a', 'A')->field('nmae')],
            [['name' => 'x']],
        )->keyOf(static fn(array $row): string => (string) $row['name']));
    },

    // 声明了 selection 却没接 onSelectionChange ⇒ 点了没人收到（选中色停在旧值，
    // 每点一次差异放大一层）。
    'table-selection-noreport' => static function () use ($mountTable): void {
        $mountTable(Table::make(
            [TableColumn::make('a', 'A')->field('a')],
            [['a' => 1]],
        )->keyOf(static fn(array $row): int => (int) $row['a'])
            ->selection(TableSelection::none()));
    },

    // 列声明了 sortable() 却没接 onSortChange ⇒ 表头可点、点了没反应，箭头停在旧值。
    'table-sort-noreport' => static function () use ($mountTable): void {
        $mountTable(Table::make(
            [TableColumn::make('a', 'A')->field('a')->sortable()],
            [['a' => 1]],
        )->keyOf(static fn(array $row): int => (int) $row['a']));
    },

    // 列声明了 resizable() 却没接 onColumnResize ⇒ 手柄在、光标也变，拖半天列宽不动。
    'table-resize-noreport' => static function () use ($mountTable): void {
        $mountTable(Table::make(
            [TableColumn::make('a', 'A')->field('a')->resizable()],
            [['a' => 1]],
        )->keyOf(static fn(array $row): int => (int) $row['a']));
    },

    // sort() 指向不存在的列 ⇒ 箭头永远画不出来（列 key 拼错时完全静默）。
    'table-sort-unknown-column' => static function () use ($mountTable): void {
        $mountTable(Table::make(
            [TableColumn::make('a', 'A')->field('a')->sortable()],
            [['a' => 1]],
        )->keyOf(static fn(array $row): int => (int) $row['a'])
            ->sort(TableSort::by('nope'))
            ->onSortChange(static function (TableSort $next, int $columnIndex): void {
            }));
    },

    // 空列 key 是列的**身份**，不是"没排序"的另一种写法（那用 `TableSort::none()`）。
    'table-sort-empty-column' => static function (): void {
        TableSort::by('');
    },

    // editable() 却没接 onCellEdit ⇒ 改完按回车什么都没发生（而格子已经显示新值了，
    // 因为编辑时画的是草稿）。
    'table-cell-edit-no-handler' => static function () use ($mountTable): void {
        $mountTable(Table::make(
            [TableColumn::make('a', 'A')->field('a')->editable()],
            [['a' => 'x']],
        )->keyOf(static fn(array $row): string => (string) $row['a']));
    },

    // `cell()` 列没有"起始文本 + 提交到哪个字段"，编辑结构上无从谈起。
    'table-cell-edit-on-cell-column' => static function () use ($mountTable): void {
        $mountTable(Table::make(
            [TableColumn::make('a', 'A')->cell(static fn(array $row): Widget => Text::make('x'))->editable()],
            [['a' => 'x']],
        )->keyOf(static fn(array $row): string => (string) $row['a'])
            ->onCellEdit(static function (): void {
            }));
    },

    // ── 布局失败路径（§13.1 的 C5 / C6 与 G2；迁自 `tools/smoke.php` 的 ⑮ 段）──
    // ★ 五条都是"用户照日志能改"的构造/布局错误，不是内部断言 ⇒ 消息必须是人话。

    // C5 Report：列放不下 ⇒ 中止，不画到盒外面去。10+10 高、间距 0，容器只给 50。
    'flex-report-overflow' => static function (): void {
        $column = new RenderFlex(true, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Report);
        $column->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
        $column->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
        $column->layout(BoxConstraints::tight(new Size(10.0, 50.0)));
    },

    // ★★ **窗口帧**里的同一个溢出：**不许中止**，只裁剪 + 留一条 diagnostic。
    //   与上面 `flex-report-overflow` 成对存在 —— 两条一起才证明"宽容是按层分的"，
    //   而不是"全局把校验关掉了"。布局层（纯 `Frame` / `RenderBox`）仍然 exit 3。
    //   连跑三次同一个溢出 ⇒ 诊断只该有 1 条（否则拖动时日志被刷满）。
    'window-frame-overflow-clipped' => static function (): void {
        LayoutTolerance::reset();

        $diag = LayoutTolerance::withinWindow(static function (): array {
            for ($i = 0; $i < 3; $i++) {
                $column = new RenderFlex(true, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Report);
                $column->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
                $column->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
                $column->layout(BoxConstraints::tight(new Size(10.0, 50.0)));
            }

            return Errors::recentDiagnostics();
        });

        echo 'clipped diag=' . \count($diag) . ' text=' . ($diag[0] ?? '(none)') . "\n";
        exit(0);
    },

    // C6 的同一分裂：控件自己报出比可用空间更大的尺寸。窗口帧里裁剪，纯布局里中止。
    'window-frame-c6-clipped' => static function (): void {
        LayoutTolerance::reset();

        LayoutTolerance::withinWindow(static function (): void {
            (new OversizeBox())->layout(new BoxConstraints(0.0, 10.0, 0.0, 10.0));
        });

        $diag = Errors::recentDiagnostics();
        echo 'clipped-c6 diag=' . \count($diag) . ' text=' . ($diag[0] ?? '(none)') . "\n";
        exit(0);
    },

    // ★★ **整条 Application 链路**：`attach()` 的首帧 + 主循环帧 + 拖动帧，在远小于
    //   内容的约束下都**不许中止**。上面那条只验了 `withinWindow()` 这个作用域本身，
    //   这条验"`Application` 真的把它套在帧上了"—— 接线漏一处，这里就 exit 3。
    //   ★ 必须在**子进程**里跑：`Errors::report` 是 `exit(3)`，进程内接不住，
    //     而"接不住"的表现是 PHPUnit 静默死掉（连失败信息都没有）——
    //     那正是这条用例最初写成进程内时，变异测试暴露出来的问题。
    'window-frame-app-clipped' => static function (): void {
        LayoutTolerance::reset();

        $app = new TfApplication();
        $tiny = BoxConstraints::loose(new Size(80.0, 50.0));
        $app->attach(new SurfaceProbe(), $tiny);      // 首帧（窗口帧）
        $app->frame(16.0, $tiny);                      // 主循环帧
        $app->frame(32.0, $tiny);                      // 再来一帧（不该重复留痕）
        $app->repaintForResize(BoxConstraints::tight(new Size(80.0, 50.0)));   // 拖动帧

        $diag = Errors::recentDiagnostics();
        echo 'app-frame diag=' . \count($diag) . ' text=' . ($diag[0] ?? '(none)') . "\n";
        exit(0);
    },

    // C5 兜底：`shrink` 只能压弹性子，间距与固定子压不动 ⇒ 压到 0 也补不回来。
    //   ★ 间距 20×2 + 两个 40 的固定子 = 120 > 100，弹性子压到 0 仍差 20。
    'flex-shrink-exhausted' => static function (): void {
        $row = new RenderFlex(false, 20.0, new EdgeInsets(), FlexAlign::Start, Overflow::Report);
        $row->addChild(new SolidBox(new Size(40.0, 10.0), 40.0, 10.0));
        $intent = new RenderProxy();
        $intent->setFlexIntent(0, 1, 10.0, RenderBox::ALIGN_INHERIT);
        $row->addChild($intent);
        $row->addChild(new SolidBox(new Size(40.0, 10.0), 40.0, 10.0));
        $row->layout(BoxConstraints::tight(new Size(100.0, 40.0)));
    },

    // 容器上的 Scroll **不支持**（不是"尚未实现"）：容器不滚，要滚包一层 `ScrollView`。
    //   ★ `Overflow::Wrap` 已从枚举删除 —— 它是"重排"不是"舍弃方式"，属类别错误。
    'flex-scroll-unsupported' => static function (): void {
        $column = new RenderFlex(true, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Scroll);
        $column->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
        $column->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
        $column->layout(BoxConstraints::tight(new Size(10.0, 50.0)));
    },

    // G2：flex 份额 < 1 分不到空间。静默夹到 1 的表现是"给了 Expanded 却不生效"。
    'flex-value-below-one' => static function (): void {
        Expanded::make(Text::make('x'))->flex(0);
    },

    // C6：`performLayout()` 返回尺寸超出父给的上界 ⇒ 中止。夹具只超 1 —— 静默夹紧
    //   在这里最难发现（画面看起来完全正常，只是短了 1 像素）。
    'layout-oversize-c6' => static function (): void {
        (new OversizeBox())->layout(new BoxConstraints(0.0, 10.0, 0.0, 10.0));
    },

    // G2：同一个组合绑两次 ⇒ 响亮失败。后绑覆盖前绑的表现是"用户按了没反应，
    //   也不报错" —— 典型的半静默。
    'accel-conflict' => static function (): void {
        AcceleratorTable::make()
            ->bind(KeyChord::ctrl('R'), 'users.refresh')
            ->bind(KeyChord::ctrl('R'), 'users.reload');
    },

    // ── 第 39 批（2026-10-28）：下面 10 例原住在 `tools/smoke.php` 的
    //    `if ($case === …)` 长链里。它们守的段落早已搬进 PHPUnit，只剩"要子进程
    //    才能验"的这一半留在 smoke ⇒ 连同 `runCase(__FILE__, …)` 一起搬过来，
    //    那 6 个段才真正变成"墓碑"（只剩注释，没有代码）。

    // Each：同层重复 key ⇒ diff 复用错乱（R4）。绝不静默 ⇒ Fatal。
    'eachdup' => static function (): void {
        Each::make(static fn(array $u): int => $u['id'])
            ->items([['id' => 1], ['id' => 1]], static fn(array $u): Widget => Text::make('x'));
    },

    // Each：key `1` 与 `'1'` **不**算重复 —— 与 `Key::same` 同口径，不做类型互转。
    // ★ 这一例期望**不崩**：能走到文件末尾 ⇒ 退出码 0（旧判据是 smoke 里的 `exit(1)`）。
    'eachkeytag' => static function (): void {
        Each::make(static fn(array $u): string|int => $u['id'])
            ->items([['id' => 1], ['id' => '1']], static fn(array $u): Widget => Text::make('x'));
    },

    // Dock：默认 Report ⇒ 条轨**合计**超出必须中止（每根各自合规，冲突在合计层
    //   暴露 —— 30 + 20 > 40）。
    'dockoverflow' => static function (): void {
        $dock = new RenderDock(Overflow::Report);
        $dock->setSlot('top', new SolidBox(new Size(100.0, 30.0), 100.0, 30.0));
        $dock->setSlot('bottom', new SolidBox(new Size(100.0, 20.0), 100.0, 20.0));
        $dock->setSlot('center', new SolidBox(new Size(10.0, 10.0), 10.0, 10.0));
        $dock->layout(BoxConstraints::tight(new Size(100.0, 40.0)));
    },

    // A4：订阅写在 build 体内 ⇒ 每重建一次多订一份。运行期守卫必须响。
    'watchbuild' => static function (): void {
        $element = (new BuildWatchWidget(new FakeStore()))->createElement();
        $element->mount(null, fatalContext());
    },

    // Computed：求值期间又触发自己的求值 ⇒ 闭包不纯（E-COMPUTED-CYCLE）。
    'computedcycle' => static function (): void {
        $self = new Computed(static function () use (&$self): int {
            return $self->get() + 1;
        }, []);
        $self->get();
    },

    // Cell 版 build 期写：首帧建订阅，第二帧写自己的格子 ⇒ 订阅回调 self-markDirty
    //   ⇒ 同一条 E-FLUSH-CYCLE。守卫放在 State 的标脏处，不放在 Tracker 上。
    'cell-buildwrite' => static function (): void {
        $element = (new CellWritePage())->createElement();
        $element->mount(null, fatalContext());
        $element->settle();
        $element->state()?->markDirty();
        $element->settle();
    },

    // build() 里标脏自己 ⇒ 每遍都重建 ⇒ 16 轮不收敛 ⇒ E-FLUSH-CYCLE
    //   （**当帧**报，不是每帧默默重建）。
    'flushcycle' => static function (): void {
        $loop = LoopPage::make()->createElement();
        $loop->mount(null, fatalContext());
        $loop->settle();
    },

    // 子的 build 标脏祖先 ⇒ 祖先第二遍重建时又把子标脏 ⇒ 子同帧第二次 rebuild
    //   ⇒ `noteRebuilt()` 当帧 Fatal（带类名）。
    'chainwrite' => static function (): void {
        $probe = new ChainProbe();
        $root = ChainPage::make($probe)->createElement();
        $root->mount(null, fatalContext());
        $probe->markOuterOnInnerBuild = true;
        $inner = $root->childAt(0);
        if ($inner instanceof StatefulElement) {
            $inner->state()?->markDirty();
        }
        $root->settle();
    },

    // 同名路由注册两次 ⇒ 后一次静默覆盖 ⇒ 上线后"导航到某页却没反应"。
    'routedup' => static function (): void {
        RouteTable::make()->route('users', static fn(): Widget => new LeafProbe(1.0))
            ->route('users', static fn(): Widget => new LeafProbe(2.0));
    },

    // 同名命令注册两次 ⇒ 后一次静默覆盖前一次。上线后"点了没反应"且查不出为什么。
    'cmdup' => static function (): void {
        CommandRouter::make()->on('a.b', new EchoCommands())->on('a.b', new EchoCommands());
    },

    // 帧末异常沿途没有 ErrorBoundary 捕获 ⇒ 必须走 Fatal 序列（exit 3 + Errors.log）。
    //   **不吞** —— 吞了就是窗口冻结在上一帧且不报错（G2 明令禁止的半静默）。
    'noboundary' => static function (): void {
        $bad = BoomPage::make()->createElement();
        $bad->mount(null, fatalContext());   // 首次 build 成功
        $bad->state()?->boom();              // 标脏 ⇒ 帧末重建时抛
        (new Frame(new SurfaceProbe()))->run($bad, BoxConstraints::tight(new Size(240.0, 80.0)), 0.0);
        echo "不该到达这里\n";
    },

    // 行高 0 ⇒ LazyColumn 的 itemExtent 是窗口计算的除数（除零 / 永远只看得到第 0 行）。
    'treebadrow' => static function (): void {
        TreeView::make([])->rowHeight(0.0);
    },

    // 空 id 的节点：展开集合与选中值都靠 id 认，空串 ⇒ 点了永远没反应且不报错。
    'treeemptyid' => static function (): void {
        TreeNode::make('', '没有身份的节点');
    },

    // 两个节点同 id ⇒ 展开一个、两个都展开；选中一个、两个都高亮。必须响亮。
    'treedupid' => static function (): void {
        TreeView::make([
            TreeNode::make('same', '甲'),
            TreeNode::make('same', '乙'),
        ])->visibleRows();
    },

    // 行高 28（一个很自然的想法）装不下 42 高的箭头按钮 ⇒ 必然裁切，而裁切不报错。
    'treeshortrow' => static function (): void {
        TreeView::make([])->rowHeight(28.0);
    },

    // 13 月：日历不会自己"滚动"成次年 1 月 —— 静默进位会让翻月错一个月且看不出来。
    'ymbadmonth' => static function (): void {
        YearMonth::make(2026, 13);
    },

    // 非闰年的 2/29：闰年规则错一条就多出一天，而"多出来的那天"点下去永远选不中。
    'datebadday' => static function (): void {
        LocalDate::make(2026, 2, 29);
    },

    // 宽松解析的典型脏数据：长度对、分隔符不对。
    'datebadiso' => static function (): void {
        LocalDate::fromIso('2026/10/29');
    },

    // `plusMonths()` 越界仍要响亮失败 —— `tryPlusMonths()` 的 null 口径是给"翻页"
    // 用的，不能顺手把 plusMonths 也降级成静默。
    'ymoverflow' => static function (): void {
        YearMonth::make(1, 1)->plusMonths(-1);
    },

    // Skia 单栈镜像：帧末自证必须看见所有未配对的状态，而不只是变换栈。
    //   5 例分别对应 4 类压栈入口 + 变换锚点，任一入口漏维护镜像都会退回 exit 0。
    'skia-unclosed-clip' => static function (): void {
        $c = new SkiaCanvas(8, 8, Color::white());
        $c->pushClip(new Rect(0.0, 0.0, 4.0, 4.0));
        $c->assertFrameBalanced();
    },
    'skia-unclosed-layer' => static function (): void {
        $c = new SkiaCanvas(8, 8, Color::white());
        $c->pushLayer(0.5);
        $c->assertFrameBalanced();
    },
    'skia-unclosed-clip-path' => static function (): void {
        $c = new SkiaCanvas(8, 8, Color::white());
        $c->pushClipPath(
            Path::create()->moveTo(1.0, 1.0)->lineTo(5.0, 1.0)->lineTo(5.0, 5.0)->close()
        );
        $c->assertFrameBalanced();
    },
    'skia-unclosed-save' => static function (): void {
        $c = new SkiaCanvas(8, 8, Color::white());
        $c->save();
        $c->assertFrameBalanced();
    },
    'skia-unclosed-transform' => static function (): void {
        $c = new SkiaCanvas(8, 8, Color::white());
        $c->transform(Matrix2D::rotation(30.0));
        $c->assertFrameBalanced();
    },

    // Page：同一个 State 实例被两个 Element 持有 ⇒ 中止。`Page::host()` 交出的
    //   是**外部注入**的 Page 实例；静默覆盖 element 会让先卸载的那一处
    //   `dropDisposers()` 退掉后者的订阅与命令 —— 表现为"切回这页请求再也不
    //   触发"，无异常也无日志。宁可挂载时当场红。
    'state-double-mount' => static function (): void {
        $page = new DoubleMountPage();
        $shell = $page->host();
        $page->attach(new StatefulElement($shell), $shell);
        $page->attach(new StatefulElement($shell), $shell);
    },

    // Page：卸载后把同一个实例再挂一次 ⇒ 中止。`initState()` 只跑一次，
    //   重挂不会重新订阅（订阅都在 initState 里做）—— 页面看着在、其实全哑。
    //   缓存 host() 后在条件分支里来回切的写法就会撞上这一条。
    'state-remount' => static function (): void {
        $page = new DoubleMountPage();
        $first = $page->host()->createElement();
        $first->mount(null, fatalContext());
        $first->unmount();

        $page->host()->createElement()->mount(null, fatalContext());
    },
];

if (!isset($cases[$case])) {
    \fwrite(\STDERR, "unknown case: {$case}\n");
    exit(2);
}

$cases[$case]();

// 走到这里 = 该崩的没崩。退出码 0 让调用方靠 `!== 3` 一眼看出方向。
echo "reached-end-without-fatal\n";
exit(0);
