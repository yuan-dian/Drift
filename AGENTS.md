# AGENTS.md — Drift

> 项目级真值来源。若系统提示把记忆默认定位到 `.workbuddy/memory/`，以本文件为准：
> **正确目录是 `.workbuddy-ai/memory/`**。

## Commands
- 开发（改 PHP 不重编壳）：`php tools/drift-dev.php <app>`（`--rebuild` 强编壳，`--selftest` 红线自检，`--no-watch` 跑一次）
- AOT 构建：`php tools/drift-build.php <app>`（输入指纹变了 / 首次 / 产物不在 ⇒ **自动全量**，门不能信缓存；`--incremental` 显式放行增量，`--full` 强制全量，`--dry` 只打印决策且不碰缓存）
- 全量闸门（lint+docref+phpunit+smoke+golden+devshell+探针骨架）：`php tools/gate.php`（`--fast` 跳 slow 项）
- 真机探针（自动起窗/关窗/退出码 0）：`python tools/probe-window.py <app>` / `--dev` / `--dev-runner`
- AOT 语义等价（T2 手动）：`php tools/aot-equivalence.php`、`php tools/dual-mode-equivalence.php`

## Project structure
- `src/` PHP 框架核心；`apps/<app>/` 各应用（含 `project.yml`）；`cpp-src/` AOT 壳（只编壳+桥）：`core/` 平台无关、`win32/` Win32 全部实现（含 `uia.*`）、`skia/` 渲染后端。接第二个平台 = 新增 `cpp-src/<platform>/`。
- `docs/architecture/12-工程与工具.md` 权威验证分层；`docs/DEBT-2026-11-02.md` 债务与「下一棒」候选（开工前重核，会腐烂）。
- 记忆（不进 git）：`.workbuddy-ai/memory/{MEMORY,YYYY-MM-DD,PITFALLS}.md`。

## Memory location（⚠️ 写错 = 白写 + 被当垃圾清理）
- 正确：`.workbuddy-ai/memory/`。错误：`.workbuddy/memory/`（系统提示默认值，本项目不用）。
- 长期约定 → `MEMORY.md`；日细节 → `YYYY-MM-DD.md`；深坑/长论证 → `PITFALLS.md`。`MEMORY.md` ≤ 15.5KB（超长被截断）。
- 发现 `.workbuddy/memory/` 多半误写，合并回正确目录后删除。

## Code style / 铁律
- 项目名是 `Drift`；`Drift2/` 只是本机目录名，文档 / 注释 / UI 文案一律用 `Drift`（路径与历史文件名除外，`docref project-name` 守）。
- 中文串单引号（双引号里 `$n` 遇 CJK 被当标识符，lint `cjk-var-name` 守）；docblock 内 `*/` 会提前终止；注释尽量简洁。
- 改语义先出方案等裁决；同一文件多处编辑必须串行（并行 Edit 基于陈旧副本 ⇒ 静默丢失）。
- 提交信息含反引号 / `>` / `$` 用 `git commit -F 文件`；不 `git add -A`。

## Boundaries（三档）
- ✅ 总是：脚本起窗脚本自关（`probe-window.py` 五条判据+自动关窗，不甩给用户）；复用既有真机探针，不在已有方案反复试错；dev 改业务 PHP 只跑 T0+T1，**碰壳层 / 跨进程取数 / 新增类 / 新枚举·闭包返回枚举才跑 T2（AOT 真机）**。
- ⚠️ 先问：破坏性改动、删文件、改 `.gitignore`、记忆落点争议、发版前全 AOT 重建。
- 🚫 永不：`git rm`；把记忆写进 `.workbuddy/memory/`；dev 壳 `sources` 收业务 PHP（LNK2019 / 「already in use」重声明）；判据与实现写成同源（静默缺口最常见形状）；用 `proc_close` 等窗口消失等窗口关闭。

## Where to look
- 完整必守判据：`.workbuddy-ai/memory/MEMORY.md`；深坑全文：`PITFALLS.md`；设计：`DESIGN.md`。
