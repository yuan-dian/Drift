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
        'Logging',
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
     *
     *   ③ `App` 行（2026-10-21 补）：§4.3 原表写「`App | 全部 | apps`」而这里
     *      没有对应行 —— 之前靠 `check()` 的"表里没有就跳过"顺带放行（行为
     *      等价，但表与文档不一致，读代码的人无法确认这个放行是有意的）。
     *      显式列出全部层 ⇒ 放行是**写下来的决定**，且 `App` 文件从此也参与
     *      检查（将来若要把某条边收紧，改这一行就有抓手）。
     */
    private const ALLOWED = [
        'Geometry'  => ['Geometry', 'Errors', 'Logging'],
        'Platform'  => ['Platform', 'Geometry', 'Errors', 'Rendering', 'Input', 'Theme', 'Logging'],
        'Text'      => ['Text', 'Geometry', 'Errors', 'Logging'],
        'Rendering' => ['Rendering', 'Geometry', 'Text', 'Errors', 'Logging'],
        'Input'     => ['Input', 'Geometry', 'Errors', 'Text', 'Layout', 'Logging'],
        'Widget'    => ['Widget', 'Layout', 'State', 'Rendering', 'Text', 'Geometry', 'Input', 'Platform', 'Errors', 'Logging'],
        'Layout'    => ['Widget', 'Layout', 'State', 'Rendering', 'Text', 'Geometry', 'Input', 'Platform', 'Errors', 'Logging'],
        'State'     => ['Widget', 'Layout', 'State', 'Rendering', 'Text', 'Geometry', 'Input', 'Platform', 'Errors', 'Logging'],
        'Commands'  => ['Commands', 'Geometry', 'Errors', 'State', 'Logging'],
        'Theme'     => ['Theme', 'Widget', 'Layout', 'State', 'Text', 'Geometry', 'Rendering', 'Input', 'Errors', 'Logging'],
        'Routing'   => ['Routing', 'Widget', 'Layout', 'State', 'Text', 'Geometry', 'Rendering', 'Input', 'Errors', 'Logging'],
        'App'       => ['App', 'Geometry', 'Platform', 'Text', 'Rendering', 'Input', 'Widget', 'Layout', 'State', 'Commands', 'Theme', 'Routing', 'Errors', 'Logging'],

        // ★ `Logging` 是横切层（与 `Errors` 同性质），2026-10-24 随日志系统补登记。
        //   **不登记 = 完全不受检查**：`layerOf()` 对不在 `LAYERS` 里的段返回 null，
        //   `check()` 会把 from / to 两侧都跳过 —— 新层默默进了检查盲区。
        //   允许集的形状是"各层可依赖 Logging、Logging 只依赖 Errors"：
        //   日志是最低位的横切能力，任何层都该能记；而它自己不得反向拽住业务层。
        'Logging'   => ['Logging', 'Errors'],
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
     *
     * `Button::arg()` 是**同一个形状的第三例**（2026-10-16）：控件要声明命令的
     * 参数（§11.4.3 的动作缝，§A.2.10 / §A.3.7 样板），`CommandArg` 是必须穿越
     * `Widget → Commands` 的值对象。链的其余部分（派发）住 `Drift\App`，Widget 侧
     * 只碰这一个值类型 —— 与 `State` 例完全同构，不为它放开整层。
     *
     * `ActivationContext` 是**第四例、同一条缝**（2026-10-25，S1 第 10 步 4j-b）：
     * 它把"激活时框架能给什么"打包给控件，其中"派发命令"这一项要 `CommandArg`。
     * ★ 为什么是加一条例外而不是放开整层：这条缝的形状始终是"**一个值类型**穿过
     *   `Widget → Commands`，派发实现仍住 `Drift\App`"。四处都是同一个 `CommandArg`，
     *   没有第二类型在穿 —— 放开整层会让 `Widget` 去 `new` 命令路由，那才是越界。
     *   （若将来出现第二个穿层的 Commands 类型，就该重审这条边界，而不是继续加行。）
     */
    private const FILE_EXCEPTIONS = [
        'src/Widget/BuildContext.php' => ['Theme'],
        'src/Widget/State.php' => ['Commands'],
        'src/Widget/Button.php' => ['Commands'],
        'src/Widget/ActivationContext.php' => ['Commands'],
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
