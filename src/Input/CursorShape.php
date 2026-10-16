<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 鼠标指针形状（§9.1 / §A.3.2）。
 *
 * 由控件**声明**（`Widget::cursor()`），命中链从命中的盒向上取**最近**的声明者
 * ⇒ 交给 `WindowHost::setCursor()`。这是 §9.1「一次命中，三方消费」的**第 ③ 方**
 * （另两方：事件路由 / 语义）。
 *
 * ★ **不给控件开自绘光标的口子**（§9.1 裁决）：自绘光标要自己画、自己跟帧，
 *   为了一个装饰把整条渲染路径拉进输入态，得不偿失。所以这里只有"选一个系统
 *   光标"这一种表达手段。
 *
 * ★★ **`value` 就是桥的 ABI 编号** —— `drift_win_set_cursor(int $hwnd, int $shape)`
 *   收的就是它。名字→`IDC_*` 的映射只该有一处（壳里）。**改这里的 value 等于
 *   改 ABI**，必须与 `cpp-src/drift_win32.cc` 的 switch 同步。
 *
 * ★ **`Default` 不是"未声明"**：`null`（`RenderObject::cursor()`）才是"没声明"，
 *   解析结果是"沿链一路没人声明"时**返回** `Default`。两者的区别要紧：
 *   `Default` 是**一个明确的决定**（"这里就该是箭头"），会**挡住**祖先的声明；
 *   `null` 是"我不管，问上面"。
 *
 * ★ case 名 PascalCase（§15.4 的枚举命名统一：`Overflow::Clip` ·
 *   `TextRole::Title` · `LoadStatus::Ready` 同形）。
 *
 * @api
 */
enum CursorShape: int
{
    /** 标准箭头 —— 同时是"整条链都没声明"时的解析结果 */
    case Default = 0;

    /** 文本 I 形（`TextField` / 可选中文本） */
    case IBeam = 1;

    /** 手形（可点） */
    case PointingHand = 2;

    /** 四向移动（拖动把手 / 面板分隔条） */
    case SizeAll = 3;

    /** 上下缩放（水平分隔条） */
    case SizeNS = 4;

    /** 左右缩放（垂直分隔条） */
    case SizeWE = 5;

    /** 忙碌（转圈）—— ★ 是**光标**转圈，不是 `Spinner` 那个控件 */
    case Wait = 6;

    /** 禁止（不可放置 / 不可操作） */
    case NotAllowed = 7;
}
