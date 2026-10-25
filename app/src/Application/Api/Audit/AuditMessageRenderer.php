<?php

declare(strict_types=1);

namespace App\Application\Api\Audit;

use App\Application\Api\ApiTime;
use App\Infrastructure\Persistence\Entity\AuditEntry;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns an audit entry into a sentence: its message key is an ICU message of the `audit` translation domain and
 * its parameters feed it. Parameters that are instants (ISO 8601) are shown in local time: `H:i` for today, the
 * date and time otherwise. A key nobody translated, or whose parameters do not fit its message, is shown as is.
 */
final readonly class AuditMessageRenderer
{
    private const string INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/D';

    public function __construct(
        private TranslatorInterface $translator,
        private TranslatorBagInterface $translations,
        private ApiTime $time,
        private ClockInterface $clock,
    ) {
    }

    public function render(AuditEntry $entry, string $locale): string
    {
        $key = $entry->getMessageKey();
        // Without this check the translator would "translate" an unknown key by replacing the parameter names in it.
        if (!$this->translations->getCatalogue($locale)->has($key, 'audit')) {
            return $key;
        }

        try {
            return $this->translator->trans($key, $this->parameters($entry->getParams(), $locale), 'audit', $locale);
        } catch (\Throwable) {
            return $key;
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, string|int|float>
     */
    private function parameters(array $params, string $locale): array
    {
        $converted = [];
        foreach ($params as $name => $value) {
            $converted[$name] = match (true) {
                \is_int($value), \is_float($value) => $value,
                \is_string($value) => $this->text($value, $locale),
                \is_bool($value) => $value ? 'true' : 'false',
                default => '',
            };
        }

        return $converted;
    }

    private function text(string $value, string $locale): string
    {
        if (1 !== preg_match(self::INSTANT, $value)) {
            return $value;
        }

        try {
            $instant = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return $value;
        }

        $zone = $this->time->zone();
        $local = $instant->setTimezone($zone);
        if ($local->format('Y-m-d') === $this->clock->now()->setTimezone($zone)->format('Y-m-d')) {
            return $local->format('H:i');
        }

        return $local->format('fr' === $locale ? 'd/m/Y H:i' : 'Y-m-d H:i');
    }
}
