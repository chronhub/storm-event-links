<?php

declare(strict_types=1);

namespace Storm\EventLinks;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Stream\StreamName;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * DBAL `DerivedStreamRevision` over `event_link_streams`: one primary-key-scoped `max(revision)`
 * aggregate per target, an index scan feeding at most one row. Reads the events connection, where
 * the link bookkeeping lives alongside `event_links`.
 *
 * A missing row is 0, not an error: the row is created by the first destructive rebuild, so its absence
 * says the stream has never been rebuilt, which is exactly revision 0; the aggregate form answers it
 * without a missing-row branch to get wrong.
 */
#[AsAlias(DerivedStreamRevision::class)]
final readonly class DbalDerivedStreamRevision implements DerivedStreamRevision
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure of the revision read
     */
    public function revisionFor(StreamName $target): int
    {
        return (int) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT COALESCE(max(revision), 0) FROM event_link_streams WHERE target_stream = :target',
            ['target' => $target->toString()],
        );
    }
}
