<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccountEvent;
use App\Entity\User;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Writes one `AccountEvent` row per call, in its own physical DBAL
 * Connection/EntityManager -- the exact mechanism `AuthEventRecorder`
 * documents and this project already proved (a second EntityManager over the
 * *same* Connection still shares one physical transaction; only a second
 * Connection is independent). Reused verbatim rather than re-derived.
 */
final class AccountEventRecorder
{
    private ?EntityManagerInterface $auditEntityManager = null;

    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
    }

    public function record(AccountEventRecord $record): void
    {
        $entityManager = $this->independentEntityManager();

        $actorUser = null !== $record->actorUserId
            ? $entityManager->getReference(User::class, $record->actorUserId)
            : null;
        $subjectUser = $entityManager->getReference(User::class, $record->subjectUserId);
        \assert($subjectUser instanceof User);

        $accountEvent = new AccountEvent(
            $record->type->value,
            new \DateTimeImmutable(),
            $actorUser,
            $subjectUser,
            $record->ip,
            $record->userAgent,
            $record->context,
        );

        $entityManager->persist($accountEvent);
        $entityManager->flush();
        $entityManager->clear();
    }

    private function independentEntityManager(): EntityManagerInterface
    {
        if (null !== $this->auditEntityManager && $this->auditEntityManager->isOpen()) {
            return $this->auditEntityManager;
        }

        $businessManager = $this->managerRegistry->getManager();
        \assert($businessManager instanceof EntityManagerInterface);

        $businessConnection = $businessManager->getConnection();

        $independentConnection = DriverManager::getConnection(
            $businessConnection->getParams(),
            $businessConnection->getConfiguration(),
        );

        $this->auditEntityManager = new EntityManager($independentConnection, $businessManager->getConfiguration());

        return $this->auditEntityManager;
    }
}
