<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Deps;

use Drift\Dev\Tests\Support\SmokeTestCase;

require_once \dirname(__DIR__, 3) . '/tools/version.php';

/**
 * `driftVersionCheck()` 的 Skia 锚点对账。
 *
 * `provenance` 是 `skiaRevision` 的函数，两处各存一份就是为了能对账：
 * 只改一边（比如 pin 了 commit 却留着 `legacy`）等于谎报来源，
 * 而此刻版本、tag、SHA256 那些判据全都是绿的。
 */
final class VersionAnchorTest extends SmokeTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = \sys_get_temp_dir() . '/drift-version-' . \bin2hex(\random_bytes(4));
        \mkdir($this->root . '/src/Core', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['VERSION', 'DEPS_VERSION', 'SKIA_REVISION', 'deps.lock.json', 'CHANGELOG.md', 'README.md', 'src/Core/Version.php'] as $f) {
            @\unlink($this->root . '/' . $f);
        }
        @\rmdir($this->root . '/src/Core');
        @\rmdir($this->root . '/src');
        @\rmdir($this->root);
        parent::tearDown();
    }

    /** 造一棵最小的锚点树：只包含 `driftVersionCheck()` 会读的文件 */
    private function seed(string $rev, string $provenance): void
    {
        $version = '0.1.0-preview.1';
        $hash = \str_repeat('a', 64);

        \file_put_contents($this->root . '/VERSION', $version . "\n");
        \file_put_contents($this->root . '/DEPS_VERSION', "deps-v1\n");
        \file_put_contents($this->root . '/SKIA_REVISION', $rev . "\n");
        \file_put_contents($this->root . '/CHANGELOG.md', "## v{$version}\n");
        \file_put_contents($this->root . '/README.md', $hash . "\n");
        \file_put_contents(
            $this->root . '/src/Core/Version.php',
            "<?php\npublic const string VERSION = '{$version}';\npublic const string DEPS_TAG = 'deps-v1';\n",
        );
        \file_put_contents($this->root . '/deps.lock.json', (string) \json_encode([
            'driftVersion' => $version,
            'depsTag' => 'deps-v1',
            'skiaRevision' => $rev,
            'provenance' => $provenance,
            'sha256' => $hash,
        ]));
    }

    /** @return string 全部错误拼成一行，方便断言目标错误在不在 */
    private function errors(): string
    {
        return \implode("\n", \driftVersionCheck($this->root)['errors']);
    }

    public function testPinnedRevisionRejectsLegacyProvenance(): void
    {
        $this->seed(\str_repeat('a', 40), 'legacy');

        check(
            '版本锚点 · pin 了 commit 却标 legacy 要报红（等于谎报来源）',
            \str_contains($this->errors(), 'provenance 应为 ci'),
            $this->errors(),
        );
        $this->flushChecks();
    }

    public function testUnpinnedRejectsCiProvenance(): void
    {
        $this->seed('unpinned', 'ci');

        check(
            '版本锚点 · unpinned 却标 ci 要报红（没有 commit 就没有 ci 重建）',
            \str_contains($this->errors(), 'provenance 应为 legacy'),
            $this->errors(),
        );
        $this->flushChecks();
    }

    public function testConsistentAnchorsPass(): void
    {
        $this->seed(\str_repeat('a', 40), 'ci');

        check(
            '版本锚点 · 一致的锚点树全绿（上面两条不是在报无关错误）',
            \driftVersionCheck($this->root)['ok'],
            $this->errors(),
        );
        $this->flushChecks();
    }
}
