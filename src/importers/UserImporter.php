<?php

namespace justinholtweb\passer\importers;

use Craft;
use craft\elements\User;
use craft\helpers\StringHelper;
use justinholtweb\passer\services\IdMap;

/**
 * WordPress users become Craft users.
 *
 * Passwords do not come across, and cannot: WordPress hashes with phpass or (since 6.8) bcrypt
 * with its own salting, and Craft cannot verify either. Rather than pretend otherwise, imported
 * users are created without a password and the run report says plainly that everyone will need
 * to reset. That is the honest outcome and every WordPress migration has it.
 *
 * Users run first because everything else references them — a post has an author, a comment has
 * a user, a Woo order has a customer.
 */
class UserImporter extends BaseImporter
{
    public static function phase(): string
    {
        return 'users';
    }

    public function estimate(RunContext $context): ?int
    {
        return null;
    }

    public function import(RunContext $context, array $resumeFrom = []): array
    {
        $offset = (int)($resumeFrom['offset'] ?? 0);
        $processed = 0;

        $context->progress->startPhase(self::phase());

        // Which WordPress users actually authored something, so the non-author filter can work
        // without a second pass over every post.
        $authors = $context->plan->importNonAuthors ? null : $this->authorIds($context);

        foreach ($context->source->users($offset) as $wpUser) {
            if ($context->limit !== null && $processed >= $context->limit) {
                break;
            }

            $offset++;
            $processed++;

            if ($authors !== null && !in_array($wpUser->id, $authors, true)) {
                $context->count(self::phase(), 'skipped');
                continue;
            }

            $this->attempt($context, IdMap::KEY_USER, $wpUser->id, $wpUser->displayName ?: $wpUser->login, function () use ($wpUser, $context) {
                $this->importUser($wpUser, $context);
            });

            $context->progress->advance($processed, $wpUser->login);
        }

        $context->progress->endPhase(self::phase());

        return ['offset' => $offset];
    }

    private function importUser(\justinholtweb\passer\models\wp\WpUser $wpUser, RunContext $context): void
    {
        $hash = $context->map->hashPayload([
            $wpUser->login, $wpUser->email, $wpUser->displayName, $wpUser->roles, $wpUser->meta,
        ]);

        if ($this->unchanged($context, IdMap::KEY_USER, $wpUser->id, $hash)) {
            $context->count(self::phase(), 'skipped');

            return;
        }

        $existingId = $context->map->lookup(IdMap::KEY_USER, $wpUser->id);
        $user = $existingId !== null ? User::find()->id($existingId)->status(null)->one() : null;
        $isNew = $user === null;

        if ($user === null) {
            // A Craft user with the same email is the same person, however they got there.
            // Reusing it is the difference between a re-run and a pile of duplicate accounts.
            $user = $wpUser->email !== ''
                ? User::find()->email($wpUser->email)->status(null)->one()
                : null;

            $isNew = $user === null;
            $user ??= new User();
        }

        $user->username = $this->uniqueUsername($wpUser->login ?: $wpUser->niceName, $user->id);
        $user->email = $this->emailFor($wpUser, $context);
        $user->firstName = $wpUser->firstName() ?: null;
        $user->lastName = $wpUser->lastName() ?: null;

        // WordPress's display name is a free-text field that often has no relationship to the
        // first/last name pair, so it is kept as the full name rather than being split.
        if ($user->firstName === null && $user->lastName === null && $wpUser->displayName !== '') {
            $user->fullName = $wpUser->displayName;
        }

        if ($wpUser->registered !== null) {
            $user->dateCreated = $this->toDateTime($wpUser->registered) ?? $user->dateCreated;
        }

        if ($isNew) {
            $user->pending = !$context->plan->activateUsers;
        }

        if ($context->dryRun) {
            $context->count(self::phase(), $isNew ? 'created' : 'updated');

            return;
        }

        $this->save($user);

        $this->assignGroups($user, $wpUser, $context);

        $context->map->record(
            IdMap::KEY_USER,
            $wpUser->id,
            User::class,
            $user->id,
            $user->uid,
            null,
            $hash,
            null,
            $context->runId
        );

        // WXR names authors by login rather than ID, so a second key is recorded for it.
        if ($wpUser->login !== '') {
            $context->map->record(
                IdMap::KEY_USER_LOGIN,
                $wpUser->login,
                User::class,
                $user->id,
                $user->uid,
                null,
                null,
                null,
                $context->runId
            );
        }

        $context->count(self::phase(), $isNew ? 'created' : 'updated');
    }

    /**
     * Craft requires an email and WordPress does not always have one — WXR exports omit them
     * entirely, and multisite users can exist without one.
     */
    private function emailFor(\justinholtweb\passer\models\wp\WpUser $wpUser, RunContext $context): string
    {
        if ($wpUser->email !== '' && filter_var($wpUser->email, FILTER_VALIDATE_EMAIL)) {
            return $wpUser->email;
        }

        $host = parse_url((string)$context->source->siteUrl(), PHP_URL_HOST) ?: 'imported.invalid';
        $local = $wpUser->login !== '' ? $wpUser->login : 'user-' . $wpUser->id;

        // `.invalid` is reserved by RFC 2606 precisely so a placeholder address can never
        // accidentally reach a real inbox.
        $placeholder = sprintf(
            '%s@%s.invalid',
            preg_replace('/[^a-z0-9._-]/i', '', $local) ?: 'user' . $wpUser->id,
            $host
        );

        $this->warn($context, sprintf(
            'User "%s" had no email address in WordPress, so a placeholder was used. They cannot '
            . 'sign in or receive mail until it is corrected.',
            $wpUser->login ?: (string)$wpUser->id
        ), ['sourceKey' => IdMap::KEY_USER, 'sourceId' => (string)$wpUser->id]);

        return $placeholder;
    }

    private function uniqueUsername(string $login, ?int $ignoreId): string
    {
        $base = trim($login) !== '' ? trim($login) : 'user';
        $username = $base;
        $suffix = 1;

        while (true) {
            $query = User::find()->username($username)->status(null);

            if ($ignoreId !== null) {
                $query->id('not ' . $ignoreId);
            }

            if (!$query->exists()) {
                return $username;
            }

            $username = $base . '-' . (++$suffix);

            if ($suffix > 500) {
                // Give up on the readable form rather than loop forever on a site with an
                // extraordinary number of collisions.
                return $base . '-' . StringHelper::randomString(8);
            }
        }
    }

    private function assignGroups(User $user, \justinholtweb\passer\models\wp\WpUser $wpUser, RunContext $context): void
    {
        $service = Craft::$app->getUserGroups();
        $groupIds = [];

        foreach ($wpUser->roles as $role) {
            $handle = $context->plan->roleMap[$role] ?? null;

            if ($handle === null) {
                continue;
            }

            $group = $service->getGroupByHandle($handle);

            if ($group !== null) {
                $groupIds[] = $group->id;
            }
        }

        if ($groupIds === []) {
            return;
        }

        // Craft's own admin flag is deliberately not set from the WordPress administrator role:
        // minting admins during an automated import is not a decision a migration should make.
        Craft::$app->getUsers()->assignUserToGroups($user->id, array_unique($groupIds));
    }

    /**
     * WordPress user IDs that authored at least one post of a type being imported.
     *
     * @return int[]
     */
    private function authorIds(RunContext $context): array
    {
        $ids = [];

        foreach (array_keys($context->plan->enabledPostTypes()) as $postType) {
            try {
                foreach ($context->source->posts($postType) as $post) {
                    if ($post->authorId > 0) {
                        $ids[$post->authorId] = true;
                    }
                }
            } catch (\Throwable) {
                // A post type that cannot be read cannot contribute authors; the fallback author
                // covers its content.
                continue;
            }
        }

        return array_map('intval', array_keys($ids));
    }
}
