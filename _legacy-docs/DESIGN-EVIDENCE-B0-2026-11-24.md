# B0 设计：先修"会读错"的六处，不动能力面

> 对象：`src/Debug/`、`tools/Debug/`。前提是 A47/A48 与 `evidence-model.md` 的分期（S0 后置）。
> 本文只记"为什么选这个做法"，不记"做了什么"——后者在 CHANGELOG 与代码里。

## 一、排序：按「错误代价 × 触发概率」，不按我上一轮的 P0/P1

上一轮按性价比排，漏了一个维度：**有的缺陷不报错、只给出可读的错误答案**。这类比崩溃贵得多，因为没人去查。

| 缺陷 | 错误代价 | 触发概率 | 处置 |
|---|---|---|---|
| 孤立因果边（`toId ∉ rebuilt`） | 高：两个计数对不上，无解释 | 中：元素在 setState 后、帧前卸载 | 本轮，用**不变量**消灭 |
| `shapeCache` 帧号重用 | 高：整棵树是拼的 | 低（当前只有测试调 reset） | 本轮，用**版本戳**消灭 |
| `frames(since)` 不报丢帧 | 高：把"漏了 300 帧"读成"没重建" | 中：面板长开 | 本轮，**信封化** |
| 默认值双写（`match` 与 `Query`） | 中：静默漂到两套默认 | 高：每加一个查询就多一处 | 本轮，**规格表** |
| `Finding` 无版本 / 阈值不可配 | 中：发现不可比对、不可调 | 高（换项目即触发） | 本轮，值对象 |
| `Digest` 三指纹合并 | 高：归错因 | 只在做回归比对时 | **B1**，属证据包格式 |

## 二、四个设计选择

### 1. `frames(since)` 改成信封，不做"新增方法保留旧的"

备选：① 保持 `list`，把 window 塞进每个元素（丑，且 N 份重复）；② 新增 `framesPage()`，旧方法保留（两套形状，消费者还要学第二种拼法）；③ 改信封 `{frames, window, dropped}`。

选 ③。理由：`list` 在类型上就表达不了"你漏了 N 帧"，而这是方案第 267 行那条硬规则（证据必须自报裁剪）。**形状错了，靠约定补不回来。** 消费者只有三个（`Panel::refreshFrames`、`Brief` 的 `pumpFrames`、`debug-report.php`），同步改的成本远低于长期维护两套形状。

`dropped = max(0, window.from - since - 1)`：`window.from` 是缓冲里最老的一帧，`since` 是调用方最后读到的。中间那段是真丢了，不是没重建。

### 2. 缓存失效用「代际戳」，不用回调

备选：① `Recorder::reset()` 通知 `Query`（引入 Devtools→Query 的反向依赖，而 Query 本来就依赖 Recorder）；② `Query` 缓存键带 `Recorder` 的代际号；③ `DebugSession` 重建 `Query`（但 `recorder()->reset()` 是公开的，绕过 session 的路径管不到）。

选 ②。`Recorder` 加一个 `generation`（每次 `reset()` 自增），`shapeCache` 的键改成 `"$generation:$frameId"`，并且代际变化时整体丢弃。这与 **A41 的 `debugSession` 戳同源**——那条裁决解决的正是"帧号/节点号重用"，这里是它的第二处应用。判据可以直接写：`reset()` 后查 reset 前的帧号必须 miss。

### 3. 孤立边：过滤 + 计数，**不是**标记 incomplete（⚠ 实施时被实测推翻，见 §7）

我上一轮建议标 `incomplete: ['orphan-cause']`，现在改主意，理由是**标记会被滥用**：

孤立边描述的不是"这一帧的证据不全"，而是"这条边不属于这一帧"——元素在 `setState` 之后、下一帧 settle 之前被卸载了，那个节点这一帧根本没重建。把它标成 incomplete，会让每个有卸载的帧都"不完整"；而"不完整"这个标记一旦遍地都是，读者就会学会忽略它（`causes-truncated` 和 `aborted` 的警示价值随之归零）。

更好的做法是**用不变量消灭不一致**：`endFrame()` 只写入 `toId` 在 `openRebuilt` 里的边，把丢掉的条数记成 `FrameRecord::$orphanCauses`（标量）。于是：

```
causes.toId ⊆ rebuilt          —— 恒成立，可写成判据
orphanCauses                   —— 可观测，不为零就说明这一帧有卸载
```

这比"写完再解释为什么不齐"强一档：解释永远可能被漏读，不变量不会。

代价：`FrameRecord` 构造多一个参数（给默认值 0，构造兼容）。

### 4. 阈值用值对象，不用四个参数、不用配置数组

备选：① `project($facts, $budget, $width, $slowMs, $fanout, $repeat, $reshapePx, ...)`（签名爆炸）；② 从环境变量 / 文件读（读的位置又多一处，且无法按调用方区分）；③ `Thresholds` readonly 值对象，默认 `Thresholds::default()`。

选 ③。三条理由：**默认值只有一份**（现在常量在 `Brief`，将来 S0 导出证据包时它要进 meta，两处就漂）；**可按调用方区分**（CLI 传 `--slow=20`、Web 传另一组）；**可序列化**（进证据包的 `generated` 段，让"这份发现是按什么阈值算的"可复算）。

`Brief::findings($facts, ?Thresholds $t = null)`、`project($facts, $budget, $width, ?Thresholds $t = null)`——可选参数，不破坏现有调用。

### 5. 查询分发用规格表，消灭默认值双写

现状：`Query::frames(int $limit = 30, ...)` 与 `DebugSession::query()` 里的 `self::intArg($name, $args, 'limit', 30)` **各写一遍**。20 个查询、约 60 个默认值，全是双写。改一处漏一处，得到的是"CLI 默认 30、面板默认 20"这种漂移，而两边都不报错。

备选：① 保持（会漂）；② 反射读 `Query` 方法的默认值（AOT 风险，且 `query()` 在 `DebugSession` 里也要能编译）；③ 规格表 `QUERY_SPECS`，一处定义类型 + 默认值，通用解析器按表取参。

选 ③：

```php
private const array QUERY_SPECS = [
    'frames'    => ['limit' => 'int:30', 'rebuiltOnly' => 'bool:false', 'since' => 'int?'],
    'explain'   => ['frame' => 'int!'],
    ...
];
```

`int:` 带默认、`int!` 必填、`int?` 可空、`bool:` 带默认。解析器一处实现，`match` 只留"怎么调"。

新增一个查询从"改三处（方法签名、match 参数解析、文档）"变成"改两处（`Query` 方法、规格表一行）"，且默认值只有一份。

风险：`match` 里已有 20 个分支，重写时容易漏。判据是**逐个查询的参数等价性**：对每个 spec 造一个"只给必填参"的调用，断言与旧行为一致（默认值 / 可空 / 必填报错）。

## 三、B1 证据包：先定形状，不实现

选单文件 JSON（零依赖；可 diff；`--gzip` 可压），外层带 `schema` + `version` + `generator`。

```
{ "schema": "drift-evidence", "version": 1,
  "drive":  { "script": {...}, "digest": "..." },
  "facts":  { "frames": [...], "nodes": [...], "geometry": [...],
              "network": [...], "bodies": [...], "props": [...] },
  "digest": { "drive": "...", "facts": "...", "external": "..." },
  "generated": { "at": "...", "thresholds": {...} } }
```

三个关键决定：

**导入 = `Recorder::hydrate(array): self`，返回游离实例。** 不装进 `Recorder::$current`（那会劫持后续帧、打开量具）。`Query` 本来就收实例（`new Query($recorder)`），所以"导入后查询与原始逐值相同"**自动成立**，不需要另写一套离线查询。`props()` / `pick()` 依然需要活树，离线时自然返回 `reflection-unavailable` / `no-roots`——这不是缺陷，是事实。

**`digest(External)` 的 URL 必须用脱敏模板。** 签名参数（`?sig=…&ts=…`）每跑一次都不同，直接进指纹会让它恒红；A38 已经为此把 URL 从因果边里赶出去一次。External 只覆盖"method + 脱敏后的 URL 模板 + 顺序 + 输入事件序列"。

**版本不认识就抛，不降级。** 降级会交出一份半证据，而读者无法分辨"这份证据里没有网络域"和"这个版本不写网络域"。这与"窗口外必须自报"是同一条纪律。

## 四、架构层：三个替代方案，为什么现在不做

| 替代 | 好处 | 代价 | 结论 |
|---|---|---|---|
| 控件层发结构化事实到 Core 事件总线，Debug 订阅 | 消除 `Debug ↔ Widget` 环 | 每帧每节点分配事件对象；热路径不可接受，除非零分配 | 不做。环已有意登记（`DOCUMENTED_CYCLES`），给出重开条件：控件层能零分配发事实 |
| 采集做成可插拔 sink（Null / 标量 / 全量） | 正好对应架构图的"黑匣子 / 深检"两档 | 现在只有一档；静态判空就是 Null 档的退化形式 | S2 再做。B1 的 `hydrate()` 会先逼出"Recorder 不必是单例"的形状 |
| 查询改成预聚合 | 大窗口下更快 | 预聚合要定窗口 ⇒ 第二份真相；违背"消费可以贵" | 不做 |

第一条的取舍值得说清：**环不是疏忽，是记账**。诊断层必须读 `Element`/`State`，而控件层必须在两个漏斗里通知它——只要后者是"静态调用 + 判空"，环就消不掉。真正的解法是让控件层只向 Core 发一个**结构化事实**（不是对象），那时边降为单向。这个条件现在不成立（会引入每帧分配），所以登记而不是消灭。

## 五、DX：三个抓手

1. **`bin/drift debug <verb>`**：现在 `debug` 只转发 `debug-report.php`，其余七个入口要记脚本名。改成子命令分发（`brief` / `panel` / `network` / `node` / `pick` / `props` / `report` / `host`），旧脚本保留一个周期做薄转发。
2. **`--json` 全覆盖**：`brief` / `network` / `node` 已有，`panel` 与 `pick` 没有。机器消费者要的是同一个开关，不是每个子命令记一个。
3. **错误信息带下一步**：`geometryMiss` 已经分了三种 miss，但 CLI 只印 reason 代码。转成人话（"`PageShell` 是复合控件，永远没有自己的盒；查有盒的子节点 21"）比多一个字段有用。

## 六、本轮落点

B0 的六项 + 规格表 + `debug` 子命令。B1 只定形状，不动代码。

## 七、实施结果（2026-11-24）

六项全部落地，判据 167 → 169（调试链），`verify --full` 17 项全过。但**两条设计被实施推翻**，记下来避免后人照着错的走。

### 被推翻 1：孤立边的"过滤"是错的

§2.3 主张用不变量 `causes.toId ⊆ rebuilt` 消灭计数不一致，并按此改了 `Recorder::endFrame()`（过滤掉目标未重建的 `state` 边，并计 `orphanCauses`）。

`NetworkTest::testSettledRequestAttributesLandedRebuild` 立刻咬红：手工构造的帧里 `setState` 没走 settle（元素在 settle 前被卸载），那条边是**合法的未消费写入**，不是脏数据。过滤掉它等于把"有人写了但没人消费"这件事从证据里删掉——而它恰恰是"为什么这一帧什么都没变"的答案。

改法：**不变量方向反了**。正确的不变量不是 `causes ⊆ rebuilt`，而是"两处读同一个源"。`cascade().writes` 改为从 `causes` 取（与 `explain().byKind.state` 同源），每条加 `rebuilt: bool` 标明有没有落地。代码注释里落成一句：**写入 ≠ 重建**。

教训：把"两个数字对不上"当成"数据脏了"，第一反应是过滤；但这两个数字本来就不该相等——口径不同不是错误。

### 被推翻 2：版本表的键名是我凭记忆写的

`Finding::RULE_VERSIONS` 按设计文档里的简写登记（`slow` / `stage` / `group` / `repeat` / `reshape` / `geometry-gap`），而 `Brief` 实际产出的是 `slow-frame` / `stage-dominance` / `unvirtualized-group` / `repeat-rebuild` / `constraint-widened` / `constraint-tightened` / `geometry-inconclusive`——**九个键全部对不上**，`version()` 对所有真实发现返回 0。

"版本"这一层看起来在、实际从未生效，且不报错、不告警。补判据时才发现：判据要求"每条产出的规则 `version() > 0`"并做双向闭合（产出侧 ⊆ 登记表、登记表 ⊆ 产出侧），一跑就红。

这条的价值不在修复本身，而在证明**双向闭合判据**能抓住"表在、语义不在"这一类。单向检查（"登记表里的都 ≥ 1"）会全绿。

### 其他落地项

| 项 | 落点 | 判据 |
|---|---|---|
| `frames` 信封 | `Query::frames()` 返回 `{frames, window, dropped, since}`；三个消费者改读 `['frames']` | `QueryTest` 两条：`window` 原样回显、`since` 之后 `dropped` 精确 |
| 代际戳 | `Recorder::generation()` 自增于 `reset()`；`Query::$cacheGeneration` 守卫骨架缓存 | `QueryTest::testSkeletonCacheDoesNotSurviveAReset`：reset 后同号帧 `rebuilt=0` 且 `roots=[]`（去掉守卫即得 `roots=["QueryProbe×1"]`，已实测会咬） |
| 统一口径 | `cascade().writes` 从 `causes` 取 + `rebuilt` 布尔 | `NetworkTest` 原有那条即为反例守卫 |
| `Thresholds` | `final readonly class`，8 项，`default()` / `with()` / `toArray()`；`Brief` 19 处常量改成 `$t->xxx` | `BriefTest::testThresholdsAreRecomputableNotHardcoded`：改一个数换一组结论，`with()` 与构造等价，拼错的键忽略 |
| 规则版本 | `Finding::RULE_VERSIONS` + `version()` + `toArray()['version']` | `BriefTest::testEveryRuleInUseIsRegisteredWithAVersion`：四组事实触发全部 10 条规则，双向闭合 |
| 规格表 | `DebugSession::QUERY_SPECS`（公开）：参数名 / 类型 / 缺省 / 注入；`query()` 由 `match` 改为表驱动 | `DebugSessionTest`：`QUERIES` 与 `QUERY_SPECS` 双向闭合 + 反射逐参比对签名（缺省值改错立刻红） |
| `debug` 子命令 | `bin/drift debug <verb>`，八出口；省略 verb 等同 `report`（旧写法继续工作）；`<verb> --help` 从脚本 docblock 取用法 | 手工验证三条路径（列表 / 退化 / 分发） |

顺带修掉一处**协议面与形参名不一致**：`Query::explain(int $frameId)` / `why(int $nodeId)` / `cascade(int $frameId)` 的形参名与 JSON 键（`frame` / `node`）不同，规格表就得配一张别名映射，而别名表本身会漂。统一后「协议键 = 规格键 = 形参名」，反射判据才能逐名核对。

### 还没做

- `bin/drift debug <verb>` 的**判据**：目前只有手工验证三条路径（列表 / 退化 / 分发）。要进 CI 得先让八个脚本支持 `--json`（`panel` / `pick` 还没有），否则只能断言"退出码 0"，而那是假绿。

一条被撤回的怀疑：我一度以为 `report` / `brief` 对未知参数报错却 `exit 0`，据此写进待办。实测是 `eval` 时读了管道（`| head`）的退出码，脚本本身是 `exit(2)`，`bin/drift` 也如实透传。**量具读错比被测对象出错更常见**——那条待办已删。
