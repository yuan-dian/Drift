# Drift 概览

> 这篇给出框架定位、三条设计公理、能力面与当前边界，以及借鉴边界和文档导航，帮助你在动手前建立正确的预期。

## 框架定位

Drift 是一个**声明式 + 自绘 + 单语言 AOT** 的桌面 GUI 框架：用 PHP 写界面，经 TypePHP AOT 编译为独立 Windows `.exe`，目标机无需 PHP 运行时。架构与 Flutter 同构——Widget / Element / RenderObject 三棵树、单向下行、组合根注入——界面全部自绘，不走原生控件，绘制走 Skia。

「单语言」是定位的关键：业务代码与框架代码都是 PHP，编译产物是一体的原生程序，不存在额外脚本层或运行时绑定。开发态另有一条路——dev 壳动态加载业务 PHP，改代码不重编译——但发布态始终是完整的 AOT 产物。

当前是 **Windows 10/11 x64 的技术预览**，不是稳定版本：API 不保证向后兼容，第三方不应把它当作稳定依赖。定位由能力与边界决定，而不是由控件数量决定。

## 三条设计公理

三条公理用于仲裁设计分歧，至今有效。

| 公理 | 含义 |
|---|---|
| G1 编译器优先 | 编译器能判的事，构建期就判掉，不留到运行期。 |
| G2 绝不静默 | 机制要么生效，要么响亮失败；静默早返回等于黑洞。 |
| G3 确定性优先 | 布局与渲染是纯函数：可重放、可快照、可逐字节比对。 |

G1 解释了为什么有 AOT 专属的编码纪律；G2 解释了为什么能力查询、边界配对、约束违反都要「说出来」；G3 解释了为什么会存在 `RecordingCanvas` 与 golden 比对。

三条公理冲突时的优先级也是定位的一部分：构建期可判的问题不当成运行期问题；无法判的问题宁可中止也不静默降级；可重放性是判据能成立的前提。后面的所有「有意不做」几乎都能追溯到其中某一条。

## 能力面

现有能力足以写出完整业务应用，而不是只能画玩具窗口：

- **描述与树**：不可变 Widget + fluent 链式修饰、`seal()` 封存、身份由位置与 Key 决定、帧末统一重建。
- **布局与绘制**：`RenderBox` 单遍约束布局；`Canvas` 接口的扁平 op 流，`RasterCanvas` / `SkiaCanvas` / `RecordingCanvas` 三实现。
- **文本**：度量归框架自己，字体面按回退链工作，IME 走 `TextInputClient`。
- **状态与异步**：`State` 标脏、`Subject` 订阅、字符串名命令派发链；`TaskHost` 的同步 / 线程 / 进程三后端。
- **交互与外观**：命中链一次三方消费、手势消歧、焦点链、浮层栈、主题 token 与语义树。
- **工程化**：统一验证入口、无头预览、dev 壳、AOT 构建、真机探针、golden 基线。

一个真实业务闭环的例子是选图、上传、建任务、轮询、出图的应用，默认离线 mock，不触网。

一个最小应用只写一个 `Application` 子类，容器负责装配：

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

平台实现不出现在这个类里；它由组合根在 `bin/win32/Bootstrap.php` 一处注入。五个文件各管什么见[应用解剖](./app-anatomy.md)。

## 当前边界

边界是现状陈述，不是承诺清单：

- **平台**：仅 Windows；第二平台的后端尚未实现，`Platform` 接口仍在演进。
- **交互**：无触控、无笔、无高对比度；无 i18n。
- **架构**：`Application` 仍是门面式的上帝类；环境值靠逐层传参，深层控件没有环境注入通道；无增量布局与 damage rect；无运行时检查器。
- **工程**：无统一 `create` 脚手架；无第三方组件生态与包管理；无安装器、签名与自动更新。
- **稳定性**：技术预览，API 可能变化；二进制依赖与运行时版本相关，构建环境需按体检结果逐项确认。

## 借鉴边界

- **学 Flutter**：三棵树、显式 Key、显式 `setState()`、单向下行、约束布局、组合根注入。
- **学 SwiftUI**：modifier 链式表达，声明式地叠加样式与行为。
- **明确不借**：自动依赖追踪、`v-model` 式双向绑定、CSS 层叠与流式布局、原生控件包装、运行期 ABI 握手。

不借自动依赖追踪，是因为 `BuildContext` 是不可变环境快照、没有向上查找；不借双向绑定，是因为控件是受控的，点击只回调新值，值由应用改。这些是架构裁决，不是尚未实现的功能。

## 文档导航

- 想跑起来：先看[快速开始](./quickstart.md)，再对照[应用解剖](./app-anatomy.md)认识五个文件。
- 想建立心智模型：从[心智模型](../concepts/model.md)进入，再读[帧管线](../concepts/frame-pipeline.md)与[渲染](../concepts/rendering.md)。
- 想写具体东西：控件见[控件](../guides/widgets.md)，布局见[布局](../guides/layout.md)，文本与外观见[文本与主题](../guides/text-and-theme.md)。
- 想处理交互与异步：见[交互](../guides/interaction.md)与[异步](../guides/async.md)；验证见[测试](../guides/testing.md)。
- 遇到构建期报错：查 [AOT 约束](../reference/aot-constraints.md)；命令参数查 [CLI 参考](../reference/cli.md)。

## 相关

- [快速开始](./quickstart.md)
- [心智模型](../concepts/model.md)
- [AOT 约束](../reference/aot-constraints.md)
- [环境搭建](../contributing/setup.md)
