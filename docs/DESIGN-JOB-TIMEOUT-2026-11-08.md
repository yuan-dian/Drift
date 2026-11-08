# 设计：超时上限由任务声明（TimedJob），而不是框架猜（2026-11-08）

> 基准：Drift2 `0599f44`（§73）。输入：`docs/PLAN-NEXT-STEPS-2026-11-08.md` §4「§74」、
> `DEBT` §68.7 / §73.4，以及对当前代码的回查（不照抄计划）。
> 本文件是**实现前分析 + 裁决**；落地结果见 §9。
> 纪律来源：`AGENTS.md`、`.workbuddy-ai/memory/MEMORY.md`（变异四律 / T0-T1-T2）、`DEBT §40.1`。

---

## 0. 结论

**计划给的三条路（A 组合根算上限 / B 收窄 HTTP 超时 / C 看门狗或 Job 声明）里，
A 已被反证、B 改变业务能力，C 的「无进度看门狗」经实证也不成立，最终改选
「Job 声明自己的墙钟上限」（下称 D）。**

- **D 的形状**：新增 `TimedJob extends Job`，`timeoutMs()` 由任务自己答；
  `ProcessTaskHost` 在每条任务上记 `limitMs`，`sweepTimeouts()` 按**每条自己的**上限判，
  没声明才回落到 Host 默认（30s）。
- **判死 A**：`WatermarkStore::$items` 来自用户选文件，**没有最大数量上界**
  （全仓无 `MAX_FILES` / `MAX_ITEMS`）⇒ 组合根算不出有界值。
- **判死 C（看门狗）**：`JobProgress` 的节流是「1% 递增才发、无时间维度」，
  而 worker 阻塞在 `run()` 里、发不出独立心跳 ⇒ 大文件慢速上传可能 30s 零包，
  看门狗会**误杀它本来要救的那种任务**（§2）。
- **两条真实受害者，不只一条**：`UploadBatchJob`（单文件 300s）与
  `DownloadResultsJob`（单张 120s）都是「串行批量 + 单条上限 ≫ 30s」，
  在默认配置下**第一条慢文件就会被杀掉**。
- **零破坏**：纯加法（新接口 + 两处 `implements` + Host 内部记账），
  不改任何现有签名语义，也不废掉 30s 兜底。
- **必须 T2**：新增 `@api` 接口 ⇒ `instanceof` 与接口方法分派要在 AOT 下真机验证。

---

## 1. 现状缺口（逐条对代码）

### 1.1 两条同构的静态链

```text
[上传] apps/watermark/bin/win32/Bootstrap.php:82  只传 2 参
       ⇒ ProcessTaskHost::requestTerminateMs = 30_000
       Config::UPLOAD_TIMEOUT_S = 300.0            ⇒ 单文件 HTTP 允许 300s
       UploadBatchJob 串行传 N 张
       ⇒ 第 1 张传超 30s 就 sweepTimeouts() 杀 worker
       ⇒ 界面「后台任务超时」，而 worker 侧 HTTP 还在等

[下载] DownloadResultsJob::fetchOne() 单张 Http::get(timeout: 120.0)
       DownloadResultsJob 串行下 N 张
       ⇒ 第 1 张超 30s 同样被杀
```

`apps/watermark/src/Api/Config.php:75` 的 `@design-param` 自己写着
「口径待对齐（§68，当前零判据）……真机没量过」——它缺的不是测量，是**裁决**。

### 1.2 计划路 A 为什么不可执行

`WatermarkStore::$items` 由用户选文件产生，`\count($items)` 只用于文案，
**没有任何上界**。⇒ 按「批大小 × 单文件上限 + 余量」在组合根算出的值同样没有上界；
按保守值算又会误杀大批上传。**不是"没算好"，是这条路没有解。**

### 1.3 计划路 C 里的「无进度看门狗」也不成立（新证据）

看门狗的前提是「任务活着就会周期性发出某种信号」。本仓的实际形状：

| 环节 | 事实 |
|---|---|
| `JobProgress::report()` | `$f <= $last` 直接 return；`$f < 1.0 && 增量 < 1%` 也 return（`MIN_STEP`） |
| 节流维度 | **只有值维度，没有时间维度** —— 进度不涨就一个包都不发 |
| worker 侧 | 阻塞在 `$job->run()`（`fgets` 主循环之外），**没有事件循环**，发不出独立心跳 |
| 慢速大文件 | 1 GB @ 100 KB/s，每 30s 只涨 0.3% ⇒ **30s 内零包** ⇒ 看门狗误杀 |

⇒ 看门狗要成立，得先改 `JobProgress` 的节流加时间放行，再让 `Future::reportProgress`
接受同值重发（它现在也吞同值）。那会污染「进度」的语义，把心跳塞进进度通道 ——
用错通道解决错问题。**否决。**

---

## 2. 成熟框架的旁证（为什么"任务声明"是主流）

| 框架 / 系统 | 上限由谁定 | 本仓可借 |
|---|---|---|
| **PHP-FPM** `request_terminate_timeout` | 运维按**进程**定，不区分任务 | 反例：粒度太粗，正是本仓现在的形状 |
| **Celery** `time_limit` / `soft_time_limit` | **任务类上声明**，可按任务覆盖全局 | ★ 与本仓 `TimedJob` 同构 |
| **gRPC** deadline | **调用方**随请求传，逐调用透传 | 声明方 = 最懂耗时的那一侧 |
| **Kubernetes** `activeDeadlineSeconds` | **Job 资源上声明** | ★ 同上 |
| **.NET** `CancellationTokenSource.CancelAfter` | **调用方（业务）**决定，框架不猜 | 同上 |
| **systemd** `TimeoutStartSec` + `WatchdogSec` | 单元文件声明；watchdog 需应用主动 `sd_notify` | 看门狗需要**独立心跳通道**，本仓没有 |
| **Chrome** hung-renderer | 消息泵无响应检测 | 同上，需要泵探针 |

**一致的结论**：上限要么由**最懂耗时的声明方**给出（Celery / K8s / gRPC / .NET），
要么依赖**独立心跳通道**（systemd / Chrome）。本仓两条都不满足后者 ⇒ 选前者。

---

## 3. 四方案五维比较与裁决

| 方案 | 结构 | 实现 | 扩展性 | DX | 维护性 | 裁决 |
|---|---|---|---|---|---|---|
| **A 组合根按批算** | ± | + 零框架 | − 无上界不可算 | − 换成组合根要记住 | − 每个 app 抄一遍 | **否**（§1.2 反证） |
| **B 收窄 `UPLOAD_TIMEOUT_S` ≤ 30s** | ± | + 一个常量 | − 削业务能力 | − 大图传不完变成"正常行为" | ± 两个数字自洽了 | **否**（改变交付能力） |
| **C 无进度看门狗** | + 语义好 | − 要改 3 个文件 | ± | − 慢速大文件被误杀 | − 心跳语义塞进进度通道 | **否**（§1.3 反证） |
| **D `TimedJob` 任务声明** ★ | **++** 单一事实源：谁耗时谁声明 | **++** 1 接口 + 2 implements + Host 3 处记账 | **++** 第二个批量任务只需 `implements` | **++** 忘了声明仍有 30s 兜底；声明了就精确 | **++** 上限与业务参数同文件，不会漂 | **推荐** |

**五维逐条理由**

- **架构**：不引入第二套超时簿记，仍由 `ProcessTaskHost` 单点判定；
  声明只提供**数值**，判定权仍在 Host（守住单一事实源）。
- **实现**：`src/App/TimedJob.php`（新 `@api` 接口）；`ProcessTaskHost` 的
  `queue` / `inflight` 各多一个 `limitMs` 字段；`sweepTimeouts()` 一行改判据。
  两处业务 `implements TimedJob` + 一个方法。**零 AOT 敏感形状**（无 `mixed`、无可空对象、
  无闭包参数个数变化），但新增接口 ⇒ 仍需 T2 验证接口分派。
- **扩展性**：将来任何"批量串行 / 单条耗时远大于默认"的任务，只需 `implements` 并答一个数；
  不改 Host、不改常量。
- **DX**：漏声明不会更糟（30s 兜底仍在）；声明后错误信息能说清「超过**本任务**上限」，
  而不是一个跟业务无关的 30s。开发者不需要记「哪些任务需要去组合根改参数」。
- **维护性**：上限表达式与批大小写在同一个类里（`count($items) * 单条上限 + 余量`），
  改业务参数时一眼看到；`Config` 的 `@design-param` 从「待对齐」改成「已对齐」并指明判据。

---

## 4. 实施设计

### 4.1 新接口

```php
/**
 * 任务自己声明墙钟上限（毫秒）—— 与 Celery `time_limit` 同形。
 *
 * ★ 为什么声明在任务上：只有它知道批大小与单条耗时上限；
 *   Host 只看到一条 JSON 行，猜不出来（见 §1.2）。
 * ★ `0` = 不声明，回落到 `ProcessTaskHost` 的默认上限（30s），不是"不限"。
 */
interface TimedJob extends Job
{
    public function timeoutMs(): int;
}
```

### 4.2 Host 记账

- `submit()`：`$limitMs = $job instanceof TimedJob ? \max(0, $job->timeoutMs()) : 0;`，
  存进 `$queue` 项。
- `send()`：把 `limitMs` 一并写进 `$inflight`。
- `sweepTimeouts()`：`$limitMs = $item['limitMs'] > 0 ? $item['limitMs'] : $this->requestTerminateMs;`；
  `limitMs === 0 && requestTerminateMs === 0` ⇒ 不清扫。
- 错误信息带上实际生效的上限值（不是恒报 `requestTerminateMs`）。

`requestTerminateMs` 的 docblock 语义收窄为「**未声明任务的兜底**」，名字与 getter 不变
（零破坏；"terminate" 仍准确）。

### 4.3 两处业务

```php
// UploadBatchJob
public function timeoutMs(): int
{
    return \count($this->items) * (int) (Config::UPLOAD_TIMEOUT_S * 1000) + self::TIMEOUT_MARGIN_MS;
}

// DownloadResultsJob
public function timeoutMs(): int
{
    return \count($this->urls) * (int) (self::FETCH_TIMEOUT_S * 1000) + self::TIMEOUT_MARGIN_MS;
}
```

`TIMEOUT_MARGIN_MS`（10_000）给 worker 启动与收尾；`FETCH_TIMEOUT_S = 120.0` 是把
`fetchOne()` 里写死的 `'timeout' => 120.0` 提成常量（同一数字只写一处）。

### 4.4 明确不做

- 不加「总上限 / lifetime」第二个参数：当前无消费者，且 30s 兜底 + 任务声明已覆盖
  两种故障（卡死、批量耗时）。等出现「声明了上限却仍然无限跑」的真实需求再谈（V4）。
- 不改 `JobProgress` 节流（§1.3）。
- 不做协作式取消（curl 中途中断）：那是 deadline 传播的另一件事，无消费者。

---

## 5. 判死条件与变异清单

**判据必须写死期望值，不从常量推导**（§68 踩过「把 `POLL_MAX_ROUNDS` 改 99999
判据跟着放大仍全绿」）。

```text
P1 声明生效：requestTerminateMs=100、Job 声明 timeoutMs=2000、任务跑 ~600ms
   ⇒ 必须**成功**（不超时）。
P2 兜底未废：requestTerminateMs=100、Job **不声明**、任务跑 ~600ms
   ⇒ 必须**超时**（证明 P1 的成功来自声明，不是清扫被关掉）。
P3 声明 0 回落：requestTerminateMs=100、Job 声明 0、任务跑 ~600ms ⇒ 同 P2。
P4 真实业务声明值：UploadBatchJob(items=3)->timeoutMs() 必须 > 300_000；
   DownloadResultsJob(urls=3)->timeoutMs() 必须 > 120_000。写死下界，不写等式。
```

```text
M1  send() 不把 limitMs 写进 inflight          ⇒ P1 必须红（回落 100ms 被杀）
M2  sweepTimeouts() 忽略 item['limitMs']        ⇒ P1 必须红
M3  UploadBatchJob 去掉 implements TimedJob     ⇒ P4 必须红（同时 P1 那组替身不受影响）
M4  timeoutMs() 恒返回 0                        ⇒ P1 必须红、P2 仍绿（证明两闸独立）
基线 全部恢复 ⇒ P1–P4 全绿
```

`M4` 是最关键的一条：它证明「声明」真的参与了判定，而不是恰好被别的东西救活。
每条变异先 `php -l`、串行改同一文件、`finally` 恢复。

---

## 6. 验证分层

- **T0**：`php -l` 所有改动 PHP；`py_compile` 变异脚本。
- **T1**：`--selftest`、`lint.php`（新接口需过 `select-purity` 与 `@api` 面检查）、
  单测、`gate.php --fast`、`gate.php` 18/18、`docref`、`api-doc-check.php --write`。
- **T2（强制）**：新增 `@api` 接口 ⇒ `drift-build watermark --full`（约 310s）
  + `probe-window.py watermark`（5 判据 0 FAIL）。
  重点验证 `instanceof TimedJob` 与接口方法分派在 AOT 下不 Fatal（§67 的覆盖签名坑）。

---

## 7. 退出条件

基线全绿 → P1–P4 绿 → M1–M4 逐条真红 → 恢复绿 → gate 18/18 → T2 真机 5/5 →
`Config` 的 `@design-param` 从「待对齐」改成「已裁决」→ DEBT 章节 + 记忆 + 提交。

---

## 8. 与 §77（B4 EOF 双判）的关系：判死

计划把 §77 与本棒合并（同文件同 T2）。本棒实测了合并的前提：

```text
build/probe-eof-timing.php：worker 读一行后直接 exit（不写回复）
6 轮读数：eof 比 proc_get_status()['running']=false 早 0.01–0.02ms（同一轮询内）
```

⇒ 管道 EOF **没有**比 `running` 更早提供信息；`readReplies()` 与 `reap()` 又同在
一次 `drain()` 里，同一帧结算。**找不到能让「只判 running」变红、而「running ∪ EOF」变绿
的真实场景** ⇒ 按 V2 判死，不实现。

**但保留一件零风险的事**：`readReplies()` 里 `$chunk === ''` 处的注释说
「有任务在跑时管道 EOF 已被 readReplies() 当场发现」，与实现（只 `continue`）不符 ——
这是**注释腐烂**，本棒顺手订正，避免下一棒照着它去实现一个不存在的机制。

---

## 9. 落地纪要

- **新接口**：`src/App/TimedJob.php`（`@api`，`TimedJob extends Job`）。
- **Host**：`submit()` 算 `limitMs` → `queue` → `inflight`；`sweepTimeouts()` 逐条取
  `limitMs > 0 ? limitMs : requestTerminateMs`；`requestTerminateMs` 的 docblock、
  构造器 `@param`、getter 注释统一收窄为「**未声明**任务的兜底」。
- **业务**：`UploadBatchJob` / `DownloadResultsJob` 各 `implements TimedJob` + 一个
  `timeoutMs()`；后者把 `fetchOne()` 里写死的 `120.0` 提成 `FETCH_TIMEOUT_S`。
  `Config::UPLOAD_TIMEOUT_S` 的 `@design-param` 从「待对齐」改为「已裁决」。
- **实测**：unit **591 tests / 1819 assertions**、feature **896 tests / 3478 assertions**、
  lint **1051 文件 / 0 error / 0 warn**、docref **136 ok / 0 FAIL**、`apidoc` 一致、
  gate **18/18（150.7s）**。
- **变异 4/4 真红**：M1 `send()` 不写 `limitMs`、M2 `sweepTimeouts()` 忽略
  `item['limitMs']`、M3 去掉 `implements TimedJob`、M4 声明值恒 0；恢复后 unit / feature 双绿。
- **T2**：`watermark.exe` **13.9 MB / 62.4s**；探针 **5/5 判据 0 FAIL**。
- **§77**：EOF 与 `running=false` 同一轮询内到达（0.01–0.02ms）⇒ 按 V2 判死，
  只订正两处「EOF 已被当场发现」的腐烂注释。

---

## 10. 诚实边界

- 本棒修的是「上限口径不一致」，**不是**「用户已经遇到超时」：未跑真机上传 / 下载，
  不能宣称线上症状。
- `TimedJob` 只解决「上限该多大」，**不解决**「任务真的卡死时怎么优雅取消」——
  仍然是杀进程 + 判失败，不是协作式取消。
- 上限表达式依赖「单条耗时上限」这个常量与 HTTP 侧实际一致；若将来某处 HTTP
  超时被改大而 `timeoutMs()` 没跟上，仍会漂。判据能抓「Host 不认声明」，
  抓不住「业务常量与表达式不同步」。
- D 方案假设「批量串行」是刻意形状（`DEBT` 并发假设）。若将来改成并行批处理，
  上限表达式要重算，不能照抄 `count × 单条`。
