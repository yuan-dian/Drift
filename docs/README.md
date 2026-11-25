# Drift 文档

> 按任务组织的现行知识入口。这里只回答"去哪看"；所有页面只描述现在是什么、怎么做，不记录开发过程。

文档分六区：**开始**建立预期并跑通，**指南**按任务解决具体问题，**概念**解释机制，**参考**供查阅，**裁决**登记现行结论与失效条件，**贡献**说明协作纪律。每个主题一页，一页只做一件事。

## 第一次接触

| 你想做的事 | 去哪 |
|---|---|
| 了解 Drift 是什么、能做什么、边界在哪 | [框架概览](start/overview.md) |
| 装环境、跑起第一个窗口、知道各条命令的用途 | [快速开始](start/quickstart.md) |
| 理解一个应用的五个文件与它们各自的职责 | [应用解剖](start/app-anatomy.md) |

## 用 Drift 开发

| 你想做的事 | 去哪 |
|---|---|
| 组合界面、写有状态控件、理解受控语义 | [Widget 与状态](guides/widgets.md) |
| 排布元素、处理溢出与滚动、写自己的 RenderBox | [布局](guides/layout.md) |
| 写文本、用主题 token、处理字体与中文字符 | [文本与主题](guides/text-and-theme.md) |
| 接键盘、指针、手势、焦点与浮层 | [交互](guides/interaction.md) |
| 用 Subject 做切片订阅、管好页面状态 | [状态与数据](guides/state-and-data.md) |
| 注册命令、让控件事件走冒泡链 | [命令](guides/commands.md) |
| 写后台任务、处理取消、失败与进度 | [异步开发](guides/async.md) |
| 给图片喂本地或网络地址、理解缓存与降级 | [异步资源](guides/async-assets.md) |
| 扩展控件：组合还是自绘、怎么接线 | [自定义控件](guides/custom-widgets.md) |
| 建语义树、接 Windows UIA 无障碍 | [语义与无障碍](guides/semantics.md) |
| 用平台端口、处理多窗口、DPI、IME 与 ABI | [平台与窗口](guides/platform-ports.md) |
| 诊断静默失败、看懂错误码与退化日志 | [调试](guides/debugging.md) |
| 跑 T0/T1/T2、理解 CI 与什么时候必须上真机 | [测试与验证](guides/testing.md) |
| 获取 Skia/字体包、管版本、发版 | [依赖与发布](guides/dependencies-and-release.md) |

## 理解机制

| 你想搞懂 | 去哪 |
|---|---|
| 三棵树、身份、写即标脏、组合根、依赖方向 | [心智模型](concepts/model.md) |
| 一帧的阶段、时钟、事件回贴、跳帧与多窗口 | [帧管线](concepts/frame-pipeline.md) |
| 约束布局、Canvas op 流、三后端、确定性与文本栈 | [渲染模型](concepts/rendering.md) |
| 一帧为什么变成这样、因果怎么记、调试器边界在哪 | [调试模型](concepts/debug-model.md) |
| 记录怎么变成可追问、可复算、可携带的证据；投影与消费者怎么分工；检查器与网络怎么接入 | [证据模型](concepts/evidence-model.md) |
| 调试器还差什么、按什么顺序补、怎么算完成 | [调试器成熟度路线](concepts/debugger-roadmap.md) |

## 查阅

| 主题 | 去哪 |
|---|---|
| AOT 编译器事实、编码纪律与错误码 | [AOT 约束](reference/aot-constraints.md) |
| 日常命令、唯一入口与不要抄的清单 | [命令速查](reference/cli.md) |
| 错误分级、`[DX]` 退化、异步 Failure 码 | [诊断](reference/diagnostics.md) |
| `@api` / `@internal` 口径与公开面审查 | [API 面](reference/api-surface.md) |

## 裁决、协作与示例

- [裁决索引](decisions/README.md)——现行结论、失效条件；新裁决必须登记，失效条件必须写清。
- [环境搭建](contributing/setup.md)与[协作流程](contributing/workflow.md)——工具链、验证纪律、提交与发布。
- [`apps/` 示例总览](../apps/README.md)——示例、教程、样板、bench 四类目录的定位；完整业务闭环看 `apps/watermark`。

## 维护纪律

1. **一页一主题**：新增主题先加导航，再写页面；不写跨主题的大杂烩。
2. **不写行号锚点**：定位用类名、方法名、小节标题。行号每次改代码都会漂。
3. **不手抄会漂的清单**：判据清单只在 `php bin/drift verify --list`，公开面清单只在 `php tools/lint.php --api`。
4. **裁决要写失效条件**：写进[裁决索引](decisions/README.md)时同时回答"什么条件下这条结论需要重评"。
5. **提交前跑文档检查**：`php tools/doccheck.php`。它守链接可达、页面结构、无行号锚点、无旧文档路径、裁决表完整与导航无孤儿页。

历史战报、过程稿与旧版方案不在这套文档里；需要考古时用 git 历史。
