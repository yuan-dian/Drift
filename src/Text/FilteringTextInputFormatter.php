<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 删除型过滤器：把匹配 `$pattern` 的字符从整串里去掉。
 *
 * ```php
 * TextField::make()->inputFormatters(new FilteringTextInputFormatter('/\s+/u'));
 * ```
 *
 * @api
 */
final class FilteringTextInputFormatter implements TextInputFormatter
{
    public function __construct(private readonly string $pattern)
    {
    }

    public function format(TextEdit $edit): TextEdit
    {
        return $edit->without($this->pattern);
    }
}
