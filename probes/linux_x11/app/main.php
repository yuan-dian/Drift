<?php
declare(strict_types=1);

use Drift\App\Application;
use Drift\App\EventLoop;
use Drift\App\Job;
use Drift\App\NamedJob;
use Drift\App\ThreadTaskHost;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Input\CursorShape;
use Drift\Input\InputEvent;
use Drift\Input\KeyEvent;
use Drift\Input\ScrollEvent;
use Drift\Input\WindowDeactivatedEvent;
use Drift\Platform\WakeReason;
use Drift\Platform\X11\X11Platform;
use Drift\Widget\Center;
use Drift\Widget\Text;
use Drift\Widget\Widget;

use function drift_io_handled;
use function drift_io_poll;
use function drift_io_start;
use function drift_io_stop;
use function drift_io_submit;
use function drift_io_threads;
use function drift_x11_now_ms;
use function drift_x11_window_state;

final class X11ProbeApplication extends Application
{
    private int $frames = 0;
    private ?X11Platform $platform = null;
    private int $handle = 0;
    private int $target = 600;

    public function configure(X11Platform $platform, int $handle, int $target): void
    {
        $this->platform = $platform;
        $this->handle = $handle;
        $this->target = $target;
    }

    public function frameCountProbe(): int
    {
        return $this->frames;
    }

    /** 把桥翻译后的输入打出来，让 build.sh 能判「键鼠真的到了框架」。 */
    public function dispatchInput(InputEvent $event, ?float $timestampMs = null): ?CursorShape
    {
        if ($event instanceof KeyEvent && $event->isText()) {
            echo '[INPUT] char=' . $event->text . "\n";
        } elseif ($event instanceof ScrollEvent) {
            echo '[INPUT] scroll delta=' . $event->delta . ' axis='
                . ($event->axis === Axis::Horizontal ? 'horizontal' : 'vertical') . "\n";
        } elseif ($event instanceof WindowDeactivatedEvent) {
            // 窗口失活事件不进命中链，但会经 Application::dispatchInput，
            // 这里打标让 build.sh 能验「桥 FocusOut → translate → 框架」整条链。
            echo "[EVENT] deactivate received at frame {$this->frames}\n";
        }

        return parent::dispatchInput($event, $timestampMs);
    }

    public function wantsFrame(): bool
    {
        return true;
    }

    public function closeRequested(): bool
    {
        echo "[EVENT] close-requested received at frame {$this->frames}\n";

        return true;
    }

    public function buildRoot(): Widget
    {
        return Center::make()->child(Text::make('X11 Drift'));
    }

    public function title(): string
    {
        return 'X11 Drift';
    }

    public function frame(float $timestampMs, BoxConstraints $constraints): void
    {
        parent::frame($timestampMs, $constraints);
        $this->frames++;
        if ($this->frames % 60 === 0) {
            echo "[HEARTBEAT] frame={$this->frames}\n";
        }

        // 兜底自毁：注入失败时也能 10 秒内收工，脚本靠事件输出判 FAIL。
        if ($this->frames === $this->target && $this->platform !== null) {
            echo "-- {$this->frames} frames reached, destroying window --\n";
            $this->platform->window()->destroy($this->handle);
        }
    }
}

/** IO 池夹具：`echo`（验字符串往返 ABI）与 `sleep`（验跨线程投递） */
final class IoEchoJob implements NamedJob
{
    public function __construct(private readonly string $text)
    {
    }

    public function name(): string
    {
        return 'echo';
    }

    public function args(): array
    {
        return ['text' => $this->text];
    }

    public function run(): mixed
    {
        return $this->text;
    }
}

final class IoSleepJob implements NamedJob
{
    public function __construct(private readonly int $ms)
    {
    }

    public function name(): string
    {
        return 'sleep';
    }

    public function args(): array
    {
        return ['ms' => $this->ms];
    }

    public function run(): mixed
    {
        return $this->ms;
    }
}

// 解码测试图（8×8，四象限红/绿/蓝/灰），base64 内嵌避免新增二进制 fixture。
// PNG/WebP 无损 ⇒ 通道序判据用它们；JPEG 有损 ⇒ 只验尺寸/长度与"红象限 R≫B"。
const PNG_8X8 = 'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAAIUlEQVR4nGP8z4AAjEgcJiRxFEC6BCMDA8LghoZGWtgBAGjwBI6UxJUVAAAAAElFTkSuQmCC';
const WEBP_8X8 = 'UklGRjoAAABXRUJQVlA4TC4AAAAvB8ABAB8gECA4d25DQFB03XICAYJz5+Y/8MlRgYK2bZiSLMmSfBuIiP6H+OEP';
const JPG_8X8 = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAAIAAgDAREAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD8/f8AgmL8ff8AhKf+F3f8Un9h+w/8K1/5jv2rzftX/Cff9Qa32bPs/wDt7t/8O35j9ph+wz/4ly/4gp/x1F/rl/rl/wARI/5sn/q9/Z3+r3+oX/V3M8+ufXP7c/6hfq/1X/l/7f8Acz+2C8Sv+K0f/Eu//CL/AMS2f8S2f8Rb/wCZl/xGL/XT/iMX/EMv+oDws/1c/wBXP+IWf9T3+1/7d/5lf9l/8KP/2Q==';

/**
 * 图片解码 P0 判据：drift_image_decode（Skia codec）解 PNG/JPEG/WebP。
 *
 * 放在建窗前（headless，无需 X/渲染）—— 解码是纯数据路径。输出 `[DEC]`，
 * build.sh grep 成失败条件。四象限已知色验通道序（R/B 互换会让判据红）。
 */
function probeImageDecode(string $dir): void
{
    echo "=== Image decode: Skia codec ===\n";
    if (!\function_exists('drift_image_decode')) {
        echo "[FAIL] drift_image_decode 不存在（stub 没声明或桥没链上）\n";
        exit(1);
    }
    @mkdir($dir);
    $samples = [
        'png' => PNG_8X8,
        'webp' => WEBP_8X8,
        'jpg' => JPG_8X8,
    ];
    $paths = [];
    foreach ($samples as $ext => $b64) {
        $p = $dir . '/sample.' . $ext;
        \file_put_contents($p, \base64_decode($b64, true));
        $paths[$ext] = $p;
    }

    // 三种格式都能解成 8×8 / 8*8*4 字节。
    $ok = true;
    $dims = [];
    foreach ($paths as $ext => $p) {
        $decoded = \drift_image_decode($p);
        $w = $decoded['w'] ?? 0;
        $h = $decoded['h'] ?? 0;
        $rgba = $decoded['rgba'] ?? '';
        $len = \strlen($rgba);
        $dims[$ext] = "{$w}x{$h}/{$len}";
        echo "[DEC] {$ext} {$w}x{$h} bytes={$len}\n";
        if ($w !== 8 || $h !== 8 || $len !== 8 * 8 * 4) {
            $ok = false;
        }
    }
    echo '[DEC] dims=' . \implode(' ', $dims) . "\n";

    // 通道序：PNG/WebP 无损，取左上红象限(1,1)与左下蓝象限(1,5)的字节。
    // 红点应 R≈255,B≈0；蓝点应 B≈255,R≈0。R/B 互换会让这两条同时反。
    foreach (['png', 'webp'] as $ext) {
        $decoded = \drift_image_decode($paths[$ext]);
        $rgba = (string) ($decoded['rgba'] ?? '');
        // 像素索引 = (y * width + x) * 4；别把 x/y 写反：
        // 红象限是左上 (x=1,y=1)，蓝象限是左下 (x=1,y=5)。
        $redOfs = (1 * 8 + 1) * 4;    // (y=1,x=1) 左上红象限
        $blueOfs = (5 * 8 + 1) * 4;   // (y=5,x=1) 左下蓝象限
        $r = \ord($rgba[$redOfs]);
        $b = \ord($rgba[$redOfs + 2]);
        $br = \ord($rgba[$blueOfs]);
        $bb = \ord($rgba[$blueOfs + 2]);
        echo "[DEC] {$ext} red(R={$r},B={$b}) blue(R={$br},B={$bb})\n";
        if (!($r > 240 && $b < 16 && $bb > 240 && $br < 16)) {
            $ok = false;
        }
    }

    // maxWidth 缩放：4 = 原宽一半，高也应减半，字节数按新尺寸。
    $scaled = \drift_image_decode($paths['png'], 4);
    $sw = $scaled['w'] ?? 0;
    $sh = $scaled['h'] ?? 0;
    $slen = \strlen((string) ($scaled['rgba'] ?? ''));
    echo "[DEC] scaled maxWidth=4 => {$sw}x{$sh} bytes={$slen}\n";
    if ($sw !== 4 || $sh !== 4 || $slen !== 4 * 4 * 4) {
        $ok = false;
    }

    // 失败路径：不存在的文件必须返回空数组（不抛、不崩）。
    $bad = \drift_image_decode($dir . '/does-not-exist.jpg');
    echo '[DEC] missing=' . (\is_array($bad) && $bad === [] ? 'empty' : 'NOT_EMPTY') . "\n";
    if ($bad !== []) {
        $ok = false;
    }

    if (!$ok) {
        echo "[FAIL] image decode verdict\n";
        exit(1);
    }
    echo "[DEC] RESULT: PASS\n";
}

/**
 * IO 池 P0 判据：core/io_pool（池体）+ 自管道唤醒 + ThreadTaskHost 端到端。
 *
 * 放在建窗之前跑（headless）：此时没有 X 事件干扰，waitEvents(-1) 若不被 IO
 * 唤醒就会挂死 —— build.sh 的 90s 超时会抓住它，不会假绿。
 * 输出前缀统一 `[IO]`，由 build.sh grep 成失败条件。
 */
function probeIoPool(X11Platform $platform): void
{
    echo "=== IO pool: core + self-pipe wake ===\n";

    // 起池（幂等）。空实现版恒返回 1 但 threads()=0，这里必须真起线程。
    $threads = drift_io_start(4);
    echo "[IO] threads={$threads}\n";
    if ($threads < 1) {
        echo "[FAIL] io pool not started\n";
        exit(1);
    }

    // J1 唤醒 + 0 丢失：submit(echo) → waitEvents(-1) 必须返回 Woken（不是
    // Timeout / Message），poll 必须有结果，handled() 累加必须等于提交数。
    $woken = 0;
    $rows = 0;
    $rounds = 8;
    for ($i = 1; $i <= $rounds; $i++) {
        drift_io_submit(1000 + $i, 'echo', 0, 'io' . $i);
        $why = $platform->waitEvents(-1.0);
        if ($why === WakeReason::Woken) {
            $woken++;
        }
        $raw = drift_io_poll();
        $decoded = \json_decode($raw, true);
        $rows += \is_array($decoded) ? \count($decoded) : 0;
    }
    $handled = drift_io_handled();
    echo "[IO] wake woken={$woken}/{$rounds} rows={$rows} handled={$handled}\n";

    // J2 ThreadTaskHost 端到端：两条 Future 必须 resolve（sleep→20 / echo→hi）。
    $host = new ThreadTaskHost(2);
    $got = [];
    $errors = [];
    $host->submit(new IoSleepJob(20))
        ->then(static function (mixed $v) use (&$got): void {
            $got['sleep'] = $v;
        })
        ->catch(static function (\Throwable $e) use (&$errors): void {
            $errors['sleep'] = $e->getMessage();
        });
    $host->submit(new IoEchoJob('hi'))
        ->then(static function (mixed $v) use (&$got): void {
            $got['echo'] = $v;
        })
        ->catch(static function (\Throwable $e) use (&$errors): void {
            $errors['echo'] = $e->getMessage();
        });

    $deadline = $platform->now() + 5000.0;
    $wakes = 0;
    while ($host->pending() > 0 && $platform->now() < $deadline) {
        if ($platform->waitEvents(-1.0) === WakeReason::Woken) {
            $wakes++;
        }
        $host->drain(static function ($completion): void {
            if ($completion->error !== null) {
                $completion->future->reject($completion->error);
                return;
            }
            $completion->future->resolve($completion->value);
        });
    }
    echo '[IO] e2e sleep=' . ($got['sleep'] ?? 'MISSING')
        . ' echo=' . ($got['echo'] ?? 'MISSING')
        . ' errors=' . (\count($errors) === 0 ? 'none' : \implode('|', $errors))
        . " wakes={$wakes}\n";

    // J3 关池：shutdown 后 threads() 必须是 0（join 完 worker）。
    $host->shutdown();
    $after = drift_io_threads();
    echo "[IO] threads_after_stop={$after}\n";

    if ($woken !== $rounds || $rows !== $rounds || $handled < $rounds
        || ($got['sleep'] ?? null) !== 20 || ($got['echo'] ?? null) !== 'hi'
        || \count($errors) !== 0 || $after !== 0) {
        echo "[FAIL] io pool verdict\n";
        exit(1);
    }
    echo "[IO] RESULT: PASS\n";
}

/**
 * 窗口管理判据：ICCCM 最小尺寸 + EWMH 最大化 + ICCCM 最小化。
 *
 * 用独立临时窗口（标题 WM Probe）跑，测完 destroy —— 不碰主窗口，避免
 * 最大化/图标化干扰后续输入注入与关窗。每条都走「PHP WindowHost 方法 →
 * 桥写 X server → 桥读回真实属性」闭环，不依赖 xprop/xwininfo 外部工具。
 */
function probeWindowManagement(X11Platform $platform): void
{
    echo "=== Window management: ICCCM + EWMH ===\n";
    $win = $platform->window();
    $tmp = $win->createWindow('WM Probe', 320, 240);
    if ($tmp <= 0) {
        echo "[WM] FAIL createWindow\n";
        exit(1);
    }
    $win->show($tmp);

    // J1 最小尺寸（XSizeHints.min_width/min_height）。
    $win->setMinSize($tmp, 400, 300);
    $state = drift_x11_window_state($tmp);
    $minW = $state['min_w'] ?? 0;
    $minH = $state['min_h'] ?? 0;
    echo "[WM] min {$minW}x{$minH}\n";

    // J2 几何还原（XMoveResizeWindow）+ 位置读回。
    // ★ WM 会拦截 ConfigureRequest（SubstructureRedirect）异步处理 ⇒ XFlush 后
    //   立即读回拿到的是旧几何（真机实测：place 400x300 读回 320x240）。轮询等 WM 落地。
    $rect = Rect::fromLtrb(120, 140, 520, 440);   // 400x300 @ (120,140)
    $win->place($tmp, $rect, false);
    $deadline = drift_x11_now_ms() + 2000;
    do {
        $sized = $win->clientSize($tmp);
        if ((int) $sized->width === 400 && (int) $sized->height === 300) break;
        \usleep(20000);
    } while (drift_x11_now_ms() < $deadline);
    $pos = $win->position($tmp);
    $pw = (int) $sized->width;
    $ph = (int) $sized->height;
    $px = $pos === null ? -1 : (int) $pos->x;
    $py = $pos === null ? -1 : (int) $pos->y;
    echo "[WM] place {$pw}x{$ph} @ {$px},{$py}\n";

    // J3 最大化（EWMH ClientMessage → WM 异步置位；轮询读回，上限 2s）。
    $win->maximizeToggle($tmp);
    $deadline = drift_x11_now_ms() + 2000;
    do {
        $maxed = $win->isMaximized($tmp);
        if ($maxed) break;
        \usleep(20000);
    } while (drift_x11_now_ms() < $deadline);
    $state = drift_x11_window_state($tmp);
    $mv = !empty($state['max_v']) ? '1' : '0';
    $mh = !empty($state['max_h']) ? '1' : '0';
    $im = $maxed ? '1' : '0';
    echo "[WM] max_v={$mv} max_h={$mh} isMaximized={$im}\n";

    // J4 最小化（XIconifyWindow → WM_STATE=Iconic；同样轮询）。
    $win->minimize($tmp);
    $deadline = drift_x11_now_ms() + 2000;
    do {
        $state = drift_x11_window_state($tmp);
        $iconic = !empty($state['iconic']);
        if ($iconic) break;
        \usleep(20000);
    } while (drift_x11_now_ms() < $deadline);
    echo '[WM] iconic=' . ($iconic ? '1' : '0') . "\n";

    $win->destroy($tmp);

    $ok = $minW === 400 && $minH === 300
        && $pw === 400 && $ph === 300
        && $px >= 0 && $py >= 0
        && $mv === '1' && $mh === '1' && $im === '1'
        && $iconic;
    if (!$ok) {
        echo "[FAIL] window management verdict\n";
        exit(1);
    }
    echo "[WM] RESULT: PASS\n";
}

function main(): void
{
    echo "=== X11 E2E: real Application on EventLoop ===\n";
    $platform = new X11Platform();
    echo 'configDir=' . $platform->configDir() . "\n";
    echo 'now=' . $platform->now() . "\n";

    // IO 池 P0 判据（建窗前，headless，无 X 事件干扰）。
    probeIoPool($platform);

    // 图片解码 P0 判据（纯数据路径，建窗前 headless 跑）。
    probeImageDecode(\sys_get_temp_dir() . '/drift-decode-samples');

    // 窗口管理判据（临时窗口，测完销毁 —— 不干扰下面主窗口的输入/关窗）。
    probeWindowManagement($platform);

    $handle = $platform->window()->createWindow('X11 Drift', 320, 240);
    echo "handle={$handle}\n";
    if ($handle <= 0) {
        echo "[FAIL] createWindow\n";
        exit(1);
    }
    $platform->window()->show($handle);
    echo "shown\n";

    $constraints = $platform->screen($handle)->viewport();
    echo "viewport {$constraints->maxWidth}x{$constraints->maxHeight}\n";

    // 表面尺寸由 X11SurfaceProvider 创建时现读客户区；attach 的同步首帧不再依赖 pump。
    $provider = $platform->surface($handle);
    if ($provider === null) {
        echo "[FAIL] surface provider\n";
        exit(1);
    }
    echo '[BACKEND] ' . (\function_exists('drift_skia_canvas_create') ? 'skia' : 'raster') . "\n";
    if (\function_exists('drift_skia_font_ready')) {
        echo '[FONT] ready=' . (\drift_skia_font_ready() ? '1' : '0') . "\n";
    }
    echo "surface ready\n";

    $app = new X11ProbeApplication();
    $app->attach($provider->surface(), $constraints);
    $app->configure($platform, $handle, (int) (\getenv('DRIFT_X11_TARGET_FRAMES') ?: 600));
    echo "attached\n";

    EventLoop::run($platform, [$handle => $app]);

    echo 'loop exited, frames=' . $app->frameCountProbe() . "\n";
    echo "=== RESULT: PASS ===\n";
}
