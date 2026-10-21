<?php

function ecPermissionGroups(): array
{
    $rows = getDBConnection()->query(
        'SELECT id,permission_key,label,menu_group,description,sort_order
         FROM permissions
         WHERE is_active=1
         ORDER BY sort_order,label',
    )->fetch_all(MYSQLI_ASSOC);
    $groups = [];
    foreach ($rows as $row) {
        $groups[$row['menu_group']][] = $row;
    }

    return $groups;
}

function ecDefaultPermissionKeys(string $role): array
{
    $payments = [
        'dashboard.view',
        'payments.view',
        'failed_payments.view',
        'lots.manage',
        'telecom.verify',
        'bulk_search.use',
        'chat.use',
    ];
    $accountability = [
        'accountability.district',
        'accountability.quarterly_office',
        'accountability.travel',
        'accountability.individual',
        'accountability.reports',
        'offices.view',
    ];

    return match ($role) {
        'admin' => array_merge($payments, $accountability, [
            'offices.manage',
            'telecom_access.manage',
            'payments.upload',
            'users.manage',
        ]),
        'accountability_admin' => array_merge($accountability, ['offices.manage']),
        'accountability_officer' => $accountability,
        'district_user' => array_merge($payments, [
            'accountability.district',
            'accountability.quarterly_office',
            'offices.view',
        ]),
        default => [],
    };
}

function ecNormalizePermissionKeys(array $keys): array
{
    $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));
    if (!$keys) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $statement = getDBConnection()->prepare(
        "SELECT permission_key FROM permissions WHERE is_active=1 AND permission_key IN ({$placeholders})",
    );
    $types = str_repeat('s', count($keys));
    $statement->bind_param($types, ...$keys);
    $statement->execute();

    return array_column($statement->get_result()->fetch_all(MYSQLI_ASSOC), 'permission_key');
}

function ecSaveUserPermissions(int $userId, array $keys, int $grantedBy): void
{
    if ($userId <= 0) {
        throw new DomainException('Choose a valid user.');
    }
    $keys = ecNormalizePermissionKeys($keys);
    if ($userId === $grantedBy && !in_array('users.manage', $keys, true)) {
        throw new DomainException('You cannot remove your own User Management permission.');
    }

    $database = getDBConnection();
    $database->begin_transaction();
    try {
        $delete = $database->prepare('DELETE FROM user_permissions WHERE user_id=?');
        $delete->bind_param('i', $userId);
        $delete->execute();
        if ($keys) {
            $insert = $database->prepare(
                'INSERT INTO user_permissions(user_id,permission_id,granted_by)
                 SELECT ?,id,? FROM permissions WHERE permission_key=? AND is_active=1',
            );
            foreach ($keys as $key) {
                $insert->bind_param('iis', $userId, $grantedBy, $key);
                $insert->execute();
            }
        }
        $database->commit();
    } catch (Throwable $exception) {
        $database->rollback();
        throw $exception;
    }
}

function ecPermissionKeysForUser(int $userId): array
{
    $statement = getDBConnection()->prepare(
        'SELECT p.permission_key
         FROM user_permissions up
         JOIN permissions p ON p.id=up.permission_id
         WHERE up.user_id=? AND p.is_active=1
         ORDER BY p.sort_order',
    );
    $statement->bind_param('i', $userId);
    $statement->execute();

    return array_column($statement->get_result()->fetch_all(MYSQLI_ASSOC), 'permission_key');
}
