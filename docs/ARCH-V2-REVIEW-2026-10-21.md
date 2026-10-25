# Drift2 架构 v2 方案 · 评审

> 评审对象：`docs/arch-v2/` 十册（8195 行，2026-10-21 版）
> 评审方式：**先机器核对可复算的部分，再读设计与执行**。不做"读起来不错"式的评价。
> 结论时间：2026-10-21 22:2x

---

## 0. 结论

**方案可用，不需要重写。** 设计判断的可信度**高于**它自己的记账 —— 这是个好方案特有的毛病：
真正难的地方（`settle` 的收敛形状、`Computed` 的拉取式、`Versioned` 的层间解耦、
用坐标变换代替 20 处改动）都推演对了，出错的全是**数字和指针**。

执行前需要处理三件事：

| # | 事 | 性质 | 状态 |
|---|---|---|---|
| 1 | 五处记账错误（smoke 链 / shim 数 / 指针 / `Stack` 重复定义） | 纯一致性 | **已修**（§2） |
| 2 | `RenderCard` 的去留三册互相矛盾 | 需裁决 | **已定：删**（§3.1，含三个连带洞） |
| 3 | `Modifier` 到底 17 还是 20 个字段 | 需裁决（出口判据依赖它） | **已定：20**（§3.2，3 个合成维删不掉） |

另外有 **一处真 bug 级遗漏**：W5 会静默改掉 IME 候选框的坐标，而 W5 的验收清单里没有这一项（§4）。

---

## 1. 机器核对：方案"说自己可复算"，那就复算

十册的可信度建立在一个承诺上（`00` §9「取数口径」）：所有现状数字都能一条命令重算。
我把这个承诺验了：

| 检查 | 方法 | 结果 |
|---|---|---|
| `file:line` 引用是否越界 | 抽取全部 `Xxx.php:NNN`，比对目标文件行数 | **78 处，0 越界** |
| 引用内容是否对得上 | 抽查 10 处最吃重的（`Element.php:282` / `ErrorBoundaryElement.php:45` / `Application.php` frameCostMs（S1 拆片后行号漂移，已不按行引）、`:688` / `RasterCanvas.php:449`、`:422` / `Frame.php:135` / `DisplayList.php:156` / `Cell.php:61` / `State.php:165`） | **10/10 逐字命中** |
| 跨册 `§` 引用是否断链 | 抽取全部 `` `NN` §A.B ``，比对目标册标题表 | **406 处，0 断链**（3 处报警是 v5/v6 外部引用，误报） |
| `00` §9 的 12 项取数 | 逐条重跑 | **12/12 一致**：223 文件 / 222 带 namespace / 974 个 ★ / `Application.php` 2192 行 / 21 个 RenderBox 子类 / 12 个代理盒 / 9 处可空返回 / 187 处 `readonly` / 32 个 `final readonly class` / 22 处 `paint(Canvas)` / 15 条 lint / 936 ok |
| 43 步 | 数 `09` 的步骤表行 | **43** ✓ |

⇒ **这份方案的"事实底座"是扎实的。** 下面的问题都在"把事实连起来"的那一层。

---

## 2. 已修的记账错误（5 项）

### 2.1 `09` 的波次 smoke 链从 W1 起整体 off-by-one（最严重的一条）

`09` §5 的累计表是**对的**（936 → 939 → 949 → 958 → 967 → 970 → 976 → 989 → 1002），
但各波自己的出口判据从 W1 开始按 **940** 起算：

```
W0 出口：939（936 + 3）        ← 对
W1 出口：950（940 + 10）       ← 起点错成 940，应为 939 + 10 = 949
W2 …W6：继承这 +1 一路错到 990
W7 出口：1002（989 + 13）      ← 又跳回 §5 的 989，于是 W6 的 990 与它直接冲突
```

⇒ 六处全部改正（`949 / 958 / 967 / 970 / 976 / 989`），现在与 `09` §5 逐格相同。

★ 这条的讽刺之处值得记下来：`09` §5 存在的理由就是"各册累计数按写作顺序算，会打架"，
而**它自己没有管住自己册内的波次表**。`09` §10 的失败模式 #8 写的是"用各册的中间数对账"——
真正的风险是"用本册的两个地方对账"。

### 2.2 shim 数量：写着"四个"，列了六个，另有一处写"2 个"

| 位置 | 原文 | 实际 |
|---|---|---|
| `02` §7.1 | "四个 shim：" 后接 `Padding`/`SizedBox`/`Center`/`Expanded`/`Flexible`/`Spacer` | **6 个** |
| `02` §7.2 M2-4 | "四个 shim 落地" | 6 |
| `00` §7 | "四个 shim" | 6 |
| `00` §6 | "**2 个** shim 保兼容" | 6 |

⇒ 四处统一为"六个 shim"，并在 `00` §6 注明 `Card` **不是** shim（它是样式预设）。
数量错会直接影响 W5 的估工与回滚粒度（回滚要按 shim 逐个撤）。

### 2.3 `00` §3 的 `D_COMPOSE` 指针指错节

`00` §3 把 `D_COMPOSE` 的来源指向 "`05` §3"。但 `05` §3 是 `RasterCanvas::drawPath`（扫描线），
`D_COMPOSE` 在 **`05` §5「合成与动画」**。⇒ 已改为 `05` §5。

### 2.4 `02` §5.1 的 `Stack` 与 `04` §4 是两份设计（`09` 早裁决，但没执行）

`09` §1 冲突 3 明确写了"按 `04` §4 的形状；`02` 的 M2-6 划掉"，但 `02` 里两处都没动。
两份设计的差异是**实打实的 API 冲突**：

| | `02` §5.1（旧） | `04` §4（准） |
|---|---|---|
| 对齐方法 | `->align(Align::TopRight)` | `->alignment(StackAlign::TopRight)` |
| 类型 | `Align`（**本仓不存在**，`04` 也没有） | `StackAlign` 枚举 |
| 尺寸策略 | **没有这个概念** | `->fit(StackFit::Loose/Expand/PassThrough)` |
| 遍数 | 单遍（"每个子按 loose 量，取最大"） | **两遍**（先量全部子，再按容器尺寸定位） |

⇒ 已改：`02` §5.1 只保留它**独有的职责**（"`Stack` 该不该存在"的判据 + 三类需求 + 不引入 `zIndex`），
形状全部指向 `04` §4，并明写"上一版 `->align(Align::...)` 是错的"。M2-6 行已划掉。

★ 这条不改的代价：实现的人读 `02` 会写出一个**没有 `StackFit` 的 `Stack`**，
而 W6 第 34 步的出口判据要求 `StackFit` 三值 —— 到那时才发现，等于白做一遍。

### 2.5 顺带记一个"内容级"的错（脚本抓不到的那类）

`02` §9 失败模式 #6（"`Stack` 的空白区吃掉点击"）讲的是 `hitTestSelf` 返回 false，
而 `04` §11 第 1 行讲的是"命中从前往后"—— **不是重复，是同一原语的两条失败模式分住在两册**。
既然 `Stack` 已归 `04`，这条应该搬过去（`02` 只留一行指针）。
不影响执行，但说明**跨册去重只做了"定义"那一层，没做"失败模式"那一层** —— 这类碎片最容易在实现时漏掉。

---

## 3. 两处裁决（已定，附推演）

### 3.0 结论速览

| 项 | 裁决 | 一句话理由 | 落地改动 |
|---|---|---|---|
| `RenderCard` | **A：一起删** | W5 之后它的 `performLayout` / `intrinsic*` 全被基类接管，**唯一独有逻辑是那 10 行三层递减阴影**，而这段正好填了 `Modifier::paintBehind()` 的空白 | `02` 新增 §3.4（阴影迁入）+ §7.1/§7.2/§7.3 + §5 计数；`00` §6；`05` §2.3/§7 |
| `Modifier` 字段数 | **20**（改四处"17"） | 3 个合成维**不能删**：`05` §10 #9 要求 rebuild 后"回到 Widget 声明的值"，那个值只可能住 `Modifier` | `02` §3.1 末；`09` W5 第 27 步 + §12 ⑦；`10` A5 |

★ 两条裁决都牵出一个**原方案没写的东西**（下面各自标了「顺带发现」），这才是这次裁决的真正产出。

---

### 3.1 `RenderCard` 到底留不留 —— 裁决：删（选项 A）

#### 3.1.1 三方矛盾的现状

| 位置 | 说法 |
|---|---|
| `02` §7.1 | "`Card` 保留为 Widget，但它的 `child()` 返回已套好样式的 `$x`（**不再产 `RenderCard`**）" |
| `02` §7.3 破坏清单 | 只删 4 个类，**没有 `RenderCard`** |
| `05` §2.3 / §7 | "`drawShadow` 不补 —— `RenderCard` 用圆角矩形模拟已够"（**假定它还在**） |
| `02` §1 统计 | smoke 里 3 处直接引用 `RenderCard` |

#### 3.1.2 证据一：W5 之后它只剩一段阴影

逐条比对 `src/Layout/RenderCard.php`（145 行，实读）与 `02` §4 的基类设计：

| `RenderCard` 的成员 | W5 之后归谁 | 还需要吗 |
|---|---|---|
| `performLayout`：`deflate` → `$child->layout` → `setOffset(padLeft,padTop)` → `inflate` | `RenderBox::layout()` 的 ②③④⑥ 步，**逐字相同** | 不需要 |
| `intrinsicWidth/Height`：内容内禀 + padding 加回 | `RenderBox::intrinsicWidth()` 的 ② | 不需要 |
| `contentOrigin(): array` | **零消费者**（`grep -rn contentOrigin src/ tools/ apps/` 只命中定义行 `RenderCard.php:54`；`RenderPopupPanel::contentOrigin` 是另一个方法，返回 `Point`） | 不需要（死代码） |
| `paint` 的 ②底 ③边框 | `Modifier::paintBehind()` | 不需要 |
| `paint` 的 ①**三层递减阴影**（`$steps = [3 => 0.35, 2 => 0.60, 1 => 1.00]`） | **没有人接** | ★ 唯一真需要迁移的东西 |
| 构造三参 `(EdgeInsets $padding, float $shadow, BoxStyle $style)` | 三个值全在 `Modifier` | 不需要 |

⇒ 结论不是"它没用了"，而是"**它只剩 10 行阴影绘制，而这段正好是另一处的空白**"。

#### 3.1.3 证据二（顺带发现）：`Modifier::paintBehind()` 从来没有被定义过

```
grep -rn "paintBehind" docs/arch-v2/
  02 §3.1:183   hasVisual() 的注释里提到它
  02 §4.2:327   $m->paintBehind($canvas, $size);   ← 调用点
  02 §4.2:358   注释里提到它
```

**三处全是引用，没有一处是定义。** `Modifier` 的方法清单只列了 `hasPadding()` / `hasVisual()` /
`hasCompose()` 三个判定器；`withXxx()` 由 `#[\With]` 生成；`deflate()` / `inflate()` / `tighten()`
在各节零散出现 —— **`paintBehind()` 的签名、画什么、按什么顺序画，十册里全空白**。

而它恰恰是"删掉 `RenderCard` 之后阴影要去的地方"。⇒ 两件事其实是**同一个改动**：
把 `RenderCard::paint` 的 ① 原样搬进 `Modifier::paintBehind()`，`RenderCard` 就可以删。
这条不补，W5 落地时要么"阴影莫名消失"，要么临时把 `RenderCard` 留下来当既成事实 ——
两种都会让 `02` 与 `05` 的分歧永久化。

#### 3.1.4 证据三：`paintBehind` 必须留在 `Modifier` 里，不能上移到 `RenderBox`

唯一该考虑的反方案是"让 `RenderBox::paintDecor()` 读 7 个 getter 再画"。否决理由**正是本册自己的纪律**（`02` §3.1）：

> ★★ **但类内一律直读 `$this->padLeft`，绝不走 `getPadLeft()`** —— `#[\Getter]` 生成的是一次**方法调用**

`paintBehind` 要读 `background` / `hasBackground` / `borderColor` / `hasBorder` / `borderWidth` /
`radius` / `shadow` —— **7 个字段**。放 `RenderBox` 里 = 每次 paint 付 **7 次方法调用**
（每帧每盒一次，卡片满屏就是几百次/帧）；放 `Modifier` 里 = **0 次**（同类内 `private` 直读）。
⇒ **留在 `Modifier`**，并在 `02` §3.4 补完整实现。

#### 3.1.5 证据四：`Card` 怎么活 —— 三个必须一起解决的洞

`02` §7.1 写的"`child()` 返回已套好样式的 `$x`"按字面推下去，会撞三个洞：

**洞 ①：`Card` 拿不到主题。**
`Card::createRenderObject(BuildContext $context)` 里的 `$context->theme()` 是它取
`ColorToken::Pane` / `ColorToken::Border` 的**唯一**来源。改成"`child()` 直接返回套好样式的 `$x`"
之后，`child()` 的签名里没有 context ⇒ 主题取不到 ⇒ 要么硬编码颜色，要么放弃"默认取主题 token"。

修法：`Card` 改 **`StatelessWidget` 子类**。`src/Widget/StatelessWidget.php` 已有
`abstract public function build(BuildContext $ctx): Widget`，`AppBar` / `Dock` / `Flex` /
`ThemeScope` / `ErrorBoundary` 全是这个形状（各自的 `*Element extends StatelessElement`）。
把逻辑从 `createRenderObject` 搬到 `build`，主题就有 context 了：

```php
final class Card extends StatelessWidget
{
    public function build(BuildContext $ctx): Widget
    {
        $theme = $ctx->theme();

        // ★ clone：Card 的语义是"包一层"，不是"给这个控件加样式"。
        //   Widget 是可共享的（`Column::make()->children([$a, $a])` 合法，`02` §3.3）
        //   ⇒ 就地 mutate 会把样式漏给另一处引用。
        $kid = clone $this->kid;

        $style = $this->box ?? BoxStyle::none()
            ->withBackground($theme->color(ColorToken::Pane))
            ->withBorder($theme->color(ColorToken::Border), 1.0)
            ->withRadius(2.0);

        return $kid->background($style->background)
            ->border($style->borderColor, $style->borderWidth)
            ->radius($style->radius)
            ->shadow($this->shadow)
            ->padding($this->padding);
    }
}
```

★ **`clone` 有一个坑（顺带发现 ②）**：`Widget::$sealed` 是 `private int`，`clone` 会**连它一起复制**
⇒ 克隆一个已封存的 Widget 得到的**也是封存的**，紧接着 `->background()` 里 `mutate()` 直接 Fatal。
本仓今天**没有任何 `__clone`**（`grep -rn __clone src/` 空），所以这条从来没被验过。
⇒ W5 里必须给 `Widget` 加 3 行：

```php
/** ★ 克隆 = 复制一份"可再配置的描述" ⇒ 封存位必须归零，否则 clone 出来的东西写不了 */
public function __clone(): void
{
    $this->sealed = 0;
}
```

**洞 ②：`ProxyElement::expand()` 的 `instanceof Card` 分支要显式删。**
现状 `expand()` 按 `instanceof` 分派 8 个类（含 `Card` → `$widget->kid()`）。`Card` 改
`StatelessWidget` 后走 `StatelessElement`，那条分支变成**永不命中的死分支**。
而"`instanceof` 写错/写漏 ⇒ 静默不生效"是本仓咬过 3 次的坑（MEMORY 首条）。
⇒ 必须显式删，不能"留着也不影响"。W5 的出口判据加一条：
`grep -c "instanceof Card" src/Widget/ProxyElement.php` = **0**。

**洞 ③：`Card` 不能再继承 `BoxWidget`。**
`BoxWidget extends RenderBoxWidget`（"有方法 ⇒ 必须是那个类型"，否则 `syncBox()` 判假）。
`Card` 改 `StatelessWidget` 就脱离了那条链，`->background()/->border()/->radius()` 要自己写。
**裁决：保留这三个 setter + 自己持有一个 `?BoxStyle $box`**（`BoxStyle` 当值容器复用，不复制实现），
语义照旧 —— "调了任何一个 ⇒ 整套默认让位"（`Card.php` 头注释 §15.7 纪律 3）。
★ `BoxStyle` 本身**不动**：`Button` / `PopupPanel` 仍产 `RenderButton` / `RenderPopupPanel`，仍吃它。

#### 3.1.6 裁决与落地改动清单

**删 `RenderCard`**。W5 从"删 4 个类"变成"**删 5 个类 + 改 1 个 Widget 形态**"。

| # | 文件 | 改什么 |
|---|---|---|
| 1 | `02` §3.4（新增） | `paintBehind()` 完整实现：**阴影（三层递减）→ 底 → 边框**，顺序不能反（阴影在盒外，先画才不被底盖掉）；附 `$steps` 表与"为什么单层像描边"的理由（搬 `RenderCard.php:30-38` 的原注） |
| 2 | `02` §3.1 末 | "17 个字段" → **20**（见 §3.2） |
| 3 | `02` §5 | "`RenderBox` 具体子类 21 → 17（少 4 个纯修饰盒）" → **21 → 16**（少 4 个纯修饰盒 + 1 个样式预设盒） |
| 4 | `02` §7.1 | `Card` 段重写为上面的 `StatelessWidget` + `build()` + `clone` 形状；补 `Widget::__clone()` 3 行 |
| 5 | `02` §7.2 M2-3 / M2-5 | M2-3 补"`Card` 的 `paint` 阴影搬进 `Modifier::paintBehind()`"；M2-5 补 `RenderCard` 与 `instanceof Card` 分支 |
| 6 | `02` §7.3 | "`RenderPadding` 等四个类消失" → **五个**；影响里补"smoke 19 处引用含 `RenderCard` 3 处" |
| 7 | `00` §6 | "`RenderPadding` `RenderSizedBox` `RenderCenter` `RenderFlexible` 四个 RenderBox" → 五个（加 `RenderCard`） |
| 8 | `05` §2.3 / §7 | "`RenderCard` 用圆角矩形模拟已够" → "阴影走 `Modifier::$shadow` 的三层递减圆角矩形（`02` §3.4）；`drawShadow` 仍不补" |
| 9 | `09` §5/§6/§7/§10/§11 | 六处"4 个类" → **5 个**（§5 累计表、§6 回滚纪律、§7 失败模式、§10 风险、§11 工期） |

**为什么不做 B / C**（留档，防以后重开）：

- **B（保留 `RenderCard` 当"样式预设的产盒"）**：它存在的唯一理由变成"转发 `padding`"，
  而这件事 W5 之后基类在做 ⇒ 一个**零行为的类**；且 `Card` 的内距会同时存在于
  `Modifier::$padLeft` 与 `RenderCard::$padding` 两处 ⇒ 正是 §7.2 警告的"padding 应用两次"那个坑的新入口。
- **C（标 `@deprecated` 留着）**：`02` §1 的 3 处 smoke 引用继续存在，"到底走哪条路"永久悬空；
  而删它只需要改 3 处夹具断言。**留的成本高于删的成本。**

---

### 3.2 `Modifier` 是 17 个字段还是 20 个 —— 裁决：20

#### 3.2.1 两个数各自怎么来的

`02` §3.1 的代码块（实读）逐个数是 **20**：

```
布局维 4：padLeft padTop padRight padBottom
尺寸   2：width height
绘制维 7：background hasBackground borderColor hasBorder borderWidth radius shadow
合成维 3：opacity translateX translateY
意图   4：grow shrink basis alignSelf
```

而 **17 = 20 − 3 个合成维**（布局 4 + 尺寸 2 + 绘制 7 + 意图 4）。所以这不是笔误，
是**两套口径**：一套认为合成维住 `Modifier`，一套认为它不住。

四处写"17"：

- `02` §3.1 末："（17 个字段，手写 17 个方法不值）"
- `09` W5 第 27 步："`Widget/Modifier.php`（值对象，17 个字段）"
- `10` A5 标题 + 正文："`Modifier` 的 17 个 `withXxx()` 白送"、"17 个字段，17 个 withXxx()"
- **`09` §12 ⑦ 的出口判据**：`grep -c '#\[\\Getter\]' src/Widget/Modifier.php` → **17**

⇒ 这条会**挂闸门**：实现完 20 个字段、`grep` 出 20 ⇒ 判据失败；
或者实现者为了让判据过，硬砍 3 个字段 —— 两种都是坏结果。这是"文档写错会变成代码写错"的典型。

#### 3.2.2 关键问题：3 个合成维能不能删

评审初稿倾向"删 3 个（走 `05` 的 `setCompose` 直连）"。**这个倾向是错的**，理由在 `05` §10 的失败模式表里：

> | 9 | `setCompose` 之后同帧又 `rebuild` | rebuild 造的**新盒**没有 `composeDirty` ⇒ 合成参数回到 **Widget 声明的值**（**正确**：结构变更覆盖动画值） |

**"回到 Widget 声明的值"** —— rebuild 造的是**新盒**，新盒上 `composeOpacity` 是构造默认值 `1.0`。
要"回到声明的值"，那个值必须在**重建时读得到**的地方。三个候选：

| 候选 | 可行性 |
|---|---|
| ① `Modifier::$opacity` 等 3 个字段 | ✔ 值对象随 Widget 一起重建，天然可读 |
| ② `RenderBox::setCompose()` 在 `setMods()` 里被调用 | ✘ 那是"从哪读"的另一种说法，读的源头还是 `Modifier` |
| ③ 硬编码 `1.0` / `0.0` | ✘ `->opacity(0.5)` 变成**只在第一次 layout 生效**的怪行为；且 §3.3 的公开 API 里 `opacity()` / `translate()` 会变成"写了个没人读的值" |

⇒ **3 个字段是硬依赖，删不掉。20 成立。**

#### 3.2.3 那"少一条 Modifier→RenderBox 的转发"这个好处还在吗

在，但代价为零：`RenderBox` 只在 **`setMods()` 时读一次**这 3 个值去初始化
`composeOpacity/composeDx/composeDy`（构造/换描述时各一次），**不是每帧读**。
每帧走的是 `setCompose()` 直接写字段（`05` §5.4 第一步）。⇒ 转发成本 = 每盒每次 rebuild 3 次方法调用，
与 `layout()` 的 O(子树) 相比是噪声。

★ **顺手把语义写清**：这 3 个字段是**初值**，不是"当前值"。`02` §3.1 的注释要改成：

```php
// ── 合成维（D_COMPOSE，见 `05` §5.4）──
// ★ 这 3 个是**初值**：`RenderBox::setMods()` 读一次去初始化
//   `composeOpacity/composeDx/composeDy`；此后动画走 `setCompose()` 直接写盒，
//   不再回到这里。`05` §10 #9（rebuild 后回到 Widget 声明的值）依赖的正是这条。
private float $opacity = 1.0;
private float $translateX = 0.0;
private float $translateY = 0.0;
```

#### 3.2.4 出口判据的口径要统一：20 个字段 = 20 个 `#[\Getter]`

`09` §12 ⑦ 用 `grep -c '#[\Getter]'` 当闸门，那就得说清"哪些字段有 getter"。
两种口径：

| 口径 | 计数 | 问题 |
|---|---|---|
| 只给"类外要读"的字段标 | 15（`hasBackground`/`hasBorder` 类内自读，`opacity` 等 3 个在 `setMods` 里读…但 `setMods` 在 `RenderBox`，算类外 ⇒ 20） | 需要逐字段判"谁读"，判错 = 闸门失准 |
| **20 个字段全标，不搞例外** | **20** | 无 |

**取后者**：本册自己的纪律是"12 处抄同一句、漏抄不报错 —— 那是 `syncBox()` 注释里已写明的问题"，
统一形状优于省 5 个生成方法。`#[\Getter]` 生成的是编译期方法，多 5 个不花钱。
⇒ **闸门改成 `= 20`**，并在 `09` §12 注明"口径 = 每个字段一个，含 `has*` 布尔"。

#### 3.2.5 落地改动清单

| # | 文件 | 改什么 |
|---|---|---|
| 1 | `02` §3.1 末 | "（17 个字段，手写 17 个方法不值）" → "（**20** 个字段，手写 20 个方法不值）" |
| 2 | `02` §3.1 代码块 | 合成维注释改为"初值"口径（见 §3.2.3） |
| 3 | `09` W5 第 27 步 | "17 个字段" → "**20** 个字段" |
| 4 | `09` §12 ⑦ | `= 17` → `= 20`，并加一句口径说明 |
| 5 | `10` A5 标题 + 正文 | 两处"17" → "**20**" |

★ 顺带把 `02` §3.1 的 `hasCompose()` 保留 —— 它判的是"初值是否等于默认"，
用于 `setMods()` 里**跳过** 3 次无意义的写入（绝大多数盒 `opacity == 1`）。

---

## 4. 一处真 bug 级遗漏：W5 会静默改掉 IME 候选框的坐标

**这条不是记账问题，是方案没覆盖的坐标系变化。**

### 4.1 现状为什么是对的

```php
// Application.php:771
$box   = $focused->effectiveBox();          // TextField 自己的盒
$local = $state->caretRect();               // 「盒内坐标（含内边距）」—— RenderTextField.php:38 原注
[$ox, $oy] = self::absoluteOrigin($box);    // 边框盒左上角（窗口坐标）
return [$ox + $local->left, $oy + $local->top, $local->height];
```

今天 `padding` 是**祖先盒**（`RenderPadding`），所以：

```
absoluteOrigin(TextFieldBox)  ← 已经含了 pad（因为祖先就是 RenderPadding）
caretRect()                   ← 相对 TextField 自己的盒原点（= 内容原点）
两者相加                      ← 正确
```

### 4.2 W5 之后错在哪

`02` §4.1 的设计把 padding 挪进**同一个** `RenderBox`：`performLayout()` 在内容盒坐标系里摆子节点，
然后第 ⑥ 步把子节点整体平移 `(padLeft, padTop)`。于是同一个盒上：

```
边框盒原点 ──pad── 内容盒原点
absoluteOrigin(box)  → 现在是「边框盒」原点（不再含 pad）
caretRect()          → 仍是「内容盒」坐标
两者相加              → 少了一个 pad
```

| 谁 | 结果 |
|---|---|
| 插入符**绘制** | **正确** —— `paint()` 已经 `translate(padLeft, padTop)` 再调 `paintContent()`（`02` §4.2） |
| IME **候选框位置** | **偏 `(padLeft, padTop)`** —— 走的是 `absoluteOrigin`，没经过画布的 translate |
| 截图能不能抓到 | **不能** —— 候选框是**独立原生窗口**，不在应用窗口的截图里 |

⇒ 这正是 `09` §7 归纳的"只有真机能抓"的第 5 类，而它连"真机截图"都抓不到，要**真机 + 输入法**。

### 4.3 建议的三处补丁（都很小）

1. **`02` §4.1 加一节「两个坐标系」**：明写 `absoluteOrigin(box)` 是**边框盒**原点，
   `caretRect()` / `performLayout` 的入参是**内容盒**坐标；凡是"盒内坐标 → 窗口坐标"的地方
   都要补 `padLeft/padTop`。列出全部调用点（`imeCaretRect()`、`selectionRect()`、
   `Element::findBox()` 的返回盒、UIA 的 `BoundingRectangle`）。
2. **W5 出口加一条 smoke**：`padding(8)` 的 `TextField` ⇒
   `imeCaretRect()[0] === absoluteOrigin(box) + 8 + advance(prefix)`。
   无头可验（`imeCaretRect()` 是纯几何），**不需要真机**。
3. **W5 真机验收加一条**：`apps/users` 的搜索框加 `padding` 后，
   **中文输入法候选框贴住插入符**（不是贴屏幕左上角、不是偏 8px）。

★ 顺带确认一个相关点：`01` §6 已经处理了"浮层里的 IME 候选框"（浮层原点要进 `absoluteOrigin`），
说明这条链是有前科的 —— 更该在 W5 的破坏面里显式列出来。

---

## 5. 三个结构性风险（不是错，是"要知道自己在赌什么"）

### 5.1 文档即规格，但没有防漂移机制

十册的价值在于 `file:line` 精确到行（我验了 78 处，全对）。但 **W0 一动 `src/`，这些行号就开始失效**，
而没有任何机制会发现。`09` §14 的"待定项"里也没有这一条。

⇒ 建议：把本次评审用的两个脚本固化进仓库（约 120 行，零依赖）：

```
php tools/docref.php        # 校验 docs/arch-v2/*.md 里的 file:line 与跨册 § 引用
```

跑在 W0 的第一次提交之后，之后**每波收口跑一次**。它抓不到"内容错"，但能抓"指针腐烂"——
而指针腐烂是这类文档最真实的死法。

### 5.2 验收不对称：1002 条 smoke 全无头，四类失败靠人眼

`09` §7 诚实列了四类"只有真机能抓"的失败，处置是"真机 N 张图"。这是对的，
但全案**定量的真机截图只有 10 张**（W1 3 / W2 1 / W3 1 / W4 3 / W6 2）+ W5 一次"全部页面逐页对比"，
而方案新增的能力（路径填充 / 裁剪 / 合成 / 覆盖层 / 修饰）每一类都是"像素级对错"。

⇒ 建议：`05` 的 `golden-raster.php` 已经是逐行比对的成熟量具，把它从"19 个 op 的基线"
扩到"**每个控件一个基线**"（`Button` / `Card` / `Checkbox` / `TextField` / `Stack` 角标）。
成本不高（`RecordingCanvas` 已有），收益是把"人眼看图"压到"只看新增控件"。

### 5.3 W0 的 1.5 天估工偏乐观

W0 里塞了 6 件事：M0-a/b/c（3 个基准 + 读生成物 + 写结论）、`LayoutSelfCheck`、
`FramePhase` 断言、`markDirty` 升 `@api`、**lint `attribute-unqualified`**、
**30–40 处 `#[\Override]` 标注**、`RenderBox` 四字段、`D-1`/`D-2` 两个文档动作。

其中"30–40 处标注"要逐个判断"父类有没有默认实现"（判断错 = 编译错，要回查），
"lint + 夹具 + selftest"是四改，`LayoutSelfCheck` 要配真机开关。**2.5–3 天更现实。**

★ 这不影响方案成立，但 W0 是"后面每一波的前置"，估短了会挤压后面的真机验收。

---

## 6. 不该改的地方（防止过度反应）

评审容易引发"顺手重构"，先把边界画出来：

| 看起来可以简化 | 为什么不要动 |
|---|---|
| `settle()` 的"`final` + 计数器差值 + 拆 `settlePass`" | 三条理由（引用闭包在 AOT 未实测 / 覆写面最小 / 计数器免费）都成立。退回"两遍固定"会丢掉多轮收敛 |
| `Computed` 的拉取式 + `Versioned` 接口 | 拉取式天然无 glitch 的论证是对的（改只置脏，读在写之后）；`Versioned` 是**唯一**不破坏"业务层零 GUI 依赖"的选项 |
| `02` §4.1 的"平移子节点"（第 ⑥ 步） | 用一次坐标变换换掉 20 个容器的改动，这是全案最漂亮的一处。不要因为"多一次 visitChildren"就改回逐容器处理 |
| `05` §6 每帧全量重录 | 是**取舍**不是错（3.1ms 有 5.3× 余量，retained 的失效逻辑是 v6 造自校验要兜的那类复杂度） |
| `02` §5.1 整节删掉（因为 `Stack` 归 `04`） | "要不要自己的 `measure()`"这条判据是 `02` 的职责，`04` 不回答它 |
| 十册合并进 `DESIGN.md` | `09` §11 已裁决：先落地再合并 |

---

## 7. 一句话总结

> **推演的部分是可靠的，记账的部分不可靠。**
> 前者靠的是把每个选择推到"如果错了会怎样"，后者靠的是人肉对齐 —— 而人肉对齐已经错了五处，
> 其中一处（`grep` 判据 17）会直接卡住实现。
>
> 建议：**修完 §2 已改的五处 + 定 §3 的两处 + 补 §4 的三条**再开 W0。
> 总成本半天，换掉的是"实现到一半发现文档自相矛盾"的返工。

---

## 附：本次评审用到的两个检查（可直接复用）

```bash
# ① file:line 是否越界（78 处）
# ② 跨册 § 引用是否断链（406 处）
# 两者都是纯文本 + 文件行数，无依赖；建议固化为 tools/docref.php（§5.1）
```

判据：**① 越界数 = 0；② 断链数 = 0。** 当前两项都是 0 —— 这是这份方案值得继续投入的理由。
