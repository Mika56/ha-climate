<?php

declare(strict_types=1);

namespace App\Domain\Decision;

use App\Domain\Climate\Temperature;

/**
 * Snapshot of the runtime facts known about a room. Every fact is optional.
 */
final readonly class RoomFacts
{
    public function __construct(
        public ?\DateTimeImmutable $houseEmptySince = null,
        public ?\DateTimeImmutable $roomVacantSince = null,
        public ?\DateTimeImmutable $openingOpenSince = null,
        public ?ActiveOverride $override = null,
        public ?VacationPeriod $vacation = null,
        public ?Temperature $currentTemperature = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public function withHouseEmptySince(?\DateTimeImmutable $houseEmptySince): self
    {
        return new self($houseEmptySince, $this->roomVacantSince, $this->openingOpenSince, $this->override, $this->vacation, $this->currentTemperature);
    }

    public function withRoomVacantSince(?\DateTimeImmutable $roomVacantSince): self
    {
        return new self($this->houseEmptySince, $roomVacantSince, $this->openingOpenSince, $this->override, $this->vacation, $this->currentTemperature);
    }

    public function withOpeningOpenSince(?\DateTimeImmutable $openingOpenSince): self
    {
        return new self($this->houseEmptySince, $this->roomVacantSince, $openingOpenSince, $this->override, $this->vacation, $this->currentTemperature);
    }

    public function withOverride(?ActiveOverride $override): self
    {
        return new self($this->houseEmptySince, $this->roomVacantSince, $this->openingOpenSince, $override, $this->vacation, $this->currentTemperature);
    }

    public function withVacation(?VacationPeriod $vacation): self
    {
        return new self($this->houseEmptySince, $this->roomVacantSince, $this->openingOpenSince, $this->override, $vacation, $this->currentTemperature);
    }

    public function withCurrentTemperature(?Temperature $currentTemperature): self
    {
        return new self($this->houseEmptySince, $this->roomVacantSince, $this->openingOpenSince, $this->override, $this->vacation, $currentTemperature);
    }
}
