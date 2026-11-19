# 快速开始

> 这篇把环境要求分成两档，说明 doctor、dev 壳、AOT 构建与真机探针各自解决什么问题，并明确 dev 绿不等于 AOT 绿。

## 环境分档

环境按「要做什么」分档，不要凭记忆配——体检脚本是路径推导的单一真相源。

| 档 | 能做什么 | 必备 | 不需要 |
|---|---|---|---|
| 档 A | 跑闸门、读代码、无头预览 | PHP >= 8.4（`php.exe` 在 `PATH`）；跑单元测试还需 `composer install` 装出 PHPUnit | MSVC / tpc / PHPX |
| 档 B | 在档 A 之上编出独立可执行程序（Windows `.exe` / Linux ELF） | PHPX、tpc 源码树入口、MSVC、Windows SDK、Skia 预编译包（字体可选） | —— |

★ 表中工具链是 Windows 路径。Linux x64（X11）的 T2 走 `probes/linux_x11/build.sh`（见[平台端口](../guides/platform-ports.md)），依赖 `deps-v2` 的 linux-x64 包与 Xvfb + fluxbox + xclip + yad，能力窄于 Win32。

两个容易混淆的点：

- 仓库根那份 `php.ini` 是**运行态**配置，dev 壳与探针读它；构建另需 `php.exe` 同目录有一份发行版 ini，脚本会复制到 `_build/ini/` 并用 `PHPRC` 指过去。发行版 ini 保持 `opcache.enable_cli=0`，避免「改了代码却没重编」的静默假绿。
- 真机探针用 Python 3，依赖仅标准库；重新生成字模才需要额外图像库。

档 A 的工具脚本直接读 `composer.json` 的 psr-4，不需要 `composer install`；但单元测试需要 PHPUnit，缺失时对应步骤会明确记为未通过，不会伪装成全绿。

## doctor：环境体检

```bash
php tools/doctor.php          # 逐项报告；有 FAIL 退出 1，仅 WARN 退出 0
php tools/doctor.php --strict # 发布前自查：WARN 也算失败
php tools/doctor.php --json   # 机器可读
```

体检用 `affects` 分级：缺 MSVC 不影响只跑闸门，但出 exe 时 `phprc` / `phpx` / `tpc` / `msvc` / `sdk` 必须全绿。缺项会打印修法；若二进制依赖的位置与默认不同，用环境变量覆盖，不要改构建脚本。

## 档 A：跑闸门

```bash
php bin/drift verify          # 日常验证：静态层 + 行为快项
php bin/drift verify --list   # 当前判据清单，唯一权威
php bin/drift lint            # 架构与分层规则
php bin/drift test            # PHPUnit 单元测试
```

这一档无 OS 窗口、可进 CI、结果确定。改了 `apps/` 或 `src/` 的代码就跑它，但它不编译业务 PHP，因此看不见 AOT 编译期语义。

## 路 A：dev 壳（改 PHP 不重编译）

**Windows 10/11 x64 的默认路径是 CI 预编译壳**：壳由产品版本 `v*` 的发布流水线构建，
本地不需要 MSVC / tpc / PHPX / Skia，也不需要 ZTS PHP；只要 PHP 8.4 CLI 跑启动器。
从 [Releases](https://github.com/yuan-dian/Drift/releases) 的产品版本下载
`drift-dev-shell-win-x64-php8.5.zip`（另有 `...php8.4.zip`）；`deps-v*` 只有 Skia / 字体依赖，不是壳。

把整包解压到仓库根 `_build/dev-shell/`（`dev.exe` 与 DLL 同级），然后：

```bash
php tools/drift-dev.php hello
# 等价：php bin/drift dev -- hello
```

源码与包同 tag 时指纹匹配，不会编译。想跨版本使用现成壳时加 `--prebuilt` 显式跳过指纹检查，
这表示你接受错位，不代表壳与源码兼容。改 `apps/<应用>/src/` 下的 PHP 保存后自动重启窗口，
`Ctrl+C` 退出。包内已带 `phpx.dll`、`php8ts.dll`、扩展与 VC 运行库，启动器按包位置生成 `php.ini`，
不绑定构建机路径；包内容与排错见[预编译壳说明](../../tools/ci/dev-shell-README.md)。

dev 壳只编一次「壳 + C++ 桥」，之后改业务 PHP 保存即自动重启窗口，适合日常写界面。它是**所有应用共用**的一个壳，不按应用分别编。只有要改壳 / C++ 桥时才走档 B 工具链在本地重编；业务开发不必先过本地构建链。

**Drift 作为 composer 包引入时**：项目根不是 `vendor/.../drift/`，必须同时给出 `--project`，并在自己的入口里加载宿主 autoload。

```text
my-app/
├── composer.json             # require: yuan-dian/drift
├── vendor/yuan-dian/drift/   # 整包解压到这里：_build/dev-shell/（dev.exe + DLL）
├── entry.php                 # 你的入口
└── src/                      # 你的业务 PHP（此处不列具体文件）
```

```php
<?php
// entry.php —— 壳以 Drift 包为 cwd，用 __DIR__ 定位自己的 vendor
require __DIR__ . '/vendor/autoload.php';
\My\App\Bootstrap::run();
```

```bash
# 从 my-app/ 执行
php vendor/yuan-dian/drift/tools/drift-dev.php --project=. --entry=entry.php --prebuilt
```

`--project` 下的 `src/`、入口及其同级 PHP 的改动都会触发热重启；`vendor/` 里的 Drift 包源码不参与热更（改它需要重编壳，属 T2）。

## 路 B：AOT 构建 + 真机探针

```bash
php -v
php tools/doctor.php                  # 或 php bin/drift verify 先过闸门
php tools/drift-build.php hello       # 等价：php bin/drift build -- hello
python tools/probe-window.py hello    # 等价：php bin/drift probe -- hello
```

`drift-build.php` 输出独立 exe，三态由 `StalePolicy` 裁决：输入未变且产物仍在则跳过；首次、输入变了或产物不在则自动全量；只有显式 `--incremental` 才走增量。`--full` 强制全量，`--dry` 只打印决策不构建。AOT 运行时不再要求 cwd 为仓库根：字体先查 exe 旁可选 `fonts/`，再查系统字体；但 showcase 的示例资源按 `<cwd>/assets` 找，跑那个示例仍需从仓库根启动。

`probe-window.py` 在真窗口上量尺寸、像素、DPI 与关窗退出码，并**自己关窗**；加 `--dev` 则量 dev 壳形态。它也有无头预览的近亲 `drift-preview.php`，输出 SVG 或 PNG，用于确认「排开了、画出来了」，但不是真窗口。

Linux x64（X11）的 T2 入口不同：设好 `PHPX_HOME` / `TPC` 后跑 `bash probes/linux_x11/build.sh`，并备好 Xvfb + fluxbox + xclip + yad 与 Linux Skia `.a`。它覆盖真窗口、事件、IO、解码、剪贴板、外观、壳服务助手接线与干净退出，但只覆盖 Skia + X11 桥的最小应用，能力差见[平台端口](../guides/platform-ports.md)。

## dev 绿不等于 AOT 绿

dev 壳和解释器都跑 PHP 源码，AOT 产物由编译器生成 C++17 再编译，两者语义有真实差异。dev 全绿仍必须跑 T2 的改动包括：

- 控制流 `switch`、两支类型不同的 `?:`、属性读直接当实参等编辑器能过、T2 才炸的写法。
- `int / int` 被编成整数除法带来的静默截断。
- 编译期常量、Trait 与编译期注解的可见性、`sources` 与 `composer.json` 的命名空间扫描是否一致。
- AOT 侧析构与 shutdown 路径、扩展加载与 `php.ini` 分发。

逐值等价量具 `aot-equivalence.php` 与 `dual-mode-equivalence.php` 分别守「解释器 ↔ 全 AOT」与「解释器 ↔ dev 壳」，共用同一份 `Fingerprint` 作唯一预期；它们需要编译产物，不进日常闸门。

## 相关

- [应用解剖](./app-anatomy.md)
- [CLI 参考](../reference/cli.md)
- [AOT 约束](../reference/aot-constraints.md)
- [测试](../guides/testing.md)
