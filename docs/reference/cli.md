# 命令速查

> 列出日常最常用的命令入口、各自解决什么问题，以及判据清单的唯一查看方式。

## 两条纪律先说清楚

**判据清单只在 `php bin/drift verify --list`。** 任何判据项、分层计划、步骤编号都从这条命令读，不往文档里抄清单内容——清单会随代码变化，抄进文档就会漂。想看某个步骤的细节用 `php bin/drift verify --step=<id>`。

**没有第二套 runner。** 所有命令都从 `bin/drift` 这一个入口进，`bin/drift` 只做路由：把子命令转发到 `tests/Verify/verify.php` 或 `tools/` 下的具体脚本，自己不实现任何判据。composer scripts 也只是 `@php bin/drift ...` 的别名，不是独立的验证逻辑。发现一条命令的行为和文档不符时，以脚本为准。

## 三个入口的关系

- `bin/drift` 是唯一入口，管所有子命令的路由。
- `tests/Verify/verify.php` 配合 `tests/Verify/VerifyPlan.php` 是验证核心，判据的分层与步骤都定义在这里。
- `composer` scripts 是 `bin/drift` 的薄封装，方便记忆，可用 `composer run --list` 列出全部可用脚本——这是唯一可发现入口。

## 验证类命令

| 命令 | 作用 |
|---|---|
| `php bin/drift verify` | 日常验证，跑 `static` + `behavior` 的快项 |
| `php bin/drift verify --full` | 全量口径，补 `feature` / `pages` / `smoke`，CI 用这条 |
| `php bin/drift verify --list` | 打印当前判据清单与分层计划，唯一权威 |
| `php bin/drift verify --layer=corpus` | 跑题库对照，`--full` 之外单独指定 |
| `php bin/drift verify --step=<id>` | 单点调试某一个步骤 |
| `php bin/drift test` | PHPUnit unit，缺 PHPUnit 时 exit 2、不算通过 |

对应的 composer 别名：`composer check`、`composer check:full`、`composer verify`、`composer verify:full`、`composer test`。`check` / `check:full` 是历史别名，等价于 `verify` / `verify:full`。`composer tooling` 等价于 `verify --layer=static`。

验证分三层：`static`、`behavior`、`device`。步骤按档位分 `fast`（日常）、`full`（`--full` 才跑）、`manual`（`--device` 手动跑）。题库 `corpus` 是独立量具，不算在三层里。更细的分层与 CI 用法见[协作流程](../contributing/workflow.md)。

## 静态检查

`php bin/drift lint` 跑全量 lint 规则，覆盖 AOT 纪律里能静态判定的部分，例如 `callback-arity`、`attribute-unqualified`、`str-concat-loop`、`internal-leak`。composer 别名是 `composer lint`。lint 规则的适用边界与已知盲区见[AOT 约束与代码纪律](./aot-constraints.md)。

同一个 lint 入口还有 `php tools/lint.php --api`，只生成公开 API 面清单，不做校验——它是 API 面的唯一事实源，用法见[公开 API 面](./api-surface.md)。

## 开发态与真机构建

- `php tools/drift-dev.php <app>`：T1 开发态壳，动态加载 PHP、开真窗口，免重编译。改 `apps/<app>/src/` 里的 PHP 即时生效。composer 别名 `composer dev`。
- `php tools/drift-build.php <app>`：T2 AOT 构建，出 exe，需要完整工具链（PHPX、tpc、MSVC、Windows SDK、Skia 与字体）。`--full` 走完整口径。composer 别名 `composer build`。
- `python tools/probe-window.py <app>`：T2 真机探针，需要 Python，`DRIFT_PYTHON` 可覆盖路径。composer 别名 `composer probe`。

这三条分别对应"改应用 PHP"、"改框架或 C++ 代码"、"真机验收"三件事。只要动了 `src/` 或 `cpp-src/`，就必须重新跑 `drift-build` 做 T2 验证，dev 壳通过不能代替它。

## 手工工具

以下命令不进验证计划、不进 CI，需要时手动跑：

| composer 脚本 | 作用 |
|---|---|
| `composer codegen` | T1 的 tpc 翻译层门，秒级、不编译，需要源码树里的 `bin/tpc.php` |
| `composer preview` | T0 无头跑完整 layout + paint，输出 SVG 或 PNG |
| `composer aot-equiv` | T2 解释器与全 AOT 的几何逐值等价，退出码 0/1/2 |
| `composer dual-equiv` | T2 解释器与 dev 壳的语义逐值等价 |
| `composer fingerprint` | 解释器侧双通道指纹，供等价比对 |
| `composer font-scan` | 扫描字面量里的非 ASCII 字符，生成字模资产输入 |
| `composer golden-report` | 把 golden 检查的失败现场渲染成自包含 HTML |
| `composer grab` | 用 `cl.exe` 重建真机抓像素工具，需要 MSVC |

## 环境与依赖脚本

环境自查用 `php tools/doctor.php`，加 `--strict` 会把警告也当失败。它是环境判据的单一真相源，构建脚本和它共用一套组件定位逻辑，所以 doctor 绿了、构建却找不到组件的情况应按 doctor 的输出排查。环境档位、组件版本与覆盖变量见[环境搭建](../contributing/setup.md)。

依赖包相关命令：

- `php tools/fetch-deps.php`：按 `DEPS_VERSION` 指定的 tag 取预编译依赖包，校验、解压到仓库根。
- `php tools/fetch-deps.php --tag=<tag>`：临时钉某个 tag ，`--force` 强制重下。
- `php tools/fetch-deps.php --latest`：取仓库最新 release。
- `php tools/fetch-deps.php --url=<路径>` 或 `DRIFT_DEPS_URL=<目录>`：从本地包或目录取，走同一套校验。
- `php tools/pack-deps.php`：组包并写 `DEPS-MANIFEST` 与 `deps.lock.json`。
- `bash tools/skia/build-skia.sh --replace`：本地重编 Skia，字体需另行备齐。

依赖包的获取、校验与发布流程见[依赖与发布](../guides/dependencies-and-release.md)。

## 相关

- [AOT 约束与代码纪律](./aot-constraints.md)
- [诊断与错误码](./diagnostics.md)
- [环境搭建](../contributing/setup.md)
- [协作流程](../contributing/workflow.md)
