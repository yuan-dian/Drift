# Drift2

> **技术预览 · 仅 Windows 10/11 x64 · API 不保证向后兼容。**
> 源码可见的技术预览，**不是** 1.0，第三方请勿当作稳定依赖。
> 版本与发布档位见 [`CHANGELOG.md`](CHANGELOG.md)。

Drift2 是一个**声明式 + 自绘 + 单语言 AOT** 的桌面 GUI 框架：用 PHP 写界面，
编译成独立 `.exe`（目标机无需 PHP 运行时）。架构与 Flutter 同构
（Widget / Element / RenderObject 三棵树 + 单向下行 + 组合根注入），绘制走 Skia。

最小应用（节选，完整版见 [`apps/hello`](apps/hello)）：

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

## 30 秒看懂

- 用 PHP 写声明式 UI，AOT 编译为原生 Windows 程序；dev 态改 PHP 不重编译。
- 自带业务闭环样例 [`apps/watermark`](apps/watermark)（选图 → 上传 → 建任务 → 轮询 → 出图）；
  **默认离线 mock，不触网**，切到真实 OSS 直传网关的方式见该应用的 `Config` 类。
- 工程质量：`tools/gate.php` 闸门 + 判据自证变异 + T0/T1/T2 分层真机验证。

## 五分钟跑起来（Windows 10/11 x64）

> 两条路，**其一**即可看到窗口。

### 路 A：改 PHP 不重编译（开发态，最快）

```bash
php tools/drift-dev.php hello          # 壳只编一次；改 PHP 保存后自动重启窗口
```

> 首次会自动编一次 dev 壳，同样需要下面的工具链；之后改业务 PHP 不再编译。

### 路 B：构建独立 exe（发布态）

```bash
# 1. PHP >= 8.4（含 php.ini）在 PATH
php -v

# 2. 体检构建环境（MSVC / Win SDK / PHPX / tpc / php.ini）
php tools/doctor.php

# 3. 构建 hello（首次或输入变化时自动全量）
php tools/drift-build.php hello

# 4. 起窗验证（窗口 / 尺寸 / 像素 / DPI / 退出码；脚本自动关窗）
python tools/probe-window.py hello
```

> 第三步的重型工具链是**唯一门槛**：`doctor` 会逐项报告缺什么；Skia 预编译包
> 已 vendored 在 `cpp/skia/`，无需自行编译。精确版本与「改什么要重编译」见
> [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md)。

## 从零写一个应用

看 [`docs/tutorial.md`](docs/tutorial.md) + [`apps/tutorial`](apps/tutorial)。

## 文档

- 现行设计：[`DESIGN.md`](DESIGN.md)（分层 / 稳定契约 / 有意不做）；v2 历史裁决见 [`docs/_archive/DESIGN-v2-2026-10-14.md`](docs/_archive/DESIGN-v2-2026-10-14.md)
- 实现现状：[`docs/architecture/00-README.md`](docs/architecture/00-README.md)
- 文档索引与权威对照：[`docs/00-README.md`](docs/00-README.md)
- 贡献 / CI / 工具链：[`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md)、[`AGENTS.md`](AGENTS.md)
- 发布就绪度：[`docs/RELEASE-READINESS-2026-11-10.md`](docs/RELEASE-READINESS-2026-11-10.md)
- 变更日志：[`CHANGELOG.md`](CHANGELOG.md)

## 当前边界

技术预览、Windows-only、API 可能变；能力面够写真实业务应用（见 `apps/watermark`）。
缺失项与「有意不为」以 [`DESIGN.md`](DESIGN.md) 的「当前缺失与有意不为」一节为准，
发布档位判定见上面的就绪度评估。

## 许可证

MIT（声明见 [`composer.json`](composer.json)）。
