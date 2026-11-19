<p align="center">
  <img src="assets/Drift-app-icon.svg" alt="Drift" width="88">
</p>

# Drift

[![Verify](https://github.com/yuan-dian/Drift/actions/workflows/verify.yml/badge.svg)](https://github.com/yuan-dian/Drift/actions/workflows/verify.yml)
[![Windows x64](https://github.com/yuan-dian/Drift/actions/workflows/windows-build.yml/badge.svg)](https://github.com/yuan-dian/Drift/actions/workflows/windows-build.yml)
[![Linux x64](https://github.com/yuan-dian/Drift/actions/workflows/linux-build.yml/badge.svg)](https://github.com/yuan-dian/Drift/actions/workflows/linux-build.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

> **技术预览** · Windows 10/11 x64（完整桌面）与 Linux x64/X11（能力子集） · API 不保证向后兼容。
> 源码可见，但**不是 1.0**；第三方请勿把它当作稳定依赖。

Drift 是一个 **声明式 + 自绘 + 单语言 AOT** 的桌面 GUI 框架：用 PHP 写界面，经 TypePHP 编译为独立可执行程序（Windows `.exe` / Linux ELF），目标机无需 PHP 运行时。架构与 Flutter 同构——Widget / Element / RenderObject 三棵树、单向下行、约束布局与组合根注入——界面全部自绘，绘制走 Skia。

它面向愿意为“可复现、可验证、单语言交付”付出工程成本的开发者：开发态默认用 CI 构建好的壳加载业务 PHP，本地零构建链，改代码保存即自动重启；发布态才需要完整工具链把同一份代码 AOT 成独立程序。

## 为什么 Drift

- **单语言交付**：业务与框架都是 PHP；发布产物是原生程序，没有额外脚本层或运行时绑定。
- **可预测的 UI**：不可变 Widget + 三棵树 + 单遍约束布局；`RecordingCanvas` 让布局与绘制可重放、可快照、可逐字节比对。
- **自绘一致性**：不包装原生控件；Skia 自绘 + 字体回退链，避免平台控件风格和文本度量漂移。
- **工程有牙齿**：`bin/drift verify` 统一入口 + 缺陷题库变异自证 + golden 基线 + T0/T1/T2 分层真机门；公开 CI 同时覆盖 Win32 与 Linux X11 两条真机链。

借鉴 Flutter 的三棵树与约束布局、SwiftUI 的 modifier 链；有意不借自动依赖追踪、双向绑定、原生控件包装与运行期 ABI 握手。现行结论与失效条件见[裁决索引](docs/decisions/README.md)。

## 平台支持

| 平台 | 状态 | 可执行产物 | T2 真机门 |
|---|---|---|---|
| Windows 10/11 x64 | 完整桌面实现 | `.exe` | `windows-build.yml` + `probe-window` |
| Linux x64（X11） | 已过公开 CI 端到端门，能力子集 | ELF | `linux-build.yml` + `probes/linux_x11/build.sh` |
| macOS | 未接 | —— | 仅在依赖平台表预留 `macos-arm64` |

X11 已接：Skia 自绘与真机事件链、xclip UTF-8 纯文本、zenity/yad 对话框与 yad 托盘、XSettings/环境兜底的暗色与高对比度跟随、XIM 插入符定位。仍是能力子集：无 Wayland / AT-SPI / 完整 IME 组合输入 / per-monitor DPI / 系统色回读；助手或托盘宿主缺失时对应壳服务返回 `null`。两平台端口差异见[平台能力端口](docs/guides/platform-ports.md)。

## 30 秒看懂

```php
final class HelloApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Center::make()->child(Text::make('Hello Drift')->title());
    }

    public function title(): string
    {
        return 'Hello Drift';
    }
}
```

- 最小应用见 [`apps/hello`](apps/hello)，从零写起见 [`apps/tutorial`](apps/tutorial)，控件与主题见 [`apps/showcase`](apps/showcase)。
- 真实业务闭环见 [`apps/watermark`](apps/watermark)：选图 → 上传 → 建任务 → 轮询 → 出图；默认离线 mock，不触网。
- 验证入口只有 `php bin/drift verify`；判据清单只在 `--list`，不抄进文档。

## 快速开始

### 路 A：开发态（改 PHP 自动重启，免本地构建链）

> Windows 10/11 x64。壳由 CI 在产品版本 `v*` 流水线里构建并随 Release 发布，
> 本地不用装 MSVC / tpc / PHPX / Skia，也不用 ZTS PHP。

1. 打开 [Releases](https://github.com/yuan-dian/Drift/releases)，在**产品版本 `v*`** 下载
   `drift-dev-shell-win-x64-php8.5.zip`（另有 `...php8.4.zip`）。`deps-v*` 是 Skia / 依赖包，不是开发壳。
2. 把整包解压到仓库根 `_build/dev-shell/`，保持 `dev.exe` 与 DLL 同级；`php.ini` 由启动器按包位置生成。
3. 从仓库根执行：

```bash
php tools/drift-dev.php hello
# 等价：php bin/drift dev -- hello
```

源码与壳同 tag 时指纹匹配，不会编译。若 checkout 了其他版本但只想用现成壳，加 `--prebuilt`
显式跳过指纹检查；不要把它当成"版本兼容"的承诺。包里自带 `phpx.dll`、`php8ts.dll`、扩展与
VC 运行库，本机只需任意 PHP 8.4 CLI 跑启动器。入口可用 `--project=<目录>` + `--entry=<路径>`
指向自己的项目；作为 composer 包引入时的写法见[快速开始](docs/start/quickstart.md#路-adev-壳改-php-不重编译)。
只有要改壳 / C++ 桥时才需要档 B 工具链在本地重编 `dev.exe`；常规业务开发不需要。

### 路 B：发布态（AOT 可执行程序）

```bash
php -v                                  # PHP >= 8.4（含 php.ini）在 PATH
php tools/doctor.php                    # 体检 MSVC / Win SDK / PHPX / tpc / php.ini
php tools/drift-build.php hello         # 首次或输入变化时自动全量
python tools/probe-window.py hello      # 真窗口：尺寸 / 像素 / DPI / 关窗退出码
```

重型工具链是**唯一门槛**，组件位置与覆盖变量见下方[构建环境](#构建环境)；`doctor` 会逐项报告缺什么并给出修法。改 `apps/<app>/src/` 的业务 PHP 不必重编译，但发版前仍要跑 T2。

### Linux x64（X11）

Linux 的 T2 入口不是 `drift-build.php` + `probe-window.py`，而是端到端门：

```bash
PHPX_HOME=/path/to/phpx bash probes/linux_x11/build.sh
```

它从 tpc/GCC 编出 ELF，在 Xvfb + fluxbox 下跑真窗口、输入、事件、IO、图片解码、WM、剪贴板（外部 `xclip` owner）、系统外观、对话框/托盘助手接线、IME 插入符定位与干净退出判据；CI 对应 `linux-build.yml`。当前只覆盖 Skia + X11 桥的最小应用，不代表 Win32 能力对等。

### 从零写一个应用

读[应用解剖](docs/start/app-anatomy.md) + [`apps/tutorial`](apps/tutorial)。平台实现不出现在 `Application` 子类里，由组合根注入：Win32 走 `bin/win32/Bootstrap.php`，X11 目前由 `probes/linux_x11/app/main.php` 直接装配。

## 版本与发布锚点

根目录三个单行文件是唯一真相源，shell / CI 直接 `cat`：

| 文件 | 含义 | 当前值 |
|---|---|---|
| [`VERSION`](VERSION) | 框架版本，也是 git tag 与 CHANGELOG 小节名的来源 | `0.1.0-preview.1` |
| [`DEPS_VERSION`](DEPS_VERSION) | 配套二进制依赖包 tag（与代码版本解耦） | `deps-v2` |
| [`SKIA_REVISION`](SKIA_REVISION) | 二进制包对应的 Skia 源码 commit | `13ffba25`（M148） |

运行时代码用 `\Drift\Core\Version::VERSION`（另有 `BASE` / `PREVIEW` / `TAG` / `DEPS_TAG`）。**这个类是生成的，不要手改**：AOT 产物里读不到仓库根目录，版本必须在编译期就确定，且不留第二份靠人同步的真相。

```bash
php tools/gen-version.php      # 生成 Version.php + 同步 apps/*/project.yml 的 PE 版本
php tools/pack-deps.php        # 把版本锚点写进依赖清单与 deps.lock.json
php tools/version.php --check  # 全部对齐才过；verify 的 static 层也挂着同样判据
```

`version.php --check` 会比对 CHANGELOG 小节、HEAD tag、代码常量、`deps.lock.json` 的版本锚点和 Skia 来源；`gen-version.php --check` 再覆盖 `project.yml`。漏同步会在闸门里报红，不会拖到发版才发现。流程见[依赖与发布](docs/guides/dependencies-and-release.md)，历史档位见[CHANGELOG](CHANGELOG.md)。

## 构建环境

> 下面以 Windows 工具链为准；Linux X11 的 T2 门走 `probes/linux_x11/build.sh`。

<details>
<summary>档位与 Windows 工具链</summary>

环境只分两档，按需补齐：

- **档 A，跑判据 / 读代码**：只要 PHP。公开 CI 跑的就是这档。
- **档 B，编出独立程序**：在档 A 之上补完整原生工具链。

`php tools/doctor.php` 是环境判据的单一真相源，和构建脚本共用路径推导逻辑；缺什么它会逐项打印修法，不要照记忆配。

**档 A 要点**

- PHP >= 8.4，放进 `PATH`；发行版至少带 `mbstring`（tpc 依赖），CI 另装 `gd`。
- 两份 `php.ini` 别混：仓库根那份是运行态配置（dev 壳 / 探针读它）；构建另需 `php.exe` 同目录有发行版 ini，脚本复制到 `_build/ini/` 并用 `PHPRC` 指过去。发行版 ini 保持 `opcache.enable_cli=0`，否则“改了代码却没重编”会静默假绿。
- 工具脚本不需要 `composer install`（`tools/autoload.php` 直接读 psr-4）；单元测试需要 PHPUnit 10.x，`composer install` 读入库的 `composer.lock`。没装时 `unit` / `feature` 记成失败，不会伪装成全绿。

**档 B 组件**

| 组件 | 要求 | 默认位置 / 覆盖变量 |
|---|---|---|
| PHPX | 含 `include` / `lib` / `src`，与 tpc 同源 | `php.exe` 同目录 `phpx/`，或 `DRIFT_PHPX_HOME` |
| tpc | 编译器源码树入口 `bin/tpc.php`（发行版 `tpc.exe` 缺本仓依赖的修复） | 与本仓同级 `../typephp/bin/tpc.php`，或 `DRIFT_TPC_ENTRY` |
| MSVC | C++ 生成工具即可；实测工具集 `14.51.36231`，需 `cl.exe` | `VC/Tools/MSVC/<版本>`；`DRIFT_MSVC` / `DRIFT_MSVC_VERSION` |
| Windows SDK | `10.0.26100.0`；`include/um` + `include/ucrt` + `lib/um/x64` 齐全 | Windows Kits 的 `10`；`DRIFT_KITS` / `DRIFT_SDK_VERSION` |
| Skia 预编译包（字体可选） | `cpp/skia/` 必需；`cpp/fonts/` 只用于确定性渲染 | 见下方依赖包 |

编译器源码树与运行时分开取，都来自 [swoole/typephp](https://github.com/swoole/typephp)：

```bash
git clone https://github.com/swoole/typephp.git ../typephp
cd ../typephp && composer install && php bin/tpc.php --help
```

版本不能太旧：本仓 AOT 台账在 `bff46481`（含 #183–#186）上验过，见[AOT 约束](docs/reference/aot-constraints.md)；`doctor` 用 git 祖先判定核对，不是只看 `--version` 报的 v0.10.0。不要用 `tpc.exe` 或 `composer require swoole/typephp`——`drift-build` 只认源码树的 `bin/tpc.php`。PHP/PHPX 运行时从[ releases](https://github.com/swoole/typephp/releases) 取 Windows x64、PHP 8.4/8.5 ZTS 资产，解压后加进 `PATH`；已有 PHP 时也可只补 `phpx/`，但必须与编译器同源。

Python 3 只给真机探针 `tools/probe-window.py` 用（标准库 `ctypes`）；重新生成字模才需要 Pillow。MSVC / SDK / PHPX / tpc 位置不同就设上表环境变量，`INCLUDE` / `LIB` / `PATH` 由 `drift-build` 自动拼好，不必手开 Developer Command Prompt。

包默认布局：

```text
<父目录>/
├── typephp/                           # 编译器源码树，入口 bin/tpc.php
└── <repo>/
    ├── cpp/skia/out/Release-x64/*.lib # 不入库，链接必需
    └── cpp/fonts/NotoSansSC-*.ttf     # 不入库，可选
```

`cpp/` 整目录在 `.gitignore` 里，所以 clone 后必须取依赖包；字体缺失也能构建，AOT 运行时默认走系统字体，要跨机像素一致才把字体包放到 exe 旁 `fonts/`。exe 不要求以仓库根为 cwd 启动。

确认环境：

```bash
php tools/doctor.php          # 有 FAIL 退出 1，并打印修法
php tools/doctor.php --strict # 发布前自查：WARN 也算失败
```

</details>

<details>
<summary>依赖包、离线分发与校验值</summary>

依赖包在 GitHub Releases，仓库公开，资产匿名可下载；`deps-v2` 已发布双平台资产。`deps.yml` 是唯一监听 `DEPS_VERSION` 的发布编排，等 `windows-deps.yml` / `linux-deps.yml` 两岸真编成功后才组包、提交锁、打 tag、发布并双平台回验。

```bash
php tools/fetch-deps.php                       # 按宿主 OS 取 DEPS_VERSION 指定的 tag
php tools/fetch-deps.php --platform=linux-x64 # 显式指定平台
php tools/fetch-deps.php --tag=deps-v2         # 临时钉别的版本；--force 重下
```

包内路径全挂在 `cpp/` 下：解压到仓库根，顶层放文件会污染工作区。`cpp/skia/` 是 M148 预编译（Windows 13 个 `.lib` / Linux 13 个 `.a`），已裁掉未使用的 Ganesh / PDF / SVG 等模块；`cpp/fonts/` 是可选的 Noto Sans SC Regular / Bold + `OFL.txt`。完整元数据见[deps.lock.json](deps.lock.json)。

不走 `composer require`：Composer 的 `dist.shasum` 只认 SHA-1，解压会剥掉包内 `cpp/` 顶层，也没有“可选依赖”——包一旦进 `require`，体积会摊给只跑闸门的人。脚本会按入库的 `deps.lock.json` 校验，解压后再数 `.lib` / 字体 / 许可，对不上直接失败。

私有镜像、匿名直链限流或内网机器走 API / 本地包：

```bash
php tools/fetch-deps.php --url=./drift-deps-win-x64.zip
DRIFT_DEPS_URL=. php tools/fetch-deps.php       # 指目录也行，脚本补包名
```

`--url` 认裸路径 / 反斜杠 / `file://` / 百分号编码，与在线路径走同一套校验。token 顺序：`--token` > `GITHUB_TOKEN` > `GH_TOKEN` > `~/.git-credentials`；Windows 的 Git Credential Manager 存在系统凭据库里读不出来，那种机器只能用环境变量。手工解压可以，但不做校验与解压后对账。

当前包校验值（按平台顺序，由 `pack-deps.php` 写入、`fetch-deps.php` 校验）：

```text
# win-x64
90aafe68a178f8b97e365a6733ee48e64cfa83a61d81e961e1398487e5b68d16
# linux-x64
dc444fbdfb168832a4b9d890c9e422f3ba18bce35093b304c86fa93a32e6c73c
```

同一份 `cpp/` 重打包 SHA 不变：条目按路径排序，ZIP DOS 时间统一写成 1980-01-01 00:00，跨时区重建也一致；否则 CI 无法判断“依赖真的变了没有”。重新发布依赖包：

```bash
php tools/pack-deps.php                        # 生成包、.sha256 与 deps.lock.json
php tools/release-deps.php                    # tag 取 DEPS_VERSION，需 GITHUB_TOKEN
php tools/release-deps.php --dry-run           # 只看要做什么
```

</details>

<details>
<summary>从源码构建 Skia（可选）</summary>

构建输入全部入库：`SKIA_REVISION`、`tools/skia/args.gn.tpl`、`tools/skia/headers.txt`、`tools/skia/build-skia.sh`。机器相关的 VS / Win SDK / LLVM 路径不写进仓库，用 `SKIA_WIN_VC` 等环境变量覆盖。

```bash
# 需要 git / python3 / LLVM 在 PATH；gn / ninja 由脚本按需下预编译包
printf '<40 位 Skia commit>' > SKIA_REVISION
bash tools/skia/build-skia.sh --replace                              # Windows：产出 cpp/skia
bash tools/skia/build-skia.sh --platform=linux --out=cpp/skia-linux   # Linux（二选一）
php tools/pack-deps.php --platform=win-x64                           # Linux 换 --platform=linux-x64
```

`SKIA_REVISION` 已 pin 到 M148 真实 commit，`deps.lock.json` 的 `skiaRevision` 与 `provenance: ci` 同步；`deps-v2` 由 `deps.yml` 从该 commit 双平台真编并发布（各 599 条目 / win 21.5 MB、linux 21.3 MB）。日常不需要自己编，细节见[依赖与发布](docs/guides/dependencies-and-release.md)。

</details>

## 架构一瞥

- **三棵树**：不可变 Widget 描述意图，Element 管身份与生命周期，RenderObject 管布局与绘制；单向下行，显式 `setState()`。
- **平台端口**：`Platform` 是能力端口集合，不是上帝接口；平台名只出现在组合根，`src/` 只依赖端口。壳在 C++ 侧暴露稳定 ABI，`.stub.php` 是唯一事实源，构建期做 stub / C++ / 替身三向签名对账，运行期不握手。
- **渲染**：`Canvas` 的扁平 op 流有 `RasterCanvas` / `SkiaCanvas` / `RecordingCanvas` 三实现；X11 走 `X11SkiaSurface`，不退回软光栅冒充通过。
- **验证**：`php bin/drift verify` 是唯一入口；判据清单只在 `--list`。T0 纯 PHP、T1 dev 壳、T2 AOT 真机，dev 绿不等于 AOT 绿。

心智模型从[心智模型](docs/concepts/model.md)进，再看[帧管线](docs/concepts/frame-pipeline.md)与[渲染模型](docs/concepts/rendering.md)。

## 文档地图

| 你想做的事 | 去哪 |
|---|---|
| 建立预期、知道边界在哪 | [框架概览](docs/start/overview.md) |
| 装环境、跑命令、理解 dev 与 AOT 两条路 | [快速开始](docs/start/quickstart.md) |
| 按任务写控件 / 布局 / 文本 / 交互 / 异步 | [docs/README.md](docs/README.md) |
| 理解平台端口、多窗口、DPI、ABI | [平台能力端口](docs/guides/platform-ports.md) |
| 知道什么必须上真机、CI 怎么分层 | [验证体系](docs/guides/testing.md) |
| 查现行裁决与失效条件 | [裁决索引](docs/decisions/README.md) |
| 提 issue / 提 PR / 看协作纪律 | [CONTRIBUTING.md](CONTRIBUTING.md) · [协作流程](docs/contributing/workflow.md) |

## 当前边界

技术预览，Windows x64 完整桌面 + Linux x64/X11 能力子集，API 可能变；能力面已经够写真实业务应用（见 `apps/watermark`）。尚无触控 / 笔、i18n / RTL、统一 `create` 脚手架、第三方组件生态与包管理、安装器 / 签名 / 自动更新；Linux 侧缺口见[平台支持](#平台支持)。缺失项与“有意不为”以[框架概览](docs/start/overview.md)和[裁决索引](docs/decisions/README.md)为准。

## 参与贡献

欢迎可复现的缺陷报告、真实应用踩坑与判据补强。提交前先读[裁决索引](docs/decisions/README.md)避免方向已被否决，再按[协作流程](docs/contributing/workflow.md)和[环境搭建](docs/contributing/setup.md)验证；新增 lint 规则或判据必须能回答“什么变异让它报红”。安全漏洞不要开公开 issue，走[SECURITY.md](SECURITY.md)。

## 许可证

MIT，全文见 [`LICENSE`](LICENSE)；机器可读声明在 [`composer.json`](composer.json)。

随包分发的第三方组件另按其自身许可：Skia 为 BSD-3-Clause，Noto Sans SC 为 SIL OFL 1.1，另有九个静态库（Expat / FreeType / HarfBuzz / ICU / libjpeg-turbo / libpng / libwebp / Wuffs / zlib）。11 份全文与来源记录见 [`licenses/THIRD-PARTY.md`](licenses/THIRD-PARTY.md)，打包时整目录随附。
