<?php
declare(strict_types=1);

namespace Drift\Dev\Support;

/**
 * gate 判据清单的**唯一事实源**（2026-11-12 从 `gate.php` 抽出）。
 *
 * ★ 为什么单独成类：此前 28 项命令硬编码在 `gate.php` 里，`CONTRIBUTING.md` 又
 *   手写一份常用命令，统一 CLI 再要一份 ⇒ 三处必然漂（2026-11-11 `--only`
 *   白名单漏 5 对判据就是这么来的）。现在 `gate.php --list` 与 `bin/drift` 读的是
 *   同一份数组 ——「一份数一个名字」。
 *
 * ★ 只搬**声明**，不搬执行：`proc_open` / 退出码 / `CheckReport` 仍只住在 `gate.php`。
 *   判据实现（lint 规则、自证对、夹具应用、真机探针）一行未动。
 *
 * ★ `$root` 与 `$python` 由调用方注入：`PHP_BINARY` 是本进程的解释器，
 *   `find_python()` 是 gate 的环境探测，registry 不自己找环境（否则它就成了第二个 runner）。
 */
final class CheckRegistry
{
    /** @return array<string, array{0: string, 1: list<string>, 2: bool}> */
    public static function checks(string $root, ?array $python): array
    {
        return [
            // ★ `--scope=gate`：doctor 的 `affects` 分级要真被读 —— 一台没有 MSVC 的机器上
            //   MSVC / SDK / PHPRC 都不影响本闸门（本闸门是 T0，纯 PHP），否则第一项就 break，
            //   后面 27 项一条都跑不到（2026-11-11 修）。
            'doctor'   => ['环境（doctor）', [PHP_BINARY, $root . '/tools/doctor.php', '--quiet', '--scope=gate'], false],
            // VERSION / DEPS_VERSION / CHANGELOG / deps.lock.json / HEAD tag 必须同版：
            // 版本漂了不报错的话，发版时才会发现 tag 和 CHANGELOG 对不上（Flutter 的打包流程
            // 会拒绝发布过时的 engine.version，同理）。
            'version'  => ['版本一致性（version --check）', [PHP_BINARY, $root . '/tools/version.php', '--check'], false],
            // 上面那条管真相源之间的一致性；这条管所有派生副本（代码常量 + 8 份 project.yml
            // 的 version / file-version / product-version）有没有跟上真相源。
            'genversion' => ['版本派生副本（gen-version --check）', [PHP_BINARY, $root . '/tools/gen-version.php', '--check'], false],
            'lint'     => ['lint', [PHP_BINARY, $root . '/tools/lint.php'], false],
            'selftest' => ['lint 自检', [PHP_BINARY, $root . '/tools/lint.php', '--selftest'], false],
            // ★ 5 对「接线对账 + 变异自证」收成一个组：它们是 5 个能力的两半，
            //   在顶层占 10 个名字，而 `gate --only` / CI 只需要一个可寻址入口。
            //   判据实现一行未动，细粒度用 `composer tooling -- --only=abi` 保住。
            //   ★ 不进 PHPUnit：这些对账器与行为单测不同源（见各自头注），且零依赖；
            //   `unit` 在缺 PHPUnit 时会 skip，绑过去等于让守卫静默消失。
            'tooling' => [
                '工具链对账组（接线 + 变异自证）',
                [PHP_BINARY, $root . '/tools/group-run.php', '--group=tooling'],
                false,
            ],
            // ★ 日常改 PHP 不再编 AOT ⇒ dev 壳成了主入口，它的红线必须进闸门（无窗口、廉价）。
            //   只靠 project.yml 头注释挡不住「顺手把 src/ 加进 sources」那一下。
            'devshell' => ['开发态壳红线（drift-dev --selftest）', [PHP_BINARY, $root . '/tools/drift-dev.php', '--selftest'], false],
            'docref'   => ['docref', [PHP_BINARY, $root . '/tools/docref.php'], false],
            // ★ `docs/API-SURFACE.md` 的抬头写着"自动生成、勿手改"，但**没人检查它是新的**
            //   ⇒ 它漂了：`FrameStage` 没进文档，行号整体偏几十行。docref 查的是"引用的符号
            //   存在吗"，这一项查的是"这份清单是最新的吗" —— 两件事，都要有闸门。
            'apidoc'   => ['API 面清单（apidoc）', [PHP_BINARY, $root . '/tools/api-doc-check.php'], false],
            'probe'    => ['探针骨架（probe-audit）', [PHP_BINARY, $root . '/tools/probe-audit.php', '--selftest'], false],
            'pixels'   => [
                '像素契约（probe_pixels）',
                $python === null ? [] : [...$python, $root . '/tools/probe_pixels.py', '--selftest'],
                false,
            ],
            'tick'     => ['每帧固定税（frame-tax）', [PHP_BINARY, $root . '/tools/frame-tax-probe.php', '--check'], false],
            // 判据只认自证 / 比值 / 结构事实；绝对耗时当红线会随机器抖动误报。
            'scale'    => ['12k 节点尺子（frame-tax --scale）', [PHP_BINARY, $root . '/tools/frame-tax-probe.php', '--scale'], false],
            // ★ 崩溃日志里的 `模块+RVA` 能不能翻成函数名 —— 这是"崩溃可观测"闭环的最后一环。
            //   自检用**合成 map**，不依赖有没有构建过 exe（所以廉价、无前置）。
            'sym'      => ['崩溃符号翻译（rva2sym）', [PHP_BINARY, $root . '/tools/rva2sym.php', '--selftest'], false],
            // ★ WIC 解码只在**有 WIC 的进程**里跑得到（`php.exe` 没这个扩展），单测覆盖不到。
            //   这条自检拿一张期望值手写的色带图，钉"红带必须是红" —— 缩放的通道序曾经静默
            //   反了（IWICBitmapScaler 不认 32bppRGBA），六条"两个尺寸互相比"的判据全绿。
            //   要 MSVC 才能编译 ⇒ 没工具链的机器记"没测到"，不许记成通过。
            'decode'   => ['图片通道序（decode-selftest）', [PHP_BINARY, $root . '/tools/decode-selftest/run.php'], false],
            // ★ M2 conformance：W1–W3 静态扫 `src/Widget`（死字段 / 字段进盒 / 单一造盒路径）
            //   + W4–W8 夹具运行时判据 + 8 条量具反例自检。
            //   ★ 它此前**不在 gate 里**，于是 3 条假红挂了一个多月没人看 ——
            //   "闸门不在 gate 里 = 红了没人知道"。假红成因是尺子只认本类体（读点漏了
            //   `use` 进来的特征与子类）与只认一条造盒路径，2026-11-03 修准后进 gate。
            //   先判定再接线：红着的闸门加进来只会被人 --only 绕过去。
            'm2'       => ['M2 conformance（W1–W8）', [PHP_BINARY, $root . '/tools/M2Conformance.php'], false],
            'unit'     => ['unit（纯单元）', [PHP_BINARY, $root . '/tools/phpunit-run.php', 'unit'], false],
            'feature'  => ['feature（场景）', [PHP_BINARY, $root . '/tools/phpunit-run.php', 'feature'], true],
            'golden'   => ['golden 原语', [PHP_BINARY, $root . '/tools/golden-raster.php', '--check', '--canvas'], false],
            'pages'    => ['golden 整页', [PHP_BINARY, $root . '/tools/golden-raster.php', '--check', '--pages'], true],
            'smoke'    => ['smoke', [PHP_BINARY, $root . '/tools/smoke.php'], true],
        ];
    }

    /**
     * 组内子项：顶层一项展开成多条判据，**仍只此一份清单**。
     *
     * ★ 与 `checks()` 同住本类：`gate --list` 与组执行器不会各抄一遍。
     * ★ 判据实现（5 个 `*-check.php`）一行不改；删任意一条会被 `group-run.php`
     *   的磁盘自证咬红 —— 期望来自文件系统，不来自这份声明。
     *
     * @return array<string, list<array{id: string, label: string, command: list<string>}>>
     */
    public static function groups(string $root): array
    {
        $pairs = [
            ['abi', 'abiself', 'ABI 契约', 'abi-check.php'],
            ['cppbridge', 'cppbridgeself', 'C++ 桥接结构', 'cpp-bridge-check.php'],
            ['dxdiag', 'dxself', 'DX 退化判据对账', 'diagnostic-check.php'],
            ['stalepolicy', 'staleself', '构建 stale 决策接线', 'build-stale-check.php'],
            ['errshape', 'errself', '错误码贯通接线', 'error-shape-check.php'],
        ];

        $steps = [];
        foreach ($pairs as [$base, $self, $label, $file]) {
            $cmd = [PHP_BINARY, $root . '/tools/' . $file];
            $steps[] = ['id' => $base, 'label' => $label, 'command' => $cmd];
            $steps[] = [
                'id' => $self,
                'label' => $label . '自证（变异体）',
                'command' => [...$cmd, '--selftest'],
            ];
        }

        return ['tooling' => $steps];
    }

    /**
     * **机器能力项**：允许"没测到"（命令缺失 / 退出码 2）而不计失败。
     *
     * ★ 只许列**依赖本机装了什么**的检查（python / MSVC / PHPUnit）。别把普通检查
     *   写进来 —— 那会把真正的失败变成一句轻描淡写的"跳过"，正是本仓反复踩的"绿壳"。
     * ★ 跳过**不是通过**：总结里必须单独列出跳了几项、为什么。
     * ★ 这些项的"跳过"都只能由**入口自己的退出码 2** 触发（找不到解释器 / 找不到
     *   cl.exe / `phpunit-run.php` 找不到 `vendor/bin/phpunit`），不能由"跑起来了
     *   但结果不对"触发 —— 后者是 1。★ `unit`/`feature` 是 2026-11-12 补的：
     *   此前 `phpunit-run.php` 缺二进制时 exit 0 ⇒ gate 把"没测到"记成"通过"，
     *   CI 的 27 项全过是假绿。
     */
    /** @return list<string> */
    public static function maySkip(): array
    {
        return ['pixels', 'decode', 'unit', 'feature'];
    }
}
