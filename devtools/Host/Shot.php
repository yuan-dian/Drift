<?php
declare(strict_types=1);

namespace Drift\DevTools\Host;

use Drift\Rendering\Canvas;

/**
 * 一帧的**像素快照**：把画布回读成 PNG 落进 `.drift/shot/`，让另一个进程（web）
 * 能看到应用现在长什么样。
 *
 * ## 为什么是像素，不是 IR
 *
 * 视面的底线是「和真机看起来一样」。IR 重绘是第二份渲染实现（抗锯齿、hinting、
 * 模糊都要再实现一遍），必然与真机漂移；而像素就是真机那张图的字节，零漂移。
 * 代价是它**只能看，不能断言** —— 不进指纹、不进基线、不参与 `--assert`。
 *
 * ## 什么时候取
 *
 * 挂在 `afterPaint`（树与浮层都画完、`endFrame` 之前），那里画布还活着。取完立刻
 * 落盘，不留在内存 —— 800×600 RGBA 是 1.83 MiB，留着就是每帧一份常驻。
 *
 * ## 为什么降频
 *
 * `toRgba()` 在 Skia 后端要走一次逐像素 swizzle（4 MB 跨界），编码 PNG 再压一遍。
 * 实测合计 25–40 ms/张，每帧做等于把帧预算吃掉一半。调试视图不需要 60 fps：
 * 默认最快 4 张/秒，且只在真的有观察者时才挂载（见 {@see installed()}）。
 *
 * @internal 手工工具；不进 AOT 判据清单
 */
final class Shot
{
    /** 环形保留张数：够翻最近几秒，不会把 `.drift/` 撑成几百兆。 */
    private const KEEP = 12;

    /** 两枪之间的最小间隔（毫秒）。取帧是观察，不是测量。 */
    public const MIN_GAP_MS = 250.0;

    private float $lastAt = -1e9;

    private int $written = 0;

    /**
     * 快照序号：这是第几张图。单调自增，从目录里已有的最大号接着数。
     *
     * ★ 为什么要和录制帧号分开：帧号回答"主循环跑过几轮"（空闲的桥让它以 ~80fps
     *   推进），快照回答"画面变过几次"。拿帧号当图片版本号，静止的应用每帧都会
     *   被当成"新图"，而真正变了的那次反而可能落在同一个号上被去重挡掉。
     */
    private int $serial = 0;

    /** 真的去读了像素的次数 —— 用来给"拍不到就停下"兜底，见 {@see self::starving()}。 */
    private int $attempts = 0;

    /** 上次问"有人在看吗"的时刻 —— 每帧 stat 一次没必要，观察者的出现是秒级事件。 */
    private float $lastProbeAt = -1e9;

    private bool $wanted = false;

    public function __construct(
        private readonly string $dir,
        private readonly float $gapMs = self::MIN_GAP_MS,
        private readonly ?string $sessionId = null,
    ) {
        $this->serial = self::latestSerial($this->shotDir());
    }

    /** 目录里已有的最大快照号；重启宿主后接着数，不会覆盖上一个进程拍的图。 */
    private static function latestSerial(string $dir): int
    {
        $max = 0;
        foreach (\glob($dir . '/s*.png') ?: [] as $file) {
            $n = (int) \substr(\basename($file, '.png'), 1);
            $max = \max($max, $n);
        }

        return $max;
    }

    /**
     * 订阅者有没有留下意图。`drift debug web` 起来时写这个标记，退出时删。
     *
     * 用文件而不是环境变量：宿主是先起来的，观察者是后来的。环境变量只能在
     * 启动时读一次，等于要求用户"先开 web 再开应用"，顺序反了就白等。
     *
     * ★ 带 TTL：观察者被 `kill -9` 或崩溃时删不掉标记，没有 TTL 就是宿主永久的
     *   白拍。标记的 mtime 是心跳 —— web 每秒请求一次图，顺便摸一下它。
     */
    public static function installed(string $dir, float $ttlSec = 20.0, ?string $sessionId = null): bool
    {
        $file = self::dirFor($dir, $sessionId) . '/want';
        $at = @\filemtime($file);

        return $at !== false && (\time() - $at) <= $ttlSec;
    }

    public function shotDir(): string
    {
        return self::dirFor($this->dir, $this->sessionId);
    }

    /**
     * 快照落在会话自己的子目录：同一目录跑两个宿主时，全局 `.drift/shot` 会让
     * 后拍的那张盖掉前一张的 meta，web 于是拿 A 的图配 B 的几何。
     * 空会话号留给没有登记的旧调用（单例宿主），仍用原来的平铺路径。
     */
    public static function dirFor(string $dir, ?string $sessionId): string
    {
        $root = $dir . '/shot';
        if ($sessionId === null || $sessionId === '') {
            return $root;
        }

        return $root . '/' . $sessionId;
    }

    /**
     * 有订阅者、但一张都还没拍成。
     *
     * 这是宿主**唯一**该为画面做的事：静止的应用不会自己重画（`Frame::run()` 的快路），
     * 不主动要一帧就永远没有第一张图。拍到之后就再也不用问 —— 画面变了自然会重画。
     */
    public function starving(float $nowMs): bool
    {
        $this->probe($nowMs);

        // 试过一二十次还拍不到（后端读不回像素 / 画布句柄无效）就停下：
        // 继续要帧等于让应用每 500 ms 白画一次，而这套系统的前提是"观察不打扰"。
        return $this->wanted && $this->written === 0 && $this->attempts < 40;
    }

    private function probe(float $nowMs): void
    {
        if ($nowMs - $this->lastProbeAt <= 500.0) {
            return;
        }

        $this->lastProbeAt = $nowMs;
        $this->wanted = self::installed($this->dir, 20.0, $this->sessionId);
    }

    /**
     * 拍一张。返回是否真的写了 —— `false` 表示"这一帧跳过"（降频 / 没人看 / 读不回像素）。
     *
     * @param int $anchor **几何覆盖到的帧号**，写进 meta 供 `boxes?frame=N` 配对。
     *                    ★ 不当去重键：它按"几何变没变"推进，而画面可能只改了颜色
     *                    或文字 —— 那种变化几何不动，用 anchor 去重会把它们全挡掉。
     *                    去重交给调用方：观察者只在**真的重画了**的时候才被回调。
     */
    public function capture(Canvas $canvas, int $anchor, float $nowMs): bool
    {
        if ($nowMs - $this->lastAt < $this->gapMs) {
            return false;
        }

        // 观察者随时可能来或走（`debug web` 是另一个进程）。节流问一次就够，
        // 而一旦摘掉就彻底不碰画布 —— 像素是"有人看才付"的那笔开销。
        $this->probe($nowMs);

        if (!$this->wanted) {
            return false;
        }

        $w = $canvas->width();
        $h = $canvas->height();
        if ($w <= 0 || $h <= 0) {
            return false;
        }

        // `Canvas` 接口不含 toRgba：只有真后端（RasterCanvas / SkiaCanvas）有像素，
        // 录制画布与合成层没有。用能力探测而不是 `instanceof` 具体类 —— 后者会把
        // 后端类型名带进 App 层，正是 backend-branch 规则要防的。
        if (!\method_exists($canvas, 'toRgba')) {
            return false;
        }

        /** @var callable(): string $read */
        $read = [$canvas, 'toRgba'];
        $this->attempts++;
        $rgba = $read();
        if (\strlen($rgba) !== $w * $h * 4) {
            return false;
        }

        $png = self::encode($rgba, $w, $h);
        $dir = $this->shotDir();
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return false;
        }

        // 先写临时名再 rename：web 随时在读 latest.png，直接覆盖会读到半张图。
        $serial = $this->serial + 1;
        $tmp = $dir . '/.tmp-' . $serial;
        if (@\file_put_contents($tmp, $png) === false) {
            return false;
        }

        $file = $dir . '/s' . $serial . '.png';
        if (!@\rename($tmp, $file)) {
            @\unlink($tmp);

            return false;
        }

        // `latest.png` 必须是**复制**而不是改名：改名会把 `s<serial>.png` 拿走，
        // 于是"按序号取图"只在最近一次拍成功时可用，翻历史快照全是 404。
        // 走临时名再 rename，web 那边就不会读到半张图。
        $latestTmp = $dir . '/.latest-tmp';
        if (@\copy($file, $latestTmp)) {
            @\rename($latestTmp, $dir . '/latest.png');
        }

        $this->lastAt = $nowMs;
        $this->serial = $serial;
        $this->written++;
        $this->publishMeta($serial, $anchor, $w, $h);

        if ($this->written % self::KEEP === 0) {
            $this->prune();
        }

        return true;
    }

    /**
     * 快照元数据：`serial` 是图片的版本号（前端据此决定换不换图），`frame` 是它
     * 对应得上的录制帧号（前端据此取几何）。两个数必须一起给 —— 分两次请求就会
     * 拿到"图是第 3 张、几何是第 5 张"的错位，而错位看起来像布局错了。
     */
    private function publishMeta(int $serial, int $frame, int $w, int $h): void
    {
        $dir = $this->shotDir();
        $json = \json_encode([
            'serial' => $serial,
            'frame' => $frame,
            'width' => $w,
            'height' => $h,
            'at' => \date('c'),
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if ($json !== false) {
            @\file_put_contents($dir . '/.meta-tmp', $json);
            @\rename($dir . '/.meta-tmp', $dir . '/shot.json');
        }
    }

    /**
     * RGBA → PNG（**8 位 RGBA**，不丢 alpha）。
     *
     * ★ 为什么不去掉 alpha 省那 33%：去 alpha 要逐像素取 3 字节，PHP 里只能一个字节
     *   一个字节地拼 —— 800×600 实测 90 ms，而整帧编码的预算只有 30 ms 量级。
     *   改成 `str_split` + `implode` 只在 C 层动，同样的帧 3 ms 就完事。
     *
     * ★ alpha 是**预乘**的（Skia N32Premul）。表面在构造时就被清成不透明背景色，
     *   所以走到这里的像素 alpha 恒为 255，预乘与非预乘没有差别。真要做半透明窗口
     *   （`apply_material`）时，这里是唯一需要反预乘的地方。
     */
    public static function encode(string $rgba, int $w, int $h): string
    {
        // 每行插一个"无滤波"字节（None）：调试视图不值得为体积做 Paeth，
        // 而逐行做滤波会让编码时间翻倍。
        $raw = "\x00" . \implode("\x00", \str_split($rgba, $w * 4));

        $chunk = static fn(string $type, string $body): string
            => \pack('N', \strlen($body)) . $type . $body . \pack('N', \crc32($type . $body));

        return "\x89PNG\r\n\x1a\n"
            // colour type 6 = truecolour + alpha
            . $chunk('IHDR', \pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0))
            // level 1：实测 level 6 在这张表上要 40 ms 才省 15 KB，而调试视图 4 fps
            // 下每秒多传 60 KB 完全无所谓 —— 用 CPU 换延迟。
            . $chunk('IDAT', \gzcompress($raw, 1))
            . $chunk('IEND', '');
    }

    private function prune(): void
    {
        $files = \glob($this->shotDir() . '/s*.png') ?: [];
        if (\count($files) <= self::KEEP) {
            return;
        }

        \usort($files, static fn(string $a, string $b): int => (\filemtime($a) ?: 0) <=> (\filemtime($b) ?: 0));
        $drop = \array_slice($files, 0, \count($files) - self::KEEP);
        foreach ($drop as $f) {
            @\unlink($f);
        }
    }
}
