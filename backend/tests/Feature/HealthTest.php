<?php

declare(strict_types=1);

it('exposes a health endpoint under /v1', function (): void {
    $response = $this->getJson('/v1/health');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'status',
        'timestamp',
        'services' => ['database', 'redis'],
    ]);
});
