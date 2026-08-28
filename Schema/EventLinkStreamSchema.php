<?php

declare(strict_types=1);

namespace Storm\EventLinks\Schema;

use Storm\EventLinks\DerivedStreamRevision;

/**
 * Raw PostgreSQL DDL for `event_link_streams`: the per-target header of a derived stream, one row per
 * target, holding the REVISION of its membership.
 *
 * A consumer resumes on the global `source_sequence`, so its checkpoint asserts "everything linked up to
 * here is folded", a claim about a SET, not a position. A destructive rebuild breaks it silently: a
 * producer whose selection widens is refused by the topology gate and routed through bump + reset, whose
 * replay can introduce a source position BELOW a consumer's checkpoint, permanently invisible to it.
 * Nothing in the stream's own shape reveals that, since `max(source_sequence)` is unchanged or higher and
 * target positions are dense either way; the revision is the only witness, and the run gate compares it
 * against the value stamped on the consumer's row.
 *
 * A row appears on the FIRST destructive rebuild, never on ordinary linking, so a stream that was never
 * rebuilt has no row and reads 0, the same value a fresh consumer stamps, which is why the absent row
 * needs no backfill. `EventLinkWriter` bumps it in the SAME statement that deletes the links, a
 * data-modifying CTE, so the two cannot drift apart.
 *
 * It stays events-side with `event_links` for the same reason that table does: the derived read JOINs
 * the two, so they must share a database.
 *
 * @see EventLinkSchema
 * @see \Storm\Projector\Link\EventLinkWriter::deleteLinks()
 * @see DerivedStreamRevision
 * @see \Storm\Projector\Run\ProjectionRunner the run-start gate consuming the revision
 */
final class EventLinkStreamSchema
{
    /**
     * @return list<string>
     */
    public static function up(): array
    {
        return [
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS event_link_streams (
                    target_stream text   NOT NULL,
                    revision      bigint NOT NULL DEFAULT 0,
                    rebuilt_at    timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                    CONSTRAINT event_link_streams_pk PRIMARY KEY (target_stream)
                )
                SQL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function down(): array
    {
        return [
            /** @lang PostgreSQL */
            'DROP TABLE IF EXISTS event_link_streams',
        ];
    }
}
