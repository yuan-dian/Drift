<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;

/**
 * §4.3 依赖规则：谁能依赖谁。PSR-4 之后这条第一次可静态检查。
 *
 * ★ 层集合由 `ALLOWED` 的 key 派生，未登记的段默认报红（借 ArchUnit
 *   `failOnEmptyShould` 的默认拒绝语义）；`UNLAYERED` 是写明理由的豁免。
 *   `Drift\<Type>` 顶层设施（`Errors` 等）只有两段、没有层段，天然不进表。
 *
 * ★ 2026-11-15 收紧：`ALLOWED` 只保留**有真实流量**的边。治理前 79 条非自身
 *   授权里 37 条零流量（46.8%）—— 宽而不管的表既拦不住越界，也读不出形状。
 *   `DependencyMatrixTest` 守「每条授权边都有流量」与「双向边只有登记过的三对」。
 *   新增引用被本规则拦下时，显式加回一行并写明理由，这就是 burn-down。
 *
 * ★ 规则盲区，显式登记：`src/Errors.php` 引用 `Drift\Logging\Log` /
 *   `PatternFormatter`，但整份顶层文件不参与检查 ⇒ 治理时 `Logging` 的层内入边
 *   实测为 0 是「看不见」而不是「没人用日志」。`Routing` 只被 `apps/` 消费
 *   （业务代码不参与分层检查），`App` 是组合根，两者零入边同理。
 */
final class DependencyDirection implements Rule
{
    /**
     * 明确不参与框架分层的命名空间段。
     *
     * 不写成"表里没有就跳过"：那样新层会静默进盲区（`Core` 就是这么漏的），
     * 读代码的人也无从确认某个放行是有意的。每一条都得写出理由。
     */
    private const UNLAYERED = [
        'Dev',                 // 工具链 / smoke / 探针 / 测试：跑在 php.exe，永不参与 AOT
        'Bench',               // apps/bench 与 tools/Bench 的基准夹具
        'ConformanceSelfTest', // M2 conformance 自检夹具
    ];

    /**
     * §4.3 的允许集（含自身）。**本表的 key 就是层集合**，不另设 `LAYERS`。
     *
     * ★ 每行只列**当前有真实引用**的目标层（2026-11-15 按 `src/` 逐边实测收紧）。
     *   删掉的 37 条零流量边不是"撤销设计裁决"，而是把"以后可能要"从表里拿走：
     *   真要加依赖时，加一行 + 写理由的成本，远低于养着一张没人审的宽表。
     *   `DependencyMatrixTest` 会在零流量边回潮时咬红。
     *
     * ★ `Platform` 行的 `Rendering` / `Input` / `Theme` 容易被误读成"开后门"：
     *   它们是平台必须产出的**输出类型**（表面 / 事件 / 系统主题），没有更低的层
     *   能拥有 ⇒ 按文件放行会扩散到 `Platform\<Os>\` 的每个文件。
     *
 * ★ `Widget → Async` 只为 `AsyncView` 开：它解释 `AsyncValue` 的四态，
 *   而 `Async` 不反向依赖 `Widget`（`AsyncCommand` 靠 `Readable` 与 Widget 解耦）。
 *   旧的 `FutureBuilder` 边在 2026-10-30 因为零消费者被删；这里是新的真实消费者。
     */
    private const ALLOWED = [
        'Core'      => ['Core'],
        'Geometry'  => ['Geometry'],
        'Platform'  => ['Platform', 'Geometry', 'Rendering', 'Input', 'Theme'],
        'Text'      => ['Text', 'Geometry'],
        'Rendering' => ['Rendering', 'Geometry', 'Text', 'Core'],
        'Input'     => ['Input', 'Geometry', 'Text'],
        'Widget'    => ['Widget', 'Layout', 'State', 'Async', 'Rendering', 'Text', 'Geometry', 'Input', 'Core'],
        'Layout'    => ['Widget', 'Layout', 'Rendering', 'Text', 'Geometry', 'Input', 'Core'],
        'State'     => ['Widget', 'State', 'Async', 'Core'],
        'Commands'  => ['Commands'],
        'Theme'     => ['Theme', 'Widget', 'Text', 'Rendering'],
        // `Routing`：页名来源收成 `Core\Readable`（2026-11-22）⇒ 不再需要 `State`（那是
        // `Widget` 的邻居层）。它要的只是"读一个值并跟着变"，依赖采集在 `Widget\State`。
        // `Routing` 装配层可依赖 `Route` 声明层（A28：descriptor 直接喂 PageRegistry，
        // 不再投影第二份 NavEntry）；`Route` 不反向依赖 `Routing`。
        'Routing'   => ['Routing', 'Route', 'Widget', 'Core'],
        // `Route`：纯声明层（`#[Route]` + `RouteDescriptor` + `RouteCatalog`），只压在 `Widget` 之上。
        // 不碰 `Reducer`（状态管理是页面内部选择），也不反向依赖 `Routing`（装配层）——
        // 声明不依赖装配；命令路由另住 `Commands`。方向见 A28。
        'Route'     => ['Route', 'Widget'],
        // `Reducer`：可选状态机（`ReducerView` + `ReducerState` + 四个值类型），
        // 只压在 `Widget` 之上（`Widget\State` 也算 Widget 层）。与 `Route` 平级、
        // 互不依赖 —— 命名解耦（A14）的分层守卫：任一侧 import 另一侧都红。
        'Reducer'   => ['Reducer', 'Widget'],
        'Async'     => ['Async'],
        'App'       => ['App', 'Async', 'Geometry', 'Platform', 'Text', 'Rendering', 'Input', 'Widget', 'Layout', 'Commands', 'Theme', 'Core'],
        'Logging'   => ['Logging'],
    ];

    /**
     * 有意保留的双向边；键是两层按字母序拼的，判据按实际流量对账。
     *
     * Widget↔Layout / Widget↔State：同一棵树的两个视角，两侧都有真实引用。
     * Theme↔Widget：`ThemeScope` 等主题控件住 `Theme`，而 `BuildContext` 住
     * `Widget`；反向只走 `BuildContext.php` 一条文件例外，不是对称互许。
     *
     * ★ 不照搬 Flutter `InheritedWidget` / `BuildOwner`：那要改公开签名并引入
     *   AOT 生成物风险，收益只是"看起来没有环"。移除条件是任一侧引用归零。
     */
    private const DOCUMENTED_CYCLES = [
        'Layout|Widget',
        'State|Widget',
        'Theme|Widget',
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
     *
     * `ThemeChangedEvent` 是**第五例、另一条缝**（2026-11-14，HC 跟随）：平台把
     * 一次系统外观快照（`Drift\Theme\SystemAppearance`）塞进输入事件，交给
     * `Drift\App\Application` 换主题。`Input` 层不 `new` 主题、不解析 token，
     * 只当载荷容器 ⇒ 与上面「一个值类型穿层」同族，按文件放行而非放开
     * `Input → Theme` 整层。`SystemAppearanceTest` 守着这条例外不扩散。
     */
    private const FILE_EXCEPTIONS = [
        'src/Widget/BuildContext.php' => ['Theme'],
        'src/Widget/State.php' => ['Commands'],
        'src/Widget/Button.php' => ['Commands'],
        'src/Widget/ActivationContext.php' => ['Commands'],
        'src/Input/ThemeChangedEvent.php' => ['Theme'],
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
            $fromSegment = $this->namespaceSegment($from);
            if (in_array($fromSegment, self::UNLAYERED, true)) {
                continue;
            }
            if (!isset(self::ALLOWED[$fromSegment])) {
                $key = $file->path() . ':unlayered-from:' . $fromSegment;
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $found[] = new Diagnostic(
                        $this->name(),
                        Diagnostic::ERROR,
                        $file->path(),
                        $file->namespaceLine(),
                        sprintf(
                            '命名空间段 Drift\%s 未登记：既不在 ALLOWED 也不在 UNLAYERED —— '
                            . '新层必须显式写进 ALLOWED（含允许依赖），或登记到 UNLAYERED 并说明理由',
                            $fromSegment
                        )
                    );
                }
                continue;
            }

            foreach ($file->references() as $reference) {
                $to = $reference['name'];
                if (!str_starts_with($to, Project::FRAMEWORK_PREFIX)) {
                    continue;
                }
                $toSegment = $this->typeSegment($to);
                if ($toSegment === null || in_array($toSegment, self::UNLAYERED, true)) {
                    continue; // `Drift\<Type>` 顶层设施 / 工具链段：不参与分层
                }
                if (!isset(self::ALLOWED[$toSegment])) {
                    $key = $file->path() . ':unlayered-to:' . $toSegment;
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $found[] = new Diagnostic(
                            $this->name(),
                            Diagnostic::ERROR,
                            $file->path(),
                            $reference['line'],
                            sprintf(
                                '%s 引用了未登记的命名空间段 Drift\%s —— 先把它登记进 ALLOWED 或 UNLAYERED',
                                $fromSegment,
                                $toSegment
                            )
                        );
                    }
                    continue;
                }
                if (in_array($toSegment, self::ALLOWED[$fromSegment], true)) {
                    continue;
                }
                if (in_array($toSegment, self::FILE_EXCEPTIONS[$file->path()] ?? [], true)) {
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
                    sprintf('%s 不得依赖 %s（%s）', $fromSegment, $toSegment, $to)
                );
            }
        }

        return $found;
    }

    /** namespace 的层段：`Drift\Core` → `Core`（顶层 `Drift;` 由调用方前置跳过） */
    private function namespaceSegment(string $namespace): string
    {
        return explode('\\', substr($namespace, strlen(Project::FRAMEWORK_PREFIX)))[0];
    }

    /**
     * 类型 FQN 的层段：只有 `Drift\<Seg>\<Type>` 才有段。
     * `Drift\<Type>` 是顶层 `namespace Drift;` 设施（Errors / InternalThing …），不是层。
     */
    private function typeSegment(string $fqn): ?string
    {
        $parts = explode('\\', substr($fqn, strlen(Project::FRAMEWORK_PREFIX)));
        return count($parts) >= 2 ? $parts[0] : null;
    }
}
