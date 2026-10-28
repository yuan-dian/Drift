<?php
declare(strict_types=1);

/**
 * drift-smoke —— M0 的出口判据（§19）：骨架能装载、错误出口三级都真的生效，
 * 且**已交付的每一层**都有真断言（不是"能 new 出来"就算过）。
 *
 * 用法：
 *   php tools/smoke.php
 *
 * ★ 中止路径（Fatal / 封存后写入）会真的 `exit(3)`，所以它们跑在**子进程**里，
 *   断言的是"退出码 + 日志内容 + 错误框被调到" —— 而不是把 abort 改成可注入的
 *   测试钩子（那会给生产 API 添一个只为测试存在的开关，正是 A15）。
 */

use Drift\Dev\Smoke\BoxProbe;
use Drift\Dev\Smoke\BuildProbe;
use Drift\Dev\Smoke\Layout\ImpureBox;
use Drift\Dev\Smoke\Layout\OffsetBox;
use Drift\Dev\Smoke\Layout\OversizeBox;
use Drift\Dev\Smoke\Layout\BleedBox;
use Drift\Dev\Smoke\Layout\SolidBox;
use Drift\Dev\Smoke\Layout\LayoutCountBox;
use Drift\Dev\Smoke\Layout\LayoutCountWidget;
use Drift\Dev\Smoke\Commands\CommandWidget;
use Drift\Dev\Smoke\Commands\EchoCommands;
use Drift\Dev\Smoke\Commands\PickyCommands;
use Drift\App\Application;
use Drift\App\FrameClock;
use Drift\Platform\Backdrop;
use Drift\Platform\Win32\Win32RasterSurface;
use Drift\Platform\Win32\Win32SkiaSurface;
use Drift\Platform\Win32\Win32SurfaceProvider;
use Drift\Platform\Win32\Win32WindowHost;
use Drift\Platform\WindowMaterial;
use Drift\App\FocusManager;
use App\Hello\HelloApplication;
use App\Showcase\ShowcaseApplication;
use App\Tutorial\TutorialApplication;
use Drift\App\Frame;
use Drift\App\FramePhase;
use Drift\App\Paths;
use Drift\App\WindowStateStore;
use Drift\Dev\Smoke\WindowState\FakeWindowHost;
use Drift\Dev\Smoke\ErrorBoundary\BoomPage;
use Drift\Dev\Smoke\ErrorBoundary\BoomState;
use Drift\Dev\Smoke\ErrorBoundary\ConstructBoom;
use Drift\Dev\Smoke\ErrorBoundary\LayoutBoomBox;
use Drift\Dev\Smoke\ErrorBoundary\PaintBoomBox;
use Drift\Dev\Smoke\Frame\QuietWidget;
use Drift\Dev\Smoke\Frame\EpochWidget;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Smoke\Frame\TickerWidget;
use Drift\Dev\Smoke\Frame\TickerState;
use Drift\Dev\Smoke\Overlay\FlatBox;
use Drift\Dev\Smoke\Overlay\RecordingSurface;
use Drift\Dev\Smoke\App\FocusRingApplication;
use Drift\Dev\Smoke\App\OverlayLabApplication;
use Drift\App\OverlayEntry;
use Drift\Dev\Smoke\Rendering\ProbePainter;
use Drift\Dev\Preview\DemoPage;
use Drift\Dev\Preview\Renderer;
use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Smoke\App\AccelApplication;
use Drift\Dev\Smoke\App\EditApplication;
use Drift\Dev\Smoke\App\MotionApplication;
use Drift\Widget\AnimationController;
use Drift\Widget\Curve;
use Drift\Dev\Smoke\App\FakeClipboard;
use Drift\Dev\Smoke\App\KeyAncestorApplication;
use Drift\Dev\Smoke\App\CompositeRootApp;
use Drift\Dev\Smoke\App\CountJob;
use Drift\Dev\Smoke\App\MenuApplication;
use Drift\Dev\Smoke\App\FocusTwoApplication;
use Drift\Dev\Smoke\App\LazyApplication;
use Drift\Dev\Smoke\App\RevealApplication;
use Drift\Dev\Smoke\App\SlowFrameApplication;
use Drift\Dev\Smoke\Frame\FakeTimers;
use Drift\Dev\Smoke\App\NamedBoomJob;
use Drift\Dev\Smoke\App\NamedExitJob;
use Drift\Dev\Smoke\App\NamedSumJob;
use Drift\Dev\Smoke\App\ModalApplication;
use Drift\Dev\Smoke\App\RecordingHandler;
use Drift\Dev\Smoke\App\SmokeJobRegistry;
use Drift\Dev\Smoke\App\TaskRow;
use Drift\Dev\Smoke\App\TaskApplication;
use Drift\Dev\Smoke\App\TaskRowJob;
use Drift\Dev\Smoke\App\TaskWatcherState;
use Drift\Dev\Smoke\App\ThrowingJob;
use Drift\Dev\Smoke\App\SumJob;
use Drift\App\Completion;
use Drift\App\Io\IoPoller;
use Drift\App\Job;
use Drift\App\NamedJob;
use Drift\App\ProcessTaskHost;
use Drift\App\SyncTaskHost;
use Drift\App\TaskHost;
use App\Users\UsersApplication;
use App\Users\UsersRoot;
use App\Users\Jobs\JobRegistry;
use App\Users\Store\MemoryUserSource;
use App\Users\Store\LoadStatus;
use Drift\Dev\Smoke\App\CursorApplication;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\App\FailSliceJob;
use Drift\Dev\Smoke\App\GestureApplication;
use Drift\Dev\Smoke\App\PressLabApplication;
use Drift\Dev\Smoke\App\TableResizeApplication;
use Drift\Dev\Smoke\App\TableDragSelectApplication;
use Drift\Dev\Smoke\App\TableScrollApplication;
use Drift\Dev\Smoke\App\SemValueApplication;
use Drift\Dev\Smoke\App\TfApplication;
use Drift\Dev\Smoke\App\TfLabeledApplication;
use Drift\Dev\Smoke\App\TitleBarLabApplication;
use Drift\Dev\Smoke\App\ZeroViewportApplication;
use Drift\Dev\Smoke\App\ThemeApplication;
use Drift\Dev\Smoke\App\PointerApplication;
use Drift\Dev\Smoke\App\ScrollApplication;
use Drift\Dev\Smoke\App\NavApplication;
use Drift\Dev\Smoke\App\NamedSleepJob;
use Drift\Dev\Smoke\App\SpinnerApplication;
use Drift\Dev\Smoke\App\ToggleApplication;
use Drift\Dev\Smoke\LeafProbe;
use Drift\Dev\Smoke\Routing\NavStore;
use Drift\Dev\Smoke\Theme\ThemeSpy;
use Drift\Dev\Smoke\SealProbe;
use Drift\Dev\Smoke\State\BuildWatchWidget;
use Drift\Dev\Smoke\State\ChainPage;
use Drift\Dev\Smoke\State\ChainProbe;
use Drift\Dev\Smoke\State\FakeStore;
use Drift\Dev\Smoke\State\HoverWidget;
use Drift\Dev\Smoke\State\LeafBoxFallbackWidget;
use Drift\Dev\Smoke\State\LeafBoxState;
use Drift\Dev\Smoke\State\LeafBoxWidget;
use Drift\Dev\Smoke\State\LoopPage;
use Drift\Dev\Smoke\State\VersionProbe;
use Drift\Core\Computed;
use Drift\Dev\Smoke\Tree\CounterState;
use Drift\Dev\Smoke\Tree\CounterWidget;
use Drift\Dev\Smoke\CollectSink;
use Drift\Errors;
use Drift\Logging\Log;
use Drift\Logging\LogEntry;
use Drift\Logging\PatternFormatter;
use Drift\Logging\RingSink;
use Drift\Logging\StreamSink;
use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use Drift\Commands\CommandRouter;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Point;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Layout\LayoutSelfCheck;
use Drift\Layout\LayoutTolerance;
use Drift\Layout\RenderRichText;
use Drift\Input\AcceleratorTable;
use Drift\Input\CursorShape;
use Drift\Input\FocusNode;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\PointerPhase;
use Drift\Input\ScrollEvent;
use Drift\Input\SemanticsInvokeEvent;
use Drift\Input\SemanticsSetValueEvent;
use Drift\Input\SemanticsRole;
use Drift\Input\TapTracker;
use Drift\Layout\ColumnWidths;
use Drift\Layout\ExtentWindow;
use Drift\Layout\FlexAlign;
use Drift\Layout\Overflow;
use Drift\Layout\RenderAppBar;
use Drift\Layout\RenderBackdropFilter;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderButton;
use Drift\Layout\RenderDock;
use Drift\Layout\RenderErrorBoundary;
use Drift\Layout\RenderFlex;
use Drift\Layout\RenderScrollView;
use Drift\Layout\RenderProxy;
use Drift\Layout\RenderCustomPaint;
use Drift\Layout\RenderProxyBox;
use Drift\Layout\RenderMenuItem;
use Drift\Layout\RenderModalHost;
use Drift\Layout\RenderObject;
use Drift\Layout\RenderPopupPanel;
use Drift\Layout\RenderSpinner;
use Drift\Layout\RenderText;
use Drift\Layout\RenderTextField;
use Drift\Layout\RenderToggle;
use Drift\Layout\StackAlign;
use Drift\Layout\StackFit;
use Drift\Layout\ImageFit;
use Drift\Layout\RenderImage;
use Drift\Rendering\BoxStyle;
use Drift\Routing\PageHost;
use Drift\Routing\PageHostState;
use Drift\Routing\NoPage;
use Drift\Routing\RouteTable;
use Drift\Widget\Offstage;
use Drift\Widget\OffstageElement;
use Drift\Rendering\CanvasCapabilities;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Rendering\DashPattern;
use Drift\Rendering\DisplayList;
use Drift\Rendering\DividerStyle;
use Drift\Rendering\Gradient;
use Drift\Rendering\ImageHandle;
use Drift\Rendering\ImageLoader;
use Drift\Rendering\MetricToken;
use Drift\Rendering\Modifier;
use Drift\Rendering\Paint;
use Drift\Rendering\Path;
use Drift\Rendering\PngDecoder;
use Drift\Rendering\Shadow;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;
use Drift\Rendering\SkiaCanvas;
use Drift\State\Cell;
use Drift\State\SubjectBuilder;
use Drift\State\Subject;
use Drift\Text\DeterministicFontManager;
use Drift\Text\FontLocator;
use Drift\Text\TtfFace;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Text\FontFamily;
use Drift\Text\FontSlant;
use Drift\Text\FontWeight;
use Drift\Text\Glyph;
use Drift\Text\GlyphRun;
use Drift\Text\Paragraph;
use Drift\Text\TextAlign;
use Drift\Text\TextLayout;
use Drift\Text\TextLine;
use Drift\Text\TextRole;
use Drift\Text\TextSpan;
use Drift\Text\TextStyle;
use Drift\Text\TextChange;
use Drift\Text\TextDraft;
use Drift\Theme\SystemTheme;
use Drift\Theme\ThemeData;
use Drift\Theme\ThemeScope;
use Drift\Widget\Activatable;
use Drift\Widget\ActivationContext;
use Drift\Widget\AspectRatio;
use Drift\Widget\BackdropFilter;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Card;
use Drift\Widget\CustomPaint;
use Drift\Widget\CustomPainter;
use Drift\Widget\SizedBox;
use Drift\Widget\Stack;
use Drift\Widget\ButtonVariant;
use Drift\Widget\AppBar;
use Drift\Widget\BoxWidget;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Widget\ComboBox;
use Drift\Widget\Image;
use Drift\Widget\Dock;
use Drift\Widget\Drag\Draggable;
use Drift\Widget\Drag\DragTarget;
use Drift\Widget\Each;
use Drift\Widget\LazyColumn;
use Drift\Widget\LazyList;
use Drift\Widget\LazyListState;
use Drift\Widget\LazyRow;
use Drift\Widget\Grid;
use Drift\Widget\LazyGrid;
use Drift\Widget\Scrollable;
use Drift\Widget\ScrollView;
use Drift\Widget\ScrollViewState;
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\Element;
use Drift\Widget\ErrorBoundary;
use Drift\Widget\ErrorBoundaryElement;
use Drift\Widget\ErrorPlaceholder;
use Drift\Widget\Expanded;
use Drift\Widget\Menu\ContextMenu;
use Drift\Widget\Menu\Menu;
use Drift\Widget\Menu\MenuBar;
use Drift\Widget\Menu\MenuItem;
use Drift\Widget\Modal;
use Drift\Widget\PopupPanel;
use Drift\Widget\Key;
use Drift\Widget\NavButton;
use Drift\Widget\Padding;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\Spinner;
use Drift\Widget\SpinnerState;
use Drift\Widget\Checkbox;
use Drift\Widget\Radio;
use Drift\Layout\RenderDivider;
use Drift\Layout\RenderIcon;
use Drift\Layout\RenderProgress;
use Drift\Widget\Divider;
use Drift\Widget\Toggle;
use Drift\Widget\Icon;
use Drift\Widget\IconButton;
use Drift\Widget\IconName;
use Drift\Widget\IconSet;
use Drift\Widget\Progress;
use Drift\Widget\SegmentedControl;
use Drift\Widget\Segment;
use Drift\Layout\RenderSegmentedControl;
use Drift\Layout\RenderSegment;
use Drift\Rendering\SegmentedStyle;
use Drift\Dev\Smoke\App\SegmentedApplication;
use Drift\Widget\Slider;
use Drift\Layout\RenderSlider;
use Drift\Rendering\SliderStyle;
use Drift\Dev\Smoke\App\SliderApplication;
use Drift\Dev\Smoke\App\TooltipApplication;
use Drift\Dev\Smoke\App\SnackbarApplication;
use Drift\Dev\Smoke\App\SelectApplication;
use Drift\Dev\Smoke\App\SelectStateApplication;
use Drift\Dev\Smoke\App\ComboBoxApplication;
use Drift\Widget\Option;
use Drift\Widget\OptionStatus;
use Drift\Widget\Select;
use Drift\Widget\Snackbar;
use Drift\Widget\TooltipPanel;
use Drift\Widget\State;
use Drift\Widget\StatefulElement;
use Drift\Widget\RichText;
use Drift\Widget\Span;
use Drift\Widget\Text;
use Drift\Input\ThemeChangedEvent;
use Drift\Input\WindowDeactivatedEvent;
use Drift\Widget\TextField;
use Drift\Widget\TextFieldState;
use Drift\Widget\Widget;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;
use Drift\Widget\TableSelection;
use Drift\Widget\TableRowPicker;
use Drift\Widget\TableSelectionMode;
use Drift\Widget\TableSort;
use Drift\Widget\TableSortDirection;
use Drift\Widget\TableState;
use Drift\Widget\InlineEditorBox;
use Drift\Layout\RenderTable;
use Drift\Dev\TestApp;
use Drift\Dev\Smoke\App\TableCellEditApplication;

require __DIR__ . '/autoload.php';

// ★ 量具自己把上限抬到 2G（2026-10-23，W8-2 Tooltip）。
//   **不是泄漏**：全量 1217 条用例会串起几十个 Application 实例，每个都持有
//   字模 + 渲染树，默认 128M 早在几百条就炸；1024M 也已经贴着边（加 Tooltip 块
//   时在 `attach()` 加载字模处 OOM，差 10MB）。
//   ★ 为什么写在脚本里而不是靠调用方 `-d memory_limit=`：量具的**运行前提**不该
//     依赖调用者的命令行 —— 忘了带参数就得到一条"内存耗尽"的假红。
ini_set('memory_limit', '2048M');

$root = dirname(__DIR__);
$log = $root . '/Errors.log';
$boxMarker = $root . '/tools/smoke-box.tmp';

// ── 子进程模式：跑一个"必然中止"的用例 ──
$caseArg = $argv[1] ?? '';
if (str_starts_with($caseArg, '--case=')) {
    $case = substr($caseArg, 7);
    Errors::setLogPath($log);
    Errors::setDebug(true);

    if ($case === 'fatal') {
        $GLOBALS['DRIFT_SMOKE_BOX'] = $argv[2];
        Errors::installFatalBox(static function (string $title, string $body): void {
            file_put_contents($GLOBALS['DRIFT_SMOKE_BOX'], $title . '|' . $body);
        });
        // 先压两条 Diagnostic —— 中止序列 ① 的"环形缓冲"段才测的是真路径
        Errors::report('冒烟：前置诊断 A', Errors::DIAGNOSTIC);
        Errors::report('冒烟：前置诊断 B', Errors::DIAGNOSTIC);
        Errors::report('冒烟：走 Fatal 路径');
    }

    if ($case === 'log-bad-pattern') {
        // ★ 未知占位符 ⇒ Fatal（`12` §4.7.1：不静默产出缺字段的行）
        PatternFormatter::compile('%l %x oops');
    }

    if ($case === 'seal') {
        $widget = new SealProbe();
        $widget->seal();
        $widget->touch(); // 封存后写入 ⇒ 必须响亮失败
    }

    if ($case === 'key') {
        $widget = new SealProbe();
        $widget->seal();
        $widget->key(7); // 封存后给身份 ⇒ 必须响亮失败
    }

    if ($case === 'box') {
        $widget = new BoxProbe();
        $widget->seal();
        $widget->background(Color::white()); // 封存后改样式 ⇒ 必须响亮失败
    }

    if ($case === 'table-nokey') {
        // ★ `keyOf` 是列宽覆盖 / 选择集 / Shift 锚点的唯一身份 ⇒ 缺了必须**响亮**
        //   失败，不能退化成"按序号"（那会让选中行在数据变化后指向另一行，
        //   而界面上看起来完全正常）。
        $table = Table::make(
            [TableColumn::make('a', 'A')->field('a')],
            [['a' => 1]],
        );
        $el = $table->createElement();
        $el->mount(null, smokeContext());
        echo "mounted-without-error\n";
        exit(0);
    }

    if ($case === 'table-width-zero') {
        // 0 宽的列让 `flex` 分配出现除零/零宽格 ⇒ 构造期就该停下
        TableColumnWidth::fixed(0.0);
        echo "no-error\n";
        exit(0);
    }

    if ($case === 'table-field-and-cell') {
        // field() 与 cell() 都写 ⇒ 两条取值路都声明了（"后写的赢"是静默坑）
        $table = Table::make(
            [
                TableColumn::make('a', 'A')
                    ->field('a')
                    ->cell(static fn(array $row): Widget => Text::make('x')),
            ],
            [['a' => 1]],
        )->keyOf(static fn(array $row): int => (int) $row['a']);
        $el = $table->createElement();
        $el->mount(null, smokeContext());
        echo "mounted-without-error\n";
        exit(0);
    }

    if ($case === 'table-cell-edit-no-handler') {
        // editable() 却没接 onCellEdit ⇒ 用户改完按回车**什么都没发生**（而格子已经
        // 显示新值了，因为编辑时画的是草稿）⇒ 挂载期响亮失败，不是静默不生效
        $table = Table::make(
            [TableColumn::make('a', 'A')->field('a')->editable()],
            [['a' => 'x']],
        )->keyOf(static fn(array $row): string => (string) $row['a']);
        $el = $table->createElement();
        $el->mount(null, smokeContext());
        echo "mounted-without-error\n";
        exit(0);
    }

    if ($case === 'table-cell-edit-on-cell-column') {
        // cell() 列没有"起始文本 + 提交到哪个字段"，编辑结构上无从谈起 ⇒ 挂载期就说
        $table = Table::make(
            [TableColumn::make('a', 'A')->cell(static fn(array $row): Widget => Text::make('x'))->editable()],
            [['a' => 'x']],
        )->keyOf(static fn(array $row): string => (string) $row['a'])
            ->onCellEdit(static function (): void {});
        $el = $table->createElement();
        $el->mount(null, smokeContext());
        echo "mounted-without-error\n";
        exit(0);
    }

    if ($case === 'table-missing-field') {
        // 字段拼错 ⇒ 必须报"第几行 / 缺哪个 / 有哪些"，不是整列空白
        $table = Table::make(
            [TableColumn::make('a', 'A')->field('nmae')],
            [['name' => 'x']],
        )->keyOf(static fn(array $row): string => (string) $row['name']);
        $el = $table->createElement();
        $el->mount(null, smokeContext());
        echo "mounted-without-error\n";
        exit(0);
    }

    if ($case === 'table-selection-noreport') {
        // 声明了 selection 却没接 onSelectionChange ⇒ 点了没人收到（选中色停在旧值，
        // 而每点一次差异放大一层）⇒ 挂载期响亮失败，不是"静默不生效"
        $table = Table::make(
            [TableColumn::make('a', 'A')->field('a')],
            [['a' => 1]],
        )->keyOf(static fn(array $row): int => (int) $row['a'])
            ->selection(TableSelection::none());
        $el = $table->createElement();
        $el->mount(null, smokeContext());
        echo "mounted-without-error\n";
        exit(0);
    }

    if ($case === 'table-sort-noreport') {
        // 列声明了 sortable() 却没接 onSortChange ⇒ 表头可点、点了没反应，
        // 箭头还停在旧值 ⇒ 挂载期响亮失败（与 selection 缺上报同一条纪律）
        $table = Table::make(
            [TableColumn::make('a', 'A')->field('a')->sortable()],
            [['a' => 1]],
        )->keyOf(static fn(array $row): int => (int) $row['a']);
        $el = $table->createElement();
        $el->mount(null, smokeContext());
        echo "mounted-without-error\n";
        exit(0);
    }

    if ($case === 'table-resize-noreport') {
        // 列声明了 resizable() 却没接 onColumnResize ⇒ 手柄在、光标也变，
        // 拖半天列宽不动且不报错 ⇒ 挂载期响亮失败（同 sortable 的纪律）
        $table = Table::make(
            [TableColumn::make('a', 'A')->field('a')->resizable()],
            [['a' => 1]],
        )->keyOf(static fn(array $row): int => (int) $row['a']);
        $el = $table->createElement();
        $el->mount(null, smokeContext());
        echo "mounted-without-error\n";
        exit(0);
    }

    if ($case === 'table-sort-unknown-column') {
        // sort() 指向不存在的列 ⇒ 箭头永远画不出来（列 key 拼错时完全静默）
        $table = Table::make(
            [TableColumn::make('a', 'A')->field('a')->sortable()],
            [['a' => 1]],
        )->keyOf(static fn(array $row): int => (int) $row['a'])
            ->sort(TableSort::by('nope'))
            ->onSortChange(static function (TableSort $next, int $columnIndex): void {
            });
        $el = $table->createElement();
        $el->mount(null, smokeContext());
        echo "mounted-without-error\n";
        exit(0);
    }

    if ($case === 'table-sort-empty-column') {
        // 空列 key 是列的**身份**，不是"没排序"的另一种写法（那用 TableSort::none()）
        TableSort::by('');
        echo "no-error\n";
        exit(0);
    }

    if ($case === 'modstateful') {
        // ★ 修饰落在"不产盒"的控件上 ⇒ 必须响亮失败（`02` §11.7）。
        //   `TextField` 的盒在 `TextFieldBox` 里，`syncBox()` 拿到的是 null ⇒
        //   声明的 pad 无处可去。静默丢掉是 G2 明令禁止的半静默。
        $modCtx = new BuildContext(240.0, 80.0, ThemeData::light(), new DeterministicMetricsProvider(
            DeterministicFontManager::standard()->collection(),
            new DeterministicShaper(),
        ));
        // @nonbox-decoration-allowed: 负向用例 —— 这一行**故意**撞运行期守卫，
        //   `tools/lint.php` 的静态规则对它无意义（不撞才是失败）。
        $modEl = TextField::make()->value('x')->paddingAll(8.0)->createElement();
        $modEl->mount(null, $modCtx);
        echo "mounted-without-error\n";
        exit(0);
    }

    if ($case === 'cardproxy') {
        // ★ 正向（与 `modstateful` 反例配对）：`Card` 的样式注入**两种孩子两条路**。
        //   M2-5（2026-10-22）把 Card 从"自己有盒"改成"注入孩子 mods"后漏过这里：
        //   结构节点（ScrollView/TextField）自己不产盒 ⇒ §11.7 守卫当场 FATAL，
        //   示例台 7 页一点就炸，而当时四档闸门全绿（用例面没覆盖 Card(结构节点)）。
        //   修法：结构节点套一层 `SizedBox`（RenderProxy）再写样式。
        // ① 结构节点：中间必须是 SizedBox 代理，样式落代理盒上。
        $ctx = smokeContext();
        $el = Card::make(ScrollView::make(Text::make('x')))->createElement();
        $el->mount(null, $ctx);
        $mid = $el->childAt(0);
        // 代理盒 = SizedBox 自己的渲染盒（`RenderProxy`），`mount` 后即有（与 Padding/Center 同一 idiom）。
        // ★ 不能走 `$sv->parent()`：ScrollView 是**有态**控件，盒要等一帧才建，仅 `mount` 后为 null
        //   ⇒ 第一版那么写，sv-parent-proxy / proxy-visual 两条恒 0（不是实现错，是取盒路径错）。
        $proxy = $mid?->renderObject();
        echo 'sv-wrapped=' . ($mid !== null && $mid->widget() instanceof SizedBox ? 1 : 0) . "\n";
        echo 'sv-parent-proxy=' . ($proxy instanceof RenderProxy ? 1 : 0) . "\n";
        echo 'proxy-visual=' . ($proxy !== null && $proxy->mods() !== null && $proxy->mods()->hasVisual() ? 1 : 0) . "\n";
        // ② 产盒孩子：**不套壳**，样式直接落它自己（M2-5 之前的行为逐字不变）。
        //   ★ 判据必须落在**控件类型**上：只看"盒有没有视觉样式"的话，哪天
        //   有人给所有孩子都套壳，RenderProxy 照样有样式 ⇒ 假绿（2026-10-23
        //   调试时我就写出过这一版，靠 lint 的 instanceof-missing-use 才抓住）。
        $el2 = Card::make(Text::make('y'))->createElement();
        $el2->mount(null, $ctx);
        $kid = $el2->childAt(0);
        $tb = $kid?->renderObject();
        echo 'text-kid-direct=' . ($kid !== null && $kid->widget() instanceof Text ? 1 : 0) . "\n";
        echo 'text-box-visual=' . ($tb !== null && $tb->mods() !== null && $tb->mods()->hasVisual() ? 1 : 0) . "\n";
        exit(0);
    }

    if ($case === 'leafbox') {
        // 反向自检（`02` §6.2 叶子路径）：同一个 `LeafBoxState`，**不**覆写
        // `createRenderObjectFromState()` ⇒ 必须走 `build()`。
        // ★ 只写正例 = 永远绿 —— 计数器自己坏了也看不出来。
        LeafBoxWidget::resetBuildCalls();
        $fallback = LeafBoxFallbackWidget::make()->createElement();
        $fallback->mount(null, smokeContext());
        $fallback->rebuild();
        echo 'buildCalls=' . LeafBoxWidget::$buildCalls . "\n";
        exit(0);
    }

    if ($case === 'watchbuild') {
        // A4：订阅写在 build 体内 ⇒ 每重建一次多订一份。运行期守卫必须响。
        $element = (new BuildWatchWidget(new FakeStore()))->createElement();
        $element->mount(null, smokeContext());
    }

    if ($case === 'computedcycle') {
        // 求值期间又触发自己的求值 ⇒ 闭包不纯。E-COMPUTED-CYCLE。
        $self = new Computed(static function () use (&$self): int {
            return $self->get() + 1; // phpcs:ignore
        }, []);
        $self->get();
    }

    if ($case === 'flushcycle') {
        // build() 里标脏自己 ⇒ 每遍都重建 ⇒ 16 轮不收敛 ⇒ E-FLUSH-CYCLE（当帧，不是每帧默默重建）
        $loop = LoopPage::make()->createElement();
        $loop->mount(null, smokeContext());
        $loop->settle();
    }

    if ($case === 'chainwrite') {
        // 子的 build 标脏祖先 ⇒ 祖先第二遍重建时又把子标脏 ⇒ 子同帧第二次 rebuild
        // ⇒ S4 的 noteRebuilt() 当帧 Fatal（E-FLUSH-CYCLE，带类名）。
        $probe = new ChainProbe();
        $root = ChainPage::make($probe)->createElement();
        $root->mount(null, smokeContext());
        $probe->markOuterOnInnerBuild = true;
        $inner = $root->childAt(0);
        if ($inner instanceof StatefulElement) {
            $inner->state()?->markDirty();
        }
        $root->settle();
    }

    if ($case === 'routedup') {
        // 同名路由注册两次 ⇒ 后一次静默覆盖 ⇒ 上线后"导航到某页却没反应"
        RouteTable::make()->route('users', static fn (): Widget => new LeafProbe(1.0))
            ->route('users', static fn (): Widget => new LeafProbe(2.0));
    }

    if ($case === 'cmdup') {
        // 同名注册两次 ⇒ 后一次静默覆盖前一次。上线后"点了没反应"且查不出为什么。
        CommandRouter::make()->on('a.b', new EchoCommands())->on('a.b', new EchoCommands());
    }

    if ($case === 'optbare') {
        // 选项表给了裸值（`['甲','乙']`）⇒ 身份不明确。`[0 => 'x']` 与 `['x']` 在
        // PHP 里是同一个数组，"值 0"与"标签列表"无法区分 ⇒ 猜就是替应用做决定。
        Option::normalize(['甲', '乙']);
    }

    if ($case === 'optemptykey') {
        // 空字符串键与 `value('')`（"未选"哨兵）语义冲突 ⇒ 必须响亮失败
        Option::normalize(['' => '请选择']);
    }

    if ($case === 'optnofield') {
        // 行里没有显示名列 ⇒ 静默变成整列空白（WPF / antd 就是这样）⇒ 这里 Fatal
        Option::fromRows([['id' => 1, 'title' => '北京市']], 'name', 'id');
    }

    if ($case === 'optbadrow') {
        // 行不是数组（比如直接给了 list<string>）⇒ 形状不对，必须响亮失败
        Option::fromRows(['北京市', '上海市'], 'name', 'id');
    }

    if ($case === 'taskerr') {
        // 后台任务失败且**没给 onError** ⇒ 必须走 Fatal 序列（§17.4.2 的错误通道）：
        // 吞掉就是"界面永远转圈"，与三态的 Error 分支（§A.2.9）矛盾。
        $app = new TaskApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(320.0, 240.0)));
        $app->tasks()?->submit(new ThrowingJob('没人接的失败'), function (int $v): void {
        });
        $app->frame(16.0, BoxConstraints::loose(new Size(320.0, 240.0)));
    }

    if ($case === 'layout') {
        (new OversizeBox())->layout(new BoxConstraints(0.0, 10.0, 0.0, 10.0)); // C6 ⇒ 必须响亮失败
    }

    if ($case === 'constraints') {
        new BoxConstraints(20.0, 10.0); // min > max ⇒ 必须响亮失败（§13.1 C6）
    }

    if ($case === 'color') {
        Color::fromRgb(300, 0, 0); // 分量越界 ⇒ 不静默截断
    }

    if ($case === 'w8') {
        $list = new DisplayList();
        $list->record(DisplayList::OP_SAVE); // 只有 save 没有 restore
        $list->assertBalanced();             // W8 ⇒ 必须响亮失败
    }

    if ($case === 'pathop') {
        // W1/L3-1：`CAP_PATH` 已从 Optional 提档 Extended，Raster 不再响亮失败。
        // ★ 这一例反过来证明**真的画出了像素** —— 否则"提档了但画布是空的"
        //   是同一种静默失败，只是换了个方向。
        $canvas = new RasterCanvas(20, 20, Color::white(), Scale::of(1.0));
        $canvas->drawPath(
            Path::create()->moveTo(2, 2)->lineTo(8, 2)->lineTo(8, 8)->close(),
            Paint::fill(Color::black())
        );
        // 三角形 (2,2)(8,2)(8,8) 的解析面积 = 0.5 × 6 × 6 = 18
        $px = $canvas->toRgba();
        $ink = 0;
        for ($i = 0, $len = strlen($px); $i < $len; $i += 4) {
            if (ord($px[$i]) !== 255) {
                $ink++;
            }
        }
        fwrite(STDOUT, 'ink=' . $ink . "\n");
    }

    if ($case === 'imageop') {
        // W8-1 起 Raster 支持 drawImage ⇒ 这一例从"响亮失败"改成"真出墨"。
        // ★ 只断言退出码不够：画布全白也照样 exit 0（与 drawPath 同一条教训）。
        //   2×2 全黑图铺到 4×4 ⇒ 16 个黑像素。
        // ★ 用**黑**不用红：下面的 ink 判据只看 R 通道（历史口径），纯红的
        //   R=255 会被当成"白" ⇒ ink=0 假 FAIL（本轮踩到）。
        $canvas = new RasterCanvas(20, 20, Color::white(), Scale::of(1.0));
        $canvas->drawImage(
            ImageHandle::fromRgba(str_repeat("\x00\x00\x00\xFF", 4), 2, 2),
            null,
            new Rect(0.0, 0.0, 4.0, 4.0)
        );
        $px = $canvas->toRgba();
        $ink = 0;
        for ($i = 0, $len = strlen($px); $i < $len; $i += 4) {
            if (ord($px[$i]) !== 255) {
                $ink++;
            }
        }
        fwrite(STDOUT, 'ink=' . $ink . "\n");
    }

    if ($case === 'shadowop') {
        // W8-1 之后 Raster 唯一仍不支持的 Optional op（drawShadow）—— 响亮失败照旧
        $canvas = new RasterCanvas(20, 20, Color::white(), Scale::of(1.0));
        $canvas->drawShadow(new Shadow(new Rect(2.0, 2.0, 8.0, 8.0), Color::black(), 0.0, 1.0, 1.0, 2.0));
    }

    if ($case === 'clippathunbalanced') {
        // W1/L3-2 / `05` §10 #5：只 pushClipPath 不 popClip ⇒ W8 必须响亮失败。
        // ★ 补 `DisplayList::record()` 的 `OP_PUSH_CLIP_PATH` 计数之前，这一例的
        //   `clipDepth` 停在 0 ⇒ `assertBalanced()` 放行 ⇒ 后续全被裁而无人
        //   知晓（静默失败）。走子进程：`Errors::report` 默认 FATAL。
        $list = new DisplayList();
        $list->record(
            DisplayList::OP_PUSH_CLIP_PATH,
            Path::create()->addRect(new Rect(0.0, 0.0, 4.0, 4.0))
        );
        $list->assertBalanced();
    }

    if ($case === 'layerunbalanced') {
        // W1/L3-3 / `05` §10 #6b：只 pushLayer 不 popLayer ⇒ W8 必须抓到。
        // ★ 之前 DisplayList 根本不查层深（`Canvas.php` docblock 明写不覆盖）
        //   ⇒ 没人弹掉的乘数让后续绘制整体变透，画面"莫名变淡"且无人知晓。
        $list = new DisplayList();
        $list->record(DisplayList::OP_LAYER_PUSH, 0.5);
        $list->assertBalanced();
    }

    if ($case === 'custompaint') {
        // P2：自绘的轴没给、父容器又不限制这一轴 ⇒ 响亮失败（自绘区不能无穷大）
        $el = (new CustomPaint(new ProbePainter(), 120.0))->createElement();
        $el->mount(null, smokeContext());
        $el->effectiveBox()?->layout(new BoxConstraints(0.0, 300.0, 0.0, INF));
    }

    if ($case === 'gradientbad') {
        // 色标越界 ⇒ 构造期就失败（不等长/越界的色标表在光栅化时会静默错位）
        Gradient::linear(0, 0, 10, 0, [Color::white(), Color::black()], [0.0, 1.5]);
    }

    if ($case === 'shadowtransparent') {
        // 全透明阴影 = 什么都不会留下 ⇒ 直接失败，别留一个 no-op 的调用点
        new Shadow(new Rect(0, 0, 10, 10), new Color(0, 0, 0, 0));
    }

    if ($case === 'font') {
        // 显式家族名找不到任何 face ⇒ 连行高都拿不到 ⇒ 必须响亮失败（§8.3 血账 1）
        $manager = DeterministicFontManager::standard();
        (new DeterministicMetricsProvider($manager->collection(), new DeterministicShaper()))
            ->layout('x', TextStyle::of(14.0)->withFamily(FontFamily::named('NoSuchFont')), INF, Scale::of(1.0));
    }

    if ($case === 'fontfile') {
        // M0 只带内置点阵字模：解析字体文件这条路没实现 ⇒ 必须响亮失败而不是静默忽略
        DeterministicFontManager::standard()->registerFile('C:/nope.ttf');
    }

    if ($case === 'flexreport') {
        // C5：Report 策略 ⇒ 溢出必须中止，不是画到盒外面去
        $column = new RenderFlex(true, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Report);
        $column->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
        $column->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
        $column->layout(BoxConstraints::tight(new Size(10.0, 50.0)));
    }

    if ($case === 'shrinkoverflow') {
        // C5 兜底：压缩把弹性子压到 0 之后**仍然**放不下 ⇒ Report 必须中止
        //   ★ 怎么才放不下：`shrink` 只能压弹性子，间距与固定子压不动。这里
        //     间距 20×2 加两个 40 的固定子就吃掉 120 > 100，弹性子压到 0 也补不回来。
        //   ★ 册子 `04` §12.2 #3 原本写"一个子 basis 200 shrink 1 进 100 ⇒ 该子 0
        //     且报 C5"：按加权公式它压到 100 就正好装满（根本不用冻结），数字推不出
        //     那条结论；"给 width 保底"也保不住（`tighten()` 把 width 夹进紧约束）。
        $row = new RenderFlex(false, 20.0, new EdgeInsets(), FlexAlign::Start, Overflow::Report);
        $row->addChild(new SolidBox(new Size(40.0, 10.0), 40.0, 10.0));
        $row->addChild(flexIntent(0, 1, 10.0));
        $row->addChild(new SolidBox(new Size(40.0, 10.0), 40.0, 10.0));
        $row->layout(BoxConstraints::tight(new Size(100.0, 40.0)));
    }

    if ($case === 'flexscroll') {
        // 容器上的 Scroll **不支持**（2026-10-25，I4）⇒ 响亮失败并指路 ScrollView，
        // 不是静默按 Clip 走。（`Overflow::Wrap` 已从枚举删除：它是"重排"不是"舍弃方式"。）
        $column = new RenderFlex(true, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Scroll);
        $column->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
        $column->addChild(new SolidBox(new Size(10.0, 40.0), 10.0, 40.0));
        $column->layout(BoxConstraints::tight(new Size(10.0, 50.0)));
    }

    if ($case === 'dockoverflow') {
        // C5/W4：Dock 默认 Report ⇒ 条轨**合计**超出必须中止（每根各自合规，
        //   冲突在合计层暴露 —— 30+20 > 40）
        $dock = new RenderDock(Overflow::Report);
        $dock->setSlot('top', new SolidBox(new Size(100.0, 30.0), 100.0, 30.0));
        $dock->setSlot('bottom', new SolidBox(new Size(100.0, 20.0), 100.0, 20.0));
        $dock->setSlot('center', new SolidBox(new Size(10.0, 10.0), 10.0, 10.0));
        $dock->layout(BoxConstraints::tight(new Size(100.0, 40.0)));
    }

    if ($case === 'eachdup') {
        // 同层重复 key ⇒ diff 复用错乱（R4）。绝不静默 ⇒ Fatal
        Each::make(fn(array $u): int => $u['id'])
            ->items([['id' => 1], ['id' => 1]], fn(array $u): Widget => Text::make('x'));
    }

    if ($case === 'eachkeytag') {
        // key 1 与 '1' **不**算重复 —— 与 Key::same 同口径，不做类型互转
        Each::make(fn(array $u): string|int => $u['id'])
            ->items([['id' => 1], ['id' => '1']], fn(array $u): Widget => Text::make('x'));
        exit(1); // 走到这里 = 该报的没报
    }

    if ($case === 'flexvalue') {
        // flex 份额 < 1 分不到空间，静默夹紧只会让"给了 Expanded 却不生效"更难查
        // ★ 守卫住 `Expanded::flex()`（`RenderFlexible` 的构造器守卫随它一起删了）
        Expanded::make(Text::make('x'))->flex(0);
    }

    if ($case === 'accelconflict') {
        // G2：同一个组合绑两次 ⇒ 响亮失败，不是后绑覆盖前绑（用户按了没反应且不报错）
        AcceleratorTable::make()
            ->bind(KeyChord::ctrl('R'), 'users.refresh')
            ->bind(KeyChord::ctrl('R'), 'users.reload');
    }

    if ($case === 'noboundary') {
        // O-12 A′ S3 的"无边界"半边：帧末异常沿途没有 ErrorBoundary 捕获
        //   ⇒ Fatal 序列（exit 3 + Errors.log）。**不吞** —— 吞了就是窗口冻结
        //   在上一帧且不报错（G2 明令禁止的半静默）。
        $ctx = new BuildContext(240.0, 80.0, ThemeData::light(), new DeterministicMetricsProvider(
            DeterministicFontManager::standard()->collection(),
            new DeterministicShaper(),
        ));
        $bad = BoomPage::make()->createElement();
        $bad->mount(null, $ctx); // 首次 build 成功
        $bad->state()?->boom();  // 标脏 ⇒ 帧末重建时抛
        (new Frame(new SurfaceProbe()))->run($bad, BoxConstraints::tight(new Size(240.0, 80.0)), 0.0);
        echo "不该到达这里\n";
    }

    // ★ 量具反例：`expectBox()` 必须**真的会红**。只写正例的助手等于"永远绿"，
    //   它自己错了也看不出来（G2：量具要有反向自检，与 `lint --selftest` 同理）。
    //   三条覆盖三种失败路径：原点不符 / 盒为 null / 盒没 layout。
    // ★ 必须放在**本链内**：链尾那句 `exit(0)` 是"未知用例静默退出"，
    //   放链外的话这段永远不执行（2026-10-22 踩到，表现为子进程零输出）。
    if ($case === 'expectbox') {
        $probe = new SolidBox(new Size(10.0, 6.0), 10.0, 6.0);
        $host = new RenderProxy();
        $host->setMods((new Modifier())->withPadding(4.0, 2.0, 4.0, 2.0));
        $host->setChild($probe);
        $host->layout(BoxConstraints::loose(new Size(60.0, 60.0)));

        // 真实矩形是 (4, 2) 10×6 —— 这里故意把 x 写成 5
        expectBox($probe, 5.0, 2.0, 10.0, 6.0, '量具反例 · 原点差 1px');
        expectBox(null, 0.0, 0.0, 10.0, 6.0, '量具反例 · 盒为 null');
        expectBox(new SolidBox(new Size(10.0, 6.0), 10.0, 6.0), 0.0, 0.0, 10.0, 6.0, '量具反例 · 没 layout');

        exit(0); // 断言层自己打印 FAIL，判定交给外层
    }
    // ★ W8-3 的两个构造期守卫：`Errors::report` 走 `abort()` ⇒ **真的 exit(3)**，
    //   进程内 `try/catch` 接不住（`exit()` 不是异常）⇒ 必须走子进程。2026-10-24
    //   第一稿就是写在进程内的，现象是 smoke 在那一行**静默截断**（无汇总行、
    //   无 FAIL、退出码还是 0 —— 因为管道吃掉了子进程的退出码）。
    if ($case === 'gridzero') {
        Grid::make(0);
        echo "不该到达这里\n";
        exit(0);
    }

    if ($case === 'lazygridpitch') {
        $bad = LazyGrid::make(3, 96.0)->spacing(96.0)->items([0], static fn(int $i): Widget => Text::make('x'));
        $element = $bad->createElement();
        $element->mount(null, smokeContext());
        $element->effectiveBox()?->layout(BoxConstraints::tight(new Size(336.0, 240.0)));
        echo "不该到达这里\n";
        exit(0);
    }

    exit(0); // 走到这里说明"该中止的没中止"
}


// ── 断言与工具 ──

$failures = 0;
$checks = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    ++$checks;
    if ($ok) {
        printf("  ok    %s\n", $name);
        return;
    }
    $failures++;
    printf("  FAIL  %s%s\n", $name, $detail === '' ? '' : '  —— ' . $detail);
}

/** 清掉日志与状态，让每个用例互不污染 */
function fresh(string $log): void
{
    Errors::reset();
    Errors::setLogPath($log);
    if (is_file($log)) {
        unlink($log);
    }
}

/** @return array{0: int, 1: string} 退出码 + 输出 */
function runCase(string $script, string $case, ?string $marker): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --case=' . $case;
    if ($marker !== null) {
        $command .= ' ' . escapeshellarg($marker);
    }
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    return [$code, implode("\n", $output)];
}

/**
 * 墨迹包围盒 `[minX, maxX, minY, maxY]`；无墨 ⇒ null。
 *
 * ★ 只数墨点数（`inkCount`）测不出"画在了哪儿" —— `Center` 算对偏移而
 *   绘制丢掉它，墨点数一样多，画面却贴在左上角。
 */
function inkBounds(RasterCanvas $canvas): ?array
{
    $minX = PHP_INT_MAX;
    $maxX = -1;
    $minY = PHP_INT_MAX;
    $maxY = -1;
    $w = 320;
    $h = 240;

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ($canvas->pixelAt($x, $y)->r < 200) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }
    }

    return $maxX < 0 ? null : [$minX, $maxX, $minY, $maxY];
}

function logText(string $log): string
{
    return is_file($log) ? (string) file_get_contents($log) : '';
}

/** 子进程用例也要挂载树 ⇒ 造一份和主进程同规格的渲染环境 */
function smokeContext(): BuildContext
{
    return new BuildContext(
        320.0,
        240.0,
        ThemeData::light(),
        new DeterministicMetricsProvider(
            DeterministicFontManager::standard()->collection(),
            new DeterministicShaper(),
        )
    );
}

/** 相对亮度（WCAG 口径），用来断言"高对比度"是真高而不是标了个名 */
function luminance(Color $c): float
{
    return (0.2126 * $c->r + 0.7152 * $c->g + 0.0722 * $c->b) / 255.0;
}

/** 数整棵子树有多少个 Element（含自己） */
function countTree(Element $element): int
{
    $total = 1;
    $element->visitChildren(function (Element $child) use (&$total): void {
        $total += countTree($child);
    });

    return $total;
}

function contrast(Color $a, Color $b): float
{
    $hi = max(luminance($a), luminance($b));
    $lo = min(luminance($a), luminance($b));

    return ($hi + 0.05) / ($lo + 0.05);
}

/** 非背景像素数：用来断言"文字真的画上了 / 真的被挡住了" */
function inkCount(RasterCanvas $canvas, ?Color $background = null): int
{
    $background ??= Color::white();
    $ink = 0;
    for ($y = 0; $y < $canvas->height(); $y++) {
        for ($x = 0; $x < $canvas->width(); $x++) {
            if (!$canvas->pixelAt($x, $y)->equals($background)) {
                $ink++;
            }
        }
    }
    return $ink;
}

/**
 * 盒在客户区的绝对原点（沿父链累加相对偏移）。
 *
 * 菜单 / 右键 / 拖放的测试要"点在某一行上"，而行的位置只能从盒树反推 ——
 * 坐标写死会在任何 padding 变动时静默失准。
 *
 * @return array{0: float, 1: float}
 */
/**
 * 深度优先找第一个满足条件的 Element（`visitChildren` 只走直接子节点 ⇒ 自己递归）。
 */
function findElement(Element $root, callable $pred): ?Element
{
    $found = null;
    $walk = function (Element $el) use (&$walk, &$found, $pred): void {
        if ($found !== null) {
            return;
        }
        if ($pred($el)) {
            $found = $el;
            return;
        }
        $el->visitChildren($walk);
    };
    $walk($root);

    return $found;
}

/**
 * 夹具：带份额的纯转发盒（`RenderFlexible` 在 M2-4 删除后的替代）。
 *
 * ★ 这里直接写盒上的意图字段，而不是 `setMods()` —— 夹具要测的是**盒**的行为，
 *   不该绕一圈 Element。生产路径上份额走 `mods.grow` → `syncBox()` → 同一组字段。
 */
function flexProxy(int $share): RenderProxy
{
    $box = new RenderProxy();
    $box->setFlexIntent($share, 0, \NAN, RenderBox::ALIGN_INHERIT);

    return $box;
}

function flexIntent(int $grow, int $shrink, float $basis): RenderProxy
{
    $box = new RenderProxy();
    $box->setFlexIntent($grow, $shrink, $basis, RenderBox::ALIGN_INHERIT);

    return $box;
}

function boxOrigin(RenderBox $box): array
{
    // ★ 委派 `RenderBox::absoluteOrigin()`，**不要**在这里手抄一遍累加：
    //   W5 之后子盒 offset 住**内容盒**坐标，"内容原点 → 边框盒原点"那一步在
    //   `absoluteOrigin()` 里补。手抄版少补一次 ⇒ `Padding::all(24)` 的字段盒
    //   原点算成 (0,0)，7 条框选/光标断言全红（2026-10-22 实测）。
    return RenderBox::absoluteOrigin($box);
}

/**
 * 断言某个盒的**矩形**（客户区绝对坐标，`07` §5.2）。
 *
 * ★ 用 `RenderBox::absoluteOrigin()` 而不是自己沿父链累加：W5/M2-3 之后子盒
 *   offset 住**内容盒**坐标，"内容原点 → 边框盒原点"那一步只有它做对了。
 * ★ **四个数一起断言**：几何错通常是"整体偏了一个 padding"，分开断言会得到
 *   四条消息、还得自己拼；合起来一条就够定位。
 * ★ 严格 `===`，**不加容差**（`07` §5.3）：布局是确定性的，容差会掩盖"差
 *   0.3px"的真实 bug。★ 涉及**字体度量**的断言不要用它 —— 那类只能取上界。
 * ★ 盒为 null / 没 layout 过（`size()` 为 null）⇒ 断言失败并写明是哪种，
 *   不是崩溃（`07` §9 第 5 条）。
 */
function expectBox(?RenderBox $box, float $x, float $y, float $w, float $h, string $name = ''): void
{
    $label = $name !== '' ? $name : '盒矩形';
    if ($box === null) {
        check($label, false, '盒为 null（没挂上 / 没 layout）');

        return;
    }
    $size = $box->size();
    if ($size === null) {
        check($label, false, '盒 size() 为 null（layout 没跑）');

        return;
    }
    [$ax, $ay] = RenderBox::absoluteOrigin($box);
    check(
        $label,
        $ax === $x && $ay === $y && $size->width === $w && $size->height === $h,
        sprintf(
            '期望 (%.4g, %.4g) %.4g×%.4g，实际 (%.4g, %.4g) %.4g×%.4g',
            $x, $y, $w, $h,
            $ax, $ay, $size->width, $size->height
        )
    );
}

// ── Skia 桥的假实现（⑨+1 conformance 夹具用）──────────────────────
// ★ 签名与 cpp-src/drift_skia.stub.php 逐字一致 —— 夹具测的必须就是 ABI 本身
//   （stub 注释原话："签名不一致时是链接期静默失败"）。
// ★ `use function` 是运行期解析：真桥只存在于 AOT 构建，解释器面从不加载
//   ⇒ SkiaCanvas 的调用落到这些假函数，参数进 $GLOBALS 记录 ——
//   "Skia 收到了什么"成为可断言的纯数据，不必每次构建 exe。
// ★ 两个开关只服务夹具：FAIL_CREATE 造"句柄 0"（H 用例）；
//   SABOTAGE 把 draw_rect 的 x 篡改一格（量具反例自检）。
if (!function_exists('drift_skia_canvas_create')) {
    function drift_skia_canvas_create(int $width, int $height, int $backgroundRgba): int
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['create', $width, $height, $backgroundRgba];
        return empty($GLOBALS['DRIFT_SMOKE_SKIA_FAIL_CREATE']) ? 1 : 0;
    }

    function drift_skia_canvas_destroy(int $canvas): void
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['destroy'];
    }

    function drift_skia_push_clip(int $canvas, float $x, float $y, float $w, float $h): void
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['push_clip', $x, $y, $w, $h];
    }

    function drift_skia_pop_clip(int $canvas): void
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['pop_clip'];
    }

    function drift_skia_draw_rect(
        int $canvas,
        float $x,
        float $y,
        float $w,
        float $h,
        float $radius,
        int $rgba,
        bool $stroke,
        float $strokeWidth,
    ): void {
        if (!empty($GLOBALS['DRIFT_SMOKE_SKIA_SABOTAGE'])) {
            $x += 1.0; // 已知违规件：只服务量具反例自检（判据恒真 = 夹具空转）
        }
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['draw_rect', $x, $y, $w, $h, $radius, $rgba, $stroke, $strokeWidth];
    }

    function drift_skia_draw_glyph(
        int $canvas,
        string $family,
        bool $bold,
        float $fontSize,
        float $penX,
        float $baselineY,
        int $codepoint,
        int $rgba,
    ): void {
        if (!empty($GLOBALS['DRIFT_SMOKE_SKIA_SABOTAGE'])) {
            $penX += 1.0; // 已知违规件：只服务量具反例自检（判据恒真 = 夹具空转）
        }
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['draw_glyph', $family, $bold, $fontSize, $penX, $baselineY, $codepoint, $rgba];
    }

    function drift_skia_draw_ellipse(
        int $canvas,
        float $x,
        float $y,
        float $w,
        float $h,
        int $rgba,
        bool $stroke,
        float $strokeWidth,
    ): void {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['draw_ellipse', $x, $y, $w, $h, $rgba, $stroke, $strokeWidth];
    }

    function drift_skia_draw_line(
        int $canvas,
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        int $rgba,
        float $strokeWidth,
    ): void {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['draw_line', $x1, $y1, $x2, $y2, $rgba, $strokeWidth];
    }

    function drift_skia_set_gradient(
        int $canvas,
        int $radial,
        float $x1, float $y1, float $x2, float $y2,
        int $n, int $c0, int $c1, int $c2, int $c3,
        float $s0, float $s1, float $s2, float $s3,
    ): void {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['set_gradient', $radial, $x1, $y1, $x2, $y2, $n, $c0, $c1, $c2, $c3, $s0, $s1, $s2, $s3];
    }

    function drift_skia_set_dash(int $canvas, float $on, float $off, float $phase): void
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['set_dash', $on, $off, $phase];
    }

    function drift_skia_clear_source(int $canvas): void
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['clear_source'];
    }

    // ── P4：Optional / Extended 复合 op 的假桥（记录调用，不真画）──
    function drift_skia_draw_path(
        int $canvas,
        int $count,
        string $verbs,
        string $coords,
        int $rgba,
        bool $stroke,
        float $strokeWidth,
    ): int {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['draw_path', $count, $verbs, $coords, $rgba, $stroke, $strokeWidth];
        return $count * 3;
    }

    function drift_skia_draw_image(
        int $canvas,
        string $rgba,
        int $w,
        int $h,
        float $sx, float $sy, float $sw, float $sh,
        float $dx, float $dy, float $dw, float $dh,
        bool $hasSrc,
    ): void {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['draw_image', strlen($rgba), $w, $h, $sx, $sy, $sw, $sh, $dx, $dy, $dw, $dh, $hasSrc];
    }

    function drift_skia_draw_shadow(
        int $canvas,
        float $x, float $y, float $w, float $h,
        int $argb, float $radius, float $dx, float $dy, float $blur,
    ): void {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['draw_shadow', $x, $y, $w, $h, $argb, $radius, $dx, $dy, $blur];
    }

    function drift_skia_push_layer(int $canvas, float $opacity): void
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['push_layer', $opacity];
    }

    function drift_skia_pop_layer(int $canvas): void
    {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['pop_layer'];
    }

    /** 毛玻璃：真桥只存在于 AOT ⇒ 这里记下"Skia 收到什么"（sigma 是否乘了 dpr、矩形是否进了设备空间） */
    function drift_skia_blur_backdrop(
        int $canvas,
        float $sigma,
        float $x,
        float $y,
        float $w,
        float $h,
    ): void {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['blur_backdrop', $sigma, $x, $y, $w, $h];
    }

    function drift_skia_push_clip_path(
        int $canvas,
        int $count,
        string $verbs,
        string $coords,
    ): void {
        $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][] = ['push_clip_path', $count, $verbs, $coords];
    }

    function drift_skia_read_pixels(int $canvas): string
    {
        return '';
    }

    function drift_skia_font_ready(): bool
    {
        return true;
    }
}

// ── Win32 桥的假实现（窗口外观材质，P1 收敛 2026-10-26）────────────────
// ★ smoke 从不构造真窗口，只验"PHP 侧把意图翻成了哪个 ABI 编号 / 哪个 bool"。
// ★ 签名与 `cpp-src/drift_win32.stub.php` 逐字一致（stub 原话：签名不一致是
//   链接期静默失败）。收敛后三个散落入口（set_opacity / set_pixel_alpha /
//   set_backdrop）合成一个 `apply_material`，外加能力查询 `material_caps`。
// ★ `set_frameless` / `dpi` 也假：像素 alpha 要无边框，`applyMaterial()` 的
//   正路径测试需要先 `setFrameless()`，而它内部会读 `dpi` 折物理像素。
if (!function_exists('drift_win_apply_material')) {
    function drift_win_apply_material(int $hwnd, bool $pixelAlpha, float $opacity, int $backdropKind): void
    {
        $GLOBALS['DRIFT_SMOKE_WIN_MATERIAL'][] = [$hwnd, $pixelAlpha, $opacity, $backdropKind];
    }
}

/** @return list<array{int, bool, float, int}> 材质调用记录：[hwnd, pixelAlpha, opacity, backdropKind] */
function winMaterialCalls(): array
{
    return $GLOBALS['DRIFT_SMOKE_WIN_MATERIAL'] ?? [];
}

if (!function_exists('drift_win_set_frameless')) {
    function drift_win_set_frameless(int $hwnd, int $dragHeight): void
    {
        $GLOBALS['DRIFT_SMOKE_WIN_FRAMELESS'][] = [$hwnd, $dragHeight];
    }
}

if (!function_exists('drift_win_dpi')) {
    function drift_win_dpi(int $hwnd): int
    {
        return 96;   // 假成 96 DPI ⇒ dpr 1.0；真实 Win32 的一致性由真机闸门保证
    }
}

if (!function_exists('drift_win_material_caps')) {
    function drift_win_material_caps(): int
    {
        // 假桥报告"全能力"（Win11 形态）：opacity | pixelAlpha | blur | acrylic | 圆角
        return 0x01 | 0x02 | 0x04 | 0x08 | 0x10;
    }
}

// ── 假桥记录的访问器与换算（⑨+1 用）──────────────────────────────

/** 清空跨边界调用记录（每个用例前必调，否则用例互相污染） */
function skiaBridgeReset(): void
{
    $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] = [];
}

/** @return list<string> 按序取出调用操作名（create / draw_rect / push_clip …） */
function skiaCallOps(): array
{
    $ops = [];
    foreach ($GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] ?? [] as $call) {
        $ops[] = $call[0];
    }
    return $ops;
}

/** @return list<array> draw_rect 记录：[op, x, y, w, h, radius, rgba, stroke, strokeWidth] */
function skiaDrawRectCalls(): array
{
    $out = [];
    foreach ($GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] ?? [] as $call) {
        if ($call[0] === 'draw_rect') {
            $out[] = $call;
        }
    }
    return $out;
}

/** @return list<array> push_clip 记录：[op, x, y, w, h] */
function skiaClipCalls(): array
{
    $out = [];
    foreach ($GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] ?? [] as $call) {
        if ($call[0] === 'push_clip') {
            $out[] = $call;
        }
    }
    return $out;
}

/** @return list<array> draw_glyph 记录：[op, family, bold, fontSize, penX, baselineY, codepoint, rgba] */
function skiaGlyphCalls(): array
{
    $out = [];
    foreach ($GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] ?? [] as $call) {
        if ($call[0] === 'draw_glyph') {
            $out[] = $call;
        }
    }
    return $out;
}

/**
 * 从 Paragraph 独立算出每个字形的期望跨边界参数（**正确语义**：offsetX/offsetY 施加）。
 *
 * 与 `SkiaCanvas::drawParagraph()` 的循环同公式但独立实现 —— 判据不靠被测代码自己背书。
 * 今天所有字形 offset 恒为 0.0（`DeterministicShaper` / `TextLayout` 的 new Glyph 全部
 * 显式传 0.0，grep 实证）⇒ 本算式与桥记录一致；真塑形（M4）产生非零 offset 后，
 * 本判据会**逼出** Skia 侧漏加 offset 的修复（分歧现状由 P4 钉住）。
 *
 * @return list<array{codepoint:int, penX:float, baselineY:float}>
 */
function skiaExpectedGlyphs(Paragraph $paragraph, float $originX, float $originY, Scale $scale): array
{
    $dpr = $scale->dpr;
    $out = [];
    foreach ($paragraph->lines as $line) {
        $baselineLogical = $originY + $line->baseline;
        $pen = $originX + $line->offsetX;
        foreach ($line->runs as $run) {
            foreach ($run->glyphs as $glyph) {
                $out[] = [
                    'codepoint' => $glyph->codepoint,
                    'penX' => round(($pen + $glyph->offsetX) * $dpr),
                    'baselineY' => round(($baselineLogical + $glyph->offsetY) * $dpr),
                ];
                $pen += $glyph->advance;
            }
        }
    }
    return $out;
}

/**
 * 从 Paragraph 独立算出软光栅墨迹的期望并集 bbox（含端点，房规 [l, r, t, b]）。
 *
 * 与 `RasterCanvas::blendBitmap()` 同公式但独立实现，口径与
 * `deviceInkBounds()` 的「非透明即墨」完全一致 —— **逐位图扫描实际墨区**
 * （`coverageAt() > 0`），位图内墨区偏移叠加 bearing 后再取并集。
 *
 * ★ 踩过（夹具第一次跑抓到自己）：第一版假设整张位图都有墨
 *   （`maxY = top + height − 1`）—— 位图字体字形不占满全高：
 *   U+48 @14px 位图 14 行但实际墨 13 行（底部 1 行全透明）、
 *   @28px 位图 28 行但实际墨 26 行 ⇒ 纵向 maxY 系统性差 1（dpr=2 差 3），
 *   4 条对照全线 FAIL。横向无透明边列（bearingX ≡ 0）故 penX 互比不受影响。
 * 位图在**回放时**才由 `face->rasterize(codepoint, pixelSize)` 产生（确定性）⇒
 * 夹具里再调一次拿到的是同一张；bearingY = −ascent×pixelSize（"位图即 em 盒"）。
 */
function skiaExpectedInkBounds(Paragraph $paragraph, float $originX, float $originY, Scale $scale): ?array
{
    $dpr = $scale->dpr;
    $pixelSize = $paragraph->style->size * $dpr;
    $minX = PHP_INT_MAX;
    $maxX = -1;
    $minY = PHP_INT_MAX;
    $maxY = -1;

    foreach ($paragraph->lines as $line) {
        $baselineLogical = $originY + $line->baseline;
        $pen = $originX + $line->offsetX;
        foreach ($line->runs as $run) {
            foreach ($run->glyphs as $glyph) {
                $bitmap = $run->face->rasterize($glyph->codepoint, $pixelSize);
                if ($bitmap !== null && !$bitmap->isEmpty()) {
                    // 扫描位图实际墨区（coverage > 0 即墨，与 deviceInkBounds 同口径）
                    $inkTop = null;
                    $inkBottom = null;
                    $inkLeft = null;
                    $inkRight = null;
                    for ($y = 0; $y < $bitmap->height; $y++) {
                        for ($x = 0; $x < $bitmap->width; $x++) {
                            if ($bitmap->coverageAt($x, $y) > 0) {
                                if ($inkTop === null) {
                                    $inkTop = $y;
                                }
                                $inkBottom = $y;
                                if ($inkLeft === null || $x < $inkLeft) {
                                    $inkLeft = $x;
                                }
                                if ($inkRight === null || $x > $inkRight) {
                                    $inkRight = $x;
                                }
                            }
                        }
                    }
                    if ($inkTop !== null) {
                        $baseX = (int) round(($pen + $glyph->offsetX) * $dpr);
                        $baseY = (int) round(($baselineLogical + $glyph->offsetY) * $dpr);
                        $left = $baseX + $bitmap->bearingX + $inkLeft;
                        $top = $baseY + $bitmap->bearingY + $inkTop;
                        $minX = min($minX, $left);
                        $maxX = max($maxX, $left + ($inkRight - $inkLeft));
                        $minY = min($minY, $top);
                        $maxY = max($maxY, $top + ($inkBottom - $inkTop));
                    }
                }
                $pen += $glyph->advance;
            }
        }
    }

    return $maxX < 0 ? null : [$minX, $maxX, $minY, $maxY];
}

/**
 * 桥记录里的矩形 (x, y, w, h) → 软光栅墨迹 bbox（含端点）。
 *
 * 换算依据：rasterize 逐**像素中心**测试，整边矩形覆盖 [left, right)×[top, bottom)
 * ⇒ 墨迹像素 x ∈ [left, right−1]。设备矩形由 quantizeRect 产出（round 的产物，
 * 必为整数）⇒ 强转 int 无损。
 *
 * ★ 返回顺序与 `deviceInkBounds()` 同款（房规）：[left, right, top, bottom]（含端点）。
 *   ★ 踩过：第一版写成 [left, top, right, bottom]，8 条对照全线 FAIL ——
 *     夹具第一次跑就靠这个顺序错位自证了"判据真的在比数值"。
 */
function skiaRectToInk(array $rec): array
{
    $x = (int) $rec[1];
    $y = (int) $rec[2];
    return [$x, $x + (int) $rec[3] - 1, $y, $y + (int) $rec[4] - 1];
}

/** 墨迹 bbox 的调试串 */
function fmtInk(?array $ink): string
{
    return $ink === null ? 'null' : '[' . implode(',', $ink) . ']';
}

/**
 * 墨迹 bbox（含端点，尺寸无关版）："不等于透明"即墨。
 * `inkBounds()` 写死 320×240 且判据是 r<200（白底假设）—— 这里要任意尺寸 + 透明底。
 */
function deviceInkBounds(RasterCanvas $canvas): ?array
{
    $clear = Color::transparent();
    $minX = PHP_INT_MAX;
    $maxX = -1;
    $minY = PHP_INT_MAX;
    $maxY = -1;

    for ($y = 0; $y < $canvas->height(); $y++) {
        for ($x = 0; $x < $canvas->width(); $x++) {
            if (!$canvas->pixelAt($x, $y)->equals($clear)) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }
    }

    return $maxX < 0 ? null : [$minX, $maxX, $minY, $maxY];
}

echo "M0 冒烟\n";

// ① PSR-4 装载：前缀表驱动，不生成 vendor/
check('autoload · Drift\\Errors', class_exists(Errors::class));
check('autoload · Drift\\Widget\\Widget', class_exists(Widget::class));
check('autoload · Drift\\Dev\\ 前缀优先于 Drift\\', class_exists(SealProbe::class));

// ② Widget 的封存状态
fresh($log);
$widget = new SealProbe();
check('Widget · 初始未封存', !$widget->isSealed());
$widget->seal();
check('Widget · seal() 后已封存', $widget->isSealed());
$widget->seal();
check('Widget · seal() 幂等', $widget->isSealed());

// ③ Diagnostic：进环形缓冲、超容量丢最旧、顺序保持
fresh($log);
for ($i = 0; $i < 105; $i++) {
    Errors::report('d' . $i, Errors::DIAGNOSTIC);
}
$ring = Errors::recentDiagnostics();
check('Errors · Diagnostic 环形缓冲容量 = 100', count($ring) === 100, '实际 ' . count($ring));
check(
    'Errors · 丢最旧、留最新、顺序正序',
    ($ring[0] ?? '') === 'd5' && ($ring[99] ?? '') === 'd104',
    ($ring[0] ?? '-') . ' … ' . ($ring[99] ?? '-')
);
check('Errors · Diagnostic 不落盘', !is_file($log));

// ④ Recoverable 在 release 下降级继续（不中止），但留痕
fresh($log);
Errors::setDebug(false);
Errors::report('降级：不该中止', Errors::RECOVERABLE);
check('Errors · Recoverable + release 不中止', true);
check('Errors · Recoverable + release 留痕', str_contains(logText($log), '降级：不该中止'));
Errors::setDebug(true);

// ⑤ Fatal：写日志（错误 + 栈 + 环形缓冲）→ 弹错误框 → exit(3)
fresh($log);
if (is_file($boxMarker)) {
    unlink($boxMarker);
}
[$code, $output] = runCase(__FILE__, 'fatal', $boxMarker);
check('Errors · Fatal 退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
$text = logText($log);
check('Errors · Fatal 写了 Errors.log', str_contains($text, '冒烟：走 Fatal 路径'));
check('Errors · Fatal 日志带栈', str_contains($text, '#0'));
check('Errors · Fatal 日志带环形缓冲段', str_contains($text, '最近 2 条 Diagnostic'));
check('Errors · Fatal 环形缓冲含 Fatal 之前的现场', str_contains($text, '冒烟：前置诊断 B'));
check(
    'Errors · Fatal 调到了原生错误框',
    is_file($boxMarker) && str_starts_with((string) file_get_contents($boxMarker), 'Drift FATAL|')
);
if (is_file($boxMarker)) {
    unlink($boxMarker);
}

// ⑥ 封存后写入：Fatal（A11 的守卫）
fresh($log);
[$code, $output] = runCase(__FILE__, 'seal', null);
check('Widget · 封存后写入退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Widget · 封存后写入写了 Errors.log', str_contains(logText($log), 'Widget 已封存'));

// ⑦ Geometry：唯一的量化函数 + 无界用 INF 不用 0
fresh($log);
check('Geometry · dpr = dpi / 96', Scale::fromDpi(96.0)->isIdentity() && Scale::fromDpi(120.0)->dpr === 1.25);
check('Geometry · quantize = round(logical × dpr)', Scale::of(1.25)->quantize(10.0) === 13.0);

$scale = Scale::of(1.5);
$leftBox = $scale->quantizeRect(new Rect(0.0, 0.0, 3.0, 3.0));  // 0 .. 4.5
$rightBox = $scale->quantizeRect(new Rect(3.0, 0.0, 3.0, 3.0)); // 4.5 .. 9
check(
    'Geometry · 相邻矩形共享同一条像素边（不错开半像素）',
    $leftBox->right() === $rightBox->left,
    $leftBox->right() . ' vs ' . $rightBox->left
);

$unbounded = BoxConstraints::unbounded();
check('Geometry · 无界 = INF（不是 0）', $unbounded->maxWidth === INF && $unbounded->hasInfiniteWidth());
check('Geometry · 0 是合法的紧尺寸', BoxConstraints::tight(Size::zero())->isTight());

$bounded = new BoxConstraints(10.0, 20.0, 0.0, INF);
check('Geometry · constrain 夹进区间', $bounded->constrain(new Size(5.0, 100.0))->equals(new Size(10.0, 100.0)));
check('Geometry · tighten 收成紧约束', $bounded->tighten(new Size(50.0, 7.0))->equals(new BoxConstraints(20.0, 20.0, 7.0, 7.0)));
check('Geometry · loosen 放开下界保留上界', $bounded->loosen()->equals(new BoxConstraints(0.0, 20.0, 0.0, INF)));

check('Geometry · Rect 右边界现算不缓存', Rect::fromLtrb(1.0, 2.0, 4.0, 6.0)->right() === 4.0);
check('Geometry · EdgeInsets 归几何面', EdgeInsets::all(8.0)->horizontal() === 16.0 && EdgeInsets::symmetric(4.0, 2.0)->vertical() === 4.0);

// ⑧ C6：约束违反 ⇒ 响亮失败
fresh($log);
[$code, $output] = runCase(__FILE__, 'constraints', null);
check('Geometry · 约束违反退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Geometry · 约束违反写了 Errors.log', str_contains(logText($log), '违反约束'));

// ⑨ Rendering：录制 → 回放，同一份 DisplayList 必得同一张位图（G3）
fresh($log);
$red = Color::fromRgb(255, 0, 0);
$list = new DisplayList();
$recorder = new RecordingCanvas($list);
$recorder->save();
$recorder->translate(10.0, 20.0);
$recorder->pushClip(new Rect(0.0, 0.0, 5.0, 5.0));
$recorder->drawRect(new Rect(0.0, 0.0, 5.0, 5.0), Paint::fill($red));
$recorder->popClip();
$recorder->restore();

check('Rendering · 录制命令数', $list->count() === 6, (string) $list->count());
check('Rendering · W8 栈平衡（save/clip 都归零）', $list->saveDepth() === 0 && $list->clipDepth() === 0);

$raster = new RasterCanvas(32, 32, Color::white());
$list->replay($raster);
check('Rendering · 回放落在平移后的位置', $raster->pixelAt(12, 22)->equals($red));
check('Rendering · 平移外的区域未被污染', $raster->pixelAt(2, 2)->equals(Color::white()));

// 裁剪真的生效：画一个大矩形，只该出现裁剪区内那一块
$clipped = new RasterCanvas(32, 32, Color::white());
$clipList = new DisplayList();
$clipCanvas = new RecordingCanvas($clipList);
$clipCanvas->pushClip(new Rect(0.0, 0.0, 4.0, 4.0));
$clipCanvas->drawRect(new Rect(0.0, 0.0, 16.0, 16.0), Paint::fill($red));
$clipCanvas->popClip();
$clipList->replay($clipped);
check('Rendering · 裁剪区内被绘制', $clipped->pixelAt(1, 1)->equals($red));
check('Rendering · 裁剪区外被挡住', $clipped->pixelAt(6, 6)->equals(Color::white()));

// 圆角：角上那一个像素必须落在圆弧之外
$rounded = new RasterCanvas(32, 32, Color::white());
$roundList = new DisplayList();
(new RecordingCanvas($roundList))->drawRect(new Rect(0.0, 0.0, 16.0, 16.0), Paint::fill($red), 6.0);
$roundList->replay($rounded);
check('Rendering · 圆角外露背景', $rounded->pixelAt(0, 0)->equals(Color::white()));
check('Rendering · 圆角中心仍是实心', $rounded->pixelAt(8, 8)->equals($red));

// 描边：中间该是空的
$stroked = new RasterCanvas(32, 32, Color::white());
$strokeList = new DisplayList();
(new RecordingCanvas($strokeList))->drawRect(new Rect(2.0, 2.0, 16.0, 16.0), Paint::stroke($red, 2.0));
$strokeList->replay($stroked);
check('Rendering · 描边落在边上', $stroked->pixelAt(3, 3)->equals($red));
check('Rendering · 描边内部是空的', $stroked->pixelAt(10, 10)->equals(Color::white()));

// 缓冲复用：reset 之后槽位保留、可以重新录
$list->reset();
check('Rendering · reset 只归零长度', $list->count() === 0 && $list->saveDepth() === 0);
$list->record(DisplayList::OP_DRAW_RECT, new Rect(0.0, 0.0, 1.0, 1.0), Paint::fill($red), 0.0);
check('Rendering · reset 后可继续录制', $list->count() === 1);

// —— 真裁剪：`Modifier::clip` / `Overflow::Clip` 落到绘制（2026-10-23）
// ★ 装置是 `BleedBox`（盒 10×10、墨 40×40）：裁与不裁像素**不同**。
//   `SolidBox` 的墨与盒同尺寸 ⇒ 裁与不裁完全相同 = 恒真断言，验不出东西。
$bleedPlain = new BleedBox(new Size(40.0, 40.0));
$bleedPlain->layout(BoxConstraints::tight(new Size(10.0, 10.0)));
$bleedPlainRaster = new RasterCanvas(40, 40, Color::white(), Scale::of(1.0));
$bleedPlain->paint($bleedPlainRaster);
check(
    '裁剪 · 基准：未声明 clip 时墨画到盒外（装置真能溢出）',
    $bleedPlain->size()?->width === 10.0
        && !$bleedPlainRaster->pixelAt(20, 20)->equals(Color::white()),
    sprintf('盒宽 %s（应为 10），盒外 (20,20) %s', (string) $bleedPlain->size()?->width,
        $bleedPlainRaster->pixelAt(20, 20)->equals(Color::white()) ? '是白' : '有墨'),
);

$clipWidget = Text::make('x')->clip();
$clipOn = $clipWidget->mods()->hasClip();
$clipWidget->clip(false);
check(
    '裁剪 · Widget::clip() 写进 mods，clip(false) 可关',
    $clipOn && !$clipWidget->mods()->hasClip(),
);
check(
    '裁剪 · clip 计入 isDefault（漏了 ⇒ syncBox 的"修饰无处可去"守卫失效）',
    (new Modifier())->isDefault() && !(new Modifier())->withClip(true)->isDefault(),
);

$bleedClip = new BleedBox(new Size(40.0, 40.0));
$bleedClip->setMods((new Modifier())->withClip(true));
$bleedClip->layout(BoxConstraints::tight(new Size(10.0, 10.0)));
$bleedClipRaster = new RasterCanvas(40, 40, Color::white(), Scale::of(1.0));
$bleedClip->paint($bleedClipRaster);
check(
    '裁剪 · Widget::clip() 后盒外是背景',
    $bleedClipRaster->pixelAt(20, 20)->equals(Color::white()),
    sprintf('(20,20) rgb(%d,%d,%d)', $bleedClipRaster->pixelAt(20, 20)->r,
        $bleedClipRaster->pixelAt(20, 20)->g, $bleedClipRaster->pixelAt(20, 20)->b),
);
check(
    '裁剪 · 盒内仍有墨（不是整块被裁没了）',
    !$bleedClipRaster->pixelAt(5, 5)->equals(Color::white()),
);

// —— 容器声明驱动：`Overflow::Clip` 从"只是不报错"变成"真的裁"
$flexClip = new RenderFlex(false, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Clip);
$flexClip->addChild(new BleedBox(new Size(40.0, 40.0)));
$flexClip->layout(BoxConstraints::tight(new Size(10.0, 10.0)));
$flexClipRaster = new RasterCanvas(40, 40, Color::white(), Scale::of(1.0));
$flexClip->paint($flexClipRaster);
check(
    '裁剪 · Overflow::Clip 的 Flex 真的裁（注释承诺的"子项被裁掉"兑现了）',
    $flexClipRaster->pixelAt(20, 20)->equals(Color::white()),
);

// Report 不裁：子被夹到 10 ⇒ mainSize 不超、不报 Fatal，但墨仍溢出 ⇒ 默认行为不变
$flexReport = new RenderFlex(false, 0.0, new EdgeInsets(), FlexAlign::Start, Overflow::Report);
$flexReport->addChild(new BleedBox(new Size(40.0, 40.0)));
$flexReport->layout(BoxConstraints::tight(new Size(10.0, 10.0)));
$flexReportRaster = new RasterCanvas(40, 40, Color::white(), Scale::of(1.0));
$flexReport->paint($flexReportRaster);
check(
    '裁剪 · Overflow::Report 不裁（默认行为与加钩子之前一致）',
    !$flexReportRaster->pixelAt(20, 20)->equals(Color::white()),
);

// 装饰（阴影）在裁剪**之外**：同一帧里"墨被裁、影子还在"
$bleedShadow = new BleedBox(new Size(40.0, 40.0));
$bleedShadow->setMods((new Modifier())->withClip(true)->withShadow(0.6));
$bleedShadow->layout(BoxConstraints::tight(new Size(20.0, 20.0)));
// ★ 不能经 `DisplayList` 回放到 Raster：录制层的 `capabilities()` 是**全能力**
//   （契约："回放时裁决"），于是它会录下 `drawShadow`（Optional，仅 Skia）⇒
//   Raster 回放时 `unsupportedOp` 响亮失败。本用例要测的是"阴影画在裁剪之外"，
//   直接在 Raster 上画同样成立（且自动走三层降级）。
$shadowRaster = new RasterCanvas(60, 60, Color::white(), Scale::of(1.0));
$shadowRaster->save();
$shadowRaster->translate(10.0, 10.0);
$bleedShadow->paint($shadowRaster);
$shadowRaster->restore();
check(
    '裁剪 · 阴影不被裁（盒 (10,10)-(30,30)，(8,20) 处仍有影子）',
    !$shadowRaster->pixelAt(8, 20)->equals(Color::white()),
    sprintf('(8,20) rgb(%d,%d,%d)', $shadowRaster->pixelAt(8, 20)->r,
        $shadowRaster->pixelAt(8, 20)->g, $shadowRaster->pixelAt(8, 20)->b),
);
check(
    '裁剪 · 同一帧里墨在盒外被裁（(35,35) 在墨内、盒外 ⇒ 白）',
    $shadowRaster->pixelAt(35, 35)->equals(Color::white()),
);

check(
    '裁剪 · clipsOverflow 口径（Flex/Dock 看声明，普通盒默认不裁）',
    $flexClip->clipsOverflow() === true
        && $flexReport->clipsOverflow() === false
        && (new RenderDock(Overflow::Clip))->clipsOverflow() === true
        && (new RenderDock(Overflow::Report))->clipsOverflow() === false
        && $bleedPlain->clipsOverflow() === false,
);

// —— chrome 在裁剪**之外**（真机抓到的：第一版把 chrome 留在 `paintContent()` 里，
//    `users` 顶条底部那条 1px 灰线整行消失，diff 983 像素）
// ★ 判据用**命令顺序**而不是像素：描边是内描边（像素 9 有墨、10 没有），
//   靠像素分不出"被裁"与"本来就不画"。`paintOwnChrome()` 必须在 `pushClip` 之前。
$bar = new RenderAppBar(
    BoxStyle::none()->withBackground(Color::black())->withBorder(Color::black(), 2.0),
    0.0,
    new EdgeInsets(),
);
$bar->layout(BoxConstraints::tight(new Size(10.0, 10.0)));
$barList = new DisplayList();
$bar->paint(new RecordingCanvas($barList));
$barClipAt = -1;
$barChromeAt = [];
for ($bi = 0; $bi < $barList->count(); $bi++) {
    $bop = $barList->opAt($bi);
    if ($bop === DisplayList::OP_PUSH_CLIP) {
        $barClipAt = $bi;
    } elseif ($bop === DisplayList::OP_DRAW_RECT) {
        $barChromeAt[] = $bi;
    }
}
check(
    '裁剪 · chrome（背景/边框）画在 pushClip 之前 ⇒ 不被自己裁掉',
    $barClipAt > 0 && count($barChromeAt) === 2 && max($barChromeAt) < $barClipAt,
    sprintf('drawRect 在 [%s]，pushClip 在 %d', implode(',', $barChromeAt), $barClipAt),
);

// 值对象
check('Rendering · Color::fromHex 支持 #RGB', Color::fromHex('#f00')->equals($red));
check('Rendering · Color 往返 ARGB', Color::fromArgb(Color::fromRgba(1, 2, 3, 4)->toArgb())->equals(Color::fromRgba(1, 2, 3, 4)));
check('Rendering · BoxStyle::none 什么也不画', BoxStyle::none()->isNone());
check(
    'Rendering · BoxStyle 逐字段 with- 不改其它字段',
    BoxStyle::none()->withBackground($red)->withRadius(8.0)->equals(new BoxStyle($red, null, 0.0, 8.0))
);
check('Rendering · Paint 风格可判', Paint::stroke($red, 2.0)->isStroke() && !Paint::fill($red)->isStroke());

// ⑨+1 Skia 后端 conformance：drawRect / pushClip 与软光栅逐像素同构
//   （§7.3 的 M1 后端之二。hello 夹具只有文字 ⇒ 矩形 / 裁剪路径此前**零视觉覆盖**）
// ★ 覆盖：A 填充 dpr=1 · B dpr=2 小数边界+量化锚点 · C 平移在逻辑空间叠加 ·
//   D save/restore 偏移栈 · E1 pushClip 几何 · E2 popClip 释放裁剪 ·
//   E3 popClip 不动偏移（两后端同解）· E4 兄弟干扰（TextField 序列后兄弟保住
//   容器偏移）· F 描边宽度不乘 dpr · G 零宽早退 · H 句柄 0 静默跳过。
//
// ★ 在解释器面做，不构建 exe：真桥（cpp-src/drift_skia.cc）只存在于 AOT 构建，
//   而 `use function` 是**运行期**解析 ⇒ 助手区的假桥接管调用并记录每一次跨边界
//   参数 —— "Skia 收到了什么"成为可断言的纯数据。
// ★ 判据是**跨后端几何同构**，不是像素 golden：两后端的抗锯齿范围与描边实现
//   **有意不同**（SkiaCanvas 类注释明写两条差异），像素级互比没有意义。能互比的
//   是设备矩形 —— 软光栅 rasterize 逐**像素中心**测试，整边矩形覆盖
//   [left, right)×[top, bottom) ⇒ 墨迹 bbox（含端点）= (left, top, right−1, bottom−1)。
// ★ 量化规则本身由 ⑦ 钉住（round(logical×dpr)、相邻矩形共享像素边）；这里钉的是
//   "Skia 路径确实走 Scale::quantizeRect 这一个量化点"。
fresh($log);

// ── A. 填充 · dpr=1：桥收到的设备矩形 == 软光栅墨迹 bbox ──
skiaBridgeReset();
$listA = new DisplayList();
(new RecordingCanvas($listA))->drawRect(new Rect(3.0, 4.0, 10.0, 8.0), Paint::fill($red));
$rasterA = new RasterCanvas(24, 20, null, Scale::of(1.0));
$skiaA = new SkiaCanvas(24, 20, null, Scale::of(1.0));
$listA->replay($rasterA);
$listA->replay($skiaA);
$inkA = deviceInkBounds($rasterA);
$rectsA = skiaDrawRectCalls();
$expectA = count($rectsA) === 1 ? skiaRectToInk($rectsA[0]) : null;
check(
    'Skia conformance · A 填充：桥恰好收到一次 draw_rect（dpr=1）',
    count($rectsA) === 1,
    (string) count($rectsA)
);
check(
    'Skia conformance · A 填充：设备矩形 == 软光栅墨迹 bbox（dpr=1）',
    $inkA === $expectA,
    'ink=' . fmtInk($inkA) . ' 桥收到=' . fmtInk($expectA)
);

// ── B. 填充 · dpr=2 小数边界：量化在逻辑空间一次做完 ──
skiaBridgeReset();
$listB = new DisplayList();
(new RecordingCanvas($listB))->drawRect(new Rect(3.25, 4.75, 10.5, 8.25), Paint::fill($red));
$rasterB = new RasterCanvas(48, 40, null, Scale::of(2.0));
$skiaB = new SkiaCanvas(48, 40, null, Scale::of(2.0));
$listB->replay($rasterB);
$listB->replay($skiaB);
$inkB = deviceInkBounds($rasterB);
$rectsB = skiaDrawRectCalls();
$expectB = count($rectsB) === 1 ? skiaRectToInk($rectsB[0]) : null;
check(
    'Skia conformance · B 填充：dpr=2 小数边界的量化口径两后端一致',
    $inkB === $expectB,
    'ink=' . fmtInk($inkB) . ' 桥收到=' . fmtInk($expectB)
);
check(
    'Skia conformance · B 量化锚点：(3.25,4.75,10.5,8.25)×2 ⇒ 设备 (7,10,21,16)（half-up）',
    count($rectsB) === 1
        && $rectsB[0][1] === 7.0 && $rectsB[0][2] === 10.0
        && $rectsB[0][3] === 21.0 && $rectsB[0][4] === 16.0,
    count($rectsB) === 1
        ? '桥收到 x=' . $rectsB[0][1] . ' y=' . $rectsB[0][2] . ' w=' . $rectsB[0][3] . ' h=' . $rectsB[0][4]
        : 'draw_rect 次数=' . count($rectsB)
);

// ── C. 平移叠加在逻辑空间：先加偏移再取整（不是先取整后加） ──
// translate(0.3,0.3) + rect(0.3,0.3,10,10)、dpr=2 ⇒ left = round(1.2) = 1；
// 若"先量化后加偏移"会得 round(0.6)+round(0.6) = 2 —— 本用例专门分开这两条路。
skiaBridgeReset();
$listC = new DisplayList();
$recC = new RecordingCanvas($listC);
$recC->translate(0.3, 0.3);
$recC->drawRect(new Rect(0.3, 0.3, 10.0, 10.0), Paint::fill($red));
$rasterC = new RasterCanvas(48, 40, null, Scale::of(2.0));
$skiaC = new SkiaCanvas(48, 40, null, Scale::of(2.0));
$listC->replay($rasterC);
$listC->replay($skiaC);
$inkC = deviceInkBounds($rasterC);
$rectsC = skiaDrawRectCalls();
$expectC = count($rectsC) === 1 ? skiaRectToInk($rectsC[0]) : null;
check(
    'Skia conformance · C 平移：先加偏移再取整，两后端一致',
    $inkC === $expectC,
    'ink=' . fmtInk($inkC) . ' 桥收到=' . fmtInk($expectC)
);
check(
    'Skia conformance · C 锚点：left = round((0.3+0.3)×2) = 1（先量化后加会得 2）',
    count($rectsC) === 1 && $rectsC[0][1] === 1.0 && $rectsC[0][2] === 1.0,
    count($rectsC) === 1 ? '桥收到 x=' . $rectsC[0][1] . ' y=' . $rectsC[0][2] : 'draw_rect 次数=' . count($rectsC)
);

// ── D. save/restore 偏移栈：restore 必须把偏移归位（不是残留） ──
// P1 画在 translate 作用域内 ⇒ 落 (20,4)；P2 画在 restore 之后 ⇒ 必须回到 (4,4)。
// restore 失效（偏移残留）会让 P2 也落 (20,4) ⇒ 与 P1 重叠 ⇒ 软光栅墨迹从 128 塌成 64
//   —— 这个 inkCount 判据两个方向**可区分**（第一版设计成"三块墨"不可区分：P2 无论
//   落回 P0 还是残留到 P1，计数都是 128，纯坏判据，smoke 当场把它揪出来了）。
skiaBridgeReset();
$listD = new DisplayList();
$recD = new RecordingCanvas($listD);
$recD->save();
$recD->translate(16.0, 0.0);
$recD->drawRect(new Rect(4.0, 4.0, 8.0, 8.0), Paint::fill($red));       // P1 = (20,4)
$recD->restore();
$recD->drawRect(new Rect(4.0, 4.0, 8.0, 8.0), Paint::fill($red));       // P2 = (4,4)（restore 归零）
$rasterD = new RasterCanvas(40, 24, null, Scale::of(1.0));
$skiaD = new SkiaCanvas(40, 24, null, Scale::of(1.0));
$listD->replay($rasterD);
$listD->replay($skiaD);
$rectsD = skiaDrawRectCalls();
check(
    'Skia conformance · D save/restore：桥收到两次 draw_rect',
    count($rectsD) === 2,
    (string) count($rectsD)
);
check(
    'Skia conformance · D 平移生效：第一块 == (20,4)',
    count($rectsD) === 2 && $rectsD[0][1] === 20.0 && $rectsD[0][2] === 4.0,
    count($rectsD) === 2 ? '桥收到 x=' . $rectsD[0][1] . ' y=' . $rectsD[0][2] : '次数=' . count($rectsD)
);
check(
    'Skia conformance · D restore 归零：第二块 == (4,4)（偏移残留会变成 (20,4)）',
    count($rectsD) === 2 && $rectsD[1][1] === 4.0 && $rectsD[1][2] === 4.0,
    count($rectsD) === 2 ? '桥收到 x=' . $rectsD[1][1] . ' y=' . $rectsD[1][2] : '次数=' . count($rectsD)
);
$inkD = inkCount($rasterD, Color::transparent());
check(
    'Skia conformance · D 软光栅两块墨互不重叠（inkCount=128；restore 失效会塌成 64）',
    $inkD === 128,
    'inkCount=' . $inkD
);
check(
    'Skia conformance · D 软光栅定点：两块位置都有墨',
    $rasterD->pixelAt(5, 5)->equals($red) && $rasterD->pixelAt(21, 5)->equals($red),
    'p(5,5)=' . ($rasterD->pixelAt(5, 5)->equals($red) ? 'red' : 'clear')
        . ' p(21,5)=' . ($rasterD->pixelAt(21, 5)->equals($red) ? 'red' : 'clear')
);

// ── E1. pushClip 几何：桥收到的裁剪矩形 == 被裁后的墨迹 bbox ──
skiaBridgeReset();
$listE1 = new DisplayList();
$recE1 = new RecordingCanvas($listE1);
$recE1->save();
$recE1->pushClip(new Rect(2.0, 2.0, 6.0, 6.0));
$recE1->drawRect(new Rect(0.0, 0.0, 20.0, 20.0), Paint::fill($red));
$recE1->popClip();
$recE1->restore();
$rasterE1 = new RasterCanvas(24, 20, null, Scale::of(1.0));
$skiaE1 = new SkiaCanvas(24, 20, null, Scale::of(1.0));
$listE1->replay($rasterE1);
$listE1->replay($skiaE1);
$inkE1 = deviceInkBounds($rasterE1);
$clipsE1 = skiaClipCalls();
$expectE1 = count($clipsE1) === 1 ? skiaRectToInk($clipsE1[0]) : null;
check(
    'Skia conformance · E1 pushClip：裁剪矩形 == 被裁后的墨迹 bbox',
    $inkE1 === $expectE1,
    'ink=' . fmtInk($inkE1) . ' 裁剪=' . fmtInk($expectE1)
);
check(
    'Skia conformance · E1 配对：push_clip / draw_rect / pop_clip 各一次（外加 create）',
    skiaCallOps() === ['create', 'push_clip', 'draw_rect', 'pop_clip'],
    implode('→', skiaCallOps())
);

// ── E2. popClip 真的释放裁剪：释放后画的矩形能落在裁剪区外 ──
skiaBridgeReset();
$listE2 = new DisplayList();
$recE2 = new RecordingCanvas($listE2);
$recE2->save();
$recE2->pushClip(new Rect(2.0, 2.0, 6.0, 6.0));
$recE2->popClip();
$recE2->drawRect(new Rect(16.0, 16.0, 2.0, 2.0), Paint::fill($red));
$recE2->restore();
$rasterE2 = new RasterCanvas(24, 20, null, Scale::of(1.0));
$skiaE2 = new SkiaCanvas(24, 20, null, Scale::of(1.0));
$listE2->replay($rasterE2);
$listE2->replay($skiaE2);
$inkE2 = deviceInkBounds($rasterE2);
$rectsE2 = skiaDrawRectCalls();
$expectE2 = count($rectsE2) === 1 ? skiaRectToInk($rectsE2[0]) : null;
check(
    'Skia conformance · E2 popClip 释放裁剪：矩形落在裁剪区外且两后端一致',
    $inkE2 === $expectE2,
    'ink=' . fmtInk($inkE2) . ' 桥收到=' . fmtInk($expectE2)
);

// ── F. 描边：宽度不乘 dpr；外圈墨迹 bbox == 设备矩形 ──
skiaBridgeReset();
$listF = new DisplayList();
(new RecordingCanvas($listF))->drawRect(new Rect(1.0, 1.0, 10.0, 10.0), Paint::stroke($red, 2.0));
$rasterF = new RasterCanvas(48, 40, null, Scale::of(2.0));
$skiaF = new SkiaCanvas(48, 40, null, Scale::of(2.0));
$listF->replay($rasterF);
$listF->replay($skiaF);
$inkF = deviceInkBounds($rasterF);
$rectsF = skiaDrawRectCalls();
$expectF = count($rectsF) === 1 ? skiaRectToInk($rectsF[0]) : null;
check(
    'Skia conformance · F 描边：外圈墨迹 bbox == 设备矩形（dpr=2）',
    $inkF === $expectF,
    'ink=' . fmtInk($inkF) . ' 桥收到=' . fmtInk($expectF)
);
check(
    'Skia conformance · F 描边宽度不乘 dpr（dpr=2 收到 2.0，不是 4.0）且标记为描边',
    count($rectsF) === 1 && $rectsF[0][8] === 2.0 && $rectsF[0][7] === true,
    count($rectsF) === 1 ? 'strokeWidth=' . $rectsF[0][8] . ' isStroke=' . var_export($rectsF[0][7], true) : '次数=' . count($rectsF)
);
check(
    'Skia conformance · F 描边结构：边上有墨、中间是空的',
    $rasterF->pixelAt(3, 3)->equals($red) && $rasterF->pixelAt(11, 11)->equals(Color::transparent()),
    'p(3,3)=' . ($rasterF->pixelAt(3, 3)->equals($red) ? 'red' : 'clear')
        . ' p(11,11)=' . ($rasterF->pixelAt(11, 11)->equals(Color::transparent()) ? 'clear' : 'ink')
);

// ── G. 零宽矩形：两后端都当空操作（Skia 早退、软光栅循环不进） ──
skiaBridgeReset();
$listG = new DisplayList();
(new RecordingCanvas($listG))->drawRect(new Rect(0.0, 0.0, 0.0, 10.0), Paint::fill($red));
$rasterG = new RasterCanvas(16, 16, null, Scale::of(1.0));
$skiaG = new SkiaCanvas(16, 16, null, Scale::of(1.0));
$listG->replay($rasterG);
$listG->replay($skiaG);
check(
    'Skia conformance · G 零宽矩形：无墨迹、无跨边界调用（两后端一致空操作）',
    deviceInkBounds($rasterG) === null && skiaDrawRectCalls() === [],
    'ink=' . fmtInk(deviceInkBounds($rasterG)) . ' 桥调用次数=' . count(skiaDrawRectCalls())
);

// ── H. 创建失败（句柄 0）：所有绘制静默跳过（装饰性失败，不崩不报） ──
$GLOBALS['DRIFT_SMOKE_SKIA_FAIL_CREATE'] = true;
skiaBridgeReset();
$dead = new SkiaCanvas(20, 16, null, Scale::of(1.0));
$listH = new DisplayList();
$recH = new RecordingCanvas($listH);
$recH->save();
$recH->translate(3.0, 4.0);
$recH->pushClip(new Rect(0.0, 0.0, 5.0, 5.0));
$recH->drawRect(new Rect(0.0, 0.0, 5.0, 5.0), Paint::fill($red));
$recH->popClip();
$recH->restore();
$listH->replay($dead);
check(
    'Skia conformance · H 句柄 0：isValid=false 且整条命令流零跨边界调用（只有 create）',
    !$dead->isValid() && skiaCallOps() === ['create'],
    'isValid=' . ($dead->isValid() ? 'true' : 'false') . ' 调用序列=' . implode('→', skiaCallOps())
);
$dead->dispose();
check(
    'Skia conformance · H 句柄 0 的 dispose 不产生跨边界调用',
    skiaCallOps() === ['create'],
    '调用序列=' . implode('→', skiaCallOps())
);
$GLOBALS['DRIFT_SMOKE_SKIA_FAIL_CREATE'] = false;
$alive = new SkiaCanvas(20, 16, null, Scale::of(1.0));
check('Skia conformance · H 失败开关复位后创建恢复有效', $alive->isValid());

// ── E3. popClip 只复位裁剪、不动偏移：两后端必须同解（2026-10-17 翻正）──────
// 历史：本断言 2026-10-16 上线时钉的是**两侧现状**（Raster 的 popClip 弹通用状态
// 帧 = 半个 restore，偏移也归位；Skia 只复位裁剪）。那不是"已知差异"而是**活
// 缺陷**：RenderTextField::paint 的生产序列 pushClip; save; translate(insets); 画;
// restore; popClip 只有双栈语义下自洽 —— Raster 上 TextField 的 popClip 吃掉父盒
// kid 循环的帧 ⇒ 后续兄弟丢容器偏移（同形状的完整用例见 E4）。
// 2026-10-17 已裁决并修复：RasterCanvas 加独立裁剪栈（结构与 SkiaCanvas 一致：
// 变换栈只存偏移，裁剪独立成栈）。本断言翻正为**正确语义**。
// ★ 判据可区分：弹通用帧（修复前）⇒ 第二块落 (0,0)，ink=8 且 (1,1) 有墨；
//   只复位裁剪（修复后）⇒ 第二块落 (6,0)，ink=4 且 (1,1) 无墨。
skiaBridgeReset();
$listX = new DisplayList();
$recX = new RecordingCanvas($listX);
$recX->save();
$recX->translate(6.0, 0.0);
$recX->pushClip(new Rect(0.0, 0.0, 8.0, 8.0));
$recX->drawRect(new Rect(0.0, 0.0, 2.0, 2.0), Paint::fill($red));
$recX->popClip();
$recX->drawRect(new Rect(0.0, 0.0, 2.0, 2.0), Paint::fill($red));
$recX->restore();
$rasterX = new RasterCanvas(24, 16, null, Scale::of(1.0));
$skiaX = new SkiaCanvas(24, 16, null, Scale::of(1.0));
$listX->replay($rasterX);
$listX->replay($skiaX);
$rectsX = skiaDrawRectCalls();
$inkX = inkCount($rasterX, Color::transparent());
check(
    'Skia conformance · E3 翻正：Raster 的 popClip 只复位裁剪（第二块仍带偏移 6，两块重叠 ink=4）',
    $rasterX->pixelAt(7, 1)->equals($red) && $rasterX->pixelAt(1, 1)->equals(Color::transparent()) && $inkX === 4,
    'p(7,1)=' . ($rasterX->pixelAt(7, 1)->equals($red) ? 'red' : 'clear')
        . ' p(1,1)=' . ($rasterX->pixelAt(1, 1)->equals(Color::transparent()) ? 'clear' : 'ink')
        . ' inkCount=' . $inkX
);
check(
    'Skia conformance · E3 两后端同解：Skia 侧第二块仍带偏移 6（popClip 不动偏移）',
    count($rectsX) === 2 && $rectsX[1][1] === 6.0 && $rectsX[1][2] === 0.0,
    count($rectsX) === 2 ? '第二块 x=' . $rectsX[1][1] . ' y=' . $rectsX[1][2] : '次数=' . count($rectsX)
);

// ── E4. 兄弟干扰：TextField 序列之后，兄弟必须保住容器偏移 ─────────────
// ★ 这是**唯一能抓 popClip 帧腐败的用例族**：单盒用例（E1–E3）全绿也看不见 ——
//   腐败的症状是"兄弟丢容器偏移"，只有多子形状才可观测。历史上窗口探针（非白
//   像素比例）、预览（RasterCanvas 像素转 SVG，自证）、W8（录制侧配平）对这一
//   类全是结构性盲区 ⇒ 本节按"多子"构造。
// 逐字镜像两个真实 paint：
//   · RenderFlex::paint      —— save/translate(padding)/[per kid: save/translate(kidOffset)/画/restore]/restore
//   · RenderTextField::paint —— pushClip/save/translate(insets)/画/restore/popClip
// 形状 = SignupPage 的 Column：padding 24；kid1 = TextField 形状；kid2 = 普通盒。
// 判据：kid2 的 10×10 蓝块必须落在布局位置 (24, 24+40)=(24,64)。
//   · 腐败（popClip 弹通用帧）：kid1 的 popClip 吃掉 kid 循环的帧 ⇒ kid2 落 (0,40)；
//   · 正确（两条独立栈）：两后端都把 kid2 画在 (24,64)。
skiaBridgeReset();
$blue = Color::fromRgb(0, 0, 255);
$listE4 = new DisplayList();
$recE4 = new RecordingCanvas($listE4);
$recE4->save();                                     // RenderFlex::paint
$recE4->translate(24.0, 24.0);                      // column padding
$recE4->save();                                     // kid 循环 · kid1
$recE4->translate(0.0, 0.0);                        // kid1 offset
$recE4->pushClip(new Rect(0.0, 0.0, 200.0, 28.0));  // RenderTextField::paint
$recE4->save();
$recE4->translate(4.0, 4.0);                        // TextField insets
$recE4->drawRect(new Rect(0.0, 0.0, 10.0, 10.0), Paint::fill($red));
$recE4->restore();
$recE4->popClip();
$recE4->restore();                                  // kid 循环 restore
$recE4->save();                                     // kid 循环 · kid2（offsetY=28+12=40）
$recE4->translate(0.0, 40.0);
$recE4->drawRect(new Rect(0.0, 0.0, 10.0, 10.0), Paint::fill($blue));
$recE4->restore();
$recE4->restore();                                  // flex restore
$rasterE4 = new RasterCanvas(320, 240, null, Scale::of(1.0));
$skiaE4 = new SkiaCanvas(320, 240, null, Scale::of(1.0));
$listE4->replay($rasterE4);
$listE4->replay($skiaE4);
$rectsE4 = skiaDrawRectCalls();
check(
    'Skia conformance · E4 兄弟干扰：TextField 自身落 (28,28)（kid1 不受帧腐败影响）',
    $rasterE4->pixelAt(29, 29)->equals($red),
    'p(29,29)=' . ($rasterE4->pixelAt(29, 29)->equals($red) ? 'red' : 'clear')
);
check(
    'Skia conformance · E4 兄弟保住容器偏移：蓝块落 (24,64) 而非腐败位 (0,40)',
    $rasterE4->pixelAt(25, 65)->equals($blue) && $rasterE4->pixelAt(1, 41)->equals(Color::transparent()),
    'p(25,65)=' . ($rasterE4->pixelAt(25, 65)->equals($blue) ? 'blue' : 'clear')
        . ' p(1,41)=' . ($rasterE4->pixelAt(1, 41)->equals(Color::transparent()) ? 'clear' : 'ink')
);
check(
    'Skia conformance · E4 跨后端同解：桥收到的两块设备矩形 == (28,28) 与 (24,64)',
    count($rectsE4) === 2
        && $rectsE4[0][1] === 28.0 && $rectsE4[0][2] === 28.0
        && $rectsE4[1][1] === 24.0 && $rectsE4[1][2] === 64.0,
    count($rectsE4) === 2
        ? '第一块 x=' . $rectsE4[0][1] . ' y=' . $rectsE4[0][2]
            . ' 第二块 x=' . $rectsE4[1][1] . ' y=' . $rectsE4[1][2]
        : '次数=' . count($rectsE4)
);

// ── 量具反例自检（§20 纪律 1）──────────────────────────────────────
// 合成一个已知违规件：让假桥把 draw_rect 的 x 报错一格（等价于"SkiaCanvas 算错
// 了偏移"），同一套换算判据必须变**假**。抓不到 = 判据恒真、本段整段空转。
// 紧接的正对照：同一输入不篡改时判据必须为**真**。
skiaBridgeReset();
$GLOBALS['DRIFT_SMOKE_SKIA_SABOTAGE'] = true;
$listS = new DisplayList();
(new RecordingCanvas($listS))->drawRect(new Rect(3.0, 4.0, 10.0, 8.0), Paint::fill($red));
$rasterS = new RasterCanvas(24, 20, null, Scale::of(1.0));
$skiaS = new SkiaCanvas(24, 20, null, Scale::of(1.0));
$listS->replay($rasterS);
$listS->replay($skiaS);
$inkS = deviceInkBounds($rasterS);
$rectsSBad = skiaDrawRectCalls();
$expectSBad = count($rectsSBad) === 1 ? skiaRectToInk($rectsSBad[0]) : null;
check(
    'Skia conformance · 反例自检：桥把 x 篡改一格 ⇒ 判据变假（否则判据恒真、夹具空转）',
    $inkS !== $expectSBad,
    'ink=' . fmtInk($inkS) . ' 篡改后桥收到=' . fmtInk($expectSBad)
);
$GLOBALS['DRIFT_SMOKE_SKIA_SABOTAGE'] = false;
skiaBridgeReset();
$skiaS2 = new SkiaCanvas(24, 20, null, Scale::of(1.0));
$listS->replay($skiaS2);
$rectsSGood = skiaDrawRectCalls();
$expectSGood = count($rectsSGood) === 1 ? skiaRectToInk($rectsSGood[0]) : null;
check(
    'Skia conformance · 反例自检正对照：同一输入不篡改 ⇒ 判据为真',
    $inkS === $expectSGood,
    'ink=' . fmtInk($inkS) . ' 桥收到=' . fmtInk($expectSGood)
);

// ── P. 段落路径 conformance：drawParagraph 与软光栅逐字形同构 ──────────
// ★ drawParagraph 是 Canvas 唯一还没有跨后端覆盖的绘制方法（⑫ 只有 Raster 侧）。
//   判据 = 跨后端**几何**同构：桥收到的每个字形（penX / baselineY / codepoint /
//   family / rgba）与软光栅的墨迹位置逐一对得上 —— 与 drawRect 的"设备矩形 ==
//   墨迹 bbox"同款思路，只是比较单位从矩形换成字形。
// ★ 位图字体的 bearingX ≡ 0（"位图即 em 盒"，P1 钉住）⇒ 字形原点 = 墨迹左沿
//   ⇒ 桥收到的 penX 与软光栅墨迹左沿**可直接互比**。bearingY = −ascent×pixelSize
//   是后端特有量（位图字体 vs 真字体），不进互比，只各自钉（与两后端抗锯齿
//   差异同档）。
// ★ baseline 由排版给（size=14 实测 13.65）。修复前 Skia 发**不取整**的浮点、
//   软光栅 round 后落位图（同类分歧、两后端各自钉）；2026-10-17 修字形 offset 时
//   **一并对齐**：Skia 也改"逻辑空间叠加后一次 round"（与 blendBitmap 逐字一致）
//   ⇒ 两后端 baseline 现在**同解、可互比**（反例自检证明：换回旧版 SkiaCanvas
//   ⇒ P1/P3 的两条 baseline 断言也 FAIL，共 5 条）。
$pFontManager = DeterministicFontManager::standard();
$pMetrics = new DeterministicMetricsProvider($pFontManager->collection(), new DeterministicShaper());
$pBody = TextStyle::of(14.0);
$pUnit = Scale::of(1.0);

// ── P1. 单字形跨后端同构（dpr=1，画布平移 (7,5)）──
// 平移必须被文本吃掉（RasterCanvas 类注释：不跟 ⇒ Center 算好的偏移画不出来，
// "矩形跟着变换、文字不跟"是最难发现的半边错）。
skiaBridgeReset();
$p1Paragraph = $pMetrics->layout('A', $pBody, INF, $pUnit);
$p1List = new DisplayList();
$p1Rec = new RecordingCanvas($p1List);
$p1Rec->save();
$p1Rec->translate(7.0, 5.0);
$p1Rec->drawParagraph($p1Paragraph, Paint::fill($red), 0.0, 0.0);
$p1Rec->restore();
$rasterP1 = new RasterCanvas(40, 30, null, $pUnit);
$skiaP1 = new SkiaCanvas(40, 30, null, $pUnit);
$p1List->replay($rasterP1);
$p1List->replay($skiaP1);
$glyphsP1 = skiaGlyphCalls();
$inkP1 = deviceInkBounds($rasterP1);
$expectP1 = skiaExpectedGlyphs($p1Paragraph, 7.0, 5.0, $pUnit);
$p1Face = $p1Paragraph->lines[0]->runs[0]->face;
check(
    'Skia conformance · P1 桥恰好一次 draw_glyph（codepoint=U+41 / family=sans / rgba=红）',
    count($glyphsP1) === 1
        && $glyphsP1[0][6] === 0x41
        && $glyphsP1[0][1] === $p1Face->familyName()
        && $glyphsP1[0][7] === $red->toArgb(),
    count($glyphsP1) === 1
        ? 'cp=U+' . dechex($glyphsP1[0][6]) . ' family=' . $glyphsP1[0][1] . ' rgba=' . $glyphsP1[0][7]
        : '次数=' . count($glyphsP1)
);
check(
    'Skia conformance · P1 平移被文本吃掉：penX=(7+0)、baselineY=(5+13.65)、fontSize=14',
    count($glyphsP1) === 1 && count($expectP1) === 1
        && $glyphsP1[0][4] === $expectP1[0]['penX']
        && $glyphsP1[0][5] === $expectP1[0]['baselineY']
        && $glyphsP1[0][3] === 14.0,
    count($glyphsP1) === 1
        ? '桥 penX=' . $glyphsP1[0][4] . ' baselineY=' . $glyphsP1[0][5] . ' fontSize=' . $glyphsP1[0][3]
        : '次数=' . count($glyphsP1)
);
$p1Bitmap = $p1Face->rasterize(0x41, 14.0);
check(
    'Skia conformance · P1 前置条件：位图字体 bearingX ≡ 0（字形原点 = 墨迹左沿，跨后端才可直接互比）',
    $p1Bitmap !== null && $p1Bitmap->bearingX === 0,
    $p1Bitmap === null ? '位图为 null' : 'bearingX=' . $p1Bitmap->bearingX
);
check(
    'Skia conformance · P1 跨后端同构：桥收到的 penX == 软光栅墨迹左沿',
    count($glyphsP1) === 1 && $inkP1 !== null && (float) $inkP1[0] === $glyphsP1[0][4],
    '墨迹左沿=' . fmtInk($inkP1) . ' 桥 penX=' . (count($glyphsP1) === 1 ? (string) $glyphsP1[0][4] : '无')
);
check(
    'Skia conformance · P1 软光栅墨迹 bbox == 独立算式（含 bearingY 的纵向定位）',
    $inkP1 === skiaExpectedInkBounds($p1Paragraph, 7.0, 5.0, $pUnit),
    'ink=' . fmtInk($inkP1) . ' 期望=' . fmtInk(skiaExpectedInkBounds($p1Paragraph, 7.0, 5.0, $pUnit))
);

// ── P2. 多字形步进与并集墨迹（dpr=1）──
// 步进 = 逐字 advance 累加（不是等距）——桥的 penX 序列与墨迹并集双向钉住。
skiaBridgeReset();
$p2Paragraph = $pMetrics->layout('Hello', $pBody, INF, $pUnit);
$p2List = new DisplayList();
(new RecordingCanvas($p2List))->drawParagraph($p2Paragraph, Paint::fill($red), 0.0, 0.0);
$rasterP2 = new RasterCanvas(80, 24, null, $pUnit);
$skiaP2 = new SkiaCanvas(80, 24, null, $pUnit);
$p2List->replay($rasterP2);
$p2List->replay($skiaP2);
$glyphsP2 = skiaGlyphCalls();
$inkP2 = deviceInkBounds($rasterP2);
$expectP2 = skiaExpectedGlyphs($p2Paragraph, 0.0, 0.0, $pUnit);
$p2Pens = [];
$p2ExpectPens = [];
foreach ($glyphsP2 as $g) {
    $p2Pens[] = $g[4];
}
foreach ($expectP2 as $e) {
    $p2ExpectPens[] = $e['penX'];
}
check(
    'Skia conformance · P2 五个字形全部过桥，penX 按 advance 逐步进（不是等距）',
    count($glyphsP2) === 5 && $p2Pens === $p2ExpectPens,
    '桥 penX=[' . implode(',', $p2Pens) . '] 期望=[' . implode(',', $p2ExpectPens) . ']'
);
// ★ 判据从 "==" 放宽成 ">="（§24.2 #21）：拉丁字模改成 32 网格、位图宽取自真字体
//   advance 之后，`H` 的字形在**位图内**左边留了 1 列空白（真字体的 side bearing）。
//   `bearingX` 仍是 0（位图左沿 == pen），所以墨迹左沿 = penX + 空列 ⇒ 只会 ≥ penX。
//   "精确相等"由下一条（并集 bbox == 独立算式）保证 —— 那条会同时钉住空列宽度。
check(
    'Skia conformance · P2 跨后端同构：墨迹左沿 ≥ 首字形 penX（side bearing 在位图内）',
    $inkP2 !== null && count($glyphsP2) === 5 && $inkP2[0] >= (int) $glyphsP2[0][4],
    'ink=' . fmtInk($inkP2) . ' 首字形 penX=' . (count($glyphsP2) === 5 ? (string) $glyphsP2[0][4] : '无')
);
// ★ 期望值只算**一次**（落变量）：放进 $cond 和诊断串各算一次时，两次求值
//   在灰度降采样字模下会不一致（诊断串打印两边相同却 FAIL，最难查）
$p2ExpectInk = skiaExpectedInkBounds($p2Paragraph, 0.0, 0.0, $pUnit);
check(
    'Skia conformance · P2 软光栅并集墨迹 bbox == 独立算式（末字形右沿由步进推出）',
    $inkP2 === $p2ExpectInk,
    'ink=' . fmtInk($inkP2) . ' 期望=' . fmtInk($p2ExpectInk)
);

// ── P3. 多行基线 + dpr=2（同一份命令流，两个缩放）──
// 'Hello Drift' 在 40 逻辑宽断两行（⑪ 钉过断行形状：行0='Hello'、行1='Drift'，
// 各 5 字形，空格不成字形）；dpr=2 用**同一份** DisplayList 回放 —— 段落是
// 缩放无关的值对象，dpr 只由画布施加（⑫ 同款口径）。
skiaBridgeReset();
$p3Paragraph = $pMetrics->layout('Hello Drift', $pBody, 40.0, $pUnit);
$p3List = new DisplayList();
(new RecordingCanvas($p3List))->drawParagraph($p3Paragraph, Paint::fill($red), 0.0, 0.0);
$rasterP3 = new RasterCanvas(90, 80, null, $pUnit);
$skiaP3 = new SkiaCanvas(90, 80, null, $pUnit);
$p3List->replay($rasterP3);
$p3List->replay($skiaP3);
$glyphsP3 = skiaGlyphCalls();
$inkP3 = deviceInkBounds($rasterP3);
$expectP3 = skiaExpectedGlyphs($p3Paragraph, 0.0, 0.0, $pUnit);
check(
    'Skia conformance · P3 两行各自过桥：前 5 字形 baseline = 行0(13.65)、后 5 = 行1(30.45)',
    count($glyphsP3) === 10
        && $glyphsP3[0][5] === $expectP3[0]['baselineY']
        && $glyphsP3[4][5] === $expectP3[4]['baselineY']
        && $glyphsP3[5][5] === $expectP3[5]['baselineY']
        && $glyphsP3[9][5] === $expectP3[9]['baselineY']
        && $glyphsP3[5][5] > $glyphsP3[0][5],
    count($glyphsP3) === 10
        ? '行0 baseline=' . $glyphsP3[0][5] . ' 行1 baseline=' . $glyphsP3[5][5]
        : '次数=' . count($glyphsP3)
);
check(
    'Skia conformance · P3 软光栅并集墨迹 bbox == 独立算式（两行都在内）',
    $inkP3 === skiaExpectedInkBounds($p3Paragraph, 0.0, 0.0, $pUnit),
    'ink=' . fmtInk($inkP3) . ' 期望=' . fmtInk(skiaExpectedInkBounds($p3Paragraph, 0.0, 0.0, $pUnit))
);
// dpr=2：同一份命令流回放到 2× 画布 —— 位置 ×2、字号 ×2、墨迹随之（⑫ 同款口径）
skiaBridgeReset();
$rasterP3x2 = new RasterCanvas(180, 160, null, Scale::of(2.0));
$skiaP3x2 = new SkiaCanvas(180, 160, null, Scale::of(2.0));
$p3List->replay($rasterP3x2);
$p3List->replay($skiaP3x2);
$glyphsP3x2 = skiaGlyphCalls();
$inkP3x2 = deviceInkBounds($rasterP3x2);
$expectP3x2 = skiaExpectedGlyphs($p3Paragraph, 0.0, 0.0, Scale::of(2.0));
check(
    'Skia conformance · P3 dpr=2：fontSize=28、末位 penX 与行 baseline 全部 ×2',
    count($glyphsP3x2) === 10
        && $glyphsP3x2[0][3] === 28.0
        && $glyphsP3x2[4][4] === $expectP3x2[4]['penX']
        && $glyphsP3x2[0][5] === $expectP3x2[0]['baselineY']
        && $glyphsP3x2[5][5] === $expectP3x2[5]['baselineY'],
    count($glyphsP3x2) === 10
        ? 'fontSize=' . $glyphsP3x2[0][3] . ' 行0末 penX=' . $glyphsP3x2[4][4]
            . ' 行0 baseline=' . $glyphsP3x2[0][5] . ' 行1 baseline=' . $glyphsP3x2[5][5]
        : '次数=' . count($glyphsP3x2)
);
// ★ 同 P2：side bearing 在位图内 ⇒ 墨迹左沿 ≥ penX；精确值由 bbox 那条钉住。
//   期望值只算一次（放进 $cond 与诊断串各算一次会重复 rasterize 整段文本）。
$p3ExpectInk = skiaExpectedInkBounds($p3Paragraph, 0.0, 0.0, Scale::of(2.0));
check(
    'Skia conformance · P3 dpr=2 跨后端同构：墨迹左沿 ≥ 桥 penX（含 side bearing），且并集 bbox == 独立算式',
    $inkP3x2 !== null && count($glyphsP3x2) === 10
        && $inkP3x2[0] >= (int) $glyphsP3x2[0][4]
        && $inkP3x2 === $p3ExpectInk,
    'ink=' . fmtInk($inkP3x2) . ' 期望=' . fmtInk($p3ExpectInk)
);

// ── P4. 字形 offset 施加（2026-10-17 修复后：两后端同解）────────────────

// ★ 合成一个带 offset 的段落（offsetX=3, offsetY=2）。今天的生产者造不出非零
//   offset —— `DeterministicShaper` 与 `TextLayout` 的 new Glyph(...) 全部显式
//   传 0.0（grep 实证）⇒ 曾是与 popClip 发现前同型的**潜伏**分歧。
// ★ 历史（2026-10-17 修复）：SkiaCanvas 第一版只发 `round(pen × dpr)`，offset
//   被丢；RasterCanvas 施加 `pen + offsetX`（blendBitmap 入参，round 在函数内）。
//   SkiaCanvas 类注释"与 RasterCanvas::drawParagraph 逐行对齐"本就是承诺 ⇒ 修 Skia。
// ★ 修法：penDevice = round((pen+offsetX)×dpr)、baseline 改逻辑值后 glyph 级
//   加 offsetY 再 round —— 与 blendBitmap 的"逻辑空间叠加后一次量化"逐字一致。
//   本断言原钉"Skia 丢弃 offset"现状（桥 penX=0、baselineY=13.65、分歧差 3px），
//   修复后翻正：桥 penX=3、baselineY=16、两后端墨迹左沿差 0px。
// ★ 必须 reset（P3 dpr=2 的 10 次调用还在记录里）—— 踩过：漏 reset ⇒
//   桥次数 11（10+1）、P4 三条断言全 FAIL。夹具用例互相污染的又一例。
skiaBridgeReset();
$p4Face = $pFontManager->faces()[0];
$p4Advance = $p4Face->glyphAdvance(0x41) * $pBody->size;
$p4Glyph = new Glyph(0x41, $p4Advance, 3.0, 2.0, 0);
$p4Run = new GlyphRun([$p4Glyph], $p4Face, $pBody, $p4Advance, 0, 1);
$p4Line = new TextLine([$p4Run], 0.0, 13.65, 16.8, $p4Advance, 0.0, 0, 1);
$p4Paragraph = new Paragraph('A', [$p4Line], $p4Advance, 16.8, $pBody, INF);
$p4List = new DisplayList();
(new RecordingCanvas($p4List))->drawParagraph($p4Paragraph, Paint::fill($red), 0.0, 0.0);
$rasterP4 = new RasterCanvas(40, 30, null, $pUnit);
$skiaP4 = new SkiaCanvas(40, 30, null, $pUnit);
$p4List->replay($rasterP4);
$p4List->replay($skiaP4);
$glyphsP4 = skiaGlyphCalls();
$inkP4 = deviceInkBounds($rasterP4);
check(
    'Skia conformance · P4 Raster 施加 offset（正确语义）：墨迹左沿 = round((0+3)×1) = 3',
    $inkP4 !== null
        && $inkP4[0] === 3
        && $inkP4 === skiaExpectedInkBounds($p4Paragraph, 0.0, 0.0, $pUnit),
    'ink=' . fmtInk($inkP4) . ' 期望=' . fmtInk(skiaExpectedInkBounds($p4Paragraph, 0.0, 0.0, $pUnit))
);
check(
    'Skia conformance · P4 Skia 施加 offset（修复后）：桥 penX=3、baselineY=16（先加后 round）',
    count($glyphsP4) === 1 && $glyphsP4[0][4] === 3.0 && $glyphsP4[0][5] === 16.0,
    count($glyphsP4) === 1
        ? '桥 penX=' . $glyphsP4[0][4] . ' baselineY=' . $glyphsP4[0][5]
        : '次数=' . count($glyphsP4)
);
check(
    'Skia conformance · P4 分歧已修：两后端墨迹左沿差 0px（桥 penX 与墨迹左沿同解）',
    $inkP4 !== null && count($glyphsP4) === 1 && (float) $inkP4[0] - $glyphsP4[0][4] === 0.0,
    '墨迹左沿 − 桥 penX = ' . ($inkP4 !== null && count($glyphsP4) === 1 ? (string) ((float) $inkP4[0] - $glyphsP4[0][4]) : 'n/a')
);

// ── 段落路径量具反例自检（§20 纪律 1）───────────────────────────────
// 合成已知违规件：假桥把 draw_glyph 的 penX 报错一格（等价于"SkiaCanvas
// 算错了字形笔位"），同款互比判据必须变**假**。抓不到 = 判据恒真、空转。
// 紧接的正对照：同一输入不篡改时判据必须为**真**。
// ★ 判据的判别力来自两条独立产出路径：墨迹左沿由 RasterCanvas 出、桥 penX
//   由假桥出 ⇒ 篡改桥侧、墨迹侧不动 ⇒ 必然不等。
skiaBridgeReset();
$GLOBALS['DRIFT_SMOKE_SKIA_SABOTAGE'] = true;
$listSP = new DisplayList();
(new RecordingCanvas($listSP))->drawParagraph($pMetrics->layout('A', $pBody, INF, $pUnit), Paint::fill($red), 0.0, 0.0);
$rasterSP = new RasterCanvas(40, 30, null, $pUnit);
$skiaSP = new SkiaCanvas(40, 30, null, $pUnit);
$listSP->replay($rasterSP);
$listSP->replay($skiaSP);
$inkSP = deviceInkBounds($rasterSP);
$glyphsSPBad = skiaGlyphCalls();
check(
    'Skia conformance · 段落反例自检：桥把 penX 篡改一格 ⇒ 互比判据变假（否则判据恒真、夹具空转）',
    $inkSP !== null && count($glyphsSPBad) === 1 && (float) $inkSP[0] !== $glyphsSPBad[0][4],
    'ink=' . fmtInk($inkSP) . ' 篡改后桥 penX=' . (count($glyphsSPBad) === 1 ? (string) $glyphsSPBad[0][4] : '无')
);
$GLOBALS['DRIFT_SMOKE_SKIA_SABOTAGE'] = false;
skiaBridgeReset();
$skiaSP2 = new SkiaCanvas(40, 30, null, $pUnit);
$listSP->replay($skiaSP2);
$glyphsSPGood = skiaGlyphCalls();
check(
    'Skia conformance · 段落反例自检正对照：同一输入不篡改 ⇒ 判据为真',
    $inkSP !== null && count($glyphsSPGood) === 1 && (float) $inkSP[0] === $glyphsSPGood[0][4],
    'ink=' . fmtInk($inkSP) . ' 桥 penX=' . (count($glyphsSPGood) === 1 ? (string) $glyphsSPGood[0][4] : '无')
);


// ⑩ 响亮失败：颜色越界 / W8 未闭合
fresh($log);
[$code, $output] = runCase(__FILE__, 'color', null);
check('Rendering · Color 越界退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);

fresh($log);
[$code, $output] = runCase(__FILE__, 'w8', null);
check('Rendering · W8 未闭合退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Rendering · W8 未闭合写了 Errors.log', str_contains(logText($log), 'W8'));

// ⑩+1 19 op 回植 · P0 契约（2026-10-20，D2 分层进 Canvas）
fresh($log);
$capsRaster = new RasterCanvas(8, 8, Color::white(), Scale::of(1.0));
check(
    'Canvas · Raster capabilities() = Core + Extended + CAP_IMAGE（W1 三步 + W8-1 图像 blit）',
    $capsRaster->capabilities() === (CanvasCapabilities::CAP_ELLIPSE | CanvasCapabilities::CAP_LINE
        | CanvasCapabilities::CAP_GRADIENT | CanvasCapabilities::CAP_DASH
        | CanvasCapabilities::CAP_CLIP_PATH | CanvasCapabilities::CAP_PATH
        | CanvasCapabilities::CAP_LAYER | CanvasCapabilities::CAP_IMAGE
        | CanvasCapabilities::CAP_BACKDROP)
);
$capsSkia = new SkiaCanvas(8, 8, null, Scale::of(1.0));
check(
    'Canvas · Skia capabilities() = Core + Extended + Optional + CLIP_PATH（P4 全 op 走 SkShader/Skia 原语）',
    $capsSkia->capabilities() === (CanvasCapabilities::CAP_ELLIPSE | CanvasCapabilities::CAP_LINE
        | CanvasCapabilities::CAP_GRADIENT | CanvasCapabilities::CAP_DASH
        | CanvasCapabilities::CAP_PATH | CanvasCapabilities::CAP_IMAGE
        | CanvasCapabilities::CAP_SHADOW | CanvasCapabilities::CAP_LAYER
        | CanvasCapabilities::CAP_CLIP_PATH | CanvasCapabilities::CAP_BACKDROP)
);
$capsRec = RecordingCanvas::create();
// ★ 默认值在 2026-10-25 改成"回放端（Raster）的支持面"：`RecordingCanvas` 的真实
//   消费者只有离屏回放，默认 ALL_MASK 会让它录下 Optional op（drawShadow）然后
//   回放时响亮失败（smoke 的 Card / 浮层段当场全崩）。
check(
    'Canvas · Recording 默认能力 = Raster 支持面（离屏回放端，不录 Optional）',
    $capsRec->capabilities() === RasterCanvas::supportedCapabilities()
);
check(
    'Canvas · Recording 显式传 ALL_MASK ⇒ 全录（"回放裁决"模式仍可用）',
    (new RecordingCanvas(new DisplayList(), CanvasCapabilities::ALL_MASK))->capabilities()
        === CanvasCapabilities::ALL_MASK
);

// ── W8-1 图像：RasterCanvas::drawImage（最近邻 blit）────────────────────────
// 判据全按公式推，不拟合：
// ① 1:1 整图 ⇒ 逐像素与源 RGBA 相同（这也是"与 Skia 逐字节一致"的那一档）；
// ② 源矩形裁剪 ⇒ 只取 src 指定的子块；
// ③ 整数放大 N 倍 ⇒ 每个源像素铺成 N×N 的同色块（最近邻的可判定形态）；
// ④ 越界夹紧 ⇒ 不炸、不越界写、可见部分落位正确；
// ⑤ 半透明 ⇒ source-over 与 blend() 同式（拿解析值对）；
// ⑥ 命令流 replay 与直接调用**逐字节相同**（OP_DRAW_IMAGE 回放路径）。
$argb = static fn(int $r, int $g, int $b, int $a = 255): int => ($a << 24) | ($r << 16) | ($g << 8) | $b;
$mkImage = static function (array $px): ImageHandle {
    $rgba = '';
    foreach ($px as $p) {
        $rgba .= chr($p[0]) . chr($p[1]) . chr($p[2]) . chr($p[3]);
    }

    return ImageHandle::fromRgba($rgba, 2, 2);
};
// 2×2：左上红 / 右上绿 / 左下蓝 / 右下黄（全不透明）
$imgQuad = $mkImage([[255, 0, 0, 255], [0, 255, 0, 255], [0, 0, 255, 255], [255, 255, 0, 255]]);

// ① 1:1 整图
$imgCanvas = new RasterCanvas(4, 4, Color::white(), Scale::of(1.0));
$imgCanvas->drawImage($imgQuad, null, new Rect(0.0, 0.0, 2.0, 2.0));
$imgGot = [];
foreach ([[0, 0], [1, 0], [0, 1], [1, 1], [2, 2]] as [$ix, $iy]) {
    $imgGot[] = $imgCanvas->pixelAt($ix, $iy)->toArgb();
}
check(
    'Image · 1:1 整图 blit 逐像素 = 源 RGBA（含"盒外不动"）',
    $imgGot === [
        $argb(255, 0, 0), $argb(0, 255, 0), $argb(0, 0, 255), $argb(255, 255, 0),
        $argb(255, 255, 255),
    ],
    'got=' . implode(',', $imgGot),
);

// ② 源矩形裁剪：只取 (1,0) 那颗绿像素，落到 (2,2)
$imgCanvas2 = new RasterCanvas(4, 4, Color::white(), Scale::of(1.0));
$imgCanvas2->drawImage($imgQuad, new Rect(1.0, 0.0, 1.0, 1.0), new Rect(2.0, 2.0, 1.0, 1.0));
check(
    'Image · 源矩形裁剪：src(1,0,1,1) ⇒ 只有 (2,2) 变绿，别处不动',
    $imgCanvas2->pixelAt(2, 2)->toArgb() === $argb(0, 255, 0)
        && $imgCanvas2->pixelAt(1, 1)->toArgb() === $argb(255, 255, 255)
        && $imgCanvas2->pixelAt(0, 0)->toArgb() === $argb(255, 255, 255),
    sprintf('p22=%d p11=%d p00=%d', $imgCanvas2->pixelAt(2, 2)->toArgb(), $imgCanvas2->pixelAt(1, 1)->toArgb(), $imgCanvas2->pixelAt(0, 0)->toArgb()),
);

// ③ 整数放大 2×：2×2 → 4×4，每个源像素铺 2×2 同色块
$imgCanvas3 = new RasterCanvas(4, 4, Color::white(), Scale::of(1.0));
$imgCanvas3->drawImage($imgQuad, null, new Rect(0.0, 0.0, 4.0, 4.0));
$imgUpscaleOk = true;
for ($uy = 0; $uy < 4; $uy++) {
    for ($ux = 0; $ux < 4; $ux++) {
        $want = $imgQuad->rgba()[((intdiv($uy, 2) * 2) + intdiv($ux, 2)) * 4];
        $got = $imgCanvas3->pixelAt($ux, $uy);
        if (ord($want) !== $got->r) {
            $imgUpscaleOk = false;
        }
    }
}
check(
    'Image · 整数放大 2×：每个源像素铺成 2×2 同色块（最近邻的可判定形态）',
    $imgUpscaleOk,
    '见逐像素核对（R 通道 = 源块 R）',
);

// ④ 越界夹紧：dst 左上角在画布外 (-1,-1)，只有右下那颗黄像素可见 ⇒ (0,0) 黄
$imgCanvas4 = new RasterCanvas(2, 2, Color::white(), Scale::of(1.0));
$imgCanvas4->drawImage($imgQuad, null, new Rect(-1.0, -1.0, 2.0, 2.0));
check(
    'Image · 越界夹紧：dst(-1,-1,2,2) ⇒ 只有源右下角可见于 (0,0)，不炸不越界',
    $imgCanvas4->pixelAt(0, 0)->toArgb() === $argb(255, 255, 0)
        && $imgCanvas4->pixelAt(1, 1)->toArgb() === $argb(255, 255, 255),
    sprintf('p00=%d p11=%d', $imgCanvas4->pixelAt(0, 0)->toArgb(), $imgCanvas4->pixelAt(1, 1)->toArgb()),
);

// ⑤ 半透明：源 (0,0,0,128) 压白底 ⇒ 每通道 = round((0*128 + 255*127)/255) = 127
$imgHalf = ImageHandle::fromRgba(chr(0) . chr(0) . chr(0) . chr(128), 1, 1);
$imgCanvas5 = new RasterCanvas(2, 2, Color::white(), Scale::of(1.0));
$imgCanvas5->drawImage($imgHalf, null, new Rect(0.0, 0.0, 1.0, 1.0));
$imgHalfWant = (int) round((0 * 128 + 255 * 127) / 255);
$imgHalfGot = $imgCanvas5->pixelAt(0, 0);
check(
    'Image · 半透明 source-over = blend() 同式（解析值 round((0*128+255*127)/255)）',
    $imgHalfGot->r === $imgHalfWant && $imgHalfGot->g === $imgHalfWant && $imgHalfGot->b === $imgHalfWant
        && $imgHalfGot->a === 255,
    sprintf('got=(%d,%d,%d,%d) want=%d', $imgHalfGot->r, $imgHalfGot->g, $imgHalfGot->b, $imgHalfGot->a, $imgHalfWant),
);

// ⑥ 命令流 replay 与直接调用逐字节相同（OP_DRAW_IMAGE 回放路径，RenderImage 走这条）
$imgDirect = new RasterCanvas(6, 6, Color::white(), Scale::of(1.0));
$imgDirect->drawImage($imgQuad, null, new Rect(1.0, 1.0, 2.0, 2.0));
$imgList = new DisplayList();
$imgRec = new RecordingCanvas($imgList);
$imgRec->drawImage($imgQuad, null, new Rect(1.0, 1.0, 2.0, 2.0));
$imgReplay = new RasterCanvas(6, 6, Color::white(), Scale::of(1.0));
$imgList->replay($imgReplay);
check(
    'Image · 命令流 replay 与直接调用逐字节相同（录制/回放路径等价）',
    $imgReplay->toRgba() === $imgDirect->toRgba(),
    'md5 direct=' . md5($imgDirect->toRgba()) . ' replay=' . md5($imgReplay->toRgba()),
);

// ⑦ 矩形裁剪生效：裁到 (0,0,1,1) 后画 2×2 ⇒ 只有 (0,0) 变红
$imgCanvas7 = new RasterCanvas(3, 3, Color::white(), Scale::of(1.0));
$imgCanvas7->pushClip(new Rect(0.0, 0.0, 1.0, 1.0));
$imgCanvas7->drawImage($imgQuad, null, new Rect(0.0, 0.0, 2.0, 2.0));
$imgCanvas7->popClip();
check(
    'Image · 矩形裁剪生效：clip(1×1) 内只画 (0,0)，(1,0)/(0,1) 保持白',
    $imgCanvas7->pixelAt(0, 0)->toArgb() === $argb(255, 0, 0)
        && $imgCanvas7->pixelAt(1, 0)->toArgb() === $argb(255, 255, 255)
        && $imgCanvas7->pixelAt(0, 1)->toArgb() === $argb(255, 255, 255),
    sprintf('p00=%d p10=%d p01=%d', $imgCanvas7->pixelAt(0, 0)->toArgb(), $imgCanvas7->pixelAt(1, 0)->toArgb(), $imgCanvas7->pixelAt(0, 1)->toArgb()),
);

// ⑧ 反例：全透明图 / 源全在界外 / 0 面积目标 ⇒ 不炸、不改一个像素
//    ★ 不用 `ImageHandle::fromRgba('', 0, 0)`：构造器对 width<=0 会 Errors::report
//      （那条是防"图坏了静默错位"的响亮失败，不是给正常调用用的）。
$imgCanvas8 = new RasterCanvas(2, 2, Color::white(), Scale::of(1.0));
$imgBefore8 = $imgCanvas8->toRgba();
$imgCanvas8->drawImage(ImageHandle::fromRgba(chr(0) . chr(0) . chr(0) . chr(0), 1, 1), null, new Rect(0.0, 0.0, 1.0, 1.0));
$imgCanvas8->drawImage($imgQuad, new Rect(5.0, 5.0, 1.0, 1.0), new Rect(0.0, 0.0, 1.0, 1.0));
$imgCanvas8->drawImage($imgQuad, null, new Rect(0.0, 0.0, 0.0, 0.0));
check(
    'Image · 反例：全透明图 / 源全在界外 / 目标 0 面积 ⇒ 一个像素都不改',
    $imgCanvas8->toRgba() === $imgBefore8,
    'md5=' . md5($imgCanvas8->toRgba()),
);

// ══ W8-1 · PNG 解码 / ImageLoader / Image 控件 ═══════════════════════════════
// ★ 解码器的判据**全部对"按规范手算出来的 RGBA"**，不对"再编回去的字节" ——
//   编解码共用一份实现时同一个 bug 会两边一起错（量具自检纪律）。下面这个
//   编码器只负责**造输入**，输出侧一律断言解析值。
$pngChunk = static function (string $type, string $data): string {
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
};

/**
 * 造一份 PNG 字节流。`$rows` = **原始样本**（每行长度 = stride），`$filter`
 * 对所有行统一（五种滤波各造一份就能把反滤波全走一遍）。
 */
$pngMake = static function (
    int $w,
    int $h,
    int $colorType,
    int $depth,
    array $rows,
    int $filter = 0,
    string $extra = '',
    int $interlace = 0,
) use ($pngChunk): string {
    $channels = 1;
    if ($colorType === 2) {
        $channels = 3;
    } elseif ($colorType === 4) {
        $channels = 2;
    } elseif ($colorType === 6) {
        $channels = 4;
    }
    $stride = intdiv($w * $channels * $depth + 7, 8);
    $bpp = intdiv($channels * $depth + 7, 8);
    if ($bpp < 1) {
        $bpp = 1;
    }

    $raw = '';
    $prev = array_fill(0, $stride, 0);
    foreach ($rows as $line) {
        $raw .= chr($filter);
        for ($i = 0; $i < $stride; $i++) {
            $a = $i >= $bpp ? $line[$i - $bpp] : 0;
            $b = $prev[$i];
            $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
            if ($filter === 0) {
                $v = $line[$i];
            } elseif ($filter === 1) {
                $v = $line[$i] - $a;
            } elseif ($filter === 2) {
                $v = $line[$i] - $b;
            } elseif ($filter === 3) {
                $v = $line[$i] - (($a + $b) >> 1);
            } else {
                $p = $a + $b - $c;
                $pa = abs($p - $a);
                $pb = abs($p - $b);
                $pc = abs($p - $c);
                $pred = $c;
                if ($pa <= $pb && $pa <= $pc) {
                    $pred = $a;
                } elseif ($pb <= $pc) {
                    $pred = $b;
                }
                $v = $line[$i] - $pred;
            }
            $raw .= chr($v & 0xFF);
        }
        $prev = $line;
    }

    return "\x89PNG\r\n\x1a\n"
        . $pngChunk('IHDR', pack('NN', $w, $h) . chr($depth) . chr($colorType) . "\x00\x00" . chr($interlace))
        . $extra
        . $pngChunk('IDAT', gzcompress($raw, 9))
        . $pngChunk('IEND', '');
};

/** 读一个像素成 "r,g,b,a" 字符串（断言里好读） */
$pngPx = static function (ImageHandle $image, int $x, int $y): string {
    $s = $image->rgba();
    $o = ($y * $image->width + $x) * 4;

    return ord($s[$o]) . ',' . ord($s[$o + 1]) . ',' . ord($s[$o + 2]) . ',' . ord($s[$o + 3]);
};

// ① 五种滤波：同一份 2×2 RGBA 样本，换滤波类型编，解出来必须逐像素相同
$pngFilterOk = true;
$pngFilterNote = [];
foreach ([0, 1, 2, 3, 4] as $pngF) {
    // 相邻像素刻意不相等 ⇒ Sub/Up/Average/Paeth 的预测器真的被用到
    $pngRows = [
        [10, 20, 30, 255, 40, 50, 60, 255],
        [70, 80, 90, 255, 100, 110, 120, 255],
    ];
    $pngImg = PngDecoder::decode($pngMake(2, 2, 6, 8, $pngRows, $pngF), 'filter-' . $pngF);
    $pngGood = $pngImg !== null
        && $pngPx($pngImg, 0, 0) === '10,20,30,255'
        && $pngPx($pngImg, 1, 0) === '40,50,60,255'
        && $pngPx($pngImg, 0, 1) === '70,80,90,255'
        && $pngPx($pngImg, 1, 1) === '100,110,120,255';
    if (!$pngGood) {
        $pngFilterOk = false;
    }
    $pngFilterNote[] = 'f' . $pngF . ($pngGood ? ' ok' : ' FAIL');
}
check(
    'PNG · 五种滤波（None/Sub/Up/Average/Paeth）解码 = 原始样本',
    $pngFilterOk,
    implode(' / ', $pngFilterNote),
);

// ② 色彩类型 2（真彩无 alpha）⇒ alpha 补 255
$pngRgb = PngDecoder::decode($pngMake(1, 2, 2, 8, [[200, 100, 50], [10, 20, 30]]), 'rgb');
check(
    'PNG · 色彩类型 2（RGB）⇒ alpha 补 255',
    $pngRgb !== null && $pngPx($pngRgb, 0, 0) === '200,100,50,255' && $pngPx($pngRgb, 0, 1) === '10,20,30,255',
    $pngRgb === null ? 'null' : $pngPx($pngRgb, 0, 0) . ' / ' . $pngPx($pngRgb, 0, 1),
);

// ③ 色彩类型 0（灰度）⇒ r=g=b
$pngGray = PngDecoder::decode($pngMake(1, 2, 0, 8, [[128], [255]]), 'gray');
check(
    'PNG · 色彩类型 0（灰度 8 位）⇒ r=g=b',
    $pngGray !== null && $pngPx($pngGray, 0, 0) === '128,128,128,255' && $pngPx($pngGray, 0, 1) === '255,255,255,255',
    $pngGray === null ? 'null' : $pngPx($pngGray, 0, 0) . ' / ' . $pngPx($pngGray, 0, 1),
);

// ④ 色彩类型 3（调色板）+ tRNS：索引 0 全透、索引 1 半透、索引 2 不透明
$pngPlte = $pngChunk('PLTE', chr(10) . chr(20) . chr(30) . chr(40) . chr(50) . chr(60) . chr(70) . chr(80) . chr(90));
$pngTrns = $pngChunk('tRNS', chr(0) . chr(128));
$pngPal = PngDecoder::decode($pngMake(3, 1, 3, 8, [[0, 1, 2]], 0, $pngPlte . $pngTrns), 'palette');
check(
    'PNG · 色彩类型 3（调色板）+ tRNS：索引色与 alpha 表都对',
    $pngPal !== null
        && $pngPx($pngPal, 0, 0) === '10,20,30,0'
        && $pngPx($pngPal, 1, 0) === '40,50,60,128'
        && $pngPx($pngPal, 2, 0) === '70,80,90,255',
    $pngPal === null ? 'null' : $pngPx($pngPal, 0, 0) . ' | ' . $pngPx($pngPal, 1, 0) . ' | ' . $pngPx($pngPal, 2, 0),
);

// ⑤ 16 位取高字节：0x12FF → 0x12
$png16 = PngDecoder::decode(
    $pngMake(1, 1, 6, 16, [[0x12, 0xFF, 0x34, 0xFF, 0x56, 0xFF, 0xFF, 0xFF]]),
    'rgba16',
);
check(
    'PNG · 16 位取高字节（0x12FF→0x12）',
    $png16 !== null && $pngPx($png16, 0, 0) === '18,52,86,255',
    $png16 === null ? 'null' : $pngPx($png16, 0, 0),
);

// ⑥ 1 位灰度：位打包（MSB 优先），0 → 黑、1 → 白
//    两个像素 = 1 字节：pixel0 在 bit7、pixel1 在 bit6 ⇒ [0,1] = 0b0100_0000 = 64
$png1bit = PngDecoder::decode($pngMake(2, 1, 0, 1, [[64]]), 'gray1');
check(
    'PNG · 1 位灰度（MSB 打包）⇒ 0/1 拉伸成 0/255',
    $png1bit !== null && $pngPx($png1bit, 0, 0) === '0,0,0,255' && $pngPx($png1bit, 1, 0) === '255,255,255,255',
    $png1bit === null ? 'null' : $pngPx($png1bit, 0, 0) . ' / ' . $pngPx($png1bit, 1, 0),
);

// ⑦ 真资源文件：assets/demo/quad.png（四象限 + 中心白点）
$pngAsset = PngDecoder::decodeFile(__DIR__ . '/../assets/demo/quad.png');
check(
    'PNG · 真文件 assets/demo/quad.png（64×64 四象限 + 中心白点）',
    $pngAsset !== null
        && $pngAsset->width === 64 && $pngAsset->height === 64
        && $pngPx($pngAsset, 8, 8) === '220,40,40,255'
        && $pngPx($pngAsset, 56, 8) === '40,170,80,255'
        && $pngPx($pngAsset, 8, 56) === '40,90,210,255'
        && $pngPx($pngAsset, 56, 56) === '235,190,40,255'
        && $pngPx($pngAsset, 32, 32) === '255,255,255,255',
    $pngAsset === null ? 'null' : $pngPx($pngAsset, 8, 8) . ' / ' . $pngPx($pngAsset, 32, 32),
);

// ⑧ 反例：签名错 / 截断 / 隔行 / 位深非法 ⇒ null + **每条都留痕**
$pngDiagBefore = count(Errors::recentDiagnostics());
$pngBad1 = PngDecoder::decode('not a png at all', 'bad-signature');
$pngBad2 = PngDecoder::decode(substr($pngMake(2, 2, 6, 8, [[1, 2, 3, 4, 5, 6, 7, 8], [9, 10, 11, 12, 13, 14, 15, 16]]), 0, 20), 'truncated');
$pngBad3 = PngDecoder::decode($pngMake(1, 1, 6, 8, [[1, 2, 3, 4]], 0, '', 1), 'interlaced');
$pngBad4 = PngDecoder::decode($pngMake(1, 1, 2, 4, [[1, 2]]), 'bad-depth');
$pngBad5 = PngDecoder::decodeFile(__DIR__ . '/../assets/demo/definitely-missing.png');
$pngDiagAfter = count(Errors::recentDiagnostics());
check(
    'PNG · 反例五条（签名错/截断/隔行/位深非法/文件不存在）⇒ 全部 null 且各留一条诊断',
    $pngBad1 === null && $pngBad2 === null && $pngBad3 === null && $pngBad4 === null && $pngBad5 === null
        && $pngDiagAfter - $pngDiagBefore === 5,
    sprintf('nulls=%s 诊断 +%d', implode(',', array_map(static fn(?ImageHandle $i): string => $i === null ? 'null' : 'ok', [$pngBad1, $pngBad2, $pngBad3, $pngBad4, $pngBad5])), $pngDiagAfter - $pngDiagBefore),
);

// ⑨ ImageLoader：候选目录解析 + **缓存命中同一实例** + 缺失留痕
ImageLoader::clearCache();
$ldrA = ImageLoader::load('demo/quad.png');
$ldrB = ImageLoader::load('demo/quad.png');
check(
    'ImageLoader · 相对路径按候选目录解析，第二次命中缓存（同一实例，不重复解码）',
    $ldrA !== null && $ldrA === $ldrB && ImageLoader::cacheSize() === 1,
    sprintf('a=%s b=%s cache=%d', $ldrA === null ? 'null' : 'ok', $ldrB === null ? 'null' : 'ok', ImageLoader::cacheSize()),
);
$ldrAbs = __DIR__ . '/../assets/demo/quad.png';
$ldrResolved = ImageLoader::resolve($ldrAbs);
check(
    'ImageLoader · 绝对路径原样使用（不往候选目录上拼）',
    $ldrResolved !== null && realpath($ldrResolved) === realpath($ldrAbs),
    'resolved=' . ($ldrResolved ?? 'null'),
);

// ⑩ RenderImage 几何：自然尺寸 / 只给一边按比例推 / 内禀与实排一致
$imgQuadAsset = ImageLoader::load('demo/quad.png'); // 64×64
$imgStripAsset = ImageLoader::load('demo/strip.png'); // 96×24
$boxNat = new RenderImage($imgQuadAsset, ImageFit::Contain, 0.0, 0.0);
$natSize = $boxNat->layout(new BoxConstraints(0.0, INF, 0.0, INF));
check(
    'Image · 自然尺寸 = 原图像素尺寸（64×64）',
    abs($natSize->width - 64.0) < 0.01 && abs($natSize->height - 64.0) < 0.01,
    sprintf('%.2f×%.2f', $natSize->width, $natSize->height),
);
$boxWOnly = new RenderImage($imgStripAsset, ImageFit::Contain, 48.0, 0.0);
$wOnlySize = $boxWOnly->layout(new BoxConstraints(0.0, INF, 0.0, INF));
check(
    'Image · 只给宽 48（原图 96×24）⇒ 高按比例 = 12',
    abs($wOnlySize->width - 48.0) < 0.01 && abs($wOnlySize->height - 12.0) < 0.01,
    sprintf('%.2f×%.2f', $wOnlySize->width, $wOnlySize->height),
);
check(
    'Image · 内禀 = 实排（contentIntrinsic* 与 performLayout 同式）',
    abs($boxWOnly->intrinsicWidth(0.0) - 48.0) < 0.01 && abs($boxWOnly->intrinsicHeight(0.0) - 12.0) < 0.01,
    sprintf('intr=%.2f×%.2f', $boxWOnly->intrinsicWidth(0.0), $boxWOnly->intrinsicHeight(0.0)),
);

// ⑪ RenderImage 像素：Contain 在方盒里 letterbox（2×1 图进 4×4 盒 ⇒ 上下各留 1 行）
//     scale = min(4/2, 4/1) = 2 ⇒ dst = (0, 1, 4, 2)
$imgTwoByOne = ImageHandle::fromRgba(
    chr(220) . chr(40) . chr(40) . chr(255) . chr(40) . chr(170) . chr(80) . chr(255),
    2,
    1,
);
$boxContain = new RenderImage($imgTwoByOne, ImageFit::Contain, 4.0, 4.0);
$boxContain->layout(new BoxConstraints(4.0, 4.0, 4.0, 4.0));
$cvContain = new RasterCanvas(4, 4, Color::white(), Scale::of(1.0));
$boxContain->paint($cvContain);
check(
    'Image · Contain 在方盒里 letterbox（上下留白、左右铺满、比例不变形）',
    $cvContain->pixelAt(0, 1)->toArgb() === $argb(220, 40, 40)
        && $cvContain->pixelAt(0, 2)->toArgb() === $argb(220, 40, 40)
        && $cvContain->pixelAt(3, 1)->toArgb() === $argb(40, 170, 80)
        && $cvContain->pixelAt(0, 0)->toArgb() === $argb(255, 255, 255)
        && $cvContain->pixelAt(0, 3)->toArgb() === $argb(255, 255, 255),
    sprintf('(0,0)=%d (0,1)=%d (0,2)=%d (3,1)=%d (0,3)=%d', $cvContain->pixelAt(0, 0)->toArgb(), $cvContain->pixelAt(0, 1)->toArgb(), $cvContain->pixelAt(0, 2)->toArgb(), $cvContain->pixelAt(3, 1)->toArgb(), $cvContain->pixelAt(0, 3)->toArgb()),
);

// ⑫ Cover 的**裁源矩形**：4×2 图进 2×4 盒 ⇒ 盒更窄 ⇒ 保高裁宽
//     boxAspect=0.5, imgAspect=2 ⇒ sw = ih*0.5 = 1, sh = ih = 2 ⇒ src=(1.5, 0, 1, 2), dst=(0,0,2,4)
$imgFourByTwo = ImageHandle::fromRgba(str_repeat("\x00\x00\x00\xFF", 8), 4, 2);
$boxCover = new RenderImage($imgFourByTwo, ImageFit::Cover, 2.0, 4.0);
$boxCover->layout(new BoxConstraints(2.0, 2.0, 4.0, 4.0));
$GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] = [];
$cvCover = new SkiaCanvas(2, 4, null, Scale::of(1.0));
$boxCover->paint($cvCover);
$coverCall = null;
foreach ($GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] as $skCall) {
    if ($skCall[0] === 'draw_image') {
        $coverCall = $skCall;
    }
}
check(
    'Image · Cover 用**裁源矩形**（不是裁目标）：src=(1.5,0,1,2) dst=(0,0,2,4)，双端收到同一设备矩形',
    $coverCall !== null
        && abs($coverCall[4] - 1.5) < 0.01 && abs($coverCall[5]) < 0.01
        && abs($coverCall[6] - 1.0) < 0.01 && abs($coverCall[7] - 2.0) < 0.01
        && abs($coverCall[8]) < 0.01 && abs($coverCall[9]) < 0.01
        && abs($coverCall[10] - 2.0) < 0.01 && abs($coverCall[11] - 4.0) < 0.01
        && $coverCall[12] === true,
    $coverCall === null ? 'no draw_image call' : implode(',', array_slice($coverCall, 4)),
);

// ⑬ Image 控件：走 createRenderObject，源来自资源路径；只给宽按比例
$imgWidget = Image::make()->asset('demo/strip.png')->width(48.0)->fit(ImageFit::Contain);
$imgWidgetBox = $imgWidget->createRenderObject(smokeContext());
$imgWidgetSize = $imgWidgetBox->layout(new BoxConstraints(0.0, INF, 0.0, INF));
check(
    'Image 控件 · asset() 走 ImageLoader，只给宽 48 ⇒ 48×12',
    abs($imgWidgetSize->width - 48.0) < 0.01 && abs($imgWidgetSize->height - 12.0) < 0.01,
    sprintf('%.2f×%.2f', $imgWidgetSize->width, $imgWidgetSize->height),
);

// ⑭ Image 控件反例：资源不存在 ⇒ 退回 1×1 透明占位（不炸、不静默空白报错）
$imgMissing = Image::make()->asset('demo/no-such-image.png');
$imgMissingBox = $imgMissing->createRenderObject(smokeContext());
$imgMissingSize = $imgMissingBox->layout(new BoxConstraints(0.0, INF, 0.0, INF));
$cvMissing = new RasterCanvas(2, 2, Color::white(), Scale::of(1.0));
$imgMissingBox->paint($cvMissing);
check(
    'Image 控件 · 缺图 ⇒ 1×1 透明占位（尺寸退化 + 画不出墨，诊断已留痕）',
    abs($imgMissingSize->width - 1.0) < 0.01 && abs($imgMissingSize->height - 1.0) < 0.01
        && $cvMissing->pixelAt(0, 0)->toArgb() === $argb(255, 255, 255),
    sprintf('%.2f×%.2f', $imgMissingSize->width, $imgMissingSize->height),
);

// ⑮ Image 控件 · 内存句柄路径（image() 不碰文件系统）
$imgFromHandle = Image::make()->image($imgQuadAsset)->width(32.0)->height(32.0)->fit(ImageFit::Fill);
$imgFromHandleBox = $imgFromHandle->createRenderObject(smokeContext());
$imgFromHandleSize = $imgFromHandleBox->layout(new BoxConstraints(0.0, INF, 0.0, INF));
$cvHandle = new RasterCanvas(32, 32, Color::white(), Scale::of(1.0));
$imgFromHandleBox->paint($cvHandle);
check(
    'Image 控件 · image() 内存句柄（32×32 Fill）⇒ 左上红、右上绿、右下黄',
    abs($imgFromHandleSize->width - 32.0) < 0.01 && abs($imgFromHandleSize->height - 32.0) < 0.01
        && $cvHandle->pixelAt(4, 4)->toArgb() === $argb(220, 40, 40)
        && $cvHandle->pixelAt(28, 4)->toArgb() === $argb(40, 170, 80)
        && $cvHandle->pixelAt(28, 28)->toArgb() === $argb(235, 190, 40),
    sprintf('%.2f×%.2f (4,4)=%d (28,4)=%d (28,28)=%d', $imgFromHandleSize->width, $imgFromHandleSize->height, $cvHandle->pixelAt(4, 4)->toArgb(), $cvHandle->pixelAt(28, 4)->toArgb(), $cvHandle->pixelAt(28, 28)->toArgb()),
);

// ── W1/L3-1 路径（`05` §3 / §12.2 #1–#4）──────────────────────────────────
// ★ 这一波的四条夹具全都要"量与已知几何的差"，因为 drawPath 是**新算法**
//   （扫描线 + 奇偶），而新算法最贵的失败是"画出来但形状不对"。
// ★ 数的是**非背景**像素：背景是不透明白，数 alpha>0 会把背景也算成墨
//   （第一版量具就这么错的，全画布都是 40000）。
$pathInkCount = static function (RasterCanvas $canvas): int {
    $px = $canvas->toRgba();
    $n = 0;
    for ($i = 0, $len = strlen($px); $i < $len; $i += 4) {
        if (ord($px[$i]) !== 255 || ord($px[$i + 1]) !== 255 || ord($px[$i + 2]) !== 255) {
            $n++;
        }
    }

    return $n;
};

// ① 填充：三角形 (20,20)(180,20)(100,160)，解析面积 = 0.5 × 160 × 140 = 11200
$triCanvas = new RasterCanvas(200, 200, Color::white(), Scale::of(1.0));
$triCanvas->drawPath(
    Path::create()->moveTo(20.0, 20.0)->lineTo(180.0, 20.0)->lineTo(100.0, 160.0)->close(),
    Paint::fill(Color::black())
);
$triInk = $pathInkCount($triCanvas);
check(
    'Path · 填充的墨量 = 解析面积 ±5%（三角形 11200）',
    abs($triInk - 11200) <= 560,
    'ink=' . $triInk . ' 偏差=' . sprintf('%+.2f%%', ($triInk - 11200) / 112.0)
);

// ② 奇偶重叠：两个 80×80 错开 40 的矩形 ⇒ 交集区**不填**
//    两个矩形并集 11200，但奇偶填的是**对称差** = 11200 − 2×1600 = 9600
$evenOddCanvas = new RasterCanvas(200, 200, Color::white(), Scale::of(1.0));
$evenOddCanvas->drawPath(
    Path::create()
        ->addRect(new Rect(20.0, 20.0, 80.0, 80.0))
        ->addRect(new Rect(60.0, 60.0, 80.0, 80.0)),
    Paint::fill(Color::black())
);
$evenOddInk = $pathInkCount($evenOddCanvas);
check(
    'Path · 奇偶规则：两个重叠子路径的交集不填（对称差 9600）',
    abs($evenOddInk - 9600) <= 480,
    'ink=' . $evenOddInk . '（若 ≈11200 就是按并集填了，奇偶没生效）'
);
// 交集正中的像素必须是背景色
check(
    'Path · 奇偶交集正中没有墨',
    $evenOddCanvas->pixelAt(80, 80)->r === 255,
    'pixel(80,80)=' . $evenOddCanvas->pixelAt(80, 80)->r
);

// ③ 描边：斜线 (20,180)→(180,20)，宽 10 ⇒ 面积 ≈ 线长 × 宽 = 226.27 × 10 ≈ 2263
$strokeCanvas = new RasterCanvas(200, 200, Color::white(), Scale::of(1.0));
$strokeCanvas->drawPath(
    Path::create()->moveTo(20.0, 180.0)->lineTo(180.0, 20.0),
    Paint::stroke(Color::black(), 10.0)
);
$strokeInk = $pathInkCount($strokeCanvas);
check(
    'Path · 斜线描边的墨量 ≈ 线长 × 宽度（2263 ±10%）',
    abs($strokeInk - 2263) <= 227,
    'ink=' . $strokeInk
);
// ★ 闭合路径描边是**环**：外矩形 130² − 内矩形 110² = 4800
$ringCanvas = new RasterCanvas(200, 200, Color::white(), Scale::of(1.0));
$ringCanvas->drawPath(
    Path::create()->addRect(new Rect(40.0, 40.0, 120.0, 120.0)),
    Paint::stroke(Color::black(), 10.0)
);
$ringInk = $pathInkCount($ringCanvas);
check(
    'Path · 闭合描边是环（外 130² − 内 110² = 4800 ±5%）',
    abs($ringInk - 4800) <= 240,
    'ink=' . $ringInk . '（若 ≈14400 就是把矩形填满了，描边没挖洞）'
);
// 环正中心必须空
check(
    'Path · 闭合描环的中心是空的',
    $ringCanvas->pixelAt(100, 100)->r === 255,
    'pixel(100,100)=' . $ringCanvas->pixelAt(100, 100)->r
);

// ④ 成本上界：500 边 × ~1000 行 = 50 万 > 20 万 ⇒ 报 1 条 DIAGNOSTIC，画面照画
$budgetCanvas = new RasterCanvas(1200, 1200, Color::white(), Scale::of(1.0));
$budgetPath = Path::create();
for ($bi = 0; $bi < 500; $bi++) {
    $ang = $bi * 2.0 * M_PI / 500.0;
    $bx = 600.0 + 500.0 * cos($ang);
    $by = 600.0 + 500.0 * sin($ang);
    if ($bi === 0) {
        $budgetPath->moveTo($bx, $by);
    } else {
        $budgetPath->lineTo($bx, $by);
    }
}
$budgetPath->close();
$budgetBefore = count(Errors::recentDiagnostics());
$budgetCanvas->drawPath($budgetPath, Paint::fill(Color::black()));
$budgetAfter = count(Errors::recentDiagnostics());
check(
    'Path · 成本上界：边数 × 行数 > 20 万 ⇒ 1 条 DIAGNOSTIC',
    $budgetAfter - $budgetBefore >= 1,
    '+' . ($budgetAfter - $budgetBefore) . ' 条'
);
// ★ 报了但不中止：500 边形面积 = π × 500² ≈ 785398
$budgetInk = $pathInkCount($budgetCanvas);
check(
    'Path · 成本上界报完照画（500 边形 ≈ 785398 ±1%）',
    abs($budgetInk - 785398) <= 7854,
    'ink=' . $budgetInk
);

// ⑤ pushClipPath 圆形裁剪：填满屏的矩形 + 64 边形近似圆 ⇒ 只有圆内有墨，四角是白的
//    64 边形面积 = 0.5 × 64 × 60² × sin(2π/64) ≈ 11300（圆的 π×60² = 11310，差 0.08%）
$clipCircle = new RasterCanvas(200, 200, Color::white(), Scale::of(1.0));
$ringPath = Path::create();
for ($ci = 0; $ci < 64; $ci++) {
    $ang = $ci * 2.0 * M_PI / 64.0;
    $cx = 100.0 + 60.0 * cos($ang);
    $cy = 100.0 + 60.0 * sin($ang);
    if ($ci === 0) {
        $ringPath->moveTo($cx, $cy);
    } else {
        $ringPath->lineTo($cx, $cy);
    }
}
$ringPath->close();
$clipCircle->pushClipPath($ringPath);
$clipCircle->drawRect(new Rect(0.0, 0.0, 200.0, 200.0), Paint::fill(Color::black()));
$clipInk = $pathInkCount($clipCircle);
check(
    'Path · pushClipPath 圆形裁剪的墨量 = 64 边形面积 ±5%（11300）',
    abs($clipInk - 11300) <= 565,
    'ink=' . $clipInk . '（若 =40000 就是裁剪没生效，矩形画满了全屏）'
);
check(
    'Path · 圆形裁剪的四角是白的',
    $clipCircle->pixelAt(2, 2)->r === 255 && $clipCircle->pixelAt(197, 2)->r === 255
        && $clipCircle->pixelAt(2, 197)->r === 255 && $clipCircle->pixelAt(197, 197)->r === 255,
    '角点 r=' . $clipCircle->pixelAt(2, 2)->r . '/' . $clipCircle->pixelAt(197, 197)->r
);
check('Path · 圆形裁剪的圆心有墨', $clipCircle->pixelAt(100, 100)->r === 0);
// popClip 之后必须恢复：再画一个全屏矩形 ⇒ 全黑（40000）
//   ★ 这一条同时验证"裁剪栈存的是 [clip, rows] 一对"：只恢复 Rect 不恢复
//     行掩码的话，popClip 之后圆外仍然是白的（裁剪没弹掉）。
$clipCircle->popClip();
$clipCircle->drawRect(new Rect(0.0, 0.0, 200.0, 200.0), Paint::fill(Color::black()));
check(
    'Path · popClip 后裁剪弹掉（全屏再填 ⇒ 40000 全黑）',
    $pathInkCount($clipCircle) === 40000,
    'ink=' . $pathInkCount($clipCircle)
);

// ⑥ pushClipPath 嵌套：两个 r=60、圆心距 60 的圆求交 ⇒ 透镜形
//    透镜面积 = 2r²·arccos(d/2r) − (d/2)·√(4r²−d²) = 2400π − 30√10800 ≈ 4422
$clipNest = new RasterCanvas(200, 200, Color::white(), Scale::of(1.0));
$nestA = Path::create();
$nestB = Path::create();
for ($ci = 0; $ci < 64; $ci++) {
    $ang = $ci * 2.0 * M_PI / 64.0;
    if ($ci === 0) {
        $nestA->moveTo(70.0 + 60.0 * cos($ang), 100.0 + 60.0 * sin($ang));
        $nestB->moveTo(130.0 + 60.0 * cos($ang), 100.0 + 60.0 * sin($ang));
    } else {
        $nestA->lineTo(70.0 + 60.0 * cos($ang), 100.0 + 60.0 * sin($ang));
        $nestB->lineTo(130.0 + 60.0 * cos($ang), 100.0 + 60.0 * sin($ang));
    }
}
$nestA->close();
$nestB->close();
$clipNest->pushClipPath($nestA);
$clipNest->pushClipPath($nestB);
$clipNest->drawRect(new Rect(0.0, 0.0, 200.0, 200.0), Paint::fill(Color::black()));
$nestInk = $pathInkCount($clipNest);
check(
    'Path · pushClipPath 嵌套 = 两个裁剪求交（透镜 4422 ±5%）',
    abs($nestInk - 4422) <= 221,
    'ink=' . $nestInk . '（若 ≈11300 就是只生效了一个裁剪，嵌套没求交）'
);
// A 圆单独覆盖、B 圆不覆盖的一点必须是白的；两圆交集内心必须是黑的
check('Path · 嵌套裁剪：只在 A 圆内的一点是白的', $clipNest->pixelAt(30, 100)->r === 255);
check('Path · 嵌套裁剪：交集内心有墨', $clipNest->pixelAt(100, 100)->r === 0);
$clipNest->popClip();
$clipNest->popClip();
$clipNest->drawRect(new Rect(0.0, 0.0, 200.0, 200.0), Paint::fill(Color::black()));
check(
    'Path · 嵌套 popClip 两次后恢复（全屏再填 ⇒ 40000）',
    $pathInkCount($clipNest) === 40000,
    'ink=' . $pathInkCount($clipNest)
);

// ⑦ pushClipPath 不配对：只 push 不 pop ⇒ W8 抓得到（`05` §10 #5）
//    ★ 子进程：`Errors::report` 默认 FATAL。补 `DisplayList::record()` 的
//      `OP_PUSH_CLIP_PATH` 计数之前，这一例的 clipDepth 停在 0、抓不到。
fresh($log);
[$code, $output] = runCase(__FILE__, 'clippathunbalanced', null);
check(
    'Path · pushClipPath 不配对 ⇒ W8 退出码 3（补 OP_PUSH_CLIP_PATH 计数前抓不到）',
    $code === 3,
    '实际 ' . $code . ' / ' . $output
);
check('Path · pushClipPath 不配对写了 Errors.log', str_contains(logText($log), 'W8'));

// ⑧ pushLayer 单层 opacity：白底上画 50% 黑 ⇒ source-over 混合值
//    alpha = round(255 × 0.5) = 128 ⇒ dst = round((0×128 + 255×127)/255) = 127
$layerCanvas = new RasterCanvas(60, 60, Color::white(), Scale::of(1.0));
$layerCanvas->pushLayer(0.5);
$layerCanvas->drawRect(new Rect(0.0, 0.0, 60.0, 60.0), Paint::fill(Color::black()));
$layerCanvas->popLayer();
$mid = $layerCanvas->pixelAt(30, 30);
check(
    'Path · pushLayer 单层 opacity = 0.5 ⇒ 黑白混合 ≈127',
    abs($mid->r - 127) <= 2 && abs($mid->g - 127) <= 2 && abs($mid->b - 127) <= 2,
    'rgb=' . $mid->r . ',' . $mid->g . ',' . $mid->b
);

// ⑨ 重叠近似（**记录为已知近似，不是断言正确**）：层内两个重叠子各乘一次
//    ⇒ 重叠处比单层更透。这正是 Raster 乘数栈与 Skia 真离屏的唯一差异
//    （`05` §5.6 的表），夹具的价值是"把有意的差异钉住"。
$overCanvas = new RasterCanvas(60, 60, Color::white(), Scale::of(1.0));
$overCanvas->pushLayer(0.5);
$overCanvas->drawRect(new Rect(0.0, 0.0, 40.0, 60.0), Paint::fill(Color::black()));
$overCanvas->drawRect(new Rect(20.0, 0.0, 40.0, 60.0), Paint::fill(Color::black()));
$overCanvas->popLayer();
// 左段（只被第一个矩形覆盖）= 一次 50% 黑 ≈ 127；中段（两个都覆盖）= 两次 ≈ 63
$left = $overCanvas->pixelAt(5, 30);
$overlap = $overCanvas->pixelAt(30, 30);
check(
    'Path · pushLayer 重叠子：重叠处比单层更透（已知近似，05 §5.6）',
    abs($left->r - 127) <= 2 && abs($overlap->r - 63) <= 3 && $overlap->r < $left->r - 40,
    '左段=' . $left->r . ' 重叠=' . $overlap->r
);

// ⑦b pushLayer 不配对：只 push 不 pop ⇒ W8 抓得到（`05` §10 #6b）
fresh($log);
[$code, $output] = runCase(__FILE__, 'layerunbalanced', null);
check(
    'Path · pushLayer 不配对 ⇒ W8 退出码 3（补 layerDepth 计数前完全不查）',
    $code === 3,
    '实际 ' . $code . ' / ' . $output
);
check('Path · pushLayer 不配对写了 Errors.log', str_contains(logText($log), 'W8'));

// ⑩+3 19 op 回植 · P3 渐变/虚线（RasterCanvas，确定性后端；Skia 侧在 Skia 桥落地后补断言）
$fb = new RasterCanvas(40, 40, Color::white(), Scale::of(1.0));
$grad = Gradient::linear(0.0, 0.0, 40.0, 0.0, [Color::fromRgb(255, 0, 0), Color::fromRgb(0, 0, 255)]);
$fb->drawRect(new Rect(0.0, 0.0, 40.0, 40.0), Paint::fill(Color::black())->withGradient($grad));
$cLeft = $fb->pixelAt(2, 20);
$cRight = $fb->pixelAt(38, 20);
check(
    'P3 · 线性渐变横向铺开：左红右蓝（两端色标被真采样，不是实色）',
    $cLeft->r > 150 && $cLeft->b < 80 && $cRight->b > 150 && $cRight->r < 80,
    '左(r=' . $cLeft->r . ',b=' . $cLeft->b . ') 右(r=' . $cRight->r . ',b=' . $cRight->b . ')'
);

// 径向渐变：圆心红、外圈蓝
$fr = new RasterCanvas(40, 40, Color::white(), Scale::of(1.0));
$gradR = Gradient::radial(20.0, 20.0, 20.0, [Color::fromRgb(255, 0, 0), Color::fromRgb(0, 0, 255)]);
$fr->drawRect(new Rect(0.0, 0.0, 40.0, 40.0), Paint::fill(Color::black())->withGradient($gradR));
$cCenter = $fr->pixelAt(20, 20);
$cEdge = $fr->pixelAt(1, 20);
check(
    'P3 · 径向渐变：圆心红、边缘蓝（半径方向被真采样）',
    $cCenter->r > 150 && $cCenter->b < 80 && $cEdge->b > 150 && $cEdge->r < 80,
    '心(r=' . $cCenter->r . ',b=' . $cCenter->b . ') 边(r=' . $cEdge->r . ',b=' . $cEdge->b . ')'
);

// 线段虚线：on/off 交替（中段有空洞，不是实线）
$fl = new RasterCanvas(60, 8, Color::white(), Scale::of(1.0));
$fl->drawLine(2.0, 4.0, 58.0, 4.0, Paint::stroke(Color::black(), 4.0)->withDash(new DashPattern(8.0, 8.0)));
$on = 0;
$off = 0;
for ($x = 2; $x <= 58; $x += 2) {
    $p = $fl->pixelAt((int) $x, 4);
    if ($p->r < 40 && $p->g < 40 && $p->b < 40) {
        $on++;
    } else {
        $off++;
    }
}
check(
    'P3 · 线段虚线：on/off 交替（中段有空洞，不是实线）',
    $on > 0 && $off > 0,
    "on=$on off=$off"
);

// 矩形描边虚线退化为实线（R2 降级）：整条描边都画出来，无空洞。
// ★ drawRect 描边是"整宽内缩"（已知口径，椭圆才是居中）：矩形 (2,2) 的顶部描边带
//   在 y∈[2,5)（边框 y=2 向内缩 w=3）。采样 y=3 落在此带内；y=1 在框外、洞内是白。
$fr2 = new RasterCanvas(40, 20, Color::white(), Scale::of(1.0));
$fr2->drawRect(new Rect(2.0, 2.0, 36.0, 16.0), Paint::stroke(Color::black(), 3.0)->withDash(new DashPattern(6.0, 6.0)));
$gap = 0;
for ($x = 4; $x <= 36; $x += 2) {
    $p = $fr2->pixelAt((int) $x, 3);
    if ($p->r > 200 && $p->g > 200 && $p->b > 200) {
        $gap++;
    }
}
check(
    'P3 · 矩形描边虚线退化为实线（R2 降级 ⇒ 描边无空洞）',
    $gap === 0,
    "gap=$gap"
);

// DisplayList 回放：录制侧原样持有 Paint ⇒ 渐变/虚线不许在管道里被"规范化"掉
$recG = RecordingCanvas::create();
$recG->drawRect(new Rect(0.0, 0.0, 40.0, 40.0), Paint::fill(Color::black())->withGradient(
    Gradient::linear(0.0, 0.0, 40.0, 0.0, [Color::fromRgb(255, 0, 0), Color::fromRgb(0, 0, 255)])
));
$cg = new RasterCanvas(40, 40, Color::white(), Scale::of(1.0));
$recG->displayList()->replay($cg);
$pl = $cg->pixelAt(2, 20);
$pr = $cg->pixelAt(38, 20);
check(
    'P3 · DisplayList 回放不丢渐变（管道只搬运 Paint，属性不许被规范化掉）',
    $pl->r > 200 && $pl->b < 60 && $pr->b > 200 && $pr->r < 60,
    "左={$pl->r},{$pl->g},{$pl->b} 右={$pr->r},{$pr->g},{$pr->b}"
);

// P3 · Skia 端（生产后端，走 SkShader/SkDashPathEffect）。
// ★ 解释器面没有真 Skia（`drift_skia_read_pixels` 的假桥返回空串）⇒ 像素断言不成立。
//   按仓库既有惯例断言**桥收到了什么**（⑨+1 conformance：ABI 形状即契约）；
//   真像素由 T2 的 AOT 构建 + 真机探针兜。
$redArgb = Color::fromRgb(255, 0, 0)->toArgb();
$blueArgb = Color::fromRgb(0, 0, 255)->toArgb();

skiaBridgeReset();
$fsk = new SkiaCanvas(40, 40, null, Scale::of(1.0));
$fsk->drawRect(new Rect(0.0, 0.0, 40.0, 40.0), Paint::fill(Color::black())->withGradient(
    Gradient::linear(0.0, 0.0, 40.0, 0.0, [Color::fromRgb(255, 0, 0), Color::fromRgb(0, 0, 255)])
));
$opsG = skiaCallOps();
check(
    'P3 · Skia 渐变：桥收到 set_gradient → draw_rect → clear_source（源不泄漏到下次 draw）',
    $opsG === ['create', 'set_gradient', 'draw_rect', 'clear_source'],
    implode(',', $opsG)
);
$callG = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1];
check(
    'P3 · Skia set_gradient 参数（线性 / 端点设备坐标 / 2 色标 / 补位不越界）',
    $callG === ['set_gradient', 0, 0.0, 0.0, 40.0, 0.0, 2, $redArgb, $blueArgb, $blueArgb, $blueArgb, 0.0, 1.0, 1.0, 1.0],
    var_export($callG, true)
);

// 径向：radial 位 = 1，且 x2 是**长度量**（半径只缩放不平移）⇒ (20,20) 圆心 + 半径 10
skiaBridgeReset();
$fskR = new SkiaCanvas(40, 40, null, Scale::of(1.0));
$fskR->drawEllipse(new Rect(10.0, 10.0, 20.0, 20.0), Paint::fill(Color::black())->withGradient(
    Gradient::radial(20.0, 20.0, 10.0, [Color::fromRgb(255, 0, 0), Color::fromRgb(0, 0, 255)])
));
$callR = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1];
check(
    'P3 · Skia 径向渐变：radial=1 且半径只缩放不平移（(20,20) r=10）',
    $callR[0] === 'set_gradient'
        && $callR[1] === 1
        && $callR[2] === 20.0 && $callR[3] === 20.0 && $callR[4] === 10.0 && $callR[5] === 0.0,
    var_export($callR, true)
);

// 虚线：on/off/phase 原样过桥
skiaBridgeReset();
$fskD = new SkiaCanvas(60, 8, null, Scale::of(1.0));
$fskD->drawLine(2.0, 4.0, 58.0, 4.0, Paint::stroke(Color::black(), 4.0)->withDash(new DashPattern(8.0, 8.0, 2.0)));
$opsD = skiaCallOps();
check(
    'P3 · Skia 虚线：桥收到 set_dash → draw_line → clear_source',
    $opsD === ['create', 'set_dash', 'draw_line', 'clear_source'],
    implode(',', $opsD)
);
check(
    'P3 · Skia set_dash 参数（on=8 / off=8 / phase=2 原样过桥）',
    $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1] === ['set_dash', 8.0, 8.0, 2.0],
    var_export($GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1], true)
);

// 反例自检：没有渐变/虚线时**一个桥调用都不多发**（热路径不为不存在的源付账，
// 也顺带守住"source 状态不跨 draw 泄漏"这条——clear 只在真挂了时才发）
skiaBridgeReset();
$fskP = new SkiaCanvas(20, 20, null, Scale::of(1.0));
$fskP->drawRect(new Rect(0.0, 0.0, 20.0, 20.0), Paint::fill(Color::black()));
$fskP->drawLine(0.0, 0.0, 20.0, 20.0, Paint::stroke(Color::black(), 2.0));
check(
    'P3 · Skia 无 source 时不发 set_*/clear_source（不多调、也不泄漏）',
    skiaCallOps() === ['create', 'draw_rect', 'draw_line'],
    implode(',', skiaCallOps())
);
skiaBridgeReset();

// ── ⑩+4 19 op 回植 · P4 Optional / Extended 复合 op（Skia 桥结构 golden）──
// ★ 解释器面没有真 Skia（read_pixels 假桥返回空串）⇒ 不像素断言；断言**桥收到了什么**
//   （ABI 形状即契约，⑨+1 conformance 惯例）。真像素由 T2 AOT 构建 + 真机探针兜。
// 路径几何走画布 translate() ⇒ 打包的 verbs/coords 是绘制时坐标系（已含偏移），
// 这里偏移 0，所以直接比对命令表即可。
skiaBridgeReset();
$fp = new SkiaCanvas(40, 40, null, Scale::of(1.0));
$pathP = Path::create()->moveTo(2.0, 2.0)->lineTo(6.0, 2.0)->quadTo(8.0, 4.0, 6.0, 6.0)->close();
$fp->drawPath($pathP, Paint::fill(Color::black()));
$opsP = skiaCallOps();
check(
    'P4 · Skia drawPath：桥收到 draw_path（count=4，verbs=MOVE/LINE/QUAD/CLOSE）',
    $opsP === ['create', 'draw_path'],
    implode(',', $opsP)
);
$callP = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1];
$expectedVerbs = chr(Path::CMD_MOVE) . chr(Path::CMD_LINE) . chr(Path::CMD_QUAD) . chr(Path::CMD_CLOSE);
check(
    'P4 · Skia drawPath 打包：count=4 且 verbs 字节序正确',
    $callP[0] === 'draw_path' && $callP[1] === 4 && $callP[2] === $expectedVerbs,
    var_export($callP, true)
);
check(
    'P4 · Skia drawPath 不挂源时不发 set_*/clear_source',
    count($opsP) === 2,
    implode(',', $opsP)
);
skiaBridgeReset();

// 图像：RGBA 字节串长度必须等于 w*h*4（与 ImageHandle 构造期校验同判据）
skiaBridgeReset();
$imgBytes = str_repeat("\x80\x40\x20\xFF", 8 * 6); // 8×6 半透明橙
$fimg = new SkiaCanvas(40, 40, null, Scale::of(1.0));
$fimg->drawImage(ImageHandle::fromRgba($imgBytes, 8, 6), null, new Rect(1.0, 1.0, 8.0, 6.0));
$opsI = skiaCallOps();
$callI = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1];
check(
    'P4 · Skia drawImage：桥收到 draw_image 且 rgba 长度 = w*h*4',
    $opsI === ['create', 'draw_image'] && $callI[1] === 192 && $callI[2] === 8 && $callI[3] === 6,
    var_export($callI, true)
);
check(
    'P4 · Skia drawImage 整幅缩放（hasSrc=false，dst 设备坐标命中 1,1,8,6）',
    $callI[12] === false && $callI[8] === 1.0 && $callI[9] === 1.0 && $callI[10] === 8.0 && $callI[11] === 6.0,
    var_export($callI, true)
);
skiaBridgeReset();

// 阴影：标量原样过桥（rect 经 toDevice，这里偏移 0 ⇒ 直接 10,10,20,20）
skiaBridgeReset();
$fsh = new SkiaCanvas(40, 40, null, Scale::of(1.0));
$fsh->drawShadow(new Shadow(new Rect(10.0, 10.0, 20.0, 20.0), Color::fromRgb(0, 0, 0), 4.0, 2.0, 3.0, 5.0));
$callSh = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1];
$expectedShadowArgb = Color::fromRgb(0, 0, 0)->toArgb();
check(
    'P4 · Skia drawShadow：桥收到标量（rect + argb + radius + 偏移 + blur 原样过桥）',
    $callSh[0] === 'draw_shadow' && $callSh[1] === 10.0 && $callSh[2] === 10.0
        && $callSh[3] === 20.0 && $callSh[4] === 20.0 && $callSh[5] === $expectedShadowArgb
        && $callSh[6] === 4.0 && $callSh[7] === 2.0 && $callSh[8] === 3.0 && $callSh[9] === 5.0,
    var_export($callSh, true)
);
skiaBridgeReset();

// 合成层 / 路径裁剪：op 顺序与参数
skiaBridgeReset();
$fly = new SkiaCanvas(40, 40, null, Scale::of(1.0));
$fly->pushLayer(0.5);
$fly->pushClipPath($pathP);
$fly->popLayer();
$opsL = skiaCallOps();
check(
    'P4 · Skia 层/路径裁剪：桥收到 push_layer → push_clip_path → pop_layer',
    $opsL === ['create', 'push_layer', 'push_clip_path', 'pop_layer'],
    implode(',', $opsL)
);
$callL = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1];
check(
    'P4 · Skia push_layer 参数（opacity=0.5 原样过桥）',
    $callL[0] === 'push_layer' && $callL[1] === 0.5,
    var_export($callL, true)
);
$callCP = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][2];
check(
    'P4 · Skia push_clip_path 打包（count=4，verbs 与 drawPath 同）',
    $callCP[0] === 'push_clip_path' && $callCP[1] === 4 && $callCP[2] === $expectedVerbs,
    var_export($callCP, true)
);
skiaBridgeReset();

// ★★ 回归闸门：路径坐标必须**叠加画布偏移并乘 dpr**（与 drawLine / toDevice 同口径）。
//   第一版照"几何走画布 translate()"的注释写 ⇒ 路径落在画布原点 ⇒ 被自绘盒裁掉
//   ⇒ 真机完全无墨，而阴影/层/图像都正常（它们各自加了偏移）。纯 PHP 缺陷，这里能抓。
skiaBridgeReset();
$foff = new SkiaCanvas(80, 80, null, Scale::of(2.0));
$foff->translate(10.0, 20.0);
$foff->drawPath(
    Path::create()->moveTo(1.0, 2.0)->lineTo(3.0, 4.0)->close(),
    Paint::fill(Color::black())
);
$callOff = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'][1];
$expectCoords = pack('f4', 22.0, 44.0, 0.0, 0.0)
    . pack('f4', 26.0, 48.0, 0.0, 0.0)
    . pack('f4', 0.0, 0.0, 0.0, 0.0);
check(
    'P4 · Skia drawPath 坐标叠加偏移并乘 dpr（(1+10)*2=22 / (2+20)*2=44）',
    $callOff[0] === 'draw_path' && $callOff[3] === $expectCoords,
    var_export($callOff[3], true)
);
skiaBridgeReset();

// Path 构造
$p19 = Path::create()->moveTo(2, 2)->lineTo(6, 2)->quadTo(8, 4, 6, 6)->close();
check('Path · 命令数与非空', $p19->commandCount() === 4 && !$p19->isEmpty());
$cmds19 = $p19->commands();
check('Path · MOVE 首命令带坐标', $cmds19[0][0] === Path::CMD_MOVE && $cmds19[0][1] === 2.0);
check('Path · QUAD 携带控制点与终点', $cmds19[2][0] === Path::CMD_QUAD && $cmds19[2][3] === 6.0 && $cmds19[2][4] === 6.0);
check('Path · addRect 四边闭合', Path::create()->addRect(new Rect(0, 0, 4, 3))->commandCount() === 5);

// Paint 的 source 正交维度（渐变/虚线是画笔属性，不是新 draw 方法）
$plain19 = Paint::fill(Color::white());
$graded19 = $plain19->withGradient(Gradient::linear(0, 0, 10, 0, [Color::white(), Color::black()]));
check('Paint · withGradient 不动原对象（值语义）', !$plain19->hasGradient() && $graded19->hasGradient());
check('Paint · withColor 保留渐变', $graded19->withColor(Color::fromRgb(255, 0, 0))->hasGradient());
$dashed19 = $plain19->withDash(new DashPattern(4.0, 2.0));
check('Paint · withDash 周期 = on+off', $dashed19->hasDash() && $dashed19->dash->period() === 6.0);
check('Paint · equals 感知渐变/虚线差异', !$graded19->equals($plain19));
check(
    'Paint · equals 对同构渐变成立（== 值语义而非实例同一）',
    $graded19->equals(Paint::fill(Color::white())->withGradient(Gradient::linear(0, 0, 10, 0, [Color::white(), Color::black()])))
);

// interning：同色 `fill()` 复用同一实例（字段全 readonly ⇒ 共享安全，`analysis/17` §6.6）
check(
    'Paint · fill() 同色 interned（同一 argb 复用实例，省每帧几十个对象）',
    Paint::fill(Color::fromRgb(0x11, 0x22, 0x33)) === Paint::fill(Color::fromRgb(0x11, 0x22, 0x33)),
);
check(
    'Paint · fill() 不同色不串（interning 的键是 argb）',
    Paint::fill(Color::fromRgb(0x11, 0x22, 0x33)) !== Paint::fill(Color::fromRgb(0x11, 0x22, 0x34)),
);
check(
    'Paint · stroke() 不进 interning（同色不同 style 不能共用实例）',
    Paint::fill(Color::fromRgb(0x11, 0x22, 0x33)) !== Paint::stroke(Color::fromRgb(0x11, 0x22, 0x33)),
);

// DisplayList 6 槽与新 opcode（LINE 是 5 操作数的命令）
$list19 = new DisplayList();
$list19->record(DisplayList::OP_DRAW_LINE, 1.0, 2.0, 3.0, 4.0, $plain19);
check('DisplayList · 6 槽 LINE 的 y2/paint 定位', $list19->count() === 1 && $list19->argAt(0, 3) === 4.0 && $list19->argAt(0, 4) === $plain19);

// W1/L3-1：drawPath 在 Raster 上从"响亮失败"翻成"真的画得出像素"
[$code, $output] = runCase(__FILE__, 'pathop', null);
check(
    'Canvas · Raster drawPath 退出码 = 0（W1/L3-1 起 CAP_PATH 已提档，不再响亮失败）',
    $code === 0,
    '实际 ' . $code . ' / ' . $output
);
preg_match('/ink=(\d+)/', $output, $pathInkMatch);
$pathInk = isset($pathInkMatch[1]) ? (int) $pathInkMatch[1] : -1;
// ★ 只断言退出码不够：画布全白也照样 exit 0。三角形解析面积 18（±3 容硬边量化）
check('Canvas · Raster drawPath 真出墨（不是"提档了但画布空"）', $pathInk >= 15 && $pathInk <= 21, 'ink=' . $pathInk);
fresh($log);

// drawImage 在 W8-1 提档：不再响亮失败，且**真出墨**（4×4 全红 = 16 像素）
[$code, $output] = runCase(__FILE__, 'imageop', null);
check(
    'Canvas · Raster drawImage 退出码 = 0（W8-1 起 CAP_IMAGE 已提档）',
    $code === 0,
    '实际 ' . $code . ' / ' . $output
);
preg_match('/ink=(\d+)/', $output, $imgInkMatch);
$imgInk = isset($imgInkMatch[1]) ? (int) $imgInkMatch[1] : -1;
check('Canvas · Raster drawImage 真出墨（4×4 = 16 像素，不是"提档了但画布空"）', $imgInk === 16, 'ink=' . $imgInk);
fresh($log);

// 仍然不支持的 Optional op：响亮失败的消息格式照旧（drawShadow 是最后一个）
[$code, $output] = runCase(__FILE__, 'shadowop', null);
check('Canvas · Raster drawShadow 退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
$msg = logText($log);
check('Canvas · 失败消息含 op 名与两条修法', str_contains($msg, 'drawShadow') && str_contains($msg, '两条修法'));
fresh($log);

[$code, $output] = runCase(__FILE__, 'gradientbad', null);
check('Gradient · 色标越界退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
fresh($log);

[$code, $output] = runCase(__FILE__, 'shadowtransparent', null);
check('Shadow · 全透明阴影退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
fresh($log);

// ── ⑩+2 19 op 回植 · P1：椭圆/线段双后端墨迹（2026-10-20）──
// ★ 夹具统一 320×240（inkBounds 写死扫这个尺寸；拿 100×100 跑会把画布外
//   的越界读当墨迹 —— PHP 越界读返回 '' ⇒ ord=0 ⇒ r=0 < 200）。
// 填充：(60,40,200,120) 实心黑 ⇒ 像素中心 px∈{60..259}、py∈{40..159}
$cE = new RasterCanvas(320, 240, Color::white(), Scale::of(1.0));
$cE->drawEllipse(new Rect(60.0, 40.0, 200.0, 120.0), Paint::fill(Color::black()));
$bE = inkBounds($cE);
check('P1 · 椭圆填充包围盒 = 外接矩形两端含', $bE !== null && $bE === [60, 259, 40, 159], var_export($bE, true));
check('P1 · 椭圆中心实心、四角空白', $cE->pixelAt(160, 100)->r === 0 && $cE->pixelAt(5, 5)->r === 255);

// 描边：居中（外扩半宽 1、内收半宽 1）⇒ 外沿 [59,260]×[39,160]，中心是洞
$cS = new RasterCanvas(320, 240, Color::white(), Scale::of(1.0));
$cS->drawEllipse(new Rect(60.0, 40.0, 200.0, 120.0), Paint::stroke(Color::black(), 2.0));
$bS = inkBounds($cS);
check('P1 · 椭圆描边外沿 = 外扩半宽', $bS !== null && $bS === [59, 260, 39, 160], var_export($bS, true));
check('P1 · 椭圆描边中心是洞（白）', $cS->pixelAt(160, 100)->r === 255);
check('P1 · 椭圆描边顶边中段有墨', $cS->pixelAt(160, 40)->r === 0);

// 线段：胶囊体谓词（半宽 1.5）—— 线上像素黑、线外像素白
$cL = new RasterCanvas(320, 240, Color::white(), Scale::of(1.0));
$cL->drawLine(40.0, 40.0, 200.0, 120.0, Paint::stroke(Color::black(), 3.0));
check('P1 · 线段中点像素有墨', $cL->pixelAt(120, 80)->r === 0);
check('P1 · 线段端头有墨', $cL->pixelAt(40, 40)->r === 0);
check('P1 · 线段外 20px 处空白', $cL->pixelAt(120, 40)->r === 255);

// DisplayList 回放路径（ELLIPSE + LINE 两个新 opcode，LINE 走 6 槽布局）
$recL = RecordingCanvas::create();
$recL->save();
$recL->translate(20.0, 20.0);
$recL->drawEllipse(new Rect(0.0, 0.0, 60.0, 60.0), Paint::fill(Color::black()));
$recL->drawLine(0.0, 0.0, 60.0, 60.0, Paint::stroke(Color::black(), 2.0));
$recL->restore();
$listL = $recL->displayList();
$listL->assertBalanced();
$cR = new RasterCanvas(320, 240, Color::white(), Scale::of(1.0));
$listL->replay($cR);
check('P1 · 回放后椭圆+线段都有墨', inkCount($cR) > 100 && $cR->pixelAt(50, 50)->r === 0);

// Skia 侧：假桥记录（ABI 形状即契约）
skiaBridgeReset();
$cK = new SkiaCanvas(100, 100, null, Scale::of(1.0));
$cK->drawEllipse(new Rect(10.0, 10.0, 80.0, 80.0), Paint::fill(Color::black()));
$cK->drawLine(5.0, 5.0, 25.0, 15.0, Paint::stroke(Color::black(), 3.0));
$opsK = skiaCallOps();
check('P1 · Skia 桥收到 draw_ellipse + draw_line', $opsK === ['create', 'draw_ellipse', 'draw_line'], implode(',', $opsK));
$callsK = $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'];
check(
    'P1 · draw_ellipse 参数（设备坐标/argb/非描边/宽 1）',
    $callsK[1] === ['draw_ellipse', 10.0, 10.0, 80.0, 80.0, Color::black()->toArgb(), false, 1.0],
    var_export($callsK[1], true)
);
check(
    'P1 · draw_line 参数（两端点设备坐标/argb/宽 3）',
    $callsK[2] === ['draw_line', 5.0, 5.0, 25.0, 15.0, Color::black()->toArgb(), 3.0],
    var_export($callsK[2], true)
);
skiaBridgeReset();

// ── ⑩+3 19 op 回植 · P2：CustomPaint 逃生舱（2026-10-20）──
// ★ 夹具统一 320×240（inkCount 按这个尺寸走）。
$ctxP = smokeContext();
$probeP = new ProbePainter(7);
$probeP->draw = true;
$cpEl = (new CustomPaint($probeP, 200.0, 120.0))->createElement();
$cpEl->mount(null, $ctxP);
$cpBox = $cpEl->effectiveBox();
$cpBox?->layout(BoxConstraints::loose(new Size(300.0, 200.0)));
$cpCustom = $cpBox instanceof RenderCustomPaint ? $cpBox : null;
check('P2 · effectiveBox() 是 RenderCustomPaint（类型接线正确）', $cpCustom !== null, get_debug_type($cpBox));
check(
    'P2 · 定尺寸 ⇒ 盒 (200,120)',
    $cpBox?->size() !== null && abs($cpBox->size()->width - 200.0) < 0.01 && abs($cpBox->size()->height - 120.0) < 0.01,
    sprintf('实际 %s', var_export($cpBox?->size(), true))
);
check('P2 · painterVersion() 直通画家内容戳（7）', $cpCustom !== null && $cpCustom->painterVersion() === 7);

$probeFill = new ProbePainter();
$cpFillEl = (new CustomPaint($probeFill, 160.0))->createElement();
$cpFillEl->mount(null, $ctxP);
$cpFillBox = $cpFillEl->effectiveBox();
$cpFillBox?->layout(BoxConstraints::loose(new Size(300.0, 200.0)));
check(
    'P2 · 未定的轴吃满约束上限（高 200 不是 0 —— 0 会静默不画）',
    $cpFillBox?->size() !== null && abs($cpFillBox->size()->width - 160.0) < 0.01
        && abs($cpFillBox->size()->height - 200.0) < 0.01,
    sprintf('实际 %s', var_export($cpFillBox?->size(), true))
);
check(
    'P2 · 未给定的轴自然尺寸 = 0（Row/Dock 量自然宽时不猜；要参与就给值）',
    $cpFillBox !== null && $cpFillBox->intrinsicHeight(0.0) === 0.0,
    sprintf('内禀高 %.6g', $cpFillBox?->intrinsicHeight(0.0) ?? -1.0)
);

$probeTight = new ProbePainter();
$cpTightEl = (new CustomPaint($probeTight, 200.0, 120.0))->createElement();
$cpTightEl->mount(null, $ctxP);
$cpTightBox = $cpTightEl->effectiveBox();
$cpTightBox?->layout(BoxConstraints::tight(new Size(150.0, 100.0)));
check(
    'P2 · 父更紧 ⇒ 听父（200 夹成 150，约束是父给的）',
    $cpTightBox?->size() !== null && abs($cpTightBox->size()->width - 150.0) < 0.01
        && abs($cpTightBox->size()->height - 100.0) < 0.01,
    sprintf('实际 %s', var_export($cpTightBox?->size(), true))
);

$cP = new RasterCanvas(320, 240, Color::white(), Scale::of(1.0));
$cpCustom?->paint($cP);
check(
    'P2 · paint 调画家一次且左上 40×40 有墨（画家直绘 Core 层图元）',
    $probeP->calls === 1 && inkCount($cP) > 100 && $cP->pixelAt(20, 20)->r === 0,
    sprintf('calls=%d ink=%d', $probeP->calls, inkCount($cP))
);
check(
    'P2 · 画家收到的尺寸 = 盒尺寸（画布已 translate 到盒原点）',
    $probeP->seen !== null && abs($probeP->seen->width - 200.0) < 0.01 && abs($probeP->seen->height - 120.0) < 0.01,
    sprintf('画家收到 %s', var_export($probeP->seen, true))
);

fresh($log);
[$code, $output] = runCase(__FILE__, 'custompaint', null);
check('P2 · CustomPaint 未定轴 + 无限约束退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
$msgP = logText($log);
check(
    'P2 · 失败消息点名 CustomPaint、带两条修法、不泄露内部编号',
    str_contains($msgP, 'CustomPaint') && str_contains($msgP, '两条修法') && !str_contains($msgP, 'C6'),
    $msgP
);
fresh($log);
// ⑪ Text：六层栈 + 确定性参考实现（§8 / §14.2）
fresh($log);
$fontManager = DeterministicFontManager::standard();
$registry = $fontManager->collection();
$textMetrics = new DeterministicMetricsProvider($registry, new DeterministicShaper());
$unit = Scale::of(1.0);
$body = TextStyle::of(14.0);
$latinFace = $fontManager->faces()[0];
$cjkFace = $fontManager->faces()[1];

check('Text · FontManager 在任何绘制前就绪（§8.3 血账 1）', $fontManager->isReady() && count($fontManager->faces()) === 2);
check(
    'Text · 两个 face 的归一化度量逐字段相同（行高不随字形漂移）',
    $latinFace->metrics()->equals($cjkFace->metrics())
);

// 回退链：按能力选 face，链上都不含才退兜底
check('Text · 逻辑家族的回退链 = 拉丁 + CJK（能力顺序）', count($registry->resolve(FontFamily::sans(), FontWeight::Regular, FontSlant::Normal)) === 2);
check('Text · 未注册的逻辑家族退默认链（落地名归平台配置）', count($registry->resolve(FontFamily::serif(), FontWeight::Regular, FontSlant::Normal)) === 2);
check('Text · 显式家族名找不到就是没有', $registry->resolve(FontFamily::named('NoSuch'), FontWeight::Regular, FontSlant::Normal) === []);
check('Text · 回退链按能力选 face：拉丁', $registry->faceFor(0x41, FontFamily::sans(), FontWeight::Regular, FontSlant::Normal) === $latinFace);
check('Text · 回退链按能力选 face：CJK', $registry->faceFor(0x4E2D, FontFamily::sans(), FontWeight::Regular, FontSlant::Normal) === $cjkFace);
$notdef = $registry->faceFor(0x1F600, FontFamily::sans(), FontWeight::Regular, FontSlant::Normal);
check('Text · 链上都不含 ⇒ 兜底 face（布局不塌、但不画方框）', !$notdef->hasGlyph(0x1F600) && $notdef->glyphAdvance(0x1F600) === 1.0 && $notdef->rasterize(0x1F600, 16.0) === null);

// 行高（§8.2 坑 3）
$hello = $textMetrics->layout('Hello', $body, INF, $unit);
check('Text · 行高 = (lineGap + (ascent+descent) × lineHeight) × size', abs($hello->lines[0]->height - 16.8) < 1e-9, (string) $hello->lines[0]->height);
check('Text · INF 不限宽 ⇒ 不折行', $hello->lineCount() === 1);

// 断行（§13.1 C5 的溢出契约）
$latinWrapped = $textMetrics->layout('Hello Drift', $body, 40.0, $unit);
check(
    'Text · 拉丁整词不被劈开（放不下就溢出，不静默折断）',
    $latinWrapped->lineCount() === 2
        && substr('Hello Drift', $latinWrapped->lines[0]->textStart, $latinWrapped->lines[0]->textLength) === 'Hello',
    (string) $latinWrapped->lineCount()
);
// 期望值 55 → 45 是 §24.2 #20 的步进语义变化（拉丁 0.75em → 0.625em），不是回归
// 45 → 35：拉丁 advance 由固定 0.625em 改为真字体值（§24.2 #21）
check('Text · 行尾空白被修剪（不参与宽度）', $latinWrapped->lines[0]->width === 35.0, (string) $latinWrapped->lines[0]->width);
check('Text · CJK 按字边界断行', $textMetrics->layout('中文断行测试', $body, 30.0, $unit)->lineCount() === 3);

// 禁则（§8.3 对策 ②）：闭标点不落行首
$rule = '中文，测试。';
$ruleWrapped = $textMetrics->layout($rule, $body, 34.0, $unit);
$lineHeads = [];
foreach ($ruleWrapped->lines as $line) {
    $lineHeads[] = substr($rule, $line->textStart, 3);
}
check(
    'Text · 禁则：闭标点不落行首',
    !in_array('，', $lineHeads, true) && !in_array('。', $lineHeads, true),
    implode(' / ', $lineHeads)
);

// 空文本 / 硬换行 / 省略号
$empty = $textMetrics->layout('', $body, INF, $unit);
check('Text · 空文本仍有一行高（不塌成 0）', $empty->lineCount() === 1 && $empty->height > 0.0);
check('Text · 硬换行按 \\n 断', $textMetrics->layout("a\nb\nc", $body, INF, $unit)->lineCount() === 3);

$clamped = $textMetrics->layout('Hello Drift Framework', $body->withMaxLines(2)->withEllipsize(true), 60.0, $unit);
$lastLine = $clamped->lines[$clamped->lineCount() - 1];
$lastRun = $lastLine->runs[count($lastLine->runs) - 1];
$lastGlyph = $lastRun->glyphs[count($lastRun->glyphs) - 1];
check('Text · 省略号：行数被 maxLines 夹住', $clamped->lineCount() === 2, (string) $clamped->lineCount());
check('Text · 省略号：末行以 U+2026 结尾', $lastGlyph->codepoint === TextLayout::ELLIPSIS);
check('Text · 省略号：末行宽度不超 maxWidth', $lastLine->width <= 60.0, (string) $lastLine->width);

// 缓存归实现内部，键 = (text, style, maxWidth, scale)（§7.1 / §8.1）
$probe = new DeterministicMetricsProvider($registry, new DeterministicShaper());
$probe->layout('Cache Probe', $body, INF, $unit);
$probe->layout('Cache Probe', $body, INF, $unit);
check('Text · 同键复用（不重复排版）', $probe->cachedCount() === 1, (string) $probe->cachedCount());
$probe->layout('Cache Probe', $body->withSize(15.0), INF, $unit);
$probe->layout('Cache Probe', $body, 100.0, $unit);
$probe->layout('Cache Probe', $body, INF, Scale::of(1.5));
$probe->layout('Cache Probe', $body->withAlign(TextAlign::Center), INF, $unit);
$probe->layout('Cache Probe Other', $body, INF, $unit);
check('Text · 键的四个分量都参与（text/style/maxWidth/scale）', $probe->cachedCount() === 6, (string) $probe->cachedCount());

// 光标唯一入口 / advance
check('Text · indexAt 落在正确的码位', $hello->indexAt(12.0, 8.0) === 1);
check('Text · advance() 与单行 layout 宽度一致', abs($textMetrics->advance('Hello', $body, $unit) - $hello->width) < 1e-9);

// 光栅：位图即 em 盒，未覆盖不画方框
$bitmap = $latinFace->rasterize(0x41, 14.0);
$ink = 0;
for ($y = 0; $y < $bitmap->height; $y++) {
    for ($x = 0; $x < $bitmap->width; $x++) {
        if ($bitmap->coverageAt($x, $y) > 0) {
            $ink++;
        }
    }
}
// 宽 9 → 8：'A' 的位图宽 = round(advance × 14/32)，advance 取自真字体（§24.2 #21）
check('Text · 位图尺寸随 pixelSize 缩放', $bitmap->width === 8 && $bitmap->height === 14, $bitmap->width . 'x' . $bitmap->height);
check('Text · 基线上方 = ascent × pixelSize（位图即 em 盒）', $bitmap->bearingY === -12, (string) $bitmap->bearingY);
check('Text · 位图有墨', $ink > 0);
check('Text · 未覆盖码位不画方框', $latinFace->rasterize(0x4E2D, 14.0) === null);

// ── 真字体度量面（TtfFace，2026-10-17）──
//    ★ 判据必须**能区分 TTF 与位图**：中文表外字两边都是 1.0em（分不出来），
//      所以取 'Ω'（TTF 0.758 / notdef 猜 0.5）与 '—'（TTF 0.894 / notdef 猜 0.5）。

$fontPath = FontLocator::find('NotoSansSC-Regular.ttf');
check('Text · 字体文件能定位（分发形态：fonts/ 或 cpp/fonts）', $fontPath !== null, (string) $fontPath);

$ttfFace = $fontPath === null ? null : TtfFace::fromFile($fontPath, [$latinFace, $cjkFace]);
check(
    'Text · TtfFace 从文件读出（upem 1000，不是空壳）',
    $ttfFace instanceof TtfFace && $ttfFace->unitsPerEm() === 1000,
    $ttfFace === null ? 'no face' : (string) $ttfFace->unitsPerEm(),
);
check(
    'Text · 行度量取 OS/2 sTypo（0.88 / 0.12），不是 hhea 的 1.448em',
    $ttfFace !== null
        && abs($ttfFace->metrics()->ascent - 0.88) < 1e-9
        && abs($ttfFace->metrics()->descent - 0.12) < 1e-9,
    $ttfFace === null ? 'no face' : sprintf('%.4f / %.4f', $ttfFace->metrics()->ascent, $ttfFace->metrics()->descent),
);
check(
    'Text · 步进来自 hmtx（Ω = 0.758em —— notdef 会猜 0.5）',
    $ttfFace !== null && abs($ttfFace->glyphAdvance(0x03A9) - 0.758) < 1e-9,
    $ttfFace === null ? 'no face' : (string) $ttfFace->glyphAdvance(0x03A9),
);
check(
    'Text · 覆盖判定查真字体 cmap（表外字「馈」有、emoji 无）',
    $ttfFace !== null && $ttfFace->hasGlyph(0x9988) && !$ttfFace->hasGlyph(0x1F600),
);
check(
    'Text · 字形委托位图面（TTF 有 + 位图也有 ⇒ 画得出）',
    $ttfFace !== null && $ttfFace->rasterize(0x4E2D, 14.0) !== null,
);
//    ★★ **判据字符必须"位图永远不会收录"**：扫描口径 = 所有 cp≥0xA0 的**字符串字面量**
//       ⇒ 任何写进夹具名/诊断串的字符，下一次重跑生成链就被补进位图 —— 'Ω' 就这样
//       假绿过一次（本轮加 Card 夹具时重跑生成链，Ω 进了位图，这条当场 FAIL）。
//       ⇒ 用**运行时构造的码点**（纯 int，不落进任何字面量），扫描器看不见它，判据才稳定。
$ttfOnlyCodepoint = 0x0416;   // 西里尔大写 Zhe：真字体有、位图从未收录（实测 ttf=true bitmap=false）
check(
    'Text · TTF 有但位图没有 ⇒ 不画（不静默画方框）',
    $ttfFace !== null
        && $ttfFace->hasGlyph($ttfOnlyCodepoint)
        && $ttfFace->rasterize($ttfOnlyCodepoint, 14.0) === null,
    $ttfFace === null ? 'no ttf' : sprintf('hasGlyph=%s', var_export($ttfFace->hasGlyph($ttfOnlyCodepoint), true)),
);
check(
    'Text · FontLocator 找不到 ⇒ null（不抛、不猜）',
    FontLocator::find('NoSuchFont.ttf', ['/nonexistent-dir']) === null,
);

// —— 集成：Application::metrics() 真的接了真字体（不是静默退回位图）
$ttfApp = new ModalApplication();
$ttfAdvance = $ttfApp->metrics()->advance('Ω', $body, $unit);
//    ★ 期望值要按**量化后**写：`DeterministicShaper` 把步进 round 到设备像素
//      （§8.2 坑 4），0.758 × 14 = 10.612 ⇒ 11。notdef 对 Ω 猜 0.5em ⇒ 7 —— 两者可分。
check(
    'Text · Application::metrics() 已接真字体（Ω 宽 11px，不是 notdef 猜的 7px）',
    abs($ttfAdvance - 11.0) < 1e-9,
    sprintf('%.4f（notdef 会是 7.0）', $ttfAdvance),
);

// ── 降采样灰度（§24.2 #17）：小字抗锯齿不倒在函数出口 ──
// pixelSize < unitsPerEm(16) ⇒ 降采样路径。判据：coverage 值域必须**超出** {0,255}
// ——0<v<255 的中间值就是 blend() 的 alpha。反例自检天然：本修复前全页 0/255 两值，
// 这两条 FAIL（去阈值化即绿）。灰阶像素数作诊断串。
$grayFace = $registry->faceFor(0x4E2D, FontFamily::sans(), FontWeight::Regular, FontSlant::Normal);
$grayBmp = $grayFace->rasterize(0x4E2D, 13.0);
$grayValues = [];
for ($i = 0; $i < strlen($grayBmp->coverage); $i++) {
    $grayValues[ord($grayBmp->coverage[$i])] = true;
}
$grayMid = 0;
foreach ($grayValues as $v => $_) {
    if ($v > 0 && $v < 255) {
        $grayMid++;
    }
}
check(
    'Text · 降采样产出连续灰度（0<coverage<255 存在，不二值化）',
    $grayMid > 0,
    '不同值=' . count($grayValues) . ' 中间灰度值=' . $grayMid
);
check('Text · 降采样尺寸仍随 pixelSize 缩放（灰度不改几何）', $grayBmp->width === 13 && $grayBmp->height === 13, $grayBmp->width . 'x' . $grayBmp->height);

// 端到端：灰度字模经 blendBitmap 上屏 ⇒ 画布出现"非字色非底色"的混合像素。
// 这条钉**消费链**（blend 真的按 alpha 混合了），不是钉实现。
$grayCanvas = new RasterCanvas(24, 24, null, $unit);
$grayCanvas->clear(Color::fromRgb(255, 255, 255));
$grayPara = $textMetrics->layout('中', $body, INF, $unit);
$grayCanvas->drawParagraph($grayPara, Paint::fill(Color::fromRgb(0, 0, 0)), 2.0, 2.0);
$grayPixels = 0;
for ($gy = 0; $gy < $grayCanvas->height(); $gy++) {
    for ($gx = 0; $gx < $grayCanvas->width(); $gx++) {
        $gp = $grayCanvas->pixelAt($gx, $gy);
        if ($gp->r > 0 && $gp->r < 255) {
            $grayPixels++;
        }
    }
}
check('Text · 端到端：黑字白底出现混合像素（blend 按覆盖率插值）', $grayPixels > 0, '混合像素=' . $grayPixels);

// 响亮失败：未覆盖码位问步进留痕；家族无 face / 解析字体文件 ⇒ Fatal
fresh($log);
$latinFace->glyphAdvance(0x4E2D);
$ring = Errors::recentDiagnostics();
check('Text · 未覆盖码位问步进 ⇒ 留痕不静默', $ring !== [] && str_contains($ring[count($ring) - 1], 'U+4E2D'));

fresh($log);
[$code, $output] = runCase(__FILE__, 'font', null);
check('Text · 家族无可用 face 退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Text · 家族无可用 face 写了 Errors.log', str_contains(logText($log), '没有可用的字体面'));

fresh($log);
[$code, $output] = runCase(__FILE__, 'fontfile', null);
check('Text · M0 不解析字体文件 ⇒ 响亮失败', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Text · 未实现就说是未实现（不静默忽略注册）', str_contains(logText($log), '不解析字体文件'));

// ⑫ 文字上屏：段落绘制命令 → 字形位图上屏（§7.2 / §8.2 坑 2）
fresh($log);
$black = Color::fromRgb(0, 0, 0);
$textScale = Scale::of(1.0);
$textParagraph = $textMetrics->layout('Hello', $body, INF, $textScale);
$textList = new DisplayList();
(new RecordingCanvas($textList))->drawParagraph($textParagraph, Paint::fill($black), 0.0, 0.0);
check('Rendering · 段落录制成一条命令', $textList->count() === 1 && $textList->opAt(0) === DisplayList::OP_DRAW_PARAGRAPH);

$textCanvas = new RasterCanvas(60, 20, Color::white(), $textScale);
$textList->replay($textCanvas);
$ink = inkCount($textCanvas);
check('Rendering · 文字真的画上了（有墨）', $ink > 0, (string) $ink);
check('Rendering · 文字落在基线之上（首行顶部仍是背景）', $textCanvas->pixelAt(0, 0)->equals(Color::white()));

// G3：同一份 DisplayList 重放 ⇒ 逐字节同一张位图
$repeated = new RasterCanvas(60, 20, Color::white(), $textScale);
$textList->replay($repeated);
check('Rendering · 段落重放确定（逐字节相同）', $textCanvas->toRgba() === $repeated->toRgba());

// 设备分辨率光栅：dpr 翻倍 ⇒ 墨的像素数变多（不是把 dpr=1 的位图放大）
$hidpi = new RasterCanvas(120, 40, Color::white(), Scale::of(2.0));
$textList->replay($hidpi);
check('Rendering · 字形在设备分辨率光栅（dpr=2 墨更多）', inkCount($hidpi) > $ink * 2, inkCount($hidpi) . ' vs ' . $ink);

// 裁剪对文本一样生效
$clipList = new DisplayList();
$clipRecorder = new RecordingCanvas($clipList);
$clipRecorder->pushClip(new Rect(0.0, 0.0, 8.0, 20.0));
$clipRecorder->drawParagraph($textParagraph, Paint::fill($black), 0.0, 0.0);
$clipRecorder->popClip();
$clipCanvas = new RasterCanvas(60, 20, Color::white(), $textScale);
$clipList->replay($clipCanvas);
$clippedInk = inkCount($clipCanvas);
check('Rendering · 裁剪对文本生效（区内画、区外挡）', $clippedInk > 0 && $clippedInk < $ink, $clippedInk . ' vs ' . $ink);

// 没有位图的字形（兜底 face）不画 —— 不是画一个方框顶上
$blankList = new DisplayList();
$blankParagraph = $textMetrics->layout("\u{1F600}", $body, INF, $textScale);
(new RecordingCanvas($blankList))->drawParagraph($blankParagraph, Paint::fill($black), 0.0, 0.0);
$blankCanvas = new RasterCanvas(32, 20, Color::white(), $textScale);
$blankList->replay($blankCanvas);
check('Rendering · 无位图的字形不上屏（不画方框顶替）', inkCount($blankCanvas) === 0, (string) inkCount($blankCanvas));

// 覆盖度即 alpha：半透明色画出的是灰，不是黑
$faded = new RasterCanvas(60, 20, Color::white(), $textScale);
$fadeList = new DisplayList();
(new RecordingCanvas($fadeList))->drawParagraph($textParagraph, Paint::fill(Color::fromRgba(0, 0, 0, 64)), 0.0, 0.0);
$fadeList->replay($faded);
check('Rendering · 覆盖度 × 颜色 alpha 走同一条混合（半透明 ⇒ 灰）', $faded->pixelAt(1, 4)->r > $textCanvas->pixelAt(1, 4)->r);

// ⑪ Key：跨位置稳定的身份（§12.3）
fresh($log);
$keyProbe = new SealProbe();
check('Key · 默认无 Key ⇒ 身份由位置决定', $keyProbe->identity() === null);
$keyProbe->key(7);
check('Key · key() 之后拿到值对象', $keyProbe->identity()?->value === 7);
check('Key · 同值即同一身份', Key::same(new Key(7), new Key(7)));
check('Key · 异值即不同身份', !Key::same(new Key(7), new Key(8)));
check('Key · 1 与 "1" 不等（不做类型互转）', !Key::same(new Key(1), new Key('1')));
check('Key · 双方都没有 ⇒ 可复用（位置相同）', Key::same(null, null));
check('Key · 一方有一方没有 ⇒ 不复用', !Key::same(new Key(1), null));
check('Key · key() 走 mutate()（返回同一实例）', $keyProbe->key(7) === $keyProbe);

// ⑪-b 子表配对的**复用语义**与**复杂度**（2026-10-27）
//   ★ 复用语义用"元素实例是不是同一个"断言；复杂度用 `Element::pairChecks()`
//     —— 后者是"退回平方扫描"的**唯一可观察症状**（行为全对、只是慢）。
$mountColumn = static function (Column $column): Element {
    $element = $column->createElement();
    $element->mount(null, smokeContext());

    return $element;
};

// 顺序不变 ⇒ 走前缀同步：200 子只做 200 次配对（旧实现是 20,100 次）
$kidsOld = [];
$kidsNew = [];
for ($i = 0; $i < 200; $i++) {
    $kidsOld[] = Text::make('n' . $i);
    $kidsNew[] = Text::make('n' . $i);   // 新实例：真实 build 每帧都造新 Widget
}
$pairRoot = $mountColumn(Column::make()->children($kidsOld));
$kidFirst = $pairRoot->childAt(0);
$kidLast = $pairRoot->childAt(199);
$checksBefore = Element::pairChecks();
$pairRoot->update(Column::make()->children($kidsNew));
$checksDelta = Element::pairChecks() - $checksBefore;
check(
    'Element · 顺序不变的 200 子 ⇒ 配对次数 = 子数（不是平方）',
    $checksDelta === 200,
    'delta=' . $checksDelta . '（旧实现 20100）',
);
check(
    'Element · 顺序不变的 200 子 ⇒ 元素全部复用（首尾同一实例）',
    $pairRoot->childAt(0) === $kidFirst && $pairRoot->childAt(199) === $kidLast,
);

// 跨位置搬家（带 Key）⇒ 元素跟着 Key 走 —— 走的是第二段（建桶）
$pairRoot = $mountColumn(Column::make()->children([
    Text::make('a')->key(1),
    Text::make('b')->key(2),
]));
$kidA = $pairRoot->childAt(0);
$kidB = $pairRoot->childAt(1);
$pairRoot->update(Column::make()->children([
    Text::make('b')->key(2),
    Text::make('a')->key(1),
]));
check(
    'Element · 带 Key 换序 ⇒ 元素跟着 Key 走（0 号是原来的 1 号）',
    $pairRoot->childAt(0) === $kidB && $pairRoot->childAt(1) === $kidA,
);

// 中间删一个 ⇒ 前缀同步只覆盖前半段，后半段由桶接手
$pairRoot = $mountColumn(Column::make()->children([
    Text::make('a')->key(1),
    Text::make('b')->key(2),
    Text::make('c')->key(3),
]));
$kidC = $pairRoot->childAt(2);
$kidB = $pairRoot->childAt(1);
$pairRoot->update(Column::make()->children([
    Text::make('a')->key(1),
    Text::make('c')->key(3),
]));
check(
    'Element · 中间删一个 ⇒ 后面的元素上移复用、被删的那个已卸载',
    $pairRoot->childCount() === 2
        && $pairRoot->childAt(1) === $kidC
        && !$kidB->isMounted(),
    'count=' . $pairRoot->childCount(),
);

// 重复 Key ⇒ 按**旧表升序**取槽位（与旧实现逐字相同的语义）
//   ★ 首子换成另一种类型 ⇒ 前缀同步在 0 号就断，**强制走建桶那条路**
//     （否则同类同键的两个子会被前缀同步按位置配掉，桶根本没被走到）。
$pairRoot = $mountColumn(Column::make()->children([
    Text::make('a')->key(9),
    Text::make('b')->key(9),
]));
$kid9a = $pairRoot->childAt(0);
$kid9b = $pairRoot->childAt(1);
$pairRoot->update(Column::make()->children([
    SizedBox::make(),
    Text::make('c')->key(9),
    Text::make('d')->key(9),
]));
check(
    'Element · 重复 Key ⇒ 按旧表升序取槽位（前缀断了也升序）',
    $pairRoot->childAt(1) === $kid9a && $pairRoot->childAt(2) === $kid9b,
    'c1=' . ($pairRoot->childAt(1) === $kid9a ? 'a' : 'b') . ' c2=' . ($pairRoot->childAt(2) === $kid9b ? 'b' : 'a'),
);

// Key 类型不互转：`1` 与 `'1'` 分属两个桶（桶键带类型前缀）
$pairRoot = $mountColumn(Column::make()->children([Text::make('a')->key(1)]));
$kidInt = $pairRoot->childAt(0);
$pairRoot->update(Column::make()->children([Text::make('a')->key('1')]));
check(
    'Element · Key 1 与 "1" 不同桶 ⇒ 不复用（元素换新）',
    $pairRoot->childAt(0) !== $kidInt,
);

// 类型变了 ⇒ 换新元素，旧元素卸载
$pairRoot = $mountColumn(Column::make()->children([Text::make('a')]));
$kidText = $pairRoot->childAt(0);
$pairRoot->update(Column::make()->children([SizedBox::make()]));
check(
    'Element · 类型变了 ⇒ 换新元素且旧元素已卸载',
    $pairRoot->childAt(0) !== $kidText && !$kidText->isMounted(),
);

fresh($log);
[$code, $output] = runCase(__FILE__, 'key', null);
check('Key · 封存后再给 Key 退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Key · 封存后再给 Key 写了 Errors.log', str_contains(logText($log), 'Widget 已封存'));

// ⑫ 盒样式：值对象 + 中间基类（§15.7）
fresh($log);
$boxProbe = new BoxProbe();
check('BoxWidget · 不用样式 ⇒ 不分配 BoxStyle（零成本）', $boxProbe->box() === null);
// ★★ W5 起 `background()` 是**所有控件**的修饰（arch-v2 `02` §11），不再是
//   `BoxWidget` 专属。边界从"有没有这个方法"变成**分道**：`BoxWidget` 子类写
//   `BoxStyle`（控件自带的整套样式，含主题派生默认值），裸 `Widget` 写 `mods`
//   （给任意控件贴一层装饰）。两套存储刻意不合并。
$plainProbe = new SealProbe();
check(
    'BoxWidget · 修饰分道：裸 Widget 也有 background()',
    method_exists(SealProbe::class, 'background') && !is_subclass_of(SealProbe::class, BoxWidget::class)
);
$plainProbe->background(Color::white());
check('BoxWidget · 修饰分道：裸 Widget 的 background() 进 mods', $plainProbe->mods()->hasVisual());

$boxProbe->background(Color::white());
check('BoxWidget · background 落进 BoxStyle', $boxProbe->box()?->hasBackground() === true);
check(
    'BoxWidget · 修饰分道：BoxWidget 子类的 background() 不进 mods',
    $boxProbe->mods()->hasVisual() === false
);
$boxProbe->border(Color::black(), 2.0);
check(
    'BoxWidget · 链式叠加是整体替换，不丢前一项',
    $boxProbe->box()?->hasBackground() === true && $boxProbe->box()?->borderWidth === 2.0
);
$boxProbe->radius(8.0);
check('BoxWidget · radius 独立改写', $boxProbe->box()?->radius === 8.0);
check('BoxWidget · 链式返回自身（不产生新实例）', $boxProbe->radius(4.0) === $boxProbe);

// ⑫b 修饰是字段（arch-v2 `02` §11，M2-1）—— 值对象 + 15 个 Widget setter
$modProbe = new SealProbe();
$mods0 = $modProbe->mods();
check(
    'Modifier · 默认：无内距 / 无视觉 / 无合成 / 尺寸与 basis 未设',
    !$mods0->hasPadding() && !$mods0->hasVisual() && !$mods0->hasCompose()
        && \is_nan($mods0->flexBasis())
);
check('Modifier · 每个 Widget 都有（构造器里建好，永远非空）', $mods0 instanceof Modifier);

$modProbe->paddingAll(8.0);
check(
    'Modifier · paddingAll 写四边',
    $modProbe->mods()->padLeft() === 8.0 && $modProbe->mods()->padTop() === 8.0
        && $modProbe->mods()->padRight() === 8.0 && $modProbe->mods()->padBottom() === 8.0
);
check(
    'Modifier · 值对象换新：旧对象不动',
    $mods0->hasPadding() === false && $modProbe->mods()->hasPadding()
);
$modProbe->paddingOnly(null, null, 2.0);
check(
    'Modifier · paddingOnly 只动给了的那一边',
    $modProbe->mods()->padLeft() === 8.0 && $modProbe->mods()->padRight() === 2.0
);

// 尺寸：NAN = 未设，与"设为 0"必须是两件事（`02` §3.2）
$sizeProbe = new SealProbe();
$sizeProbe->boxSize(120.0);
$loose = new BoxConstraints(0.0, 400.0, 0.0, 300.0);
$tightW = $sizeProbe->mods()->tighten($loose);
check(
    'Modifier · boxSize 只定宽 ⇒ 宽变紧、高透传',
    $tightW->minWidth === 120.0 && $tightW->maxWidth === 120.0
        && $tightW->minHeight === 0.0 && $tightW->maxHeight === 300.0
);
$tightNarrow = $sizeProbe->mods()->tighten(new BoxConstraints(0.0, 50.0, 0.0, 300.0));
check(
    'Modifier · 父更紧时听父的（不造 min > max）',
    $tightNarrow->minWidth === 50.0 && $tightNarrow->maxWidth === 50.0
);
$zeroProbe = new SealProbe();
$zeroProbe->boxSize(0.0, 0.0);
$tightZero = $zeroProbe->mods()->tighten($loose);
check(
    'Modifier · boxSize(0) 是"0 尺寸"，不是"未设"',
    $tightZero->minWidth === 0.0 && $tightZero->maxWidth === 0.0
        && $tightZero->minHeight === 0.0 && $tightZero->maxHeight === 0.0
);

$m8 = (new Modifier())->withPadding(8.0, 4.0, 8.0, 4.0);
$deflated = $m8->deflate(new BoxConstraints(0.0, 100.0, 0.0, 100.0));
check('Modifier · deflate 减内边距', $deflated->maxWidth === 84.0 && $deflated->maxHeight === 92.0);
$inflated = $m8->inflate(new Size(84.0, 92.0));
check('Modifier · inflate 加内边距', $inflated->width === 100.0 && $inflated->height === 100.0);

$modProbe->opacity(0.5)->translate(3.0, -2.0);
check(
    'Modifier · opacity / translate 进合成维',
    $modProbe->mods()->hasCompose()
        && $modProbe->mods()->composeOpacity() === 0.5
        && $modProbe->mods()->composeDx() === 3.0
        && $modProbe->mods()->composeDy() === -2.0
);

$modProbe->grow(2)->shrink(1)->basis(40.0)->alignSelf(RenderBox::ALIGN_CENTER);
check(
    'Modifier · 弹性意图四项（Expanded / Flexible 的字段形态）',
    $modProbe->mods()->flexGrow() === 2 && $modProbe->mods()->flexShrink() === 1
        && $modProbe->mods()->flexBasis() === 40.0
        && $modProbe->mods()->flexAlign() === RenderBox::ALIGN_CENTER
);

// ★★ 合成初值落到盒上。
$flexKeep = new RenderProxy();
$flexKeep->setMods((new Modifier())->withOpacity(0.25));
check('Modifier · setMods 把合成初值落到盒上', $flexKeep->composeOpacity() === 0.25);

// ★★ M2-1 时这条是**反向**的（"setMods 不能清掉 `RenderFlexible` 的份额"）——
//   那时份额住在 `RenderFlexible` 的构造器里，按 mods 默认值再写一次会清零、
//   `Expanded` 全失效且不报错。M2-4 删掉那个类之后，`mods.grow` 成了份额的
//   **唯一**来源 ⇒ 断言反转：`setMods()` 必须把份额写到盒上。
$flexKeep->setMods((new Modifier())->withGrow(2)->withShrink(1)->withBasis(40.0)->withAlign(RenderBox::ALIGN_CENTER));
check(
    'Modifier · setMods 把 mods 的弹性意图写到盒上（M2-4 起份额只有一个来源）',
    $flexKeep->grow() === 2 && $flexKeep->shrink() === 1
        && $flexKeep->basis() === 40.0 && $flexKeep->alignSelf() === RenderBox::ALIGN_CENTER,
    sprintf('grow=%d shrink=%d basis=%.1f align=%d', $flexKeep->grow(), $flexKeep->shrink(), $flexKeep->basis(), $flexKeep->alignSelf())
);
$flexKeep->setMods(new Modifier());
check(
    'Modifier · mods 里没有份额 ⇒ 盒上的份额归零（没有第二个来源可以"留着"）',
    $flexKeep->grow() === 0 && \is_nan($flexKeep->basis())
);

$plainBox = BoxStyle::none()->withBackground(Color::white());
$roundedBox = $plainBox->withRadius(4.0);
check(
    'BoxStyle · with- 返回新值，原值不变',
    $plainBox->radius === 0.0 && $roundedBox->radius === 4.0 && $roundedBox->hasBackground()
);

fresh($log);
[$code, $output] = runCase(__FILE__, 'box', null);
check('BoxWidget · 封存后改样式退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('BoxWidget · 封存后改样式写了 Errors.log', str_contains(logText($log), 'Widget 已封存'));

// ⑬ 主题：角色 → 值的一次解析（§11.5 / §15.7）
fresh($log);
$lightTheme = ThemeData::light();
$darkTheme = ThemeData::dark();

$bothModes = true;
foreach (ColorToken::cases() as $token) {
    if (!$token->lightValue() instanceof Color || !$token->darkValue() instanceof Color) {
        $bothModes = false;
    }
}
check('Theme · 每个颜色角色明暗都有值 ⇒ 换肤只换值不换键', $bothModes);
check(
    'Theme · 正文与底色在明暗下都反相（浅底深字 / 深底浅字）',
    luminance($lightTheme->color(ColorToken::Text)) < luminance($lightTheme->color(ColorToken::Pane))
        && luminance($darkTheme->color(ColorToken::Text)) > luminance($darkTheme->color(ColorToken::Pane))
);
check(
    'Theme · 暗色表把强调色提亮（深蓝压深灰 ⇒ 看不清）',
    luminance($darkTheme->color(ColorToken::Accent)) > luminance(ColorToken::Accent->lightValue())
);
check(
    'Theme · 度量与明暗无关（只有一张表）',
    $lightTheme->metric(MetricToken::FontSize) === $darkTheme->metric(MetricToken::FontSize)
);
check(
    'Theme · 语义角色字号在表里 ⇒ 换肤不改调用点',
    $lightTheme->metric(MetricToken::FontSizeTitle) > $lightTheme->metric(MetricToken::FontSize)
);

$accentTheme = $lightTheme->withColor(ColorToken::Accent, Color::black());
check(
    'Theme · withColor 返回新实例，原主题不变',
    $accentTheme !== $lightTheme
        && $accentTheme->color(ColorToken::Accent)->equals(Color::black())
        && $lightTheme->color(ColorToken::Accent)->equals(ColorToken::Accent->lightValue())
);
check(
    'Theme · withMetric 覆盖生效且原值不变',
    $lightTheme->withMetric(MetricToken::Gap, 20.0)->metric(MetricToken::Gap) === 20.0
        && $lightTheme->metric(MetricToken::Gap) === 8.0
);

$highContrast = $lightTheme->withHighContrast();
check(
    'Theme · 高对比度：边框对底色的对比度真的更高（不是标了个名）',
    contrast($highContrast->color(ColorToken::Border), $highContrast->color(ColorToken::Pane))
        > contrast($lightTheme->color(ColorToken::Border), $lightTheme->color(ColorToken::Pane)),
    sprintf(
        '%.2f vs %.2f',
        contrast($highContrast->color(ColorToken::Border), $highContrast->color(ColorToken::Pane)),
        contrast($lightTheme->color(ColorToken::Border), $lightTheme->color(ColorToken::Pane))
    )
);
check(
    'Theme · 高对比度跳过没有 HC 形态的角色（Hover 保持原值，不与 Pressed 撞车）',
    $highContrast->color(ColorToken::Hover)->equals($lightTheme->color(ColorToken::Hover))
);
check(
    'Theme · forSystem 按系统主题选表',
    ThemeData::forSystem(SystemTheme::Dark)->isDark() && !ThemeData::forSystem(SystemTheme::Light)->isDark()
);

// ⑭ BuildContext + StatelessWidget（§3.4 / §15.6.1）
fresh($log);
$context = new BuildContext(320.0, 240.0, ThemeData::light(), new DeterministicMetricsProvider(
    DeterministicFontManager::standard()->collection(),
    new DeterministicShaper(),
));
check('BuildContext · 只读渲染环境快照（约束宽高 + 解析后的主题）', $context->width === 320.0 && $context->height === 240.0);
check(
    'BuildContext · color() 与 theme() 是同一落点（不是两套取色）',
    $context->color(ColorToken::Text)->equals($context->theme()->color(ColorToken::Text))
);
check(
    'BuildContext · metric() 与 theme() 是同一落点',
    $context->metric(MetricToken::Gap) === $context->theme()->metric(MetricToken::Gap)
);
check(
    'BuildContext · 无向上查找（无 of / find / maybeOf —— §18 已否决 provider soup）',
    !method_exists(BuildContext::class, 'of')
        && !method_exists(BuildContext::class, 'find')
        && !method_exists(BuildContext::class, 'maybeOf')
);
check(
    'BuildContext · 无业务数据出口（不返回 Subject）',
    !method_exists(BuildContext::class, 'store') && !method_exists(BuildContext::class, 'subject')
);
check(
    'Widget · 基类上刻意没有 build()（§15.6.1：否则与 StatelessWidget 不兼容覆盖）',
    !method_exists(Widget::class, 'build')
);
check('StatelessWidget · build() 拿到本次渲染环境', (new BuildProbe())->build($context)->width === 320.0);
check(
    'StatelessWidget · 同样的入参保证同样的输出（纯函数）',
    (new BuildProbe())->build($context)->width === (new BuildProbe())->build($context)->width
);

// ⑮ 布局：两阶段 + 六条契约（§13.1）
fresh($log);
$solid = new SolidBox(new Size(10.0, 6.0), 24.0, 7.0);
check(
    'Layout · 布局是纯函数（同约束两次 ⇒ 同尺寸）',
    $solid->layout(BoxConstraints::unbounded())->equals($solid->layout(BoxConstraints::unbounded()))
);

// ── 布局自校验的反例自检（`04` §5 / §12.2 #14）──────────────────────────
// ★★ 这是"量具自己也会空转"的那一条：`LayoutSelfCheck` 开关打开时如果什么都没
//   发生（采样没落到这棵树 / 快照字段漏了 / `$on` 从没被翻开），表现是**静默**的。
//   ⇒ 一条夹具同时驱**两个方向**：纯盒必须 0 不一致（不误报），不纯盒必须 > 0（真在守）。
//   合成一条 check，免得为对照组单开一条（W0 的 smoke 链按 936+3 记账）。
$selfCheckConstraints = BoxConstraints::loose(new Size(1000.0, 1000.0));
LayoutSelfCheck::$on = true;

// ① 对照组：`SolidBox` 的 `performLayout` 只读约束 ⇒ 连排两次必须逐字节相同
LayoutSelfCheck::reset();
$pureBox = new SolidBox(new Size(40.0, 20.0), 40.0, 20.0);
for ($i = 0; $i < 32; $i++) {
    $pureBox->layout($selfCheckConstraints);
    LayoutSelfCheck::maybeCheck($pureBox, $selfCheckConstraints);
}
$pureChecks = LayoutSelfCheck::$checks;
$pureMismatches = LayoutSelfCheck::$mismatches;

// ② 反例：`ImpureBox::performLayout()` 读 `$this->size()` ⇒ 每排一次宽 +1
LayoutSelfCheck::reset();
$impureBox = new ImpureBox();
for ($i = 0; $i < 64; $i++) {
    $impureBox->layout($selfCheckConstraints);
    LayoutSelfCheck::maybeCheck($impureBox, $selfCheckConstraints);
}
LayoutSelfCheck::$on = false;

// ③ 报告文件：真机上 `users.exe` 是独立进程，`Errors` 的环形缓冲外面一条都读不到
//    ⇒ "mismatches === 0" 会退化成"只能选择相信"。落盘出口必须真的落。
$selfCheckReport = getcwd() . '/build/layout.check.out';
$selfCheckReportMismatches = -1;
if (is_file($selfCheckReport)) {
    $text = (string) file_get_contents($selfCheckReport);
    if (preg_match('/^mismatches (\d+)$/m', $text, $mm) === 1) {
        $selfCheckReportMismatches = (int) $mm[1];
    }
}

check(
    'Layout · 自校验反例自检（纯盒 0 不一致 / 不纯盒 > 0 / 两侧都真采样到 / 报告文件落盘）',
    $pureChecks > 0 && $pureMismatches === 0
        && LayoutSelfCheck::$checks > 0 && LayoutSelfCheck::$mismatches > 0
        && $selfCheckReportMismatches > 0,
    sprintf(
        '纯盒 checks=%d mismatches=%d ｜ 不纯盒 checks=%d mismatches=%d ｜ 末条差异=%s ｜ 报告文件 mismatches=%d',
        $pureChecks,
        $pureMismatches,
        LayoutSelfCheck::$checks,
        LayoutSelfCheck::$mismatches,
        LayoutSelfCheck::$recent === []
            ? '(空)'
            : LayoutSelfCheck::$recent[count(LayoutSelfCheck::$recent) - 1]['class']
                . ' @ ' . LayoutSelfCheck::$recent[count(LayoutSelfCheck::$recent) - 1]['field'],
        $selfCheckReportMismatches
    )
);
check(
    'Layout · layout() 把尺寸存下来（第二阶段 paint 用得上）',
    $solid->hasSize() && $solid->size()?->equals(new Size(10.0, 6.0)) === true
);
check(
    'Layout · C3 内禀测量带另一轴（不是把自身尺寸回抄）',
    $solid->intrinsicWidth(0.0) === 24.0 && $solid->intrinsicWidth(0.0) !== $solid->size()?->width
);

// C1 单向数据流：父给子约束、父定位子
$childBox = new SolidBox(new Size(8.0, 5.0), 8.0, 5.0);
$holder = (new OffsetBox(new Size(100.0, 50.0)))->at(20.0, 10.0)->child($childBox);
$holder->layout(BoxConstraints::tight(new Size(100.0, 50.0)));
check(
    'Layout · C1 约束真的往下传了（父给子的是自己那份，不是自己的约束）',
    $holder->seenChildConstraints !== null && $holder->seenChildConstraints->hasInfiniteWidth()
);
check(
    'Layout · C1 父定位子（偏移由父写）',
    $childBox->offsetX() === 20.0 && $childBox->offsetY() === 10.0
);
$visited = 0;
$holder->visitChildren(function (RenderBox $visitor) use (&$visited): void {
    $visited++;
});
check('Layout · visitChildren 遍历到子节点', $visited === 1);

// paint：父的 translate 必须落到设备像素上
$treeList = new DisplayList();
$holder->paint(new RecordingCanvas($treeList));
$treeCanvas = new RasterCanvas(40, 30, Color::white(), Scale::of(1.0));
$treeList->replay($treeCanvas);
check(
    'Layout · paint 把父的 translate 落到设备像素上（偏移 20,10 处有墨、原点没有）',
    $treeCanvas->pixelAt(21, 11)->r < 128 && $treeCanvas->pixelAt(0, 0)->r >= 250
);

// C4 / C5：溢出策略
check(
    'Layout · C5 默认溢出策略 = Clip（只给不会超的容器）',
    (new SolidBox(new Size(1.0, 1.0), 1.0, 1.0))->overflow() === Overflow::Clip
);
check(
    'Layout · C5 会超的容器显式覆盖默认',
    (new OversizeBox())->overflow() === Overflow::Report
);

// ── ⑮ Flex / ⑮b basis·shrink / ⑮c alignSelf / Clip 已迁出 ──────────────────────
//   · tests/Unit/Layout/FlexTest.php —— C4 弹性子量一次 / flex 份额分配 / spacing /
//     交叉轴 / padding / shrink 公式（加权压缩·冻结迭代·增量语义）/ alignSelf /
//     Overflow::Clip（19 条，纯布局 Unit 层）
//   ★ 子进程失败路径（C5 Report / shrink 溢出 / flex scroll / flex 值 / C6）仍留本
//     文件（runCase 依赖 __FILE__ 为 smoke 入口，见下方保留段）

// C4 / C5 的失败路径（子进程：会 Fatal）
fresh($log);
[$code, $output] = runCase(__FILE__, 'flexreport', null);
check('Layout · C5 Report 溢出退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check(
    'Layout · 溢出消息写了 Errors.log（含差额与修法，不含内部条款编号）',
    str_contains(logText($log), '放不下')
        && str_contains(logText($log), '差 ')
        && !str_contains(logText($log), 'C5')
);


// shrink 压到 0 之后仍放不下 ⇒ C5 兜底（子进程：会 Fatal）
fresh($log);
[$code, $output] = runCase(__FILE__, 'shrinkoverflow', null);
check('Layout · shrink 压到 0 仍放不下 ⇒ C5 中止退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Layout · shrink 的 C5 消息写的是"放不下"（不是"所有子都变 0 宽"）', str_contains(logText($log), '放不下'));

// 容器上声明 Scroll **不支持**（不是"还没做"）⇒ 响亮失败，且消息必须**指路** ScrollView
//   ★ 判据里同时查"不支持"与"ScrollView"：只查前半句的话，一条
//     "尚未实现"的旧文案也能过（那是本次 I4 之前的形态）。
fresh($log);
[$code, $output] = runCase(__FILE__, 'flexscroll', null);
check('Layout · 容器上 Scroll 不支持 ⇒ 响亮失败退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check(
    'Layout · 容器上 Scroll 不支持写了 Errors.log 且指路 ScrollView',
    str_contains(logText($log), '不支持') && str_contains(logText($log), 'ScrollView')
);

// flex 份额 < 1 是构造成员错误（G2：绝不静默夹紧）⇒ 子进程里验
fresh($log);
[$code, $output] = runCase(__FILE__, 'flexvalue', null);
check('Layout · flex 份额 < 1 退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Layout · flex 份额 < 1 写了 Errors.log', str_contains(logText($log), 'flex 份额'));

fresh($log);
[$code, $output] = runCase(__FILE__, 'layout', null);
check('Layout · C6 布局违反约束退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check(
    'Layout · 约束违规消息写了 Errors.log（人话描述，不含内部条款编号）',
    str_contains(logText($log), '超出父容器给的可用空间')
        && !str_contains(logText($log), 'C6')
);

// ── ⑯ 三树 Element（挂载 / diff / 卸载）已迁出 ───────────────────────────────
//   · tests/Unit/Widget/ElementTreeTest.php（10 条，纯 Element 机制 Unit 层）
// ── 结构控件：Column / Row / Padding / Center / Spacer / Expanded（⑱）已迁出 ──────
//   · `tests/Unit/Widget/StructureControlsTest.php` —— 建盒/子盒挂接 / 端到端 flex 分配 /
//     Row 横主轴 / 弹性子拿全部剩余（A·B·C）/ Padding 进盒 / Center 正中·松约束·带 padding /
//     不是 BoxWidget · 无 build() · update 换盒（16 条）
//   ★ 纯布局，不依赖帧驱动，放 Unit 层。

// ── ⑲ Text 控件 / ㉑ Each：声明层与列表 diff 已迁出 ─────────────────────────────
//   · `tests/Unit/Widget/TextTest.php` —— 哨兵三档（enum case / 三态 bool / -1.0 域外）/
//     角色经主题解析（title 18 · caption 12 · 显式 size 盖过角色）/ 端到端排版 ·
//     收窄折行 · maxLines 进 TextStyle（12 条）
//   · `tests/Unit/Widget/EachTest.php` —— keyFn 落位 / 挂载铺子 / key 相同复用 /
//     重排 key 跟随 item / 空列表 0 子（5 条）
//   ★ 重复 key 与 int·字符串 key 撞车靠子进程验 Errors 上报，留下面两条（runCase）。
// 重复 key ⇒ 响亮失败（G2：绝不静默）
fresh($log);
[$code, $output] = runCase(__FILE__, 'eachdup', null);
check('Each · 重复 key 退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Each · 重复 key 写了 Errors.log', str_contains(logText($log), '重复的 key'));

// key 1 与 '1' 不算重复（与 Key::same 同口径）：int id 与字符串 id 不静默撞车
fresh($log);
[$code, $output] = runCase(__FILE__, 'eachkeytag', null);
check("Each · key 1 与 '1' 不算重复（子进程 exit 1 = 该报的没报）", $code === 1, '实际 ' . $code . ' / ' . $output);

// ── ㉒ Button / NavButton / IconButton / 盒级 hover / 滚动条淡出 已迁出 ─────────
//   · `tests/Unit/Widget/ButtonTest.php` —— 变体薄壳 / onPressed 原样递 / 换肤取色 /
//     盒尺寸 = 内边距 + 标签 / 图标加宽 / 标签排图标右侧 / 像素居中 / NavButton 左对齐 /
//     IconButton 正方形 · 不建 RenderText · 可访问名（17 条）
//   · `tests/Unit/Layout/HoverAnimationTest.php` —— progress 起点 0 / 首帧自报在跑 /
//     24 帧单调逼近 / 收口返回 false / 浮起把影子画到盒外（6 条）
//   · `tests/Unit/Widget/ScrollBarFadeTest.php` —— 恒亮 · 不建槽 / 900ms 空闲线 /
//     淡到 0.35 不是 0 / 收口后能重新起飞（7 条）
//   ★ Dock C5 Report 的失败路径留下面两条（runCase）。
// command() 在 M2 响亮说没实现（不静默留下一个按了没反应的按钮）
fresh($log);
[$code, $output] = runCase(__FILE__, 'dockoverflow', null);
check('Dock · C5 Report 条轨超出退出码 = 3', $code === 3, '实际 ' . $code . ' / ' . $output);
check(
    'Dock · Report 超出写了 Errors.log（含槽名与差额，且不含内部条款编号）',
    str_contains(logText($log), 'Dock 放不下')
        && str_contains(logText($log), 'top + bottom')
        && !str_contains(logText($log), 'C5')
);

// ── ㉓ TextField 已迁出 ───────────────────────────────────────────────────
//   · `tests/Feature/Widget/TextFieldTest.php` —— 声明层（make 链式 · label 三态 ·
//     onChanged 原样递）/ 盒几何与换肤（三段分工 · Clip · 占位符不塌 · 明暗底色 ·
//     描边 · 有 label 根是 Column）/ 编辑态序列（码位删与吸附 · onChanged 只在真改动时
//     触发）/ 插入符几何与墨（caretRect 需先 settle · 编辑态真多出墨 · 受控回推）/
//     表单示例页无头出图（§20 纪律 4）—— 共 26 条
//   ★ 落 Feature 层：判据含光栅取墨与无头 SVG 出图。
// ── ㉔ 输入层最小集 / 坐标链 / IME 定位 已迁出 ──────────────────────────────
//   · `tests/Unit/Input/KeyModelTest.php`（11）—— KeyChord 值语义 · F5 · character vs
//     down · FocusNode 链式与 claims · Widget 侧 focusOrder/claimKeys → focusNode()
//   · `tests/Feature/Input/FocusRoutingTest.php`（21）—— 文档序收集 · 没焦点不吃键 ·
//     Tab 前进 / Shift+Tab 后退 / 末尾停（不环绕）· 焦点落下即编辑态 · 离开即退出 ·
//     打字 / 退格 / 方向键 / Home / End · 命中测试最深优先 → 焦点 · 帧末重建换新盒 ·
//     skipFocus / focusOrder / claimKeys
//   · `tests/Feature/Input/CoordinateChainTest.php`（6）—— hitTest 命中起点 == 墨迹起点
//     （padding 移到布局期施加的判据）· 墨迹起点 == 24px padding · imeCaretRect 含容器
//     padding 且沿父链累积 · 无焦点 ⇒ null · 带 label 穿 Column 找真实字段盒
//   ★ 夹具复用 `Drift\Dev\Smoke\App\{TfApplication,TfLabeledApplication}` 与
//     `Frame\SurfaceProbe`（composer autoload 已覆盖）。
// ── 修饰落在"不产盒"的控件上（arch-v2 `02` §11.7）──────────────────────
//   `StatefulWidget` / `StatelessWidget` 自己不产盒（`TextField` 的盒在
//   `TextFieldBox` 里）⇒ 声明的 pad 只留在 Widget 上、盒永远读不到。
//   实测现象是"尺寸一点没变"——**静默失效**是最坏的一种，所以这里断言它响亮失败。
check('Modifier · isDefault() 对全默认为真', (new Modifier())->isDefault());
check(
    'Modifier · isDefault() 对单字段改动为假（21 个字段都在判据里）',
    !(new Modifier())->withCenter(true)->isDefault()
        && !(new Modifier())->withShadow(0.1)->isDefault()
        && !(new Modifier())->withGrow(1)->isDefault()
        && !(new Modifier())->withBasis(0.0)->isDefault()
);

// ★ 中止路径跑子进程（与「封存后写入」同型）：断言退出码 + 日志内容。
fresh($log);
[$modCode, $modOut] = runCase(__FILE__, 'modstateful', null);
check(
    'Modifier · 不产盒的控件上声明修饰 ⇒ 退出码 = 3（不静默丢）',
    $modCode === 3,
    '实际 ' . $modCode . ' / ' . $modOut
);
check(
    'Modifier · 该失败写了 Errors.log 并指明修法',
    str_contains(logText($log), '不产渲染盒')
);
// ── 选区模型 / 选区渲染 已迁出 ───────────────────────────────────────────────
//   · `tests/Unit/Widget/TextFieldSelectionTest.php`（23）—— selectAll 两端 / 锚点模型
//     （Shift+Left·Right·Home·End 只挪活动端）· 不带 Shift 移动折叠 · Ctrl+A 经 handleKey ·
//     Esc 吃掉选区并消费按键（无选区不吞）· 键入与退格/Delete 吃整段 · 无选区退格仍按码位 ·
//     多字节端点恒字符边界 · 外部换值作废选区 · extendSelectionTo 吸附与夹取 · endEditing 清选区
//   · `tests/Feature/Widget/TextFieldTest::testSelectionGeometryAndPaint`（5）—— selectionRect
//     非 null / 右边缘与同偏移插入符左边缘同源 / offsetAt 真实往返 / 全选真的多出墨 /
//     无选区为 null
//   ★ `fieldInk()` 已随段删除 —— 测试侧用 TextFieldTest 的同名私有方法（同一量具）。
// ── 拖动 resize 轻量入口（§5.2）：只重排+重绘，不碰任务/动画/语义 ──────
//   平台壳在**模态尺寸循环内同步调回**它（C → PHP 重入）⇒ 能碰的状态越少越安全。
//   头侧可断言的部分：新约束真的生效（根盒宽）、只跑一帧、时间戳复用上一帧。
$resizeApp = new TfApplication();
$resizeSurface = new SurfaceProbe();
$resizeApp->attach($resizeSurface, BoxConstraints::loose(new Size(300.0, 200.0)));
$resizeApp->frame(16.0, BoxConstraints::loose(new Size(300.0, 200.0)));
$resizeFramesBefore = $resizeSurface->frames;
$resizeTsBefore = $resizeSurface->lastTimestamp;
$resizeApp->repaintForResize(BoxConstraints::tight(new Size(420.0, 260.0)));
check(
    'Resize · repaintForResize 按新约束重排（根盒宽 = 420，不是旧的 300）',
    ($resizeApp->root()?->effectiveBox()?->size()?->width ?? -1.0) === 420.0,
    'rootW=' . ($resizeApp->root()?->effectiveBox()?->size()?->width ?? -1.0)
);
check(
    'Resize · repaintForResize 只跑一帧、时间戳复用上一帧（不自己读时钟）',
    $resizeSurface->frames === $resizeFramesBefore + 1
        && $resizeSurface->ends === $resizeFramesBefore + 1
        && $resizeSurface->lastTimestamp === $resizeTsBefore,
    'frames=' . $resizeSurface->frames . ' ends=' . $resizeSurface->ends
        . ' ts=' . var_export($resizeSurface->lastTimestamp, true)
);

// ── 零视口（最小化）：不构成可布局的一帧 ────────────────────────────
//   用户实测（2026-10-25）：最小化 ⇒ `GetClientRect` 给 0×0 ⇒ `tight(0,0)` 拿去
//   布局 ⇒ `RenderText 实际 0.0 × 86.4，最多只能用 0.0 × 0.0` 当场 C6 Fatal。
//   ★ 判据不是"没崩"，而是三件事：帧被**计数跳过**、表面**一帧都没起**、根盒
//   **保持旧尺寸**（不被 0 覆盖）—— 后者才是"还原后能正常重排"的前提。
$zeroApp = new ZeroViewportApplication();
$zeroSurface = new SurfaceProbe();
$zeroApp->attach($zeroSurface, BoxConstraints::tight(new Size(320.0, 240.0)));
$zeroApp->frame(16.0, BoxConstraints::tight(new Size(320.0, 240.0)));
$zeroRootW = $zeroApp->root()?->effectiveBox()?->size()?->width ?? -1.0;
$zeroFramesBefore = $zeroSurface->frames;
$zeroApp->frame(32.0, BoxConstraints::tight(new Size(0.0, 0.0)));
check(
    '零视口 · frame(tight(0,0)) 跳过布局与绘制（不 C6，表面不起帧，根盒保持 320）',
    $zeroRootW === 320.0
        && $zeroApp->zeroViewportFrames() === 1
        && $zeroSurface->frames === $zeroFramesBefore
        && ($zeroApp->root()?->effectiveBox()?->size()?->width ?? -1.0) === $zeroRootW,
    'frames=' . $zeroSurface->frames . '/' . $zeroFramesBefore
        . ' zero=' . $zeroApp->zeroViewportFrames()
        . ' rootW=' . ($zeroApp->root()?->effectiveBox()?->size()?->width ?? -1.0)
);
$zeroApp->repaintForResize(BoxConstraints::tight(new Size(0.0, 0.0)));
check(
    '零视口 · repaintForResize(tight(0,0)) 与 frame() 同一条判据（表面仍不起帧）',
    $zeroSurface->frames === $zeroFramesBefore,
    'frames=' . $zeroSurface->frames,
);
$zeroApp->frame(48.0, BoxConstraints::tight(new Size(200.0, 120.0)));
check(
    '零视口 · 还原后按新尺寸重排（早退不留死状态）',
    ($zeroApp->root()?->effectiveBox()?->size()?->width ?? -1.0) === 200.0,
    'rootW=' . ($zeroApp->root()?->effectiveBox()?->size()?->width ?? -1.0),
);

// ── AppBar 窄窗口（§12.1 / W4）：宽度随窗口变化 ⇒ 裁剪，不弹 Fatal ──────
//   实测回归：窗口拖到可用宽 322、顶条自然宽 327 ⇒ 原 `Overflow::Report` 直接
//   弹 "RenderFlex 溢出" Fatal，拖动缩窗被中断。顶条放不下是**用户行为**，
//   正确语义是裁剪（右侧导航项暂时不可见）。
Errors::reset();
$barEl = AppBar::make()->title('用户管理')->children([
    NavButton::make('列表'),
    NavButton::make('注册'),
    NavButton::make('设置'),
])->createElement();
$barEl->mount(null, smokeContext());
$barEl->settle();
// ★ 约束必须**窄于顶条自然宽**（实测自然宽 ≈ 327：标题 + 3 个导航 + 菜单）。
//   320 已接近，取 280 保证稳定溢出 —— 否则夹具对回归不敏感（反例仍绿）。
$barEl->effectiveBox()?->layout(BoxConstraints::tight(new Size(100.0, 220.0)));
$barDiag = Errors::recentDiagnostics();
check(
    'AppBar · 可用宽 100 < 自然宽 ⇒ 裁剪（无溢出 Fatal）',
    $barDiag === []
        && ($barEl->effectiveBox()?->size()?->width ?? -1.0) === 100.0,
    'diag=' . count($barDiag) . ' rootW=' . ($barEl->effectiveBox()?->size()?->width ?? -1.0)
);

// ── 预览期硬校验降级（§5.2）：拖动中的瞬时极小尺寸不弹 Fatal ──────────
//   用户拖窗口边缘时中间尺寸是**瞬时态**——「窗口比内容小」是合法状态，不是
//   编程错误。`LayoutTolerance::$previewing` 期间 C5/C6 的硬校验一律降级为裁剪
//   （RenderFlex / RenderDock / RenderBox 三处都读它）；松手后复位，真溢出照旧报。
Errors::reset();
LayoutTolerance::$previewing = true;
$tolApp = new TfApplication();
$tolApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));
$tolApp->frame(16.0, BoxConstraints::loose(new Size(300.0, 200.0)));
$tolApp->repaintForResize(BoxConstraints::tight(new Size(80.0, 50.0)));   // 远小于内容
$tolDiag = Errors::recentDiagnostics();
LayoutTolerance::$previewing = false;
check(
    'Resize · 预览期极小尺寸不弹 Fatal（C5/C6 降级为裁剪）',
    $tolDiag === [],
    'diag=' . count($tolDiag) . ' ' . implode(' | ', array_slice($tolDiag, 0, 2))
);

// ── 主题切换 ⇒ 界面真的换表（§9.2 / §24.2 #14）────────────────────────
//   `Element::applyContext()` 本就是"换一份渲染环境（主题切换的唯一入口）"，
//   但 src/ 里**零生产调用者** ⇒ ThemeChangedEvent 之前只是被显式消费、不做
//   任何事。本轮把两者接起来（先 `onSystemThemeChanged()` 换源、再下发 context）。
$thApp = new ThemeApplication();
$thApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));
$thRoot = $thApp->rootElement();
check(
    '主题 · 派发前：根 context 是浅色（对照，证明"没事件就不变"）',
    $thRoot?->context()->theme()->isDark() === false,
    $thRoot?->context()->theme()->isDark() ? 'dark' : 'light'
);

$thApp->dispatchInput(new ThemeChangedEvent());
check(
    '主题 · onSystemThemeChanged() 钩子被调 ⇒ 应用换了主题源',
    $thApp->currentTheme()->isDark() === true,
    $thApp->currentTheme()->isDark() ? 'dark' : 'light'
);
check(
    '主题 · 根 Element 的 context 换成深色（applyContext 下发）',
    $thRoot?->context()->theme()->isDark() === true,
    $thRoot?->context()->theme()->isDark() ? 'dark' : 'light'
);

// ★ 递归生效才是"界面换表" —— 只换根不换子层，控件仍按旧主题取色（换表不换色）
$thText = findElement($thRoot, fn($el) => $el->widget() instanceof Text);
check(
    '主题 · 子层 context 也换了（applyContext 递归下发 —— 换表**且**换色）',
    $thText?->context()->theme()->isDark() === true,
    $thText?->context()->theme()->isDark() ? 'dark' : 'light'
);

// 帧末重建（adoptBoxes）/ skipFocus / focusOrder / claimKeys 已迁出
//   · `tests/Feature/Input/FocusRoutingTest.php` —— 父容器换上重建后的新盒（量具
//     双向自检：`Element::settle()` 去掉 `adoptBoxes()` 这条必须变红）/
//     skipFocus 整棵子树不进 Tab 序 / focusOrder 稳定排序 / claimKeys 独占 Ctrl+A
// ── ⑳ 控件与结构节点混用（§A.1 的形态真的能搭起来）──
$mixed = Column::make()->spacing(8)->paddingAll(12)->children([
    Text::make('标题')->title(),
    Row::make()->spacing(8)->children([
        Text::make('左')->body(),
        Spacer::make(),
        Text::make('右')->caption(),
    ]),
]);
$mixedRoot = $mixed->createElement();
$mixedRoot->mount(null, $context);
$mixedBox = $mixedRoot->renderObject();
$mixedBox?->layout(BoxConstraints::loose(new Size(400.0, 300.0)));
check(
    '控件 · Column 包 Text + Row(Text/Spacer/Text) 能一路排到底',
    ($mixedBox?->size()?->width ?? 0.0) > 0.0 && ($mixedBox?->size()?->height ?? 0.0) > 0.0,
    sprintf('实际 %.2f × %.2f', $mixedBox?->size()?->width ?? -1.0, $mixedBox?->size()?->height ?? -1.0)
);

// ⑰ State：setState 只标脏（§5.2 / §11.3.1 / §12.3）
fresh($log);
$counterElement = (new CounterWidget())->createElement();
$counterElement->mount(null, $context);
$counter = $counterElement->state();

check('State · 挂载时造一次，之后是同一个', $counter instanceof CounterState && $counterElement->state() === $counter);
check('State · build() 经 State 出，并收到渲染环境', $counter->builds === 1 && $counter->seenWidth === 320.0);
check('State · 挂载时也建出了一层子', countTree($counterElement) === 2);

// ★ §5.2 的核心：setState **只标脏**，不立即重建
$counter->bump();
check('State · setState 跑闭包', $counter->bumps === 1);
check('State · setState 只标脏，不立即重建', $counter->needsBuild() && $counter->builds === 1);
$counterElement->rebuild();
check('State · 帧末重建后清脏', !$counter->needsBuild() && $counter->builds === 2);
check('State · 重建不换 State（纯 UI 态活过整个 build）', $counterElement->state() === $counter);

// §12.3：换描述时 State 跟着走，旧的不丢
$newCounter = new CounterWidget();
$counterElement->update($newCounter);
check('State · 换新描述时 State 不换（状态活过更新）', $counterElement->state() === $counter);
check('State · State 手里的 Widget 换成整份新的', $counter->widget() === $newCounter);

// §12.3 回收纪律：卸载 ⇒ dispose
$counterElement->unmount();
check('State · unmount 调 dispose()（§12.3 回收纪律）', $counter->disposed);

// ── ㉓ State 层：订阅生命周期 / Cell 门控 / select 切片（§11.3）──

// ① SubjectBuilder：门控比的是**两次 select 输出**，不是 Store 的新旧值（§11.3.3）
$store = new FakeStore();
$builderRuns = 0;
$builderArgs = [];
$sub = SubjectBuilder::make($store)
    ->select(static function (FakeStore $s): int { return $s->status(); })
    ->builder(static function (mixed ...$args) use (&$builderRuns, &$builderArgs): Widget {
        $builderRuns++;
        $builderArgs = $args;

        return new LeafProbe(1.0);
    });
$subElement = $sub->createElement();
$subElement->mount(null, $context);
check(
    'State · initState 在首帧 build 之前 ⇒ 首帧就拿到选中的值',
    $builderRuns === 1 && $builderArgs === [0],
    'runs=' . $builderRuns . ' args=' . json_encode($builderArgs)
);

// 同值再 notify：select 输出没变 ⇒ **连脏都不标**（门控在回调里就挡掉了）
$store->setStatus(0);
check(
    'State · 同值 notify 被门控挡住（两次 select 输出相等）',
    $builderRuns === 1 && !$subElement->state()->needsBuild(),
    'runs=' . $builderRuns
);

// 改了字段但不 notify ⇒ 订阅者收不到（重建由**通知**驱动，不是由字段变动驱动）
$store->silentTouch(5);
check(
    'State · 改字段但不 notify ⇒ 订阅者收不到',
    $builderRuns === 1 && !$subElement->state()->needsBuild(),
    'runs=' . $builderRuns
);

// notify 且 select 输出变了 ⇒ 标脏
$store->setStatus(6);
check('State · notify 后 select 输出变了 ⇒ 标脏', $subElement->state()->needsBuild(), '没有标脏');

// 帧末 flush 的动作。★ flush 循环（走全树消费脏标记）本身属 App 层（#22）——
// State 层只负责"标脏标对"，这里显式 rebuild 一次来证明链路是通的。
$subElement->rebuild();
$subChild = $subElement->childAt(0)?->widget();
check(
    'State · 重建后 builder 拿到 select 的新值（不是 null、不是 Store）',
    $builderRuns === 2 && $builderArgs === [6] && $subChild instanceof LeafProbe,
    'runs=' . $builderRuns . ' args=' . json_encode($builderArgs)
);

// ② 卸载后退订 —— 没有"僵尸更新"，且**不依赖用户写 dispose()**
$subElement->unmount();
$store->setStatus(9);
check(
    'State · unmount 后退订（订阅袋全退，无僵尸更新）',
    $builderRuns === 2 && !$subElement->state()->needsBuild(),
    'runs=' . $builderRuns
);

// ③ 无 select ⇒ builder 收 0 个参数（★ 不是统一传 null）
$plainRuns = 0;
$plainArgs = null;
$plain = SubjectBuilder::make($store)->builder(static function (mixed ...$args) use (&$plainRuns, &$plainArgs): Widget {
    $plainRuns++;
    $plainArgs = $args;

    return new LeafProbe(1.0);
});
$plainElement = $plain->createElement();
$plainElement->mount(null, $context);
check(
    'State · 无 select ⇒ builder 收 0 个参数',
    $plainRuns === 1 && $plainArgs === [],
    'args=' . json_encode($plainArgs)
);
$store->setStatus(11);
check('State · 无 select ⇒ 任何 notify 都标脏', $plainElement->state()->needsBuild(), '没有标脏');
$plainElement->rebuild();
check(
    'State · 无 select ⇒ 重建后 builder 仍收 0 个参数',
    $plainRuns === 2 && $plainArgs === [],
    'args=' . json_encode($plainArgs)
);
$plainElement->unmount();

// ④ Cell：设同值不标脏（§11.3.1 / RT-4）
$hoverElement = (new HoverWidget())->createElement();
$hoverElement->mount(null, $context);
$hoverState = $hoverElement->state();
check('State · initState 只跑一次（重复挂载不重复订阅）', $hoverState->inits === 1, '实际 ' . $hoverState->inits);
$hoverState->onRowHover(2);
check('State · Cell 换值 ⇒ 标脏', $hoverState->needsBuild(), '没有标脏');
$hoverElement->rebuild();
$hoverState->onRowHover(2); // 同一行
check('State · Cell 设同值不标脏（RT-4：=== 严格比较）', !$hoverState->needsBuild(), '同值也标了脏');
$hoverState->onRowHover(3);
check('State · Cell 换新值 ⇒ 再标脏', $hoverState->needsBuild(), '换值没标脏');
$hoverElement->unmount();

// ⑤ Computed：无脏不重算 / 依赖变重算 / 嵌套求值（`03` §4，W2/S1）
$vprobe = new VersionProbe(3);
$computedRuns = 0;
$computed = new Computed(static function () use (&$computedRuns, $vprobe): int {
    $computedRuns++;

    return $vprobe->value() * 2;
}, [$vprobe]);
check(
    'Computed · 首次 get ⇒ 求值一次',
    $computed->get() === 6 && $computedRuns === 1,
    'runs=' . $computedRuns
);
check(
    'Computed · 无脏不重算（两次 get 之间不改依赖 ⇒ 闭包只跑一次）',
    $computed->get() === 6 && $computedRuns === 1,
    'runs=' . $computedRuns
);
check('Computed · 版本号没变就不变', $computed->version() === 1, 'version=' . $computed->version());

$vprobe->bump(); // 版本 +1、值不动 ⇒ 必须重算
check(
    'Computed · 依赖版本变 ⇒ 重算（闭包跑第二次）',
    $computed->get() === 6 && $computedRuns === 2,
    'runs=' . $computedRuns
);
check('Computed · 求值成功自己也进一版（可嵌套）', $computed->version() === 2, 'version=' . $computed->version());

// 嵌套：A 依赖 B，B 依赖 C；改 C ⇒ 一次 A.get() 把链算完
// ★ 这条抓的是一个真会静默的坑：A 的版本和用的是 B.version()，而 B 不重算就不进
//   版本 ⇒ 若不先刷新下游，A 永远拿缓存（03 原稿的形状就是这样，靠这条夹具才发现）
$cProbe = new VersionProbe(1);
$bRuns = 0;
$computedB = new Computed(static function () use (&$bRuns, $cProbe): int {
    $bRuns++;

    return $cProbe->value() + 10;
}, [$cProbe]);
$aRuns = 0;
$computedA = new Computed(static function () use (&$aRuns, $computedB): int {
    $aRuns++;

    return $computedB->get() * 2;
}, [$computedB]);
check(
    'Computed · 嵌套首次求值（A→B→C 一次算完）',
    $computedA->get() === 22 && $aRuns === 1 && $bRuns === 1,
    'a=' . var_export($computedA->get(), true) . ' aRuns=' . $aRuns . ' bRuns=' . $bRuns
);
$cProbe->bump();
check(
    'Computed · 改最底层依赖 ⇒ 一次 A.get() 把整条链算完',
    $computedA->get() === 22 && $aRuns === 2 && $bRuns === 2,
    'aRuns=' . $aRuns . ' bRuns=' . $bRuns
);

// ⑥ RT-3：一个订阅者抛异常，不妨碍其余订阅者收到通知
$debugWas = Errors::isDebug();
Errors::setDebug(false); // Recoverable 在 debug 会中止，先关掉才测得着"隔离"
try {
    $isoLog = $root . '/iso.log';
    fresh($isoLog);
    $ran = [];
    $ownerA = new FakeStore();
    $ownerB = new FakeStore();
    $ownerC = new FakeStore();
    $iso = new FakeStore();
    $iso->subscribeFor($ownerA, static function () use (&$ran): void { $ran[] = 'a'; });
    $iso->subscribeFor($ownerB, static function () use (&$ran): void {
        $ran[] = 'b';
        throw new \RuntimeException('订阅者 B 炸了');
    });
    $iso->subscribeFor($ownerC, static function () use (&$ran): void { $ran[] = 'c'; });
    $iso->notifyChanged();
    check(
        'State · 一个订阅者抛异常，其余仍收到通知（RT-3）',
        $ran === ['a', 'b', 'c'],
        '实际 ' . implode(',', $ran)
    );
    check('State · 订阅者异常没有静默（写了 Errors 出口）', str_contains(logText($isoLog), '订阅者异常已隔离'));
    check('State · 异常报的是 Recoverable 级', str_contains(logText($isoLog), 'RECOVERABLE'));
    $iso->unsubscribeAllOf($ownerC);
    $ran = [];
    $iso->notifyChanged();
    check('State · unsubscribeAllOf 退掉整个属主的订阅', $ran === ['a', 'b'], '实际 ' . implode(',', $ran));
    if (is_file($isoLog)) {
        unlink($isoLog);
    }
} finally {
    Errors::setDebug($debugWas);
}

// ⑦ settle 多轮收敛（`03` §5.1，W2/S3 + §5.3，W2/S4）
//    基线先取：pendingDirty 是进程级静态，前面夹具卸载时留的脏要排除在外，
//    否则"计数不漂移"会被别人的泄漏判红。
$pendingBase = State::pendingDirty();

// ⑦b 计数不漂移：100 帧、每帧标脏一次 + settle 一次 ⇒ 净增量必须为 0
$driftElement = (new HoverWidget())->createElement();
$driftElement->mount(null, $context);
$driftState = $driftElement->state();
for ($i = 1; $i <= 100; $i++) {
    $driftState->onRowHover($i); // Cell 换新值 ⇒ 标脏
    $driftElement->settle();
}
check(
    'settle · 计数不漂移（100 帧后 pendingDirty 与开局一致）',
    State::pendingDirty() === $pendingBase,
    'pending=' . State::pendingDirty() . ' base=' . $pendingBase
);
$driftElement->unmount();

// ⑦c build 期标脏自己 ⇒ 当帧 Fatal（不是"每帧默默重建一次、CPU 上去没人知道"）
fresh($log);
[$code, $output] = runCase(__FILE__, 'flushcycle', null);
check('settle · build 期标脏自己 ⇒ 当帧 Fatal（E-FLUSH-CYCLE）', $code === 3, '实际 ' . $code . ' / ' . $output);
check('settle · 不收敛写了 Errors.log', str_contains(logText($log), 'E-FLUSH-CYCLE'));

// ⑦d S4 守卫收紧后的回归：build 期写"自己"才 Fatal；跨 State 的一次性写
//     （子 build 标脏祖先）是合法的脏传播（§5.2 性质 B），应当收敛、不误杀。
//     W2/S4 之前粗守卫把这种合法传播也当 build 期写误杀了（showcase 主题切换
//     走的就是同一形态），收紧到"build 里对自己标脏"后这里必须收敛、不 Fatal。
fresh($log);
[$code, $output] = runCase(__FILE__, 'chainwrite', null);
check('settle · build 期跨 State 一次性写 ⇒ 收敛不 Fatal（守卫不误杀合法传播）', $code !== 3, '实际 ' . $code . ' / ' . $output);

// ⑧ 守卫跑在子进程里（会 exit(3)）
fresh($log);
[$code, $output] = runCase(__FILE__, 'watchbuild', null);
check('State · build 期订阅 ⇒ Fatal（A4 运行期守卫）', $code === 3, '实际 ' . $code . ' / ' . $output);
check('State · build 期订阅写了 Errors.log', str_contains(logText($log), 'initState'));

fresh($log);
[$code, $output] = runCase(__FILE__, 'computedcycle', null);
check('Computed · 自引用 ⇒ Fatal（E-COMPUTED-CYCLE）', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Computed · 自引用写了 Errors.log', str_contains(logText($log), 'E-COMPUTED-CYCLE'));

// Cell 构造私有 ⇒ new 不出来（W2/S2）。进程内断言：私有构造抛的是**可捕获的
// Error**，不需要子进程（原来的"未绑定 ⇒ Fatal"用例随 makeFor 一起退役 ——
// 未绑定的 Cell 现在**造不出来**，比造出来再炸更早）。
try {
    new Cell(1);
    check('State · Cell 构造函数私有 ⇒ new 不出来', false, 'new Cell(1) 竟然成功了');
} catch (\Error $e) {
    check(
        'State · Cell 构造函数私有 ⇒ new 不出来',
        str_contains($e->getMessage(), 'private'),
        $e->getMessage()
    );
}

// State::cell() 仍可用，且拿到的 Cell 真的绑在本 State 上（makeFor 的回归面）
$cellElement = (new HoverWidget())->createElement();
$cellElement->mount(null, $context);
$cellState = $cellElement->state();
$madeCell = $cellState->cell(7);
check('State · State::cell() 仍可用（走 makeFor）', $madeCell->value() === 7);
$madeCell->setIfChanged(8);
check('State · makeFor 建的 Cell 绑对了 Owner（标脏到本 State）', $cellState->needsBuild(), '没有标脏');
$cellElement->unmount();

fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㉔ Commands：参数 / 注册表 / 冒泡链第 ① 环（§11.4.3）──

check(
    'Commands · arg 不是裸 int（int id 与 string key 各有一个槽）',
    CommandArg::none()->i === 0 && CommandArg::none()->s === ''
        && CommandArg::id(7)->i === 7 && CommandArg::id(7)->s === ''
        && CommandArg::key('nav.users')->s === 'nav.users' && CommandArg::key('nav.users')->i === 0
);

$echo = new EchoCommands();
$router = CommandRouter::make()->on('user.del', $echo);
check('Commands · on() 返回同一个注册表（可链式，不每步 new）', $router->names() === ['user.del']);
check(
    'Commands · dispatch 把名字与参数原样递给 handler',
    $router->dispatch('user.del', CommandArg::id(42)) && $echo->seen === [['user.del', 42, '']]
);

// handler 说"不是我管" ⇒ 视为没接（调用方继续第 ④ 环）
$picky = new PickyCommands('nav.users');
$pickyRouter = CommandRouter::make()->on('nav.users', $picky);
check(
    'Commands · handler 返回 false ⇒ 视为没接（继续冒泡的语义）',
    !$pickyRouter->dispatch('nav.signup', CommandArg::none())
);

// 没人接 ⇒ Diagnostic 级上报，**不静默**（菜单项亮了但点了什么都没发生最难查）
fresh($log);
$ringBefore = count(Errors::recentDiagnostics());
check('Commands · 未注册的名字 ⇒ 返回 false 且不中止', !$pickyRouter->dispatch('ghost.cmd', CommandArg::none()));
check(
    'Commands · 未注册 ⇒ 进了 Diagnostic 环形缓冲（不是静默）',
    count(Errors::recentDiagnostics()) === $ringBefore + 1
);

// 冒泡链第 ① 环：State 默认"不接"
check('Commands · State 默认不接命令（不接是常态，不做成抽象）', (new CommandWidget())->createState()->handleCommand('x', CommandArg::none()) === false);

// 第 ① 环接了 ⇒ 不需要走到注册表
fresh($log);
$cmdElement = (new CommandWidget())->createElement();
$cmdElement->mount(null, $context);
$cmdState = $cmdElement->state();
$taken = $cmdState->handleCommand('widget.echo', CommandArg::id(3));
$skipped = $cmdState->handleCommand('other.thing', CommandArg::none());
check(
    'Commands · State 接管自己的命令、其余放行（冒泡链有明确返回值）',
    $taken && !$skipped && $cmdState->handled === ['widget.echo', 'other.thing']
);
$cmdElement->unmount();

// 同名重复注册 ⇒ Fatal（子进程）
fresh($log);
[$code, $output] = runCase(__FILE__, 'cmdup', null);
check('Commands · 同名重复注册 ⇒ Fatal（不静默覆盖）', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Commands · 重复注册写了 Errors.log', str_contains(logText($log), '重复注册'));

fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㉕ App.Frame：flush → layout → paint → present（§5.2）──

$surface = new SurfaceProbe();
$surface->resize(320, 240, 1.0);
$frame = new Frame($surface);

// setState **不立即重建**，Frame.run 才消费脏标记（§5.2 裁决 3）
// ★ 根必须是**有盒**的节点（Column）：Frame 在 flush 之后才拿 renderObject，
//   拿不到就直接返回 —— 这是设计如此（结构根无从排起），不是 bug。
$rootElement = Column::make()->children([new TickerWidget()])->createElement();
$rootElement->mount(null, $context);
$tickerElement = $rootElement->childAt(0);
check('Frame · 挂载即建出子树', $tickerElement !== null && $tickerElement->childCount() === 1);
$ticker = $tickerElement?->state();
check('Frame · 挂载时 State 只建一次', $ticker !== null && $ticker->builds === 1);
$firstChild = $tickerElement?->childAt(0)?->widget();
check(
    'Frame · 首帧 build 的产物来自 State 初值',
    $firstChild instanceof LeafProbe && $firstChild->width === 0.0
);

$ticker?->tick(); // 只标脏
check(
    'Frame · setState 之后、Frame 之前 ⇒ 还没重建',
    $ticker !== null && $ticker->builds === 1 && $ticker->needsBuild()
);

$frame->run($rootElement, BoxConstraints::tight(new Size(320.0, 240.0)), 16.0);
check(
    'Frame · 帧末 flush 重建了脏节点',
    $ticker !== null && $ticker->builds === 2 && !$ticker->needsBuild()
);
$secondChild = $tickerElement?->childAt(0)?->widget();
check(
    'Frame · 重建后子树换成新产物（setState → flush → 新 widget）',
    $secondChild instanceof LeafProbe && $secondChild->width === 1.0,
    (string) ($secondChild instanceof LeafProbe ? $secondChild->width : -1.0)
);

// 时间戳是**注入**的，不读环境时钟（§5.2 裁决 2 ⇒ G3 的 golden 基础）
check('Frame · beginFrame 收到注入的时间戳', $surface->lastTimestamp === 16.0);
check('Frame · beginFrame / endFrame 各一次（不重不漏）', $surface->frames === 1 && $surface->ends === 1);

// 脏边界：没脏的节点不该被重建
$quietElement = (new QuietWidget())->createElement();
$quietElement->mount(null, $context);
$quiet = $quietElement->state();
$frame->run($quietElement, BoxConstraints::tight(new Size(320.0, 240.0)), 32.0);
check('Frame · 没脏的节点不重建（flush 有脏边界，不是每帧全树）', $quiet->builds === 1);

// layout + paint 真的跑了：一棵 Column[Text] 有墨上图
$columnElement = Column::make()->children([Text::make('Hi')])->createElement();
$columnElement->mount(null, $context);
$surface2 = new SurfaceProbe();
$surface2->resize(320, 240, 1.0);
$frame2 = new Frame($surface2);
$frame2->run($columnElement, BoxConstraints::tight(new Size(320.0, 240.0)), 48.0);
check(
    'Frame · layout → paint → present 一路通到表面（画布上有墨）',
    $surface2->canvas !== null && inkCount($surface2->canvas) > 0,
    '墨点数 ' . (string) ($surface2->canvas !== null ? inkCount($surface2->canvas) : -1)
);

// ★★ 回归（2026-10-24）：**帧外换盒必须触发重排**（`State::$boxEpoch`）。
//   缺陷形态：直调 `Element::update()` 换盒**不经过 settle** ⇒ `settle()` 的
//   "帧内重建差"为 0 ⇒ `Frame` 判"本帧没变" ⇒ 跳过 layout ⇒ 新盒 `size` 恒
//   `null` ⇒ **之后所有命中测试落空**。而 overlay 计数 / 焦点 / 声明全对 ⇒ 极难定位。
//   ★ 特意用**有状态根**：`update()` 会走 `StatefulElement::rebuild()` →
//     `noteRebuilt()`，但那一次发生在 settle 的**快照之前** ⇒ 旧判据照样看不见。
//     这是本缺陷最难的一条（"明明计数加了，为什么说没重建"）。
$epSurface = new SurfaceProbe();
$epSurface->resize(320, 240, 1.0);
$epFrame = new Frame($epSurface);
$epRoot = (new EpochWidget())->createElement();
$epRoot->mount(null, $context);
$epFrame->run($epRoot, BoxConstraints::tight(new Size(320.0, 240.0)), 0.0);
check('Frame · 首帧排过（根盒有尺寸）', $epRoot->effectiveBox()?->hasSize() === true);

$epRoot->update(new EpochWidget());   // 帧外换盒：不经过 settle
$epFrame->run($epRoot, BoxConstraints::tight(new Size(320.0, 240.0)), 16.0);
$epBox = $epRoot->effectiveBox();
check(
    'Frame · 帧外换盒（update 直调）⇒ 新盒照样排过（不跳过 layout）',
    $epBox !== null && $epBox->hasSize(),
    $epBox === null ? 'effectiveBox=null' : ($epBox->hasSize() ? 'ok' : 'size=null')
);

// ── ㉖ Routing：RouteTable（数据）+ Offstage（保状态，§12.1 第 4/7 条）──

$table = RouteTable::make()
    ->route('users', static fn (): Widget => new LeafProbe(1.0))
    ->route('signup', static fn (): Widget => new LeafProbe(2.0));
check(
    'Routing · route() 链式 + names() 可枚举（devtools 列举的输入）',
    $table->names() === ['users', 'signup']
);
$built = $table->build('users');
check('Routing · build() 调工厂造页', $built instanceof LeafProbe && $built->width === 1.0);
check('Routing · 未命中 ⇒ null（开放域 ⇒ 由调用方 default 处理）', $table->build('ghost') === null);
check('Routing · has() 与 names() 一致', $table->has('users') && !$table->has('ghost'));

fresh($log);
[$code, $output] = runCase(__FILE__, 'routedup', null);
check('Routing · 同名路由重复注册 ⇒ Fatal（不静默覆盖）', $code === 3, '实际 ' . $code . ' / ' . $output);
check('Routing · 重复注册写了 Errors.log', str_contains(logText($log), '已注册'));

// ① Offstage 三档里的"保留"档：切走再切回，State 还是同一个
$offstage = Offstage::make(new CounterWidget());
$offElement = $offstage->createElement();
$offElement->mount(null, $context);
$hidden = $offElement->childAt(0)?->state();
check('Routing · 可见 ⇒ 子层在树里', $offElement->childCount() === 1);
check('Routing · 挂载即建出 State', $hidden !== null);

$hidden?->bump(); // 标脏，先不重建
$updated = Offstage::make($offstage->child(), false);
$offElement->update($updated);
check('Routing · 隐藏 ⇒ 子层不在树里（不 layout / 不 paint / flush 走不到）', $offElement->childCount() === 0);
check('Routing · 隐藏**不卸载**（State 没被 dispose）', $hidden !== null && !$hidden->disposed);

// 隐藏期间 Frame 不该重建它 —— 否则"跳过 layout"是假的
$frame->run($offElement, BoxConstraints::tight(new Size(320.0, 240.0)), 64.0);
check('Routing · 隐藏期间的 Frame 走不到它（脏标记留着）', $hidden !== null && $hidden->needsBuild());

// 切回来 ⇒ 同一个 State（§12.3"保留"档的全部意义）
$backElement = Offstage::make($offstage->child(), true);
$offElement->update($backElement);
$restored = $offElement->childAt(0)?->state();
check('Routing · 切回来 ⇒ State 是同一个对象（状态没丢）', $restored === $hidden);
// ★ 隐藏期间攒下的脏标记在**切回来那一刻就兑现**（update 内部 settle），
//   不等下一帧、也不用谁手动 rebuild。这正是"切回来不是旧画面"的机制保证：
//   若改成"留着等 Frame"，帧还没跑时画面就是旧的 —— 而且是静默的。
check(
    'Routing · 切回来 ⇒ 隐藏期间攒的 setState 立即兑现',
    $hidden !== null && !$hidden->needsBuild() && $hidden->builds === 2 && $hidden->bumps === 1,
    'builds=' . (string) ($hidden?->builds ?? -1) . ' bumps=' . (string) ($hidden?->bumps ?? -1)
);

// ② 另一种"消失"是丢弃：整个 Offstage 被卸载 ⇒ State 必须 dispose（不许泄漏）
//    ★ 先可见再隐藏：隐藏子层拿不到引用（那正是"树里没有它"的证明），
//      所以要在它还看得见时把 State 抓在手里。
$doomed = Offstage::make(new CounterWidget());
$doomElement = $doomed->createElement();
$doomElement->mount(null, $context);
$doomState = $doomElement->childAt(0)?->state();
$offElement->update(Offstage::make($doomed->child(), false));
$doomElement->update(Offstage::make($doomed->child(), false));
$doomElement->unmount();
check('Routing · 隐藏页被卸载 ⇒ State 仍走 dispose（不漏）', $doomState !== null && $doomState->disposed);

fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㉗ ThemeScope：面覆盖（§2446 / §3171）──

// ① 子树看到的是覆盖后的主题，外层不受影响
$lightRoot = ThemeScope::make(ThemeData::light(), new ThemeSpy())->createElement();
$lightRoot->mount(null, $context);
$lightSpy = $lightRoot->childAt(0)?->state();
check('ThemeScope · 外层是浅色 ⇒ 子层看到浅色', $lightSpy !== null && !$lightSpy->sawDark);

$darkRoot = ThemeScope::make(ThemeData::dark(), new ThemeSpy())->createElement();
$darkRoot->mount(null, $context);
$darkSpy = $darkRoot->childAt(0)?->state();
check('ThemeScope · 子树看到覆盖后的暗色', $darkSpy !== null && $darkSpy->sawDark);
check('ThemeScope · 覆盖不外溢（外层 ctx 仍是浅色）', !$context->theme()->isDark());

// ② 嵌套 ⇒ 最内层赢，没有特异性权重（§2446 原话"谁生效查父链"）
$nested = ThemeScope::make(
    ThemeData::light(),
    ThemeScope::make(ThemeData::dark(), new ThemeSpy()),
)->createElement();
$nested->mount(null, $context);
$nestedSpy = $nested->childAt(0)?->childAt(0)?->state();
check('ThemeScope · 嵌套 ⇒ 最内层赢（不算特异性权重）', $nestedSpy !== null && $nestedSpy->sawDark);

// ③ 切换主题 ⇒ 子层换表。**两种兑现时机**（Element::applyContext 的注释写清了）：
//    有状态节点只标脏、等帧末；无状态节点立即重建。两种都在这儿钉住。
$scopedChild = new ThemeSpy(); // Widget 不可变 ⇒ 复用一个实例（换主题不换控件）
$switchRoot = ThemeScope::make(ThemeData::light(), $scopedChild)->createElement();
$switchRoot->mount(null, $context);
$switchSpy = $switchRoot->childAt(0)?->state();
check('ThemeScope · 切换前是浅色', $switchSpy !== null && !$switchSpy->sawDark);
$switchRoot->update(ThemeScope::make(ThemeData::dark(), $scopedChild));
check(
    'ThemeScope · 切主题 ⇒ 有状态子层先只标脏（§5.2 裁决 3，不在事件中途重建）',
    $switchSpy !== null && $switchSpy->needsBuild() && $switchSpy->builds === 1
);
$switchRoot->settle(); // 帧末：与 Frame.run 走同一条 settle()
check(
    'ThemeScope · 帧末兑现 ⇒ 子层看到暗色，且 State 没换（切主题不丢状态）',
    $switchSpy !== null && $switchSpy->sawDark && $switchSpy->builds === 2
        && $switchRoot->childAt(0)?->state() === $switchSpy
);

// ④ 无状态控件**立即**兑现：它的盒是按 ctx 算的，不重算就是"换表不换色"
$scopedButton = Button::make('确定');
$buttonScope = ThemeScope::make(ThemeData::light(), $scopedButton)->createElement();
$buttonScope->mount(null, $context);
$lightBox = $buttonScope->childAt(0)?->renderObject();
check('ThemeScope · scope 内按钮先取到浅色表', $lightBox?->boxStyle()->background !== null);
$buttonScope->update(ThemeScope::make(ThemeData::dark(), $scopedButton));
$darkBox = $buttonScope->childAt(0)?->renderObject();
check(
    'ThemeScope · 切主题 ⇒ 无状态控件的盒立即重算（不是下一帧）',
    $darkBox !== null && $darkBox !== $lightBox
        && $darkBox->boxStyle()->background !== $lightBox->boxStyle()->background
);

// ④b **容器**也要立即换盒**且子盒已挂上**（§13.2：先换盒、再挂子）。
//    顺序反了（先 rebuild 后 syncBox）会让新盒为空 ⇒ 容器整棵子树静默消失。
//    ★ 这条与上面那条**不重复**：上面测叶子（自己就是盒），这条测容器（盒里还要装子盒）。
$scopedColumn = Column::make()->children([Text::make('a'), Text::make('b')]);
$columnScope = ThemeScope::make(ThemeData::light(), $scopedColumn)->createElement();
$columnScope->mount(null, $context);
$columnScope->update(ThemeScope::make(ThemeData::dark(), $scopedColumn));
$columnBox = $columnScope->childAt(0)?->renderObject();
check(
    'ThemeScope · 切主题 ⇒ 容器的盒立即重算**且子盒已挂上**（§13.2 顺序）',
    $columnBox instanceof RenderFlex && count($columnBox->children()) === 2,
    sprintf(
        'box=%s kids=%s',
        $columnBox === null ? 'null' : $columnBox::class,
        $columnBox instanceof RenderFlex ? (string) count($columnBox->children()) : 'n/a',
    ),
);

// ⑤ 端到端：同一按钮在两份表下取到不同底色（§15.7 纪律 3 只解析一次）
$darkButton = Button::make('确定')->createRenderObject(
    (new BuildContext(320.0, 240.0, ThemeData::dark(), $context->metrics()))
);
$lightButton = Button::make('确定')->createRenderObject(
    (new BuildContext(320.0, 240.0, ThemeData::light(), $context->metrics()))
);
check(
    'ThemeScope · 同一按钮在两份表下取到不同底色（§15.7 纪律 3 只解析一次）',
    $darkButton->boxStyle()->background !== $lightButton->boxStyle()->background
);

// ⑤ ThemeScope 是结构节点：W5 起也有 `background()`（修饰是字段），但不产 BoxStyle
check(
    'ThemeScope · 结构节点也有 background()，但不产 BoxStyle',
    method_exists(ThemeScope::class, 'background') && !method_exists(ThemeScope::class, 'box')
);

fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㉘ PageHost：换页 = 旧页 Offstage 化（§12.1 第 4/7 条）──

$nav = new NavStore();
$navTable = RouteTable::make()
    ->route('users', static fn (): Widget => Text::make('用户列表'))
    ->route('signup', static fn (): Widget => Text::make('注册'));
$host = PageHost::make()->source($nav)->select(static fn (NavStore $s): string => $s->page())->table($navTable);
$hostElement = $host->createElement();
$hostElement->mount(null, $context);

/** @var PageHostState $hostState */
$hostState = $hostElement->state();
check('PageHost · 首帧选出当前页', $hostState !== null && $hostState->page() === 'users');
check('PageHost · 初始就两页都挂上（各一个 Offstage）', $hostElement->childCount() === 1);
$usersOffstage = $hostElement->childAt(0)?->childAt(0);
$signupOffstage = $hostElement->childAt(0)?->childAt(1);
check(
    'PageHost · 当前页可见、另一页隐藏',
    $usersOffstage instanceof OffstageElement && $usersOffstage->childCount() === 1
        && $signupOffstage instanceof OffstageElement && $signupOffstage->childCount() === 0
);

// 换页：旧页隐藏但**不卸载**（State 留着），新页可见
$nav->go('signup');
check('PageHost · notify 后选中新页', $hostState !== null && $hostState->page() === 'signup');
$hostElement->settle();
check(
    'PageHost · 换页 ⇒ 旧页进 Offstage、新页可见',
    $hostElement->childAt(0)?->childAt(0)?->childCount() === 0
        && $hostElement->childAt(0)?->childAt(1)?->childCount() === 1
);

// ★ 每页只造一次：切回来是同一个 Widget 实例（否则"保留状态"是空话）
$firstUsers = $hostElement->childAt(0)?->childAt(0);
$nav->go('users');
$hostElement->settle();
$backUsers = $hostElement->childAt(0)?->childAt(0);
check(
    'PageHost · 切回来 ⇒ 页面是同一个实例（工厂没有二次调用）',
    $firstUsers !== null && $firstUsers === $backUsers && $hostState !== null && $hostState->cachedCount() === 2,
    '缓存 ' . (string) ($hostState?->cachedCount() ?? -1) . ' 页'
);

// 同值 notify 被门控挡住（§11.3.3）：别的字段变了，页名没变 ⇒ 不换页
$notifiesBefore = $nav->notifyCount();
$nav->touchOtherField();
check(
    'PageHost · 同值 notify 被门控挡住（两次 select 输出相等）',
    $hostState !== null && !$hostState->needsBuild()
);
// 反证：通知真的到了（否则"门控挡下"与"订阅没联上"长得一样）
check(
    'PageHost · 被门控挡的通知仍到达订阅者（排除"订阅没联上"）',
    $nav->notifyCount() === $notifiesBefore + 1
);

// 未命中：开放域 ⇒ 什么都不显示，不崩（§12.1 第 2 条）
$nav->go('ghost');
$hostElement->settle();
$ghostSlot = $hostElement->childAt(0)?->childAt(2);
check(
    'PageHost · 未命中的页名 ⇒ 末槽是可见的 NoPage（不崩、不静默错误）',
    $ghostSlot instanceof OffstageElement
        && $ghostSlot->childCount() === 1
        && $ghostSlot->childAt(0)?->widget() instanceof NoPage
);

fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㉙ Application：组合根（§4.5 / §11.4.3 全链）──

$surface3 = new SurfaceProbe();
$surface3->resize(320, 240, 1.0);
$app = new EchoApplication();
$app->attach($surface3, BoxConstraints::tight(new Size(320.0, 240.0)));

check('Application · buildRoot 被调一次（组合根只组合）', $app->kerned);
check('Application · 根 Element 挂上且有盒', $app->root() !== null && $app->rootBox() !== null);
check('Application · attach 即跑首帧（表面收到 beginFrame）', $surface3->frames === 1);
check('Application · title() 由应用给', $app->title() === '冒烟应用');

// 平台驱动后续帧：每调一次 surface 多一帧
$app->frame(16.0, BoxConstraints::tight(new Size(320.0, 240.0)));
$app->frame(32.0, BoxConstraints::tight(new Size(320.0, 240.0)));
check('Application · frame() 由平台驱动（每调一次一帧）', $surface3->frames === 3 && $surface3->ends === 3);

// ★ 命令派发链（§11.4.3 四环齐全）
//   第 ①② 环：树内冒泡；第 ③ 环：CommandRouter；第 ④ 环：谁都没接 ⇒ Diagnostic
check(
    'Application · 注册表里的命令接得走（第 ③ 环）',
    $app->dispatch('echo.hello') === true
);

// ★ 顺序：树内冒泡**优先于**注册表。同一个名字两边都能接 ⇒ 树内先接，
//   注册表的 handler 一次都不该被调到。顺序反了页面就永远收不到自己的命令。
$ordered = new EchoApplication();
$ordered->withCommandWidget = true;
$ordered->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
check(
    'Application · 树内冒泡优先于注册表（页面自己最知道命令是不是它的）',
    $ordered->dispatch('widget.echo') === true
);
$routerHits = 0;
$ordered->root()?->visitChildren(function (\Drift\Widget\Element $e) use (&$routerHits): void {
    $st = $e instanceof \Drift\Widget\StatefulElement ? $e->state() : null;
    if ($st instanceof \Drift\Dev\Smoke\Commands\CommandState) {
        $routerHits = count($st->handled);
    }
});
check(
    'Application · 树内接走后注册表没被调（handler 的 seen 不增长）',
    $routerHits === 1
);
check(
    'Application · 链上谁都没接 ⇒ 返回 false + Diagnostic（不静默）',
    $app->dispatch('nobody.wants.this') === false
);
check(
    'Application · 没人接的命令写进环形缓冲',
    str_contains((string) json_encode(\Drift\Errors::recentDiagnostics()), 'nobody.wants.this')
);

fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㉚ drift-preview：无头渲染一页到 SVG（§16.3 / §A.5）──

$previewSurface = new SvgSurface();
$preview = new Renderer($previewSurface, $context->metrics(), ThemeData::light());
$svg = $preview->render(new DemoPage(), 320.0, 240.0, 1.0);
check(
    'Preview · SVG 有内容（像素 run > 0，不是空白页）',
    substr_count($svg, '<rect x=') > 0,
    'run 数 ' . (string) substr_count($svg, '<rect x=')
);
check(
    'Preview · 根盒排开到视口尺寸（320 × 240 紧约束）',
    $preview->effectiveRoot()?->size()->width === 320.0,
    (string) ($preview->effectiveRoot()?->size()->width ?? -1.0)
);
// ★ 组合节点对渲染树透明：页面根是 StatelessWidget（无盒），但其下的 Column
//   必须被外层视口收到 —— 少了这一步画面整块消失且不报错（0×0 在 tight 下合法）
check(
    'Preview · 组合根（无盒）下的渲染盒被外层收到（中间层透明）',
    $preview->effectiveRoot()?->size()->height === 240.0
);
check(
    'Preview · dpr=2 ⇒ 设备像素翻倍（§6.1 唯一量化点）',
    substr_count($preview->render(new DemoPage(), 320.0, 240.0, 2.0), 'viewBox="0 0 640 480"') === 1
);

// ★ 回归：应用根是**组合节点**（`StatelessWidget`，自己没盒、底下才有）
//   ⇒ 以前 `Frame::run` 拿 `renderObject()` 得到 null ⇒ 直接 return
//   ⇒ **整屏空白且不报错**（C6 只查上界，什么都不画也不算违反约束）。
//   现在走 `effectiveBox()`，中间层对渲染树透明。
$compositeSurface = new SurfaceProbe();
$compositeSurface->resize(320, 240, 1.0);
$compositeApp = new CompositeRootApp();
$compositeApp->attach($compositeSurface, BoxConstraints::tight(new Size(320.0, 240.0)));
check(
    'Application · 组合根（无盒）仍能排到视口尺寸',
    $compositeApp->rootBox()?->size()->width === 320.0,
    (string) ($compositeApp->rootBox()?->size()->width ?? -1.0)
);
check(
    'Application · 组合根的画面有墨（不是静默空白）',
    $compositeSurface->canvas !== null && inkCount($compositeSurface->canvas) > 0,
    '墨点数 ' . (string) ($compositeSurface->canvas !== null ? inkCount($compositeSurface->canvas) : -1)
);

// ── ㉛ apps/hello：第一个真实消费者（§4.5 / §A.1）──

$hello = new HelloApplication();
$helloSurface = new SurfaceProbe();
$helloSurface->resize(320, 240, 1.0);
$hello->attach($helloSurface, BoxConstraints::tight(new Size(320.0, 240.0)));
check('apps/hello · 应用只写 buildRoot/title/commands（§4.5）', $hello->title() === 'Hello Drift');
check('apps/hello · 无平台也能挂载（表面是注入的，§7.2 裁决 4）', $hello->root() !== null);
check(
    'apps/hello · 屏幕中央有「Hello Drift」（画出墨了）',
    $helloSurface->canvas !== null && inkCount($helloSurface->canvas) > 0,
    '墨点数 ' . (string) ($helloSurface->canvas !== null ? inkCount($helloSurface->canvas) : -1)
);
check(
    'apps/hello · 根排满视口（Center 撑满紧约束）',
    $hello->rootBox()?->size()->width === 320.0 && $hello->rootBox()?->size()->height === 240.0
);

// preview 的 --app 路径：Application 也能无头出图（§12.2）
$helloSvgSurface = new SvgSurface();
$helloSvg = (new Renderer($helloSvgSurface, $context->metrics(), ThemeData::light()))
    ->renderApp(new HelloApplication(), 320.0, 240.0, 1.0);
check(
    'apps/hello · drift-preview --app 出 SVG（应用可独立预览）',
    substr_count($helloSvg, '<rect x=') > 0,
    'run 数 ' . (string) substr_count($helloSvg, '<rect x=')
);

// ★ 回归：文本**必须吃画布变换**。`drawRect` 走 `toDevice()`（跟变换），
//   而 `drawParagraph` 曾直接用传入坐标（不跟）⇒ `Center` 布局算出的偏移
//   在 paint 时被丢掉 ⇒ 文字永远贴左上角，且墨点数一模一样（最难查的一档）。
$shiftCanvas = new RasterCanvas(320, 240, Color::white(), Scale::of(1.0));
$shiftCanvas->translate(50.0, 20.0);
$shiftPara = $context->metrics()->layout('Hi', TextStyle::of(14.0), INF, Scale::of(1.0));
$shiftList = new DisplayList();
(new RecordingCanvas($shiftList))->drawParagraph($shiftPara, Paint::fill(Color::black()), 0.0, 0.0);
$shiftList->replay($shiftCanvas);
$shiftBounds = inkBounds($shiftCanvas);
check(
    'Rendering · 文本吃画布平移（translate 之后墨迹整体挪位）',
    $shiftBounds !== null && $shiftBounds[0] >= 50 && $shiftBounds[2] >= 20,
    '包围盒 ' . ($shiftBounds === null ? 'null' : implode(',', $shiftBounds))
);

// ★ 回归：hello 的「Hello Drift」是**居中**的，不是贴着左上角
$helloBounds = inkBounds($helloSurface->canvas);
check(
    'apps/hello · 文字居中（Center 的偏移真被画出来）',
    $helloBounds !== null
        && abs(($helloBounds[0] + $helloBounds[1]) / 2 - 160) <= 4
        && abs(($helloBounds[2] + $helloBounds[3]) / 2 - 120) <= 4,
    '包围盒 ' . ($helloBounds === null ? 'null' : implode(',', $helloBounds))
);

// ── ㊷ apps/tutorial：从零写一个应用（`docs/tutorial.md` 的配套代码）───────
//
// ★ 这一节的作用不是"证明 tutorial 能跑"，是**给教程绑一条闸门**：
//   `docs/tutorial.md` 讲的那几条（四段结构 / overlayPanel 零接线 / after 要接线）
//   一旦被后续改动打破，这里先红，而不是等下一次有人照抄教程时才发现。
// ★ 断言全部走**公开面**（`dispatchInput` + `findElement`），不碰 State 私有字段
//   —— 教程要教的就是"从外面看得见的行为"。

$tutConstraints = BoxConstraints::tight(new Size(720.0, 900.0));
$tutApp = new TutorialApplication();
$tutSurface = new SurfaceProbe();
$tutSurface->resize(720, 900, 1.0);
$tutApp->attach($tutSurface, $tutConstraints);

check('apps/tutorial · 应用只写 buildRoot/title（§4.5）', $tutApp->title() === 'Drift 教程');
check(
    'apps/tutorial · 首屏有墨（四段都排开了，不是静默空白）',
    $tutSurface->canvas !== null && inkCount($tutSurface->canvas) > 0,
    '墨点数 ' . (string) ($tutSurface->canvas !== null ? inkCount($tutSurface->canvas) : -1)
);

// 四段标题都在树上 —— 少一段 = 那段建树失败（它自己**不会报错**，只是不出现）
$tutTitles = ['① 最小应用', '② 计数器', '③ 浮层（声明式缝，零接线）', '④ 异步（唯一需要接线的一段）'];
$tutFound = 0;
foreach ($tutTitles as $tutTitle) {
    if (findElement(
        $tutApp->root(),
        static fn (Element $e): bool => $e->widget() instanceof Text && $e->widget()->content() === $tutTitle
    ) !== null) {
        $tutFound++;
    }
}
check('apps/tutorial · 四段标题都在树上', $tutFound === 4, '找到 ' . $tutFound . ' / 4');

/** 按控件标签点一下（盒中心）并走一帧；找不到盒 ⇒ 返回 false（断言会因此红） */
$tutClick = static function (
    Application $app,
    callable $pred,
    float $timestamp,
    BoxConstraints $constraints,
): bool {
    $element = findElement($app->root(), $pred);
    $box = $element?->renderObject();
    $size = $box?->size();
    if ($box === null || $size === null) {
        return false;
    }
    $origin = boxOrigin($box);
    $x = $origin[0] + $size->width / 2;
    $y = $origin[1] + $size->height / 2;
    $app->dispatchInput(PointerEvent::down($x, $y), $timestamp);
    $app->dispatchInput(PointerEvent::up($x, $y), $timestamp + 1.0);
    $app->frame($timestamp + 2.0, $constraints);

    return true;
};

/** 树上有没有这段文字 */
$tutHasText = static function (Element $root, string $needle): bool {
    return findElement(
        $root,
        static fn (Element $e): bool => $e->widget() instanceof Text
            && str_contains($e->widget()->content(), $needle)
    ) !== null;
};

/**
 * 浮层（任一 entry）里有没有这个标签。
 *
 * ★ 不取 `top()`：浮层栈顶可能是遮罩那一层，面板在它下面 —— 扫全部 entry
 *   才不会"看着开了、内容却找不到"。
 * ★ 同时认 `Text` 与 `Button`：面板里的选项是 `Button::make($city)`，
 *   **不是** `Text`（第一版只找 `Text` ⇒ 假红一次）。
 */
$tutOverlayHasLabel = static function (Application $app, string $needle): bool {
    foreach ($app->overlays()->all() as $entry) {
        $element = $entry->element;
        if ($element === null) {
            continue;
        }
        if (findElement(
            $element,
            static function (Element $e) use ($needle): bool {
                $widget = $e->widget();
                if ($widget instanceof Button) {
                    return $widget->label() === $needle;
                }

                return $widget instanceof Text && str_contains($widget->content(), $needle);
            }
        ) !== null) {
            return true;
        }
    }

    return false;
};

// ── ② 计数器：点 +1 ⇒ 文本真的变（State + setState 全链）──
check(
    'apps/tutorial · ② 计数器初始为 0',
    $tutHasText($tutApp->root(), 'count = 0')
);
$tutClicked = $tutClick(
    $tutApp,
    static fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === '+1',
    100.0,
    $tutConstraints
);
check('apps/tutorial · ② 找得到 +1 按钮并点中（盒中心命中）', $tutClicked);
check(
    'apps/tutorial · ② 点一次 ⇒ count = 1（setState 帧末统一重建）',
    $tutHasText($tutApp->root(), 'count = 1'),
    '树上仍是 ' . ($tutHasText($tutApp->root(), 'count = 0') ? 'count = 0' : '别的值')
);

// ── ③ 浮层：`overlayPanel()` 是声明式缝 ⇒ 激活即开，不需要组合根接线 ──
$tutOverlayBase = $tutApp->overlays()->count();
$tutOpened = $tutClick(
    $tutApp,
    static fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === '选择城市',
    200.0,
    $tutConstraints
);
check('apps/tutorial · ③ 找得到「选择城市」并点中', $tutOpened);
check(
    'apps/tutorial · ③ 激活 ⇒ 浮层多一层（overlayPanel 缝真的通）',
    $tutApp->overlays()->count() === $tutOverlayBase + 1,
    '层数 ' . (string) $tutApp->overlays()->count() . ' / 基线 ' . (string) $tutOverlayBase
);
// ★ 再走一帧：`placeOverlays()` 在 settle **之前**跑 ⇒ 面板内容是**下一帧**才建出来的
//   （真机上靠 `panelsLagBehind()` 把帧时钟上膛，用例里得自己多跑一帧）。
$tutApp->frame(210.0, $tutConstraints);
check(
    'apps/tutorial · ③ 浮层里是那三个选项（面板内容真建出来了）',
    $tutOverlayHasLabel($tutApp, '上海'),
    '浮层层数 ' . (string) $tutApp->overlays()->count()
);

// ★★ 浮层暴露的**框架缺陷**回归（2026-10-25，`RenderButton` 内禀宽漏 insets）：
//   `PopupPanel` 按 `child->intrinsicWidth(INF)` 定面板宽 ⇒ 按钮内禀宽若只算标签、
//   不算自己的左右内边距，面板就会比标签还窄 ⇒ **中文逐字竖排**（实测面板 48 宽、
//   按钮 28 宽、"北京"两行）。修前 `intrinsicWidth(INF)` 是 28 而实际排出 60。
//
//   ★ 判据取**「自然排出来的宽 === 内禀宽」**这条不变量，不写死像素：
//     它是"内禀宽表达的是真尺寸"的定义本身，改字号 / 换主题都不会假红。
//   ★ 用 `+1` 按钮（在 `Row` 里按自然宽排，没被 `Spacer` 挤）而不是面板里的按钮 ——
//     面板里那个正是被压的对象，拿它判会两边一起变小、看着"相等"。
$tutPlusBox = findElement(
    $tutApp->root(),
    static fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === '+1'
)?->renderObject();
$tutPlusSize = $tutPlusBox?->size();
check(
    'Layout · Button 内禀宽 = 自然排出来的宽（含内边距；漏了 ⇒ PopupPanel 挤成竖排）',
    $tutPlusBox !== null && $tutPlusSize !== null
        && $tutPlusBox->intrinsicWidth(INF) === $tutPlusSize->width,
    '内禀 ' . (string) ($tutPlusBox?->intrinsicWidth(INF) ?? -1.0)
        . ' / 实排 ' . (string) ($tutPlusSize?->width ?? -1.0)
);

// 再点一次 ⇒ 收回去（"激活 = 开/关"）；关掉之后 ④ 的点击才不会被遮罩吞掉
$tutClosed = $tutClick(
    $tutApp,
    static fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === '选择城市',
    220.0,
    $tutConstraints
);
check('apps/tutorial · ③ 再激活一次 ⇒ 浮层收回（开/关是同一个入口）', $tutClosed && $tutApp->overlays()->count() === $tutOverlayBase);

// ── ④ 异步：`after()` 由组合根递下来；700ms 后结果到达 ──
$tutLoad = $tutClick(
    $tutApp,
    static fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === '开始加载',
    300.0,
    $tutConstraints
);
check('apps/tutorial · ④ 找得到「开始加载」并点中', $tutLoad);
check(
    'apps/tutorial · ④ 点了 ⇒ 进加载态（loading 一帧都不能少）',
    $tutHasText($tutApp->root(), '加载中')
);
check(
    'apps/tutorial · ④ 还没到点 ⇒ 结果不出现（防"当场触发"）',
    !$tutHasText($tutApp->root(), '结果到了')
);
$tutApp->frame(1200.0, $tutConstraints);
check(
    'apps/tutorial · ④ 700ms 后 ⇒ 结果到达（after 的回调在 UI 线程 setState）',
    $tutHasText($tutApp->root(), '结果到了')
);

// ── ㊷b apps/showcase：示例台无头挂载（2026-10-25 补的闸门）─────────────────
// ★★ **为什么必须有这一段**：composer.json 早就注册了 `App\Showcase\`，但 smoke
//   从来没挂过这个应用 ⇒ 应用侧代码是"只有真机能证伪"的盲区。2026-10-25 真机
//   实测：`ShowcaseRoot` 里写了一个**不存在的** `IconName::Refresh`
//   （解释器 = Fatal "Undefined constant"，AOT = **Segmentation fault**、
//   没有任何文件名行号），而 smoke 1461 全绿。
//   ⇒ 这一段把"应用能挂起来"变成 smoke 断言，未定义的 enum case / 构造期错误
//     在解释器面就红，不等真机。
// ★ 视口取 **1920×1017**（真机客户区的实测值）：示例台左导航有 22 项，
//   800 高会 `RenderFlex 放不下` 直接 FATAL（那是视口不够，不是缺陷）——
//   夹具必须用真实尺寸，否则断言红得没有意义。
$sc2App = new ShowcaseApplication();
$sc2Surface = new SurfaceProbe();
$sc2Surface->resize(1920, 1017, 1.0);
$sc2Constraints = BoxConstraints::loose(new Size(1920.0, 1017.0));
$sc2App->attach($sc2Surface, $sc2Constraints);
$sc2App->frame(16.0, $sc2Constraints);
check('apps/showcase · 应用只写 buildRoot/title/commands（§4.5）', $sc2App->title() === 'Drift 示例台');
check('apps/showcase · 无平台也能挂载（表面是注入的，§7.2 裁决 4）', $sc2App->root() !== null);
check(
    'apps/showcase · 首屏有墨（顶条 + 侧栏 + 内容都排开了，不是静默空白）',
    $sc2Surface->canvas !== null && inkCount($sc2Surface->canvas) > 0,
    '墨点数 ' . (string) ($sc2Surface->canvas !== null ? inkCount($sc2Surface->canvas) : -1)
);

// AppBar::actions()（2026-10-25 新增的右动作位）：两个图标按钮必须在树上。
// ★ 按**可访问名**找（`IconButton` 的 label 不进视觉，只作 UIA Name）——
//   这正是"纯图标按钮必须有两个参数"的理由在测试面的兑现。
$sc2Actions = 0;
findElement(
    $sc2App->root(),
    static function (Element $e) use (&$sc2Actions): bool {
        if ($e->widget() instanceof IconButton && \in_array($e->widget()->label(), ['搜索', '刷新'], true)) {
            $sc2Actions++;

            return false;
        }

        return false;
    }
);
check(
    'apps/showcase · AppBar::actions() 的两个图标按钮在树上（按可访问名）',
    $sc2Actions === 2,
    '找到 ' . $sc2Actions . ' / 2'
);

// 命令缝（§11.4.3）：`showcase.refresh` 必须真有注册的 handler。
// ★ 未注册命令会走 `Errors::DIAGNOSTIC`（CommandRouter）—— 那种失败**不抛**，
//   所以"点了没反应"只能靠"handler 在表里"这条正断言来堵。
$sc2HasRefresh = false;
foreach ($sc2App->commands() as $sc2Name => $sc2Handler) {
    if ($sc2Name === 'showcase.refresh' && $sc2Handler instanceof CommandHandler) {
        $sc2HasRefresh = true;
    }
}
check(
    'apps/showcase · showcase.refresh 有注册的 handler（不是死命令）',
    $sc2HasRefresh,
    'commands=' . implode(',', array_keys(iterator_to_array($sc2App->commands())))
);

// ★ Feedback 页的模态演示（2026-10-25）：`Modal` 在此前**没有任何应用侧消费者**，
//   关闭按钮落地后补的入口。这条断言盯的是"组合根 → 页面 → showModal"这条链
//   真的通（缺一环就是"按钮点了没反应"，而那种失败不抛错）。
// ★ 必须**先切到 Feedback 页**：showcase 的页是按需建的（`pageFor($selected)()`），
//   首屏只有第 0 页 ⇒ 不点导航的话 Feedback 页的按钮根本不在树上。
//   （第一版直接 findElement ⇒ 假红。）
$sc2NavFeedback = static fn (Element $e): bool => $e->widget() instanceof NavButton
    && $e->widget()->label() === '反馈 Feedback';
$sc2NavEl = findElement($sc2App->root(), $sc2NavFeedback);
$sc2NavBox = $sc2NavEl?->renderObject();
check('apps/showcase · 左导航找得到「反馈 Feedback」', $sc2NavBox !== null && $sc2NavBox->size() !== null);
if ($sc2NavBox !== null && $sc2NavBox->size() !== null) {
    [$sc2NavX, $sc2NavY] = boxOrigin($sc2NavBox);
    $sc2NavW = $sc2NavBox->size()->width;
    $sc2NavH = $sc2NavBox->size()->height;
    $sc2App->dispatchInput(PointerEvent::down($sc2NavX + $sc2NavW / 2.0, $sc2NavY + $sc2NavH / 2.0), 100.0);
    $sc2App->frame(102.0, $sc2Constraints);
}
check(
    'apps/showcase · 切到 Feedback 页（标题出现在树上）',
    $tutHasText($sc2App->root(), '反馈 Feedback')
);
$sc2ModalBtn = findElement(
    $sc2App->root(),
    static fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === '打开对话框'
);
check(
    'apps/showcase · Feedback 页有「打开对话框」入口（Modal 有应用侧消费者）',
    $sc2ModalBtn !== null,
);
// 端到端：点它 ⇒ `showModal` 闭包被调 ⇒ 模态真的开着
if ($sc2ModalBtn !== null) {
    $sc2BtnBox = $sc2ModalBtn->renderObject();
    if ($sc2BtnBox !== null && $sc2BtnBox->size() !== null) {
        [$sc2BtnX, $sc2BtnY] = boxOrigin($sc2BtnBox);
        $sc2BtnW = $sc2BtnBox->size()->width;
        $sc2BtnH = $sc2BtnBox->size()->height;
        $sc2App->dispatchInput(PointerEvent::down($sc2BtnX + $sc2BtnW / 2.0, $sc2BtnY + $sc2BtnH / 2.0), 200.0);
        $sc2App->frame(202.0, $sc2Constraints);
    }
}
check(
    'apps/showcase · 点「打开对话框」⇒ 模态真的开着（组合根接线通）',
    $sc2App->isModalOpen() && $sc2App->modal()?->showsCloseIcon() === true,
    'open=' . ($sc2App->isModalOpen() ? 'yes' : 'no'),
);
// 关掉它（走刚注入的那条 dismiss），验证"开 → 关"闭环
$sc2App->closeModal();
check('apps/showcase · closeModal 关掉（闭环，不残留）', !$sc2App->isModalOpen());

// ── ㉜ 光标形状（§9.1「一次命中，三方消费」的第 ③ 方）──
// ★ 三方 = 事件路由 / 语义 / **光标形状**。前两方早就在（`focusHit()`；语义树是
//   M4 的活）；第 ③ 方此前**完全缺位** ⇒ 真机窗口的指针永远是箭头，**且不报错**
//   （§9.1 只把"光标形状"写进了名字，没有一行代码兑现它）。
// ★ 本段只判**解释器面判得了的**：形状表（= 桥的 ABI）· 声明落到盒上 · 命中链
//   取最近者 · 应用层出口。**"系统光标真的变成手形"只能真机判**（`GetCursorInfo`），
//   不在这里假装 —— 那是 §20 纪律 1 的双向量具纪律。

check('光标 · Default 是 0（= 桥的 IDC_ARROW，兜底值）', CursorShape::Default->value === 0);
check(
    '光标 · IBeam=1 / PointingHand=2（★ value 就是桥的 ABI：改它等于改协议）',
    CursorShape::IBeam->value === 1 && CursorShape::PointingHand->value === 2,
);
$shapeValues = array_map(static fn (CursorShape $c): int => $c->value, CursorShape::cases());
check(
    '光标 · 8 个 case、value 恰好是 0..7 且互不重复（桥的 switch 覆盖面）',
    $shapeValues === [0, 1, 2, 3, 4, 5, 6, 7],
    'values=' . implode(',', $shapeValues),
);
check(
    '光标 · from() 往返（桥只传编号，PHP 侧能还原形状）',
    CursorShape::from(2) === CursorShape::PointingHand,
);

// 声明：Widget::cursor() ⇒ 盒上的 RenderObject::cursor()
check(
    '光标 · 没声明 ⇒ cursorShape() 为 null（"我不管，问上面"）',
    Text::make('x')->cursorShape() === null,
);
check(
    '光标 · Widget::cursor() 是声明（链式 + 可读回）',
    Text::make('x')->cursor(CursorShape::IBeam)->cursorShape() === CursorShape::IBeam,
);

// ★ 用 tight 约束：`Center` 的盒是**贴身**的（size = 孩子 size），loose 下盒只有
//   文字那么大 ⇒ 命中点得算字体度量。tight 下盒铺满，命中点取 (10,10) 即可，
//   与度量无关（§20 纪律 1：断言不该依赖它不打算测的东西）。
$curContext = smokeContext();
$curRoot = Center::make()->cursor(CursorShape::PointingHand)->child(Text::make('hit me'))->createElement();
$curRoot->mount(null, $curContext);
$curBox = $curRoot->effectiveBox();
$curBox?->layout(BoxConstraints::tight(new Size(300.0, 200.0)));
check(
    '光标 · 声明落到了盒上（Element::syncBox 是唯一落点）',
    $curBox?->cursor() === CursorShape::PointingHand,
);

$curFm = new FocusManager();

// 内层声明盖过外层：Padding(Wait) > Center(PointingHand) > Text(无)
$nestRoot = Padding::make(Center::make()->cursor(CursorShape::PointingHand)->child(Text::make('deep')))
    ->cursor(CursorShape::Wait)
    ->createElement();
$nestRoot->mount(null, $curContext);
$nestBox = $nestRoot->effectiveBox();
$nestBox?->layout(BoxConstraints::tight(new Size(300.0, 200.0)));
$nestHit = $nestBox?->hitTest(10.0, 10.0);
check(
    '光标 · 命中链取**最近**声明者（内层 PointingHand 盖过外层 Wait）',
    $curFm->cursorOf($nestHit) === CursorShape::PointingHand,
    'resolved=' . $curFm->cursorOf($nestHit)->name . '｜hit=' . ($nestHit === null ? 'null' : $nestHit::class),
);

// 命中者自己没声明 ⇒ 沿父链上溯
$outerRoot = Padding::make(Center::make()->child(Text::make('deep')))
    ->cursor(CursorShape::Wait)
    ->createElement();
$outerRoot->mount(null, $curContext);
$outerBox = $outerRoot->effectiveBox();
$outerBox?->layout(BoxConstraints::tight(new Size(300.0, 200.0)));
$outerHit = $outerBox?->hitTest(10.0, 10.0);
check(
    '光标 · 命中者自己没声明 ⇒ 沿父链上溯到声明者（Wait）',
    $curFm->cursorOf($outerHit) === CursorShape::Wait,
    'resolved=' . $curFm->cursorOf($outerHit)->name . '｜hit=' . ($outerHit === null ? 'null' : $outerHit::class),
);

// 整条链没人声明 ⇒ Default（不是 null）
$bareRoot = Center::make()->child(Text::make('bare'))->createElement();
$bareRoot->mount(null, $curContext);
$bareBox = $bareRoot->effectiveBox();
$bareBox?->layout(BoxConstraints::tight(new Size(300.0, 200.0)));
check(
    '光标 · 整条链没人声明 ⇒ Default（★ 不是 null：Default 是**明确决定**）',
    $curFm->cursorOf($bareBox?->hitTest(10.0, 10.0)) === CursorShape::Default,
);
check('光标 · 没命中任何盒（点在根外）⇒ Default', $curFm->cursorOf(null) === CursorShape::Default);

// 应用面：dispatchInput() 的返回值**就是**喂给 WindowHost::setCursor() 的形状
$curApp = new CursorApplication();
check(
    '光标 · 未挂载 ⇒ dispatchInput 返回 null（没有树就没有路由）',
    $curApp->dispatchInput(PointerEvent::down(1.0, 1.0)) === null,
);
$curApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
check(
    '光标 · 按键不吃光标（dispatchInput(KeyEvent) 返回 null，焦点链照常走）',
    $curApp->dispatchInput(KeyEvent::down(KeyChord::tab())) === null,
);
check(
    '光标 · 指针落在控件上 ⇒ dispatchInput 返回声明形状（第 ③ 方的出口）',
    $curApp->dispatchInput(PointerEvent::down(160.0, 120.0)) === CursorShape::PointingHand,
);
check(
    '光标 · 指针落在窗口外 ⇒ 返回 Default（壳据此把光标还原成箭头）',
    $curApp->dispatchInput(PointerEvent::down(-5.0, -5.0)) === CursorShape::Default,
);

// ── ㉗ Widget.Spinner：加载指示器（§11.5 三态 Loading 分支，2026-10-16 落地）──

/** 把一个盒画到白底光栅上（§14.1：同输入 ⇒ 同指纹，golden 的物质基础） */
function spinCanvas(RenderBox $box, int $width, int $height): RasterCanvas
{
    $list = new DisplayList();
    $box->paint(new RecordingCanvas($list));

    $canvas = new RasterCanvas($width, $height, Color::white(), Scale::of(1.0));
    $list->replay($canvas);

    return $canvas;
}

$spinRoot = Spinner::make()->diameter(40.0)->createElement();
$spinRoot->mount(null, $context);
$spinBox = $spinRoot->effectiveBox();
check(
    'Spinner · 挂载即建出渲染盒（State 直接产盒 → RenderSpinner）',
    $spinBox instanceof RenderSpinner,
);
$spinBox?->layout(BoxConstraints::loose(new Size(200.0, 200.0)));
check(
    'Spinner · 宽松约束 ⇒ 拿自然直径（40×40）',
    $spinBox?->size()?->width === 40.0 && $spinBox?->size()?->height === 40.0,
);
check(
    'Spinner · 内禀尺寸 = 直径（另一轴不限）',
    $spinBox?->intrinsicWidth(INF) === 40.0 && $spinBox?->intrinsicHeight(INF) === 40.0,
);

// 约束比直径小 ⇒ 缩小而不报 C6（装饰性控件；C6 只罚"画到盒外"）
$tightSpin = Spinner::make()->diameter(40.0)->createElement();
$tightSpin->mount(null, $context);
$tightSpin->effectiveBox()?->layout(BoxConstraints::tight(new Size(16.0, 16.0)));
check(
    'Spinner · 紧约束(16) < 直径(40) ⇒ 缩到约束内（C6 不响）',
    $tightSpin->effectiveBox()?->size()?->width === 16.0,
);

// 紧约束比直径大 ⇒ 盒被父定死（C6 下界提升，框架统一行为），但墨迹不拉伸
$fillSpin = Spinner::make()->diameter(40.0)->createElement();
$fillSpin->mount(null, $context);
$fillBox = $fillSpin->effectiveBox();
$fillBox?->layout(BoxConstraints::tight(new Size(200.0, 200.0)));
check(
    'Spinner · 紧约束(200) > 直径(40) ⇒ 盒被父定死 200×200',
    $fillBox?->size()?->width === 200.0,
);
$fillCanvas = spinCanvas($fillBox, 200, 200);
$fMinX = PHP_INT_MAX;
$fMaxX = -1;
$fMinY = PHP_INT_MAX;
$fMaxY = -1;
$fInk = 0;
for ($y = 0; $y < $fillCanvas->height(); $y++) {
    for ($x = 0; $x < $fillCanvas->width(); $x++) {
        if (!$fillCanvas->pixelAt($x, $y)->equals(Color::white())) {
            $fInk++;
            $fMinX = min($fMinX, $x);
            $fMaxX = max($fMaxX, $x);
            $fMinY = min($fMinY, $y);
            $fMaxY = max($fMaxY, $y);
        }
    }
}
check(
    'Spinner · 授予盒 200×200 但墨迹不拉伸（≈40×40 且居中 —— 图标语义）',
    $fInk > 0
        && ($fMaxX - $fMinX + 1) <= 42 && ($fMaxY - $fMinY + 1) <= 42
        && abs($fMinX + $fMaxX - 199) <= 4 && abs($fMinY + $fMaxY - 199) <= 4,
    sprintf('ink=%d bounds=%d×%d center=(%d,%d)', $fInk, $fMaxX - $fMinX + 1, $fMaxY - $fMinY + 1, (int) (($fMinX + $fMaxX) / 2), (int) (($fMinY + $fMaxY) / 2)),
);

// 纯函数：同相位渲染两次 ⇒ 同指纹
$sigA = md5(spinCanvas($spinBox, 200, 200)->toRgba());
$sigB = md5(spinCanvas($spinBox, 200, 200)->toRgba());
check('Spinner · 同相位渲染两次指纹一致（§14.1 纯函数）', $sigA === $sigB);

// 墨迹结构：8 个点 = 8 档渐隐 ⇒ 墨里恰好 8 种颜色（alpha 尾迹真的画上了）
$dotCanvas = spinCanvas($spinBox, 200, 200);
$spinInk = 0;
$shades = [];
for ($y = 0; $y < $dotCanvas->height(); $y++) {
    for ($x = 0; $x < $dotCanvas->width(); $x++) {
        $pixel = $dotCanvas->pixelAt($x, $y);
        if (!$pixel->equals(Color::white())) {
            $spinInk++;
            $shades[$pixel->toArgb()] = true;
        }
    }
}
check('Spinner · 8 个点都有墨（ink > 0）', $spinInk > 0, 'ink=' . $spinInk);
check(
    'Spinner · 8 档渐隐 = 8 种颜色（不是 8 个同样实心的点）',
    count($shades) === 8,
    'shades=' . count($shades),
);

// 动画：tick() 只标脏，帧末才重建；重建后墨迹变化
$spinState = $spinRoot->state();
check('Spinner · State 只建一次（初相位 0）', $spinState instanceof SpinnerState && $spinState->step() === 0);
$spinState?->tick();
check(
    'Spinner · tick 只标脏、不立即重建（§5.2 裁决 3）',
    $spinState?->needsBuild() === true,
);
$spinSurface = new SurfaceProbe();
$spinSurface->resize(200, 200, 1.0);
$spinFrame = new Frame($spinSurface);
$spinFrame->run($spinRoot, BoxConstraints::tight(new Size(200.0, 200.0)), 16.0);
$spinBox2 = $spinRoot->effectiveBox();
check(
    'Spinner · 帧末重建后换成新盒',
    $spinBox2 !== $spinBox && $spinBox2 instanceof RenderSpinner,
);
check('Spinner · 相位前进一步（step 0 → 1）', $spinState instanceof SpinnerState && $spinState->step() === 1);
check(
    'Spinner · 相位变 ⇒ 墨迹变（动画真的在走）',
    md5(spinCanvas($spinBox2, 200, 200)->toRgba()) !== $sigA,
);

// ── ㉗b 帧时钟驱动的动画（§17.4.10，2026-10-18）──
//    ★ 这里量的是"**没人调 `tick()`**，相位也自己走"。真机侧由
//    `tools/probe-async.php` 的 ⑦⑧（帧间变化像素数）守。
$spinApp = new SpinnerApplication();
$spinApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(120.0, 120.0)));
$spinAppRoot = $spinApp->root();
$spinAppState = $spinAppRoot instanceof StatefulElement ? $spinAppRoot->state() : null;
check(
    '§17.4.10 · 首帧就推进了动画（attach 走同一入口）',
    $spinAppState instanceof SpinnerState && $spinAppState->step() === 1,
);
check('§17.4.10 · 有动画在跑 ⇒ wantsFrame() 为真', $spinApp->wantsFrame() === true);
$spinApp->frame(16.0, BoxConstraints::tight(new Size(120.0, 120.0)));
$spinApp->frame(32.0, BoxConstraints::tight(new Size(120.0, 120.0)));
check(
    '§17.4.10 · 每帧推进一格（没人调 tick）',
    $spinAppState instanceof SpinnerState && $spinAppState->step() === 3,
);

// 反例方向：没有动画的应用不该要求下一帧（**空闲不空转**的另一半）
$quietApp = new EchoApplication();
$quietApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(120.0, 120.0)));
check('§17.4.10 · 无动画的应用 wantsFrame() 为假（空闲不空转）', $quietApp->wantsFrame() === false);

// ── ㉗c `createRenderObjectFromState()`：有态控件直接产盒（`02` §6.2）──
//    以前"有态"与"有盒"必须是两个类（`Spinner → SpinnerState → SpinnerBox`，
//    第三类的唯一职责是把相位递进去）。现在 StatefulWidget 可以直接从 State
//    产盒 ⇒ 叶子控件两段就够，`SpinnerBox` 已删。
//    ★ 夹具 `LeafBoxWidget` 数 `build()` 调用次数 —— 叶子路径下一次都不该调。
LeafBoxWidget::resetBuildCalls();
$leafRoot = LeafBoxWidget::make()->createElement();
$leafRoot->mount(null, $context);
check(
    '叶子态控件 · 挂载即有盒（State 直接产盒，不经过 build）',
    $leafRoot->effectiveBox() instanceof SolidBox,
);
check(
    '叶子态控件 · 没有子节点（叶子语义：不产子）',
    $leafRoot->childCount() === 0,
    'childCount=' . $leafRoot->childCount(),
);
check(
    '叶子态控件 · `State::build()` 零调用',
    LeafBoxWidget::$buildCalls === 0,
    'calls=' . LeafBoxWidget::$buildCalls,
);
$leafModRoot = LeafBoxWidget::make()->paddingAll(8.0)->createElement();
$leafModRoot->mount(null, $context);
check(
    '叶子态控件 · 修饰落在自己身上（`setMods()` 没被漏掉 —— 漏了会撞 §11.7 守卫）',
    $leafModRoot->effectiveBox()?->mods()?->padLeft() === 8.0,
);

// `Spinner` 也走这条路（它的 `SpinnerBox` 已删）—— 结构上不可能再走 build
$spinLeafRoot = Spinner::make()->diameter(30.0)->createElement();
$spinLeafRoot->mount(null, $context);
check(
    'Spinner · 走叶子路径（有盒、无子 ⇒ 不再需要 SpinnerBox）',
    $spinLeafRoot->effectiveBox() instanceof RenderSpinner && $spinLeafRoot->childCount() === 0,
    'box=' . ($spinLeafRoot->effectiveBox() === null ? 'null' : $spinLeafRoot->effectiveBox()::class)
    . ' childCount=' . $spinLeafRoot->childCount(),
);

// 量具反向自检：不覆写钩子 ⇒ `build()` 真被调用（计数器不是死的）
[$lbCode, $lbOut] = runCase(__FILE__, 'leafbox', null);
$lbCalls = 0;
if (preg_match('/buildCalls=(\d+)/', $lbOut, $lbM) === 1) {
    $lbCalls = (int) $lbM[1];
}
check(
    '叶子态控件 · 反向自检：不覆写钩子 ⇒ build() 被调用（计数器有牙）',
    $lbCode === 0 && $lbCalls >= 1,
    'rc=' . $lbCode . ' calls=' . $lbCalls . ' out=' . trim($lbOut),
);

// `State::build()` 的默认实现（§6.3）：只为"直接产盒"的控件存在，返回零尺寸
$leafDefaultState = new LeafBoxState();
$defaultBuilt = $leafDefaultState->build($context);
$defaultBox = $defaultBuilt->createElement();
$defaultBox->mount(null, $context);
$defaultBox->effectiveBox()?->layout(BoxConstraints::loose(new Size(100.0, 100.0)));
check(
    'State::build() 默认实现 · 返回零尺寸盒（不再抽象，叶子控件不需要它）',
    $defaultBuilt instanceof SizedBox
        && $defaultBox->effectiveBox()?->size()?->width === 0.0
        && $defaultBox->effectiveBox()?->size()?->height === 0.0,
);

// ── ㉘ 多页外壳：Dock / AppBar / NavButton + 命令激活（§12.1 / §12.2，2026-10-16 落地）──

// RenderDock 五槽布局（直接构造渲染盒，与 C5 负例同一条测试路径）
$dock = new RenderDock(Overflow::Report);
$dock->setSlot('top', new SolidBox(new Size(100.0, 20.0), 100.0, 20.0));
$dock->setSlot('bottom', new SolidBox(new Size(100.0, 15.0), 100.0, 15.0));
$dock->setSlot('left', new SolidBox(new Size(30.0, 65.0), 30.0, 65.0));
$dock->setSlot('right', new SolidBox(new Size(25.0, 65.0), 25.0, 65.0));
$dock->setSlot('center', new SolidBox(new Size(45.0, 65.0), 45.0, 65.0));
$dock->layout(BoxConstraints::tight(new Size(100.0, 100.0)));
// ★ 五槽改用 `expectBox()`：**绝对原点 + 尺寸四个数一起断**。原来的写法只断
//   `offsetX/Y`（相对内容盒）+ 部分尺寸 —— left 槽的 x 和宽、right 槽的宽都
//   没人管，而"整体偏一个 padding"正是这类断言最容易漏的错。
expectBox($dock->slot('top'), 0.0, 0.0, 100.0, 20.0, 'Dock · top 槽：全宽贴顶、拿自然高');
expectBox($dock->slot('bottom'), 0.0, 85.0, 100.0, 15.0, 'Dock · bottom 槽：全宽贴底（100−15）');
expectBox($dock->slot('left'), 0.0, 20.0, 30.0, 65.0, 'Dock · left 轨：中带全高、贴左');
expectBox($dock->slot('right'), 75.0, 20.0, 25.0, 65.0, 'Dock · right 轨：贴右（100−25）');
expectBox($dock->slot('center'), 30.0, 20.0, 45.0, 65.0, 'Dock · center 槽：tight 填满剩余矩形（45×65 @ 30,20）');

// 量具自检：上面 10 条用 `expectBox()` 的断言**全绿**只说明"它们都对"，不说明
// "助手会红"。这里跑一个故意错的子进程，把"会红"也变成断言（`07` §11.2 #4/#5）。
[$ebCode, $ebOut] = runCase(__FILE__, 'expectbox', null);
check(
    '量具自检 · expectBox 给错期望时会红（三种失败路径各一条，消息带期望与实际）',
    $ebCode === 0
        && substr_count($ebOut, 'FAIL') === 3
        && str_contains($ebOut, '期望 (5, 2) 10×6，实际 (4, 2) 10×6')
        && str_contains($ebOut, '盒为 null（没挂上 / 没 layout）')
        && str_contains($ebOut, '盒 size() 为 null（layout 没跑）'),
    'code=' . $ebCode . ' ｜ ' . str_replace("\n", ' ⏎ ', $ebOut)
);

// ── 无界主轴：Dock 取内容自然高（§24.2 #18，2026-10-17 修）──
// 触发形状 = ScrollView 给内容的约束（主轴无界"先量有多长"）。判据：
// ① 自身高有限且 = 20 + max(左40,中50,右10) + 15 = 85（**不是** INF）
// ② bottom offsetY = topH + midH = 70（**不是** INF —— 旧行为正是它）
// ③ 中带三槽铺满 midH=50（不是各自自然高、也不是 INF）
// ④ bottom 仍贴**内容**底：20 + 50 + 15 == 85
// SolidBox 的 intrinsic 与 box 故意不同（见其注释）⇒ 这条夹具同时钉住
// "midH 真的来自 intrinsicHeight"。
$infDock = new RenderDock(Overflow::Report);
$infDock->setSlot('top', new SolidBox(new Size(100.0, 20.0), 100.0, 20.0));
$infDock->setSlot('bottom', new SolidBox(new Size(100.0, 15.0), 100.0, 15.0));
$infDock->setSlot('left', new SolidBox(new Size(30.0, 40.0), 30.0, 40.0));
$infDock->setSlot('center', new SolidBox(new Size(45.0, 50.0), 45.0, 77.0)); // intrinsic 77 > 左40 右10 ⇒ max
$infDock->setSlot('right', new SolidBox(new Size(25.0, 10.0), 25.0, 10.0));
$infSize = $infDock->layout(new BoxConstraints(0.0, 100.0, 0.0, INF));
check(
    'Dock · 无界主轴：自身高 = 内容自然高 112（20+max(40,77,10)+15），不是 INF',
    $infSize->height === 112.0,
    var_export($infSize->height, true),
);
check(
    'Dock · 无界主轴：bottom offsetY = 97（topH+midH，不是 INF）',
    $infDock->slot('bottom')?->offsetY() === 97.0,
    var_export($infDock->slot('bottom')?->offsetY(), true),
);
check(
    'Dock · 无界主轴：中带三槽铺满 midH=77（来自 center 的 intrinsicHeight）',
    $infDock->slot('left')?->size()?->height === 77.0
        && $infDock->slot('center')?->size()?->height === 77.0
        && $infDock->slot('right')?->size()?->height === 77.0,
);
check(
    'Dock · 无界主轴：有界行为不动（复跑 tight 100x100 五槽数值不变）',
    $dock->slot('bottom')?->offsetY() === 85.0 && $dock->slot('center')?->offsetY() === 20.0,
);

// 元素层：挂载、封存、槽盒挂接（盒挂接不变量）
$shellRoot = Dock::make()
    ->top(AppBar::make()->title('外壳')->children([NavButton::make('列表')->command('nav.users')]))
    ->center(Column::make()->children([Text::make('内容')]))
    ->createElement();
$shellRoot->mount(null, $context);
$shellBox = $shellRoot->effectiveBox();
check('Dock · 挂载即建出 RenderDock', $shellBox instanceof RenderDock);
check(
    'Dock · 槽盒按声明挂接（top/center 有盒，left/right/bottom 为空）',
    $shellBox instanceof RenderDock
        && $shellBox->slot('top') !== null && $shellBox->slot('center') !== null
        && $shellBox->slot('left') === null && $shellBox->slot('right') === null && $shellBox->slot('bottom') === null,
);
check('Dock · 挂载后全树封存', $shellRoot->widget()->isSealed());

// 条的宽拉满 / 轨的高铺满（§12.1 第 3 条"全宽横条 / 中带全高竖轨"）
$stretchRoot = Dock::make()
    ->top(AppBar::make()->title('条')->children([NavButton::make('a')->command('x')]))
    ->left(Padding::make(Text::make('轨'))->all(4.0))
    ->center(Text::make('内容'))
    ->createElement();
$stretchRoot->mount(null, $context);
$stretchBox = $stretchRoot->effectiveBox();
$stretchBox?->layout(BoxConstraints::tight(new Size(300.0, 200.0)));
check(
    'Dock · 横条拉满全宽（AppBar 300 宽，不是自然宽）',
    $stretchBox instanceof RenderDock && $stretchBox->slot('top')?->size()?->width === 300.0,
    (string) ($stretchBox instanceof RenderDock ? $stretchBox->slot('top')?->size()?->width : -1.0),
);
check(
    'Dock · 竖轨铺满中带高（轨高 = 200 − 条高）',
    $stretchBox instanceof RenderDock
        && $stretchBox->slot('left')?->size()?->height === 200.0 - ($stretchBox->slot('top')?->size()?->height ?? 0.0),
);

// AppBar：标题 + children 一行；chrome 底有墨
$barRoot = AppBar::make()->title('用户管理')->children([
    NavButton::make('列表')->command('nav.users'),
])->createElement();
$barRoot->mount(null, $context);
$barBox = $barRoot->effectiveBox();
$barBox?->layout(BoxConstraints::tight(new Size(300.0, 60.0)));
check('AppBar · 渲染盒是 RenderAppBar（RenderFlex 的 chrome 子类）', $barBox instanceof RenderAppBar);
$barKids = [];
$barBox?->visitChildren(function (RenderObject $kid) use (&$barKids): void {
    if ($kid instanceof RenderBox) {
        $barKids[] = $kid;
    }
});
check(
    'AppBar · 标题与按钮排成一行（按钮在标题右侧、不重叠）',
    count($barKids) === 2
        && $barKids[1]->offsetX() >= $barKids[0]->offsetX() + ($barKids[0]->size()?->width ?? 0.0),
    sprintf('%d 个子盒', count($barKids)),
);
check('AppBar · chrome 底有墨（底色真的画了）', inkCount(spinCanvas($barBox, 300, 60)) > 0);

// AppBar::windowButtons()（P1-Stage 1b 无边框窗）：三个窗口按钮 + 标准命令名。
// ★ 命令名是 `AppBar` 与组合根 `WindowCommands` 之间的**唯一契约** —— 写错一个字
//   的现象是"按钮点了没反应"（没人注册那个命令），不报错、不写日志。
// ★ 判据落在**顺序**上（最小化 / 最大化 / 关闭 从左到右），且必须是**最后三个**、
//   前面紧跟弹性占位（否则按钮不贴右边）。
$wbBar = AppBar::make()->title('条')->windowButtons();
$wbKids = $wbBar->barKids();
$wbNames = [];
foreach ($wbKids as $wbKid) {
    if ($wbKid instanceof Button) {
        $wbNames[] = $wbKid->commandName();
    }
}
check(
    'AppBar · windowButtons ⇒ 三个窗口按钮（标准命令名，顺序固定）',
    $wbNames === ['window.minimize', 'window.maximize', 'window.close'],
    'names=' . implode(',', array_map(static fn(?string $n): string => (string) $n, $wbNames)),
);
check(
    'AppBar · windowButtons ⇒ 按钮贴右边（前面紧跟弹性占位，且是最后三个子项）',
    count($wbKids) === 5
        && $wbKids[1] instanceof Spacer
        && $wbKids[2] instanceof IconButton
        && $wbKids[4] instanceof IconButton,
    'kids=' . count($wbKids),
);
check(
    'AppBar · 不声明 windowButtons ⇒ 一个窗口按钮都不画（有原生标题栏的窗口不该有两排）',
    array_filter(
        AppBar::make()->title('条')->barKids(),
        static fn($k): bool => $k instanceof Button && str_starts_with((string) $k->commandName(), 'window.'),
    ) === [],
    '意外画了窗口按钮',
);

// AppBar::skipFocus() ⇒ 整组工具按钮不进 Tab 序（§A.3.4）
$skipRoot = AppBar::make()->children([
    NavButton::make('a')->command('x'),
    NavButton::make('b')->command('y'),
])->skipFocus()->createElement();
$skipRoot->mount(null, $context);
$skipFm = new FocusManager();
$skipFm->collect([$skipRoot]);
check('AppBar · skipFocus ⇒ 整组按钮不进 Tab 序', $skipFm->order() === []);

$keepRoot = AppBar::make()->children([
    NavButton::make('a')->command('x'),
    NavButton::make('b')->command('y'),
])->createElement();
$keepRoot->mount(null, $context);
$keepFm = new FocusManager();
$keepFm->collect([$keepRoot]);
check('AppBar · 不声明 skipFocus ⇒ 按钮照常进 Tab 序（2 个）', count($keepFm->order()) === 2);

// 命令激活（§9.1：焦点按钮"回车/空格能触发"）—— 两条路径各一遍
$navApp = new NavApplication();
$navApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$navApp->dispatchInput(KeyEvent::down(KeyChord::tab()));      // 焦点 ⇒ 第一个 NavButton
$navApp->dispatchInput(KeyEvent::down(KeyChord::enter()));    // 激活 ⇒ 命令带 arg 派发
check(
    '激活 · 焦点 NavButton 的 Enter ⇒ 命令带 arg 派发（nav.users#7）',
    $navApp->recorder()->seen === ['nav.users#7/'],
    implode(',', $navApp->recorder()->seen),
);
$navApp->dispatchInput(KeyEvent::down(KeyChord::tab()));      // 焦点 ⇒ 取消按钮
$navApp->dispatchInput(KeyEvent::down(KeyChord::make('Space'))); // 激活 ⇒ onPressed 纯 UI 回调
check(
    '激活 · 焦点 Button 的 Space ⇒ onPressed 被调（纯 UI 回调路径）',
    $navApp->pressed === ['cancelled'],
    implode(',', $navApp->pressed),
);

// —— 语义节点激活（§9 / M5 无障碍的"可操作"半边 = UIA `InvokePattern` 的 PHP 侧）
//   ★ 实现落成"在节点中心派发一次合成 tap"，所以判据必须落在**效果**上
//     （`pressed` 变了），不是"方法返回 true" —— 后者在"点歪了 / 点空了"
//     的时候照样绿，等于没测。
//   ★ 这条同时锁住"激活语义只有一处"：读屏点与鼠标点走同一条
//     输入路由 → 命中 → `Activatable::activate()`。
$invApp = new NavApplication();
$invApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$invTree = SemanticsBuilder::build($invApp->rootElement(), null);
$invIdx = $invTree->findByRoleAndName(SemanticsRole::Button->value, '取消');
check(
    '语义激活 · 按节点索引点中「取消」⇒ onPressed 被调（Invoke 走合成 tap）',
    $invIdx >= 0 && $invApp->invokeSemanticsNode($invIdx) === true && $invApp->pressed === ['cancelled'],
    'idx=' . $invIdx . ' pressed=' . implode(',', $invApp->pressed),
);
check(
    '语义激活 · 越界索引 ⇒ false（壳据此回 UIA_E_ELEMENTNOTAVAILABLE，不能假装成功）',
    $invApp->invokeSemanticsNode(-1) === false && $invApp->invokeSemanticsNode(9999) === false,
    'ok',
);
// ★ 事件缝本身：壳把 UIA `Invoke` 翻成 `SemanticsInvokeEvent` 塞进输入队列，
//   `dispatchInput()` 必须接住并激活。**这条测的是"平台→框架"的接缝**，
//   上一条测的是"框架内部动作"—— 少了本条，接缝断了（事件被 `dispatch()` 的
//   `else` 兜底吞掉）也只有真机才发现，而真机现象是"命令执行了、画面不动"。
$invEvtApp = new NavApplication();
$invEvtApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$invEvtIdx = SemanticsBuilder::build($invEvtApp->rootElement(), null)
    ->findByRoleAndName(SemanticsRole::Button->value, '取消');
$invEvtApp->dispatchInput(new SemanticsInvokeEvent($invEvtIdx));
check(
    '语义激活 · SemanticsInvokeEvent 经 dispatchInput 落地（壳的接缝，不是框架内部直调）',
    $invEvtIdx >= 0 && $invEvtApp->pressed === ['cancelled'],
    'idx=' . $invEvtIdx . ' pressed=' . implode(',', $invEvtApp->pressed),
);

// —— 语义写值（§4.4 提交 2 = UIA `ValuePattern` / `RangeValuePattern` 的 PHP 侧）
//   ★ 判据落在**回写值**上（`onChanged` 真收到），不是"方法返回 true"——
//     后者在"顺着父链找到了控件但没人接值"时照样绿，等于没测。
//   ★ 反例两条：受控但没人接 onChanged（TfApplication）⇒ false；越界 ⇒ false。
$svApp = new SemValueApplication();
$svApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$svIdx = SemanticsBuilder::build($svApp->rootElement(), null)
    ->findByRoleAndName(SemanticsRole::TextField->value, '搜索');
check(
    '语义写值 · TextField 按节点索引 ⇒ onChanged 收到新值（ValuePattern 回写路径）',
    $svIdx >= 0 && $svApp->setSemanticsValue($svIdx, 'hi') === true && $svApp->got === 'hi',
    'idx=' . $svIdx . ' got=' . var_export($svApp->got, true),
);
check(
    '语义写值 · 越界索引 ⇒ false（壳据此回 UIA_E_ELEMENTNOTAVAILABLE，不假装成功）',
    $svApp->setSemanticsValue(-1, 'x') === false && $svApp->setSemanticsValue(9999, 'x') === false,
    'ok',
);
$tfNoCb = new TfApplication();
$tfNoCb->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$tfNoCbIdx = SemanticsBuilder::build($tfNoCb->rootElement(), null)
    ->findByRole(SemanticsRole::TextField->value);
check(
    '语义写值 · TextField 没接 onChanged ⇒ false（受控值没人接，不假装写成功）',
    $tfNoCbIdx >= 0 && $tfNoCb->setSemanticsValue($tfNoCbIdx, 'x') === false,
    'idx=' . $tfNoCbIdx,
);
// 事件接缝：壳把 UIA `SetValue` 翻成 `SemanticsSetValueEvent`，`dispatchInput` 必须接住。
// 少了本条，接缝断了（事件被 `dispatch()` 兜底吞掉）也只有真机才发现。
$svEvtApp = new SemValueApplication();
$svEvtApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$svEvtIdx = SemanticsBuilder::build($svEvtApp->rootElement(), null)
    ->findByRoleAndName(SemanticsRole::TextField->value, '搜索');
$svEvtApp->dispatchInput(new SemanticsSetValueEvent($svEvtIdx, '派发来的'));
check(
    '语义写值 · SemanticsSetValueEvent 经 dispatchInput 落地（壳的接缝）',
    $svEvtIdx >= 0 && $svEvtApp->got === '派发来的',
    'idx=' . $svEvtIdx . ' got=' . var_export($svEvtApp->got, true),
);

// Slider 的 Application 层：与 TextField 同一条 `SemanticsValueEditable` 路，
// 但回写走 `emit()`（夹区间）。这条同时证明"按节点索引找到的就是 Slider 本身"。
$slSvApp = new SliderApplication();
$slSvApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 400.0)));
$slSvIdx = SemanticsBuilder::build($slSvApp->root(), null)
    ->findByRole(SemanticsRole::Slider->value);
check(
    '语义写值 · Slider 按节点索引 ⇒ onChanged(77)（RangeValuePattern 回写路径）',
    $slSvIdx >= 0 && $slSvApp->setSemanticsValue($slSvIdx, '77') === true
        && $slSvApp->events === [['a', 77.0]],
    'idx=' . $slSvIdx . ' events=' . json_encode($slSvApp->events),
);

// ── 交互状态层（08 §4.1）：hover 推给 acceptsHover() 的盒 ──
//   ★ 判据落在**盒的状态**上（不是"方法被调用"）：hoverTo 是把状态推到盒，
//     画不画由盒的 paintContent 决定 —— 状态没推对，视觉一定不对。
//   ★ 反例：悬停在布局容器上 ⇒ 没有盒接受，按钮不该整块变亮。
$hvRoot = Column::make()->children([
    Button::make('悬停我'),
])->createElement();
$hvRoot->mount(null, $context);
$hvRoot->effectiveBox()?->layout(BoxConstraints::loose(new Size(320.0, 200.0)));
$hvEl = findElement($hvRoot, static fn (Element $e): bool => $e->widget() instanceof Button);
$hvBox = $hvEl?->effectiveBox();
$hvFm = new FocusManager();
check(
    '状态层 · RenderButton 声明了状态色 ⇒ acceptsHover=true',
    $hvBox !== null && $hvBox->acceptsHover() === true,
);
$hvFm->hoverTo($hvEl);
check(
    '状态层 · hoverTo(Button) ⇒ 它的盒 hovered=true',
    $hvBox !== null && $hvBox->hovered() === true,
);
$hvFm->hoverTo($hvRoot);   // 悬停在布局容器上
check(
    '状态层 · 悬停容器 ⇒ 按钮复位（列不会整块变色）',
    $hvBox?->hovered() === false,
);
$hvFm->hoverTo($hvEl);
$hvFm->hoverTo(null);      // 离开窗口
check(
    '状态层 · hoverTo(null) ⇒ 复位（离开不留"常亮"）',
    $hvBox?->hovered() === false,
);

// —— 指针侧激活（§9.1 动作缝的另一半：鼠标**按下**即触发，与 Enter/Space 同入口）
//   ★ 按下即激活：当前指针只有 down 相位（up 相位要 P1 的指针事件）。
//   ★ 判据设计：点空白（无焦点可落）必须**不**触发 —— 若实现退化成
//     "焦点非空就激活"，空点击会误触发上一个焦点控件，本条即 FAIL。
$ptrApp = new NavApplication();
$ptrApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$navBtnEl = findElement($ptrApp->rootElement(), fn (Element $e): bool => $e->widget() instanceof NavButton);
$cancelEl = findElement($ptrApp->rootElement(), fn (Element $e): bool => $e->widget() instanceof Button && !$e->widget() instanceof NavButton);
$navBox = $navBtnEl?->effectiveBox();
[$nbx, $nby] = $navBox === null ? [0.0, 0.0] : boxOrigin($navBox);
$nbw = $navBox?->size()?->width ?? 0.0;
$nbh = $navBox?->size()?->height ?? 0.0;
$ptrApp->dispatchInput(PointerEvent::down($nbx + $nbw / 2, $nby + $nbh / 2));
check(
    '激活 · 鼠标按下「列表」按钮 ⇒ 命令带 arg 派发（与 Enter 同入口）',
    $ptrApp->recorder()->seen === ['nav.users#7/'],
    implode(',', $ptrApp->recorder()->seen),
);
$ptrApp->dispatchInput(PointerEvent::down(160.0, 210.0));   // Center 区：Text 不可聚焦
check(
    '激活 · 点空白（无焦点可落）⇒ 不误触发上一个焦点控件',
    $ptrApp->recorder()->seen === ['nav.users#7/'],
    implode(',', $ptrApp->recorder()->seen),
);
$cancelBox = $cancelEl?->effectiveBox();
[$cbx, $cby] = $cancelBox === null ? [0.0, 0.0] : boxOrigin($cancelBox);
$cbw = $cancelBox?->size()?->width ?? 0.0;
$cbh = $cancelBox?->size()?->height ?? 0.0;
$ptrApp->dispatchInput(PointerEvent::down($cbx + $cbw / 2, $cby + $cbh / 2));
check(
    '激活 · 鼠标按下「取消」按钮 ⇒ onPressed 被调（焦点随点击转移）',
    $ptrApp->pressed === ['cancelled'],
    implode(',', $ptrApp->pressed),
);

// 无焦点时激活键 ⇒ 无事发生（不崩、不误派发）
$idleApp = new NavApplication();
$idleApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$idleApp->dispatchInput(KeyEvent::down(KeyChord::enter()));
check(
    '激活 · 无焦点时 Enter ⇒ 无事发生',
    $idleApp->recorder()->seen === [] && $idleApp->pressed === [],
);

// 非激活键不触发（Enter/Space 之外的键不该激活按钮）
$quietApp = new NavApplication();
$quietApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$quietApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
$quietApp->dispatchInput(KeyEvent::down(KeyChord::left()));
check(
    '激活 · 方向键不触发激活（只有 Enter/Space）',
    $quietApp->recorder()->seen === [] && $quietApp->pressed === [],
);

// —— 能力接口 `Activatable`（S1 第 10 步 4j-b，2026-10-25）——
//   ★ 为什么要有这一组：重构把 `InputRouter::activateFocusedWidget()` 的 4 条
//     `instanceof`（Menu / ToggleBase / Segment / Button）换成了一次能力判断。
//     **漏 implements 的后果是静默的** —— 框架 `return`、Enter 什么都不做，
//     而"直调 activate()"的用例照样绿（那种调用不经过框架）。
check(
    'Activatable · 四个可激活控件都声明了能力（漏一个 ⇒ 框架激活静默失效）',
    Button::make('b') instanceof Activatable
        && Menu::make('m') instanceof Activatable
        && Checkbox::make('c') instanceof Activatable
        && Segment::make('s', 1) instanceof Activatable,
);

// Menu 覆写 ⇒ 只走 onOpen，**不**落 Button 的命令。
//   ★ 旧实现靠"Menu 分支必须排在 Button 之前"达到同一语义（顺序脆弱，注释自己写着）；
//     现在是虚方法覆写。
//   ★★ 判据必须**两边都记**：只记 onOpen 的话，"命令也派发了"这件事看不出来
//      （实测：第一版断言就是这么写的，把 Menu 改成 `parent::activate()` 之后
//      它照样绿）。所以 dispatcher 与 onOpen 记进同一个数组 —— 谁多跑一次都红。
$menuSaw = [];
$menuProbe = Menu::make('文件')
    ->command('menu.should.not.fire')
    ->onOpen(function (Menu $m, ?Element $anchor) use (&$menuSaw): void {
        $menuSaw[] = $anchor === null ? 'open:no-anchor' : 'open:anchor';
    });
$menuProbe->activate(new ActivationContext(null, function (string $command, CommandArg $arg) use (&$menuSaw): void {
    $menuSaw[] = 'cmd:' . $command;
}));
check(
    'Activatable · Menu::activate ⇒ 只走 onOpen（Button 的命令不被派发）',
    $menuSaw === ['open:no-anchor'],
    implode(',', $menuSaw),
);

// Button：命令优先；无命令才回落 onPressed（与搬迁前逐字相同）
$cmdSaw = [];
$cmdProbe = Button::make('b')
    ->command('cmd.a')
    ->onPressed(function () use (&$cmdSaw): void {
        $cmdSaw[] = 'pressed';
    });
$cmdProbe->activate(new ActivationContext(null, function (string $command, CommandArg $arg) use (&$cmdSaw): void {
    $cmdSaw[] = $command;
}));
check(
    'Activatable · Button::activate ⇒ 命令优先，onPressed 不调',
    $cmdSaw === ['cmd.a'],
    implode(',', $cmdSaw),
);

$pressSaw = [];
$pressProbe = Button::make('b')->onPressed(function () use (&$pressSaw): void {
    $pressSaw[] = 'pressed';
});
$pressProbe->activate(ActivationContext::none());
check(
    'Activatable · 无命令的 Button::activate ⇒ 回落 onPressed（上下文可以什么都不给）',
    $pressSaw === ['pressed'],
    implode(',', $pressSaw),
);

// ToggleBase / Segment：激活 = 换值，上下文里没有它们要的东西
$toggleSaw = [];
$cbProbe = Checkbox::make('c')->checked(false)->onChanged(function (bool $on) use (&$toggleSaw): void {
    $toggleSaw[] = $on ? 'on' : 'off';
});
$cbProbe->activate(ActivationContext::none());
check(
    'Activatable · Checkbox::activate ⇒ 换值（false ⇒ true）',
    $toggleSaw === ['on'],
    implode(',', $toggleSaw),
);

// —— `RenderObject::panelRect()` 能力方法（4j-c）——
//   ★ 判据必须成对：普通盒**不表态**（否则 `hitsModalPanel()` 对所有命中都返回
//     true ⇒ 点遮罩关不掉模态）；面板盒**表态**（否则 `OverlayLayout` 拿不到
//     面板矩形 ⇒ 落位算错）。只测一边等于没测。
$plainBox = new RenderDivider(new DividerStyle(Color::fromRgba(0, 0, 0, 255), 1.0), 100.0);
check('panelRect · 普通盒默认 null（不表态）', $plainBox->panelRect() === null);
check('panelRect · 面板盒覆写（表态，供落位与模态判定）', (new RenderPopupPanel())->panelRect() !== null);

// ── ㉝ 错误边界容器 + AcceleratorTable（任务 #5：M3，O-12 A′ + §9.1 第 ① 环）──

// A. AcceleratorTable 单元（§9.1）
$accel = AcceleratorTable::make()
    ->bind(KeyChord::ctrl('R'), 'users.refresh')
    ->bind(KeyChord::f(5), 'users.refresh');
check(
    'AcceleratorTable · 绑定后可查（Ctrl+R / F5 ⇒ 同一命令）',
    $accel->resolve(KeyChord::ctrl('R')) === 'users.refresh'
        && $accel->resolve(KeyChord::f(5)) === 'users.refresh',
);
check(
    'AcceleratorTable · 未绑定的组合 ⇒ null（调用方继续走焦点链）',
    $accel->resolve(KeyChord::ctrl('N')) === null,
);
check(
    'AcceleratorTable · 修饰键不同 ⇒ 不同组合（Ctrl+R ≠ Ctrl+Shift+R）',
    $accel->resolve(KeyChord::make('R', true, false, true)) === null,
);
check('AcceleratorTable · 计数 2 / 空表 isEmpty', $accel->count() === 2 && AcceleratorTable::make()->isEmpty());

[$code, $output] = runCase(__FILE__, 'accelconflict', null);
check(
    'AcceleratorTable · 同一组合绑两次 ⇒ Fatal（exit 3，G2 冲突不静默）',
    $code === 3,
    '实际 ' . $code . ' / ' . $output,
);
check(
    'AcceleratorTable · 冲突写了 Errors.log',
    str_contains(logText($log), 'AcceleratorTable'),
);

// B. 错误边界 · 构造期（O-12 第 ① 层：createRenderObject 抛 ⇒ rebuild 的 try 捕获）
$ebCtor = ErrorBoundary::make(ConstructBoom::make())->createElement();
$ebCtor->mount(null, $context);
check(
    '错误边界 · 构造期异常被容器捕获（不冒泡，窗口其余部分继续）',
    $ebCtor instanceof ErrorBoundaryElement && $ebCtor->hasCaught(),
);
check(
    '错误边界 · 捕获后子树换成占位（ErrorPlaceholder）',
    $ebCtor->childAt(0)?->widget() instanceof ErrorPlaceholder,
);
check(
    '错误边界 · 占位带错误原文（G2：不吞不改写）',
    str_contains((string) $ebCtor->caughtMessage(), 'construct boom'),
);
$ebCtor->effectiveBox()?->layout(BoxConstraints::tight(new Size(240.0, 80.0)));
check(
    '错误边界 · 占位可布局出墨（Center+Text 真画了东西）',
    inkCount(spinCanvas($ebCtor->effectiveBox(), 240, 80), Color::white()) > 0,
);
// ★ 2026-10-16 改判：确定性测试字体已补上**示例/应用字符串里用到的全部非 ASCII 字符**
//   （`CjkGlyphData`，含中文标点与箭头）⇒ 中文文本现在**有墨**。真正的通用 CJK 后端
//   （任意码位、按需打包）仍归 M4（O-08）；这条断言从此守"补的字模真的上屏"。
//   ★ 旧版是**空断言**：探针盒从未 `layout()` ⇒ `RenderText::lineCount()===0` ⇒ 恒 0 墨，
//     加字之前也是 0 ⇒ 它一直通过，却什么都没测到（量具无自检的又一处）。
$cjkProbe = Text::make('出错')->createElement();
$cjkProbe->mount(null, $context);
$cjkBox = $cjkProbe->effectiveBox();
$cjkBox?->layout(BoxConstraints::tight(new Size(240.0, 80.0)));
$cjkInk = inkCount(spinCanvas($cjkBox, 240, 80), Color::white());
check(
    '字体边界 · 确定性测试字体覆盖示例用字 ⇒ 中文有墨',
    $cjkInk > 0,
    (string) $cjkInk,
);

// C. 错误边界 · build 期（第 ② 层：State::build 抛 ⇒ settle 的 try 捕获）
$ebBuild = ErrorBoundary::make(BoomPage::make())->createElement();
$ebBuild->mount(null, $context);
check(
    '错误边界 · 健康时完全透明（孩子就是原页面）',
    $ebBuild->childAt(0)?->widget() instanceof BoomPage && !$ebBuild->hasCaught(),
);
$boomState = $ebBuild->childAt(0) instanceof StatefulElement ? $ebBuild->childAt(0)->state() : null;
if ($boomState instanceof BoomState) {
    $boomState->boom(); // 标脏 ⇒ 帧末重建 ⇒ 第二次 build 抛
}
$ebBuild->settle();
check(
    '错误边界 · build 期异常被 settle() 捕获（帧末重建路径）',
    $ebBuild->hasCaught() && str_contains((string) $ebBuild->caughtMessage(), 'build boom'),
);
check(
    '错误边界 · build 期捕获后也换占位',
    $ebBuild->childAt(0)?->widget() instanceof ErrorPlaceholder,
);
// ★ W2/S3：settle() 从"单遍"变成"多轮循环"后，这一条才真的有意义 ——
//   边界覆写的已改名 settlePass() ⇒ **每一遍**都在边界内，异常不会从第 2 遍起
//   绕过边界直冒帧循环（那会让错误边界粒度从"到节点"退化成"到整帧"且不报错）。
check(
    '错误边界 · 多轮收敛下仍到节点（异常没冒到帧循环）',
    !str_contains(implode('|', Errors::recentDiagnostics()), '帧循环：未捕获异常'),
    '环形缓冲里出现了帧循环级未捕获异常',
);

// D. 错误边界 · 渲染期（第 ③ 层：盒自己兜底，不冒泡）
//    ★ loose 下才看得到"自然尺寸"：tight 的 constrain 会把下界提到上限
//      （Spinner 任务确认过的语义 —— 紧约束下父把尺寸定死），fallback 的
//      160×48 会被撑成 200×200，那是约束语义不是兜底失效。
$rebLayout = new RenderErrorBoundary();
$rebLayout->setChild(new LayoutBoomBox());
$rebLayout->layout(BoxConstraints::loose(new Size(200.0, 200.0)));
check(
    '错误边界 · 渲染期 layout 异常 ⇒ 兜底自然尺寸（160×48，不放大到 INF）',
    $rebLayout->size()?->width === 160.0 && $rebLayout->size()?->height === 48.0,
    sprintf('实际 %.6g × %.6g', $rebLayout->size()?->width ?? -1, $rebLayout->size()?->height ?? -1),
);
$rebTight = new RenderErrorBoundary();
$rebTight->setChild(new LayoutBoomBox());
$rebTight->layout(BoxConstraints::tight(new Size(200.0, 200.0)));
check(
    '错误边界 · tight 约束下兜底地被提到上限（constrain 语义，非失效）',
    $rebTight->size()?->width === 200.0,
);
$rebPaint = new RenderErrorBoundary();
$rebPaint->setChild(new PaintBoomBox());
$rebPaint->layout(BoxConstraints::tight(new Size(100.0, 60.0)));
$paintThrew = false;
try {
    $rebPaint->paint(new RecordingCanvas(new DisplayList()));
} catch (Throwable) {
    $paintThrew = true;
}
check(
    '错误边界 · 渲染期 paint 异常 ⇒ 盒兜底（paint 不冒泡）',
    !$paintThrew,
);
check(
    '错误边界 · paint 兜底有墨（红底叉框真的画了）',
    inkCount(spinCanvas($rebPaint, 100, 60)) > 0,
);

// E. 无边界 ⇒ Fatal 序列（S3 的另半边：帧循环吞异常是 G2 不容的半静默）
[$code, $output] = runCase(__FILE__, 'noboundary', null);
check(
    '帧循环 S3 · 无边界 ⇒ Fatal 序列（exit 3，绝不吞掉冻窗口）',
    $code === 3,
    '实际 ' . $code . ' / ' . $output,
);
check(
    '帧循环 S3 · Fatal 写了 Errors.log（可观测）',
    str_contains(logText($log), '未捕获异常冒到帧末'),
);

// F. 加速键接线（§9.1 第 ① 环：焦点之前）
$accelApp = new AccelApplication();
$accelApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$accelApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('R')));
check(
    '加速键 · Ctrl+R ⇒ 命令走全链（第 ① 环命中，焦点之前）',
    $accelApp->seen === ['accel.fire'],
);
$accelApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
check(
    '加速键 · 未绑定的键不走第 ① 环（Tab 仍归焦点链，seen 不变）',
    $accelApp->seen === ['accel.fire'],
);

// G. 让位规则 ②：焦点控件声明独占 ⇒ 不吃快捷键
$claimApp = new AccelApplication();
$claimApp->withClaimer = true;
$claimApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$claimApp->dispatchInput(KeyEvent::down(KeyChord::tab())); // 焦点落到 TextField（文档序第一个可聚焦）
check(
    '加速键 · 焦点控件声明独占 Ctrl+A ⇒ 让位给输入框（seen 为空）',
    $claimApp->seen === [],
);
$claimApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('R')));
check(
    '加速键 · 未独占的组合照常命中（Ctrl+R 仍走第 ① 环）',
    $claimApp->seen === ['accel.fire'],
);

// ★ 2026-10-21：`TextField` 构造时自带 `claimKeys([ctrl('A')])` —— 让位规则 ②
//   的**第一个生产消费者**（此前 claims() 只有夹具在声明）。不带 withClaimer
//   也照样让位，且键真的落到 `TextFieldState::handleKey()`（全选发生）。
$prodApp = new AccelApplication();
$prodApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$prodApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
$prodApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('A')));
$prodEl = findElement($prodApp->rootElement(), fn(Element $el) => $el->widget() instanceof TextField);
$prodState = $prodEl instanceof StatefulElement ? $prodEl->state() : null;
check(
    '让位规则② · TextField 自带 Ctrl+A 独占（让位给输入框，且全选真的发生）',
    $prodApp->seen === [] && $prodState instanceof TextFieldState
        && $prodState->selectionStart() === 0 && $prodState->selectionEnd() === 3,
    'seen=[' . implode(',', $prodApp->seen) . '] sel='
        . ($prodState?->selectionStart() ?? -1) . '..' . ($prodState?->selectionEnd() ?? -1),
);

// H. 编辑命令环 + 指针框选（2026-10-21，#50：§9.1 让位规则 ② 的剪贴板侧）
//    夹具应用：padding24 里两个字段（abc / xyz），Ctrl+C / Ctrl+V **另绑**
//    加速键 ⇒ 门控放行时它们必须开火（证明键没被吞），环吃掉时 seen 必须
//    不动（证明优先级正确）。
$editApp = new EditApplication();
$editApp->clip = new FakeClipboard();
$editApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(300.0, 200.0)));
$editApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
$editEl = findElement($editApp->rootElement(), fn(Element $el) => $el->widget() instanceof TextField);
$editState = $editEl instanceof StatefulElement ? $editEl->state() : null;
check(
    '编辑命令 · Tab 焦点进第一个字段（编辑态同步开）',
    $editState instanceof TextFieldState && $editState->isEditing(),
    var_export($editState instanceof TextFieldState, true),
);

// —— 门控 ④（C/X 无选区）⇒ 整族回落给加速键
$editApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('C')));
check(
    '编辑命令 · 无选区（门控④）⇒ Ctrl+C 回落加速键（键没被吞）',
    $editApp->seen === ['app.copy'] && $editApp->clip->text === '',
    'seen=[' . implode(',', $editApp->seen) . '] clip=' . var_export($editApp->clip->text, true),
);

// —— Ctrl+A：让位后的键落点（不进加速键，全选发生）
$editApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('A')));
check(
    '编辑命令 · Ctrl+A 归输入框（加速键不吃，全选 [0,3]）',
    $editState?->selectionStart() === 0 && $editState?->selectionEnd() === 3
        && $editApp->seen === ['app.copy'],
    'sel=' . ($editState?->selectionStart() ?? -1) . '..' . ($editState?->selectionEnd() ?? -1),
);

// —— 环吃掉：复制（有选区 + 有剪贴板）
$editApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('C')));
check(
    '编辑命令 · 有选区 + 有剪贴板 ⇒ 复制进剪贴板，加速键不吃',
    $editApp->clip->text === 'abc' && $editApp->seen === ['app.copy'],
    'clip=' . var_export($editApp->clip->text, true) . ' seen=[' . implode(',', $editApp->seen) . ']',
);

// —— 剪切：写剪贴板 + 删掉选区（不等同于复制）
$editApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('X')));
check(
    '编辑命令 · 剪切 = 写剪贴板 + 删掉选区（字段空了）',
    $editApp->clip->text === 'abc' && $editState?->text() === '',
    'clip=' . var_export($editApp->clip->text, true) . ' text=' . var_export($editState?->text(), true),
);

// —— 粘贴：替换选区（此处无选区 ⇒ 插在插入符）
$editApp->clip->text = 'XY';
$editApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('V')));
check(
    '编辑命令 · 粘贴把剪贴板内容写进字段（加速键不吃）',
    $editState?->text() === 'XY' && $editApp->seen === ['app.copy'],
    'text=' . var_export($editState?->text(), true) . ' seen=[' . implode(',', $editApp->seen) . ']',
);

// —— 门控 ③（平台没递剪贴板）⇒ 有选区也回落
$noClipApp = new EditApplication();
$noClipApp->clip = null;
$noClipApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(300.0, 200.0)));
$noClipApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
$noClipApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('A')));
$noClipApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('C')));
check(
    '编辑命令 · 平台没递剪贴板（门控③）⇒ 有选区也回落给加速键',
    $noClipApp->seen === ['app.copy'],
    'seen=[' . implode(',', $noClipApp->seen) . ']',
);

// —— 门控 ④（V 的剪贴板空）⇒ 回落
$emptyClipApp = new EditApplication();
$emptyClipApp->clip = new FakeClipboard();   // text='' ⇒ hasText() false
$emptyClipApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(300.0, 200.0)));
$emptyClipApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
$emptyClipApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('V')));
$emptyEl = findElement($emptyClipApp->rootElement(), fn(Element $el) => $el->widget() instanceof TextField);
$emptyState = $emptyEl instanceof StatefulElement ? $emptyEl->state() : null;
check(
    '编辑命令 · 剪贴板空（门控④）⇒ Ctrl+V 回落加速键，字段不动',
    $emptyClipApp->seen === ['app.paste'] && $emptyState?->text() === 'abc',
    'seen=[' . implode(',', $emptyClipApp->seen) . '] text=' . var_export($emptyState?->text(), true),
);

// —— 门控 ②（焦点不在输入框）⇒ 回落
$idleApp = new EditApplication();
$idleApp->clip = new FakeClipboard();
$idleApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(300.0, 200.0)));
$idleApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('C')));
check(
    '编辑命令 · 无焦点（门控②）⇒ 整族回落给加速键',
    $idleApp->seen === ['app.copy'],
    'seen=[' . implode(',', $idleApp->seen) . ']',
);

// —— 指针框选（§A.2.9 的指针侧）：down 落插入符 / move 延伸 / up·leave 收口
$selApp = new EditApplication();
$selApp->clip = new FakeClipboard();
$selApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(300.0, 200.0)));
$selEl = findElement($selApp->rootElement(), fn(Element $el) => $el->widget() instanceof TextField);
$selState = $selEl instanceof StatefulElement ? $selEl->state() : null;
$selBox = $selEl?->effectiveBox();
check(
    '框选 · 夹具就绪（字段盒在 padding 24 处，宽度由内容定）',
    $selBox instanceof RenderTextField && boxOrigin($selBox) === [24.0, 24.0],
    'origin=' . implode(',', $selBox === null ? [] : boxOrigin($selBox)),
);
[$bx, $by] = boxOrigin($selBox);
$bw = $selBox->size()->width;   // 字段盒宽（点"右端"必须点在盒内 —— 盒外焦点落不上）

$selApp->dispatchInput(PointerEvent::down($bx + 1.0, $by + 1.0));
check(
    '框选 · down 落左边缘 ⇒ 插入符 0、无选区（点一下不继承旧选区）',
    $selState?->caretOffset() === 0 && $selState?->hasSelection() === false,
    'caret=' . ($selState?->caretOffset() ?? -1) . ' sel=' . var_export($selState?->hasSelection(), true),
);

$selApp->dispatchInput(PointerEvent::move($bx + 200.0, $by + 4.0));
check(
    '框选 · 向右拖 ⇒ 选区延伸到末尾（[0,3]，selectedText 完整）',
    $selState?->selectionStart() === 0 && $selState?->selectionEnd() === 3
        && $selState?->selectedText() === 'abc',
    'sel=' . ($selState?->selectionStart() ?? -1) . '..' . ($selState?->selectionEnd() ?? -1),
);

$selApp->dispatchInput(PointerEvent::up($bx + 200.0, $by + 4.0));
$selApp->dispatchInput(PointerEvent::move($bx + 250.0, $by + 4.0));
check(
    '框选 · up 收口后再 move 不延伸（手势结束，选区留在字段里）',
    $selState?->selectionStart() === 0 && $selState?->selectionEnd() === 3
        && $selState?->selectedText() === 'abc',
    'sel=' . ($selState?->selectionStart() ?? -1) . '..' . ($selState?->selectionEnd() ?? -1),
);

// 反向：点在右端（折叠）⇒ 向左拖 ⇒ 活动端在左；leave 收口后不再延伸
$selApp->dispatchInput(PointerEvent::down($bx + $bw - 1.0, $by + 4.0));
check(
    '框选 · 再点一次 ⇒ 旧选区折叠、插入符移到点中处',
    $selState?->caretOffset() === 3 && $selState?->hasSelection() === false,
    'caret=' . ($selState?->caretOffset() ?? -1) . ' sel=' . var_export($selState?->hasSelection(), true),
);
$selApp->dispatchInput(PointerEvent::move($bx + 1.0, $by + 4.0));
check(
    '框选 · 反向拖动同样成选区（活动端在左，[0,3]）',
    $selState?->selectionStart() === 0 && $selState?->selectionEnd() === 3,
    'sel=' . ($selState?->selectionStart() ?? -1) . '..' . ($selState?->selectionEnd() ?? -1),
);
$selApp->dispatchInput(PointerEvent::leave($bx + 1.0, $by + 4.0));
$selApp->dispatchInput(PointerEvent::move($bx + 200.0, $by + 4.0));
check(
    '框选 · leave 收口后再 move 不延伸（指针无捕获的诚实边界）',
    $selState?->selectionStart() === 0 && $selState?->selectionEnd() === 3
        && $selState?->selectedText() === 'abc',
    'sel=' . ($selState?->selectionStart() ?? -1) . '..' . ($selState?->selectionEnd() ?? -1),
);

$selCursor = $selApp->dispatchInput(PointerEvent::move($bx + 2.0, $by + 2.0));
check(
    '光标 · 指针移入字段 ⇒ IBeam（§9.1 三方消费第三项）',
    $selCursor === CursorShape::IBeam,
    var_export($selCursor, true),
);

// ── ㉞ 菜单 / 浮层 / 拖放（任务 #6：O-20 方案 A + §9.1 关闭语义 + §A.3.5）──

$menuSurface = new SvgSurface();
$menuSurface->resize(320, 240, 1.0);
$menuApp = new MenuApplication();
$menuApp->attach($menuSurface, BoxConstraints::tight(new Size(320.0, 240.0)));
$tight320 = BoxConstraints::tight(new Size(320.0, 240.0));

check('浮层 · attach 后默认没开（浮层槽位为空）', !$menuApp->isMenuOpen());

// —— 键盘路径打开：Tab 到菜单标题 ⇒ Enter（Application::activateFocused 的 Menu 分支）
$menuApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
$menuApp->dispatchInput(KeyEvent::down(KeyChord::enter()));
check(
    '浮层 · Menu 标题 Enter ⇒ onOpen 被调 + 应用打开面板',
    $menuApp->opened && $menuApp->isMenuOpen(),
);
check(
    '浮层 · 锚点 = 标题 Element（菜单钉在被点的控件旁）',
    $menuApp->anchor !== null && $menuApp->anchor->widget() instanceof Menu,
);
$panelBox = $menuApp->overlays()->top()?->element?->effectiveBox();
check('浮层 · 面板盒是 RenderPopupPanel（与根树同构：一个 Widget 一个 Element）', $panelBox instanceof RenderPopupPanel);

// —— 渲染：主树先画、浮层后画（顺序即层叠）
$svgBefore = $menuSurface->svg();
$menuApp->frame(16.0, $tight320);
$svgAfter = $menuSurface->svg();
check(
    '浮层 · 渲染多画了一棵（SVG 的 rect 数增加 —— 面板底 / 边框 / 项文本）',
    substr_count($svgAfter, '<rect') > substr_count($svgBefore, '<rect'),
);

// —— 落位：面板左上角 = 锚点左下；夹进客户区（按**面板**矩形算）
$menuTop = $menuApp->overlays()->top();
[$mx, $my] = [$menuTop?->x ?? 0.0, $menuTop?->y ?? 0.0];
$titleBox = $menuApp->anchor?->effectiveBox();
$titleBottom = $titleBox === null ? 0.0 : boxOrigin($titleBox)[1] + $titleBox->size()->height;
$panelInset = $panelBox instanceof RenderPopupPanel ? $panelBox->panelRect()->left : 0.0;
check(
    '浮层 · 面板左上角钉在锚点左下（含投影留白补偿）',
    abs(($my + $panelInset) - $titleBottom) < 1.0,
    sprintf('面板 y=%.6g 锚点底=%.6g', $my + $panelInset, $titleBottom),
);
check(
    '浮层 · 面板夹进客户区（右 / 下都不越界）',
    $panelBox !== null
        && $mx + $panelBox->size()->width <= 320.5
        && $my + $panelBox->size()->height <= 240.5,
);

// —— 关闭语义 ①：点面板外 ⇒ 关 + 吞掉该次按下（不穿透）
//    ★ 坐标要同时满足两件事：**在面板矩形之外**、**仍在带 `ContextMenu` 的那行上**
//      —— 面板窄（≈140）而客户区宽 320 ⇒ 取该行右侧（x+200）即可。若穿透，
//      这次右键会顺手打开下面那个菜单（最容易被抓的形态）。
$colBox = $menuApp->rootBox();
$rows = $colBox instanceof RenderFlex ? $colBox->children() : [];
$rowBox = $rows[1] ?? null;
$targetBox = $rows[2] ?? null;
[$rx, $ry] = $rowBox === null ? [0.0, 0.0] : boxOrigin($rowBox);
[$sx, $sy] = $targetBox === null ? [0.0, 0.0] : boxOrigin($targetBox);
$outsideX = $rx + 200.0;

$menuApp->dispatchInput(PointerEvent::down($outsideX, $ry + 10.0, 1));
check(
    '浮层 · 点面板外 ⇒ 关且**吞掉**按下（不穿透到下面的右键菜单）',
    !$menuApp->isMenuOpen(),
    $menuApp->isMenuOpen() ? '穿透了：菜单被重开' : '',
);

// —— 右键最小集：再点一次（此刻无浮层）⇒ 打开命中的 ContextMenu
$menuApp->dispatchInput(PointerEvent::down($rx + 10.0, $ry + 10.0, 1));
check('右键 · 无浮层时右键 ⇒ 打开命中行的 ContextMenu', $menuApp->isMenuOpen());

// —— 关闭语义 ②：命令分发**之后**才关
$dispatched = $menuApp->dispatch('row.del');
check(
    '浮层 · 命令分发之后才关（语义 #2）',
    $dispatched && !$menuApp->isMenuOpen() && $menuApp->seen === ['row.del'],
);

// —— 右键落在无声明处 ⇒ 什么也不做（不报错、不落给焦点）
$menuApp->dispatchInput(PointerEvent::down($sx + 10.0, $sy + 10.0, 1));
check('右键 · 落在无 ContextMenu 声明的行 ⇒ 什么也不做', !$menuApp->isMenuOpen());

// —— 关闭语义 ③：ESC 在 dispatchKey 之前拦
$fileMenu = $menuApp->fileMenu();
$menuApp->showMenu($fileMenu->panel()); // 直接开（不依赖焦点当前在哪儿）
check('浮层 · 重开（ESC 用例的前置）', $menuApp->isMenuOpen());
$menuApp->dispatchInput(KeyEvent::down(KeyChord::escape()));
check('浮层 · ESC ⇒ 关（语义 #3：在 dispatchKey 之前拦，不被焦点控件吃掉）', !$menuApp->isMenuOpen());

// —— 关闭语义 ④：点面板**内**（空白处，非菜单项）⇒ 不关
//   ★ 2026-10-17 坐标修正：原 (20,20) 落在第一项上。指针激活修复后
//     （点菜单项 ⇒ 命令分发 + 语义 #2 关浮层），点项会关 —— 那是**正确**
//     行为。本用例要测的是"点空白不关"，故移到面板右下 padding
//     （项区右界 x=150、下界 y≈70.6，取 (w−6, h−6) 必空白）。
$menuApp->showMenu($fileMenu->panel());
$menuApp->frame(32.0, $tight320); // 走一帧 ⇒ 落位算过（overlays()->top() 才准）
$menuTop2 = $menuApp->overlays()->top();
[$mx2, $my2] = [$menuTop2?->x ?? 0.0, $menuTop2?->y ?? 0.0];
$panelBox2 = $menuApp->overlays()->top()?->element?->effectiveBox();
$menuApp->dispatchInput(PointerEvent::down(
    $mx2 + ($panelBox2?->size()?->width ?? 160.0) - 6.0,
    $my2 + ($panelBox2?->size()?->height ?? 80.0) - 6.0,
));
check('浮层 · 点面板内 ⇒ 不关（指针先归浮层，语义 #1 的另一半）', $menuApp->isMenuOpen());
$menuApp->dispatchInput(KeyEvent::down(KeyChord::escape()));

// —— 浮层项指针激活：点菜单项 ⇒ 命令分发 + 语义 #2 分发后关浮层（与 Enter 同路）
$ptrMenuApp = new MenuApplication();
$ptrMenuApp->attach($menuSurface, BoxConstraints::tight(new Size(320.0, 240.0)));
$ptrMenuApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
$ptrMenuApp->dispatchInput(KeyEvent::down(KeyChord::enter()));   // 键盘打开「文件」菜单
$ptrMenuApp->frame(16.0, $tight320);                             // 走一帧 ⇒ 浮层落位（overlays()->top() 才准、盒才有尺寸）
check('浮层 · 菜单已开（指针项激活用例前置）', $ptrMenuApp->isMenuOpen());
$itemEl = findElement($ptrMenuApp->overlays()->top()?->element, fn (Element $e): bool => $e->widget() instanceof MenuItem);
$itemBox = $itemEl?->effectiveBox();
// ★ `boxOrigin()` 现在给**绝对**原点（委派 `absoluteOrigin()`，浮层根会加自己的
//   落点）⇒ 再叠一次 `overlays()->top()` 就是加两遍，点会落到面板外、静默不生效
//   （2026-10-22 实测：`seen=` 空而 `open=yes`）。
[$ibx, $iby] = $itemBox === null ? [0.0, 0.0] : boxOrigin($itemBox);
$ibw = $itemBox?->size()?->width ?? 0.0;
$ibh = $itemBox?->size()?->height ?? 0.0;
$ptrMenuApp->dispatchInput(PointerEvent::down($ibx + $ibw / 2, $iby + $ibh / 2));
check(
    '浮层 · 指针点菜单项 ⇒ 命令分发 + 分发后关浮层（语义 #2，与 Enter 同路）',
    $ptrMenuApp->seen === ['menu.refresh'] && !$ptrMenuApp->isMenuOpen(),
    'seen=' . implode(',', $ptrMenuApp->seen) . ' open=' . ($ptrMenuApp->isMenuOpen() ? 'yes' : 'no'),
);

// —— 应用内拖放（§A.3.5）：状态机可驱动（真机指针序列等 M4）
$dragApp = $menuApp;
$dragApp->beginDrag(Draggable::make('user-7'));
check('拖放 · beginDrag ⇒ 进入拖动态', $dragApp->drag()->isDragging());
$dragApp->updateDrag($sx + 5.0, $sy + 5.0);
check('拖放 · 进入 DragTarget ⇒ onHover(true)', $dragApp->hover === [true]);
$dragApp->updateDrag($sx + 9.0, $sy + 9.0);
check('拖放 · 目标不变 ⇒ 不重复通知（§9.1 语义 #5 同源）', $dragApp->hover === [true]);
$dragApp->updateDrag($rx + 5.0, $ry + 5.0);
check('拖放 · 离开目标 ⇒ onHover(false)', $dragApp->hover === [true, false]);
$dragApp->updateDrag($sx + 5.0, $sy + 5.0);
check(
    '拖放 · drop 把负载交给命中的 DragTarget',
    $dragApp->dropDrag() === true && $dragApp->drop === ['user-7'],
);
check('拖放 · drop 后退出拖动态', !$dragApp->drag()->isDragging());
$dragApp->beginDrag(Draggable::make('user-9'));
$dragApp->updateDrag($rx + 5.0, $ry + 5.0); // 拖到没有目标的地方
check(
    '拖放 · 拖空 ⇒ drop 返回 false（不派发 onAccept）',
    $dragApp->dropDrag() === false && count($dragApp->drop) === 1,
);
$dragApp->beginDrag(Draggable::make('user-9'));
$dragApp->dispatchInput(KeyEvent::down(KeyChord::escape()));
check(
    '拖放 · ESC 取消（不派发 onAccept）',
    !$dragApp->drag()->isDragging() && count($dragApp->drop) === 1,
);

// —— 声明落位（Widget 基类三个新声明位）
check(
    '声明 · draggable / contextMenu / dragTarget 落在 widget 上（可被宿主读回）',
    $rowBox !== null
        && FlatBox::make(10.0, 10.0)->draggable(Draggable::make(1))->draggableValue()?->payload() === 1
        && FlatBox::make(10.0, 10.0)->dragTarget(DragTarget::make())->dragTargetValue() instanceof DragTarget
        && FlatBox::make(10.0, 10.0)->contextMenu(ContextMenu::make())->contextMenuValue() instanceof ContextMenu,
);

// —— Menu 四件的形体
$menu = Menu::make('文件')->items([MenuItem::make('刷新')]);
check('Menu · panel() 是 PopupPanel（浮层形体归 PopupPanel，落位归 App）', $menu->panel() instanceof PopupPanel);
check('Menu · 是一种按钮（命令 / 焦点 / 激活整套复用，同 NavButton 先例）', $menu instanceof Button && $menu->isFocusable());

$item = MenuItem::make('刷新')->chord(KeyChord::ctrl('R'))->command('menu.refresh');
check(
    'MenuItem · 命令 / chord 声明可读',
    $item->commandName() === 'menu.refresh' && $item->chordValue()?->label() === 'Ctrl+R',
);
check('MenuItem · 是一种按钮 + 可聚焦（键盘能选中）', $item instanceof Button && $item->isFocusable());
check('MenuItem · separator 形态', MenuItem::separator()->isSeparator());
check('MenuBar · build 出横排（纯组合控件，自己没有盒）', MenuBar::make()->menus([$menu])->build($context) instanceof Row);
check('ContextMenu · panel() 是 PopupPanel（声明对象，不是 Widget）', ContextMenu::make()->items([
    MenuItem::make('删除'),
])->panel() instanceof PopupPanel);

// —— 菜单项盒：两端分布（label 左 / chord 右）+ 分隔线有墨
//    ★ 约束用 **tight 宽 + loose 高**（与 `RenderPopupPanel` 给内容的一致）——
//      tight 高会把文本挤爆（C6：内容放不下就该报，这里是我们给的约束不对）
$itemBox = $item->createRenderObject($context);
check('MenuItem · 盒是 RenderMenuItem', $itemBox instanceof RenderMenuItem);
$itemBox->layout(new BoxConstraints(180.0, 180.0, 0.0, 60.0));
$rowKids = [];
$itemBox->visitChildren(function (RenderBox $c) use (&$rowKids): void {
    $c->visitChildren(function (RenderBox $inner) use (&$rowKids): void {
        $rowKids[] = $inner;
    });
});
$first = $rowKids[0] ?? null;
$last = $rowKids === [] ? null : $rowKids[count($rowKids) - 1];
check(
    'MenuItem · chord 在行的右端（label 与 chord 两端分布 ⇒ 整行可点）',
    $first !== null && $last !== null && $last->offsetX() > $first->offsetX() + 40.0,
    sprintf('label x=%.6g chord x=%.6g', $first?->offsetX() ?? -1, $last?->offsetX() ?? -1),
);
$sepBox = MenuItem::separator()->createRenderObject($context);
$sepBox->layout(new BoxConstraints(180.0, 180.0, 0.0, 20.0));
check(
    'MenuItem · 分隔线有墨（1px 横线真画了）',
    inkCount(spinCanvas($sepBox, 180, 8)) > 0,
);

// —— PopupPanel：统一宽 = max(minWidth, 最宽项)
//    ★ 要**挂载**后再测：面板的 child 由 Element 层挂接（C1 父管挂接），
//      光对 createRenderObject 的盒 layout 会拿到"没有孩子"的空盒。
$panelProbe = $menu->minWidth(200.0)->panel();
$panelProbeEl = $panelProbe->createElement();
$panelProbeEl->mount(null, $context);
$panelProbeBox = $panelProbeEl->effectiveBox();
$panelProbeBox?->layout(BoxConstraints::unbounded());
check(
    'PopupPanel · 宽 = max(minWidth, 最宽项)（面板统一宽 ⇒ 菜单项整行可点）',
    $panelProbeBox?->size() !== null && $panelProbeBox->size()->width >= 200.0,
    sprintf('实际宽 %.6g', $panelProbeBox?->size()?->width ?? -1),
);

// ── ㉞a 菜单面板限高 + 滚动（`PopupPanel::maxHeight()`，2026-10-24）──
//    ★ 客户区取 **320×900（高视口）**：`RenderPopupPanel` 的上限会与"约束允许的内容高"
//      取小，320×240 那种矮视口会把面板夹成视口高 ⇒ 分不清"被 PANEL_MAX_HEIGHT 限"
//      还是"被客户区限"，这条用例就白写了。
//    ★ 长菜单（30 项）**刻意不进 MenuBar**：菜单栏自然宽有既有断言（标题 + 导航 + 菜单），
//      加一个标题会把它顶翻。照 `fileMenu()` 的用法直接 `showMenu($m->panel())`。
$menuCapSurface = new SvgSurface();
$menuCapSurface->resize(320, 900, 1.0);
$menuCapApp = new MenuApplication();
$menuCapApp->attach($menuCapSurface, BoxConstraints::tight(new Size(320.0, 900.0)));
$menuCapTight = BoxConstraints::tight(new Size(320.0, 900.0));

$menuCapApp->showMenu($menuCapApp->longMenu()->panel());
$menuCapApp->frame(16.0, $menuCapTight);   // 走一帧 ⇒ 落位算过

$menuCapPanel = $menuCapApp->overlays()->top()?->element?->effectiveBox();
$menuCapSv = $menuCapApp->panelScrollView();
check(
    '菜单 · 长菜单（30 项）⇒ 面板高 = PANEL_MAX_HEIGHT（超长才滚）',
    $menuCapPanel?->size() !== null
        && abs($menuCapPanel->size()->height - Menu::PANEL_MAX_HEIGHT) < 0.01,
    sprintf('实际高 %.6g（上限 %.6g）', $menuCapPanel?->size()?->height ?? -1, Menu::PANEL_MAX_HEIGHT),
);

// 反例：自然高必须**真的**超过上限，否则这条用例没在验限高
$menuCapContent = null;
$menuCapSv?->visitChildren(static function ($c) use (&$menuCapContent): void {
    $menuCapContent = $c;
});
$menuCapNatural = $menuCapContent instanceof RenderBox ? $menuCapContent->size()?->height ?? -1 : -1;
check(
    '菜单 · 反例：自然高（30 项）远超上限 ⇒ 这条用例真的在验限高',
    $menuCapNatural > Menu::PANEL_MAX_HEIGHT,
    sprintf('自然高 %.6g vs 上限 %.6g', $menuCapNatural, Menu::PANEL_MAX_HEIGHT),
);

check(
    '菜单 · 面板里有滚动视口，且视口比面板矮（内容变成可滚视口）',
    $menuCapSv !== null
        && $menuCapSv->size() !== null
        && $menuCapPanel?->size() !== null
        && $menuCapSv->size()->height < $menuCapPanel->size()->height
        && $menuCapSv->maxScroll(Axis::Vertical) > 0.0,
    sprintf(
        '视口高 %.6g / 面板高 %.6g / maxScroll=%.6g',
        $menuCapSv?->size()?->height ?? -1,
        $menuCapPanel?->size()?->height ?? -1,
        $menuCapSv?->maxScroll(Axis::Vertical) ?? -1,
    ),
);

// 推导：30 项里 6 条分隔线（i%5==0）⇒ 24 行 × 行高 + 6 × 分隔线高。
// 行高分两种（带 / 不带加速键显示），一律从盒子现推，不写死。
$menuCapRows = [];
$menuCapSv?->visitChildren(static function ($c) use (&$menuCapRows): void {
    $menuCapRows[] = $c;
});
$menuCapRowH = 0.0;
$menuCapSepH = 0.0;
foreach ($menuCapRows as $r) {
    if (!$r instanceof RenderBox || $r->size() === null) {
        continue;
    }
    $h = $r->size()->height;
    if ($h <= 8.0) {
        $menuCapSepH = $menuCapSepH > 0.0 ? $menuCapSepH : $h;
    } else {
        $menuCapRowH = $menuCapRowH > 0.0 ? $menuCapRowH : $h;
    }
}
$menuCapItems = 0;
$menuCapSeps = 0;
foreach ($menuCapRows as $r) {
    if (!$r instanceof RenderBox || $r->size() === null) {
        continue;
    }
    if ($r->size()->height <= 8.0) {
        $menuCapSeps++;
    } else {
        $menuCapItems++;
    }
}
$menuCapView = $menuCapSv?->size()?->height ?? -1.0;
check(
    '菜单 · maxScroll = 自然内容高 − 视口高（推导，数字全从盒子现推）',
    $menuCapSv !== null
        && abs($menuCapSv->maxScroll(Axis::Vertical) - ($menuCapItems * $menuCapRowH + $menuCapSeps * $menuCapSepH - $menuCapView)) < 0.01,
    sprintf(
        '%d 行×%.6g + %d 分隔×%.6g − 视口 %.6g = %.6g，实际 maxScroll=%.6g',
        $menuCapItems,
        $menuCapRowH,
        $menuCapSeps,
        $menuCapSepH,
        $menuCapView,
        $menuCapItems * $menuCapRowH + $menuCapSeps * $menuCapSepH - $menuCapView,
        $menuCapSv?->maxScroll(Axis::Vertical) ?? -1,
    ),
);

// 限高的**动机**：最后一项在面板下沿之外 ⇒ 不限高就永远点不到它
$menuCapLast = null;
$menuCapLastY = -1.0;
$menuCapLastH = 0.0;
foreach ($menuCapRows as $r) {
    if (!$r instanceof RenderBox || $r->size() === null || $r->size()->height <= 8.0) {
        continue;
    }
    $menuCapLast = $r;
}
if ($menuCapLast !== null) {
    [$menuCapLastX, $menuCapLastY] = RenderBox::absoluteOrigin($menuCapLast);
    $menuCapLastH = $menuCapLast->size()->height;
}
check(
    '菜单 · 最后一项在面板下沿之外（不限高就点不到它 —— 限高的动机）',
    $menuCapLast !== null
        && $menuCapPanel !== null
        && $menuCapLastY + $menuCapLastH > RenderBox::absoluteOrigin($menuCapPanel)[1] + $menuCapPanel->size()->height,
    sprintf('末项底 y=%.6g，面板底 y=%.6g', $menuCapLastY + $menuCapLastH, RenderBox::absoluteOrigin($menuCapPanel)[1] + $menuCapPanel->size()->height),
);

// 滚一格 ⇒ appliedOffset = 48（`Application::WHEEL_NOTCH_PX`，真实 `ScrollEvent` 通道）
// ★ 常量是 private ⇒ 表达式里写字面量、名字进消息（与 `Scroll · 应用` 那条同款）。
// ★★ 每滚一次必须**重取** ScrollView：`scrollBy()` → `setState` → `update()` 会换一个
//    新渲染盒，拿旧盒读 `appliedOffset()` 恒为上一帧的值（看着像"滚轮没生效"）。
[$menuCapCx, $menuCapCy] = RenderBox::absoluteOrigin($menuCapPanel);
$menuCapCx += $menuCapPanel->size()->width / 2.0;
$menuCapCy += $menuCapPanel->size()->height / 2.0;
$menuCapApp->dispatchInput(ScrollEvent::make($menuCapCx, $menuCapCy, 1.0));
$menuCapApp->frame(32.0, $menuCapTight);
check(
    '菜单 · 滚一格 ⇒ appliedOffset = WHEEL_NOTCH_PX（真实滚轮通道）',
    abs($menuCapApp->panelScrollView()?->appliedOffset(Axis::Vertical) - 48.0) < 0.01,
    sprintf('appliedOffset=%.6g', $menuCapApp->panelScrollView()?->appliedOffset(Axis::Vertical) ?? -1),
);
$menuCapApp->dispatchInput(ScrollEvent::make($menuCapCx, $menuCapCy, 1.0));
$menuCapApp->frame(48.0, $menuCapTight);
check(
    '菜单 · 滚两格 ⇒ appliedOffset = 2 × WHEEL_NOTCH_PX（量对，不是只动一下）',
    abs($menuCapApp->panelScrollView()?->appliedOffset(Axis::Vertical) - 96.0) < 0.01,
    sprintf('appliedOffset=%.6g', $menuCapApp->panelScrollView()?->appliedOffset(Axis::Vertical) ?? -1),
);
check(
    '菜单 · 滚完之后面板仍在、高度仍是上限',
    $menuCapApp->isMenuOpen()
        && $menuCapApp->overlays()->top()?->element?->effectiveBox()?->size() !== null
        && abs(($menuCapApp->overlays()->top()?->element?->effectiveBox()?->size()?->height ?? 0.0) - Menu::PANEL_MAX_HEIGHT) < 0.01,
);
$menuCapApp->dispatchInput(KeyEvent::down(KeyChord::escape()));

// 反例：短菜单（3 项）⇒ **贴着内容**，不撑到上限、也不滚
$menuShortApp = new MenuApplication();
$menuShortApp->attach($menuCapSurface, BoxConstraints::tight(new Size(320.0, 900.0)));
$menuShortApp->showMenu($menuShortApp->fileMenu()->panel());
$menuShortApp->frame(16.0, $menuCapTight);
$menuShortPanel = $menuShortApp->overlays()->top()?->element?->effectiveBox();
check(
    '菜单 · 反例：短菜单（3 项）⇒ 面板高 < 上限（**贴着内容**，不凭空撑高一截空白）',
    $menuShortPanel?->size() !== null
        && $menuShortPanel->size()->height < Menu::PANEL_MAX_HEIGHT,
    sprintf('实际高 %.6g（上限 %.6g）', $menuShortPanel?->size()?->height ?? -1, Menu::PANEL_MAX_HEIGHT),
);
check(
    '菜单 · 反例：短菜单 maxScroll = 0（内容不溢出 ⇒ 不画滚动条，浏览器同行为）',
    $menuShortApp->panelScrollView()?->maxScroll(Axis::Vertical) === 0.0,
    sprintf('maxScroll=%.6g', $menuShortApp->panelScrollView()?->maxScroll(Axis::Vertical) ?? -1),
);
$menuShortApp->dispatchInput(KeyEvent::down(KeyChord::escape()));

// ── ㉞b 模态（§A.3.7 / §12.1 第 6 条：复用浮层槽位 + 紧约束盖满 + 遮罩自绘）──
//    ★ 客户区取 480×360（不是 320）：Modal 默认 minWidth=320，320 宽的客户区
//      会让面板几乎盖满 ⇒ 遮罩区只剩投影留白，"四角都是遮罩"这条判据就废了。

$modalSurface = new SvgSurface();
$modalSurface->resize(480, 360, 1.0);
$modalTight = BoxConstraints::tight(new Size(480.0, 360.0));
$modalApp = new ModalApplication();
$modalApp->attach($modalSurface, $modalTight);

check(
    '模态 · attach 后默认没开（槽位与菜单共用，两个开关各自诚实）',
    !$modalApp->isModalOpen() && $modalApp->modal() === null && !$modalApp->isMenuOpen(),
);

$modalApp->openConfirm();
$modalApp->frame(16.0, $modalTight);
check(
    '模态 · showModal ⇒ 打开（浮层槽位被占，但 isMenuOpen() 仍为假）',
    $modalApp->isModalOpen() && $modalApp->overlays()->top()?->element !== null && !$modalApp->isMenuOpen(),
);

$modalBox = $modalApp->overlays()->top()?->element?->effectiveBox();
check('模态 · 浮层盒是 RenderModalHost（遮罩 + 盖满住同一个盒）', $modalBox instanceof RenderModalHost);
check(
    '模态 · 紧约束盖满客户区（不是自尺寸 —— 盖不满就"后面还能点"）',
    $modalBox?->size() !== null
        && abs($modalBox->size()->width - 480.0) < 0.01
        && abs($modalBox->size()->height - 360.0) < 0.01,
    sprintf('实际 %.6g × %.6g', $modalBox?->size()?->width ?? -1.0, $modalBox?->size()?->height ?? -1.0),
);
$modalTop = $modalApp->overlays()->top();
check('模态 · 原点归零（模态不钉锚点）', $modalTop !== null && $modalTop->x === 0.0 && $modalTop->y === 0.0);

// —— 面板：沿盒树找 PopupPanel（模态不自造 chrome，形体复用菜单那套）
$modalPanel = null;
$scanModal = function (?RenderBox $node) use (&$scanModal, &$modalPanel): void {
    if ($node === null || $modalPanel !== null) {
        return;
    }
    if ($node instanceof RenderPopupPanel) {
        $modalPanel = $node;

        return;
    }
    $node->visitChildren(function (RenderBox $child) use (&$scanModal): void {
        $scanModal($child);
    });
};
$scanModal($modalBox);
check('模态 · 面板是 PopupPanel（形体复用，模态不自造一套 chrome）', $modalPanel instanceof RenderPopupPanel);

$modalPanelRect = $modalPanel instanceof RenderPopupPanel ? $modalPanel->panelRect() : null;
[$modalPx, $modalPy] = $modalPanel instanceof RenderBox ? boxOrigin($modalPanel) : [0.0, 0.0];
$modalPanelW = $modalPanelRect?->width ?? 0.0;
$modalPanelH = $modalPanelRect?->height ?? 0.0;
$modalPanelX = $modalPx + ($modalPanelRect?->left ?? 0.0);
$modalPanelY = $modalPy + ($modalPanelRect?->top ?? 0.0);
check(
    '模态 · 面板居中（Center 组合出来的，不是盒里写死的偏移）',
    abs(($modalPanelX + $modalPanelW / 2.0) - 240.0) < 1.0
        && abs(($modalPanelY + $modalPanelH / 2.0) - 180.0) < 1.0,
    sprintf(
        '面板中心 (%.6g, %.6g)，客户区中心 (240, 180)',
        $modalPanelX + $modalPanelW / 2.0,
        $modalPanelY + $modalPanelH / 2.0,
    ),
);

// —— 遮罩：半透明混色（★ §A.3.7 点名的"实现第一天验"）
$modalRaster = new RasterCanvas(480, 360, Color::white(), Scale::of(1.0));
$modalBox?->paint($modalRaster);
$barrierPx = $modalRaster->pixelAt(2, 2);
check(
    '模态 · 遮罩半透明混色（白底 × 35% 黑 ⇒ 灰；纯白/纯黑都说明 alpha 没走通）',
    $barrierPx->r === $barrierPx->g && $barrierPx->g === $barrierPx->b
        && $barrierPx->r > 120 && $barrierPx->r < 230,
    sprintf('角落 rgb(%d,%d,%d)', $barrierPx->r, $barrierPx->g, $barrierPx->b),
);

$cornersOk = true;
foreach ([[2, 2], [477, 2], [2, 357], [477, 357]] as $cornerPt) {
    $p = $modalRaster->pixelAt($cornerPt[0], $cornerPt[1]);
    if (!$p->equals($barrierPx)) {
        $cornersOk = false;
    }
}
check('模态 · 遮罩覆盖四角（盖满是真的盖满，不是画了个中间块）', $cornersOk);

$panelPx = $modalRaster->pixelAt(
    (int) round($modalPanelX + 8.0),
    (int) round($modalPanelY + 8.0),
);
check(
    '模态 · 面板画在遮罩之上（面板内像素 ≠ 遮罩色）',
    !$panelPx->equals($barrierPx),
    sprintf('面板内 rgb(%d,%d,%d)', $panelPx->r, $panelPx->g, $panelPx->b),
);

// —— 关闭语义：点面板内（空白）⇒ 不关
$modalApp->dispatchInput(PointerEvent::down($modalPanelX + 8.0, $modalPanelY + 8.0));
check('模态 · 点面板内 ⇒ 不关（遮罩只挡面板外）', $modalApp->isModalOpen());

// —— 关闭语义：ESC ⇒ 关（dismissible 默认 true）
$modalApp->dispatchInput(KeyEvent::down(KeyChord::escape()));
check('模态 · ESC ⇒ 关（dismissible 默认 true）', !$modalApp->isModalOpen());
check(
    '模态 · 关后槽位清空 + 模态标记归零（不留脏状态）',
    $modalApp->overlays()->top()?->element === null && $modalApp->modal() === null && !$modalApp->isMenuOpen(),
);
$modalApp->closeModal();
check('模态 · closeModal 幂等（没开再关一次不炸）', !$modalApp->isModalOpen());

// —— dismissible=false：ESC 与遮罩点击都**吞掉但不关**
$modalApp->dismissible = false;
$modalApp->openConfirm();
$modalApp->frame(16.0, $modalTight);
$modalApp->seen = [];
check(
    '模态 · dismissible=false 的模态已打开（前置）',
    $modalApp->isModalOpen() && $modalApp->modal()?->isDismissible() === false,
);

$modalApp->dispatchInput(KeyEvent::down(KeyChord::escape()));
check(
    '模态 · dismissible=false ⇒ ESC 不关且被吞（不落到第 ① 环加速键）',
    $modalApp->isModalOpen() && $modalApp->seen === [],
    'open=' . ($modalApp->isModalOpen() ? 'yes' : 'no') . ' seen=' . implode(',', $modalApp->seen),
);

$modalApp->dispatchInput(PointerEvent::down(5.0, 5.0));
check(
    '模态 · dismissible=false ⇒ 点遮罩不关且被吞（拒绝 ≠ 放行给下面的按钮）',
    $modalApp->isModalOpen() && $modalApp->seen === [],
    'open=' . ($modalApp->isModalOpen() ? 'yes' : 'no') . ' seen=' . implode(',', $modalApp->seen),
);

// —— dismissible=true：点遮罩 ⇒ 关 + 吞掉该次按下
$modalApp->closeModal();
$modalApp->dismissible = true;
$modalApp->openConfirm();
$modalApp->frame(16.0, $modalTight);
$modalApp->seen = [];
$modalApp->dispatchInput(PointerEvent::down(5.0, 5.0));
check(
    '模态 · dismissible=true ⇒ 点遮罩关 + 吞掉该次按下（不穿透到下面的按钮）',
    !$modalApp->isModalOpen() && $modalApp->seen === [],
    'open=' . ($modalApp->isModalOpen() ? 'yes' : 'no') . ' seen=' . implode(',', $modalApp->seen),
);

// —— ★ 核心裁决：点面板内按钮 ⇒ 命令分发，但模态**不自己关**（关不关归应用）
$modalApp->openConfirm();
$modalApp->frame(16.0, $modalTight);
$modalApp->seen = [];
$confirmEl = $modalApp->overlays()->top()?->element === null ? null : findElement(
    $modalApp->overlays()->top()?->element,
    fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->commandName() === 'modal.confirm',
);
$confirmBox = $confirmEl?->effectiveBox();
[$confirmX, $confirmY] = $confirmBox === null ? [0.0, 0.0] : boxOrigin($confirmBox);
$confirmW = $confirmBox?->size()?->width ?? 0.0;
$confirmH = $confirmBox?->size()?->height ?? 0.0;
$modalApp->dispatchInput(PointerEvent::down($confirmX + $confirmW / 2.0, $confirmY + $confirmH / 2.0));
check(
    '模态 · 点面板内按钮 ⇒ 命令分发，模态**不自己关**（§A.3.7：关不关归应用）',
    $modalApp->seen === ['modal.confirm'] && $modalApp->isModalOpen(),
    'seen=' . implode(',', $modalApp->seen) . ' open=' . ($modalApp->isModalOpen() ? 'yes' : 'no'),
);

// —— ★ 关闭按钮（2026-10-25）：`OverlayManager::showModal()` 注入 dismiss，
//   `Modal::build()` 据此决定画不画那个 X。三条各自独立：
//   ① 框架注入了 ⇒ 画；② `dismissible=false` ⇒ 不画（点了没反应的 X 比没有更糟）；
//   ③ 按钮真的在树上，且**点它 => 模态关**（端到端，不是"盒在那儿"）。
$modalCloseSeen = $modalApp->modal();
check(
    '模态 · showModal 注入了 dismiss ⇒ showsCloseIcon() 为真',
    $modalCloseSeen instanceof Modal && $modalCloseSeen->showsCloseIcon(),
);
$modalCloseEl = $modalApp->overlays()->top()?->element === null ? null : findElement(
    $modalApp->overlays()->top()?->element,
    fn (Element $e): bool => $e->widget() instanceof IconButton && $e->widget()->label() === '关闭',
);
check('模态 · 关闭按钮在树上（按可访问名「关闭」）', $modalCloseEl !== null);
$modalCloseBox = $modalCloseEl?->effectiveBox();
[$modalCloseX, $modalCloseY] = $modalCloseBox === null ? [0.0, 0.0] : boxOrigin($modalCloseBox);
$modalCloseW = $modalCloseBox?->size()?->width ?? 0.0;
$modalCloseH = $modalCloseBox?->size()?->height ?? 0.0;
$modalApp->dispatchInput(
    PointerEvent::down($modalCloseX + $modalCloseW / 2.0, $modalCloseY + $modalCloseH / 2.0)
);
check(
    '模态 · 点关闭按钮 ⇒ 模态关（框架注入的 dismiss 真的接了线）',
    !$modalApp->isModalOpen(),
    'open=' . ($modalApp->isModalOpen() ? 'yes' : 'no'),
);

// 反例：`dismissible=false` ⇒ 没有 X（不是"有个点了没反应的 X"）
$modalApp->dismissible = false;
$modalApp->openConfirm();
$modalApp->frame(16.0, $modalTight);
check(
    '模态 · dismissible=false ⇒ 不画关闭按钮（与"拒绝用户关闭"同一条语义）',
    $modalApp->modal()?->showsCloseIcon() === false,
);
$modalApp->closeModal();
$modalApp->dismissible = true;

// —— ★ W3 行为变化（`01` §8）：模态**盖在菜单之上**，不再清菜单
//   旧语义"同一槽位 ⇒ 打开模态先清菜单"是槽位的产物，不是需求：
//   在下拉里点「新建分类…」弹出对话框，关掉对话框后下拉应该还在。
//   判据三条：① 模态开、菜单仍在 ② 栈深 = 2 ③ 关模态后菜单回到栈顶。
$modalMutexApp = new ModalApplication();
$modalMutexApp->attach($modalSurface, $modalTight);
$modalMutexApp->showMenu(PopupPanel::make(Text::make('菜单项')));
check('模态 · 前置：菜单开着', $modalMutexApp->isMenuOpen() && !$modalMutexApp->isModalOpen());
$modalMutexApp->openConfirm();
check(
    '模态 · 模态盖在菜单之上（不再清菜单 —— `01` §8 的行为变化）',
    $modalMutexApp->isModalOpen() && $modalMutexApp->isMenuOpen()
    && $modalMutexApp->overlays()->count() === 2,
    'modal=' . ($modalMutexApp->isModalOpen() ? 'yes' : 'no')
    . ' menu=' . ($modalMutexApp->isMenuOpen() ? 'yes' : 'no')
    . ' depth=' . $modalMutexApp->overlays()->count(),
);
$modalMutexApp->closeModal();
check(
    '模态 · 关掉对话框后菜单还在（栈没被连坐清掉）',
    !$modalMutexApp->isModalOpen() && $modalMutexApp->isMenuOpen()
    && $modalMutexApp->overlays()->count() === 1,
    'modal=' . ($modalMutexApp->isModalOpen() ? 'yes' : 'no')
    . ' menu=' . ($modalMutexApp->isMenuOpen() ? 'yes' : 'no')
    . ' depth=' . $modalMutexApp->overlays()->count(),
);

// —— Modal 的 build 结构：ModalHost → Center → PopupPanel
$modalBareEl = Modal::make()->child(Text::make('只有正文'))->createElement();
$modalBareEl->mount(null, $context);
$modalBareBox = $modalBareEl->effectiveBox();
$modalBareCenter = false;
$modalBareBox?->visitChildren(function (RenderBox $child) use (&$modalBareCenter): void {
    $mods = $child->mods();
    $modalBareCenter = $child instanceof RenderProxy && $mods !== null && $mods->hasCenter();
});
check(
    'Modal · build = ModalHost → Center → PopupPanel（居中靠组合，不是盒里的开关）',
    $modalBareBox instanceof RenderModalHost && $modalBareCenter,
);

// ── ㉞b 浮层栈（arch-v2 `01` §12.1 的八条夹具）──
//    ★ 每条都要能区分"栈版"与"槽位版"：单槽只能放一个 ⇒ 三层、逐层路由、
//      锚点消失自动关 这三类判据在旧实现下**根本写不出来**。

$labSurface = new RecordingSurface();
$labSurface->resize(320, 240, 1.0);
$lab = new OverlayLabApplication();
$lab->attach($labSurface, BoxConstraints::tight(new Size(320.0, 240.0)));

// ① 三层同屏：栈深 3，栈顶是最新的那一层
$lab->openPanel(FlatBox::make(80.0, 30.0));
$lab->openPanel(FlatBox::make(90.0, 30.0));
$lab->openModalBox(60.0, 24.0, '第三层');
check(
    '浮层栈 · 三层同屏（count=3，栈顶是最新的模态）',
    $lab->overlays()->count() === 3
    && $lab->overlays()->top()?->scrim === true
    && $lab->overlays()->at(0)?->scrim === false,
    'depth=' . $lab->overlays()->count()
    . ' top-scrim=' . var_export($lab->overlays()->top()?->scrim, true),
);

// ② scrim 顺序：第二层模态的遮罩**盖在第一层模态的面板上**（栈序 = 绘制序）
//    ★ 用像素回答，不用字符串：两个同色 rect 换个顺序，SVG 里看不出来。
//    ★ 采样点必须**在第一层面板内、第二层面板外** —— 否则测的是"第二层的面板
//      盖住第一层"，与遮罩顺序无关（这个坑踩过一次：两层模态面板都 ≈308 宽，
//      只有**高度**不同 ⇒ 沿 y 挑点，挑第一层面板的底部）。
$labTight = BoxConstraints::tight(new Size(320.0, 240.0));
$lab->overlays()->pop();
$lab->overlays()->pop();
$lab->overlays()->pop();
$lab->frame(16.0, $labTight);
$lab->openModalBox(200.0, 120.0, '第一层');
$lab->frame(32.0, $labTight);
$firstPanelEl = $lab->overlays()->top()?->element;
$firstPanelBox = $firstPanelEl === null ? null : findElement(
    $firstPanelEl,
    static fn(Element $e): bool => $e->widget() instanceof PopupPanel,
)?->effectiveBox();
$firstPanel = $firstPanelBox instanceof RenderPopupPanel ? $firstPanelBox->panelRect() : null;
[$fpX, $fpY] = $firstPanelBox === null ? [0.0, 0.0] : RenderBox::absoluteOrigin($firstPanelBox);
$probeX = (int) ($fpX + ($firstPanel?->left ?? 0.0) + 20.0);
$probeY = (int) ($fpY + ($firstPanel?->top ?? 0.0) + ($firstPanel?->height ?? 0.0) - 10.0);
$oneLayer = $labSurface->raster()->pixelAt($probeX, $probeY);
$lab->openModalBox(60.0, 24.0, '第二层');
$lab->frame(48.0, $labTight);
$twoLayers = $labSurface->raster()->pixelAt($probeX, $probeY);
check(
    '浮层栈 · 上层 scrim 盖在下层面板上（栈序 = 绘制序，不是"最后画一层遮罩"）',
    $twoLayers->r < $oneLayer->r && $oneLayer->r > 200,
    sprintf('单层 r=%d 两层 r=%d @(%d,%d)', $oneLayer->r, $twoLayers->r, $probeX, $probeY),
);

// ③ 锚点消失 ⇒ 下一帧自动关（`01` §9 边界 #2）
$lab2Surface = new RecordingSurface();
$lab2Surface->resize(320, 240, 1.0);
$lab2 = new OverlayLabApplication();
$lab2->attach($lab2Surface, BoxConstraints::tight(new Size(320.0, 240.0)));
$lab2Tight = BoxConstraints::tight(new Size(320.0, 240.0));
$anchorEl = findElement(
    $lab2->root(),
    static fn(Element $e): bool => $e->widget() instanceof Button
        && $e->widget()->commandName() === 'lab.anchor',
);
$lab2->openPanel(FlatBox::make(80.0, 30.0), $anchorEl);
check('浮层栈 · 前置：锚点还在 ⇒ 浮层在栈里', $lab2->overlays()->count() === 1);
$lab2->root()->unmount();
$lab2->frame(16.0, $lab2Tight);
check(
    '浮层栈 · 锚点卸载 ⇒ 下一帧自动关（不悬在左上角冒充别人的框）',
    $lab2->overlays()->count() === 0,
    'depth=' . $lab2->overlays()->count(),
);

// ④ 翻转：锚点贴底边 + BELOW ⇒ 面板翻到锚点上方
$lab3Surface = new RecordingSurface();
$lab3Surface->resize(320, 240, 1.0);
$lab3 = new OverlayLabApplication();
$lab3->attach($lab3Surface, BoxConstraints::tight(new Size(320.0, 240.0)));
$lab3Tight = BoxConstraints::tight(new Size(320.0, 240.0));
$bgEl = findElement(
    $lab3->root(),
    static fn(Element $e): bool => $e->widget() instanceof Button
        && $e->widget()->commandName() === 'lab.bottom',
);
$bgBox = $bgEl?->effectiveBox();
[$bgX, $bgY] = $bgBox === null ? [0.0, 0.0] : RenderBox::absoluteOrigin($bgBox);
$lab3->openPanel(FlatBox::make(80.0, 60.0), $bgEl, OverlayEntry::BELOW);
$lab3->frame(16.0, $lab3Tight);
$flipTop = $lab3->overlays()->top();
check(
    '浮层栈 · 锚点贴底边 ⇒ BELOW 翻到上方（只翻一次，不做通用避让）',
    $flipTop !== null && $flipTop->y + 60.0 <= $bgY + 1.0 && $flipTop->y >= 0.0,
    $flipTop === null ? 'no entry' : sprintf('面板底 y=%.6g 锚点 y=%.6g', $flipTop->y + 60.0, $bgY),
);
$lab3->closeMenu();

// ⑤ 嵌套 base：浮层里的锚点 ⇒ 子浮层落点 = 父浮层原点 + 锚点相对位置
$parentPanel = FlatBox::make(120.0, 90.0);
$parentEntry = $lab3->openPanel($parentPanel, $bgEl, OverlayEntry::ABOVE);
$lab3->frame(32.0, $lab3Tight);
$parentEntry = $lab3->overlays()->top();
$innerEl = $parentEntry?->element;
$innerBox = $innerEl?->effectiveBox();
[$innerAbsX, $innerAbsY] = $innerBox === null ? [0.0, 0.0] : RenderBox::absoluteOrigin($innerBox);
$childEntry = $lab3->openPanel(FlatBox::make(40.0, 20.0), $innerEl, OverlayEntry::BELOW);
$lab3->frame(48.0, $lab3Tight);
$childEntry = $lab3->overlays()->top();
check(
    '浮层栈 · 嵌套浮层的锚点坐标系（父层 base + 相对偏移，不是客户区绝对坐标）',
    $parentEntry !== null && $childEntry !== null
    && ($parentEntry->x !== 0.0 || $parentEntry->y !== 0.0)
    && abs($childEntry->x - $innerAbsX) < 1.0
    && abs($childEntry->y - ($innerAbsY + ($innerBox?->size()?->height ?? 0.0))) < 1.0,
    $parentEntry === null || $childEntry === null ? 'no entry' : sprintf(
        '父=(%.6g,%.6g) 内层绝对=(%.6g,%.6g) 子=(%.6g,%.6g)',
        $parentEntry->x,
        $parentEntry->y,
        $innerAbsX,
        $innerAbsY,
        $childEntry->x,
        $childEntry->y,
    ),
);

// ⑥ barrier=true ⇒ 点浮层外不穿透到主树按钮
$lab4Surface = new RecordingSurface();
$lab4Surface->resize(320, 240, 1.0);
$lab4 = new OverlayLabApplication();
$lab4->attach($lab4Surface, BoxConstraints::tight(new Size(320.0, 240.0)));
$lab4Tight = BoxConstraints::tight(new Size(320.0, 240.0));
$lab4Anchor = findElement(
    $lab4->root(),
    static fn(Element $e): bool => $e->widget() instanceof Button
        && $e->widget()->commandName() === 'lab.anchor',
);
$lab4->openPanel(FlatBox::make(40.0, 20.0), $lab4Anchor);
$lab4->frame(16.0, $lab4Tight);
$lab4Bg = findElement(
    $lab4->root(),
    static fn(Element $e): bool => $e->widget() instanceof Button
        && $e->widget()->commandName() === 'lab.bottom',
);
$lab4BgBox = $lab4Bg?->effectiveBox();
[$l4x, $l4y] = $lab4BgBox === null ? [0.0, 0.0] : RenderBox::absoluteOrigin($lab4BgBox);
$lab4->seen = [];
$lab4->dispatchInput(PointerEvent::down(
    $l4x + ($lab4BgBox?->size()?->width ?? 0.0) / 2.0,
    $l4y + ($lab4BgBox?->size()?->height ?? 0.0) / 2.0,
));
check(
    '浮层栈 · barrier=true 点浮层外 ⇒ 主树按钮未被激活（吞掉，不穿透）',
    $lab4->seen === [],
    'seen=' . implode(',', $lab4->seen),
);

// ⑦ barrier=false（Tooltip / Toast）⇒ 点浮层外穿透到主树
$lab5Surface = new RecordingSurface();
$lab5Surface->resize(320, 240, 1.0);
$lab5 = new OverlayLabApplication();
$lab5->attach($lab5Surface, BoxConstraints::tight(new Size(320.0, 240.0)));
$lab5Tight = BoxConstraints::tight(new Size(320.0, 240.0));
$lab5Anchor = findElement(
    $lab5->root(),
    static fn(Element $e): bool => $e->widget() instanceof Button
        && $e->widget()->commandName() === 'lab.anchor',
);
$lab5->openPanel(FlatBox::make(40.0, 20.0), $lab5Anchor, OverlayEntry::BELOW, false);
$lab5->frame(16.0, $lab5Tight);
$lab5Bg = findElement(
    $lab5->root(),
    static fn(Element $e): bool => $e->widget() instanceof Button
        && $e->widget()->commandName() === 'lab.bottom',
);
$lab5BgBox = $lab5Bg?->effectiveBox();
[$l5x, $l5y] = $lab5BgBox === null ? [0.0, 0.0] : RenderBox::absoluteOrigin($lab5BgBox);
$lab5->seen = [];
$lab5->dispatchInput(PointerEvent::down(
    $l5x + ($lab5BgBox?->size()?->width ?? 0.0) / 2.0,
    $l5y + ($lab5BgBox?->size()?->height ?? 0.0) / 2.0,
));
check(
    '浮层栈 · barrier=false 点浮层外 ⇒ 穿透到主树按钮（Tooltip 不吃点击）',
    $lab5->seen === ['lab.bottom'],
    'seen=' . implode(',', $lab5->seen),
);

// ⑧ 焦点回锚：模态关掉后焦点回到打开前的节点（`01` §6.4）
$lab6Surface = new RecordingSurface();
$lab6Surface->resize(320, 240, 1.0);
$lab6 = new OverlayLabApplication();
$lab6->attach($lab6Surface, BoxConstraints::tight(new Size(320.0, 240.0)));
$lab6Tight = BoxConstraints::tight(new Size(320.0, 240.0));
$lab6Anchor = findElement(
    $lab6->root(),
    static fn(Element $e): bool => $e->widget() instanceof Button
        && $e->widget()->commandName() === 'lab.anchor',
);
$lab6->focusForTest($lab6Anchor);
$lab6->openModalBox(60.0, 24.0, '回锚');
$lab6->frame(16.0, $lab6Tight);
$lab6->closeModal();
$lab6->frame(32.0, $lab6Tight);
check(
    '浮层栈 · 关闭后焦点回到打开前的节点（restoreFocus 真被消费）',
    $lab6->focusedForTest() === $lab6Anchor,
    $lab6->focusedForTest() === null ? '焦点被清空' : '焦点在别处',
);

// ⑨ 焦点陷阱：模态开着 ⇒ 主树的按钮不在 Tab 序里（Tab 出不去）
$lab7Surface = new RecordingSurface();
$lab7Surface->resize(320, 240, 1.0);
$lab7 = new OverlayLabApplication();
$lab7->attach($lab7Surface, BoxConstraints::tight(new Size(320.0, 240.0)));
$lab7Tight = BoxConstraints::tight(new Size(320.0, 240.0));
$lab7->openModalBox(60.0, 24.0, '陷阱', true);
$lab7->frame(16.0, $lab7Tight);
$tabOrder = $lab7->focusOrderForTest();
$inMainTree = 0;
foreach ($tabOrder as $tabEl) {
    $isAnchor = $tabEl->widget() instanceof Button
        && in_array($tabEl->widget()->commandName(), ['lab.anchor', 'bg.hit'], true);
    if ($isAnchor) {
        $inMainTree++;
    }
}
check(
    '浮层栈 · 模态开着 ⇒ Tab 序只剩浮层（focusTrap：主树按钮进不来）',
    $tabOrder !== [] && $inMainTree === 0,
    '链长=' . count($tabOrder) . ' 主树按钮=' . $inMainTree,
);

// ⑩ 指针捕获（`06` §5 / §11.2 夹具 #9）：捕获期不命中测试
$capSurface = new RecordingSurface();
$capSurface->resize(320, 240, 1.0);
$cap = new OverlayLabApplication();
$cap->attach($capSurface, BoxConstraints::tight(new Size(320.0, 240.0)));
$capAnchor = findElement(
    $cap->root(),
    static fn(Element $e): bool => $e->widget() instanceof Button
        && $e->widget()->commandName() === 'lab.anchor',
);
$capBottom = findElement(
    $cap->root(),
    static fn(Element $e): bool => $e->widget() instanceof Button
        && $e->widget()->commandName() === 'lab.bottom',
);
$capBottomBox = $capBottom?->effectiveBox();
[$cbx, $cby] = $capBottomBox === null ? [0.0, 0.0] : RenderBox::absoluteOrigin($capBottomBox);
$cap->capturePointer($capAnchor);
$cap->pointerLog = [];
$cap->dispatchInput(PointerEvent::move($cbx + 5.0, $cby + 5.0));
check(
    '指针捕获 · 捕获期 move 落在别的控件上 ⇒ 仍归捕获者（不做命中测试）',
    $cap->pointerLog === ['move'] && $cap->captureNode() === $capAnchor,
    'log=' . implode(',', $cap->pointerLog),
);
$cap->dispatchInput(PointerEvent::up($cbx + 5.0, $cby + 5.0));
check(
    '指针捕获 · 抬起 ⇒ 自动释放（不释放就是"事件永远发给死节点"）',
    $cap->captureNode() === null && $cap->pointerLog === ['move', 'up'],
    'log=' . implode(',', $cap->pointerLog),
);
$cap->pointerLog = [];
$cap->dispatchInput(PointerEvent::move($cbx + 6.0, $cby + 6.0));
check(
    '指针捕获 · 释放后 move 不再进捕获者（回到正常命中）',
    $cap->pointerLog === [],
    'log=' . implode(',', $cap->pointerLog),
);

// ⑪ 浮层优先于捕获（`06` §10）：捕获中点浮层外 ⇒ 先释放捕获 + 关浮层
$cap->capturePointer($capAnchor);
$cap->openPanel(FlatBox::make(40.0, 20.0), $capAnchor);
$cap->frame(16.0, BoxConstraints::tight(new Size(320.0, 240.0)));
$cap->pointerLog = [];
$cap->dispatchInput(PointerEvent::down($cbx + 5.0, $cby + 5.0));
check(
    '指针捕获 · 捕获中点浮层外 ⇒ 释放捕获 + 关浮层（浮层优先，down 不转发给捕获者）',
    $cap->captureNode() === null && $cap->overlays()->count() === 0 && $cap->pointerLog === [],
    'cap=' . ($cap->captureNode() === null ? 'null' : 'set')
    . ' depth=' . $cap->overlays()->count()
    . ' log=' . implode(',', $cap->pointerLog),
);

// ⑫ 捕获者卸载 ⇒ 自动释放 + DIAGNOSTIC（`06` §5.2 释放路径 ②）
//    ★ DIAGNOSTIC **不落盘**（只进进程内环形缓冲）⇒ 读 `recentDiagnostics()`，
//      读日志文件永远是空的（这条差点写成假绿）。
$cap->capturePointer($capAnchor);
$cap->root()->unmount();
$cap->frame(32.0, BoxConstraints::tight(new Size(320.0, 240.0)));
$captureDiag = false;
foreach (Errors::recentDiagnostics() as $diag) {
    $text = is_array($diag) ? (string) ($diag['message'] ?? '') : (string) $diag;
    if (str_contains($text, '捕获者已卸载')) {
        $captureDiag = true;
    }
}
check(
    '指针捕获 · 捕获者卸载 ⇒ 自动释放并报 DIAGNOSTIC（不静默冻住）',
    $cap->captureNode() === null && $captureDiag,
    'cap=' . ($cap->captureNode() === null ? 'null' : 'set')
    . ' diag=' . ($captureDiag ? 'yes' : 'no'),
);

// ⑬ 窗口失活（`WM_ACTIVATE` + `WA_INACTIVE`）⇒ 收口所有指针手势（`06` §5.2 释放路径 ③）
//    ★ 为什么必须有这条：指针手势的收口信号**全部来自指针自己**（`up` / `leave`），
//      而"拖到一半 Alt+Tab 出去"这两个都不会来 —— 指针没动（没有 `WM_MOUSEMOVE`），
//      用户在别处松手（`WM_LBUTTONUP` 落在别的窗口）⇒ 捕获 / 竞技场 / 拖动 /
//      框选 / 拇指全部卡在"进行中"。现象是"某个控件永久只收它的事件"。
$blurSurface = new RecordingSurface();
$blurSurface->resize(320, 240, 1.0);
$blur = new OverlayLabApplication();
$blur->attach($blurSurface, BoxConstraints::tight(new Size(320.0, 240.0)));
$blurAnchor = findElement(
    $blur->root(),
    static fn(Element $e): bool => $e->widget() instanceof Button
        && $e->widget()->commandName() === 'lab.anchor',
);
$blurBottom = findElement(
    $blur->root(),
    static fn(Element $e): bool => $e->widget() instanceof Button
        && $e->widget()->commandName() === 'lab.bottom',
);
$blurBottomBox = $blurBottom?->effectiveBox();
[$bbx, $bby] = $blurBottomBox === null ? [0.0, 0.0] : RenderBox::absoluteOrigin($blurBottomBox);

$blur->capturePointer($blurAnchor);
$blur->pointerLog = [];
$blur->dispatchInput(PointerEvent::move($bbx + 5.0, $bby + 5.0));
$blur->dispatchInput(new WindowDeactivatedEvent());
check(
    '窗口失活 · 捕获被释放（否则回到窗口后只有那个节点收事件）',
    $blur->captureNode() === null && $blur->pointerLog === ['move'],
    'cap=' . ($blur->captureNode() === null ? 'null' : 'set')
    . ' log=' . implode(',', $blur->pointerLog),
);
$blur->pointerLog = [];
$blur->dispatchInput(PointerEvent::move($bbx + 6.0, $bby + 6.0));
check(
    '窗口失活 · 释放后 move 回到正常命中（不再进原捕获者）',
    $blur->pointerLog === [],
    'log=' . implode(',', $blur->pointerLog),
);

// ⑭ 失活**不关浮层**（W3 裁决：失焦 ≠ 放弃，`06` §10「关闭四来源」里没有这一条）
//    ★ 反向守卫：以后有人"顺手"把关浮层加进失活路径，这条会红。
$blur->openPanel(FlatBox::make(40.0, 20.0), $blurAnchor);
$blur->frame(16.0, BoxConstraints::tight(new Size(320.0, 240.0)));
$blurDepth = $blur->overlays()->count();
$blur->dispatchInput(new WindowDeactivatedEvent());
check(
    '窗口失活 · 浮层不关（失焦 ≠ 放弃：用户可能只是去别处看一眼）',
    $blurDepth > 0 && $blur->overlays()->count() === $blurDepth,
    'before=' . $blurDepth . ' after=' . $blur->overlays()->count(),
);

// ⑮ 失活幂等 + 未挂载也安全
//    ★ 后半条钉的是代码形状：失活分支在"未挂载"守卫**之前**（窗口可能在拆卸
//      途中失去前台，那一刻树已经没了，但手势状态仍要清）。
$blur->dispatchInput(new WindowDeactivatedEvent());
$blurDetachedOk = true;
try {
    (new OverlayLabApplication())->dispatchInput(new WindowDeactivatedEvent());
} catch (\Throwable $e) {
    $blurDetachedOk = false;
}
check(
    '窗口失活 · 幂等，且未挂载时也安全（分支在"未挂载"守卫之前）',
    $blur->captureNode() === null
        && $blur->overlays()->count() === $blurDepth
        && $blurDetachedOk,
    'cap=' . ($blur->captureNode() === null ? 'null' : 'set')
    . ' depth=' . $blur->overlays()->count()
    . ' detached=' . ($blurDetachedOk ? 'ok' : 'threw'),
);

// ── ㉟ 合成与跳过 layout（arch-v2 `05` §5.4 / §12.2 #10–#12，W4）──
//    ★ 判据是**调用次数**，不是"画面看起来变了"：画面在"重排了但结果一样"和
//      "没重排"两种情况下完全一样 ⇒ 只有探针能分辨。

$lcTight = BoxConstraints::tight(new Size(320.0, 240.0));
$lcWidget = new LayoutCountWidget(new Size(80.0, 40.0));
$lcEl = $lcWidget->createElement();
$lcEl->mount(null, $context);
$lcBox = $lcEl->effectiveBox();
$lcSurface = new RecordingSurface();
$lcSurface->resize(320, 240, 1.0);
$lcFrame = new Frame($lcSurface);

$lcFrame->run($lcEl, $lcTight, 0.0);
check(
    '合成 · 首帧：layout 排一次、paint 画一次（量具本身不是空转）',
    $lcBox instanceof LayoutCountBox && $lcBox->layoutCalls === 1 && $lcBox->paintCalls === 1,
    $lcBox instanceof LayoutCountBox
        ? sprintf('layout=%d paint=%d', $lcBox->layoutCalls, $lcBox->paintCalls)
        : 'no box',
);

// #12：纯动画帧 —— 没 rebuild、约束没变 ⇒ 跳过 layout，但 paint 照画
$lcFrame->run($lcEl, $lcTight, 16.0);
check(
    '合成 · 纯动画帧跳过 layout、paint 仍执行（§12.2 #12）',
    $lcBox instanceof LayoutCountBox && $lcBox->layoutCalls === 1 && $lcBox->paintCalls === 2,
    $lcBox instanceof LayoutCountBox
        ? sprintf('layout=%d paint=%d', $lcBox->layoutCalls, $lcBox->paintCalls)
        : 'no box',
);

// #10：setCompose 只改合成参数，不触发 layout
$lcBox instanceof LayoutCountBox && $lcBox->setCompose(0.5, 3.0, 0.0);
$lcFrame->run($lcEl, $lcTight, 32.0);
check(
    '合成 · setCompose 不触发 layout（§12.2 #10）',
    $lcBox instanceof LayoutCountBox
    && $lcBox->layoutCalls === 1
    && $lcBox->paintCalls === 3
    && $lcBox->composeOpacity() === 0.5,
    $lcBox instanceof LayoutCountBox
        ? sprintf('layout=%d paint=%d opacity=%.3f', $lcBox->layoutCalls, $lcBox->paintCalls, $lcBox->composeOpacity())
        : 'no box',
);

// setCompose 的参数必须**真被画出来**（写而不读 = 未实现）：录到的命令里
// 要有一条 OP_LAYER_PUSH（opacity < 1 开层）和一条 OP_TRANSLATE（dx ≠ 0）
$lcList = $lcSurface->list();
$lcHasLayer = false;
$lcHasTranslate = false;
for ($i = 0, $n = $lcList->count(); $i < $n; $i++) {
    $op = $lcList->opAt($i);
    if ($op === DisplayList::OP_LAYER_PUSH) {
        $lcHasLayer = true;
    } elseif ($op === DisplayList::OP_TRANSLATE) {
        $lcHasTranslate = true;
    }
}
check(
    '合成 · 合成参数真被画出来（opacity 开层 + dx 平移各一条命令）',
    $lcHasLayer && $lcHasTranslate,
    'layer=' . ($lcHasLayer ? 'yes' : 'no') . ' translate=' . ($lcHasTranslate ? 'yes' : 'no'),
);

// #11：settle() 的返回值是"本帧有没有 rebuild"—— W4 跳过 layout 的唯一判据
$tickEl = (new TickerWidget())->createElement();
$tickEl->mount(null, $context);
$tickEl->settle(); // 清掉挂载那一轮的脏
$tickState = $tickEl instanceof StatefulElement ? $tickEl->state() : null;
if ($tickState instanceof TickerState) {
    $tickState->tick();
}
$tickDirtySettle = $tickEl->settle();
check(
    '合成 · settle() 有脏 ⇒ true（W4 靠它判断能不能跳过 layout）',
    $tickDirtySettle === true,
    'settle=' . var_export($tickDirtySettle, true)
    . ' state=' . ($tickState === null ? 'null' : get_class($tickState))
    . ' builds=' . ($tickState instanceof TickerState ? $tickState->builds : -1),
);
check(
    '合成 · settle() 干净 ⇒ false（不脏还排 = 每帧全树重排，等于没优化）',
    $tickEl->settle() === false,
);


// ── ㉞c 卡片（§15.7：带内边距的盒样式控件 —— 示例台"分组/层次"的地基）──
//    ★ 每条判据都要能区分"有 Card"与"没有 Card"（否则是空转）：
//      ① 内边距**真进**盒尺寸（少了它内容贴边，卡片退化成一个描边矩形）
//      ② 内容原点 = padding（盒尺寸对、偏移对不上 ⇒ 内容画在角上）
//      ③ 内禀宽含 padding（少了它 Row/Dock 量出的自然宽窄一圈 ⇒ 文字被挤）
//      ④ 显式 ->background() ⇒ 默认边框/圆角**整套让位**（§15.7 纪律 3）
//      ⑤ 阴影三层外扩且由外向内加深（单层实心 = 描边，不是影子）

$cardTextWidget = Text::make('卡片内容');
$cardTextEl = $cardTextWidget->createElement();
$cardTextEl->mount(null, $context);
$cardTextBox = $cardTextEl->effectiveBox();
$cardTextBox?->layout(BoxConstraints::unbounded());
$cardTextSize = $cardTextBox?->size();

$cardWidget = Card::make($cardTextWidget);
$cardEl = $cardWidget->createElement();
$cardEl->mount(null, $context);
$cardBox = $cardEl->effectiveBox();
$cardBox?->layout(BoxConstraints::unbounded());

// ★ M2-5：`RenderCard` 已删，Card 是 `StatelessWidget` ⇒ `effectiveBox()` 拿到的是
//   **子控件自己的盒**，卡片样式住在它的 `mods` 上。判据因此从"是不是那个类"变成
//   "样式有没有落到那个盒上"（后者才是真正要守的东西）。
check(
    'Card · 样式落在子控件的 mods 上（不再是独立的 RenderCard 盒）',
    $cardBox !== null && $cardBox->mods()->hasVisual() && $cardBox->mods()->hasPadding()
);
check(
    'Card · 内边距真进盒尺寸（默认 16 ⇒ 盒 = 文字 + 32，两轴都是）',
    $cardBox?->size() !== null && $cardTextSize !== null
        && abs($cardBox->size()->width - ($cardTextSize->width + 32.0)) < 0.01
        && abs($cardBox->size()->height - ($cardTextSize->height + 32.0)) < 0.01,
    sprintf(
        '盒 %.6g×%.6g 文字 %.6g×%.6g',
        $cardBox?->size()?->width ?? -1.0,
        $cardBox?->size()?->height ?? -1.0,
        $cardTextSize?->width ?? -1.0,
        $cardTextSize?->height ?? -1.0,
    ),
);

// ★ M2-5 起内容原点不再靠"父盒平移子盒"，而是 `contentOriginX/Y()`（`02` §4.1.1）
check(
    'Card · 内容原点 = padding（不是 0 —— 那内容就贴在描边上）',
    $cardBox !== null
        && abs($cardBox->contentOriginX() - 16.0) < 0.01
        && abs($cardBox->contentOriginY() - 16.0) < 0.01,
    sprintf('contentOrigin %.6g,%.6g', $cardBox?->contentOriginX() ?? -1.0, $cardBox?->contentOriginY() ?? -1.0),
);
check(
    'Card · 内禀宽含 padding（Row/Dock 量自然宽不会窄一圈 ⇒ 文字不被挤）',
    $cardBox?->intrinsicWidth(0.0) !== null && $cardTextSize !== null
        && abs($cardBox->intrinsicWidth(0.0) - ($cardTextSize->width + 32.0)) < 0.01,
    sprintf('内禀宽 %.6g', $cardBox?->intrinsicWidth(0.0) ?? -1.0),
);

// —— 空 Card：不崩、尺寸 = 内边距（"没内容也要占住 padding"）——
// ★ 走 Element 挂载：`build()` 对"没内容"给的是 `SizedBox::make()`（纯转发盒），
//   盒尺寸 = 内边距由基类 ⑤ 步加回。
$emptyCardEl = Card::make()->paddingAll(8.0)->createElement();
$emptyCardEl->mount(null, $context);
$emptyCardBox = $emptyCardEl->effectiveBox();
$emptyCardBox?->layout(BoxConstraints::unbounded());
check(
    'Card · 无内容 ⇒ 尺寸 = 内边距（不崩、不产生 INF）',
    $emptyCardBox?->size() !== null
        && abs($emptyCardBox->size()->width - 16.0) < 0.01
        && abs($emptyCardBox->size()->height - 16.0) < 0.01,
    sprintf('%.6g×%.6g', $emptyCardBox?->size()?->width ?? -1.0, $emptyCardBox?->size()?->height ?? -1.0),
);

/**
 * 把盒画到白底光栅上（先平移，好让"盒外"的阴影有地方落）。
 */
$paintCardAt = function (RenderBox $box, int $w, int $h, float $dx, float $dy) {
    $list = new DisplayList();
    $rec = new RecordingCanvas($list);
    $rec->save();
    $rec->translate($dx, $dy);
    $box->paint($rec);
    $rec->restore();

    $canvas = new RasterCanvas($w, $h, Color::white(), Scale::of(1.0));
    $list->replay($canvas);

    return $canvas;
};

// —— 默认样式：底 Pane + 边 Border（1px）+ 圆角 2 ——
$defaultCard = Card::make(Text::make('卡片内容'));
$defaultCardBox = $defaultCard->createElement();
$defaultCardBox->mount(null, $context);
$defaultCardElBox = $defaultCardBox->effectiveBox();
$defaultCardElBox?->layout(BoxConstraints::unbounded());
$defaultSize = $defaultCardElBox?->size();
$defaultCanvas = $defaultCardElBox === null
    ? null
    : $paintCardAt($defaultCardElBox, (int) ceil($defaultSize->width) + 8, (int) ceil($defaultSize->height) + 8, 4.0, 4.0);

// 左边框中点（x=4 ⇒ 盒的左边界；y 取中线避开圆角）
$midY = $defaultCanvas === null ? 0 : (int) round(4.0 + $defaultSize->height / 2.0);
$edgePx = $defaultCanvas?->pixelAt(4, $midY);
check(
    'Card · 默认有边框（左边框中点不是白底 —— 有 1px 描边卡片才"立"得住）',
    $edgePx !== null && !$edgePx->equals(Color::white()),
    $edgePx === null ? 'no canvas' : sprintf('rgb(%d,%d,%d)', $edgePx->r, $edgePx->g, $edgePx->b),
);
check(
    'Card · 默认圆角（左上角外露白底 —— 直角矩形不是 LayUI 卡片形态）',
    $defaultCanvas?->pixelAt(4, 4)->equals(Color::white()) === true,
    $defaultCanvas === null ? 'no canvas' : 'p(4,4) 有墨',
);

// —— 显式 ->background() ⇒ 默认边框/圆角整套让位（§15.7 纪律 3）——
$flatCard = Card::make(Text::make('卡片内容'))->background(Color::fromRgb(0xFF, 0x00, 0x00));
$flatEl = $flatCard->createElement();
$flatEl->mount(null, $context);
$flatBox = $flatEl->effectiveBox();
$flatBox?->layout(BoxConstraints::unbounded());
$flatSize = $flatBox?->size();
$flatCanvas = $flatBox === null
    ? null
    : $paintCardAt($flatBox, (int) ceil($flatSize->width) + 8, (int) ceil($flatSize->height) + 8, 4.0, 4.0);
check(
    'Card · 显式 background ⇒ 默认边框让位（边上就是纯底色，不是灰描边）',
    $flatCanvas?->pixelAt(4, (int) round(4.0 + $flatSize->height / 2.0))?->equals(Color::fromRgb(0xFF, 0x00, 0x00)) === true,
    $flatCanvas === null ? 'no canvas' : sprintf(
        'rgb(%d,%d,%d)',
        $flatCanvas->pixelAt(4, (int) round(4.0 + $flatSize->height / 2.0))->r,
        $flatCanvas->pixelAt(4, (int) round(4.0 + $flatSize->height / 2.0))->g,
        $flatCanvas->pixelAt(4, (int) round(4.0 + $flatSize->height / 2.0))->b,
    ),
);
check(
    'Card · 显式 background ⇒ 默认圆角也让位（角上是纯底色 ⇒ 直角）',
    $flatCanvas?->pixelAt(4, 4)?->equals(Color::fromRgb(0xFF, 0x00, 0x00)) === true,
);

// —— 阴影：三层外扩、由外向内加深（单层实心 = 描边，不是影子）——
$shadowCard = Card::make(Text::make('卡片内容'))->shadow(0.20);
$shadowEl = $shadowCard->createElement();
$shadowEl->mount(null, $context);
$shadowBox = $shadowEl->effectiveBox();
$shadowBox?->layout(BoxConstraints::unbounded());
$shadowSize = $shadowBox?->size();
$shadowCanvas = $shadowBox === null
    ? null
    : $paintCardAt($shadowBox, (int) ceil($shadowSize->width) + 24, (int) ceil($shadowSize->height) + 24, 12.0, 12.0);

// 盒左边界 x=12 ⇒ 外扩 1/2/3 层分别落在 x=11 / 10 / 9
$r = static fn (?int $x): int => $shadowCanvas === null ? -1 : $shadowCanvas->pixelAt($x, (int) round(12.0 + $shadowSize->height / 2.0))->r;
check(
    'Card · 阴影三层外扩且由外向内加深（r9 > r10 > r11 ⇒ 是过渡带不是描边）',
    $shadowCanvas !== null && $r(9) > $r(10) && $r(10) > $r(11) && $r(11) < 255,
    sprintf('r(9)=%d r(10)=%d r(11)=%d', $r(9), $r(10), $r(11)),
);
check(
    'Card · 阴影不越过第 3 层（x=8 仍是纯白 —— 影子有边界，不糊一片）',
    $shadowCanvas?->pixelAt(8, (int) round(12.0 + $shadowSize->height / 2.0))->equals(Color::white()) === true,
);
$noShadowCanvas = $defaultCardElBox === null
    ? null
    : $paintCardAt($defaultCardElBox, (int) ceil($defaultSize->width) + 24, (int) ceil($defaultSize->height) + 24, 12.0, 12.0);
check(
    'Card · shadow 默认 0 ⇒ 盒外纯白（不要影子时一个像素都不多画）',
    $noShadowCanvas?->pixelAt(11, (int) round(12.0 + $defaultSize->height / 2.0))->equals(Color::white()) === true,
);


// —— flex 的交叉轴拉伸（`FlexAlign::Stretch`）——
//    ★ 这条分支在生产代码里**长期零用法**（示例台的三层卡片外壳是第一个），
//      2026-10-17 一用就炸真机：旧实现把**主轴与交叉轴写反了**（`Row` 给了紧宽），
//      同级 `Expanded` 被挤成 0 宽 ⇒ 内容区整片空白 + 第一帧 FATAL。
//      ⇒ 判据必须同时钉"交叉轴拉满"与"主轴仍自然"两面，只钉一面漏掉的就是这个缺陷。

$naturalTextSize = static function (string $text) use ($context) {
    $el = Text::make($text)->createElement();
    $el->mount(null, $context);
    $box = $el->effectiveBox();
    $box?->layout(BoxConstraints::unbounded());

    return $box?->size();
};
$kidsOf = static function (?RenderBox $box): array {
    $out = [];
    $box?->visitChildren(function (RenderBox $c) use (&$out): void {
        $out[] = $c;
    });

    return $out;
};

$stretchNatural = $naturalTextSize('拉伸');

$stretchRowBox = Row::make()->cross(FlexAlign::Stretch)->children([Text::make('拉伸'), Text::make('自然')])
    ->createElement();
$stretchRowBox->mount(null, $context);
$stretchRow = $stretchRowBox->effectiveBox();
$stretchRow?->layout(BoxConstraints::tight(new Size(300.0, 120.0)));
$stretchRowKids = $kidsOf($stretchRow);
check(
    'Flex · Row + cross(Stretch) ⇒ 子盒高拉满 120（交叉轴拉伸）',
    ($stretchRowKids[0] ?? null)?->size() !== null
        && abs($stretchRowKids[0]->size()->height - 120.0) < 0.01,
    sprintf('实际高 %.6g', $stretchRowKids[0]?->size()?->height ?? -1.0),
);
check(
    'Flex · Row + cross(Stretch) ⇒ 子盒宽仍自然（紧宽是旧缺陷的形态）',
    ($stretchRowKids[0] ?? null)?->size() !== null && $stretchNatural !== null
        && abs($stretchRowKids[0]->size()->width - $stretchNatural->width) < 0.01,
    sprintf('实际宽 %.6g 自然 %.6g', $stretchRowKids[0]?->size()?->width ?? -1.0, $stretchNatural?->width ?? -1.0),
);

$startRowBox = Row::make()->children([Text::make('拉伸')])->createElement();
$startRowBox->mount(null, $context);
$startRow = $startRowBox->effectiveBox();
$startRow?->layout(BoxConstraints::tight(new Size(300.0, 120.0)));
$startRowKids = $kidsOf($startRow);
check(
    'Flex · Row 默认 Start ⇒ 子盒高仍自然（不拉伸是默认语义）',
    ($startRowKids[0] ?? null)?->size() !== null && $stretchNatural !== null
        && abs($startRowKids[0]->size()->height - $stretchNatural->height) < 0.01,
    sprintf('实际高 %.6g 自然 %.6g', $startRowKids[0]?->size()?->height ?? -1.0, $stretchNatural?->height ?? -1.0),
);

$stretchColBox = Column::make()->cross(FlexAlign::Stretch)->children([Text::make('拉伸')])->createElement();
$stretchColBox->mount(null, $context);
$stretchCol = $stretchColBox->effectiveBox();
$stretchCol?->layout(BoxConstraints::tight(new Size(200.0, 100.0)));
$stretchColKids = $kidsOf($stretchCol);
check(
    'Flex · Column + cross(Stretch) ⇒ 子盒宽拉满 200（交叉轴是宽）',
    ($stretchColKids[0] ?? null)?->size() !== null
        && abs($stretchColKids[0]->size()->width - 200.0) < 0.01,
    sprintf('实际宽 %.6g', $stretchColKids[0]?->size()?->width ?? -1.0),
);

// —— 弹性子也要拉满（第二遍 `tightMain` 的对称修正）——
$stretchFlexRowBox = Row::make()->cross(FlexAlign::Stretch)->children([
    Text::make('自然'),
    Expanded::make(Text::make('弹性')),
])->createElement();
$stretchFlexRowBox->mount(null, $context);
$stretchFlexRow = $stretchFlexRowBox->effectiveBox();
$stretchFlexRow?->layout(BoxConstraints::tight(new Size(300.0, 120.0)));
$stretchFlexKids = $kidsOf($stretchFlexRow);
$flexKid = $stretchFlexKids[1] ?? null;
$flexKidBox = $flexKid === null ? null : $kidsOf($flexKid)[0] ?? null;
check(
    'Flex · Row + Stretch ⇒ 弹性子也拉满高（否则同行高度参差）',
    $flexKidBox?->size() !== null && abs($flexKidBox->size()->height - 120.0) < 0.01,
    sprintf('弹性子内容高 %.6g', $flexKidBox?->size()?->height ?? -1.0),
);

// —— Card 在紧宽约束下内容撑满（不要 `loosen()`）——
//    ★ 父把宽定死（`Column::cross(Stretch)`）时松掉紧约束 ⇒ 卡片撑满、内容缩在
//      左边，观感像"没对齐"。与 `RenderPadding` 同口径：只 deflate，不放松。
// ★ M2-5：`Card` 不再多一层盒 ⇒ `effectiveBox()` 就是**内容自己的盒**，
//   "内容宽度"得往下再走一层才看得见（用 `Column::cross(Stretch)` —— 也正是
//   示例台侧栏的真实形态）。
$tightCardEl = Card::make(
    Column::make()->cross(FlexAlign::Stretch)->children([Text::make('卡片内容')])
)->createElement();
$tightCardEl->mount(null, $context);
$tightCardCol = $tightCardEl->effectiveBox();
$tightCardCol?->layout(BoxConstraints::tight(new Size(300.0, 80.0)));
$tightCardKids = $kidsOf($tightCardCol);
check(
    'Card · 紧宽约束 ⇒ 内容撑满（300 − 2×16 = 268，不是文字自然宽）',
    ($tightCardKids[0] ?? null)?->size() !== null
        && abs($tightCardKids[0]->size()->width - 268.0) < 0.01,
    sprintf('内容宽 %.6g', $tightCardKids[0]?->size()?->width ?? -1.0),
);


// ── ㉞e 定尺寸（`SizedBox`）与扁平按钮（`ButtonVariant::Flat`）──
//    ★ 两条都是"侧栏定宽 + 像菜单项"的直接依赖，2026-10-17 由示例台逼出来。
//      判据必须能区分"有 SizedBox"与"没有"：只量盒尺寸不够 —— `Row`/`Dock` 量
//      **自然宽**走的是 `intrinsicWidth()`，不覆写那里，定尺寸在"量"这一路
//      完全看不见（静默），所以 ③ 必须单独钉。

$fixedTextSize = static function (string $text) use ($context) {
    $el = Text::make($text)->createElement();
    $el->mount(null, $context);
    $box = $el->effectiveBox();
    $box?->layout(BoxConstraints::unbounded());

    return $box?->size();
};

$fixedNatural = $fixedTextSize('侧栏');

$sizedBoxEl = SizedBox::make(Text::make('侧栏'))->width(200.0)->createElement();
$sizedBoxEl->mount(null, $context);
$sizedBox = $sizedBoxEl->effectiveBox();
$sizedBox?->layout(BoxConstraints::loose(new Size(940.0, 620.0)));
$sizedBoxKids = [];
$sizedBox?->visitChildren(function (RenderBox $c) use (&$sizedBoxKids): void {
    $sizedBoxKids[] = $c;
});
check(
    'SizedBox · 定宽 ⇒ 盒宽 200 且孩子被拉满 200（只定盒不定孩子 = 内容缩在左边）',
    $sizedBox?->size() !== null && abs($sizedBox->size()->width - 200.0) < 0.01
        && ($sizedBoxKids[0] ?? null)?->size() !== null
        && abs($sizedBoxKids[0]->size()->width - 200.0) < 0.01,
    sprintf(
        '盒 %.6g 孩子 %.6g 内容自然 %.6g',
        $sizedBox?->size()?->width ?? -1.0,
        $sizedBoxKids[0]?->size()?->width ?? -1.0,
        $fixedNatural?->width ?? -1.0,
    ),
);
check(
    'SizedBox · 未定的轴透传（高随内容，不是 0 也不是约束上限）',
    $sizedBox?->size() !== null && $fixedNatural !== null
        && abs($sizedBox->size()->height - $fixedNatural->height) < 0.01,
    sprintf('实际高 %.6g 内容高 %.6g', $sizedBox?->size()?->height ?? -1.0, $fixedNatural?->height ?? -1.0),
);
check(
    'SizedBox · 内禀宽 == 定宽（Row/Dock 量自然宽也看得见；漏了这条等于没定）',
    $sizedBox?->intrinsicWidth(0.0) !== null && abs($sizedBox->intrinsicWidth(0.0) - 200.0) < 0.01,
    sprintf('内禀宽 %.6g', $sizedBox?->intrinsicWidth(0.0) ?? -1.0),
);

// —— 父更紧时听父（tight(100) 里塞 width(200) ⇒ 100，且不许抛"min 不得大于 max"）
$clampedEl = SizedBox::make(Text::make('侧栏'))->width(200.0)->createElement();
$clampedEl->mount(null, $context);
$clamped = $clampedEl->effectiveBox();
$clamped?->layout(BoxConstraints::tight(new Size(100.0, 40.0)));
$clampedKids = [];
$clamped?->visitChildren(function (RenderBox $c) use (&$clampedKids): void {
    $clampedKids[] = $c;
});
check(
    'SizedBox · 父更紧时听父（tight 100 里 width 200 ⇒ 盒 100 且孩子 100，不抛约束违规）',
    $clamped?->size() !== null && abs($clamped->size()->width - 100.0) < 0.01
        && ($clampedKids[0] ?? null)?->size() !== null
        && abs($clampedKids[0]->size()->width - 100.0) < 0.01,
    sprintf('盒 %.6g 孩子 %.6g', $clamped?->size()?->width ?? -1.0, $clampedKids[0]?->size()?->width ?? -1.0),
);

// —— 无子盒：尺寸就是定值（不崩、不产生 INF）
// ★ 走 Element 挂载而不是裸 `createRenderObject()`：W5 之后几何住 `mods`，
//   由 `Element::syncBox()` 递给盒 —— 裸造盒拿不到定值，量出来是 0×0。
$emptySizedBoxEl = SizedBox::make()->size(24.0, 8.0)->createElement();
$emptySizedBoxEl->mount(null, $context);
$emptySizedBox = $emptySizedBoxEl->effectiveBox();
$emptySizedBox?->layout(BoxConstraints::unbounded());
check(
    'SizedBox · 无子盒 ⇒ 尺寸 = 定值（占位空隙的用法）',
    $emptySizedBox?->size() !== null
        && abs($emptySizedBox->size()->width - 24.0) < 0.01
        && abs($emptySizedBox->size()->height - 8.0) < 0.01,
    sprintf('%.6g×%.6g', $emptySizedBox?->size()?->width ?? -1.0, $emptySizedBox?->size()?->height ?? -1.0),
);

// —— W8-3 AspectRatio：比例是 `mods.aspect` 字段（不是新渲染盒），由布局管线 ① 步解出
$aspectEl = AspectRatio::make(2.0, Text::make('封面'))->createElement();
$aspectEl->mount(null, $context);
$aspect = $aspectEl->effectiveBox();
$aspect?->layout(BoxConstraints::loose(new Size(940.0, 620.0)));
check(
    'AspectRatio · 宽铺满、高按比例（940 ÷ 2 = 470）',
    $aspect?->size() !== null
        && abs($aspect->size()->width - 940.0) < 0.01
        && abs($aspect->size()->height - 470.0) < 0.01,
    sprintf('盒 %.6g×%.6g', $aspect?->size()?->width ?? -1.0, $aspect?->size()?->height ?? -1.0),
);

$aspectCapEl = AspectRatio::make(2.0, Text::make('封面'))->createElement();
$aspectCapEl->mount(null, $context);
$aspectCap = $aspectCapEl->effectiveBox();
$aspectCap?->layout(new BoxConstraints(0.0, 200.0, 0.0, 60.0));
check(
    'AspectRatio · 高度不够时按高度回退（宽 200 要 100 高、只有 60 ⇒ 120×60）',
    $aspectCap?->size() !== null
        && abs($aspectCap->size()->width - 120.0) < 0.01
        && abs($aspectCap->size()->height - 60.0) < 0.01,
    sprintf('盒 %.6g×%.6g', $aspectCap?->size()?->width ?? -1.0, $aspectCap?->size()?->height ?? -1.0),
);

$aspectFixedEl = AspectRatio::make(2.0, Text::make('封面'))->width(200.0)->createElement();
$aspectFixedEl->mount(null, $context);
$aspectFixed = $aspectFixedEl->effectiveBox();
$aspectFixed?->layout(BoxConstraints::loose(new Size(940.0, 620.0)));
check(
    'AspectRatio · 显式 width 优先（200 ÷ 2 = 100）',
    $aspectFixed?->size() !== null
        && abs($aspectFixed->size()->width - 200.0) < 0.01
        && abs($aspectFixed->size()->height - 100.0) < 0.01,
    sprintf('盒 %.6g×%.6g', $aspectFixed?->size()?->width ?? -1.0, $aspectFixed?->size()?->height ?? -1.0),
);

$aspectBothEl = AspectRatio::make(2.0, Text::make('封面'))->width(200.0)->height(80.0)->createElement();
$aspectBothEl->mount(null, $context);
$aspectBoth = $aspectBothEl->effectiveBox();
$aspectBoth?->layout(BoxConstraints::loose(new Size(940.0, 620.0)));
check(
    'AspectRatio · 两轴都定死 ⇒ 比例无自由度（200×80，不改成 100 高）',
    $aspectBoth?->size() !== null
        && abs($aspectBoth->size()->width - 200.0) < 0.01
        && abs($aspectBoth->size()->height - 80.0) < 0.01,
    sprintf('盒 %.6g×%.6g', $aspectBoth?->size()?->width ?? -1.0, $aspectBoth?->size()?->height ?? -1.0),
);

$aspectPadEl = AspectRatio::make(2.0, Text::make('封面'))->paddingAll(10.0)->createElement();
$aspectPadEl->mount(null, $context);
$aspectPad = $aspectPadEl->effectiveBox();
$aspectPad?->layout(BoxConstraints::loose(new Size(240.0, 240.0)));
check(
    'AspectRatio · 比例作用于**盒**（含 padding）：240 宽 ⇒ 120 高',
    $aspectPad?->size() !== null
        && abs($aspectPad->size()->width - 240.0) < 0.01
        && abs($aspectPad->size()->height - 120.0) < 0.01,
    sprintf('盒 %.6g×%.6g', $aspectPad?->size()?->width ?? -1.0, $aspectPad?->size()?->height ?? -1.0),
);

// —— W8-5 RichText：一段里多个样式；颜色/字号由 run 自带，排版一次成段
$richEl = RichText::make([
    Span::make('保存失败：')->bold(),
    Span::make('磁盘空间不足')->color(Color::fromHex('#d93025')),
])->createElement();
$richEl->mount(null, $context);
$richBox = $richEl->effectiveBox();
$richBox?->layout(BoxConstraints::loose(new Size(500.0, 120.0)));
$richPara = $richBox instanceof RenderRichText ? $richBox->paragraph() : null;
$richRuns = $richPara?->lines[0]->runs ?? [];
$richLast = $richRuns === [] ? null : $richRuns[count($richRuns) - 1];
check(
    'RichText · 两段各自成 run（前段默认色、后段显式色）',
    count($richRuns) >= 2
        && $richRuns[0]->argb === -1
        && $richLast !== null
        && $richLast->argb === Color::fromHex('#d93025')->toArgb(),
    sprintf('runs=%d 首=%d 尾=%s', count($richRuns), $richRuns[0]->argb ?? 99, var_export($richLast?->argb, true)),
);
$richTailFirst = null;
foreach ($richRuns as $richRun) {
    if ($richRun->argb === Color::fromHex('#d93025')->toArgb()) {
        $richTailFirst = $richRun;
        break;
    }
}
check(
    'RichText · 后段 cluster 是全文字节偏移（不是 0；段内回退 run 也一起抬）',
    $richTailFirst !== null
        && $richTailFirst->glyphs !== []
        && $richTailFirst->glyphs[0]->cluster === strlen('保存失败：'),
    sprintf('后段首 run 的 cluster=%s 期望=%d', var_export($richTailFirst?->glyphs[0]->cluster ?? null, true), strlen('保存失败：')),
);

$richBigEl = RichText::make([Span::make('大')->size(28.0), Span::make('小')])->createElement();
$richBigEl->mount(null, $context);
$richBig = $richBigEl->effectiveBox();
$richBig?->layout(BoxConstraints::loose(new Size(500.0, 200.0)));
check(
    'RichText · 行高按最大 span 字号（28pt ⇒ 高 ≥ 28，不被 14pt 压扁）',
    $richBig?->size() !== null && $richBig->size()->height >= 28.0,
    sprintf('高 %.1f', $richBig?->size()?->height ?? -1.0),
);

$richBaseStyle = TextStyle::of(14.0);
$richKeyA = Paragraph::keyForSpans([new TextSpan('x', $richBaseStyle, 0xFFFF0000)], $richBaseStyle, 100.0, new Scale(1.0));
$richKeyB = Paragraph::keyForSpans([new TextSpan('x', $richBaseStyle, 0xFF0000FF)], $richBaseStyle, 100.0, new Scale(1.0));
check(
    'RichText · 缓存键不含颜色（只换色不重排 —— §8.1 键只收排版输入）',
    $richKeyA === $richKeyB,
    sprintf('A=%s B=%s', substr($richKeyA, 0, 8), substr($richKeyB, 0, 8)),
);

// —— W8-5 续：行内链接（tag 命中）与下划线 ——
$linkEl = RichText::make([
    Span::make('加载失败，'),
    Span::make('重试')->tag('retry')->underline()->color(Color::fromHex('#009688')),
    Span::make('。'),
])->createElement();
$linkEl->mount(null, $context);
$linkBox = $linkEl->effectiveBox();
$linkBox?->layout(BoxConstraints::loose(new Size(500.0, 60.0)));
$linkPara = $linkBox instanceof RenderRichText ? $linkBox->paragraph() : null;
$linkRun = null;
$linkStartX = 0.0;
foreach ($linkPara?->lines[0]->runs ?? [] as $linkCandidate) {
    if ($linkCandidate->tag === 'retry') {
        $linkRun = $linkCandidate;
        break;
    }
    $linkStartX += $linkCandidate->width;
}
check(
    'RichText · tag 与下划线落在同一个 run 上',
    $linkRun !== null && $linkRun->underline === true,
    sprintf('tag=%s underline=%s', $linkRun?->tag ?? '(null)', $linkRun !== null && $linkRun->underline ? 'y' : 'n'),
);
check(
    'RichText · tagAt 命中链接中点（字节偏移映射，不另算 pen）',
    $linkRun !== null && $linkPara !== null
        && $linkPara->tagAt($linkStartX + $linkRun->width / 2.0, 5.0) === 'retry',
    sprintf('tagAt=%s', var_export($linkPara?->tagAt($linkStartX + ($linkRun?->width ?? 0.0) / 2.0, 5.0), true)),
);
check(
    'RichText · tagAt 在普通文本上返回空串（不是"整段都是链接"）',
    $linkPara !== null && $linkPara->tagAt(1.0, 5.0) === '',
    sprintf('tagAt=%s', var_export($linkPara?->tagAt(1.0, 5.0), true)),
);
check(
    'RichText · cursorAt：链接上 PointingHand、普通文字 null（按位置变，不是整段）',
    $linkBox instanceof RenderRichText
        && $linkRun !== null
        && $linkBox->cursorAt($linkStartX + $linkRun->width / 2.0, 5.0) === CursorShape::PointingHand
        && $linkBox->cursorAt(1.0, 5.0) === null,
    sprintf(
        'link=%s plain=%s',
        $linkBox instanceof RenderRichText && $linkRun !== null
            ? ($linkBox->cursorAt($linkStartX + $linkRun->width / 2.0, 5.0)?->name ?? 'null')
            : 'n/a',
        $linkBox instanceof RenderRichText ? ($linkBox->cursorAt(1.0, 5.0)?->name ?? 'null') : 'n/a',
    ),
);

// —— W8-5 续作三：背景色（绘制输入，不进缓存键）——
$bgEl = RichText::make([
    Span::make('正常 '),
    Span::make('高亮')->background(Color::fromHex('#fff3cd')),
])->createElement();
$bgEl->mount(null, $context);
$bgBox = $bgEl->effectiveBox();
$bgBox?->layout(BoxConstraints::loose(new Size(400.0, 60.0)));
$bgPara = $bgBox instanceof RenderRichText ? $bgBox->paragraph() : null;
$bgRun = null;
foreach ($bgPara?->lines[0]->runs ?? [] as $bgCandidate) {
    if ($bgCandidate->background >= 0) {
        $bgRun = $bgCandidate;
        break;
    }
}
check(
    'RichText · background 落在 run 上（ARGB 透传，与 argb 分开）',
    $bgRun !== null
        && $bgRun->background === Color::fromHex('#fff3cd')->toArgb()
        && $bgRun->argb === -1,
    sprintf('bg=%s fg=%s', var_export($bgRun?->background, true), var_export($bgRun?->argb, true)),
);

$bgKeyA = Paragraph::keyForSpans([new TextSpan('x', $richBaseStyle, -1, '', false, -1)], $richBaseStyle, 100.0, new Scale(1.0));
$bgKeyB = Paragraph::keyForSpans([new TextSpan('x', $richBaseStyle, -1, '', false, 0xFFFFFF00)], $richBaseStyle, 100.0, new Scale(1.0));
check(
    'RichText · 缓存键不含背景色（改背景不重排，与颜色同一条纪律）',
    $bgKeyA === $bgKeyB,
    sprintf('A=%s B=%s', substr($bgKeyA, 0, 8), substr($bgKeyB, 0, 8)),
);

// —— W8-5 续作四：富文本键盘可达（焦点变化走 `State::didChangeFocus`）——
$taps = [];
$kbRoot = Column::make()->children([
    RichText::make([
        Span::make('加载失败，'),
        Span::make('重试')->tag('retry')->underline(),
    ])->onSpanTap(function (string $tag) use (&$taps): void {
        $taps[] = $tag;
    }),
])->createElement();
$kbRoot->mount(null, $curContext);
$kbRoot->effectiveBox()?->layout(BoxConstraints::loose(new Size(400.0, 60.0)));

$kbFm = new FocusManager();
$kbFm->collect([$kbRoot]);
check(
    'RichText · onSpanTap ⇒ 声明可聚焦（进 Tab 序）',
    count($kbFm->order()) === 1,
    'order=' . count($kbFm->order()),
);

$kbEl = $kbFm->order()[0] ?? null;
$kbBox = $kbEl?->effectiveBox();
check(
    'RichText · firstTag 找到第一个链接（Enter 的落点）',
    $kbBox instanceof RenderRichText && $kbBox->firstTag() === 'retry',
    'tag=' . ($kbBox instanceof RenderRichText ? $kbBox->firstTag() : 'n/a'),
);

if ($kbEl !== null) {
    $kbFm->focus($kbEl);
}

$kbConsumed = $kbFm->dispatchKey(KeyEvent::down(KeyChord::enter()));
check(
    'RichText · Enter 触发第一个链接（keyboard 缝，键被消费）',
    $kbConsumed && $taps === ['retry'],
    sprintf('consumed=%s taps=%s', $kbConsumed ? 'y' : 'n', implode(',', $taps)),
);

// —— W8-5 续作五：焦点环（应用级叠加，零控件改动）——
$ringApp = new FocusRingApplication();
$ringProbe = new SurfaceProbe();
$ringProbe->resize(300, 200, 1.0);
$ringApp->attach($ringProbe, BoxConstraints::tight(new Size(300.0, 200.0)));
$ringApp->frame(0.0, BoxConstraints::tight(new Size(300.0, 200.0)));
$ringBefore = $ringProbe->canvas?->pixelAt(19, 19);

$ringApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
$ringWants = $ringApp->wantsFrame();
$ringApp->frame(1.0, BoxConstraints::tight(new Size(300.0, 200.0)));
$ringAfter = $ringProbe->canvas?->pixelAt(19, 19);
$ringAccent = Color::fromRgb(0x09, 0x69, 0xDA);

check(
    '焦点环 · 焦点变了但还没画 ⇒ wantsFrame 为真（第七判据；否则环要等下次输入）',
    $ringWants === true,
    'wantsFrame=' . ($ringWants ? 'y' : 'n'),
);

check(
    '焦点环 · 无焦点时 (19,19) 不是描边（不是"一直画")',
    $ringBefore !== null && !$ringBefore->equals($ringAccent),
    'rgb=' . ($ringBefore === null ? 'null' : $ringBefore->r . ',' . $ringBefore->g . ',' . $ringBefore->b),
);
check(
    '焦点环 · Tab 聚焦后控件左上外扩 1px 是 Accent 描边',
    $ringAfter !== null && $ringAfter->equals($ringAccent),
    'rgb=' . ($ringAfter === null ? 'null' : $ringAfter->r . ',' . $ringAfter->g . ',' . $ringAfter->b),
);
check(
    '焦点环 · 画完后悔回落（wantsFrame 的第七判据有界）',
    $ringApp->wantsFrame() === false,
    'wantsFrame=' . ($ringApp->wantsFrame() ? 'y' : 'n'),
);

// :focus-visible —— 指针交互后隐藏（焦点可能没变，环仍然该消失）
$ringApp->dispatchInput(PointerEvent::down(5.0, 5.0));
$ringAfterClickWants = $ringApp->wantsFrame();
$ringApp->frame(2.0, BoxConstraints::tight(new Size(300.0, 200.0)));
$ringAfterClick = $ringProbe->canvas?->pixelAt(19, 19);

check(
    '焦点环 · 指针点击后隐藏（:focus-visible —— 鼠标操作后不该再看到蓝框）',
    $ringAfterClick !== null && !$ringAfterClick->equals($ringAccent),
    'rgb=' . ($ringAfterClick === null ? 'null' : $ringAfterClick->r . ',' . $ringAfterClick->g . ',' . $ringAfterClick->b),
);
check(
    '焦点环 · "隐藏"本身也请求一帧（目标 null，不靠下次输入才消失）',
    $ringAfterClickWants === true,
    'wantsFrame=' . ($ringAfterClickWants ? 'y' : 'n'),
);

// —— 扁平按钮：盒样式 isNone（无底无边框），字色走正文色
$flatBox = Button::make('导航项')->variant(ButtonVariant::Flat)->createRenderObject($context);
check(
    'ButtonVariant::Flat ⇒ 盒样式 isNone（无底无边框 —— 看起来像菜单项而不是按钮）',
    $flatBox instanceof RenderButton && $flatBox->boxStyle()->isNone(),
    $flatBox instanceof RenderButton
        ? sprintf('底 %s 边 %s', $flatBox->boxStyle()->background === null ? 'null' : 'set', $flatBox->boxStyle()->borderColor === null ? 'null' : 'set')
        : 'not RenderButton',
);
$flatDefaultBox = Button::make('导航项')->createRenderObject($context);
check(
    'ButtonVariant::Default 仍有边框（Flat 是新形态，不是把默认改扁）',
    $flatDefaultBox instanceof RenderButton && !$flatDefaultBox->boxStyle()->isNone(),
);
$flatBox->layout(BoxConstraints::unbounded());
check(
    'ButtonVariant::Flat 仍占位（有尺寸 ⇒ 仍然可点，不是"视觉消失"）',
    $flatBox->size() !== null && $flatBox->size()->width > 0.0 && $flatBox->size()->height > 0.0,
    sprintf('%.6g×%.6g', $flatBox->size()?->width ?? -1.0, $flatBox->size()?->height ?? -1.0),
);

// —— 集成：侧栏定宽 + 扁平导航项（示例台的真实组合）
$navProbeEl = SizedBox::make(
    Card::make(
        Column::make()->cross(FlexAlign::Stretch)->children([
            Button::make('导航项一')->variant(ButtonVariant::Flat),
        ])
    )->paddingAll(10.0)
)->width(200.0)->createElement();
$navProbeEl->mount(null, $context);
$navProbe = $navProbeEl->effectiveBox();
$navProbe?->layout(BoxConstraints::loose(new Size(940.0, 600.0)));
// ★ M2-5：`Card` 不再是盒 ⇒ 树少一层（SizedBox → Column → Button）
$navCol = $kidsOf($navProbe)[0] ?? null;
$navBtnKids = $kidsOf($navCol);
check(
    '集成 · 侧栏 SizedBox(200) ⇒ 导航项宽 180（整行可点，右边缘齐）',
    ($navBtnKids[0] ?? null)?->size() !== null
        && abs($navBtnKids[0]->size()->width - 180.0) < 0.01,
    sprintf('导航项宽 %.6g', $navBtnKids[0]?->size()?->width ?? -1.0),
);

// ── ㉟ apps/users 完整样板（§19 M3 出口判据：§A.2 的完整应用可跑）──

$usersSurface = new SvgSurface();
$usersSurface->resize(900, 640, 1.0);
$usersTight = BoxConstraints::tight(new Size(900.0, 640.0));
$usersApp = new UsersApplication(new MemoryUserSource());
$usersApp->attach($usersSurface, $usersTight);

check(
    'apps/users · 完整应用 attach 后就有盒（§A.2 可跑：值缝+动作缝+多页+表单+加速键）',
    $usersApp->root() !== null && $usersApp->rootBox() !== null,
);
check(
    'apps/users · buildRoot 只调一次（框架不重复调；Root 只组合）',
    $usersApp->rootBuilds() === 1,
);
check(
    'apps/users · 首屏渲染有墨（chrome 条 + 状态栏 + 列表行）',
    substr_count($usersSurface->svg(), '<rect') > 50,
);

// ① 值缝 · **跨页共享**（AppStore：状态栏跨页活着）
$usersApp->dispatch('users.refresh');
check(
    'apps/users · 跨页共享态：刷新 ⇒ 状态栏播报"已刷新"（AppStore.say）',
    $usersApp->appStore()->statusLine() === '已刷新',
);
$usersApp->dispatch('nav.signup');
check('apps/users · 跨页共享态：nav.signup ⇒ currentPage=signup', $usersApp->appStore()->currentPage() === 'signup');
check(
    'apps/users · 跨页共享态：切页后状态栏仍在（共享态不随页销毁）',
    $usersApp->appStore()->statusLine() === '已刷新',
);

// ② 值缝 · **局部态**（SignupForm：property hooks + 派生缓存）
$usersApp->signupForm()->name = '张三';
$usersApp->signupForm()->email = 'zhang@example.com';
check(
    'apps/users · 局部态：表单字段写入（property hooks ⇒ 字段名只写一次）',
    $usersApp->signupForm()->name === '张三' && $usersApp->signupForm()->validate() === [],
);
$usersApp->dispatch('form.submit');
check(
    'apps/users · 局部态：合法表单 ⇒ 提交成功并回跳列表页（form.submit 走全链）',
    $usersApp->appStore()->currentPage() === 'users'
        && $usersApp->appStore()->statusLine() === '注册成功',
);
$usersApp->signupForm()->name = '';
$usersApp->dispatch('form.submit');
check(
    'apps/users · 局部态：空姓名 ⇒ 校验失败回显（走 Store 的 submitError，不走 State）',
    str_contains($usersApp->signupForm()->submitError, '姓名不能为空'),
);

// ③ 动作缝 · 第三页 + 删除 + 派生缓存失效
$usersApp->dispatch('nav.settings');
check('apps/users · 动作缝：nav.settings ⇒ 第三页', $usersApp->appStore()->currentPage() === 'settings');
$rowsBefore = count($usersApp->userStore()->rows());
$usersApp->dispatch('user.del', CommandArg::id(1));
check(
    'apps/users · 动作缝：user.del ⇒ 行数减一 + 状态栏播报',
    count($usersApp->userStore()->rows()) === $rowsBefore - 1
        && $usersApp->appStore()->statusLine() === '已删除',
);
$usersApp->userStore()->setQuery('不存在的名字');
check(
    'apps/users · 派生缓存：搜索 ⇒ visible() 变窄（缓存随依赖失效）',
    $usersApp->userStore()->visible() === [],
);
$usersApp->userStore()->setQuery('');

// ④ 加速键（§19 M3 出口判据：Ctrl+S 等加速键可用）
$usersApp->signupForm()->name = '';
$usersApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('S')));
check(
    'apps/users · 加速键 Ctrl+S ⇒ form.submit 被派发（第 ① 环，焦点之前）',
    str_contains($usersApp->signupForm()->submitError, '姓名不能为空'),
);

// ⑤ 菜单打开接线（Widget 不许依赖 App ⇒ 应用把 showMenu 包成闭包传进来）
$openerInjected = 0;
$probeRoot = UsersRoot::make($usersApp->appStore(), $usersApp->userStore(), $usersApp->signupForm())
    ->menuOpener(function () use (&$openerInjected): void {
        $openerInjected++;
    });
check(
    'apps/users · 菜单 opener 可注入（Root 只组合，打开归应用）',
    $probeRoot->menuOpenerHandler() instanceof Closure,
);
unset($probeRoot);

// ⑥ 菜单端到端：Tab 到菜单标题（焦点链第 4 个）⇒ Enter ⇒ 浮层真的开了
for ($i = 0; $i < 4; $i++) {
    $usersApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
}
$usersApp->dispatchInput(KeyEvent::down(KeyChord::enter()));
check(
    'apps/users · 菜单标题 Enter ⇒ 浮层打开（opener → showMenu 端到端）',
    $usersApp->isMenuOpen(),
);
$usersApp->dispatchInput(KeyEvent::down(KeyChord::escape()));
check('apps/users · ESC 关掉菜单', !$usersApp->isMenuOpen());

// ⑦ 帧驱动（宿主每帧调；浮层不开也照常）
$framesBefore = $usersSurface->frameCount();
$usersApp->frame(16.0, $usersTight);
check(
    'apps/users · frame() 由宿主驱动（表面多一帧）',
    $usersSurface->frameCount() === $framesBefore + 1,
);

// ── ㊱ TaskHost 端口：`Job` / `TaskHost` / `SyncTaskHost`（§17.2 后端 B）──
//
// ★★ 2026-10-18 **P0 的语义变更**：从"同步直调 `$onDone`"改为"**入队 → 每帧 drain**"。
//   同步后端仍在 `submit()` 时跑完 `run()`，但回调**不在** `submit()` 的调用栈上 ——
//   它由 `drain()` 在 UI 线程的下一帧取出执行（§17.4.2 的两条不变量）。
//   ★ 这条改动是**有意的**，理由见 `SyncTaskHost` 的类注释：换异步后端时
//     "提交 → 某一帧拿到结果"的语义不变，业务代码不用动。**改回直调就是破坏它。**
//   端口层判据（八条）：① 当帧不回调 ② drain 后回调/值正确/恰好一次 ③ drain 取走即清空
//   ④ FIFO ⑤ 搬得动对象 ⑥ 签名收窄仍生效 ⑦ swap（drain 中新入队留到下一帧）
//   ⑧ 错误通道（run 抛 ⇒ onError，不调 onDone）。应用层四条在 ㊲。
//
// ★ **诚实边界**：端口的**生产消费者**仍是 `HttpUserSource`（§A.2.8，归 M4）；
//   本段是"端口契约"的判据，`apps/users` 走的是 `MemoryUserSource`（同步）。

$tasks = new SyncTaskHost();
check('TaskHost · `SyncTaskHost` 是端口的实现', $tasks instanceof TaskHost);
check('TaskHost · `SumJob` 是 `Job` 的实现（类，不是闭包）', new SumJob(1) instanceof Job);

// ① 当帧不回调：submit 只入队
$delivered = [];
$tasks->submit(new SumJob(10), function (int $sum) use (&$delivered): void {
    $delivered[] = $sum;
});
check(
    'TaskHost · submit() 之后回调**未**执行（结果只在队列里，不在调用栈上）',
    $delivered === [],
    'delivered=' . implode(',', $delivered),
);

// ② drain 取出并回调：值正确 + 恰好一次
$tasks->drain(function (Completion $c): void {
    ($c->onDone)($c->value);
});
check(
    'TaskHost · drain() 把结果交给 onDone（1+…+10 = 55）',
    $delivered === [55],
    'delivered=' . implode(',', $delivered),
);
check('TaskHost · 回调恰好一次（不是 0 次也不是 2 次）', count($delivered) === 1);

// ③ drain = 取走：队列已清空 ⇒ 再 drain 不重复回调
$tasks->drain(function (Completion $c): void {
    ($c->onDone)($c->value);
});
check('TaskHost · drain() 取走即清空（再 drain 不重复回调）', count($delivered) === 1, (string) count($delivered));

// ④ 顺序：连提交 ⇒ FIFO
$order = [];
$tasks->submit(new SumJob(1), function (int $n) use (&$order): void { $order[] = 'a' . $n; });
$tasks->submit(new SumJob(3), function (int $n) use (&$order): void { $order[] = 'b' . $n; });
$tasks->submit(new SumJob(6), function (int $n) use (&$order): void { $order[] = 'c' . $n; });
$tasks->drain(function (Completion $c): void { ($c->onDone)($c->value); });
check(
    'TaskHost · 多任务按提交序回贴（FIFO）',
    $order === ['a1', 'b6', 'c21'],
    'order=' . implode(',', $order),
);

// ⑤ 搬对象：闭包签名把 `mixed` 收窄成 `TaskRow` ⇒ 回调拿到的就是 Job 造的那个实例
$gotRow = null;
$tasks->submit(new TaskRowJob('ada'), function (TaskRow $r) use (&$gotRow): void {
    $gotRow = $r;
});
$tasks->drain(function (Completion $c): void { ($c->onDone)($c->value); });
check(
    'TaskHost · 端口搬得动类实例（`mixed` 由调用点闭包签名收窄）',
    $gotRow instanceof TaskRow && $gotRow->name === 'ada' && $gotRow->len === 3,
    $gotRow instanceof TaskRow ? $gotRow->name . '/' . $gotRow->len : 'null',
);

// ⑥ 反向自检：收窄不是"随便什么都收" —— 换类型就该被 PHP 拦下。
//   ★ 抛点在 **drain()**（不是 submit）：队列把"回调时机"挪了，类型检查跟着挪 ——
//     这正是"回调真的在 drain 里跑"的第二重证据。
$typeError = '';
try {
    $tasks->submit(new SumJob(1), function (TaskRow $r): void {});
    $tasks->drain(function (Completion $c): void { ($c->onDone)($c->value); });
} catch (\TypeError $e) {
    $typeError = $e->getMessage();
}
check(
    'TaskHost · 闭包签名收窄在 drain 时生效（传错类型 ⇒ TypeError，不是静默）',
    $typeError !== '',
    $typeError,
);

// ⑦ swap 语义：drain 期间新入队的留到下一帧（否则"回调里再 submit"会让一帧无限长）
$waves = [];
$tasks->submit(new SumJob(1), function (int $n) use (&$waves, $tasks): void {
    $waves[] = 'a' . $n;
    $tasks->submit(new SumJob(2), function (int $m) use (&$waves): void { $waves[] = 'b' . $m; });
});
$tasks->drain(function (Completion $c): void { ($c->onDone)($c->value); });
check(
    'TaskHost · drain 期间新入队的留到下一帧（一帧的工作量有界）',
    $waves === ['a1'],
    'waves=' . implode(',', $waves),
);
$tasks->drain(function (Completion $c): void { ($c->onDone)($c->value); });
check('TaskHost · 下一帧取到上一帧入队的（a1, b3 —— SumJob(2) = 3）', $waves === ['a1', 'b3'], 'waves=' . implode(',', $waves));

// ⑧ 错误通道：`run()` 抛异常 ⇒ 走 onError（两条通道**互斥**，不是都调）
$boom = null;
$okCalled = 0;
$tasks->submit(
    new ThrowingJob('设备离线'),
    function (int $v) use (&$okCalled): void { $okCalled++; },
    function (\Throwable $e) use (&$boom): void { $boom = $e; },
);
$tasks->drain(function (Completion $c): void {
    if ($c->error !== null) {
        ($c->onError)($c->error);

        return;
    }
    ($c->onDone)($c->value);
});
check(
    'TaskHost · run() 抛异常 ⇒ 走 onError（异常原文带出）',
    $boom instanceof \RuntimeException && str_contains($boom->getMessage(), '设备离线'),
    $boom instanceof \Throwable ? $boom->getMessage() : 'null',
);
check('TaskHost · 失败任务**不**调 onDone（两条通道互斥）', $okCalled === 0, (string) $okCalled);

// ── ㊲ P0 接线：结果队列接进帧循环（§17.4.2 的帧驱动 + 同帧重建）──────────
//
// ★ 判据只有**应用层**测得出：端口层的 drain 是手调的，看不出"帧有没有驱动它"、
//   也看不出"drain 排在 settle 之前"。
//   ★ 反例自检（T1 后手做）：把 `Application::frame()` 里的 `pumpTasks()` 挪到
//     `$frame->run(...)` 之后 ⇒ ②"同帧重建"必红（builds 不增、seen 是旧值）。

$taskApp = new TaskApplication();
$taskApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(320.0, 240.0)));
$taskState = $taskApp->watcherState();
check(
    'P0 · 应用默认装上后台端口（`taskHost()` 默认 SyncTaskHost）',
    $taskApp->tasks() instanceof TaskHost,
);
check(
    'P0 · 挂载时 watcher 只建一次',
    $taskState !== null && $taskState->builds === 1,
    'builds=' . ($taskState?->builds ?? -1),
);

// ① 帧驱动：submit 之后当帧不回调，frame() 才回调
$hit = 0;
$taskApp->tasks()?->submit(new SumJob(4), function (int $sum) use (&$hit): void {
    $hit = $sum;
});
check('P0 · submit() 之后回调未执行（帧还没来）', $hit === 0, (string) $hit);
$taskApp->frame(16.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check('P0 · frame() 的 drain 段执行了回调（1+2+3+4 = 10）', $hit === 10, (string) $hit);

// ② 同帧重建：`onDone` 里改 Store ⇒ 本帧 settle 就重建（drain 在 settle **之前**）
$buildsBefore = $taskState?->builds ?? -1;
$taskApp->tasks()?->submit(new SumJob(3), function (int $sum) use ($taskApp): void {
    $taskApp->store->setStatus($sum * 10); // 6 ⇒ 60
});
$taskApp->frame(32.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check(
    'P0 · onDone 里改 Store ⇒ **同帧**重建（builds +1 且读到新值）',
    $taskState !== null && $taskState->builds === $buildsBefore + 1 && $taskState->seen === 60,
    sprintf('builds %d→%d seen=%d', $buildsBefore, $taskState?->builds ?? -1, $taskState?->seen ?? -1),
);

// ③ 队列空了就不再回调（下一帧不重建）—— "结果不会被重复交付"
$buildsAfter = $taskState?->builds ?? -1;
$taskApp->frame(48.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check(
    'P0 · 队列空了就不再回调（下一帧不重建）',
    $taskState !== null && $taskState->builds === $buildsAfter,
    'builds=' . ($taskState?->builds ?? -1),
);

// ④ 失败走 onError（应用层）：回调抛错不许拖垮帧循环
$caught = null;
$taskApp->tasks()?->submit(
    new ThrowingJob('服务不可达'),
    function (int $v): void {
    },
    function (\Throwable $e) use (&$caught): void {
        $caught = $e;
    },
);
$taskApp->frame(64.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check(
    'P0 · 应用层失败走 onError（帧照常走完）',
    $caught instanceof \RuntimeException && $caught->getMessage() === '服务不可达',
    $caught instanceof \Throwable ? $caught->getMessage() : 'null',
);

// ⑤ 不装端口：`taskHost()` 返回 null ⇒ 不建端口、frame() 照常
$noTaskApp = new TaskApplication();
$noTaskApp->withTasks = false;
$noTaskApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(320.0, 240.0)));
check('P0 · taskHost() 返回 null ⇒ 不装端口', $noTaskApp->tasks() === null);
$noTaskApp->frame(16.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check('P0 · 无端口时 frame() 行为不变（drain 段整段跳过）', $noTaskApp->root() !== null);

// ⑥ 未给 onError ⇒ Fatal 序列（三态的 Error 分支不能被静默吞掉）
fresh($log);
[$code, $taskErrOut] = runCase(__FILE__, 'taskerr', null);
check(
    'P0 · 任务失败且未给 onError ⇒ Fatal（响亮，不静默）',
    $code === 3,
    '实际 ' . $code . ' / ' . $taskErrOut,
);
check('P0 · 未给 onError 写了 Errors.log', str_contains(logText($log), '未提供 onError'));
fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㊳ P1：`SlicedJob` 单线程分片（§17.4.3）──────────────────────────────
//
// ★ 它解决的是"能算完、只是算得慢"（过滤一万行 / 排序 / 解析）：不需要并行，
//   只需要别把一帧卡死（WPF 的原话：`await` 不加 `Task.Run` 就是单线程分段计算）。
//   ★ 分片**不走 `TaskHost`** —— 它永远在 UI 线程跑，与"换后端"正交。
//   判据：① 进队列不执行 ② 一帧只推一步 ③ N 帧后完成 ④ 结果与一次性算相同
//   ⑤ 完成后出队且不再步进 ⑥ 每步耗时有界 ⑦ 完成回调改 Store ⇒ 同帧重建
//   ⑧ step 抛异常 ⇒ onError 且出队

$sliceApp = new TaskApplication();
$sliceApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(320.0, 240.0)));

$sliceJob = new CountJob(100000, 10000); // 10 万次累加、每帧 1 万 ⇒ 10 帧
$sliceDone = null;
$sliceApp->submitSliced($sliceJob, function (int $sum) use (&$sliceDone): void {
    $sliceDone = $sum;
});
check(
    'P1 · submitSliced 之后进队列（还没执行）',
    $sliceApp->slicedCount() === 1 && $sliceDone === null,
    'queued=' . $sliceApp->slicedCount() . ' done=' . var_export($sliceDone, true),
);

$sliceApp->frame(16.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check(
    'P1 · 一帧只推一步（不一口气跑完）',
    $sliceJob->steps() === 1 && $sliceDone === null,
    'steps=' . $sliceJob->steps(),
);

for ($i = 0; $i < 9; $i++) {
    $sliceApp->frame(32.0 + $i * 16.0, BoxConstraints::loose(new Size(320.0, 240.0)));
}
check(
    'P1 · 10 帧后完成（10 万 / 每帧 1 万）',
    $sliceJob->steps() === 10 && $sliceDone !== null,
    'steps=' . $sliceJob->steps() . ' done=' . var_export($sliceDone, true),
);
check(
    'P1 · 结果与一次性求和相同（1+…+100000）',
    $sliceDone === (new SumJob(100000))->run(),
    (string) ($sliceDone ?? -1),
);
check('P1 · 完成后出队（不再占用帧预算）', $sliceApp->slicedCount() === 0, (string) $sliceApp->slicedCount());

$stepsAfterDone = $sliceJob->steps();
$sliceApp->frame(256.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check('P1 · 完成后不再步进（下一帧 steps 不变）', $sliceJob->steps() === $stepsAfterDone, (string) $sliceJob->steps());

$maxStepMs = $sliceJob->stepMs === [] ? 999.0 : max($sliceJob->stepMs);
check(
    'P1 · 每步耗时有界（10 万次累加切成 10 份，单步 < 5ms）',
    $maxStepMs < 5.0,
    sprintf('max=%.2fms', $maxStepMs),
);

// ⑦ 完成回调改 Store ⇒ 同帧重建（与 ㊲ 同一条：pumpSliced 也在 settle 之前）
$buildsBeforeSlice = $taskState?->builds ?? -1;
$taskApp->submitSliced(new CountJob(2000, 1000), function (int $sum) use ($taskApp): void {
    $taskApp->store->setStatus(7);
});
$taskApp->frame(80.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check(
    'P1 · 分片未完时不回调（第 1 帧只推进）',
    $taskState !== null && $taskState->builds === $buildsBeforeSlice && $taskApp->slicedCount() === 1,
    'builds=' . ($taskState?->builds ?? -1),
);
$taskApp->frame(96.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check(
    'P1 · 分片完成回调改 Store ⇒ **同帧**重建',
    $taskState !== null && $taskState->builds === $buildsBeforeSlice + 1 && $taskState->seen === 7,
    sprintf('builds %d→%d seen=%d', $buildsBeforeSlice, $taskState?->builds ?? -1, $taskState?->seen ?? -1),
);

// ⑧ 错误通道：step 抛 ⇒ onError 且出队（不静默、不重试）
$sliceErr = null;
$taskApp->submitSliced(
    new FailSliceJob(),
    function (mixed $v): void {
    },
    function (\Throwable $e) use (&$sliceErr): void {
        $sliceErr = $e;
    },
);
$taskApp->frame(112.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check(
    'P1 · step 抛异常 ⇒ onError 且出队（任务终止，不重试）',
    $sliceErr instanceof \RuntimeException && $sliceErr->getMessage() === '分片中途失败' && $taskApp->slicedCount() === 0,
    ($sliceErr instanceof \Throwable ? $sliceErr->getMessage() : 'null') . ' queued=' . $taskApp->slicedCount(),
);

// ── ㊴ P2：`IoPoller` 非阻塞 IO（§17.4.4）────────────────────────────────
//
// ★ 前提已实测（probes §12.3 [J3]）：`stream_socket_pair` + `stream_set_blocking(false)`
//   + `stream_select(..., 0)` 在 AOT 产物里全部可用。
// ★ 为什么不是"线程里阻塞读"：线程起价 30ms、进程 275ms，而 `select(0)` 是微秒级
//   （§17.2.6 的实测）。★ 与 Swoole 的差别：它的 `Event::dispatch()` 阻塞到下一个事件。
// 判据：① 无数据不回调且不阻塞 ② 写入后回调一次且读得到 ③ unwatch 后不再回调
//   ④ 多监听只回调就绪的那个 ⑤ IO 回调改 Store ⇒ 同帧重建

$pollApp = new TaskApplication();
$pollApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(320.0, 240.0)));
check('P2 · 应用挂载后带轮询器（attach 建一次）', $pollApp->io() instanceof IoPoller);

// Windows 没有 AF_UNIX ⇒ 用 PF_INET(2) / SOCK_STREAM(1) / IPPROTO_IP(0)
[$ia, $ib] = stream_socket_pair(2, 1, 0);
stream_set_blocking($ia, false); // ★ 必须：poll 只保证"问一下就走"

$hits = 0;
$got = '';
$token = $pollApp->io()?->watch($ia, true, function ($stream) use (&$hits, &$got): void {
    $hits++;
    $chunk = fread($stream, 16);
    $got .= $chunk === false ? '' : $chunk;
});
check('P2 · watch 返回非零 token 且监听数 +1', $token !== 0 && $pollApp->io()?->watchCount() === 1);

$t0 = microtime(true);
$pollApp->frame(16.0, BoxConstraints::loose(new Size(320.0, 240.0)));
$pollMs = (microtime(true) - $t0) * 1000.0;
check('P2 · 无数据 ⇒ 不回调', $hits === 0, (string) $hits);
// ★ 阈值宽松（50ms）是有意的：误写成阻塞版（超时 null）会**直接挂住整轮**，
//   不会"慢一点"——那样 smoke 是超时失败，比断言失败更响。
check('P2 · poll 不阻塞（无数据的一帧仍很快返回）', $pollMs < 50.0, sprintf('%.1fms', $pollMs));

fwrite($ib, 'hi');
$pollApp->frame(32.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check('P2 · 写入后 ⇒ 回调恰好一次', $hits === 1, (string) $hits);
check('P2 · 回调里读得到数据（非阻塞 fread）', $got === 'hi', $got);

// ③ unwatch：取消后不再回调（数据还在流里也不调）
$pollApp->io()?->unwatch((int) $token);
check('P2 · unwatch 之后监听数归零', $pollApp->io()?->watchCount() === 0, (string) $pollApp->io()?->watchCount());
fwrite($ib, 'x');
$pollApp->frame(48.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check('P2 · unwatch 后不再回调（即使流里有数据）', $hits === 1, (string) $hits);

// ④ + ⑤ 多监听：只有就绪的那个被回调；就绪回调里改 Store ⇒ 同帧重建
[$ic, $idd] = stream_socket_pair(2, 1, 0);
stream_set_blocking($ic, false);
stream_set_blocking($ia, false);
fread($ia, 16); // 读掉上一步残留的 'x'，避免它干扰"只有就绪的那个"

$order = [];
$pollApp->io()?->watch($ia, true, function () use (&$order): void { $order[] = 'a'; });
$pollApp->io()?->watch($ic, true, function () use (&$order, $pollApp): void {
    $order[] = 'c';
    $pollApp->store->setStatus(42);
});

$pollState = $pollApp->watcherState();
$buildsBeforePoll = $pollState?->builds ?? -1;

fwrite($idd, 'y'); // 只有 $ic 就绪
$pollApp->frame(64.0, BoxConstraints::loose(new Size(320.0, 240.0)));
check('P2 · 多监听：只有就绪的那个被回调', $order === ['c'], implode(',', $order));
check(
    'P2 · IO 回调改 Store ⇒ **同帧**重建（poll 在 settle 之前）',
    $pollState !== null && $pollState->builds === $buildsBeforePoll + 1 && $pollState->seen === 42,
    sprintf('builds %d→%d seen=%d', $buildsBeforePoll, $pollState?->builds ?? -1, $pollState?->seen ?? -1),
);

fclose($ia);
fclose($ib);
fclose($ic);
fclose($idd);

// ── ㊵ P3：`ProcessTaskHost` 常驻子进程（§17.4.5）────────────────────────
//
// ★ **真进程、真管道、真 JSON Lines**：worker 用 php.exe 起（生产形态是同一份 AOT 产物的
//   `--drift-worker` 分支，协议与 `ProcessWorker` 完全一致 ⇒ 测的是生产路径）。
// ★ 为什么不是"每任务一进程"：spawn 实测 **275ms/次**（probes §12 ⑧）⇒ 懒启动 + 常驻。
// ★ 为什么不是线程：worker 线程跑不了 AOT 代码（探针 K/L，§17.2.5）。
// 判据：① 懒启动 ② 真进程算对 ③ 串行（在途 ≤ 1）④ 任务失败 ⇒ onError 且 worker 活着
//   ⑤ 崩溃 ⇒ onError 且 worker 停 ⑥ 崩溃后自动重启 ⑦ shutdown 收工

$workerScript = __DIR__ . '/Smoke/App/worker.php';
$procHost = new ProcessTaskHost([PHP_BINARY, $workerScript], SmokeJobRegistry::make());

// ★ `$until` 必须**收参数**（不能靠闭包捕获 `$sink`）：`static fn() => $sink !== []`
//   按值捕获的是**创建时**的空数组 ⇒ 永远 false ⇒ 每次 pump 等满 deadline
//   （本段第一版就是这样，smoke 从 10s 涨到 78s）。
$pump = static function (ProcessTaskHost $host, array &$sink, callable $until): void {
    $deadline = microtime(true) + 10.0;
    while (!$until($sink) && microtime(true) < $deadline) {
        usleep(5000);
        // ★ 走**生产路径**（`$onDone` / `$onError`），不直接读 `Completion->value` ——
        //   否则测的是"队列里有值"，测不出"回调真的被调了"（本段第一版就栽在这：
        //   断言期望 `a1` 却拿到 `1` —— `onDone` 根本没被调）。
        $host->drain(function (Completion $c) use (&$sink): void {
            if ($c->error !== null) {
                if ($c->onError !== null) {
                    ($c->onError)($c->error);
                } else {
                    $sink[] = 'ERR:' . $c->error->getMessage();
                }

                return;
            }
            ($c->onDone)($c->value);
        });
    }
};

// ① 懒启动 + 真进程算对
$sink = [];
$procHost->submit(new NamedSumJob(10), function ($v) use (&$sink): void {
    $sink[] = $v;
});
check('P3 · submit 之后未回调（任务在 worker 里跑）', $sink === [], json_encode($sink));
check('P3 · 懒启动：第一次 submit 才起 worker', $procHost->isRunning());
check('P3 · 在途 1 条', $procHost->inFlightCount() === 1, (string) $procHost->inFlightCount());

$pump($procHost, $sink, static fn(array $s): bool => $s !== []);
check('P3 · 真进程算对了（跨进程跑 AOT 类：1+…+10 = 55）', $sink === [55], json_encode($sink));

// ② 串行：第二条排队（在途恒 ≤ 1）
$sink = [];
$procHost->submit(new NamedSumJob(1), function ($v) use (&$sink): void { $sink[] = 'a' . $v; });
$procHost->submit(new NamedSumJob(3), function ($v) use (&$sink): void { $sink[] = 'b' . $v; });
check(
    'P3 · 串行：第二条在排队（在途仍 1、队列 1）',
    $procHost->inFlightCount() === 1 && $procHost->queuedCount() === 1,
    'inflight=' . $procHost->inFlightCount() . ' queued=' . $procHost->queuedCount(),
);
$pump($procHost, $sink, static fn(array $s): bool => count($s) >= 2);
check('P3 · 两条都跑完且按提交序（a1, b6）', $sink === ['a1', 'b6'], json_encode($sink));

// ②b 并发：池里 3 个 worker **各接一条**（`maxWorkers` 是 opt-in；默认 1 就是上面那条串行）
//   ★ 判据是**结构**不是墙钟：任务睡 200ms 保证「提交第二条时第一条还在跑」，
//     于是「3 条同时在途 + 3 个 worker + 0 排队」只有在真的并行时才成立。
//     不拿墙钟当断言 —— 机器一忙就假红（量具纪律：别让判据依赖负载）。
$poolSink = [];
$pool = new ProcessTaskHost([PHP_BINARY, $workerScript], SmokeJobRegistry::make(), 3);
foreach ([200, 200, 200] as $ms) {
    $pool->submit(new NamedSleepJob($ms), function ($v) use (&$poolSink): void { $poolSink[] = $v; });
}
check(
    'P3 · 池：3 条同时在途 + 3 个 worker + 0 排队（真并行）',
    $pool->inFlightCount() === 3 && $pool->workerCount() === 3 && $pool->queuedCount() === 0,
    'inflight=' . $pool->inFlightCount() . ' workers=' . $pool->workerCount() . ' queued=' . $pool->queuedCount(),
);
$pump($pool, $poolSink, static fn (array $s): bool => count($s) >= 3);
check(
    'P3 · 池：3 条都回来了（各睡 200ms，并行跑完）',
    count($poolSink) === 3 && $poolSink === [200, 200, 200],
    json_encode($poolSink),
);
$pool->shutdown();
check(
    'P3 · 池：shutdown 收掉所有 worker',
    $pool->workerCount() === 0 && $pool->isRunning() === false,
    'workers=' . $pool->workerCount(),
);

// ②c 池上限：**满载排队**，不无界起进程（一个 worker = 一整套 AOT 运行时）
$cappedSink = [];
$capped = new ProcessTaskHost([PHP_BINARY, $workerScript], SmokeJobRegistry::make(), 2);
foreach ([300, 300, 300] as $ms) {
    $capped->submit(new NamedSleepJob($ms), function ($v) use (&$cappedSink): void { $cappedSink[] = $v; });
}
check(
    'P3 · 池上限：第 3 条排队（2 在途 + 1 排队 + 2 个 worker）',
    $capped->inFlightCount() === 2 && $capped->queuedCount() === 1 && $capped->workerCount() === 2,
    'inflight=' . $capped->inFlightCount() . ' queued=' . $capped->queuedCount() . ' workers=' . $capped->workerCount(),
);
$pump($capped, $cappedSink, static fn (array $s): bool => count($s) >= 3);
check('P3 · 池上限：排队那条最后也跑完了', count($cappedSink) === 3, json_encode($cappedSink));
$capped->shutdown();

// ③ 任务失败（worker 里抛）⇒ onError，且**不打死 worker**
$sink = [];
$procHost->submit(
    new NamedBoomJob('设备离线'),
    function ($v): void {
    },
    function (\Throwable $e) use (&$sink): void {
        $sink[] = $e->getMessage();
    },
);
$pump($procHost, $sink, static fn(array $s): bool => $s !== []);
check(
    'P3 · worker 里的异常 ⇒ onError（原文带出）',
    str_contains($sink[0] ?? '', '设备离线'),
    $sink[0] ?? 'null',
);
check('P3 · 一个任务失败不打死 worker', $procHost->isRunning());

// ④ 崩溃隔离：worker 真的死（`exit 9`，catch 拦不住）⇒ onError + worker 停
$sink = [];
$procHost->submit(
    new NamedExitJob(),
    function ($v): void {
    },
    function (\Throwable $e) use (&$sink): void {
        $sink[] = $e->getMessage();
    },
);
$pump($procHost, $sink, static fn(array $s): bool => $s !== []);
check(
    'P3 · worker 崩溃 ⇒ 在途任务 onError（不是静默挂住）',
    str_contains($sink[0] ?? '', '后台进程退出'),
    $sink[0] ?? 'null',
);
check('P3 · 崩溃后 worker 已停', !$procHost->isRunning());

// ⑤ 崩溃后自动重启（同一实例继续用）
$sink = [];
$procHost->submit(new NamedSumJob(4), function ($v) use (&$sink): void { $sink[] = $v; });
$pump($procHost, $sink, static fn(array $s): bool => $s !== []);
check('P3 · 崩溃后自动重启并跑完新任务（1+…+4 = 10）', $sink === [10], json_encode($sink));

// ⑥ 收工
$procHost->shutdown();
check('P3 · shutdown 之后不再有 worker', !$procHost->isRunning());

// ── ㊶ apps/users 的**真异步取数**（真 HTTP + 真子进程，§17.4.5 的落地）────────
//
// ★ 这条链就是 M4 `HttpUserSource` 的预演：`UserStore::load()` → `ProcessTaskHost`
//   → 常驻 worker → **真 HTTP**（`php -S` 的 mock，故意慢 1.2s）→ JSON 回程 → hydrate。
// ★ 它同时兑现了 §11.5 那条结论："`loading` 只在同步 IO 下不可见" —— 有了后台，
//   三态第一次真的对用户有意义。
// 判据：① attach 之后仍 Loading（没被 HTTP 阻塞）② 若干帧后 Ready + 15 行
//   ③ 数据真的来自 HTTP（首行姓名）④ 同步路径（host=null）行为不变（既有 18 条已覆盖）

$mockPort = 18000 + (getmypid() % 1000);
$mockProc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $mockPort, dirname(__DIR__) . '/tools/app-workers/users-mock.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $mockPipes,
);
$mockReady = false;
for ($i = 0; $i < 120; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $mockPort . '/healthz');
    if ($probe !== false) {
        $mockReady = true;

        break;
    }
    usleep(50000);
}
check('异步 · mock 后端起来了（php -S）', $mockReady, 'port=' . $mockPort);

if ($mockReady) {
    $asyncHost = new ProcessTaskHost(
        [PHP_BINARY, dirname(__DIR__) . '/tools/app-workers/users-worker.php'],
        JobRegistry::make(),
    );
    $asyncApp = new UsersApplication(new MemoryUserSource(), $asyncHost, 'http://127.0.0.1:' . $mockPort);
    $asyncApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(900.0, 640.0)));

    check(
        '异步 · attach 之后仍是 Loading（取数没占 UI 线程）',
        $asyncApp->userStore()->status() === LoadStatus::Loading,
        $asyncApp->userStore()->status()->name,
    );

    $asyncT0 = microtime(true);
    $asyncFrames = 0;
    while ($asyncApp->userStore()->status() === LoadStatus::Loading && microtime(true) - $asyncT0 < 10.0) {
        usleep(16000);
        $asyncApp->frame((microtime(true) - $asyncT0) * 1000.0, BoxConstraints::loose(new Size(900.0, 640.0)));
        $asyncFrames++;
    }

    $asyncStore = $asyncApp->userStore();
    check(
        '异步 · 后台取数完成 ⇒ Ready 且 15 行',
        $asyncStore->status() === LoadStatus::Ready && count($asyncStore->rows()) === 15,
        $asyncStore->status()->name . ' rows=' . count($asyncStore->rows()) . ' frames=' . $asyncFrames,
    );
    check(
        '异步 · 数据真的来自 HTTP（首行「张伟」经 worker 往返）',
        ($asyncStore->rows()[0]->name ?? '') === '张伟',
        $asyncStore->rows()[0]->name ?? '(空)',
    );
    check(
        '异步 · 耗时说明它真的等了网络（≥ 1.2s）',
        (microtime(true) - $asyncT0) >= 1.2,
        sprintf('%.2fs', microtime(true) - $asyncT0),
    );

    $asyncHost->shutdown();
}

if (is_resource($mockProc)) {
    proc_terminate($mockProc);
    foreach ($mockPipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($mockProc);
}

// ── 可滚动容器（P1 / 2026-10-17）：偏移进坐标链 + 滚轮路由 ──────────
//   两条线：① 布局层直接测 `RenderScrollView`（视口 / maxScroll 夹取 / 命中按视口判 /
//   **偏移进子盒 offset 而非 paint 期 translate** —— 2026-10-17 padding 缺陷的同族判据，
//   "画在哪 vs 命中哪"的交叉验证此前是结构性盲区）；② 应用层测
//   `ScrollEvent → dispatchInput → scrollAt → scrollBy → 帧末重建 → 盒偏移` 全链。
//   ★ 反例自检：把偏移改回 paint 期 translate（setOffset 去掉、paint 里加 translate）
//   ⇒ ①线 3 条 FAIL（offsetY 恒 0）+ ②线 2 条 FAIL（命中/绘制错位），诊断值精确显示形态。
// ★ 滚动条色用品红：SolidBox 画黑 ⇒ 像素断言能区分"thumb 画了"与"只是内容"。
$scrollInk = Color::fromRgb(0xFF, 0x00, 0xFF);

$scrollChildOf = static function (?RenderScrollView $box): ?RenderBox {
    if ($box === null) {
        return null;
    }
    $kid = null;
    $box->visitChildren(function (RenderObject $child) use (&$kid): void {
        $kid = $child;
    });

    return $kid instanceof RenderBox ? $kid : null;
};

// ① 布局层：内容 100×1000，视口 100×300
$sv0 = new RenderScrollView(0.0, $scrollInk, true);
$sv0->setChild(new SolidBox(new Size(100.0, 1000.0), 100.0, 1000.0));
$sv0Size = $sv0->layout(new BoxConstraints(0.0, 100.0, 0.0, 300.0));
$sv0Kid = $scrollChildOf($sv0);
check(
    'Scroll · 视口 = 约束上限（内容 1000 高 ⇒ 盒停在 100×300，不撑成内容高）',
    $sv0Size->width === 100.0 && $sv0Size->height === 300.0,
    sprintf('%.1f×%.1f', $sv0Size->width, $sv0Size->height),
);
check(
    'Scroll · maxScroll = 内容主轴 − 视口主轴（1000−300 = 700）',
    $sv0->maxScroll(Axis::Vertical) === 700.0,
    (string) $sv0->maxScroll(Axis::Vertical),
);
check(
    'Scroll · 偏移 0 ⇒ 子盒 offsetY = 0（偏移落在子盒 offset 上，不是别处）',
    $sv0Kid?->offsetY() === 0.0,
    (string) ($sv0Kid?->offsetY() ?? -1.0),
);
check(
    'Scroll · 命中按视口判定：视口内的点进子树（内容超长仍命中）',
    $sv0->hitTest(50.0, 250.0) === $sv0Kid,
    $sv0->hitTest(50.0, 250.0) === $sv0Kid ? 'hit' : 'miss',
);
check(
    'Scroll · 视口外的点进不了子树（"裁掉的部分点不到"是结构保证）',
    $sv0->hitTest(50.0, 350.0) === null,
    $sv0->hitTest(50.0, 350.0) === null ? 'null' : 'hit',
);

// ① 布局层：偏移 300 ⇒ 子盒 offsetY = −300（★ 坐标链判据）
$sv1 = new RenderScrollView(300.0, $scrollInk, true);
$sv1->setChild(new SolidBox(new Size(100.0, 1000.0), 100.0, 1000.0));
$sv1->layout(new BoxConstraints(0.0, 100.0, 0.0, 300.0));
$sv1Kid = $scrollChildOf($sv1);
check(
    'Scroll · 偏移 300 ⇒ 子盒 offsetY = −300（★ 偏移进子盒 offset，不是 paint 期 translate）',
    $sv1Kid?->offsetY() === -300.0 && $sv1->appliedOffset(Axis::Vertical) === 300.0,
    'offsetY=' . ($sv1Kid?->offsetY() ?? -1.0) . ' applied=' . $sv1->appliedOffset(Axis::Vertical),
);
check(
    'Scroll · 滚动后命中跟随偏移：视口 (50,250) 命中内容第 550 行（局部坐标跟着偏移走）',
    $sv1->hitTest(50.0, 250.0) === $sv1Kid,
    $sv1->hitTest(50.0, 250.0) === $sv1Kid ? 'hit' : 'miss',
);

// ① 布局层：越界夹取（"内容飞走"的静默形态 ⇒ 结构上不可能）
$sv2 = new RenderScrollView(9999.0, $scrollInk, true);
$sv2->setChild(new SolidBox(new Size(100.0, 1000.0), 100.0, 1000.0));
$sv2->layout(new BoxConstraints(0.0, 100.0, 0.0, 300.0));
check(
    'Scroll · 越界偏移被 maxScroll 夹住（9999 ⇒ 700 ⇒ offsetY = −700）',
    $sv2->appliedOffset(Axis::Vertical) === 700.0 && $scrollChildOf($sv2)?->offsetY() === -700.0,
    'applied=' . $sv2->appliedOffset(Axis::Vertical),
);

// ① 布局层：父无界 ⇒ 视口跟着内容走（不滚）
$sv3 = new RenderScrollView(9999.0, $scrollInk, true);
$sv3->setChild(new SolidBox(new Size(100.0, 1000.0), 100.0, 1000.0));
$sv3->layout(BoxConstraints::unbounded());
check(
    'Scroll · 父无界 ⇒ 视口跟着内容走（不滚：maxScroll = 0，偏移归 0）',
    $sv3->maxScroll(Axis::Vertical) === 0.0 && $sv3->appliedOffset(Axis::Vertical) === 0.0 && $scrollChildOf($sv3)?->offsetY() === 0.0,
    'maxScroll=' . $sv3->maxScroll(Axis::Vertical),
);

// ① 布局层：横向
$sv4 = new RenderScrollView(120.0, $scrollInk, false);
$sv4->setChild(new SolidBox(new Size(800.0, 50.0), 800.0, 50.0));
$sv4->layout(new BoxConstraints(0.0, 400.0, 0.0, 50.0));
check(
    'Scroll · 横向滚动：偏移进 offsetX（内容 800 宽，视口 400 ⇒ maxScroll = 400）',
    $sv4->maxScroll(Axis::Horizontal) === 400.0 && $scrollChildOf($sv4)?->offsetX() === -120.0,
    'maxScroll=' . $sv4->maxScroll(Axis::Horizontal),
);

// ① 布局层：滚动条（2026-10-17 补齐的第一个滚动缺口 —— 纯视觉层）
//   ★ 判据走像素：把盒画到白底光栅，数区域里的 **thumb 色**像素（SolidBox 画黑，
//     用品红不会混）。★ 反例自检三发：删 `maxScroll<=0` 早退 ⇒ "不溢出不画"红；
//     删 `*WHEEL_NOTCH_PX` ⇒ 步长两条红；thumb 位置公式写反 ⇒ "贴底/贴顶"红。
$scrollRaster = static function (?RenderBox $box, int $w, int $h): RasterCanvas {
    $list = new DisplayList();
    $box?->paint(new RecordingCanvas($list));
    $c = new RasterCanvas($w, $h, Color::white(), Scale::of(1.0));
    $list->replay($c);

    return $c;
};
$inkIn = static function (RasterCanvas $c, int $x0, int $y0, int $x1, int $y1) use ($scrollInk): int {
    $n = 0;
    for ($y = $y0; $y < $y1; $y++) {
        for ($x = $x0; $x < $x1; $x++) {
            if ($c->pixelAt($x, $y)->equals($scrollInk)) {
                $n++;
            }
        }
    }

    return $n;
};

// sv0：视口 100×300 / 内容 1000 / maxScroll 700 / offset 0
//   thumbLen = 300×300/1000 = 90；travel = 300−6−90 = 204；pos = 3+0 = 3 ⇒ y 3..93
$sv0Raster = $scrollRaster($sv0, 100, 300);
check(
    'Scroll · 滚动条：offset 0 ⇒ thumb 贴顶（y 3..93 有墨，y 0..2 的边距无墨）',
    $inkIn($sv0Raster, 92, 8, 95, 88) > 0 && $inkIn($sv0Raster, 91, 0, 97, 3) === 0,
    sprintf('body=%d margin=%d', $inkIn($sv0Raster, 92, 8, 95, 88), $inkIn($sv0Raster, 91, 0, 97, 3)),
);

// sv2：offset 9999 ⇒ 夹到 700 = maxScroll ⇒ thumb 贴底（pos = 3+204 = 207 ⇒ y 207..297）
$sv2Raster = $scrollRaster($sv2, 100, 300);
check(
    'Scroll · 滚动条：offset = maxScroll ⇒ thumb 贴底（y 290..296 有墨、顶端无墨）',
    $inkIn($sv2Raster, 92, 291, 95, 296) > 0 && $inkIn($sv2Raster, 91, 0, 97, 3) === 0,
    sprintf('bottom=%d margin=%d', $inkIn($sv2Raster, 92, 291, 95, 296), $inkIn($sv2Raster, 91, 0, 97, 3)),
);

// sv3：父无界 ⇒ maxScroll = 0 ⇒ **不画**（画一条满轨 thumb 是撒谎：暗示"可以滚"）
$sv3Raster = $scrollRaster($sv3, 100, 1000);
check(
    'Scroll · 滚动条：内容不溢出（maxScroll=0）⇒ 不画（整条右带无 thumb 色）',
    $inkIn($sv3Raster, 91, 0, 97, 1000) === 0,
    (string) $inkIn($sv3Raster, 91, 0, 97, 1000),
);

// sv1：offset 300（中段）⇒ thumb 不随内容平移（★ 本轮真 bug 的判据：
//   thumb 若画在 translate 之后 ⇒ 被带到 y −210..−120 ⇒ 整条飞出视口全红）
//   thumbPos = 3 + 300/700×204 = 90.4 ⇒ thumb y 90..181
$sv1Raster = $scrollRaster($sv1, 100, 300);
check(
    'Scroll · 滚动条：中段偏移 ⇒ thumb 不随内容平移（y 95..178 有墨、顶端边距无墨）',
    $inkIn($sv1Raster, 92, 95, 95, 178) > 0 && $inkIn($sv1Raster, 91, 0, 97, 3) === 0,
    sprintf('body=%d margin=%d', $inkIn($sv1Raster, 92, 95, 95, 178), $inkIn($sv1Raster, 91, 0, 97, 3)),
);

// sv4：横向 —— 轨道 400 / 内容 800 ⇒ thumbLen = 200（占一半，正确）
//   thumbPos = 3 + 120/400×194 = 61.2 ⇒ thumb x 61..262、y 41..47
$sv4Raster = $scrollRaster($sv4, 400, 50);
check(
    'Scroll · 滚动条：横向 ⇒ thumb 贴底侧（x 70..90 有墨、x 5..55 的 thumb 左缘外无墨）',
    $inkIn($sv4Raster, 70, 42, 90, 45) > 0 && $inkIn($sv4Raster, 5, 42, 55, 45) === 0,
    sprintf('body=%d left=%d', $inkIn($sv4Raster, 70, 42, 90, 45), $inkIn($sv4Raster, 5, 42, 55, 45)),
);


// ② 应用层：滚轮全链（命中 → 找视口 → scrollBy → 重建 → 盒偏移）
$scrollApp = new ScrollApplication();
$scrollApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));

// ── UI 线程身份（§17.4.2.1）：attach 记 id，帧内断言 —— WPF `VerifyAccess()` 的等价物
//   ★ 解释器下由 `tools/autoload.php` 的 `drift_thread_id()` 替身提供（固定 1）；
//   ★ AOT 产物里是 `GetCurrentThreadId()`（cpp-src 的桥）。
check(
    'App · UI 线程身份在 attach 时记下（!= 0；0 = 未挂载）',
    $scrollApp->uiThreadId() !== 0,
    (string) $scrollApp->uiThreadId(),
);
check(
    'App · 当前线程就是 UI 线程（uiThreadId == drift_thread_id()）',
    $scrollApp->uiThreadId() === \drift_thread_id(),
    $scrollApp->uiThreadId() . ' vs ' . \drift_thread_id(),
);
// 正路径：断言不失败（失败走 Fatal 序列会中止整个 smoke ⇒ 这行本身就是判据）
$scrollApp->assertUiThread('smoke');
check('App · assertUiThread 在 UI 线程上放行', true, 'ok');
$sb0 = $scrollApp->scrollBox();
$scrollMax = $sb0?->maxScroll(Axis::Vertical) ?? 0.0;
check(
    'Scroll · 应用：视口盒存在且内容超长（maxScroll > 100 ⇒ 真能滚）',
    $sb0 !== null && $scrollMax > 100.0,
    'maxScroll=' . $scrollMax,
);
check(
    'Scroll · 应用：未滚时子盒 offsetY = 0',
    $scrollChildOf($sb0)?->offsetY() === 0.0,
    (string) ($scrollChildOf($sb0)?->offsetY() ?? -1.0),
);

$scrollApp->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0));   // 一格
$scrollApp->frame(16.0, BoxConstraints::loose(new Size(300.0, 200.0)));
check(
    'Scroll · 应用：滚轮一格（delta=1.0）⇒ 48px（WHEEL_NOTCH_PX：不跟手的根因修复）',
    $scrollChildOf($scrollApp->scrollBox())?->offsetY() === -48.0,
    'offsetY=' . ($scrollChildOf($scrollApp->scrollBox())?->offsetY() ?? -1.0),
);

// ★ 格是相对量 ⇒ 再滚一格累加（96 = 2×48），证明换算的是"每格"而不是一次性
$scrollApp->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0));
$scrollApp->frame(16.5, BoxConstraints::loose(new Size(300.0, 200.0)));
check(
    'Scroll · 应用：第二格累加（offsetY = −96：每格 48 不是一次 48）',
    $scrollChildOf($scrollApp->scrollBox())?->offsetY() === -96.0,
    'offsetY=' . ($scrollChildOf($scrollApp->scrollBox())?->offsetY() ?? -1.0),
);

// ── 滚动条拖动（§12.3，2026-10-19）：thumb 是绘制物，命中必须先于内容 ──
//   判据两条：① 在 thumb 上按下并下拖 30px ⇒ 偏移按 maxScroll/thumbTravel 放大；
//   ② 在内容区同向拖 ⇒ 偏移**不变**（内容区拖动不是拖条）。
$barBox = $scrollApp->scrollBox();
$barRect = $barBox?->thumbRect(Axis::Vertical);
$barOffsetBefore = $barBox?->appliedOffset(Axis::Vertical) ?? -1.0;
$barCx = ($barRect?->left ?? 0.0) + ($barRect?->width ?? 0.0) / 2.0;
$barCy = ($barRect?->top ?? 0.0) + ($barRect?->height ?? 0.0) / 2.0;
$barTravel = $barBox?->thumbTravel(Axis::Vertical) ?? 0.0;
$barMax = $barBox?->maxScroll(Axis::Vertical) ?? 0.0;

$scrollApp->dispatchInput(PointerEvent::down($barCx, $barCy));
$scrollApp->dispatchInput(PointerEvent::move($barCx, $barCy + 30.0));
$scrollApp->dispatchInput(PointerEvent::up($barCx, $barCy + 30.0));

// ★ 必须跑一帧：`scrollBy()` 改的是 State 的 offset，`appliedOffset()` 读的是
//   渲染盒的（布局期才从 state 同步）—— 真机上由帧循环自动完成，头侧要手动补。
$scrollApp->frame(18.0, BoxConstraints::loose(new Size(300.0, 200.0)));

$barExpected = $barOffsetBefore + ($barTravel > 0.0 ? 30.0 * $barMax / $barTravel : 0.0);
$barNow = ($scrollApp->scrollBox())?->appliedOffset(Axis::Vertical) ?? -1.0;
check(
    'Scroll · 拖滚动条：thumb 下拖 30px ⇒ 偏移按 maxScroll/thumbTravel 放大',
    $barRect !== null && abs($barNow - $barExpected) < 0.51,
    sprintf(
        'rect=%s travel=%.1f max=%.1f before=%.1f now=%.1f expect=%.1f',
        $barRect === null ? 'null' : sprintf('[%.1f,%.1f,%.1f,%.1f]', $barRect->left, $barRect->top, $barRect->width, $barRect->height),
        $barTravel,
        $barMax,
        $barOffsetBefore,
        $barNow,
        $barExpected,
    )
);

$bodyOffsetBefore = $barNow;
$scrollApp->dispatchInput(PointerEvent::down(40.0, 40.0));
$scrollApp->dispatchInput(PointerEvent::move(40.0, 70.0));
$scrollApp->dispatchInput(PointerEvent::up(40.0, 70.0));
$scrollApp->frame(18.5, BoxConstraints::loose(new Size(300.0, 200.0)));
check(
    'Scroll · 内容区拖动不是拖条（偏移不变）',
    ($scrollApp->scrollBox())?->appliedOffset(Axis::Vertical) === $bodyOffsetBefore,
    'before=' . $bodyOffsetBefore . ' now=' . (($scrollApp->scrollBox())?->appliedOffset(Axis::Vertical) ?? -1.0)
);

// ★ scrollBy 是**相对**滚动（Δ 累加）⇒ 夹取测试用远超当前偏移的量：
//   从 60 起 +999999 ⇒ 夹在 maxScroll；再 -9999999 ⇒ 夹在 0。
$scrollApp->dispatchInput(ScrollEvent::make(150.0, 100.0, 999999.0));
$scrollApp->frame(17.0, BoxConstraints::loose(new Size(300.0, 200.0)));
check(
    'Scroll · 应用：滚出上界 ⇒ 夹在 maxScroll（内容不飞出视口）',
    $scrollChildOf($scrollApp->scrollBox())?->offsetY() === -$scrollMax,
    'offsetY=' . ($scrollChildOf($scrollApp->scrollBox())?->offsetY() ?? -1.0) . ' max=' . $scrollMax,
);

$scrollApp->dispatchInput(ScrollEvent::make(150.0, 100.0, -9999999.0));
$scrollApp->frame(18.0, BoxConstraints::loose(new Size(300.0, 200.0)));
check(
    'Scroll · 应用：滚出下界 ⇒ 夹在 0（scrollBy 的下界夹取生效）',
    $scrollChildOf($scrollApp->scrollBox())?->offsetY() === 0.0,
    'offsetY=' . ($scrollChildOf($scrollApp->scrollBox())?->offsetY() ?? -1.0),
);

$scrollApp->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0));
$scrollApp->frame(19.0, BoxConstraints::loose(new Size(300.0, 200.0)));
$beforeOutside = $scrollChildOf($scrollApp->scrollBox())?->offsetY();
$scrollApp->dispatchInput(ScrollEvent::make(150.0, 250.0, 1.0));   // y=250 在视口（200）外
$scrollApp->frame(20.0, BoxConstraints::loose(new Size(300.0, 200.0)));
check(
    'Scroll · 应用：轮落在视口外 ⇒ 命不中不滚（偏移不变）',
    $scrollChildOf($scrollApp->scrollBox())?->offsetY() === $beforeOutside,
    'before=' . ($beforeOutside ?? -1.0) . ' after=' . ($scrollChildOf($scrollApp->scrollBox())?->offsetY() ?? -1.0),
);

// ── 指针四相位（2026-10-17 move/up/leave 通路落地）─────────────────
//   ★ 三个消费者：① 悬停派发（§9.1 语义 #5 —— **目标变化才通知**）② 拖放
//   阈值状态机（§A.3.5 —— **按下 ≠ 拖动**，过 6 逻辑像素阈值才 begin）
//   ③ 光标刷新（move 与 down 同一条命中链）。
//   ★ 反例自检：把 dispatchPointer 的 move 分支去掉 hoverTo/advanceDrag
//   ⇒ 悬停两条 + 拖放两条 FAIL（诊断值精确显示"没派发/没开始"）。

// ① 相位枚举与桥映射（ABI 编号只该有一处读法 —— 与 CursorShape 同纪律）
check(
    'Pointer · PointerPhase 值与桥一致（down=0/move=1/up=2/leave=3）',
    PointerPhase::Down->value === 0 && PointerPhase::Move->value === 1
        && PointerPhase::Up->value === 2 && PointerPhase::Leave->value === 3,
);
check(
    'Pointer · fromBridge 四相位映射（未知 phase 兜 Down）',
    PointerEvent::fromBridge(1.0, 2.0, 2, 0)->isDown()
        && PointerEvent::fromBridge(1.0, 2.0, 0, 1)->isMove()
        && PointerEvent::fromBridge(1.0, 2.0, 0, 2)->isUp()
        && PointerEvent::fromBridge(1.0, 2.0, 0, 3)->isLeave()
        && PointerEvent::fromBridge(1.0, 2.0, 0, 9)->isDown(),
);

// ② 悬停：move 命中 ⇒ enter；同目标再 move ⇒ 不重复派发；移开 ⇒ leave
$ptrApp = new PointerApplication();
$ptrApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));

$shapeOnHover = $ptrApp->dispatchInput(PointerEvent::move(20.0, 20.0));
check(
    'Pointer · move 命中悬停目标 ⇒ enter 派发一次',
    $ptrApp->hoverEnters === 1 && $ptrApp->hoverLeaves === 0,
    'enter=' . $ptrApp->hoverEnters . ' leave=' . $ptrApp->hoverLeaves,
);
check(
    'Pointer · move 刷新光标（命中声明 PointingHand 的盒）',
    $shapeOnHover === CursorShape::PointingHand,
    (string) ($shapeOnHover?->name ?? 'null'),
);
$ptrApp->dispatchInput(PointerEvent::move(21.0, 22.0));   // 同目标内移动
check(
    'Pointer · 同目标再 move ⇒ 不重复派发（§9.1 语义 #5）',
    $ptrApp->hoverEnters === 1,
    'enter=' . $ptrApp->hoverEnters,
);
$ptrApp->dispatchInput(PointerEvent::move(20.0, 60.0));   // 移到无悬停声明的源盒
check(
    'Pointer · 移出悬停目标 ⇒ leave 派发一次',
    $ptrApp->hoverLeaves === 1 && $ptrApp->hoverEnters === 1,
    'enter=' . $ptrApp->hoverEnters . ' leave=' . $ptrApp->hoverLeaves,
);
$ptrApp->dispatchInput(PointerEvent::leave(20.0, 60.0));
check(
    'Pointer · leave（已在目标外）⇒ 不重复 leave',
    $ptrApp->hoverLeaves === 1,
    'leave=' . $ptrApp->hoverLeaves,
);

// ③ 拖放阈值：小位移不开始、大位移开始并落到目标上、up 交出 payload
$dragApp = new PointerApplication();
$dragApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));

$dragApp->dispatchInput(PointerEvent::down(20.0, 60.0));   // 落在拖放源上
$dragApp->dispatchInput(PointerEvent::move(22.0, 62.0));   // 位移 ~2.8 < 6 阈值
check(
    'Pointer · 拖放：小位移（< 阈值）⇒ 不开始拖动（按下 ≠ 拖动）',
    $dragApp->drag()->isDragging() === false,
);
$dragApp->dispatchInput(PointerEvent::move(20.0, 100.0));  // 位移 40 > 阈值 ⇒ 开始 + 落到目标
check(
    'Pointer · 拖放：大位移 ⇒ 开始拖动且目标 enter 派发',
    $dragApp->drag()->isDragging() === true && $dragApp->targetEnters === 1,
    'dragging=' . var_export($dragApp->drag()->isDragging(), true) . ' targetEnter=' . $dragApp->targetEnters,
);
$dragApp->dispatchInput(PointerEvent::up(20.0, 100.0));
check(
    'Pointer · 拖放：up ⇒ 放下，payload 原样交出',
    $dragApp->dropped === true && $dragApp->droppedPayload === 'payload-1',
    'dropped=' . var_export($dragApp->dropped, true) . ' payload=' . var_export($dragApp->droppedPayload, true),
);
check(
    'Pointer · 拖放：放下后状态清空（可再次起拖）',
    $dragApp->drag()->isDragging() === false,
);

// ④ 拖出窗口（leave）⇒ 取消，不卡死（无 SetCapture 的诚实边界）
$leaveApp = new PointerApplication();
$leaveApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));
$leaveApp->dispatchInput(PointerEvent::down(20.0, 60.0));
$leaveApp->dispatchInput(PointerEvent::move(20.0, 100.0));
check(
    'Pointer · 拖放：拖动中（leave 前置条件）',
    $leaveApp->drag()->isDragging() === true,
);
$leaveApp->dispatchInput(PointerEvent::leave(20.0, 100.0));
check(
    'Pointer · 拖放：指针离开客户区 ⇒ 取消（不卡死、不放下）',
    $leaveApp->drag()->isDragging() === false && $leaveApp->dropped === false && $leaveApp->targetLeaves === 1,
    'dragging=' . var_export($leaveApp->drag()->isDragging(), true)
        . ' dropped=' . var_export($leaveApp->dropped, true)
        . ' targetLeave=' . $leaveApp->targetLeaves,
);

// ⑤ 手势消歧（GestureArena，§9.1）：可拖 + 可点击控件的 tap/drag 裁决
$gestApp = new GestureApplication();
$gestApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));
$dragBtnEl = findElement($gestApp->root(), fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->draggableValue() !== null);
$plainBtnEl = findElement($gestApp->root(), fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->draggableValue() === null);
$dBox = $dragBtnEl?->effectiveBox();
[$dox, $doy] = $dBox === null ? [0.0, 0.0] : boxOrigin($dBox);
$dcx = $dox + ($dBox?->size()?->width ?? 0.0) / 2;
$dcy = $doy + ($dBox?->size()?->height ?? 0.0) / 2;

// a. 过阈值拖动 ⇒ Drag claim ⇒ Tap 让位 ⇒ **不误激活**命令（修真的缺陷）
$gestApp->dispatchInput(PointerEvent::down($dcx, $dcy));
$gestApp->dispatchInput(PointerEvent::move($dcx + 40.0, $dcy));   // 位移 40 > 6 阈值
check(
    'Gesture · 可拖控件：过阈值拖动 ⇒ 开始拖动且不误激活命令（拖动中 tap 让位）',
    $gestApp->drag()->isDragging() === true && $gestApp->dragPresses === 0,
    'dragging=' . var_export($gestApp->drag()->isDragging(), true) . ' dragPresses=' . $gestApp->dragPresses,
);
$gestApp->dispatchInput(PointerEvent::up($dcx + 40.0, $dcy));   // 放下（无 DragTarget ⇒ 退出拖动态）
check(
    'Gesture · 可拖控件：拖动后 up ⇒ 仍不激活（命令只在 tap 胜时）',
    $gestApp->dragPresses === 0,
    'dragPresses=' . $gestApp->dragPresses,
);

// b. 未过阈值的 up ⇒ Tap 胜 ⇒ 激活（tap 在可拖控件上仍可用）
$gestApp->dispatchInput(PointerEvent::down($dcx, $dcy));
$gestApp->dispatchInput(PointerEvent::move($dcx + 2.0, $dcy + 2.0));   // 位移 ~2.8 < 6
$gestApp->dispatchInput(PointerEvent::up($dcx + 2.0, $dcy + 2.0));
check(
    'Gesture · 可拖控件：未过阈值的 up ⇒ tap 胜 ⇒ 激活',
    $gestApp->dragPresses === 1,
    'dragPresses=' . $gestApp->dragPresses,
);

// c. 非可拖控件 ⇒ down 即激活（防回归，A15 不动）
$pBox = $plainBtnEl?->effectiveBox();
[$pox, $poy] = $pBox === null ? [0.0, 0.0] : boxOrigin($pBox);
$pcx = $pox + ($pBox?->size()?->width ?? 0.0) / 2;
$pcy = $poy + ($pBox?->size()?->height ?? 0.0) / 2;
$gestApp->dispatchInput(PointerEvent::down($pcx, $pcy));
check(
    'Gesture · 非可拖控件：down 即激活（防回归）',
    $gestApp->plainPresses === 1,
    'plainPresses=' . $gestApp->plainPresses,
);

// ⑥ 「按下归我」+「键盘归我」两条缝（W8-2，Slider 立的范式）
//
// 背景：`activateFocusedWidget()` 是 `instanceof` 塔（Menu/ToggleBase/Segment/
// Button），而且**收不到坐标、收不到按键** ⇒ 位置即语义的控件（Slider）做不了。
// 这两条缝让控件自己声明"按下归我"/"键盘归我"，框架只做路由。
$pl = new PressLabApplication();
$pl->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(320.0, 400.0)));

$plOwnEl = findElement(
    $pl->root(),
    static fn(Element $e): bool => $e->widget() instanceof Button && $e->widget()->pointerOwnsPressValue(),
);
$plWatchEl = findElement(
    $pl->root(),
    static fn(Element $e): bool => $e->widget() instanceof Button
        && $e->widget()->pointerHandlerValue() !== null
        && !$e->widget()->pointerOwnsPressValue(),
);
$plKeyEl = findElement(
    $pl->root(),
    static fn(Element $e): bool => $e->widget() instanceof Button && $e->widget()->keyHandlerValue() !== null,
);
$plChildEl = findElement(
    $pl->root(),
    static fn(Element $e): bool => $e->widget() instanceof Button && $e->widget()->pressedHandler() !== null
        && $e->widget()->pointerHandlerValue() === null
        && $e->widget()->keyHandlerValue() === null,
);

/** 盒中心（夹具一律现取，不赌字模高度） */
$plCenter = static function (?Element $e): array {
    $box = $e?->effectiveBox();
    if ($box === null || $box->size() === null) {
        return [0.0, 0.0];
    }
    [$ox, $oy] = boxOrigin($box);

    return [$ox + $box->size()->width / 2.0, $oy + $box->size()->height / 2.0];
};

[$plOx, $plOy] = $plCenter($plOwnEl);
[$plWx, $plWy] = $plCenter($plWatchEl);
[$plCx, $plCy] = $plCenter($plChildEl);

// a. 接管：down ⇒ 回调收到 down、指针被捕获、**命令不发**
$pl->dispatchInput(PointerEvent::down($plOx, $plOy));
check(
    'Press · ownsPress：down 交给控件（回调收到 down、指针被捕获）',
    $pl->ownLog === ['down'] && $pl->captureNode() === $plOwnEl,
    'log=' . implode(',', $pl->ownLog) . ' captured=' . ($pl->captureNode() === $plOwnEl ? 'yes' : 'no'),
);
check(
    'Press · ownsPress：按下**不**走 activateFocusedWidget（命令不发）',
    $pl->ownPresses === 0,
    'ownPresses=' . $pl->ownPresses,
);
check(
    'Press · ownsPress：焦点**仍落上去**（键盘可达的前提）',
    $pl->focusedElement() === $plOwnEl,
    'focused=' . ($pl->focusedElement() === $plOwnEl ? 'own' : 'other'),
);

// b. 接管期拖到控件外 ⇒ 仍归它（捕获的全部意义：不做命中测试）
$pl->dispatchInput(PointerEvent::move(5.0, 5.0));
check(
    'Press · ownsPress：拖到控件外仍归它（捕获期不命中测试）',
    $pl->ownLog === ['down', 'move'] && $pl->captureNode() === $plOwnEl,
    'log=' . implode(',', $pl->ownLog),
);

// c. up ⇒ 收口（捕获自动释放；不释放就是"事件永远发给死节点"）
$pl->dispatchInput(PointerEvent::up(5.0, 5.0));
check(
    'Press · ownsPress：up ⇒ 捕获释放且回调收到 up',
    $pl->ownLog === ['down', 'move', 'up'] && $pl->captureNode() === null,
    'log=' . implode(',', $pl->ownLog),
);

// d. 零回归：默认声明（ownsPress=false）⇒ 命令照发、回调一次都不跑
$pl->dispatchInput(PointerEvent::down($plWx, $plWy));
check(
    'Press · 默认 pointer()：down 仍走激活（命令照发）且回调不跑 —— 零回归',
    $pl->watchPresses === 1 && $pl->watchLog === [] && $pl->captureNode() === null,
    'presses=' . $pl->watchPresses . ' log=' . implode(',', $pl->watchLog),
);

// e. 让位：子按钮（Activatable）没声明，祖先声明了 ownsPress ⇒ **子按钮赢**
//   ★ 2026-10-26 改判（阶段 2 的 Table 行立的口子）：原先这里断言"祖先吞掉按下"。
//     行级 `ownsPress` 让"整行可点"成立，但它会顺手抢走 cell 里按钮的按下 ⇒
//     表格里放按钮点不动且不报错。判据改成与 `windowDragOwner()` 同构：
//     命中点**在**可聚焦 / `Activatable` 的控件上 ⇒ 这次按下归它（它本来就有
//     "按下 ⇒ 自己激活"的语义），祖先没有资格替它决定。
$pl->dispatchInput(PointerEvent::down($plCx, $plCy));
check(
    'Press · 祖先 ownsPress + 子按钮 ⇒ 子按钮赢（表格行里放按钮能点）',
    $pl->ancestorLog === [] && $pl->childPresses === 1,
    'log=' . implode(',', $pl->ancestorLog) . ' childPresses=' . $pl->childPresses,
);
$pl->dispatchInput(PointerEvent::up($plCx, $plCy));

// f. 键盘缝：焦点在声明了 keyboard() 的控件上 ⇒ 方向键归它，返回 true 吃掉
$pl->dispatchInput(PointerEvent::down($plKeyEl === null ? 0.0 : $plCenter($plKeyEl)[0], $plKeyEl === null ? 0.0 : $plCenter($plKeyEl)[1]));
$pl->dispatchInput(PointerEvent::up($plKeyEl === null ? 0.0 : $plCenter($plKeyEl)[0], $plKeyEl === null ? 0.0 : $plCenter($plKeyEl)[1]));
$pl->dispatchInput(KeyEvent::down(KeyChord::right()));
check(
    'Press · keyboard()：焦点控件收到方向键（框架不认识的键也能被控件吃掉）',
    $pl->keyLog === ['Right'],
    'keyLog=' . implode(',', $pl->keyLog),
);

// g. 键盘缝的让位：返回 false ⇒ 键继续往下走（Enter 落到激活 ⇒ 按钮真被按下）
// ★ 计数先归零：上面那次点击（down 即激活）已经给它 +1 了。
$pl->keyPresses = 0;
$pl->keyHandled = false;
$pl->dispatchInput(KeyEvent::down(KeyChord::enter()));
check(
    'Press · keyboard() 返回 false ⇒ 让位到下游（Enter 仍激活按钮）',
    $pl->keyLog === ['Right', 'Enter'] && $pl->keyPresses === 1,
    'keyLog=' . implode(',', $pl->keyLog) . ' keyPresses=' . $pl->keyPresses,
);

// h. 反例：没声明 keyboard() 的控件收到同一个键 ⇒ 回调不跑（不会被别的控件接走）
$pl->dispatchInput(PointerEvent::down($plOx, $plOy));
$pl->dispatchInput(PointerEvent::up($plOx, $plOy));
$pl->dispatchInput(KeyEvent::down(KeyChord::right()));
check(
    'Press · 反例：焦点在没声明 keyboard() 的控件上 ⇒ 该键无人接（不串台）',
    $pl->keyLog === ['Right', 'Enter'],
    'keyLog=' . implode(',', $pl->keyLog),
);

// i. ★★ 顶条当标题栏（P1-Stage 1b 无边框窗）
//
// 失败形态是**静默吞掉点击**（按钮点了没反应），不是崩溃 —— 所以判据必须两边
// 都钉住：该拖的（标题文字 / 顶条空白）真拖，不该拖的（按钮 / 自绘控件）真不拖。
// 只验"点空白能拖"无法发现按钮被吞。
$tb = new TitleBarLabApplication();
$tb->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(320.0, 400.0)));

$tbBarEl = findElement(
    $tb->root(),
    static fn(Element $e): bool => $e->widget()->windowDragHandlerValue() !== null,
);
$tbTitleEl = findElement(
    $tb->root(),
    static fn(Element $e): bool => $e->widget() instanceof Text && $e->widget()->content() === '用户管理',
);
$tbBtnEl = findElement(
    $tb->root(),
    static fn(Element $e): bool => $e->widget() instanceof Button,
);
$tbPaintEl = findElement(
    $tb->root(),
    static fn(Element $e): bool => $e->widget()->pointerOwnsPressValue(),
);

[$tbTx, $tbTy] = $plCenter($tbTitleEl);
[$tbBx, $tbBy] = $plCenter($tbBtnEl);
[$tbPx, $tbPy] = $plCenter($tbPaintEl);

// i-1. 点标题文字 ⇒ 拖窗（真实窗口的标题栏上按下去就是拖窗，文字不是例外）
$tb->dispatchInput(PointerEvent::down($tbTx, $tbTy));
check(
    'TitleBar · 点标题文字 ⇒ 开始拖窗（沿父链找到顶条的 windowDrag 声明）',
    $tb->dragStarts === 1,
    'dragStarts=' . $tb->dragStarts,
);
check(
    'TitleBar · 拖窗**不捕获指针**（拖窗的鼠标循环归平台，框架再捕获会锁死后续事件）',
    $tb->captureNode() === null,
    'captured=' . ($tb->captureNode() === null ? 'no' : 'yes'),
);
check(
    'TitleBar · 拖窗**不改焦点**（抓标题栏不是"选中"）',
    $tb->focusedElement() === null,
    'focused=' . ($tb->focusedElement() === null ? 'none' : 'other'),
);

// i-2. 拖动期间后续 move / up 不该再触发一次（否则每帧重开一次模态移动循环）
$tb->dispatchInput(PointerEvent::move($tbTx + 30.0, $tbTy + 12.0));
$tb->dispatchInput(PointerEvent::up($tbTx + 30.0, $tbTy + 12.0));
check(
    'TitleBar · 一次按下只拖一次（move / up 不重复触发）',
    $tb->dragStarts === 1,
    'dragStarts=' . $tb->dragStarts,
);

// i-3. 点顶条里的按钮 ⇒ **不拖窗**，命令照发（Activatable 让位）
$tb->dispatchInput(PointerEvent::down($tbBx, $tbBy));
check(
    'TitleBar · 点顶条按钮 ⇒ 不拖窗且命令照发（可激活控件让位）',
    $tb->dragStarts === 1 && $tb->actionPresses === 1,
    'dragStarts=' . $tb->dragStarts . ' actionPresses=' . $tb->actionPresses,
);
$tb->dispatchInput(PointerEvent::up($tbBx, $tbBy));

// i-4. 点顶条里的自绘控件（`pointer()` 声明者）⇒ **不拖窗**（第三让位判据）
$tb->dispatchInput(PointerEvent::down($tbPx, $tbPy));
check(
    'TitleBar · 点顶条里的 pointer() 声明者 ⇒ 不拖窗（自绘控件让位）',
    $tb->dragStarts === 1 && $tb->paintedLog === ['down'],
    'dragStarts=' . $tb->dragStarts . ' paintedLog=' . implode(',', $tb->paintedLog),
);
$tb->dispatchInput(PointerEvent::up($tbPx, $tbPy));

// i-5. 反例：点在顶条**外面**（内容区）⇒ 不拖窗
//      ★ 没有这一条，`windowDragOwner()` 写成"沿父链一路找"也能过前四条
//        （内容区往上就是根，找不到声明者 —— 但实现若写成"无条件返回根"就露馅）。
$tbOutEl = findElement(
    $tb->root(),
    static fn(Element $e): bool => $e->widget() instanceof Text && $e->widget()->content() === '内容',
);
[$tbOx, $tbOy] = $plCenter($tbOutEl);
$tb->dispatchInput(PointerEvent::down($tbOx, $tbOy));
check(
    'TitleBar · 反例：点在顶条外 ⇒ 不拖窗（声明只在顶条子树内生效）',
    $tb->dragStarts === 1,
    'dragStarts=' . $tb->dragStarts,
);
$tb->dispatchInput(PointerEvent::up($tbOx, $tbOy));

// h2. ★★ `keyboard()` 沿**父链**找最近的声明者（2026-10-24 修）
//     此前只读焦点控件自己的 widget ⇒ 包裹式控件的声明是**死的**，而
//     `Widget::keyboard()` 的 docblock 一直写着"任何控件都能挂"（文档承诺了
//     代码没做到的事）。`ComboBox` 包 `TextField` 时焦点落在 `TextField` 上，
//     外层声明必须收得到键 —— 否则浮层驱动不了。
//     判据用 **Backspace + `TextFieldState::text()`**：外层吃掉 ⇒ 文本不变；
//     让位 ⇒ 文本真被删一个字符。这是"吃没吃到"的可观测差别，不是恒真断言。
$kaApp = new KeyAncestorApplication();
$kaC = BoxConstraints::loose(new Size(300.0, 200.0));
$kaApp->attach(new SurfaceProbe(), $kaC);
$kaApp->frame(16.0, $kaC);
$kaField = $kaApp->fieldElement();
$kaBox = $kaField?->effectiveBox();
[$kaX, $kaY] = $kaBox === null || $kaBox->size() === null
    ? [0.0, 0.0]
    : [RenderBox::absoluteOrigin($kaBox)[0] + $kaBox->size()->width / 2.0,
        RenderBox::absoluteOrigin($kaBox)[1] + $kaBox->size()->height / 2.0];
// 点一下 ⇒ 焦点落到 TextField 上（命中测试 ⇒ 焦点）
$kaApp->dispatchInput(PointerEvent::down($kaX, $kaY));
$kaApp->dispatchInput(PointerEvent::up($kaX, $kaY));
// 方向键：`TextFieldState` 不认它 ⇒ 只有外层能收到。这一条单独证明"父链走得通"
$kaApp->dispatchInput(KeyEvent::down(KeyChord::right()));
check(
    'Press · keyboard() 沿父链：焦点在 TextField 上，外层声明的 handler 也收到键',
    $kaApp->ancestorLog === ['Right'],
    'ancestorLog=' . implode(',', $kaApp->ancestorLog),
);
// 外层吃掉 Backspace ⇒ 键不到 `TextFieldState` ⇒ 文本不动
$kaApp->ancestorEats = true;
$kaApp->ancestorLog = [];
$kaApp->dispatchInput(KeyEvent::down(KeyChord::backspace()));
check(
    'Press · keyboard() 沿父链：外层吃掉 Backspace ⇒ 焦点控件的文本不被改',
    $kaApp->ancestorLog === ['Backspace'] && $kaApp->fieldState()?->text() === 'ab',
    'ancestorLog=' . implode(',', $kaApp->ancestorLog) . ' text=' . ($kaApp->fieldState()?->text() ?? '-'),
);
// 外层让位 ⇒ 键继续往下走到 `TextFieldState::handleKey()` ⇒ 文本真被删一个字符
//   （这一条同时证明"`keyboard()` 仍排在 `TextFieldState` 之前"的顺序没被改坏）
//   ★ 期望值从 `caretOffset()` **现推**，不猜插入符在哪：上面的点击落在输入框中部，
//     插入符就在 'a'/'b' 之间 ⇒ 删的是 'a'（结果 'b'）。写死 'a' 会把正确行为判成 bug。
$kaCaret = $kaApp->fieldState()->caretOffset();
$kaBefore = $kaApp->fieldState()->text();
$kaApp->ancestorEats = false;
$kaApp->ancestorLog = [];
$kaApp->dispatchInput(KeyEvent::down(KeyChord::backspace()));
$kaExpected = substr($kaBefore, 0, $kaCaret - 1) . substr($kaBefore, $kaCaret);
check(
    'Press · keyboard() 沿父链：外层让位 ⇒ 键落到 TextFieldState（删掉插入符前一个字符）',
    $kaApp->ancestorLog === ['Backspace'] && $kaApp->fieldState()?->text() === $kaExpected,
    sprintf(
        'ancestorLog=%s caret=%d %s ⇒ %s（期望 %s）',
        implode(',', $kaApp->ancestorLog),
        $kaCaret,
        $kaBefore,
        $kaApp->fieldState()?->text() ?? '-',
        $kaExpected,
    ),
);

// ⑦ UIA 语义推送（§9 / M5）：帧末把语义树经 sink 交给平台（头侧；真机 provider 归 AOT）
$semApp = new GestureApplication();
$semNodes = [];
$semApp->setSemanticsSink(function (array $nodes) use (&$semNodes): void {
    $semNodes = $nodes;
});
$semApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));
check(
    'Semantics · attach 首帧 ⇒ sink 收到语义树（含 Button 角色）',
    count($semNodes) >= 1 && ($semNodes[0]['role'] ?? '') === 'button',
    'nodes=' . count($semNodes),
);
check(
    'Semantics · 两个 Button 各贡献一个节点，name = label',
    count($semNodes) === 2 && ($semNodes[0]['name'] ?? '') !== '' && ($semNodes[1]['name'] ?? '') !== '',
    'count=' . count($semNodes),
);

// ── 平台后端解析（§7.2 裁决 4：2026-10-17 默认从 soft 翻成 skia）──
// ★ 这两条都钉**静默失败**：默认没翻（界面继续锯齿）与显式 'soft' 被默认值吞掉
//   （"确定性后端没生效"）都不会报错，只能靠夹具。反例自检：把 resolveBackend
//   换回"只认 'skia'、其余退回默认" ⇒ 第 2 条 FAIL，另两条照过。
check(
    'Platform · 默认后端 = skia（2026-10-17 翻转）',
    Win32SurfaceProvider::resolveBackend(null) === 'skia',
    '实际 ' . Win32SurfaceProvider::resolveBackend(null),
);
check(
    'Platform · 显式 soft 不被默认值吞掉',
    Win32SurfaceProvider::resolveBackend('soft') === 'soft',
    '实际 ' . Win32SurfaceProvider::resolveBackend('soft'),
);
check(
    'Platform · 未知值退回默认（不报错也不选错）',
    Win32SurfaceProvider::resolveBackend('vulkan') === 'skia',
    '实际 ' . Win32SurfaceProvider::resolveBackend('vulkan'),
);

fresh($log);
if (is_file($log)) {
    unlink($log);
}

// ── ㊷ M5：`LazyColumn` 虚拟列表（按视口造子层）──────────────────────────────
//   虚拟化的定义是"**没看见的行不存在**"：没有 Widget、没有 Element、没有盒。
//   三线判据 ——
//   ① **窗口只覆盖视口**：5000 / 20000 行都只造 5 行（视口 240 ÷ 行高 56）。
//      全量列表随行数**线性**变慢（`_build/bench-list3.php` 实测：20000 行
//      mount+layout 2.8s），虚拟列表与行数无关。
//   ② **坐标链**：窗在完整内容里的起点（`sliceOrigin`）进子盒 offset ⇒
//      "画在哪 = 点在哪"（与 padding / 滚动偏移同一条纪律）。
//   ③ **绘制透明**：同一偏移下 SVG 与全量列表**逐字节一致** —— 虚拟化不许改观感。
//   ★ 反例自检三发：① 窗口改成"全部行" ⇒ 前两条红（行数变 5000）；
//   ② `sliceOrigin` 恒置 0 ⇒ "滚一格 ⇒ 子盒 offsetY = −8" 红（会得 −48）；
//   ③ `maxScroll` 按窗长算 ⇒ 可滚上限红（280 而不是 1119760）。
$lazyRows = static function (int $count): array {
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = $i;
    }

    return $rows;
};

$lazyBuild = static function (int $count, float $extent, ?EdgeInsets $pad = null) use ($lazyRows): LazyColumn {
    $widget = LazyColumn::make($extent)->items(
        $lazyRows($count),
        static fn(int $i): Widget => Text::make('行 ' . $i),
    );

    return $pad === null ? $widget : $widget->padding($pad);
};

/** 挂树 + 按 320×240 布局（smokeContext 的规格） */
$lazyMount = static function (LazyList $widget): Element {
    $element = $widget->createElement();
    $element->mount(null, smokeContext());
    $element->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));

    return $element;
};

/** 窗口里实际造了几行（= Column 的直接子节点数） */
$lazyKids = static function (Element $root): int {
    $column = findElement($root, static fn(Element $e): bool => $e->widget() instanceof Column);
    $n = 0;
    $column?->visitChildren(static function (Element $child) use (&$n): void {
        $n++;
    });

    return $n;
};

$lazyStateOf = static function (Element $root): ?LazyListState {
    $hit = findElement(
        $root,
        static fn(Element $e): bool => $e instanceof StatefulElement && $e->state() instanceof LazyListState,
    );

    $state = $hit instanceof StatefulElement ? $hit->state() : null;

    return $state instanceof LazyListState ? $state : null;
};

$lazyColumnBoxOf = static function (Element $root): ?RenderBox {
    return findElement($root, static fn(Element $e): bool => $e->widget() instanceof Column)?->renderObject();
};

// ① 窗口只覆盖视口：视口 240 / 行高 56 ⇒ 5 行（ceil(240/56) = 5）
$lazySmall = $lazyMount($lazyBuild(5000, 56.0));
check(
    'Lazy · 窗口只造视口覆盖的行（5000 行 ⇒ 5 行，不是 5000）',
    $lazyKids($lazySmall) === 5,
    (string) $lazyKids($lazySmall),
);

// ② 与行数无关：20000 行仍然 5 行（这是虚拟化与全量列表的分界判据）
$lazyBig = $lazyMount($lazyBuild(20000, 56.0));
check(
    'Lazy · 窗口行数与总行数无关（20000 行 ⇒ 仍 5 行）',
    $lazyKids($lazyBig) === 5,
    (string) $lazyKids($lazyBig),
);

// ③ 可滚上限按**完整内容**算：20000×56 − 240 = 1119760
//    （按窗长算会得到 280 —— 滚动条会假装"只有一屏"）
$lazyMax = $lazyBig->effectiveBox()?->maxScroll(Axis::Vertical) ?? -1.0;
check(
    'Lazy · maxScroll = 完整内容 − 视口（20000×56−240 = 1119760，不是窗长）',
    $lazyMax === 1119760.0,
    (string) $lazyMax,
);

// ④ 滚到 400：第 7 行是窗的第一行（400 ÷ 56 = 7.14）⇒ 窗起点 392
//    ⇒ 子盒 offsetY = −(400 − 392) = −8（★ sliceOrigin 的正负号判据）
$lazyState = $lazyStateOf($lazyBig);
$lazyState?->scrollBy(400.0);
$lazyBig->settle();
$lazyBig->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));
$lazyCol = $lazyColumnBoxOf($lazyBig);
check(
    'Lazy · 滚动 400 ⇒ 窗起点 392、子盒 offsetY = −8（窗在完整内容里的位置进坐标链）',
    $lazyCol?->offsetY() === -8.0 && $lazyState?->offset() === 400.0,
    'offsetY=' . ($lazyCol?->offsetY() ?? -1.0) . ' offset=' . ($lazyState?->offset() ?? -1.0),
);

// ⑤ 命中跟随窗：视口 y=10 落在第 7 行（−8..48）⇒ 命中盒的绝对 y 必须也是 −8
$lazyHit = $lazyBig->effectiveBox()?->hitTest(100.0, 10.0);
$lazyHitY = $lazyHit === null ? null : boxOrigin($lazyHit)[1];
check(
    'Lazy · 命中跟随窗：视口 (100,10) 命中第 7 行，绝对 y = −8（画在哪 = 点在哪）',
    $lazyHitY === -8.0,
    $lazyHitY === null ? 'miss' : (string) $lazyHitY,
);

// ⑥ 上内边距进 sliceOrigin：offset 0 / pad.top 24 ⇒ 第一行画在 y = 24
$lazyPad = $lazyMount($lazyBuild(100, 56.0, EdgeInsets::only(0.0, 24.0, 0.0, 0.0)));
$lazyPadCol = $lazyColumnBoxOf($lazyPad);
check(
    'Lazy · 上内边距是内容的一部分（pad.top 24 ⇒ 子盒下移 +24）',
    $lazyPadCol?->offsetY() === 24.0,
    (string) ($lazyPadCol?->offsetY() ?? -1.0),
);

// ⑦ 绘制透明：同一偏移下 SVG 与全量列表逐字节一致
$lazySvgOf = static function (float $offset, bool $virtual): string {
    $rows = [];
    for ($i = 0; $i < 300; $i++) {
        $rows[] = $i;
    }
    $rowOf = static fn(int $i): Widget => SizedBox::make(Text::make('行 ' . $i))->height(56.0);

    $root = $virtual
        ? LazyColumn::make(56.0)->items($rows, $rowOf)
        : ScrollView::make(
            Column::make()->children([Each::make(static fn(int $i): int => $i)->items($rows, $rowOf)])
        );

    return (new Renderer(new SvgSurface(), smokeContext()->metrics(), ThemeData::light()))->render(
        $root,
        320.0,
        240.0,
        1.0,
        static function (Element $el) use ($offset, $virtual): void {
            if ($virtual) {
                $st = findElement(
                    $el,
                    static fn(Element $e): bool => $e instanceof StatefulElement && $e->state() instanceof LazyListState,
                )?->state();
            } else {
                $st = findElement(
                    $el,
                    static fn(Element $e): bool => $e instanceof StatefulElement && $e->state() instanceof ScrollViewState,
                )?->state();
            }
            if ($st instanceof Scrollable) {
                $st->scrollBy($offset);
            }
        },
    );
};
// ★ 先跑一发预热：`DeterministicFontManager` 的字形表是**懒加载**的，第一次
//   排版会顺带把它建起来（建表前后的度量能差几像素）⇒ 不预热的话"第一份 SVG
//   与第二份不同"会伪装成虚拟化的缺陷。预热后两份都走同一份已建好的度量。
$lazySvgOf(0.0, true);
$lazyPairs = [];
foreach ([0.0, 400.0] as $off) {
    $lazyPairs[(string) $off] = [$lazySvgOf($off, false), $lazySvgOf($off, true)];
}
$lazySame = true;
$lazySizes = [];
foreach ($lazyPairs as $off => [$eachSvg, $virtualSvg]) {
    $lazySame = $lazySame && $eachSvg === $virtualSvg;
    $lazySizes[] = sprintf('%s: %d/%d', $off, strlen($eachSvg), strlen($virtualSvg));
}
check(
    'Lazy · 绘制透明：offset 0 与 400 下与全量列表的 SVG 逐字节一致',
    $lazySame,
    implode(' · ', $lazySizes),
);

// ⑧ 应用层：滚轮经 `Scrollable` 路由到 `LazyListState`（接口的第一个真实消费者）
$lazyApp = new LazyApplication();
$lazyApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));
$lazyApp->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0));   // 一格 = 48px
$lazyApp->frame(16.0, BoxConstraints::loose(new Size(300.0, 200.0)));
$lazyAppState = $lazyApp->lazyState();
$lazyAppBox = $lazyApp->scrollBox();
check(
    'Lazy · 滚轮一格 ⇒ offset 48（`Scrollable` 接口路由到 LazyListState）',
    $lazyAppState?->offset() === 48.0,
    (string) ($lazyAppState?->offset() ?? -1.0),
);
// 行高 20 ⇒ 窗起点 floor(48/20)×20 = 40 ⇒ 子盒 offsetY = −8
check(
    'Lazy · 滚轮后窗滑两行（起点 40 ⇒ 子盒 offsetY = −8）',
    $lazyAppBox?->maxScroll(Axis::Vertical) === 9800.0
        && findElement(
            $lazyApp->root(),
            static fn(Element $e): bool => $e->widget() instanceof Column,
        )?->renderObject()?->offsetY() === -8.0,
    'maxScroll=' . ($lazyAppBox?->maxScroll(Axis::Vertical) ?? -1.0),
);

// ⑨–⑬ 横向（`LazyRow`）：轴是**一个参数**，不是一份拷贝。
//   ★ 判据选"确定会变"的量：视口 320 **宽** / 格 56 ⇒ 6 列（ceil(320/56)）；
//     纵向同规格是 5 行（240/56）—— 两个数字不同本身就是"取的是 width 不是
//     height"的证据（若误用纵向公式，这里会得 5）。
$lazyRowKids = static function (Element $root): int {
    $row = findElement($root, static fn(Element $e): bool => $e->widget() instanceof Row);
    $n = 0;
    $row?->visitChildren(static function (Element $child) use (&$n): void {
        $n++;
    });

    return $n;
};

$lazyRowBuild = static function (int $count, float $extent, ?EdgeInsets $pad = null) use ($lazyRows): LazyRow {
    $widget = LazyRow::make($extent)->items(
        $lazyRows($count),
        static fn(int $i): Widget => Text::make('格 ' . $i),
    );

    return $pad === null ? $widget : $widget->padding($pad);
};

$lazyRowBoxOf = static function (Element $root): ?RenderBox {
    return findElement($root, static fn(Element $e): bool => $e->widget() instanceof Row)?->renderObject();
};

$lazyRowBig = $lazyMount($lazyRowBuild(20000, 56.0));
check(
    'LazyRow · 窗口只造视口覆盖的列（20000 项 ⇒ 6 列，按 width 算不是 height）',
    $lazyRowKids($lazyRowBig) === 6,
    (string) $lazyRowKids($lazyRowBig),
);

$lazyRowMax = $lazyRowBig->effectiveBox()?->maxScroll(Axis::Horizontal) ?? -1.0;
check(
    'LazyRow · maxScroll 按完整内容宽算（20000×56−320 = 1119680）',
    $lazyRowMax === 1119680.0,
    (string) $lazyRowMax,
);

$lazyRowState = $lazyStateOf($lazyRowBig);
$lazyRowState?->scrollBy(400.0);
$lazyRowBig->settle();
$lazyRowBig->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));
$lazyRowCell = $lazyRowBoxOf($lazyRowBig);
check(
    'LazyRow · 滚动 400 ⇒ 窗起点 392、子盒 offsetX = −8 且 offsetY = 0（横向不许动 y）',
    $lazyRowCell?->offsetX() === -8.0 && $lazyRowCell?->offsetY() === 0.0,
    'offsetX=' . ($lazyRowCell?->offsetX() ?? -1.0) . ' offsetY=' . ($lazyRowCell?->offsetY() ?? -1.0),
);

$lazyRowHit = $lazyRowBig->effectiveBox()?->hitTest(10.0, 100.0);
$lazyRowHitX = $lazyRowHit === null ? null : boxOrigin($lazyRowHit)[0];
check(
    'LazyRow · 命中跟随窗：视口 (10,100) 命中第 7 列，绝对 x = −8（画在哪 = 点在哪）',
    $lazyRowHitX === -8.0,
    $lazyRowHitX === null ? 'miss' : (string) $lazyRowHitX,
);

// 横向的"主轴起始"是**左**（不是上）：pad.left 进 sliceOrigin
$lazyRowPad = $lazyMount($lazyRowBuild(100, 56.0, EdgeInsets::only(24.0, 0.0, 0.0, 0.0)));
check(
    'LazyRow · 左内边距是内容的一部分（pad.left 24 ⇒ 子盒右移 +24）',
    $lazyRowBoxOf($lazyRowPad)?->offsetX() === 24.0,
    (string) ($lazyRowBoxOf($lazyRowPad)?->offsetX() ?? -1.0),
);

// 应用层：滚轮经路由落到 `LazyRow` 的 State。
//   ★ 竖轮是**默认手势**：横向容器只有一根轴 ⇒ 回落到那根（阶段 5b 明确保留，
//     与 Windows 横向 ListView 同款）。不回落的话 `LazyRow` 的滚动就只剩
//     "代码看起来对"（F8 那类"缺的是驱动方"的同族）。
$lazyRowApp = new LazyApplication(false);
$lazyRowApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));
$lazyRowApp->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0));
$lazyRowApp->frame(16.0, BoxConstraints::loose(new Size(300.0, 200.0)));
check(
    'LazyRow · 竖轮一格 ⇒ offset 48（竖向回落到容器唯一那根轴，不由调用方猜）',
    $lazyRowApp->lazyState()?->offset() === 48.0,
    (string) ($lazyRowApp->lazyState()?->offset() ?? -1.0),
);

// ⑭ 格宽真的是 extent。★ 这条补的是"窗口算得对、排出来却是竖的"那种半对：
//   若 `sizedCell()` 横向也写成 `->height($extent)`，上面五条**全都还是绿的**
//   （窗口只看 extent 这个数，不看子盒实际尺寸）⇒ 必须有一条量几何。
$lazyRowCellW = findElement(
    $lazyRowBig,
    static fn(Element $e): bool => $e->widget() instanceof SizedBox,
)?->renderObject()?->size()?->width;
check(
    'LazyRow · 每格宽 = itemExtent 56（定的是 width，不是 height）',
    $lazyRowCellW === 56.0,
    (string) ($lazyRowCellW ?? -1.0),
);

// ════════════════════════════════════════ ensureVisible（W6 L4-6，`04` §6.3）══
//
// 守的是**键盘可达性**：Tab 把焦点移出视口时，焦点环会画在视口外 ⇒ 用户看不到
// 焦点在哪。算术只有一处（`RenderScrollView::revealDelta()`），两个 `Scrollable`
// 实现都只做"找自己的盒 → 转交"。
//
// ★ 每条都写了它抓的反例 —— 没有反例的断言是空转。

// ① 盒级算术（不经过 State / 应用）：目标在视口上方 ⇒ 负增量
//    反例：忘了减 `margin` ⇒ −300；把视口当成窗口高 240 ⇒ 差 40。
$revApp = new RevealApplication();
$revApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 200.0)));
$revState = $revApp->scrollState();
$revState?->scrollBy(400.0);
$revApp->frame(16.0, BoxConstraints::tight(new Size(320.0, 200.0)));
// ★ 盒在重建时**换新**（Widget 不可变 ⇒ 每次 build 造一个新盒）⇒ 必须在
//   frame() 之后重新取。拿首帧那个盒（`scrollOffset` 恒 0）算出来永远是 0 ——
//   这条是首跑红掉的：`revealDelta()` 得 0，看着像公式错、其实是基准旧。
$revBox = $revApp->scrollBox();
$revUp = $revBox?->revealDelta(new Rect(0.0, 100.0, 100.0, 20.0), 8.0, $revBox->appliedOffset(Axis::Vertical)) ?? -999.0;
check(
    'ensureVisible · revealDelta：目标在视口上方 ⇒ −308（100 − 8 − 400）',
    $revUp === -308.0,
    (string) $revUp,
);

// ② State 级：真滚过去（`04` §12.2 #10）
//    反例：只改盒不算 State ⇒ offset 仍是 400；夹上界夹错 ⇒ 停在 600。
$revState?->ensureVisible(new Rect(0.0, 100.0, 100.0, 20.0));
check(
    'ensureVisible · 上滚到 top − 8（400 → 92：增量 −308 加到 400 上）',
    $revState?->offset() === 92.0,
    (string) ($revState?->offset() ?? -1.0),
);

// ③ 已在视口内 ⇒ 不动、**不标脏**（`04` §12.2 #11）
//    反例：无条件 `scrollBy(0)` 会走 `setState` ⇒ 每帧重建一次（白烧）。
$revApp->frame(32.0, BoxConstraints::tight(new Size(320.0, 200.0)));
$revState?->ensureVisible(new Rect(0.0, 120.0, 100.0, 20.0));
check(
    'ensureVisible · 已在视口内 ⇒ 偏移不变且不标脏（省一次重建）',
    $revState?->offset() === 92.0 && $revState?->needsBuild() === false,
    'offset=' . ($revState?->offset() ?? -1.0)
        . ' dirty=' . ($revState?->needsBuild() === true ? '1' : '0'),
);

// ④ 下滚：`bottom + 8 − 视口`
//    反例：把目标起点当终点 ⇒ 只滚 12（108 → 120）而不是 420。
$revState?->ensureVisible(new Rect(0.0, 700.0, 100.0, 20.0));
check(
    'ensureVisible · 下滚到 bottom + 8 − 视口（92 → 528，增量 436）',
    $revState?->offset() === 528.0,
    (string) ($revState?->offset() ?? -1.0),
);

// ⑤ 目标比视口还高（300 > 200）⇒ 上沿对齐，且**幂等**
//    反例：不加这条分支会先滚到底、再滚回顶 —— 连着调两次在两端来回跳。
$revState?->scrollBy(-528.0);   // 回到 0
$revState?->ensureVisible(new Rect(0.0, 100.0, 100.0, 300.0));
$revState?->ensureVisible(new Rect(0.0, 100.0, 100.0, 300.0));
check(
    'ensureVisible · 目标比视口高 ⇒ 上沿对齐 92 且幂等（第二次不动）',
    $revState?->offset() === 92.0,
    (string) ($revState?->offset() ?? -1.0),
);

// ⑥ 横向视口取 x/width
//    反例：只写纵向公式 ⇒ 横向滚动容器里 Tab 永远不跟（静默不生效）。
$hKids = [];
for ($i = 0; $i < 20; $i++) {
    $hKids[] = SizedBox::make()->width(40.0);
}
$hRoot = ScrollView::make(Row::make()->children($hKids), false)->createElement();
$hRoot->mount(null, smokeContext());
$hRoot->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 100.0)));
$hState = findElement(
    $hRoot,
    static fn(Element $e): bool => $e instanceof StatefulElement && $e->state() instanceof ScrollViewState,
)?->state();
$hState?->scrollBy(400.0);
// ★ 用**下滚**验轴向：上滚的公式（`top − margin − offset`）里没有视口，
//   横向纵向算出同一个值 ⇒ 验不出"取的是 width 还是 height"。
//   下滚：`728 − 400 − 320 = 8` ⇒ 408；若错取高 100 ⇒ 628（两个值分得开）。
$hState?->ensureVisible(new Rect(700.0, 0.0, 20.0, 10.0));
check(
    'ensureVisible · 横向视口用 x/width（下滚 400 → 408；按高算会得 628）',
    $hState instanceof ScrollViewState && $hState->offset() === 408.0,
    $hState instanceof ScrollViewState ? (string) $hState->offset() : 'no-state',
);

// ⑦ LazyColumn 是**同一形状**的实现（内容坐标 = 完整内容坐标）
//    反例：把 `sliceOrigin` 再补一遍 ⇒ 488 变成 448（差两行）。
$lazyRev = $lazyMount($lazyBuild(500, 40.0));
$lazyRevState = $lazyStateOf($lazyRev);
$lazyRevState?->ensureVisible(new Rect(0.0, 700.0, 100.0, 20.0));
check(
    'ensureVisible · LazyColumn 同一条算术（488 = 720 + 8 − 240）',
    $lazyRevState?->offset() === 488.0,
    (string) ($lazyRevState?->offset() ?? -1.0),
);

// ⑧ 真消费：Tab 换焦点 ⇒ 自动滚进视口（6 次 Tab 落到 b5，y 200..240）
//    反例：`revealFocused()` 没接上 ⇒ offset 恒 0（Tab 走得到、看不见）。
$navApp = new RevealApplication();
$navApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 200.0)));
$navTs = 0.0;
for ($i = 0; $i < 6; $i++) {
    $navTs += 16.0;
    $navApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
    $navApp->frame($navTs, BoxConstraints::tight(new Size(320.0, 200.0)));
}
$navState = $navApp->scrollState();
check(
    'ensureVisible · Tab 到视口外的按钮 ⇒ 自动滚进来（6 次 ⇒ 焦点 b5、offset 48）',
    $navApp->focusedLabel() === 'b5' && $navState?->offset() === 48.0,
    'focus=' . ($navApp->focusedLabel() ?? 'null') . ' offset=' . ($navState?->offset() ?? -1.0),
);

// ⑨ 反向同样跟：Shift+Tab 回退到 b0 ⇒ offset 回 0
//    反例：只在 `focusNext()` 侧接线 ⇒ 反向导航时焦点跑到视口上方。
for ($i = 0; $i < 5; $i++) {
    $navTs += 16.0;
    $navApp->dispatchInput(KeyEvent::down(KeyChord::shift('Tab')));
    $navApp->frame($navTs, BoxConstraints::tight(new Size(320.0, 200.0)));
}
check(
    'ensureVisible · Shift+Tab 反向同样跟（回到 b0 ⇒ offset 回 0）',
    $navApp->focusedLabel() === 'b0' && $navState?->offset() === 0.0,
    'focus=' . ($navApp->focusedLabel() ?? 'null') . ' offset=' . ($navState?->offset() ?? -1.0),
);

// ═══════════════════════════════════════ 虚拟列表锚点（W6 L4-7，`04` §7.3）══
//
// 问题：搜索把 10000 行筛成 30 行时，滚动位置怎么办？
// 按**行号**保持会跳到列表末尾（锚点那一行已经不存在了）⇒ 必须按**身份 key** 保持。
//
// ★ 每条都写了它抓的反例。

$anchorItems = static function (int $from, int $to): array {
    $out = [];
    for ($i = $from; $i < $to; $i++) {
        $out[] = $i;
    }

    return $out;
};
$anchorWidget = static function (array $items, bool $withKey): LazyColumn {
    $widget = LazyColumn::make(40.0)->items(
        $items,
        static fn(int $i): Widget => Text::make('行 ' . $i),
    );
    if ($withKey) {
        $widget->keyOf(static fn(int $i): string => 'k' . $i);
    }

    return $widget;
};
$anchorLayout = static function (Element $root): void {
    $root->settle();
    $root->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));
};
/** 视口顶部那一行的文本（窗口第一行 = 文档序第一个 `Text`） */
$anchorTopRow = static function (Element $root): string {
    $text = findElement($root, static fn(Element $e): bool => $e->widget() instanceof Text)?->widget();

    return $text instanceof Text ? $text->content() : '?';
};
$anchorStateOf = static function (Element $root): ?LazyListState {
    $hit = findElement(
        $root,
        static fn(Element $e): bool => $e instanceof StatefulElement && $e->state() instanceof LazyListState,
    );
    $state = $hit instanceof StatefulElement ? $hit->state() : null;

    return $state instanceof LazyListState ? $state : null;
};

// ⑩ 有 keyOf：删掉第 1 行 ⇒ **同一行**仍在视口顶部（offset 4000 → 3960）
//    反例：按行号保持 ⇒ offset 仍是 4000 ⇒ 顶部变成"行 101"（跳了一行）。
$anchorA = $anchorWidget($anchorItems(0, 500), true)->createElement();
$anchorA->mount(null, smokeContext());
$anchorA->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));
$anchorStateA = $anchorStateOf($anchorA);
$anchorStateA?->scrollBy(4000.0);
$anchorLayout($anchorA);
$anchorTopBefore = $anchorTopRow($anchorA);
$anchorA->update($anchorWidget($anchorItems(1, 500), true));
$anchorLayout($anchorA);
check(
    'Lazy 锚点 · 有 keyOf：删掉第 1 行 ⇒ 原第 100 行仍在顶部（4000 → 3960）',
    $anchorStateA?->offset() === 3960.0
        && $anchorTopBefore === '行 100'
        && $anchorTopRow($anchorA) === '行 100',
    'offset=' . ($anchorStateA?->offset() ?? -1.0)
        . ' 前=' . $anchorTopBefore . ' 后=' . $anchorTopRow($anchorA),
);

// ⑪ 无 keyOf：同样的操作 ⇒ 按行号夹，顶部换行（**允许跳**，但行为可预测）
//    反例：没有 keyOf 也想按身份保持 ⇒ 只能靠猜，猜错就是随机跳。
$anchorB = $anchorWidget($anchorItems(0, 500), false)->createElement();
$anchorB->mount(null, smokeContext());
$anchorB->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));
$anchorStateB = $anchorStateOf($anchorB);
$anchorStateB?->scrollBy(4000.0);
$anchorLayout($anchorB);
$anchorB->update($anchorWidget($anchorItems(1, 500), false));
$anchorLayout($anchorB);
check(
    'Lazy 锚点 · 无 keyOf：按行号夹（offset 仍 4000，顶部变成"行 101"）',
    $anchorStateB?->offset() === 4000.0 && $anchorTopRow($anchorB) === '行 101',
    'offset=' . ($anchorStateB?->offset() ?? -1.0) . ' top=' . $anchorTopRow($anchorB),
);

// ⑫ 父重建但内容没变 ⇒ 偏移一点不动（**守门夹具**）
//    反例：`didUpdateWidget` 里无条件重算 offset ⇒ 每帧按锚点重算一遍，
//    浮点误差把滚动位置慢慢磨掉（"滚到 4000 变成 3999.98"）。
$anchorC = $anchorWidget($anchorItems(0, 500), true)->createElement();
$anchorC->mount(null, smokeContext());
$anchorC->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));
$anchorStateC = $anchorStateOf($anchorC);
$anchorStateC?->scrollBy(4000.0);
$anchorLayout($anchorC);
for ($i = 0; $i < 3; $i++) {
    $anchorC->update($anchorWidget($anchorItems(0, 500), true));
    $anchorLayout($anchorC);
}
check(
    'Lazy 锚点 · 内容没变的重建不碰偏移（连重建 3 次仍是 4000）',
    $anchorStateC?->offset() === 4000.0,
    (string) ($anchorStateC?->offset() ?? -1.0),
);

// ⑬ 锚点行被筛掉 ⇒ 回顶部（不夹到末尾）
//    反例：`min($index, $count - 1)` 不加 key 查找 ⇒ 10000 行筛成 30 行时
//    跳到列表末尾 —— 比跳回顶部更糟。
$anchorD = $anchorWidget($anchorItems(0, 500), true)->createElement();
$anchorD->mount(null, smokeContext());
$anchorD->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));
$anchorStateD = $anchorStateOf($anchorD);
$anchorStateD?->scrollBy(4000.0);
$anchorLayout($anchorD);
$anchorD->update($anchorWidget($anchorItems(0, 51), true));   // 锚点行 100 被筛掉
$anchorLayout($anchorD);
check(
    'Lazy 锚点 · 锚点行被筛掉 ⇒ 回顶部（offset 0，不是跳到末尾）',
    $anchorStateD?->offset() === 0.0 && $anchorTopRow($anchorD) === '行 0',
    'offset=' . ($anchorStateD?->offset() ?? -1.0) . ' top=' . $anchorTopRow($anchorD),
);


// ── 帧预算与 D1 降级 / 挂载登记表 已迁出 ────────────────────────────────────
//   · `tests/Feature/Frame/BudgetTest.php`       —— ⑭⑮⑯ overBudget 立/复位 + ⑰⑱ D1 节流 + ⑲ FrameClock 真量
//   · `tests/Feature/Frame/MountRegistryTest.php` —— ⑳㉑㉒ 登记表消费 / 跳过已卸载节点
//   ★ 共用 `tests/Support/Frame.php`（open + tick + resetErrors + pushCount + diagnostics）。


// ── 窗口状态（§5.4 / M5）已迁出 ────────────────────────────────────
//   · `tests/Feature/State/WindowStateTest.php` —— ① Paths 解析 + ②~⑩ persist/restore 全分支
//   ★ 临时文件走 setUp/tearDown，⑦-b 深目录自建自删。

// ── 语义树（§9 无障碍 / M5）已迁出 ────────────────────────────────────
//   · `tests/Feature/Semantics/SemanticsTest.php` —— ① 空树 / ② 角色+type / ③ TextField / ⑤ 焦点×2+几何 / ⑥ hitTest / ⑦ Modal / ⑧ 越界 / ⑨ toArrays / ⑩ 区间·text / ⑪ LazyColumn（15 条）
//   ★ $semLay（挂载+布局+建树闭包）是共享夹具，下方 Toggle / Switch / PageIndicator / Icon / SegmentedControl / Slider 的语义测试仍复用，故保留于此（只删断言，不删夹具）。

/** 挂载 + 布局一棵 Widget 树 —— 语义的几何来自渲染树 ⇒ 必须先布局再建树 */
$semLay = static function (Widget $root): Element {
    $el = $root->createElement();
    $el->mount(null, smokeContext());
    $el->settle();
    $el->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));

    return $el;
};


// ── 表单控件第一批：Checkbox / Radio / Toggle（㉚）已迁出 ──────────────────────
//   · `tests/Feature/Widget/ToggleTest.php` —— 挂载 RenderToggle / 布局 / 选中·未选中像素 /
//     Radio 值推导 / 开关胶囊像素 / Tab+Space 激活链 / 语义角色（13 条）
//   ★ 像素走共享 `spinCanvas()`（tests/Support/functions.php）；语义走共享 `Semantics::element()`（tests/Support/Semantics.php）。



// ── 分节原语：Divider / Progress（㉛）已迁出 ──────────────────────────────
//   · `tests/Feature/Widget/DividerProgressTest.php` —— 挂载渲染盒 / 布局（吃满·固定·无界回落 MIN_WIDTH）
//     / 像素（Divider 整根线、Progress 墨水分界 168）/ 语义（Divider 装饰不进树、Progress role=progress）/
//     value 夹取（0/负/NaN→0，0.37→370，≥1→满 1000）（15 条）
//   ★ 像素走共享 `spinCanvas()`；语义走共享 `Semantics::element()`。



// ── 图标（Icon / IconName / IconSet）已迁出 ──────────────────────────────
//   · `tests/Feature/Widget/IconTest.php` —— 挂载 RenderIcon / 固定正方形 16×16 /
//     Menu 画墨 / 每个 IconName 都有数据 / 装饰不进语义树（5 条）
//   ★ 像素走共享 `spinCanvas()`；语义走共享 `Semantics::element()`。



// ── 动画：`AnimationController` / `Curve` / 动画链 / 帧阶段顺序 已迁出 ────────────
//   · `tests/Unit/Widget/AnimationControllerTest.php` —— Curve 三根曲线 + Controller 十二个时钟判据（纯算术）
//   · `tests/Feature/Animation/AnimationChainTest.php` —— State::tickFrame → wantsFrame → 帧真重建（特征链）
//   · `tests/Unit/App/FramePhaseTest.php` —— 帧阶段顺序：正确序一条不报 + 三个越界方向都报
//   ★ 纯逻辑与特征链分离：前者不依赖帧驱动，后者用 MotionApplication 夹具。


// ═══════════════════════════════════════════ 语义推送两档（W7 L6-6）══
//
// `07` §6.2：结构变了 ⇒ `full`；动画中（结构没变）⇒ `coalesce`（每 4 帧一次）；
// 焦点变 ⇒ 立即 `full`。★ `delta` 档**诚实降级没做**（§6.4：没有字段表就只能是
// "每帧仍遍历全树、少一次序列化"的半成品）。
//
// ★ 反例方向：若 `updateChildren()` 无条件 `bumpStructure()`（每次 rebuild 都 +1），
//   则"结构没变"永远判不出来 ⇒ ㉓ 会变成每帧推（8 次而不是 2 次）、㉔ 的版本号会涨。

// ㉑ 结构版本号随挂载增长（`mount()` 里 +1）
$sv0 = Element::structureVersion();
$svApp = new EchoApplication();
$svApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(120.0, 120.0)));
$sv1 = Element::structureVersion();
check(
    '语义两档 · 结构版本号随挂载增长（mount() 里 +1）',
    $sv1 > $sv0,
    $sv0 . ' -> ' . $sv1,
);

// ㉒ 首帧（结构变了）⇒ 推 full
$modeApp = new SpinnerApplication();
$modePushes = 0;
$modeApp->setSemanticsSink(function (array $nodes) use (&$modePushes): void {
    ++$modePushes;
});
$modeApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(120.0, 120.0)));
check(
    '语义两档 · 首帧结构变了 ⇒ 推 full',
    $modePushes === 1 && $modeApp->semanticsMode() === 'full',
    'pushes=' . $modePushes . ' mode=' . $modeApp->semanticsMode(),
);

// ㉓ coalesce：动画中 8 帧 ⇒ 2 次（每 4 帧一次），不是 8 次
// ㉔ 同时钉住"动画每帧重建但**结构没变**" ⇒ 版本号不动
$modePushes = 0;
$svSpinBefore = Element::structureVersion();
$modeTs = 0.0;
for ($i = 0; $i < 8; $i++) {
    $modeTs += 16.0;
    $modeApp->frame($modeTs, BoxConstraints::tight(new Size(120.0, 120.0)));
}
$svSpinAfter = Element::structureVersion();
check(
    '语义两档 · coalesce：动画中 8 帧 ⇒ 推 2 次（每 4 帧一次）',
    $modePushes === 2 && $modeApp->semanticsMode() === 'coalesce',
    'pushes=' . $modePushes . ' mode=' . $modeApp->semanticsMode(),
);
check(
    '语义两档 · 动画每帧重建但结构没变 ⇒ 版本号不动（updateChildren 不无条件 +1）',
    $svSpinAfter === $svSpinBefore,
    $svSpinBefore . ' -> ' . $svSpinAfter,
);

// ㉕ coalesce 的第 1 帧**不推**（攒着）—— 证明它真在攒，不是"没走这条路"
$spin2 = new SpinnerApplication();
$spin2Pushes = 0;
$spin2->setSemanticsSink(function (array $nodes) use (&$spin2Pushes): void {
    ++$spin2Pushes;
});
$spin2->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(120.0, 120.0)));
$spin2Pushes = 0;
$spin2->frame(16.0, BoxConstraints::tight(new Size(120.0, 120.0)));
check(
    '语义两档 · coalesce 第 1 帧不推（攒着），档位记的是 coalesce',
    $spin2Pushes === 0 && $spin2->semanticsMode() === 'coalesce',
    'pushes=' . $spin2Pushes . ' mode=' . $spin2->semanticsMode(),
);

// ㉖ 虚拟列表滚动换了行 ⇒ **结构变** ⇒ full（不是 coalesce）
//    这条钉的是"滚动不是一种可以攒的变化"：行换了就必须整棵推，攒着会让读屏
//    的树与真实树错位（增量表达不了"多了几个节点"）。
$svLazy = new LazyApplication();
$lazyPushes = 0;
$svLazy->setSemanticsSink(function (array $nodes) use (&$lazyPushes): void {
    ++$lazyPushes;
});
$svLazy->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(400.0, 200.0)));
$lazyPushes = 0;
$svLazyBefore = Element::structureVersion();
$svLazy->dispatchInput(ScrollEvent::make(200.0, 100.0, 3.0)); // 3 格 = 144px ⇒ 窗口换行
$svLazy->frame(16.0, BoxConstraints::tight(new Size(400.0, 200.0)));
check(
    '语义两档 · 虚拟列表滚动换了行 ⇒ 结构变 ⇒ 立即 full（不攒）',
    Element::structureVersion() > $svLazyBefore && $lazyPushes === 1 && $svLazy->semanticsMode() === 'full',
    'ver=' . $svLazyBefore . '->' . Element::structureVersion() . ' pushes=' . $lazyPushes . ' mode=' . $svLazy->semanticsMode(),
);

// ㉗ **纯焦点变 ⇒ 立即 full**（2026-10-24 补，`pumpSemantics` 的 `|| $focusChanged`）
//    ★ 这条与 ⑱ 的区别（也是它存在的理由）：⑱ 里 `overBudget` 是混进来的
//      （skip=3 已经在），它测的是"节流期间焦点仍能穿过去"。
//    ★★ **必须带动画**，否则证伪不了（2026-10-24 实测）：不带动画时，删掉
//      `|| $focusChanged` 后焦点变的帧会落到 ③ 的**无条件落地推**，仍然推 1 次、
//      mode 仍是 `full` ⇒ 断言分不出 ① 与 ③。
//      带动画就不一样了：①（焦点变）⇒ `full`；③ 走 ②（动画）⇒ `coalesce`。
//      **这就是能区分两种实现的唯一形状** —— 前提不成立的断言比没有断言更坏。
$focusApp = new FocusTwoApplication();
$focusPushes = 0;
$focusApp->setSemanticsSink(function (array $nodes) use (&$focusPushes): void {
    ++$focusPushes;
});
$focusApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 200.0)));
$focusApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
$focusApp->frame(16.0, BoxConstraints::tight(new Size(320.0, 200.0)));
$focusPushes = 0;
$focusLabelBefore = $focusApp->focusedLabel();
$focusVerBefore = Element::structureVersion();
$focusApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
$focusLabelAfter = $focusApp->focusedLabel();
$focusApp->frame(16.0, BoxConstraints::tight(new Size(320.0, 200.0)));
check(
    '语义两档 · 焦点移动（甲→乙）+ 动画中 ⇒ 仍立即 full（绕过 coalesce）',
    $focusPushes === 1
        && $focusApp->semanticsMode() === 'full'
        && $focusLabelBefore === '甲'
        && $focusLabelAfter === '乙'
        && Element::structureVersion() === $focusVerBefore,
    'pushes=' . $focusPushes . ' mode=' . $focusApp->semanticsMode()
        . ' focus=' . $focusLabelBefore . '->' . $focusLabelAfter
        . ' ver=' . $focusVerBefore . '->' . Element::structureVersion(),
);
// ── TestApp：声明式场景（`07` §4.2 / §4.3，W7 第 42 步）──
//   三条场景各验一类，都用 `TestApp` 的**同一个**执行器：
//     ① 虚拟列表：滚动前后都只造可见行（R1 查树，不查 Widget 声明）
//     ② 动画：没人调 `tick`，帧时钟自己推进（R4 显式 pump）
//     ③ 几何：滚动只动**子盒偏移**，不动视口盒（M2-3 的不变量）
//   ★ 第 4 条是**反例**：假谓词 / 选择器无匹配 / 选择器不唯一都必须记失败 ——
//     只写正例的宿主等于"永远绿"。
$lazyTextCount = static function (Application $app): int {
    $count = 0;
    $walk = static function (Element $el) use (&$walk, &$count): void {
        if ($el->widget() instanceof Text) {
            ++$count;
        }
        $el->visitChildren($walk);
    };
    $root = $app->root();
    if ($root !== null) {
        $walk($root);
    }

    return $count;
};
$spinnerStep = static function (Application $app): int {
    $root = $app->root();
    if (!$root instanceof StatefulElement) {
        return -1;
    }
    $state = $root->state();

    return $state instanceof SpinnerState ? $state->step() : -1;
};

$testAppScenarios = [
    TestApp::scenario('虚拟列表：滚动前后都只造可见行')
        ->app(new LazyApplication())
        ->constraints(400.0, 200.0)
        ->pump(1)
        ->expect(
            '树里的 Text < 20（500 行只造可见的）',
            static fn(Application $a): bool => $lazyTextCount($a) < 20
        )
        // ★ `scroll()` 的单位是**格**：1 格 = 48px（`Application::WHEEL_NOTCH_PX`）。
        //   这个 48 是**设计值**，改了就该让这条断言红 —— 不是"跑出来再抄回去"。
        ->scroll(1.0)
        ->pump(1)
        ->expect(
            '滚轮真的改了偏移（1 格 = 48px）',
            static fn(Application $a): bool => $a instanceof LazyApplication && $a->lazyState()?->offset() === 48.0
        )
        ->expect(
            '滚动后仍然只造可见行',
            static fn(Application $a): bool => $lazyTextCount($a) < 20
        ),
    TestApp::scenario('Spinner：没人调 tick，帧时钟自己推进')
        ->app(new SpinnerApplication())
        ->constraints(120.0, 120.0)
        ->pump(1)
        ->expect('首帧 step === 1（attach 走同一入口）', static fn(Application $a): bool => $spinnerStep($a) === 1)
        ->pump(2)
        ->expect('再跑两帧 ⇒ step === 3', static fn(Application $a): bool => $spinnerStep($a) === 3),
    TestApp::scenario('几何：滚动只动子盒偏移，不动视口盒')
        ->app(new LazyApplication())
        ->constraints(400.0, 200.0)
        ->pump(1)
        ->expectBox('LazyColumn', 0.0, 0.0, 400.0, 200.0)
        ->scroll(3.0)
        ->pump(1)
        ->expectBox('LazyColumn', 0.0, 0.0, 400.0, 200.0),
];

foreach ($testAppScenarios as $scenario) {
    $result = $scenario->run();
    check(
        'TestApp · ' . $result['name'] . '（' . $result['passed'] . ' 条断言）',
        $result['failed'] === 0,
        implode(' ｜ ', $result['failures'])
    );
}

// 反例：宿主自己也得会红
$testAppNegative = TestApp::scenario('反例：假谓词 / 无匹配 / 不唯一')
    ->app(new LazyApplication())
    ->constraints(400.0, 200.0)
    ->pump(1)
    ->expect('故意假', static fn(Application $a): bool => false)
    ->expectBox('NoSuchWidget', 0.0, 0.0, 1.0, 1.0)
    ->expectBox('Text', 0.0, 0.0, 1.0, 1.0)
    ->run();
check(
    'TestApp · 反例：假谓词 + 选择器无匹配 + 选择器不唯一都记失败（R2 与 G2 都有牙）',
    $testAppNegative['failed'] === 3 && $testAppNegative['passed'] === 0,
    'failed=' . $testAppNegative['failed'] . ' ｜ ' . implode(' ｜ ', $testAppNegative['failures'])
);

// ── ㊺ 分段控件：SegmentedControl / Segment（2026-10-23，W8-2 第一个控件）──
// 五条判据：① 结构 = 多子盒容器（`Stack` 形态：kidList + 自己的 Element）；
//          ② 几何按**公式**推（等宽 = 最宽标签推进，不跑一次抄回来）；
//          ③ 墨迹/命令序：轨道 chrome 在段之前，选中块精确落在段内缩处；
//          ④ 激活走 Application 唯一入口，焦点落**段**（整组答不出"选了哪段"）；
//          ⑤ 反例全在位：越界选中 / 空标签 / 已选段 / 禁用段 / 空点击。

$segTextStyle = TextStyle::of($context->textSize(TextRole::Body));
$segScale = new Scale(1.0);
$segGap = SegmentedStyle::DEFAULT_GAP;
$segInset = SegmentedStyle::DEFAULT_INSET;
$segLabels = ['日', '周', '月'];

// 期望几何全部由度量现推（RenderText 的自然宽 = advance；行高 = layout(...)->height）
$segEqual = 0.0;
foreach ($segLabels as $segLabel) {
    $segAdv = $context->metrics()->advance($segLabel, $segTextStyle, $segScale);
    if ($segAdv > $segEqual) {
        $segEqual = $segAdv;
    }
}
$segLineH = $context->metrics()->layout($segLabels[0], $segTextStyle, INF, $segScale)->height;
// ★ 段盒 = 标签 + 四边 inset（RenderSegment::performLayout 的不变量）：选中块按
//   inset 内缩后画，药丸必须装得下标签。少了这一圈，选中态白字会溢出到轨道上。
$segBoxW = $segEqual + 2.0 * $segInset;
$segBoxH = $segLineH + 2.0 * $segInset;
$segWantW = $segBoxW * 3.0 + $segGap * 2.0 + 20.0; // 段盒宽×3 + 缝×2 + 左右内距 10×2
$segWantH = $segBoxH + 10.0;                       // 段盒高 + 上下内距 5×2

$scEl = SegmentedControl::make($segLabels)->selected(1)->createElement();
$scEl->mount(null, $context);
$scBox = $scEl->effectiveBox();
check(
    'Seg · 挂载即建出 RenderSegmentedControl（结构节点产盒）',
    $scBox instanceof RenderSegmentedControl,
    $scBox === null ? 'box=null' : get_class($scBox),
);

$scKids = $scBox instanceof RenderSegmentedControl ? $scBox->children() : [];
$scKidsOk = count($scKids) === 3;
if ($scKidsOk) {
    foreach ($scKids as $scIdx => $scKid) {
        if (!$scKid instanceof RenderSegment || $scKid->isSelected() !== ($scIdx === 1)) {
            $scKidsOk = false;
            break;
        }
    }
}
check(
    'Seg · 三个段各是 RenderSegment，只有中段选中',
    $scKidsOk,
    'kids=' . count($scKids),
);

$scBox?->layout(BoxConstraints::loose(new Size(400.0, 100.0)));
$scSize = $scBox?->size();
check(
    'Seg · 宽 = 段盒宽×3 + 缝×2 + 内距 20（公式推）',
    $scSize !== null && abs($scSize->width - $segWantW) < 0.01,
    sprintf('w=%.2f want=%.2f', $scSize?->width ?? -1.0, $segWantW),
);
check(
    'Seg · 高 = 段盒高 + 内距 10',
    $scSize !== null && abs($scSize->height - $segWantH) < 0.01,
    sprintf('h=%.2f want=%.2f', $scSize?->height ?? -1.0, $segWantH),
);
check(
    'Seg · 内禀宽 = 实排宽（等宽口径：最宽者乘段数，不是求和）',
    $scBox !== null && abs($scBox->intrinsicWidth(INF) - $segWantW) < 0.01,
    sprintf('intrinsic=%.2f want=%.2f', $scBox?->intrinsicWidth(INF) ?? -1.0, $segWantW),
);

// 段盒等宽 + offset 累积（内容盒坐标：0, equal+gap, 2*(equal+gap)）
$scOffsets = [];
$scWidths = [];
foreach ($scKids as $scKid) {
    $scOffsets[] = $scKid->offsetX();
    $scWidths[] = $scKid->size()?->width ?? -1.0;
}
check(
    'Seg · 段盒等宽（= 最宽标签 + 2*inset）且 offset 累积',
    count($scOffsets) === 3
        && abs($scWidths[0] - $segBoxW) < 0.01 && abs($scWidths[1] - $segBoxW) < 0.01 && abs($scWidths[2] - $segBoxW) < 0.01
        && abs($scOffsets[0]) < 0.01
        && abs($scOffsets[1] - ($segBoxW + $segGap)) < 0.01
        && abs($scOffsets[2] - 2.0 * ($segBoxW + $segGap)) < 0.01,
    sprintf('boxW=%.2f offsets=%s', $segBoxW, implode(',', array_map(static fn(float $v): string => sprintf('%.2f', $v), $scOffsets))),
);

// 命令序：轨道 fill + stroke 两条在前，选中块一条在后（chrome 不被段盖、也不裁自己）
$scList = new DisplayList();
$scBox?->paint(new RecordingCanvas($scList));
$scRects = [];
for ($scRi = 0; $scRi < $scList->count(); $scRi++) {
    if ($scList->opAt($scRi) === DisplayList::OP_DRAW_RECT) {
        $scRects[] = $scRi;
    }
}
check(
    'Seg · 命令序：轨道 fill+stroke 在前、选中块在后（共 3 条 drawRect）',
    count($scRects) === 3 && $scRects[0] < $scRects[1] && $scRects[1] < $scRects[2],
    'rects=[' . implode(',', $scRects) . ']',
);

// 像素 + 命令双重判据。
// ★ 为什么不扫"accent Run 的精确边界"（第一版那么写，恒红）：抗锯齿让边缘像素是
//   混合色，leftmost/rightmost 找不到纯 accent；且内容盒 offset 差一个左内距。
//   几何改走**命令参数**精确断言（rect / radius / color 全在 DisplayList 里），
//   像素只断"墨真的落在该落的地方、没落在不该落的地方"。
$scCanvas = spinCanvas($scBox, 240, 60);
$scMid = (int) round($segWantH / 2.0);
$scAccent = $context->color(ColorToken::Accent);
$scChrome = $context->color(ColorToken::Chrome);
$scBorder = $context->color(ColorToken::Border);
$scOriginX = $scBox->contentOriginX(); // 左内距 10 —— 子盒 offset 住内容盒，像素坐标要加它

// ① 选中块的命令参数：rect = 段盒四边内缩 inset、radius = min(宽,高)/2（夹半宽）、
//    颜色 = Accent。三个数全由几何公式推，不拟合。
$scPillRect = null;
$scPillRadius = 0.0;
for ($scRi = 0; $scRi < $scList->count(); $scRi++) {
    if ($scList->opAt($scRi) !== DisplayList::OP_DRAW_RECT) {
        continue;
    }
    $scPaintArg = $scList->argAt($scRi, 1);
    if (!$scPaintArg instanceof Paint || !$scPaintArg->color->equals($scAccent)) {
        continue;
    }
    $scPillRect = $scList->argAt($scRi, 0);
    $scPillRadius = (float) $scList->argAt($scRi, 2);
}
$scPillW = $segBoxW - 2.0 * $segInset;
$scPillH = $segBoxH - 2.0 * $segInset;
check(
    'Seg · 选中块命令参数：rect = 段盒内缩 inset、radius = min(宽,高)/2、色 = Accent',
    $scPillRect instanceof Rect
        && abs($scPillRect->left - $segInset) < 0.01 && abs($scPillRect->top - $segInset) < 0.01
        && abs($scPillRect->width - $scPillW) < 0.01 && abs($scPillRect->height - $scPillH) < 0.01
        && abs($scPillRadius - min($scPillW, $scPillH) / 2.0) < 0.01,
    $scPillRect instanceof Rect
        ? sprintf('rect(%.1f,%.1f,%.1f,%.1f) r=%.2f', $scPillRect->left, $scPillRect->top, $scPillRect->width, $scPillRect->height, $scPillRadius)
        : 'no accent rect',
);

// ①b 药丸必须装得下标签（这条是"段盒 = 标签 + 2*inset"不变量的判据）：
//     药丸宽 = 段盒宽 − 2*inset = 最宽标签宽；药丸高同理 = 行高。
//     少了这条，`equal` 就等于标签宽、药丸比标签窄 4 ⇒ 选中态白字每边溢出 1px
//     落到轨道上（真机像素实测：字迹 12px / 药丸 10px）。
check(
    'Seg · 药丸装得下标签：pill 宽 = 最宽标签、pill 高 = 行高',
    abs($scPillW - $segEqual) < 0.01 && abs($scPillH - $segLineH) < 0.01 && $scPillW >= $segEqual,
    sprintf('pillW=%.2f labelW=%.2f pillH=%.2f lineH=%.2f', $scPillW, $segEqual, $scPillH, $segLineH),
);

// ② 段间缝（2px）在选中块两侧都必须是轨底：选中块不越段界
$scGapBad = [];
foreach ([0, 1] as $scGapIdx) {
    $scGapX = (int) floor($scOriginX + ($scGapIdx + 1) * $segBoxW + $scGapIdx * $segGap);
    for ($scG = 0; $scG < (int) $segGap; $scG++) {
        if (!$scCanvas->pixelAt($scGapX + $scG, $scMid)->equals($scChrome)) {
            $scGapBad[] = ($scGapX + $scG);
        }
    }
}
check(
    'Seg · 段间缝（2px）全是轨底 ⇒ 选中块不越段界',
    $scGapBad === [],
    'bad=' . implode(',', $scGapBad),
);

// ③ 选中块里有真 accent 墨（排除"命令录对了却没画出来"）
$scInk = 0;
for ($scPy = 0; $scPy < 60; $scPy++) {
    for ($scPx = 0; $scPx < 240; $scPx++) {
        if ($scCanvas->pixelAt($scPx, $scPy)->equals($scAccent)) {
            $scInk++;
        }
    }
}
check('Seg · 选中块有真 accent 墨（≥ 15 px）', $scInk >= 15, 'accentPx=' . $scInk);

// ④ 未选段（日/月）中行零 accent：填充只画在选中段上
$scUnselBad = [];
foreach ([0, 2] as $scUnselIdx) {
    $scSegX0 = (int) floor($scOriginX + $scUnselIdx * ($segBoxW + $segGap));
    $scSegX1 = (int) ceil($scOriginX + $scUnselIdx * ($segBoxW + $segGap) + $segBoxW);
    for ($scUx = $scSegX0; $scUx < $scSegX1; $scUx++) {
        if ($scCanvas->pixelAt($scUx, $scMid)->equals($scAccent)) {
            $scUnselBad[] = $scUx;
        }
    }
}
check('Seg · 未选段中行零 accent（填充只画在选中段）', $scUnselBad === [], 'bad=' . implode(',', $scUnselBad));

// ⑤ 轨道 chrome：中行 x=0 描边、x=1 轨底（chrome 真的画了）
check(
    'Seg · 轨道 chrome：中行 x=0 描边、x=1 轨底（chrome 真的画了）',
    $scCanvas->pixelAt(0, $scMid)->equals($scBorder) && $scCanvas->pixelAt(1, $scMid)->equals($scChrome),
    sprintf('x0=%s x1=%s', $scCanvas->pixelAt(0, $scMid)->toArgb(), $scCanvas->pixelAt(1, $scMid)->toArgb()),
);

// kidList 单元：selected() 在 make() 之后调也必须拿得到值（不能建构器里建一次缓存）
$scKidsFresh = SegmentedControl::make($segLabels)->selected(1)->kidList();
$scFreshOk = count($scKidsFresh) === 3 && $scKidsFresh[1]->isSelected()
    && !$scKidsFresh[0]->isSelected() && !$scKidsFresh[2]->isSelected();
check('Seg · kidList 每次建新段：make() 之后调的 selected() 进得去', $scFreshOk);

$scOut = SegmentedControl::make($segLabels)->selected(5);
$scOutKids = $scOut->kidList();
$scNoneSel = true;
foreach ($scOutKids as $scOutKid) {
    if ($scOutKid->isSelected()) {
        $scNoneSel = false;
    }
}
check(
    'Seg · 越界选中 ⇒ selectedIndex() = -1 且一段都不选中（不夹到 0）',
    $scOut->selectedIndex() === -1 && $scNoneSel,
    'idx=' . $scOut->selectedIndex(),
);

$scEmpty = SegmentedControl::make([]);
check('Seg · 空标签表 ⇒ kidList 空、不炸', $scEmpty->kidList() === []);
$scEmptyEl = $scEmpty->createElement();
$scEmptyEl->mount(null, $context);
$scEmptyEl->effectiveBox()?->layout(BoxConstraints::loose(new Size(400.0, 100.0)));
$scEmptySize = $scEmptyEl->effectiveBox()?->size();
check(
    'Seg · 空轨 = 只剩内距（20×10），不炸不出 INF',
    $scEmptySize !== null && $scEmptySize->width === 20.0 && $scEmptySize->height === 10.0,
    $scEmptySize === null ? 'size=null' : sprintf('%.1fx%.1f', $scEmptySize->width, $scEmptySize->height),
);

// 段控件自己的 activate()：未选才回调；已选 / 禁用都不回调（反例在位）
$scFired = 0;
$scFire = static function () use (&$scFired): void {
    $scFired++;
};
Segment::make('周', 1)->selected(false)->onChanged($scFire)->activate(ActivationContext::none());
Segment::make('周', 1)->selected(true)->onChanged($scFire)->activate(ActivationContext::none());
Segment::make('周', 1)->selected(false)->enabled(false)->onChanged($scFire)->activate(ActivationContext::none());
check('Seg · activate()：未选段才回调；已选/禁用都不回调', $scFired === 1, 'fired=' . $scFired);

// 全不选 ⇒ 整幅零 accent（没有"默认选中第一项"这回事）
$scNoneEl = SegmentedControl::make($segLabels)->selected(5)->createElement();
$scNoneEl->mount(null, $context);
$scNoneBox = $scNoneEl->effectiveBox();
$scNoneBox?->layout(BoxConstraints::loose(new Size(400.0, 100.0)));
$scNoneCanvas = spinCanvas($scNoneBox, 240, 60);
$scNoneAccent = 0;
for ($scNy = 0; $scNy < 60; $scNy++) {
    for ($scNx = 0; $scNx < 240; $scNx++) {
        if ($scNoneCanvas->pixelAt($scNx, $scNy)->equals($scAccent)) {
            $scNoneAccent++;
        }
    }
}
check('Seg · 全不选 ⇒ 整幅零 accent', $scNoneAccent === 0, 'accentPx=' . $scNoneAccent);

// 语义：三个 radio 节点；容器本身不给角色（结构不是语义）
$scSem = SemanticsBuilder::build($semLay(SegmentedControl::make($segLabels)->selected(2)));
$scRadioCount = 0;
for ($scSi = 0; $scSi < $scSem->count(); $scSi++) {
    if ($scSem->node($scSi)->role === SemanticsRole::Radio->value) {
        $scRadioCount++;
    }
}
$scSemMoon = $scSem->findByRoleAndName(SemanticsRole::Radio->value, '月');
check(
    'Seg · 语义：三个 radio 节点（容器本身不给角色）',
    $scRadioCount === 3,
    'count=' . $scRadioCount,
);
check(
    'Seg · 语义：name = 标签、value = 选中项的标签',
    $scSemMoon >= 0 && $scSem->node($scSemMoon)->value === '月',
    'idx=' . $scSemMoon,
);

// ★★ 激活链（键盘）：Tab 一段一格 + Space ⇒ Application 的 Segment 分支。
//   A 组尾段预选中、B 组中段预选中、C 组整组禁用 —— 三个反例各占几步。
$segApp = new SegmentedApplication();
$segApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
for ($segStep = 0; $segStep < 8; $segStep++) {
    $segApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
    $segApp->dispatchInput(KeyEvent::down(KeyChord::make('Space')));
}
check(
    'Seg · Tab+Space 八步：未选段回调、已选段不回调、禁用段不回调',
    $segApp->events === [['a', 0], ['a', 1], ['b', 0], ['b', 2]],
    json_encode($segApp->events),
);

// 指针侧：down 即激活（与 Enter 同入口）。命中测试取最深盒 ⇒ 焦点落**段**上
//   （若忘写 markFocusable，焦点落不上去 ⇒ 不激活 ⇒ 本条红）。
$segPtrApp = new SegmentedApplication();
$segPtrApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$segPtrDay = findElement($segPtrApp->rootElement(), static function (Element $e): bool {
    $w = $e->widget();

    return $w instanceof Segment && $w->index() === 0;
});
$segDayBox = $segPtrDay?->effectiveBox();
[$segDx, $segDy] = $segDayBox === null ? [0.0, 0.0] : boxOrigin($segDayBox);
$segDw = $segDayBox?->size()?->width ?? 0.0;
$segDh = $segDayBox?->size()?->height ?? 0.0;
$segPtrApp->dispatchInput(PointerEvent::down($segDx + $segDw / 2.0, $segDy + $segDh / 2.0));
check(
    'Seg · 指针按下段中心 ⇒ 选中该段',
    $segPtrApp->events === [['a', 0]],
    json_encode($segPtrApp->events),
);
$segPtrApp->dispatchInput(PointerEvent::down(4.0, 4.0)); // 根 Column 的 padding 区：无焦点可落
check(
    'Seg · 点空白（无焦点可落）⇒ 不误触发上一个焦点段',
    $segPtrApp->events === [['a', 0]],
    json_encode($segPtrApp->events),
);
$segPtrMoon = findElement($segPtrApp->rootElement(), static function (Element $e): bool {
    $w = $e->widget();

    return $w instanceof Segment && $w->index() === 2;
});
$segMoonBox = $segPtrMoon?->effectiveBox();
[$segMx, $segMy] = $segMoonBox === null ? [0.0, 0.0] : boxOrigin($segMoonBox);
$segMw = $segMoonBox?->size()?->width ?? 0.0;
$segMh = $segMoonBox?->size()?->height ?? 0.0;
$segPtrApp->dispatchInput(PointerEvent::down($segMx + $segMw / 2.0, $segMy + $segMh / 2.0));
check(
    'Seg · 点已选段（月，A 组预选中）⇒ 不重复回调',
    $segPtrApp->events === [['a', 0]],
    json_encode($segPtrApp->events),
);
$segPtrOff = findElement($segPtrApp->rootElement(), static function (Element $e): bool {
    $w = $e->widget();

    return $w instanceof Segment && $w->semanticsName() === '开';
});
$segOffBox = $segPtrOff?->effectiveBox();
[$segOx, $segOy] = $segOffBox === null ? [0.0, 0.0] : boxOrigin($segOffBox);
$segOw = $segOffBox?->size()?->width ?? 0.0;
$segOh = $segOffBox?->size()?->height ?? 0.0;
$segPtrApp->dispatchInput(PointerEvent::down($segOx + $segOw / 2.0, $segOy + $segOh / 2.0));
check(
    'Seg · 点禁用段（开，C 组整组禁用）⇒ 不回调',
    $segPtrApp->events === [['a', 0]],
    json_encode($segPtrApp->events),
);

// ── W8-2 Slider（滑杆）──────────────────────────────────────────────────
//
// 几何全部按公式推（不拟合）：padX = 拇指半径，盒高 = 2*半径 + 2*留白，
// thumbX = padX + t*(w - 2*padX)，t = (value - min)/(max - min)。
$slRadius = SliderStyle::DEFAULT_THUMB_RADIUS;   // 8
$slGap = SliderStyle::DEFAULT_THUMB_GAP;         // 2
$slTrackH = SliderStyle::DEFAULT_TRACK_HEIGHT;   // 4
$slWantH = 2.0 * $slRadius + 2.0 * $slGap;       // 20
$slWantW = Slider::DEFAULT_WIDTH;                 // 160
$slPad = $slRadius;                               // 8
$slInner = $slWantW - 2.0 * $slPad;               // 144（拇指中心可达区间）

$slEl = Slider::make()->range(0.0, 100.0)->value(50.0)->createElement();
$slEl->mount(null, $context);
$slBox = $slEl->effectiveBox();
check(
    'Slider · 挂载即建出 RenderSlider（叶子盒）',
    $slBox instanceof RenderSlider,
    $slBox === null ? 'box=null' : get_class($slBox),
);

$slBox?->layout(BoxConstraints::loose(new Size(400.0, 100.0)));
$slSize = $slBox?->size();
check(
    'Slider · 宽 = 显式 160、高 = 2*拇指半径 + 2*留白 = 20（公式推）',
    $slSize !== null && abs($slSize->width - $slWantW) < 0.01 && abs($slSize->height - $slWantH) < 0.01,
    sprintf('w=%.2f h=%.2f want=%.2fx%.2f', $slSize?->width ?? -1.0, $slSize?->height ?? -1.0, $slWantW, $slWantH),
);
check(
    'Slider · 内禀 = 实排（叶子无内容，宽只能显式给）',
    $slBox !== null
        && abs($slBox->intrinsicWidth(INF) - $slWantW) < 0.01
        && abs($slBox->intrinsicHeight(INF) - $slWantH) < 0.01,
    sprintf('intrinsic=%.2fx%.2f', $slBox?->intrinsicWidth(INF) ?? -1.0, $slBox?->intrinsicHeight(INF) ?? -1.0),
);

// 位置 ↔ 值互逆：value=50 ⇒ thumbX = 8 + 0.5*144 = 80
$slBox instanceof RenderSlider || null;
$slThumb = $slBox instanceof RenderSlider ? $slBox->thumbX() : -1.0;
check(
    'Slider · value=50 ⇒ thumbX = pad + 0.5*(w-2*pad) = 80（公式推）',
    abs($slThumb - ($slPad + 0.5 * $slInner)) < 0.01,
    sprintf('thumbX=%.2f want=%.2f', $slThumb, $slPad + 0.5 * $slInner),
);

$slAt = static function (?RenderBox $box, float $x): float {
    return $box instanceof RenderSlider ? $box->valueAt($x) : -999.0;
};
check(
    'Slider · valueAt 是 thumbX 的反函数（80 → 50；两端 8/152 → 0/100）',
    abs($slAt($slBox, 80.0) - 50.0) < 0.01
        && abs($slAt($slBox, $slPad) - 0.0) < 0.01
        && abs($slAt($slBox, $slPad + $slInner) - 100.0) < 0.01,
    sprintf('f(80)=%.2f f(8)=%.2f f(152)=%.2f', $slAt($slBox, 80.0), $slAt($slBox, $slPad), $slAt($slBox, $slPad + $slInner)),
);
check(
    'Slider · valueAt 越界夹紧（x=0 与 x=200 都落回端点，不产生越界值）',
    abs($slAt($slBox, 0.0) - 0.0) < 0.01 && abs($slAt($slBox, 200.0) - 100.0) < 0.01,
    sprintf('f(0)=%.2f f(200)=%.2f', $slAt($slBox, 0.0), $slAt($slBox, 200.0)),
);

// 量化：range(0,10) step(1)，x = pad + 0.55*inner ⇒ 5.5 ⇒ round ⇒ 6
$slQuantEl = Slider::make()->range(0.0, 10.0)->step(1.0)->value(3.0)->createElement();
$slQuantEl->mount(null, $context);
$slQuantBox = $slQuantEl->effectiveBox();
$slQuantBox?->layout(BoxConstraints::loose(new Size(400.0, 100.0)));
$slQuantX = $slPad + 0.55 * $slInner;
check(
    'Slider · step=1 量化：x 落在 5.5 ⇒ 值 6（不是 5.5）',
    abs($slAt($slQuantBox, $slQuantX) - 6.0) < 0.01,
    sprintf('valueAt(%.2f)=%.3f want=6', $slQuantX, $slAt($slQuantBox, $slQuantX)),
);

// 绘制命令：track rect → fill rect → 拇指 fill 椭圆 → 拇指 stroke 椭圆
$slList = new DisplayList();
$slBox?->paint(new RecordingCanvas($slList));
$slOps = [];
for ($slOi = 0; $slOi < $slList->count(); $slOi++) {
    $slOps[] = $slList->opAt($slOi);
}
check(
    'Slider · 命令序：轨道 rect → 已选段 rect → 拇指 fill → 拇指 stroke（6,6,8,8）',
    $slOps === [DisplayList::OP_DRAW_RECT, DisplayList::OP_DRAW_RECT, DisplayList::OP_DRAW_ELLIPSE, DisplayList::OP_DRAW_ELLIPSE],
    'ops=[' . implode(',', $slOps) . ']',
);

$slTrackRect = $slList->argAt(0, 0);
$slFillRect = $slList->argAt(1, 0);
$slFillRadius = $slList->argAt(1, 2);
$slThumbRect = $slList->argAt(2, 0);
check(
    'Slider · 轨道 rect = (pad, cy-2, w-2*pad, 4)、radius = 2（公式推）',
    $slTrackRect instanceof Rect
        && abs($slTrackRect->left - $slPad) < 0.01
        && abs($slTrackRect->top - ($slWantH / 2.0 - $slTrackH / 2.0)) < 0.01
        && abs($slTrackRect->width - $slInner) < 0.01
        && abs($slTrackRect->height - $slTrackH) < 0.01
        && abs((float) $slFillRadius - $slTrackH / 2.0) < 0.01,
    $slTrackRect instanceof Rect
        ? sprintf('rect(%.1f,%.1f,%.1f,%.1f) r=%.2f', $slTrackRect->left, $slTrackRect->top, $slTrackRect->width, $slTrackRect->height, (float) $slFillRadius)
        : 'no track rect',
);
check(
    'Slider · 已选段宽 = thumbX - pad = 72（跟着值走，不是半个轨道）',
    $slFillRect instanceof Rect && abs($slFillRect->width - ($slThumb - $slPad)) < 0.01,
    $slFillRect instanceof Rect ? sprintf('w=%.2f want=%.2f', $slFillRect->width, $slThumb - $slPad) : 'no fill rect',
);
check(
    'Slider · 拇指 = 正方形椭圆（thumbX-r, cy-r, 2r, 2r）= (72,2,16,16)',
    $slThumbRect instanceof Rect
        && abs($slThumbRect->left - ($slThumb - $slRadius)) < 0.01
        && abs($slThumbRect->top - ($slWantH / 2.0 - $slRadius)) < 0.01
        && abs($slThumbRect->width - 2.0 * $slRadius) < 0.01
        && abs($slThumbRect->height - 2.0 * $slRadius) < 0.01,
    $slThumbRect instanceof Rect
        ? sprintf('rect(%.1f,%.1f,%.1f,%.1f)', $slThumbRect->left, $slThumbRect->top, $slThumbRect->width, $slThumbRect->height)
        : 'no thumb rect',
);

// 像素：拇指中心 accent、pad 区（x<8）无 accent
$slCanvas = spinCanvas($slBox, (int) $slWantW, (int) $slWantH);
$slAccent = $context->color(ColorToken::Accent)->toArgb();
$slCenterOk = $slCanvas->pixelAt((int) round($slThumb), (int) round($slWantH / 2.0))->toArgb() === $slAccent;
$slPadInk = 0;
for ($slPx = 0; $slPx < (int) $slPad; $slPx++) {
    for ($slPy = 0; $slPy < (int) $slWantH; $slPy++) {
        if ($slCanvas->pixelAt($slPx, $slPy)->toArgb() === $slAccent) {
            $slPadInk++;
        }
    }
}
check(
    'Slider · 拇指中心是 accent 墨，且左 pad 区（x<8）零 accent（拇指不出界）',
    $slCenterOk && $slPadInk === 0,
    'center=' . var_export($slCenterOk, true) . ' padInk=' . $slPadInk,
);

// 指针：down 在 3/4 处 ⇒ 75；move 到最左 ⇒ 0；up 收口
$slApp = new SliderApplication();
$slApp->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(320.0, 200.0)));
$slAppBox = $slApp->root()?->effectiveBox();
$slAppBox?->layout(BoxConstraints::loose(new Size(320.0, 200.0)));
$slAppSliders = [];
$slApp->root()?->visitChildren(function (Element $e) use (&$slAppSliders): void {
    if ($e->widget() instanceof Slider) {
        $slAppSliders[] = $e;
    }
});
$slElA = $slAppSliders[0] ?? null;
$slElC = $slAppSliders[2] ?? null;
$slBoxA = $slElA?->effectiveBox();
[$slAx, $slAy] = $slBoxA === null ? [0.0, 0.0] : boxOrigin($slBoxA);

$slApp->dispatchInput(PointerEvent::down($slAx + $slPad + 0.75 * $slInner, $slAy + $slWantH / 2.0));
check(
    'Slider · 按下 3/4 处 ⇒ onChanged(75) 且指针被捕获（ownsPress 生效）',
    count($slApp->events) === 1 && abs($slApp->events[0][1] - 75.0) < 0.01 && $slApp->captureNode() === $slElA,
    json_encode($slApp->events) . ' captured=' . ($slApp->captureNode() === $slElA ? 'yes' : 'no'),
);
$slApp->dispatchInput(PointerEvent::move($slAx + $slPad, $slAy + $slWantH / 2.0));
check(
    'Slider · 拖到最左 ⇒ onChanged(0)（跟手，不需要过阈值）',
    count($slApp->events) === 2 && abs($slApp->events[1][1] - 0.0) < 0.01,
    json_encode($slApp->events),
);
$slApp->dispatchInput(PointerEvent::up($slAx + $slPad, $slAy + $slWantH / 2.0));
check(
    'Slider · up ⇒ 捕获释放（不释放就是"事件永远发给死节点"）',
    $slApp->captureNode() === null && count($slApp->events) === 2,
    'captured=' . var_export($slApp->captureNode(), true),
);

// 反例：值已在端点，再点同一点 ⇒ 不回调（同位置不产生无意义的 setState）
$slEdgeEl = Slider::make()->range(0.0, 100.0)->value(100.0)->createElement();
$slEdgeEl->mount(null, $context);
$slEdgeBox = $slEdgeEl->effectiveBox();
$slEdgeBox?->layout(BoxConstraints::loose(new Size(400.0, 100.0)));
$slEdgeFired = 0;
$slEdgeApp = new SliderApplication();
check(
    'Slider · 反例：value 已在 max、点最右 ⇒ emit 判等后不回调（阈值 1e-9）',
    // 直接验纯函数面：valueAt(端点) === 受控值 ⇒ emit 的判等会拦下
    abs($slAt($slEdgeBox, $slPad + $slInner) - 100.0) < 1e-9 && $slEdgeFired === 0,
    sprintf('valueAt(end)=%.6f', $slAt($slEdgeBox, $slPad + $slInner)),
);

// 禁用：down 不回调、不吃方向键
$slBoxC = $slElC?->effectiveBox();
[$slCx, $slCy] = $slBoxC === null ? [0.0, 0.0] : boxOrigin($slBoxC);
$slBeforeC = count($slApp->events);
$slApp->dispatchInput(PointerEvent::down($slCx + $slPad + 0.5 * $slInner, $slCy + $slWantH / 2.0));
check(
    'Slider · 禁用：按下不回调（仍然吞掉按下 —— 禁用控件不该被激活）',
    count($slApp->events) === $slBeforeC && $slApp->captureNode() === $slElC,
    'events=' . count($slApp->events),
);
$slApp->dispatchInput(PointerEvent::up($slCx + $slPad + 0.5 * $slInner, $slCy + $slWantH / 2.0));

// 键盘：焦点在滑杆上 ⇒ 方向键 ±（step<=0 时按区间 1%）、Home/End 到端点
//
// ★ 判据基于**受控值**而不是"连按累加"：夹具的回调只记录事件、不改值
//   ⇒ `$this->value` 恒 50。所以这里验的是"一次按键按当前受控值算多少"，
//   而不是"连按两次到 52"（后者依赖应用在回调里 setState + 重建，
//   是应用的受控值纪律，不是框架的）。
$slApp->dispatchInput(PointerEvent::down($slAx + $slPad, $slAy + $slWantH / 2.0));
$slApp->dispatchInput(PointerEvent::up($slAx + $slPad, $slAy + $slWantH / 2.0));
$slApp->events = [];
$slApp->dispatchInput(KeyEvent::down(KeyChord::right()));
check(
    'Slider · 键盘 →：step<=0 时按区间 1% 走（受控值 50 ⇒ 51）',
    count($slApp->events) === 1 && abs($slApp->events[0][1] - 51.0) < 0.01,
    json_encode($slApp->events),
);
$slApp->events = [];
$slApp->dispatchInput(KeyEvent::down(KeyChord::end()));
check(
    'Slider · 键盘 End ⇒ max（100）',
    count($slApp->events) === 1 && abs($slApp->events[0][1] - 100.0) < 0.01,
    json_encode($slApp->events),
);
$slApp->events = [];
$slApp->dispatchInput(KeyEvent::down(KeyChord::left()));
check(
    'Slider · 键盘 ←：受控值 50 ⇒ 49（不是"从 End 往回 1"——受控值才是基值）',
    count($slApp->events) === 1 && abs($slApp->events[0][1] - 49.0) < 0.01,
    json_encode($slApp->events),
);
$slApp->events = [];
$slApp->dispatchInput(KeyEvent::down(KeyChord::home()));
check(
    'Slider · 键盘 Home ⇒ min（0）',
    count($slApp->events) === 1 && abs($slApp->events[0][1] - 0.0) < 0.01,
    json_encode($slApp->events),
);

// B 栏（range 0..10 step 1 value 3）：证明"基值 = 受控值"且 step 真的被用上
$slElB = $slAppSliders[1] ?? null;
$slBoxB = $slElB?->effectiveBox();
[$slBx, $slBy] = $slBoxB === null ? [0.0, 0.0] : boxOrigin($slBoxB);
$slApp->dispatchInput(PointerEvent::down($slBx + $slPad, $slBy + $slWantH / 2.0));
$slApp->dispatchInput(PointerEvent::up($slBx + $slPad, $slBy + $slWantH / 2.0));
$slApp->events = [];
$slApp->dispatchInput(KeyEvent::down(KeyChord::right()));
check(
    'Slider · 键盘 →：step=1 的滑杆（受控值 3 ⇒ 4，走的是 step 不是 1%）',
    count($slApp->events) === 1 && abs($slApp->events[0][1] - 4.0) < 0.01,
    json_encode($slApp->events),
);

// 反例：禁用滑杆不吃方向键（键继续往下走，不会被吞掉）
$slApp->events = [];
$slApp->dispatchInput(PointerEvent::down($slCx + $slPad + 0.5 * $slInner, $slCy + $slWantH / 2.0));
$slApp->dispatchInput(PointerEvent::up($slCx + $slPad + 0.5 * $slInner, $slCy + $slWantH / 2.0));
$slApp->dispatchInput(KeyEvent::down(KeyChord::right()));
check(
    'Slider · 反例：禁用滑杆不吃方向键（keyboard 返回 false 让位）',
    $slApp->events === [],
    json_encode($slApp->events),
);

// 语义：role=slider、value = 读数
$slSem = SemanticsBuilder::build($semLay(
    Slider::make()->range(0.0, 100.0)->value(42.0)->label('音量')
));
$slSemIdx = $slSem->findByRoleAndName(SemanticsRole::Slider->value, '音量');
check(
    'Slider · 语义：role=slider、name=label、value=读数（42）',
    $slSemIdx >= 0 && $slSem->node($slSemIdx)->value === '42',
    $slSemIdx < 0 ? 'no slider node (count=' . $slSem->count() . ')' : 'value=' . $slSem->node($slSemIdx)->value,
);

// 语义区间（§4.4）：`range(0,100)` ⇒ 节点与 `toArrays` 都带 rangeMin/rangeMax。
// 反例自检：删 `SemanticsBuilder` 的 range 填充 / 删 `Slider` 的 `semanticsRange()`
// ⇒ 本条 FAIL（不是恒真断言）。
$slArr = $slSem->toArrays();
check(
    'Slider · 语义区间：range(0,100) ⇒ 节点与 toArrays 都带 rangeMin/rangeMax（§4.4）',
    $slSemIdx >= 0
        && $slSem->node($slSemIdx)->rangeMin === 0.0
        && $slSem->node($slSemIdx)->rangeMax === 100.0
        && ($slArr['nodes'][$slSemIdx]['rangeMin'] ?? -1.0) === 0.0
        && ($slArr['nodes'][$slSemIdx]['rangeMax'] ?? -1.0) === 100.0,
    $slSemIdx < 0
        ? 'no slider node'
        : 'min=' . $slSem->node($slSemIdx)->rangeMin . ' max=' . $slSem->node($slSemIdx)->rangeMax
            . ' arr=' . json_encode($slArr['nodes'][$slSemIdx] ?? null),
);

// 语义写值（§4.4 提交 2）：`setSemanticsValue` 转 `emit()`（夹区间 + 只在变化时回调）。
$slSetGot = null;
$slSetW = Slider::make()->range(0.0, 100.0)->value(42.0)->onChanged(function (float $v) use (&$slSetGot): void {
    $slSetGot = $v;
});
check(
    'Slider · setSemanticsValue(70) ⇒ onChanged(70)（RangeValuePattern 回写路径）',
    $slSetW->setSemanticsValue('70') === true && $slSetGot === 70.0,
    'got=' . var_export($slSetGot, true),
);
check(
    'Slider · setSemanticsValue(999) ⇒ emit 夹到 max 100',
    $slSetW->setSemanticsValue('999') === true && $slSetGot === 100.0,
    'got=' . var_export($slSetGot, true),
);
check(
    'Slider · setSemanticsValue("abc") ⇒ false（非数字，读屏据此知道没生效）',
    $slSetW->setSemanticsValue('abc') === false,
);

// ── W8-2 Tooltip（声明式 + 框架托管浮层，2026-10-23）──────────────────
// ★ 这一块同时钉住两件东西：① `Application::after()` 这条**通用延时**能力
//   （Tooltip / Snackbar 的共同燃料）；② Tooltip 的显示时序与落位。
//   ★ 反例自检有三条：`hitTest=true`（闪烁死循环）、悬停无提示控件（不该排期）、
//     未满 500ms（不该弹）。
$ttConstraints = BoxConstraints::loose(new Size(320.0, 400.0));
$ttApp = new TooltipApplication();
$ttApp->attach(new SurfaceProbe(), $ttConstraints);
$ttApp->root()?->effectiveBox()?->layout($ttConstraints);

$ttButtons = $ttApp->buttons();
$ttD = $ttButtons[0] ?? null;   // 被 a 的提示盖住
$ttA = $ttButtons[1] ?? null;   // 带提示（主判据）
$ttB = $ttButtons[2] ?? null;   // 带提示（相邻切换）
$ttC = $ttButtons[3] ?? null;   // 反例：无提示
$ttABox = $ttA?->effectiveBox();
$ttBBox = $ttB?->effectiveBox();
$ttCBox = $ttC?->effectiveBox();
[$ttAx, $ttAy] = $ttABox === null ? [0.0, 0.0] : boxOrigin($ttABox);
[$ttBx, $ttBy] = $ttBBox === null ? [0.0, 0.0] : boxOrigin($ttBBox);
[$ttCx, $ttCy] = $ttCBox === null ? [0.0, 0.0] : boxOrigin($ttCBox);
$ttAw = $ttABox?->size()?->width ?? 0.0;
$ttAh = $ttABox?->size()?->height ?? 0.0;
$ttBw = $ttBBox?->size()?->width ?? 0.0;
$ttBh = $ttBBox?->size()?->height ?? 0.0;
$ttCw = $ttCBox?->size()?->width ?? 0.0;
$ttCh = $ttCBox?->size()?->height ?? 0.0;

// —— ① 通用延时 API（`after()` / `cancelAfter()`）——
$ttTicks = [];
$ttApp->after(500, function () use (&$ttTicks): void {
    $ttTicks[] = 't500';
});
check(
    '延时 · 排期后 wantsFrame() 为真（否则帧时钟不叫醒 ⇒ 定时器永远不响）',
    $ttApp->wantsFrame(),
    'wantsFrame=false（帧时钟不会上膛）',
);
$ttApp->frame(400.0, $ttConstraints);
check('延时 · 未到点不触发', $ttTicks === [], 'ticks=' . implode(',', $ttTicks));
$ttApp->frame(600.0, $ttConstraints);
check('延时 · 到点触发一次', $ttTicks === ['t500'], 'ticks=' . implode(',', $ttTicks));
check(
    '延时 · 触发后 wantsFrame() 归假（空闲不空转）',
    !$ttApp->wantsFrame(),
    'wantsFrame=true（会每 15ms 空转）',
);

$ttCancel = 0;
$ttCancelId = $ttApp->after(100, function () use (&$ttCancel): void {
    $ttCancel++;
});
$ttApp->cancelAfter($ttCancelId);
$ttApp->frame(2000.0, $ttConstraints);
check('延时 · cancelAfter() 撤销后不触发（幂等）', $ttCancel === 0, 'cancel=' . $ttCancel);

$ttNest = [];
$ttApp->after(10, function () use ($ttApp, &$ttNest): void {
    $ttNest[] = 'outer';
    $ttApp->after(10, function () use (&$ttNest): void {
        $ttNest[] = 'inner';
    });
});
$ttApp->frame(2100.0, $ttConstraints);
check(
    '延时 · 回调里再 after() 不丢（先摘再调，遍历中不改进中的表）',
    $ttNest === ['outer'],
    'nest=' . implode(',', $ttNest),
);
$ttApp->frame(2200.0, $ttConstraints);
check(
    '延时 · 新排的回调下一帧照常触发',
    $ttNest === ['outer', 'inner'],
    'nest=' . implode(',', $ttNest),
);

// —— ② 悬停 500ms 才弹 ——
$ttApp->dispatchInput(PointerEvent::move($ttAx + $ttAw / 2.0, $ttAy + $ttAh / 2.0));
check(
    'Tooltip · 悬停不立即弹（500ms 未到）',
    $ttApp->overlayCount() === 0,
    'count=' . $ttApp->overlayCount(),
);
check('Tooltip · 排期后 wantsFrame() 为真', $ttApp->wantsFrame(), 'wantsFrame=false');

$ttApp->frame(2500.0, $ttConstraints);
check(
    'Tooltip · 反例：未满 500ms 走帧仍不弹',
    $ttApp->overlayCount() === 0,
    'count=' . $ttApp->overlayCount(),
);

$ttApp->frame(2800.0, $ttConstraints);
check(
    'Tooltip · 满 500ms ⇒ 弹出且文字是声明的那句',
    $ttApp->overlayCount() === 1 && $ttApp->tooltipText() === '保存当前文档',
    'count=' . $ttApp->overlayCount() . ' text=' . var_export($ttApp->tooltipText(), true),
);

// —— ②b 全清栈之后，相邻切换仍要等满 500ms ——
// ★ 这条盯 S1 第 8 步「E 碰 D」的那条不变量：`closeMenu()` 全清会顺手收掉
//   提示层，"已显示"状态位（`tooltipShown`）若不跟着复位，下一次悬停会
//   **跳过 500ms 立即弹** —— 而正常路径必须重新等满。
$ttApp->closeMenu();
check(
    'Tooltip · closeMenu() 全清后栈空（提示层被顺手收掉）',
    $ttApp->overlayCount() === 0,
    'count=' . $ttApp->overlayCount(),
);
$ttApp->dispatchInput(PointerEvent::move($ttBx + $ttBw / 2.0, $ttBy + $ttBh / 2.0));
$ttApp->frame(3100.0, $ttConstraints);
check(
    'Tooltip · 反例：全清后悬停 b 不立即弹（状态位已复位，重新等 500ms）',
    $ttApp->overlayCount() === 0,
    'count=' . $ttApp->overlayCount(),
);
$ttApp->frame(3700.0, $ttConstraints);
check(
    'Tooltip · 再满 500ms ⇒ b 的提示弹出（文字是 b 声明的那句）',
    $ttApp->overlayCount() === 1 && $ttApp->tooltipText() === '永久删除，不可恢复',
    'count=' . $ttApp->overlayCount() . ' text=' . var_export($ttApp->tooltipText(), true),
);
// 恢复现场：③ 段起的落位 / hitTest 用例读的都是 **a 的提示**（已显示时相邻
// 切换立即换内容 ⇒ move 回 a 即弹，不用再等 500ms）。
$ttApp->dispatchInput(PointerEvent::move($ttAx + $ttAw / 2.0, $ttAy + $ttAh / 2.0));
$ttApp->frame(4200.0, $ttConstraints);
check(
    'Tooltip · 悬停回 a ⇒ 现场恢复（后续落位用例读 a 的提示）',
    $ttApp->overlayCount() === 1 && $ttApp->tooltipText() === '保存当前文档',
    'count=' . $ttApp->overlayCount() . ' text=' . var_export($ttApp->tooltipText(), true),
);

// —— ③ 落位：水平居中于锚点 + 垂直在锚点上方（首选 ABOVE_CENTER）——
$ttTop = $ttApp->topEntry();
$ttPanelBox = $ttTop?->element?->effectiveBox();
$ttPanelW = ($ttPanelBox?->size()?->width ?? 0.0) - 8.0;    // elevation 4 × 2
$ttPanelH = ($ttPanelBox?->size()?->height ?? 0.0) - 8.0;
// ★ 面板必须有非零尺寸。2026-10-23 真机踩过：`BoxConstraints::INFINITY` 作为
//   **外部类常量引用**在 AOT 下求值成 0 ⇒ maxHeight=0 ⇒ 面板 w=h=0 ⇒
//   位置算得完全正确、画面一丝不变，而解释器侧（本文件）全绿。
//   这条断言在解释器下**抓不到那个 bug**（INFINITY 正常），但它把"面板必须有
//   尺寸"写成了显式契约；真正的闸门是 T2 真机一图 + `BoxConstraints::capWidth()`。
check(
    'Tooltip · 面板宽高非零（位置对但尺寸 0 = 画面一丝不变）',
    $ttPanelW > 0.0 && $ttPanelH > 0.0,
    'panelW=' . $ttPanelW . ' panelH=' . $ttPanelH,
);
$ttLeft = ($ttTop?->x ?? -999.0) + 4.0;
$ttTopY = ($ttTop?->y ?? -999.0) + 4.0;
// ★ 期望值 = **夹取后**的居中值（`placeEntry()` ⑤ 会把负的 x 夹到 0）。
//   不夹就写死 `ax + (aw−w)/2` 的话，锚点靠左时会误判成"没居中"——而实际是
//   "居中后出界，被夹回来了"，那正是设计意图（`01` §4.2 不做通用避让）。
$ttWantX = $ttAx + ($ttAw - $ttPanelW) / 2.0;
if ($ttWantX < 0.0) {
    $ttWantX = 0.0;
}
$ttMaxX = 320.0 - $ttPanelW;
if ($ttWantX > $ttMaxX) {
    $ttWantX = $ttMaxX > 0.0 ? $ttMaxX : 0.0;
}
check(
    'Tooltip · 水平居中于锚点（x = clamp(ax + (aw − w)/2, 0, maxW − w)）',
    abs($ttLeft - $ttWantX) < 0.51 && $ttAx > 50.0,
    'left=' . $ttLeft . ' expect=' . $ttWantX . ' ax=' . $ttAx . ' panelW=' . $ttPanelW,
);
// ★ 期望值按公式推：锚点上方放得下就上方，否则翻到下方（夹具顶部留了 120 白，
//   所以这里走的正是**上方**分支 —— 不留白就永远测不到首选方向）。
$ttWantY = $ttAy - $ttPanelH >= 0.0 ? $ttAy - $ttPanelH : $ttAy + $ttAh;
check(
    'Tooltip · 垂直落在锚点上方（ABOVE_CENTER，不是贴着窗口顶翻转）',
    abs($ttTopY - $ttWantY) < 0.51 && $ttAy - $ttPanelH >= 0.0,
    'y=' . $ttTopY . ' expect=' . $ttWantY . ' ay=' . $ttAy . ' panelH=' . $ttPanelH,
);
check(
    'Tooltip · 条目参数：不拦点击 / 不遮罩 / 不参与命中 / 限宽 320 / 不动焦点',
    $ttTop !== null
        && $ttTop->barrier === false
        && $ttTop->scrim === false
        && $ttTop->hitTest === false
        && $ttTop->dismissOnTapOutside === false
        && $ttTop->dismissOnEsc === false
        && abs($ttTop->maxWidth - TooltipPanel::MAX_WIDTH) < 0.01
        && $ttTop->restoreFocus === null,
    $ttTop === null ? 'no entry' : json_encode([
        'barrier' => $ttTop->barrier,
        'hitTest' => $ttTop->hitTest,
        'tapOutside' => $ttTop->dismissOnTapOutside,
        'maxWidth' => $ttTop->maxWidth,
        'restoreFocus' => $ttTop->restoreFocus === null ? 'null' : 'set',
    ]),
);

// —— ④ hitTest=false：提示盖在 d 上时，点击穿透到 d（提示不吃点击）——
$ttPanelCx = $ttLeft + $ttPanelW / 2.0;
$ttPanelCy = $ttTopY + $ttPanelH / 2.0;
// 前提：面板真的盖住了 d 的盒（否则这条判据测的是空气）
$ttDBox = $ttD?->effectiveBox();
[$ttDx, $ttDy] = $ttDBox === null ? [0.0, 0.0] : boxOrigin($ttDBox);
$ttDw = $ttDBox?->size()?->width ?? 0.0;
$ttDh = $ttDBox?->size()?->height ?? 0.0;
$ttCovers = $ttPanelCx >= $ttDx && $ttPanelCx <= $ttDx + $ttDw
    && $ttPanelCy >= $ttDy && $ttPanelCy <= $ttDy + $ttDh;

$ttApp->seen = [];
$ttApp->dispatchInput(PointerEvent::down($ttPanelCx, $ttPanelCy));
check(
    'Tooltip · hitTest=false ⇒ 提示盖在 d 上时点击穿透到 d（提示不吃点击）',
    $ttCovers && $ttApp->seen === ['d'],
    'covers=' . ($ttCovers ? 'yes' : 'no')
        . ' seen=' . implode(',', $ttApp->seen)
        . ' panel=(' . $ttPanelCx . ',' . $ttPanelCy . ')'
        . ' d=(' . $ttDx . ',' . $ttDy . ',' . $ttDw . ',' . $ttDh . ')',
);
$ttApp->dispatchInput(PointerEvent::up($ttPanelCx, $ttPanelCy));

// ★ 反例自检：hitTest=true ⇒ 面板自己吃掉这次 down（下面的 d 收不到）。
//   这一条是"为什么必须有 hitTest 字段"的证据，不是补充说明。
$ttTop2 = $ttApp->topEntry();
if ($ttTop2 !== null) {
    $ttTop2->hitTest = true;
}
$ttApp->seen = [];
$ttApp->dispatchInput(PointerEvent::down($ttPanelCx, $ttPanelCy));
check(
    'Tooltip · 反例：hitTest=true ⇒ 提示吃掉点击（d 收不到）—— 这就是必须有这个字段的理由',
    $ttApp->seen === [],
    'seen=' . implode(',', $ttApp->seen),
);
$ttApp->dispatchInput(PointerEvent::up($ttPanelCx, $ttPanelCy));

// —— ⑤ 离开 ⇒ 立即消失；回来 ⇒ 重新等 500ms ——
// ★ 顺序很重要：**先离开再回来**。不离开的话 hover 目标没变 ⇒ `onHoverHit()`
//   根本不会被调 ⇒ "重新弹出"会变成"它本来就没消失"的假通过。
$ttApp->dispatchInput(PointerEvent::move($ttCx + $ttCw / 2.0, $ttCy + $ttCh / 2.0));
check(
    'Tooltip · 离开锚点 ⇒ 立即消失（不等延时）',
    $ttApp->overlayCount() === 0,
    'count=' . $ttApp->overlayCount(),
);
// ★ 先把时间基准对齐到 3000：`after()` 的基准是 **`lastTimestampMs`**（上一帧），
//   在帧外调用时它可能已经落后很久 ⇒ 不对齐的话 due 会落在过去、定时器"提前到期"。
//   ★ 对齐必须**在 move 之前**（`after()` 在 move 那一刻就调用了，due 当场定死）。
//   对齐后 due = 3000 + 500 = 3500 ⇒ 下面 3400 / 3600 是**推出来的**，不是试出来的。
$ttApp->frame(3000.0, $ttConstraints);
$ttApp->dispatchInput(PointerEvent::move($ttAx + $ttAw / 2.0, $ttAy + $ttAh / 2.0));
$ttApp->frame(3400.0, $ttConstraints);
check(
    'Tooltip · 离开再回来：未满 500ms 不弹（延时是真的在重新计时）',
    $ttApp->overlayCount() === 0,
    'count=' . $ttApp->overlayCount() . '（due=3500，此刻 3400）',
);
$ttApp->frame(3600.0, $ttConstraints);
check(
    'Tooltip · 离开再回来、满 500ms ⇒ 再次弹出',
    $ttApp->overlayCount() === 1 && $ttApp->tooltipText() === '保存当前文档',
    'count=' . $ttApp->overlayCount() . ' text=' . var_export($ttApp->tooltipText(), true),
);

// —— ⑥ 反例：悬停**无提示**的控件 ⇒ 不排期、不弹 ——
$ttApp->dispatchInput(PointerEvent::move($ttCx + $ttCw / 2.0, $ttCy + $ttCh / 2.0));
check(
    'Tooltip · 移到无提示控件 ⇒ 已显示的提示立刻消失',
    $ttApp->overlayCount() === 0,
    'count=' . $ttApp->overlayCount(),
);
$ttApp->frame(4600.0, $ttConstraints);
// ★ 这条原本判 `!wantsFrame()` —— 那是"没排期"的**代理判据**。悬停过渡（盒级
//   `AnimatedValue`）上线后，指针刚移过来就有几百毫秒的合法动画在跑 ⇒ 代理判据
//   失效。改成直接判本意：**时间过了也不弹**（真排了期的话 900ms 早该弹了）。
check(
    'Tooltip · 反例：悬停无 tooltip() 的按钮 ⇒ 走满 500ms 也不弹',
    $ttApp->overlayCount() === 0,
    'count=' . $ttApp->overlayCount(),
);

// —— ⑦ 已显示时移到相邻带提示的控件 ⇒ 立即换（不再等 500ms）——
$ttApp->dispatchInput(PointerEvent::move($ttAx + $ttAw / 2.0, $ttAy + $ttAh / 2.0));
$ttApp->frame(4700.0, $ttConstraints);
$ttApp->frame(5300.0, $ttConstraints);
$ttApp->dispatchInput(PointerEvent::move($ttBx + $ttBw / 2.0, $ttBy + $ttBh / 2.0));
$ttApp->frame(5400.0, $ttConstraints);   // 距上次显示只有 100ms < 500ms
check(
    'Tooltip · 已显示时移到相邻带提示的按钮 ⇒ 立即换内容（100ms < 500ms 也换）',
    $ttApp->overlayCount() === 1 && $ttApp->tooltipText() === '永久删除，不可恢复',
    'count=' . $ttApp->overlayCount() . ' text=' . var_export($ttApp->tooltipText(), true),
);

// ── W8-2 Snackbar（底部通知 + FIFO 队列 + `after()` 定时，2026-10-23）──
// ★ 这一块同时钉住三件事：① 落位（底部居中 + 贴底留白）；② 队列语义（第一条立即、
//   后面排队、队首到点后上位）；③ `duration=0` = 永不自动消失。
// ★ 反例自检四条：未到点不消失 / duration=0 跑满仍在 / 无 action() 就没按钮 /
//   `hitTest` 与 Tooltip **相反**（这里是 true，动作按钮要能点）。
$sbConstraints = BoxConstraints::loose(new Size(600.0, 400.0));
$sbApp = new SnackbarApplication();
$sbApp->attach(new SurfaceProbe(), $sbConstraints);
$sbApp->root()?->effectiveBox()?->layout($sbConstraints);

// —— ① 第一条立即显示 + 落位公式 ——
// ★ 先跑一帧对齐 `after()` 的时间基准（它读 `lastTimestampMs`），再推 —— 否则
//   due 会落在过去、定时器"提前到期"（Tooltip 块踩过同一个坑）。
$sbApp->frame(1000.0, $sbConstraints);
$sbApp->push(Snackbar::make('已保存'));   // due = 1000 + 4000 = 5000
check(
    'Snackbar · 第一条立即显示（不等队列）',
    $sbApp->hasSnackbar() && $sbApp->snackbarQueueLength() === 0,
    'has=' . ($sbApp->hasSnackbar() ? 'yes' : 'no') . ' queue=' . $sbApp->snackbarQueueLength(),
);

$sbApp->frame(1100.0, $sbConstraints);   // 落位（placeOverlays 在这一帧跑）
$sbEntry = $sbApp->snackbarEntry();
$sbBox = $sbEntry?->element?->effectiveBox();
$sbPanelW = ($sbBox?->size()?->width ?? 0.0) - 12.0;    // elevation 6 × 2
$sbPanelH = ($sbBox?->size()?->height ?? 0.0) - 12.0;
check(
    'Snackbar · 文字是声明的那句',
    $sbApp->snackText() === '已保存',
    'text=' . var_export($sbApp->snackText(), true),
);
check(
    'Snackbar · 面板宽高非零（位置对但尺寸 0 = 画面一丝不变）',
    $sbPanelW > 0.0 && $sbPanelH > 0.0,
    'panelW=' . $sbPanelW . ' panelH=' . $sbPanelH,
);

$sbLeft = ($sbEntry?->x ?? -999.0) + 6.0;
$sbTop = ($sbEntry?->y ?? -999.0) + 6.0;
check(
    'Snackbar · 水平居中（x = (maxW − w)/2）',
    abs($sbLeft - (600.0 - $sbPanelW) / 2.0) < 0.51,
    'left=' . $sbLeft . ' expect=' . ((600.0 - $sbPanelW) / 2.0) . ' panelW=' . $sbPanelW,
);
check(
    'Snackbar · 贴底留白（y = maxH − h − Snackbar::MARGIN）',
    abs($sbTop - (400.0 - $sbPanelH - Snackbar::MARGIN)) < 0.51,
    'top=' . $sbTop . ' expect=' . (400.0 - $sbPanelH - Snackbar::MARGIN) . ' panelH=' . $sbPanelH,
);
check(
    'Snackbar · 条目参数：hitTest=true（动作按钮要能点，与 Tooltip 相反）/ 不拦层外 / 不动焦点',
    $sbEntry !== null
        && $sbEntry->hitTest === true
        && $sbEntry->barrier === false
        && $sbEntry->scrim === false
        && $sbEntry->dismissOnTapOutside === false
        && $sbEntry->dismissOnEsc === false
        && $sbEntry->prefer === OverlayEntry::BOTTOM_CENTER
        && abs($sbEntry->maxWidth - Snackbar::MAX_WIDTH) < 0.01
        && $sbEntry->restoreFocus === null,
    $sbEntry === null ? 'no entry' : json_encode([
        'hitTest' => $sbEntry->hitTest,
        'barrier' => $sbEntry->barrier,
        'prefer' => $sbEntry->prefer,
        'maxWidth' => $sbEntry->maxWidth,
        'restoreFocus' => $sbEntry->restoreFocus === null ? 'null' : 'set',
    ]),
);

// —— ② 定时消失 ——
$sbApp->frame(2000.0, $sbConstraints);
check(
    'Snackbar · 反例：未到点不消失',
    $sbApp->hasSnackbar(),
    'has=' . ($sbApp->hasSnackbar() ? 'yes' : 'no') . '（due=5000，此刻 2000）',
);
$sbApp->frame(5100.0, $sbConstraints);
check(
    'Snackbar · 到点自动消失',
    !$sbApp->hasSnackbar(),
    'has=' . ($sbApp->hasSnackbar() ? 'yes' : 'no') . '（due=5000，此刻 5100）',
);

// —— ③ 队列 FIFO ——
$sbApp->frame(6000.0, $sbConstraints);
$sbApp->push(Snackbar::make('第一条'));   // due = 10000
$sbApp->push(Snackbar::make('第二条'));   // 入队
check(
    'Snackbar · 第二条进队列（不抢当前这条）',
    $sbApp->hasSnackbar() && $sbApp->snackbarQueueLength() === 1 && $sbApp->snackText() === '第一条',
    'queue=' . $sbApp->snackbarQueueLength() . ' text=' . var_export($sbApp->snackText(), true),
);
$sbApp->frame(10100.0, $sbConstraints);
check(
    'Snackbar · 第一条到点 ⇒ 队首立刻上位（FIFO）',
    $sbApp->hasSnackbar() && $sbApp->snackbarQueueLength() === 0 && $sbApp->snackText() === '第二条',
    'queue=' . $sbApp->snackbarQueueLength() . ' text=' . var_export($sbApp->snackText(), true),
);

// —— ④ duration=0 = 永不自动消失 ——
$sbApp->dismissSnackbar();
check(
    'Snackbar · dismiss 之后没有通知、队列也空',
    !$sbApp->hasSnackbar() && $sbApp->snackbarQueueLength() === 0,
    'has=' . ($sbApp->hasSnackbar() ? 'yes' : 'no'),
);
$sbApp->frame(11000.0, $sbConstraints);
$sbApp->push(Snackbar::make('错误：保存失败')->duration(0));
check(
    'Snackbar · duration=0 ⇒ 不排定时器（wantsFrame 为假 = 不会自己消失）',
    $sbApp->hasSnackbar() && !$sbApp->wantsFrame(),
    'has=' . ($sbApp->hasSnackbar() ? 'yes' : 'no') . ' wantsFrame=' . ($sbApp->wantsFrame() ? 'true' : 'false'),
);
$sbApp->frame(999999.0, $sbConstraints);
check(
    'Snackbar · 反例：duration=0 跑满任意时长仍在',
    $sbApp->hasSnackbar() && $sbApp->snackText() === '错误：保存失败',
    'has=' . ($sbApp->hasSnackbar() ? 'yes' : 'no'),
);

// —— ⑤ 动作按钮 ——
$sbApp->dismissSnackbar();
$sbApp->frame(1000000.0, $sbConstraints);
$sbHits = 0;
$sbApp->push(
    Snackbar::make('已删除')->duration(0)->action('撤销', function () use (&$sbHits): void {
        $sbHits++;
    })
);
$sbApp->frame(1000100.0, $sbConstraints);   // 落位
check(
    'Snackbar · action() ⇒ 面板里有动作按钮',
    $sbApp->actionButton() !== null,
    $sbApp->actionButton() === null ? 'no button' : 'ok',
);

$sbBtn = $sbApp->actionButton();
$sbBtnBox = $sbBtn?->effectiveBox();
[$sbBx, $sbBy] = $sbBtnBox === null ? [0.0, 0.0] : boxOrigin($sbBtnBox);
$sbBw = $sbBtnBox?->size()?->width ?? 0.0;
$sbBh = $sbBtnBox?->size()?->height ?? 0.0;
$sbApp->dispatchInput(PointerEvent::down($sbBx + $sbBw / 2.0, $sbBy + $sbBh / 2.0));
$sbApp->dispatchInput(PointerEvent::up($sbBx + $sbBw / 2.0, $sbBy + $sbBh / 2.0));
check(
    'Snackbar · 点动作按钮 ⇒ 调 handler 且收掉这条',
    $sbHits === 1 && !$sbApp->hasSnackbar(),
    'hits=' . $sbHits . ' has=' . ($sbApp->hasSnackbar() ? 'yes' : 'no'),
);

$sbApp->frame(1000200.0, $sbConstraints);
$sbApp->push(Snackbar::make('无动作')->duration(0));
$sbApp->frame(1000300.0, $sbConstraints);
check(
    'Snackbar · 反例：没有 action() 就没有动作按钮',
    $sbApp->actionButton() === null,
    $sbApp->actionButton() === null ? 'ok' : 'unexpected button',
);

// —— ⑥ 幂等 ——
$sbApp->dismissSnackbar();
$sbApp->dismissSnackbar();
check(
    'Snackbar · dismiss 幂等（空队列再调无事发生）',
    !$sbApp->hasSnackbar() && $sbApp->snackbarQueueLength() === 0,
    'has=' . ($sbApp->hasSnackbar() ? 'yes' : 'no'),
);

// ── W8-2 选项表形态（`Option::normalize()`，2026-10-24）──
// ★ 钉住两件事：① 映射形态的**键类型收口**（PHP 把 `'110000'` 转成 int ⇒ 必须
//   转回 string，否则 `value()` 永远比不中、静默显示 placeholder）；② list 形态
//   **只收 Option**（`[0 => 'x']` 与 `['x']` 在 PHP 里是**同一个数组** ⇒ "值 0"与
//   "标签列表"在语言层面无法区分 ⇒ 裸值报错丢弃，不猜）。

check('Option · 空表 ⇒ 空（不炸）', Option::normalize([]) === []);

$normObjs = [Option::make('北京市', '110000')];
check('Option · list<Option> 原样通过（实例身份不变）', Option::normalize($normObjs) === $normObjs);

$normMap = Option::normalize(['110000' => '北京市', '01' => '某地']);
check(
    'Option · 映射形态：键 = 值、值 = 显示名',
    count($normMap) === 2
        && $normMap[0]->label === '北京市' && $normMap[0]->value === '110000'
        && $normMap[1]->value === '01',
    'n=' . count($normMap)
);
// ★★ 这条是反向自检的对象：把 `(string) $key` 去掉 ⇒ 键是 int 装不进 string 值域
//   （TypeError ⇒ 整个 smoke 崩），而 `['110000' => ...]` 是最常见的城市码写法。
check(
    'Option · ★ 数字键被 PHP 转成 int ⇒ 必须收口回 string（否则这种写法根本装不进去）',
    $normMap[0]->value === '110000',
    'value=' . var_export($normMap[0]->value, true)
);
check(
    'Select · 映射形态直接喂 ⇒ 受控值能命中（键类型收口的端到端证据）',
    Select::make(['110000' => '北京市'])->value('110000')->selectedIndex() === 0,
    'idx=' . Select::make(['110000' => '北京市'])->value('110000')->selectedIndex()
);
$normCombo = ComboBox::make(['110000' => '北京市'])->optionsValue();
check(
    'ComboBox · 映射形态直接喂 ⇒ 选项表同样收口（与 Select 走同一处）',
    count($normCombo) === 1 && $normCombo[0]->value === '110000',
    'value=' . var_export($normCombo[0]->value ?? null, true)
);

// ── `Option::fromRows()`：DB / HTTP 的行数组直接组装 ──
$normRows = [
    ['id' => 1, 'name' => '北京市'],
    ['id' => 2, 'name' => '上海市'],
];
$normFrom = Option::fromRows($normRows, 'name', 'id');
check(
    'Option · fromRows：行数组 ⇒ 选项表（label 取 name、value 取 id）',
    count($normFrom) === 2 && $normFrom[0]->label === '北京市' && $normFrom[1]->value === '2',
    'n=' . count($normFrom)
);
check(
    'Option · fromRows：数字 id 转成 string（与映射形态同一条收口）',
    $normFrom[0]->value === '1',
    'value=' . var_export($normFrom[0]->value ?? null, true)
);
check(
    'Select · fromRows 直接喂 ⇒ 受控值能命中',
    Select::make(Option::fromRows($normRows, 'name', 'id'))->value('2')->selectedIndex() === 1
);

// 反例：list 里的裸值 ⇒ Fatal（身份不明确，猜就是替应用做决定）
fresh($log);
[$codeBare, $outBare] = runCase(__FILE__, 'optbare', null);
check(
    'Option · 反例：list 里的裸值 ⇒ Fatal（不猜身份）',
    $codeBare === 3,
    '实际 ' . $codeBare . ' / ' . $outBare
);
check(
    'Option · 反例：裸值 Fatal 时给出两种正确写法',
    str_contains(logText($log), 'list 形态只收 Option') && str_contains(logText($log), 'Option::of')
);

// 反例：空字符串键 ⇒ Fatal（"没选"与"选了空"不能混）
fresh($log);
[$codeKey, $outKey] = runCase(__FILE__, 'optemptykey', null);
check(
    'Option · 反例：空字符串键 ⇒ Fatal（不静默当一个真选项）',
    $codeKey === 3,
    '实际 ' . $codeKey . ' / ' . $outKey
);
check('Option · 反例：空键 Fatal 时说明了哨兵冲突', str_contains(logText($log), '未选'));

// 反例：行里缺字段 ⇒ Fatal 并报出「哪一行、缺哪个字段、现有哪几列」
//   （WPF `DisplayMemberPath` / antd `fieldNames` 拼错是**静默**的，这里不静默）
fresh($log);
[$codeNoField, $outNoField] = runCase(__FILE__, 'optnofield', null);
check(
    'Option · 反例：fromRows 缺字段 ⇒ Fatal（不静默变成整列空白）',
    $codeNoField === 3,
    '实际 ' . $codeNoField . ' / ' . $outNoField
);
check(
    'Option · 反例：缺字段时报出字段名与现有列',
    str_contains(logText($log), '没有显示名列') && str_contains(logText($log), 'id')
);

// 反例：行不是数组 ⇒ Fatal
fresh($log);
[$codeBadRow, $outBadRow] = runCase(__FILE__, 'optbadrow', null);
check(
    'Option · 反例：fromRows 的行不是数组 ⇒ Fatal',
    $codeBadRow === 3,
    '实际 ' . $codeBadRow . ' / ' . $outBadRow
);

// ── W8-2 Select（下拉选择 + 声明式浮层面板，2026-10-23）──
// ★ 这一块钉住四件事：① 激活 ⇒ 框架开面板（`Widget::overlayPanel()` 的通用缝）；
//   ② 面板参数（barrier / owner / restoreFocus 三处最容易搞反的）；③ 点选项 ⇒
//   回调 + 收面板 + 焦点回控件；④ **不影响既有控件**（没声明浮层的按钮照旧 onPressed）。
// ★ 反例自检三条：值不匹配 ⇒ 不选（不夹到第一项）/ 点面板外只关不开 / host 按钮
//   没有被新分支吃掉。
$selConstraints = BoxConstraints::loose(new Size(600.0, 400.0));
$selApp = new SelectApplication();
$selApp->attach(new SurfaceProbe(), $selConstraints);
$selApp->root()?->effectiveBox()?->layout($selConstraints);
$selApp->frame(1000.0, $selConstraints);

// —— ⓪ 纯函数：受控值 → 下标（不需要应用）——
check(
    'Select · 受控值匹配 ⇒ selectedIndex 命中那一项',
    Select::make([Option::of('a'), Option::of('b')])->value('b')->selectedIndex() === 1,
    'idx=' . Select::make([Option::of('a'), Option::of('b')])->value('b')->selectedIndex(),
);
// ★ 反例：不匹配 ⇒ -1（**不夹到 0**）—— 静默选中第一项是替用户做决定
//   （与 `SegmentedControl` 的"越界选中 ⇒ 全不选"同一条纪律）。
check(
    'Select · 反例：值不匹配 ⇒ selectedIndex = -1（显示 placeholder，不夹到第一项）',
    Select::make([Option::of('a'), Option::of('b')])->value('nope')->selectedIndex() === -1,
    'idx=' . Select::make([Option::of('a'), Option::of('b')])->value('nope')->selectedIndex(),
);

check(
    'Select · 初始没有浮层',
    $selApp->overlayCount() === 0,
    'count=' . $selApp->overlayCount(),
);

// —— ① 激活（真实指针链）⇒ 框架开面板 ——
$selTc = $selApp->triggerCenter();
$selApp->dispatchInput(PointerEvent::down($selTc[0] ?? 0.0, $selTc[1] ?? 0.0));
$selApp->frame(1100.0, $selConstraints);

check(
    'Select · 激活触发框 ⇒ 开了一层浮层（Widget::overlayPanel() 的通用缝）',
    $selApp->overlayCount() === 1,
    'count=' . $selApp->overlayCount() . ' trigger=' . var_export($selTc, true),
);

$selTop = $selApp->topEntry();
$selBox = $selTop?->element?->effectiveBox();
$selSize = $selBox?->size();
$selW = $selSize?->width ?? 0.0;
$selH = $selSize?->height ?? 0.0;

// ★★ 尺寸必须单独断言：Tooltip 那次"位置完全正确、尺寸 0"（AOT 外部类常量求值成 0）
//   就是这样漏过去的 —— 位置对不代表画得出来。
check(
    'Select · 面板宽高非零（位置对但尺寸 0 = 画面一丝不变）',
    $selW > 0.0 && $selH > 0.0,
    'size=' . $selW . 'x' . $selH,
);

check(
    'Select · 面板宽 ≥ PANEL_MIN_WIDTH（一列选项不能挤成一条）',
    $selW >= Select::PANEL_MIN_WIDTH,
    'w=' . $selW . ' min=' . Select::PANEL_MIN_WIDTH,
);

$selTrigger = $selApp->trigger();
check(
    'Select · 面板参数：锚点=控件 / owner=控件（页面走了自动收尸）/ barrier=true（点外面才关得掉）'
        . ' / scrim=false / hitTest=true / dismissOnTapOutside / dismissOnEsc / 不抢焦点',
    $selTop !== null
        && $selTop->anchor === $selTrigger
        && $selTop->owner === $selTrigger
        && $selTop->barrier === true
        && $selTop->scrim === false
        && $selTop->hitTest === true
        && $selTop->dismissOnTapOutside === true
        && $selTop->dismissOnEsc === true
        && $selTop->focusTrap === false,
    'anchor=' . ($selTop?->anchor === $selTrigger ? 'yes' : 'no')
        . ' owner=' . ($selTop?->owner === $selTrigger ? 'yes' : 'no')
        . ' barrier=' . ($selTop?->barrier ? '1' : '0')
        . ' hitTest=' . ($selTop?->hitTest ? '1' : '0'),
);

// ★★ 与 Tooltip / Snackbar **相反**：那两个打开时焦点不在锚点上（悬停 / 定时器触发），
//   所以必须把 `restoreFocus` 抹成 null；这里是控件自己激活 ⇒ 快照天然正确，
//   收面板时焦点要回到控件上（否则选完一项焦点就丢了）。
check(
    'Select · restoreFocus = 触发框（选完焦点回到控件，与 Tooltip/Snackbar 相反）',
    $selTop?->restoreFocus === $selTrigger,
    'restore=' . ($selTop?->restoreFocus === $selTrigger ? 'yes' : 'no'),
);

// —— ② 面板内容：三项 + 选中项用 Primary 底（不用 "✓" 前缀，字模子集没有 U+2713）——
$selOptions = $selApp->optionButtons();
check(
    'Select · 面板里恰好 3 个选项按钮',
    \count($selOptions) === 3,
    'n=' . \count($selOptions),
);

$selVariants = [];
foreach ($selOptions as $selOpt) {
    $selW2 = $selOpt->widget();
    $selVariants[] = $selW2 instanceof \Drift\Widget\Button
        ? $selW2->variantValue()->name
        : '?';
}
check(
    'Select · 选中项（上海市）是 Primary、其余是 Flat',
    $selVariants === ['Flat', 'Primary', 'Flat'],
    'variants=' . implode(',', $selVariants),
);

// —— ③ 点第 1 个选项（北京市）⇒ 回调 + 收面板 + 焦点回控件 ——
$selC0 = $selApp->optionCenter(0);
$selApp->dispatchInput(PointerEvent::down($selC0[0] ?? 0.0, $selC0[1] ?? 0.0));
$selApp->frame(1200.0, $selConstraints);
check(
    'Select · 点选项 ⇒ onChanged 收到那一项的 value（不是 label、不是下标）',
    $selApp->changed === ['110000'],
    'changed=' . implode(',', $selApp->changed),
);
check(
    'Select · 点选项 ⇒ 面板自己收掉（dismiss 由 Application 注入）',
    $selApp->overlayCount() === 0,
    'count=' . $selApp->overlayCount(),
);
check(
    'Select · 点选项 ⇒ 焦点回到触发框（不是"焦点丢了"）',
    $selApp->focusedElement() === $selTrigger,
    'focused=' . ($selApp->focusedElement() instanceof \Drift\Widget\Button ? 'button' : 'other'),
);

// —— ④ 再开一次 ⇒ 点面板外 ⇒ 关且不开新的（`barrier` 吞掉那次按下）——
$selApp->dispatchInput(PointerEvent::down($selTc[0] ?? 0.0, $selTc[1] ?? 0.0));
$selApp->frame(1300.0, $selConstraints);
$selHost = $selApp->host();
$selHBox = $selHost?->effectiveBox();
[$selHx, $selHy] = $selHBox === null
    ? [0.0, 0.0]
    : \Drift\Layout\RenderBox::absoluteOrigin($selHBox);
$selHw = $selHBox?->size()?->width ?? 0.0;
$selHh = $selHBox?->size()?->height ?? 0.0;

$selApp->hostPressed = [];
$selApp->dispatchInput(PointerEvent::down($selHx + $selHw / 2.0, $selHy + $selHh / 2.0));
$selApp->frame(1400.0, $selConstraints);
check(
    'Select · 点面板外 ⇒ 关面板，且那次按下被吞（host 收不到 —— 下拉的标准手感）',
    $selApp->overlayCount() === 0 && $selApp->hostPressed === [],
    'count=' . $selApp->overlayCount() . ' host=' . implode(',', $selApp->hostPressed),
);

// —— ⑤ ESC ⇒ 关 ——
$selApp->dispatchInput(PointerEvent::down($selTc[0] ?? 0.0, $selTc[1] ?? 0.0));
$selApp->frame(1500.0, $selConstraints);
$selOpenBefore = $selApp->overlayCount();
$selApp->dispatchInput(KeyEvent::down(KeyChord::escape()));
$selApp->frame(1600.0, $selConstraints);
check(
    'Select · ESC ⇒ 关面板（dismissOnEsc）',
    $selOpenBefore === 1 && $selApp->overlayCount() === 0,
    'before=' . $selOpenBefore . ' after=' . $selApp->overlayCount(),
);

// —— ⑥ 反例：没声明浮层的按钮 ⇒ 激活照旧走 onPressed（新分支是加性的）——
$selApp->hostPressed = [];
$selApp->dispatchInput(PointerEvent::down($selHx + $selHw / 2.0, $selHy + $selHh / 2.0));
$selApp->frame(1700.0, $selConstraints);
check(
    'Select · 反例：没有 overlayPanel() 的按钮 ⇒ 激活照旧走 onPressed（新分支加性）',
    $selApp->hostPressed === ['host'] && $selApp->overlayCount() === 0,
    'host=' . implode(',', $selApp->hostPressed) . ' count=' . $selApp->overlayCount(),
);

// —— ⑦ `PopupPanel::maxHeight()`：长列表被限高 + 真的能滚（2026-10-24）——
// ★ 为什么必须有：不限高时 30 项的面板被 `placeEntry()` 的"超大退化"夹成**整个客户区**
//   那么高，超出部分被面板自己的 `Overflow::Clip` 裁掉 ⇒ 第 N 项之后**点不到**；而
//   ⑤ 的夹紧只挪 x/y 不改高，那条兜底救不了"点不到"。
// ★ 判据**不按条目数猜**：`RenderPopupPanel` 先无界排一遍量自然高，超过上限才按上限
//   重排（内容变成可滚视口），否则按自然高贴着 ⇒ ⑦（限高可滚）与 ⑧（贴着内容）
//   两条必须同时钉，只有一条会漏掉另一半。
$selLTc = $selApp->longTriggerCenter();
$selApp->dispatchInput(PointerEvent::down($selLTc[0] ?? 0.0, $selLTc[1] ?? 0.0));
$selApp->frame(1800.0, $selConstraints);
check(
    'Select · 长列表（30 项）⇒ 激活即开面板',
    $selApp->overlayCount() === 1,
    'count=' . $selApp->overlayCount(),
);

$selLBox = $selApp->topEntry()?->element?->effectiveBox();
$selLH = $selLBox?->size()?->height ?? 0.0;
check(
    'Select · 长列表面板高 = PANEL_MAX_HEIGHT（限高生效）',
    abs($selLH - Select::PANEL_MAX_HEIGHT) < 0.01,
    'h=' . $selLH . ' cap=' . Select::PANEL_MAX_HEIGHT,
);

// 反向自检：自然高（30 行，chrome 只增不减）必须**远超**上限 —— 否则这条用例根本没在
// 验"限高"，只是在验一个本来就够短的面板（量具只会绿的那种）。
$selLRow = $selApp->optionButtons()[0]?->effectiveBox()?->size()?->height ?? 0.0;
check(
    'Select · 反例：自然高（30 行）远超上限 ⇒ 这条用例真的在验限高',
    30.0 * $selLRow > $selLH && $selLRow > 0.0,
    'natural=' . (30.0 * $selLRow) . ' actual=' . $selLH,
);

$selLSv = $selApp->panelScrollView();
$selLView = $selLSv?->size()?->height ?? 0.0;
$selLMax = $selLSv?->maxScroll(Axis::Vertical) ?? -1.0;
check(
    'Select · 长列表面板里有 ScrollView，视口比盒矮（chrome 扣掉了）且 maxScroll > 0',
    $selLSv !== null && $selLView > 0.0 && $selLView < $selLH && $selLMax > 0.0,
    'view=' . $selLView . ' box=' . $selLH . ' maxScroll=' . $selLMax,
);

// 可滚距离 = 内容高 − 视口高（**推导**，不是抄实测值：行高与项数都从盒里读）
check(
    'Select · 长列表面板 maxScroll = 30 行内容高 − 视口高（滚得到底）',
    abs($selLMax - (30.0 * $selLRow - $selLView)) < 0.01,
    'maxScroll=' . $selLMax . ' expect=' . (30.0 * $selLRow - $selLView),
);

// ★ "为什么不限高就点不到"的**实证**：第 30 项的中心在面板下沿之外 ⇒ 滚之前摸不到它
$selLLast = $selApp->optionCenter(29);
[$selLPx, $selLPy] = $selLBox === null ? [0.0, 0.0] : \Drift\Layout\RenderBox::absoluteOrigin($selLBox);
$selLBottom = $selLPy + $selLH;
check(
    'Select · 第 30 项在面板下沿之外 ⇒ 不限高/不滚就点不到它（限高的动机）',
    $selLLast !== null && $selLLast[1] > $selLBottom,
    'last=' . ($selLLast[1] ?? -1.0) . ' panelBottom=' . $selLBottom,
);

// ★★ 端到端：滚一格（`WHEEL_NOTCH_PX` = 48px）⇒ 第 0 项真的上移 48px。
//   走真实 `ScrollEvent` 通道（命中 → 找最近 ScrollViewState → scrollBy），不直接改 state。
$selLBefore = $selApp->optionCenter(0);
$selLCx = $selLPx + ($selLBox?->size()?->width ?? 0.0) / 2.0;
$selLCy = $selLPy + $selLH / 2.0;
$selApp->dispatchInput(ScrollEvent::make($selLCx, $selLCy, 1.0));
$selApp->frame(1900.0, $selConstraints);
$selLAfter = $selApp->optionCenter(0);
check(
    'Select · 长列表面板滚一格 ⇒ 第 0 项上移 48px（WHEEL_NOTCH_PX，真实滚轮通道）',
    $selLBefore !== null && $selLAfter !== null
        && abs(($selLBefore[1] - $selLAfter[1]) - 48.0) < 0.01,
    'dy=' . (($selLBefore[1] ?? 0.0) - ($selLAfter[1] ?? 0.0)),
);

// ★ 滚完面板还开着、还在限高（滚动不该把面板关掉或撑开）
check(
    'Select · 滚完之后面板仍在、高度仍是上限',
    $selApp->overlayCount() === 1
        && abs(($selApp->topEntry()?->element?->effectiveBox()?->size()?->height ?? 0.0) - Select::PANEL_MAX_HEIGHT) < 0.01,
    'count=' . $selApp->overlayCount(),
);

// —— ⑧ 反例：短列表（3 项）⇒ 面板**贴着内容**，不凭空撑到上限 ——
// ★ 这一条是限高的反面：若 `RenderPopupPanel` 无脑按上限排，3 项的面板会拖出一截
//   空白（"明明只有 3 项却有 260px 高的面板"）。判定用**实测 chrome**，不写死数字。
$selApp->dispatchInput(KeyEvent::down(KeyChord::escape()));
$selApp->frame(1950.0, $selConstraints);
$selApp->dispatchInput(PointerEvent::down($selTc[0] ?? 0.0, $selTc[1] ?? 0.0));
$selApp->frame(2000.0, $selConstraints);

$selSBox = $selApp->topEntry()?->element?->effectiveBox();
$selSH = $selSBox?->size()?->height ?? 0.0;
$selSSv = $selApp->panelScrollView();
$selSView = $selSSv?->size()?->height ?? 0.0;
$selSMax = $selSSv?->maxScroll(Axis::Vertical) ?? -1.0;
$selSRow = $selApp->optionButtons()[0]?->effectiveBox()?->size()?->height ?? 0.0;
check(
    'Select · 反例：短列表（3 项）⇒ 面板高 = 3 行 + chrome（**贴着内容**，不撑到上限）',
    abs($selSH - (3.0 * $selSRow + ($selSH - $selSView))) < 0.01 && $selSH < Select::PANEL_MAX_HEIGHT,
    'h=' . $selSH . ' rows=' . (3.0 * $selSRow) . ' view=' . $selSView . ' cap=' . Select::PANEL_MAX_HEIGHT,
);
check(
    'Select · 反例：短列表 maxScroll = 0（内容不溢出 ⇒ 不画滚动条，浏览器同行为）',
    $selSMax === 0.0,
    'maxScroll=' . $selSMax,
);

// ── W8-2 Select 三态 / 方向键 / 跨树重建（2026-10-24）──
// ★ 这一块钉住的是"面板内容随状态变"这条链：浮层是**第二棵根渲染树**，主树重建
//   不带动它 ⇒ 全靠 `SelectState` 的 `Subject`（方向键）与 `didUpdateWidget`
//   签名比对（受控值 / 三态 / 选项表）。
// ★ 反例自检（2026-10-24 实测）：把 `SelectState::didUpdateWidget()` 里的
//   `parent::didUpdateWidget($widget)` 摘掉 ⇒ 下面**恰好 6 条红**（三态 5 条 + 跨树
//   1 条），而方向键 3 条**仍绿** —— 方向键走的是 `Subject` 广播、不经过那条缝。
//   这个"红 6 绿 3"的形状就是这套用例确实守着跨树重建的证据。
$ssC = BoxConstraints::loose(new Size(600.0, 400.0));
$ssApp = new SelectStateApplication();
$ssApp->attach(new SurfaceProbe(), $ssC);
$ssApp->frame(1000.0, $ssC);
$ssHost = $ssApp->host();
check('Select 三态 · 夹具挂上（根是 StatefulWidget，有 State）', $ssHost !== null);

// —— 键盘路径开面板：Tab 到触发框 ⇒ Enter（`onOpen` 在这里触发）——
$ssApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
$ssApp->dispatchInput(KeyEvent::down(KeyChord::enter()));
$ssApp->frame(1100.0, $ssC);
check(
    'Select 三态 · Enter 开面板，且 onOpen 恰好被调一次',
    $ssApp->overlayCount() === 1 && $ssHost?->openCount === 1,
    'overlay=' . $ssApp->overlayCount() . ' open=' . ($ssHost?->openCount ?? -1)
);
check('Select 三态 · 面板 3 项（三态都没设时的正常路径）', count($ssApp->optionButtons()) === 3);

// —— 方向键：打开时高亮 = 当前值（310000 ⇒ 下标 1）⇒ Enter 直接提交它 ——
$ssApp->dispatchInput(KeyEvent::down(KeyChord::enter()));
$ssApp->frame(1200.0, $ssC);
check(
    'Select 方向键 · 打开时高亮落在当前值上 ⇒ Enter 提交它',
    $ssHost?->changed === ['310000'],
    'changed=' . implode(',', $ssHost?->changed ?? [])
);
check('Select 方向键 · 提交后面板自己收起', $ssApp->overlayCount() === 0);

// —— Down 移一格 ——
$ssApp->dispatchInput(KeyEvent::down(KeyChord::enter()));
$ssApp->frame(1300.0, $ssC);
$ssApp->dispatchInput(KeyEvent::down(KeyChord::down()));
$ssApp->frame(1400.0, $ssC);
$ssApp->dispatchInput(KeyEvent::down(KeyChord::enter()));
$ssApp->frame(1500.0, $ssC);
check(
    'Select 方向键 · Down 一格 ⇒ 提交下一项（440100，不是当前值）',
    $ssHost?->changed === ['310000', '440100'],
    'changed=' . implode(',', $ssHost?->changed ?? [])
);

// —— Up 环绕：下标 1 往上两次 ⇒ 1 → 0 → 末项（2）——
$ssApp->dispatchInput(KeyEvent::down(KeyChord::enter()));
$ssApp->frame(1600.0, $ssC);
$ssApp->dispatchInput(KeyEvent::down(KeyChord::up()));
$ssApp->frame(1700.0, $ssC);
$ssApp->dispatchInput(KeyEvent::down(KeyChord::up()));
$ssApp->frame(1800.0, $ssC);
$ssApp->dispatchInput(KeyEvent::down(KeyChord::enter()));
$ssApp->frame(1900.0, $ssC);
check(
    'Select 方向键 · Up 到顶后环绕到末项（与 FocusManager 的 Tab 环同手感）',
    $ssHost?->changed === ['310000', '440100', '440100'],
    'changed=' . implode(',', $ssHost?->changed ?? [])
);

// —— 三态：面板开着时改受控值 ⇒ 面板**跨树**跟着变 ——
// ★ 每处要跑**两帧**，原因是 `Application::frame()` 的顺序：
//   ① `placeOverlays()`（先 settle 浮层 + 布局）→ ② `Frame::run()`（settle 主树）。
//   受控值一变，主树重建发生在 ②，面板的 `markDirty()` 也在 ② —— 此时浮层的
//   布局段已经跑完 ⇒ 新盒这一帧没排过，下一帧才显示。60fps 下不可见（16ms），
//   但用例必须按实际帧序写，否则会把"晚一帧"误判成"没生效"。
$ssSettle = function (float $t) use ($ssApp, $ssC): void {
    $ssApp->frame($t, $ssC);
    $ssApp->frame($t + 1.0, $ssC);
};

$ssApp->dispatchInput(KeyEvent::down(KeyChord::enter()));
$ssApp->frame(2000.0, $ssC);
$ssHost?->setStatus(OptionStatus::loading());
$ssSettle(2100.0);
check(
    'Select 三态 · loading ⇒ 面板 0 个候选按钮（不是还列着旧选项）',
    $ssApp->optionButtons() === [],
    'n=' . count($ssApp->optionButtons())
);
check(
    'Select 三态 · loading ⇒ 面板里是"正在加载…"（跨树重建的证据）',
    in_array('正在加载…', $ssApp->panelTexts(), true),
    'texts=' . implode('|', $ssApp->panelTexts())
);

$ssHost?->setStatus(OptionStatus::error('加载失败'));
$ssSettle(2200.0);
check(
    'Select 三态 · error ⇒ 面板里是错误行',
    in_array('加载失败', $ssApp->panelTexts(), true) && $ssApp->optionButtons() === [],
    'texts=' . implode('|', $ssApp->panelTexts())
);

// ★ 2026-10-24：三态收成 `OptionStatus` 之后这里**没有优先级**可测了 ——
//   值互斥，后设的替换先设的。"重试时 error 与 loading 都真"那个场景在类型上
//   就不存在（旧版专门有一条"loading 优先于 error"的用例，见附八）。
$ssHost?->setStatus(OptionStatus::loading());
$ssSettle(2300.0);
check(
    'Select 三态 · error 之后设 loading ⇒ 只剩"正在加载…"（值替换，不是优先级）',
    in_array('正在加载…', $ssApp->panelTexts(), true) && !in_array('加载失败', $ssApp->panelTexts(), true),
    'texts=' . implode('|', $ssApp->panelTexts())
);

// 选项表空了但应用没说这是空态 ⇒ **控件内置**兜底文案（不是可配置的 `emptyText`）
$ssHost?->setStatus(OptionStatus::ready());
$ssHost?->setOptions([]);
$ssSettle(2400.0);
check(
    'Select 三态 · 空表 + ready ⇒ 内置"没有可选项"（不是一片空白）',
    in_array('没有可选项', $ssApp->panelTexts(), true) && $ssApp->optionButtons() === [],
    'texts=' . implode('|', $ssApp->panelTexts())
);

// 应用**主动声明**空态 ⇒ 用它给的文案（与上面那条的区别：文案归应用）
$ssHost?->setStatus(OptionStatus::empty('没有匹配的城市'));
$ssSettle(2450.0);
check(
    'Select 三态 · OptionStatus::empty(文案) ⇒ 面板用应用的文案',
    in_array('没有匹配的城市', $ssApp->panelTexts(), true) && $ssApp->optionButtons() === [],
    'texts=' . implode('|', $ssApp->panelTexts())
);

// 面板开着时换选项表 ⇒ 项数跟着变（框架的 `overlaySignature()` 在起作用）
$ssHost?->setStatus(OptionStatus::ready());
$ssHost?->setOptions([Option::of('甲'), Option::of('乙')]);
$ssSettle(2500.0);
check(
    'Select 跨树 · 面板开着时换选项表 ⇒ 面板项数跟着变',
    count($ssApp->optionButtons()) === 2,
    'n=' . count($ssApp->optionButtons())
);

// ── W8-2 ComboBox（可编辑下拉：输入过滤 + 浮层联动，2026-10-24）──
// ★ 这一块钉住三件事：
//   ① **跨树重建**：输入框在主树、候选面板在浮层树（第二棵根渲染树）⇒ 主树重建
//      **不会**带动面板。面板靠 `ComboBoxQuery`（`Subject`）自己订阅自己重建 ——
//      这是本控件最不显然的一处，只读代码看不出来它通不通，所以必须有断言。
//   ② 字段的两个状态（显示标签 / 搜索框）之间的切换点只有"打开面板"与"关闭面板"。
//   ③ 键盘：Enter 取第一项、Esc 收起、Tab 进面板后打字仍进输入框（`forwardKey()`）。
// ★ 反例自检两条：无匹配项时 Enter **不关面板也不回调**；点面板外 ⇒ 字段**丢弃**
//   打了一半的词回到旧标签。
$cbConstraints = BoxConstraints::loose(new Size(360.0, 300.0));
$cbApp = new ComboBoxApplication();
$cbApp->attach(new SurfaceProbe(), $cbConstraints);
$cbApp->frame(2000.0, $cbConstraints);
$cbT = 2000.0;

/** 推进一帧（`placeOverlays()` 的 settle 段在这一帧里重建面板） */
$cbTick = static function () use ($cbApp, $cbConstraints, &$cbT): void {
    $cbT += 16.0;
    $cbApp->frame($cbT, $cbConstraints);
};

/** 在客户区某点按一下（down + up 同点）并推进一帧 */
$cbTap = static function (array $pt) use ($cbApp, $cbTick): void {
    $cbApp->dispatchInput(PointerEvent::down($pt[0], $pt[1]));
    $cbApp->dispatchInput(PointerEvent::up($pt[0], $pt[1]));
    $cbTick();
};

/** 候选按钮的标签（按视觉顺序） */
$cbLabels = static function () use ($cbApp): array {
    $out = [];
    foreach ($cbApp->optionButtons() as $el) {
        $w = $el->widget();
        $out[] = $w instanceof Button ? $w->label() : '?';
    }

    return $out;
};

check('ComboBox · 初始没有浮层', $cbApp->overlayCount() === 0, 'overlay=' . $cbApp->overlayCount());

// —— ① 点输入框 ⇒ 开面板（全部 4 项）——
$cbTap($cbApp->fieldCenter());
check(
    'ComboBox · 点输入框 ⇒ 开一层浮层，列出全部 4 项',
    $cbApp->overlayCount() === 1 && $cbLabels() === ['北京市', '上海市', '广州市', '北京南站'],
    'overlay=' . $cbApp->overlayCount() . ' rows=[' . implode(',', $cbLabels()) . ']',
);
// ★ 打开时字段必须切成**空**的搜索框：否则打字会追加在标签后面
//   （现象：选中"北京市"再打开、打"上"⇒ 字段成了"北京市上"、一项都匹配不到）。
check(
    'ComboBox · 打开时字段切成空搜索框（不是留着标签让打字追加）',
    $cbApp->fieldState()?->text() === '',
    'text=' . var_export($cbApp->fieldState()?->text(), true),
);

// —— ② 打字 ⇒ **跨树**过滤 ——
$cbApp->dispatchInput(KeyEvent::character('北'));
$cbTick();
check(
    'ComboBox · 打字「北」⇒ 面板跨树过滤到 2 项（主树输入、浮层树重建）',
    $cbLabels() === ['北京市', '北京南站'],
    'rows=[' . implode(',', $cbLabels()) . ']',
);
check(
    'ComboBox · 字段显示输入的字（不是标签）',
    $cbApp->fieldState()?->text() === '北',
    'text=' . var_export($cbApp->fieldState()?->text(), true),
);

// —— ③ Enter ⇒ 取过滤后的**第一项** ——
$cbApp->dispatchInput(KeyEvent::down(KeyChord::enter()));
$cbTick();
check(
    'ComboBox · Enter ⇒ 取过滤后的第一项（110000，不是唯一的"北京南站"）',
    $cbApp->changed() === ['110000'] && $cbApp->value() === '110000',
    'changed=[' . implode(',', $cbApp->changed()) . '] value=' . $cbApp->value(),
);
check(
    'ComboBox · Enter 后浮层收掉，字段回到新标签「北京市」',
    $cbApp->overlayCount() === 0 && $cbApp->fieldState()?->text() === '北京市',
    'overlay=' . $cbApp->overlayCount() . ' text=' . var_export($cbApp->fieldState()?->text(), true),
);

// —— ④ 再打开 ⇒ 过滤词复位（"点一下看全部"）——
$cbTap($cbApp->fieldCenter());
check(
    'ComboBox · 再打开 ⇒ 又列出全部 4 项（过滤词随打开复位，不是只剩标签那一项）',
    $cbApp->overlayCount() === 1 && count($cbLabels()) === 4,
    'overlay=' . $cbApp->overlayCount() . ' rows=' . count($cbLabels()),
);

// —— ⑤ 过滤 + Esc ⇒ 收面板且**丢弃**过滤词 ——
$cbApp->dispatchInput(KeyEvent::character('上'));
$cbTick();
check(
    'ComboBox · 打「上」⇒ 只剩 1 项（"北京市"不再匹配）',
    $cbLabels() === ['上海市'],
    'rows=[' . implode(',', $cbLabels()) . ']',
);
$cbApp->dispatchInput(KeyEvent::down(KeyChord::escape()));
$cbTick();
check(
    'ComboBox · Esc ⇒ 收面板且字段回到旧标签「北京市」（丢弃打了一半的词）',
    $cbApp->overlayCount() === 0 && $cbApp->fieldState()?->text() === '北京市'
        && $cbApp->changed() === ['110000'],
    'overlay=' . $cbApp->overlayCount() . ' text=' . var_export($cbApp->fieldState()?->text(), true),
);

// —— ⑥ 鼠标点第二项 ——
$cbTap($cbApp->fieldCenter());
$cbTap($cbApp->optionCenter(1));
check(
    'ComboBox · 点面板里的第二项 ⇒ 回调收到它的 value（310000）且收面板',
    $cbApp->changed() === ['110000', '310000'] && $cbApp->overlayCount() === 0
        && $cbApp->fieldState()?->text() === '上海市',
    'changed=[' . implode(',', $cbApp->changed()) . '] overlay=' . $cbApp->overlayCount()
        . ' text=' . var_export($cbApp->fieldState()?->text(), true),
);

// —— ⑦ 反例：无匹配项时 Enter 不关面板也不回调 ——
$cbTap($cbApp->fieldCenter());
$cbApp->dispatchInput(KeyEvent::character('z'));
$cbTick();
check(
    'ComboBox · 反例：无匹配项 ⇒ 面板里 0 个候选按钮（但面板还在，有一行"无匹配项"）',
    $cbApp->overlayCount() === 1 && count($cbLabels()) === 0,
    'overlay=' . $cbApp->overlayCount() . ' rows=' . count($cbLabels()),
);
$cbApp->dispatchInput(KeyEvent::down(KeyChord::enter()));
$cbTick();
check(
    'ComboBox · 反例：无匹配项时 Enter 不关面板、也不回调（别让人以为选中了什么）',
    $cbApp->overlayCount() === 1 && $cbApp->changed() === ['110000', '310000'],
    'overlay=' . $cbApp->overlayCount() . ' changed=[' . implode(',', $cbApp->changed()) . ']',
);

// —— ⑧ Tab 进面板后打字仍进输入框（`forwardKey()`）——
$cbApp->dispatchInput(KeyEvent::down(KeyChord::escape()));
$cbTick();
$cbTap($cbApp->fieldCenter());
$cbApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
$cbTick();
$cbFocused = $cbApp->focusedElement()?->widget();
check(
    'ComboBox · Tab ⇒ 焦点进面板的候选行（浮层树也在焦点链里，菜单同一条路）',
    $cbFocused instanceof Button,
    'focus=' . ($cbFocused === null ? '(null)' : get_class($cbFocused)),
);
$cbApp->dispatchInput(KeyEvent::character('上'));
$cbTick();
check(
    'ComboBox · 焦点在面板行上时打字仍进输入框（forwardKey ⇒ 面板过滤到 1 项）',
    $cbApp->fieldState()?->text() === '上' && $cbLabels() === ['上海市'],
    'text=' . var_export($cbApp->fieldState()?->text(), true) . ' rows=[' . implode(',', $cbLabels()) . ']',
);

// —— ⑨ 反例：点面板外 ⇒ 关且丢弃过滤词 ——
$cbApp->dispatchInput(PointerEvent::down(340.0, 280.0));
$cbApp->dispatchInput(PointerEvent::up(340.0, 280.0));
$cbTick();
check(
    'ComboBox · 反例：点面板外 ⇒ 关且字段回旧标签「上海市」（不把半截过滤词留在框里）',
    $cbApp->overlayCount() === 0 && $cbApp->fieldState()?->text() === '上海市'
        && $cbApp->changed() === ['110000', '310000'],
    'overlay=' . $cbApp->overlayCount() . ' text=' . var_export($cbApp->fieldState()?->text(), true),
);

// ── ⑩ 受控值 ⇒ 面板重读（框架的 `overlaySignature()`，2026-10-24）──
//
// ★ 这一块钉的是 2026-10-24 之前 `ComboBox` 的**静默缺陷**：面板开着时应用换掉
//   `options`（远程取数就是这个形状），面板**纹丝不动**、不报错。
//   现在由框架负责（`Application::syncPanelSignatures()`）：锚点 Widget 换了 ⇒
//   求签名 ⇒ 变了就标脏面板。
// ★ 面板内容比主树**晚一帧**（`placeOverlays()` 跑在主树 settle 之前）⇒ 每次
//   改完都要跑**两帧**，否则会把"晚一帧"误判成"没生效"。
$cbSettle = static function () use ($cbTick): void {
    $cbTick();
    $cbTick();
};

$cbTap($cbApp->fieldCenter());
check(
    'ComboBox · 换选项表前：面板 4 项（基线）',
    $cbLabels() === ['北京市', '上海市', '广州市', '北京南站'],
    'rows=[' . implode(',', $cbLabels()) . ']',
);

$cbApp->host()?->setOptions([
    Option::make('成都市', '510100'),
    Option::make('杭州市', '330100'),
]);
$cbSettle();
check(
    'ComboBox 跨树 · 面板开着时换选项表 ⇒ 面板项数跟着变（受控值 ⇒ 面板重读）',
    $cbLabels() === ['成都市', '杭州市'],
    'rows=[' . implode(',', $cbLabels()) . ']',
);

// ★ 反例的另一半：**内容没变就不许重建**。应用每次重建都会造新的 `Option`
//   实例，若按对象身份比 ⇒ 面板每帧重建 ⇒ `ScrollView` 换盒、滚动位置回顶部。
//   判据用**Widget 实例身份**：没重建 ⇒ 还是同一个实例。
//   ★★ 必须捕获 **Widget**，不能捕获 Element：`Element::widget()` 是活动访问器，
//     元素会被复用（`update()` 只换里面的 Widget）⇒ 拿 Element 再读 widget() 拿到
//     的永远是**当前**那个，断言恒真。这一版第一稿就是这么写的，反向自检当场抓到
//     （去掉签名比对仍然全绿）。
$cbBefore = $cbApp->optionButtons()[0]?->widget();
$cbApp->host()?->setOptions([
    Option::make('成都市', '510100'),
    Option::make('杭州市', '330100'),
]);
$cbSettle();
$cbAfter = $cbApp->optionButtons()[0]?->widget();
check(
    'ComboBox 跨树 · 换的是**同样的内容** ⇒ 面板不重建（滚动位置不会跳）',
    $cbBefore !== null && $cbAfter !== null && $cbBefore === $cbAfter,
    $cbBefore === null || $cbAfter === null
        ? '面板没项'
        : 'rebuilt=' . ($cbBefore === $cbAfter ? 'no' : 'yes'),
);

// ── ⑪ 远程搜索（`onSearch` + `status`，2026-10-24）──
//
// ★ 这一块钉四条**只靠读代码看不出来**的事：
//   ① `onSearch` 只挂在"用户输入"上 —— 开关面板时的内部复位**不许**触发它
//      （否则每次开关面板都发一次"搜索空串"请求）。
//   ② 声明了 `onSearch` ⇒ **本地过滤让位**：服务端结果原样显示。判据要选"本地会
//      筛掉一项"的表（查询词是"北"、表里有"上海市"）—— 用全是"北"的表就分不出
//      是"没筛"还是"筛了没变化"。
//   ③ 三态不是 ready 时 Enter **不许**选中：加载中面板里没有选项行，但应用很可能
//      还留着上一批 `options`（不清旧数据以免闪烁）⇒ 不加闸会选中看不见的项。
//   ④ 换选项表之后字段**不许变空白**（远程搜索必然走到这里）。
//
// ★ 面板内容比主树晚一帧 ⇒ 每次改完跑两帧（`$cbSettle`）。
$cbApp->dispatchInput(KeyEvent::down(KeyChord::escape()));
$cbTick();
$cbApp->host()?->setRemote(true);
$cbApp->host()?->setOptions(ComboBoxApplication::cities());
$cbApp->host()?->setStatus(OptionStatus::ready());
$cbSettle();

$cbTap($cbApp->fieldCenter());
check(
    'ComboBox 远程 · 开面板 ⇒ onOpen 触发一次（不是每次应用重建）',
    $cbApp->host()?->openCount === 1,
    'openCount=' . var_export($cbApp->host()?->openCount, true),
);
check(
    'ComboBox 远程 · 开面板时的内部清空**不**触发 onSearch（只有用户输入才算）',
    $cbApp->host()?->searched === [],
    'searched=[' . implode(',', $cbApp->host()?->searched ?? []) . ']',
);

$cbApp->dispatchInput(KeyEvent::character('北'));
// ★ 这里必须跑**两帧**：`onSearch` 的回调把 status 改成 loading，而面板内容读的是
//   "锚点当前那个 Widget" —— 它要等主树 settle 之后才换。第一帧面板还会用**旧**
//   Widget（ready）重建一次（那是 `$query` 广播带出来的），第二帧框架的签名闸才
//   看到新 Widget 并把面板换成"正在加载…"。
$cbSettle();
check(
    'ComboBox 远程 · 打字 ⇒ onSearch 收到**当时的词**（不是空串、不是旧词）',
    $cbApp->host()?->searched === ['北'],
    'searched=[' . implode(',', $cbApp->host()?->searched ?? []) . ']',
);
check(
    'ComboBox 远程 · 回调进加载态 ⇒ 面板只剩一行"正在加载…"、0 个候选',
    $cbLabels() === [] && in_array('正在加载…', $cbApp->panelTexts(), true),
    'rows=[' . implode(',', $cbLabels()) . '] texts=' . implode('|', $cbApp->panelTexts()),
);

// ★★ ③ 加载中 Enter 不许选中 —— 此时 options 里**还留着 4 个城市**（应用没清旧数据）
$cbChangedBefore = count($cbApp->changed());
$cbApp->dispatchInput(KeyEvent::down(KeyChord::enter()));
$cbTick();
check(
    'ComboBox 远程 · 加载中 Enter ⇒ 不选中（旧 options 还在，但不许拿它当"看得见的项"）',
    count($cbApp->changed()) === $cbChangedBefore && $cbApp->overlayCount() === 1,
    'changed=' . count($cbApp->changed()) . ' overlay=' . $cbApp->overlayCount(),
);

// 服务端结果到达：**表里含一项不含"北"的** —— 本地若还筛，就只剩 1 项
$cbApp->host()?->setOptions([
    Option::make('北京市', '110000'),
    Option::make('上海市', '310000'),
]);
$cbApp->host()?->setStatus(OptionStatus::ready());
$cbSettle();
check(
    'ComboBox 远程 · 服务端结果原样显示（查询词「北」+ 含「上海市」⇒ 本地不再筛）',
    $cbLabels() === ['北京市', '上海市'],
    'rows=[' . implode(',', $cbLabels()) . ']',
);

// ★★ ④ 选中之后换选项表 ⇒ 字段**不许变空白**
$cbTap($cbApp->optionCenter(0));
check(
    'ComboBox 远程 · 选中后字段回标签「北京市」（基线）',
    $cbApp->value() === '110000' && $cbApp->fieldState()?->text() === '北京市',
    'value=' . $cbApp->value() . ' text=' . var_export($cbApp->fieldState()?->text(), true),
);
$cbApp->host()?->setOptions([Option::make('杭州市', '330100')]);
$cbSettle();
check(
    'ComboBox 远程 · 换选项表（已选值不在表里）⇒ 字段仍显示「北京市」，不变空白',
    $cbApp->fieldState()?->text() === '北京市',
    'text=' . var_export($cbApp->fieldState()?->text(), true),
);
// ★ 反例的另一半：**没见过**的值不许拿旧标签冒充（否则字段会显示一个错的名字）
$cbApp->host()?->setValue('999999');
$cbSettle();
check(
    'ComboBox 远程 · 反例：表里没有、也从没见过的值 ⇒ 字段空（不拿旧标签冒充）',
    $cbApp->fieldState()?->text() === '',
    'text=' . var_export($cbApp->fieldState()?->text(), true),
);

// ★★ 真机缺陷的判据（2026-10-24）：主树 settle 之后、面板还没同步的那一帧，
//    `wantsFrame()` **必须为真** —— 否则帧时钟不上膛，面板永远停在旧内容。
//    现象（真机实测）：远程搜索的 `deliver()` 明明把选项换成了 2 项（日志 n=2），
//    面板还列着旧的 4 项；而 smoke 因为总是**显式跑两帧**，从来看不出来。
//    ★ 这一条不能用"再跑一帧面板就对了"来测 —— 那正是本缺陷**恰好**能通过的写法。
$cbTap($cbApp->fieldCenter());
$cbSettle();
$cbApp->host()?->setOptions([Option::make('成都市', '510100')]);
$cbTick();   // 只跑**一帧**：主树换了，面板还没跟上
check(
    'ComboBox 远程 · 主树换了、面板还没同步 ⇒ wantsFrame() 为真（否则帧时钟不上膛）',
    $cbApp->wantsFrame() === true,
    'wantsFrame=' . var_export($cbApp->wantsFrame(), true),
);
$cbTick();   // 第二帧：面板同步（`syncPanelSignatures()` 更新 `seenAnchor`）
check(
    'ComboBox 远程 · 同步完 ⇒ wantsFrame() 回落（这条判据不自旋）',
    $cbApp->wantsFrame() === false,
    'wantsFrame=' . var_export($cbApp->wantsFrame(), true),
);

// ★★ 延时基准（真机缺陷判据，2026-10-24）：`after()` 的基准必须取"上一次输入"与
//    "上一帧"的**较大者**。只取"上一帧"的话，**空闲应用**上排的 400ms 会当场触发
//    （现象：远程搜索的"正在加载…"一帧都没出现过，结果直接跳出来）。
//    ★ 摆法要选"上一帧离得很远"：帧戳 1000、输入戳 5000 —— 两者差 4 秒，
//      只取上一帧的话 `due=1400` 早就在过去了。
//    ★ 这里是全文件最后一组断言，直接写帧戳（不再走 `$cbTick` 的递增计数）。
$cbApp->frame(1000.0, $cbConstraints);
$cbApp->dispatchInput(PointerEvent::move(5.0, 5.0), 5000.0);
$cbDelayFired = 0;
$cbApp->after(400, function () use (&$cbDelayFired): void {
    $cbDelayFired++;
});
$cbApp->frame(5100.0, $cbConstraints);
check(
    '延时 · 基准取输入时刻（+100ms 不触发；只取上一帧的话这里已经触发了）',
    $cbDelayFired === 0,
    'fired=' . $cbDelayFired,
);
$cbApp->frame(5400.0, $cbConstraints);
check('延时 · 基准取输入时刻（+400ms 才触发）', $cbDelayFired === 1, 'fired=' . $cbDelayFired);

// ── W8-3 Grid / LazyGrid（等宽网格 = Row + Expanded，零新原语，2026-10-24）──
//
// ★ 方案册 `04` §4.4 否决过 `Wrap` / `Grid`，理由是"两遍布局 + 布局迭代"。那条
//   理由对 `Wrap` **成立**（"一行放几个"要量完全部子才知道），对 `Grid` **不成立**
//   （列数是应用给的，只剩一个除法）。而那个除法 `Row` + `Expanded` 已经做了
//   （`RenderFlex` 把剩余宽度按 `grow` 均分）⇒ 本控件**没有渲染盒、没有 Element**，
//   只是"切块 + 每块一个 `Row`"。
//
// ★ 判据全部**推出来**，不拟合：容器 336 宽 / 3 列 / 列间距 12 ⇒ 格宽 =
//   (336 − 12×2) / 3 = 104。5 个子 ⇒ 2 行（3 + 2）；末行 2 格 + 1 个 `Spacer`
//   占位 ⇒ 末行格宽**也是 104**。
//   ★ 反向自检：去掉 `Grid::rowOf()` 里补 `Spacer` 的那段 while ⇒ 末行格宽变成
//     (336 − 12) / 2 = 162，本条恰好红（这正是本控件存在的理由）。
$gridMount = static function (Widget $widget, float $width = 336.0, float $height = 240.0): Element {
    $element = $widget->createElement();
    $element->mount(null, smokeContext());
    $element->effectiveBox()?->layout(BoxConstraints::tight(new Size($width, $height)));

    return $element;
};

/** 树里所有 `Row` 的渲染盒（按遍历顺序 = 从上到下） @return list<RenderFlex> */
$gridRowBoxes = static function (Element $root): array {
    $out = [];
    $walk = function (Element $e) use (&$walk, &$out): void {
        if ($e->widget() instanceof Row) {
            $box = $e->renderObject();
            if ($box instanceof RenderFlex) {
                $out[] = $box;
            }
        }
        $e->visitChildren($walk);
    };
    $walk($root);

    return $out;
};

/** 一行的每个子盒宽度（`Expanded` 的代理盒 ⇒ 就是格宽） @return list<float> */
$gridCellWidths = static function (RenderFlex $row): array {
    $out = [];
    foreach ($row->children() as $kid) {
        $size = $kid->size();
        $out[] = $size === null ? -1.0 : $size->width;
    }

    return $out;
};

$gridKids = [];
for ($i = 1; $i <= 5; $i++) {
    $gridKids[] = Text::make('格 ' . $i);
}

// ① 切块：5 个子 3 列 ⇒ 2 行
$gridRoot = $gridMount(Grid::make(3)->spacing(12.0)->children($gridKids));
$gridRows = $gridRowBoxes($gridRoot);
check(
    'Grid · 5 个子 / 3 列 ⇒ 2 行（ceil(5/3)）',
    \count($gridRows) === 2,
    'rows=' . \count($gridRows),
);

// ② ★ 核心判据：末行不满时格宽与首行**相同**（靠 `Spacer` 占位）
$gridRowWidths = [];
foreach ($gridRows as $row) {
    $gridRowWidths[] = $gridCellWidths($row);
}
check(
    'Grid · 每格等宽 = (336 − 12×2)/3 = 104，末行不满也不变宽',
    $gridRowWidths === [[104.0, 104.0, 104.0], [104.0, 104.0, 104.0]],
    var_export($gridRowWidths, true),
);
check(
    'Grid · 末行补了 1 个占位（2 格 + 1 Spacer = 3 个子，不是 2 个）',
    \count($gridCellWidths($gridRows[1])) === 3,
    'kids=' . \count($gridCellWidths($gridRows[1])),
);

// ③ 行高统一 + 行间距：`cellHeight(50)` + `spacing(12)` ⇒ 行高 50、第二行 offsetY 62
$gridTallRows = $gridRowBoxes($gridMount(
    Grid::make(3)->spacing(12.0)->cellHeight(50.0)->children($gridKids),
));
$gridTallFirst = $gridTallRows[0]->size();
check(
    'Grid · cellHeight(50) ⇒ 行高 50，行距 12 ⇒ 第二行 offsetY = 62',
    $gridTallFirst !== null && $gridTallFirst->height === 50.0
        && $gridTallRows[1]->offsetY() === 62.0,
    'h=' . var_export($gridTallFirst?->height, true) . ' y2=' . $gridTallRows[1]->offsetY(),
);

// ④ 列数非法 ⇒ 构造期响亮失败（G1：不留到布局期变成"没格子"）
//   ★ 子进程：`Errors::report` 走 `abort()` ⇒ `exit(3)`，进程内 try/catch 接不住
//     （`exit()` 不是异常）。2026-10-24 第一稿写在进程内 ⇒ smoke 在那一行**静默
//     截断**：无汇总行、无 FAIL、退出码 0。
fresh($log);
[$gridZeroCode, $gridZeroOut] = runCase(__FILE__, 'gridzero', null);
check(
    'Grid · 列数 0 ⇒ 构造期响亮失败（exit 3，不是"没格子"）',
    $gridZeroCode === 3 && str_contains(logText($log), '列数必须 ≥ 1'),
    'code=' . $gridZeroCode . ' out=' . $gridZeroOut,
);

// ⑤ LazyGrid = LazyColumn 套 Grid：视口 240 / 行节距 96 ⇒ 只造 ceil(240/96) = 3 行
$lazyGridItems = [];
for ($i = 0; $i < 100; $i++) {
    $lazyGridItems[] = $i;
}
$lazyGridRoot = $gridMount(
    LazyGrid::make(3, 96.0)
        ->spacing(12.0)
        ->items($lazyGridItems, static fn(int $i): Widget => Text::make('项 ' . $i)),
);
$lazyGridRows = $gridRowBoxes($lazyGridRoot);
check(
    'LazyGrid · 100 项 / 3 列 = 34 行，视口只造 3 行（虚拟化与行数无关）',
    \count($lazyGridRows) === 3,
    'rows=' . \count($lazyGridRows),
);
check(
    'LazyGrid · 格宽与全量版**逐像素一致** = 104（分行规则只有一份）',
    $gridCellWidths($lazyGridRows[0]) === [104.0, 104.0, 104.0],
    var_export($gridCellWidths($lazyGridRows[0]), true),
);
// ★ 量具纪律：`offsetY()` 是**相对父盒**的 —— `LazyColumn` 把每个 item 包在
//   `SizedBox(height: 节距)` 里 ⇒ 行盒的 offsetY 恒 0（它相对那个 SizedBox）。
//   行节距要用 `RenderBox::absoluteOrigin()`（父链累加的唯一出处）。
// ★ 行盒自己的高是 **96**（被 SizedBox 紧约束撑成节距），格子才是 84 ——
//   多出来的 12 就是行间距。所以"格子高"要量子盒，不能量行盒。
$lazyGridCellSize = $lazyGridRows[0]->children()[0]->size();
$lazyGridRow1Origin = RenderBox::absoluteOrigin($lazyGridRows[1]);
check(
    'LazyGrid · 格子高 = 行节距 96 − 行距 12 = 84；行节距 96 ⇒ 第二行绝对 y = 96',
    $lazyGridCellSize !== null && $lazyGridCellSize->height === 84.0
        && $lazyGridRow1Origin[1] === 96.0,
    'cellH=' . var_export($lazyGridCellSize?->height, true) . ' y2=' . $lazyGridRow1Origin[1],
);

// ⑥ 末行不满在懒版里同样成立（5 项 ⇒ 2 行，两行都在视口里）
$lazyGridSmall = $gridMount(
    LazyGrid::make(3, 96.0)
        ->spacing(12.0)
        ->items([0, 1, 2, 3, 4], static fn(int $i): Widget => Text::make('项 ' . $i)),
);
$lazyGridSmallRows = $gridRowBoxes($lazyGridSmall);
check(
    'LazyGrid · 末行不满也不变宽（5 项 ⇒ 2 行，末行 2 格 + 占位，格宽仍 104）',
    \count($lazyGridSmallRows) === 2
        && $gridCellWidths($lazyGridSmallRows[1]) === [104.0, 104.0, 104.0],
    'rows=' . \count($lazyGridSmallRows) . ' w=' . var_export($gridCellWidths($lazyGridSmallRows[1] ?? $lazyGridSmallRows[0]), true),
);

// ⑦ 行节距必须大于行间距（否则格子高 ≤ 0，画面是"看不见的网格"）
fresh($log);
[$lazyGridBadCode, $lazyGridBadOut] = runCase(__FILE__, 'lazygridpitch', null);
check(
    'LazyGrid · 行间距 ≥ 行节距 ⇒ 响亮失败（不留一个 0 高的网格）',
    $lazyGridBadCode === 3 && str_contains(logText($log), '行节距必须大于行间距'),
    'code=' . $lazyGridBadCode . ' out=' . $lazyGridBadOut,
);

// ── 日志系统（`12`，2026-10-24）──
// ★ 这一组是 `12` §6 P0 的验收判据，全部 head 侧可断言。
// ★ 每条用例前 `Log::reset()` —— 日志是全局静态，不隔离会互相污染。
{
    // ① 未达门槛不写
    Log::reset();
    $collect = new CollectSink();
    Log::addSink($collect);
    Log::setThreshold(Log::INFO);
    Log::get('layout')->debug('不到门槛');
    Log::get('layout')->info('到门槛了');
    check(
        'Log · 未达门槛不写（DEBUG 在 INFO 门槛下消失）',
        count($collect->got) === 1 && $collect->got[0]->message === '到门槛了',
        'got=' . count($collect->got),
    );

    // ② 分类覆盖生效
    Log::reset();
    $collect = new CollectSink();
    Log::addSink($collect);
    Log::setThreshold(Log::INFO);
    Log::setCategoryThreshold('layout', Log::DEBUG);
    Log::get('layout')->debug('layout 的 debug');
    Log::get('render')->debug('render 的 debug');
    check(
        'Log · 分类点名覆盖门槛（layout=DEBUG 时只 layout 进）',
        count($collect->got) === 1 && $collect->got[0]->category === 'layout',
        'got=' . count($collect->got),
    );

    // ③ 环满丢最旧
    Log::reset();
    $ring = new RingSink(3);
    Log::addSink($ring);
    foreach ([1, 2, 3, 4] as $i) {
        Log::get('t')->info('第 ' . $i . ' 条');
    }
    $keep = array_map(static fn (LogEntry $e): string => $e->message, $ring->recent());
    check(
        'Log · 环满丢最旧（容量 3 写 4 条，第一条不在）',
        $keep === ['第 2 条', '第 3 条', '第 4 条'],
        implode(',', $keep),
    );

    // ④ seq 单调递增
    Log::reset();
    $ring = new RingSink(10);
    Log::addSink($ring);
    foreach (range(1, 5) as $i) {
        Log::get('t')->info('n' . $i);
    }
    $seqs = array_map(static fn (LogEntry $e): int => $e->seq, $ring->recent());
    check(
        'Log · seq 单调递增且从 1 起',
        $seqs === [1, 2, 3, 4, 5],
        implode(',', $seqs),
    );

    // ⑤ Errors::report(DIAGNOSTIC) 落到 Log —— **同时** `Errors::$ring` 不受影响
    Log::reset();
    Errors::reset();
    $ring = new RingSink(10);
    Log::addSink($ring);
    Errors::report('诊断桥测试', Errors::DIAGNOSTIC);
    check(
        'Log · Errors::report(DIAGNOSTIC) 落到 Log 环（WARN 级）',
        count($ring->recent()) === 1
            && $ring->recent()[0]->level === Log::WARN
            && $ring->recent()[0]->category === 'framework'
            && $ring->recent()[0]->message === '诊断桥测试',
    );
    check(
        'Log · 桥不动 Errors::$ring（测试契约，78 处断言靠它）',
        Errors::recentDiagnostics() === ['诊断桥测试'],
        json_encode(Errors::recentDiagnostics()),
    );

    // ⑥ `Log::disable()` 后零写入
    Log::reset();
    $collect = new CollectSink();
    Log::addSink($collect);
    Log::disable();
    Log::get('t')->error('不该出现');
    Log::get('t')->log(Log::FATAL, 'FATAL 也不该出现');
    check(
        'Log · disable() 后零写入（连 FATAL 也不写）',
        $collect->got === [],
        'got=' . count($collect->got),
    );
    Log::enable();

    // ⑦ pattern 编译一次 + 未知占位符 ⇒ Fatal
    Log::reset();
    $fmt = PatternFormatter::compile('%l %c: %m');
    $sample = new LogEntry(7, Log::INFO, 'layout', '测量', ['h' => 320], 1_000_000, 0);
    $one = $fmt->format($sample);
    $same = true;
    for ($i = 0; $i < 1000; $i++) {
        if ($fmt->format($sample) !== $one) {
            $same = false;
            break;
        }
    }
    check(
        'Log · 同一 pattern 1000 次 format 逐字节相同（编译一次，不再解析）',
        $same && $one === 'INFO layout: 测量',
        'got=' . $one,
    );

    [$badCode, $badOut] = runCase(__FILE__, 'log-bad-pattern', null);
    check(
        'Log · 未知占位符 ⇒ Fatal（G2 不静默，不产出缺字段的行）',
        $badCode === 3 && str_contains(logText($log), '未知占位符'),
        'code=' . $badCode . ' out=' . $badOut,
    );

    // ⑧ 每 sink 独立 formatter
    Log::reset();
    $a = new RingSink(4);
    $b = new CollectSink();
    Log::addSink($a);
    Log::addSink($b);
    Log::get('render')->info('独立格式', ['k' => 1]);
    $viaA = PatternFormatter::compile('%s %c %m')->format($a->recent()[0]);
    $viaB = PatternFormatter::compile('%m')->format($b->got[0]);
    check(
        'Log · 每 sink 独立 formatter（同一条 entry 两种成型）',
        $viaA === '1 render 独立格式' && $viaB === '独立格式',
        'a=' . $viaA . ' b=' . $viaB,
    );

    // ⑨ 占位符全集（含 %T 墙钟来自 entry，不是 format() 里的 date()）
    Log::reset();
    $all = PatternFormatter::compile('%T|%t|%l|%c|%m|%f|%s|%%');
    $e = new LogEntry(9, Log::WARN, 'net', '掉线', ['ip' => '127.0.0.1', 'retry' => true], 5_500_000_000, 3_661_234);
    // wallMs=3_661_234 ⇒ 3661 秒 ⇒ 01:01:01；monotonic=5.5s 相对启动
    $line = $all->format($e);
    check(
        'Log · 占位符全集（%T 由 entry 的 wallMs 算，不是 format() 里取的）',
        str_contains($line, '01:01:01') && str_contains($line, 'WARN')
            && str_contains($line, 'net') && str_contains($line, '掉线')
            && str_contains($line, 'ip=127.0.0.1 retry=true')
            && str_contains($line, '|9|%'),
        'got=' . $line,
    );

    // ⑩ 过滤在数据层（`RingSink::recent($category, $minLevel)`）
    //    ★ 反例方向：若过滤挪到 UI 层，这里就测不到"数据层真会滤"；
    //      而两个参数**各自独立**（分类 ⊕ 级别）—— 只测一个会让另一个的
    //      回归漏过去。
    Log::reset();
    $ring = new RingSink(10);
    Log::addSink($ring);
    Log::get('layout')->info('layout 的 info');
    Log::get('layout')->warn('layout 的 warn');
    Log::get('net')->info('net 的 info');
    Log::get('net')->error('net 的 error');
    $mAll = array_map(static fn (LogEntry $e): string => $e->message, $ring->recent());
    $mLayout = array_map(static fn (LogEntry $e): string => $e->message, $ring->recent('layout'));
    $mWarnUp = array_map(static fn (LogEntry $e): string => $e->message, $ring->recent(null, Log::WARN));
    $mBoth = array_map(static fn (LogEntry $e): string => $e->message, $ring->recent('net', Log::WARN));
    check(
        'Log · recent() 按分类 / 级别过滤（两个参数各自独立，可叠加）',
        $mAll === ['layout 的 info', 'layout 的 warn', 'net 的 info', 'net 的 error']
            && $mLayout === ['layout 的 info', 'layout 的 warn']
            && $mWarnUp === ['layout 的 warn', 'net 的 error']
            && $mBoth === ['net 的 error'],
        'all=' . count($mAll) . ' layout=' . count($mLayout)
            . ' warnUp=' . count($mWarnUp) . ' both=' . count($mBoth),
    );

    Log::reset();
    Errors::reset();
}

// ── 日志 P1：文件 sink / 轮转 / 收口（`12` §6，2026-10-24）──
{
    // ⑩ StreamSink 落盘 + 默认 pattern 带时间戳
    Log::reset();
    $tmp = sys_get_temp_dir() . '/drift-log-' . getmypid();
    if (is_dir($tmp)) {
        foreach (glob($tmp . '/*') ?: [] as $f) {
            unlink($f);
        }
    }
    mkdir($tmp, 0777, true);
    $sink = new StreamSink($tmp . '/app.log', 0, PatternFormatter::compile('%c: %m'));
    Log::addSink($sink);
    Log::get('net')->info('第一次');
    Log::get('net')->warn('第二次');
    $text = is_file($tmp . '/app.log') ? file_get_contents($tmp . '/app.log') : '';
    check(
        'Log · StreamSink 落盘，两行各自成型',
        $text === "net: 第一次\nnet: 第二次\n",
        'got=' . var_export($text, true),
    );

    // ⑪ 轮转：上限 10 字节。**一条就超上限的长消息**是关键夹具 ——
    //    只有"轮转检查在 `write()` 开头"才能保证它整条落进新文件；
    //    若检查在结尾（写完再看），它会被劈成"尾巴在旧文件、没有在新文件"。
    Log::reset();
    $sink = new StreamSink($tmp . '/rot.log', 10, PatternFormatter::compile('%m'));
    Log::addSink($sink);
    Log::get('t')->info('AAAAAAAAAA'); // 11 字节（含 \n）⇒ 已超
    Log::get('t')->info('BBBBBBBBBB'); // 开头检查 ⇒ 先转，整条进新文件
    $cur = is_file($tmp . '/rot.log') ? file_get_contents($tmp . '/rot.log') : '';
    $old = is_file($tmp . '/rot.log.1') ? file_get_contents($tmp . '/rot.log.1') : '';
    check(
        'Log · 超上限轮转（旧的成 .1；单条超上限也整条落新文件，不被劈开）',
        $old === "AAAAAAAAAA\n" && $cur === "BBBBBBBBBB\n",
        'old=' . var_export($old, true) . ' cur=' . var_export($cur, true),
    );

    // ⑫ `Log::flush()` / `Log::close()` 转发到每个 sink
    Log::reset();
    $collect = new CollectSink();
    $ring = new RingSink(4);
    Log::addSink($collect);
    Log::addSink($ring);
    Log::flush();
    Log::close();
    check(
        'Log · flush() / close() 逐个转发到全部 sink',
        $collect->flushes === 1 && $collect->closes === 1,
        'flushes=' . $collect->flushes . ' closes=' . $collect->closes,
    );

    // ⑬ 一个 sink 收不了口不影响其他（逐个 catch）
    Log::reset();
    $collect = new CollectSink();
    $boom = new class implements \Drift\Logging\LogSink {
        public function write(LogEntry $entry): void
        {
        }

        public function flush(): void
        {
            throw new \RuntimeException('sink 炸了');
        }

        public function close(): void
        {
            throw new \RuntimeException('sink 炸了');
        }
    };
    Log::addSink($boom);
    Log::addSink($collect);
    $threw = false;
    try {
        Log::flush();
        Log::close();
    } catch (\Throwable) {
        $threw = true;
    }
    check(
        'Log · 一个 sink 收不了口不影响其他（且不冒泡到业务）',
        $threw === false && $collect->flushes === 1 && $collect->closes === 1,
        'threw=' . var_export($threw, true),
    );

    foreach (glob($tmp . '/*') ?: [] as $f) {
        unlink($f);
    }
    @rmdir($tmp);
    Log::reset();
    Errors::reset();
}

// ── 毛玻璃（BackdropFilter / Canvas::blurBackdrop）──
// ★ 这一组钉的是**顺序与范围**，不是"糊得好不好看"：
//   ① 模糊必须在子内容之前（否则字被糊）—— 用命令流里的下标比较；
//   ② 模糊矩形 = 内容盒（不是边框盒，不是整屏）；
//   ③ 一次性操作，不进任何栈深度（`assertBalanced()` 必须过）。
//   Raster 不实现模糊（空实现），所以像素级验证只能真机做 —— 这里验结构。
{
    $glassKid = SizedBox::make()->width(120.0)->height(80.0);
    $glassEl = BackdropFilter::make($glassKid, 16.0)->createElement();
    $glassEl->mount(null, $context);
    $glassBox = $glassEl->effectiveBox();

    check(
        'BackdropFilter · 挂载即建出 RenderBackdropFilter（ProxyElement 走单子路径）',
        $glassBox instanceof RenderBackdropFilter,
    );

    $glassBox?->layout(BoxConstraints::loose(new Size(400.0, 300.0)));
    check(
        'BackdropFilter · 尺寸 = 子尺寸（不自己撑开、不额外占位）',
        $glassBox?->size()?->width === 120.0 && $glassBox?->size()?->height === 80.0,
        'size=' . var_export($glassBox?->size(), true),
    );

    // ★ 用 RecordingCanvas 录命令流：能同时验"录了什么"与"顺序对不对"。
    $glassList = new DisplayList();
    $glassBox?->paint(new RecordingCanvas($glassList));

    $blurAt = -1;
    $blurSigma = 0.0;
    $blurRect = null;
    for ($gi = 0; $gi < $glassList->count(); $gi++) {
        if ($glassList->opAt($gi) === DisplayList::OP_BLUR_BACKDROP) {
            $blurAt = $gi;
            $blurSigma = (float) $glassList->argAt($gi, 0);
            $blurRect = $glassList->argAt($gi, 1);
            break;
        }
    }

    check(
        'BackdropFilter · 录下 OP_BLUR_BACKDROP 且 sigma 原样传下去',
        $blurAt >= 0 && $blurSigma === 16.0,
        'blurAt=' . $blurAt . ' sigma=' . $blurSigma,
    );

    // ★ 断言落在**被测语义**上：模糊矩形必须是内容盒 (0,0,120,80)。
    //   写 `$blurRect !== null` 之类是恒真断言，等于没测。
    check(
        'BackdropFilter · 模糊矩形 = 内容盒（0,0,120,80），不是边框盒也不是整屏',
        $blurRect instanceof Rect
            && $blurRect->left === 0.0 && $blurRect->top === 0.0
            && $blurRect->width === 120.0 && $blurRect->height === 80.0,
        $blurRect instanceof Rect
            ? sprintf('rect=%.1f,%.1f,%.1f,%.1f', $blurRect->left, $blurRect->top, $blurRect->width, $blurRect->height)
            : 'rect=' . var_export($blurRect, true),
    );

    // ★ 顺序：模糊必须排在**所有绘制子内容的命令之前**。子内容此刻只有背景/
    //   无墨，所以用"模糊命令的位置"和"裁剪压栈之后"两条一起钉：模糊前一条
    //   是 pushClip（说明确实在裁剪之内），且模糊不是最后一条（后面还有子）。
    check(
        'BackdropFilter · 顺序 = 压裁剪 → 模糊 → 画子（反了就变成"糊自己"）',
        $blurAt >= 1
            && $glassList->opAt($blurAt - 1) === DisplayList::OP_PUSH_CLIP
            && $blurAt < $glassList->count() - 2,
        'blurAt=' . $blurAt . ' prev=' . ($blurAt >= 1 ? $glassList->opAt($blurAt - 1) : -1)
            . ' count=' . $glassList->count(),
    );

    // ★ 一次性操作 ⇒ 栈必须仍然平衡（若哪天改成 push/pop 对，这条会立刻炸）
    $glassBalanced = true;
    try {
        $glassList->assertBalanced();
    } catch (\Throwable) {
        $glassBalanced = false;
    }
    check(
        'BackdropFilter · 录制栈平衡（blurBackdrop 不进 save/clip/layer 深度）',
        $glassBalanced && $glassList->saveDepth() === 0 && $glassList->clipDepth() === 0,
        'save=' . $glassList->saveDepth() . ' clip=' . $glassList->clipDepth(),
    );

    // 回放：Raster 空实现 ⇒ 不崩、尺寸不变（"不模糊"是有意的降级）
    $glassRaster = new RasterCanvas(200, 200, Color::white(), Scale::of(1.0));
    $glassReplayOk = true;
    try {
        $glassList->replay($glassRaster);
    } catch (\Throwable) {
        $glassReplayOk = false;
    }
    check(
        'BackdropFilter · 回放到 Raster 不崩（CAP_BACKDROP 是 Extended 近似，不响亮失败）',
        $glassReplayOk && $glassRaster->width() === 200,
    );

    check(
        'Canvas · Raster 也置 CAP_BACKDROP（近似 = 不模糊，与 CAP_LAYER 同档）',
        ($glassRaster->capabilities() & CanvasCapabilities::CAP_BACKDROP) !== 0,
    );

    // ★ Skia 侧：解释器里桥是假函数 ⇒ 验"Skia 收到了什么"。这一段钉的是
    //   **单位换算**：sigma 必须乘 dpr、矩形必须进设备像素（含当前偏移）。
    //   漏了 dpr ⇒ 200% 缩放下玻璃比设计稿"更硬"；漏了偏移 ⇒ 糊错位置。
    $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] = [];
    $dprCanvas = new SkiaCanvas(400, 400, null, Scale::of(2.0));
    $dprCanvas->translate(10.0, 20.0);
    $dprCanvas->blurBackdrop(6.0, new Rect(0.0, 0.0, 50.0, 30.0));
    $dprCall = null;
    foreach ($GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] as $dprEntry) {
        if ($dprEntry[0] === 'blur_backdrop') {
            $dprCall = $dprEntry;
        }
    }
    check(
        'SkiaCanvas · blurBackdrop 按 dpr + 偏移换算成设备像素（sigma 6→12，矩形 20,40,100,60）',
        $dprCall !== null
            && $dprCall[1] === 12.0
            && $dprCall[2] === 20.0 && $dprCall[3] === 40.0
            && $dprCall[4] === 100.0 && $dprCall[5] === 60.0,
        $dprCall === null ? 'no call' : implode(',', array_map('strval', $dprCall)),
    );

    // 反例：sigma=0 不该惊动桥（模糊半径 0 就是不模糊，连一次跨边界调用都不值）
    $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] = [];
    $dprCanvas->blurBackdrop(0.0, new Rect(0.0, 0.0, 50.0, 30.0));
    $zeroCalls = 0;
    foreach ($GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] as $zeroEntry) {
        if ($zeroEntry[0] === 'blur_backdrop') {
            $zeroCalls++;
        }
    }
    check('SkiaCanvas · sigma=0 ⇒ 不发桥调用（早退在 PHP 侧）', $zeroCalls === 0);

    // ★ 反例自检：没有内边距时"内容盒 == 边框盒"，上面那条断言**证伪不了**
    //   "误用 $this->size()" 的实现。加 5 的内边距把它们分开：
    //   边框盒 130×90、内容盒 120×80 —— 模糊矩形必须跟着**内容盒**走。
    $padEl = BackdropFilter::make(SizedBox::make()->width(120.0)->height(80.0), 8.0)
        ->padding(EdgeInsets::all(5.0))
        ->createElement();
    $padEl->mount(null, $context);
    $padBox = $padEl->effectiveBox();
    $padBox?->layout(BoxConstraints::loose(new Size(400.0, 300.0)));
    $padList = new DisplayList();
    $padBox?->paint(new RecordingCanvas($padList));
    $padRect = null;
    for ($pi = 0; $pi < $padList->count(); $pi++) {
        if ($padList->opAt($pi) === DisplayList::OP_BLUR_BACKDROP) {
            $padRect = $padList->argAt($pi, 1);
            break;
        }
    }
    check(
        'BackdropFilter · 带内边距时模糊矩形仍 = 内容盒 120×80（不是边框盒 130×90）',
        $padBox?->size()?->width === 130.0 && $padBox?->size()?->height === 90.0
            && $padRect instanceof Rect
            && $padRect->width === 120.0 && $padRect->height === 80.0,
        'box=' . var_export($padBox?->size(), true)
            . ' rect=' . ($padRect instanceof Rect ? sprintf('%.1fx%.1f', $padRect->width, $padRect->height) : 'null'),
    );

    // 反例：sigma(0) 语义上就是"不模糊"，不该被当成错误
    $zeroSigmaOk = true;
    try {
        BackdropFilter::make(SizedBox::make()->width(10.0)->height(10.0), 0.0);
    } catch (\Throwable) {
        $zeroSigmaOk = false;
    }
    check('BackdropFilter · sigma=0 合法（= 不模糊，不是错误）', $zeroSigmaOk);
}

// ── 表面底色（`RenderSurface::setBackground`）──
// ★ 这一组钉三件事，一件都不能少：
//   ① 底色**真的落到像素上**（不是只存字段）—— 读清屏后的像素，且按**预乘**口径读；
//   ② 带 alpha 的底色 ⇒ Win32 表面**声明**逐像素模式（壳切 ULW 的唯一入口）；
//   ③ 默认白 ⇒ **一个桥调用都不发**（零回归：现有应用行为不变）。
{
    // ①-a Raster 侧：清屏像素就是底色（按预乘）
    $bgProbe = new SurfaceProbe();
    $bgProbe->resize(20, 10, 1.0);
    $bgProbe->setBackground(new Color(0x33, 0x66, 0x99, 0x80));
    $bgProbe->beginFrame(0.0);
    $bgPixel = $bgProbe->canvas?->pixelAt(0, 0);

    // ★ 期望值必须**算**出来，不能抄字面量：`RasterCanvas` 的混合公式是
    //   `dst = src·a + dst·(1−a)`，落在全零缓冲上 ⇒ rgb 各乘一次 a/255。
    //   51·128/255 = 25.6 → 26、102·128/255 = 51.2 → 51、153·128/255 = 76.8 → 77。
    //   写直通值 (51,102,153) 会红 —— 而那个红正是"漏了预乘"这个 bug 的形状。
    check(
        'RenderSurface · setBackground 落到清屏像素（预乘：51,102,153,128 → 26,51,77,128）',
        $bgPixel instanceof Color
            && $bgPixel->r === 26 && $bgPixel->g === 51 && $bgPixel->b === 77
            && $bgPixel->a === 128,
        $bgPixel instanceof Color
            ? sprintf('%d,%d,%d,%d', $bgPixel->r, $bgPixel->g, $bgPixel->b, $bgPixel->a)
            : 'null',
    );

    // ①-b Skia 侧：底色（含 alpha）必须原样进 `clear` 的参数 —— 那是逐像素透明
    //   的**唯一**来源（画布是 N32Premul，`clear` 等价 `drawColor(kSrc)`）。
    //   漏掉 alpha ⇒ 窗口全不透明，且**不报错**。
    $GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] = [];
    $bgSkiaSurface = new Win32SkiaSurface(2);
    $bgSkiaSurface->resize(30, 20, 1.0);
    $bgSkiaSurface->setBackground(new Color(0x11, 0x22, 0x33, 0x44));
    $bgSkiaSurface->beginFrame(0.0);
    $bgCreate = null;
    foreach ($GLOBALS['DRIFT_SMOKE_SKIA_CALLS'] as $bgEntry) {
        if ($bgEntry[0] === 'create') {
            $bgCreate = $bgEntry;
            break;
        }
    }
    check(
        'Win32SkiaSurface · 底色原样进 Skia 的 clear 参数（含 alpha：a=44 r=11 g=22 b=33）',
        // ★ `Color::toArgb()` 是 `0xAARRGGBB` ⇒ 期望值写成 a/r/g/b 拼出来的样子，
        //   别照 `new Color($r,$g,$b,$a)` 的实参顺序抄（抄错方向会"看起来对"）。
        $bgCreate !== null && $bgCreate[3] === 0x44112233,
        $bgCreate === null ? 'no create' : sprintf('0x%08X', $bgCreate[3]),
    );

    // ── WindowMaterial 值对象（P1 收敛，2026-10-26）────────────────────
    // ★ 纯值语义：make() 全中性、链式返回新实例（不改原对象）、wantsPixelAlpha()
    //   只由底色 alpha 决定 —— 这是跨桥唯一的底色衍生量（Color 本身不过桥）。
    $mBase = WindowMaterial::make();
    $mAlpha = $mBase->background(new Color(0xFF, 0xFF, 0xFF, 0xB0));
    check(
        'WindowMaterial · make() 全中性 / 链式不改原对象 / wantsPixelAlpha 只由底色 alpha 决定',
        $mBase->backgroundColor() === null
            && $mBase->opacityValue() === 1.0
            && $mBase->backdropKind() === null
            && $mBase->wantsPixelAlpha() === false
            && $mAlpha->wantsPixelAlpha() === true
            && $mBase->wantsPixelAlpha() === false   // 原对象不可变
            && $mAlpha->background(Color::white())->wantsPixelAlpha() === false
            && WindowMaterial::make()->background(new Color(0, 0, 0, 0))->wantsPixelAlpha() === true,
        'base=' . var_export($mBase->wantsPixelAlpha(), true)
            . ' alpha=' . var_export($mAlpha->wantsPixelAlpha(), true),
    );

    // ② 声明：带 alpha 底色 ⇒ 逐像素模式；再设不透明 ⇒ 明确关掉（不能只开不关）
    //   ★ 收敛后"声明"归 `WindowHost::applyMaterial()`：`Surface::setBackground()` 不再
    //     过桥（底色只管"画出来是什么像素"）。逐像素要无边框是硬前提，故先 setFrameless。
    $GLOBALS['DRIFT_SMOKE_WIN_MATERIAL'] = [];
    $alphaHost = new Win32WindowHost();
    $alphaHost->setFrameless(9001, 0);
    $alphaHost->applyMaterial(9001, WindowMaterial::make()->background(new Color(0xFF, 0xFF, 0xFF, 0xB0)));
    $alphaHost->applyMaterial(9001, WindowMaterial::make()->background(Color::white()));
    check(
        'Win32WindowHost · alpha<255 声明逐像素、再设不透明声明关掉（pixelAlpha true→false）',
        winMaterialCalls() === [[9001, true, 1.0, 0], [9001, false, 1.0, 0]],
        var_export(winMaterialCalls(), true),
    );

    // ★ 反例自检：`a === 0` 是"全透明"而不是"没设"，必须照样声明
    //   （用 `> 0` 判"有没有 alpha"会漏掉全透明窗口 —— 那正是最想透明的那种）
    $GLOBALS['DRIFT_SMOKE_WIN_MATERIAL'] = [];
    $clearHost = new Win32WindowHost();
    $clearHost->setFrameless(777, 0);
    $clearHost->applyMaterial(777, WindowMaterial::make()->background(new Color(0, 0, 0, 0)));
    check(
        'Win32WindowHost · 全透明底色（a=0）照样声明逐像素',
        winMaterialCalls() === [[777, true, 1.0, 0]],
        var_export(winMaterialCalls(), true),
    );

    // ③ 零回归：中性材质（不声明任何旋钮）⇒ 发一次完整快照，但 pixelAlpha=false /
    //    opacity=1.0 / backdrop=0（壳据此把窗口调回默认；与 Surface::setBackground 解耦）。
    $GLOBALS['DRIFT_SMOKE_WIN_MATERIAL'] = [];
    $plainHost = new Win32WindowHost();
    $plainHost->applyMaterial(1, WindowMaterial::make());
    check(
        'Win32WindowHost · 中性材质 ⇒ 发一次快照、pixelAlpha=false / opacity=1.0 / backdrop=0',
        winMaterialCalls() === [[1, false, 1.0, 0]],
        var_export(winMaterialCalls(), true),
    );

    // 回放底色：`RecordingSurface::raster()` 是浮层像素断言的取像口，
    // 它必须用同一个底色（否则半透明浮层的像素断言全按白底算）
    $bgRecSurface = new RecordingSurface();
    $bgRecSurface->resize(4, 4, 1.0);
    $bgRecSurface->setBackground(new Color(0x10, 0x20, 0x30));
    $bgRecSurface->beginFrame(0.0);
    $bgRecPixel = $bgRecSurface->raster()->pixelAt(0, 0);
    check(
        'RecordingSurface · raster() 用 setBackground 的底色回放（不是写死白）',
        $bgRecPixel->r === 0x10 && $bgRecPixel->g === 0x20
            && $bgRecPixel->b === 0x30 && $bgRecPixel->a === 255,
        sprintf('%d,%d,%d,%d', $bgRecPixel->r, $bgRecPixel->g, $bgRecPixel->b, $bgRecPixel->a),
    );

    // ── 窗口背后材质（P1 §质感 / Stage 3，收敛进 WindowMaterial）────────────
    // ① ABI 护栏：枚举 `value` **就是**桥的编号（`0` 留给"关"）⇒ 改它等于改 ABI，
    //    而改了之后"磨砂变轻模糊"这种错**不报错、只是看着不对**。
    check(
        'Backdrop · value 就是桥的 ABI 编号（Blur=1 / Acrylic=2，0 留给"关"）',
        Backdrop::Blur->value === 1 && Backdrop::Acrylic->value === 2,
        sprintf('%d / %d', Backdrop::Blur->value, Backdrop::Acrylic->value),
    );

    // ② 意图 → 编号：三种都要过一遍（走 applyMaterial 一次快照）。
    //   ★ `null` 必须是**发 0**，不能是"不发调用" —— 不发的话窗口上已经生效的
    //     材质会一直留着，"关掉"静默失效（本仓最讨厌的那种 bug）。
    $GLOBALS['DRIFT_SMOKE_WIN_MATERIAL'] = [];
    $bdHost = new Win32WindowHost();
    $bdHost->applyMaterial(9001, WindowMaterial::make()->backdrop(Backdrop::Blur));
    $bdHost->applyMaterial(9001, WindowMaterial::make()->backdrop(Backdrop::Acrylic));
    $bdHost->applyMaterial(9001, WindowMaterial::make()->backdrop(null));
    check(
        'Win32WindowHost · applyMaterial 映射 Blur→1 / Acrylic→2 / null→0（关也要发调用）',
        winMaterialCalls() === [[9001, false, 1.0, 1], [9001, false, 1.0, 2], [9001, false, 1.0, 0]],
        var_export(winMaterialCalls(), true),
    );

    // ③ 能力查询：位掩码按 ABI 编号解析（位1 opacity / 位2 pixelAlpha / 位4 blur /
    //   位8 acrylic / 位16 圆角）；假桥报"全能力" ⇒ 五项全 true。
    $caps = (new Win32WindowHost())->materialCapabilities(9001);
    check(
        'Win32WindowHost · materialCapabilities 解析位掩码（假桥全能力 ⇒ 五项全 true）',
        $caps->opacity() && $caps->pixelAlpha() && $caps->backdropBlur()
            && $caps->backdropAcrylic() && $caps->roundedCorners(),
        sprintf(
            '%d/%d/%d/%d/%d',
            (int) $caps->opacity(), (int) $caps->pixelAlpha(), (int) $caps->backdropBlur(),
            (int) $caps->backdropAcrylic(), (int) $caps->roundedCorners(),
        ),
    );
}

// ── ㊾ P2 阶段 1：`Table`（固定表头 + 虚拟行 + 列宽三层）────────────────────
//   四条验收闸门（`docs/DESIGN-TABLE-2026-10-26.md` §6）在这里变成可证伪断言：
//   ① 10000 行只造一屏 ② 表头不随竖向滚动移动 ③ 表头与数据行逐像素对齐
//   ④ 未滚动时行区紧贴表头下方（"表头 + 行区简单堆叠"的等价性，为阶段 5 留退路）
//   ★ 反例自检：① 窗口改成"全部行" ⇒ ①红（6 → 10000）；
//     ② 表头也吃 offsetY ⇒ ②红；③ 两处各算一次列宽 ⇒ ③红（宽序列不等）；
//     ④ 把表头高算进内容坐标 ⇒ ④红（行区 offsetY 32 → 64，整条错位）。
$tableRowsOf = static function (int $count): array {
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = ['id' => $i, 'name' => '用户 ' . $i, 'city' => '城市 ' . ($i % 7)];
    }

    return $rows;
};

$tableBuild = static function (int $count) use ($tableRowsOf): Table {
    $columns = [
        TableColumn::make('id', 'ID')->field('id')->width(TableColumnWidth::fixed(60.0)),
        TableColumn::make('name', '姓名')->field('name')->width(TableColumnWidth::flex(2.0)),
        TableColumn::make('city', '城市')->field('city')->width(TableColumnWidth::flex(1.0)),
    ];

    return Table::make($columns, $tableRowsOf($count))
        ->keyOf(static fn(array $row): int => $row['id'])
        ->rowHeight(40.0)
        ->headerHeight(32.0);
};

/** 挂树 + 按 320×240 布局（smokeContext 的规格） */
$tableMount = static function (Table $widget): Element {
    $element = $widget->createElement();
    $element->mount(null, smokeContext());
    $element->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));

    return $element;
};

/** 重建 + 重排（`scrollBy` 只标脏，帧末才生效 —— 这里手动推一帧） */
$tablePump = static function (Element $root): void {
    $root->rebuild();
    $root->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));
};

$tableStateOf = static function (Element $root): ?TableState {
    $hit = findElement(
        $root,
        static fn(Element $e): bool => $e instanceof StatefulElement && $e->state() instanceof TableState,
    );
    $state = $hit instanceof StatefulElement ? $hit->state() : null;

    return $state instanceof TableState ? $state : null;
};

$tableBoxOf = static function (Element $root): ?RenderTable {
    $box = $root->effectiveBoxOf(RenderTable::class);

    return $box instanceof RenderTable ? $box : null;
};

/** 窗口里实际造了几行（= 行区 `Column` 的直接子节点数） */
$tableRowsInWindow = static function (Element $root): int {
    $column = findElement($root, static fn(Element $e): bool => $e->widget() instanceof Column);
    $n = 0;
    $column?->visitChildren(static function (Element $child) use (&$n): void {
        $n++;
    });

    return $n;
};

/** `RenderTable` 的两个子盒：[表头, 行区] */
$tableKidsOf = static function (?RenderTable $box): array {
    $kids = [];
    $box?->visitChildren(static function (RenderObject $child) use (&$kids): void {
        $kids[] = $child;
    });

    return $kids;
};

/** 某个盒是否在指定祖先的子树下（命中断言的归属判定） */
$tableUnder = static function (?RenderBox $hit, ?RenderObject $ancestor): bool {
    for ($node = $hit; $node !== null; $node = $node->parent()) {
        if ($node === $ancestor) {
            return true;
        }
    }

    return false;
};

$tableBig = $tableMount($tableBuild(10000));
$tableBigState = $tableStateOf($tableBig);
$tableBigBox = $tableBoxOf($tableBig);

// ① 虚拟化：视口 240 − 表头 32 = 行区可见 208 ⇒ ceil(208/40) = 6 行
//   ★★ 2026-10-27 起是"**可见行 + 缓冲带**"（`Table::cacheExtent()`，默认 200）：
//   没有缓冲带的话 `first = floor(offsetY/rowH)`，偏移一动窗口就变 ⇒ 滚动每帧
//   都要重建，A 批第 1 项的收益当场归零。断言因此写成**上界**而不是等于 6：
//   `ceil((208 + 2×200)/40) = ceil(15.2) = 16` 是"窗口最多多大"，11 是当前实际值
//   （缓冲只向下延伸：offset=0 时上方没有内容可建）。
check(
    'Table · 10000 行只造一屏 + 缓冲（行区 208 / 行高 40 / 缓冲 200 ⇒ 11 行，不是 10000）',
    $tableBigState?->createdRows() === 11 && $tableRowsInWindow($tableBig) === 11,
    'created=' . ($tableBigState?->createdRows() ?? -1) . ' / 树内=' . $tableRowsInWindow($tableBig),
);
// ★ 契约断言（与具体缓冲值解耦）：窗口永远 ≤ 可见行 + 两侧缓冲
check(
    'Table · 行窗口有上界（≤ 可见行 + 2×缓冲/行高，与缓冲取值无关）',
    ($tableBigState?->createdRows() ?? PHP_INT_MAX) <= (int) \ceil((208.0 + 2.0 * 200.0) / 40.0),
    'created=' . ($tableBigState?->createdRows() ?? -1),
);

// ② 可滚上限按**完整行高**算：10000×40 − 208 = 399792
//    （按窗长算会得到 32 —— 滚动条会假装"只有一屏"）
$tableMax = $tableBigBox?->maxScroll(Axis::Vertical) ?? -1.0;
check(
    'Table · maxScroll = 总行高 − 行区可见高（400000 − 208 = 399792，不是窗长）',
    $tableMax === 399792.0,
    (string) $tableMax,
);

// ③ 列宽三层：avail 320 / fixed 60 / flex 2:1 分 260 ⇒ [60, 173.33, 86.67]
$tableRowEls = [];
$tableCollectRows = static function (Element $el) use (&$tableCollectRows, &$tableRowEls): void {
    if ($el->widget() instanceof Row) {
        $tableRowEls[] = $el;

        return;
    }

    $el->visitChildren($tableCollectRows);
};
$tableCollectRows($tableBig);

$tableWidthsOf = static function (Element $rowEl): array {
    $box = $rowEl->renderObject();
    if (!$box instanceof RenderFlex) {
        return [];
    }

    $out = [];
    foreach ($box->children() as $kid) {
        $size = $kid->size();
        $out[] = $size === null ? -1.0 : \round($size->width, 2);
    }

    return $out;
};

$tableHeaderWidths = isset($tableRowEls[0]) ? $tableWidthsOf($tableRowEls[0]) : [];
$tableFirstRowWidths = isset($tableRowEls[1]) ? $tableWidthsOf($tableRowEls[1]) : [];

check(
    'Table · 列宽三层：fixed 60 + flex 2:1 分 260 ⇒ [60, 173.33, 86.67]',
    \count($tableHeaderWidths) === 3
        && abs($tableHeaderWidths[0] - 60.0) < 0.5
        && abs($tableHeaderWidths[1] - 173.33) < 1.0
        && abs($tableHeaderWidths[2] - 86.67) < 1.0,
    \json_encode($tableHeaderWidths),
);

// ④ 表头与数据行**逐像素对齐**（最容易错的一处：两处各算一次列宽必然漂）
check(
    'Table · 表头与数据行的列宽逐像素一致（两处 Row 的子宽序列完全相同）',
    $tableHeaderWidths !== [] && $tableHeaderWidths === $tableFirstRowWidths,
    \json_encode([$tableHeaderWidths, $tableFirstRowWidths]),
);

// ⑤ 未滚：行区紧贴表头下方（"表头 + 行区堆叠"的等价性 —— 阶段 5 的退路证明）
$tableKids = $tableKidsOf($tableBigBox);
check(
    'Table · 未滚动时表头在 y=0、行区在 y=表头高（32）',
    isset($tableKids[0], $tableKids[1])
        && $tableKids[0]->offsetY() === 0.0
        && $tableKids[1]->offsetY() === 32.0,
    isset($tableKids[0], $tableKids[1])
        ? sprintf('%s / %s', (string) $tableKids[0]->offsetY(), (string) $tableKids[1]->offsetY())
        : '子盒缺失',
);

// ⑥ 滚动 48：表头**不动**，行区上移。
//   ★★ 断言从"行区盒 offsetY === 24"改成"**第 1 行的顶边落在盒内 y=24**"
//   （2026-10-27）：加了缓冲带之后窗不再从第 1 行开始（`first = floor((offset−200)/40) = 0`），
//   于是行区盒自己上移到 −16 —— 那个数只反映"窗起点在哪"，**不是**被测语义。
//   被测语义是"内容坐标 c 落在盒内 y = 表头高 − offset + c"（与窗起点无关）：
//   第 1 行（内容 y=40）⇒ 32 − 48 + 40 = 24。这也是**能证伪**的：窗起点算错、
//   或表头高被算进内容坐标，这个数就变。
$tableRowBoxOf = static function (Element $root, int $index): ?RenderBox {
    $el = findElement(
        $root,
        static fn(Element $e): bool => $e->widget() instanceof SizedBox
            && $e->widget()->identity()?->value === $index,
    );
    $box = $el?->effectiveBox();

    return $box instanceof RenderBox ? $box : null;
};

$tableBigState?->scrollBy(48.0);
$tablePump($tableBig);
$tableKidsScrolled = $tableKidsOf($tableBoxOf($tableBig));
check(
    'Table · 竖向滚动后表头不动（offsetY 恒 0）',
    isset($tableKidsScrolled[0]) && $tableKidsScrolled[0]->offsetY() === 0.0,
    isset($tableKidsScrolled[0]) ? (string) $tableKidsScrolled[0]->offsetY() : '缺失',
);
$tableRow1Box = $tableRowBoxOf($tableBig, 1);
$tableRow1Y = $tableRow1Box === null ? null : RenderBox::absoluteOrigin($tableRow1Box)[1];
check(
    'Table · 竖向滚动 48 后第 1 行顶边落在 y=24（= 表头高 32 − 已滚 8）',
    $tableRow1Y === 24.0,
    $tableRow1Y === null ? '第 1 行不在树里' : (string) $tableRow1Y,
);
// ★ 缓冲带生效的直接证据：滚到 48 时**第 0 行仍在树里**（窗从 0 起，不是从 1 起）。
//   没有缓冲带的话 first=1 ⇒ 第 0 行被卸掉 ⇒ 往上滚一格就要重建。
check(
    'Table · 缓冲带生效：滚动 48 后第 0 行仍在树里（窗没跟着行边界走）',
    $tableRowBoxOf($tableBig, 0) !== null,
    '第 0 行=' . ($tableRowBoxOf($tableBig, 0) === null ? '已卸载' : '在树里'),
);

/** 推一帧：settle + layout（与 `$tablePump` 的区别是**不强制 rebuild**） */
$tableFrame = static function (Element $root): void {
    $root->settle();
    $root->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));
};

// ★ **回夹取值**的守卫：内容装得下时滚 100 ⇒ 偏移必须回 0。
//   推偏移之后 State 不再每帧重建，于是"State 里存的是用户增量累加值"这条
//   旧账**没人替它擦**了 —— 不回写盒夹过的值，State 会停在 100（盒画 0），
//   之后每笔增量都先消耗这段死区（真机现象："滚轮转了画面不动"）。
$tableClamp = $tableMount($tableBuild(3));
$tableClampState = $tableStateOf($tableClamp);
$tableFrame($tableClamp);
$tableClampState?->scrollBy(100.0);
$tableFrame($tableClamp);
check(
    'Table · 内容装得下时滚动 ⇒ State 的偏移回夹到 0（不许停在 100）',
    $tableClampState?->offsetY() === 0.0,
    (string) ($tableClampState?->offsetY() ?? -1.0),
);

// `ScrollView` 的内容是**外部递进来的同一个 Widget 实例** ⇒ 窗口概念不存在
// ⇒ 滚动**永远** 0 重建（这是"偏移不进 build"最纯的一档）。
$svKids = [];
for ($i = 0; $i < 40; $i++) {
    $svKids[] = Text::make('行 ' . $i);
}
$svRoot = ScrollView::make(Column::make()->children($svKids))->createElement();
$svRoot->mount(null, smokeContext());
$tableFrame($svRoot);
$svState = findElement(
    $svRoot,
    static fn(Element $e): bool => $e instanceof StatefulElement && $e->state() instanceof ScrollViewState,
)?->state();
$svRebuildsBefore = State::rebuiltCount();
if ($svState instanceof ScrollViewState) {
    $svState->scrollBy(120.0);
}
$tableFrame($svRoot);
$svDelta = State::rebuiltCount() - $svRebuildsBefore;
check(
    'ScrollView · 滚动 ⇒ 重建 0 次（内容不是 build 的输入，本来就不该重建）',
    $svDelta === 0,
    'rebuilt=' . $svDelta,
);
check(
    'ScrollView · 滚动确实生效（appliedOffset = 120）',
    $svRoot->effectiveBoxOf(RenderScrollView::class)?->appliedOffset(Axis::Vertical) === 120.0,
    (string) ($svRoot->effectiveBoxOf(RenderScrollView::class)?->appliedOffset(Axis::Vertical) ?? -1.0),
);

// ★★ **端到端判据**：真跑两帧 `Frame`，第二帧的画面必须与第一帧不同。
//   这条抓的是"改了偏移但没应用到子盒 offset"——`Frame` 会跳过 layout
//   （没重建 + 约束没变 + 盒没换代），于是 `performLayout()` 里那段
//   `setOffset()` 不跑 ⇒ 删掉 `setScrollOffset()` 里的 `applyScrollOffsets()`
//   时**画面停在旧位置**（且不报错、不写日志）。上面那些 `layout()` 直调的
//   断言抓不到这个坑（它们每帧都手动重排）。
$svFrameSurface = new SvgSurface();
$svFrameSurface->resize(320, 240, 1.0);
$svFrameEl = ScrollView::make(Column::make()->children($svKids))->createElement();
$svFrameEl->mount(null, smokeContext());
$svFrame = new Frame($svFrameSurface);
$svTight = BoxConstraints::tight(new Size(320.0, 240.0));
$svFrame->run($svFrameEl, $svTight, 0.0);
$svSvgBefore = $svFrameSurface->svg();
$svFrameState = findElement(
    $svFrameEl,
    static fn(Element $e): bool => $e instanceof StatefulElement && $e->state() instanceof ScrollViewState,
)?->state();
if ($svFrameState instanceof ScrollViewState) {
    $svFrameState->scrollBy(120.0);
}
$svFrame->run($svFrameEl, $svTight, 16.0);
$svSvgAfter = $svFrameSurface->svg();
check(
    'ScrollView · 滚动后**下一帧画面真的变了**（偏移必须应用到子盒 offset，Frame 会跳过 layout）',
    $svSvgBefore !== $svSvgAfter && $svSvgBefore !== '',
    'before=' . \strlen($svSvgBefore) . ' / after=' . \strlen($svSvgAfter),
);

// ★ **回夹取值**的守卫（`ScrollView` 这一档才守得住）：内容装得下时滚 100 ⇒
//   State 的偏移必须回 0。`ScrollView` 没有窗口概念 ⇒ 不会因为标脏而重建 ⇒
//   build 里那次"夹取写回"**不会发生** ⇒ 只有 `setScrollOffset()` 的返回值能救它。
//   （`Table` 那一档守不住：夹取必然伴随换窗 ⇒ 重建时的写回会把变异掩盖掉。）
$svTightRoot = ScrollView::make(Column::make()->children([Text::make('一行')]))->createElement();
$svTightRoot->mount(null, smokeContext());
$tableFrame($svTightRoot);
$svTightState = findElement(
    $svTightRoot,
    static fn(Element $e): bool => $e instanceof StatefulElement && $e->state() instanceof ScrollViewState,
)?->state();
if ($svTightState instanceof ScrollViewState) {
    $svTightState->scrollBy(100.0);
}
check(
    'ScrollView · 内容装得下时滚动 ⇒ State 的偏移回夹到 0（不许停在 100）',
    $svTightState instanceof ScrollViewState && $svTightState->offset() === 0.0,
    $svTightState instanceof ScrollViewState ? (string) $svTightState->offset() : '无 State',
);

// `LazyColumn` 的缓冲带由 `overscan()` 显式给（**默认 0**，与 `Table::cacheExtent`
// 默认 200 不同 —— 那边是数据面、行高 40+ 所以 200px 只是 4~5 行；这边格高可能
// 很小，按 px 给固定值会把 6 格的列表撑成 16 格）。两档都断言，把默认行为写实：
//   ① 默认 overscan 0 ⇒ 滚一行就换窗（1 次重建）—— 这不是缺陷，是没配缓冲
//   ② overscan 200 ⇒ 缓冲带内 0 重建（与表格同一条机制）
$lazyRebuildsOf = static function (LazyColumn $widget, float $delta) use ($anchorStateOf): array {
    $root = $widget->createElement();
    $root->mount(null, smokeContext());
    $root->settle();
    $root->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));

    $state = $anchorStateOf($root);
    $before = State::rebuiltCount();
    $state?->scrollBy($delta);
    $root->settle();
    $root->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));

    $box = $root->effectiveBoxOf(RenderScrollView::class);

    return [
        State::rebuiltCount() - $before,
        $box instanceof RenderScrollView ? $box->appliedOffset(Axis::Vertical) : -1.0,
    ];
};

[$lazyDelta0, $lazyOffset0] = $lazyRebuildsOf($anchorWidget($anchorItems(0, 60), false), 40.0);
check(
    'LazyColumn · 默认（overscan 0）滚一行 ⇒ 重建 1 次（没配缓冲，行为写实）',
    $lazyDelta0 === 1 && $lazyOffset0 === 40.0,
    'rebuilt=' . $lazyDelta0 . ' / offset=' . $lazyOffset0,
);
[$lazyDelta200, $lazyOffset200] = $lazyRebuildsOf(
    $anchorWidget($anchorItems(0, 60), false)->overscan(200.0),
    40.0,
);
check(
    'LazyColumn · overscan 200 ⇒ 缓冲带内滚一行重建 0 次（与表格同一机制）',
    $lazyDelta200 === 0 && $lazyOffset200 === 40.0,
    'rebuilt=' . $lazyDelta200 . ' / offset=' . $lazyOffset200,
);

// ⑦ 命中分区：表头区的点进表头子树，行区的点进行区子树
$tableBoxNow = $tableBoxOf($tableBig);
$tableHitHeader = $tableBoxNow?->hitTest(100.0, 10.0);
$tableHitBody = $tableBoxNow?->hitTest(100.0, 230.0);
check(
    'Table · 命中分区：表头区的点落在表头子树',
    isset($tableKidsScrolled[0]) && $tableUnder($tableHitHeader, $tableKidsScrolled[0]),
    $tableHitHeader === null ? 'miss' : \get_debug_type($tableHitHeader),
);
check(
    'Table · 命中分区：行区的点落在行区子树（不是被表头吞掉）',
    isset($tableKidsScrolled[1]) && $tableUnder($tableHitBody, $tableKidsScrolled[1]),
    $tableHitBody === null ? 'miss' : \get_debug_type($tableHitBody),
);

// ⑧ 斑马行：奇数行才有背景（2 行的小表、**未滚动** ⇒ 子 0 = 第 0 行、子 1 = 第 1 行。
//   ★ 不能用 `$tableBig`：它在 ⑥ 里已经滚过 48 ⇒ 窗内第 0 个 Element 是**第 1 行**，
//   断言会反过来（这条正是被自己抓到过一次的）。
$tableSmall = $tableMount($tableBuild(2));
$tableColumnBox = findElement(
    $tableSmall,
    static fn(Element $e): bool => $e->widget() instanceof Column,
)?->renderObject();
$tableLine0 = null;
$tableLine1 = null;
if ($tableColumnBox instanceof RenderFlex) {
    $tableLine0 = $tableColumnBox->children()[0] ?? null;
    $tableLine1 = $tableColumnBox->children()[1] ?? null;
}
check(
    'Table · 斑马行只给奇数行（第 0 行无背景、第 1 行有）',
    $tableLine0 !== null && $tableLine1 !== null
        && $tableLine0->mods()?->hasVisual() === false
        && $tableLine1->mods()?->hasVisual() === true,
    sprintf(
        '%s / %s',
        $tableLine0?->mods()?->hasVisual() === true ? '有' : '无',
        $tableLine1?->mods()?->hasVisual() === true ? '有' : '无',
    ),
);

// ⑨ 滚动条：内容溢出才画（2 行 ⇒ 不溢出 ⇒ 没有条）
$tableSmallBox = $tableBoxOf($tableSmall);
check(
    'Table · 内容不溢出 ⇒ maxScroll = 0 且不画滚动条',
    $tableSmallBox?->maxScroll(Axis::Vertical) === 0.0 && $tableSmallBox->thumbRect(Axis::Vertical) === null,
    'maxScroll=' . (string) ($tableSmallBox?->maxScroll(Axis::Vertical) ?? -1.0),
);
check(
    'Table · 内容溢出 ⇒ 有滚动条（几何与 ScrollView 同一份 `ScrollBarGeometry`）',
    $tableBigBox !== null && $tableBigBox->thumbRect(Axis::Vertical) !== null,
    $tableBigBox?->thumbRect(Axis::Vertical) === null ? 'null' : '有',
);
check(
    'Table · RenderTable 实现 ScrollBarHost（滚动条可拖 —— 与 ScrollView 同一接口）',
    $tableBigBox instanceof \Drift\Layout\ScrollBarHost,
    $tableBigBox instanceof \Drift\Layout\ScrollBarHost ? 'ok' : '未实现',
);

// ⑩ 像素级：把盒画到光栅上，验"画出来的位置"（结构断言抓不到平移/裁剪的错位）
//   ★ 这是 `RenderScrollView` 那次教训的同一条：偏移进 offset 的属性断言能过，
//     而 paint 期多一次 translate 照样错位 ⇒ 必须有一组判"像素落在哪"的断言。
//   ★ 清屏色用**洋红**而不是白：表格底 `Surface` 本身就是纯白（`ColorToken`
//     三套主题都是 0xFFFFFF），白底画布会让"这里画了 Surface"与"这里什么都没画"
//     无法区分 —— 一条恒真的断言等于没有。
//   ★ 取 x=305：竖滚动条占 `[320 − 3 − 6, 320 − 3]` = `[311, 317]`，
//     用 315 会落在条上（踩过一次：表头底被验成 `Muted` 的 110,119,129）。
//     最后一列是 fixed 96 + Center 对齐，右侧是空白 ⇒ 露出的一定是背景色。
$tableRasterOf = static function (?RenderBox $box, int $w, int $h): RasterCanvas {
    $list = new DisplayList();
    $box?->paint(new RecordingCanvas($list));
    $canvas = new RasterCanvas($w, $h, Color::fromRgb(0xFF, 0x00, 0xFF), Scale::of(1.0));
    $list->replay($canvas);

    return $canvas;
};

$tableTheme = ThemeData::light();
$tableChromeInk = $tableTheme->color(ColorToken::Chrome);
$tableSurfaceInk = $tableTheme->color(ColorToken::Surface);
$tablePaneInk = $tableTheme->color(ColorToken::Pane);

$tablePx = static function (?RasterCanvas $raster, int $x, int $y): string {
    if ($raster === null) {
        return '无图';
    }
    $c = $raster->pixelAt($x, $y);

    return sprintf('rgb(%d,%d,%d,%d)', $c->r, $c->g, $c->b, $c->a);
};

$tableSmallRaster = $tableSmallBox === null ? null : $tableRasterOf($tableSmallBox, 320, 240);
check(
    'Table · 像素：表头区是表头底色（y=10）',
    $tableSmallRaster !== null && $tableSmallRaster->pixelAt(305, 10)->equals($tableChromeInk),
    $tablePx($tableSmallRaster, 305, 10),
);
check(
    'Table · 像素：第 0 行（偶数行）是表格底色、第 1 行（奇数行）是斑马色',
    $tableSmallRaster !== null
        && $tableSmallRaster->pixelAt(305, 50)->equals($tableSurfaceInk)
        && $tableSmallRaster->pixelAt(305, 90)->equals($tablePaneInk),
    $tablePx($tableSmallRaster, 305, 50) . ' / ' . $tablePx($tableSmallRaster, 305, 90),
);
check(
    'Table · 像素：内容不足一屏时，行区下方的空白仍是表格底色（不是窗口底色透出来）',
    $tableSmallRaster !== null && $tableSmallRaster->pixelAt(305, 200)->equals($tableSurfaceInk),
    $tablePx($tableSmallRaster, 305, 200),
);

// ★★ 关键一条：滚动后**表头区必须还是表头底色** —— 行区上的那一半会被裁掉。
//   不裁的话首行会画到表头上（表头整个被行盖住），而属性断言全都能过。
//   ★ 盒必须用**滚动后**重新取的 `$tableBoxNow`：`syncBox()` 每次 build 都造新盒，
//   ⑥ 之前的 `$tableBigBox` 是滚动前的旧对象（拿它画出来是未滚画面 ⇒ y=40 落在
//   第 0 行，验成 Surface 白，正是这次踩到的坑）。
$tableBigRaster = $tableBoxNow === null ? null : $tableRasterOf($tableBoxNow, 320, 240);
check(
    'Table · 像素：滚动后表头没被行区盖住（y=10 仍是表头底色，裁剪生效）',
    $tableBigRaster !== null && $tableBigRaster->pixelAt(305, 10)->equals($tableChromeInk),
    $tablePx($tableBigRaster, 305, 10),
);
check(
    'Table · 像素：滚动 48 后第 1 行落在 y=40（行区上移 8，斑马色）',
    $tableBigRaster !== null && $tableBigRaster->pixelAt(305, 40)->equals($tablePaneInk),
    $tablePx($tableBigRaster, 305, 40),
);

// ⑩bis 首帧视口宽漂移 ⇒ 自查一帧
//   ★ 真机踩到的坑：`BuildContext::$width` 是**窗口**宽（根 context 全树共用），
//     而表格的可用宽还要减掉导航栏 / 卡片 padding。首帧没有盒 ⇒ 只能拿窗口宽当
//     上界 ⇒ flex 列按偏大的宽分完，行宽超出表盒 ⇒ **右边的列被裁掉**（真机现象：
//     5 列只看见前 3 列，滚一下才对）。这里把"窗口宽 320 / 实际宽 200"做成夹具，
//     断言"多要一帧 + 下一帧按真实宽重算 + 收敛后不再空转"。
$tableHeaderWidthsIn = static function (Element $root) use ($tableWidthsOf): array {
    $rows = [];
    $walk = static function (Element $e) use (&$walk, &$rows): void {
        if ($e->widget() instanceof Row) {
            $rows[] = $e;

            return;
        }

        $e->visitChildren($walk);
    };
    $walk($root);

    return isset($rows[0]) ? $tableWidthsOf($rows[0]) : [];
};

$tableDrift = $tableBuild(5);
$tableDriftEl = $tableDrift->createElement();
$tableDriftEl->mount(null, smokeContext());   // ctx 宽 320 = 窗口宽
$tableDriftEl->effectiveBox()?->layout(BoxConstraints::tight(new Size(200.0, 240.0)));
$tableDriftState = $tableStateOf($tableDriftEl);
$tableDriftWanted = $tableDriftState?->tickFrame(0.0) === true;
check(
    'Table · 首帧视口宽用了窗口宽 ⇒ 下一帧自查并要一帧',
    $tableDriftWanted,
    $tableDriftWanted ? '要了' : '没要（差异没被看到 ⇒ 列会一直溢出）',
);

$tableDriftEl->rebuild();
$tableDriftEl->effectiveBox()?->layout(BoxConstraints::tight(new Size(200.0, 240.0)));
$tableDriftWidths = $tableHeaderWidthsIn($tableDriftEl);
check(
    'Table · 重建后列宽按真实盒宽分配（200 − 60 = 140 ⇒ 93.33 / 46.67）',
    \count($tableDriftWidths) === 3
        && abs($tableDriftWidths[0] - 60.0) < 0.5
        && abs($tableDriftWidths[1] - 93.33) < 1.0
        && abs($tableDriftWidths[2] - 46.67) < 1.0,
    \json_encode($tableDriftWidths),
);
$tableDriftAgain = $tableDriftState?->tickFrame(16.0);
check(
    'Table · 宽差收敛后不再要帧（不会每帧空转）',
    $tableDriftAgain === false,
    $tableDriftAgain === false ? '已收敛' : '仍在要帧',
);

// ── Table 选择：声明 `selection` 却没接上报 ⇒ 挂载期 Fatal ────────────────
//   ★ 其余选择断言已迁到 `tests/Feature/Table/SelectionTest.php`（点击语义 + 行态像素），
//     语义底稿在 `tests/Unit/Widget/TableRowPickerTest`。
//   ★ 这两条**留在这里**是因为判据是"子进程退出码 3"：PHPUnit 里跑要自己
//     `proc_open` 管进程，而它测的是 `Errors` 的上报链路，与"改 Table 只跑 Table"无关。

// ⑥ 声明了 selection 却没接上报 ⇒ 挂载期 Fatal
fresh($log);
[$tableCode, $tableOut] = runCase(__FILE__, 'table-selection-noreport', null);
check('Table · 声明 selection 但没接 onSelectionChange ⇒ 退出码 3', $tableCode === 3, '实际 ' . $tableCode . ' / ' . $tableOut);
check(
    'Table · 缺上报的报错给出两条修法（接上报 / 去掉 selection）',
    str_contains(logText($log), 'onSelectionChange'),
    logText($log) === '' ? '空日志' : '有日志',
);

// ── Table 排序：三条响亮失败 ────────────────────────────────────────────────
//   ★ 其余排序断言已迁到 `tests/Feature/Table/SortTest.php`（三态循环 + 箭头 + 列序号），
//     值对象在 `tests/Unit/Widget/TableSortTest`。
//   ★ 这三组留在这里是因为判据是"子进程退出码 3"：它们测的是 `Errors` 的上报链路，
//     与"改 Table 只跑 Table"无关。

// ⑧ 三条响亮失败
fresh($log);
[$tableSortCode, $tableSortOut] = runCase(__FILE__, 'table-sort-noreport', null);
check('Table · 列 sortable() 但没接 onSortChange ⇒ 退出码 3', $tableSortCode === 3, '实际 ' . $tableSortCode . ' / ' . $tableSortOut);
check(
    'Table · 缺上报的报错指路 onSortChange（并给出"去掉 sortable()"这条退路）',
    str_contains(logText($log), 'onSortChange') && str_contains(logText($log), 'sortable()'),
    logText($log) === '' ? '空日志' : '有日志',
);

fresh($log);
[$tableSortCode2, $tableSortOut2] = runCase(__FILE__, 'table-sort-unknown-column', null);
check('Table · sort() 指向不存在的列 ⇒ 退出码 3', $tableSortCode2 === 3, '实际 ' . $tableSortCode2 . ' / ' . $tableSortOut2);
check(
    'Table · 列 key 拼错时报"不存在的列"并列出已有列',
    str_contains(logText($log), '不存在的列') && str_contains(logText($log), '现有列'),
    logText($log) === '' ? '空日志' : '有日志',
);

fresh($log);
[$tableSortCode3, $tableSortOut3] = runCase(__FILE__, 'table-sort-empty-column', null);
check(
    'TableSort · 空列 key ⇒ Fatal（"没排序"要写 TableSort::none()）',
    $tableSortCode3 === 3,
    '实际 ' . $tableSortCode3 . ' / ' . $tableSortOut3,
);
// 表头分割线的取色 —— 键盘光标格那一节也用它验"当前格的线是高亮色"
$tableDividerBorderInk = $tableTheme->color(ColorToken::Border);
$tableDividerAccentInk = $tableTheme->color(ColorToken::Accent);

// ── Table 列宽拖拽：`resizable()` 却没接 `onColumnResize` ⇒ 挂载期 Fatal ────────
//   ★ 其余列宽断言已迁到 `tests/Feature/Table/ColumnResizeTest.php`（手柄 / 拖动 /
//     clamp / override / 分割线结构与像素 / 真实路由），滚动条在
//     `tests/Feature/Table/ScrollBarTest.php`。
//   ★ 这两条留在这里是因为判据是"子进程退出码 3"。

// ⑧ 响亮失败：resizable() 但没接 onColumnResize
fresh($log);
[$tableResizeCode, $tableResizeOut] = runCase(__FILE__, 'table-resize-noreport', null);
check('Table · 列 resizable() 但没接 onColumnResize ⇒ 退出码 3', $tableResizeCode === 3, '实际 ' . $tableResizeCode . ' / ' . $tableResizeOut);
check(
    'Table · 缺列宽上报的报错指路 onColumnResize（并给出"去掉 resizable()"这条退路）',
    str_contains(logText($log), 'onColumnResize') && str_contains(logText($log), 'resizable()'),
    logText($log) === '' ? '空日志' : '有日志',
);
// ── Table 键盘光标格的断言已迁出 ──────────────────────────────────────────────
//   → `tests/Feature/Table/KeyboardTest.php`（单 Tab 位 / 方向键 / 边界吞键 /
//     End 自动滚动 / 横向 ensureVisible / 失焦保留光标格 / 子控件让位 / 2px 描边像素）
//   → 列宽带 `maxWidth` 的夹取在 `tests/Feature/Table/ColumnResizeTest.php`
//   ★ 下面留着的是**滚轮带轴**与**单轴容器的按轴语义**（Scroll 主题，不是键盘）。


// ⑱ 单轴容器的按轴语义：问错轴 ⇒ 什么都不做（不是报错）
$tableRevBefore = $revState?->offset() ?? -1.0;
$tableHorizBefore = $hState?->offset() ?? -1.0;
$revState?->scrollByAxis(Axis::Horizontal, 50.0);
$hState?->scrollByAxis(Axis::Vertical, 50.0);
check(
    'Scroll · 按轴：纵向视口收到 Horizontal / 横向视口收到 Vertical ⇒ 都不动',
    $revState !== null && $hState !== null
        && $revState->offset() === $tableRevBefore
        && $hState->offset() === $tableHorizBefore,
    'v=' . (string) ($revState?->offset() ?? -1.0) . ' h=' . (string) ($hState?->offset() ?? -1.0),
);
$hState?->scrollByAxis(Axis::Horizontal, 50.0);
check(
    'Scroll · 按轴：横向视口收到 Horizontal ⇒ 生效（主轴由 Widget 决定，不由调用方猜）',
    $hState !== null && $hState->offset() === $tableHorizBefore + 50.0,
    'h=' . (string) ($hState?->offset() ?? -1.0) . ' expect=' . ($tableHorizBefore + 50.0),
);

// ⑲ 阶段 5b：滚轮**带轴**（触控板横滑 / Shift+竖轮）
//   链路：ScrollEvent(axis) → InputRouter（格→像素）→ scrollAt（按 scrollAxes 分派）
//   ★ 断言的是"轴由**事件**决定"，不是"容器自己猜"：同一张表，换轴就该换轴动。
$tableWheelH = new TableScrollApplication();
$tableWheelH->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$tableWheelH->frame(16.0, BoxConstraints::tight(new Size(320.0, 240.0)));
$tableWheelH->dispatchInput(ScrollEvent::make(100.0, 100.0, 1.0, Axis::Horizontal));
$tableWheelH->frame(17.0, BoxConstraints::tight(new Size(320.0, 240.0)));
$tableWheelHBox = $tableBoxOf($tableWheelH->root());
check(
    'Table · 滚轮：横轴一格 ⇒ offsetX = 48、offsetY = 0（轴来自事件，不由容器猜）',
    $tableWheelHBox?->appliedOffset(Axis::Horizontal) === 48.0
        && $tableWheelHBox->appliedOffset(Axis::Vertical) === 0.0,
    'x=' . sprintf('%.1f', $tableWheelHBox?->appliedOffset(Axis::Horizontal) ?? -1.0)
        . ' y=' . sprintf('%.1f', $tableWheelHBox?->appliedOffset(Axis::Vertical) ?? -1.0),
);

// 再发**竖轴**一格（缺省轴）：横轴停在 48 ⇒ 两轴不串味（同一张表、同一次命中）
$tableWheelH->dispatchInput(ScrollEvent::make(100.0, 100.0, 1.0));
$tableWheelH->frame(18.0, BoxConstraints::tight(new Size(320.0, 240.0)));
$tableWheelHBox = $tableBoxOf($tableWheelH->root());
check(
    'Table · 滚轮：接着竖轴一格 ⇒ offsetY = 48 且 offsetX 仍是 48（两轴互不串味）',
    $tableWheelHBox?->appliedOffset(Axis::Vertical) === 48.0
        && $tableWheelHBox->appliedOffset(Axis::Horizontal) === 48.0,
    'x=' . sprintf('%.1f', $tableWheelHBox?->appliedOffset(Axis::Horizontal) ?? -1.0)
        . ' y=' . sprintf('%.1f', $tableWheelHBox?->appliedOffset(Axis::Vertical) ?? -1.0),
);

// ① 横向**装得下**时，横轴滚轮一格 ⇒ 不标脏（否则触控板每秒几十次白重建）
$tableWheelNarrow = $tableMount(Table::make(
    [
        TableColumn::make('id', 'ID')->field('id')->width(TableColumnWidth::fixed(60.0)),
        TableColumn::make('name', '姓名')->field('name')->width(TableColumnWidth::fixed(60.0)),
    ],
    [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']],
)->keyOf(static fn(array $row): int => (int) $row['id'])
    ->rowHeight(40.0)->headerHeight(32.0));
$tableWheelNarrowState = $tableStateOf($tableWheelNarrow);
$tableWheelNarrowClean = $tableWheelNarrowState?->needsBuild() === false;
$tableWheelNarrowState?->scrollByAxis(Axis::Horizontal, 48.0);
check(
    'Table · 滚轮：横向装得下 ⇒ 横轴滚轮不标脏（`contentWInBuild` 早退）',
    $tableWheelNarrowClean && $tableWheelNarrowState?->needsBuild() === false,
    'clean=' . ($tableWheelNarrowClean ? 'yes' : 'no')
        . ' dirty=' . ($tableWheelNarrowState?->needsBuild() === true ? 'yes' : 'no'),
);

// ③ 竖轮落在"纵向装得下、横向溢出"的表上 ⇒ 不动：**回落只发生在单轴容器**
//   （双轴容器两轴都"支持"，竖向没得滚就是没得滚 —— 不能因为横向有溢出就横着跑）
$tableWheelShort = new TableScrollApplication(3);
$tableWheelShort->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$tableWheelShort->frame(16.0, BoxConstraints::tight(new Size(320.0, 240.0)));
$tableWheelShort->dispatchInput(ScrollEvent::make(100.0, 100.0, 1.0));
$tableWheelShort->frame(17.0, BoxConstraints::tight(new Size(320.0, 240.0)));
$tableWheelShortBox = $tableBoxOf($tableWheelShort->root());
// ★ 也看 State 的**未夹值**：回落一旦发生，`offsetY` 会累加上去（appliedOffset 仍被
//   夹回 0，看不出来），等将来行数变多会突然跳一下。
$tableWheelShortState = $tableStateOf($tableWheelShort->root());
check(
    'Table · 滚轮：竖轮落在"纵向装得下、横向溢出"的表上 ⇒ 两轴都不动（双轴不回落）',
    $tableWheelShortBox?->maxScroll(Axis::Vertical) === 0.0
        && $tableWheelShortBox->maxScroll(Axis::Horizontal) > 0.0
        && $tableWheelShortBox->appliedOffset(Axis::Horizontal) === 0.0
        && $tableWheelShortBox->appliedOffset(Axis::Vertical) === 0.0
        && $tableWheelShortState?->offsetY() === 0.0
        && $tableWheelShortState->offsetX() === 0.0,
    'maxV=' . sprintf('%.1f', $tableWheelShortBox?->maxScroll(Axis::Vertical) ?? -1.0)
        . ' maxH=' . sprintf('%.1f', $tableWheelShortBox?->maxScroll(Axis::Horizontal) ?? -1.0)
        . ' raw=(x=' . sprintf('%.1f', $tableWheelShortState?->offsetX() ?? -1.0)
        . ',y=' . sprintf('%.1f', $tableWheelShortState?->offsetY() ?? -1.0) . ')',
);
// ④ 横轴落在**纵向**视口上 ⇒ 什么都不做（只有竖向才回落；横划不许把页面上下带走）
$tableWheelOnlyV = new ScrollApplication();
$tableWheelOnlyV->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));
$tableWheelOnlyV->frame(16.0, BoxConstraints::loose(new Size(300.0, 200.0)));
$tableWheelOnlyV->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0, Axis::Horizontal));
$tableWheelOnlyV->frame(17.0, BoxConstraints::loose(new Size(300.0, 200.0)));
check(
    'Scroll · 滚轮：横轴落在纵向视口 ⇒ 不动（只有**竖向**回落到容器唯一那根轴）',
    $tableWheelOnlyV->scrollBox()?->appliedOffset(Axis::Vertical) === 0.0,
    'offsetY=' . sprintf('%.1f', $tableWheelOnlyV->scrollBox()?->appliedOffset(Axis::Vertical) ?? -1.0),
);

// ⑪ Fatal 面：四类配置错都必须在挂载期停下（不是静默画错）
fresh($log);
[$tableCode, $tableOut] = runCase(__FILE__, 'table-nokey', null);
check('Table · 缺 keyOf ⇒ 退出码 3', $tableCode === 3, '实际 ' . $tableCode . ' / ' . $tableOut);
check(
    'Table · 缺 keyOf 的报错点名 keyOf 并给理由',
    str_contains(logText($log), 'keyOf'),
    logText($log) === '' ? '空日志' : '有日志',
);

fresh($log);
[$tableCode, $tableOut] = runCase(__FILE__, 'table-width-zero', null);
check('TableColumnWidth · fixed(0) ⇒ 退出码 3', $tableCode === 3, '实际 ' . $tableCode . ' / ' . $tableOut);
check(
    'TableColumnWidth · fixed(0) 的报错说明它是正数约束',
    str_contains(logText($log), '正数'),
    logText($log) === '' ? '空日志' : '有日志',
);

fresh($log);
[$tableCode, $tableOut] = runCase(__FILE__, 'table-field-and-cell', null);
check('TableColumn · field + cell 同时声明 ⇒ 退出码 3', $tableCode === 3, '实际 ' . $tableCode . ' / ' . $tableOut);
check(
    'TableColumn · field + cell 的报错给出二选一的修法',
    str_contains(logText($log), '二选一'),
    logText($log) === '' ? '空日志' : '有日志',
);

fresh($log);
[$tableCode, $tableOut] = runCase(__FILE__, 'table-missing-field', null);
check('TableColumn · field 拼错 ⇒ 退出码 3（不是整列空白）', $tableCode === 3, '实际 ' . $tableCode . ' / ' . $tableOut);
check(
    'TableColumn · 缺字段的报错带"第几行 / 缺哪个 / 有哪些"',
    str_contains(logText($log), 'nmae') && str_contains(logText($log), '现有字段'),
    logText($log) === '' ? '空日志' : '有日志',
);

// ═══════════════════════════════════════════════════════════════════════
// P2 阶段 7a：单元格编辑（2026-10-27）
// ═══════════════════════════════════════════════════════════════════════

// ── 编辑会话 / 点击链 / 拖动框选 已迁出 ────────────────────────────────────
//   · `tests/Feature/Table/EditTest.php`       —— 进 / 提交 / 丢弃 / 失效（9 条）
//   · `tests/Feature/Table/TapTest.php`        —— 单击落格 / 双击进编辑 / 插入符（6 条）
//   · `tests/Feature/Table/DragSelectTest.php` —— 区间 / 反向 / 阈值 / 自动滚（9 条）
//   · `tests/Unit/Input/TapTrackerTest.php`    —— 四条规则 + 三个断链边界（9 条）
//   · `tests/Unit/Text/TextDraftTest.php`      —— 字节数学
//   ★ 它们共用 `DrivesEditSession`（按键必推帧、帧号自增）。


// ⑭ 两条"期望响亮失败"（子进程：`Errors::report` 会 abort，进程内接不住）
fresh($log);
[$editCode, $editOut] = runCase(__FILE__, 'table-cell-edit-no-handler', null);
check('Table · editable() 却没接 onCellEdit ⇒ 退出码 3', $editCode === 3, '实际 ' . $editCode . ' / ' . $editOut);
check(
    'Table · 缺 onCellEdit 的报错点名"回车后什么都没发生"并给修法',
    str_contains(logText($log), 'onCellEdit') && str_contains(logText($log), '修法'),
    logText($log) === '' ? '空日志' : '有日志',
);

fresh($log);
[$editCode, $editOut] = runCase(__FILE__, 'table-cell-edit-on-cell-column', null);
check('Table · cell() 列声明 editable() ⇒ 退出码 3', $editCode === 3, '实际 ' . $editCode . ' / ' . $editOut);
check(
    'Table · cell() 列可编辑的报错给出"改用 field()"的修法',
    str_contains(logText($log), 'field('),
    logText($log) === '' ? '空日志' : '有日志',
);

// ── 汇总 + 退出码（2026-10-18）──
// ★ 以前只打印 FAIL、**从不汇总、也从不非零退出** ⇒ 打印 3 个 FAIL 仍然 exit 0：
//   任何自动化调用（脚本 / CI / `&&` 链）都会把它读成"过了"。与 G2（不许静默
//   失败）直接冲突，故收口在这里。反例自检：故意让一条 check() 失败 ⇒ 末行
//   `N FAIL` 且退出码 1。
printf("\n--- %d ok / %d FAIL ---\n", $checks - $failures, $failures);
exit($failures === 0 ? 0 : 1);
