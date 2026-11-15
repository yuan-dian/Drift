<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 输入格式化器：只作用在**用户输入**的边界，不碰外部受控值、撤销与重做。
 *
 * ★ 返回 `TextEdit` 而不是只返回文本：格式化可能增删字符，插入符必须一起修正，
 *   否则「姓名里打字」会跳到末尾。做插入型变换（分隔符等）的实现要自己算 caret。
 *
 * @api
 */
interface TextInputFormatter
{
    public function format(TextEdit $edit): TextEdit;
}
