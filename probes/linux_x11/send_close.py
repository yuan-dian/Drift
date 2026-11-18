import ctypes
import struct
import sys

x11 = ctypes.CDLL("libX11.so.6")
x11.XOpenDisplay.restype = ctypes.c_void_p
x11.XOpenDisplay.argtypes = [ctypes.c_char_p]
x11.XInternAtom.restype = ctypes.c_ulong
x11.XInternAtom.argtypes = [ctypes.c_void_p, ctypes.c_char_p, ctypes.c_int]
x11.XSendEvent.restype = ctypes.c_int
x11.XSendEvent.argtypes = [ctypes.c_void_p, ctypes.c_ulong, ctypes.c_int, ctypes.c_long, ctypes.c_void_p]
x11.XFlush.argtypes = [ctypes.c_void_p]
x11.XCloseDisplay.argtypes = [ctypes.c_void_p]

win = int(sys.argv[1], 0)
d = x11.XOpenDisplay(None)
if not d:
    print("XOpenDisplay failed")
    sys.exit(1)
atom = x11.XInternAtom(d, b"WM_DELETE_WINDOW", 0)
buf = ctypes.create_string_buffer(192)
struct.pack_into("i", buf, 0, 33)
struct.pack_into("i", buf, 16, 1)
struct.pack_into("Q", buf, 24, d)
struct.pack_into("Q", buf, 32, win)
struct.pack_into("Q", buf, 40, atom)
struct.pack_into("i", buf, 48, 32)
struct.pack_into("Q", buf, 56, atom)
rc = x11.XSendEvent(d, win, 0, 0, buf)
x11.XFlush(d)
x11.XCloseDisplay(d)
print("XSendEvent rc={} atom={} win={}".format(rc, atom, win))
