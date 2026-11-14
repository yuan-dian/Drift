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
 *
 * ★ 2026-11-10：层集合改由 `ALLOWED` 的 key 派生，不再维护第二份 `LAYERS` 表
 *   （两份表曾漂移出 `Core` 漏层）。未登记的命名空间段默认报红，只有写进
 *   `UNLAYERED` 才豁免 —— 借 ArchUnit `failOnEmptyShould` 的默认拒绝语义。
 *
 * ★ `Errors` 等顶层 `namespace Drift;` 设施不是层：`Drift\<Type>` 只有两段，
 *   没有层段，所有层天然可用，因此不进 `ALLOWED`。此前 14 行都写着 `'Errors'`
 *   却恒被跳过（段不在层表里）—— 那是死条目，不是可收紧的边，故一并删除。
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
        // ★ `Core`（2026-11-10 补）：`src/Core/` 是帧管线基座（`Pipeline` /
        //   `PipelineAccess` / `Versioned` / `Computed`），全 @internal，只认 `Logging`。
        //   ★ 只给下面 5 个**实际引用**它的层放行 —— 其余层若要用 Core，
        //     必须显式改那一行并说明理由，不允许"反正谁都能用"地兜底放开。
        'Core'      => ['Core', 'Logging'],
        'Geometry'  => ['Geometry', 'Logging'],
        'Platform'  => ['Platform', 'Geometry', 'Rendering', 'Input', 'Theme', 'Logging'],
        'Text'      => ['Text', 'Geometry', 'Logging'],
        'Rendering' => ['Rendering', 'Geometry', 'Text', 'Core', 'Logging'],
        'Input'     => ['Input', 'Geometry', 'Text', 'Layout', 'Logging'],
        // ★ `Widget` 行的 `Async`（2026-10-30 增删记录）：加它是为了 `FutureBuilder`
        //   同时认识 `Future` 与 `AsyncValue`；那个控件已被 `State\AsyncCommand`
        //   取代（它是 `FutureBuilder` 的超集，且复用既有 `Cell`/`Subject` 通知设施）
        //   ⇒ **放行随之移除**，否则就是一条"没人走也没人审"的边。
        //   ★ 现在唯一放行 `Async` 的非 `App` 层是 `State`（见下面那行的注释）。
        'Widget'    => ['Widget', 'Layout', 'State', 'Rendering', 'Text', 'Geometry', 'Input', 'Platform', 'Core', 'Logging'],
        'Layout'    => ['Widget', 'Layout', 'State', 'Rendering', 'Text', 'Geometry', 'Input', 'Platform', 'Core', 'Logging'],
        // ★ `Async`（2026-10-30，`AsyncCommand`）：`Drift\State\AsyncCommand` 是
        //   "异步版的 `Cell`" —— 住在 `State`（生命周期与页面对齐），值为
        //   `AsyncValue`、发起走 `Future`，两者都在 `Async` 层。
        //   ★ 为什么改这一行而不是把命令塞进 `Widget`：命令不是控件，而 `Widget`
        //     已经放行 `Async` ⇒ 方向仍是"上层用下层"，不产生新环
        //     （`Async` 自己只认 `Logging`）。
        'State'     => ['Widget', 'Layout', 'State', 'Rendering', 'Text', 'Geometry', 'Input', 'Platform', 'Async', 'Core', 'Logging'],
        'Commands'  => ['Commands', 'Geometry', 'State', 'Logging'],
        'Theme'     => ['Theme', 'Widget', 'Layout', 'State', 'Text', 'Geometry', 'Rendering', 'Input', 'Logging'],
        'Routing'   => ['Routing', 'Widget', 'Layout', 'State', 'Text', 'Geometry', 'Rendering', 'Input', 'Logging'],
        // ★ `Async`（2026-10-30，N3）：`Future` 是**纯值类型**，只认 `Logging`
        //   （失败没人接时要喊 Fatal，那是顶层 `Errors` 设施，不进层表）。
        //   它比 `App` 低 —— 执行体后端（`TaskHost`）住在 `App`，反过来不成立。
        'Async'     => ['Async', 'Logging'],
        'App'       => ['App', 'Async', 'Geometry', 'Platform', 'Text', 'Rendering', 'Input', 'Widget', 'Layout', 'State', 'Commands', 'Theme', 'Routing', 'Core', 'Logging'],

        // ★ `Logging` 是横切层，2026-10-24 随日志系统补登记。
        //   **当时不登记 = 完全不受检查**：`layerOf()` 对不在层表里的段返回 null，
        //   `check()` 两侧都跳过 —— `Core` 后来正是这么漏的（2026-11-10 改为默认报红）。
        //   日志是最低位的横切能力，任何层都该能记；而它自己不得反向拽住业务层。
        'Logging'   => ['Logging'],
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
