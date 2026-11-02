<?php
declare(strict_types=1);

namespace Drift\Dev\Doctor;

/**
 * 构建环境的**单一真相源**：定位（MSVC / SDK / PHPX / tpc / ini）+ 体检。
 *
 * ★ 为什么要有它：这些约束此前只活在 `drift-build.php` 的 `fail()` 分支和人的脑子里
 *   （PITFALLS「PHP / tpc 硬坑」整段都是"不报错、只是没效果"）。新机器接手时，
 *   只能靠"构建失败 → 猜 → 改"逐个试出来。收成可查询的探针后，`doctor` 与
 *   `drift-build` **消费同一份定位逻辑**，不再各写一套路径推导。
 *
 * ★ 两条路径分工：
 *   · `probe()`  **只读**，逐项出 `Check`，永不写盘 —— `doctor` 用。
 *   · `prepare()` 有副作用（写 `_build/ini/php.ini`），失败抛异常 —— `drift-build` 用。
 *   两者共用私有定位方法 ⇒ 判据只写一遍。
 */
final class Environment
{
    /** composer.json 的 `require.php`；tpc 生成物也按这个底线。 */
    public const MIN_PHP = '8.4';

    /** MSVC / SDK 默认位置（本机实测；换机器用环境变量覆盖） */
    public const DEFAULT_MSVC_VERSION = '14.51.36231';
    public const DEFAULT_VS_YEAR = '18';
    public const DEFAULT_SDK_VERSION = '10.0.26100.0';

    /**
     * 逐项体检（只读）。
     *
     * @return list<Check>
     */
    public static function probe(string $root): array
    {
        $checks = [];

        $phpOk = version_compare(PHP_VERSION, self::MIN_PHP, '>=');
        $checks[] = new Check(
            'php-version',
            'PHP 版本',
            $phpOk ? Check::OK : Check::FAIL,
            $phpOk ? PHP_VERSION : PHP_VERSION . '（需 >= ' . self::MIN_PHP . '）',
            true,
            $phpOk ? null : '换成 >= ' . self::MIN_PHP . ' 的发行版（composer.json 的 require.php）',
            ['gate', 'build'],
        );

        // ── PHPRC 派生源（坑 ②）──
        $checks[] = self::guard(
            'phprc',
            '发行版 php.ini',
            ['build'],
            function (): Check {
                $ini = self::distributionRoot() . '/php.ini';

                return is_file($ini)
                    ? new Check('phprc', '发行版 php.ini', Check::OK, $ini, true)
                    : new Check('phprc', '发行版 php.ini', Check::FAIL, "{$ini} 不存在（PHPRC 无从派生）", true,
                        '把 php.ini 放回 PHP 发行版根（tpc 不加载同目录 ini，必须靠 PHPRC）');
            },
        );

        // ── opcache：CLI 下真启用了才是坑（改了代码不生效 ⇒ 假绿）──
        $opcacheLoaded = extension_loaded('Zend OPcache');
        $enableCli = (string) ini_get('opcache.enable_cli');
        $enableCliOn = $enableCli !== '' && $enableCli !== '0';
        $checks[] = new Check(
            'opcache',
            'opcache（CLI）',
            $enableCliOn ? Check::WARN : Check::OK,
            $opcacheLoaded
                ? ('enable=' . var_export(ini_get('opcache.enable'), true)
                    . ' · enable_cli=' . ($enableCli === '' ? '(默认 0)' : var_export($enableCli, true))
                    . ($enableCliOn ? ' ⇒ CLI 已启用' : ' ⇒ CLI 未启用'))
                : '未加载 Zend OPcache',
            false,
            $enableCliOn
                ? 'CLI 开 opcache 会让"改了代码却没重编"静默假绿 ⇒ php.ini 设 opcache.enable_cli=0'
                : null,
            ['gate'],
        );

        // ── PHPX（坑 ③）──
        $checks[] = self::guard(
            'phpx',
            'PHPX_HOME',
            ['build'],
            function (): Check {
                $phpx = self::phpxHome(self::distributionRoot());
                if (!is_dir($phpx)) {
                    throw new EnvironmentException("PHPX_HOME 不存在：{$phpx}");
                }
                $missing = [];
                foreach (['include', 'lib', 'src'] as $sub) {
                    if (!is_dir("{$phpx}/{$sub}")) {
                        $missing[] = $sub;
                    }
                }

                return $missing === []
                    ? new Check('phpx', 'PHPX_HOME', Check::OK, $phpx, true)
                    : new Check('phpx', 'PHPX_HOME', Check::FAIL, $phpx . ' 缺 ' . implode('/', $missing), true,
                        '设 DRIFT_PHPX_HOME 指向完整的 phpx 根（需 include / lib / src）');
            },
        );

        // ── tpc 入口（源码树优先，见 drift-build --help）──
        $checks[] = self::guard(
            'tpc',
            'tpc 入口',
            ['build'],
            function () use ($root): Check {
                $entry = self::tpcEntry($root);

                return new Check('tpc', 'tpc 入口', Check::OK, $entry, true);
            },
        );

        // ── MSVC（坑 ④）──
        $checks[] = self::guard(
            'msvc',
            'MSVC cl.exe',
            ['build'],
            function (): Check {
                $msvc = self::msvcRoot();
                $cl = "{$msvc}/bin/Hostx64/x64/cl.exe";
                if (!is_file($cl)) {
                    throw new EnvironmentException("找不到 cl.exe：{$cl}（设 DRIFT_MSVC 覆盖）");
                }
                $version = self::envOr('DRIFT_MSVC_VERSION', self::DEFAULT_MSVC_VERSION);

                return new Check('msvc', 'MSVC cl.exe', Check::OK, "{$cl}（{$version}）", true);
            },
        );

        // ── Windows SDK（坑 ④：三件套缺一个都报得莫名其妙）──
        $checks[] = self::guard(
            'sdk',
            'Windows SDK',
            ['build'],
            function (): Check {
                $sdk = self::sdkRoot();
                $missing = [];
                foreach ([$sdk['include'] . '/um', $sdk['include'] . '/ucrt', $sdk['lib'] . '/um/x64'] as $p) {
                    if (!is_dir($p)) {
                        $missing[] = $p;
                    }
                }
                $version = $sdk['version'];
                if ($missing !== []) {
                    throw new EnvironmentException("Windows SDK {$version} 不完整：{$sdk['include']}（缺 "
                        . implode(' / ', $missing) . '）');
                }

                return new Check('sdk', 'Windows SDK', Check::OK, "{$version} @ {$sdk['kits']}", true);
            },
        );

        // ── golden 基线完整度：缺一张 = 少一张网，必须能看见 ──
        $checks[] = self::goldenCheck($root);

        // ── _build 可写（清单 / ini / manifest 都落这儿）──
        $checks[] = self::guard(
            'build-dir',
            '_build 可写',
            ['build', 'golden'],
            function () use ($root): Check {
                $dir = "{$root}/_build";
                if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                    throw new EnvironmentException("建不了 {$dir}");
                }
                $probeFile = "{$dir}/.doctor-write-probe";
                if (@file_put_contents($probeFile, 'x') === false) {
                    throw new EnvironmentException("{$dir} 不可写");
                }
                @unlink($probeFile);

                return new Check('build-dir', '_build 可写', Check::OK, $dir, true);
            },
        );

        return $checks;
    }

    /**
     * 定位 + 副作用（写 `_build/ini/php.ini`）+ 拼 tpc 的 env。
     *
     * ★ `$withToolchain=false` 给 `drift-codegen`（`--dry` 只走翻译层，用假 cl.exe
     *   ⇒ 真 MSVC/SDK 不是它的前置）。若在这里也强制要真工具链，那条门会凭空多出
     *   一台机器的依赖 —— 判据必须跟着消费者走，不能一刀切。
     *
     * @throws EnvironmentException 任一必需项不满足
     */
    public static function prepare(string $root, bool $withToolchain = true): PreparedEnv
    {
        $distRoot = self::distributionRoot();

        // 坑 ②：tpc 不加载同目录 php.ini ⇒ 复制一份到 PHPRC 并修正 extension_dir
        $iniDir = self::writePrivateIni($root);
        if ($iniDir === null) {
            throw new EnvironmentException(
                "找不到发行版 php.ini：{$distRoot}/php.ini（PHPRC 无从派生）",
            );
        }

        // 坑 ③
        $phpxHome = self::phpxHome($distRoot);
        if (!is_dir($phpxHome)) {
            throw new EnvironmentException("PHPX_HOME 不存在：{$phpxHome}");
        }

        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env['PHP_HOME'] = $distRoot;
        $env['PHPX_HOME'] = $phpxHome;
        $env['PHPRC'] = self::win($iniDir);

        $msvcVersion = '';
        $sdkVersion = '';

        if ($withToolchain) {
            // 坑 ④：cl.exe 要 INCLUDE / LIB / PATH 三件套（vcvars64.bat 干的就是这个），
            //       而 bash 里没法 `call` 它 ⇒ 直接按 MSVC/SDK 目录拼。
            $msvc = self::msvcRoot();
            if (!is_file("{$msvc}/bin/Hostx64/x64/cl.exe")) {
                throw new EnvironmentException(
                    "找不到 cl.exe：{$msvc}/bin/Hostx64/x64/cl.exe（设 DRIFT_MSVC 覆盖）",
                );
            }
            $sdk = self::sdkRoot();
            $sdkInclude = $sdk['include'];
            $sdkLib = $sdk['lib'];
            $sdkVersion = $sdk['version'];
            if (!is_dir("{$sdkInclude}/um") || !is_dir("{$sdkLib}/um/x64")) {
                throw new EnvironmentException(
                    "Windows SDK {$sdkVersion} 不完整：{$sdkInclude}（设 DRIFT_KITS / DRIFT_SDK_VERSION 覆盖）",
                );
            }

            $env['INCLUDE'] = implode(';', [
                self::win("{$msvc}/include"),
                self::win("{$sdkInclude}/ucrt"),
                self::win("{$sdkInclude}/shared"),
                self::win("{$sdkInclude}/um"),
            ]);
            $env['LIB'] = implode(';', [
                self::win("{$msvc}/lib/x64"),
                self::win("{$sdkLib}/ucrt/x64"),
                self::win("{$sdkLib}/um/x64"),
            ]);
            // PATH 里必须同时有 cl.exe（MSVC bin）与 rc.exe（SDK bin）—— 后者缺了 tpc 报
            // "找不到 rc.exe"，看不出是 PATH 的问题（PITFALLS 坑 ④）。
            $env['PATH'] = implode(';', [
                self::win("{$msvc}/bin/Hostx64/x64"),
                self::win("{$sdk['kits']}/bin/{$sdkVersion}/x64"),
                (string) ($env['PATH'] ?? ''),
            ]);
            $msvcVersion = self::envOr('DRIFT_MSVC_VERSION', self::DEFAULT_MSVC_VERSION);
        }

        return new PreparedEnv(
            $env,
            $distRoot,
            $phpxHome,
            $iniDir,
            self::tpcEntry($root),
            $msvcVersion,
            $sdkVersion,
        );
    }

    // ─────────────────────────── 定位（probe / prepare 共用，只写一遍） ───────────────────────────

    /** 反斜杠化的 Windows 路径（PHP 是原生 Windows 程序，不认 `/d/...`） */
    private static function win(string $path): string
    {
        return str_replace('/', '\\', $path);
    }

    private static function envOr(string $name, string $fallback): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    /** PHP 发行版根（= 当前 php.exe 所在目录）；ini 与 ext/ 都从它派生 */
    public static function distributionRoot(): string
    {
        $phpBinary = PHP_BINARY;
        if ($phpBinary === '') {
            throw new EnvironmentException('PHP_BINARY 为空 —— 无法定位 PHP 发行版根');
        }

        return self::win(dirname(self::win($phpBinary)));
    }

    /** PHPX 根（`DRIFT_PHPX_HOME` 优先，否则发行版根下的 `phpx/`） */
    public static function resolvePhpxHome(): string
    {
        return self::phpxHome(self::distributionRoot());
    }

    private static function phpxHome(string $distRoot): string
    {
        return self::envOr('DRIFT_PHPX_HOME', $distRoot . '/phpx');
    }

    /**
     * MSVC 的安装根（`.../VC/Tools/MSVC/<版本>`）。
     *
     * ★ 公开的理由：调用 `cl.exe` 的**第三方**（如 `tools/decode-selftest/run.php`）
     *   必须拿到绝对路径才能启动它 —— Windows 上 `proc_open` 按**父进程**的 PATH
     *   找可执行文件，`prepare()` 塞进子进程 env 的 PATH 对它不起作用，
     *   于是 `cl.exe` 会报"系统找不到指定的文件"。让它们从 `INCLUDE` 里反推路径，
     *   等于把"MSVC 装在哪"这件事复制出去第二份 —— 这里只留一份。
     */
    public static function msvcRoot(): string
    {
        $msvc = self::envOr('DRIFT_MSVC', '');
        if ($msvc !== '') {
            return $msvc;
        }

        return 'D:/Microsoft Visual Studio/' . self::DEFAULT_VS_YEAR . '/Community/VC/Tools/MSVC/'
            . self::envOr('DRIFT_MSVC_VERSION', self::DEFAULT_MSVC_VERSION);
    }

    /**
     * @return array{kits: string, version: string, include: string, lib: string}
     */
    private static function sdkRoot(): array
    {
        $kits = self::envOr('DRIFT_KITS', 'D:/Windows Kits/10');
        $version = self::envOr('DRIFT_SDK_VERSION', self::DEFAULT_SDK_VERSION);

        return [
            'kits' => $kits,
            'version' => $version,
            'include' => "{$kits}/Include/{$version}",
            'lib' => "{$kits}/Lib/{$version}",
        ];
    }

    /** tpc 入口：**源码树优先**（发行版 `tpc.exe` 缺编译期修复，只作兜底） */
    public static function tpcEntry(string $root): string
    {
        $entry = self::envOr('DRIFT_TPC_ENTRY', '');
        if ($entry === '') {
            foreach ([dirname($root) . '/typephp/bin/tpc.php', 'D:/TypePHP/typephp/bin/tpc.php'] as $candidate) {
                if (is_file($candidate)) {
                    $entry = $candidate;
                    break;
                }
            }
        }
        if ($entry === '' || !is_file($entry)) {
            throw new EnvironmentException(
                '找不到 tpc 入口 —— 设 DRIFT_TPC_ENTRY=D:/…/typephp/bin/tpc.php',
            );
        }

        return $entry;
    }

    /**
     * 私有 php.ini（`_build/ini/php.ini`）—— tpc 不加载同目录 ini，必须靠 `PHPRC` 指过去。
     *
     * ★ 所有消费者共用**同一份**（`drift-build` / `drift-codegen`）：此前各写一份
     *   （`_build/ini` 与 `_codegen/ini`），内容相同却两处派生 ⇒ 一处改了另一处不知道。
     * ★ 源 ini 缺失返回 `null`（不设 PHPRC，让 tpc 用自己的默认）—— `drift-codegen`
     *   只需要"尽量修好"，不像 `drift-build` 那样必须成功。
     */
    public static function writePrivateIni(string $root): ?string
    {
        $distRoot = self::distributionRoot();
        $source = $distRoot . '/php.ini';
        if (!is_file($source)) {
            return null;
        }

        $iniDir = $root . '/_build/ini';
        if (!is_dir($iniDir) && !mkdir($iniDir, 0777, true) && !is_dir($iniDir)) {
            throw new EnvironmentException("建不了 {$iniDir}");
        }

        $text = (string) file_get_contents($source);
        // 三处 `extension_dir` 都可能是相对路径（含 `;extension_dir = "ext"` 注释形态）⇒ 全改成绝对
        $patched = preg_replace(
            '/^\s*;?\s*extension_dir\s*=\s*.*$/mi',
            'extension_dir = "' . $distRoot . '/ext/"',
            $text,
        );
        if ($patched === null || $patched === $text) {
            throw new EnvironmentException(
                "patch extension_dir 失败（{$source}）—— 宁可门没跑，也别悄悄用错的 ini",
            );
        }
        file_put_contents($iniDir . '/php.ini', $patched);

        return $iniDir;
    }

    // ────────────────────────────────────────── 辅助 ──────────────────────────────────────────

    /**
     * 定位失败 → FAIL 检查项（而不是让整轮体检中断）。
     *
     * @param string[] $affects
     * @param callable(): Check $fn
     */
    private static function guard(string $id, string $label, array $affects, callable $fn): Check
    {
        try {
            return $fn();
        } catch (EnvironmentException $e) {
            return new Check($id, $label, Check::FAIL, $e->getMessage(), true, null, $affects);
        }
    }

    /**
     * golden 基线完整度。
     *
     * ★ 只查"目录在、基线在"这一层：真正的场景清单要跑 `golden-raster.php` 才知道
     *   （页面是自动发现的）。这里抓的是"基线被删了一半却还在跑 --check"这类
     *   结构性问题 —— 那种情况下 `--check` 会因 `no-baseline` 而失败，但没人知道
     *   是"网少了一张"还是"代码真错了"。
     */
    private static function goldenCheck(string $root): Check
    {
        $dir = "{$root}/golden";
        if (!is_dir($dir)) {
            return new Check('golden', 'golden 基线', Check::WARN, "{$dir} 不存在", false,
                '跑 php tools/golden-raster.php --update 生成基线', ['golden']);
        }

        $scenes = [];
        foreach (glob("{$dir}/*", GLOB_ONLYDIR) ?: [] as $entry) {
            if (basename($entry) === 'page') {
                // page/ 是容器：真正的场景在下一层
                foreach (glob("{$entry}/*", GLOB_ONLYDIR) ?: [] as $pageDir) {
                    $scenes[] = $pageDir;
                }
                continue;
            }
            $scenes[] = $entry;
        }
        if ($scenes === []) {
            return new Check('golden', 'golden 基线', Check::WARN, "{$dir} 下无场景目录", false,
                '跑 php tools/golden-raster.php --update 生成基线', ['golden']);
        }

        $missing = [];
        foreach ($scenes as $scene) {
            if (!is_file($scene . '/expected.rgba.gz')) {
                $missing[] = basename($scene);
            }
        }

        if ($missing !== []) {
            return new Check('golden', 'golden 基线', Check::WARN,
                count($scenes) . ' 个场景中 ' . count($missing) . ' 个缺基线（'
                . implode('/', array_slice($missing, 0, 5)) . (count($missing) > 5 ? '…' : '') . '）',
                false, 'php tools/golden-raster.php --update 补齐（★ 更新前先看 diff 图）', ['golden']);
        }

        return new Check('golden', 'golden 基线', Check::OK, count($scenes) . ' 个场景基线齐全', false, null, ['golden']);
    }
}
