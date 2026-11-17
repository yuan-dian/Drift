<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Build;

use Drift\Dev\Tests\Support\SmokeTestCase;

/** dev 壳清单把 VC 运行库当可选时，缺 DLL 的包也能报绿。 */
final class DevShellManifestTest extends SmokeTestCase
{
    public function testMsvcRuntimesAreRequired(): void
    {
        $root = dirname(__DIR__, 3);
        $script = $root . '/tools/ci/dev-shell-files.php';
        require_once $script;

        $required = array_keys(devShellRequiredFiles());
        check(
            '清单把三个 MSVC 运行库列为必需（二进制导入表都依赖）',
            array_intersect(['vcruntime140.dll', 'vcruntime140_1.dll', 'msvcp140.dll'], $required) === ['vcruntime140.dll', 'vcruntime140_1.dll', 'msvcp140.dll'],
        );

        $dir = sys_get_temp_dir() . '/drift-dev-shell-manifest-' . bin2hex(random_bytes(4));
        mkdir($dir . '/ext', 0777, true);
        try {
            foreach ($required as $relative) {
                file_put_contents($dir . '/' . $relative, 'x');
            }
            $run = static function () use ($script, $dir): array {
                exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($dir) . ' 2>&1', $out, $rc);
                return [$rc, implode("\n", $out)];
            };
            [$rc, $out] = $run();
            check('齐包校验通过', $rc === 0, $out);

            unlink($dir . '/vcruntime140.dll');
            [$rc, $out] = $run();
            check('缺 VCRUNTIME140.dll 必须报红（变异守住清单）', $rc !== 0, $out);
        } finally {
            foreach ($required as $relative) {
                @unlink($dir . '/' . $relative);
            }
            @rmdir($dir . '/ext');
            @rmdir($dir);
        }

        $this->flushChecks();
    }
}
