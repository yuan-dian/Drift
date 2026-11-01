"""真机探针的 **Win32 唯一层** —— 启动 / 找窗 / 截图 / 真实输入 / 收尾。

★★ 存在的理由（2026-11-01，同一件事被写了五遍，其中三遍是错的）

  这一层原来散在 9 个探针里，各抄一份。抄出来的差异不是风格，是**真假**：

  | 探针 | 输入通道 | 后果 |
  |---|---|---|
  | `probe-motion.py` | `SetCursorPos` + `mouse_event` | 对 |
  | `probe-scroll-diag.py` | 真的输入，但**不验前台** | 前台抢不到 ⇒ 假 SKIP |
  | `probe-modal.py` | 纯 `PostMessage`，光标都不挪 | **假红** |
  | `probe-uia.py` | 真光标 + 假消息，混着 | 半对，最贵的一种错 |
  | `probe-scrollbar.py` | `PostMessage(WM_MOUSEWHEEL)` | **假红** |

  而 `PostMessage` 那条路已经被证伪过两次：它只让应用"知道"指针在哪，
  而 `TrackMouseEvent(TME_LEAVE)` 与 `ScrollBarFade::noteScroll()` 认的是
  **真实光标 / 真实消息** ⇒ 应用照常推进状态，画面却不变，探针报"一像素不动"。
  两次都白追了整轮。

  ⇒ 结论不是"提醒大家用真的"，是**把假的那条路删掉**：本模块只导出真实输入，
  `PostMessage` 连包装都不给。探针想再造一条假路，得自己 `ctypes` 重写一遍 ——
  那时 `tools/probe-audit.php` 会红。

★ 第二条纪律也落在这里：**真输入只送到前台窗口**。所以 `move/click/wheel`
  每次调用前都先验 `GetForegroundWindow() == hwnd`，不成立就抛
  `probe_check.NotMeasured`（= SKIP）。"输入没送到"从此不可能被写成"画面没变"。

★ 与成熟框架的对应：Playwright 只有**一个** `Input` domain，测试作者选不了
  "真发还是假发"；FlaUI / pyautogui 同理。本模块就是本仓的 `Input` domain。

★ 用法：

    from probe_win import App
    from probe_check import check, run

    def main():
        with App("showcase.exe", "Drift 示例台") as app:
            before = app.grab()
            app.click(25, 90 + 5 * 36)
            ...
        return 0

    sys.exit(run(main))
"""

import atexit
import ctypes
import os
import subprocess
import sys
import tempfile
import time
from ctypes import wintypes

from probe_check import NotMeasured, check, run

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

user32 = ctypes.WinDLL("user32", use_last_error=True)
gdi32 = ctypes.WinDLL("gdi32", use_last_error=True)
kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)

SW_RESTORE = 9
WM_CLOSE = 0x0010
WHEEL_DELTA = 120
# 真实鼠标事件的 flag（`mouse_event` 的 dwFlags）
MOUSEEVENTF_LEFTDOWN = 0x0002
MOUSEEVENTF_LEFTUP = 0x0004
MOUSEEVENTF_RIGHTDOWN = 0x0008
MOUSEEVENTF_RIGHTUP = 0x0010
MOUSEEVENTF_WHEEL = 0x0800


class BITMAPINFOHEADER(ctypes.Structure):
    _fields_ = [
        ("biSize", wintypes.DWORD), ("biWidth", wintypes.LONG), ("biHeight", wintypes.LONG),
        ("biPlanes", wintypes.WORD), ("biBitCount", wintypes.WORD), ("biCompression", wintypes.DWORD),
        ("biSizeImage", wintypes.DWORD), ("biXPelsPerMeter", wintypes.LONG), ("biYPelsPerMeter", wintypes.LONG),
        ("biClrUsed", wintypes.DWORD), ("biClrImportant", wintypes.DWORD),
    ]


# ─────────────────────────── 窗口 / 进程 ───────────────────────────

def launch(exe, args=(), cwd=None, env_extra=None):
    """起一个产物，并给它**必须的**运行环境。

    ★ `PHPRC` 指向一个 `opcache.enable=0` 的临时 ini：TypePHP 产物会拉起 opcache，
      而 ASLR 下 opcache 拒绝启动 ⇒ 进程秒退、`FindWindowW` 返回 0（不是报错）。
      这段环境设置原来在 9 个探针里各抄一份，其中两份写成 `encoding="ascii"`、
      七份 `utf-8` —— 内容一样纯属运气。
    """
    if not os.path.isfile(exe):
        raise NotMeasured(f"找不到 {exe} —— 先构建它（php tools/drift-build.php ...）")

    ini_dir = tempfile.mkdtemp(prefix="drift-run-")
    ini = os.path.join(ini_dir, "php.ini")
    with open(ini, "w", encoding="ascii") as fh:
        fh.write("opcache.enable=0\n")

    env = dict(os.environ)
    env["PHPRC"] = ini
    if env_extra:
        env.update(env_extra)

    return subprocess.Popen([exe, *args], cwd=cwd or ROOT, env=env)


def wait_window(title, proc=None, timeout=20.0):
    """等窗口出来；进程先死就给**死因**，不是"找不到窗口"。"""
    deadline = time.time() + timeout
    while time.time() < deadline:
        hwnd = user32.FindWindowW(None, title)
        if hwnd:
            return hwnd
        if proc is not None and proc.poll() is not None:
            raise NotMeasured(
                f"进程在窗口出现前就退出了（退出码 {proc.returncode}）—— "
                f"通常是 PHPRC / opcache 或构建产物过期，不是窗口问题"
            )
        time.sleep(0.1)

    raise NotMeasured(f"{timeout:.0f}s 内没等到窗口「{title}」")


def focus(hwnd):
    """把窗口顶到前台，**并确认真的到了**。返回 bool（不抛）。

    ★ `SetForegroundWindow` 对"非前台进程"会被系统**静默拒绝**（返回 0 也不报错）。
      解锁办法是把本线程的输入队列临时挂到目标线程上（`AttachThreadInput`），切完再摘。
    ★ 必须**回读** `GetForegroundWindow()` 确认：只信返回值是这里最贵的错
      （`probe-scroll-diag.py` 就只调不复核 ⇒ 抢不到前台时它报"滚轮没生效"，
      听起来像产品坏了）。
    """
    user32.ShowWindow(hwnd, SW_RESTORE)

    if user32.GetForegroundWindow() == hwnd:
        return True

    cur = kernel32.GetCurrentThreadId()
    fg = user32.GetForegroundWindow()
    if not fg:
        # 会话里**根本没有**前台窗口（桌面锁定 / 非交互会话 / 服务态运行）。
        # 这时 `SetForegroundWindow` 必然失败（实测 `GetLastError = 1400`），
        # 后面 40 次重试也全是空转 ⇒ 早点返回，让调用方把原因说准。
        return False

    target = user32.GetWindowThreadProcessId(fg, None)
    attached = False
    if target and target != cur:
        attached = bool(user32.AttachThreadInput(cur, target, True))

    try:
        for _ in range(40):
            user32.BringWindowToTop(hwnd)
            user32.SetForegroundWindow(hwnd)
            if user32.GetForegroundWindow() == hwnd:
                return True
            time.sleep(0.05)
    finally:
        if attached:
            user32.AttachThreadInput(cur, target, False)

    return False


def require_foreground(hwnd):
    """真输入的前置：窗口必须是前台，否则抛 `NotMeasured`。

    ★ 这是本模块存在的主要理由。真实按键/滚轮**只送到前台窗口**；不验就送，
      消息落在别的窗口上，而画面当然不变 —— 于是"输入没送到"被读成"产品坏了"。

    ★★ 两种"没前台"要分开报，因为**处置完全不同**：
      · 会话里**没有**前台窗口（`GetForegroundWindow() == 0`）⇒ 桌面锁定 /
        非交互会话，真实输入物理上送不到。换一台能看见桌面的机器，或在
        有人值守的会话里跑。
      · 有前台窗口但**抢不过来** ⇒ 被别的进程占着（前台锁），重试 / 切走再跑。
      合并成一句"抢不到前台"，下次还是得重查一遍。
    """
    if user32.GetForegroundWindow() == hwnd:
        return

    if not user32.GetForegroundWindow():
        raise NotMeasured(
            "这个会话没有前台窗口（桌面已锁定 / 非交互会话）—— 真实输入送不到任何窗口。"
            "把桌面解锁（或换一个有人值守的会话），或改用 `interactive=False` 的只读探针"
        )

    if not focus(hwnd):
        raise NotMeasured(
            "窗口抢不到前台（被别的进程占着）—— 真实输入会落到那个窗口上，先别测"
        )


# ─────────────────────────── 截图 ───────────────────────────

def client_size(hwnd):
    rect = wintypes.RECT()
    user32.GetClientRect(hwnd, ctypes.byref(rect))

    return rect.right - rect.left, rect.bottom - rect.top


def is_window(hwnd):
    """`IsWindow` 带好签名（不设 argtypes 时 64 位下 HWND 会被截断）。"""
    user32.IsWindow.argtypes = [wintypes.HWND]
    user32.IsWindow.restype = wintypes.BOOL

    return bool(user32.IsWindow(hwnd))


def grab(hwnd):
    """客户区截图 ⇒ `(w, h, data)`，data 是自顶向下 32bpp 行主序 BGRA。

    返回**元组**（不是对象）：调用方一律 `w, h, data = grab(hwnd)`，
    像素用 `probe_pixels.px(data, w, x, y)` 取 —— 那边已经有一份，别再造。
    """
    w, h = client_size(hwnd)
    if w <= 0 or h <= 0:
        raise NotMeasured(f"客户区尺寸异常 {w}x{h}（窗口最小化了？）")

    hdc = user32.GetDC(hwnd)
    mem = gdi32.CreateCompatibleDC(hdc)
    bmp = gdi32.CreateCompatibleBitmap(hdc, w, h)
    old = gdi32.SelectObject(mem, bmp)
    try:
        gdi32.BitBlt(mem, 0, 0, w, h, hdc, 0, 0, 0x00CC0020)
        bi = BITMAPINFOHEADER()
        bi.biSize = ctypes.sizeof(BITMAPINFOHEADER)
        bi.biWidth = w
        bi.biHeight = -h          # 负 = 自顶向下（否则 y 是反的，扫出来的行全错位）
        bi.biPlanes = 1
        bi.biBitCount = 32
        buf = ctypes.create_string_buffer(w * h * 4)
        gdi32.GetDIBits(mem, bmp, 0, h, buf, ctypes.byref(bi), 0)
        return w, h, buf.raw
    finally:
        gdi32.SelectObject(mem, old)
        gdi32.DeleteObject(bmp)
        gdi32.DeleteDC(mem)
        user32.ReleaseDC(hwnd, hdc)


def to_screen(hwnd, x, y):
    """客户区坐标 ⇒ **屏幕**坐标。真输入只认屏幕坐标。"""
    pt = wintypes.POINT(int(x), int(y))
    user32.ClientToScreen(hwnd, ctypes.byref(pt))

    return pt.x, pt.y


def screen_to_client(hwnd, sx, sy):
    """屏幕坐标 ⇒ 客户区坐标（UIA 给的矩形是屏幕坐标，喂输入前要换回来）。

    ★ 两端必须是同一个语义：矩形若是客户区坐标、这里又换一次，就会**双重偏移**
      （2026-10-30 实测偏 108,131，点全落到窗口左上方的空白处）。
    """
    pt = wintypes.POINT(int(sx), int(sy))
    user32.ScreenToClient(hwnd, ctypes.byref(pt))

    return pt.x, pt.y


def dpr_of(hwnd) -> float:
    """该窗口所在显示器的缩放（96dpi = 1.0）；查不到按 1.0 算。

    ★ "客户区 == 声明尺寸 × dpr" 这一族判据都要它，原来 `probe-window.py` 与
      `probe-two-windows.py` 各写一份（后者连异常兜底注释都一样）。
    """
    try:
        user32.GetDpiForWindow.restype = wintypes.UINT
        user32.GetDpiForWindow.argtypes = [wintypes.HWND]

        return user32.GetDpiForWindow(hwnd) / 96.0
    except Exception:  # noqa: BLE001 —— 查不到按 1.0 算，调用方的容差会兜住
        return 1.0


# ─────────────────────────── 真实输入（唯一通道）───────────────────────────
#
# ★ 下面三个是**本仓唯一的输入实现**。它们都先验前台，且只走 `SetCursorPos` +
#   `mouse_event` —— 不提供 `PostMessage` 版本，见文件头。

def move(hwnd, x, y, settle=0.0):
    """把**系统光标**真移到客户区 (x,y)。

    ★ 不是伪造 `WM_MOUSEMOVE`：`TrackMouseEvent(TME_LEAVE)` 依据的是**真实光标
      位置**，伪造消息时应用照常推进悬停动画，但画面不变 ⇒ 悬停判据假红。
    """
    require_foreground(hwnd)
    sx, sy = to_screen(hwnd, x, y)
    user32.SetCursorPos(sx, sy)
    if settle > 0:
        time.sleep(settle)


def click(hwnd, x, y, settle=0.0):
    """真点击客户区 (x,y)。"""
    require_foreground(hwnd)
    sx, sy = to_screen(hwnd, x, y)
    user32.SetCursorPos(sx, sy)
    time.sleep(0.05)
    user32.mouse_event(MOUSEEVENTF_LEFTDOWN, 0, 0, 0, 0)
    time.sleep(0.05)
    user32.mouse_event(MOUSEEVENTF_LEFTUP, 0, 0, 0, 0)
    if settle > 0:
        time.sleep(settle)


def wheel(hwnd, x, y, notches, settle=0.0):
    """真滚轮：`notches > 0` = **向下**滚（与"往后翻"同向）。

    ★ 伪造 `WM_MOUSEWHEEL` 走不到 `ScrollBarFade::noteScroll()` ⇒ 条从来没开始
      计算空闲，而画面看起来"滚过了"（内容确实动了）—— 淡出判据假红。
    ★ 调用方过去传的是 `delta`（±120），语义容易反；这里只收**格数**。
    """
    require_foreground(hwnd)
    sx, sy = to_screen(hwnd, x, y)
    user32.SetCursorPos(sx, sy)
    time.sleep(0.05)
    delta = -WHEEL_DELTA if notches > 0 else WHEEL_DELTA
    for _ in range(abs(int(notches))):
        user32.mouse_event(MOUSEEVENTF_WHEEL, 0, 0, delta, 0)
        time.sleep(0.05)
    if settle > 0:
        time.sleep(settle)


def park_pointer():
    """把光标挪到屏幕角落，别让悬停态掺进后续截图。"""
    user32.SetCursorPos(5, 5)


# ─────────────────────────── 用户状态隔离 ───────────────────────────
#
# ★ 应用退出时会写 `%APPDATA%\Drift\<app>.window`（真实用户的窗口几何）。
#   探针要验的是**默认尺寸**，留着上次的几何会把正确行为判成 FAIL；
#   而"跑一次测试就把用户的窗口位置改掉"更不可接受。
#   ⇒ 起进程前挪开、收尾时原样还回去（`atexit` 兜异常路径）。
#   原来只有 `probe-window.py` 自己做这件事，其余 8 个探针都没做 —— 迟早出事。

def _state_path(app_name):
    appdata = os.environ.get("APPDATA")

    return os.path.join(appdata, "Drift", f"{app_name}.window") if appdata else None


class StateStash:
    def __init__(self, app_name):
        self.path = _state_path(app_name)
        self.saved = None
        self.done = False

    def take(self):
        if not self.path or not os.path.isfile(self.path):
            return
        with open(self.path, "rb") as fh:
            self.saved = fh.read()
        os.remove(self.path)

    def restore(self):
        if self.done or not self.path:
            return
        self.done = True
        if self.saved is None:
            if os.path.isfile(self.path):
                os.remove(self.path)
            return
        os.makedirs(os.path.dirname(self.path), exist_ok=True)
        with open(self.path, "wb") as fh:
            fh.write(self.saved)


# ─────────────────────────── 一个产物 = 一个 App ───────────────────────────

class App:
    """起产物 → 等窗 → （可选）抢前台 → 用 → 收尾。异常路径也保证不留进程。

    `interactive=False` 用于"只看不摸"的探针（量尺寸、投 `WM_CLOSE`）：它们
    不需要前台，抢前台反而会抢走用户正在用的窗口。
    """

    def __init__(self, exe, title, args=(), interactive=True, settle=0.6, timeout=20.0,
                 isolate_state=True):
        self.exe = exe if os.path.isabs(exe) else os.path.join(ROOT, exe)
        self.title = title
        self.args = tuple(args)
        self.interactive = interactive
        self.settle = settle
        self.timeout = timeout
        self.proc = None
        self.hwnd = None
        self._state = StateStash(os.path.splitext(os.path.basename(self.exe))[0]) \
            if isolate_state else None

    # ── 生命周期 ──
    def __enter__(self):
        if self._state is not None:
            self._state.take()
            atexit.register(self._state.restore)   # 崩了也要还回去
        self.proc = launch(self.exe, self.args)
        self.hwnd = wait_window(self.title, self.proc, self.timeout)
        if self.interactive:
            require_foreground(self.hwnd)
        time.sleep(self.settle)   # 首帧 + 首轮动画收口

        return self

    def __exit__(self, exc_type, exc, tb):
        self.close()

        return False

    def close(self, timeout=3.0):
        """先礼后兵：`WM_CLOSE` ⇒ 等 ⇒ `terminate`。"""
        if self.hwnd:
            user32.PostMessageW(self.hwnd, WM_CLOSE, 0, 0)
        if self.proc is not None:
            try:
                self.proc.wait(timeout=timeout)
            except Exception:
                self.proc.terminate()
                try:
                    self.proc.wait(timeout=timeout)
                except Exception:
                    pass
        if self._state is not None:
            self._state.restore()

    # ── 截图 / 输入（都转到模块函数，App 不另存一份逻辑）──
    def grab(self):
        return grab(self.hwnd)

    def size(self):
        return client_size(self.hwnd)

    def move(self, x, y, settle=0.0):
        move(self.hwnd, x, y, settle)

    def click(self, x, y, settle=0.0):
        click(self.hwnd, x, y, settle)

    def wheel(self, x, y, notches, settle=0.0):
        wheel(self.hwnd, x, y, notches, settle)

    def park(self):
        park_pointer()


# ─────────────────────────── 量具自检 ───────────────────────────

def _selftest():
    """证明**这一层自己**能真把输入送进去 —— 否则下游探针的"没变"全是废话。

    做法：起示例台 → 点进「长列表 Big」（第 6 项）→ 真滚 3 格 → 内容区必须变。
    变不了有三种可能，输出里分得清：抢不到前台 / 点没落到导航 / 滚轮没生效。
    """
    def main():
        with App("showcase.exe", "Drift 示例台") as app:
            w, h = app.size()
            check("自检 · 客户区拿到了", w > 200 and h > 200, f"{w}x{h}")
            check("自检 · 窗口真在前台", user32.GetForegroundWindow() == app.hwnd)

            app.click(25, 90 + 5 * 36, settle=1.0)   # 导航第 6 项：长列表 Big
            app.park()
            time.sleep(0.3)
            base = app.grab()[2]

            app.wheel(w // 2, h // 2, 3, settle=0.5)
            app.park()
            time.sleep(0.3)
            scrolled = app.grab()

            from probe_pixels import diff_count
            w2, h2, data = scrolled
            moved = diff_count(base, data, w2, 0, 60, int(w2 * 0.75), h2 - 40)
            check("自检 · 真滚轮让内容动了（否则下游判据全无意义）", moved > 50, f"changed={moved}")
        return 0

    return run(main)


if __name__ == "__main__":
    sys.exit(_selftest() if "--selftest" in sys.argv else 0)
