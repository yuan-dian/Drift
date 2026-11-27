<?php
declare(strict_types=1);

namespace Drift\Core;

/**
 * 发布态常驻的现场便签：固定容量标量环形缓冲（帧现场 + 告警面包屑），周期性原子落盘给原生崩溃过滤器读。
 *
 * ## 为什么不是 Recorder
 *
 * `Recorder` 是 dev 侧按需安装的可复算证据源：完整因果边、节点表、几何窗口，
 * 装了才有、不装零成本。黑匣子反过来——默认就在、只存标量、不参与重放。两者
 * 的生命期和格式都不同，合成一个会让"证据"这一层背上常驻预算，也会让崩溃文件
 * 与证据包格式耦合（A60 否决的方案①）。
 *
 * ## 为什么契约住 Core
 *
 * 持有者 `Pipeline` 在 `Core`，而 `Core` 只允许依赖自己。把黑匣子放 `Debug`
 * 就得让总线靠 `object` 弱类型偷传，类型检查与 `@internal` 一起丢——与
 * `PaintObserver` 是同一条理由（A60 否决的方案②）。
 *
 * ## 截断是事实，不是缺陷
 *
 * `total` 单调增，`dropped = total - capacity`：窗口被覆盖过就说被覆盖过，不把
 * "没写到"读成"没发生"。`worst` 在覆盖之外单独保留最慢那一帧——环形窗口里
 * 最贵的帧可能已经被挤走，而它恰恰是最该留下的一笔。
 *
 * @internal 只由 `Pipeline` 写入；发布态没有 CLI 消费者，落盘文件自己可读
 */
final class BlackBox
{
    public const int DEFAULT_CAPACITY = 64;

    /** 可恢复告警面包屑容量：比帧窗口短，只回答“崩之前发生过什么” */
    public const int RECOVERABLE_CAPACITY = 16;

    /**
     * sidecar 刷新节奏（帧）。原生崩溃过滤器读不到 PHP 内存，只能读这份文件；
     * 每帧落盘太贵，30 帧（半秒）是“现场够新”与“磁盘不被写爆”的折中。
     */
    public const int FLUSH_EVERY_FRAMES = 30;

    /** 发现文件格式；与协议版本无关，遇到未知版本显式报错而不是降级 */
    public const int PROTOCOL = 3;

    /**
     * 同目录最多留多少份 blackbox sidecar（A79）。
     *
     * 名字带管线对象号，每建一条管线换一份；无上限时本机积到 1570 份 / 11 MB，
     * 而崩溃过滤器会把每一份原文都附进日志——淹没的是最后那份真现场。
     * 与 `Shot::KEEP` 同理：够覆盖同时活着的窗口，多了就是过期便签。
     */
    public const int MAX_SIDECARS = 12;

    /** @var list<array{frame: int, cost: float, settle: int, layout: int, paint: int, skipped: bool}|null> */
    private array $frames;

    private int $write = 0;

    private int $total = 0;

    private int $worstFrame = 0;

    private float $worstCost = 0.0;

    /** @var array{stage: string, message: string, frame: int}|null 第一致命现场，不被告警顶掉 */
    private ?array $failure = null;

    /** @var list<array{frame: int, message: string}> 可恢复告警面包屑，时间正序 */
    private array $recoverables = [];

    private int $recoverableTotal = 0;

    /** 最近一次成功落盘对应的帧号；0 = 从未落盘 */
    private int $lastFlushFrame = 0;

    /**
     * @param int    $capacity 环形容量；预分配一次，帧内不再分配对象
     * @param string $path     落盘路径；空串 = 只留在内存（测试 / 内嵌宿主）
     */
    public function __construct(
        private readonly int $capacity = self::DEFAULT_CAPACITY,
        private readonly string $path = '',
    ) {
        if ($capacity < 1) {
            throw new \InvalidArgumentException('黑匣子容量至少为 1');
        }
        $this->frames = \array_fill(0, $capacity, null);
    }

    /**
     * 写一帧。阶段 ns 只在显式开启分段计时时有值；常驻档不取它们——手写段名
     * 常量会与 `FrameStage` 漂（项目既有纪律：段名只写一遍），所以这里收参数
     * 但不猜名字。
     */
    public function record(
        int $frame,
        float $costMs,
        int $settleNs,
        int $layoutNs,
        int $paintNs,
        bool $skipped,
    ): void {
        $this->frames[$this->write] = [
            'frame' => $frame,
            'cost' => $costMs,
            'settle' => $settleNs,
            'layout' => $layoutNs,
            'paint' => $paintNs,
            'skipped' => $skipped,
        ];
        $this->write = ($this->write + 1) % $this->capacity;
        ++$this->total;

        if ($costMs > $this->worstCost) {
            $this->worstCost = $costMs;
            $this->worstFrame = $frame;
        }

        // 原生崩溃过滤器只能读盘 ⇒ 周期性留一份可读现场；事件失败仍走立即 flush。
        if ($this->total % self::FLUSH_EVERY_FRAMES === 0) {
            $this->flush();
        }
    }

    /**
     * 记崩溃现场并立即落盘：调用方紧接着会走 G2 的中止序列，没有第二机会。
     *
     * 只留第一条失败——崩溃路径上先到的通常是根因，后面几条多是连锁反应；
     * 覆盖掉根因会让报告指向一个后果。
     */
    public function noteFailure(string $stage, string $message): void
    {
        if ($this->failure !== null) {
            return;
        }
        // 当前帧还没 record：total + 1 就是它，不必另开一个帧号出口。
        $frame = $this->total + 1;
        $this->failure = [
            'stage' => $stage,
            'message' => $message,
            'frame' => $frame,
        ];
        $this->flushAt($frame);
    }

    /**
     * 记一条已隔离的可恢复告警并立即落盘。
     *
     * ★ 不写 `$failure`：告警是崩溃前的面包屑，不是崩溃根因。复用失败槽会让
     *   先到的告警顶掉后面的真失败，读文件的人会拿告警当现场。
     * 帧号沿用 `noteFailure()` 的口径：下一条将写的帧；帧外发生时就表示
     * “下一次帧之前”，表达时间位置，不冒充精确帧号。
     */
    public function noteRecoverable(string $message): void
    {
        $frame = $this->total + 1;
        $this->recoverables[] = [
            'frame' => $frame,
            'message' => $message,
        ];
        if (count($this->recoverables) > self::RECOVERABLE_CAPACITY) {
            array_shift($this->recoverables);
        }
        ++$this->recoverableTotal;
        $this->flushAt($frame);
    }

    /**
     * 落盘。写不动就静默——这是崩溃现场便签，绝不能因为目录权限让应用
     * 换一种失败方式（更不能在 G2 的中止序列里抛出第二个异常）。
     */
    public function flush(): void
    {
        $this->flushAt($this->total);
    }

    /**
     * 原子替换 sidecar：原生过滤器可能正好在读，半截 JSON 比没有更糟。
     * 临时名带帧号，`blackbox-*.json` 通配符不会碰到它。
     */
    private function flushAt(int $frame): void
    {
        if ($this->path === '') {
            return;
        }
        // 快照里写的是"这一次打算落盘的帧号"，但只有 rename 成功才算真的落了。
        $json = \json_encode(
            $this->buildSnapshot($frame),
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
        if ($json === false) {
            return;
        }
        $dir = \dirname($this->path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            return;
        }
        $tmp = $this->path . '.tmp-' . \getmypid() . '-' . $frame;
        if (@\file_put_contents($tmp, $json . "\n") === false) {
            return;
        }
        if (!@\rename($tmp, $this->path)) {
            @\unlink($tmp);
            return;
        }
        $this->lastFlushFrame = $frame;
        $this->pruneSidecars($dir);
    }

    /**
     * 把同目录的 sidecar 数收在上限内（A79）。
     *
     * ★ 只在本份**写成功之后**跑：清理失败是次要问题，不能让它盖掉刚落下的现场。
     * ★ 存量也在这里消化：新代码第一次 flush 就会把旧版本留下的无界文件
     *   一次删到上限，不需要单独的迁移入口。
     * ★ 按 mtime 从老到新丢，不用对象号或名字排序：`noteFailure()` 会立即
     *   `flushAt()`，把失败那份的 mtime 刷到最新，于是它永远是最后被丢的。
     * ★ 只认 `blackbox-*.json`，与原生过滤器的通配符同口径；临时名
     *   `blackbox-*.json.tmp-*` 不匹配，不会被扫进来删掉正在写的那份。
     * ★ 全程静默：清理是卫生，不是崩溃现场的一部分，任何失败都不改应用行为。
     */
    private function pruneSidecars(string $dir): void
    {
        $files = \glob($dir . '/blackbox-*.json');
        if ($files === false || \count($files) <= self::MAX_SIDECARS) {
            return;
        }

        // 用 stat()['mtime'] 而不是 filemtime()：后者不在 tpc 的扩展映射表里
        // （NanoExtensionSelector 只列 stat / lstat），极小 AOT 构建未必带它。
        \usort($files, static function (string $a, string $b): int {
            $ta = \stat($a);
            $tb = \stat($b);

            return (($ta === false ? 0 : $ta['mtime']) <=> ($tb === false ? 0 : $tb['mtime']));
        });
        $drop = \array_slice($files, 0, \count($files) - self::MAX_SIDECARS);
        foreach ($drop as $file) {
            @\unlink($file);
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return $this->buildSnapshot($this->lastFlushFrame);
    }

    /** @return array<string, mixed> */
    private function buildSnapshot(int $lastFlushFrame): array
    {
        $frames = [];
        foreach ($this->frames as $row) {
            if ($row !== null) {
                $frames[] = $row;
            }
        }
        // 环形缓冲的物理顺序从写指针开始；按帧号排回时间序，读者才看得出经过。
        \usort($frames, static fn(array $a, array $b): int => $a['frame'] <=> $b['frame']);

        return [
            'protocol' => self::PROTOCOL,
            'capacity' => $this->capacity,
            'total' => $this->total,
            'dropped' => \max(0, $this->total - $this->capacity),
            'frames' => $frames,
            'worst' => $this->worstFrame > 0
                ? ['frame' => $this->worstFrame, 'cost' => $this->worstCost]
                : null,
            'failure' => $this->failure,
            'lastFlushFrame' => $lastFlushFrame,
            'recoverables' => [
                'capacity' => self::RECOVERABLE_CAPACITY,
                'total' => $this->recoverableTotal,
                'dropped' => max(0, $this->recoverableTotal - self::RECOVERABLE_CAPACITY),
                'items' => $this->recoverables,
            ],
        ];
    }
}
