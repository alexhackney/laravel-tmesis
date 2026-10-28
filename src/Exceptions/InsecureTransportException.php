<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * An admin call refused before anything was sent, because it would carry the node's
 * bearer token over cleartext: a URL that is not `https`, to a host that is not
 * loopback, outside the `local` and `testing` environments.
 *
 * The message names the node and the scheme and host, never the token, a port, a
 * path or anything after the host.
 */
class InsecureTransportException extends TmesisException
{
    /**
     * @param  string  $target  `scheme://host` only (Transport::cleartextTarget())
     */
    public static function forNode(string $node, string $target): self
    {
        return new self(
            "Refused an admin call to the Tmesis node [{$node}] at [{$target}]: it would send the admin bearer token in clear. "
            .'Outside the local and testing environments an admin url must be https, or http to a loopback host. '
            .'Give the node a DNS name in its api.tls.domain (it then serves its own ACME certificate) and dial https://<that name>:<port>.',
            node: $node,
        );
    }
}
