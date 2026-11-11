# Drift2

> **⚠️ 技术预览（v0.1.0-preview）· 仅 Windows 10/11 x64 · API 不保证向后兼容。**
> 这是 Drift 框架的**源码可见技术预览**，**不是** 1.0，第三方请勿当作稳定依赖。
> 发布就绪度与三档路径见 [`docs/RELEASE-READINESS-2026-11-10.md`](docs/RELEASE-READINESS-2026-11-10.md)。

Drift2 是一个**声明式 + 自绘 + 单语言 AOT** 的桌面 GUI 框架：用 PHP 写界面，
编译成独立 `.exe`（**无需**目标机装 PHP 运行时）。架构与 Flutter 同构
（Widget / Element / RenderObject 三层树 + 单向下行 + 组合根注入），渲染走 Skia。

```
┌───────────────────────────────────────────────┐
│  Hello Drift                                    │  ← python tools/probe-window.py hello
│                                                 │    起窗后看到的内容
│      一个窗口，居中显示「Hello Drift」          │
│      （真实截图待补充：构建后见窗口）           │
└───────────────────────────────────────────────┘
```

## 30 秒看懂

- 用 PHP 写声明式 UI，AOT 编译为原生 Windows 程序；
- 自带完整业务闭环样例 [`apps/watermark`](apps/watermark)（选图 → OSS 直传 → 建任务 → 轮询 → 出图）；
- 工程质量：28 项质量闸门 + 变异四律 + T0/T1/T2 分层验证（多数个人框架死在这一栏）。

## 五分钟跑起来（Windows 10/11 x64）

> 下面两条路，**其一**即可看到窗口。

### 路 A：改 PHP 不重编译（开发态，最快）

```bash
php tools/drift-dev.php hello          # 起开发态壳，改 PHP 即时生效
```

### 路 B：构建独立 exe（发布态）

```bash
# 1. 装 PHP >= 8.4 发行版（含 php.ini），确保 php 在 PATH
php -v

# 2. 体检构建环境（MSVC / Win SDK / PHPX / tpc / php.ini）
php tools/doctor.php

# 3. 构建 hello（首次需 TypePHP 工具链：MSVC 14.51 + Win SDK 10.0.26100 + PHPX + tpc）
php tools/drift-build.php hello --full

# 4. 起窗验证（5 判据：起窗 / 尺寸 / 非白像素 / DPI / 退出码）
python tools/probe-window.py hello
```

> 第三步的重型工具链是**唯一门槛**。装不齐时 `doctor` 会逐项告诉你缺什么；
> `cpp/skia/` 已 vendored（24 个 `.lib`），Skia 不是障碍——障碍在编译器与 PHP 运行时。
> 精确版本与「改什么要重编译」见 [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md)。

## 从零写一个应用

看 [`tutorial.md`](tutorial.md) + [`apps/tutorial`](apps/tutorial)。

## 文档

- 设计裁决：[`DESIGN.md`](DESIGN.md)（为什么这么设计）
- 实现现状：[`docs/architecture/00-README.md`](docs/architecture/00-README.md)（现在怎么写）
- 文档索引与权威对照：[`docs/00-README.md`](docs/00-README.md)
- 给贡献者 / CI：[`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md)
- 发布就绪度评估：[`docs/RELEASE-READINESS-2026-11-10.md`](docs/RELEASE-READINESS-2026-11-10.md)
- 变更日志：[`CHANGELOG.md`](CHANGELOG.md)

## 状态与路线图

**当前（v0.1.0-preview）**：Windows-only、alpha、API 可能变。
能力面已可写真实业务应用（见 `apps/watermark`），但**尚不具备** i18n、多平台、
安装器 / 签名 / 自动更新、第三方组件生态。详见 `CHANGELOG.md` 与发布就绪度评估的「三档发布路径」。

## 许可证

MIT（见 [`composer.json`](composer.json)）。
