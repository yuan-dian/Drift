"""按 map 文件把 exe 的体积分账到「库 / 对象文件」。

用法：
    python tools/map-size.py watermark.map [--top 20]

为什么不用 dumpbin：`/MAP` 构建里已经开了（`build/base.yml`），它逐符号给出
`段:偏移 + 符号 + Rva+Base + 模块:对象`。没有 pdb 就没有真实函数长度，所以
用**相邻符号的偏移差**当体积 —— 同段内符号按地址排序，这是唯一定义明确的切分。

★ 近似：符号之间的对齐填充会算给前一个符号。对"谁占地方"这个量级的问题没影响，
  而且对齐浪费本身也确实占文件，算进去反而更接近真实占用。
★ `Static symbols` 段必须读：publics 不含 `static` 函数，而 AOT 出来的代码
  大量是 static（每个 PHP 方法一个）。
★ 两个坑（第一版都踩了）：
  ① `Rva+Base` 后面可能夹标志位（`f` / `i` / `f i`），`Lib:Object` 不一定在倒数第一
     个位置 ⇒ 先找 16 位 RVA 字段，再取它后面第一个非标志位字段。
  ② map 里有 `.idata$6  …  0000000140000000` 这种**节符号**，偏移字段是 `ff553000`
     这种越界值 —— 不按段长过滤，它会把前一个符号的"体积"撑到 4GB。
"""
import re
import sys
from collections import defaultdict

HEX4 = re.compile(r'[0-9a-f]{4}')
HEX8 = re.compile(r'[0-9a-f]{8}')
HEX16 = re.compile(r'[0-9a-f]{16}')
SECTION_ROW = re.compile(r'^\s+([0-9a-f]{4}):([0-9a-f]{8})\s+([0-9a-f]{8})H\s+(\S+)')
FLAGS = {'f', 'i', 'fi', 'if'}


def parse(path):
    """-> (段长 dict, {段: [(offset, sym, lib, obj), ...]})"""
    ends = {}
    syms = defaultdict(list)
    in_syms = False

    with open(path, 'r', encoding='utf-8', errors='replace') as fh:
        for line in fh:
            if not in_syms:
                m = SECTION_ROW.match(line)
                if m:
                    sec, start, length = m.group(1), int(m.group(2), 16), int(m.group(3), 16)
                    ends[sec] = max(ends.get(sec, 0), start + length)
                    continue
                if 'Publics by Value' in line or line.strip() == 'Static symbols':
                    in_syms = True
                continue

            parts = line.split()
            if len(parts) < 4 or ':' not in parts[0]:
                continue
            sec, off = parts[0].split(':')
            if not HEX4.fullmatch(sec) or not HEX8.fullmatch(off):
                continue
            if parts[1].startswith('.'):
                continue                       # `.idata$6` / `.text$mn` 这类节符号
            idx = next((i for i in range(2, len(parts)) if HEX16.fullmatch(parts[i])), None)
            if idx is None:
                continue                       # `entry point at` 之类
            rest = [t for t in parts[idx + 1:] if t not in FLAGS]
            if not rest:
                continue
            where = rest[0]
            if where.startswith('<') or where.startswith('*'):
                continue
            lib, obj = (where.split(':', 1) + [''])[:2]
            syms[sec].append((int(off, 16), parts[1], lib.lower(), obj))

    # ★ 越界偏移直接丢：它们不占任何真实空间，留着会把前一个符号撑成 4GB
    for sec in list(syms):
        limit = ends.get(sec, 0)
        syms[sec] = [s for s in syms[sec] if s[0] < limit]

    return ends, syms


def main():
    path = sys.argv[1]
    top = int(sys.argv[sys.argv.index('--top') + 1]) if '--top' in sys.argv else 20

    ends, by_sec = parse(path)

    per_lib = defaultdict(int)
    per_obj = defaultdict(int)
    per_sec = defaultdict(int)
    spans = []

    for sec, syms in by_sec.items():
        syms.sort()
        end = ends.get(sec, 0)
        for i, (off, sym, lib, obj) in enumerate(syms):
            stop = syms[i + 1][0] if i + 1 < len(syms) else end
            size = max(0, stop - off)
            per_lib[lib] += size
            per_obj[(lib, obj)] += size
            per_sec[sec] += size
            spans.append((size, sym, lib, obj))

    total = sum(per_lib.values())
    print('可归因 %.2f MB（= 各段内符号跨度之和，含对齐填充）\n' % (total / 1048576))

    print('== 按段 ==')
    for sec, size in sorted(per_sec.items(), key=lambda kv: -kv[1]):
        print('  %8.2f MB  %4.1f%%  段 %s' % (size / 1048576, 100.0 * size / total, sec))

    print('\n== 按库/对象（前 %d）==' % top)
    for (lib, obj), size in sorted(per_obj.items(), key=lambda kv: -kv[1])[:top]:
        name = (lib + ':' + obj) if obj else lib
        print('  %8.2f MB  %4.1f%%  %s' % (size / 1048576, 100.0 * size / total, name))

    print('\n== 单个符号最大（前 12）==')
    for size, sym, lib, obj in sorted(spans, reverse=True)[:12]:
        print('  %7.1f KB  %-52s %s' % (size / 1024, sym[:52], (lib + ':' + obj)[:44]))


if __name__ == '__main__':
    main()
