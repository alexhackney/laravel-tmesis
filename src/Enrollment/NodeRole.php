<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Enrollment;

/**
 * The role a node is installed as: `install.sh --role <role>`, and the `<role>` in
 * the release's `cloud-init.<role>.yml` and `config.<role>.yml`.
 */
enum NodeRole: string
{
    case Edge = 'edge';
    case Origin = 'origin';
    case Transcoder = 'transcoder';
    case Standalone = 'standalone';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
