# 贡献与协作指南（含 CI 真值）

> 这份文档把「只有作者本机知道」的项目判据固化下来，供第二开发者与 CI 使用。
> 详细的架构 / 设计请去 `docs/` 体系（见 [`docs/00-README.md`](00-README.md) 的权威对照）。

## 0. 一句话

**Windows-only、alpha、API 可能变。** 改代码前先看 [`docs/architecture/`](architecture/00-README.md)，
跑 `php bin/drift verify`；改 PHP 不用重编译，改 `src/` / `cpp-src/` 要重 AOT。

## 1. 构建工具链（精确版本，错一点就是 LNK2019 / 静默行为不对）

| 组件 | 版本 | 备注 |
|---|---|---|
| PHP 发行版 | >= 8.4（含 php.ini） | `composer.json` 的 `require.php`；PHPRC 靠 php.ini 同目录 |
| MSVC | 14.51.36231（VS 2022 / 18） | `tools/Doctor/Environment.php::DEFAULT_MSVC_VERSION` |
| Windows SDK | 10.0.26100.0 | `DEFAULT_SDK_VERSION` |
| PHPX | 须与 tpc 匹配 | 默认 `<php.exe 目录>/phpx/`，用 `DRIFT_PHPX_HOME` 覆盖（`PHPX_HOME` 由构建脚本写给子进程） |
| tpc（TypePHP 编译器） | 与 PHPX 同源 | 默认同级 `../typephp/bin/tpc.php`，用 `DRIFT_TPC_ENTRY` 覆盖；`doctor` 会检查 |
| Skia + 字体 | `cpp/skia/`（约 141 MB）+ `cpp/fonts/`（约 21 MB） | **`/cpp/` 在 `.gitignore` 里，不随仓库分发**；clone 后需自行补入，分发方式仍是开放项 |

`php tools/doctor.php` 逐项体检（只读，不安装）。它与 `drift-build` 共用同一份
定位逻辑（`tools/Doctor/Environment.php`），**不会各写一套**。
TypePHP 的下载安装、默认目录布局与环境变量清单，见 [`../README.md`](../README.md)
的「构建环境」一节（其中「下载安装 TypePHP」给出 clone / Release 包的具体步骤）。

## 2. 三层验证（T0/T1/T2，dev 绿 ≠ AOT 绿）

- **T0**：纯 PHP 校验（`bin/drift verify` 的日常档 —— **以 `verify --list` 为准**，别抄清单）。
  装 PHP 8.4 或 8.5 就能跑——**CI 公开 runner 两个版本都跑**（`composer.json`
  声明 `>=8.4` 无上界，只在单版本上验等于承诺没兑现）。
- **T1**：开发态壳（`php tools/drift-dev.php <app>`）—— 改 PHP 即时生效，不重编译。
- **T2**：AOT 真机构建 + 真机探针（`drift-build --full` + `probe-window`）。
  **需要 §1 完整工具链**——自托管 Windows runner 才跑。

## 3. 日常命令（一个入口，别再抄清单）

★ 判据清单**只**在 `php bin/drift verify --list` / `composer checks`，项数与顺序都会变，
  不抄进文档、不写进注释（2026-11-11 `--only` 白名单漏 5 对判据就是手写清单漂的）。
  `composer run --list` 是唯一可发现入口；下面的 scripts 全部经 `bin/drift` 转发到
  `tests/Verify/verify.php`，**不是第二个 runner**，也没有第二套退出码口径。
  计划按 `static / behavior / device / corpus` 四层组织；单点调试用
  `composer verify -- --step=<id>`，别再抄一份判据白名单。
  `check` / `check:full` / `checks` / `tooling` 只是 V1 时代脚本名的历史别名，
  一律转发同一个 V2 runner。

```bash
composer verify                      # T0 日常验证（static + behavior 快项）
composer verify -- --step=unit       # 单点调试；-- --json 机器可读
composer verify:full                 # T0 全量（含 feature / pages / smoke 慢项）
composer test                        # PHPUnit unit；composer test -- feature 跑场景
composer tooling                     # V2 static 层（历史别名）
composer checks                      # 当前判据清单（--list，顺序即执行顺序）
composer dev -- <app>                # T1 开发态壳（动态 PHP、真窗口、免重编译）
composer build -- <app> --full       # T2 AOT 构建
composer probe -- <app>              # T2 真机探针
```

裸命令仍在 `tools/` 下（CI / 真机探针 / 调试直接调），但**不要把它们另抄一份清单**：
`php bin/drift verify --json`（CI）、`php tools/phpunit-run.php unit`、
`python tools/probe-window.py <app>`。

## 4. CI

- **PR / push main**：跑 `php bin/drift verify --json`（日常档），`windows-2022` +
  `setup-php` 的 8.4 / 8.5 matrix（上游 `swoole/typephp` 同口径），**无需 MSVC / tpc**。
  ★ 不要在这里写判据白名单：白名单不会随新增判据增长，2026-11-11 之前它只列了
  11 项（真实 28 项），漏掉的是 abi / cppbridge / dxdiag / stalepolicy / errshape
  这五对「接线对账 + 变异自证」——最该进 CI 的那一批。现在判据集合自动跟随
  `tests/Verify/VerifyPlan.php`，`--list` 是唯一事实源。
  ★ runner 镜像 pin `windows-2022` 而不是 `latest`；`setup-php` 带 `coverage: none` /
  `fail-fast: true`，并有 `Show PHP environment` + 版本 / 扩展断言（不信任安装结果）。
  **不借**上游的 `phpts: ts`：那是编译 tpc 要 ZTS，本档是纯 PHP 判据。
- **手动全量**：需要时在 Actions 手动触发 `ci.yml` 的 `gate-full`，跑 `php bin/drift verify --full --json`（含 feature / pages / smoke 慢项），单跑 8.4；不设定时任务，发版 `v*` tag 由 `release.yml` 自动跑同一档。题库全量对照是 `--layer=corpus` 的独立量具，不进 verify 报告。
- **报告断言**：三个 workflow 跑完 `verify --json` 都调
  `.github/scripts/assert-verify-report.php <报告> [step id...]`。`ci.yml` / `release.yml`
  必须给 `unit`（手动全量 / release 还给 `feature`）；V1 gate 会把「PHPUnit 没装」记成
  `skip` 让整份报告仍 `ok: true`，V2 改成 `exitCode=127 / ok=false`，断言再走独立路径
  读 `items[].status`，把「没测到」判红。`deps-build.yml` 只跑 `--layer=static`
  且不传 id，因为自托管 runner 不保证有 composer，不该卡住 Skia 包。
- **AOT / T2**：需自托管 Windows runner（预装 §1 工具链），见 `.github/workflows/ci.yml` 注释模板。

## 5. 改代码的纪律

- 改 `src/` 或 `cpp-src/` → 必须 T2 重编译验证（`drift-build --full` + `probe-window`）。
- 改 `apps/<app>/src/` 的 PHP → dev 壳即时生效，但发版前仍要 T2。
- 新增 lint 规则 / 判据，必须能回答「什么变异能让它报红」，答不上的不上仓（变异四律）。
- 新文档不写 `file:line`（行号会漂）；定位用符号名。
- 文档引用检查随旧文档体系整体退役；当前只保留 `lint project-name` 命名纪律。引用完整性与 API 面清单在文档重构那一棒内建进生成器 / 发布脚本。

## 6. 发布

- 版本号 `v0.1.0-preview.N`，tag 即发布（见 [`../CHANGELOG.md`](../CHANGELOG.md)）。
- 发版前：T2 全绿 + `drift-build --full`（不要增量，避免 stale）+ `probe-window` 5/5。
