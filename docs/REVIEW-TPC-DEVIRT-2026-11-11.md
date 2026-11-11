# tpc 去虚化修复（swoole/typephp#184）对本仓的影响 —— 2026-11-11

> 一句话：**有真实收益，且不需要改我们一行代码；但当前构建门控会让它静默不生效。**
> 数据全部来自本机 A/B（同一份 `src/`，只换编译器），不是推断。

## 1. 上游修了什么

`[AOT] 静态类型在属性赋值、常量、容器和接口场景丢失，导致去虚化失效并回退到动态派发`
—— 编译器在多类常见写法上把 `php::Object` / `int64_t` 提升成 `php::Var`，把可静态
直调的方法改用 `typephp_call_method_cached()`。表现为**每次调用多一次方法缓存查找 +
zval 装箱/取箱**，且会沿返回值、循环累加器扩散。六类修复（均已合入 master，共 11 个
commit）：typed property、常量、`??`/nullsafe、typed array 属性、interface 单实现者、
闭包；外加数组字面量/解构、联合类型 → `php::Object`、`instanceof` 收窄、final 直调。

上游只声明验证过 **Linux x86_64 + GCC**，未覆盖 Windows/MSVC。

**相邻的一组提交单独处理「可空类」**（2026-11-09，`963c0ae1` + `340a8589`，同样在 12:45 那次
合并里）：

- `?T` / `T|null` 在参数、返回、推导局部、属性、提升构造参数里**保留 `php::Object` + 类信息**，
  句柄本身可存 null；native 调用沿普通 typed object 路径，不再退化成 `php::Var`。
- 可空标量、其它联合类型仍走动态；`&$ref` 形式的可空对象参数仍是 `php::Ref`（Zend 引用 ABI）。
- 语义未放宽：空接收者上调用仍抛 `Error`（PHPX 在调用点保护），`?->` 仍短路且**不计算实参**。
- 另一条 `340a8589` 只做**去冗余校验**：`new` / `clone` / `$this` / 非空对象属性 / 非空返回的
  调用、以及 SSA 证明非空的局部，不再重复类与空值检查；可空对象实参的边界检查交给
  native 调用路径。
- ★ 这会**改 ABI**：可空对象形参从 `php::Var` 变 `php::Object`。本仓两份 stub
  （`cpp-src/skia`、`cpp-src/win32`）零可空类类型，所以桥接层不受影响。

## 2. 本仓是否命中：逐个场景核对

| 上游场景 | 命中 | 证据 |
|---|---|---|
| typed property 赋给局部后再调方法 | **强命中** | 186 处 `$x = $this->y;`（`RenderBox` 的 `$m = $this->mods` 就在最热布局文件里） |
| 闭包参数 / 按值捕获 / 返回 | **强命中** | 全仓 152 处闭包；`Bootstrap.cc` 已实证恢复静态（见 §3） |
| 联合 / 可空 / 交叉类型 | **命中**（改为 `php::Object`） | 178 处 `?Klass` 返回声明 |
| 常量参与算术 | 轻 | 17 处 `self::X` 运算 |
| `??` / nullsafe | **部分** | `??` 与 nullsafe 结果类型已保留；但 `cond ? $a : null` 这类**异型合流**仍降级为 `php::Var`。可空类本身已不再降级（见 §1 末段与 §8） |
| typed array 属性（`#[\StdList]`） | **不命中** | 全仓 0 处，属性一律裸 `array` |
| interface 单实现者特化 | **不命中** | 只对「**同文件**单实现者」生效；`TaskHost` 有 3 个实现且分散在 3 个文件 |

⇒ 收益主要来自「属性→局部」和「闭包」两条，恰好是布局与事件的热路径。

## 3. 实测 A/B

### 3.1 生成代码（`apps/users`，同一份源码）

本仓产物原是 10:32 由**修复前**编译器编的；`typephp` 源码 12:43 才更新，故可做同源对照。

| 指标 | 修复前 | 修复后 | 变化 |
|---|---|---|---|
| `typephp_call_method_cached` | 1593 | 1128 | **−29.2%** |
| 静态直调（`php_drift__*__*()`） | 12293 | 12663 | +3.0% |
| `php::Var` 出现次数 | 27381 | 24336 | **−11.1%** |
| exe 体积 | 13.6 MB | 13.3 MB | −0.3 MB |

机制确认（不是计数巧合），`bin/win32/Bootstrap.cc` 同一处：

```
- auto platform = vars_.get(0);
- typephp_call_method_cached(platform, get_str(2928), ..., php::VarList{title, body});
+ php::Object platform = php::Object(vars_.get(0));
+ php_drift__platform__win32__win32platform__fatalbox(platform, title, body);
```

热点文件动态派发降幅：`TableState` 169→86、`InputRouter` 93→60、`RenderBox` 91→49、
`Element` 44→40；`FocusManager` 66→67（基本不变）。

★ **这组数字属于 12:45 那次合并的整批（20+ 提交）**，本稿不把它拆成单提交归因：可空那组
（11-09 的 `963c0ae1` + `340a8589`）单独快照**编不过本仓** —— `src/App/FocusManager.php:428`
的 `return $state;`（`?ScrollMetrics` 接 `States::of()` 的结果）在那一档报
`Cannot assign object to return value of type ?ScrollMetrics`，是后续同批提交一起补齐的。
⇒ 不要尝试 cherry-pick 这组；按整批前进。

### 3.2 帧税（`probes/frame_tax_aot`，12k 节点，新旧 exe 交替三轮取中位数）

| 指标 | 修复前 | 修复后 | 变化 |
|---|---|---|---|
| 全量重排 med | 87034 µs | 82482 µs | **−5.2%** |
| 稳态帧 med | 17.7 µs | 18.0 µs | +1.7%（噪声） |
| 重建帧 layout / paint | 130327 / 100281 | 116042 / 81880 | −11% / −18% |

单次读数会给出 −6% 或 +6% 两种结论（离散 1.06–1.15×），**只有交替多次的中位数可信**。
结论：**静态收益是确定的（−29% 动态派发），wall-clock 收益在 5% 量级**——12k 节点下
每节点 6.5–8.1 µs 的成本由布局算法（box model 递归）主导，方法派发不是瓶颈。

### 3.3 语义安全

- `probe-window users --title 用户管理`：**5/5，退出码 0**（新编译器产物能起窗、关窗不崩）。
- `probes/frame_tax_aot` 自带的 AOT ↔ 解释器逐值等价：**8 项全过**（27 页 / 2253 节点 /
  9207 条绘制命令逐值相同）。类型收窄是语义改动，这条是本轮唯一能证明「没改坏」的证据。

## 4. 必须一起修的：构建门控看不见编译器升级

`tools/drift-build.php` 的输入指纹里，编译器那一项是：

```php
hash_update($inputs, 'tpc:' . (string) hash_file('sha256', $entry));
```

`$entry` 默认 `../typephp/bin/tpc.php` —— **333 字节 / 12 行的启动器，2026-09-23 至今未变**；
真正的编译器是 `typephp/src/**/*.php` 共 **218 文件 / 3.6 MB**，一项都没被哈希。

⇒ 拉取/切换 tpc 后指纹不变 ⇒ 决策 `SKIP`/增量 ⇒ 旧 `.cc` 与旧 exe 原样保留，
**编译器升级静默不生效**。本轮能发现问题只因为产物时间戳恰好早于源码更新时间戳；
换个人换个时机就会以为「已经用上了」。

**已修**（2026-11-11，本轮）：指纹纳入 `typephp/src/**`（源码树形态）与 `bin/`，发行版
`tpc.exe` 形态则哈希 exe 本身；`--dry` 额外打印「★ tpc 编译器源码变了」，把「重建 3 分钟」
与「改了业务代码」区分开。`tools/build-stale-check.php` 加了两条对账判据（只哈希入口 ⇒ 红、
不覆盖源码树 ⇒ 红），与本仓既有的「`cpp-src` 必须传空后缀」那条同形。

上游 tpc 自己的增量指纹是 `serialize(['translator' => self::VERSION, …])` 加编译期快照，
同样不随源码内容变化 —— 两侧都不能指望，所以本仓每次 tpc 更新后仍应显式 `--full` 一次
（指纹变了会自动全量，这条只是兜底）。

### 4.1 编译器与运行时必须同步前进（2026-11-11 实测）

指纹修好后紧接着撞上第二层：tpc 与 PHPX 是**两棵树**，新编译器可能要求新的运行期符号。

- `3959b2ef` / `c3e52aee`（2026-11-11 13:22 / 13:43）把「已知 Closure 的 `$f(...)`」从
  `obj.call(get_persistent_method(…))` 改成 `typephp_call_closure(...)`。
- 本机 Windows 运行时 `typephp-windows-x64/phpx/lib/phpx.lib` 是 **11-06** 的，头里没有
  这个符号 ⇒ `drift-build` 直接红：`error C3861: "typephp_call_closure": 找不到标识符`。
- `D:/TypePHP/phpx` 是 `swoole/phpx` master（13:43，含 `src/typephp/typephp_call.cc`），
  但 `lib/` 只有 Linux 产物；**本机没有任何 `.lib`/`.a` 含该符号**，所以不能靠换 include
  绕过 —— 必须重建 Windows 版 `phpx.lib`。

**收益核算决定了「先不追」**：同一份源码做 codegen 对照（`drift-codegen`，510 文件 / 450 `.cc`），
闭包那三条提交的净效果是 `typephp_call_method_cached` **4969 → 4969**、`php::Var`
**34117 → 34117**，只多出 **4 处** `typephp_call_closure`（`TableState` 的排序回调）。
为 4 个调用点去重建运行时不合算。

**当前做法**：本地构建用 `DRIFT_TPC_ENTRY` 固定到 `d4f8c3ca`（12:45 合并，`typephp_call_closure`
之前）的 worktree `D:/TypePHP/tpc-pin`；等上游 Windows 运行时发布后再解开，届时按 T2 重跑。

```bash
DRIFT_TPC_ENTRY="D:/TypePHP/tpc-pin/bin/tpc.php" php tools/drift-build.php users
```

★ 顺带一条踩过的坑：worktree 里的 `vendor/` 不能做成 junction —— PHP 会把 `__DIR__`
解析成**真实路径**，于是加载回原树的 `src/`（表现为「固定了还是报新符号」）。要真拷贝。

## 5. 不做的事

- **不把 `php::Var == 0` / 动态派发计数做成 gate 判据**：剩余 1128 处里合法动态占多数
  （跨文件接口、nullable、裸 `array`、phpx 原生对象），这个数会随代码正常波动，做成
  判据只会被调阈值调到失去意义。它适合当量具（`frame-tax` 同类），不适合当门。
- **不为拿收益改业务写法**（给属性加 `#[\StdList]`、把接口实现塞进同一文件）：这是被
  编译器牵着走，且会破坏分层。上游修好后自然覆盖。
- **不长期锁 tpc 版本**：当前依赖的是 `fix/composite-abstract-interface-return` 特性分支，
  不是 tag。§4.1 那次固定是**临时**的（等 Windows 运行时补齐），不是发布纪律意义上的锁定。

## 6. 结论与顺序

1. 收益确认：**做**（零代码改动，已有产物证据）。前提是先补 §4 的指纹判据 —— 已在本轮补上，
   否则下次「升级了但没生效」会再发生一次。
2. 每次 tpc 更新后按 T2 走一遍：`probe-window` + `frame_tax_aot` 的等价与帧税。
3. 可选：把 Windows/MSVC 下的验证结果回到上游 issue —— 上游只测了 Linux，我们这份
   `13.3 MB / 5-of-5 / 8-of-8` 是它缺的平台证据。

## 7. 复现命令

```
# 当前唯一能编过的入口（§4.1：上游 Windows 运行时尚未含 typephp_call_closure）
DRIFT_TPC_ENTRY="D:/TypePHP/tpc-pin/bin/tpc.php" php tools/drift-build.php users --full
python tools/probe-window.py users --title 用户管理          # 5 条判据
bash probes/frame_tax_aot/build.sh && ./frame_tax_aot_probe.exe
grep -rho 'typephp_call_method_cached' apps/users/build --include='*.cc' | wc -l
# 编译器版本对照（只走翻译层，不碰 MSVC，~35s）
DRIFT_TPC_ENTRY="D:/TypePHP/typephp/bin/tpc.php" php tools/drift-codegen.php
```

## 8. 剩下 1128 处动态派发是什么写法

`apps/users` 产物按接收者形态分（脚本按括号深度切第一个实参 + 回溯其声明/赋值）：

| 接收者 | 处数 | 含义 |
|---|---|---|
| `php::Object` | 633 | 类在运行期确定：**多态方法**（非 final 且被覆写）或经接口/转换擦掉类 |
| `php::Var` | 458 | **值层面就不知道**（裸 `array` 元素、`mixed`、异型三元合流） |
| `this_` | 14 | abstract 类里的 `$this->`（子类可覆写，不可直调） |
| 内联表达式 | 23 | `typephp_call_method_cached(typephp_read_property_cached(...))` 链式 |

对应到 PHP 写法，按占比排序：

1. **可空对象 `?Klass`** —— 声明处：属性 **140**、形参 **97**、返回 **198**。**已不再降级**：
   `963c0ae1` + `340a8589`（2026-11-09，随 12:45 那次合并进本仓）把 `?T` / `T|null` 的
   参数、返回、局部、属性都保留成 `php::Object` + 类信息，空句柄由 PHPX 在调用点抛
   `Error`（等价 PHP 语义），`?->` 仍短路且不计算实参。生成代码可直接印证：
   `php::Object root;` / `php::Object frame;`，`$this->focus->collect()` 直接编成
   `php_drift__app__focusmanager__collect(focus, …)`。
   ★ 但仍有两类动态派发：① **多态**（`RenderObject::parent()` 被 `Element` 覆写、非 final
   ⇒ 静态直调会在子类上出错，`RenderBox.cc:247` 的 `$node->parent()` 属于这一类，**不是缺陷**）；
   ② 值经 `typephp_nullable_object_value` 这类可空转换后类被抹掉。
   `!== null` 依旧不做收窄，但既然不再擦类，它已不是主要矛盾。
2. **接口类型** —— `src/` 有 **40** 个接口；`TaskHost` 有 3 个实现且**跨文件**
   （上游特化只对「同文件单实现者」生效）。例 `Element.php:76` 的
   `$host->requestFocus()`（`?FocusHost`）。
3. **裸 `array` 及其元素** —— **107** 个 `array` 属性，另有 **119** 处泛型只写在
   `/** @var list<Element> */` 里、编译器看不见（`Element::$children` 就是）。
   上游只修 `#[\StdList]` typed array 的属性根。
4. **未声明类型的闭包参数** —— 生成 `php::Var x = vars_.get(N)`，例 `TableState.cc:204`。
5. **`mixed` / `?array` 返回** —— `stage(): mixed`、`anchorAt(): ?array`。
6. **异型三元 / `?:` 合流** —— 两支类不同 ⇒ `php::Var`（`Coalesce` 已修，三元未全修）。

### 能动 / 不能动

- **能改（上游已支持）**：把热点的裸 `array` 属性换成 `#[\StdList(Foo::class)]`，
  元素与 `foreach` 都会拿到元素类。但会引入写入校验与 COW/引用限制，且 `$children`
  的写入路径多 —— 属于「为拿 5% 去动核心数据结构」，暂不做。
- **不该改**：可空与接口是语义需要（空状态、可替换端口），改写成非空只是把断言挪到别处。
- **别把多态当缺陷**：633 里相当一部分是「非 final + 被子类覆写」的方法（`Element::parent()`
  这类），静态直调才是错的。做这类统计时先看目标方法有没有覆写，否则会得出「还剩一半可优化」
  的假结论。
- **该找上游**：接口跨文件多实现的特化（`TaskHost` 三个实现分散在三处），以及可空转换路径上
  的类保留。`!== null` 收窄仍有价值但不是杠杆 —— 类已保留，剩下的是多态与擦除。
