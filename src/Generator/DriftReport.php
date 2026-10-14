<?php

declare(strict_types=1);

namespace LaraGram\Server\Generator;

use LaraGram\Server\Bot\Client;

/**
 * Shows what a Bot API update changed and what is still to be ported:
 * the difference between two spec versions, and the documented methods
 * whose handlers are not implemented yet.
 */
final class DriftReport
{
    /**
     * @param array<string, mixed>|null $previous the spec before --fetch, if any
     * @param array<string, mixed> $current
     * @return array<string, list<string>>
     */
    public function build(?array $previous, array $current): array
    {
        $report = $previous === null ? [] : $this->diff($previous, $current);

        $missing = [];
        foreach ($current['methods'] as $method) {
            $handler = MethodTableEmitter::handlerName($method['name']);
            if (!method_exists(Client::class, $handler)) {
                $missing[] = "{$method['name']} ({$handler})";
            }
        }
        $report['unimplemented_handlers'] = $missing;

        return $report;
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return array<string, list<string>>
     */
    private function diff(array $old, array $new): array
    {
        $oldMethods = array_column($old['methods'], null, 'name');
        $newMethods = array_column($new['methods'], null, 'name');

        $changedMethods = [];
        foreach (array_intersect_key($newMethods, $oldMethods) as $name => $method) {
            $changes = $this->fieldChanges($oldMethods[$name]['parameters'] ?? [], $method['parameters'] ?? []);
            if ($changes !== '') {
                $changedMethods[] = "{$name}: {$changes}";
            }
        }

        $changedTypes = [];
        foreach (array_intersect_key($new['types'], $old['types']) as $name => $type) {
            $changes = $this->fieldChanges($old['types'][$name]['fields'] ?? [], $type['fields'] ?? []);
            if ($changes !== '') {
                $changedTypes[] = "{$name}: {$changes}";
            }
        }

        return [
            'added_methods' => array_keys(array_diff_key($newMethods, $oldMethods)),
            'removed_methods' => array_keys(array_diff_key($oldMethods, $newMethods)),
            'changed_methods' => $changedMethods,
            'added_types' => array_keys(array_diff_key($new['types'], $old['types'])),
            'removed_types' => array_keys(array_diff_key($old['types'], $new['types'])),
            'changed_types' => $changedTypes,
        ];
    }

    /**
     * @param list<array<string, mixed>> $old
     * @param list<array<string, mixed>> $new
     */
    private function fieldChanges(array $old, array $new): string
    {
        $old = array_column($old, null, 'name');
        $new = array_column($new, null, 'name');

        $parts = [];
        foreach (array_diff_key($new, $old) as $name => $_) {
            $parts[] = "+{$name}";
        }
        foreach (array_diff_key($old, $new) as $name => $_) {
            $parts[] = "-{$name}";
        }
        foreach (array_intersect_key($new, $old) as $name => $field) {
            if (($field['type'] ?? null) !== ($old[$name]['type'] ?? null)
                || ($field['required'] ?? null) !== ($old[$name]['required'] ?? null)) {
                $parts[] = "~{$name}";
            }
        }

        return implode(', ', $parts);
    }
}
