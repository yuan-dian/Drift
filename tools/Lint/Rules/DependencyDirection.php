<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;

/**
 * §4.3 依赖规则：谁能依赖谁。
 *
 * GUI 仓用平铺 `namespace GUI;` + classmap，"分层"没有抓手；PSR-4 之后
 * "谁能依赖谁"第一次变得可静态检查 —— 这条规则就是那个抓手。
 */
final class DependencyDirection implements Rule
{
    /** §4.2 的层名（= `Drift\` 之后的第一个命名空间段） */
    private const LAYERS = [
        'Geometry', 'Platform', 'Text', 'Rendering', 'Input',
        'Widget', 'Layout', 'State', 'Commands', 'Theme', 'Routing', 'App',
    ];

    /**
     * §4.3 的允许集（含自身）。
     *
     * ★ 两处对 §4.3 的补正（都是"写全已有裁决"，不是新设计）：
     *
     *   ① `Theme` 行漏列了 `Rendering`，而 §4.2 把 `Color` 放在 `Drift\Rendering`，
     *      `ThemeData` 必须产出颜色值 ⇒ 放行 Rendering。
     *
     *   ② `Platform` 行漏列了 `Rendering` / `Input` / `Theme` 三条，而 §5.1 的接口
     *      签名已经要求它们 —— `Platform::surface(): ?SurfaceProvider` 的
     *      `SurfaceProvider::surface(): RenderSurface`（§7.1）、
     *      `InputSource::poll(): ?InputEvent`（§9.1 路由链把平台输出逐字写成
     *      `PointerEvent / KeyEvent`）、`Screen::systemTheme(): SystemTheme`（§9.2）。
     *
     *      ★ 这三条与 ① 的性质不同，值得说清楚**为什么是层放行而不是按文件例外**：
     *        它们全是**平台必须产出的输出类型**（表面 / 事件 / 系统主题），而这三样
     *        没有任何更低的层能拥有 —— `Rendering` 要 `Text`，`Input` 要 `Geometry`，
     *        都住在 `Platform` **上面**。按文件放行在这里不成立：每个平台的
     *        `Win32SurfaceProvider` / `Win32InputSource` / `Win32Screen` 都要引它们，
     *        例外会一路扩散到 `Platform\<Os>\` 的每一个文件。
     *        ⇒ 这不是"给 Platform 开后门"，而是 §4.3 的表把 Platform 的**出口**
     *          当成了入口来登记（表中 `Platform` 行只写了"输入侧"）。
     */
    private const ALLOWED = [
        'Geometry'  => ['Geometry', 'Errors'],
        'Platform'  => ['Platform', 'Geometry', 'Errors', 'Rendering', 'Input', 'Theme'],
        'Text'      => ['Text', 'Geometry', 'Errors'],
        'Rendering' => ['Rendering', 'Geometry', 'Text', 'Errors'],
        'Input'     => ['Input', 'Geometry', 'Errors', 'Text', 'Layout'],
        'Widget'    => ['Widget', 'Layout', 'State', 'Rendering', 'Text', 'Geometry', 'Input', 'Platform', 'Errors'],
        'Layout'    => ['Widget', 'Layout', 'State', 'Rendering', 'Text', 'Geometry', 'Input', 'Platform', 'Errors'],
        'State'     => ['Widget', 'Layout', 'State', 'Rendering', 'Text', 'Geometry', 'Input', 'Platform', 'Errors'],
        'Commands'  => ['Commands', 'Geometry', 'Errors', 'State'],
        'Theme'     => ['Theme', 'Widget', 'Layout', 'State', 'Text', 'Geometry', 'Rendering', 'Input', 'Errors'],
        'Routing'   => ['Routing', 'Widget', 'Layout', 'State', 'Text', 'Geometry', 'Rendering', 'Input', 'Errors'],
    ];

    /**
     * §4.3 的协议例外，**按文件**放行而不是放开整层。
     *
     * `BuildContext` 要把"解析后的主题"递给 `build()`（§3.4），它是 `Drift\Widget`
     * 里唯一可以引用 `Drift\Theme` 的文件。★ 写成文件级是有意的：层级放行会让
     * "别的 Widget 文件也去依赖 Theme"合法化，例外就扩散了。`src/Widget/UsesTheme.php`
     * 这条夹具就是守这条边界的。
     *
     * `State::handleCommand()` 是冒泡链第 ① 环（§11.4.3），签名要收 `CommandArg`
     * ⇒ 同一个形状的第二条例外。同样只登记一个文件：链的其余部分住 `Drift\App`
     * （那里 `Widget → Commands` 天然合法），Widget 侧只碰这一个参数类型。
     */
    private const FILE_EXCEPTIONS = [
        'src/Widget/BuildContext.php' => ['Theme'],
        'src/Widget/State.php' => ['Commands'],
    ];

    public function name(): string
    {
        return 'dependency-direction';
    }

    public function serves(): string
    {
        return 'G1';
    }

    public function summary(): string
    {
        return '层之间只能沿 §4.3 的允许方向依赖（如 Rendering 不得依赖 Widget）';
    }

    public function check(Project $project): array
    {
        $found = [];
        $seen = [];

        foreach ($project->files() as $file) {
            $from = $file->namespace();
            if (!str_starts_with($from, Project::FRAMEWORK_PREFIX)) {
                continue; // 业务代码不参与分层检查（它走 internal-leak）
            }
            $fromLayer = $this->layerOf($from);
            if ($fromLayer === null || !isset(self::ALLOWED[$fromLayer])) {
                continue; // 工具链（Drift\Dev）与 Errors 本身不设限
            }

            foreach ($file->references() as $reference) {
                $to = $reference['name'];
                if (!str_starts_with($to, Project::FRAMEWORK_PREFIX)) {
                    continue;
                }
                $toLayer = $this->layerOf($to);
                if ($toLayer === null || !in_array($toLayer, self::LAYERS, true)) {
                    continue; // Drift\Dev 或 Drift\Errors 这类不在层表里的
                }
                if (in_array($toLayer, self::ALLOWED[$fromLayer], true)) {
                    continue;
                }
                if (in_array($toLayer, self::FILE_EXCEPTIONS[$file->path()] ?? [], true)) {
                    continue; // 协议例外，且仅限登记过的那一个文件
                }

                // 一个 (文件, 目标) 只报一次 —— 同一类型在文件里出现多次不是多条问题
                $key = $file->path() . ':' . $to;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $reference['line'],
                    sprintf('%s 不得依赖 %s（%s）', $fromLayer, $toLayer, $to)
                );
            }
        }

        return $found;
    }

    /** 取 `Drift\<Layer>\...` 的层名；不在层表里返回 null */
    private function layerOf(string $fqn): ?string
    {
        $rest = substr($fqn, strlen(Project::FRAMEWORK_PREFIX));
        if ($rest === false || $rest === '') {
            return null;
        }
        $segment = explode('\\', $rest)[0];
        return in_array($segment, self::LAYERS, true) ? $segment : null;
    }
}
