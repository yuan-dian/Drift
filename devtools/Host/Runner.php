<?php
declare(strict_types=1);

namespace Drift\DevTools\Host;

use Drift\DevTools\App\DebugSession;
use Drift\Debug\Session\RecordOptions;
use Drift\Debug\Session\Script;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * "建应用 → 挂表面 → 开会话 → 备好副作用"这一段，本地跑与宿主进程共用一份。
 *
 * ## 为什么单独抽出来
 *
 * 挂载耗时（`mountMs`）是页面级调试的第二个问题，而它只能由**挂载的那一方**量。
 * 这段代码若在 CLI 里一份、宿主里一份，两份量出来的口径迟早不同 —— 一次把挂载
 * 包进首帧，一次没有，"首帧成本"这一列就骗人。
 *
 * 副作用（`resolve`）同理：它是环境的一部分，必须由**造页面的人**注册。
 * 宿主不知道"这一页怎么取数"，只知道脚本 meta 里写着 `rows=N`。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class Runner
{
    /**
     * @param array<string, scalar|null> $meta 脚本环境（`rows` 取数替身、`page` 是否路由 id）
     *
     * @return array{session: DebugSession, mountMs: float, surface: SurfaceProbe}
     */
    public static function open(
        string $label,
        float $width,
        float $height,
        array $meta = [],
        ?Script $skeleton = null,
        ?RecordOptions $options = null,
    ): array {
        $rows = (int) ($meta['rows'] ?? 0);
        [$app, $source] = Target::app($label, (bool) ($meta['page'] ?? false), $rows);

        $constraints = BoxConstraints::tight(new Size($width, $height));

        // ★ 挂载**不在任何帧里**（`buildRoot()` + 首次 layout 都发生在 `attach()` 内），
        //   而"打开这一页要多久"恰恰是页面级调试的第二个问题。单独量、单独报，别让它
        //   混进首帧 —— 混进去会让"首帧成本"这一列骗人。
        $surface = new SurfaceProbe();
        // ★ 必须显式 resize：`attach()` **不管像素口径**（真机上那一步由平台壳按客户区
        //   做，见 `Win32Platform` 的 WM_SIZE）。夹具默认 0×0 ⇒ `raster()` 会造一张
        //   `max(1,0)` 的画布，于是**每一次像素读取都是同一张 1×1**：读像素的断言
        //   全部恒绿，`pixelAt()` 还会越界警告。这里漏过一次，靠"高亮前后像素为什么
        //   一样"才逮到。
        $surface->resize((int) \round($width), (int) \round($height), 1.0);
        $start = \hrtime(true);
        $app->attach($surface, $constraints);
        $mountMs = (\hrtime(true) - $start) / 1e6;

        $options = ($options ?? RecordOptions::default())->withScript($skeleton);
        $session = DebugSession::open($app, $constraints, $options);

        if ($source !== null) {
            $session->defineEffect('resolve', static fn(): bool => $source->resolveNext());
        }

        // 表面一并交出去：读像素是"叠加层真的上屏了没"的唯一硬证据，
        // 而它只能由**挂表面的人**拿到 —— 会话不管表面（见 `DebugSession` 类注释）。
        return ['session' => $session, 'mountMs' => $mountMs, 'surface' => $surface];
    }
}
