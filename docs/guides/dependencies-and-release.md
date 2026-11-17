# 二进制依赖与发布

> 本篇解决：Skia 与字体这类不入库的二进制依赖怎么获取、校验、打包、发版，以及版本号锚点、从源码构建和 CI 自动构建各走哪条路。

## 依赖包从哪来

`cpp/skia` 与 `cpp/fonts` 不进 git，`/cpp/` 与 `/dist/` 都在 `.gitignore` 里。新机器 clone 完之后，从 GitHub Releases 的独立 `deps-vN` tag 取预编译包；公开资产可匿名下载，资产尚未发布或落在私有镜像后可带 token，也可用 `--url` 指向本机包。

资产名是契约，一个字都不能改：`drift-deps-win-x64.zip` 与 `drift-deps-win-x64.zip.sha256`。包内所有路径落在 `cpp/` 下，解压到仓库根不会污染工作区；许可全文随包附带。

构建过程**不会自动下载**依赖：缺包时 `drift-build` 直接打印 `php tools/fetch-deps.php` 并退出。缺包要一眼看见来源，而不是让构建时长莫名多出几分钟。

## 获取与校验

`fetch-deps.php` 的源优先级是 `--url` > `DRIFT_DEPS_URL`（逗号分隔，可指镜像或内网目录，最后一个候选永远是 GitHub）> GitHub release。默认取 `DEPS_VERSION` 指定的 tag，不跟随 `latest`——代码发版比依赖包勤，`latest` 一旦指向没传 zip 的 release，取包就失败。

```bash
php tools/fetch-deps.php                          # 取 DEPS_VERSION 的 tag
php tools/fetch-deps.php --latest                 # 改用仓库最新 release
php tools/fetch-deps.php --tag=deps-v2            # 临时钉别的版本
php tools/fetch-deps.php --url=./drift-deps-win-x64.zip
DRIFT_DEPS_URL=https://mirror.example.com/drift php tools/fetch-deps.php
```

校验规则：优先用入库的 `deps.lock.json` 里的 SHA256，远端 `.sha256` 只作交叉验证；两者不一致就停。既没有 lock 也取不到远端校验值时，只打印本地 SHA256 让人比对，不会假装校验通过。大资产支持 `.part` 断点续传，服务器不认 `Range` 时丢档重下。离线包（`--url` 指本机 zip 或目录）与在线那条共用鉴权之外的全部校验：lock → SHA256 → 解压后逐项对账。

token 权限：取包只需只读——fine-grained token 选本仓库 + `Contents: Read`；classic token 用 `public_repo`（私有 fork 才需要 `repo`）。发布的包不进 git 历史，避免每次发版都让仓库永久膨胀。

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
- `pack-deps.php` 重写 `deps.lock.json`（`driftVersion` / `depsTag` / `sha256`）以及包内清单、zip 注释里的锚点。
- `release-deps.php` 与 `fetch-deps.php` 默认取的 tag 来自运行时读 `DEPS_VERSION`；前者拒绝 `--tag` 与真相源不一致。
- `CHANGELOG.md` 的版本小节和 git tag 由人工维护，`version --check` 含 HEAD tag 比对。

这两条 `--check` 挂在 verify 的 static 层，漏同步在闸门就红，不会拖到发版当天才发现 tag 和 CHANGELOG 对不上。运行时代码不能去读仓库根目录的 `VERSION`：AOT 产物是独立 exe，目标机上没有仓库，版本必须在编译期定下来。`probes/` 与 `_codegen/` 里的实验工程不随产品发版，故意不参与同步。

## pack / fetch / release 流程

发首个包或升级包时，顺序是固定的：

```bash
git push                           # 先推 lock：它是使用方的校验依据
php tools/pack-deps.php            # 组包 + 写 .sha256 + 重写 deps.lock.json
php tools/release-deps.php         # tag 取 DEPS_VERSION
```

`pack-deps.php` 把条目按路径排序，并把 ZIP 内所有 DOS 时间覆盖成固定值，因此同一份 `cpp/` 重打包的 SHA256 是稳定的——否则"lock 没变就不发版"永远失效。`release-deps.php` 做四件事：前置校验包 / `.sha256` / lock 三者一致且与根目录版本锚点相同；查目标 tag 是否已有 release；没有就建，tag 落在**当前 HEAD** 上（不指定 commit 会落到默认分支最新提交，可能是别人刚推的）；先传 zip 再传 `.sha256`，中途失败不会留下"校验值在、包不在"。

内容变化必须重打包，SHA256 必变。推荐新 tag（`deps-vN` 递增）：旧包仍可复现。同 tag 覆盖资产要 `--force`，实际动作是"删同名资产 → 重传"；没有它遇到同名资产以退出码 2 停下，不会静默替换。无论哪种，**`deps.lock.json` 必须一起提交**，忘了提交的表现就是别人 fetch 时 SHA 不符——这正是它该做的事。

先用 `--dry-run` 看发布脚本要做什么，它不产生写操作。

## 从源码构建 Skia

需要 git、python3、depot_tools（含 gn 与 ninja）、LLVM，且都在 PATH 上：

```bash
printf '<40 位 commit>' > SKIA_REVISION
bash tools/skia/build-skia.sh --replace
php tools/pack-deps.php && php tools/version.php --check
```

输入文件：`SKIA_REVISION`（pinned commit）、`tools/skia/args.gn.tpl`（平台无关的 GN 策略）、`tools/skia/headers.txt`（包内头 / 元数据路径清单，保证产物与既有布局一致）、`tools/skia/build-skia.sh`（clone → checkout → `git-sync-deps` → `gn gen` → `ninja skia modules` → 组装 `cpp/skia`）。机器相关的 VC / SDK / LLVM 路径不入库，走 `SKIA_WIN_VC` 等环境变量。`--replace` 是显式确认：默认不覆盖非空 `cpp/skia`，免得删掉本机现成的包。

`SKIA_REVISION` 当前 pin 到 Skia M148 的真实 commit，lock 的 `skiaRevision` 与 `provenance: ci` 与之同步；该 commit 的 `SkMilestone.h` 为 148，与包内一致。`unpinned` / `provenance: legacy` 只用于来源不可考的历史包，构建脚本与 CI 都拒绝它。注意：现有 `deps-v1` 二进制仍是早期拷入包，尚未从这个 commit 实际重建，声明与产物一致须以 `windows-deps.yml` 重建并替换 Release 资产为准。

字体不随 Skia 变，也没有可复现的 URL + SHA256 来源，目前靠构建机器一次性预置；公开分发前要补来源 pin。

## CI 自动构建

两条流水线触发点分开：

- `windows-deps.yml`：`DEPS_VERSION` 变化时自动重建 → 组包 → 校验 → 提交 lock → 打 `deps-vN` tag → 上传两个资产；也可手动触发。被产品发版调用时只产 artifact，不写远端。
- `release.yml`：`push` 产品 `v*` tag 时**先调用 `windows-deps.yml` 编出 Skia**，verify 与 dev 壳都消费这份 artifact，最后建 **draft** release。
- `verify.yml`：push / PR 的主闸门，只跑判据，不写 Release。
- `windows-build.yml`：push main / PR / 手动触发的真 T2，取依赖包，全量编译 `hello` 并跑真机探针；资产缺失时取包阶段就红，不会用纯 PHP 判据冒充 AOT 通过。

产品发版**先构建 Skia，再编译 AOT / dev 壳**，但不会覆盖公共 `deps-vN` 资产：`fetch-deps.php` 按固定资产名去 `DEPS_VERSION` 那个 tag 找，资产发布仍只在依赖版本变化时发生。这样既保证发版链路的顺序，又不让依赖资产被每次代码 release 重写。

**前置条件**：`SKIA_REVISION` 必须是 40 位 commit，现已 pin 到 M148 分支 tip，流水线不再因 revision 未定而红。但产物侧的红线仍在：`deps-v1` 二进制尚未从该 commit 重建，首个走新链路的发版前必须先用 `windows-deps.yml` 重建并发布依赖包，否则等于用旧二进制冒充「已重建 Skia」。

`windows-deps.yml` 的 publish 分支顺序锁死：`pack-deps.php` 重写 lock → `sync-lock-anchors.php` 同步 README SHA256 → `git commit` + push → `git tag $DEPS_VERSION` + push → `release-deps.php` 上传。tag 落在含新 lock 的 commit 上，干净 clone 时两者天然一致；最后再用 `fetch-deps.php --tag` 从已发布资产回取一遍自验。

自托管 runner 需要预装 VS / Win SDK / LLVM / depot_tools / PHP；hosted Windows runner 只有 14 GB SSD，全量 Skia 有爆盘风险，依赖构建因此留在自托管。`windows-deps.yml` 只监听 `DEPS_VERSION` 变更与手动触发，`release.yml` 只监听产品 tag；都不监听 `pull_request`——fork PR 不能跑自托管构建。

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
