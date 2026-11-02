#!/usr/bin/env python3
"""PE 导出表 / 符号近似翻译。

没有 pdb/map 的 DLL（php8ts.dll、phpx.dll）只能靠导出表定位：
导出表按 RVA 排序后二分，取 <= rva 的最近导出并给偏移。
误差 = 该导出到崩点的距离，对"这一族函数"的判断足够；对精确行号无意义。

用法：
  python tools/pe-sym.py <dll> +0x1ae3c [more...]
  python tools/pe-sym.py <dll> --near 0x1ae3c          # 前后各两条
"""
import struct
import sys


def load_exports(path):
    data = open(path, "rb").read()
    if data[:2] != b"MZ":
        raise SystemExit(f"不是 PE：{path}")
    pe = struct.unpack_from("<I", data, 0x3C)[0]
    if data[pe:pe + 4] != b"PE\0\0":
        raise SystemExit(f"不是 PE：{path}")
    coff = pe + 4
    nsec, = struct.unpack_from("<H", data, coff + 2)
    optsz, = struct.unpack_from("<H", data, coff + 16)
    opt = coff + 20
    magic, = struct.unpack_from("<H", data, opt)
    ddoff = opt + (112 if magic == 0x20B else 96)
    exp_rva, exp_size = struct.unpack_from("<II", data, ddoff)
    if exp_rva == 0:
        return [], 0
    # 段表
    secs = []
    secbase = opt + optsz
    for i in range(nsec):
        off = secbase + i * 40
        name = data[off:off + 8].rstrip(b"\0").decode("ascii", "replace")
        vsize, vaddr, rawsize, rawptr = struct.unpack_from("<IIII", data, off + 8)
        secs.append((vaddr, vsize, rawptr, rawsize, name))

    def rva2off(rva):
        for vaddr, vsize, rawptr, rawsize, _ in secs:
            if vaddr <= rva < vaddr + max(vsize, rawsize):
                return rawptr + (rva - vaddr)
        return None

    eo = rva2off(exp_rva)
    nfun, nname = struct.unpack_from("<II", data, eo + 20)[0], struct.unpack_from("<I", data, eo + 24)[0]
    afun, aname, aord = struct.unpack_from("<III", data, eo + 28)
    fo = rva2off(afun)
    no = rva2off(aname)
    oo = rva2off(aord)
    out = []
    for i in range(nname):
        nrva, = struct.unpack_from("<I", data, no + i * 4)
        noff = rva2off(nrva)
        end = data.index(b"\0", noff)
        nm = data[noff:end].decode("ascii", "replace")
        oi, = struct.unpack_from("<H", data, oo + i * 2)
        if oi >= nfun:
            continue
        frva, = struct.unpack_from("<I", data, fo + oi * 4)
        out.append((frva, nm))
    out.sort()
    return out, exp_rva


def fmt(sym, rva):
    return f"+0x{rva:X}  {sym}  (Δ+0x{rva - sym[0]:X})"


def main():
    args = sys.argv[1:]
    if len(args) < 2:
        raise SystemExit(__doc__)
    dll = args[0]
    exports, base = load_exports(dll)
    print(f"{dll}: {len(exports)} exports, export dir rva=0x{base:X}")
    near = "--near" in args
    for a in args[1:]:
        if a.startswith("--"):
            continue
        rva = int(a[1:], 16) if a.lower().startswith("+0x") else int(a, 0)
        # 二分：最后一个 rva <= 目标的导出
        lo, hi, idx = 0, len(exports) - 1, -1
        while lo <= hi:
            mid = (lo + hi) // 2
            if exports[mid][0] <= rva:
                idx = mid
                lo = mid + 1
            else:
                hi = mid - 1
        if idx < 0:
            print(f"  0x{rva:X}: 在第一个导出之前（{exports[0][1]} @0x{exports[0][0]:X}）")
            continue
        print("  " + fmt(exports[idx], rva))
        if near:
            for j in range(max(0, idx - 1), min(len(exports), idx + 2)):
                if j != idx:
                    print(f"     邻居 {fmt(exports[j], exports[j][0])}")


if __name__ == "__main__":
    main()
