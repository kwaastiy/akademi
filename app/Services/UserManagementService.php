<?php
declare(strict_types=1);

namespace Akademi\Services;

use Akademi\Database\Connection;
use RuntimeException;

final class UserManagementService
{
    public function list(array $actor, string $query = '', string $status = '', int $page = 1): array
    {
        $page = max(1, $page);
        $query = trim($query);
        $query = mb_substr($query, 0, 120, 'UTF-8');
        if ($status !== '' && !in_array($status, ['pending', 'active', 'suspended', 'deleted'], true)) {
            throw new RuntimeException('Choose a valid account status filter.', 422);
        }

        $parameters = [];
        $where = [];
        if (!in_array('superadmin', $actor['roles'], true)) {
            $where[] = "NOT EXISTS (
                SELECT 1 FROM user_roles protected_target
                JOIN roles protected_role ON protected_role.id = protected_target.role_id
                WHERE protected_target.user_id = u.id AND protected_role.slug = 'superadmin'
            )";
        }
        if ($status !== '') {
            $where[] = 'u.status = :status';
            $parameters['status'] = $status;
        } else {
            $where[] = "u.status <> 'deleted'";
        }
        if ($query !== '') {
            $where[] = '(u.display_name LIKE :search_name OR u.email LIKE :search_email)';
            $search = '%' . $query . '%';
            $parameters['search_name'] = $search;
            $parameters['search_email'] = $search;
        }
        $whereSql = implode(' AND ', $where);
        $pdo = Connection::get();
        $count = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE {$whereSql}");
        $count->execute($parameters);
        $total = (int)$count->fetchColumn();

        $statement = $pdo->prepare(
            "SELECT u.id, u.display_name, u.email, u.status, u.email_verified_at, u.created_at,
                    p.school_name, p.region,
                    GROUP_CONCAT(DISTINCT r.slug ORDER BY r.slug SEPARATOR ',') AS role_slugs
             FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE {$whereSql}
             GROUP BY u.id
             ORDER BY u.created_at DESC, u.id DESC
             LIMIT 50 OFFSET :offset"
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':offset', ($page - 1) * 50, \PDO::PARAM_INT);
        $statement->execute();
        $users = array_map(static function (array $row): array {
            $roles = $row['role_slugs'] === null ? [] : explode(',', (string)$row['role_slugs']);
            unset($row['role_slugs']);
            $row['id'] = (int)$row['id'];
            $row['roles'] = $roles;
            return $row;
        }, $statement->fetchAll());

        return [
            'users' => $users,
            'total' => $total,
            'page' => $page,
            'per_page' => 50,
            'can_manage_roles' => in_array('superadmin', $actor['roles'], true),
        ];
    }

    public function update(int $targetUserId, array $input, array $actor): array
    {
        if (array_intersect(['admin', 'superadmin'], $actor['roles']) === []) {
            throw new RuntimeException('You do not have permission to manage user accounts.', 403);
        }
        $status = $input['status'] ?? null;
        $requestedRoles = $input['roles'] ?? null;
        if ($status !== null && !in_array($status, ['active', 'suspended'], true)) {
            throw new RuntimeException('User status can only be set to active or suspended.', 422);
        }
        if ($requestedRoles !== null && !is_array($requestedRoles)) {
            throw new RuntimeException('Choose valid account roles.', 422);
        }
        $isSuperAdmin = in_array('superadmin', $actor['roles'], true);
        if (!$isSuperAdmin && $requestedRoles !== null) {
            throw new RuntimeException('Only a Super Admin can change account roles.', 403);
        }
        $roles = $requestedRoles === null ? null : array_values(array_unique($requestedRoles));
        if ($roles !== null && (!in_array('teacher', $roles, true)
            || array_diff($roles, ['teacher', 'admin']) !== [])) {
            throw new RuntimeException('Accounts may have the Teacher role, optionally with Administrator access.', 422);
        }
        if ($status === null && $roles === null) {
            throw new RuntimeException('Choose an account status or role change to save.', 422);
        }
        if ($targetUserId === (int)$actor['id']) {
            throw new RuntimeException('You cannot change your own account status or roles.', 403);
        }

        $pdo = Connection::get();
        $pdo->beginTransaction();
        try {
            $lookup = $pdo->prepare(
                'SELECT id, status, email_verified_at FROM users WHERE id = :id FOR UPDATE'
            );
            $lookup->execute(['id' => $targetUserId]);
            $target = $lookup->fetch();
            if (!$target || $target['status'] === 'deleted') {
                throw new RuntimeException('The selected account was not found or has been deleted.', 404);
            }
            if ($status !== null && $target['email_verified_at'] === null) {
                throw new RuntimeException('Only verified accounts can be enabled or disabled.', 422);
            }
            $roleLookup = $pdo->prepare(
                'SELECT r.slug FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id'
            );
            $roleLookup->execute(['id' => $targetUserId]);
            $currentRoles = $roleLookup->fetchAll(\PDO::FETCH_COLUMN);
            if (in_array('superadmin', $currentRoles, true)) {
                throw new RuntimeException('Super Admin accounts are protected from user-management changes.', 403);
            }
            if ($roles !== null && $status !== null && !in_array('teacher', $currentRoles, true)) {
                throw new RuntimeException('Only teacher accounts can be assigned Administrator access here.', 422);
            }

            if ($roles !== null) {
                $roleQuery = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug');
                $desiredRoleIds = [];
                foreach ($roles as $role) {
                    $roleQuery->execute(['slug' => $role]);
                    $roleId = $roleQuery->fetchColumn();
                    if ($roleId === false) {
                        throw new RuntimeException('A required account role is missing. Apply database migrations.');
                    }
                    $desiredRoleIds[] = (int)$roleId;
                }
                $pdo->prepare('DELETE FROM user_roles WHERE user_id = :id')->execute(['id' => $targetUserId]);
                $assign = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
                foreach ($desiredRoleIds as $roleId) {
                    $assign->execute(['user_id' => $targetUserId, 'role_id' => $roleId]);
                }
            }
            if ($status !== null) {
                $pdo->prepare('UPDATE users SET status = :status, updated_at = UTC_TIMESTAMP() WHERE id = :id')
                    ->execute(['status' => $status, 'id' => $targetUserId]);
                if ($status === 'suspended') {
                    $pdo->prepare(
                        'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP()
                         WHERE user_id = :id AND revoked_at IS NULL'
                    )->execute(['id' => $targetUserId]);
                }
            }

            $audit = $pdo->prepare(
                'INSERT INTO audit_logs (actor_user_id, action_name, target_type, target_id, metadata)
                 VALUES (:actor, :action, \'user\', :target, :metadata)'
            );
            $audit->execute([
                'actor' => (int)$actor['id'],
                'action' => $roles !== null ? 'user.roles_updated' : 'user.status_updated',
                'target' => (string)$targetUserId,
                'metadata' => json_encode([
                    'status' => $status,
                    'roles' => $roles,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return ['updated' => true, 'user_id' => $targetUserId];
    }

    public function deleteUnverified(int $targetUserId, array $actor): array
    {
        if ($targetUserId === (int)$actor['id']) {
            throw new RuntimeException('You cannot delete your own account from user management.', 403);
        }
        if (array_intersect(['admin', 'superadmin'], $actor['roles']) === []) {
            throw new RuntimeException('You do not have permission to delete user accounts.', 403);
        }

        $pdo = Connection::get();
        $pdo->beginTransaction();
        try {
            $lookup = $pdo->prepare(
                'SELECT id, status, email_verified_at FROM users WHERE id = :id FOR UPDATE'
            );
            $lookup->execute(['id' => $targetUserId]);
            $target = $lookup->fetch();
            if (!$target || $target['status'] === 'deleted') {
                throw new RuntimeException('The selected account was not found or has been deleted.', 404);
            }
            if ($target['status'] !== 'pending' || $target['email_verified_at'] !== null) {
                throw new RuntimeException('Only accounts pending email verification can be deleted here.', 422);
            }

            $roleLookup = $pdo->prepare(
                'SELECT r.slug FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id'
            );
            $roleLookup->execute(['id' => $targetUserId]);
            if (in_array('superadmin', $roleLookup->fetchAll(\PDO::FETCH_COLUMN), true)) {
                throw new RuntimeException('Super Admin accounts are protected from user-management changes.', 403);
            }

            $pdo->prepare(
                "UPDATE users
                 SET status = 'deleted', deleted_at = UTC_TIMESTAMP(),
                     display_name = 'Deleted user',
                     email = CONCAT('deleted+', id, '@deleted.invalid'),
                     password_hash = NULL, updated_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            )->execute(['id' => $targetUserId]);
            $pdo->prepare(
                'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP()
                 WHERE user_id = :id AND revoked_at IS NULL'
            )->execute(['id' => $targetUserId]);
            $pdo->prepare('DELETE FROM user_profiles WHERE user_id = :id')->execute(['id' => $targetUserId]);
            $pdo->prepare('DELETE FROM email_verification_codes WHERE user_id = :id')->execute(['id' => $targetUserId]);
            $pdo->prepare('DELETE FROM user_roles WHERE user_id = :id')->execute(['id' => $targetUserId]);
            $audit = $pdo->prepare(
                'INSERT INTO audit_logs (actor_user_id, action_name, target_type, target_id, metadata)
                 VALUES (:actor, \'user.unverified_account_deleted\', \'user\', :target, :metadata)'
            );
            $audit->execute([
                'actor' => (int)$actor['id'],
                'target' => (string)$targetUserId,
                'metadata' => json_encode(['email_verified_at' => null], JSON_THROW_ON_ERROR),
            ]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return ['deleted' => true, 'user_id' => $targetUserId];
    }

    public function resetPassword(int $targetUserId, array $actor): array
    {
        if ($targetUserId === (int)$actor['id']) {
            throw new RuntimeException('You cannot reset your own password from user management.', 403);
        }
        $isSuperAdmin = in_array('superadmin', $actor['roles'], true);
        if (!$isSuperAdmin && !in_array('admin', $actor['roles'], true)) {
            throw new RuntimeException('You do not have permission to reset user passwords.', 403);
        }

        $temporaryPassword = bin2hex(random_bytes(12));
        $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
        if (!is_string($passwordHash)) {
            throw new RuntimeException('The temporary password could not be secured. Try again.', 500);
        }

        $pdo = Connection::get();
        $pdo->beginTransaction();
        try {
            $lookup = $pdo->prepare('SELECT id, status FROM users WHERE id = :id FOR UPDATE');
            $lookup->execute(['id' => $targetUserId]);
            $target = $lookup->fetch();
            if (!$target || $target['status'] === 'deleted') {
                throw new RuntimeException('The selected account was not found or has been deleted.', 404);
            }
            $roleLookup = $pdo->prepare(
                'SELECT r.slug FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id'
            );
            $roleLookup->execute(['id' => $targetUserId]);
            $currentRoles = $roleLookup->fetchAll(\PDO::FETCH_COLUMN);
            if (in_array('superadmin', $currentRoles, true)) {
                throw new RuntimeException('Super Admin accounts are protected from user-management changes.', 403);
            }
            if (!$isSuperAdmin && (!in_array('teacher', $currentRoles, true) || in_array('admin', $currentRoles, true))) {
                throw new RuntimeException('Administrators can reset teacher passwords only.', 403);
            }

            $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute(['hash' => $passwordHash, 'id' => $targetUserId]);
            $pdo->prepare(
                'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP()
                 WHERE user_id = :id AND revoked_at IS NULL'
            )->execute(['id' => $targetUserId]);
            $audit = $pdo->prepare(
                'INSERT INTO audit_logs (actor_user_id, action_name, target_type, target_id, metadata)
                 VALUES (:actor, \'user.password_reset\', \'user\', :target, :metadata)'
            );
            $audit->execute([
                'actor' => (int)$actor['id'],
                'target' => (string)$targetUserId,
                'metadata' => json_encode(['method' => 'temporary_password'], JSON_THROW_ON_ERROR),
            ]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return ['updated' => true, 'user_id' => $targetUserId, 'temporary_password' => $temporaryPassword];
    }
}
