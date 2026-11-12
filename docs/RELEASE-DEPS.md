# 依赖包的分发与托管（Skia + 字体）

`cpp/skia`（约 141 MB）与 `cpp/fonts`（约 21 MB）**不入库**，`/cpp/` 与 `/dist/` 都在
`.gitignore` 里。所以「新机器 clone 完怎么拿到包」这件事，只能由 GitHub Releases 承担。

**当前状态**：分发链路已经写完并端到端验证过（`tools/pack-deps.php` 打、`tools/fetch-deps.php` 取），
但**仓库尚未配置远端、没有任何 tag、本机也没装 `gh`** —— 首个 release 还没发出去。
在那之前 README 里那句「从已有 `cpp/` 的机器拷入」仍然有效。

---

## 一、发布首个包（只做一次）

```bash
# 1. 建远端并推主干
git remote add origin https://github.com/<owner>/<repo>.git
git push -u origin main

# 2. 打包，记下打印的 SHA256（同时写出 dist/*.sha256）
php tools/pack-deps.php

# 3. 把 SHA256 顺手回写到 README「构建环境」② 的校验值段
#    （可选，只供肉眼比对；fetch 的校验认 deps.lock.json，它第 2 步已自动写好）

# 4. 打 tag 并建 release，两个资产一起传
git tag deps-v1 && git push origin deps-v1
gh release create deps-v1 \
  --title "deps v1 · Skia M148 + Noto Sans SC" \
  --notes "外部二进制依赖，解压到仓库根。详见包内 cpp/DEPS-MANIFEST.txt 与 cpp/licenses/THIRD-PARTY.md。" \
  dist/drift-deps-win-x64.zip dist/drift-deps-win-x64.zip.sha256
```

没装 `gh` 就在网页上做：Releases → Draft a new release → 选 tag `deps-v1` → 上传上述两个文件。
**资产名一个字都不能改**：`fetch-deps.php` 按
`https://github.com/<repo>/releases/<latest|download/tag>/download/drift-deps-win-x64.zip`
拼 URL，`.sha256` 是它的兄弟资产，缺了就只能人工比对。

## 二、为什么依赖包用独立 tag，不跟代码版本

`latest` 指向**仓库最新**的 release。代码发版通常比依赖包勤，一旦 latest 指向一个
没传 zip 的代码 release，`fetch-deps.php` 默认路径就 404。所以：

- 依赖包单独发 `deps-v1`、`deps-v2`…，与代码版本解耦；
- `fetch-deps.php` 默认仍走 `latest`（小仓库只发一种 release 时最省事），
  404 时会提示改用 `--tag=deps-v1`；
- 使用方想钉版本就显式写 `--tag=deps-v1`。

## 三、更新包

内容变了（Skia 升级、字体换版、补齐许可）就要重打包，**SHA256 必变**。两种做法：

| 做法 | 好处 | 代价 |
|---|---|---|
| 新 tag `deps-v2`（推荐） | 旧包仍可复现，谁在用哪版说得清 | tag 会变多 |
| 删旧资产、同 tag 重传 | tag 少 | 外部手里的 SHA 与 README 会短暂对不上 |

无论哪种，**`deps.lock.json` 必须一起提交**。它由 `pack-deps.php` 自动重写，忘了提交的表现是
别人 fetch 时报「SHA256 不符」或「lock 与 release 的 .sha256 不一致」—— 而这正是它该做的事：
把「悄悄换了包」变成一次停在解压之前的失败。README 里的 hash 段只供肉眼比对，不同步不影响 fetch。

## 四、每次发布后的验收

在干净目录里 clone 一次，按新使用者的路径走一遍：

```bash
git clone <repo> ../drift-check && cd ../drift-check
php tools/fetch-deps.php --tag=deps-v1
php tools/doctor.php
```

两条都要过：fetch 退出 0 且打印 `24 个 .lib、2 个字体、11 份许可`，doctor 全绿。
只在开发机上测等于没测 —— 开发机的 `cpp/` 本来就存在，`--force` 覆盖一遍看不出「从零」。

## 五、边界

- **public 仓库才能匿名下载**。private 仓库需要带凭据的直链：GitHub 的
  `releases/download/...` 会 302 到带签名的对象存储地址，`--url` 传那个直链可行，
  但脚本目前不会自动加 `Authorization` 头。
- 单资产上限 2 GB（GitHub 限制），当前 49.6 MiB，够用很久。
- **包不进 git 历史**：50 MB × 每次发版会让仓库永久膨胀，且 clone 变慢；
  release 资产可删可换，这才是它该待的地方。
- 包内已随附 11 份许可全文（`cpp/licenses/`）与索引，发版时**不要**只传 zip 不传 `.sha256`。

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

**我们吸收的四条**

1. `deps.lock.json` 入库（Electron 内嵌 checksum / Meson `source_hash` 的做法）：
   校验值不再和被校验物同源上传，换包必须同时改仓库文件。
2. 多源回退（Meson `source_fallback_url` / Electron `ELECTRON_MIRROR`）：`DRIFT_DEPS_URL`，
   逗号分隔，最后一个候选永远是 GitHub。
3. 断点续传（Godot 的教训）：GitHub 大资产走签名 URL，网络差会中途断；已支持 `.part` 续传，
   服务器不认 `Range` 时自动丢档重下。
4. 包内一切路径落 `cpp/`（Chromium 的 `third_party/` 隔离思路）：解压到仓库根不污染工作区。

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
