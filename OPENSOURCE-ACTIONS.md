# 开源化：交回维护者的动作清单

> 身份已裁决为**真开源**（2026-11-16）：文档口径已按公开项目改写，不再标注仓库可见性身份。下面列出只能在 GitHub 后台 / 涉及发版承诺、因此交回维护者执行的动作，以及它们之间的依赖顺序。本文是一次性交接清单，不是长期文档。

## 依赖顺序（先解死结，否则后面都是美化）

1. **仓库设为公开**（GitHub 后台：Settings → General → Danger Zone → Change visibility）。身份已裁决，这一步只是落地。
2. **发布首个依赖包**：`php tools/pack-deps.php` → `php tools/release-deps.php`，把 `deps-v1` 资产转为公开；公开后 `fetch-deps.php` 的默认路径才对匿名可用。
3. **打 `v0.1.0-preview.1` tag 并推送**：激活已写好的 `release.yml`（当前 0 tag，`git describe` 报 no names）。release 以 DRAFT 创建， stray tag 不会误发公告。
4. **开公开 AOT smoke**：`ci.yml` 的 `aot` job 当前 `if: false`、依赖自托管 runner。最小版本是在公开 runner 上只做"编译 hello"（不跑真机），让核心卖点在公开渠道有绿灯；真机探针仍留自托管。
5. **README 门面**：加 CI badge + 一张运行截图；把 17 KB 的环境细节下沉，第一屏只留定位、截图、三行 hello、一句话安装。

第 2 步是硬前置：跳过它，贡献入口、badge、文档再漂亮，陌生人依旧 clone 不出可运行的 exe。

## 本轮已落地（无需后台动作）

- **身份口径**：README / CHANGELOG / 环境搭建 / 依赖与发布指南 / `release.yml` 注释 / ROADMAP 全部改为"公开 Release 可匿名取包；资产未发布或镜像不可达时用 token / `--url`"的取包机制说明。归档 `_legacy-docs/` 是历史快照，不动。
- `CONTRIBUTING.md` / `CODE_OF_CONDUCT.md` / `SECURITY.md` / issue-PR 模板：对外贡献入口、行为准则、漏洞上报渠道；联系邮箱与安全链接统一指向 `composer.json` 的 `authors[].email` / `support.security`。
- `composer.json`：补 authors / homepage / repository / support / keywords。
- `ROADMAP.md`：兼容承诺、1.0 门槛、方向优先级、失效条件。
- `tools/Doctor/Environment.php`：新增 tpc 版本基线判据（git 祖先判定），配 `tests/Unit/Doctor/TpcBaselineCheckTest.php` 三态测试 + 变异自检。
- `docs/contributing/setup.md`：新增"从 clone 到 hello 的最短路径"，每一步判据仍收在 doctor。

## 尚待后续轮次（非本轮遗漏，需专门判据/设计）

- **Skia commit pin**：`SKIA_REVISION=unpinned`、lock 里 `provenance: legacy`，首个包无可追溯 commit。要 pin 就得从 pinned commit 重建依赖包（自托管 runner + 数小时），属于发布动作，不是改文件。
- **英文首屏**：全中文文档对国际贡献者是天然门槛；即使只做 README 首屏英文化也有价值。
- **贡献引导脚本**：`doctor` 已是单一真相源，暂不包第二层脚本；若后续确认需要，应在 doctor 里加 `--fix` 而非另起脚本。
