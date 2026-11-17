# Drift 开发态壳（预编译）

包里的 `dev.exe` 由发布流水线用与 AOT 相同的工具链编出：壳与 C++ 桥只编译一次，
之后改业务 PHP 不再重编。它跑的是 PHP 源码，**不能替代 AOT 验证**。

## 前提

- Windows 10/11 x64
- clone 与本包**同版本 tag** 的 Drift 源码（业务 PHP、字体、组合根都在仓库里）
- PATH 里有任意 PHP 8.4 CLI —— 只用来跑 `tools/drift-dev.php` 这个启动器，
  **不需要 ZTS 构建**
- 不需要 MSVC / tpc / PHPX / Skia 构建工具链

## 包里有什么

`dev.exe` 是动态链接的，所以运行时一起打进来：

| 文件 | 作用 |
|---|---|
| `dev.exe` | 壳本体 |
| `phpx.dll` | PHPX 运行时（TypePHP 专有，PHP 发行版没有） |
| `php8ts.dll` | ZendVM（ZTS 构建） |
| `ext/php_mbstring.dll` `ext/php_openssl.dll` `ext/php_curl.dll` | 业务代码与 AOT 需要的扩展 |
| `cacert.pem` | TLS 信任根 |
| `vcruntime140.dll` `vcruntime140_1.dll` `msvcp140.dll` | MSVC 运行库（三个二进制都导入，必需） |

启动器会按包的实际位置生成 `php.ini`，`extension_dir` 指向包内 `ext/`，所以解压到
哪都能跑，不会绑死构建机的路径。

## 用法

1. 把整个包解压到仓库的 `_build/dev-shell/`（`dev.exe` 与 DLL 同级）。
2. 在仓库根执行：

   ```bat
   php tools/drift-dev.php hello
   ```

   源码与包同 tag 时指纹匹配，不会重编。要强制跳过指纹检查：

   ```bat
   php tools/drift-dev.php hello --prebuilt
   ```

3. 改 `apps/<应用>/src/` 下的 PHP 保存后自动重启窗口；`Ctrl+C` 退出。

## 自定义入口 / composer 包

默认入口是 Drift 包内的 `apps/dev/dev-entry.php`，按 PSR-4 加载 `App\<应用>\Bootstrap`。
Drift 作为 composer 包引入时，项目根不是 Drift 包目录，必须同时给出 `--project` 与自己的入口：

```bat
php vendor/yuan-dian/drift/tools/drift-dev.php --project=. --entry=entry.php --prebuilt
```

`--entry` 相对 `--project` 解析（绝对路径原样用）；入口里用 `__DIR__ . '/vendor/autoload.php'`
加载宿主 autoload，因为壳以 Drift 包目录为 cwd。`--project/src`、入口及其同级 PHP 的改动
都会触发热重启。壳自带运行时，ini 由启动器按包位置生成；宿主项目的 `php.ini` 只在
跑本地编译版壳时才被沿用。

## 边界

- 改 `src/` 或 `cpp-src/` 仍必须跑
  `php tools/drift-build.php <app> --full` 与 `python tools/probe-window.py <app>`。
- `php8ts.dll` / `phpx.dll` 就在包内，壳从自己所在目录加载，不进 PATH 也能跑。
- 用发布包时指纹不匹配会**直接报错**，不会尝试重编（这台机器多半没有工具链）：
  要么 checkout 与包同 tag 的源码，要么 `--prebuilt` 显式接受错位，要么在
  有 MSVC / tpc / PHPX 的机器上跑 `tools/drift-build.php dev` 重编。
