<?php
declare(strict_types=1);

/**
 * dev 壳发布包的组成清单 —— 打包、校验、README 说明共用这一份。
 *
 * ## 为什么必须自带运行时
 *
 * `dev.exe` 是动态链接的，依赖三样宿主机器上没有的东西：
 *   - `phpx.dll`  —— TypePHP 专有运行时，任何 PHP 发行版都不带
 *   - `php8ts.dll` —— ZendVM；非 ZTS 的 PHP 只有 php8.dll
 *   - `VCRUNTIME140*.dll` —— MSVC 运行库，Windows 不预装
 * 缺任一样时 Windows 弹的是系统级"找不到 DLL"对话框，没有任何可读诊断，
 * 用户只会以为包坏了。所以这些文件是**包的一部分**，不是"用户自己装"。
 *
 * 用法：
 *   php tools/ci/dev-shell-files.php <包目录>   校验目录里齐不齐（缺了非零退出）
 */

/**
 * 包内必须有。键是包内相对路径（`/` 分隔），值是"为什么不能少"。
 *
 * @return array<string, string>
 */
function devShellRequiredFiles(): array
{
    return [
        'dev.exe'              => '壳本体',
        'phpx.dll'             => 'PHPX 运行时（TypePHP 专有，PHP 发行版没有）',
        'php8ts.dll'           => 'ZendVM（ZTS 构建）',
        'ext/php_mbstring.dll' => 'mbstring：AOT 与业务代码的必需扩展',
        'ext/php_openssl.dll'  => 'openssl：TLS 与摘要',
        'ext/php_curl.dll'     => 'curl：HTTP 客户端',
        'cacert.pem'           => 'TLS 信任根（缺了 HTTPS 只能靠系统证书库）',
        'dev-shell.sha'        => '壳指纹（源码与包不一致时拒跑）',
        'README.md'            => '包内使用说明',
    ];
}

/**
 * 有就带、没有不算错 —— 版本与镜像差异，不写死成硬要求。
 *
 * @return list<string>
 */
function devShellOptionalFiles(): array
{
    return ['vcruntime140.dll', 'vcruntime140_1.dll', 'msvcp140.dll'];
}

// ── CLI：校验一个已备好的包目录 ──────────────────────────────────────
if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === __FILE__) {
    $dir = $_SERVER['argv'][1] ?? '';
    if ($dir === '' || !is_dir($dir)) {
        fwrite(STDERR, "用法： php tools/ci/dev-shell-files.php <包目录>\n");
        exit(2);
    }

    $missing = [];
    $total = 0;
    $bytes = 0;
    foreach (devShellRequiredFiles() as $relative => $why) {
        $path = rtrim($dir, '/\\') . '/' . $relative;
        if (!is_file($path)) {
            $missing[] = "{$relative} —— {$why}";
            continue;
        }
        $total++;
        $bytes += (int) filesize($path);
    }

    foreach ($missing as $item) {
        fwrite(STDERR, "  ✗ 缺 {$item}\n");
    }
    if ($missing !== []) {
        fwrite(STDERR, 'dev 壳包不完整：' . count($missing) . " 件缺失，用户装上去会起不来\n");
        exit(1);
    }

    $optional = [];
    foreach (devShellOptionalFiles() as $relative) {
        if (is_file(rtrim($dir, '/\\') . '/' . $relative)) {
            $optional[] = $relative;
        }
    }

    printf(
        "dev 壳包完整：%d 件必需文件 · %.1f MB%s\n",
        $total,
        $bytes / 1048576,
        $optional === [] ? '' : ' · 另带 ' . implode(', ', $optional),
    );
}
