<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Watermark;

use App\Watermark\Api\Config;
use App\Watermark\Api\SignInfo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `SignInfo`：OSS 直传签名的**解析**与**过期判据**（`apps/watermark`）。
 *
 * ## 为什么判据落在这两个纯函数上
 *
 * `ApiClient::sign()` 要打网络，`UploadBatchJob` 要跨进程 —— 两层都没法在单测里
 * 断言。而整条签名链上唯一有分支的就是这里：
 *   ① 五字段缺一不可（缺了要 `null`，不能造半残签名）；
 *   ② `expireTime` 的**秒 / 毫秒判别**。
 *
 * ## ② 是本文件真正在钉的东西
 *
 * 服务端两种都出现过。若把秒级时间戳（~1.7e9）当毫秒比，结论是"1970 年就过期"
 * ⇒ 每次上传都重签，每次重签拿到同一个秒级时间戳 ⇒ **无限循环**（不报错、只是
 * 永远传不完）。所以 `isExpired()` 故意宽松：**只在 > 1e12 时才判**。
 * 变异 `$value < 1_000_000_000_000 ⇒ false` 改成 `< 0` 会让
 * `testSecondPrecisionTimestampIsNeverExpired` 当场红。
 *
 * ## 60 秒余量
 *
 * 大文件传到一半签名过期，失败点在 OSS 侧、错误信息与本应用无关 ⇒ 提前 60 秒重签。
 * 边界两侧都断言（`value - margin` 算过期、`value - margin - 1` 不算），
 * 否则"余量"这个数被改小/改大都不会被发现。
 */
final class SignInfoTest extends TestCase
{
    /** 一组完整的签名数据（`expireTime` 单测各自覆盖） */
    private static function data(array $overrides = []): array
    {
        return \array_merge([
            'account' => 'acc',
            'policy' => 'pol',
            'signature' => 'sig',
            'directoryPath' => 'watermark/2026/',
            'accessAddress' => 'https://oss.example.com',
            'expireTime' => '9999999999999',
        ], $overrides);
    }

    #[Test]
    public function testAnyMissingCredentialFieldMeansNoSign(): void
    {
        // ★ 五个字段**各缺一次** —— 只测其中一个的话，另外四个的守卫静默失效也发现不了
        foreach (['account', 'policy', 'signature', 'directoryPath', 'accessAddress'] as $field) {
            $data = self::data();
            $data[$field] = '';
            $this->assertNull(
                SignInfo::fromArray($data),
                '缺 ' . $field . ' 时必须返回 null（造半残签名 ⇒ OSS 回 400「签名不匹配」）'
            );
        }

        // 反例的对照：字段齐了就必须能造出来（否则上面五条恒真）
        $this->assertNotNull(SignInfo::fromArray(self::data()));
    }

    #[Test]
    public function testNonArrayInputMeansNoSign(): void
    {
        $this->assertNull(SignInfo::fromArray(null));
        $this->assertNull(SignInfo::fromArray('acc'));
        $this->assertNull(SignInfo::fromArray(42));
    }

    #[Test]
    public function testExpireTimeIsOptional(): void
    {
        $data = self::data();
        unset($data['expireTime']);

        $sign = SignInfo::fromArray($data);

        $this->assertNotNull($sign, 'expireTime 不是凭证的一部分，缺了不该整个作废');
        $this->assertSame('', $sign->expireTime);
        $this->assertFalse($sign->isExpired(1_800_000_000_000), '没给过期时间 ⇒ 不判过期');
    }

    #[Test]
    public function testSecondPrecisionTimestampIsNeverExpired(): void
    {
        // 秒级时间戳（2026-01-01 ≈ 1.77e9）：若当成毫秒 ⇒ "1970 年就过期"
        $sign = new SignInfo('a', 'p', 's', 'd/', 'https://x', '1767225600');

        $this->assertFalse(
            $sign->isExpired(1_800_000_000_000),
            '秒级时间戳不参与判断 —— 判错会变成"每次上传都重签"的死循环'
        );
    }

    #[Test]
    public function testNonNumericExpireTimeIsNeverExpired(): void
    {
        foreach (['', '   ', 'abc', '0', '000'] as $raw) {
            $sign = new SignInfo('a', 'p', 's', 'd/', 'https://x', $raw);
            $this->assertFalse(
                $sign->isExpired(1_800_000_000_000),
                'expireTime=' . \var_export($raw, true) . ' ⇒ 不判过期'
            );
        }
    }

    #[Test]
    public function testMillisecondTimestampInFutureIsNotExpired(): void
    {
        $sign = new SignInfo('a', 'p', 's', 'd/', 'https://x', '1800000000000');

        $this->assertFalse($sign->isExpired(1_700_000_000_000));
    }

    #[Test]
    public function testMarginBoundaryBothSides(): void
    {
        $expire = 1_800_000_000_000;
        $sign = new SignInfo('a', 'p', 's', 'd/', 'https://x', (string) $expire);
        $margin = Config::SIGN_REFRESH_MARGIN_MS;

        // 边界内侧（剩余正好 = 余量）⇒ 算过期
        $this->assertTrue(
            $sign->isExpired($expire - $margin),
            '剩余正好等于余量 ⇒ 该重签了（大文件传到一半会过期）'
        );
        // 边界外侧（剩余 = 余量 + 1）⇒ 不算
        $this->assertFalse(
            $sign->isExpired($expire - $margin - 1),
            '多 1 毫秒就不该重签 —— 否则余量被改大也发现不了'
        );
        // 已经过去 ⇒ 过期
        $this->assertTrue($sign->isExpired($expire + 1));
    }

    #[Test]
    public function testObjectKeyKeepsServerPrefix(): void
    {
        $sign = new SignInfo('a', 'p', 's', 'watermark/2026/', 'https://x', '');

        $this->assertSame(
            'watermark/2026/1700000000000_abcdef0123.png',
            $sign->objectKey('png', 1_700_000_000_000, 'abcdef0123'),
            '前缀尾斜杠归服务端管，不能重复补'
        );
    }

    #[Test]
    public function testObjectKeyAddsMissingSlash(): void
    {
        // ★ 服务端少给一个斜杠 ⇒ 拼成 `watermark/2026123_x.png`：**能传上去但找不回来**
        $sign = new SignInfo('a', 'p', 's', 'watermark/2026', 'https://x', '');

        $this->assertSame(
            'watermark/2026/123_x.png',
            $sign->objectKey('png', 123, 'x'),
            '缺尾斜杠时必须补一个（否则对象键粘在一起，静默传错位置）'
        );
    }

    #[Test]
    public function testArrayRoundTrip(): void
    {
        // ★ 跨进程契约：`FetchSignJob` 回来的是数组，`SignInfo::fromArray()` 要能还原
        $original = SignInfo::fromArray(self::data());
        $this->assertNotNull($original);

        $restored = SignInfo::fromArray($original->toArray());

        $this->assertNotNull($restored);
        $this->assertSame($original->toArray(), $restored->toArray());
    }
}
