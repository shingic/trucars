<?php

namespace App\Services\Carfax;

/**
 * What the vehicle page shows from one CARFAX Canada vehicle history report.
 * Any figure the report doesn't give stays null and its row is left off. The
 * page never shows a guess.
 */
final class CarfaxReport
{
    /**
     * @param  list<string>  $badges  CARFAX's own badge labels, e.g. "No Reported Accidents".
     * @param  list<string>  $registeredIn  Provinces or states the car has been registered in.
     */
    public function __construct(
        public readonly ?string $reportUrl = null,
        public readonly ?int $accidentCount = null,
        public readonly ?int $ownerCount = null,
        public readonly ?int $serviceRecordCount = null,
        public readonly array $registeredIn = [],
        public readonly bool $stolen = false,
        public readonly array $badges = [],
    ) {}

    /** Nothing worth showing: no link and no facts. */
    public function isEmpty(): bool
    {
        return $this->reportUrl === null
            && $this->accidentCount === null
            && $this->ownerCount === null
            && $this->serviceRecordCount === null
            && $this->registeredIn === []
            && $this->badges === []
            && ! $this->stolen;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            reportUrl: $data['reportUrl'] ?? null,
            accidentCount: $data['accidentCount'] ?? null,
            ownerCount: $data['ownerCount'] ?? null,
            serviceRecordCount: $data['serviceRecordCount'] ?? null,
            registeredIn: $data['registeredIn'] ?? [],
            stolen: (bool) ($data['stolen'] ?? false),
            badges: $data['badges'] ?? [],
        );
    }
}
