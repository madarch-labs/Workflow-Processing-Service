<?php

declare(strict_types=1);

namespace Wps\Services\Conditions;

use Wps\Types\CastOptions;
use Wps\Types\CastType;
use Wps\Types\TypeCaster;

/**
 * Right-hand side of "in" / "not_in" (md/tasks_list/done/3.4-in-not-in.md): a static array or a field with a list
 * (array, JSON array or a separated string — value_list_options). Every element is cast like the left side;
 * elements that do not cast are reported and skipped.
 */
final class ListOperand
{
    public const MAX_ITEMS = 500;

    /**
     * @param array<string, mixed> $listOptions value_list_options: separator, wrapper
     * @return array{items: list<mixed>, errors: list<array{index: int, message: string}>}
     */
    public static function resolve(TypeCaster $caster, mixed $raw, CastOptions $elementOptions, array $listOptions = []): array
    {
        if (is_array($raw)) {
            $elements = array_values($raw);
        } else {
            $parsed = $caster->cast($raw, new CastOptions(
                type: CastType::List,
                separator: (string)($listOptions['separator'] ?? ','),
                wrapper: isset($listOptions['wrapper']) ? (string)$listOptions['wrapper'] : null,
            ));
            $elements = $parsed->isOk() ? (array)$parsed->value : [];
        }
        $items = [];
        $errors = [];
        foreach ($elements as $index => $element) {
            $cast = $caster->cast($element, $elementOptions);
            if ($cast->isOk()) {
                $items[] = $cast->value;
            } elseif ($cast->isError()) {
                $errors[] = ['index' => (int)$index, 'message' => (string)$cast->error];
            } else {
                $errors[] = ['index' => (int)$index, 'message' => "Empty item is not allowed for cast \"{$elementOptions->type->value}\""];
            }
        }
        return ['items' => $items, 'errors' => $errors];
    }

    /**
     * @return list<array{field: string, message: string}> problems of "value_list_options" (field suffix)
     */
    public static function optionErrors(mixed $options): array
    {
        if (!is_array($options) || (array_is_list($options) && $options !== [])) {
            return [['field' => 'value_list_options', 'message' => 'Must be an object']];
        }
        $errors = [];
        foreach ($options as $name => $value) {
            if (!in_array($name, ['separator', 'wrapper'], true)) {
                $errors[] = ['field' => "value_list_options.{$name}", 'message' => "Unknown option \"{$name}\" (allowed: separator, wrapper)"];
            } elseif ($value !== null && (!is_string($value) || $value === '' || mb_strlen($value) > 5)) {
                $errors[] = ['field' => "value_list_options.{$name}", 'message' => 'Must be a non-empty string up to 5 characters'];
            }
        }
        return $errors;
    }
}
