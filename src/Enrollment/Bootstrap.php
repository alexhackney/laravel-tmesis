<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Enrollment;

/**
 * The provisioning steps after the install, as the enrolment and join commands print
 * them (tmesis `v0.9.0`, handover section 6.1; DEPLOY.md 5.6 and 6.5).
 *
 * The order is `install.sh ... --no-start`, register or join, the two benches, then the
 * start. `--no-start` skips the installer's own live bench, because a sweep inside it
 * outlives the 15-minute bootstrap token; the benches need the service stopped, and the
 * node reads the stored capacity at its next start. The live bench runs AS THE SERVICE
 * through DEPLOY.md 6.5's `systemd-run` line, which is not repeated here: it is long,
 * and the node's document is the one to copy it from.
 */
final class Bootstrap
{
    /** Stop the node and the update watcher, so a fleet `update` cannot restart a bench. */
    public const STOP = 'systemctl stop tmesis-update.path tmesis';

    /**
     * Repair the bench directory's ownership before the live bench on a box upgraded in
     * place (DEPLOY.md 6.5): a v0.8.x job bench run as root left it root-owned, and the
     * live bench, run as the service, cannot store its result there. Idempotent.
     */
    public const BENCH_OWNERSHIP = 'chown -R tmesis:tmesis /var/lib/tmesis/bench   (a v0.8.x job bench left it root-owned)';

    public const LIVE_BENCH = 'tmesis bench --profile live --store --if-live   (as the service, through the DEPLOY.md 6.5 systemd-run line)';

    public const JOB_BENCH = 'tmesis bench --profile job   (a box that runs VOD jobs)';

    public const START = 'systemctl start tmesis tmesis-update.path';
}
