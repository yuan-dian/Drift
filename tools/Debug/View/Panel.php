<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

use Drift\DevTools\Session\Session;

/**
 * 一次会话上的交互视图：时间轴 + 因果树 + 原因浮层。
 *
 * ## 它是一个**消费者**，不是新的一层
 *
 * 数据从 `Session` 来（`frames` / `cascade` / `why` / `stats` 四个查询），推帧走同一
 * 个 `Session` 的 `pump()`。所以它同时跑在本会话与 `--remote` 的远端会话上，一行
 * 也不用改 —— D3 那条"跨进程读到的值逐值相同"的判据，到这里才第一次有了**真正的
 * 消费者**：一次挂载，几十次追问。
 *
 * ## 三个结构决定
 *
 * 1. **光标按行键，不按下标**。每重算一次行表（切帧、展开、新帧进来），下标指的就
 *    可能是另一个东西，而屏幕看起来完全正常。键来自记录里的稳定标识（`debugId` /
 *    帧号），于是"换了行表也不换身份"是结构性的。
 * 2. **虚拟化在格式化之前**。先摊平出 `Row`（轻量对象），再只格式化视口里那几十行。
 *    360 个重建节点、2000 行的场面，输出永远只有一屏。
 * 3. **查询一次，缓存一帧**。展开/折叠只是重新投影同一份 `cascade`，不重新问。
 *    远端时这尤其要紧：每次翻页都发一条几 MB 的树过去，面板会"越用越慢"。
 *
 * ## 选中项消失时说什么
 *
 * 换了帧、祖先被折叠、选中帧被环形缓冲裁掉 —— 三种情况都会让光标指向一行不存在
 * 的东西。处理是"往上找最近的祖先，找不到就第一行"，**并且把这件事写进状态栏**。
 * 静默跳走比跳错更糟：读者会以为自己看的还是刚才选中那个节点。
 *
 * @internal 面板内部实现；`src/` 不依赖它
 */
final class Panel
{
    public const TAB_FRAMES = 'frames';
    public const TAB_TREE = 'tree';

    private string $tab = self::TAB_FRAMES;

    /** 因果树正在看哪一帧（帧表 tab 下表示"最近一次看过的那一帧"） */
    private int $frame = 0;

    /** 光标：行键。`null` = 还没定位 */
    private ?string $cursor = null;

    /** @var array<string, bool> 行键 => 已折叠 */
    private array $collapsed = [];

    private int $offset = 0;

    /** 原因浮层的节点 id；`null` = 没开 */
    private ?int $whyNode = null;

    private string $status = '';

    // ------------------------------------------------------------ 取回的数据

    /** @var list<array<string, mixed>> */
    private array $frames = [];

    /** @var array<int, float> 帧号 => 整帧墙钟（`pump()` 的返回值或前端喂进来） */
    private array $wall = [];

    /** @var array<string, mixed>|null */
    private ?array $cascade = null;

    private int $cascadeFor = -1;

    /** @var array<int, array<string, mixed>> 帧号 => cascade（最多留几帧） */
    private array $cascades = [];

    /** @var array<string, mixed>|null */
    private ?array $why = null;

    /** @var array<string, mixed> */
    private array $stats = [];

    // ------------------------------------------------------------ 派生缓存

    private int $collapseVersion = 0;

    /** @var array<int, array{v: int, rows: list<TreeRow>}> */
    private array $rowCache = [];

    /** @var array<string, ?string> 行键 => 父键（沿它找消失选中的祖先） */
    private array $parents = [];

    private float $t = 0.0;

    private bool $booted = false;

    public function __construct(
        private readonly Session $session,
        private readonly string $title = '',
        private readonly int $width = 100,
        private readonly int $height = 24,
    ) {
    }

    // ================================================================ 生命周期

    /**
     * 拉一次概览与帧表，定下初始光标。
     *
     * 初始帧取"最慢的**有重建**的帧"，与 `composer debug` 的报告同一个口径：
     * 纯重绘的帧可以最慢却零重建，拿它开局只能看到一棵空树。
     */
    public function boot(?int $frame = null): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $this->stats = $this->session->query('stats');
        $this->refreshFrames();
        $last = $this->frames === [] ? 0 : (int) $this->frames[\count($this->frames) - 1]['id'];
        $this->t = $this->frames === [] ? 0.0 : (float) $this->frames[\count($this->frames) - 1]['t'];

        $pick = $frame ?? $this->pickFrame();
        if ($frame !== null) {
            // 显式指定了帧 ⇒ 直接进树。显式指定时**不进时间轴**：调用方已经决定了看哪帧。
            $this->frame = $frame;
            $this->tab = self::TAB_TREE;
            $this->cursor = null;
            $this->loadCascade();
        } elseif ($pick > 0) {
            $this->cursor = 'f:' . $pick;
        }
        if ($this->frames === []) {
            $this->status = '没有帧：先用 --frames=N 跑一段，再进面板';
        } elseif ($last === 0) {
            $this->status = '这一轮没有产生帧';
        }

        // 开面板时就把光标落到一个真实存在的行上：调用方拿 `rows()` 直接读，
        // 不能因为"还没人渲染"就拿到一个空选中。
        $this->sync();
    }

    /** 前端把重放过程中量到的墙钟喂进来（面板自己推的帧由 {@see self::advance()} 记）。 */
    public function noteWall(int $frameId, float $ms): void
    {
        $this->wall[$frameId] = $ms;
    }

    // ==================================================================== 输入

    /**
     * 处理一个键；返回 false 表示退出。
     *
     * 键名与 `Plan` 的 `--key=` 同一套词表（`Tab` / `Enter` / `Esc` / `Up` …），
     * 单字符就是字面那个键。这样"用命令行跑一遍按键序列"与"人在终端里敲"走的是
     * 同一条路径 —— 判据才能钉住交互本身，而不是只钉住渲染函数。
     */
    public function press(string $key): bool
    {
        $this->boot();

        switch ($key) {
            case 'q':
                return false;
            case 'j':
            case 'down':
                $this->move(1);
                break;
            case 'k':
            case 'up':
                $this->move(-1);
                break;
            case 'J':
            case 'pagedown':
                $this->move($this->bodyHeight());
                break;
            case 'K':
            case 'pageup':
                $this->move(-$this->bodyHeight());
                break;
            case 'g':
            case 'home':
                $this->jumpTo(0);
                break;
            case 'G':
            case 'end':
                $this->jumpTo(-1);
                break;
            case 'enter':
                $this->activate();
                break;
            case 'esc':
            case 'escape':
                $this->escape();
                break;
            case 'tab':
                $this->switchTab();
                break;
            case 'w':
                $this->openWhy();
                break;
            case 'n':
                $this->advance(1);
                break;
            case 'N':
                $this->advance(10);
                break;
            case '[':
                $this->stepFrame(-1);
                break;
            case ']':
                $this->stepFrame(1);
                break;
            case '?':
                $this->status = self::HELP;
                break;
            default:
                $this->status = '不认识的键：' . $key . '（? 看帮助）';
                break;
        }

        // ★ 每个键处理完就把视图状态对齐一次（帧还在不在、光标指的行还在不在、
        //   滚动越没越界）。不把这件事留给渲染：状态不该由"有没有人看"决定 ——
        //   直接读 `rows()` / `status()` 的调用方（判据、将来的导出）也必须自洽。
        $this->sync();

        return true;
    }

    private const string HELP = 'j/k 移动  ⏎ 展开/下钻  w 原因  n/N 推帧  [ ] 换帧  Tab 切换  q 退出  ? 帮助';

    // ==================================================================== 渲染

    /**
     * 整整一屏：`height` 行，不多不少。
     *
     * 行数恒定是面板可用的前提：少一行会让下面的提示语往上跳，读者每按一个键都要
     * 重新找一遍光标在哪。所以短的内容也要补空行。
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $this->boot();
        $this->sync();

        $h = \max(6, $this->height);
        $body = $this->body($h - 4);

        $out = [$this->header()];
        $out[] = Fit::rule($this->width);
        foreach ($body as $line) {
            $out[] = Fit::clip($line, $this->width);
        }
        $out[] = Fit::rule($this->width);
        $out[] = Fit::clip($this->footer(), $this->width);

        return $out;
    }

    // ================================================================== 只读面

    public function tab(): string
    {
        return $this->tab;
    }

    public function frame(): int
    {
        return $this->frame;
    }

    public function cursorKey(): ?string
    {
        return $this->cursor;
    }

    public function status(): string
    {
        return $this->status;
    }

    /** 当前视图的完整行表（判据与将来"另存为文本"都用它）。 @return list<Line> */
    public function rows(): array
    {
        $this->boot();

        return $this->tab === self::TAB_FRAMES ? $this->frameRows() : $this->treeRows();
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        $this->boot();

        return $this->stats;
    }

    // ==================================================================== 内部

    private function bodyHeight(): int
    {
        return \max(1, \max(6, $this->height) - 4);
    }

    private function refreshFrames(): void
    {
        /** @var array{frames: list<array<string, mixed>>} $page */
        $page = $this->session->query('frames', ['limit' => 500]);
        $this->frames = $page['frames'];
    }

    /** 最慢的**有重建**的帧；全都没有重建就退回最后一帧。 */
    private function pickFrame(): int
    {
        $best = 0;
        $bestWall = -1.0;
        foreach ($this->frames as $frame) {
            if ((int) $frame['rebuilt'] === 0) {
                continue;
            }
            $wall = $this->wallOf($frame);
            if ($wall > $bestWall) {
                $bestWall = $wall;
                $best = (int) $frame['id'];
            }
        }
        if ($best > 0) {
            return $best;
        }

        return $this->frames === [] ? 0 : (int) $this->frames[\count($this->frames) - 1]['id'];
    }

    /** @param array<string, mixed> $frame */
    private function wallOf(array $frame): float
    {
        $id = (int) $frame['id'];

        return $this->wall[$id] ?? (float) $frame['cost'];
    }

    /** @return list<int> */
    private function frameIds(): array
    {
        $ids = [];
        foreach ($this->frames as $frame) {
            $ids[] = (int) $frame['id'];
        }

        return $ids;
    }

    /**
     * 把数据与光标对齐：帧还在不在、光标指向的行还在不在、滚动位置越没越界。
     *
     * 三种"消失"在这里统一处理，且都要出声 —— 静默跳走等于骗人。
     */
    private function sync(): void
    {
        if ($this->frames === []) {
            return;
        }

        $ids = $this->frameIds();
        if ($this->tab === self::TAB_TREE) {
            if (!\in_array($this->frame, $ids, true)) {
                $old = $this->frame;
                $this->frame = $ids[0];
                $this->cascade = null;
                $this->cascadeFor = -1;
                $this->cursor = null;
                $this->rowCache = [];
                $this->status = \sprintf(
                    '帧 #%d 已滚出缓冲（容量 %d），改看 #%d',
                    $old,
                    (int) ($this->stats['capacity'] ?? 0),
                    $this->frame,
                );
            }
            $this->loadCascade();
        }

        $rows = $this->rows();
        $keys = [];
        foreach ($rows as $row) {
            $keys[$row->key()] = true;
        }

        if ($this->cursor === null || !isset($keys[$this->cursor])) {
            $this->cursor = $this->resolveCursor($keys, $rows);
        }

        $index = $this->indexOf($rows, $this->cursor);
        // 原因浮层自己管滚动（它滚的是原因链，不是行表），两者不要互相夹。
        if ($this->whyNode === null) {
            $this->clampOffset($index, \count($rows));
        }
    }

    /**
     * 光标指向的行不见了 ⇒ 沿父键往上找最近的活着的祖先，再不行取第一行。
     *
     * @param array<string, bool>      $keys
     * @param list<Line>               $rows
     */
    private function resolveCursor(array $keys, array $rows): ?string
    {
        $walked = 0;
        $cursor = $this->cursor;
        while ($cursor !== null && $walked++ < 64) {
            $cursor = $this->parents[$cursor] ?? null;
            if ($cursor !== null && isset($keys[$cursor])) {
                $this->status = '选中的那行不在了，改选它的上层';

                return $cursor;
            }
        }
        if ($this->cursor !== null && $rows !== []) {
            $this->status = '选中的那行不在这一帧，改选第一行';
        }

        return $rows === [] ? null : $rows[0]->key();
    }

    /** @param list<Line> $rows */
    private function indexOf(array $rows, ?string $key): int
    {
        if ($key === null) {
            return 0;
        }
        foreach ($rows as $i => $row) {
            if ($row->key() === $key) {
                return (int) $i;
            }
        }

        return 0;
    }

    private function clampOffset(int $index, int $count): void
    {
        $body = $this->bodyHeight();
        if ($index < $this->offset) {
            $this->offset = $index;
        } elseif ($index >= $this->offset + $body) {
            $this->offset = $index - $body + 1;
        }
        $this->offset = \max(0, \min($this->offset, \max(0, $count - $body)));
    }

    private function header(): string
    {
        $tabs = $this->tab === self::TAB_FRAMES
            ? '[时间轴]  因果树'
            : '时间轴  [因果树]';

        if ($this->tab === self::TAB_FRAMES) {
            $right = \sprintf(
                '%d 帧 · %d 节点',
                (int) ($this->stats['frames'] ?? \count($this->frames)),
                (int) ($this->stats['nodes'] ?? 0),
            );
        } else {
            $rebuilt = (int) ($this->cascade['rebuilt'] ?? 0);
            $right = \sprintf('帧 #%d · %.2fms · %d 重建', $this->frame, $this->frameWall($this->frame), $rebuilt);
        }

        return Fit::spread($tabs . '   ' . $this->title, $right, $this->width);
    }

    private function footer(): string
    {
        $hints = $this->why !== null ? 'esc 关闭原因  j/k 滚动  q 退出' : self::HELP;

        return $this->status === '' ? $hints : $this->status . '   ·   ' . $hints;
    }

    private function frameWall(int $id): float
    {
        foreach ($this->frames as $frame) {
            if ((int) $frame['id'] === $id) {
                return $this->wallOf($frame);
            }
        }

        return 0.0;
    }

    private function rebuiltOf(int $id): int
    {
        foreach ($this->frames as $frame) {
            if ((int) $frame['id'] === $id) {
                return (int) $frame['rebuilt'];
            }
        }

        return 0;
    }

    /** @return list<string> */
    private function body(int $height): array
    {
        if ($this->whyNode !== null) {
            $all = $this->whyLines();
            $this->offset = \max(0, \min($this->offset, \max(0, \count($all) - $height)));
            $slice = \array_slice($all, $this->offset, $height);
            while (\count($slice) < $height) {
                $slice[] = '';
            }

            return $slice;
        }

        /** @var list<Line> $rows */
        $rows = $this->rows();
        if ($rows === []) {
            return $this->emptyBody($height);
        }

        $slice = \array_slice($rows, $this->offset, $height);
        $lines = [];
        foreach ($slice as $row) {
            $lines[] = ($row->key() === $this->cursor ? '> ' : '  ') . $row->text();
        }
        // 短内容补空行：行数恒定，下面的提示语才不会每按一个键就上下跳。
        while (\count($lines) < $height) {
            $lines[] = '';
        }

        return $lines;
    }

    /** @return list<string> */
    private function emptyBody(int $height): array
    {
        $why = $this->tab === self::TAB_FRAMES
            ? '（还没有帧）'
            : '（这一帧没有重建节点：换成有重建的帧再看，] 或 [ 换帧）';
        $lines = [$why];
        while (\count($lines) < $height) {
            $lines[] = '';
        }

        return $lines;
    }

    /** @return list<string> */
    private function whyLines(): array
    {
        $why = $this->why;
        if ($why === null) {
            return [];
        }

        $node = (int) $why['node'];
        $frame = $why['frame'];
        $lines = [];
        $lines[] = \sprintf(
            '为什么 #%d 重建%s',
            $node,
            $frame === null ? '（没有可用的帧）' : \sprintf('（帧 #%d%s）', (int) $frame, (bool) $why['rebuilt'] ? '' : '，它这一帧没重建'),
        );
        $lines[] = '路径   ' . (string) $why['path'];
        $lines[] = '';

        /** @var list<array<string, mixed>> $causes */
        $causes = $why['causes'];
        if ($causes === []) {
            $lines[] = '这一帧没有它的因果边：可能只是重绘，或被父节点吸收。';
        }
        foreach ($causes as $cause) {
            $line = \sprintf('直接   ← %-7s', (string) $cause['kind']);
            if ((string) $cause['site'] !== '') {
                $line .= '  ' . (string) $cause['site'];
            }
            if ((string) $cause['detail'] !== '') {
                $line .= '  ' . (string) $cause['detail'];
            }
            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = '链路（往上到根因为止）';
        /** @var list<array<string, mixed>> $trail */
        $trail = $why['trail'];
        foreach ($trail as $hop) {
            $lines[] = \sprintf(
                '  %-34s ← %s',
                self::lastSegment((string) $hop['path']),
                (string) $hop['kind'] === 'state'
                    ? 'state  ' . (string) $hop['site'] . ((string) $hop['detail'] !== '' ? '  ' . (string) $hop['detail'] : '')
                    : (string) $hop['kind'],
            );
        }

        return $lines;
    }

    private static function lastSegment(string $path): string
    {
        $parts = \explode(' > ', $path);

        return (string) $parts[\count($parts) - 1];
    }

    // ================================================================ 行表投影

    /** @return list<TreeRow> */
    private function treeRows(): array
    {
        if ($this->cascade === null) {
            return [];
        }
        $cached = $this->rowCache[$this->frame] ?? null;
        if ($cached !== null && $cached['v'] === $this->collapseVersion) {
            return $cached['rows'];
        }

        /** @var list<array<string, mixed>> $roots */
        $roots = $this->cascade['roots'];
        $rows = TreeRows::flatten($roots, $this->collapsed);

        foreach ($rows as $row) {
            // 上限只防"跑了一万帧之后这张表无限长"：一个会话里见过的行键是有尽头的
            // （debugId 不回收，但元素会销毁），真撞上了整体丢弃，不在这里做 LRU。
            if (\count($this->parents) > 20000) {
                $this->parents = [];
            }
            $this->parents[$row->key()] = $row->parentKey;
        }

        if (\count($this->rowCache) > 4) {
            $this->rowCache = [];
        }
        $this->rowCache[$this->frame] = ['v' => $this->collapseVersion, 'rows' => $rows];

        return $rows;
    }

    /** @return list<FrameRow> */
    private function frameRows(): array
    {
        $max = 0.0;
        foreach ($this->frames as $frame) {
            $max = \max($max, $this->wallOf($frame));
        }

        $rows = [];
        foreach ($this->frames as $frame) {
            $id = (int) $frame['id'];
            $wall = $this->wallOf($frame);
            $rows[] = new FrameRow(
                frameId: $id,
                wall: $wall,
                rebuilt: (int) $frame['rebuilt'],
                causes: (int) $frame['causes'],
                current: $id === $this->frame,
                bar: FrameRow::bar($wall, $max),
            );
        }

        return $rows;
    }

    // ================================================================== 动作

    private function move(int $delta): void
    {
        $rows = $this->rows();
        if ($rows === []) {
            return;
        }
        $index = $this->indexOf($rows, $this->cursor) + $delta;
        $index = \max(0, \min(\count($rows) - 1, $index));
        $this->cursor = $rows[$index]->key();
    }

    private function jumpTo(int $index): void
    {
        $rows = $this->rows();
        if ($rows === []) {
            return;
        }
        if ($index < 0) {
            $index = \count($rows) - 1;
        }
        $this->cursor = $rows[\max(0, \min(\count($rows) - 1, $index))]->key();
    }

    /**
     * 时间轴上：进这一帧的树；树里：展开/折叠这一行。
     *
     * 光标**不动**：折叠的是它自己，展开的还是它自己。这样连按 ⏎ 就是"来回切"，
     * 不需要记住"我上次在哪一行"。
     */
    private function activate(): void
    {
        if ($this->tab === self::TAB_FRAMES) {
            $id = (int) \substr((string) $this->cursor, 2);
            if ($id <= 0) {
                return;
            }
            $this->frame = $id;
            $this->tab = self::TAB_TREE;
            $this->offset = 0;
            $this->status = \sprintf('看帧 #%d 的因果树', $id);
            $this->loadCascade();

            $rows = $this->treeRows();
            $this->cursor = $rows === [] ? null : $rows[0]->key();

            return;
        }

        $key = $this->cursor;
        if ($key === null) {
            return;
        }
        if (isset($this->collapsed[$key])) {
            unset($this->collapsed[$key]);
            ++$this->collapseVersion;
            $this->status = '展开';

            return;
        }
        // 叶子折叠没有意义，但也别装作做了：如实说"没有可展开的"。
        $row = null;
        foreach ($this->treeRows() as $candidate) {
            if ($candidate->key() === $key) {
                $row = $candidate;
                break;
            }
        }
        if ($row === null || !$row->hasChildren) {
            $this->status = '这一行没有可展开的下层';

            return;
        }
        $this->collapsed[$key] = true;
        ++$this->collapseVersion;
        $this->status = '折叠';
    }

    private function escape(): void
    {
        if ($this->whyNode !== null) {
            $this->whyNode = null;
            $this->why = null;
            $this->offset = 0;
            $this->status = '';

            return;
        }
        if ($this->tab === self::TAB_TREE) {
            $this->tab = self::TAB_FRAMES;
            $this->cursor = $this->frame > 0 ? 'f:' . $this->frame : $this->cursor;
            $this->offset = 0;
            $this->status = '回到时间轴';
        }
    }

    private function switchTab(): void
    {
        if ($this->tab === self::TAB_FRAMES) {
            $this->activate();

            return;
        }
        $this->tab = self::TAB_FRAMES;
        $this->cursor = $this->frame > 0 ? 'f:' . $this->frame : null;
        $this->offset = 0;
        $this->status = '时间轴';
    }

    private function openWhy(): void
    {
        if ($this->tab !== self::TAB_TREE) {
            $this->status = '先在因果树里选中一行（⏎ 进入这一帧）';

            return;
        }
        $node = 0;
        foreach ($this->treeRows() as $row) {
            if ($row->key() === $this->cursor) {
                $node = $row->nodeId;
                break;
            }
        }
        if ($node <= 0) {
            return;
        }
        // ★ 问的就是**正在看的这一帧**，不是"最近一次重建它的帧"：树是按这一帧画的，
        //   原因若来自另一帧，两者对不上，读者会把别处的原因当成这里的。
        $why = $this->session->query('why', ['node' => $node, 'frame' => $this->frame]);
        $this->why = $why;
        $this->whyNode = $node;
        $this->offset = 0;
        $this->status = \sprintf('节点 #%d 的原因', $node);
    }

    /**
     * 推帧。
     *
     * 树本来就停在最后一帧时**跟着走**（像 `tail -f`）：看动画的时候每按一次 n 还要
     * 再按一次 ⏎ 才能看到新帧，等于把"看"变成了"翻"。停在历史帧时不跟 —— 那是
     * 读者有意钉住某帧在看。
     */
    private function advance(int $count): void
    {
        $before = $this->frames === [] ? 0 : (int) $this->frames[\count($this->frames) - 1]['id'];
        $follow = $this->tab === self::TAB_TREE && $this->frame === $before;
        $last = $before;

        for ($i = 0; $i < $count; ++$i) {
            $this->t += 16.0;
            $ms = $this->session->pump($this->t);
            $id = $this->session->lastFrameId();
            $this->wall[$id] = $ms;
            $last = $id;
        }

        $this->refreshFrames();
        $note = '';
        if ($follow && $last > 0) {
            // ★ 新帧没有重建（静止页 / 纯重绘）时**不跟着走**：跟着走会让树突然变空，
            //   读者以为"这一帧把界面清空了"。停在原帧并把新帧的情况说出来。
            if ($this->rebuiltOf($last) > 0) {
                $this->frame = $last;
                $this->loadCascade();
                $note = '，树跟着走';
            } else {
                $note = \sprintf('；#%d 没有重建，仍看 #%d', $last, $this->frame);
            }
        }
        $this->status = \sprintf('推进 %d 帧（到 #%d）', $count, $last) . $note;
    }

    private function stepFrame(int $delta): void
    {
        $ids = $this->frameIds();
        if ($ids === []) {
            return;
        }
        if ($this->tab !== self::TAB_TREE) {
            // 时间轴上 `[` / `]` 就是移动光标，跟 j/k 一样；不然一个键有两种含义。
            $this->move($delta);

            return;
        }

        $at = \array_search($this->frame, $ids, true);
        $at = $at === false ? 0 : (int) $at;
        $at = \max(0, \min(\count($ids) - 1, $at + $delta));
        $this->frame = $ids[$at];
        $this->offset = 0;
        $this->loadCascade();
        // 光标**不重置**：换帧时尽量停在同一个节点上。它不在这一帧的树里时，
        // `sync()` 会沿父链退回最近的祖先并说明 —— 直接清掉等于每换一帧就丢失方向。
        $this->status = \sprintf('帧 #%d（%d 重建）', $this->frame, (int) ($this->cascade['rebuilt'] ?? 0));
    }

    private function loadCascade(): void
    {
        if ($this->frame <= 0 || $this->cascadeFor === $this->frame) {
            return;
        }
        if (isset($this->cascades[$this->frame])) {
            $this->cascade = $this->cascades[$this->frame];
            $this->cascadeFor = $this->frame;

            return;
        }

        // ★ 面板要**完整结构**：`maxDepth` 给足、`maxChildren=0` 表示不丢分支。
        //   显示多少由视图（折叠 + 视口）决定 —— 查询截断是给文本报告用的，
        //   在这里截会让"展开"变成无源之水。
        $cascade = $this->session->query('cascade', [
            'frame' => $this->frame,
            'maxDepth' => 64,
            'maxChildren' => 0,
        ]);

        if (\count($this->cascades) > 4) {
            $this->cascades = [];
        }
        $this->cascades[$this->frame] = $cascade;
        $this->cascade = $cascade;
        $this->cascadeFor = $this->frame;
        $this->rowCache = [];
    }
}
