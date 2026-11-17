# 诊断体系与静默坑

> 本篇解决：出问题时怎么从退出码、日志和 `[DX]` 退化记录收窄范围，以及哪些错误在解释器里永远看不见、必须靠真机或生成链兜住。

Drift 对失败的基本要求是"要么生效，要么响亮失败"。真正难查的是第三类：没报错、也没崩，只是能力悄悄没了。这篇把三类问题的定位方式放在一起。

## 三级判决与 `[DX]` 退化日志

`Errors::report()` 是唯一判决入口，分三级：

| 级别 | 行为 |
|---|---|
| `Errors::FATAL` | 写 `Errors.log`、弹原生错误框、`exit(3)`；不传级别默认就是它 |
| `Errors::RECOVERABLE` | dev 中止，release 只追加日志；帧间隔超冻结阈值走这一级 |
| `Errors::DIAGNOSTIC` | 进环形缓冲，同时写一条 framework 通道的 warn，不中止 |

Fatal 的标准序列顺序不可换：① 写日志（错误 + 栈 + 最近Diagnostic 环 + `Log` 全级别环）→ ② 弹原生错误框（不依赖渲染栈，渲染栈可能正是崩掉的那环）→ ③ 固定退出码 3。日志文件默认落在**当前工作目录**的 `Errors.log`，所以 exe 要以仓库根为 cwd 运行，否则日志会落到别处。组合根要注入错误框，并在退出前卸下——闭包捕获的静态对象图若留到 PHP shutdown，AOT 产物会在销毁类元数据后解引用，表现为"关窗之后偶发崩"。

能力没报错但静默变弱时，框架内部用 `Diagnostics::degraded($key, $message)` 播报：

- 统一带 `[DX] ` 前缀（`Diagnostics::PREFIX`），按 key 只报一次，一帧内触发几十次也不刷屏。
- 级别是 `DIAGNOSTIC`，所以只进环形缓冲、不中止；`Diagnostics::reported()` 返回报过的 key。
- key 不能为空，传空串会被当成 Fatal 报出去。业务代码不要用它，只用 `Errors::report()`。

框架检测点用 `[DX]`，业务错误用 `Errors`。常见的 key 有 `progress/derived-chain`（进度订阅挂到了 `then()` 派生链）、`future/fanout-cancel`（一条 Future 多处扇出后被取消）、`select/unstable` 一类选择判据不稳。看到这些 key 先按消息里的接线建议改代码，不要当成普通日志忽略：它们对应的症状分别是"进度条不动"、"另一处永久 loading"、"选择反复横跳"，都不报错。

异步链路还要按 code 分诊：`Failure::CODE_JOB_REJECTED` 是装配写错（重试无用），其余具名码是瞬时故障。完整码表见[诊断与错误码](../reference/diagnostics.md)。

`Errors` 与 `Log` 是两个不同的环，不要合并理解：前者只收 Diagnostic，是"能力退化"的检索面，消息原文不改（许多断言依赖"没有 diagnostic"）；后者按级别记运行轨迹，带时间 / 级别 / 分类。Fatal 报告会把两者分别 dump，所以一次中止既能看见判决现场，也能看见之前发生过什么。

## dev 壳双模边界

三层的边界要记清：

- **T0**：`bin/drift verify` 的纯 PHP 判据，装 PHP 就能跑。
- **T1**：`php tools/drift-dev.php <app>` 的开发态壳。它只编一次"壳 + C++ 桥"，业务 PHP 由壳内嵌的 ZendVM 在运行时 `require`，改 PHP 保存即生效、不重编译。热更是**跨进程重启**：同进程 `require` 会缓存 opcode，所以重启前先优雅关窗让状态写回，新进程恢复；超时强杀不投 `WM_CLOSE`，收尾不跑，丢半截输入属预期降级。
- **T2**：`drift-build --full` 的 AOT 产物 + 真机探针，需要完整工具链。

T1 能验解释器和壳层逻辑，**验不了 AOT 生成物**。`dual-mode-equivalence` 对解释器与 dev 壳做语义逐值等价，`aot-equivalence` 对解释器与全 AOT 做几何逐值等价，二者共用 fingerprint 作唯一 expected。判断"我改的东西要不要重编译"：改 `apps/<app>/src/` 的 PHP，dev 壳即时生效；改 `src/` 或 `cpp-src/`，必须 T2 重编译。

dev 壳的 `--selftest` 做指纹 + 壳红线自检（含反例），其中一条是 `src/` 不许进壳的 sources。新应用要在 `composer.json` 的 autoload 里有 `App\<名字>\` 的 PSR-4 前缀，dev 壳才知道去哪里 require——漏登记不是"找不到类"的显式报错，而是启动起来一片空白或加载不到你的页面。

入口本身并不固定：壳端只读 `DRIFT_DEV_ENTRY`，运行器默认写 Drift 包内的 `apps/dev/dev-entry.php`。`--project=<目录>` 指定宿主项目根（composer 包引入时必需），`--entry=<路径>` 相对它解析；热更监听包含 `--project/src`、入口及其同级 PHP，Drift 包自身源码不热更。发布包里的预编译壳用 `--prebuilt` 启动，跳过指纹检查——代价是源码必须与包同版本，否则壳与源码会静默错位。仍需 PHP 8.4 ZTS 运行时提供 `php8ts.dll`。

## 典型静默失败

**组合根 / 控件构造器漏 `parent::__construct()`。** 基类在构造里初始化 `$mods` 等字段，漏调后字段未初始化，到 `syncBox()` 读它才炸，栈顶看不出是哪一层。AOT 下的表现是 `The parameter object must be object, got null`。lint 有 `app-subclass-ctor-parent` 规则守这一类。

**新中文字符没有字模。** 确定性拉丁字模只覆盖可见 ASCII，中文标点和新文案来自生成链：`font-scan-chars.php` 扫字符串字面量里所有 `cp >= 0xA0` 的字符 → `_build/cjk_used.json` → `font-gen-glyphs.py` → `src/Text/CjkGlyphData.php`。新增上屏文本后不重跑这条链，新字符就是零宽隐形——只扫汉字区间还不够，`，。「」↑` 这些也会静默消失。注释里的中文不参与扫描。

**`FrameClock` 不推进导致异步不动。** 帧时钟是唯一的叫醒策略：`nextWaitMs()` 给 `waitEvents()` 返回 `-1 / 0 / >0`（无限等 / 尽快跑 / 等到点），`advance()` 跑一帧。它问 `wantsFrame()` 与 `nextDeadlineMs()`，而后台任务靠 `TaskHost::pending() > 0` 让应用"还要下一帧"。后端若提前把 pending 减到 0，帧循环就睡死，结果永远躺在完成队列、界面永远转圈且不报错。`needsPolling()` 为 `false` 却叫不醒 UI 是同一类挂死。同步后端 `pending()` 只在"结果已就绪、还没回贴"的一两帧大于 0，所以不会让应用空转。

**调用不存在的方法。** `$this->不存在的方法()` 在 AOT 下可以编译通过、真机才崩，解释器与 smoke 都发现不了。lint 有按可达类方法表检查的 rule（含跨类扩展），但变量传入、跨文件工厂这类形状守不全，最终仍靠 T2 兜底。

**属性读直接当实参。** `$f($this->box->maxWidth)` 这类写法生成 `php::Variant` 再传 `float`，报 `C2440`；解释器正常、只有 AOT 拦得住，而且没有 lint 能覆盖。先落到局部标量再传。

另外三类容易误判的静默：继承字段用 `private`（真机读到错值）；闭包 arity 不符（解释器忽略多传参数，真机整页错误占位）；整数除法截断（两个原生整数相除直接落 C++ `/`，`7 / 2` 得 `3` 而非 `3.5`）。逐条约束与错误码见[AOT 约束与代码纪律](../reference/aot-constraints.md)。

## 定位手段

按这个顺序收窄最快：

1. **看退出码。** `3` 是 Fatal；`2` 通常是测试缺依赖或等价检查无法完成；`0` 但画面不对，往"静默退化 / 几何错"方向查。退出码先把问题限定到"中止"还是"没通过"。
2. **翻 `Errors.log`。** 先确认 cwd 正确，再看 Fatal 消息、栈、最近的 Diagnostic 环与 `Log` 环。
3. **搜 `[DX]`。** 没有 Fatal 但能力变弱时，这一步能直接指出是哪条退化路径。
4. **异步链路查 code。** 用 `AsyncValue::code()` 或错误映射拿字符串码，按具名码表区分"重试"与"配置错误"。
5. **回到分层。** 解释器与 smoke 全绿、只有真机出问题，基本就是 AOT 那一族（属性读、arity、继承字段 `private`、`?->`、整数除法）；按[命令速查](../reference/cli.md)跑 T2 与对应 lint 复现。
6. **环境类先体检。** `php tools/doctor.php` 逐项报告构建前提，与构建脚本共用同一套定位逻辑；`--strict` / `--scope` 用于发布前自查。
7. **重放最小场景。** 找到嫌疑后先在做小应用里复现，再回到出问题的应用；能稳定复现才谈得上定位。

## 什么时候必须上真机

解释器里桥函数都是替身，跑通不等于真机跑通。只有真机（T2）能覆盖这些：AOT 生成物的类型 / 签名问题（`C2440` 等）、闭包 arity 的 `expects exactly N arguments`、继承字段 `private` 的错值、`?->` 静默读空、整数除法截断、DPI 感知级别、窗口关闭与退出码、UIA provider 的树导航与事件、`PostMessageW` 唤醒、`WM_CLOSE` 守卫链。解释器里的桥都是替身，跑通不等于真机跑通。

真机验证走 `drift-build --full` 加探针：起窗探针量标题、客户区尺寸、非空像素、DPI 感知级别与关窗后的退出码；语义探针按名字定位控件；关窗探针验"拒绝时还在、同意后自己退出"。起窗脚本会在收尾时主动 `App.close()` 并断言退出码，不能只看"窗口出现过"。测性与像素基线见[测试](./testing.md)。

## 相关

- [诊断与错误码](../reference/diagnostics.md)
- [AOT 约束与代码纪律](../reference/aot-constraints.md)
- [测试](./testing.md)
- [命令速查](../reference/cli.md)
