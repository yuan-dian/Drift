"""给 probe 窗口注入按键 / 滚轮事件。

XSendEvent 直接投递原生事件到目标窗口（关窗脚本已验证这条路可靠）。
不用 XTest：XTest 走 X server 的输入焦点，fluxbox 会把焦点抢走，
按键进了 WM 而不是 probe 窗口 —— 实测事件一条都到不了桥。

用法：python3 send_input.py <window-id>
"""
import ctypes
import struct
import sys

x11 = ctypes.CDLL("libX11.so.6")
x11.XOpenDisplay.restype = ctypes.c_void_p
x11.XOpenDisplay.argtypes = [ctypes.c_char_p]
x11.XCloseDisplay.argtypes = [ctypes.c_void_p]
x11.XFlush.argtypes = [ctypes.c_void_p]
x11.XKeysymToKeycode.restype = ctypes.c_ubyte
x11.XKeysymToKeycode.argtypes = [ctypes.c_void_p, ctypes.c_ulong]
x11.XSendEvent.restype = ctypes.c_int
x11.XSendEvent.argtypes = [ctypes.c_void_p, ctypes.c_ulong, ctypes.c_int, ctypes.c_long, ctypes.c_void_p]

win = int(sys.argv[1], 0)
d = x11.XOpenDisplay(None)
if not d:
    print("XOpenDisplay failed")
    sys.exit(1)


def send(buf, typ):
    struct.pack_into("i", buf, 0, typ)
    struct.pack_into("i", buf, 16, 1)       # send_event = True
    struct.pack_into("Q", buf, 24, d)       # display
    struct.pack_into("Q", buf, 32, win)     # window
    return x11.XSendEvent(d, win, 0, 0, buf)


def key(sym):
    """KeyPress + KeyRelease（XKeyEvent 布局：state@72, keycode@76）。"""
    code = x11.XKeysymToKeycode(d, sym)
    if code == 0:
        print("no keycode for keysym=%#x" % sym)
        return
    for typ in (2, 3):                       # KeyPress / KeyRelease
        buf = ctypes.create_string_buffer(192)
        struct.pack_into("I", buf, 72, 0)    # state
        struct.pack_into("I", buf, 76, code)
        struct.pack_into("i", buf, 80, 1)    # same_screen
        send(buf, typ)


def button(n):
    """ButtonPress + ButtonRelease（XButtonEvent 布局：state@72, button@76）。"""
    for typ in (4, 5):                       # ButtonPress / ButtonRelease
        buf = ctypes.create_string_buffer(192)
        struct.pack_into("i", buf, 56, 10)   # x
        struct.pack_into("i", buf, 60, 10)   # y
        struct.pack_into("I", buf, 72, 0)    # state
        struct.pack_into("I", buf, 76, n)    # button 4/5 = 竖轮
        struct.pack_into("i", buf, 80, 1)    # same_screen
        send(buf, typ)


# 'a' → key('A') + char('a')；'1' → key('1') + char('1')
key(0x61)
key(0x31)
# 竖轮前推/后推（桥映射为 -1 / +1 格）
button(4)
button(5)
x11.XFlush(d)
x11.XCloseDisplay(d)
print("XSendEvent injected: keys a,1 + wheel 4,5")
