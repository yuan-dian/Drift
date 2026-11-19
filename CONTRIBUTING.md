# 参与贡献

谢谢你考虑贡献 Drift。当前是技术预览（Windows x64 与 Linux x64/X11、API 可能变），贡献价值最高的三件事是：**可复现的缺陷报告、真实应用跑出来的坑、判据补强**。

## 先分清两件事

- **想用 Drift 写应用**：你不需要给本仓提 PR。从[快速开始](docs/start/quickstart.md)起步，卡住就按下面"报告问题"提 issue。
- **想改 Drift 框架本身**：这是开源贡献，按"提交变更"走。

## 报告问题

好 issue 的标准只有一条：**别人照着能复现**。用 bug report 模板，务必给出：

- 复现步骤 + 最小代码（能跑的 `apps/` 小应用最好）；
- 期望与实际，附上 `php bin/drift verify` 的输出；
- 环境：`php -v`、`php tools/doctor.php --json`、Windows 版本或 Linux 发行版 + X11 环境；
- 是解释器 / dev 壳 / AOT 可执行程序中的哪一种——三者语义不同，见[验证分层](docs/guides/testing.md)。

安全漏洞**不要**开公开 issue，走 [SECURITY.md](SECURITY.md)。

## 提交变更

1. **先开 issue 说清要改什么**，尤其大改动。避免你写了半天发现方向已被裁决否决——现行结论在[裁决索引](docs/decisions/README.md)，每条带失效条件。
2. Fork → 分支 → 改 → 验证 → PR。不要直接推 main。
3. 改代码守的纪律见[协作流程](docs/contributing/workflow.md)：新增 lint 规则或判据必须能回答"什么变异让它报红"；文档不写行号锚点。
4. 按改动层级跑验证：改 `apps/<app>/src/` 跑 T0+T1；碰 `src/` / `cpp-src/` 跑 T2（Windows 为 `drift-build --full` + `probe-window`，Linux X11 为 `bash probes/linux_x11/build.sh`）。公开 CI 覆盖 T0/T1 与两条 T2 真机门，**dev 绿不等于 AOT 绿**。
5. 环境怎么配见[环境搭建](docs/contributing/setup.md)；`php tools/doctor.php` 是单一真相源，别照记忆配。

## PR 会被怎样评审

- **判据与实现不同源**：一条只验证"代码写了某行"的测试不算判据。见[测试判据纪律](docs/guides/testing.md)。
- **一处真相**：能收口到单一写者的，不留第二份。文档里手抄会漂的清单（判据项数、API 面、版本号）一律不收。
- **公开面**：加 / 改 `@api` 按破坏性变更对待，走[公开 API 面](docs/reference/api-surface.md)的审查顺序。
- **注释尽量简洁**：写"为什么"，不写"做了什么"。

## 行为准则

参与即表示同意 [行为准则](CODE_OF_CONDUCT.md)。

## 路线图与兼容性

preview 阶段的兼容承诺与 1.0 门槛见 [ROADMAP.md](ROADMAP.md)。
