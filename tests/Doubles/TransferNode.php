<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Tests\Doubles;

use AlexHackney\Tmesis\Events\TransferAuthorizationRequired;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The node side of a signed transfer, for the signing seam's tests: the event, the
 * plan, the object and its parts, built from the bundled fixtures and answered through
 * Http::fake().
 *
 * The object is the waiting multipart one of `responses/listTransfers.json` on the
 * signed target of `responses/getTransferPlan.json`; the event is the generated
 * `transfer.authorization_required` example bound to that object.
 */
final class TransferNode
{
    public const STREAM = 'show-2041-main';

    public const GENERATION = '01J8ZCK5R7V2QNX8RW4TKB6PD2';

    public const OBJECT = '01J8ZCK9T4N2QWX6VM3RKB9PD8';

    public const TARGET = '01J8ZCK8T4N2QWX6VM3RKB9PD8';

    public const KEY = 'backup/csn/2026/show/show-ingest.ts';

    public const INVENTORY = '9f2c1d5e6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f';

    public const BASE = 'http://127.0.0.1:7000/api/v1/streams/show-2041-main/artifacts/01J8ZCK5R7V2QNX8RW4TKB6PD2';

    public const JOB = 'job_01J8ZCA2R7F3H9NQ4KTX8VD5YB';

    public const JOB_BASE = 'http://127.0.0.1:7000/api/v1/transcode/jobs/job_01J8ZCA2R7F3H9NQ4KTX8VD5YB/artifacts/01J8ZCK5R7V2QNX8RW4TKB6PD2';

    /** The generation's routes: the stream's, or the job's after asJobGeneration(). */
    public string $base = self::BASE;

    /** @var array<string, mixed> */
    public array $plan;

    /** @var array<string, mixed> */
    public array $object;

    /** @var list<array<string, mixed>> */
    public array $parts = [];

    /** @var array<string, mixed> */
    public array $event;

    /**
     * Answers each supply; null echoes the object at the refresh's revision.
     *
     * @var (callable(array<string, mixed>, int): PromiseInterface)|null
     */
    public $supply = null;

    /** @var list<array<string, mixed>> */
    public array $supplied = [];

    private function __construct()
    {
        $this->plan = self::fixture('responses/getTransferPlan.json');

        /** @var list<array<string, mixed>> $items */
        $items = self::fixture('responses/listTransfers.json')['items'];
        $this->object = $items[1];

        $this->event = self::fixture('contract/docs/contract/examples/transfer.authorization_required.json');
        $this->event['node'] = 'edge-venue-07';
        $this->event['plan_revision'] = 1;
        $this->event['object_key'] = self::KEY;
    }

    /**
     * Waiting on `parts` and `abort`, with `$parts` parts of which the first
     * `$complete` are durable.
     */
    public static function multipart(int $parts = 4, int $complete = 1): self
    {
        $node = new self;
        $node->object['part_count'] = $parts;
        $node->object['completed_part_count'] = $complete;

        for ($number = 1; $number <= $parts; $number++) {
            $done = $number <= $complete;

            $node->parts[] = [
                'part_number' => $number,
                'offset_bytes' => ($number - 1) * 16777216,
                'size_bytes' => 16777216,
                'checksum_sha256' => str_pad(dechex($number), 64, '0', STR_PAD_LEFT),
                'state' => $done ? 'complete' : 'waiting_authorization',
                'attempts' => $done ? 1 : 0,
                'etag' => $done ? '"etag-'.$number.'"' : null,
                'completed_at' => $done ? '2026-09-22T22:20:03.441Z' : null,
                'error' => null,
            ];
        }

        return $node;
    }

    /**
     * A single-request object waiting on `put`.
     */
    public static function put(): self
    {
        $node = new self;
        $node->object = array_merge($node->object, [
            'phase' => 'put',
            'multipart_upload_id' => null,
            'part_size_bytes' => null,
            'part_count' => 0,
            'completed_part_count' => 0,
            'authorization_revision' => 0,
            'authorization_required' => ['put'],
            'size_bytes' => 1048576,
        ]);
        $node->event['multipart_upload_id'] = null;
        $node->event['required'] = ['put'];
        $node->event['reason'] = TransferAuthorizationRequired::REASON_AUTHORIZATION_MISSING;

        return $node;
    }

    /**
     * An ambiguous write the node must settle with a HEAD (TMESIS-REVIEW-7 R3).
     */
    public static function probe(): self
    {
        $node = self::put();
        $node->object['phase'] = 'probe';
        $node->object['authorization_revision'] = 1;
        $node->object['authorization_required'] = ['head'];
        $node->object['reconcile_required'] = true;
        $node->object['error'] = ['code' => 'completion_probe_required', 'message' => 'supply a signed HEAD'];
        $node->event['required'] = ['head'];
        $node->event['reason'] = TransferAuthorizationRequired::REASON_COMPLETION_PROBE_REQUIRED;

        return $node;
    }

    /**
     * The same object on a JOB generation (tmesis v0.6.0): no stream, session or
     * epoch in the event, the job instead, and every route under the job.
     */
    public function asJobGeneration(): self
    {
        $this->event = array_replace($this->event, [
            'stream' => null,
            'session' => null,
            'session_epoch' => null,
            'job_id' => self::JOB,
            'kind' => 'job_audio',
        ]);
        $this->base = self::JOB_BASE;

        return $this;
    }

    public function toEvent(): TransferAuthorizationRequired
    {
        return TransferAuthorizationRequired::fromArray($this->event);
    }

    /**
     * Answer the four routes the seam reads and writes; anything else is a stray.
     */
    public function fake(): self
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $base = (string) parse_url($this->base, PHP_URL_PATH);

            return match (true) {
                $path === $base.'/transfer-plan' => Http::response($this->plan),
                $path === $base.'/transfers' => Http::response(['items' => [$this->object], 'limit' => 1000, 'next_cursor' => null]),
                $path === $base.'/transfers/'.self::OBJECT.'/parts' => Http::response(['items' => $this->parts, 'limit' => 1000, 'next_cursor' => null]),
                $path === $base.'/transfers/'.self::OBJECT.'/authorization' => $this->answerSupply($request),
                default => throw new RuntimeException('Unexpected request to '.$path),
            };
        });

        return $this;
    }

    /**
     * The PUT bodies sent to the authorization route, in order.
     *
     * @return list<Request>
     */
    public static function supplies(): array
    {
        return Http::recorded(fn (Request $request): bool => $request->method() === 'PUT')
            ->map(fn (array $pair): Request => $pair[0])
            ->values()
            ->all();
    }

    private function answerSupply(Request $request): PromiseInterface
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
        $this->supplied[] = $body;

        if ($this->supply !== null) {
            return ($this->supply)($body, count($this->supplied));
        }

        return Http::response(array_merge($this->object, [
            'state' => 'uploading',
            'authorization_revision' => $body['authorization_revision'],
            'authorization_available' => true,
            'authorization_required' => [],
            'error' => null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $relative): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/'.$relative), true, 512, JSON_THROW_ON_ERROR);
    }
}
