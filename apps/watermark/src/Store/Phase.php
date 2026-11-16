<?php
declare(strict_types=1);

namespace App\Watermark\Store;

/**
 * 流水线现在做到哪一步 —— 界面的「在忙什么」只有这一个来源。
 *
 * ## 为什么是一个枚举而不是五个 `isRunning()`
 *
 * 迁移前五个 `AsyncCommand` 各有一个 `isRunning()`，界面拿它们拼「在忙」：
 * `canUpload()` 看 upload 命令、`canSubmit()` 看 task 命令。问题是**它们互不排斥** ——
 * 轮询中 `taskCommand` 并不在跑，于是「提交任务」按钮是活的，点一下会起第二条轮询，
 * 两条 `absorbPoll` 互相覆盖 `lastResult`。
 *
 * 「在忙」本来就是**一个**状态，所以它就该是一个值。
 *
 * ★ `Signing` 单独一档（而不是并进 `Uploading`）：取凭证时还没有进度可画，
 *   界面要给的是"正在获取上传凭证…"而不是一条 0% 的进度条。
 *
 * @internal
 */
enum Phase
{
    /** 没在跑（可以点任何按钮） */
    case Idle;

    /** 正在取上传凭证 */
    case Signing;

    /** 正在上传文件（有进度） */
    case Uploading;

    /** 正在建任务 */
    case Submitting;

    /** 正在轮询结果（结果图下载由 Image 控件自治，不占流水线阶段） */
    case Polling;
}
