# 评审 · `DESIGN-UI-DATA-DX-FIRST-2026-11-11.md` 的 v2（§11）· 2026-11-12

> **后续（2026-11-12 同日）**：v2.1 又经一轮**可实施性复核**，产出
> `docs/DESIGN-UI-DATA-FINAL-2026-11-12.md`（最终方案）。那一轮改了本文没覆盖的七处
> —— 迁移单位（页面 → 订阅宿主类）、`deps` 来源（推导 → 搬表达式）、门控比较方式、
> `properties` 不收构造器提升导致的判据假绿等。本文的 A/B/C 各条在最终方案 §13 有逐条裁决。
> 本文保留为评审记录，不再是开工依据。
>
> 对象：`docs/DESIGN-UI-DATA-DX-FIRST-2026-11-11.md` §11（v2，1137 行版本的最终裁决）。
> 基线 HEAD `5f26dfc`。只读评审：**本轮未改 `src/`、未改方案稿、未新增判据**。
> 判据：v2 的每条主张都要能在仓库里找到落点；找不到落点的，标「需裁决」而不是「错」。

---

## 0. 一句话结论

**方向对，形状没闭合。** 三条裁决（普通读 + 构建期生成 + 数据平面）在成熟框架里有先例、
在「性能后置」的框架下也成立；但 v2 的具体形状有五处**落不了地**的硬冲突，
其中两处会把方案的核心收益抵掉：

1. `final readonly class Row` + `field('name')` 在现行 `TableColumn::textOf()` 上**必然 Fatal**
   —— 现行契约明写「关联数组行用 `field()`，对象行用 `cell()`」；
2. `readonly` 在 PHP 里**不提供值相等**（对象 `===` 是身份比较）⇒ §11.4 #2 声称被
   「结构性消除」的坑，一个都没消除，只是从 `array<User>` 平移成 `array<Row>`。

另有两条**方案内部矛盾**（零新增书写物 vs 手写 Row 类；删 `select()` vs 保留逃生口），
和一条**依据链缺口**（评审稿 §11.4 对 C1 的裁决是「最后做」，v2 提前到 G2 且没回应理由）。

结论：**先补 §5 的四条修正再进 G0**，否则 G1 一开始就会撞上 `textOf()` 的守卫。

### 0.1 处置登记（2026-11-12 同日回填）

方案稿已按本评审改成 v2.1（`DESIGN-UI-DATA-DX-FIRST-2026-11-11.md` §11），逐条对账：

| 评审项 | v2.1 处置 | 落在哪 |
|---|---|---|
| A1 对象行 + `field()` 冲突 | 行保持 `array<string,scalar>`，`field()`/`editable()`/`copyText()` 零改动 | §11.5.3、§11.6 表 |
| A2 `readonly` 无值相等 | 撤 `readonly class Row`，改标量数组 + `FIELDS` 常量 | §11.4 #2、§11.5.3 |
| A3 零新增书写物自相矛盾 | 收窄为「`build()` 里零新增」，承认表要加一句 `FIELDS` | §11.5.2 |
| A4 生成层接线 | 补三条硬约束（生成在 `collectSources()` 前、不落 `_codegen/`、进 git + 第 10 条判据） | §11.6 |
| A5 谁持有订阅 | `ViewBinding` 空壳改成 `extends BindingHost`；明写 `SubjectBuilder` 下沉、不删 | §11.5.5 |
| B1 门控粒度退化 | 写成明确取舍表；G2 出口加重建节点数判据 | §11.5.5、§11.6 |
| B2 deps 字符串自相矛盾 | `deps()` 改成返回方法调用结果；撤「信息密度更高」 | §11.2、§11.5.5 |
| B3 `onChanged` 粒度不匹配 | 降 P2，本轮不动，理由三条 | §11.5.4 |
| B4 逃生口与 G3 互斥 | `select()` 保留作逃生口，G3 判据带豁免名单 | §11.8、§11.6 |
| B5 评审稿对 C1「最后做」 | 新增 §11.3.1，逐条回应三条顾虑 | §11.3.1 |
| B6 `dead-field` 类型推导 | 降级为 `FIELDS` 常量 ↔ 字面量两端对账 | §11.4 #1、§11.8 |
| C1~C4 | 全部采纳（行数组 / G0 换口径 / G2 量测 / 破坏面补口径） | §11.5.3、§11.6 |

---

## 1. 已核对的事实锚点

评审中实测的部分（可复现，命令口径见各行）：

| 事实 | 证据 |
|---|---|
| 破坏面 7 项里 6 项与文档**完全吻合** | `grep -rc -e "->m(" --include=*.php apps src tests`：`builder` 45 / `keyOf` 27 / `cell` 3 / `onChanged` 90 / `onSelectionChange` 5 / `command` 83 |
| `select` 的 57 也可复现，但**口径必须写明** | = apps 25 + src 16 + tests 16；其中 `src/Widget/Select.php` 的 10 处是**下拉控件**同名方法，不属于要删的订阅入口。只算 apps 是 **25** |
| 行是关联数组，`field()` 只认数组 | `src/Widget/TableColumn.php:361`：`if (!\is_array($row)) { Errors::report('…用了 field()，但第 %d 行不是关联数组…修法：对象行 / 派生值改用 cell()'); }`（`textOf()` 在 `:357`） |
| `editable()` 强绑 `field()` + 数组 | `TableColumn::editableText()`（`:283`）、`TableContract::collect()`（`:168`）、`TableState` 的提交路径（`:954`）都按「字段名 + 数组下标」取值 |
| 生产的表格行是关联数组 | `apps/showcase/src/Pages/TablePage.php:180` `Table::make(self::columns(), $this->rowsNow())`，列定义 443-490 全是 `field('id')` / `field('name')` / `field('email')` / `field('city')` + 一条 `cell(fn(array $row))` |
| 订阅的载体是 `State`，不是 `Element` | `src/Widget/State.php:283` `watchSlot(string, Subject, callable)`；`StatelessWidget` 没有 `State` |
| 每次 `build()` 的新 Widget 实例必触发全子树 update+rebuild | `Element::reuseChild()`（`:589`）：`if ($element->widget() !== $newWidget) { $element->update($newWidget); }`，而 `Widget` 无「同值复用」缓存 ⇒ `!==` 恒真 |
| `drift-codegen.php` 是**翻译门**，不是生成器 | 全文 434 行：`collectSources()` 只枚举 `src/` + `apps/*/src`，`writeProjectYml()` 只写 `project.yml`，无 AST、无 PHP 发射 |
| `ClassTable` 只有类型关系，没有方法签名 | `tools/Lint/ClassTable.php` 133 行，公开面只有 `all/byFile/implementorsOf/subtypesOf/descendsFrom` |
| 评价评审稿对 C1 的原裁决 | `REVIEW-UI-DATA-2026-11-11.md:646-661`：「C1 最有长期价值**却该最后做**……B 还没落地就先做 C1，等于给未验证的形状写生成器」 |

---

## 2. 阻断级：五处不改就落不了地

### A1 · 对象行 + `field('name')` 与现行表格契约正面冲突

v2 §11.5.3 把行改成 `final readonly class UserRow`，§11.5.4 又保留
`TableColumn::make('name','姓名')->field('name')`。这两条在现行代码上**不能同时成立**：

```php
// src/Widget/TableColumn.php:361（现状，逐字）
if (!\is_array($row)) {
    Errors::report(sprintf(
        'Table 的列 "%s" 用了 field("%s")，但第 %d 行不是关联数组（%s）。'
            . "\n" . '修法：对象行 / 派生值改用 cell(fn($row): Widget => ...)。',
        ...
```

`textOf()` 只接受 `array_key_exists($field, $row)`；对象行走到这里直接 `Errors::report`。
要让 `field()` 支持对象，只有三条路，代价都不小：

| 路子 | 代价 |
|---|---|
| `$row->{$field}` 动态属性 | AOT 下退化成 `php::Var` / 动态取属性，与 v2「信息密度更高」的前提相反 |
| `Row::value(string $name): string` + `match` | **字段表回到两处手写**（属性 + `match`），正是 v2 §11.3 说「不要平行书写物」要避免的 |
| 生成访问器 | 生成器要生成数据形状 —— 评审稿 §11.4 反对的正是这个 |

连锁面还不止 `textOf()`：`editableText()`、`copyText()`、`TableContract::collect()`
的 `'editable'` 能力检查、`TableState` 的单元格提交，四处都按「字段名 → 数组下标」写死。
v2 §11.6 的破坏面表只统计了 `keyOf` 27 处和 `cell` 3 处，**没有一行提到
`field()` / `editable()` / `copyText()` 要跟着改**——而它们才是「行形状」的实际消费者。

### A2 · `readonly` 不提供值相等：坑 #2 没被消除

v2 §11.4 第 2 行写：

> 行统一 `final readonly class Row` 全标量 ⇒ `===` 结构上稳定；顺带解 §3.3 `array<User>` 门控盲区

**这是 PHP 语言事实层面的错**：`readonly` 只约束「属性初始化后不可改」，不改变比较语义。
两个内容完全相同的 `new UserRow(1,'a')` 用 `===` 比是 `false`（对象比较是**身份**比较），
只有 `==` 才是逐属性比较。列成表：

| 行形状 | `$a === $b` 的语义 | 是否「结构上稳定」 |
|---|---|---|
| `array<string,scalar>` | 逐键 + 逐值 + 键序 + 类型全等 | **是**（真正的值语义） |
| `array<User>`（现状） | 逐元素比，元素是对象 ⇒ 身份 | 否（这就是 §3.3 的盲区） |
| `array<UserRow>`（v2 提案） | 同上，元素是 readonly 对象 ⇒ **仍是身份** | **否，与现状同级** |

后果直接：`Store::rows()` 每次刷新都 new 一批 `UserRow` ⇒ 数组 `===` 恒不相等 ⇒
整表重建。这与 v2 声称修掉的坑 #2（selector 返回新对象 ⇒ 白重建）是**同一个坑**，
只是换了类名。本仓已有正确解法且就在同文件里：`SubjectBuilderState::sameValue()`
（`:321-328`）—— 两边都 `Equatable` 才按值比。要按值比，`Row` 必须 `implements Equatable`
**并且**门控对数组做逐元素 `equals()`（现在的 `sameValue()` 不做这一步）。

> 顺带：§11.5.3 那句「`===` 在标量上是稳定的值比较」如果本意是「行内字段都是标量」，
> 那它论证的是「装箱成本低」，不是「数组门控稳定」——两者不能互相顶替。

### A3 · 「零新增书写物」与「手写 Row 类」互斥

§0.1 第 1 条：「开发者**零新增书写物**」。
§11.5.3：「设计草案：**手写一次**，字段表就是 `dead-field` 的事实源」。

这两句不能同时为真。实际净增：

```php
final readonly class UserRow            // +10 行左右，每张表一个
{
    public function __construct(
        public int $id, public string $name, public string $email,
        public string $city, public string $status,
    ) {}
    public function key(): int { return $this->id; }
}
```

对「一个页面一个 Store」的小应用（本仓 `apps/users` 就是），这是**新增一个类**换掉
「3 行 `select()`」。DX 账要如实记：v2 省的是「漏订阅」这一类 bug 的**可能性**，
付出的是「每张表多一个 DTO」。这与「更少写」不是同一个方向，§0.1 的措辞要改。

### A4 · 生成层与 gate 的接线未定义，且首次运行就会红

`drift-codegen.php` 的通过条件是**双向严格相等**：

```php
// tools/drift-codegen.php:389-399（现状）
$missing = array_diff(array_keys($expected), array_keys($converted));   // 枚举了却没 convert
if ($prepared !== null && $prepared !== count($expected)) {             // tpc 自己数 ≠ 我们枚举
    $problems[] = "tpc 自己数到 {$prepared} 个源文件，我们枚举到 " . count($expected) . ' 个 —— 两边必须一致';
}
```

生成物落在 `apps/users/src/Generated/` ⇒ 会被 `collectSources()` 收进 `$expected`。
于是第一次运行（生成物还不存在）与第二次（存在）的枚举数不同，而**两次都可能被判红**：
第一次缺文件、第二次 tpc 与枚举不同步。要让这条门稳定，必须：

1. 生成步骤**排在 `collectSources()` 之前**，且同一次进程内完成；
2. 生成物落盘位置不能是 `_codegen/`（`removeTree($buildDir)` 每次删）；
3. 「生成物进不进 git」必须现在定 —— 不进 git，fresh clone 上 `php -l` / `lint` 会因为
   缺类而红（`lint` 要解析 `class X implements ViewBinding`）；进 git，则生成物与源码
   是两份事实源，`build-stale-check` 要新增一条「生成物与输入一致」的判据。

v2 §11.8 只提了「构建指纹必须收生成输入」，**没提 A4 这三条**。而本轮刚因指纹漏
`.cc` 修过一次——同类洞（判据全绿但能力假的）在生成层上的形态就是「枚举数漂移」。

### A5 · 「谁订阅、谁 markDirty」没定义；删掉 `SubjectBuilder` 后没有订阅者

这是最实的一条。现行订阅链是：

```text
SubjectBuilder(StatefulWidget) → SubjectBuilderState(State) → State::watchSlot() → Subscription
                                                                   ↑ src/Widget/State.php:283
```

`StatelessWidget` 没有 `State`，也**没有** `watchSlot`。`StatelessElement::rebuild()`
是 `updateChildren($this->expand())` 直接进 `build()`，中间没有任何钩子。
所以 v2 的「页面 `build()` 里普通读，框架自动订阅」在 `UsersPage`（`StatelessWidget`）
上**没有落点**：删掉 `SubjectBuilder` 之后，没有任何对象持有那条 `Subscription`。

能想到的落点只有三个，都不在 v2 的「未改 `src/`」边界内：

| 落点 | 要改什么 | 问题 |
|---|---|---|
| 生成物提供 `BindingHost`（一个 `StatefulWidget`，内含生成的 deps） | 只改生成层 + `apps` | **可行，但等于把 `SubjectBuilder` 藏进生成物**，不是「删掉它」 |
| 给 `StatelessElement` 加订阅窗口 | `src/Widget/Element.php` | 就是 §10.3 撤回 C2 的理由之一（「两个 Element 的 `rebuild()` 要补窗口」） |
| 页面改成 `StatefulWidget` | `apps` 全量 | `UsersPage` 等页面要新增 `State` 类，DX 更差 |

而且**即使走第一条**，还有一个 AOT 硬问题：生成物怎么拿到 Store 实例？
`ViewBinding::pageOf(UserStore $store)` 这个签名是反的（页面由 `Application::buildRoot()`
造，不由 binding 造）。Element 层也不可行——它只有 `Widget` 对象，Store 在页面的
私有构造参数里，AOT 下无反射可读。

⇒ 最短自洽形状是：**生成一个 `SubjectBuilder` 的等价包装**，`select` 换成生成的
deps 闭包，`builder` 换成页面的 `build()`。这样不用改 `Element`。
但必须诚实写成「`SubjectBuilder` 下沉到生成物」，而不是「删掉 `select()`」——
否则「删 select」只是改名。

---

## 3. 需裁决：六处不是错，是没定

### B1 · 门控粒度从 per-slice 退化到 per-build，且这属「不可逆」那一类

现行 `UsersPage` 有四个独立订阅，文档 §1.3 自己写明了收益：

> 四个读点对应四份订阅，各自独立门控：改搜索字不会让列表重建，刷新中不会让搜索框重建。

v2 把四个读点合并成一个 `build()` 的读集上界 ⇒ **一个订阅、一次整树重建**。
而且 `Element::reuseChild()` 的 `widget !== newWidget` 恒真 ⇒ 整树是**真的**全
update + rebuild，不是「描述没变就跳过」。

这个代价不属于「调用开销」，属于**重建工作量**（算法级），正是上一轮 §9 分层里
归到「不可后置」的那一类：编译器能把虚调用变直调，不能把「整树重建」变成
「只重建一个输入框」。所以 §11.2 的「性能后置」不覆盖这一条。

两条出路，**必须选一条并写进 G2 的出口判据**：

- **粗门控 + 承认**：接受「改一个字重建整页」，把 §1.3 那段收益说明改掉，并在 G2 出口
  用现成的 `tools/frame-tax-probe.php` / `probe-frame-gates.php` 量一次「输入一个字符的
  重建节点数」，与 v1 对比。
- **生成分区**：生成器把 `build()` 体按读点切成 N 个订阅区。**注意这是量级不同的工作**：
  从「抽 deps 列表」变成「重写 `build()` 的方法体」，需要真正的 AST 级重写 +
  AOT 约束检查。v2 §11.5.5 的产物只有 `deps()` + `pageOf()`，显然打算走粗门控，
  但文档没承认这个取舍。

### B2 · 生成物 `deps(): array` 返回字符串，与「擦掉素材」的批判自相矛盾

§11.2 说「把读路径压成一个名字反而擦掉了素材」；§11.5.5 的生成物是：

```php
public static function deps(): array
{
    return ['page.isRunning', 'query', 'rows'];   // 字符串
}
```

这正是它批判的那个动作。要么 deps 是类型化的方法调用（`fn($s) => [$s->page()->isRunning(), $s->query(), $s->rows()]`，
信息密度与 v1 的 `select` 相同但不用人写），要么承认「生成物信息密度**低于**闭包」，
把 §11.2 最后那句「信息密度比闭包更高」删掉。二者只能选一个。

### B3 · `onChanged(callable) → 'name'` 的粒度不匹配，是 90 处换一个新坑

v2 §11.5.4 把写通道收编为字符串名。问题在于**作用域**：

- `command('users.refresh')` 是**全局**动作，路由表天然对得上；
- `onChanged` 是**控件实例**的事件，而且最需要它的是 `TableColumn::editable()` 的
  **行内编辑**（`TableState:954` 用 `$column->editableText($rows[$row], $row)` 起编辑），
  提交时需要知道 **(哪一行, 哪一列, 新文本)** 三元组；
- `CommandArg` 只有两个槽（`i` / `s`），装不下三元组。摊开成三个参数就要改
  `CommandHandler::handle(string, CommandArg)` 的签名 —— 那是 83 处 `command()` 的
  共同签名，动它等于动全仓命令系统。

另外这条改造是**热路径**：每次按键一次路由查表 + `handle()` 分派，而现在是一次直接
调用。收益是「拼错构建期红」，而 `onChanged` 拼错的后果本来就是「没反应」——不是
难查的那一类（对比 `field()` 拼错会静默整列空白）。

**建议降级为 P2**：读通道先做，写通道等 `Table` 的行编辑需求给出真实的复合参数形状再定。

### B4 · 逃生口自相矛盾

- §11.8：「保留 `select()` 作逃生口、或 `@deps` 显式声明，二者择一待裁决」；
- §11.6 G3 出口判据：「全仓 **0 处**旧入口」。

这两条互斥。要么 G3 的判据改成「0 处旧入口，除登记豁免的 N 处」，要么 §11.8 删掉。
按 A5 的结论，**保留 `select()` 作逃生口更可能是对的**（唯一真盲区
`watermark/StatusBar::slice()` 需要一个不依赖静态提取的通道）。

### B5 · 依据链缺口：评审稿对 C1 的裁决是「最后做」

v2 两处声明依据是评审稿 §11/§12（§11 的开头、§9 的表）。但评审稿 §11.4 的原裁决是：

> C1：构建期生成，为什么最有长期价值却**该最后做** …… B 还没落地就先做 C1，
> 等于给未验证的形状写生成器。⇒ 顺序：先 B 有真实消费者，再让 C1 把 B 的样板生成掉。

v2 的 G 序列是 G1（B1+B4 的一部分）→ **G2 直接上生成层**，跳过 B2（`CellSpec`）与
B3（枚举投影名）。v2 §11.3 换了一个论据（「本仓有 `drift-codegen` 这道 T1 门」），
但**没有回应原论据**（形状未验证）。这两件事没关系：有门 ≠ 形状已验证。

要么在 §11.3 补一段「为什么这次可以提前」的可证伪理由，要么把 G2 推迟到 G1 有
真实页面跑一个迭代之后。按目前文本，这是**选择性引用**。

### B6 · `dead-field` 的事实源比文档写的远得多

v2 §11.4 #1：「一端来自 `Row` 字段表（AST 事实源）、一端来自字面量 ⇒ 构建期对账。
**两端不同源**，避开 §8 判定的同源漏洞」。前半句对，后半句的类比错：

`dead-command` 之所以便宜，是因为**两端都是字符串字面量**（控件里的 `'users.refresh'`
↔ 路由表的键），比集合即可，不需要类型信息。

`dead-field` 要做的是：`Table::make(self::columns(), $rows)` 里的 `$rows` 元素类型是
`UserRow`，然后 `UserRow` 有哪些属性。这需要：

1. 从 `$rows` 表达式反推类型 —— 生产代码里是 `$this->store->rows()`，
   要跨方法读 `UserStore::rows()` 的 `@return list<UserRow>`；
2. 把 `UserRow` 的属性列表抽出来（token 级可做）；
3. 在 `self::columns()`（另一处静态方法）里声明的 `field('id')` 与第 2 步对账。

第 1 步本仓**没有零件**：`tools/Lint/ClassTable.php` 133 行，只有
`all/byFile/implementorsOf/subtypesOf/descendsFrom`，**没有方法签名表，没有返回类型**。
这不是「`dead-command` 同款」，是 `x:Bind` 级的类型推导。G1 的工作量被低估了，
而且它决定了 `dead-field` 到底能不能做成「零配置」。

可选的降级：`field()` 的对账只做到「列 key 与本表某处声明的字段清单」，
字段清单由 `Row` 的 `FIELDS` 常量显式给出（一行数组字面量），不靠类型推导。
这样仍是两端不同源（常量 vs 字面量），但便宜得多——**建议在 G1 里裁决走这条**。

---

## 4. 建议

| # | 建议 | 为什么 |
|---|---|---|
| C1 | **行保持 `array<string,scalar>`，不做 readonly DTO** | PHP 数组 `===` 本身就是值语义（键序 + 类型全等），天然解掉坑 #2；`field()` / `editable()` / `copyText()` 零改动；`dead-field` 的事实源可以是一行 `FIELDS` 常量 |
| C2 | G0 的判死口改口径 | 现写「复现 36/37」，但 36/37 是**闭包内**读点的可提取率，v2 要扫的是 **`build()` 方法体**，样本不同。改成「对真实 `UsersPage::build` 复现读集，且**故意漏一个读点必须红**」 |
| C3 | 把粒度的量测提到 G2 出口 | 用现成的 `frame-tax-probe` / `probe-frame-gates` 量「输入一个字符的重建节点数」。等 G4 才发现整页重建翻倍，回滚成本是整个 G3 |
| C4 | 破坏面表补口径 | `select` 的 57 = 25 apps + 16 src + 16 tests，其中 `src/Widget/Select.php` 的 10 处不属于订阅入口；只算 apps 是 25。另外补上 `field()` / `editable()` / `copyText()` 的连锁改动 |

---

## 5. 最小可行修正（把 v2 收到能落地的形状）

如果只允许改四处，按这个顺序：

**① 行形状退回标量数组，`Row` 只做「字段清单」不做「DTO」**

```php
// 设计草案：一行常量 + 一个类型别名，不新增类层级
final class UserRows
{
    /** @var array<string, string> 字段 ⇒ 展示名；dead-field 的事实源 */
    public const FIELDS = [
        'id' => 'ID', 'name' => '姓名', 'email' => '邮箱',
        'city' => '城市', 'status' => '状态',
    ];
}
// 行仍是 array<string, scalar> ⇒ field()/editable()/copyText() 零改动
// 值语义由 PHP 数组给出 ⇒ 门控天然稳定，不需要 Equatable
```

**② 生成物下沉一层，不假装删掉了 `SubjectBuilder`**

```text
生成物 = 一个 BindingHost（StatefulWidget）
         · deps 闭包      ← 生成的，不用人写
         · 页面 build()   ← 人写的，普通读
页面的 select()/builder() 消失，但「订阅得有 State 持有」这条结构事实不变。
```

这样 `src/` 一行不改、`Element` 不用碰、A5 那条链闭合。

**③ 写通道（`onChanged`）先不动**，降级为 P2，等 `Table` 行编辑给出复合参数形状。

**④ G2 出口加一条量化判据**：改一个字符 ⇒ 重建节点数不超过 v1 的 K 倍（K 现场定）。
没有这条，G3 全量迁移完成后就没有回滚点了。

改完这四条，v2 的三条裁决仍然成立，但**「零新增书写物」要改成「不新增订阅书写物，
新增一行字段清单」**，「删掉 `select()`」要改成「`select()` 下沉到生成物」。

---

## 6. 值得肯定的部分

评审不能只有问题清单。这几点该保留：

| 项 | 为什么对 |
|---|---|
| 破坏面 7 项里 6 项**逐字可复现** | 45/27/3/90/5/83 全部对上；`select` 的 57 也能复现，只是口径要写清楚 |
| 坑 #5 的裁决（**不做增量通知**） | Qt `begin/endInsertRows` 成对纪律 + WPF 跨线程异常，本仓 `Subject` 粗广播确实不该动 |
| 坑 #6 的裁决（**不做运行期记账**） | 理由从「零诊断」改成「运行期记账这条路线本身有坑」，这个更正比原稿准确 |
| 撤掉 v1「零命中 ⇒ 全不做」的判死条件 | 对：零命中只说明没写错，不说明框架看得见 |
| `11.5.6` 那张「写错 → 反馈」表 | 是方案里最可验证的一节，每条都能落到一条判据 |

---

## 7. 诚实边界

- 本轮**未改任何代码**，未新增 lint / 判据 / 夹具；所有结论基于 HEAD `5f26dfc` 的静态阅读。
- A1/A2/A5 是**代码事实**（有行号）；B1/B3 的量级判断基于 `Element::reuseChild()` 的
  实现，**未跑真机量测**——所以 C3 要求补测，而不是判定「一定变慢」。
- A4 的「枚举数漂移」是从 `drift-codegen.php` 的断言反推的**必然结果**，
  但生成层的具体形式（同进程内生成 vs 独立前置脚本）未验证，两种都可行。
- B5 引用的是评审稿 §11.4 原文；如果那段已被后续裁决覆盖，本条作废——
  但截至本轮，`REVIEW-UI-DATA-2026-11-11.md` 没有指向 v2 的修订行。
- B6 的「`ClassTable` 无方法签名」是 `grep` 公开方法得出，不排除有私有推导逻辑；
  但即便如此，重复实现一套跨方法类型推导仍是 G1 的真实成本。
