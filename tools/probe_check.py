"""真机探针的**判据协议** —— 三态：PASS / FAIL / **没测到（SKIP）**。

★★ 存在的理由（2026-11-01，两次假红都栽在同一处）

  探针能给出的结论只有三种，而过去所有探针只写得出两种：`ok` 与 `FAIL`。
  于是"我根本没采到目标"只能被塞进 `FAIL` 里 —— 下一棒打开日志看到的是
  「滚动条一条都没画」，追了 90 分钟才发现条画着、也在淡，只是探针在**窗口右缘**
  采样（那里是页面底色，整列恒 238.9）。

  把三种结论压成两种，是**协议**的问题，不是某个探针的疏忽 —— 所以修在这里：

    判据全过      ⇒ 退出码 **0**（判完了，结论在输出里）
    任一条不过    ⇒ 退出码 **1**（产品/实现真的不对）
    没采到 / 送不进输入 ⇒ 退出码 **2**（**没测到**，不是没通过）

  `2` 这条通道是本节的全部意义。它现在有了唯一实现，任何探针想"沉默地把没测到
  写成没变"都得先绕过 `run()`。

★ 用法（每个探针的骨架都一样）：

    from probe_check import check, need, run
    import probe_check

    def main():
        frame = need(grab(hwnd), "没截到客户区")   # None ⇒ 直接 SKIP
        check("内容真的滚了", moved > 50, f"changed={moved}")
        ...
        return 0

    if __name__ == "__main__":
        sys.exit(run(main))

★ 与成熟框架的对应：Playwright 的 `test.skip()` / pytest 的 `pytest.skip`
  也是"没条件测就别报红"；区别只在于本仓的探针是**脚本**不是测试框架，
  所以三态要自己带。
"""

import sys


class NotMeasured(Exception):
    """**没测到** —— 采不到目标、抢不到前台、送不进输入。

    它不是失败：失败是"产品不对"，它只是"这次没量成"。`run()` 把它翻成退出码 2。
    """


_fails = []
_total = 0


def _utf8_output():
    """CI runner 的 Python 可能以 cp1252 开 stdout，中文判据名会直接抛 UnicodeEncodeError。

    窗口其实已经探测成功，挂在这一行等于把「日志编码」误报成「产品失败」；
    而且判据名本身就是中文，躲不开，只能在统一入口把流的编码钉住。
    """
    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(encoding="utf-8", errors="backslashreplace")
        except (AttributeError, ValueError):
            pass


def check(name, ok, detail=""):
    """记一条判据。`ok` 为假时不会立刻中断 —— 一次跑完拿到全貌。"""
    global _total
    _total += 1
    print(f"  {'ok  ' if ok else 'FAIL'}  {name}" + (f"  —— {detail}" if detail else ""))
    if not ok:
        _fails.append(name)
    return bool(ok)


def need(value, reason):
    """值必须是"采到了"；`None` ⇒ 抛 `NotMeasured`（SKIP），**不是** FAIL。

    ★ 这一行是"判据先自证落在目标内部"那条纪律的执行点：定位函数返回 `None`
      时，调用方没有别的写法可用。
    """
    if value is None:
        raise NotMeasured(reason)
    return value


def skip(reason):
    """立刻以"没测到"收尾（用于前置条件不成立，后续判据全都无意义时）。"""
    raise NotMeasured(reason)


def run(main):
    """统一收尾。返回**退出码**（探针不自己拼 `--- N FAIL ---`）。

    ★ 顺序是"**失败优先**"：已经记下 FAIL 之后再走到 SKIP（例如"入口按钮不见了"
      记一条 FAIL，然后下游判据跳过），结论必须仍是 1 —— 否则一次 SKIP 会把
      一条**真发现**吞掉。
    ★ 反过来，一条 FAIL 都没记时不报失败：环境没测成不等于产品坏了。
    """
    skipped = None
    _utf8_output()
    try:
        rc = main()
    except NotMeasured as exc:
        skipped = str(exc)
        rc = None

    if rc not in (None, 0):
        return rc
    if _fails:
        if skipped:
            print(f"\n（另有没测到的部分：{skipped}）")
        print(f"\n--- {len(_fails)} FAIL / {_total} 条判据 ---")
        for name in _fails:
            print(f"  · {name}")
        return 1
    if skipped:
        print(f"\nSKIP: {skipped}   ← 没测到，不是没通过（退出码 2）")
        return 2
    print(f"\n--- 0 FAIL（{_total} 条判据）---")

    return 0
