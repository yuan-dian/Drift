# Drift · 历史、证据与已作废结论

> 这份文件回答三个问题：**① 每条裁决是怎么来的？② 证据在哪？③ 哪些旧结论已经过期？**
>
> 它**不是**设计方案。方案本体在 `DESIGN.md`；裁决得失在 `DECISIONS.md`；评审发现过程在 `REVIEW.md` / `REVIEW-2.md`；探针原始数据在 `probes/RESULTS.md`。
>
> ★ **写方案时不要把本文件的内容搬回 `DESIGN.md`** —— 设计文档只写"现在是什么"，不写"曾经怎么错"。

---

## 0. 一句话时间线（2026-10-14，七轮）

| 轮 | 触发 | 性质 | 净结果 |
|---|------|------|--------|
| 1 | 用户要求出方案 | 从零设计 | `DESIGN.md` v1.0 + `architecture.html` |
| 2 | 用户追问"类型化属性进三元""要不要 `->build()`" | **探针实证** | 推翻两条手册旧记录；否决 `->build()`；`freeze` → `seal` |
| 3 | 用户要求评审 | 评审 | `REVIEW.md`：8 处事实错误（含 `project.yml` schema 差 5 处） |
| 4 | 用户要求"直接补 vs 待裁决"分类 | 分类处置 | 16 条文档遗漏直接补；11 项设计缺陷 → `DECISIONS.md` |
| 5 | 用户拍板"按推荐执行" + 提示 `-m lib` | 裁决 + 补研 | 11 项全落地；`-m lib` 改变 D-02 答案 |
| 6 | 用户提示"GUI 仓已试过、已提上游、已修复" | **实证复核** | `-m lib` 结论翻转；但发现**发行版不含修复** |
| 7 | 用户提示"看一下 Nano 原生编译" | 评估 | 不启用 `--nano`，但采纳其禁用清单 |

**v2.0 重写（本次）**：不改任何设计裁决，只做三件事 —— 结构重排（过程记录移出）、补完整示例（新增 §A）、修 8 处文档内不一致。

---

## 1. 被证伪 / 已作废的结论（★ 最需要防复发的一节）

这些结论**曾经写在方案里、后来被实测或复核推翻**。保留在此，是为了防止有人再从旧文档或 GUI 仓注释里把它们捡回来。

| # | 旧结论 | 出处 | 现状 | 依据 |
|---|--------|------|------|------|
| F1 | 类型化属性参与三元/混合运算 ⇒ 类型降级 ⇒ `C2440`，报错指向生成的 `.cc` | 手册 §9.5/§9.15 · GUI 仓 AGENTS.md §4 | ❌ **不成立**。不再降级、不再 C2440。真实代价是**装箱 + 动态分派**（性能特征，非正确性坑） | 探针 A（`probes/typed_ternary/`） |
| F2 | "链式写法本身慢"（v1 探针报 B = 0.68 µs/节点） | 方案 v1 §15.3 | ❌ **低估 32%**。v1 测的是**简化替身**（无守卫、`public` 字段），不是设计真实形态 | 探针 C v2（`RESULTS.md §3.3`） |
| F3 | "`mutate()` 守卫在噪声量级内 ⇒ 去掉它省不下任何东西" | 方案 v1 §15.3 | ❌ **被证伪**。守卫可测量：**+0.09 µs/节点（+11%）**，三次一致为正。裁决不变，但依据从"免费"换成**25× 比值** | 探针 C v2（`RESULTS.md §3.4`） |
| F4 | `freeze(ownerId)`：同一实例挂两处要报错 | 方案 v1 §15.2 | ❌ **误杀**。Widget 是不可变描述，`children([$t, $t])` 合法且常见。owner 校验已删除，改名 `seal()` | 探针 B |
| F5 | 后端 A 依赖 Swoole/Swow 协程调度器 | 方案 v1 §17.2 | ❌ **因果反了**。让 PHP 离开 UI 线程靠 **TSRM 线程上下文**（`ts_resource(0)`），**零扩展** | 探针 E（`RESULTS.md §5`） |
| F6 | `include:` 不可用（"被静默忽略 ⇒ `sources` 空 ⇒ `Duplicate function main`"） | GUI 仓源码注释 | ❌ **过时注释**。`include:` 可用（支持提交于 2026-09-21，tpc.exe 构建于 2026-10-13） | 探针 D（`RESULTS.md §4`） |
| F7 | `sources` 没有排除能力 | GUI 仓源码注释 | ❌ **过时**。`ignore:` 可用，能排除被递归扫描的子目录 | 探针 D |
| F8 | GUI 的 `-m lib` 补丁"已合入上游" | 我第 5 轮的写法 | ❌ **写错**。`git merge-base --is-ancestor 6c466d91 master` = **false** ⇒ **报告被采纳，补丁未被采纳**，上游另起实现 `89fe80aa` | 生成物 diff |
| F9 | "上游 0.9.5 已修 D5/D6 ⇒ 可以直接用" | 我第 5 轮的写法 | ⚠️ **只对源码树成立**。发行版 `tpc.exe` v0.9.5 **不含**该修复 | 见 §3 时间线 |
| F10 | "Windows 上 `--nano` 不会开 `nanoMode`" | `SourcePipelineTrait.php:602-606` 注释 | ❌ **注释过期**。`NanoBuildBackend::composesRuntimeSources()` **无条件 `return true`** | 读源码 |
| F11 | GUI 仓 `TOPIC-lib-delivery.md` §八："`-m lib` 当前不可直接交付" | GUI 仓专文档 | ❌ **已过期**（上游 0.9.5 修了 D2/D3/D5/D6） | 上游 git log + 生成物 diff |

**★ 从这 11 条里抽出的三条元规律**（写方案时反复用得上）：

1. **"我撞上了" ≠ "文档没有"**。写"新发现 X"之前先 `grep` 方案里有没有 X。
   （实例：把 §2.2 早记过的"前导 `\`"当成探针 E 的新发现。）
2. **"上游已修"要分三层问**：① master 上有没有？② 合入的是不是我们提的那个？③ **发行版二进制带没带**？
   ★ **③ 才决定"今天能不能用"**，且只能靠**生成物 diff** 判定 —— 不能 grep 二进制（源码已压缩）、不能比版本号。
3. **判定发行版内容的唯一可靠办法 = 生成物 diff**，配**假编译器**手法（见 §4）。

---

## 2. 探针索引

| 探针 | 目录 | 回答的问题 | 状态 |
|------|------|-----------|------|
| A 类型化属性 | `probes/typed_ternary/` | 类型化属性进三元/算术会不会降级 | ✅ 已证伪旧记录（F1） |
| B `clone-with` | `probes/clone_with/` | `readonly` + `clone($this, [...])` 可用性、别名安全 | ✅ 可用但贵（4.2×） |
| C fluent 成本 | `probes/fluent_cost/` | 三种写法成本 + 守卫/seal 的隔离 | ✅ v2 三次一致（F2/F3） |
| D `include`/`ignore` | `probes/include_ignore/` | 两个配置键是否可用、路径相对谁解析 | ✅ 可用 + 抓到 1 个 tpc bug（F6/F7） |
| E TaskHost 线程 | `probes/taskhost_thread/` | 线程创建 / 并发 / 跨线程队列 / worker 跑 PHP | ✅ 4 线程并发通过（F5） |
| — `-m lib` 发行版 diff | 见 §3 | 发行版 tpc 是否含 lib 修复 | ✅ 不含（F9） |
| — `--nano` | `probes/`（临时） | Drift 代表性语法在 nano 下是否可编译 | ⚠️ 本机缺 `swoole/php-nano`，未跑通 |

**三条探针纪律**（探针 E 沉淀，复用价值高）：

1. **worker 用自旋不用 `sleep`** —— `sleep` 会让"主线程其实被阻塞"伪装成通过。
2. **两边各放一个进度计**，都 > 0 才算并发成立。
3. **输出落文件、每行一次 open/write/close** —— 后面有一步会掀掉进程，前面证据必须已在盘上。

★ **探针必须测量"设计的真实形态"**（F2/F3 的根因）：测简化替身会得出方向相反的结论。

---

## 3. `-m lib` 的实证链与上游时间线

### 3.1 GUI 仓的 spike（2026-10-10 ~ 10-11，框架源码一字未改的对照实验）

| 角色 | 配置 | 关键点 |
|------|------|--------|
| 库侧 | `sdk/project.yml`：`mode: lib` · `sources: [../src, ../diagnostics, ../cpp-src]` · 完整 Skia `link-libs` | 从仓库根跑 `tpc sdk/project.yml -O2 -m lib` |
| 消费侧 | `apps/spike/project.yml`：`mode: bin` · `sources: [main.php, ../demo/src, ../../typephp_gui.stub.php]` | **不含** `src` / `cpp-src`；`ld-flags` 从 **35 行缩到 1 行** |

**四条判据全过**：① 子类化跨边界（含 protected 成员）② 回调方向（lib 的 `run()` 调到应用覆写的钩子）③ **闭包跨 `.dll` 边界** ④ 桥接链接（建真窗 + 跑一帧）。
**终态 `372 ok / 1 fail`**，与 `-m bin` demo 基线逐条一致（唯一 fail = 真机字体差异）。

**六条缺陷**（GUI 当时 → 0.9.5 复核）：

| # | 症状 | 现在 |
|---|------|------|
| D1 | lib 模式给每个 TU 强制 `/FI<php_<target>_all_decl.h>` ⇒ 聚合头把 `php::operator*` 提到 Skia 头前 ⇒ 位运算重载歧义 | ❌ **根因未修** ⇒ 永久纪律：`cpp-src/*.h` 绝不写 `using namespace php` |
| D2 | stub 默认值 `self::CONST` 又拼一次命名空间 ⇒ `GUI\GUI\…` | ✅ 已修 |
| D3 | 裸库名交给 `link.exe` ⇒ 当 `.obj` 找 ⇒ `LNK1181` | ✅ 已修 |
| D4 | `*_arg_N_default_value()` 定义处漏导出前缀 ⇒ 670 个 `LNK2019` | ⏳ 待复验 |
| **D5** | 消费方从不 `module_init` 导入库 ⇒ 库侧 `php_request_cache` 恒 `nullptr` ⇒ **段错误 exit 139** | ✅ 已修（**仅源码树**） |
| **D6** | lib 模式**全程序去虚化** ⇒ 消费方覆写被**静默忽略** | ✅ 已修（**仅源码树**） |

### 3.2 ★ 发行版 `tpc.exe` v0.9.5 不含修复（生成物 diff 实测）

用同一份最小 `-m lib` 工程分别跑发行版与源码树 tpc，diff 生成的 `extension-probe.cc`（40 行差异）：

| 生成物特征 | 发行版 v0.9.5 | 源码树 master |
|-----------|--------------|--------------|
| `php_request_users` 引用计数 | ❌ 无 | ✅ 有 |
| RINIT 二次进入 | `php_error_docref("already initialized")` + **`return FAILURE`** | `++php_request_users; return SUCCESS;` |
| 导出 `typephp_<target>_library_request_startup/shutdown()` | ❌ 无 | ✅ 有 |
| persistent cache 重置 | ❌ 无 | ✅ 有 |

**时间线**：版本打点 `21770951`（0.9.5）= 10-13 **14:59** · 修复 `89fe80aa` = 10-13 **17:43** · 发行版文件 mtime = 10-13 **07:53** ⇒ **发布早于修复**。
⇒ **"版本号相同" ≠ "内容相同"。版本是标签，不是内容承诺。**

---

## 4. 可复用手法：假编译器拿生成物

只想要代码生成、不想要真编译时：

```bash
mkdir -p /tmp/fakecl
printf '#!/bin/bash\nexit 0\n' > /tmp/fakecl/cl.exe      # ★ Windows 下必须有 .exe 后缀
printf '#!/bin/bash\nexit 0\n' > /tmp/fakecl/link.exe
PATH=/tmp/fakecl:$PATH \
PHPX_HOME='D:/TypePHP/.phpx-dllhost' \
PHPRC='D:/TypePHP/typephp-windows-x64' \
  tpc project.yml -m lib
# 编译"失败"（假编译器不产 .obj），但 build/ 下 .cc 已生成 ⇒ 拿到明文产物可 diff / grep
```

- **不需要真编译器、不需要提权、秒级。**
- `tpc` 在 `Translator->prepare()` 阶段就因找不到 `cl` 而 Fatal ⇒ 假编译器是绕过该前置检查的钥匙。
- `--dry` 仍要求 `cl.exe` 可发现（只跳过编译链接，不跳过工具链探测）。
- `build/` 缓存会让 `--dry` 的 `.cc` 陈旧 ⇒ 改完源要 `rm -rf build` 或加 `-f`。

---

## 5. 环境坑（一次踩完，别再踩）

| 坑 | 症状 | 解法 |
|----|------|------|
| `tpc.exe` **不加载**同目录 `php.ini`（`php.exe` 会） | `Fatal: Failed to startup module [typephp_tpc]`（缺 `mbstring`） | ① `export PHPRC=<发行版根>` ② 该 ini 的 `extension_dir` 改**绝对路径**。★ 0.9.0 不要求、**0.9.4 起强制** ⇒ 升级发行版即破坏既有脚本 |
| 已验证**无效**的尝试 | 同上 | `tpc -d extension=mbstring` / `tpc -c php.ini` / 只改 `extension_dir` 不设 `PHPRC` |
| `PHPRC` 两个用途混用 | 构建期 vs 运行期 exe | 构建期指向**发行版根**；运行产物 exe 时才用"关 opcache"的那个 ini |
| `php.exe` 拒收 Git Bash 风格路径 | `/d/...` 被拒 | 传 Windows 风格 `D:/...` |
| 本环境 PowerShell 工具不回传输出 | 构建脚本拿不到 stdout | 一律走 Bash + 直接 `export INCLUDE/LIB` |
| `cl.exe` 被沙箱拦 | 构建失败 | 构建须提权；`LNK1104`（锁自家 exe）= 有残留进程，先 `taskkill` |

---

## 6. `D:\TypePHP\Drift` 的处置（已拍板：由 Drift2 取代）

**现状**：`D:\TypePHP\Drift` 是 git 仓但 **`master` 零提交** —— 只有工作区文件，无历史、无测试、无构建脚本。内容为 `DESIGN.md`(86KB) / `DRIFT-REDESIGN-V2.md` / `V3.md` / `WIDGET-ERGO-PLAN.md` / `DATA-SEAM-PLAN.md` / `FLUENT-VS-FRAMEWORKS-BENCH.md` / `DRIFT-VS-GUI-COMPARISON.md` / `src/` / `tools/`。

**处置**：代码与方案**不迁移**（`Drift2/DESIGN.md` 已吸收其全部有效裁决且带探针背书）；有价值的历史推理文档可归档到 `Drift/_archive/`；**后续开发只在 `Drift2/`**。

★ **纪律**：不要把 `Drift` 当"上一版实现"参考 —— 它的结论凡与 `Drift2/DESIGN.md` 冲突，一律以 **Drift2** 为准。

---

## 7. 裁决索引（原 `DESIGN.md §23` 的单一视图，v2.0 重排后保留在此）

设计条文已散入 `DESIGN.md` 各章（下表的"现落点"列），此处保留**单张索引表**，便于一次性核对"哪些事当初是**从零裁决**出来的"。
得失权衡在 `DECISIONS.md`；探针数据在 `probes/RESULTS.md`。

### 7.1 桌面刚需 11 项（原本全篇 0 次提及；原则：**能零平台端口解决的就不加端口**）

| 能力 | 裁决 | 现落点 | 关键点 |
|------|------|--------|--------|
| 键盘快捷键 / 加速键 | ✅ 做 | §9.1 | `Drift\Input\AcceleratorTable`，路由链**第 ① 环（焦点之前）**；**不加平台端口**。两条冲突规则：IME 组合期不吃快捷键；`TextInputClient` 可声明独占键集 |
| 菜单栏 / 上下文菜单 | ✅ 做，**自绘** | §5.1 · §9.1 | 普通 Widget；**原生菜单栏明确不做**（风格割裂 + 违反 §18） |
| 鼠标指针形状 | ✅ 做 | §9.1 | `WindowHost::setCursor(CursorShape)`；命中测试改为**一次命中三方消费**（事件 / 语义 / 光标） |
| 系统主题变更 | ✅ 做 | §9.2 | **不加端口**：复用 `screen()` 通道，`WM_SETTINGCHANGE` → `ThemeChanged`，与 `DpiChanged` 同路 |
| 焦点遍历顺序 | ✅ 定义 | §9.1 | 默认文档序；`->skipFocus()`；`->focusOrder(int)`。lint：同容器混用有无 `focusOrder` ⇒ 报错 |
| 窗口状态持久化 | ✅ 做 | §5.4 | `Drift\App\WindowStateStore`，**不进 `Platform`**；启动/退出各一次同步 IO；恢复失败**允许静默降级**（G2 显式例外，见 §24.3） |
| 列表虚拟化 | ✅ 做（M5）+ 前置纪律 | §12.3 | **虚拟化会 dispose State ⇒ 跨回收存活的状态（滚动/选中/展开/草稿）必须外置 Store** |
| 应用内拖放（控件↔控件） | ✅ 做（M3） | §12.4 | `Draggable` / `DragTarget`，**零平台端口** |
| 多窗口 | ❌ 明确不做 | §12.4 | 将来做是**结构性**代价：改 `Application` / `EventLoop` / `RenderSurface` 三处所有权 |
| 原生菜单栏 | ❌ 明确不做 | §5.1 · §12.4 | 风格割裂；自绘 `MenuBar` 已覆盖同一场景 |
| OS 级拖放（资源管理器→窗口） | ❌ 明确不做（M5+） | §5.1 · §12.4 | 需 OLE `IDropTarget`；§5.1 留 `dropTarget()` 注释位 |

### 7.2 语义未定 4 项（原 P1 剩余）

| 项 | 裁决 | 现落点 | 依据 |
|----|------|--------|------|
| `seal()` 的 API 地位 | **纯 `@internal`，不进公共面；同时删除 `->seal()`** | §15.6.1 · §21 A15 | 两条宣称收益都不成立：挂载时的"封存判断"就是一次赋值、实测在噪声内（`F+ − E` = +19.8 / +17.5 / **−2.0**）；跨帧复用本来就不需要它 |
| `TextRole` 取值域 | **`TextRole::Unset = 0` 作为显式 enum case** | §15.2 纪律 4 | 让 0 成为**合法取值**，"未设置"是它的语义 ⇒ **A2 从根上消失**；配套**哨兵三档定则** |
| `Paragraph` 所有权 | **归 `Drift\Text`；从 `GpuContext` 摘除 `createParagraph`** | §7.1 | `GpuContext` 的职责是设备/队列所有权，"排版不是 GPU 的事" |
| 补测"带守卫的 B" | ✅ 已补测，**推翻原推断** | §15.3 | 守卫**可测量**（+11%），不是噪声；裁决不变但依据换成 **25× 比值** |

### 7.3 第二轮 11 项设计缺陷（`D-01` ~ `D-11`）

| # | 裁决 | 现落点 |
|---|------|--------|
| D-01 | 动作缝 = **冒泡链 + 应用级注册表** | §11.4.3 · §16.6.3 |
| D-02 | 公共面 = **按 `@api` 标注**（默认 `@internal`）+ 分发形态三选一 | §10 整章 |
| D-03 | `ForEach::make(keyFn)` **必填** | §11.5 · §15.4 · §16.6.1（R4 升格） |
| D-04 | 三态 = **backed enum + 无 `default` 的 `match`** | §11.5 · §12.1 |
| D-05 | `SubjectBuilder::select()` **切片** | §11.3.3 · §16.6.3 |
| D-06 | property hooks（M2）→ **`Bound`**（M3），**纪律不动** | §11.4.1/11.4.2 · §10.3 第 6 条 |
| D-07 | 派生值 = **缓存 getter + lint**（不做 `Computed` 原语） | §11.3.4 · §16.6.3 |
| D-08 | 线程断言 = **`notifyChanged()` 入口 + `setState()`** | §5.3 第 3 条 |
| D-09 | 反模式 **A16** + 页面级 lint | §21 · §16.6.3 |
| D-10 | "专职订阅控件" = **接口标记**（`SubscribingWidget`） | §10.3 第 5 条 · §12.2 |
| D-11 | `DisplayList` **总是录制**（+ 缓冲复用契约） | §7.2 第 1/2 条 |

**净增**：**17 条机器守卫规则**（§16.6.3，v2.0 已从 9 条扩到 17）· **5 个公共类型**（`CommandRouter` / `CommandHandler` / `CommandArg` / `Bound` / `SubscribingWidget`）· **1 个探针门**（`-m lib`，见 §3.2 与 `DESIGN.md §24.2`）。

### 7.4 第三轮：v2 提案批（`O-01` ~ `O-17`，2026-10-14 用户拍板）

**背景**：v2 重写期间按"边写边找可优化点"产出的 17 项提案（`DESIGN.md §23.2`）。**首轮裁决 9 项，全部按推荐方案 A 落地**：

| # | 提案 | 裁决 | 现落点 |
|---|------|------|--------|
| O-01 | `Errors` Fatal 在 GUI 里的中止形态 | **标准中止序列**：日志（含最近 100 条 Diagnostic 环形缓冲）→ **`Platform::fatalBox()`**（★ 走端口抽象，**不直调 `MessageBoxW`**）→ `exit(3)`；启动早期 Fatal 退化到"日志 + `abort()`"（§24.3 例外） | `DESIGN.md §1.2` + `§5.1` |
| O-03 | `TaskHost::submit()` 收 `Job` 类而非 `callable` | 收**类**（绕开"闭包跨线程未验"，与 `CommandHandler` 同一条设计语言） | `§17.2` |
| O-04 | 加速键键位 | **`KeyChord` 值对象**（与菜单项共用同一类型 ⇒ 显示与行为不可能不一致） | `§9.1` + `§22` |
| O-07 | `CommandArg` 命名构造器 | 加 `none()` / `id(int)` / `key(string)` | `§11.4.3` |
| O-08 | golden 绑定测试字体 | **仓库内打包一份测试字体**，`golden` 强制使用 + **断言**实际字体 == 测试字体 | `§14.2` + `§16.2` |
| O-09 | 性能预算表 | 采用三行表（M1 单页 60 FPS / M3 1000 节点有数 / M5 帧时间 ∝ 可见项数） | `§20` |
| O-10 | 新工具 `drift-doctor` | **做**（§16.5 六个陷阱全是"环境配置错 ⇒ 报错指向别处"） | `§16.2`（五件套 → **六件套**） |
| O-11 | 反模式 ↔ lint 双向索引 | 两条硬规则：§21 每条反模式标注"守它的人/机制" + §16.6 每条规则标注**服务哪条公理/反模式**（均不许留空） | `§16.6`（三张表新增"服务"列）+ `§21` |
| O-13 | `nano-hygiene` 的诚实边界 | 写死"只保证源码纪律，最终判据是真机 `--nano` 构建" | `§16.6.3` |

**同批附带裁决（非 O 编号）**：

| 事项 | 裁决 | 现落点 |
|------|------|--------|
| **`--nano` 模式** | ★ **不作为前期重点，M0–M5 明确不做**；禁用清单仍作源码纪律（零成本保留可选性）。⇒ **形态 B 的"多文件部署"代价改为接受**（不再指望 nano 消除） | `§16.7.5` · `§10.2` 代价 2 · `§24.2` #7/#8 |

**复核批（2026-10-14）—— 6 项，其中 3 项由"推荐 A/B"翻转为「不做」**：

| # | 提案 | 复核结论 | 依据 |
|---|------|---------|------|
| O-02 | `BuildContext` 不得携带业务数据 | ★ **不需要 lint** —— 真正的缺口是"能力面从未定义"⇒ 补 `DESIGN.md §3.4`；§18 已否决 `of()` 式查找 ⇒ 该 lint **防的是不存在的通道** | 全篇只用到 `$ctx->width` |
| O-06 | `select` 大数组的相等策略 | ★ **不做**（**实测证伪**）—— 最坏形态 40.3 µs = 1000 节点重建的 **4.4%**；列表页真实形态 **0.020 µs**；且原推荐 lint 的判据（"静态可知数组长度"）**本身不可实现** | 探针 F，`probes/RESULTS.md §6` |
| O-05 | `select()` 返回 `SelectedBuilder<T>` | ★ **不做**（**前提证伪**，用户批准）—— PHP 的 `callable` 不透明 + **用户态泛型不可用** ⇒ `<T>` 只能是文档；`tpc -m bin --dry` 对"故意元数不符 + `@param callable(T)`"**零诊断** ⇒ "编译错误"兑现不了（真实效果是"**从 lint 降级为注释**"）。★ 顺带两项：§4.2 的 `SelectedBuilder` 是**孤儿名字**（全项目仅出现 1 次）⇒ 已删；**`SubjectBuilder` 全文无类形状** ⇒ 归入 O-16 一起做 | 探针 G，`probes/RESULTS.md §7` |
| O-12 | 反模式 A17（子树没有错误边界） | ★ **前提与 §19 矛盾** —— §19 里"错误边界"**同时在 M3 和 M5**（O-12 只读了 M5）；"整帧黑"是**未验断言**（真实症状取决于一个**从未定义**的帧循环异常策略）。⇒ 真正的缺口 = **「帧循环对未捕获异常的策略」从未定义** ⇒ 推荐 **S3**（有边界 ⇒ 容器捕获 / 无边界 ⇒ Fatal 序列），**容器与纪律必须同里程碑（建议 M3）** | 全文检索 + §19 双处 |
| O-14 | `Subject` 的通知批处理 | ★ **不做**（**实测**）—— 提案自己的场景（5 订阅者 × 3 次 notify）= **1.9 µs = 帧预算的 0.011%**；要变成 1% 需要 **500 订阅者**，而那正是 `nested-subscribe` 已禁止的形态。★ 新判据：**扇出单价 ≈ 0.22 µs/订阅者/次**，回头闸门 = **N×K ≳ 760**。★ A 的真实代价 = 打破 §5.3 **已承认存在**的"纯业务订阅者"的同步语义；若将来真做，首选形态是 **C：显式 `$store->batch(fn)`** | 探针 H，`probes/RESULTS.md §8` |
| O-15 | `CommandHandler` 的依赖从哪来 | ★ 缺口真实；★ **技术前提已真机证实可用**（`new $className(...$args)` + 接口虚调用在 `-m bin` 下构建 + 运行都过 ⇒ 与 O-05 相反）。但 **① 漏了方案 E**（`on()` 收**实例**：零新 API / 零反射 / 零动态 `new`，且满足"必须是类"的全部三条理由）**② A 缺两处必填项**（依赖解析机制 —— "不需要反射"与"按参数类型注入"**自相矛盾**；handler 生命周期）**③ 需补 lint `handler-deps-resolvable`**（把"首次派发才响"提前到构建期）⇒ **A 或 E 二选一** | 探针 I，`probes/RESULTS.md §9` |

**第三轮裁决（2026-10-14 用户拍板）—— 5 项**：

| # | 提案 | 裁决 | 现落点 |
|---|------|------|--------|
| O-12 | 反模式 A17（子树没有错误边界） | **A′** —— ① 定义**帧循环异常策略 S3**（有边界 ⇒ 容器捕获 / 无边界 ⇒ Fatal 序列）；② **容器与纪律同里程碑，都归 M3**；③ 加 A17 反模式。★ **先修了 §19 的自相矛盾**（"错误边界"原先同时在 M3 和 M5，M5 那处已删） | `§19`（M3 行）+ `§21 A17` + `§A.2 反模式表` + `§23.2 O-12` |
| O-14 | `Subject` 的通知批处理 | **不做** —— 提案自己的场景实测 **1.9 µs = 帧预算 0.011%**；要变成可测量的 1% 需要 500 订阅者，而那正是 `nested-subscribe` 已禁止的形态。★ 保留**量化回头闸门**：回调次数 **N×K ≳ 760**；真出痛点时首选形态 **C（`$store->batch(fn)`）** | `§23.2 O-14` + `§23.3` |
| O-15 | `CommandHandler` 的依赖从哪来 | **方案 E：`on()` 收 handler 实例** —— 依赖**就在构造点**，零新 API / 零反射 / 零动态 `new`；A 的 `provide()` **放弃**。★ 代价三条（handler 是注册期单例 ⇒ **必须无状态** / 多命令共用要提变量 / 依赖清单隐式化），已写进正文 | `§11.4.3`（注册段 + handler 段 + 代价表）+ `§A.2.5` + `§A.2.10` + `§23.2 O-15` |
| O-16 | `Bound` 的定位 | **A′：删 `Bound` 原语 + 删 `TextField::bind()`** —— 表单控件的官方形态 = `SubjectBuilder::select(...)` 包住手写的 `value()/onChanged()`。★ 四层论据：**语言层**（实测 PHP 禁止对带 hook 的属性间接修改 ⇒ "1 次出现"与"写入自动通知"二选一）· **收益层**（属性名仍出现 2 次，与手写等价）· **结构层**（是"机制封装"非"固定变体"，越出 §10.3 第 6 条）· **同源层**（§18 早把 Vue `v-model` / WPF 绑定表达式 / SwiftUI 属性包装器全列入避开） | 全项目 17 处：`§0` / `§2` / `§4.2` / `§10.3` 第 5·6 条 / `§11.3.2` / **`§11.4.2` 整节重写** / `§11.4.3` / `§15.4` / `§16.6.3` / `§19 M3` / `§21 术语表` / `§A.2.8` / `§A.2.9` / `§A.2.10` / `§A.2 反模式表` + `§23.2 O-16` |
| O-17 | `BoxStyle` 样式复用 | **A′**：`BoxStyle` 值对象**只管盒样式**（background / border / radius / shadow）+ 中间基类 **`BoxWidget`**；**不引入 widget 层 `TextStyle`**（§15.2 的 `Text` 纯样式只 2 个字段，不达"≥4"阈值）；**删孤儿名 `ControlStyle`**；补 `ThemeScope`（面覆盖）/ `BoxStyle`（点覆盖）/ 主题 token（键）三层分工 | **`§15.7`（新增节）** + `§4.2`（补 `Color` / `BoxStyle` / `BoxWidget`）+ `§16.4` + `§19 M2` + `§21 术语表` + `§23.2 O-17` |

★ **第三轮裁决连带修掉的 2 处实际缺陷**：
① **§19 的自相矛盾**（"错误边界"同时挂在 M3 与 M5、名字还不一致）—— 与 O-02/O-05/O-06/O-12 同型的第 **4** 次"前提没核全"。
② **§A.2.5 的 `provide()` 清单漏了 `UserSource`** —— 而 §A.2.10 的 `SignupCommands` 构造器需要它 ⇒ **按 A 走会"首次派发才响"**。★ 这反而是 E 的优势：E 在组合根显式 `new SignupCommands($this->form, $this->src)` ⇒ **漏参是编译错误**。

**第三轮复核（O-16 / O-17）—— 引入"其他 GUI 框架怎么做"的横向对照**（两项均已按推荐 **A′** 裁决并落地）：

| # | 提案 | 第三轮复核结论 | 依据 |
|---|------|---------------|------|
| O-16 | `Bound` 的定位 | ★ 方向仍是 **A′（删 `Bound` + 删 `TextField::bind()`）**，但论据升级为**四层**。**语言层**：**实测**（探针 J）PHP **禁止对带 `set` hook 的属性做任何间接修改**（`&$f->p` 与 by-ref 传参都抛 `Error`）⇒ **"字段名出现 1 次"与"写入自动通知"在 PHP 里二选一**，而 §11.4.1 已选后者 ⇒ **O-16 是 §11.4.1 的必然推论，不是新缺口**。**同源层**：§18 早已把 Vue `v-model` / WPF 绑定表达式 / SwiftUI 属性包装器**全部列入避开栏** ⇒ `Bound` 就是 Drift 自己的 `v-model`。**收益层**：可行形态必须传 read+write 两个闭包 ⇒ 属性名仍出现 2 次（与手写等价），§11.4.2 那张"3→1"摩擦点表**按不可实现形态算出**。**结构层**：它是"机制封装"而非"固定变体" ⇒ 越出 §10.3 第 6 条"变体工厂"的判据 | 探针 J，`probes/RESULTS.md §10`；§18 原文；§10.3 第 6 条 |
| O-17 | 样式复用 | ★ 推荐改为 **A′**：`BoxStyle` 值对象**只管盒样式**（background / border / radius / shadow）；`padding` 归 §13.1 几何面（原方案描述与它自己的拆面表矛盾）；**不引入 `TextStyle`**（§15.2 的 `Text` 纯样式只 2 个字段，不达"≥4"阈值 ⇒ §15.2 不动）；共享手段从"trait"改为**一个中间基类 `BoxWidget`**（trait 混入在 AOT 下未验，继承已验）；**删孤儿名 `ControlStyle`**。★ **横向对照的硬结论**：**WPF 的"基类样式字段"靠"每实例稀疏表 + 四层值链"才付得起，而 Drift 的字段是非稀疏 C++ 成员 ⇒ 学不来；Flutter / Compose / SwiftUI 与 Drift 存储模型同构 ⇒ 跟它们（每族值对象 + 基类零样式）** | §15.2 / §15.3；§18 对照表；§13.1 |

**全部收口（0 项待裁决）**：17 项提案（`O-01`~`O-17`）**全部裁决完毕**。其中 **3 项"不做"**（`O-05` / `O-06` / `O-14`，全部实测证伪）、**4 项为修正形态**（`O-12` A′ / `O-15` 方案 E / `O-16` A′ / `O-17` A′）、其余按推荐方案 A 落地。

★ **仍挂账（不是待裁决，是随里程碑实现时一并处理）**：**`SubjectBuilder` 的类形状**（字段表 / 方法表 / "无 `select` 时 `builder` 传 0 参还是 `null`"）—— 归入 **M3 的订阅侧**，见 `DESIGN.md §23.2 O-05` 复核发现 ⑥ 与 `§23.3`。

**第四轮裁决（2026-10-15 用户拍板）—— 2 项，均按推荐方案 A**：

| # | 提案 | 裁决 | 现落点 |
|---|------|------|--------|
| **O-18** | §A.2 的 `Application` 用法与已实现 API 分裂 | **A：改文档对齐代码**（零代码改动）。§A.2.4/§A.2.5 的 `protected` + `boot()` + `commands(): CommandRouter` + `accelerators()` + `taskHost()` 在 `src/App/Application.php` 上一个都不存在 —— #23 只修了 §A.1 同一处三枪，§A.2 全留着。★ 三条落地规则：① 覆写全 `public`；② `boot()` 删掉（依赖装配就在构造点 = O-15 方案 E 原话）；③ `commands(): iterable<string, CommandHandler>` 字典。★ `accelerators()` / `onSystemThemeChanged()` / `taskHost()` 三处**不存在的钩子按 A15 不预挂**，形状以注释留存 | 9 处：§5.1 / §9.1 / §11.4.3 / §A.2.4 / §A.2.5 / §A.3.1 / §A.3.2 / §A.3.8 / §A.3.9 |
| **O-19** | §19 出口判据与 `src/` 真实状态脱节 | **A：§19 也加「当前状态」列**（§4.2 已随本轮前一提交补上）。事实：M1 判据「一个窗口画出 Hello Drift」**未兑现** —— `Drift\Platform` 9 + `Win32` 2 + `SkiaCanvas` + `cpp-src/` + `project.yml` + `Bootstrap.php` 全 0 文件，现有验证全部无头 | §19 六个里程碑全标实况（M0 ✅ / M1 ⏳ 平台层 0 / M2 ⏳ 缺 FocusNode+CursorShape / M3 ⏳ 约一半 / M4 ⏳ 工具链三件在 / M5 ⏳ 未动） |

★ **本轮暴露的方法论问题**：**"上一轮已落地"是自述，不是事实** —— §24.1 #22 写「`Page` 已删」，核验时 `Page` 仍在 §4.2 表里、真正的成员 `NoPage` 反而没列。⇒ 以后每条"已落地"都必须有**可复核的落点**（本轮起：§24.1 逐条记位置 + 状态列）。

**第五轮裁决（2026-10-16 用户拍板）—— 1 项，按推荐 A**：

| # | 提案 | 裁决 | 现落点 |
|---|------|------|--------|
| **O-20** | 菜单浮层机制（`Menu` / `ContextMenu` 打开形态；兼 §12.1 第 6 条 / §A.3.7 `showModal()` 的机制缺口） | **A：第二棵根渲染树** —— `Application` 持 `?Widget $menuWidget` + `?Element $menuElement`（与 `$root` 同构）；`Frame::run()` 三段加浮层分支；`dispatchInput()` 指针先选根；菜单 / 模态共用槽位。★ 参考仓 GUI `TOPIC-overlay.md`（提交 `e51e5b1`，`verify.sh` 56 项全绿）已真机验证同款架构 ⇒ **移植而非从零**。★ 同批批两项：右键最小集（`PointerEvent` button + 位移阈值，不等 `GestureArena`）+ §9.1“GestureArena 已支持”改诚实（第 6 处文档不实） | §5.1 裁决 1 · §9.1 · §12.1 第 6 条 · §A.3.7 · §4.2（+4 点名，96→100 / 23→27）· §19 M3 |

★ **方法论再得一证**：MEMORY.md 的纪律“写'⏳ 待验'项先 grep 参考仓 memory”直接兑现 —— `TOPIC-overlay.md` 让 O-20 从“从零设计”变成“移植已验证架构”，且核出三处**必须逐条对**的结构差异：① GUI 命中靠每帧 `elementId` 注册表（Drift 没有 ⇒ 先选根树再命中，反而更简单）；② GUI `Canvas` 有 `measureText`（Drift 没有 ⇒ 走 `TextMetricsProvider::advance()`）；③ GUI `Color::value()` 丢 alpha 被迫自绘遮罩（Drift 契约层 `Color::fromRgba` / `Paint::withAlpha` 就有 alpha，光栅后端混色未验）。

### 7.5 实现纪事：M3 收尾（2026-10-16）

**当日落地顺序**（每个都跑完四门：smoke / lint / `M2Conformance` / codegen）：
Spinner → M2 conformance 运行器 → 多页外壳（`Dock`/`AppBar`/`NavButton`）→ 错误边界容器 + `AcceleratorTable`
→ 菜单四件 + 应用内拖放 + 浮层机制（O-20 方案 A 从文档变实现）→ **`apps/users` 完整样板（M3 出口判据兑现）**。

★ **"守卫空转"第 2 批（`apps/users` 一落地就当场露馅）** —— 与 O-19「文档自述≠事实」同族，
但这次是**规则自己的代码**在空转：

| # | 规则 | 病灶 | 后果 | 修法 |
|---|------|------|------|------|
| 1 | `dead-command` | `arrayKeys()` 拿 `=>` 跟**原始字符串**比，而它在 PHP 里是 **`T_DOUBLE_ARROW` 令牌** ⇒ 恒假 | `Application::commands()` 的**声明式注册路径从来没被扫到** ⇒ 七条命令全误报"死命令" | 比 `T_DOUBLE_ARROW`；并做**反例自检**（构造一个"死注册"文件必须先被抓到） |
| 2 | `subscriber-mark` | 见 `->select(` 就报，不看**接收者** | 把 §A.2.9 的**官方写法**（页面用 `SubjectBuilder::make($store)->select(...)`，订阅责任在那个专职订阅者身上）判成违规 | 只认 `$this->select(...)`（`SourceQuery::methodCalls()` 加 `index`，判 `->` 左邻是不是 `$this`）；同样加反例自检 |

★ **落地过程暴露的三类"文档承诺、代码没有"**（都已就地登记）：
① `Widget::disabled()` **不存在**（§A.3.2 在用）；
② `ThemeScope` 是**两参构造** `make($theme, $child)`（§A.3.3 写的是链式 `make()->theme()->child()`）；
③ §A.2.1 目录树用小写目录名（`store/`），而 PSR-4 要求目录名 = 命名空间（`Store/`）—— 一落地就被
`namespace-mismatch` 抓 37 条 error。

★ **PHP 层的一处硬坑（值得写进纪律）**：**`static fn` 里用 `$this` 是运行时错误**，而当它出现在
`Subject::notifyChanged()` 的订阅者回调路径里时，异常会被"订阅者异常隔离"包成 `RECOVERABLE`
⇒ **debug 下整局中止**（现象是"改个搜索词，应用没了"）。`UserStore::visible()` 的过滤闭包踩了这条。

★ **真机这一枪（本轮最值钱的一步）**：`apps/hello` 只走了 Column/Row/Text，
而 **AOT 只编译"可达"代码** ⇒ `apps/users` 的更大用法面**首次触达**两位数框架代码，
当场崩出两个 AOT 真缺陷（解释器面 smoke / lint / codegen 三道闸门全绿，一个都看不见）：

| # | 症状 | 根因 | 修法 |
|---|------|------|------|
| 1 | `error C2440: "return": 无法从 php::Variant 转换为 php::Float`（`RenderFlex.cc`） | 三元分支里直接放**属性读取** ⇒ 被推成 `Variant`，与 `float` 形参不匹配（同型坑记在 `typephp-gui-build` 技能里） | `tightMain()` 先落局部标量再传 |
| 2 | `Deprecated: Creation of dynamic property NavButton::$label` → `ArgumentCountError` Fatal | **会被继承的类的 `private` 构造提升属性**：AOT 按运行时类型查属性表，父类 private 不进子类表 ⇒ 访问退化成"创建动态属性"（值为 NULL） | `Button` 5 个字段 + `RenderFlex` 5 个提升参数改 `protected`（与 `Element::$box` 同一条纪律） |

⇒ 固化两条纪律：**① 会被继承的类的构造提升属性一律 `protected`**；**② 写顶层 `return` 的夹具
放 `tests/` 并在 `ignore:` 里排掉**（AOT 判据里没有 `Stmt_Return`）。

★ **§4.6 的共享 base 抽出并验证**：`build/base.yml`（两个应用**当前编译参数完全相同** ⇒ 配得上
一个 base；抽的时机正是 `apps/hello` 注释里写的"第二个应用落地时"）。tpc 的 `include:` **实测可用**
（`ProjectYamlLoader` 有实现与循环检测；GUI 仓注释说"没有 include"是旧版本经验），两条语义已核实
并写进注释：**路径相对被包含文件**、**list 型整体替换**。两个 exe 都构建通过、真机跑通。

★ **M3 出口判据兑现**（§19）：`apps/users`（20 个 PHP）attach 后可跑、局部态与跨页共享两条路径都能写、
`Root` 只组合（`rootBuilds()===1` + 全树过 lint）、**Ctrl+S 可用**（加速键第 ① 环 ⇒ `form.submit`）。
清点复测（`_build/audit_42.py`）：`src/` 154 文件 / 136 `@api` / 点名 96 = **90 有文件 + 6 个 0 文件**
（剩 6 个分属 M4/M5：`SkiaCanvas` · `Modal` · `GestureArena` · `SemanticsTree` · `ThemeChangedEvent` · `HoverHandler`）。

---

### 7.6 实现纪事：示例 UI "乱" 的三处根因（2026-10-16 下午）

**触发**：用户看 `out/*.svg` 预览后报"现在的示例，UI 界面怎么都是乱的"。**入口是"看渲染结果"**，
不是"读代码"—— 三处根因里有两处**所有解释器面闸门都是绿的**（smoke 496 / lint 0 error / codegen 0 skip）。

| # | 症状 | 根因 | 为什么闸门看不见 |
|---|------|------|------------------|
| 1 | 示例里中文**整片不显示**（"用户管理/刷新/编辑"全空，看起来像布局塌了） | `DeterministicFontData::CJK_GLYPHS` 只手写了 **16 个**演示字，而示例/应用用到 74 个汉字 ⇒ **71 个缺字形**。缺字形契约是"返回 `null` ⇒ 不画"（不是画方框）⇒ **静默无墨** | 契约本身是对的、有断言守；**没有任何断言问过"示例文案的字都有字形吗"** |
| 2 | `AppBar` 标题画到盒**外面**（盒高 44.8，标题落在 y=143.2）；`PopupPanel` 菜单项同理 | `RenderFlex::performLayout` 的交叉轴定位拿**约束上限**居中（`$inner->maxHeight`），而 `AppBar` 从纵向 `Column` 拿到宽松高约束 `0..320` ⇒ `(320−21.6)/2≈143.2` | 所有既有断言都在**紧约束**下测 `Center`（`tight(100,40)` 恰好 `max==min` 所以对），宽松约束那条路径**零覆盖** |
| 3 | 12px 说明文字糊成一团（笔画整根丢） | `BitmapFontFace::rasterize()` 降采样用**最近邻**：16→12 只采样 12 行，**丢掉 4/16 行** | 拉丁字模 5×8、常用字号 ≥12 ⇒ 拉丁永远走**放大**路径；CJK 才有 16→12 降采样，而 CJK 之前根本没有字形（被 #1 掩盖） |

★ **修 #2 时自己踩的坑（值得写进纪律）**：第一版改法用"内容交叉尺寸 `min($crossSize, $inner->maxCross)`"，
**当场把 smoke 那条 `Center` 断言打红** —— 因为 `tight()` 的语义是"父把尺寸定死"，
`RenderBox::layout()` 末尾会 `$constraints->constrain($measured)` 把**下界顶上来**，
所以居中必须按**夹后的盒尺寸**算。**判据：交叉轴居中用"盒自己的交叉尺寸"，而盒尺寸要按
`constrain()` 同口径夹一次（含下界），不是按内容、也不是按约束上限。**

★ **量具自检的又一证（第 3 处空断言）**：`tools/smoke.php` 那条"确定性测试字体无中文字形 ⇒
中文无墨（O-08 M4 前已知，届时拆除）"**从未 layout 探针盒** ⇒ `RenderText::lineCount()===0` ⇒
恒 0 墨 ⇒ **加字之前也是 0**。它一直"通过"，却什么都没测到；`O-08` 的"已知边界"是建立在一个
空断言上的。已补 `layout()` 并把断言翻成正向（"覆盖示例用字 ⇒ 中文有墨"）。
⇒ 与 §7.5 的 `dead-command` / `subscriber-mark` 同族：**规则/断言"存在"不等于"在测东西"**。

★ **`subscriber-mark` 夹具没跟上判据（同轮收口）**：§7.5 把判据收紧成"只认 `$this->select(`"，
但夹具还写 `$source->select(` ⇒ `--selftest` 报"期望 2 / 实际 0"，**该规则失去正向测试**。
夹具已改（并补一条"委托式订阅不该响"的 `ConfiguredBuilder`、把 `WatcherState` 改成真能测到
`State` 豁免分支的 `$this->watch(`）。

★ **字模生成链的两个坑（都是"静默给错东西"）**：
① **来源必须可再分发**：第一版拿系统 **SimHei** 生成 —— 而 `DeterministicFontData` 的 docblock
   明写"取自 Noto Sans SC（SIL OFL 1.1）"。专有字体派生的点阵数据**不能入库**，已改回
   `NotoSansSC-VF.ttf`（OFL）。
② **变量字体的默认实例是 Thin**：`NotoSansSC-VF` 的 `Weight` 轴默认值 = **100**，
   直接渲染出来的横竖笔画只有 ~1.5px（64px 下）⇒ 降到 16×16 后覆盖度够不上阈值 ⇒
   `三/二/厂/引/「」` 全部被判"无字形"。必须 `set_variation_by_name('Regular')`。
③ 顺带纠正了"扫描只收汉字"的口径：只扫 `U+4E00–U+9FFF` 会让**中文标点**（`，。（）「」`）
   与箭头（`↑ ⇒`）静默无墨（`DemoPage` 的句号/逗号整个消失）。改成"所有 `cp >= 0xA0`"。
④ 生成器从 `_build/`（**被 gitignore**）挪到 `tools/` —— 生成物入库、生成器不入库 =
   "这个文件是生成物"这句话是**不可复现的谎言**。

★ **预览链补了一件工具**：`drift-preview` 只出 SVG，而 SVG 是"位图 runs"、人眼读不了。
本轮加了 `_build/svg2png.py`（纯 `<rect>` 解析 + Pillow 光栅化）才第一次真正"看见"渲染结果 ——
**#1 和 #2 都是靠它看见的**，纯读代码/看断言看不出来。


### 7.7 实现纪事：TaskHost 线程池被探针 K 判死（2026-10-16 晚）

**这一节的主题是"一个已经写进方案 4 处的『【实测】可用』是怎么被推翻的"。**

★ **§17.2 后端 A（C++ 线程池执行 PHP 任务）在方案里被标了 4 次"【实测】可用"**
（§2 表第 15 行 · §2 能力表 · §11.x 第 2 条 · §17.2 架构图），依据是探针 E。
但探针 E 的 ⑤ 只跑了三个函数：`thr_php_add`（整数算术）/ `thr_php_sum`（标量循环）/
`thr_php_concat`（循环拼字符串）—— **从没碰过类 / 对象 / 属性**。
⇒ "worker 能跑 PHP"这句话成立的范围，**比它被引用时假设的范围小得多**。

★ **探针 K（`probes/taskhost_tsrm/`）补的正是 §17.2.2 点名的那一枪**，做法上两个关键点：
① **`threads=1` 是关键对照列** —— 它把"并发竞争"与"worker 线程本身就缺东西"分开。
   结果是后者：**碰类/对象/属性的 mode 在 1 个 worker 线程上就 SIGSEGV**（3/3 稳定）。
② **C++ 侧 `trace()` 文件追踪**（`fopen/fprintf/fclose`，每行一次 open/close）——
   崩在 PHP 里时 `file_put_contents` 已经不可用，只有 C++ 侧的证据留得下来。
   它把崩溃点定到了行：`mode=0 req=2` 崩在 **`zm_activate_*` 内部**；
   `mode=4 req=1` 崩在 **`php::request_init()` 之后的 mode 调用里**。

★ **根因是读生成的 C++ 读出来的**（不是猜的）：`build/extension-taskhost_tsrm.cc` 里
`static THREAD_LOCAL php_request_cache_storage *php_request_cache = nullptr;`
**只在模块 RINIT 里被 `new`**，而 `get_class()` / `get_property_cache()` / `get_method()` /
`get_method_call_cache()` / `get_function_resolution_cache()` 全走它 ⇒
**没跑过 RINIT 的线程上全是空指针解引用**。`typephp_read_property_cached(…, get_property_cache(…))`
在生成的 `main.cc` 里出现 **32 次**（读枚举 `->value` 就要请求级属性缓存）。
★ 顺带解释了一个"看起来像并发"的现象：mode 1（类静态属性）在 `threads=1` 也崩、但
**主线程先摸一遍就好了** —— 因为 `get_persistent_class` 是 `std::atomic` + CAS 的**持久**缓存，
主线程填上以后 worker 直接命中，**不再需要在自己线程上做类名解析**。

★ **两条"给 worker 补请求上下文"的路都断了**（这是本轮唯一"想救但救不了"的部分）：
- `php::request_init()`（**公开 API**）可调、安全（`mode=0 req=1` 完整跑通），
  但它**不建 `php_request_cache`** ⇒ 类/对象访问照样崩；
- 完整 RINIT（`zm_activate_*`）**自己就 SIGSEGV** —— 它的最后一步是
  `php::eval("main();")`，把"建请求上下文"与"启动 main()"**绑死了**。
⇒ 结论：**后端 A 在当前 tpc 运行时下不可实现**；需要 tpc 提供一个"每线程进入请求"的入口
（分配请求缓存 + `php::request_init()`，**不启动 main()**），最省事的等价做法是把 RINIT 的
前两步与最后那一步拆开各自导出。⇒ **M1–M3 只上后端 B（`SyncTaskHost`）**，
端口契约不变，tpc 补上钩子即可接入（页面/Store/`Job` 零改动）。

★ **顺带抓到两个"永不匹配的 mangling"式 `LNK2019`**（`obj` 里明明有符号）：
① 生成的模块钩子**在命名空间里** —— 真实符号是
   `typephp_project_taskhost_tsrm::zm_activate_typephp_taskhost_tsrm`（命名空间名 =
   `typephp_project_<project.name>`）。在全局作用域写声明就永远匹配不上。
② **`INIT_FUNC_ARGS` 是 `int type, int module_number`**（`zend_modules.h:27`），
   **不是** `zend_module_entry*`。写成指针签名同样匹配不上。

★ **一条量具缺陷**（自己踩的，写下来防复发）：mode 0 第一版返回"本线程推进的步数"——
而"共享静态量 + 线程恰好串行"时**每线程步数也是 `$rounds`** ⇒ 判据恒真、什么都测不到。
改成返回**绝对值**才成立（每线程独立 ⇒ `min=max=rounds`；共享 ⇒ `max=threads*rounds` 且 `distinct>1`）。
**"量具必须带反例自检"的又一例。**
