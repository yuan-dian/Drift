"""真机验证异步取数端到端：UI 提交 → 后端 → 回贴 → UI 解锁。

    python tools/probe-async.py

`DRIFT_PROBE_ENV_JSON` 可注入启动环境（与 probe-window.py 同款）；例如 showcase
的 `{"DRIFT_SHOWCASE_HTTP":"1"}` 让同一套判据走 UI 侧 curl_multi 而非 worker。

判据（五条缺一不可）：
  ① 点得到异步页入口（UIA 语义名）
  ② 提交后「重试」按钮变禁用 —— 任务确实在途
  ③ 随后按钮恢复 —— worker 回贴已交付 UI
  ④ 点「下一页」再次进入在途态 —— 不是一次性缓存
  ⑤ 第二次回贴完成

★ 为什么读按钮而不是读文本：`Text` 控件不进本仓的语义树（真机 dump 证实），
  而「重试」的 `IsEnabled` 直接来自 `AsyncCommand::isRunning()` —— 它是任务
  生命周期在 UI 上的真实投影，比像素 diff 更精确，也不受文案改动影响。
★ 独立成层而不是扩 `probe-window.py`：那条刻意 `interactive=False`，桌面锁定时
  照样能跑；这里必须真点导航，前置条件不同，混进去会让窗口五条跟着 SKIP。
★ 不往应用或框架里加探针钩子：全走真实用户路径，生产代码零改动。

★ 与成熟框架的对应：Playwright `expect(locator).to_be_enabled()` 和 Espresso
  `onView(...).check(matches(isEnabled()))` —— 按语义定位、轮询到超时，
  不看像素、不固定 sleep。
"""

import contextlib
import importlib.util
import io
import json
import os
import sys
import time

from probe_check import NotMeasured, check, run, skip
from probe_win import ROOT, App, click_by_name

UIA_IS_ENABLED = 30010
NAV = "异步表格 Async"
RETRY = "重试"
NEXT = "下一页"
UPLOAD = "上传示例"


def _uia_module():
    """与 `probe_win._uia()` 共用同一份模块（文件名带横线，只能 importlib）"""
    mod = sys.modules.get("probe_uia")
    if mod is not None:
        return mod
    spec = importlib.util.spec_from_file_location("probe_uia", os.path.join(ROOT, "tools", "probe-uia.py"))
    mod = importlib.util.module_from_spec(spec)
    sys.modules["probe_uia"] = mod
    spec.loader.exec_module(mod)

    return mod


def _find(U, api, walker, hwnd, target):
    # 页面重建时旧 UIA 节点会当场失效，整树遍历可能抛 COM 错误。
    # 重试整次遍历（而不是吞掉后当没找到），结果未回来仍由超时判 FAIL。
    for _attempt in range(4):
        found = []
        try:
            root = U.element_from_handle(api, hwnd)
            with contextlib.redirect_stdout(io.StringIO()):
                U.dump(root, walker, found=found, target=target)
        except OSError:
            time.sleep(0.1)
            continue
        for elem, _rect, _ctype in found:
            if elem.value:
                return elem

        return None

    return None


def _wait_enabled(U, api, walker, hwnd, name, want, timeout=8.0):
    """轮询 UIA 的 IsEnabled；按钮还没渲染时会短暂找不到，不算失败。"""
    deadline = time.time() + timeout
    while time.time() < deadline:
        elem = _find(U, api, walker, hwnd, name)
        if elem is not None:
            got = U.prop_of(elem, UIA_IS_ENABLED)[1]
            if got is want:
                return True
        time.sleep(0.1)

    return False


def _click_named(hwnd, name):
    """公共层找不到目标抛 NotMeasured（= 没测到）；入口点不到本身要记 FAIL。"""
    try:
        click_by_name(hwnd, name, settle=0.0)
    except NotMeasured:
        return False

    return True


def main():
    exe = os.environ.get("DRIFT_PROBE_EXE", "showcase.exe")
    args = json.loads(os.environ.get("DRIFT_PROBE_ARGS_JSON", "[]"))
    env_extra = json.loads(os.environ.get("DRIFT_PROBE_ENV_JSON", "null"))
    with App(exe, "Drift 示例台", args=args, interactive=True, env_extra=env_extra) as app:
        U = _uia_module()
        api = U.uia_create()  # COM 对象要留引用，内联会被 GC 收掉
        walker = U.walker(api, control_view=True)

        if not check(f"① 点得到导航项「{NAV}」", _click_named(app.hwnd, NAV)):
            skip("导航没切过去，后面四条量不到")

        # 先看到在途、再看到完成 —— 顺序本身就是判据：同步数据或当场失败
        # 都不会经过禁用态，那种情况下③就算碰巧解锁也不能算"走通了异步"。
        busy = _wait_enabled(U, api, walker, app.hwnd, RETRY, False, timeout=3.0)
        if not check("② 提交后「重试」禁用（请求在途）", busy):
            skip("没看到在途态，后面的完成判据分不清是回贴还是同步数据")

        check("③ 随后「重试」恢复（结果已交付 UI）",
              _wait_enabled(U, api, walker, app.hwnd, RETRY, True, timeout=10.0))

        if not check(f"入口：点得到「{NEXT}」", _click_named(app.hwnd, NEXT)):
            skip("翻页按钮点不到，④⑤ 量不到")
        check("④ 翻页再次进入在途态（不是一次性缓存）",
              _wait_enabled(U, api, walker, app.hwnd, RETRY, False, timeout=3.0))
        check("⑤ 第二次回贴完成",
              _wait_enabled(U, api, walker, app.hwnd, RETRY, True, timeout=10.0))

        # ⑥⑦⑧ 只在 HTTP 档存在：N6 第二条分流（multipart → HttpRequestJob 进程池）
        # 的真机端到端。local 档没注入上传闭包、没有这个按钮 ⇒ 正常收尾（既有判据全绿），
        # 不记 SKIP：SKIP 会把退出码变 2，破坏 local 档"全绿=0"的既有承诺。
        http_mode = (env_extra or {}).get("DRIFT_SHOWCASE_HTTP") == "1"
        if not http_mode:
            return 0
        if not check("⑥ 点得到「上传示例」（HTTP 档才有）", _click_named(app.hwnd, UPLOAD)):
            skip("上传按钮点不到，⑦⑧ 量不到")
        check("⑦ 上传期间「上传示例」禁用（multipart 在途，进程池）",
              _wait_enabled(U, api, walker, app.hwnd, UPLOAD, False, timeout=3.0))
        check("⑧ 上传完成按钮恢复（onProgress / 结果已回贴 UI）",
              _wait_enabled(U, api, walker, app.hwnd, UPLOAD, True, timeout=15.0))

    return 0


if __name__ == "__main__":
    sys.exit(run(main))
