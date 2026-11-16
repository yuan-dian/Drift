# apps/bench —— v7 阶段 0 的三个微基准

> 规格：[`docs/decisions/README.md`](../../docs/decisions/README.md) §3
> 背景：[`24-v7-vs-实现.md`](../../docs/decisions/README.md) §5

**这里只有一个问题：三个数字。** 方案（26 册）已经自洽，实现（22081 行）能跑，
但项目至今**没有"每阶段耗时"的数据** —— 所有"增量布局值不值得"的讨论都建立在
端到端数字上，而端到端不能分解。这三个基准就是把它拆开。

★ 它住在 `apps/` 是因为 `drift-build` 只认 `apps/<name>/project.yml`（见
`project.yml` 头部）。但它**不是** §20 纪律 4 意义上的"能力示例"，它是量具。

---

## 怎么跑

```bash
# 从仓库根（cwd 决定 exe 落点与所有相对路径）
php tools/drift-build.php bench

# 产物在仓库根，跑它（GUI 子系统，无 stdout）
./bench.exe

# 看结果
cat build/bench-out.txt
```

★ **必须跑 AOT 产物**，不要用 `php.exe` 跑 `main.php`。解释执行与 AOT 的开销分布
不同（本项目踩过"解释模式过了不算完"）。`php apps/bench/main.php` 也能跑出一份
数字，但它**不是**判据依据。

★ `bench.exe` 的 cwd 必须是仓库根 —— 字体与 Skia 库都按相对路径找。

---

## M1：`toObject` 的成本（V3）

v7 的"一棵树"让孩子存在 `array` 里，遍历时 `toObject(Node::class)` 恢复类型再调
静态方法。**实现的形状不同**：`RenderFlex::$kids` 也是裸 `array`，遍历时
`$kid->layout($sub)` —— 那是**动态方法分派**。

| 组 | 形状 |
|---|---|
| A | `toObject` + 静态调用 —— v7 的形状 |
| B | `$v->layout()` —— 实现的形状（动态分派） |
| C | 父持类型化引用（8 度 unroll）—— 理想下界，**形状不同，只作信息量** |
| D | 纯 `foreach` —— 把迭代本身的成本分离出来 |

**判据只看 `A / B`**：

- `≤ 1.0` ⇒ 一棵树**比现状便宜**（1 次定型换掉 N 次动态分派）⇒ I1 有实测支撑
- `1.0 – 1.5` ⇒ 同量级 ⇒ 不构成推翻理由，按可读性 / 可调试性决策
- `> 1.5` ⇒ 关掉 I1（`24` 加结论、`13` 的 D1 加推翻条件）

★ `A / C` **不作判据**。它衡量的是"PHP 层相对 C struct 的差距"，而那个差距
**现状也有**（现状同样是裸 array + 动态分派）。拿 C 当基线会把"PHP 的固有成本"
误判成"一棵树的成本" —— 这是 [`24` §2.2](../../docs/decisions/README.md)
自我修正过的那一条。

★ 已核实的机制前提（`typephp/src/CompilerBase.php:2618`）：`toObject(X::class)` 的
返回类型被编译器**收窄为 `X`**（`resolveClassNameArg`）⇒ 后续 `$n->w` 是静态偏移
访问，不是动态属性查找。所以 A 组量的纯粹是"定型 + 类校验"这一次调用的成本。

---

## M2：`mixed` 装箱的成本（Cell 分裂）

| 组 | 形状 |
|---|---|
| A | 读 `mixed`（值 int）+ `toInt()` 拆箱 —— v7 统一 `Cell` 的形状 |
| B | 读 `int` 属性 —— 分裂成 `IntCell` 之后 |
| C | 读 `float` 属性 —— 分裂成 `FloatCell` 之后 |
| D | 读 `mixed`（值 **string**）+ `toInt()` —— **反例** |

**判据**：`A / B > 5` ⇒ `Cell` 分裂优先（1 周见效）；`≈ int` ⇒ 降级为"顺手做"。

★ D 组是**反例自检**。第一版只有 A/B/C，测出 `A / B = 1.00` —— 两种解释都成立：
「`toInt()` 内联成一次 tag 检查」或「值恰好是 int，被优化成 int 存储」。
两者分不清时判据不成立。若 `D / A` 明显 > 1，说明 `mixed` 的开销**与值的类型有关**
⇒ v7 的 `Cell` 分裂该按"这个 cell 会不会装非 int"决定，不能一句话概括。

---

## M3：每阶段耗时分解（**三个里最重要**）

直接回答"v7 的 measure/place 拆分值不值得"：如果 layout 只占 15%，那套改动的收益
上限就是 15%，而它要动 23 个文件。

四个夹具：`showcase` 真实根（70 个 Element）+ 参数化树 85 / 400 / 1464 节点。

★★ **占比分母 = `settle + layout + paint`**，不含 `present`。理由：无头
`RecordingCanvas` 的 `endFrame()` 只是计数（≈0ns），而真机 Skia 的 present 是
29.9ms 里的大头 —— 把它放进分母会让 layout 占比虚低，结论就反了。
真机 present 成本属于另一个基准（需要窗口，本目录不管）。

★★ **paint 段必须是 record，不是光栅化**（第一版用 `RasterCanvas`，实测踩到）：
`RasterCanvas::beginFrame()` 会 `clear()` 一次 1280×800 全屏 —— 102 万像素的
逐像素 PHP 循环 ⇒ showcase 的 paint 量到 **1078ms/帧**，而 layout 只有 0.87ms，
占比算出来 0.1%，**结论完全反了**。光栅化在生产里由 Skia 的 C++ 做，PHP 侧不参与
⇒ 那 1078ms 是"只存在于测试路径"的成本，不是被优化对象。
`RecordingCanvas` 只把命令追加进 `DisplayList`，那才是每帧 PHP 真正要做的事。

### ★★ 同步义务

`M3::runTree()` **复刻**了 `src/App/Frame.php::run()` 的四段，**没有**在框架里插
计时代码。理由是阶段 0 要"零代码风险"，不在热路径上加分支。

代价：`Frame::run()` 的结构一旦变化（加段 / 改顺序 / 改 early return），这里必须
同步改 —— 否则量的不是真实管线。**改 `Frame.php` 时顺手看这个文件。**

---

## M4：真机每帧分解（需要窗口）

M3 的分母**不含 present**。M4 补上它 —— 真机一帧的实际链路是：

````
Skia 画布 →(toRgba) PHP 字符串 4MB →(drift_win_present) 逐像素 swizzle → GDI
````

每帧跨两次语言边界、搬两次 4 MB。**如果它占端到端大头，"拆 measure/place" 就是把
2.9% 的份额再优化一遍。**

跑法：

```bash
touch build/m4.on          # 文件开关：存在 ⇒ 只跑 M4（要窗口）
./bench.exe
cat build/bench-m4.txt
rm build/m4.on              # 关掉，恢复无头三基准
```

为什么用文件开关而不是命令行参数：GUI 子系统的 AOT 产物拿 argv 要过 CRT 初始化，
而 `main()` 是 tpc 生成的入口 —— 不值得为一行参数去赌。

输出五段：`端到端` / `beginFrame`（Skia 分配 + 清白）/ `toRgba`（4 MB 搬运）/
`present`（swizzle + GDI）/ `其余`（PHP：settle + layout + paint 的记录部分）。

判据（[`25`](../../docs/decisions/README.md) §4）：`toRgba + present > 80%`
⇒ 不碰 `src/Layout/`，先修这条链；`< 50%` ⇒ 才轮到 measure/place 拆分。

★ 实测落在 **50.9%**（灰区），但把 `beginFrame` 也算进"表面链"就是 **61.2%**；
而修它只要 2–3 个文件、拆布局要 23 个 ⇒ 结论明确：**先修表面链**（`26` §5）。

★ 计时开关是 `Drift\Rendering\SurfaceProfile::$on`（默认关，关时每段只多一次静态读）。
它在 `Win32SkiaSurface` 里采样，不在 `App` 层 —— 那三段本来就属于 `RenderSurface`。

---

## 结果去哪

`build/bench-out.txt`。三个基准一次跑完，含判据与结论行。

★ 报告是**一次运行**的结果，不是多次取平均。计时噪声由"每组 200 轮取中位"吸收；
如果某个数字看着可疑，重跑一次对比，不要在同一份报告里挑。

---

## 目录

```
apps/bench/
  README.md            本文件
  main.php             全局 main()（-m bin 硬要求）
  project.yml          sources: . / src / cpp-src / apps/showcase/src
  src/
    Surface.php        无头 RenderSurface（RasterCanvas，只计数）
    Stats.php          中位数 / p90 / 格式化
    BenchNode.php      M1 夹具（BenchNode + Fanout8）
    M1.php             toObject 四组
    M2.php             mixed / int / float 三组
    Trees.php          M3 参数化树（branch × depth）
    M3.php             四段计时 + 占比判据
    Runner.php         编排 + 写 build/bench-out.txt
```
