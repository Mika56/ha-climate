<?php

declare(strict_types=1);

namespace App\Application\Api\Room;

use App\Application\Api\Exception\Violations;
use App\Application\Api\Input\PresenceSignalRequest;
use App\Domain\Room\PresenceSignal;
use App\Domain\Room\PresenceSignalKind;

/**
 * The rules on the presence signals of a room that depend on several fields: the domain of an `active` signal,
 * the threshold and the value that go with the kind, and no entity twice. Shared by the room API and the
 * restore of a backup, which only differ by the path of the violations.
 */
final readonly class PresenceSignalsChecker
{
    /**
     * @param list<PresenceSignalRequest> $signals
     * @param string                      $path    where the signals are in the request, e.g. `presenceSignals`
     */
    public function check(array $signals, string $path, Violations $violations): void
    {
        $seen = [];
        foreach ($signals as $index => $signal) {
            $at = \sprintf('%s[%d]', $path, $index);

            if (isset($seen[$signal->entityId])) {
                $violations->add($at.'.entityId', 'api.duplicate_value');
            }
            $seen[$signal->entityId] = true;

            match ($signal->kind) {
                PresenceSignalKind::Active => $this->checkActive($signal, $at, $violations),
                PresenceSignalKind::Above => $this->checkAbove($signal, $at, $violations),
                PresenceSignalKind::Equals => $this->checkEquals($signal, $at, $violations),
            };
        }
    }

    private function checkActive(PresenceSignalRequest $signal, string $at, Violations $violations): void
    {
        if (!\in_array(explode('.', $signal->entityId, 2)[0], PresenceSignal::ACTIVE_DOMAINS, true)) {
            $violations->add($at.'.entityId', 'api.presence_domain', ['domains' => implode(', ', PresenceSignal::ACTIVE_DOMAINS)]);
        }
        $this->forbid($signal->threshold, $at.'.threshold', $violations);
        $this->forbid($signal->value, $at.'.value', $violations);
    }

    private function checkAbove(PresenceSignalRequest $signal, string $at, Violations $violations): void
    {
        if (null === $signal->threshold || !is_finite($signal->threshold)) {
            $violations->add($at.'.threshold', 'api.threshold_required');
        }
        $this->forbid($signal->value, $at.'.value', $violations);
    }

    private function checkEquals(PresenceSignalRequest $signal, string $at, Violations $violations): void
    {
        if (null === $signal->value || '' === trim($signal->value)) {
            $violations->add($at.'.value', 'api.value_required');
        }
        $this->forbid($signal->threshold, $at.'.threshold', $violations);
    }

    private function forbid(float|string|null $field, string $path, Violations $violations): void
    {
        if (null !== $field) {
            $violations->add($path, 'api.not_for_this_kind');
        }
    }
}
