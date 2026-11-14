# 后续推进计划 · 2026-11-11

> 基准：HEAD `d4443fd`。输入为 [`REVIEW-ASYNC-SWOOLE-2026-11-11.md`](REVIEW-ASYNC-SWOOLE-2026-11-11.md)
> 的 §5 三处该改，加一直挂着的交付项（CHANGELOG / LICENSE / 版本口径）。
>
> **本文件是计划不是实施记录**。棒号从 §78 顺延（DEBT 顺序号；与
> [`PLAN-NEXT-STEPS-2026-11-08.md`](PLAN-NEXT-STEPS-2026-11-08.md) 的规划编号 §76
> 无关，后者仍未开工）。
>
> 纪律来源：`AGENTS.md`、`MEMORY.md`（变异四律 / T0-T1-T2 / 判据三态）。
>
> **11-11 下午新增**：综合 review 的复核评估与修复顺序另见
> [`PLAN-REVIEW-FIX-2026-11-11.md`](PLAN-REVIEW-FIX-2026-11-11.md)；本表 §82
> 「执行作用域裁决稿」与其 §3.3 是同一处，仍未开工。

---

## 0. 排序总表

| 棒 | 主题 | 核心判据 | 五维 | 成本 / T2 | 结论 |
|---|---|---|---|---|---|
| §78 | 四条腐烂论据订正 + 评审稿入仓 | ①② | 维护性 +++ | 近零 / 无 | **已完成（本轮）** |
| §79 | CHANGELOG 已知限制 + LICENSE + 版本口径 | ③ | 交付 +++ | 低 / 无 | **已完成（11-12，见 §1.1）** |
| §80 | `curl_multi` 复活探针（判据先行） | ①② | 实现 ++ | 中 / **必须 T2** | **P1** |
| §81 | 隐性规则 lint 化（PLAN-11-08 的 §76） | ①② | DX ++ | 中 / 无 | **P2** |
| §82 | 执行作用域（Deadline / 预算 / 子作用域）裁决稿 | ② | DX ++ / 扩展性 ++ | 低（只出稿） | **P2，等消费者** |
| §83 | T2 进 CI | ③ | 维护性 ++ | 依赖外部 runner | **P3，条件项** |

判据沿用 `DEBT §40.1` 三条：**①有消费者 ②错了是静默的 ③决定能不能交付**。

排序理由：**§79 排在最前不是因为它简单，而是它决定"别人拿到能不能用"** ——
当时仓库没有 `LICENSE`，对外等于"保留所有权利"，法务上不可用；CHANGELOG 的
已知限制还写着两条已修的问题，用户会按错误预期选型。★ 这两点现已解决
（`c7dd37f` 落 LICENSE 与版本口径，§1.1 完成逐条复核）。**§80 排 P1 是因为它唯一能
改变技术结论，且越晚做越贵**：C++ 桥接刚按链接性拆分完，将来若真要接 A1
（帧内 `curl_multi`），`IoPoller` 与 `TaskHost` 的形状都得动。

---

## 1. §79 CHANGELOG 已知限制 + LICENSE + 版本口径（已完成 · 2026-11-12）

三件事同一个主题：**对外事实可信**。2、3 在 `c7dd37f` 落地；1 于 2026-11-12 复核收口，
复核结果见 §1.1。

1. **已知限制逐条复核**。已知过期两条：ABI 运行期握手（已改判为静态契约 +
   `abi-check`）、AOT 增量 stale（`StalePolicy` 已落地）。做法是 **grep 复核每一条**，
   不照抄旧清单 —— 清单会腐烂是本项目反复验证过的。
2. **落 `LICENSE`**。〔已完成〕许可类型定为 MIT（`LICENSE` / `composer.json` 的
   `license` 字段 / `README.md` 的「许可证」小节三处一致）。原计划里"需要先拍板"一句已作废。
3. **版本口径统一**。〔已完成〕取了"降级为未发布"这一支，不真打 tag：`CHANGELOG.md`
   的「版本号规范」明写「未打 tag = 未发布」，条目标题也标了「尚未打 tag」，
   ⇒ `git tag -l` 为空不再是矛盾，而是**如实状态**。

**验收**：`docref` 绿；每条已知限制都能在代码里指出"仍存在"的落点；
`LICENSE` / `composer.json` / README 三处口径一致；打 tag 则 `git tag -l` 非空。

### 1.1 复核结果（2026-11-12）

逐条对 CHANGELOG 里可证伪的数字与能力断言做取证，**只有一条需要收窄**：

| 断言 | 取证 | 结论 |
|---|---|---|
| 「28 项闸门」 | `tools/gate.php` 的 `$checks` 逐项数出 28 | 成立 |
| 「控件面约 130 个类（含 8 个接口）」 | `src/Widget/*.php`：类 123 + 接口 8 = 131 | 成立 |
| 「8 个应用样例」及所列名字 | `apps/` 恰好 8 个目录，名字全对 | 成立 |
| 「多窗口（`WindowHost` / `WindowSet`）」 | `Drift\Platform\WindowHost`（单窗操作面）+ `src/App/WindowSet.php`（组合根窗口表） | 成立，是两个不同层 |
| 「深色主题 + `SystemTheme` 跟随系统」 | `Win32Screen::systemTheme()` → `drift_win_system_dark()`；`Win32Platform` 推 `ThemeChangedEvent`；`Application::onSystemThemeChanged()` 分发 | 成立；另已接 HC 独立轴（见下条） |
| 「`TaskHost` 三后端」 | `ProcessTaskHost` / `SyncTaskHost` / `ThreadTaskHost` | 成立 |
| 「UIA + 结构 / 焦点事件」 | `cpp-src/win32/uia.cc` 的 `UiaRaiseStructureChangedEvent` / `UIA_AutomationFocusChangedEventId` | 成立 |
| 「Per-Monitor V2」 | `cpp-src/win32/drift_win32.cc:1502` 的 `kPerMonitorV2` | 成立 |
| 「无 `RelayoutBoundary`」 | 全仓 0 命中 | 成立 |
| 「❌ 高对比度」 | 原判「映射已有、系统信号未接、唯一消费者是 `tools/smoke.php`」⇒ **表述过宽**，已收窄为「系统高对比度跟随」；该轮之后已**接线完成**：`WM_SYSCOLORCHANGE` + `SPI_GETHIGHCONTRAST` + `GetSysColor()`，showcase 为真实消费者，见 `docs/architecture/09-主题与语义.md` §2.2 | 已收窄 → 已闭环 |

收窄后的三处改动：

- 「❌ 触控 / 笔 / 高对比度」拆开：前两者留原样；高对比度改为「**系统高对比度跟随**」
  并写明「配色映射已有、未接信号、应用层无消费者」——否则会误导人以为连配色都得自己做。
  （2026-11-14 该系统信号已接线，见 `docs/architecture/09-主题与语义.md` §2.2；
  本条保留为当时的收窄记录。）
- 已知限制新增「**源码树不自足**」：`/cpp/` 在 `.gitignore` 里，`cpp/skia/`（141 MB）与
  `cpp/fonts/`（21 MB）clone 后不存在，构建期直接链接失败；PHPX 与 tpc 也不在本仓。
  这条是外部使用者**第一个撞上的墙**，不写就是静默失败（判据②）。
- 已知限制新增「纯运行态不需要工具链」：`gate --fast` 是纯 PHP 的，也不必 `composer install`。

**顺手发现、未动**：`composer.json` 的 psr-4 里还留着 5 处 `apps/*/bin/macos/`，目录均不存在。
autoload 对不存在的目录静默跳过，不影响运行，但属「仅 Windows」口径的残留 —— 改它超出
文档范围，记在此处待和「多平台」一并裁决。

---

## 2. §80 `curl_multi` 复活探针（判据先行）

目的：把 A1（同线程 `curl_multi` 每帧推进）从"判死"降级为"**证据过期**"，
给出可证伪的结论，而不是又一句口头裁决。

**先写判据表再跑**：新建 `probes/curl_multi_aot/RESULT.md`，先把预期读数、
通过/不通过线写死，再补实测列 —— 否则测完再挑解释。

变量矩阵（每一格都要有退出码）：

| 维度 | 取值 |
|---|---|
| 运行时 | 解释器 / AOT 产物 |
| swoole | 加载 / 不加载 |
| 负载 | 单请求 / 5 并发 / 256 并发 |
| 退出路径 | 正常退出 / 提前 `exit` |

关注读数：退出码、总耗时、`curl_multi_exec` 单轮耗时（旧读数 12µs）、内存增量。

**通过线**：真请求 + AOT + **无 swoole** + 退出码 0 四条件齐了，才改
`REVIEW-ASYNC-SWOOLE-2026-11-11.md` §5.2 的记账。

**边界**：探针阶段**不改产品代码**。若通过也不立刻接 A1 —— 按"未消费分支 =
未实现"，先在 DEBT 记"可复活"，等真实消费者（轻 HTTP 高频）出现。

**风险**：需 T2 真机构建；产物要单独一份，避免与主力产物混淆。

---

## 3. §81 隐性规则 lint 化（PLAN-11-08 唯一未开工项）

- **2026-11-11 已做一条**（规则 30 → 31）：`cjk-var-name`。判据是"变量名含非 ASCII 字节" ——
  双引号插值里变量紧跟中文会被词法吃成 `$n项`，静默取到未定义变量。零误报；不跳
  toolchain（夹具住在 `tools/` 下，跳了就永不响）。Bad 2 / Good 4 夹具 + 磁盘级变异真红。
- **第二条不做，这是判断不是偷懒**：逐条对过 `--list`，30 条已覆盖文档里几乎所有可静态判
  的隐性规则；`SelectPurity` 的 `array` 放行经复核是**正确契约**（数组 `===` 本身是值语义），
  不是洞。硬凑会退化成"为做而做"。
- 以后再加仍按 V2（能咬红、能自动、误报可控）+ 「1 规则 + 2 夹具 + 1 组断言」三件套。

---

## 4. §82 执行作用域裁决稿（只出稿，不动代码）

`AsyncScope` 今天只有"取消"（`AsyncCancelled` + `guardCancelled()` + unmount 兜底），
缺 Deadline / 预算 / 子作用域。TypeApp 的 `ExecutionScope` 是**概念参考，不是依赖**。

裁决稿要回答四问：
1. deadline / budget 是 `AsyncScope` 的扩展，还是新类型？
2. 子作用域是否需要（复制关联信息、不继承资源）？
3. 与 `AsyncCommand` 的取消是什么关系（谁先触发、幂等性）？
4. 时钟来源 —— `Future::timeout` 的注入问题一直没裁决，这里是同一个坑。

**触发条件**：出现真实消费者（长轮询 / 批量并发取消）再开工。

---

## 5. §83 T2 进 CI（条件项）

现状 `.github/workflows/ci.yml` 的 `aot` job 是 `if: false` + `[self-hosted, windows]`。
公开 runner 没有 MSVC / tpc，跑必失败，所以关着是对的。

真要开，先产出 `docs/T2-RUNNER.md`：MSVC 版本、Win SDK、`PHPX_HOME`、`tpc`、
运行时 `php.ini`、构建缓存与超时（T2 单次 200s+）。**成本在运维不在代码**，故 P3。

---

## 6. 明确不做 / 排后

| 项 | 不做的理由 | 重裁触发 |
|---|---|---|
| UI 进程引入 Swoole / 线程换进程池 | 控制权与 `GetMessageW` 互斥；swoole 与 `curl_multi` 共现崩在 shutdown；编译类进 `Swoole\Thread` 报 `Class not found` | REVIEW §6 四条门槛 |
| 给业务暴露 `Coroutine\run()` | 会把"卸载安全"换回"恢复后世界已变" | 同上 |
| showcase 示例树 `drift2` 节点改名 | 联动 3 个 UIA 探针期望值 + 重建 AOT，零收益 | 下次顺手重建时 |
| `docref --selftest` 的 `section-refs` 反例恒红 | 工具缺陷不是产品缺陷，且未进 gate | 下次动 `docref` |
| 富文本**编辑侧**（`RichText` 可编辑） | 渲染侧已有 `RenderRichText` + `TextSpan::$tag` 点击命中；编辑侧今天是只读，无消费者。最小完备集已查清：`spansInRange` / `insertSpan` / `replaceRange` 三件套 + 规范化不变量（相邻同样式合并、零长度 span 删除）+ **一次编辑 = 一个 undo 单元**；内联图片要动排版层，不是加个 span 类型 | 公告编辑 / 工单备注这类真实需求 |
| 富文本文档序列化格式 | 无消费者。要做必须是带 `version` 的显式编解码 + 迁移，**不学** `JSON.stringify(span)` 那种做法 | 出现"存下来再回显"的需求 |
| 第二平台 / DevTools / 触控 / 高对比度 | 非 1.0 卡点，见 `DESIGN.md` 边界章 | 1.0 之后 |

---

## 7. 诚实边界

- **§78 本轮真做了**（4 份旧稿加勘误条 + 11-08 计划加状态注记 + 本文件入仓）；
  其余全部**未开工**，也没有半成品。
- 本计划所有文件名与行为都是 2026-11-11 复核的结果。**开工前仍要 grep 复核一遍** ——
  这个项目的清单腐烂率已经被验证过四次。
