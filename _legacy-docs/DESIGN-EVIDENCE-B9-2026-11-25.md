# 设计证据 B9：手册是声明的视图，参数名要能咬住

日期：2026-11-25 ｜ 上接 B7（Registry）与 B8（finish）

## 1. 先证伪推荐

上轮留下的推荐是「把 `docs/reference/cli.md` 的调试手册接到 Registry/schema」。实测比这句话更糟：

```
php -r 'require "tools/autoload.php"; ... foreach(Registry::all() ...'
brief=1 report=1 panel=1 host=1 serve=1 ctl=1 web=1 loop=1
network=0 node=0 pick=0 props=0        ← 四个出口在手册里一次都没出现
```

```
手册调试段的 --token  ∩  schema 全集的规范名 + 别名
未知：width            ← 规范名已是 view-width，手册写的是别名
```

所以问题不是「手册没生成」，而是三件不同的事：出口有漏、参数名有别名/正名之差、
每个出口的特有参数只在 `--help` 里活着。任何只做其中一件的方案都会留下另一半漏洞。

## 2. 候选方案

| 方案 | 做法 | 优点 | 代价 / 坑 |
|---|---|---|---|
| A 全量生成整篇 `cli.md` | 连散文一起搬进代码/模板 | 零漂移 | 散文（语义、踩坑、链接）本来就该住在文档里；搬进 docblock 后 doccheck 的链接检查反而失效，改一次说明要改代码 |
| B 只做校验 | doccheck 比对动词清单与 `--token` | 改动最小 | 新增出口时手册仍不显示它；「有哪些出口」还得去翻 help；校验器误伤散文里非调试段的 `--strict` 等词 |
| C 生成块 + 闭合校验（选） | Registry/schema 生成一段紧凑速查表；散文保留语义；校验器咬住生成块与散文里的参数名 | 枚举事实只有一个来源；散文仍可读；新增出口会同时进表并因散文未提及而报红 | 多一个生成步骤，必须让「忘记重生成」比「重生成」更容易被发现 |

成熟 CLI 文档（Flutter/ng 的命令参考）也是「自动生成的命令面 + 手写的语义说明」两层，
不把 use-case 散文塞进 schema 注释。这里照这个分工。

## 3. 裁决

1. `tools/Debug/Cli/Doc.php` 是文档视图的唯一实现：
   - `Doc::block()` 从 `Registry` 生成 12 行出口表（动词 / 脚本 / driver|protocol / 摘要 /
     特有选项，特有选项来自 `Commands::extras()`），从 `OptionSet::driver()` 生成共用词表
     （类型 / 默认 / 别名 / 说明）。
   - `Doc::problems($markdown)` 返回问题列表：标记缺失或多份、块内容与 `block()` 不逐字节相同、
     注册出口在散文里未提及、手册调试段出现 schema 不认识的 `--token`。
   - 散文段取 `## 手工工具` 到 `## 环境与依赖脚本` 之间；生成块内的 token 不参与闭合检查
     （它由 schema 生成，自己检查自己没有意义）。
2. `tools/gen-cli-doc.php [--check]` 只做写回或核对，不实现第二份生成逻辑；
   手工入口与 `gen-route-manifest.php` 同级（直调工具，不进 V2 runner）。
3. 判据落在 `tests/Feature/Debug/CliDocTest.php`（进 `--testsuite=feature`）；
   `tools/doccheck.php` 复用同一个 `Doc::problems()`，不另写一套解析。
4. 兼容：不改任何运行时参数名。手册把 `--width` 改成规范名 `--view-width`，同时保留别名
   在生成表里——文档转正名不是破坏兼容，是承认 schema 已经这样定义。
5. `network/node/pick/props` 必须在散文里各有一句，否则闭合判据红。这是补文档，不是补代码。

## 4. 判据与变异

正向：`CliDocTest` 检查生成块逐字节一致、12 出口都有散文提及、参数 token 全部闭合、
生成器自己不漏动词/共用词；反向至少六组变异要咬红：
删标记、篡改生成块的一行、删散文里的一个出口、散文写错参数名、生成器漏一个共用词、
新增出口不写散文。

验收链：PHPUnit（Debug + 全量）、`verify --full`、`tools/doccheck.php`、`tools/lint.php`。

## 5. 结果

落地时又抓到两处实测偏差：`loop` 在整篇 grep 里命中其实是 `str-concat-loop` 误报，
散文里并未提到它；`gen-route-manifest.php --check` 与调试出口同在一张手工表里，
参数闭合必须只取调试行，否则把别人的 `--check` 当幽灵参数。两处都已修进判据。

六组源码级变异全咬红：新增未写散文的出口 / 删共用词 `resolve` / 改特有参数名 `screen`
/ 改出口摘要 / 校验器自废 `return []` / 手册写错 `--screen`。每组跑完即还原，
最后 `gen-cli-doc.php --check` 仍绿。

`CliDocTest` 3 用例 / 38 断言。`php bin/drift test` 949 用例 / 3635 断言、
`verify --full` 18 项全过、`doccheck` 30 篇 / 194 链接 / 74 裁决、`lint` 1399 文件 0 error。
