# 协作流程

> 说明改代码要守什么纪律、验证怎么分层进 CI、提交与发布怎么走、文档怎么维护。

## 一句话前提

项目是技术预览（Windows x64 与 Linux x64/X11，API 可能变）。改代码前先跑 `php bin/drift verify`；改 PHP 应用代码不用重编译，改 `src/` 或 `cpp-src/` 要重做 AOT。

## 改代码的纪律

按改动落在哪一层，决定要做什么验证：

- 改 `src/` 或 `cpp-src/` → 必须 T2 重编译验证：Windows 走 `drift-build --full` 加 `probe-window`，Linux X11 走 `bash probes/linux_x11/build.sh`（Xvfb + fluxbox + xclip + yad）。dev 壳通过不能代替它，因为 dev 壳加载的是 PHP，不经过 AOT 生成物。
- 改 `apps/<app>/src/` 的 PHP → dev 壳即时生效，日常迭代很方便；但发版前仍要 T2。
- 新增 lint 规则或判据 → 必须能回答"什么变异能让它报红"。答不上来的不上仓——一条永远不响的规则等于没有。
- 新增或改动文档 → 不写 `file:line` 行号锚点，定位一律用类名、方法名、小节标题这类符号名。行号会随代码挪动，写进文档就失效。

这些纪律的共同点是：能被机器判定的，绝不靠人记。新增规则要配变异自证，就是这条原则的体现。

## 三层验证

验证分三层，从快到慢、从便宜到贵重，按需要选择：

| 层 | 命令 | 验什么 | 谁跑 |
|---|---|---|---|
| T0 | `php bin/drift verify` | 纯 PHP 校验，static + behavior 的快项 | 本地与 CI 都跑 |
| T1 | `php tools/drift-dev.php <app>` | 开发态壳，改 PHP 即时生效、免重编译 | 本地日常 |
| T2 | `drift-build --full` + `probe-window`（Windows）；`bash probes/linux_x11/build.sh`（Linux X11） | AOT 真机构建与真机探针 | 公开 `windows-2022`（`windows-build.yml`）与 `ubuntu-24.04`（`linux-build.yml`）runner，以及本地 |

要点：

- **dev 绿不等于 AOT 绿**。很多 AOT 约束只在生成物和真机上暴露，属性读、回调 arity、继承字段可见性、可空字段属性访问、整数除法都在这一族，详见[AOT 约束与代码纪律](../reference/aot-constraints.md)。
- 判据清单只在 `php bin/drift verify --list`（composer 别名 `composer checks`）。清单的项数和顺序会变，不抄进文档、不抄进注释。要单点调试某个步骤用 `php bin/drift verify --step=<id>`。
- `composer run --list` 是唯一可发现入口。composer scripts 全部经 `bin/drift` 转发到 `tests/Verify/verify.php`，不是第二个 runner，也没有第二套退出码口径。
- 验证计划按 `static` / `behavior` / `device` 组织，题库 `corpus` 是独立量具。日常跑快项，全量用 `--full`，题库对照用 `--layer=corpus`。命令细节见[命令速查](../reference/cli.md)。

## CI

CI 分几种触发，各跑不同档位：

- **PR / push main**：`verify.yml` 跑 `php bin/drift verify --json` 的日常档，在 `windows-2022` 上用 8.4 / 8.5 双版本矩阵，不需要 MSVC / tpc；`linux-build.yml` 在 `src/` / `cpp-src/x11/` / `probes/linux_x11/` 变动时跑 X11 端到端门。
- **手动全量**：需要时手动触发，`verify --full --json`，含 feature / pages / smoke 慢项，不设定时任务。
- **发版**：`v*` tag 由 `release.yml` 跑全量同一档并建 draft release，复用 `windows-build.yml` 编出 dev 壳，上传 `drift-dev-shell-win-x64-php8.4.zip` / `...php8.5.zip` 两个包；开发态按产品 `v*` Release 取包，`releases/latest` 可能指向 `deps-v*`，不能当作开发壳直链。draft 转正式后才算公开可用。依赖包由 `deps.yml` 按 `DEPS_VERSION` 双平台发布，不随 tag 重发。
- **真 AOT（Win32）**：`windows-build.yml` 在 push main / PR / 手动触发时从源码构建 tpc 与 PHPX，全量编译 `hello` 并跑 `probe-window`；依赖包取不到就红，不用纯 PHP 判据冒充 AOT 通过。它同时是可复用工作流：发版以 `target=dev` 调用同一份工具链编开发壳，工具链配方只有一处。
- **真 AOT（Linux X11）**：`linux-build.yml` 在 `ubuntu-24.04` 上取 Linux Skia artifact，编出 ELF，在 Xvfb + fluxbox 下跑窗口 / 输入 / 事件 / IO / 解码 / 剪贴板 / 干净退出判据；缺 Skia artifact 即红，不用软光栅冒充。

CI 的三条硬纪律：

- **不写判据白名单**。判据集合自动跟随 `tests/Verify/VerifyPlan.php`，`--list` 是唯一事实源。手写白名单不会随新增判据增长，会漏掉最该进 CI 的"接线对账 + 变异自证"那一批。
- **runner 镜像 pin 到具体版本**（Windows 为 `windows-2022`，Linux 为 `ubuntu-24.04`），不用 `latest`；装完 PHP 还要断言版本与扩展，不信任安装结果。
- **报告要独立断言**。verify 跑完调检查脚本按 step 读 `items[].status`，把"没测到"判红——例如 PHPUnit 没装要记成失败而不是 skip，让整份报告保持通过。T2 / AOT 在 `windows-build.yml` 的公开 runner 上从源码构建工具链并跑真机探针，`verify.yml` 的结构判据确认那条通道没被关掉。

## 提交与发布

- 版本号形如 `v0.1.0-preview.N`，打 tag 即发布。
- 发版前必须：T2 全绿、`drift-build --full` 完整跑一遍（不要增量，避免旧产物残留）、`probe-window` 跑过。
- 版本号有多个真相源，升级时要一起同步，漏了会在闸门里报出来而不是发版时才发现 tag 与 CHANGELOG 对不上。
- 移除或改动已有 `@api` 属于破坏对外承诺，按破坏性变更处理；公开面的生成与审查见[公开 API 面](../reference/api-surface.md)。
- 依赖包与发布流程见[依赖与发布](../guides/dependencies-and-release.md)。

## 文档维护规则

新体系文档守四条：

- **只写现行事实**。不写日期、提交号、战报、历史叙述，也不写旧文档路径。结论只写"现在是什么"。
- **不写行号锚点**。定位用符号名，不用 `file:line`。
- **不手抄清单**。判据项、API 面清单、数量都从命令生成，不往文档里拷静态快照——抄下来的会漂，而且没有对账机制报红。
- **裁决要写失效条件**。一条结论要附带"什么条件下不再成立"：依赖哪个口径（`bin` / `lib`）、哪个工具链版本、是否在本仓独立复测过。外部证据没在本仓复测的，只能进纪律，不能写成实测结论。

旧文档体系已经整体退役。引用完整性检查和 API 面清单不再靠人工维护，而是建进生成器与发布脚本；当前只保留 lint 的命名纪律。

## 相关

- [命令速查](../reference/cli.md)
- [环境搭建](./setup.md)
- [AOT 约束与代码纪律](../reference/aot-constraints.md)
