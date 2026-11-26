<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Debug\Session\GeometryCapture;
use Drift\Debug\Session\RecordOptions;
use Drift\Debug\Session\Recorder;
use Drift\Debug\Session\Script;
use Drift\Dev\Tests\Support\SmokeTestCase;

/** 采集预算判据：预算是一份不可变的值，不是散在门面里的布尔。 */
final class RecordOptionsTest extends SmokeTestCase
{
    public function testPresetsDeclareTheirDomains(): void
    {
        $dev = RecordOptions::default();
        check('开发默认不抓正文', $dev->bodies() === false);
        check('开发默认记变化几何与网络骨架', $dev->geometry() === GeometryCapture::Changed && $dev->network());
        check('开发默认脱敏', $dev->redact());

        $resident = RecordOptions::framesOnly(12);
        check('常驻档只留帧因果', $resident->geometry() === GeometryCapture::Off
            && !$resident->network() && !$resident->bodies());
        check('常驻档仍保留窗口与脱敏', $resident->capacity() === 12 && $resident->redact());

        $full = RecordOptions::full(9);
        check('完整档开几何全量、正文与异步', $full->geometry() === GeometryCapture::All
            && $full->bodies() && $full->async());

        $this->flushChecks();
    }

    public function testLegacyArgumentsNormalizeAtOneFunnel(): void
    {
        $script = new Script('table', 800.0, 600.0);
        $options = RecordOptions::fromLegacy(7, $script, false);

        check('旧容量 / 脚本 / 脱敏都归到值对象', $options->capacity() === 7
            && $options->script()?->digest() === $script->digest()
            && $options->redact() === false, \json_encode($options->toManifest()));
        check('旧调用不偷偷开正文', $options->bodies() === false);

        try {
            RecordOptions::fromLegacy($options, $script, true);
            check('传了值对象又给旧参数应报错', false);
        } catch (\InvalidArgumentException) {
            check('传了值对象又给旧参数应报错', true);
        }

        $this->flushChecks();
    }

    public function testBodiesCannotOutliveTheNetworkSkeleton(): void
    {
        try {
            RecordOptions::of(network: false, bodies: true);
            check('正文不能脱离网络骨架', false);
        } catch (\InvalidArgumentException) {
            check('正文不能脱离网络骨架', true);
        }

        $this->flushChecks();
    }

    public function testManifestRoundTripKeepsTheBudget(): void
    {
        $source = RecordOptions::of(
            capacity: 31,
            redact: false,
            geometry: GeometryCapture::Off,
            network: true,
            bodies: true,
            async: true,
        );
        $back = RecordOptions::fromManifest($source->toManifest());

        check('预算逐项往返一致', $back->toManifest() === $source->toManifest(),
            \json_encode([$source->toManifest(), $back->toManifest()]));
        check('脚本不进 manifest（它有自己的证据域）', !\array_key_exists('script', $source->toManifest()));

        $this->flushChecks();
    }

    public function testDerivedOptionsKeepTheAsyncBudget(): void
    {
        $source = RecordOptions::of(paint: true, async: true);
        check('withPaint 不把 async 冲回默认', $source->withPaint(false)->async() === true);
        check('withAsync 不把 paint 冲回默认', $source->withAsync(false)->paint() === true);

        $this->flushChecks();
    }

    public function testRecorderExposesTheInstalledBudget(): void
    {
        $options = RecordOptions::framesOnly(4);
        $recorder = Recorder::install($options);
        try {
            check('安装后读回同一份预算', $recorder->options()->toManifest() === $options->toManifest(),
                \json_encode($recorder->options()->toManifest()));
            check('帧窗口按预算裁剪', $recorder->stats()['capacity'] === 4);
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }
}
