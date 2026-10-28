<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Streams\StreamSpec;

it('serialises the self-hosted caption provider unchanged', function (): void {
    expect(StreamSpec::make()->captionsProvider('self_hosted')->toRequestBody())
        ->toBe(['captions' => ['provider' => 'self_hosted']]);
});
