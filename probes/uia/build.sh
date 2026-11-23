#!/usr/bin/env bash
# P1 UIA 可行性探针构建：纯 C++，不依赖 tpc / phpx。
# 手拼 MSVC 三件套（同 taskhost_swoole）：INCLUDE/LIB 用 Windows 分号+反斜杠，
# PATH 用 cygpath -u + 冒号（混了就是 "cl not found"）。
set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
MSVC='D://Microsoft Visual Studio\18\Community\VC\Tools\MSVC\14.51.36231'
KITS='D://Windows Kits\10'
SDK='10.0.26100.0'
export INCLUDE="$MSVC\\include;$KITS\\Include\\$SDK\\ucrt;$KITS\\Include\\$SDK\\shared;$KITS\\Include\\$SDK\\um"
export LIB="$MSVC\\lib\\x64;$KITS\\Lib\\$SDK\\ucrt\\x64;$KITS\\Lib\\$SDK\\um\\x64"
export PATH="$(cygpath -u "$MSVC")/bin/Hostx64/x64:$(cygpath -u "$KITS")/bin/$SDK/x64:$PATH"
# Git Bash 会把 /link、/DUNICODE 等当 Unix 路径转译（D9024）⇒ 全禁。
export MSYS2_ARG_CONV_EXCL="*"

cd "$HERE" || exit 1
cl.exe /nologo /EHsc /O2 /W3 /std:c++17 /DUNICODE /D_UNICODE host.cc /Fe:uia_probe.exe \
    /link UIAutomationCore.lib ole32.lib oleaut32.lib uuid.lib advapi32.lib user32.lib gdi32.lib
rc1=$?
# UIA 跨进程客户端：起 users.exe 读其 provider Name/ControlType（P2 真机闭环）
cl.exe /nologo /EHsc /O2 /W3 /std:c++17 /DUNICODE /D_UNICODE read.cc /Fe:uia_read.exe \
    /link UIAutomationCore.lib ole32.lib oleaut32.lib uuid.lib advapi32.lib user32.lib gdi32.lib
rc2=$?
# IScrollProvider 真机判据：读 + SetScrollPercent 写回（vtable 由编译器解析）
cl.exe /nologo /EHsc /O2 /W3 /std:c++17 /DUNICODE /D_UNICODE scroll.cc /Fe:uia_scroll.exe \
    /link UIAutomationCore.lib ole32.lib oleaut32.lib uuid.lib advapi32.lib user32.lib gdi32.lib
rc3=$?
echo "[build] cl rc probe=$rc1 read=$rc2 scroll=$rc3"
exit $((rc1 | rc2 | rc3))
