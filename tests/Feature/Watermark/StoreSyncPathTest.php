<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Watermark;

use App\Watermark\Api\Config;
use App\Watermark\Store\StatusMessage;
use App\Watermark\Store\WatermarkStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `WatermarkStore` 的**同步那一半**（`$host === null`）。
 *
 * ## 为什么这条测试必须存在
 *
 * `StoreHostChainTest` 只覆盖了 `$host !== null`（真机唯一走的那条）。同步那一半
 * 从 2026-11-02 起就**没有任何判据落在上面** —— 正是本仓那句老话的现场：
 * **未被消费的分支 = 未实现**。那一半当时是五份手写副本（`ApiClient` 第二遍），
 * 2026-11-03 改成 `JobRunner::exec(null, $job)`（跑**任务本体**）之后，
 * 它第一次有了"跑的是哪个 Job"这个可判的事，也就第一次值得测。
 *
 * ## 判据
 *
 * | 用例 | 钉住的不变式 |
 * |---|---|
 * | ① 连不上时**不抛** | 同步路径的失败必须落成 `rejected`（`JobRunner` 的契约），否则异常会射穿事件循环 |
 * | ② 失败**落到界面上** | 用户点"上传"后必须看到"获取上传凭证失败"，而不是永远停在"正在获取上传凭证…" |
 * | ③ 失败后**状态干净** | 不许留着 `isUploading() === true` / 半个 `uploadedKeys` —— 那会让重试按钮变成死键 |
 *
 * ★ 地址用 `127.0.0.1:1`（**立刻** ECONNREFUSED）而不是 `gw.invalid`：
 *   后者要走一次 DNS，测试不该依赖网络栈的失败有多快。
 * ★ 这里**故意**不判"用的是 `FetchSignJob` 还是复制品" —— 两者的症状一模一样。
 *   那条判据在 `tests/Unit/App/JobRunnerTest.php`（判的是提交/执行的**实例身份**），
 *   此处只判"这条分支真的被走过且失败有去处"。
 */
final class StoreSyncPathTest extends TestCase
{
    /** @var list<string> 临时文件，测完清掉 */
    private array $tmp = [];

    protected function setUp(): void
    {
        // ★ 这条测的是"连不上时失败有去处"，必须走**真实**客户端（mock 永远成功）。
        //   翻回 `true` 在 tearDown 里，避免影响同进程的其它用例。
        Config::$mock = false;
    }

    protected function tearDown(): void
    {
        Config::$mock = true;
        foreach ($this->tmp as $path) {
            @\unlink($path);
        }
        $this->tmp = [];
    }

    #[Test]
    public function 同步路径取凭证失败时落成错误消息而不是抛异常(): void
    {
        $store = $this->store();

        // ★ 这一行**不许抛**（`JobRunner` 把 `run()` 的异常接住了）。
        $store->upload();

        $message = $store->uploadMessage();
        self::assertSame(StatusMessage::ERROR, $message->kind, '失败没有落到界面上 ⇒ 用户会永远看到"正在获取上传凭证…"');
        self::assertStringContainsString('获取上传凭证失败', $message->text);

        self::assertFalse($store->isUploading(), '失败后仍处于"上传中"⇒ 重试按钮变成死键');
        self::assertSame([], $store->uploadedKeys(), '失败不该留下半截 keys');
        self::assertFalse($store->canSubmit(), '没上传成功却允许提交 ⇒ 后端会收到空 keys');
    }

    /** 造一个"没有后台、地址必然连不上"的 store（同步路径的真实形状） */
    private function store(): WatermarkStore
    {
        $file = (string) \tempnam(\sys_get_temp_dir(), 'drift-wm-');
        $path = $file . '.jpg';
        \rename($file, $path);
        $this->tmp[] = $path;

        // ★ `null` 显式传：这是"解释器 / 没装后台端口"的语义（不是"拒绝"）。
        $store = new WatermarkStore(null, 'http://127.0.0.1:1');
        self::assertSame(1, $store->addFiles([$path]), '夹具没被选上 ⇒ 后面全靠巧合');

        return $store;
    }
}
