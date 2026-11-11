# 其他平台怎么接入 —— 以 Android 为例（2026-11-09）

> 基准 `14e3e83`。**只读评估：未改一行产品代码、未编译任何 Android 产物、未跑真机**。
> 方法：对代码不对稿子 → 区分「已裁决 / 有意裁剪 / 从未验证」→ 对照成熟嵌入层 → 给判死条件。
> 本仓清单会腐烂（`docs/PLAN-NEXT-STEPS-2026-11-08.md` 自己写了），故本稿先重核代码现状。
> **2026-11-09 追加订正**：用户指出上游 `typephp/examples/android-native`。
> 它把「tpc 能否上 Android」从推测变成现成代码 ⇒ §0 / §3 / §5 / §7 已按此重写，证据与边界见 §8。

## 0. 结论先行

1. **写「能跨平台的应用源码」现在就能做，而且不用改框架**：应用放 `apps/<app>/src/`，
   平台名只允许出现在 `apps/<app>/bin/<os>/Bootstrap.php`。本次重算确认
   `src/` 397 个 PHP 文件在 `src/Platform/Win32/` 之外**零 Win32 类型引用**。
2. **实现 Android 后端不是「填一份 8 项清单」**：`docs/_archive/CROSS-PLATFORM.md`
   的移动端小节说「不需要新抽象，只需要一个新 `Platform` 实现」，那句话只在
   **PHP 框架层成立**。门槛在它没写的 L0：把 PHP 运行时 / PHPX / tpc 产物 /
   Skia 用 NDK 交叉编译成 Android `.so`。**Drift2 仓对此零验证、零代码，
   但上游 tpc 已有可跑的 Android 示例（§8）** ⇒ L0 从「可行性未知」降级为
   「形状已知、Drift2 侧的规模 / 语言面 / 渲染路径未验」。
3. **上游示例没有解决 Drift2 的渲染**：它走 Java 原生 View 桥（`ui_add_label` 等），
   上游全仓 `ANativeWindow` **零命中** ⇒「PHP 能在 Android 跑」≠「Drift2 自绘能上屏」。
   且 `mode: bin`（单 exe）→ `mode: lib`（`.so` + JNI + `Activity`）是启动形态变更，
   不是给 `build/base.yml` 加一个 target。
4. **移动端是本仓明确写下的非目标**（`docs/architecture/00-README.md:101`）。
   所以这不是「照清单开工」，而是要先推翻一条裁决 —— 本稿即方案，等拍板。

---

## 1. 先分清两件事

| | 做什么 | 现状 |
|---|---|---|
| **A. 写跨平台应用** | 业务代码与 `Application` 子类全平台共用，每个 OS 一个组合根 | 已具备。`composer.json` 已预留 `bin/macos/`（无 `bin/android/`）；`WindowSet::__construct(Platform $platform)` 是真接口注入 |
| **B. 实现新平台后端** | 壳 + 适配器 + 构建链 + 判据 | 只有 Win32 一份实现 |

**A 的纪律**（现在就能守）：共享代码零平台引用；`Application::title()` 是标题唯一来源；
`buildRoot()` 不碰窗口；平台差异全部收敛到 `Bootstrap.php`。将来接 Android 时，
`apps/<app>/src/` 一行都不改，只新增 `apps/<app>/bin/android/Bootstrap.php`
并在 `composer.json` 的 autoload 里登记该目录。

**B 才是下面要拆的。**

---

## 2. 别从零开始：仓里已有的四份材料

| 材料 | 给了什么 | 状态 |
|---|---|---|
| `docs/_archive/CROSS-PLATFORM.md` 移动端小节 | 桩形状：`createWindow()` 返回固定句柄 `1`；`show/close/setTitle/setMinSize` 空操作；`clientSize` 从 `Screen::viewport()` 推；`pump()` 变成「等一帧 vsync」；指针 `device = 1`；唯一语义缺口是 `Screen::safeInsets()` | 2026-10-20 评审稿，**未改一行代码**，结论已被 design-v7 吸收 |
| 同稿 D6 | `PointerEvent` 加 `int $device = 0`（0 鼠标 / 1 触摸 / 2 笔），带默认值零破坏；纪律「框架内不得出现『这是不是鼠标』的判断」 | **至今未落地**：`src/Input/PointerEvent.php:30-39` 仍只有 `x/y/button/phase/ctrl/alt/shift/clickCount` |
| `docs/EVENT-LOOP-2026-10-30.md` 五后端表 / 实现草案 | Android = `ALooper_pollAll($ms, ...)`，天然「消息 + fd」混合；等 UI 与等 IO 是同一次等待；定时器亚毫秒 | 已部分落地到 `Platform::waitEvents()` / `pumpEvents()` 接口，但 **Android 后端不存在** |
| 同稿 §6 归档验收三道闸 | ① `RasterCanvas` 全量 smoke 在 `php.exe` 过 ② 真机开窗一帧像素 ③ 真机探针 | ① **已常驻 gate**（smoke 473 / golden / phpunit）；②③ 仅 Win32 有 |

★ **估时不可信**：归档稿 V4「移动端桩 1–2 周（前提：先做 D6 + 安全区）」只算了
`Platform` 桩，**完全没算 NDK / PHPX 交叉编译、Activity、JNI、Accessibility**。

---

## 3. Android 接入的五层（按依赖排序）

### L0 · 运行时与构建链 —— 最硬；上游已有形状，Drift2 侧零验证

- `tools/drift-build.php:15-17` 明写「真的调用 `cl.exe` / `link.exe`…需要本机有
  MSVC + Windows SDK」；`build/base.yml:120-131` 链接 14 个 Windows 库
  （`user32` / `gdi32` / `imm32` / `windowscodecs` / `dwrite` / `d3d12` …）+ 12 个 Skia
  Windows `.lib`；产物写死 `{$app}.exe`。
- `docs/_archive/DESIGN-v2-2026-10-14.md:991`：「库构建仍**需要 PHP 运行时与 PHPX**（`phpx.lib` 链接期 +
  `phpx.dll` 运行期）⇒ **不是完全自包含产物**」；
  `docs/_archive/DESIGN-v2-2026-10-14.md:938-940` 提到 tpc `-m lib` 的理论产物是 Linux `.so`；
  `docs/RESEARCH-ALIGN-2026-11-03.md:240` 记「产物带 `libphp.so` + `libphpx.so`」。
  **Drift2 全仓 0 个 lib 工程**（`docs/_archive/DESIGN-v2-2026-10-14.md:3583`），`-m ext` 明确不做；
  但上游 `typephp/examples/android-native/android.yml` 已是真实 `mode: lib`
  工程（§8），所以「理论」二字只对本仓成立。
- 全仓 Grep `NDK|JNI|ANativeWindow|gradle|apk|arm64-v8a|交叉编译` ⇒
  **零命中**（唯一相关是 `docs/EVENT-LOOP-2026-10-30.md` 的 ALooper 草案文字）。
- `cpp-src/skia/drift_skia.cc:34,38,151` 有 `<phpx.h>`、`<windows.h>`、
  `GetModuleFileNameA`（找 exe 旁 `fonts/`）—— 归档稿 D4 建议的
  `Platform::assetDir()` 至今未落地。
- **但「本仓零命中」不等于「上游零实现」**：`typephp/examples/android-native`
  把 `mode: lib` + `aarch64-linux-android24` + NDK `clang++` + JNI + 打包成
  signed APK 整条链都跑通了，且**非 nano 路径也存在**（§8）。原稿此处写的
  「整件事可不可行未知」应订正为「上游形状已知，Drift2 侧未复现」。

⇒ 剩下的三个**真正未验证点**，全部不需要写任何 `Platform` 代码就能验：
① tpc 在 Android target 下能否编过 Drift2 的 397 文件 / `cxx-std: c++20` /
`\Fiber`（上游示例只有两个小 PHP 文件）；② Skia 的 arm64 构建（上游零 Skia，
或按 D1 先用纯 `RasterCanvas` 绕开）；③ `ANativeWindow` 的 `present()`（上游零命中，
要自己写）。见 §5 的 G0′。
`docs/REVIEW-ARCH-DX-2026-11-08.md:109` 也点名 Skia 预编译包「分发方式仍是开放项」。

### L1 · 壳与生命周期（C++ / Java）

`cpp-src/win32/drift_win32.cc` 的 21 个桥函数要按 Android 重写，但**分块难度不均**：

| 关注点 | Win32 现状 | Android 对应 | 难度 |
|---|---|---|---|
| 呈现 | `drift_win_present()` 整帧 RGBA | `ANativeWindow_lock` → memcpy → `unlockAndPost` | **低**，见下 |
| 消息泵 | `GetMessageW` / `MsgWaitForMultipleObjectsEx` | `ALooper_pollAll` + `AInputQueue` | 中 |
| 单调时钟 | `GetTickCount64` / QPC | `clock_gettime(CLOCK_MONOTONIC)` | 低 |
| 窗口 | `CreateWindowEx` + HWND | Java `Activity` + `Surface`，句柄可固定为 `1` | 中（跨 JNI） |
| IME | IMM32 + 候选框定位 | `InputMethodManager` + `View` 上报 | 高 |
| 无障碍 | UIA provider 挂 HWND | `AccessibilityNodeProvider`（Java 层） | **高**，模型不同 |
| 图片解码 | WIC | `PngDecoder` 纯 PHP 或 Skia codec | 中 |

**最大的好消息**：`docs/_archive/CROSS-PLATFORM.md` D1 已裁决「离屏光栅 + 整帧上屏，
不做 swapchain」。`src/Platform/Win32/Win32RasterSurface.php:94-99` 与
`Win32SkiaSurface.php:109-114` 都是「取回整块 RGBA → 同一个 `present()`」，
`docs/architecture/04-绘制与双后端.md:42` 的 `GpuContext` 是留口未实现。
⇒ 渲染路径**天然贴 Android 的 `ANativeWindow`**，也不需要 GPU 上下文。
`RasterCanvas` 是纯 PHP 软光栅，可先绕开 Skia 交叉编译做首帧验证。

### L2 · `AndroidPlatform implements Platform`（PHP 侧，最便宜）

`src/Platform/Platform.php` 14 方法 + 7 个子端口
（`WindowHost` 20 / `SurfaceProvider` 1 / `InputSource` 2 / `Screen` 3 /
`Clipboard` 3 / `Dialog` 5 / `Tray` 2），合计约 50 个方法要写或空桩。
归档稿移动端小节已给了空桩清单。新增 `src/Platform/Android/` 与
`apps/<app>/bin/android/Bootstrap.php`（组合根换一行平台类名）。

**注意**：这一层看起来最像「接入」，其实**排在 L0/L1 之后**——
`Platform` 只是框架问 OS 的端口，壳还不存在时它无处可问。

### L3 · 框架语义缺口（真要上移动端才动）

| 缺口 | 代码现状 | 裁决/风险 |
|---|---|---|
| 指针设备类型 | `src/Input/PointerEvent.php:30-39` 无 `device` | D6 未落地；带默认值零破坏，**现在加几乎免费** |
| 安全区 | `src/Platform/Screen.php` 无 `safeInsets()` | 归档稿明写「等真要上移动端再加」——现在加会逼所有实现返回 0，属「未被消费的分支 = 未实现」 |
| hover / press 两相位 | `src/Layout/RenderObject.php:56-68`、`src/Input/PointerPhase.php:20-37` 的 `Leave`（`WM_MOUSELEAVE`） | 触摸无 hover、无 leave；`device=1` 时须整体跳过，否则「点了没反应」类静默 bug 会成片 |
| 长按 / fling / pinch | `src/Input/GestureRecognizer.php:31-51` 只有 tap/drag | `docs/DEBT-2026-11-02.md:2825-2826`「刻意不做，无触摸输入」；`docs/ARCH-REVIEW-2026-10-24.md:126`「壳无 `WM_TOUCH`/`WM_POINTER` 做不了」 |
| 右键 / 上下文菜单 | `src/Input/PointerEvent.php:43,165-173` | Android 无右键，需长按替代（§59/§60 的键盘入口也要重想） |
| 无障碍 | `src/App/Application.php:1102,1116,1147,1185` 的 UIA 语义全在 docblock | PHP 语义树本身平台无关，可复用；**provider 要整套换** Java 实现 |
| 桌面专属端口 | 托盘 / `CF_UNICODETEXT` / `%APPDATA%` / per-monitor DPI / 多窗口 / 文件拖放 | 走 `null` 空桩或降级（`Platform` 已允许「不支持 ⇒ null」） |

### L4 · 判据与分发

`gradle` + `AndroidManifest.xml` + `arm64-v8a` 产物；三道闸要有 Android 版本。
`docs/REVIEW-2026-11-01.md:93` 已裁决「跨平台（Win/Linux/mac）：**各平台各自基线，不互比**」
——不要试图把 Win32 的 golden 基线搬到 Android 当 expected。

---

## 4. 成熟嵌入层怎么切（Flutter Android embedding）

`FlutterActivity`（Java，生命周期 / Window / IME）→ `FlutterJNI`（唯一 JNI 边界）
→ `AndroidShellHolder`（C++ 侧顶层编排，与 Java 侧 `FlutterEngine` 1:1）
→ `VsyncWaiterAndroid`（等垂直同步驱动帧）。

对照到 Drift2：

| Flutter | Drift2 落点 |
|---|---|
| 引擎编成 `.so` + Java 薄壳 | L0 的 `-m lib` + `Activity`，**不是**把 Java 塞进 C++ |
| `FlutterJNI` 单一边界 | 对应 `cpp-src/win32/drift_win32.stub.php` 的「只传原语、零回调、零闭包」纪律，Android 要守同样形状 |
| `VsyncWaiter` 驱动帧 | 对应归档稿「`pump()` 变成等一帧 vsync」与 `Platform::pumpEvents()` docblock 的「移动端只能 poll」 |
| `PlatformViewAndroid` / `AccessibilityBridge` | L1 的 `ANativeWindow` + L3 的 `AccessibilityNodeProvider` |

**可迁移的是切法与顺序**（先 .so 能加载 → 再贴一帧 → 再谈输入），
**不是**任何代码：Flutter 引擎自带 Dart runtime 与 Skia，Drift2 的 tpc 产物
仍需 PHP/PHPX 运行时，这是本仓独有的额外一层。

---

## 5. 最小验证顺序（每条先给判死条件）

按本仓 V2 纪律：答不出「怎样的变异能让它报红」的不上仓；判据不与实现同源。

| 门 | 验证什么 | 退出条件 | 失败含义 |
|---|---|---|---|
| **G0**（降级） | 复现上游：拿 `typephp/examples/android-native` 跑出 signed APK，装机启动一次 | APK `apksigner verify` 通过 + 真机点一次计数 +1 | **只是工具链 / 环境缺口**，不是可行性判死；上游已给定形状（§8） |
| **G0′**（真生死门） | 把 `sources` 换成 Drift2 的 `src/`（或最小可编译子集），`mode: lib` + `aarch64-linux-android24`，**不建窗、不接 Skia**，编译出 `.so` 并由 JNI 调通一个纯 PHP 函数 | `.so` 被加载 + 返回值正确 + 编译期零 Fatal | tpc 在 Android target 下编不过 Drift2 的规模 / `c++20` / `\Fiber` / ZTS ⇒ **整件事停，后面全不做** |
| **G1** | `ANativeWindow` 贴一帧 `RasterCanvas` 的 RGBA（先绕开 Skia） | 真机截图像素与 PHP 侧 `toRgba()` 一致 | present 路径不成立，或 PHPX 线程模型有问题；上游示例**不覆盖**此门 |
| **G2** | `AInputQueue` → `PointerEvent` 归一化（先落 D6 `device=1`） | 一次 tap 能触发 `onPressed`；`hovered` 在触摸下**始终为 false** | 输入语义缺口；此门要配「hover 不该出现」的反例判据 |
| **G3** | Activity 生命周期 / IME / Accessibility | 前后台切换不丢帧、不崩；语义树经 Java provider 可读 | 产品化缺口，可分期 |

★ **G0′ 之前不要写 `AndroidPlatform`**。理由：L2 那 ~50 个方法在壳不存在时
无处落地，写了就是「零消费者 + 零实现的抽象 = 陷阱」
（`docs/DEBT-2026-11-02.md:6567`）。
同理，G0′ 之前**不要**先做 `SupportLevel` / `--dump-caps` / `safeInsets()` —
`docs/DEBT-2026-11-02.md:7416` 判死理由是「零消费者」，
`docs/EVAL-design-v7-2026-10-21.md:237` 写的是「**只有真要上第二个平台才值得**」。
G0′ 过了，这两条判死的**前提才成立**，届时回来重裁，而不是现在补。

★ **本机今天连 G0 都跑不了**：只有 `JAVA_HOME=D:\Java\jdk-11` 与
`PHPX_HOME=D:\TypePHP\typephp-windows-x64\phpx`（Windows x64），
**无 `ANDROID_SDK_ROOT` / `ANDROID_NDK_HOME`、无本地 Android SDK**；
`phpx/sdk/build-native.sh` 的 `ndk_host_tag` 只枚举 linux/darwin，
`build-app.sh` 依赖 `bash`/`find -printf`/`mapfile`/`zip`。
⇒ 这是**环境缺口，不是可行性缺口**，别把「本机跑不了」写成「不可行」。

---

## 6. 如果只想先「写个能移植的应用」

不需要等上面任何一门：

1. 业务全部放 `apps/<app>/src/`，共享代码不出现平台名（现有分层 lint 已能挡一部分）。
2. 不用 `Win32` 专属能力：不直接调 `drift_win_*`、不读 `%APPDATA%`、
   不假设有标题栏 / 右键 / hover。
3. 平台相关配置走 `Platform::configDir()`、`Screen::viewport()` 等端口，别自己 `getenv`。
4. 现在就在 Win32 上跑 `gate 18/18` + golden；将来每个新平台各自建基线（不互比）。

---

## 7. 诚实边界

- 全程只读。**未跑 NDK、未编译任何 Android 产物、未装机、未做变异**。
  §8 的上游证据也只读了源码与构建脚本，**没有实际执行 `build-app.sh`**
  ⇒「上游跑得通」是「上游有完整可执行的链」的判断，不是本机实测结果。
- L0 的 Drift2 侧判断来自 `build/base.yml` / `tools/drift-build.php` / `DESIGN.md`
  的静态文本；**订正**：旧稿写「tpc 是否真能用 NDK 交叉编译、PHP 运行时在 Android
  上是否可用，本仓无证据」——对 Drift2 仓成立，但上游 `typephp` 就是证据（§8）。
  仍未验证的是**把这套链套到 Drift2 的 397 文件 + `c++20` + Fiber 上**（G0′）。
- 「移动端是非目标」是 2026-10 的裁决；本稿不推翻它，只把推翻所需的成本与门序列摊开。
- `docs/_archive/CROSS-PLATFORM.md` 是归档评审稿，其中的行号 / 文件数 / 估时均为
  2026-10-20 快照；本稿引用的 `src/` 现状已按 `14e3e83` 重核。

---

## 8. 上游 `typephp/examples/android-native` 带来的订正（2026-11-09 追加）

用户指出 `D:\TypePHP\typephp\examples\android-native`。这是 **tpc 上游仓**（不是 Drift2）
自带的完整 Android 示例；`README.md` 自述「compiles PHP into a self-contained Android
`arm64-v8a` shared library and packages a signed APK … does not require Gradle or
Android Studio」。它把旧稿 §3/§7 的「L0 零证据」直接推翻了一半。

### 8.1 它证明了什么（G0 不再是「零证据」）

| 证据 | 文件 | 结论 |
|---|---|---|
| `mode: lib` · `target-platform: aarch64-linux-android24` · `cxx-std: c++17`，产物 `dist/typephp_android_hello.so` | `android.yml` | tpc 的 Android target 不是草案，是配置面 |
| `tpc.php android.yml [--nano]` → `javac` → `d8` → `aapt2 compile/link` → `zip` → `zipalign` → `apksigner sign/verify` | `build-app.sh:55-120` | 编 `.so` 到 signed APK 全链齐，无需 Gradle |
| `JNI_OnLoad` 存 `JavaVM`；`std::call_once` 里 `TYPEPHP_RUNTIME_INIT(...)(1, argv)`；`NewGlobalRef(Activity)`；JNI 回调转 `php_typephp_application_*` | `cpp-src/android_bridge.cc:49-90` | PHP 运行时能在 Android 进程起来，JNI→PHP 通道形状给定 |
| `Android extends UnixPlatform`：`supportsTarget()`、NDK `clang++` 发现、`libphp.a` 检测、`-Wl,--start-group/--end-group` | `typephp/src/Platform/Android.php` | 编译器侧是真交叉编译支持 |
| 非 nano 路径链 `libphp.a` + `log/android/dl/m`；`getAndroidSdkDir()` 校验 ABI 戳 | `typephp/src/Build/NativeBuildConfigurationTrait.php:77-95,302-305,399-401` | **`--nano` 不是唯一路径** |
| `sdk/build-native.sh --target android-arm64-v8a` → `phpx/android/build.sh` → `libphpx.a` | `phpx/sdk/build-native.sh:13,89-95,129-132` | PHP 运行时层 + PHPX 层的 Android 构建入口存在 |
| PHP 侧只有 `did_launch()` / `control_activated(int)` 两个入口；业务走 `ui_add_label/button/set_control_text` 原语 | `php-src/android-main.php` · `application.php` · `cpp-src/android_ui.stub.php` | 桥「只传原语、零回调、零闭包」与 Drift2 `drift_win32.stub.php` 同形，可照抄骨架 |

★ **两条路径，别只看见 `--nano`**：上游 `typephp/examples/android-native/README.md`
的 Prerequisites / Build 段明写，`--nano` 把 php-nano + PHPX 源码编进 `.so`、
不需要预编 Android `libphp.a`；**不带 `--nano`** 时改用 `PHPX_ANDROID_SDK_DIR`
（`phpx/sdk/build-native.sh` 产出）走标准 Zend VM。
⇒ 旧稿隐含的「Drift2 不做 nano ⇒ Android 没戏」是**推论过度**：非 nano 路径存在，
Drift2 完全可以走它，`AsyncScope` 的 `\Fiber` 也就不再是阻塞。

### 8.2 它没证明什么（三条硬边界）

1. **UI 是 Java 原生 View，不是自绘**：`MainActivity.java` 直接 `new TextView /
   EditText / Button / ImageView`，`android_ui.stub.php` 全是 `ui_add_*` 控件原语；
   **上游 typephp 全仓 `ANativeWindow` 零命中**（`grep -rIl`，含全部 examples）。
   ⇒ G1「整帧 RGBA 上屏」仍是 Drift2 要自己写的第一份代码。而且这恰好与
   `docs/architecture/00-README.md:99`「原生控件（一律自绘）」的裁决**相反**，
   所以示例能抄的只有**壳与运行时**，不能抄 UI 层。
2. **`--nano` 对 Drift2 仍然不可用**：`typephp/src/Transform/NanoSyntaxValidationVisitor.php:11-16,62-66`
   明确禁 `Fiber` / `Generator` / `yield` / 匿名类 / `eval` / `include`，理由是
   「C++17 has no standard stack-switching API」；Drift2 `src/Async/AsyncScope.php:149,218`
   用 `\Fiber::suspend()`，`build/base.yml:73` 是 `cxx-std: c++20`，
   `docs/_archive/DESIGN-v2-2026-10-14.md:2605-2613` 另记 ZTS/NTS、C++20/C++17、网络三条硬冲突。
   ⇒ 若上 Android **必须走非 nano**，这点反而比旧稿更明确。
3. **规模与语言面完全不对等**：示例只有 2 个 PHP 文件、零框架、零 Fiber、零 Skia；
   Drift2 是 397 个 `src/` 文件 + 12 个 Skia Windows `.lib` + `mode: bin` 单 exe。
   上游 `mode: lib` 由 `nativeApplicationDidLaunch` 回调拉起应用，Drift2 现在是
   `main()` 拉起 ⇒ **启动形态与组合根都要改**，不是给 yml 换个 target。

### 8.3 环境现状（本机，2026-11-09 实测）

`JAVA_HOME=D:\Java\jdk-11`、`PHPX_HOME=D:\TypePHP\typephp-windows-x64\phpx`
（Windows x64）；**`ANDROID_SDK_ROOT` / `ANDROID_NDK_HOME` 均未设**，
`%LOCALAPPDATA%\Android\Sdk` 不存在。`Android.php:getDefaultCompiler()` 虽有
`Windows => windows-x86_64` 分支，但 `phpx/sdk/build-native.sh:137-143` 的
`ndk_host_tag` 只枚举 linux/darwin，`build-app.sh` 还依赖 `find -printf` / `mapfile` / `zip`。
⇒ **本机今天连 G0 都跑不了**：需先装 SDK Platform 36 + NDK r27 + PHPX Android SDK，
更稳的是找一台 Linux host。这是**环境成本，不是可行性判死**。

### 8.4 对门序列的净影响

- 旧 **G0**（NDK 编 `.so` + JNI 跑通纯 PHP 函数）从「唯一决定生死的门」
  **降级**为「复现上游」的环境门 —— 形状与代码都在上游现成。
- **新增 G0′** 才是生死门：把 Drift2 的源码面套进这条链，验 tpc 在 Android
  target 下能否编过 `c++20` + `\Fiber` + 397 文件。
- G1 / G2 / G3 **一条都没被上游覆盖**（`ANativeWindow` / `AInputQueue` /
  `AccessibilityNodeProvider` 全零命中）。
- 「G0′ 之前不要写 `AndroidPlatform` / `SupportLevel` / `safeInsets()`」**不变，且更硬**。
