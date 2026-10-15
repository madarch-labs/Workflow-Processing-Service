<?php

declare(strict_types=1);

/**
 * Static list of CRM modules (object types) with Russian labels and the fields the mock generates.
 */
final class MockModulesList
{
    /** @var array<string, array{label: string, fields: list<string>}> */
    public const MODULES = [
        'Accounts' => ['label' => 'Контрагенты', 'fields' => ['name', 'status', 'phone', 'email', 'account_id']],
        'Contacts' => ['label' => 'Контакты', 'fields' => ['name', 'status', 'phone', 'email']],
        'Leads' => ['label' => 'Лиды', 'fields' => ['name', 'status', 'phone', 'email']],
        'Deals' => ['label' => 'Сделки', 'fields' => ['name', 'status', 'amount', 'phone', 'email']],
        'Invoices' => ['label' => 'Счета', 'fields' => ['name', 'status', 'amount']],
        'Products' => ['label' => 'Товары', 'fields' => ['name', 'status']],
        'Orders' => ['label' => 'Заказы', 'fields' => ['name', 'status', 'amount']],
        'Payments' => ['label' => 'Платежи', 'fields' => ['name', 'status', 'amount']],
        'Tasks' => ['label' => 'Задачи', 'fields' => ['name', 'status']],
        'Calls' => ['label' => 'Звонки', 'fields' => ['name', 'status', 'phone']],
        'Meetings' => ['label' => 'Встречи', 'fields' => ['name', 'status']],
        'Emails' => ['label' => 'Письма', 'fields' => ['name', 'status', 'email']],
        'Projects' => ['label' => 'Проекты', 'fields' => ['name', 'status']],
        'Contracts' => ['label' => 'Договоры', 'fields' => ['name', 'status', 'amount']],
        'Tickets' => ['label' => 'Обращения', 'fields' => ['name', 'status', 'email']],
    ];

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function dictionary(): array
    {
        $items = [];
        foreach (self::MODULES as $value => $module) {
            $items[] = ['value' => $value, 'label' => $module['label']];
        }
        return $items;
    }

    public static function exists(string $module): bool
    {
        return isset(self::MODULES[$module]);
    }
}
