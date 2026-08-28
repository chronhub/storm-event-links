<?php

declare(strict_types=1);

namespace Storm\EventLinks;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Stream\StreamName;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * DBAL `DerivedStreamHead` over `event_links`: one indexed `max(source_sequence)` per target. Reads
 * the events connection, where `event_links` lives alongside `event_store`, a derived stream being a
 * join of the two.
 */
#[AsAlias(DerivedStreamHead::class)]
final readonly class DbalDerivedStreamHead implements DerivedStreamHead
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure of the head read
     */
    public function headFor(StreamName $target): int
    {
        return (int) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT COALESCE(max(source_sequence), 0) FROM event_links WHERE target_stream = :target',
            ['target' => $target->toString()],
        );
    }
}
