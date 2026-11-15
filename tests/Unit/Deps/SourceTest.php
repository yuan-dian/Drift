<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Deps;

use Drift\Dev\Deps\Source;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `Source::localPath()` —— 「这是网址还是手里已有的那个文件」。
 *
 * ★ 为什么值得单独测：判错的后果是 curl 报 `Port number was not a decimal number`，
 *   与真实原因（文件不存在 / 路径写错）毫无关系；而这条路径在首个 release 之前
 *   是**唯一**能装起来的路。
 * ★ 只测判定，不碰 `fetch-deps.php` 的下载/解压 —— 后者解压到仓库根，不能在单测里跑。
 */
final class SourceTest extends SmokeTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/drift-deps-source-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testExistingFileIsLocal(): void
    {
        $zip = $this->dir . '/drift deps.zip';
        file_put_contents($zip, 'PK');

        $got = Source::localPath($zip);
        check('Source · 含空格的裸路径 ⇒ 本地文件', $got !== null, 'got=' . var_export($got, true));
        check('Source · 返回绝对路径且能读回内容', $got !== null && file_get_contents($got) === 'PK');
        check('Source · 裸路径不是网址', !Source::isUrl($zip));

        if (DIRECTORY_SEPARATOR === '\\') {
            $withBackslashes = str_replace('/', '\\', $zip);
            check(
                'Source · Windows 反斜杠路径同样认',
                Source::localPath($withBackslashes) === $got,
                'got=' . var_export(Source::localPath($withBackslashes), true)
            );
        }

        $this->flushChecks();
    }

    public function testFileUriIsLocal(): void
    {
        $zip = $this->dir . '/drift deps.zip';
        file_put_contents($zip, 'PK');
        $absolute = (string) realpath($zip);

        $uri = 'file:///' . ltrim(str_replace('\\', '/', $absolute), '/');
        check('Source · file:// URI ⇒ 本地文件', Source::localPath($uri) === $absolute, 'uri=' . $uri);

        $encoded = str_replace(' ', '%20', $uri);
        check('Source · 百分号编码的空格能解回来', Source::localPath($encoded) === $absolute);

        $this->flushChecks();
    }

    public function testNetworkAndMissingAreNotLocal(): void
    {
        $cases = [
            'https 直链' => 'https://github.com/o/r/releases/download/deps-v1/drift-deps-win-x64.zip',
            'http 直链' => 'http://mirror.example.com/drift/drift-deps-win-x64.zip',
            'file:// 指向不存在的文件' => 'file:///' . ltrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/no-such-drift-deps.zip',
            '裸路径但文件不存在' => $this->dir . '/no-such.zip',
            '空串' => '',
        ];
        foreach ($cases as $name => $raw) {
            check(
                'Source · ' . $name . ' ⇒ 不是本地文件',
                Source::localPath($raw) === null,
                'got=' . var_export(Source::localPath($raw), true)
            );
        }

        check('Source · https 是网址', Source::isUrl('https://example.com/a.zip'));
        check('Source · file:// 是网址', Source::isUrl('file:///D:/a.zip'));
        check('Source · 只有主机名不算网址', !Source::isUrl('mirror.example.com/drift'));
        $this->flushChecks();
    }

    public function testSidecarSha(): void
    {
        $zip = $this->dir . '/drift-deps-win-x64.zip';
        file_put_contents($zip, 'PK');
        $sha = str_repeat('aB', 32);
        file_put_contents($zip . '.sha256', $sha . "  drift-deps-win-x64.zip\n");

        check('Source · 旁车 .sha256 取首列并小写', Source::localSha($zip) === strtolower($sha));

        file_put_contents($zip . '.sha256', '不是校验值');
        check('Source · 旁车内容不合法 ⇒ null（lock 仍是权威）', Source::localSha($zip) === null);

        @unlink($zip . '.sha256');
        check('Source · 没有旁车 ⇒ null（不报错）', Source::localSha($zip) === null);
        $this->flushChecks();
    }
}
