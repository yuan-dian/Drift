# Drift

> **技术预览 · 仅 Windows 10/11 x64 · API 不保证向后兼容。**
> 源码可见的技术预览，**不是** 1.0，第三方请勿当作稳定依赖。
> 版本与发布档位见 [`CHANGELOG.md`](CHANGELOG.md)。

Drift 是一个**声明式 + 自绘 + 单语言 AOT** 的桌面 GUI 框架：用 PHP 写界面，
编译成独立 `.exe`（目标机无需 PHP 运行时）。架构与 Flutter 同构
（Widget / Element / RenderObject 三棵树 + 单向下行 + 组合根注入），绘制走 Skia。

最小应用（节选，完整版见 [`apps/hello`](apps/hello)）：

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

## 30 秒看懂

- 用 PHP 写声明式 UI，AOT 编译为原生 Windows 程序；dev 态改 PHP 不重编译。
- 自带业务闭环样例 [`apps/watermark`](apps/watermark)（选图 → 上传 → 建任务 → 轮询 → 出图）；
  **默认离线 mock，不触网**，切到真实 OSS 直传网关的方式见该应用的 `Config` 类。
- 工程质量：`bin/drift verify` 统一验证 + 缺陷题库覆盖率对照 + T0/T1/T2 分层真机验证。

## 版本号

**版本与依赖锚点是根目录三个单行文件**，shell / CI 可直接 `cat`：

| 文件 | 含义 | 当前值 |
|---|---|---|
| [`VERSION`](VERSION) | 框架版本，也是 git tag 与 CHANGELOG 小节名的来源 | `0.1.0-preview.1` |
| [`DEPS_VERSION`](DEPS_VERSION) | 配套的二进制依赖包 tag（与代码版本解耦） | `deps-v1` |
| [`SKIA_REVISION`](SKIA_REVISION) | 二进制包对应的 Skia 源码 commit | `unpinned` |

运行时代码用 `\Drift\Core\Version::VERSION`（另有 `BASE` / `PREVIEW` / `TAG` / `DEPS_TAG`）。
**这个类是生成的，不要手改**：AOT 产物里没有仓库根目录可读，所以版本必须在编译期就有了；
同时不该出现第二份靠人记着同步的真相。

改版本只需动一个文件，其余全部派生：

```bash
# 改 VERSION（或 DEPS_VERSION）
php tools/gen-version.php      # 生成 src/Core/Version.php + 同步 8 份 apps/*/project.yml 的 PE 版本
php tools/pack-deps.php        # 把版本锚点写进 DEPS-MANIFEST 与 deps.lock.json
php tools/version.php --check  # 全部对齐才过；verify 的 static 层也挂着同样的判据
```

`version.php --check` 会比对 `CHANGELOG.md` 的版本小节、HEAD 上的 tag、代码常量、
`deps.lock.json` 的版本锚点，以及 `SKIA_REVISION` 与 lock 里的 `skiaRevision`；
`gen-version.php --check` 再覆盖 8 份 `project.yml`。漏了同步会在闸门里响，
而不是发版时才发现 tag 和 CHANGELOG 对不上。升级流程与借鉴来源见
[`依赖与发布`](docs/guides/dependencies-and-release.md)。

## 构建环境：装什么、放哪、怎么确认

> 仅 Windows 10/11 x64。分两档：**跑判据 / 读代码**只要 PHP；**编出 `.exe`**还要一整套
> 原生工具链。`tools/doctor.php` 是环境判据的单一真相源（与 `drift-build` 共用同一份
> 路径推导），不要照记忆配 —— 缺什么它会逐项打印修法。

### 档 A：只跑闸门（T0，公开 CI runner 跑的就是这档）

- **PHP >= 8.4**（`composer.json` 的 `require.php`），把 `php.exe` 放进 `PATH`；
  发行版至少带 `mbstring`（tpc 依赖），CI 同时装 `gd`。
- **两份 `php.ini` 别弄混**：仓库根那份是**运行态**（dev 壳 / 探针读它，已入库）；
  构建另需 `php.exe` 同目录有一份**发行版 ini** —— tpc 不加载同目录 ini，
  脚本会复制到 `_build/ini/` 并用 `PHPRC` 指过去。发行版 ini 保持 `opcache.enable_cli=0`：
  CLI 开 opcache 会让「改了代码却没重编」静默假绿。
- **工具脚本不需要 `composer install`**（`tools/autoload.php` 直接读 `composer.json` 的 psr-4），
  但**单元测试需要 PHPUnit 10.x**：`composer install` 读入库的 `composer.lock`
  （pin 10.5.66），装出 `vendor/bin/phpunit`；`/vendor/` 不入库，也没有 phar 兜底。
  没装时 `unit` / `feature` step 起不来，V2 报告记成 `exitCode=127 / ok=false`，
  不会伪装成全绿；CI 里再由 `.github/scripts/assert-verify-report.php`
  独立读 `items[].status`，`unit` 不是 `ok` 就直接判红，不靠人眼看报告。
- **不需要 MSVC / tpc / PHPX**：`php bin/drift verify` 应全绿；
  `doctor` 里只影响 build 的项在这档不拦。
  ★ 但「改 PHP 不重编译」的 dev 壳首次仍要编一次，属于档 B。

### 档 B：要编出独立 `.exe`（T2）

在档 A 之上补齐：

| 组件 | 要求 | 默认位置 / 覆盖变量 |
|---|---|---|
| PHPX | 含 `include` / `lib` / `src`，与 tpc 同源 | `php.exe` 所在目录的 `phpx/`，或 `DRIFT_PHPX_HOME` |
| tpc（TypePHP 编译器） | 源码树入口 `bin/tpc.php`（发行版 `tpc.exe` 缺编译期修复，不作默认） | 与本仓同级的 `../typephp/bin/tpc.php`，或 `DRIFT_TPC_ENTRY` |
| MSVC | VS 2022（18），工具集 `14.51.36231`，需 `cl.exe` | `D:/Microsoft Visual Studio/18/Community/VC/Tools/MSVC/14.51.36231`；`DRIFT_MSVC` / `DRIFT_MSVC_VERSION` |
| Windows SDK | `10.0.26100.0`；`include/um` + `include/ucrt` + `lib/um/x64` 三处齐全 | `D:/Windows Kits/10`；`DRIFT_KITS` / `DRIFT_SDK_VERSION` |
| Skia 预编译包 + 字体 | `cpp/skia/`（约 141 MB）与 `cpp/fonts/`（约 21 MB）**不随仓库分发** | 见下 |

#### ① 下载安装 TypePHP（两件分开的东西）

本仓要的是**编译器源码树** + **Windows PHP/PHPX 运行时**，两者都来自
[swoole/typephp](https://github.com/swoole/typephp)，都不在本仓里。

**1) 编译器源码树**（`tpc` 入口）：

```bash
git clone https://github.com/swoole/typephp.git ../typephp  # 与本仓同级 ⇒ 命中默认路径
cd ../typephp
composer install                 # Composer 2；给编译器装依赖，不是给本仓装
php bin/tpc.php --help
```

★ 版本不能太旧：本仓 AOT 台账在 `bff46481`（含 #183–#186）上验过，见
[`AOT 约束`](docs/reference/aot-constraints.md)。
`doctor` 只验 `tpc` 入口文件存在，**不验版本** —— 旧编译器要到构建期才报错。
**不要用 release 包里的 `tpc.exe`，也不要用 `composer require swoole/typephp`**：
`drift-build` / `drift-codegen` 只认源码树的 `bin/tpc.php`（发行版 `tpc.exe`
缺本仓依赖的编译期修复）。放在别处就设 `DRIFT_TPC_ENTRY` 指向那个 `bin/tpc.php`。

**2) PHP + PHPX 运行时**：在 [Releases](https://github.com/swoole/typephp/releases)
选 Windows x64、PHP 8.4/8.5 ZTS 的资产（本机用的是
`release-windows-x64-php-8.5-zts.zip`），解压到任意目录，把该目录加进 `PATH`。
包里自带 `php.ini`、`ext/` 与 `phpx/`（`include` / `lib` / `src`），正好满足
`doctor` 的 `phprc` / `phpx` 两项；包内 `WINDOWS-SETUP.txt` 让手动设
`PHP_HOME` / `PHPX_HOME`，本仓由 `drift-build` 自动派生，不必照做。
已有 PHP >= 8.4 发行版时也可只补 `phpx/` 到 `php.exe` 同目录（或设 `DRIFT_PHPX_HOME`），
但 PHPX 必须与编译器同源。

Python 3 只给真机探针 `tools/probe-window.py` 用，依赖仅标准库 `ctypes`；
重新生成字模的脚本才需要 Pillow。

#### ② 补 Skia 与字体

目录关系（默认布局）：

```text
<父目录>/
├── typephp/                           # ① 编译器源码树；tpc 入口 bin/tpc.php
└── <repo>/                            # 本仓库
    ├── cpp/skia/out/Release-x64/*.lib # ② 不入库
    └── cpp/fonts/NotoSansSC-*.ttf     # ② 不入库
```

★ **最大的隐藏门槛**：`/cpp/` 整目录在 `.gitignore` 里，`git clone` 后 `cpp/skia/`
与 `cpp/fonts/` **都不存在**，缺了构建期会直接链接失败。exe 还必须以仓库根为 cwd
启动（两者按相对路径查找）。

包的内容：

| 项 | 内容 |
|---|---|
| `cpp/skia/` | Skia **M148** 预编译（clang / x64 / Release）：`include/` + `modules/` + `src/` + `out/Release-x64/` 下 24 个 `.lib`，附 `LICENSE`（BSD-3-Clause） |
| `cpp/fonts/` | Noto Sans SC Regular / Bold + `OFL.txt`（SIL OFL 1.1 全文，重分发必须保留） |
| 打包产物 | `drift-deps-win-x64.zip`，约 50 MB（解压后 159 MB），含 `cpp/DEPS-MANIFEST.txt` 与 `cpp/licenses/`（11 份许可全文 + `THIRD-PARTY.md` 索引）。包内路径全挂在 `cpp/` 下 —— 它解压到仓库根，顶层放文件会污染工作区 |

首次获取（包托管在 GitHub Releases；**本仓库是私有的，必须带 token**）：

```bash
export GITHUB_TOKEN=ghp_xxx               # PowerShell: $env:GITHUB_TOKEN = "ghp_xxx"
php tools/fetch-deps.php                  # 取 DEPS_VERSION 指定的 tag
php tools/fetch-deps.php --tag=deps-v2    # 临时钉别的版本；--force 重下
php tools/fetch-deps.php --latest         # 改取仓库最新 release
```

私有仓库的资产**没有可匿名访问的地址**：不带 token 时 `releases/download/...` 一律回
404，看起来像"还没发布"。带上 token 后脚本改走 API 解析资产 id，再按
`Accept: application/octet-stream` 下载。token 只需 `repo` 读权限。

token 的取法依次是：`--token=…` > `GITHUB_TOKEN` > `GH_TOKEN` > `~/.git-credentials`。
Windows 上 Git Credential Manager 把凭据放在系统凭据库里读不出来，那种机器只能用环境变量。

它会下到 `dist/`、拿入库的 `deps.lock.json` 校验、解压到仓库根，再数一遍 `.lib` / 字体 /
许可数量 —— 对不上直接失败，不用等到构建期吃 LNK2019。

不走 `composer require`：Composer 的 `dist.shasum` 只认 SHA-1（填 SHA-256 直接校验失败）、
解压会剥掉包内的 `cpp/` 顶层，而且没有"可选依赖"—— 包一旦进 `require`，50 MB 会摊给每个
只跑闸门、根本不需要 Skia 的人。实测数据与取舍见 [`依赖与发布`](docs/guides/dependencies-and-release.md)
末节「为什么不用 Composer 分发」。

从已有 `cpp/` 的机器分发（**首个 release 之前唯一可行**，也是内网 / 离线机器的做法）：

```bash
# ① 有 cpp/ 的机器：打包（产物 dist/drift-deps-win-x64.zip + .sha256）
php tools/pack-deps.php

# ② 新机器：clone 后把那个 zip 拷过来，一条命令装上
php tools/fetch-deps.php --url=D:/transfer/drift-deps-win-x64.zip
DRIFT_DEPS_URL=D:/transfer php tools/fetch-deps.php   # 指目录也行，脚本自己补包名
```

`--url` 认裸路径 / 反斜杠 / `file://` / 百分号编码；路径写错会直接说「既不是网址也不是
已存在的文件」，不会再抛 curl 的 `Port number was not a decimal number`。这条命令与在线
那条走**同一套校验**（`deps.lock.json` + 解压后数 `.lib` / 字体 / 许可）。

手工解压仍然可以，但不校验、也不做解压后对账：

```bash
unzip drift-deps-win-x64.zip -d <仓库根>       # 得到 cpp/skia、cpp/fonts、cpp/licenses
```

```powershell
Expand-Archive drift-deps-win-x64.zip -DestinationPath <仓库根>
Get-FileHash drift-deps-win-x64.zip -Algorithm SHA256   # 校验收到的包
```

当前包的校验值（`pack-deps.php` 打包时自动写入，`fetch-deps.php` 用它校验）：

```text
a3b45d2ca542c89a6091a77878fb852f964e8a7e20d1d9f89ed819005dfc67e9
```

同一份 `cpp/` 重打包 SHA 不变：条目按路径排序，且所有 ZIP DOS 时间统一写成
1980-01-01 00:00（包内不写扩展时间戳，跨时区重建也一致）。否则每次打包都换一个哈希，
CI 就无法判断「依赖真变了没有」。

完整元数据（字节数 / entry 数 / Skia 里程碑 / 24 个 lib / 2 个字体 / 11 份许可）见
[`deps.lock.json`](deps.lock.json)。它入库，所以换包必须同时改仓库文件 —— 这是校验能成立的前提；
`dist/*.sha256` 只是同批上传的副本。

★ **首个 release 尚未发布**：GitHub 那条路会取不到资产 —— 用上面的 `--url=<本机 zip>`
（离线包），校验与在线那条一样。
发布是一次性的（打包 → 建 release → 传两个资产，脚本全包）：

```bash
php tools/pack-deps.php                        # 生成包、.sha256 与 deps.lock.json
php tools/release-deps.php                    # tag 取 DEPS_VERSION；需要 GITHUB_TOKEN
php tools/release-deps.php --dry-run           # 只看要做什么
```

发布脚本会先把包 / `.sha256` / `deps.lock.json` 三者对齐，再核对版本锚点
（`lock` 里的 `driftVersion` / `depsTag` 必须等于根目录 `VERSION` / `DEPS_VERSION`），
全对齐才动手；覆盖已有资产要加 `--force`（GitHub 不支持改资产内容）。完整流程与取舍见 [`依赖与发布`](docs/guides/dependencies-and-release.md)。

**不想要预编译包、要从源码自己编 Skia**（可选，仅 Windows x64）：构建输入全部入库 ——
`SKIA_REVISION`（Skia commit）、`tools/skia/args.gn.tpl`（平台无关的 GN 参数）、
`tools/skia/headers.txt`（包内该带哪些头）、`tools/skia/build-skia.sh`（构建脚本）。
机器相关的 VS / Win SDK / LLVM 路径不写进仓库，用 `SKIA_WIN_VC` 等环境变量覆盖。

```bash
# 需要 git / python3 / depot_tools（gn + ninja）/ LLVM，且都在 PATH
printf '<40 位 Skia commit>' > SKIA_REVISION
bash tools/skia/build-skia.sh --replace    # 产出 cpp/skia；cpp/fonts 需另行备齐
php tools/pack-deps.php                    # 组包 + 写 deps.lock.json
```

★ 现有 `deps-v1` 的包是从别的机器拷入的预编译包，**没有可追溯的源码 commit** ⇒
`SKIA_REVISION` 目前是 `unpinned`、`deps.lock.json` 里 `provenance: legacy`。这是如实标注，
不是漏填：脚本与 CI 重建都拒绝 `unpinned`。自动构建、发布流程见
[`依赖与发布`](docs/guides/dependencies-and-release.md) 的「CI 自动构建依赖包」一节。

#### ③ 路径不对就设环境变量

MSVC / SDK / PHPX / tpc 的位置与默认不同就设上表的环境变量覆盖，不要改构建脚本；
`INCLUDE` / `LIB` / `PATH`（`cl.exe` + `rc.exe`）由 `drift-build` 按这些目录自动拼好，
不必手动开 Developer Command Prompt 或 `call vcvars64.bat`。

### 装完怎么确认

```bash
php -v
php tools/doctor.php          # 逐项报告；有 FAIL 会打印「修法」并退出 1
php tools/doctor.php --strict # 发布前自查：WARN 也算失败
```

`doctor` 用 `affects` 分级：缺 MSVC 不拦 `verify`，但出 exe 时
`phprc` / `phpx` / `tpc` / `msvc` / `sdk` 必须全绿。体检过后再走下一节。

## 五分钟跑起来（Windows 10/11 x64）

> 两条路，**其一**即可看到窗口。

### 路 A：改 PHP 不重编译（开发态，最快）

```bash
php tools/drift-dev.php hello          # 壳只编一次；改 PHP 保存后自动重启窗口
```

> 首次会自动编一次 dev 壳，同样需要上一节档 B 的完整工具链；之后改业务 PHP 不再编译。

### 路 B：构建独立 exe（发布态）

```bash
# 1. PHP >= 8.4（含 php.ini）在 PATH
php -v

# 2. 体检构建环境（MSVC / Win SDK / PHPX / tpc / php.ini；装法见上一节）
php tools/doctor.php

# 3. 构建 hello（首次或输入变化时自动全量）
php tools/drift-build.php hello

# 4. 起窗验证（窗口 / 尺寸 / 像素 / DPI / 退出码；脚本自动关窗）
python tools/probe-window.py hello
```

> 第三步的重型工具链是**唯一门槛**：组件、默认位置与覆盖变量见上一节「构建环境」，
> `doctor` 会逐项报告缺什么。精确版本与「改什么要重编译」见
> [`协作流程`](docs/contributing/workflow.md)。

## 从零写一个应用

看 [`应用解剖`](docs/start/app-anatomy.md) + [`apps/tutorial`](apps/tutorial)。

## 文档

- 文档导航：[`docs/README.md`](docs/README.md)（开始 / 指南 / 概念 / 参考 / 裁决 / 贡献）
- 框架概览与边界：[`docs/start/overview.md`](docs/start/overview.md)
- 裁决索引：[`docs/decisions/README.md`](docs/decisions/README.md)
- 贡献 / CI / 工具链：[`docs/contributing/setup.md`](docs/contributing/setup.md)、[`AGENTS.md`](AGENTS.md)
- 变更日志：[`CHANGELOG.md`](CHANGELOG.md)

## 当前边界

技术预览、Windows-only、API 可能变；能力面够写真实业务应用（见 `apps/watermark`）。
缺失项与「有意不为」以 [`框架概览`](docs/start/overview.md) 与 [`裁决索引`](docs/decisions/README.md) 为准。

## 许可证

MIT，全文见 [`LICENSE`](LICENSE)；机器可读声明在 [`composer.json`](composer.json)。

随包分发的第三方组件另按其自身许可：Skia 为 BSD-3-Clause，Noto Sans SC 为
SIL OFL 1.1，另有九个静态库（Expat / FreeType / HarfBuzz / ICU / libjpeg-turbo /
libpng / libwebp / Wuffs / zlib）。11 份全文与来源记录见
[`licenses/`](licenses/THIRD-PARTY.md)，打包时整目录随附。
