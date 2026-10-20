<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The fixed list of Command Centre permissions. Owners hold all of them
 * implicitly; internal staff hold an explicit subset. Each key is also a
 * Laravel Gate (see AppServiceProvider), so checks read can('dealers.manage').
 *
 * Add a permission here when its screen is built; nothing else needs to change.
 */
final class Permission
{
    public const CUSTOMERS_VIEW = 'customers.view';
    public const CUSTOMERS_ASSIST = 'customers.assist';
    public const RESERVATIONS_VIEW = 'reservations.view';
    public const DEALERS_VIEW = 'dealers.view';
    public const DEALERS_MANAGE = 'dealers.manage';
    public const DEALER_USERS_MANAGE = 'dealer_users.manage';
    public const INVENTORY_MANAGE = 'inventory.manage';
    public const STAFF_MANAGE = 'staff.manage';
    public const SETTINGS_MANAGE = 'settings.manage';
    public const AUDIT_VIEW = 'audit.view';

    /** Key → [label, what it allows]. Order is display order. */
    public const DEFINITIONS = [
        self::CUSTOMERS_VIEW      => ['Customers — view', 'Look up customer accounts and their contact details.'],
        self::CUSTOMERS_ASSIST    => ['Customers — assist', 'Limited support actions on a customer’s behalf.'],
        self::RESERVATIONS_VIEW   => ['Reservations — view', 'See reservations across dealers.'],
        self::DEALERS_VIEW        => ['Dealers — view', 'See dealer profiles and addresses.'],
        self::DEALERS_MANAGE      => ['Dealers — manage', 'Create, edit, activate and suspend dealers.'],
        self::DEALER_USERS_MANAGE => ['Dealer users — manage', 'Invite, suspend and reinstate dealer staff.'],
        self::INVENTORY_MANAGE    => ['Inventory — manage', 'Import dealer feeds and publish or unpublish vehicles.'],
        self::STAFF_MANAGE        => ['TruCars staff — manage', 'Invite internal staff and change their access.'],
        self::SETTINGS_MANAGE     => ['Platform settings', 'Change platform-wide settings, including brands and their CPO checklists and offers.'],
        self::AUDIT_VIEW          => ['Audit log — view', 'Read the record of sensitive actions.'],
    ];

    /** Holding the key on the left implies holding those on the right. */
    private const IMPLIES = [
        self::CUSTOMERS_ASSIST => [self::CUSTOMERS_VIEW],
        self::DEALERS_MANAGE   => [self::DEALERS_VIEW],
        self::DEALER_USERS_MANAGE => [self::DEALERS_VIEW],
        self::INVENTORY_MANAGE    => [self::DEALERS_VIEW],
    ];

    /**
     * Starting points when inviting staff. Customer support gets what it needs
     * to help a customer — nothing that touches dealers, staff, security or
     * platform settings.
     */
    public const PRESETS = [
        'customer_support' => [
            'label'       => 'Customer support',
            'permissions' => [self::CUSTOMERS_VIEW, self::CUSTOMERS_ASSIST, self::RESERVATIONS_VIEW],
        ],
        'operations' => [
            'label'       => 'Operations',
            'permissions' => [self::DEALERS_VIEW, self::RESERVATIONS_VIEW],
        ],
    ];

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /**
     * Only known keys, implied ones added, de-duplicated, in display order.
     *
     * @param  array<int, mixed>  $permissions
     * @return array<int, string>
     */
    public static function normalize(array $permissions): array
    {
        $held = array_values(array_intersect(self::all(), array_filter($permissions, 'is_string')));

        foreach ($held as $permission) {
            $held = [...$held, ...(self::IMPLIES[$permission] ?? [])];
        }

        return array_values(array_intersect(self::all(), array_unique($held)));
    }
}
