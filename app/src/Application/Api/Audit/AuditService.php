<?php

declare(strict_types=1);

namespace App\Application\Api\Audit;

use App\Application\Api\ApiTime;
use App\Application\Api\Input\AuditQuery;
use App\Infrastructure\Persistence\Entity\AuditEntry;
use App\Infrastructure\Persistence\Repository\AuditEntryRepository;
use App\Infrastructure\Persistence\Repository\AuditSearchCriteria;
use App\Infrastructure\Persistence\Repository\RoomRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Searches the audit trail and exports it as CSV.
 */
final readonly class AuditService
{
    private const int EXPORT_PAGE = 500;

    /** @var list<string> */
    private const array CSV_HEADER = ['occurredAt', 'actorType', 'actorName', 'room', 'category', 'messageKey', 'message'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditEntryRepository $entries,
        private RoomRepository $rooms,
        private AuditEntryNormalizer $normalizer,
        private AuditMessageRenderer $messages,
        private ApiTime $time,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(AuditQuery $query, string $locale): array
    {
        $criteria = $this->criteria($query, $query->limit, $query->offset);
        if (null === $criteria) {
            return ['items' => [], 'total' => 0];
        }

        return [
            'items' => array_map(fn (AuditEntry $entry): array => $this->normalizer->normalize($entry, $locale), $this->entries->search($criteria)),
            'total' => $this->entries->countMatching($criteria),
        ];
    }

    /**
     * Writes the matching entries, newest first, as CSV with their message rendered, page by page so a long
     * trail never sits in memory at once.
     *
     * @param resource $output
     */
    public function exportCsv(AuditQuery $query, string $locale, $output): void
    {
        fputcsv($output, self::CSV_HEADER, ',', '"', '');

        for ($offset = 0;; $offset += self::EXPORT_PAGE) {
            $criteria = $this->criteria($query, self::EXPORT_PAGE, $offset);
            $page = null === $criteria ? [] : $this->entries->search($criteria);

            foreach ($page as $entry) {
                fputcsv($output, array_map(self::safeCell(...), [
                    (string) $this->time->format($entry->getOccurredAt()),
                    $entry->getActorType()->value,
                    $entry->getActorName() ?? $entry->getActorId() ?? '',
                    $entry->getRoomName() ?? '',
                    $entry->getCategory(),
                    $entry->getMessageKey(),
                    $this->messages->render($entry, $locale),
                ]), ',', '"', '');
            }

            if (\count($page) < self::EXPORT_PAGE) {
                return;
            }
            $this->entityManager->clear();
        }
    }

    /**
     * @return AuditSearchCriteria|null null when the filtered room does not exist (nothing can match)
     */
    private function criteria(AuditQuery $query, int $limit, int $offset): ?AuditSearchCriteria
    {
        $room = null;
        if (null !== $query->roomId) {
            $room = $this->rooms->find(Uuid::fromString($query->roomId));
            if (null === $room) {
                return null;
            }
        }

        return new AuditSearchCriteria($room, $query->actorType, $query->from, $query->to, $limit, $offset);
    }

    /**
     * A spreadsheet would run a cell starting with one of these characters as a formula.
     */
    private static function safeCell(string $value): string
    {
        return '' !== $value && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}
