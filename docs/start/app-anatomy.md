# 应用解剖

> 这篇拆开一个应用的五个文件，说明各自职责与会静默失败的坑，并对比 dev 壳与 AOT exe 两条构建产物的差异。

## 五个文件

| 文件 | 职责 | 你要改什么 |
|---|---|---|
| `main.php` | 入口：全局 `main()` 转给组合根 | 通常不改 |
| `project.yml` | 告诉 tpc 这个程序由哪些源码组成 | 名字、描述等本应用信息 |
| `bin/win32/Bootstrap.php` | 组合根：平台、窗口、任务池与主循环的装配 | 窗口标题、尺寸等少量项 |
| `src/<Name>Application.php` | 应用本体：`buildRoot()` / `title()` 等 | 主要写这里 |
| 仓库根 `composer.json` | PSR-4 登记 `App\<Name>\` | 加一行 |

`apps/` 下每个目录是一个工程，另有一个 `dev/` 是开发态专用壳，不是应用。其余目录分别承担最小窗口、能力示例、完整业务、教学、性能基准与帧节奏等角色。

## main.php：全局 main

入口必须提供一个全局命名空间下的 `main()`，无参数、无返回值，转调组合根：

```php
namespace {
    function main(): void
    {
        \App\Hello\Bootstrap::run();
    }
}
```

三条硬要求：

1. 函数名、签名固定——tpc 会加 `/SUBSYSTEM:WINDOWS /ENTRY:mainCRTStartup`，C++ 侧找的就是这个符号。
2. 必须在**全局**命名空间，否则符号对不上。
3. 调全局符号写全限定名，也就是前导 `\`；少了它会被解析成当前命名空间下的类。

入口保持「哑」：不写任何装配。装配全在 `Bootstrap.php`，因为入口文件不受 PSR-4 与分层规则检查。

## project.yml：路径基准

```bash
php tools/drift-build.php hello
# 等价于：tpc apps/hello/project.yml -O2 -m bin
```

必须从仓库根调用，原因是两类路径基准不同：

- `cxx-flags` / `ld-flags` 不经过 tpc 的路径解析，相对的是编译进程的 cwd；输出 exe 也落在 cwd，`name:` 只决定文件名。
- 被 tpc 解析的路径（`sources` / `build-dir`）以 `project.yml` 所在目录为基准，所以要写 `../../` 前缀回到框架与桥接层。

共享编译参数抽在 `build/base.yml`，各应用只留自己独有的源码集。`sources` 必须能覆盖应用源码、框架 `src/` 与桥接层 `cpp-src/`；它与 `composer.json` 的 psr-4 命名空间要一致，否则解释器侧能跑、AOT 侧编译不过。list 型配置项是整体替换而非追加，放在被包含文件里会被子文件顶掉。

## Bootstrap.php：组合根

这是**全应用唯一提到具体平台实现**的文件；共享的应用源码零平台引用。装配顺序都是真机踩出来的：

1. `new <Platform>()` 建平台实现——这是全应用唯一提到具体平台的地方。
2. `new WindowSet($platform, 'app')` 声明一次应用名——框架经 `AppPaths` 拼出 `<平台基础目录>/<应用名>/window.state`，多个 Drift 应用不会互撞；构造时经 `Platform::hooks()` 装 fatal box 与 resize pump，`run()` 的 finally 统一卸。钩子捕获的闭包持有整条对象图，漏卸会让 AOT 关窗崩，所以这段不再由组合根手写。
3. `AppScope::make()` 建进程级共享作用域——多窗口共用字体、任务池、主题。
4. 按系统外观取主题。
5. `AppScope::setTaskHost()` 必须在 `attach()` 之前——`PumpHost` 在 attach 时取走并缓存端口。
6. `WindowSet::open()` 每窗一个应用实例；槽位名要不同，否则几何互相覆盖且不报错。标题默认取应用的 `title()`，同应用开两窗要不同标题时才在末尾传覆盖值。
7. `WindowSet::run()` 进主循环；resize pump 由平台钩子按窗口句柄接好，副窗拖动不会重排主窗。

`Application` 没有 `run()`，只有 `attach()` 与 `frame()`：等待由平台给，主循环由框架编排。

## Application 子类

应用只写它关心的回调，可见性必须是 `public`——基类把它们定义为 public，子类收窄会直接 PHP Fatal。

```php
final class HelloApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Center::make()->child(Text::make('Hello Drift')->title());
    }

    public function title(): string
    {
        return 'Hello Drift';
    }
}
```

★★★ 覆写了 `__construct()` 就**必须**调 `parent::__construct()`。基类在构造器里建若干不依赖子类字段的部件（路由、文本编辑、滚动输入、浮层、输入、覆盖布局、泵），漏调会让这些字段全部未初始化，真机进入 `attach()` 第一句就崩；AOT 会把未初始化属性读成 `null`，错误信息往往只有一句空指针样式的提示，没有文件名与行号。有 lint 规则专门守这一条。

## 多页应用：一次声明身份，切页保活

页面多不等于复杂；复杂常来自"切一次页就 `new` 一个新页"，把表单草稿、滚动位置、异步结果全丢掉。框架现成的组合是：

```php
RouteTable::make()->route('buttons', static fn(): Widget => new ButtonsPage());
PageHost::make()->current($pageId)->table($table)->expanded();
```

`RouteTable` 是数据（名字 → 工厂），`PageHost` 是容器：按名字缓存页面实例，换页走 `Offstage` 隐藏而非卸载。页名是稳定身份，和侧栏第几项无关，插页 / 重排不影响既有页。纯 UI 页切换用 `current()` 直接给页名；页名住在 Store 里就给一个可读通道（`->from($store->pageSource())`，`Cell` / `Computed` 都行，读它 = 订它）。`expanded()` 让页根 `ScrollView` 量到可视高。

全常驻容器在首帧就把所有页 mount 完，`initState()` 因此不再等于"用户进过这一页"。首次取数、注册日志槽这类"进页面才开始"的副作用放 `State::onActivate()`，它只跑一次。

## composer.json：PSR-4 登记

仓库根的 `composer.json` 在本项目的首要身份是**物理边界表**，工具链与自动加载都读它。新增应用必须登记 `App\<Name>\` 到对应源码目录。

漏登记的后果不是「少点便利」：解释器侧的无头预览与 smoke 找不到类，而 AOT 侧靠 `project.yml` 的 `sources` 自动收录，于是两边不同源，只有内环会炸，真机 exe 反而正常。

## 构建产物的双轨差异

| 维度 | dev 壳 | AOT exe |
|---|---|---|
| 命令 | `drift-dev.php <app>` | `drift-build.php <app>` |
| 内容 | 一次编出壳 + C++ 桥，业务 PHP 动态加载 | 框架、业务、桥接层一起静态编译 |
| 复用 | 所有应用共用同一个壳 | 出哪个应用才编哪个应用 |
| 改动后 | 改业务 PHP 保存即重启窗口 | 输入变化自动全量重编 |
| 首次门槛 | 仍需完整工具链编壳 | 需完整工具链 |
| 能发现的问题 | 布局、交互、像素；看不见编译期语义 | 唯一能抓 AOT 编译期问题 |

dev 壳把「编译」从每次改代码里挪走，并没有删掉 AOT 验证。碰过编译期敏感写法、跨进程取数、新增类或新枚举，dev 全绿也要跑 AOT 构建与真机探针，详见[快速开始](./quickstart.md)。

## 相关

- [快速开始](./quickstart.md)
- [心智模型](../concepts/model.md)
- [CLI 参考](../reference/cli.md)
- [AOT 约束](../reference/aot-constraints.md)
