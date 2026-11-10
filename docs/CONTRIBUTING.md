# 贡献与协作指南（含 CI 真值）

> 这份文档把「只有作者本机知道」的项目判据固化下来，供第二开发者与 CI 使用。
> 详细的架构 / 设计请去 `docs/` 体系（见 [`docs/00-README.md`](00-README.md) 的权威对照）。

## 0. 一句话

**Windows-only、alpha、API 可能变。** 改代码前先看 [`docs/architecture/`](architecture/00-README.md)，
跑 `php tools/gate.php`；改 PHP 不用重编译，改 `src/` / `cpp-src/` 要重 AOT。

## 1. 构建工具链（精确版本，错一点就是 LNK2019 / 静默行为不对）

| 组件 | 版本 | 备注 |
|---|---|---|
| PHP 发行版 | >= 8.4（含 php.ini） | `composer.json` 的 `require.php`；PHPRC 靠 php.ini 同目录 |
| MSVC | 14.51.36231（VS 2022 / 18） | `tools/Doctor/Environment.php::DEFAULT_MSVC_VERSION` |
| Windows SDK | 10.0.26100.0 | `DEFAULT_SDK_VERSION` |
| PHPX | 须与 tpc 匹配 | 环境变量 `PHPX_HOME` 指向 |
| tpc（TypePHP 编译器） | 与 PHPX 同源 | 源码树 + 可执行；`doctor` 会检查 |
| Skia | 已 vendored（`cpp/skia/`，24 个 `.lib`） | 无需额外下载 |

`php tools/doctor.php` 逐项体检（只读，不安装）。它与 `drift-build` 共用同一份
定位逻辑（`tools/Doctor/Environment.php`），**不会各写一套**。

## 2. 三层验证（T0/T1/T2，dev 绿 ≠ AOT 绿）

- **T0**：纯 PHP 校验（`gate` 的 lint / selftest / devshell / docref / apidoc / probe / tick / scale / sym / m2 / unit）。
  只装 PHP 8.4 就能跑——**CI 公开 runner 跑这部分**。
- **T1**：开发态壳（`php tools/drift-dev.php <app>`）—— 改 PHP 即时生效，不重编译。
- **T2**：AOT 真机构建 + 真机探针（`drift-build --full` + `probe-window`）。
  **需要 §1 完整工具链**——自托管 Windows runner 才跑。

## 3. 日常命令

```bash
php tools/gate.php --fast            # 日常迭代（跳过慢项）
php tools/gate.php --full            # 全量
php tools/gate.php --only=unit       # 单跑某项
php tools/gate.php --json            # 机器可读（CI 消费）
php tools/drift-dev.php <app>        # 开发态壳（免重编译）
php tools/drift-build.php <app> --full   # 构建独立 exe
python tools/probe-window.py <app>   # 真机探针（5 判据）
```

## 4. CI

- **PR**：跑 T0 纯 PHP 子集
  （`gate --only=lint,selftest,devshell,docref,apidoc,probe,tick,scale,sym,m2,unit --json`），
  `windows-latest` + `setup-php 8.4`，**无需 MSVC / tpc**。
- **Nightly**：同上 + 慢项（feature / golden）。
- **AOT / T2**：需自托管 Windows runner（预装 §1 工具链），见 `.github/workflows/ci.yml` 注释模板。

## 5. 改代码的纪律

- 改 `src/` 或 `cpp-src/` → 必须 T2 重编译验证（`drift-build --full` + `probe-window`）。
- 改 `apps/<app>/src/` 的 PHP → dev 壳即时生效，但发版前仍要 T2。
- 新增 lint 规则 / 判据，必须能回答「什么变异能让它报红」，答不上的不上仓（变异四律）。
- 新文档不写 `file:line`（行号会漂）；定位用符号名。
- 文档引用由 `tools/docref.php` 把守（三条防漂检查），error 挡闸门。

## 6. 发布

- 版本号 `v0.1.0-preview.N`，tag 即发布（见 [`../CHANGELOG.md`](../CHANGELOG.md)）。
- 发版前：T2 全绿 + `drift-build --full`（不要增量，避免 stale）+ `probe-window` 5/5。
