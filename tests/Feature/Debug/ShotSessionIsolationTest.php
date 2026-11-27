<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\Session\SessionQueries;
use Drift\Debug\Session\Thresholds;
use Drift\DevTools\Host\Shot;
use Drift\DevTools\View\Web;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Rendering\RasterCanvas;

/**
 * 画面通道按会话隔离：同一个目录跑两个宿主时，底图不能互相覆盖。
 *
 * 几何是按会话连 socket 读的，像素却走文件。两条通道只要有一条还是全局的，
 * web 就会拿 A 会话的图配 B 会话的框 —— 那看起来像布局坏了，其实是串了会话。
 */
final class ShotSessionIsolationTest extends SmokeTestCase
{
    private function tempDir(): string
    {
        $dir = \sys_get_temp_dir() . '/drift-shot-iso-' . \bin2hex(\random_bytes(6));
        \mkdir($dir . '/shot', 0775, true);

        return $dir;
    }

    public function testTwoSessionsWriteTheirOwnShotDirectories(): void
    {
        $dir = $this->tempDir();
        try {
            // 模拟 web 的心跳：按会话子目录写 want（Web::shotPing 也 touch 同一处）。
            \mkdir($dir . '/shot/session-a', 0775, true);
            \mkdir($dir . '/shot/session-b', 0775, true);
            \touch($dir . '/shot/session-a/want', \time() - 1);
            \touch($dir . '/shot/session-b/want', \time() - 1);
            $a = new Shot($dir, 0.0, 'session-a');
            $b = new Shot($dir, 0.0, 'session-b');

            check('A 会话拍到了图', $a->capture(new RasterCanvas(4, 2), 7, 1000.0));
            check('B 会话拍到了图', $b->capture(new RasterCanvas(2, 3), 9, 2000.0));

            $metaA = \json_decode((string) \file_get_contents($dir . '/shot/session-a/shot.json'), true);
            $metaB = \json_decode((string) \file_get_contents($dir . '/shot/session-b/shot.json'), true);
            check('A 的元数据尺寸是 A 的画布', ($metaA['width'] ?? 0) === 4 && ($metaA['height'] ?? 0) === 2,
                \json_encode($metaA));
            check('B 的元数据尺寸是 B 的画布', ($metaB['width'] ?? 0) === 2 && ($metaB['height'] ?? 0) === 3,
                \json_encode($metaB));
            check('A 的锚点没被 B 覆盖', ($metaA['frame'] ?? 0) === 7, \json_encode($metaA));
            check('两套会话各有独立的 want 标记', \is_file($dir . '/shot/session-a/want')
                && \is_file($dir . '/shot/session-b/want'), 'want 没按会话分开');
        } finally {
            $this->rmTree($dir);
        }
    }

    /** 订阅标记也要按会话：一个宿主被观察，不该让另一个会话跟着拍。 */
    public function testWantMarkerIsPerSession(): void
    {
        $dir = $this->tempDir();
        try {
            \mkdir($dir . '/shot/session-a', 0775, true);
            \touch($dir . '/shot/session-a/want', \time() - 1);
            $a = new Shot($dir, 0.0, 'session-a');
            $b = new Shot($dir, 0.0, 'session-b');

            check('A 看见订阅', $a->starving(1000.0), 'A 没认到自己的 want');
            check('B 没看见订阅', !$b->starving(1000.0), 'B 借用了 A 的 want');
        } finally {
            $this->rmTree($dir);
        }
    }

    /** web 侧必须把 attach 到的会话号传下去，否则多会话时又退回全局目录。 */
    public function testWebReadsTheAttachedSessionShotDirectory(): void
    {
        $src = (string) \file_get_contents(\dirname(__DIR__, 3) . '/devtools/bin/debug-web.php');
        check('web 把会话号交给视图', \str_contains($src, '->sessionId()'), $src);
        check('evidence 目录带上会话号', \str_contains($src, 'shotDirFor'), $src);

        $dir = $this->tempDir();
        try {
            $queries = new class implements SessionQueries {
                public function query(string $name, array $args = []): array
                {
                    return [];
                }
            };
            // A 会话的图写在自己的子目录，B 会话的图写在另一处；带 session-a 的 web
            // 必须拿到 A 那张，而不是全局目录或 B 的。
            $aDir = $dir . '/shot/session-a';
            \mkdir($aDir, 0775, true);
            \file_put_contents($aDir . '/latest.png', 'PNG-A');
            \file_put_contents($aDir . '/shot.json', \json_encode([
                'serial' => 3, 'frame' => 5, 'width' => 4, 'height' => 2, 'at' => 'now',
            ]));

            $web = new Web($queries, 'a', Thresholds::default(), __DIR__, null, null, $dir, 'session-a');
            $ping = \json_decode($web->route('GET', '/api/shot/ping')['body'], true);
            check('心跳读到 A 会话自己的图', ($ping['shot'] ?? null) === 3, \json_encode($ping));
            $shot = $web->route('GET', '/api/shot', ['serial' => 3]);
            check('取图拿到 A 会话那张', $shot['status'] === 200 && $shot['body'] === 'PNG-A',
                'status=' . $shot['status'] . ' body=' . \substr($shot['body'], 0, 20));

            $bWeb = new Web($queries, 'b', Thresholds::default(), __DIR__, null, null, $dir, 'session-b');
            $bPing = \json_decode($bWeb->route('GET', '/api/shot/ping')['body'], true);
            check('B 会话看不到 A 的图', ($bPing['shot'] ?? null) === null, \json_encode($bPing));
        } finally {
            $this->rmTree($dir);
        }
    }

    private function rmTree(string $dir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? \rmdir($file->getPathname()) : \unlink($file->getPathname());
        }
        \rmdir($dir);
    }
}
