<?php
declare(strict_types=1);

final class StationsService
{
    /** @var StationsRepository */
    private $repo;

    public function __construct(StationsRepository $repo)
    {
        $this->repo = $repo;
    }

    public function listStations(): array
    {
        return $this->repo->allActive();
    }
}
