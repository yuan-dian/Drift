# 决策记录：`apps/` 要不要改成 `examples/`

> 承接 `docs/REVIEW-示例应用整合-2026-11-16.md`。那份的结论是"不整合，缺的是定位标签"；
> 本份回答追问："既然成熟框架用 `examples/`，我们是不是也该换？"

## 0. 一句话结论

**不建议改。** `apps/` 与 `examples/` 都是成熟惯例，这里不是"从错到对"；
而本仓 `apps/` 里只有 4/8 是"给人看的示例"，另 4 个是量具 / 开发壳 / CI 判据。
全量改名会把这些也塞进"示例"目录，**语义反而倒退**，且要动约 180 个文件与整条构建链。
真正的问题（定位不清）补一层标签即可解决，零成本、零风险。

## 1. 改名的真实成本（实测，不是估的）

| 位置 | `apps/` 出现 | 是否必须改 |
|---|---|---|
| `docs/` | 730 次 / 93 文件 | 路径引用必须改，概念表述可留 |
| `tools/` | 181 次 / 27 文件 | **构建链硬编码**，必须改 |
| `tests/` | 38 次 / 21 文件 | 必须改 |
| `apps/` 内部 | 67 次 / 29 文件 | 相对引用，多数随之失效 |
| `src/` | 15 次 | 少量，必须改 |
| `composer.json` | 18 条 PSR-4 映射 | 必须改（`App\Xxx\` → 新路径） |

构建链里吃路径约定的点（改目录名就得同步改）：

- `tools/drift-codegen.php` — `glob($root . '/apps/*/src')`；
- `tools/gen-version.php` — `glob($root . '/apps/*/project.yml')`；
- `tools/drift-dev.php` — 硬编码 `{$root}/apps/dev/project.yml`（多处读写）；
- `tools/drift-build.php` — 应用名解析为 `apps/<name>/project.yml`；
- `golden-raster.php` / `scene-set-check.php` — L1 冻结清单已显式记录场景来源。

namespace 不绑物理目录（`App\Hello` ↔ `apps/hello`），改目录只需同步 PSR-4，
类名不用动——这是唯一省事的地方，但救不了整体成本。
**去掉 `.workbuddy-ai/`（记忆，46 文件）与 `_build`/`_codegen`（派生产物）后，
净改动约 180 文件，零功能收益。**

## 2. 惯例对照：两种都成熟，不存在唯一正确

- **Flutter**：`examples/`（catalog / layers / hello_world / platform_channel …）
  + `dev/`（benchmarks / devicelab / integration_tests / manual_tests）。
  注意连 `hello_world` 这种最小出口也放 `examples/`；量具与 CI 测试在 `dev/`。
- **Qt**：`examples/` 按模块分组，`tests/benchmarks` 放量具。
- **monorepo 主流（Nx / Turborepo）**：`apps/` 就是"可启动应用"的标准目录名，
  `packages/` 放库与工具。本仓"每应用一个 `project.yml`"正是这套语义。

所以"换成 examples"**不是纠错**，是从一套成熟惯例切到另一套。
切的直接代价就是 §1 的 180 文件，收益仅是命名审美。

## 3. 语义核心：4/8 不是"示例"

上一轮已定性——8 个目录分四类：

| 类别 | 目录 | 给谁看 |
|---|---|---|
| 能力示例（4） | showcase / tutorial / users / watermark | 应用开发者 |
| 最小出口判据 | hello | 构建链回归 |
| 量具（2） | bench / pacing | 框架维护者 |
| 开发工具 | dev（热重载壳） | 框架维护者 |

全量改成 `examples/` ⇒ `bench/`、`pacing/`、`dev/` 也变成"examples"，
**这正是上一轮报告点名要避免的语义错位**（§4 共同点："量具与示例物理分开"）。

## 4. 三方案裁决

| 方案 | 做法 | 成本 | 语义 | 裁决 |
|---|---|---|---|---|
| **A** | 保留 `apps/`，补一张定位总览表 | 零代码、一个文档段 | 清晰（标出 4 类） | ✅ **推荐** |
| **B** | 全量 `apps/` → `examples/` | ~180 文件 + 构建链 + PSR-4 | ❌ 倒退（量具变示例） | ❌ 否决 |
| **C** | 照 Flutter 拆：`examples/`(5) + `dev/`(3) | 最高：物理迁移 + 构建工具分目录 + 全部引用 | 最贴近 Flutter | ⚠️ 仅当强诉求时做 |

- **A** 解决真问题：上一轮已确认"感觉多"的根源是定位标签缺失，不是目录名。
  在 `docs/00-README.md` 或 `apps/README.md` 补一张四类标签表即可，零风险。
- **C** 是唯一"既拿到 examples 语义又不错位"的方案，但要重构构建工具的路径约定
  （`glob(apps/*/...)` 要同时覆盖两个根，`drift-dev` 硬编码的 dev 路径要重新落位），
  且 dev 目录名已被热重载壳占用——牵一发动全身。**除非明确要求 Flutter 式布局，否则不启动。**

## 5. 若坚持要 examples 语义（方案 C 最小路径）

1. 先迁非示例：`dev/`（热重载壳）改名避让 → 量具 `bench`/`pacing` 与壳归入 `dev/` 或 `tools/`；
2. 再迁 5 个应用示例到 `examples/`（含 `hello`）；
3. 改构建链：`drift-codegen` / `gen-version` 的 glob 双根、`drift-dev` 路径、`drift-build` 解析；
4. 改 `composer.json` 18 条 PSR-4；
5. 全仓替换引用（docs 730 / tools 181 / tests 38 / apps 67 / src 15）；
6. 四档验证（phpunit / lint / codegen / AOT 真机 hello）全绿后再提交。

> 这是"可破坏兼容性"范畴的大改，需要单独立项，不建议顺手做。

## 6. 附带的过时文档债（与命名无关，顺带记录）

`docs/tutorial.md` 写 showcase "19 个页面"，实际 35 个。上次审查已记，本次不改。

---

> 本文件是快照式决策记录，记录 2026-11-16 的判断，不随后续改动回填结论。
