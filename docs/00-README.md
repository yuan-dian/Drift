# Drift2 文档索引与权威对照

> **为什么需要这份**：仓库里曾有**四套并行文档**，册名几乎一一对应，读者（包括未来的我）
> 分不清**哪套是权威、什么时候该看哪套**。ARCH-REVIEW-2026-10-24 §M2 把"三套并行、
> 无防漂移"列为最大长期风险 —— 这份对照表是第一步止血：**先把"看哪套"说清楚**。
> ★ 2026-11-05 删掉 `docs/design-v7/`（v7 提案稿）后剩**三套**；对照结论仍留在
> `docs/architecture/13-与-v7-设计对照.md`。
>
> ★ **本表只写"角色与读者路径"，不写设计内容** —— 内容在各册里，本表只回答"去哪看"。

---

## 0. 一句话导读

**想知道"现在代码是怎么写的"看 `docs/architecture/`；想知道"为什么这么设计"看根
`DESIGN.md`；`docs/arch-v2/` 是 v2 修订期的过程稿，已被 architecture 吸收，只在追溯
历史时看。**

---

## 1. 三套文档的权威对照

| 套 | 路径 | 册数 / 行数 | 性质 | 权威度 | 什么时候看 |
|---|---|---|---|---|---|
| **主设计** | 根 `DESIGN.md` | 1 册 / 3 449 行 | **v2.0 设计方案本体**（15 个重点的裁决 + §A 示例 + §23 提案 + §24 修正） | ★★★★★ | 想知道"**为什么这么设计**"、设计裁决的来龙去脉 |
| **实现现状** | `docs/architecture/` | 14 册 / 965 行 | **代码现状文档**（每册对应一个实现域，写"现在是怎么写的"+ `file:line` 锚点） | ★★★★★ | 想知道"**现在代码是怎么写的**"、改代码前先看这里 |
| **v2 过程稿** | `docs/arch-v2/` | 14 册 / 12 159 行 | **v2 修订期的过程稿**（含迁移路线、AOT 能力增补、W8 控件增补等专题） | ★★☆☆☆ | 只在**追溯 v2 修订历史**时看；日常开发不必看 |

★ **行数是 2026-10-24 实测**（`wc -l`）。"权威度"是**读者该优先信哪套**的排序，不是质量排序。

---

## 2. 读者路径（按角色）

| 我是谁 / 我想干什么 | 看哪套 | 入口 |
|---|---|---|
| **改代码前想了解现状** | `docs/architecture/` | [`00-README.md`](architecture/00-README.md) |
| **想从零写一个应用** | [`tutorial.md`](tutorial.md) + `apps/tutorial/` | [`tutorial.md`](tutorial.md) |
| **想加一个新控件** | `docs/architecture/08-控件面.md` + 根 `DESIGN.md §15` | — |
| **想懂设计裁决为什么这么定** | 根 `DESIGN.md` | [`../DESIGN.md`](../DESIGN.md) |
| **想知道 v7 提案里哪些判断被采纳 / 让位** | `docs/architecture/13-与-v7-设计对照.md` | [`13-与-v7-设计对照.md`](architecture/13-与-v7-设计对照.md) |
| **想查 AOT 编译约束 / 坑** | `docs/AOT-CONSTRAINTS.md` + `docs/architecture/11` | — |
| **想知道跨平台能力矩阵** | 暂缺（原 `design-v7/08-平台与能力矩阵.md` 已删；结论在 `_archive/CROSS-PLATFORM.md`） | — |
| **追溯 v2 修订过程** | `docs/arch-v2/00-总纲.md` | [`arch-v2/00-总纲.md`](arch-v2/00-总纲.md) |
| **找历史评审 / 归档** | `docs/_archive/` | 只读，不更新 |

---

## 3. `docs/` 根目录散落文件的角色

| 文件 | 角色 | 权威度 |
|---|---|---|
| [`AOT-CONSTRAINTS.md`](AOT-CONSTRAINTS.md) | **AOT 编译约束与实测**（TypePHP `tpc.php` 的边界） | ★★★★★ |
| [`tutorial.md`](tutorial.md) | **从零写一个应用**（五个文件 / 四段代码 / 四个静默坑）；配套 `apps/tutorial/` | ★★★★★ |
| [`ARCH-REVIEW-2026-10-24.md`](ARCH-REVIEW-2026-10-24.md) | **W8 收盘后的全维度体检**（架构/实现/扩展性/易用性/长期维护） | ★★★★☆ |
| [`DESIGN-LAYOUTBUILDER-2026-11-04.md`](DESIGN-LAYOUTBUILDER-2026-11-04.md) | **布局期约束反馈的裁决与实施记录**（方案 D · 帧内收敛；含四个候选的否决理由与 12 条判据） | ★★★★☆ |
| [`ARCH-REVIEW-2026-10-21.md`](ARCH-REVIEW-2026-10-21.md) | W8 之前的诊断（A1/A3 已落地，A2 未做） | ★★★☆☆ |
| [`ARCH-V2-REVIEW-2026-10-21.md`](ARCH-V2-REVIEW-2026-10-21.md) | v2 文档体系评审（§5.1"文档即规格但没防漂移"是 M2 的来源） | ★★★☆☆ |
| [`EVAL-design-v7-2026-10-21.md`](EVAL-design-v7-2026-10-21.md) | design-v7 复评（逐条裁"该搬 / 该扔"）；该提案稿已于 2026-11-05 删除，本文与 `architecture/13` 是唯一留存 | ★★★★☆ |

---

## 4. 已归档（`docs/_archive/`，只读不更新）

| 子目录 / 文件 | 内容 | 为什么归档 |
|---|---|---|
| `DESIGN-v1.0-2026-10-14.md` | v1.0 设计（210 KB） | 被根 `DESIGN.md`（v2.0）取代 |
| `HISTORY-2026-10-18.md` | 历史、探针证据、已作废结论 | 过程记录，不进主文档 |
| `DECISIONS-2026-10-14.md` | 裁决档案（问题本质/得失/推荐） | v1.0 期的裁决，v2 已重排 |
| `REVIEW-1/2-2026-10-14.md` | 两轮评审发现过程 | 过程记录 |
| `SKIA-BUILD-2026-10-16.md` | Skia 构建记录 | 一次性记录 |
| `PLAN-REVISION-ASYNC-2026-10-20.md` | 异步修订计划 | 已执行完 |
| `BACKPORT-19OP-2026-10-20.md` | 19 op 回移植记录 | 已执行完 |
| `_bak-v7/`（22 项） | **v7 重写前的备份** | `design-v7/` 已于 2026-11-05 删除 ⇒ 本目录是 v7 提案稿的**唯一留存**，**不删** |
| `design-proposals.md` | §23 优化提案（O-01~O-17 提案表 + 已落地清单 + 批准依赖图） | **已正式拆分**：`DESIGN.md` 仅保留摘要、`§3474`/`§3530` 索引与交叉引用，正文在此 ⇒ 归档（非"重复冗余"——本稿 10-24 初版描述已过时，2026-11-03 复核更正）
| `changelog-v2.md` | §24.1 v2 文档内不一致修正记录 | 同上（已拆分，非重复）⇒ 归档
| `examples.md` | §A 完整示例（users 应用 + 能力示例 + 反模式对照 + 工具链） | 同上（已拆分，非重复）⇒ 归档
| `CROSS-PLATFORM.md` | 跨平台抽象设计（评审稿） | **自标"结论已被 `design-v7/` 吸收"**，只保留作实测数据来源 ⇒ 归档 |

★ **归档 ≠ 删除**：所有归档文件都在 git 历史里，需要时 `git log --follow` 可回溯。

---

## 5. 非 Drift2 文档（`docs/typephp-issues/`）

| 文件 | 内容 |
|---|---|
| [`ISSUE-typephp-class-const-expr-inline-tmpvar-C2065.md`](typephp-issues/ISSUE-typephp-class-const-expr-inline-tmpvar-C2065.md) | TypePHP 编译器 bug 记录（`class const` 表达式内联临时变量 C2065） |
| [`ISSUE-typephp-class-const-vs-method-C2872.md`](typephp-issues/ISSUE-typephp-class-const-vs-method-C2872.md) | TypePHP 编译器 bug 记录（`class const` vs 方法 C2872） |

★ 这两份是**别的仓（typephp）的 bug 记录**，不是 Drift2 设计/实现文档，单独归类避免干扰。

---

## 6. 防漂移纪律（M2 的闸门）

**这份对照表解决的是"看哪套"，没解决"内容是否一致"。** 后者由 `tools/docref.php`
把守（ARCH-REVIEW §7 的 M2，已落地）—— 三条检查：

1. `file-line-refs`：文档里的 `file:line` 引用 —— 文件须存在、起始行不越界、裸名不许歧义
2. `section-refs`：跨册 § 引用（反引号册名后跟 §编号）—— 目标册与标题编号都须存在
3. `markdown-links`：文档里的相对链接 —— 按文档自身目录与仓库根双基准解析，不许死链

跑法：`php tools/docref.php`（`--list` 看检查清单，`--selftest` 量具双向自检）。
**error 才挡闸门**（越界 / 歧义 / 标题对不上 —— 本仓内可确定的错）；**warn 是留痕**
（外部仓引用如 typephp 的 `HISTORY.md`，本仓无从验证）。

★ 每条检查都过双向自检（该响会响 + 不该响不误响，§20 纪律 1）—— 没有这层，
闸门只能证明"跑了"，不能证明"测得准"。

★ **改文档的纪律**：新文档**不写 `file:line`** —— 定位用符号名（类名 / 方法名 / 标题号）；
行号每改一次代码就漂，是 100% 会过期的跳转目标。历史文档里已有的行号只作**当时的
坐标**：`docref` 报越界就把行号摘掉、留文件名（2026-11-04 清了 17 处），不要按当前
代码去「改对」—— 那等于把历史改写成假的。本表的行数仍是 `wc -l` 实测。

---

## 7. API 面清单（U1）

「我作为应用开发者能用什么」—— `php tools/lint.php --api` 输出 `@api` /
`@internal` 清单（按层分组），快照在 [`API-SURFACE.md`](API-SURFACE.md)
（自动生成，勿手改）。

★ 口径：docblock **独立标注行**。单行 `/** @api … */` 与行内描述性提及
（如「它本身是 `@internal` 的实现细节」）都按规则区分 —— grep 口径两者都算错
（实测：@api 真标注 333 / @internal 241，而 grep 字样是 332 / 253）。
★ 这份快照是 **API 面变更的 diff 基线** —— 拆 `Application`（ARCH-REVIEW §7 的
S1）时重跑 `--api`，与快照的 diff 就是该审的 API 面变化。
