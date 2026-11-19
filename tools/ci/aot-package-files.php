<?php
declare(strict_types=1);

/**
 * AOT 发布包的组成清单 —— CI 打包、完整性校验、README 说明共用这一份。
 *
 * ## 为什么必须自带运行时
 *
 * AOT exe **不是纯静态**：导入表里明确有 phpx.dll / php8ts.dll / VCRUNTIME140*.dll，
 * 而 phpx.dll 又传递依赖 GMP / MPFR / decimal 三个数字库。这些 DLL 任何 Windows
 * 或普通 PHP 发行版都不会全有；缺一个就是 0xC0000135 (STATUS_DLL_NOT_FOUND)，
 * Windows 只弹系统级"找不到 DLL"对话框，没有任何可读诊断。
 *
 * ## 为什么 php.ini 必须相对路径
 *
 * 构建期的 php.ini（`drift-build` 生成）写死构建机 PHP 的绝对路径，那是给
 * probe 注入 PHPRC 用的。分发包必须另写 `extension_dir = "ext/"`，让 phpx
 * 以 exe 所在目录解析 —— 用户解压到哪都能跑，不绑构建机路径。
 *
 * 用法： php tools/ci/aot-package-files.php <包目录> [目标名，默认 hello]
 */

/**
 * 包内必须有。键是包内相对路径（`/` 分隔），值是"为什么不能少"。
 * 与 CI 的 Pack AOT runtime 步骤逐项对应；新增/删除必须两边同步。
 *
 * @return array<string, string>
 */
function aotPackageRequiredFiles(string $target = 'hello'): array
{
    return [
        $target . '.exe'                => 'AOT 产物本体',
        'php.ini'                      => '运行时配置（extension_dir 必须是相对 ext/）',
        'phpx.dll'                     => 'PHPX 运行时（TypePHP 专有，导入表直接依赖）',
        'php8ts.dll'                   => 'ZendVM（ZTS 构建，导入表直接依赖）',
        'gmp-10.dll'                   => 'GMP：phpx.dll 传递依赖',
        'mpfr-6.dll'                   => 'MPFR：phpx.dll 传递依赖',
        'libmpdec-4.0.1.dll'           => 'decimal：phpx.dll 传递依赖',
        'libmpdec++-4.0.1.dll'         => 'decimal C++：phpx.dll 传递依赖',
        'vcruntime140.dll'             => 'MSVC 运行库（exe / php8ts.dll / phpx.dll 导入）',
        'vcruntime140_1.dll'           => 'MSVC 运行库（exe / phpx.dll 导入）',
        'msvcp140.dll'                 => 'MSVC C++ 运行库（phpx.dll 导入）',
        'ext/php_mbstring.dll'         => 'mbstring：编译产物与业务代码必需',
        'ext/php_openssl.dll'          => 'openssl：TLS 与摘要',
        'ext/php_curl.dll'             => 'curl：HTTP 客户端',
        'cacert.pem'                   => 'TLS 信任根（缺了 HTTPS 只能靠系统证书库）',
    ];
}

/** 相对路径 ini 的判据：extension_dir 不能是绝对路径（盘符 / UNC）。 */
function aotPackageIniIsPortable(string $iniText): bool
{
    if (preg_match('/^\s*extension_dir\s*=\s*"([^"]+)"/mi', $iniText, $m) !== 1) {
        return false;
    }
    $dir = $m[1];
    if ($dir === '' || $dir[0] === '/' || $dir[0] === '\\' || preg_match('/^[A-Za-z]:/', $dir) === 1) {
        return false;
    }
    foreach (['mbstring', 'openssl', 'curl'] as $ext) {
        if (preg_match('/^\s*extension\s*=\s*' . preg_quote($ext, '/') . '\s*$/mi', $iniText) !== 1) {
            return false;
        }
    }
    return true;
}

// ── CLI：校验一个已备好的包目录 ──────────────────────────────────────
if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === __FILE__) {
    $dir = $_SERVER['argv'][1] ?? '';
    $target = $_SERVER['argv'][2] ?? 'hello';
    if ($dir === '' || !is_dir($dir)) {
        fwrite(STDERR, "用法： php tools/ci/aot-package-files.php <包目录> [目标名]\n");
        exit(2);
    }

    $missing = [];
    $total = 0;
    $bytes = 0;
    foreach (aotPackageRequiredFiles($target) as $relative => $why) {
        $path = rtrim($dir, '/\\') . '/' . $relative;
        if (!is_file($path)) {
            $missing[] = "{$relative} —— {$why}";
            continue;
        }
        $total++;
        $bytes += (int) filesize($path);
    }

    $iniPath = rtrim($dir, '/\\') . '/php.ini';
    if (is_file($iniPath) && !aotPackageIniIsPortable((string) file_get_contents($iniPath))) {
        $missing[] = 'php.ini —— extension_dir 必须是相对 ext/，且启用 mbstring/openssl/curl';
    }

    foreach ($missing as $item) {
        fwrite(STDERR, "  ✗ 缺 {$item}\n");
    }
    if ($missing !== []) {
        fwrite(STDERR, 'AOT 包不完整：' . count($missing) . " 件缺失，用户装上去会起不来\n");
        exit(1);
    }

    printf(
        "AOT 包完整：%d 件必需文件 · %.1f MB（ini 相对路径可移植）\n",
        $total,
        $bytes / 1048576,
    );
}
