<?php
namespace App\Libraries;

final class BulkSelection
{
    public static function ids(mixed $values): array
    {
        if (! is_array($values) || count($values) < 1 || count($values) > 100) {
            throw new \DomainException('Select between 1 and 100 items.');
        }
        $ids = [];
        foreach ($values as $value) {
            if (! is_scalar($value) || ! ctype_digit((string) $value) || (int) $value < 1) {
                throw new \DomainException('Invalid selection.');
            }
            $ids[] = (int) $value;
        }
        return array_values(array_unique($ids));
    }
}
