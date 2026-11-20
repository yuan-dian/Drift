<?php
declare(strict_types=1);

namespace App\Showcase\Routes\Snackbar;

/**
 * 这一页能发起的动作。
 *
 * ★ 为什么用"一个枚举"而不是"六个事件类"：这六件事本质是同一个形状
 *   「弹一条 X 通知」，分成六个类只是把 6 行 switch 换成 6 个文件。
 *   等某条动作带上自己的字段（例如带行号、带 payload）再拆成具体类。
 * ★ 枚举拼错是编译期/静态检查能抓的；对应的字符串常量拼错是运行时静默不响应。
 *
 * @api
 */
enum SnackbarIntent
{
    /** ① 普通通知 */
    case Saved;

    /** ① 长文案（验证面板有宽度上限） */
    case LongText;

    /** ② 带「撤销」动作 */
    case Undoable;

    /** ③ 一次推三条，验证 FIFO 排队 */
    case Burst;

    /** ④ `duration(0)`：不自动消失，必须点动作 */
    case PersistentError;

    /** 「撤销」/「知道了」被点（动作回调重新走主路） */
    case UndoClicked;
}
