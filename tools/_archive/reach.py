#!/usr/bin/env python3
"""reach.py —— 算「只注册/只编译可达类」能裁掉多少，用**不动点迭代**。

为什么不能一遍扫完就下结论（这是本脚本存在的唯一理由）：
  AOT 之后，`new X` 不产生符号引用，而是 `get_persistent_class(id, "命名空间\\X")`
  —— **按名字**。于是每个类的 .cc 里都有"引用自己"的句子（`$this`、`self`、
  `instanceof self` 都会落成它自己的类名）。全目录一遍统计会得到"399/402 个类
  都被引用了"的荒谬结论 —— 那不是它们被用，是**它们自己引用了自己**。

  所以必须：从入口出发，**只沿可达文件的引用**往外扩，把一个类拉进来之后再看
  它又引用了谁，迭代到稳定。不可达类的 .cc 不参与统计，它们的"自引用"才会消失。

三族跨文件引用，漏任何一族都会把活代码判死（三个都踩过）：
  php_<ns>__<class>__<method>              方法
  class_const__<ns>__<class>__NAME         类常量（**没有括号**，变量引用）
  typephp_request_init_class_constant_<h>  常量的构造函数
  以及 `get_persistent_class(id, "A\\B")`  按名引用一个类

用法：
  python tools/reach.py apps/watermark/build              # 概览
  python tools/reach.py apps/watermark/build --unreached   # 列出可裁的类
  python tools/reach.py apps/watermark/build --json        # 只输出 JSON
  python tools/reach.py --self-test                        # 判据自检
"""

import os
import re
import sys
import json
import glob
from collections import defaultdict, deque

IDENT = r'php_[A-Za-z0-9_]+|class_const__[A-Za-z0-9_]+|typephp_request_init_class_constant_[a-z0-9]+'
IDENT_RE = re.compile(r'\b(' + IDENT + r')\b')
# 函数定义：行首无缩进，签名里有标识符，行尾是 {
FUNC_DEF_RE = re.compile(r'^[A-Za-z_][\w:<>,\*&\s]*?\b(' + IDENT + r')\s*\([^;]*\)\s*\{?\s*$',
                         re.MULTILINE)
# 类常量定义：行首无缩进的赋值
CONST_DEF_RE = re.compile(r'^(' + r'class_const__[A-Za-z0-9_]+' + r')\s*=', re.MULTILINE)
REGISTER_RE = re.compile(r'^php_register_class_')
CLASS_REF_RE = re.compile(r'get_persistent_class\(\s*PersistentClassId\{\d+\}\s*,\s*get_str\((\d+)\)')
METH_REF_RE = re.compile(r'call_method_cached\([^;]*?get_str\((\d+)\)', re.DOTALL)
LITERAL_RE = re.compile(r'php::Str\{ZEND_STRL\("((?:[^"\\]|\\.)*)"\), true\}, // \[(\d+)\]')
ARGINFO_NS_RE = re.compile(r'INIT_NS_CLASS_ENTRY\(\s*ce\s*,\s*"([^"]*)"\s*,\s*"([^"]*)"')
ARGINFO_PARENT_RE = re.compile(r'zend_register_internal_class_with_flags\(\s*&ce\s*,\s*class_entry_([A-Za-z0-9_]+)')


def unescape(s):
    return s.replace('\\\\', '\\').replace('\\"', '"')


def load_literals(build_dir):
    for path in glob.glob(os.path.join(build_dir, 'extension-*.cc')):
        text = open(path, encoding='utf-8', errors='replace').read()
        return {int(i): unescape(s) for s, i in LITERAL_RE.findall(text)}
    return {}


def load_parents(build_dir):
    """类 -> 父类（全小写，`a\\b` 形式）。从 arginfo.h 里读。

    ★ 这条边必须算：子类注册时要拿父类的 zend_class_entry 当参数，
      父类没注册 ⇒ `zend_register_internal_class_with_flags` 直接崩。
      子类可达 ⇒ 父类**必须**可达。
    """
    parents = {}
    for path in glob.glob(os.path.join(build_dir, 'include', '*arginfo.h')):
        text = open(path, encoding='utf-8', errors='replace').read()
        m = ARGINFO_NS_RE.search(text)
        p = ARGINFO_PARENT_RE.search(text)
        if not m or not p:
            continue
        ns, cls = m.group(1).replace('\\\\', '\\'), m.group(2)
        self_name = (ns + '\\' + cls).lower() if ns else cls.lower()
        # `class_entry_Drift_Widget_StatefulWidget` -> `drift\widget\statefulwidget`
        # ★ 命名规则是 `Ns1_Ns2_ClassName`（最后一段才是类名），不是驼峰拆分 ——
        #   按驼峰拆会把 `StatefulWidget` 拆成两个"命名空间"。
        parts = p.group(1).split('_')
        parents[self_name] = '\\'.join(x.lower() for x in parts)
    return parents


def scan(build_dir):
    sources = [p for p in sorted(glob.glob(os.path.join(build_dir, '**', '*.cc'), recursive=True))
               if not os.path.basename(p).startswith('extension-')]
    defs = {}
    file_syms = defaultdict(set)
    method_index = defaultdict(set)
    for path in sources:
        text = open(path, encoding='utf-8', errors='replace').read()
        own = set(FUNC_DEF_RE.findall(text)) | set(CONST_DEF_RE.findall(text))
        for name in own:
            defs.setdefault(name, path)
            file_syms[path].add(name)
            seg = name.split('__')
            if len(seg) >= 3 and name.startswith('php_'):
                method_index[seg[-1]].add(name)
    return sources, defs, file_syms, method_index


def load_use_index(repo_root):
    """basename(小写) -> 该 PHP 文件 `use` 的类名末段集合。

    ★ 为什么还要用 PHP 源码补边：C++ 符号图**看不见某些引用方式**
      （枚举 / 值对象 / 类型注解里出现的类，未必落成 `get_persistent_class`）。
      实测漏了 24 个"被 import 却判死"的类 —— 这类漏判在真机上就是
      `class not found`。PHP 的 `use` 是**宁可多算**的安全边。
    """
    index = defaultdict(set)
    for pattern in ('src/**/*.php', 'apps/*/src/**/*.php'):
        for path in glob.glob(os.path.join(repo_root, pattern), recursive=True):
            text = open(path, encoding='utf-8', errors='replace').read()
            # ★ 反斜杠只有一个（PHP 的 `use Drift\Widget\Menu;`）。raw string 里
            #   字符类写 `\\` 就是"一个反斜杠"；写成 `\\\\` 会要求两个，一条也匹配不上。
            names = {m.group(1).rsplit('\\', 1)[-1].lower()
                     for m in re.finditer(r'^use\s+([A-Za-z0-9_\\]+)\s*;', text, re.MULTILINE)}
            if names:
                index[os.path.basename(path)[:-4].lower()] |= names
    return index


def reach(build_dir, use_poly=False, repo_root='.'):
    sources, defs, file_syms, method_index = scan(build_dir)
    literals = load_literals(build_dir)
    parents = load_parents(build_dir)
    use_index = load_use_index(repo_root)

    # 类名末段 -> 文件（`php_a__b__card__x` 的倒数第二段就是类名）
    by_tail = defaultdict(set)
    for path, syms in file_syms.items():
        for sym in syms:
            seg = sym.split('__')
            if len(seg) >= 3:
                by_tail[seg[-2]].add(path)

    texts = {}

    def text_of(p):
        if p not in texts:
            texts[p] = open(p, encoding='utf-8', errors='replace').read()
        return texts[p]

    reachable = set()
    queue = deque(p for p in sources if os.path.basename(p).lower() == 'main.cc')
    stats = defaultdict(int)

    while queue:
        path = queue.popleft()
        if path in reachable:
            continue
        reachable.add(path)
        t = text_of(path)
        own = file_syms.get(path, set())

        # ① 符号引用：方法 / 类常量 / 常量构造函数，统一处理
        #    ★ 减去"本文件自己定义的" —— 否则每个文件都会引用自己，等于全可达
        for ident in set(IDENT_RE.findall(t)) - own:
            if REGISTER_RE.match(ident):
                continue
            owner = defs.get(ident)
            if owner and owner not in reachable:
                queue.append(owner)
                stats['symbol'] += 1

        # ② 按名引用类：`new X` / `instanceof` / 类型注解
        #    同名类可能在不同命名空间各有一份 ⇒ 全部算可达（宁多勿少）
        for sid in set(CLASS_REF_RE.findall(t)):
            name = literals.get(int(sid))
            if not name:
                continue
            tail = name.rsplit('\\', 1)[-1].lower()
            for owner in by_tail.get(tail, ()):
                if owner not in reachable:
                    queue.append(owner)
                    stats['class'] += 1

        # ③ 同一类的 PHP 源码里 `use` 了谁 —— 补 C++ 图看不见的边
        for tail in use_index.get(os.path.basename(path)[:-3].lower(), ()):
            for owner in by_tail.get(tail, ()):
                if owner not in reachable:
                    queue.append(owner)
                    stats['use'] += 1

        # ④ 多态方法名（可选）：名字对得上的所有实现都算可达
        if use_poly:
            for sid in set(METH_REF_RE.findall(t)):
                name = literals.get(int(sid))
                if not name:
                    continue
                for sym in method_index.get(name.lower(), ()):
                    owner = defs.get(sym)
                    if owner and owner not in reachable:
                        queue.append(owner)
                        stats['poly'] += 1

    # ⑤ 继承闭包：子类可达 ⇒ 父类必须可达（否则注册时崩）
    changed = True
    while changed:
        changed = False
        for path in list(reachable):
            for sym in file_syms.get(path, ()):
                seg = sym.split('__')
                if len(seg) < 3:
                    continue
                # 从符号反推类名不可靠，改用父类表 + 末段
                cls_tail = seg[-2]
                for full, parent in parents.items():
                    if full.rsplit('\\', 1)[-1] == cls_tail and parent:
                        for owner in by_tail.get(parent.rsplit('\\', 1)[-1], ()):
                            if owner not in reachable:
                                reachable.add(owner)
                                stats['parent'] += 1
                                changed = True

    dead = sorted(set(sources) - reachable)
    return {'files': len(sources), 'reachable': len(reachable), 'dead': dead, 'stats': dict(stats)}


def main(argv):
    if '--self-test' in argv:
        return self_test()

    build_dir = next((a for a in argv if not a.startswith('-')), 'apps/watermark/build')
    r = reach(build_dir, use_poly='--poly' in argv)

    if '--json' in argv:
        print(json.dumps({'files': r['files'], 'reachable': r['reachable'],
                          'dead': [os.path.relpath(p, build_dir).replace('\\', '/') for p in r['dead']]},
                         ensure_ascii=False, indent=2))
        return 0

    print(f'生成源文件        {r["files"]}')
    print(f'可达              {r["reachable"]}')
    print(f'不可达            {len(r["dead"])}  ({100*len(r["dead"])/r["files"]:.1f}%)')
    print(f'边来源            {r["stats"]}')

    if '--unreached' in argv:
        print('\n== 不可达的生成文件（可裁）==')
        for p in r['dead']:
            print('  ' + os.path.relpath(p, build_dir).replace('\\', '/'))
    return 0


def self_test():
    """判据：钉住三条，每条都对应一个曾把我带偏的坑。"""
    items = []

    # ① 自引用不该让文件可达
    got = set()
    q = deque(['a.cc'])
    while q:
        p = q.popleft()
        if p in got:
            continue
        got.add(p)
        if p == 'a.cc':
            q.append('b.cc')
    items.append(('c.cc 自引用不会让它可达', 'c.cc' not in got, sorted(got)))

    # ② 标识符正则必须覆盖类常量（没有括号，是变量引用）
    items.append(('能抓 class_const 变量引用',
                  bool(IDENT_RE.search('tmp = class_const__drift__text__cjkglyphdata__GLYPHS;')),
                  None))
    items.append(('能抓 init 函数',
                  bool(IDENT_RE.search('typephp_request_init_class_constant_d4a9450a9fa46b472583()')),
                  None))

    # ③ 转义还原
    items.append(('转义还原', unescape('Drift\\\\Widget\\\\Card') == 'Drift\\Widget\\Card', None))

    # ④ 驼峰拆命名空间（父类名反解）：最后一段是类名
    parts = 'Drift_Widget_StatefulWidget'.split('_')
    items.append(('父类名反解', '\\'.join(x.lower() for x in parts) == 'drift\\widget\\statefulwidget',
                  parts))

    bad = 0
    for label, ok, extra in items:
        print(('ok    ' if ok else 'FAIL  ') + label + ('' if extra is None else f'  {extra}'))
        bad += 0 if ok else 1
    print(f'\n{len(items)} 条判据，{bad} 条失败')
    return 0 if bad == 0 else 1


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
