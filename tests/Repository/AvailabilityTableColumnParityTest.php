<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Task 35: the risk named in the architecture's "two availability tables
 * will drift" note. `player_availability_slot` (S4) and
 * `coach_availability_slot` (S5) were deliberately built as two separate
 * tables of identical shape (D2) rather than one shared table. This test
 * compares their column sets and asserts they remain identical, turning
 * future drift into a failing test rather than a silent surprise.
 *
 * Deleting this test later is the explicit decision to allow divergence,
 * not an accident.
 */
final class AvailabilityTableColumnParityTest extends KernelTestCase
{
    public function testPlayerAndCoachAvailabilitySlotTablesHaveIdenticalColumnSets(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $playerColumns = $this->columnDefinitions($connection, 'player_availability_slot');
        $coachColumns = $this->columnDefinitions($connection, 'coach_availability_slot');

        // The owner column differs by name (player_id vs coach_id) by
        // design -- normalize both to a common placeholder before comparing
        // the rest of the shape.
        $normalizedPlayer = $this->withOwnerColumnNormalized($playerColumns, 'player_id');
        $normalizedCoach = $this->withOwnerColumnNormalized($coachColumns, 'coach_id');

        self::assertSame(
            $normalizedPlayer,
            $normalizedCoach,
            'player_availability_slot and coach_availability_slot have drifted in column shape (name/type/nullability) beyond their owner column.',
        );
    }

    /**
     * @return list<array{column_name: string, data_type: string, is_nullable: string}>
     */
    private function columnDefinitions(\Doctrine\DBAL\Connection $connection, string $table): array
    {
        /** @var list<array{column_name: string, data_type: string, is_nullable: string}> */
        return $connection->executeQuery(
            'SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = :table ORDER BY column_name',
            ['table' => $table],
        )->fetchAllAssociative();
    }

    /**
     * @param list<array{column_name: string, data_type: string, is_nullable: string}> $columns
     *
     * @return list<array{column_name: string, data_type: string, is_nullable: string}>
     */
    private function withOwnerColumnNormalized(array $columns, string $ownerColumnName): array
    {
        $normalized = array_map(
            static function (array $column) use ($ownerColumnName): array {
                if ($column['column_name'] === $ownerColumnName) {
                    $column = ['column_name' => 'owner_id', 'data_type' => $column['data_type'], 'is_nullable' => $column['is_nullable']];
                }

                return $column;
            },
            $columns,
        );

        usort($normalized, static fn (array $a, array $b): int => $a['column_name'] <=> $b['column_name']);

        return $normalized;
    }
}
