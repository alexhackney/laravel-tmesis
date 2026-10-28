<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Enrollment;

/**
 * What a rendered document is for, which decides what happens to the secrets.
 *
 *  - Config: a `config.<role>.yml`. The `.env` secrets and the bootstrap token stay
 *    as `${...}`, and the node interpolates them from its `.env` at start. The
 *    document holds nothing secret.
 *  - UserData: a `cloud-init.<role>.yml`, whose `write_files` is what puts
 *    `/etc/tmesis/.env` on the box. The four secrets and the bootstrap token are
 *    SUBSTITUTED, so the document is a credential (tmesis WP7: the secrets leave
 *    the control plane only into the box's own user-data).
 */
enum RenderMode: string
{
    case Config = 'config';
    case UserData = 'user-data';

    /**
     * By file name: `cloud-init.*` is user-data, anything else config.
     */
    public static function forPath(string $path): self
    {
        return str_starts_with(basename($path), 'cloud-init.') ? self::UserData : self::Config;
    }
}
