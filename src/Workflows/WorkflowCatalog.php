<?php

declare(strict_types=1);

namespace JetWP\Control\Workflows;

final class WorkflowCatalog
{
    /**
     * @return array<string, array{label:string,category:string,kind:string,default_label:string,config_schema:array<string, mixed>}>
     */
    public static function nodeTypes(): array
    {
        return [
            'start' => [
                'label' => 'Start',
                'category' => 'control',
                'kind' => 'start',
                'default_label' => 'Start',
                'config_schema' => [],
            ],
            'end' => [
                'label' => 'End',
                'category' => 'control',
                'kind' => 'end',
                'default_label' => 'End',
                'config_schema' => [],
            ],
            'cache.flush' => [
                'label' => 'Cache Flush',
                'category' => 'maintenance',
                'kind' => 'action',
                'default_label' => 'Flush Cache',
                'config_schema' => [],
            ],
            'db.optimize' => [
                'label' => 'DB Optimize',
                'category' => 'maintenance',
                'kind' => 'action',
                'default_label' => 'Optimize DB',
                'config_schema' => [],
            ],
            'translations.update' => [
                'label' => 'Translations Update',
                'category' => 'maintenance',
                'kind' => 'action',
                'default_label' => 'Update Translations',
                'config_schema' => [],
            ],
            'security.integrity' => [
                'label' => 'Integrity Check',
                'category' => 'maintenance',
                'kind' => 'action',
                'default_label' => 'Verify Integrity',
                'config_schema' => [],
            ],
            'plugin.update.selected' => [
                'label' => 'Update Selected Plugins',
                'category' => 'plugins',
                'kind' => 'action',
                'default_label' => 'Update Selected Plugins',
                'config_schema' => ['plugins' => 'list<string>'],
            ],
            'plugin.update.all_with_updates' => [
                'label' => 'Update All Plugins With Updates',
                'category' => 'plugins',
                'kind' => 'action',
                'default_label' => 'Update Plugins With Updates',
                'config_schema' => [],
            ],
            'plugin.update.all_except' => [
                'label' => 'Update Plugins Except',
                'category' => 'plugins',
                'kind' => 'action',
                'default_label' => 'Update Plugins Except',
                'config_schema' => ['exclude_plugins' => 'list<string>'],
            ],
            'theme.update.selected' => [
                'label' => 'Update Selected Themes',
                'category' => 'themes',
                'kind' => 'action',
                'default_label' => 'Update Selected Themes',
                'config_schema' => ['themes' => 'list<string>'],
            ],
            'theme.update.all_with_updates' => [
                'label' => 'Update All Themes With Updates',
                'category' => 'themes',
                'kind' => 'action',
                'default_label' => 'Update Themes With Updates',
                'config_schema' => [],
            ],
            'theme.update.all_except' => [
                'label' => 'Update Themes Except',
                'category' => 'themes',
                'kind' => 'action',
                'default_label' => 'Update Themes Except',
                'config_schema' => ['exclude_themes' => 'list<string>'],
            ],
            'core.update' => [
                'label' => 'Core Update',
                'category' => 'core',
                'kind' => 'action',
                'default_label' => 'Update Core',
                'config_schema' => ['version' => 'string?'],
            ],
            'condition.previous_step_succeeded' => [
                'label' => 'Previous Step Succeeded',
                'category' => 'conditions',
                'kind' => 'condition',
                'default_label' => 'If Previous Step Succeeded',
                'config_schema' => [],
            ],
            'condition.plugin_updates_available' => [
                'label' => 'Plugin Updates Available',
                'category' => 'conditions',
                'kind' => 'condition',
                'default_label' => 'If Plugin Updates Available',
                'config_schema' => [
                    'mode' => 'enum:selected|all_with_updates|all_except',
                    'plugins' => 'list<string>',
                    'exclude_plugins' => 'list<string>',
                ],
            ],
            'condition.theme_updates_available' => [
                'label' => 'Theme Updates Available',
                'category' => 'conditions',
                'kind' => 'condition',
                'default_label' => 'If Theme Updates Available',
                'config_schema' => [
                    'mode' => 'enum:selected|all_with_updates|all_except',
                    'themes' => 'list<string>',
                    'exclude_themes' => 'list<string>',
                ],
            ],
            'condition.core_update_available' => [
                'label' => 'Core Update Available',
                'category' => 'conditions',
                'kind' => 'condition',
                'default_label' => 'If Core Update Available',
                'config_schema' => [],
            ],
        ];
    }

    public static function hasNodeType(string $type): bool
    {
        return array_key_exists($type, self::nodeTypes());
    }

    public static function definitionFor(string $type): ?array
    {
        return self::nodeTypes()[$type] ?? null;
    }

    public static function kindFor(string $type): ?string
    {
        return self::nodeTypes()[$type]['kind'] ?? null;
    }
}
