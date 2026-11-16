<?php
declare(strict_types=1);

namespace App\Watermark\Api;

use Drift\App\Wire;

/**
 * OSS 直传签名（`/storage/upload/sign` 的 `data`）。
 *
 * ★ 六个字段全是**字符串**（服务端可能把数字给成字符串），所以这里不做类型转换
 *   —— 转换在用到的地方做，且只做一次（`expireTime` → `int`）。
 * ★ `expireTime` 的语义**不明确**：原文只写了 `Date.now() > +signInfo.expireTime`，
 *   说明它至少是"毫秒时间戳"。可能是 0（没给）、也可能是秒 —— 两种都出现过。
 *   处理办法见 {@see self::isExpired()}：**只在它明显是毫秒时间戳时**才判过期，
 *   否则一律当"还有效"（宁可多签一次，也不要因为判错而永远签不出新签名）。
 */
final class SignInfo
{
    use Wire;

    /** 键名恒等于属性名（顺序 == 构造器参数顺序） */
    private const FIELDS = [
        'account',
        'policy',
        'signature',
        'directoryPath',
        'accessAddress',
        'expireTime',
    ];

    public function __construct(
        public readonly string $account,
        public readonly string $policy,
        public readonly string $signature,
        public readonly string $directoryPath,
        public readonly string $accessAddress,
        /** 原始字符串（可能是毫秒时间戳、可能是空串） */
        public readonly string $expireTime,
    ) {
    }

    /**
     * 从接口返回的 `data` 造一个；**字段缺失 ⇒ `null`**（不是造个半残的）。
     *
     * ★ 缺字段就当"没拿到签名"，让调用方重新签 / 报错。造一个 `policy` 为空的
     *   签名出来，OSS 会回 400，而错误信息里只有"签名不匹配" —— 归因跑偏。
     *
     * @param mixed $data 服务端给的 `data`（形状未知，必须自己验）
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data)) {
            return null;
        }

        $account = self::wireString($data, 'account');
        $policy = self::wireString($data, 'policy');
        $signature = self::wireString($data, 'signature');
        $directory = self::wireString($data, 'directoryPath');
        $address = self::wireString($data, 'accessAddress');

        // ★ 五个字段缺一不可：`accessAddress` 是上传的**目的地**，`directoryPath`
        //   是对象键的前缀，`account/policy/signature` 是凭证。
        if ($account === '' || $policy === '' || $signature === '' || $directory === '' || $address === '') {
            return null;
        }

        return new self($account, $policy, $signature, $directory, $address, self::wireString($data, 'expireTime'));
    }

    /**
     * 是否该重新签一次。
     *
     * ★★ 判据故意**宽松**：只有 `expireTime` 看起来像毫秒时间戳（> 1e12）时才比较，
     *   且留 {@see Config::SIGN_REFRESH_MARGIN_MS} 的余量（大文件传到一半过期，
     *   失败点会在 OSS 侧，错误信息与本应用无关）。
     * ★ 秒级时间戳（~1.7e9）**不参与判断** —— 拿秒当毫秒比会得出"1970 年就过期了"，
     *   于是每次上传都重签，每次重签都拿到同一个秒级时间戳 ⇒ 无限循环。
     *
     * @param int $nowMs 当前毫秒时间戳（由调用方注入 —— 壳的时间与测试的时间要能分开）
     */
    public function isExpired(int $nowMs): bool
    {
        $raw = \trim($this->expireTime);
        if ($raw === '' || !\ctype_digit($raw)) {
            return false;   // 没给 / 不是数字 ⇒ 不判过期
        }

        $value = (int) $raw;
        if ($value < 1_000_000_000_000) {
            return false;   // 小于 1e12 ⇒ 秒级或更小，不参与判断（见类注释）
        }

        return $nowMs + Config::SIGN_REFRESH_MARGIN_MS >= $value;
    }

    /** 对象键：`directoryPath` + `<毫秒>_<随机>.<ext>`（原文的形状） */
    public function objectKey(string $extension, int $nowMs, string $random): string
    {
        // ★ 目录前缀**原样拼**：服务端给的是 `xxx/yyy/`，尾斜杠归它管。
        //   这里补一个 `/` 只在它确实缺的时候（防御：某次服务端少给一个斜杠，
        //   拼出来的键会变成 `dir1234_abc.png` —— 能传上去，但找不回来）。
        //   ★ 用 `substr($prefix, -1)` 而不是 `str_ends_with()`：后者是 8.0 才有的
        //   函数，AOT 下能不能用没验过 —— 这条路径每上传一个文件走一次，
        //   不值得为一个可读性提升去赌。
        $prefix = $this->directoryPath;
        if ($prefix !== '' && \substr($prefix, -1) !== '/') {
            $prefix .= '/';
        }

        return $prefix . $nowMs . '_' . $random . '.' . $extension;
    }
}
