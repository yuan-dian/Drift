# 环境搭建

> 说明跑判据要装什么、编 exe 还要补什么，以及每个组件的位置如何覆盖、如何自查。

## 先看自己属于哪一档

环境只分两档，按需补齐，不要一次全装：

- **档 A，跑判据 / 读代码**：只要 PHP。公开 CI runner 跑的就是这档，`php bin/drift verify` 应全绿，不需要 MSVC / tpc / PHPX。
- **档 B，编出独立 exe**：在档 A 之上补一整套原生工具链，才能做 T2 真机构建。

只支持 Windows 10/11 x64。`php tools/doctor.php` 是环境判据的单一真相源，它和构建脚本共用同一份路径推导逻辑，不会各写一套。不要按记忆配环境——缺什么 doctor 会逐项打印修法。

## 档 A：PHP

- **PHP >= 8.4**，把 `php.exe` 放进 `PATH`。发行版至少带 `mbstring`（tpc 依赖），CI 另装 `gd`。`composer.json` 声明 `>=8.4` 且无上界，所以 CI 在 8.4 和 8.5 两个版本都跑，只验一个版本等于没兑现承诺。
- **两份 `php.ini` 别弄混**：仓库根那份是运行态配置，本地编译版 dev 壳和探针读它（发布包的壳自带运行时，由启动器按包位置生成 ini，不读这份）；构建另需 `php.exe` 同目录有一份发行版 ini，tpc 不加载同目录 ini，脚本会复制到 `_build/ini/` 并用 `PHPRC` 指过去。发行版 ini 要保持 `opcache.enable_cli=0`——CLI 开 opcache 会让"改了代码却没重编"静默假绿。
- **工具脚本不需要 `composer install`**，`tools/autoload.php` 直接读 `composer.json` 的 psr-4 配置。
- **单元测试需要 PHPUnit**：`composer install` 读入库的 `composer.lock` 装出 `vendor/bin/phpunit`。`vendor/` 不入库，也没有 phar 兜底。没装时 unit / feature 步骤起不来，报告记成失败而不是伪装成全绿。
- **Python 3** 只给真机探针 `tools/probe-window.py` 用，依赖仅标准库 `ctypes`；重新生成字模的脚本才需要 Pillow。

档 A 有一个例外：dev 壳虽然"改 PHP 不重编"，但首次仍要编一次，那一步属于档 B。

## 档 B：原生工具链

在档 A 之上补齐下列组件。版本错一点就是链接失败或静默行为不对。

| 组件 | 要求 | 默认位置 | 覆盖变量 |
|---|---|---|---|
| PHPX | 含 `include` / `lib` / `src`，须与 tpc 同源 | `php.exe` 所在目录的 `phpx/` | `DRIFT_PHPX_HOME` |
| tpc | 编译器源码树入口 `bin/tpc.php` | 与本仓同级的 `../typephp/bin/tpc.php` | `DRIFT_TPC_ENTRY` |
| MSVC | VS 2022 / 18，工具集 `14.51.36231`，需 `cl.exe` | Visual Studio 的 `VC/Tools/MSVC/14.51.36231` | `DRIFT_MSVC` / `DRIFT_MSVC_VERSION` |
| Windows SDK | `10.0.26100.0`，`include/um`、`include/ucrt`、`lib/um/x64` 三处齐全 | Windows Kits 的 `10` 目录 | `DRIFT_KITS` / `DRIFT_SDK_VERSION` |
| Skia 预编译包（字体可选） | Skia M148，头文件与 `out/Release-x64` 下的 `.lib`；字体只用于确定性渲染 | `cpp/skia/`；可选 `cpp/fonts/` | 见下 |

几个必须注意的点：

- MSVC 只需独立的 [**C++ 生成工具**](https://visualstudio.microsoft.com/zh-hans/visual-cpp-build-tools/)，不必装完整 Visual Studio IDE：勾选「使用 C++ 的桌面开发」工作负载（含 MSVC x64 工具集、Windows SDK、CMake、vcpkg）。本机实测版本 `14.51.36231` 写在 `Environment.php`，只是默认值；Build Tools 装出的版本/路径不同就用 `DRIFT_MSVC` 指向实际 `VC/Tools/MSVC/<版本>`，并用 `DRIFT_MSVC_VERSION` 同步展示值，不要为凑版本号去装全版。
- tpc 要的是**编译器源码树**，不要用 release 包里的 `tpc.exe`，也不要 `composer require swoole/typephp`——发行版的可执行文件缺本仓依赖的编译期修复。`drift-build` 和 `drift-codegen` 只认源码树的 `bin/tpc.php`。放别处就用 `DRIFT_TPC_ENTRY` 指过去。
- PHP + PHPX 运行时来自 TypePHP 的 Windows x64、PHP 8.4/8.5 ZTS 发行资产，解压后把目录加进 `PATH`；包里自带 `php.ini`、`ext/` 与 `phpx/`。已有 PHP 时可只补 `phpx/`，但必须与编译器同源。
- doctor 用 git 祖先判定核对 tpc 源码树是否含最低修复基线（`bff46481`）；源码树不是 git 或基线不在历史里时报 WARN，不静默放行。`--version` 固定报 v0.10.0，区分不了 commit 级修复。

## Skia 与可选字体

`cpp/` 整个目录在 `.gitignore` 里，`git clone` 之后 `cpp/skia/` 不存在，缺了会在链接期直接失败。`cpp/fonts/` 只承担确定性渲染：不附带也能构建，AOT 运行时默认查系统字体；要跨机像素一致或离线部署时，把字体包放到 exe 旁 `fonts/`。exe 不再要求以仓库根为 cwd 启动。

默认布局是编译器源码树与本仓同级：

```text
<父目录>/
├── typephp/                           # 编译器源码树，入口 bin/tpc.php
└── <repo>/                            # 本仓库
    ├── cpp/skia/out/Release-x64/*.lib # 不入库
    └── cpp/fonts/NotoSansSC-*.ttf     # 不入库
```

包内容：`cpp/skia/` 是预编译头文件与 `.lib`，附许可文件；`cpp/fonts/` 是可选的 Noto Sans SC Regular / Bold 加 `OFL.txt`（SIL OFL 1.1 全文，重分发必须保留）。打包产物解压到仓库根，包内路径都挂在 `cpp/` 下，不要手动把文件散到顶层污染工作区。

## 取包：Release 与离线

依赖包托管在 GitHub Releases，仓库公开，资产匿名可下载：当前 `deps-v2` 双平台资产已发布，`php tools/fetch-deps.php` 直接可取。fork 出的私有镜像、或匿名直链被限流时，可给 token 走 API 解析（只需 `Contents: Read`）。

```bash
php tools/fetch-deps.php                     # 按宿主 OS 取 DEPS_VERSION 的 tag
php tools/fetch-deps.php --platform=linux-x64 # 显式指定平台（deps-v2 起）
php tools/fetch-deps.php --tag=<tag>         # 临时钉别的版本；--force 重下
php tools/fetch-deps.php --latest            # 改取仓库最新 release
```

token 的取法依次是：`--token=…` > `GITHUB_TOKEN` > `GH_TOKEN` > `~/.git-credentials`。Windows 上 Git Credential Manager 把凭据放在系统凭据库里读不出来，那种机器只能用环境变量。

从已有 `cpp/` 的机器分发，适合内网 / 离线机器，或想复用本机已构建好的包：

```bash
php tools/pack-deps.php --platform=win-x64              # 有 cpp/ 的机器：按平台打包
php tools/fetch-deps.php --url=<包路径>                   # 新机器：用本地包装上
DRIFT_DEPS_URL=<目录> php tools/fetch-deps.php            # 指目录也行，脚本补包名
```

`--url` 认裸路径、反斜杠、`file://` 和百分号编码。两种取法走同一套校验：拿入库的 `deps.lock.json` 校验，解压后再数 `.lib`、字体与许可，对不上直接失败，不会拖到构建期报链接错误。

不走 `composer require`：Composer 的校验口径和打包口径不同，解压还会剥掉包内的 `cpp/` 顶层，而且没有可选依赖机制——包一旦进 `require`，体积会摊给每个只跑判据、根本不需要 Skia 的人。

## 从 clone 到 hello 的最短路径

一条命令串不起来，因为中间隔着两件**发布侧**的事，不是本地命令能补的：仓库可见性、依赖包是否已发布——这两件现在都已就绪（仓库公开、`deps-v2` 已发布），下面按顺序走：

1. `git clone <仓库>` —— 此时 `cpp/` 为空，只能跑档 A。
2. 取依赖包：`php tools/fetch-deps.php`（`deps-v2` 已发布）；离线 / 内网机器走 `--url=<本机 zip>`（见上节）。
3. 补齐档 B 工具链（TypePHP 源码树 + PHPX + MSVC + SDK），路径不对设环境变量，不改脚本。
4. `php tools/doctor.php` —— **单一真相源**，逐项报缺什么、给修法；全绿再往下。
5. `php tools/drift-build.php hello` → `python tools/probe-window.py hello`。

每一步的判据都在 doctor 里，本文不复述它的检查项（会漂）。`doctor` 报 FAIL 就按它打印的修法走，不要跳到构建期再猜。

## 自查

装完按这个顺序确认：

1. `php bin/drift verify`：档 A 应全绿。
2. `php tools/doctor.php`：逐项体检，只读、不安装。看缺哪一项，按输出修。
3. `php tools/doctor.php --strict`：把警告也当失败，档 B 用它确认没有临近边界的项。
4. `php bin/drift build -- <app> --full`：真正编一次 exe，这是对档 B 最硬的确认。

doctor 与 `drift-build` 共用定位逻辑，所以 doctor 通过而构建找不到组件的情况，按 doctor 输出和上面的覆盖变量逐个对照排查。命令速查见[命令速查](../reference/cli.md)。

## 相关

- [命令速查](../reference/cli.md)
- [协作流程](./workflow.md)
- [AOT 约束与代码纪律](../reference/aot-constraints.md)
