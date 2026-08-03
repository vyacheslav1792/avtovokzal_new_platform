<?php

namespace App\Services\Buster;

class GatewayFactory
{
    public function __construct(private readonly StationRegistry $stations) {}

    public function forStation(int|string $awtcod, array $agent): BusterGateway
    {
        $station = $this->stations->get($awtcod);

        return new BusterGateway(
            new BusterConnection($station),
            (int) $agent['legacy_id'],
            (int) ($agent['worker_code'] ?? 990),
            (int) ($agent['baggage_worker_code'] ?? 991),
        );
    }
}
