# 依赖包的分发与托管（Skia + 字体）

`cpp/skia`（约 141 MB）与 `cpp/fonts`（约 21 MB）**不入库**，`/cpp/` 与 `/dist/` 都在
`.gitignore` 里。所以「新机器 clone 完怎么拿到包」这件事，只能由 GitHub Releases 承担。

**当前状态**：仓库已托管到 GitHub（**私有**），但还没有任何 release 与 `deps-vN` tag ——
首个包尚未发出。在那之前 README 里那句「从已有 `cpp/` 的机器拷入」仍然有效。

私有不影响流程，只影响取用方：私有资产的 `releases/download/...` 没有可匿名访问的地址，
`fetch-deps.php` 必须拿到 token 才能下载（详见第五节）。

---

## 一、发布首个包（只做一次）

不需要 `gh`，也不需要手工 `git tag` —— 三步都由脚本包了：

```bash
export GITHUB_TOKEN=ghp_xxx        # PowerShell: $env:GITHUB_TOKEN = "ghp_xxx"

git push                           # 先把 deps.lock.json 推上去：它是使用方的校验依据
php tools/pack-deps.php            # 打包 + 写 .sha256 + 重写 deps.lock.json
php tools/release-deps.php         # tag 取 DEPS_VERSION（当前 deps-v1）
```

`release-deps.php` 依次做四件事：

1. **前置校验**：包 / `.sha256` / `deps.lock.json` 三者一致，且 lock 里的
   `driftVersion` / `depsTag` 与根目录 `VERSION` / `DEPS_VERSION` 相同，否则直接退出。
   使用方是拿 lock 校验的，发了不一致的资产只会让所有人 fetch 失败，而发布侧毫无察觉。
2. 查 `deps-v1` 是否已有 release。
3. 没有就建，tag 落在**当前 HEAD 的 commit** 上 —— 不指定 `target_commitish` 时 GitHub
   会落到默认分支的最新提交，那可能是别人刚推的。
4. 先传 zip 再传 `.sha256`：顺序如此，中途失败不会留下「校验值在、包不在」的资产。

先用 `--dry-run` 看一眼要做什么，不产生任何写操作。

**token 要什么权限**：Settings → Developer settings → Personal access tokens。
fine-grained token 选本仓库 + `Contents: Read and write`；classic token 勾 `repo`。

**资产名一个字都不能改**：`fetch-deps.php` 按 `drift-deps-win-x64.zip` 与
`drift-deps-win-x64.zip.sha256` 两个名字找资产，改名等于找不到。

## 二、为什么依赖包用独立 tag，不跟代码版本

`latest` 指向**仓库最新**的 release。代码发版通常比依赖包勤，一旦 latest 指向一个
没传 zip 的代码 release，取包就 404。所以依赖包单独发 `deps-v1`、`deps-v2`…，
与代码版本解耦，且**默认不取 latest**：

- tag 由根目录 `DEPS_VERSION` 唯一决定。`fetch-deps.php` 与 `release-deps.php` 都从它读，
  两边天然对齐；`release-deps.php` 甚至拒绝 `--tag` 与它不一致，防止「发到 A tag、
  大家却去 B tag 取」这种没人会察觉的错配。
- `fetch-deps.php --latest` 才改走仓库最新 release，`--tag=<tag>` 可临时钉别的版本
  （取旧包做对比时用）。
- 要升级依赖包，先改 `DEPS_VERSION`，再打包、再发版 —— 见第三节与末节。

## 三、更新包

内容变了（Skia 升级、字体换版、补齐许可）就要重打包，**SHA256 必变**。两种做法：

| 做法 | 好处 | 代价 |
|---|---|---|
| 新 tag `deps-v2`（推荐） | 旧包仍可复现，谁在用哪版说得清 | tag 会变多 |
| 同 tag 覆盖资产 | tag 少 | 外部手里的 SHA 会短暂对不上，且必须 `--force` |

```bash
# 推荐：改 DEPS_VERSION 为 deps-v2，提交，然后
php tools/pack-deps.php                # 新包 + 新 lock（版本锚点一并重写）
php tools/release-deps.php             # 自动取 deps-v2

# 覆盖：DEPS_VERSION 不变，只重打包
php tools/pack-deps.php
php tools/release-deps.php --force     # 删旧资产后重传
```

★ **不能只靠 `--tag` 换 tag**：`release-deps.php` 会拿它和 `DEPS_VERSION` 比对，不一致直接退出。
这不是刁难 —— 绕过它，发出去的包和 `fetch-deps.php` 默认取的 tag 就不是一个东西。

GitHub 不支持修改资产内容，`--force` 的实际动作是「DELETE 同名资产 → 重新上传」。
没有 `--force` 时遇到同名资产会以退出码 2 停下，不会静默替换。

无论哪种，**`deps.lock.json` 必须一起提交**。它由 `pack-deps.php` 自动重写，忘了提交的表现是
别人 fetch 时报「SHA256 不符」或「lock 与 release 的 .sha256 不一致」—— 而这正是它该做的事：
把「悄悄换了包」变成一次停在解压之前的失败。README 里的 hash 段只供肉眼比对，不同步不影响 fetch。

## 四、每次发布后的验收

在干净目录里 clone 一次，按新使用者的路径走一遍：

```bash
git clone <repo> ../drift-check && cd ../drift-check
GITHUB_TOKEN=ghp_xxx php tools/fetch-deps.php    # 默认取 DEPS_VERSION 的 tag
php tools/version.php --check
php tools/doctor.php
```

三条都要过：fetch 退出 0 且打印 `24 个 .lib、2 个字体、11 份许可`；`version --check`
确认版本锚点对齐（CHANGELOG / 代码常量 / lock / HEAD tag）；doctor 全绿（`Skia 依赖包` 那项会显示
`24 个 .lib · 2 个字体`）。只在开发机上测等于没测 —— 开发机的 `cpp/` 本来就存在，
`--force` 覆盖一遍看不出「从零」。

**首个 release 之前，或目标机器在内网**：走离线包，判据与在线那条完全一样。

```bash
# 有 cpp/ 的机器
php tools/pack-deps.php                     # → dist/drift-deps-win-x64.zip + .sha256

# 新机器（clone 后，把 zip 拷过去即可）
php tools/fetch-deps.php --url=D:/transfer/drift-deps-win-x64.zip
DRIFT_DEPS_URL=D:/transfer php tools/fetch-deps.php   # 给目录也行，脚本补包名
```

`--url` 还认反斜杠路径、`file://`、百分号编码（空格 / 中文）。2026-11-15 在
`_build/deps-probe/` 造了一棵「只有 `tools/` 与三个版本文件」的假新机器跑通：
`拷贝 → SHA256 一致 → 解压 610 entry → 24 lib / 2 字体 / 11 许可 → 对账一致`，退出 0。
★ 它不走网络，**不替代在线那条的验收**，只证明离线这条路是活的 —— 上线前的验收仍要
按上面三步在干净 clone 里跑。

## 五、边界

- **私有仓库必须带 token**。`releases/download/...` 对私有资产没有可匿名访问的地址，
  匿名请求拿到的是 404（不是 403）—— 看起来像"还没发布"。`fetch-deps.php` 在有 token 时
  改走 API 解析 asset id 再按 `application/octet-stream` 取，token 只需 `repo` 读权限。
- **首个 release 发布前**，GitHub 那条路必然拿不到资产：离线包（`--url=<本机 zip>`）是
  唯一可行路径，它与在线那条共用鉴权之外的**全部**校验（lock → SHA → 解压后数一遍）。
- 单资产上限 2 GB（GitHub 限制），当前 49.6 MiB，够用很久。
- **包不进 git 历史**：50 MB × 每次发版会让仓库永久膨胀，且 clone 变慢；
  release 资产可删可换，这才是它该待的地方。
- 包内已随附 11 份许可全文（`cpp/licenses/`）与索引，发版时**不要**只传 zip 不传 `.sha256`。
- **不会自动下载**：`drift-build` 发现缺包时直接给出 `php tools/fetch-deps.php` 并退出，
  不在构建过程中偷偷联网取 50 MB。缺包要能一眼看见来源，而不是让构建时长莫名多出几分钟。

---

## 六、别的项目怎么做（以及我们只学一半的理由）

| 项目 | 机制 | 值得学的那一点 |
|---|---|---|
| Chromium | `DEPS`（Python 文件，精确 commit id）+ `gclient sync`；二进制走 CIPD | 版本集中在一个入库文件里；大二进制永不进 git |
| rust-skia | 预编译包放**独立仓库**的 Releases，按 repo hash + platform + features 匹配；`SKIA_BINARIES_URL` 可指 `file://` | 离线/内网必须留一个环境变量口子 |
| Electron | `@electron/get` 从 Release 下；优先用**内嵌 checksums**，远程 `SHASUMS256.txt` 次之；`ELECTRON_MIRROR` + `ELECTRON_CUSTOM_DIR` 拼 URL；缓存按 `[checksum]/[filename]` 存 | **校验值入库**才叫校验；镜像用 env 覆盖；缓存以哈希为键 |
| Meson | `subprojects/*.wrap`：`source_url` + `source_filename` + `source_hash`（入库）+ `source_fallback_url`；`packagecache` 可离线覆盖 | 清单入库、备用源、本地缓存优先 |
| Godot | thirdparty 源码入库，`export_templates.tpz`（约 1 GB）走 Release | 大资产独立分发；社区真实痛点：**下载中断要能续** |
| Qt | supermodule + `init-repository` 拉 submodule，二进制走官方 installer / aqtinstall | 引导入口只给一个名字，别让人读文档才知道下一步 |
| SkiaSharp（.NET） | 原生库直接打进 NuGet：`SkiaSharp.NativeAssets.Win32`（3.119 起约 87 MB，大头是 PDB），Avalonia / Uno 一行 `PackageReference` 就带走 | 生态里有包管理器就别自建分发 —— 代价是所有人都得下全平台或至少整包 |

**我们吸收的五条**

1. `deps.lock.json` 入库（Electron 内嵌 checksum / Meson `source_hash` 的做法）：
   校验值不再和被校验物同源上传，换包必须同时改仓库文件。
2. 多源回退（Meson `source_fallback_url` / Electron `ELECTRON_MIRROR`）：`DRIFT_DEPS_URL`，
   逗号分隔，最后一个候选永远是 GitHub。
3. 断点续传（Godot 的教训）：GitHub 大资产走签名 URL，网络差会中途断；已支持 `.part` 续传，
   服务器不认 `Range` 时自动丢档重下。
4. 包内一切路径落 `cpp/`（Chromium 的 `third_party/` 隔离思路）：解压到仓库根不污染工作区。
5. **触发点放进构建入口**（skia-safe 的 `build.rs` / Qt 的 `init-repository`）：别人不用记
   「先跑哪条命令」。这里不做自动下载（构建里偷偷联网取 50 MB，失败了只有一句看不懂的错），
   而是让 `doctor` 把它列为构建必需项、`drift-build` 缺包时直接打印 `php tools/fetch-deps.php`。

**明确不学的**

| 方案 | 为什么不用 |
|---|---|
| git submodule | 158 MB 会让 `git clone` 变成分钟级，Windows 下还有换行/权限的老问题。Chromium 自己也不用 submodule 存二进制（用 CIPD） |
| Git LFS | 免费额度 1 GB 存储 / 1 GB 月流量，50 MB × 每次发版很快打满；且使用者得先装 LFS 客户端 |
| CIPD / vcpkg / conan | 要么自建包服务器，要么把 PHP + 自研构建拖进 CMake 生态，接入成本远大于收益 |
| 独立仓库放包 | 只有一种包一个平台，独立仓库只是多一处要同步的地方 |

**诚实边界**：我们没有签名（GPG/minisign）。`deps.lock.json` 只能证明「包与仓库记录一致」，
不能证明「发布者身份」—— 仓库账号被攻破时，攻击者可以同时改包和 lock。补签名要先解决密钥
管理与轮换，现阶段不做，也不该把 sha256 说成防篡改。

---

## 七、为什么不用 Composer 分发（实测而非推测）

PHP 有 Composer，自然的问题是「直接 `composer require` 不就行了」。**技术上可以**：Composer
的 `type: package` 内联仓库能描述任意 zip，`dist.url` 发请求、`shasum` 做校验，一条
`composer install` 就到手。本机 2.10.2 实测通过：

```json
"repositories": [{
  "type": "package",
  "package": {
    "name": "drift/deps-win-x64",
    "version": "1.0.0",
    "dist": { "type": "zip", "url": "…/drift-deps-win-x64.zip", "shasum": "<40 位>" }
  }
}]
```

改错 `shasum` 会报 `The checksum verification of the file failed` 并退出 1，确实在做事。
但有四条实测出来的约束，合起来使它不划算：

| 约束 | 实测表现 | 后果 |
|---|---|---|
| **只认 SHA-1（40 位）** | 填 64 位 SHA-256 直接校验失败、退出 1 | 全仓是 sha256 体系（`deps.lock.json`、`.sha256` 资产、`pack-deps`），改用 Composer 要么降级到 SHA-1，要么维护两套哈希 |
| **解压剥掉单一顶层目录** | 包内 `cpp/skia/a.lib` → `vendor/drift/deps-test/skia/a.lib`，`cpp/` 那层丢了 | 落点是 `vendor/` 而不是 `cpp/`，还得用 `post-install-cmd` 搬；Windows 上 symlink 要开发者模式或管理员，只能拷贝，50 MB 变 100 MB |
| **没有可选依赖** | 只有 `require` / `require-dev` / `suggest`，`suggest` 不安装 | 包进 `require` ⇒ **所有 clone 的人都被拖 50 MB**，包括只跑 `bin/drift verify --json`、根本不需要 MSVC 和 Skia 的贡献者。这与 README「档 A：只跑闸门不需要构建链」直接冲突 |
| **认证另成一套** | 私有仓库走 `composer config --global github-oauth.github.com`；release 资产 302 到 `objects.githubusercontent.com`，2.8 之前会把认证头带过去吃 404 | 使用者要同时维护 `GITHUB_TOKEN`（fetch-deps）和 composer 的 auth，且 `--no-scripts` 会静默跳过搬移步骤 |

根子上是错配：**Composer 管的是 PHP 包**，产物要落进 `vendor/` 供 autoload 消费；而 Skia 是
C++ 链接输入，要么进 `cpp/` 被 `base.yml` 引用，要么根本不该出现在 PHP 的依赖图里。用
Composer 等于借它的下载器，再把它装错位置的东西搬走 —— 现有 `fetch-deps.php` 做的就是同一件事，
少两层。

**什么情况下该改用 Composer**：如果 Drift 将来要发布成可被第三方
`composer require drift/drift` 引入的库，对方仓库里没有 `cpp/`，那时必须有一个 Composer 能解析
的入口 —— 加一个 `type: package` 声明（并接受 SHA-1 与强制下载），是那个场景的正确做法。
现在还不是。

顺带一提，Composer 这套「哈希写在入库的清单文件里」正是 `deps.lock.json` 在学的东西，
两者在这点上是同一个答案。

---

## 八、版本号与升级锚点

之前整套链路只有一堆散落的 `0.1.0`、`deps-v1` 字面量：tag 靠手写、CHANGELOG 靠人记、
`project.yml` 里三个版本字段各写各的，升级时没有锚点，也没有任何判据能发现漏改。

**借鉴的三条**

| 项目 | 做法 | 我们借的部分 |
|---|---|---|
| Chromium | `chrome/VERSION` 是一个纯文本单行文件，构建期派生出各处版本 | 根目录 `VERSION` / `DEPS_VERSION` 作唯一真相源，shell 与 CI 能直接 `cat` |
| Flutter | `bin/internal/engine.version` 把二进制依赖 pin 成一行；打包流程会**拒绝发布过时的 engine.version** | `DEPS_VERSION` 与代码版本解耦；`release-deps.php` 发版前对账 lock 的版本锚点，不一致就停 |
| Godot | `version.py` 是真相，构建期生成 `version.h` 供 C++ 消费，不手抄 | `gen-version.php` 生成 `src/Core/Version.php`，代码用编译期常量而不是运行时读文件 |

**一个反面教材**：上游 TypePHP 的 `7136faf`（`chore: bump version to 0.10.0`）一次手改
4 行 —— `project.yml` 的 `version` / `file-version` / `product-version`，加
`Translator.php` 的 `VERSION` 常量，全靠人记得同步。本仓 8 个 `apps/*/project.yml`
里 7 个是同款（`bench` 是量具，不进产品 PE 资源，只有 `version:`），注释里甚至
明写着「两处真相，改版本时一起改」。**值得学的是它暴露的问题，不是它的做法**：
只要有第二份手抄副本，就一定会漂。

**所以结构是这样**（真相源只有两行，其余全是派生产物）：

| 派生副本 | 生成者 | 谁负责判据 |
|---|---|---|
| `src/Core/Version.php`（`VERSION` / `BASE` / `PREVIEW` / `TAG` / `DEPS_TAG`） | `gen-version.php` | `version --check` + `gen-version --check` |
| 7 份 `apps/*/project.yml` 的三处版本（+ `bench` 的 `version`） | `gen-version.php` | `gen-version --check`（含字段齐备断言） |
| `deps.lock.json` 的 `driftVersion` / `depsTag`，包内 `DEPS-MANIFEST.txt` 与 zip 注释 | `pack-deps.php` | `version --check` + `release-deps.php` 前置 |
| `fetch-deps.php` / `release-deps.php` 默认取的 tag | 运行时读 `DEPS_VERSION` | `release-deps.php` 拒绝 `--tag` 覆盖真相源 |
| `CHANGELOG.md` 的 `## v<版本>` 小节、git tag `v<版本>` | 人工写 / 人工打 | `version --check`（含 HEAD tag 比对） |

两条命令都挂在 V2 `verify` 的 static 层（`version` / `genversion` step），漏同步会在闸门红，而不是发版当天发现 tag 和 CHANGELOG
对不上。运行时代码**不能**改成 `file_get_contents('../../VERSION')`：AOT 产物是一个
独立 `.exe`，目标机上根本没有仓库根目录，版本必须在编译期就确定下来。

**升级流程**

```bash
# 改框架版本
#   1. 编辑 VERSION，CHANGELOG.md 开一节 `## v<新版本>`
#   2. php tools/gen-version.php      # 代码常量 + 8 份 project.yml
#   3. php tools/pack-deps.php        # lock 与包内锚点（不改 Skia 也要，否则 lock 会漂）
#   4. php bin/drift verify --layer=static   # 含 version / genversion --check
#   5. 提交以上全部派生产物，再 git tag v<新版本>

# 改依赖包版本
#   1. 编辑 DEPS_VERSION 为 deps-v2
#   2. php tools/pack-deps.php && php tools/version.php --check
#   3. 提交 VERSION/deps.lock.json 相关文件 → php tools/release-deps.php
```

**诚实边界**

- PE 资源版本仍是三段数字（`0.1.0`），不带 `-preview.N`：tpc 现在能构出这个值，
  换成带文字的四段需要重跑真机验证，而收益只是属性页多显示几个字符。完整版本由
  `Version::VERSION` 承载。
- `gen-version.php` 只管 `apps/*/project.yml`；`probes/` 与 `_codegen/` 里的实验工程
  不随产品发版，故意不同步。
- 版本号不是签名。`deps.lock.json` 证明的是「包与仓库记录一致」，不是「发布者身份」；
  这一条已写在第六节。

---

## 九、CI 自动构建依赖包

**当前状态**：仓库私有，首个 `deps-v1` release 尚未发出，自托管 runner 未注册 ⇒ 两条流水线
**已入库但未跑过**。下面写的是启用后的流程，不是已验证的事实。

### 两条流水线，触发点分开

| workflow | 触发 | 做什么 | 写什么 |
|---|---|---|---|
| `deps-build.yml` | `workflow_dispatch`（pin 变更时可加 push 路径过滤） | 从 pinned Skia commit 重建 `cpp/skia` → 组包 → 校验 | 默认只传 artifact；`publish=true` 时 commit lock → `deps-vN` tag → release 两个资产 |
| `release.yml` | `push` `v*` tag | `verify --full --json` + 从 `deps-vN` 取包 | **draft** release，人工确认后才 publish |
| `ci.yml` | push / PR（主闸门）+ 手动全量 | 只跑判据 | 不写 Release |

★ **依赖包不跟着产品 release 构建**，三条理由：

1. `fetch-deps.php` 按固定资产名去 `DEPS_VERSION` 那个 tag 找，挂到 `v*` 上所有人 404。
2. `DEPS_VERSION` 与代码版本解耦就是为了避免「发一次代码重编一次 Skia」，全量构建是数小时级。
3. 产品 tag 指向的 commit 里 lock 是构建**之前**写的，与 CI 新产的包对不上（见下）。

这与 rust-skia 同构：release 分支每 commit 触发 CI，产物发到独立 `skia-binaries` 仓库的
Releases，使用方按 hash + 平台 + features 匹配；Chromium 用 `DEPS` pin commit、二进制走
CIPD，也是同一个分工 —— 都不在产品打 tag 时编依赖。

### 顺序锁死：pack → commit → tag → release

`deps.lock.json` 里的 SHA256 要等包打完才知道，而它是入库的校验依据。先建 release 再回写
lock，会留下「新包已发、旧 lock 还在」的窗口：`fetch-deps.php` 拿旧 lock 校验新包直接停，
它还会把 lock 与 release 的 `.sha256` 对账，不一致同样停。

`deps-build.yml` 的 publish 分支严格四步：`pack-deps.php` 重写 lock → `git commit` + push →
`git tag $DEPS_VERSION` + push → `release-deps.php` 上传。tag 落在含新 lock 的 commit 上，
干净 clone 时两者天然一致；最后一步再用 `fetch-deps.php --tag` 从已发布资产回取一遍自验。

`GITHUB_TOKEN` 需 `contents: write`（建 release / 传资产 / 推 commit+tag）。GitHub 对
GITHUB_TOKEN 产生的 push 不再触发新 workflow，所以不会自激。**分支保护若禁止 Actions 直接
push main，这一步会红** —— 给机器人开例外或改用有写权限的 PAT，不要为此把 lock 改成运行时回写。

`pack-deps.php` 把条目按路径排序，并写盘后把所有 ZIP DOS 时间统一覆盖成 1980-01-01
00:00（包内不写扩展时间戳，跨时区重建也一致），同一份 `cpp/` 重打包 SHA 稳定。否则
每次打包都换一个哈希，「lock 没变就不发版」的判断永远失效，README 里的校验值也只能
每次跟着改。

### 构建输入（供下载源码自行构建的使用者）

| 文件 | 作用 |
|---|---|
| `SKIA_REVISION` | Skia 源码 commit（40 位 hex），二进制包必须能追到源码 |
| `tools/skia/args.gn.tpl` | 平台无关的 GN 策略：`is_official_build`、`skia_use_system_*` 全 false、`-MT`、x64 |
| `tools/skia/headers.txt` | 包内 567 个头/元数据文件的相对路径清单，保证 CI 产物与现有布局一致 |
| `tools/skia/build-skia.sh` | clone → checkout rev → `git-sync-deps` → `gn gen` → `ninja skia modules` → 组装 `cpp/skia` |

机器相关路径（`win_vc` / `win_sdk` / `win_sdk_version` / `clang_win` / 工具链版本）**不入库**，
走 `SKIA_WIN_VC` 等环境变量；CI 里对应 repository variables。配方与 Aseprite 的 Skia fork
的 `build.yml` 同源（depot_tools → git-sync-deps → LLVM → `-MT` → `ninja skia modules`），
差别只在工具链版本与 milestone。

```bash
# 本机；需要 git / python3 / depot_tools（gn + ninja）/ LLVM，且都在 PATH
printf '<40 位 commit>' > SKIA_REVISION
bash tools/skia/build-skia.sh --replace
php tools/pack-deps.php && php tools/version.php --check
```

`--replace` 是显式确认：默认不覆盖非空 `cpp/skia`，避免删掉本机现成的 141 MB 包。

**关于 `unpinned`**：现有 `deps-v1` 是从别的机器拷入的预编译包，只有 `SK_MILESTONE 148`
和 `args.gn`，**没有 commit 可追**。所以 `SKIA_REVISION` 写 `unpinned`、`deps.lock.json` 记
`provenance: legacy`，包内清单也如实写明；`build-skia.sh` 与 CI 都拒绝 `unpinned`。这是不伪造
来源，不是漏填 —— 第一个由 CI 重建的包（建议 `deps-v2`）起才有完整 provenance。

### 启用前置

| 项 | 要求 |
|---|---|
| runner | 自托管 Windows（`[self-hosted, windows]`），预装 VS 2022 / Win SDK / LLVM / depot_tools / PHP 8.4（zip + curl）。私有仓 hosted Windows runner 只有 2 vCPU / 8 GB / **14 GB SSD**，全量 Skia 有爆盘风险；job 上限 6h、Windows 分钟按 2× 扣额度 |
| 字体 | `cpp/fonts/*.ttf` 不入库也不随 Skia 变；自托管 runner 一次性预置（`clean: false` 保留），或用 dispatch 输入 `fonts_dir` 拷入，缺失时流水线直接停 |
| 已发布的包 | `release.yml` 会真的跑一次 `fetch-deps.php`；首个 `deps-vN` 发布前它必然红，这是它在做该做的事 |
| 权限 | `contents: write`；分支保护需允许机器人 push |

先跑一次 `publish=false`（默认）：构建 + 组包 + `bin/drift verify --layer=static --json`，产物挂 artifact 人工看过，
再跑 `publish=true`。升级依赖要先把 `DEPS_VERSION` 改成 `deps-v2` 并提交 —— 同 tag 覆盖资产
需要 `--force`，而 CI 不发 `--force`。

### 诚实边界

- **字体来源未 pin**：目前靠 runner 预置，没有 URL + SHA256 的可复现来源；公开分发前应补。
- **没有签名**：`deps.lock.json` 只证明「包与仓库记录一致」，不证明发布者身份。公开后建议用
  GitHub OIDC 的 build provenance attestation（零密钥）或 minisign，别把 sha256 说成防篡改。
- **只有 Windows x64 一种产物**：`headers.txt` 与 `args.gn.tpl` 都是单平台口径。
- CI 里 `bin/drift verify --json` 是纯 PHP 判据，**不等于** AOT 真机构建通过；后者仍走 `ci.yml` 里关闭的
  `aot` job 做人工验证。

---

## 十、私有仓库 → 公开仓库

当前仓库私有，也还没达到对外公布的条件。下面是不需要重写分发链就能切换的部分，以及公开前
**必须**补的部分。设计原则：脚本不写死「私有」，只写「可能拿不到匿名地址」。

| 项 | 私有阶段 | 公开后 | 要改代码吗 |
|---|---|---|---|
| 取包 | 必须带 token；匿名 `releases/download` 回 404 | 匿名直链可用，带 token 仍走 API | 否，`githubSource()` 两条路都在 |
| CI token | `GITHUB_TOKEN` 自动注入，读私有资产 | 同 | 否 |
| Release 可见性 | 随仓库私有 | 随仓库公开 | 否 |
| 产品发版 | `release.yml` 只建 **draft** | 仍建 draft，人工 publish | 否 |
| 镜像 / 内网 | `DRIFT_DEPS_URL` 多源回退 | 可挂 CDN 或 `file://` 离线源 | 否 |
| 许可 | 包内 11 份全文 + `THIRD-PARTY.md`，仓库 `licenses/` | 同（BSD-3 / OFL 等重分发义务已满足） | 否 |
| 自托管 runner | 只有协作者能触发 | ★ fork PR 能提交恶意代码，**不得让 PR 用自托管 runner** | 维持：两条 workflow 只监听 `push` tag / `workflow_dispatch`，不监听 `pull_request`；runner 放进受限 runner group |
| 发布者身份 | 无签名 | 建议加 attestation / 签名 | 是 |
| 字体来源 | runner 预置 | 应 pin URL + SHA256 | 是 |
| 首个可追溯包 | `unpinned` / legacy | 应至少发一个 `provenance: ci` 的包 | 是（跑一次 CI） |

**公开切换清单**

1. 注册自托管 runner 并放进受限 runner group；确认两条 workflow 没有 `pull_request` 触发。
2. 跑一次 `deps-build.yml`（`publish=false`）拿 artifact，核对 `24 个 .lib / 2 个字体 / 11 份许可`。
3. 改 `DEPS_VERSION` 为 `deps-v2` 并提交，再跑 `publish=true`；确认 tag 指向含新 lock 的
   commit、`SKIA_REVISION` 是 40 位 hash。
4. 补字体来源 pin 与产物签名 / attestation。
5. 在干净目录匿名 clone 一次，按 README 走 `fetch-deps.php`（**不带 token**）→
   `version --check` → `doctor`。
6. 把 README / 本文里「私有仓库必须带 token」的措辞改成「私有仓库才需要」。
7. 仓库转公开；`release.yml` 仍只建 draft，由人决定何时 publish。

**不要做的事**：不要为公开把 `deps.lock.json` 改成从 release 拉取（校验值必须与包不同源）；
不要把依赖包并进 `v*` release；不要让 CI 在 `pull_request` 上跑自托管构建。
