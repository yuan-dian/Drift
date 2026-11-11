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

## 2. 本仓是否命中：逐个场景核对

| 上游场景 | 命中 | 证据 |
|---|---|---|
| typed property 赋给局部后再调方法 | **强命中** | 186 处 `$x = $this->y;`（`RenderBox` 的 `$m = $this->mods` 就在最热布局文件里） |
| 闭包参数 / 按值捕获 / 返回 | **强命中** | 全仓 152 处闭包；`Bootstrap.cc` 已实证恢复静态（见 §3） |
| 联合 / 可空 / 交叉类型 | **命中**（改为 `php::Object`） | 178 处 `?Klass` 返回声明 |
| 常量参与算术 | 轻 | 17 处 `self::X` 运算 |
| `??` / nullsafe | **部分** | 上游明确「nullable 必须排除」，`taskHost = cond ? … : null` 仍走动态派发，这是设计取舍不是遗漏 |
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

## 5. 不做的事

- **不把 `php::Var == 0` / 动态派发计数做成 gate 判据**：剩余 1128 处里合法动态占多数
  （跨文件接口、nullable、裸 `array`、phpx 原生对象），这个数会随代码正常波动，做成
  判据只会被调阈值调到失去意义。它适合当量具（`frame-tax` 同类），不适合当门。
- **不为拿收益改业务写法**（给属性加 `#[\StdList]`、把接口实现塞进同一文件）：这是被
  编译器牵着走，且会破坏分层。上游修好后自然覆盖。
- **不锁 tpc 版本**：当前依赖的是 `fix/composite-abstract-interface-return` 特性分支，
  不是 tag。长期该锁一个稳定快照，但那是发布纪律问题，与本 issue 无关。

## 6. 结论与顺序

1. 收益确认：**做**（零代码改动，已有产物证据）。前提是先补 §4 的指纹判据 —— 已在本轮补上，
   否则下次「升级了但没生效」会再发生一次。
2. 每次 tpc 更新后按 T2 走一遍：`probe-window` + `frame_tax_aot` 的等价与帧税。
3. 可选：把 Windows/MSVC 下的验证结果回到上游 issue —— 上游只测了 Linux，我们这份
   `13.3 MB / 5-of-5 / 8-of-8` 是它缺的平台证据。

## 7. 复现命令

```
php tools/drift-build.php users --full                      # 换编译器后必须全量
python tools/probe-window.py users --title 用户管理          # 5 条判据
bash probes/frame_tax_aot/build.sh && ./frame_tax_aot_probe.exe
grep -rho 'typephp_call_method_cached' apps/users/build --include='*.cc' | wc -l
```
