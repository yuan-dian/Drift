"""给 probe 窗口注入一次"用户切走"的 FocusOut 事件。

XSendEvent 直接投递到目标窗口（与 send_input.py / send_close.py 同款）。
不用 XSetInputFocus：fluxbox 的焦点归属不稳定，主窗口可能从未拿到焦点，
切焦点不保证生成 FocusOut；XSendEvent 直投确定进队列。

XFocusChangeEvent 布局（64-bit）：type@0, send_event@16, display@24,
window@32, mode@40, detail@44。mode=0(NotifyNormal) + detail=3(NotifyNonlinear)
= "焦点切到一个不相关窗口"（用户 Alt+Tab 切走），桥据此吐 deactivate。

用法：python3 send_focus.py <window-id>
"""
import ctypes
import struct
import sys

x11 = ctypes.CDLL("libX11.so.6")
x11.XOpenDisplay.restype = ctypes.c_void_p
x11.XOpenDisplay.argtypes = [ctypes.c_char_p]
x11.XCloseDisplay.argtypes = [ctypes.c_void_p]
x11.XFlush.argtypes = [ctypes.c_void_p]
x11.XSendEvent.restype = ctypes.c_int
x11.XSendEvent.argtypes = [ctypes.c_void_p, ctypes.c_ulong, ctypes.c_int, ctypes.c_long, ctypes.c_void_p]

win = int(sys.argv[1], 0)
d = x11.XOpenDisplay(None)
if not d:
    print("XOpenDisplay failed")
    sys.exit(1)

buf = ctypes.create_string_buffer(192)
struct.pack_into("i", buf, 0, 10)        # type = FocusOut
struct.pack_into("i", buf, 16, 1)        # send_event = True
struct.pack_into("Q", buf, 24, d)        # display
struct.pack_into("Q", buf, 32, win)      # window
struct.pack_into("i", buf, 40, 0)        # mode = NotifyNormal
struct.pack_into("i", buf, 44, 3)        # detail = NotifyNonlinear（切到别的应用）
x11.XSendEvent(d, win, 0, 0, buf)
x11.XFlush(d)
x11.XCloseDisplay(d)
print("XSendEvent injected: FocusOut(mode=Normal, detail=Nonlinear)")
