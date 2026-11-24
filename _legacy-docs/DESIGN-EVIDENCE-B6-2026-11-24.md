# B6：把驱动词表收成一份 schema（写代码前的证伪与裁决）

上一轮我说「驱动词表现在是第五份（`brief`/`network`/`serve`/`web`/`loop`），收成一个解析器是最好的时机」。
动手前先量了一遍，这句话错在两处：**份数不对**，而且问题不是「重复」这么温和。

## 一、实测：三件与想当然相反的事

### 1.1 不是五份，是十份

`tools/debug-*.php` 里含 `frames/rows/hold/at/view-width/height/page/key/text/click/wheel`
这组参数的出口有 **10 个**：`brief` / `network` / `serve` / `web` / `loop` / `node` /
`pick` / `props` / `report` / `panel`。另外 `ctl` 只用到 `frames`，`host` 只用到 `remote`。

我上一轮只数了最近改过的五个，这是抽样偏差——最近改过的出口恰好是常用出口，
而「词表」这种东西的全部代价恰在不常用的那几个里。

### 1.2 `--width` 已经有两套含义

| 出口 | `--width` 的含义 | 视口宽度怎么写 |
|---|---|---|
| `brief` | **排版宽度**（文本行数，默认 100） | `--view-width`（默认 800） |
| `web` / `loop` / `serve` / `node` / `pick` / `props` / `network` | 不认识这个参数 | `--view-width` |
| `panel` / `report` | **视口宽度**（默认 800） | 没有别的写法 |

同一条命令从 `panel --width=1200` 抄到 `brief --width=1200`，得到的是「视口还是 800、
文本每行 1200 列」——报告不会报错，只是行数少得莫名其妙。这不是维护成本，是**已经发生的分歧**。

它之所以没被测试咬住，是因为没有任何一条判据跨出口比过「同一个词是不是同一个意思」。
每个出口的测试都只验自己。

### 1.3 `--from` 的互斥，三处写了两个版本

```
brief  --from=包 --at=2  → exit 0（--at 传给查询侧，选包里的第 2 帧）
network --from=包 --at=2 → exit 2「--from 与 ... --at ... 互斥」
web    --from=包 --at=2  → exit 0
```

读 `Source::resolve()` 就明白 `brief` 是对的：`--at` 是**查询侧的帧选择**，不改驱动脚本，
包里当然可以选帧。`network` 那条互斥是抄 `brief` 时多抄了一个词，而它自己的测试没覆盖这个组合。

这三处互斥还有个共同结构：都写在出口的 try 块里、都用同一句中文、都靠 `$framesGiven`
这种只有本地变量知道的状态。也就是说：**校验的位置决定了它的作用域**，挪不动、也测不到。

### 1.4 非法数值被静默改写

`--frames=abc` / `--rows=-5` / `--hold=xyz` 在全部 10 个出口里都走 `(int)` + `max()`，
结果是 `1` / `0` / `1`，没有一句提示。10 份 clamp 各自写死阈值，任何一份改了，
「同一份命令行参数在不同出口跑出不同脚本」就成立，而且没有任何一份证据会指出改在哪里。

## 二、方案比较

| 方案 | 做法 | 裁决 |
|---|---|---|
| A. 通用 argv 解析器，每个出口自己声明所有选项 | 引入 schema，但驱动词表仍在 10 处各声明一遍 | **否决**。只是把 `if-else` 换成数组，重复没消失，只是更难读 |
| B. 解析两遍：出口特有参数先剔掉，再喂共享解析器 | 两个解析器、两套错误格式 | **否决**。同名参数仍可各自解释，`--width` 这类分裂照样发生 |
| C. 一份共享 schema fragment + 一次解析 + 中间表示 | 驱动词表只有一处声明；出口只声明**特有**选项；解析结果直接能产出 `Script` | **采用** |
| D. 每个出口重建成 Command 类 | 统一 `execute(): int` | **本轮不做**。改动面与 CLI 动词注册机制重合，会盖住本轮要修的语义问题 |

### 2.1 为什么选 C，而不只是「提取一个 parse 函数」

一个函数只能统一**解析**，统一不了**含义**。要修 §1.2 必须让「同名选项不能被两个地方各定义一次」成为
类型层面的约束：schema 合并时遇到重名（或重别名）直接抛错。这样下次谁想在 `brief` 里重新定义
`--width`，第一件事就是解析失败，而不是又造出一个只在某个出口成立的解释。

借鉴的地方：

- **pflag / cobra 的 FlagSet 组合**：一组 flag 可以被多个命令 `AddFlagSet`，命令只补自己的。
- **clap 的 `conflicts_with`**：互斥是声明，不是散落在各出口 try 块里的判断。
- **argparse 的 `help` 从 schema 生成**：选项清单与解析规则不可能写歪——它们本来就是同一份数据。

### 2.2 为什么解析结果要直接产出 `Script`

现在 10 个出口里有 8 处重复同一段组装：

```php
$meta = $rows > 0 ? ['rows' => $rows] : [];
if ($page !== null) { $meta['page'] = 1; }
$drive = Plan::build($label, $width, $height, $meta, $frames, $hold, $steps);
```

这不是「几行样板」。`$meta` 是脚本指纹的一部分：一段漏了，跨出口比 digest 就会差。
把组装放进 `Parsed::script()`，好处不是少写代码，而是**「驱动参数 → 脚本」这条路径只有一条可测**。

## 三、裁决

1. **共享词表只有一份声明**，含 11 个驱动选项（`page/frames/rows/hold/at/view-width/height/key/text/click/wheel/resolve`）
   与两个通用开关（`json/remote`）。出口通过 `OptionSet::for(name, extras)` 补充特有选项。
   同名或别名冲突直接抛错。
2. **`--width` 统一为视口宽度**，`--view-width` 保留为别名；`brief` 的排版宽度改名 `--text-width`。
   这是本轮唯一的破坏性兼容：旧 `brief --width=N` 不再改排版，但十处写法从此只有一个意思。
3. **`--at` 不属于驱动词表的互斥项**，它选的是查询侧的帧。统一后 `network --from=包 --at=2` 与
   `brief` 同行为。互斥声明在 schema 里，只有一处。
4. **数值非法不再静默改写**：`--frames=abc` 报明确错误（含参数名与实际值）。范围 clamp 保留
   （`--rows=-5` → 0），因为它是刻意的归一化；但「不能解析成数」与「超出范围」要分开。
5. **`--help` 从 schema 生成**，所有出口自动获得；输出的就是它实际接受的参数，不需要手抄用法。
6. **出口特有校验仍留在出口**，但表达成 `Parsed` 上的判断（例如 `loop` 拒绝 `remote`、
   `panel` 拒绝 `save`），不回到 `foreach ($argv)` 里。

## 四、判据（写实现之前定）

| # | 判据 | 怎么咬 |
|---|---|---|
| 1 | 10 个出口对同一份驱动参数产出同一份脚本指纹 | 直接对 `Parsed::script()` 做跨出口逐值比对；`--out` 三出口再比一次真包 |
| 2 | 同名/别名重复声明必抛错 | 变异：给某出口补一个重定义 `--width` 的 schema |
| 3 | `--width` 在 `brief` 改视口、`--text-width` 改排版 | 同一组参数下两者分别改动不同的量 |
| 4 | `--from --at` 在 `brief`/`network`/`web` 行为一致 | 三个出口子进程同参数，退出码与输出一致 |
| 5 | 非法数值报明确错误，合法范围仍 clamp | `--frames=abc` 拒、`--rows=-5` 归零 |
| 6 | 未知参数错误列出该出口实际接受的选项 | 错误信息包含 schema 里的选项名，且不含别的出口特有选项 |
| 7 | `--help` 与实际接受集合一致 | 把 help 里的选项名与解析结果比对 |
| 8 | 变异：删掉某出口的 `--click` 声明后，判据 1 变红 | 故意漏一个共享选项 → 解析失败 |

## 五、边界

- 不碰 `bin/drift debug <verb>` 的分派表——那层没问题，本轮只换每个出口内部的参数入口。
- 不改 `Plan::steps()` 的动作语义；`--click` 的 `x,y[,键]` 等规格原样透传。
- `report` 的 `--width`（视口）语义本来就对，改名后行为不变；`panel` 同理。
- 迁移顺序：`brief` → `network` → `loop` → 其余七个，每两三个跑一轮测试。

## 六、落地时订正

判据写完后实现，以下十件事是设计稿里没想到的，逐条记原因。

### 6.1 PSR-4 不认「类名和文件名差不多」

第一版叫 `Option.php`、类名 `Opt`。`composer.json` 把 `Drift\Dev\` 映射到 `tools/`，
`Drift\Dev\Debug\Cli\Opt` 于是去找 `tools/Debug/Cli/Opt.php` —— 找不到。改文件名，不改类名。

### 6.2 集中声明把「默认值抄错」从十处变成一处，也让它被看见

`props` 的 `maxDepth`/`maxFields` 实际默认是 3/120，我在 `Commands::extras()` 里先写成了 0/1；
`report` 的 `top`/`depth` 实际是 10/6，先写成了 1/1。这类错误在原来十份 if-else 里根本不会
「错」——每份都自洽，只是彼此不同。集中声明之后它是**一个明确的、可被测试读到的数**。

### 6.3 panel 漏了一个 `$meta`，靠 PanelTest 咬出来

迁移时把 `$meta` 组装和 `Plan::build` 一起换成了 `$args->script()`，但 `panel` 后面还有一句
`Runner::open(..., $meta, ...)` —— 它要的是**同一份 meta**，不是脚本。结果本地 panel 挂在
`Argument #4 ($meta) must be of type array, null given`。全量 PHPUnit 唯一一处失败就是它。

这暴露了一个边界：`Parsed::meta()` 与 `Parsed::script()` 必须同时提供。
只给后者，出口会为了拿回 meta 再拼一次——那就又回到十份组装。

### 6.4 `report` 的 `run_once()` 里那处 `Plan::build` 不是词表

它构造的是**录制分叉**：重放时原脚本不可变，录制落在一份同目标同视口、步骤为空的副本上。
这是运行语义，不是命令行词表，保留。不要为了"全局只有一处 Plan::build"把它也收进来——
那条不变量本身是错的。

### 6.5 `--at` 的互斥：解析层一处，语义两个位置

`--from` 与驱动参数互斥放在 `OptionSet::guardSources()`；`report` 的
「`--at` 与 `--frames` 一起给要提示」仍留在 `serve` 出口。两者不冲突：前者决定**能不能解析**，
后者决定**跑起来说什么**。混在一起会让"合法但值得提醒"变成"错误"。

### 6.6 `report` 是 CRLF，批量替换的正则先空跑了两轮

`\n\}\n\n` 在那份文件里永远匹配不上，因为是 `\r\n\r\n`。诊断用了
`re.search('$target = null;')` 打印后续 repr 才看到。批量改文件时先探测换行符，别假设。

### 6.7 `report` / `panel` 的 replay 逻辑夹在解析和 label 之间

它们会**改** `$target` / `$width` / `$rows`（脚本自带这些值，命令行没给时才采用）。
所以正则不能一路替到 `$label = ...`，只能替到 foreach 的右花括号；后面的 replay 块原样留下。
这也是为什么迁移后必须保留 `$target`、`$page` 这两个变量而不是全用 `$args`。

### 6.8 错误信息里选项名要带 `--`

原来"未知参数"只列 `assert at click ...`，看着像词表却不能直接抄。测试要求含 `--runs`，
于是改成 `--assert --at --click ...`。小改动，但错误提示是 CLI 的说明书。

### 6.9 `-h` 原来只有 web 认识

统一后所有出口都从 schema 生成 `--help`，但 web 的短开关 `-h` 会消失。在
`Commands::parseOrExit()` 里把 `-h` 归一成 `--help`，比让一个出口留着自己的解析分支划算。

### 6.10 八条 schema 判据全绿，三个出口却根本没接上

端到端手验时 `node` / `pick` / `props` 全部挂在 `Undefined variable $args`。批量替换时
replacement 少了第一行 `$args = Commands::parseOrExit(...)`——八条判据**一条都没红**，
因为它们测的是 `Commands::set()`，而不是出口脚本自己的入口。

这不是"再小心一点"能解决的：`schema 正确` 与 `脚本接上了 schema` 是两件事。
补两条子进程判据：10 个出口 `--help` 必须退出 0；7 个一次性出口必须真跑完一次。
把 `node` 那行再删一遍，第 10 条立刻红。

### 6.11 变异自检七组全咬红

| 变异 | 咬住的判据 | 现象 |
|---|---|---|
| 从 driver 删掉 `--click` | 1 | 10 出口同一词表 → 2 条失败 |
| `Commands::set()` 里重定义 `--width` | 2 | 6 个 error（启动即抛） |
| 去掉 `--width` 别名 | 3 | 3 条失败 |
| `guardSources` 把 `--at` 加回互斥 | 4 | 1 条失败 |
| 恢复非法数值静默改写 | 5 | 2 条失败 |
| 错误信息不列选项 | 6 | 2 条失败 |
| 删掉 `node` 的 `parseOrExit` 那行 | 10 | 1 条失败（真跑判据） |

## 七、判据落点

| # | 判据 | 落在哪 |
|---|---|---|
| 1 | 10 出口同词同指纹 | `CliTest::testEveryCommandBuildsTheSameScriptFromTheSameWords` |
| 2 | 重名/重别名拒绝 | `testSchemaRefusesToDefineTheSameNameTwice` |
| 3 | `--width` 视口、`--text-width` 排版 | `testWidthIsViewportEverywhereAndTextWidthIsSeparate` |
| 4 | `--from + --at` 三出口一致 | `testAtSelectsAFrameInAnEvidencePackage` |
| 5 | 非法数值拒绝、越界归一 | `testBadNumbersAreRejectedButRangesStillClamp` |
| 6 | 错误只列本出口的词 | `testUnknownOptionListsOnlyTheCommandsOwnWords` |
| 7 | help 与解析集合一致 | `testHelpIsGeneratedFromTheSameSchema` |
| 8 | 漏声明即拒绝 | `testAnUndeclaredActionCannotSlipThrough` |
| 9 | 10 个出口都能启动并显示共享词 | `testEveryCommandStartsAndShowsTheSharedWords` |
| 10 | 7 个一次性出口真跑一次 | `testEveryOneShotCommandActuallyRuns` |
