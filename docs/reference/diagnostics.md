# 诊断与错误码

> 说明失败如何分级、静默退化如何变成可观测日志、异步错误码如何读取，以及出问题时的排查顺序。

## 一句话分清三个东西

- `Errors` 是**判决**：决定一次事件是中止程序、只记录、还是报个诊断，Fatal 会中止。
- `Diagnostics` 是**退化播报**：能力没报错但静默变弱时，用它留下一条可检索的痕迹，不中止。
- `Log` 是**轨迹**：按级别记录运行过程，永不中止、永不抛出，即使写 sink 失败也吞掉。

三者不要互相替用：要中止用 `Errors`，要报退化用 `Diagnostics::degraded()`，要留运行轨迹用 `Log`。

## 错误分级

`Errors::report()` 是唯一的判决入口，级别定义在 `Errors`：

| 级别 | 常量 | 行为 |
|---|---|---|
| 致命 | `Errors::FATAL` | 写 `Errors.log`、弹原生错误框、`exit(3)`；不传级别时默认就是它 |
| 可恢复 | `Errors::RECOVERABLE` | dev 中止；release 追加日志，并给当前应用的黑匣子写一条可恢复面包屑（无活跃应用时只有日志） |
| 诊断 | `Errors::DIAGNOSTIC` | 进环形缓冲，并写一条 framework 通道的 warn |

几个要点：

- `Errors::report()` 只传消息时按 Fatal 处理。想记一条轻量的诊断，必须显式传 `Errors::DIAGNOSTIC`。
- Fatal 的退出码固定为 3，环形缓冲有容量上限，栈回溯也有深度上限。
- 默认日志文件是 `getcwd()` 下的 `Errors.log`，可用 `Errors` 提供的路径设置改成别处。因为路径依赖当前工作目录，exe 要以仓库根为 cwd 运行，否则日志落到别的地方。

## 退化日志 `[DX]`

AOT 与运行时的很多退化是静默的：能力没消失，只是走了更慢或更保守的路径，不报错、不中止。这类情况用 `Diagnostics::degraded($key, $message)` 播报：

- 输出统一带 `Diagnostics::PREFIX`，也就是 `[DX] ` 前缀，方便在日志里搜索。
- 按 key 去重，同一个 key 只报一次——同一退化路径每帧触发也不会刷屏。
- key 不能为空，传空串会被当成 Fatal 报出去。
- 级别是 `Errors::DIAGNOSTIC`，所以只进环形缓冲、不中止程序。
- `Diagnostics::reported()` 返回已经报过的 key。

排查时先搜 `Errors.log` 里的 `[DX]`，就能看到本次运行发生过哪些静默退化。判定检查脚本会对这些退化日志做对账，防止加了退化却忘了播报。

## 异步错误码

worker 只能把异常压成 JSON 标量，还原端造不回原来的异常类，错误码就丢在这条边界上。`Failure` 是这段边界的载体，`Failure::codeOf()` 是全链路唯一的提取点。

具名错误码，全部定义在 `Failure`：

| 常量 | 值 | 含义与处置 |
|---|---|---|
| `Failure::CODE_TIMEOUT` | `drift/timeout` | 任务超过自己声明或 Host 兜底的墙钟上限被杀；通常可重试 |
| `Failure::CODE_WORKER_DIED` | `drift/worker-died` | worker 进程崩溃或被外部杀掉，不是任务自身抛错；重试通常有效 |
| `Failure::CODE_HOST_UNAVAILABLE` | `drift/host-unavailable` | 后端起不来或递不进去，例如池满、唤醒窗建不出来；重试可能有用 |
| `Failure::CODE_JOB_REJECTED` | `drift/job-rejected` | 任务不满足后端要求，例如不是 `NamedJob`、参数转不了 JSON、注册表里没有；重试无用，是装配写错 |

读码方式：

- 应用侧读 `AsyncValue::code()` 拿到字符串，或给 `AsyncCommand` 配 `mapError()` 把码映射成界面状态；不需要 `instanceof Failure`。
- `Failure::codeOf()` 把任意 `Throwable` 归一成字符串：`Failure` 实例返回它的 `errorCode()`，普通异常走 PHP 标准的 `getCode()`。
- 空串 `''` 和字符串 `'0'` 视为"没分类"，不是一次分类失败。UI 拿到空串应走通用错误展示，不要当成某个具名码。

区分 `CODE_JOB_REJECTED` 和其余三个很关键：前者是配置错误，界面该画"配置错误"而不是"重试"按钮；后三者是瞬时故障，重试有意义。

## 常见错误码速查

除异步码外，排查时还会遇到几类来自编译、环境与运行时的码：

- `exit(3)`：Fatal 中止的统一退出码，见 `Errors`。
- PHPUnit 缺依赖时 exit 2：`bin/drift test` 在没装 PHPUnit 时返回 2，不算通过。
- AOT 等价检查退出码 0/1/2：分别对应等价、不等价、无法完成。
- C++ 编译码 `C2440` / `C2678` / `C2065` / `C2872` / `C3861` / `C2653` / `C2660` / `C2129`：AOT 侧的类型与签名错误，逐条含义见[AOT 约束与代码纪律](./aot-constraints.md)。

## 排查顺序

出问题按这个顺序收窄，能最快定位到是哪一层：

1. **看退出码。** 3 是 Fatal，2 可能是测试缺依赖或等价检查无法完成。退出码先把范围限定到"中止"还是"没通过"。
2. **翻 `Errors.log` 与 `.drift/blackbox-*.json`。** 确认 cwd 正确、日志落在预期位置。先看 Fatal 的消息，再看有没有 `Errors::RECOVERABLE` 的记录；后者在 release 下还会按应用写进黑匣子的 `recoverables` 面包屑，且不会顶掉 `failure` 现场。
3. **搜 `[DX]`。** 若没有 Fatal 但能力变弱，搜退化前缀，确认是不是走了静默退化路径，以及是哪个 key。
4. **异步链路查 code。** 若错误发生在异步任务里，用 `AsyncValue::code()` 或错误映射拿到字符串码，按上面的具名码表判断是重试类还是配置类。
5. **回到验证分层。** 解释器与 smoke 全绿、只有真机出问题，通常是 AOT 约束那一族（属性读、arity、继承字段 `private`、`?->`、整数除法）；按[命令速查](./cli.md)跑 T2 与相关 lint 复现。

环境类问题（找不到组件、构建失败）先跑 doctor 自查，见[环境搭建](../contributing/setup.md)。

## 相关

- [AOT 约束与代码纪律](./aot-constraints.md)
- [命令速查](./cli.md)
- [协作流程](../contributing/workflow.md)
