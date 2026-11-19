# 二进制依赖与发布

> 本篇解决：Skia 与字体这类不入库的二进制依赖怎么获取、校验、打包、发版，以及版本号锚点、从源码构建和 CI 自动构建各走哪条路。

## 依赖包从哪来

`cpp/skia*` 与 `cpp/fonts` 不进 git，`/cpp/` 与 `/dist/` 都在 `.gitignore` 里。新机器 clone 完之后，从 GitHub Releases 的独立 `deps-vN` tag 取预编译包；仓库公开，资产匿名可下载。fork 私有镜像或匿名直链被限流时可给 token。

- **deps-v1（当前发布）**：只有 `drift-deps-win-x64.zip` 一个 Windows 资产，解压得到 `cpp/skia/`。
- **deps-v2（代码已就绪，尚待发布）**：每个平台一个资产 —— `drift-deps-win-x64.zip` 解压得到 `cpp/skia/`，`drift-deps-linux-x64.zip` 解压得到 `cpp/skia-linux/`；两者共享一份带 `platforms` 表的 `deps.lock.json`。macOS 只在平台表里预留 `macos-arm64`，未接构建通道前不发布。

资产名是契约，一个字都不能改。包内所有路径落在 `cpp/` 下，解压到仓库根不会污染工作区；许可全文随包附带。

构建过程**不会自动下载**依赖：缺包时 `drift-build` 直接打印 `php tools/fetch-deps.php` 并退出。缺包要一眼看见来源，而不是让构建时长莫名多出几分钟。
## 获取与校验

`fetch-deps.php` 的源优先级是 `--url` > `DRIFT_DEPS_URL`（逗号分隔，可指镜像或内网目录，最后一个候选永远是 GitHub）> GitHub release。默认取 `DEPS_VERSION` 指定的 tag，不跟随 `latest`——代码发版比依赖包勤，`latest` 一旦指向没传 zip 的 release，取包就失败。

```bash
php tools/fetch-deps.php                          # 按宿主 OS 取当前平台包
php tools/fetch-deps.php --platform=linux-x64     # 显式指定平台（cross 场景/回验）
php tools/fetch-deps.php --latest                 # 改用仓库最新 release
php tools/fetch-deps.php --tag=deps-v2            # 临时钉别的版本
php tools/fetch-deps.php --url=./drift-deps-win-x64.zip
DRIFT_DEPS_URL=https://mirror.example.com/drift php tools/fetch-deps.php
```

校验规则：优先用入库的 `deps.lock.json` 里的 SHA256，远端 `.sha256` 只作交叉验证；两者不一致就停。既没有 lock 也取不到远端校验值时，只打印本地 SHA256 让人比对，不会假装校验通过。大资产支持 `.part` 断点续传，服务器不认 `Range` 时丢档重下。离线包（`--url` 指本机 zip 或目录）与在线那条共用鉴权之外的全部校验：lock → SHA256 → 解压后逐项对账。

匿名可取时不需要 token。需要时只读就够——fine-grained token 选本仓库 + `Contents: Read`；classic token 用 `public_repo`（私有 fork 才需要 `repo`）。发布的包不进 git 历史，避免每次发版都让仓库永久膨胀。

## 版本锚点文件与生成物

真相源只有几行纯文本文件，其余都是派生产物：

| 真相源 | 作用 |
|---|---|
| `VERSION` | 框架版本，构建期派生到各处，shell 与 CI 可直接读 |
| `DEPS_VERSION` | 依赖包 tag，取包与发包都从它读，与代码版本解耦 |
| `SKIA_REVISION` | Skia 源码 commit，二进制包必须能追到源码 |
| `deps.lock.json` | 入库的 SHA256 与版本 / 依赖元数据，是校验依据 |

派生产物与守门判据：

- `gen-version.php` 生成 `src/Core/Version.php`（`VERSION` / `BASE` / `PREVIEW` / `TAG` / `DEPS_TAG` 常量）与 `apps/*/project.yml` 的版本字段；由 `version --check` + `gen-version --check` 守。
- `pack-deps.php --platform=<id>` 重写当前平台条目与包内清单、zip 注释里的锚点；deps-v2 的锁由发布编排按 win-x64 → linux-x64 顺序合成，顶层 win-x64 镜像只兼容旧消费点。
- `release-deps.php` 与 `fetch-deps.php` 默认取的 tag 来自运行时读 `DEPS_VERSION`；前者拒绝 `--tag` 与真相源不一致。
- `CHANGELOG.md` 的版本小节和 git tag 由人工维护，`version --check` 含 HEAD tag 比对。

这两条 `--check` 挂在 verify 的 static 层，漏同步在闸门就红，不会拖到发版当天才发现 tag 和 CHANGELOG 对不上。运行时代码不能去读仓库根目录的 `VERSION`：AOT 产物是独立 exe，目标机上没有仓库，版本必须在编译期定下来。`probes/` 与 `_codegen/` 里的实验工程不随产品发版，故意不参与同步。

## pack / fetch / release 流程

日常只在 CI 走这条链；手动重建时按平台分别组包，最后统一发布：

```bash
php tools/pack-deps.php --platform=win-x64      # 从 cpp/skia 组 Windows 包
php tools/pack-deps.php --platform=linux-x64    # 从 cpp/skia-linux 组 Linux 包
php tools/sync-lock-anchors.php                 # README 按平台顺序写两个哈希
php tools/version.php --check                   # 缺任一平台项就红
php tools/release-deps.php --dry-run            # 先看要上传哪些资产
php tools/release-deps.php                      # tag 取 DEPS_VERSION，上传全部平台资产
```

`pack-deps.php` 把条目按路径排序，并把 ZIP 内所有 DOS 时间覆盖成固定值，因此同一份源目录重打包的 SHA256 是稳定的——否则"lock 没变就不发版"永远失效。`release-deps.php` 先对每个平台校验包 / `.sha256` / lock 三者一致且与根目录版本锚点相同，再查目标 tag 是否已有 release；没有就建，tag 落在**当前 HEAD** 上（不指定 commit 会落到默认分支最新提交，可能是别人刚推的）。先传 zip 再传 `.sha256`，中途失败不会留下"校验值在、包不在"。

内容变化必须重打包，SHA256 必变。推荐新 tag（`deps-vN` 递增）：旧包仍可复现。同 tag 覆盖资产要 `--force`，实际动作是"删同名资产 → 重传"；没有它遇到同名资产以退出码 2 停下，不会静默替换。无论哪种，**`deps.lock.json` 必须一起提交**；v2 起缺平台项会让 `version --check` 直接红，不再靠人眼发现半份锁。
## 从源码构建 Skia

需要 git、python3、LLVM，且都在 PATH 上（gn / ninja 见下，脚本会自己搞定）：

```bash
printf '<40 位 commit>' > SKIA_REVISION
bash tools/skia/build-skia.sh --platform=windows --replace   # Linux 用 --platform=linux --out=<cpp/skia-linux>
php tools/pack-deps.php --platform=win-x64
```

输入文件：`SKIA_REVISION`（pinned commit）、`tools/skia/args.gn.tpl`（平台无关的 GN 策略）、`tools/skia/headers.txt`（包内头 / 元数据路径清单，保证产物与既有布局一致）、`tools/skia/build-skia.sh`（clone → checkout → `git-sync-deps` → `gn gen` → `ninja skia modules` → 组装 `cpp/skia`）。gn / ninja 的调用形态照抄 Skia 官方 CI 的 `infra/bots/recipe_modules/build/default.py`：`gn gen` 必须在 Skia 源码目录内执行（它靠当前目录的 `.gn` 定位 source root），工具用仓库自带的 `bin/fetch-gn` / `bin/fetch-ninja`。机器相关的 VC / SDK / LLVM 路径不入库，走 `SKIA_WIN_VC` 等环境变量。`--replace` 是显式确认：默认不覆盖非空 `cpp/skia`，免得删掉本机现成的包。

`SKIA_REVISION` 当前 pin 到 Skia M148 的真实 commit，lock 的 `skiaRevision` 与 `provenance: ci` 与之同步；该 commit 的 `SkMilestone.h` 为 148，与包内一致。`unpinned` / `provenance: legacy` 只用于来源不可考的历史包，构建脚本与 CI 都拒绝它。`deps-v1` 已由 `windows-deps.yml` 从这个 commit 实际重建并发布（610 条目 / 49.5 MB），来源声明与产物一致：拿到包就能按该 commit 复现。

字体不随 Skia 变，两个 Noto Sans SC 字重（Regular / Bold，OFL 授权）**入库**（`cpp/fonts/`，`.gitignore` 用 `/cpp/*` + 白名单放行）。托管 runner 没有持久盘，字体不入库就得再引一条下载链路加版本锚点，等于给重建多加一个会漂的部件；入库后来源就是 commit 本身。字体许可全文在 `licenses/OFL-NotoSansSC.txt`，打包时随附。字体只参与确定性渲染：AOT 运行时默认查系统字体，随包发布由 `windows-build.yml` 的 `with_fonts` 输入决定。

## 依赖构建只在托管镜像上跑

Skia 构建通道（`windows-deps.yml`）跑在 `windows-2022` 托管镜像上，全仓不允许出现 `self-hosted`——仓库没有注册 runner 时，自托管任务不会失败，只会静默排队到超时取消，那是最贵的一种假绿。`assert-workflows.php` 把这条守死。

镜像上现成的东西直接用，缺的在这里补：

- **VS / Win SDK / LLVM**：镜像自带，目录名随镜像更新漂移，所以用 `vswhere` 探测而不是写死；`vars.SKIA_WIN_VC` 等仍可覆盖。GN 字符串里反斜杠是转义符，路径必须转正斜杠后才喂给 `gn gen`。
- **gn / ninja**：不装 depot_tools。`build-skia.sh` 先看 PATH（本地开发机常装着），找不到就调 Skia 自带的 `bin/fetch-gn` / `bin/fetch-ninja` 拉官方预编译包——版本由 Skia 自己的脚本和 `DEPS` 钉住，产物落在 `third_party/gn`、`third_party/ninja`，正是 depot_tools 包装期望的位置。之前走 depot_tools 那条路连踩三次：`gn.exe` 装在 CIPD 子目录 `bootstrap-<hash>\` 里（不在 depot_tools 根），而 `bootstrap.bat` 只在它自己那个 cmd 进程内改 PATH，父进程拿不到；失败又发生在 `git-sync-deps` 之后，看着像 Skia 编译错。绕开这一层后 CI 少两个步骤。
- **字体**：入库，构建前只做存在性检查；AOT 运行时默认系统字体，可选字体包由 `with_fonts` 决定是否随包发布。
- **磁盘**：托管镜像初始只剩十几 GB，而 `git-sync-deps` 加全量编译要二十多 GB。构建前删掉本次用不到的 Android SDK / dotnet / 预装浏览器 / 多版本 JDK，再断言剩余空间不低于 18 GB。爆盘时报的会是和磁盘无关的编译错误，看起来像代码问题，所以这一步要显式拦。

## CI 自动构建

三条流水线职责分开：

- `windows-deps.yml`：`DEPS_VERSION` 不直接触发它；从固定 commit 编 Windows Skia 并产出 win-x64 包 artifact。被产品发版调用时也只产 artifact。
- `linux-deps.yml`：从同一 commit 编 Linux Skia，产出原始 `.a` 树（供 X11 门）与 linux-x64 包 artifact；同样不写 lock、不打 tag、不发 Release。
- `deps.yml`：**唯一监听 `DEPS_VERSION` 变化的发布编排**。等两个构建作业都成功后下载两份包，重打包合成一份双平台锁，同步 README 锚点，提交 lock → 打 `deps-vN` tag → `release-deps.php` 上传全部资产 → 两个平台各回取一次。任一平台红就不写远端。
- `release.yml`：`push` 产品 `v*` tag 时**只调用 `windows-deps.yml`** 编出 Skia，verify 与 dev 壳消费这份 artifact，最后建 **draft** release；它不覆盖公共 `deps-vN` 资产。
- `windows-build.yml`：真 AOT + 真机门。它对 `DEPS_VERSION` 做 `paths-ignore`，不随版本提升立刻跑；改由 `workflow_run` 订阅 `deps.yml`，只在它 `completed` 且 `conclusion == success` 时补跑。

`assert-workflows.php` 守住“两个构建通道不碰发布链路、只有 `deps.yml` 写 tag/Release”的边界，避免某个平台先把自己那半份锁发布出去。

**红窗隔离**：`TAG` 由 `DEPS_VERSION` 唯一决定，但资产要先真编才存在。版本提升的 push 若直接触发 `windows-build`，它会在双平台包发布前就去 fetch `deps-vN`，红在资产缺失而不是代码问题。所以版本提升只启动 `deps.yml`；发布成功后由 `workflow_run` 补一次真机门。两侧必须成对：只加 `paths-ignore` 不接补跑，等于版本提升后完全没有真机门，是另一种静默缺口。

**前置条件**：`SKIA_REVISION` 必须是 40 位 commit；`deps-v1` 已由该 commit 真编并从已发布资产回验（610 条目 / 49.5 MB）。`deps-v2` 的 Windows 裁剪与 Linux 包代码已就绪，但资产尚未发布；`DEPS_VERSION` 升到 `deps-v2` 会触发 `deps.yml` 的完整双平台构建与发布，不能手动改文件冒充发布完成。

从零重建一次双平台包的顺序：

1. 确认 `SKIA_REVISION` 是 40 位 commit，`DEPS_VERSION` 改成未占用的 `deps-vN`。
2. push 该变更（或手动触发 `deps.yml` 并显式打开 publish）。这次 push 不会触发 AOT，公开 CI 只跑发布编排。
3. `deps.yml` 固定执行：两平台构建 → 下载两份资产 → `pack-deps.php --platform=…` 合成锁 → `sync-lock-anchors.php` → `version --check` → 提交 lock → 打 tag → 发布 → 双平台回验。
4. 发布成功后 `workflow_run` 自动补跑 `windows-build.yml`：从已发布的 `deps-vN` 取包，全量 AOT + 真机五判据。这一步是新裁剪参数的真机门，绿了才算闭环。
5. 结束后在干净 clone 里走一遍 `fetch-deps.php` → `version --check` → `doctor`。

## dev 壳发布包

`drift-dev-shell-win-x64-php8.5.zip` 与依赖包不是一回事：它是预编译的**壳**，用发布流水线同一套工具链编出，装上之后改业务 PHP 不重编。产品 `v*` tag 时由 `windows-build.yml` 产出并上传。

壳是动态链接的，所以包里必须自带运行时——这不是"用户可以自己装"的东西：

| 包内文件 | 为什么要带 |
|---|---|
| `dev.exe` | 壳本体 |
| `phpx.dll` | PHPX 运行时，TypePHP 专有，任何 PHP 发行版都没有 |
| `php8ts.dll` | ZendVM；非 ZTS 的 PHP 只有 `php8.dll` |
| `ext/php_mbstring.dll` `ext/php_openssl.dll` `ext/php_curl.dll` | AOT 与业务代码的必需扩展 |
| `cacert.pem` | TLS 信任根 |
| `vcruntime140.dll` `vcruntime140_1.dll` `msvcp140.dll` | MSVC 运行库（三个二进制都导入，必需） |

清单与校验在 `tools/ci/dev-shell-files.php`：打包后立刻对备好的目录跑一次，缺一件就红。缺 DLL 时的症状是 Windows 弹系统级「找不到 DLL」，没有任何可读诊断，用户只会以为包坏了——所以「包里必须有」写成机检，不写在文档里靠人记。

安装方式是解压到仓库的 `_build/dev-shell/`，之后 `php tools/drift-dev.php <app>`。启动器按包的实际位置生成 `php.ini`（`extension_dir` 指向包内 `ext/`），所以解压到哪都能跑，不绑构建机路径；`--print-runtime` 只打印解析结果不启动窗口，起不来时先看这个。

## 发版前检查与 tag 约定

产品版本 tag 形如 `v<版本>`，tag 即发布入口；依赖包用独立的 `deps-vN`，两者不互相借用。发版前：

1. T2 全绿，`drift-build --full` 用全量而非增量（避免 stale 产物）。
2. 真机起窗探针跑通，关窗后退出码为 0；交互路径按需要另跑输入 / 关闭 / 语义探针。
3. `php tools/version.php --check` 确认 CHANGELOG、代码常量、lock、HEAD tag 对齐。
4. 在**干净 clone** 里按新使用者的路径走一遍：`fetch-deps.php` → `version --check` → `doctor`。开发机上 `cpp/` 本来就存在，只覆盖一遍看不出"从零"的问题。
5. CHANGELOG 写好对应版本小节，构建产物与依赖 lock 一并提交。

校验值只证明"包与仓库记录一致"，不证明发布者身份；不要把 SHA256 说成防篡改。产物签名 / attestation 与字体来源 pin 属于公开分发前要补的项。

## 相关

- [命令速查](../reference/cli.md)
- [测试](./testing.md)
- [协作流程](../contributing/workflow.md)
- [环境搭建](../contributing/setup.md)
