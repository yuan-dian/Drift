# Drift 开发态壳（预编译）

包里的 `dev.exe` 由发布流水线用与 AOT 相同的工具链编出：壳与 C++ 桥只编译一次，
之后改业务 PHP 不再重编。它跑的是 PHP 源码，**不能替代 AOT 验证**。

## 前提

- Windows 10/11 x64
- clone 与本包**同版本 tag** 的 Drift 源码（业务 PHP、字体、组合根都在仓库里）
- PATH 里有 PHP 8.4 ZTS 运行时（提供 `php8ts.dll`）
- 不需要 MSVC / tpc / PHPX / Skia 构建工具链

## 用法

1. 把 `dev.exe` 放到仓库根，`dev-shell.sha` 放到 `_build/`（没有就新建）。
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
都会触发热重启。ini 优先取宿主项目根的 `php.ini`，其次 Drift 包的，最后当前 PHP 的。

## 边界

- 改 `src/` 或 `cpp-src/` 仍必须跑
  `php tools/drift-build.php <app> --full` 与 `python tools/probe-window.py <app>`。
- `php8ts.dll` 必须在 PATH（PHP 发行版目录）；壳内嵌 ZendVM，但仍动态加载该 DLL。
- 指纹不匹配时运行器会尝试重编壳；无构建工具链的机器请用 `--prebuilt`，
  并保证源码与包同 tag，否则壳与源码会静默错位。
