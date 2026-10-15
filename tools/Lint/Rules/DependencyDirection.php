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
     * ★ 一处对 §4.3 的补正：`Theme` 行漏列了 `Rendering`，而 §4.2 把 `Color`
     *   放在 `Drift\Rendering`，`ThemeData` 必须产出颜色值 ⇒ 放行 Rendering。
     *   登记为文档缺口，见本次提交说明。
     */
    private const ALLOWED = [
        'Geometry'  => ['Geometry', 'Errors'],
        'Platform'  => ['Platform', 'Geometry', 'Errors'],
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
     */
    private const FILE_EXCEPTIONS = [
        'src/Widget/BuildContext.php' => ['Theme'],
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
