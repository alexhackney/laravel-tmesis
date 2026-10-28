<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationBundle;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationRequest;
use AlexHackney\Tmesis\Data\Artifacts\InitialAuthorization;
use AlexHackney\Tmesis\Data\Artifacts\ObjectStatus;
use AlexHackney\Tmesis\Data\Artifacts\OverrideRequest;
use AlexHackney\Tmesis\Data\Artifacts\PartAuthorization;
use AlexHackney\Tmesis\Data\Artifacts\PlanRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanStatus;
use AlexHackney\Tmesis\Data\Artifacts\PlanTarget;
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Data\Artifacts\SignedDestination;
use AlexHackney\Tmesis\Data\Artifacts\SignedRequest;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Transfers\TransferAuthorization;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

const UNIT_INVENTORY = '9f2c1d5e6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f';

const ULID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

/**
 * The object as every dumper a developer might reach for prints it: an `(array)` cast,
 * `var_export()`, `var_dump()`, `print_r()`, `json_encode()`, and Symfony VarDumper
 * (what `dump()` and `dd()` use), which shows private properties next to
 * `__debugInfo`.
 *
 * @return array<string, string>
 */
function everyDumpOf(object $value): array
{
    ob_start();
    var_dump($value);
    $varDump = (string) ob_get_clean();

    $dumper = new CliDumper;
    $dumper->setColors(false);

    return [
        'array cast' => var_export((array) $value, true),
        'var_export' => var_export($value, true),
        'var_dump' => $varDump,
        'print_r' => print_r($value, true),
        'json_encode' => (string) json_encode($value),
        'VarDumper' => (string) $dumper->dump((new VarCloner)->cloneVar($value), true),
    ];
}

function unitSigned(string $method = 'PUT', array $headers = []): SignedRequest
{
    return SignedRequest::make($method, 'https://bucket.example.net/k?X-Amz-Signature=s3cr3t', $headers, '2026-09-22T22:30:00.000Z');
}

describe('PlanRequest', function (): void {
    it('mints a ULID request id unless one is given, and keeps it for the retry', function (): void {
        $minted = PlanRequest::make(UNIT_INVENTORY, 0);
        $given = PlanRequest::make(UNIT_INVENTORY, 0, '01J8ZCK6R3M8QWX2VN5TKB7PD4');

        expect($minted->requestId())->toMatch(ULID_PATTERN)
            ->and($minted->toArray()['request_id'])->toBe($minted->requestId())
            ->and($minted->withReason('x')->requestId())->toBe($minted->requestId())
            ->and($given->requestId())->toBe('01J8ZCK6R3M8QWX2VN5TKB7PD4');
    });

    it('sends the expected revision it was given and refuses a negative one', function (): void {
        expect(PlanRequest::make(UNIT_INVENTORY, 3)->toArray()['expected_revision'])->toBe(3)
            ->and(fn () => PlanRequest::make(UNIT_INVENTORY, -1))->toThrow(InvalidConfigurationException::class, 'expected_revision');
    });

    it('omits a reason it was not given, and sends an empty one it was', function (): void {
        $none = PlanRequest::make(UNIT_INVENTORY, 0, 'r')->toArray();
        $empty = PlanRequest::make(UNIT_INVENTORY, 0, 'r')->withReason('')->toArray();

        expect($none)->not->toHaveKey('reason')
            ->and($empty['reason'])->toBe('')
            ->and(json_encode($none))->toBe('{"request_id":"r","expected_revision":0,"inventory_sha256":"'.UNIT_INVENTORY.'","targets":[]}');
    });

    it('refuses a reason over 1024 bytes', function (): void {
        expect(fn () => PlanRequest::make(UNIT_INVENTORY, 0)->withReason(str_repeat('a', 1025)))
            ->toThrow(InvalidConfigurationException::class, '1024 bytes');

        expect(PlanRequest::make(UNIT_INVENTORY, 0)->withReason(str_repeat('a', 1024))->reason())->toHaveLength(1024);
    });

    it('keeps targets in the order they were added, the first being the registration target', function (): void {
        $plan = PlanRequest::make(UNIT_INVENTORY, 0)
            ->withTarget(PlanTarget::recordingIngest('primary', 'a.ts', 'T1'))
            ->withTargets(PlanTarget::recordingIngest('backup', 'b.ts', 'T2'), PlanTarget::recordingIngest('cold', 'c.ts', 'T0'));

        expect(array_column($plan->toArray()['targets'], 'id'))->toBe(['T1', 'T2', 'T0'])
            ->and(array_map(static fn (PlanTarget $t): string => $t->id(), $plan->targets()))->toBe(['T1', 'T2', 'T0']);
    });

    it('refuses the same target id twice', function (): void {
        expect(fn () => PlanRequest::make(UNIT_INVENTORY, 0)->withTargets(
            PlanTarget::recordingIngest('a', 'a.ts', 'T1'),
            PlanTarget::recordingIngest('b', 'b.ts', 'T1'),
        ))->toThrow(InvalidConfigurationException::class, 'already in this plan');
    });

    it('binds every initial authorization to the revision it creates, its inventory and its target', function (): void {
        $initial = fn (string $path): InitialAuthorization => InitialAuthorization::make($path, "k/{$path}", AuthorizationBundle::make(put: unitSigned()));

        $body = PlanRequest::make(UNIT_INVENTORY, 3)->withTargets(
            PlanTarget::hlsSealed(SignedDestination::make('us-east-1', 'a', authorizations: [$initial('playlist.m3u8'), $initial('v1/init.mp4')]), 'k', null, 'T1'),
            PlanTarget::hlsSealed(SignedDestination::make('us-east-1', 'b', authorizations: [$initial('playlist.m3u8')]), 'k', null, 'T2'),
        )->toArray();

        $bindings = [];

        foreach ($body['targets'] as $target) {
            foreach ($target['signed']['authorizations'] as $authorization) {
                $bindings[] = [$authorization['artifact_path'], $authorization['plan_revision'], $authorization['inventory_sha256'], $authorization['target_id'], $authorization['object_key']];
            }
        }

        expect($bindings)->toBe([
            ['playlist.m3u8', 4, UNIT_INVENTORY, 'T1', 'k/playlist.m3u8'],
            ['v1/init.mp4', 4, UNIT_INVENTORY, 'T1', 'k/v1/init.mp4'],
            ['playlist.m3u8', 4, UNIT_INVENTORY, 'T2', 'k/playlist.m3u8'],
        ]);
    });

    it('is immutable: adding a target returns a new plan', function (): void {
        $empty = PlanRequest::make(UNIT_INVENTORY, 0);
        $empty->withTarget(PlanTarget::recordingIngest('a', 'a.ts'));

        expect($empty->targets())->toBe([]);
    });
});

describe('PlanTarget', function (): void {
    it('mints a ULID target id unless one is given', function (): void {
        expect(PlanTarget::hlsSealed('d', 'p')->id())->toMatch(ULID_PATTERN)
            ->and(PlanTarget::hlsSealed('d', 'p', null, 'T1')->id())->toBe('T1');
    });

    it('renders exactly one of destination_ref and signed, from the one destination argument', function (): void {
        $configured = PlanTarget::recordingMaster('csn-vod', 'show.mp4', 'T1')->toArray();
        $signed = PlanTarget::recordingMaster(SignedDestination::make('us-east-1', 'backup'), 'show.mp4', 'T2')->toArray();

        expect($configured)->toBe(['id' => 'T1', 'artifact' => 'recording_master', 'destination_ref' => 'csn-vod', 'key' => 'show.mp4'])
            ->and($signed)->toBe(['id' => 'T2', 'artifact' => 'recording_master', 'signed' => ['region' => 'us-east-1', 'bucket' => 'backup'], 'key' => 'show.mp4']);
    });

    it('takes a prefix and an optional master name for HLS, and never a key', function (): void {
        expect(PlanTarget::hlsSealed('csn-vod', '', null, 'T1')->toArray())
            ->toBe(['id' => 'T1', 'artifact' => 'hls_sealed', 'destination_ref' => 'csn-vod', 'prefix' => ''])
            ->and(PlanTarget::hlsSealed('csn-vod', 'show', 'playlist.m3u8', 'T1')->toArray()['master_name'])->toBe('playlist.m3u8');
    });

    it('builds from a kind that arrives as data', function (): void {
        $hls = PlanTarget::forKind(ArtifactStatus::KIND_HLS_SEALED, 'd', 'show', 'playlist.m3u8', 'T1');
        $ingest = PlanTarget::forKind(ArtifactStatus::KIND_RECORDING_INGEST, 'd', 'show.ts', id: 'T2');

        expect($hls->prefix())->toBe('show')
            ->and($hls->key())->toBeNull()
            ->and($hls->masterName())->toBe('playlist.m3u8')
            ->and($ingest->key())->toBe('show.ts')
            ->and($ingest->prefix())->toBeNull()
            ->and($ingest->artifact())->toBe('recording_ingest')
            ->and($ingest->destinationRef())->toBe('d')
            ->and($ingest->signed())->toBeNull();
    });

    it('refuses a master name on a recording and an unknown kind', function (): void {
        expect(fn () => PlanTarget::forKind(ArtifactStatus::KIND_RECORDING_MASTER, 'd', 'show.mp4', 'playlist.m3u8'))
            ->toThrow(InvalidConfigurationException::class, 'for an hls_sealed or job_hls target only')
            ->and(fn () => PlanTarget::forKind('mp4_clip', 'd', 'x'))
            ->toThrow(InvalidConfigurationException::class, 'targets.artifact');
    });
});

describe('SignedDestination', function (): void {
    it('omits every optional field it was not given', function (): void {
        expect(SignedDestination::make('us-east-1', 'backup')->toArray())->toBe(['region' => 'us-east-1', 'bucket' => 'backup']);
    });

    it('renders every field it was given', function (): void {
        $authorization = InitialAuthorization::make('playlist.m3u8', 'k/playlist.m3u8', AuthorizationBundle::make(put: unitSigned()));

        $destination = SignedDestination::make('us-east-1', 'backup', 'https://archive.example.net', 'virtual', 64, 16, [$authorization]);
        $body = $destination->toArray(1, UNIT_INVENTORY, 'T1');

        expect(array_keys($body))->toBe(['region', 'bucket', 'endpoint', 'addressing', 'part_size_mb', 'upload_concurrency', 'authorizations'])
            ->and($body['authorizations'][0]['authorization_revision'])->toBe(1)
            ->and($body['authorizations'][0]['multipart_upload_id'])->toBeNull()
            ->and($destination->addressing())->toBe('virtual')
            ->and($destination->partSizeMb())->toBe(64)
            ->and($destination->uploadConcurrency())->toBe(16);
    });

    it('refuses to render initial authorizations without the plan and target they bind to', function (): void {
        $destination = SignedDestination::make('us-east-1', 'backup', authorizations: [
            InitialAuthorization::make('playlist.m3u8', 'k/playlist.m3u8', AuthorizationBundle::make(put: unitSigned())),
        ]);

        expect(fn () => $destination->toArray())->toThrow(InvalidConfigurationException::class, 'PlanRequest::toArray()')
            ->and(fn () => PlanTarget::hlsSealed($destination, 'k', null, 'T1')->toArray())->toThrow(InvalidConfigurationException::class, 'signed.authorizations');
    });

    it('refuses an addressing mode and sizes outside the document\'s ranges', function (Closure $make, string $key): void {
        expect($make)->toThrow(InvalidConfigurationException::class, $key);
    })->with([
        'addressing' => [fn () => SignedDestination::make('r', 'b', addressing: 'dns'), 'signed.addressing'],
        'part size 4' => [fn () => SignedDestination::make('r', 'b', partSizeMb: 4), 'signed.part_size_mb'],
        'part size 513' => [fn () => SignedDestination::make('r', 'b', partSizeMb: 513), 'signed.part_size_mb'],
        'concurrency 0' => [fn () => SignedDestination::make('r', 'b', uploadConcurrency: 0), 'signed.upload_concurrency'],
        'concurrency 65' => [fn () => SignedDestination::make('r', 'b', uploadConcurrency: 65), 'signed.upload_concurrency'],
    ]);
});

describe('SignedRequest', function (): void {
    it('renders the whole request, an empty header set as a JSON object', function (): void {
        expect(json_encode(unitSigned()->toArray()))
            ->toBe('{"method":"PUT","url":"https:\/\/bucket.example.net\/k?X-Amz-Signature=s3cr3t","headers":{},"expires_at":"2026-09-22T22:30:00.000Z"}');
    });

    it('takes a single header value as a list of one, and a DateTime expiry as RFC3339 UTC', function (): void {
        $request = SignedRequest::make('HEAD', 'https://b.example.net/k', ['x-a' => 'one', 'x-b' => [2 => 'two']], new DateTimeImmutable('2026-09-22T18:30:00-04:00'));

        expect($request->toArray()['headers'])->toBe(['x-a' => ['one'], 'x-b' => ['two']])
            ->and($request->expiresAt())->toBe('2026-09-22T22:30:00.000Z')
            ->and($request->method())->toBe('HEAD')
            ->and($request->headerNames())->toBe(['x-a', 'x-b']);
    });

    it('refuses a method the document does not name', function (): void {
        expect(fn () => unitSigned('GET'))->toThrow(InvalidConfigurationException::class, 'signed.method');
    });

    it('exposes the URL only redacted, and dumps nothing that signs', function (): void {
        $request = unitSigned(headers: ['x-amz-security-token' => 'tok3n']);

        expect($request->redactedUrl())->toBe('https://bucket.example.net')
            ->and(everyDumpOf($request))->each->not->toContain('s3cr3t')
            ->and(everyDumpOf($request))->each->not->toContain('tok3n')
            ->and($request->__debugInfo()['headers'])->toBe(['x-amz-security-token'])
            ->and($request->toArray()['url'])->toBe('https://bucket.example.net/k?X-Amz-Signature=s3cr3t')
            ->and($request->toArray()['headers'])->toBe(['x-amz-security-token' => ['tok3n']]);
    });

    it('cannot be serialized', function (): void {
        expect(fn () => serialize(unitSigned()))->toThrow(Exception::class, 'SensitiveParameterValue');
    });
});

describe('what holds a SignedRequest', function (): void {
    it('dumps nothing that signs from a plan with initial authorizations', function (): void {
        $plan = PlanRequest::make(UNIT_INVENTORY, 0)->withTarget(PlanTarget::hlsSealed(
            SignedDestination::make('us-east-1', 'backup', authorizations: [
                InitialAuthorization::make('playlist.m3u8', 'k/playlist.m3u8', AuthorizationBundle::make(
                    put: unitSigned(headers: ['x-amz-security-token' => 'tok3n']),
                    parts: [PartAuthorization::make(1, unitSigned())],
                )),
            ]),
            'k',
            null,
            'T1',
        ));

        expect(everyDumpOf($plan))->each->not->toContain('s3cr3t')
            ->and(everyDumpOf($plan))->each->not->toContain('tok3n')
            ->and(json_encode($plan->toArray()))->toContain('s3cr3t')
            ->and(fn () => serialize($plan))->toThrow(Exception::class, 'SensitiveParameterValue');
    });

    it('dumps nothing that signs from a transfer authorization', function (): void {
        $authorization = TransferAuthorization::make(
            put: unitSigned(headers: ['x-amz-security-token' => 'tok3n']),
            parts: [PartAuthorization::make(2, unitSigned())],
            head: unitSigned('HEAD'),
        );

        expect(everyDumpOf($authorization))->each->not->toContain('s3cr3t')
            ->and(everyDumpOf($authorization))->each->not->toContain('tok3n')
            ->and(fn () => serialize($authorization))->toThrow(Exception::class, 'SensitiveParameterValue');
    });
});

describe('AuthorizationBundle and PartAuthorization', function (): void {
    it('renders all six operations, null where not supplied', function (): void {
        $bundle = AuthorizationBundle::make(parts: [PartAuthorization::make(7, unitSigned())], abort: unitSigned('DELETE'));

        expect(array_keys($bundle->toArray()))->toBe(['put', 'initiate', 'parts', 'complete', 'abort', 'head'])
            ->and($bundle->toArray()['put'])->toBeNull()
            ->and($bundle->toArray()['parts'][0]['part_number'])->toBe(7)
            ->and($bundle->supplied())->toBe(['parts', 'abort'])
            ->and($bundle->parts()[0]->request()->method())->toBe('PUT')
            ->and($bundle->abort()?->method())->toBe('DELETE')
            ->and($bundle->head())->toBeNull();
    });

    it('refuses more than 128 part requests in one refresh', function (): void {
        $parts = array_map(static fn (int $n): PartAuthorization => PartAuthorization::make($n, unitSigned()), range(1, 129));

        expect(fn () => AuthorizationBundle::make(parts: $parts))->toThrow(InvalidConfigurationException::class, '128')
            ->and(AuthorizationBundle::make(parts: array_slice($parts, 0, 128))->parts())->toHaveCount(128);
    });

    it('refuses a part number below 1', function (): void {
        expect(fn () => PartAuthorization::make(0, unitSigned()))->toThrow(InvalidConfigurationException::class, 'part_number');
    });
});

describe('AuthorizationRequest', function (): void {
    it('renders the pinned field order with multipart_upload_id always present', function (): void {
        $body = AuthorizationRequest::make(1, UNIT_INVENTORY, 'T1', 'k', 2, null, AuthorizationBundle::make(put: unitSigned()))->toArray();

        expect(array_keys($body))->toBe(['plan_revision', 'inventory_sha256', 'target_id', 'object_key', 'authorization_revision', 'multipart_upload_id', 'requests'])
            ->and($body['multipart_upload_id'])->toBeNull();
    });

    it('builds the next refresh from the object status', function (): void {
        $object = ObjectStatus::fromArray($this->fixtureJson('responses/listTransfers.json')['items'][1]);

        $next = AuthorizationRequest::nextFor($object, UNIT_INVENTORY, AuthorizationBundle::make(abort: unitSigned('DELETE')));

        expect($next->authorizationRevision())->toBe($object->authorizationRevision() + 1)
            ->and($next->planRevision())->toBe($object->planRevision())
            ->and($next->targetId())->toBe($object->targetId())
            ->and($next->objectKey())->toBe($object->objectKey())
            ->and($next->multipartUploadId())->toBe('provider-upload-id')
            ->and($next->inventorySha256())->toBe(UNIT_INVENTORY)
            ->and($next->requests()->supplied())->toBe(['abort']);
    });

    it('refuses an authorization revision below 1', function (): void {
        expect(fn () => AuthorizationRequest::make(1, UNIT_INVENTORY, 'T1', 'k', 0, null, AuthorizationBundle::make()))
            ->toThrow(InvalidConfigurationException::class, 'authorization_revision');
    });
});

describe('ReleaseRequest', function (): void {
    it('renders the four fields and mints a request id', function (): void {
        $release = ReleaseRequest::make(2, UNIT_INVENTORY, 'done');

        expect(array_keys($release->toArray()))->toBe(['request_id', 'plan_revision', 'inventory_sha256', 'reason'])
            ->and($release->requestId())->toMatch(ULID_PATTERN)
            ->and($release->planRevision())->toBe(2)
            ->and($release->reason())->toBe('done');
    });

    it('releases the revision the caller\'s own plan write created', function (): void {
        $plan = PlanStatus::fromArray($this->fixtureJson('responses/replaceTransferPlan.json'), created: true);

        $release = ReleaseRequest::forPlan($plan, 'copied', 'R1');

        expect($release->toArray())->toBe(['request_id' => 'R1', 'plan_revision' => $plan->revision(), 'inventory_sha256' => $plan->inventorySha256(), 'reason' => 'copied']);
    });

    it('refuses what the node refuses on the request alone', function (Closure $make, string $key): void {
        expect($make)->toThrow(InvalidConfigurationException::class, $key);
    })->with([
        'empty reason' => [fn () => ReleaseRequest::make(1, UNIT_INVENTORY, ''), 'reason'],
        'reason over 1024 bytes' => [fn () => ReleaseRequest::make(1, UNIT_INVENTORY, str_repeat('é', 513)), 'reason'],
        'negative revision' => [fn () => ReleaseRequest::make(-1, UNIT_INVENTORY, 'x'), 'plan_revision'],
        'empty inventory' => [fn () => ReleaseRequest::make(0, '', 'x'), 'discardAbandoned'],
        'a plan status without an inventory' => [fn () => ReleaseRequest::forPlan(PlanStatus::fromArray([]), 'x'), 'inventory_sha256'],
    ]);
});

describe('OverrideRequest', function (): void {
    it('renders an override and a discard, waivers re-indexed', function (): void {
        $override = OverrideRequest::override(2, UNIT_INVENTORY, 'backup waived', [3 => 'T2'], 'R1');
        $discard = OverrideRequest::discard(2, UNIT_INVENTORY, 'drop it', ['T1', 'T2']);

        expect($override->toArray())->toBe([
            'request_id' => 'R1',
            'plan_revision' => 2,
            'inventory_sha256' => UNIT_INVENTORY,
            'action' => 'override',
            'reason' => 'backup waived',
            'waived_targets' => ['T2'],
        ])
            ->and($discard->action())->toBe(OverrideRequest::ACTION_DISCARD)
            ->and($discard->waivedTargets())->toBe(['T1', 'T2'])
            ->and($discard->isAbandonedDiscard())->toBeFalse();
    });

    it('renders the abandoned discard as exactly the shape the node accepts', function (): void {
        $discard = OverrideRequest::discardAbandoned('crashed window', 'R9');

        expect($discard->toArray())->toBe([
            'request_id' => 'R9',
            'plan_revision' => 0,
            'inventory_sha256' => '',
            'action' => 'discard',
            'reason' => 'crashed window',
            'waived_targets' => [],
        ])
            ->and($discard->isAbandonedDiscard())->toBeTrue()
            ->and(OverrideRequest::discardAbandoned('x')->requestId())->toMatch(ULID_PATTERN);
    });

    it('allows an empty inventory only on the abandoned discard', function (): void {
        expect(fn () => OverrideRequest::override(0, '', 'x', []))->toThrow(InvalidConfigurationException::class, 'inventory_sha256')
            ->and(fn () => OverrideRequest::discard(0, '', 'x', []))->toThrow(InvalidConfigurationException::class, 'inventory_sha256')
            ->and(fn () => OverrideRequest::discardAbandoned(''))->toThrow(InvalidConfigurationException::class, 'reason');
    });
});
