<?php
declare(strict_types=1);

namespace DN;

use DN\Model\User;

/**
 * Roles : superadmin > admin > writer.
 *  - superadmin : everything, incl. settings, themes, user roles, deleting other admins
 *  - admin      : write + publish + delete own or others' posts, manage categories
 *  - writer     : write drafts (edit/delete own posts only; submitting for publish is a flag they cannot set)
 */
final class Acl
{
    public const SUPERADMIN = 'superadmin';
    public const ADMIN      = 'admin';
    public const WRITER     = 'writer';

    public static function isValidRole(string $role): bool
    {
        return in_array($role, [self::SUPERADMIN, self::ADMIN, self::WRITER], true);
    }

    public static function canPublish(?User $u): bool
    {
        return $u !== null && in_array($u->role, [self::SUPERADMIN, self::ADMIN], true);
    }

    /** Can this user manage categories? */
    public static function canManageCategories(?User $u): bool
    {
        return $u !== null && in_array($u->role, [self::SUPERADMIN, self::ADMIN], true);
    }

    /** Site settings + theme + user roles: superadmin only. */
    public static function canAdminister(?User $u): bool
    {
        return $u !== null && $u->role === self::SUPERADMIN;
    }

    /**
     * Post-level ACL.
     * - edit: admins can edit anything; writers only their own posts.
     * - delete: admins anything except deleting another superadmin's post (only superadmin can);
     *   writers can delete their own posts (drafts or not — it's their content).
     * - set published status: admins+ only (enforced in the save route).
     */
    public static function canEditPost(?User $u, int $authorId, string $authorRole = ''): bool
    {
        if ($u === null) {
            return false;
        }
        if ($u->role === self::SUPERADMIN || $u->role === self::ADMIN) {
            return true; // admins edit everything
        }
        return $u->id === $authorId; // writer : own posts only
    }

    public static function canDeletePost(?User $u, ?string $authorRole = null, ?int $authorId = null): bool
    {
        if ($u === null) {
            return false;
        }
        if ($u->role === self::SUPERADMIN) {
            return true;
        }
        if ($u->role === self::ADMIN) {
            // an admin may delete anything except another superadmin's post
            return $authorRole !== self::SUPERADMIN;
        }
        return $authorId !== null && $u->id === $authorId; // writer : own only
    }

    /** User management: superadmin only. */
    public static function canManageUsers(?User $u): bool
    {
        return self::canAdminister($u);
    }
}
