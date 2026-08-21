<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccountEvent;
use App\Entity\User;
use App\Security\ImpersonationContext;
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
 *
 * S6 (AC-7, D6b), one additive edit: an optional `ImpersonationContext`
 * merges `impersonatorUserId` into every record's context when the current
 * token is a `SwitchUserToken`. This is AC-7 for every existing and every
 * future `AccountEvent` writer at once, with zero call-site changes --
 * `ImpersonationContext` depends only on `TokenStorageInterface`, so
 * reading it here, on the recorder's own independent connection, is safe.
 * `AuthEventRecorder` is deliberately not given the same treatment: an
 * `AuthEvent`'s actor and subject are the same person by that entity's own
 * docblock, and impersonation creates no `AuthEvent`.
 */
final class AccountEventRecorder
{
    private ?EntityManagerInterface $auditEntityManager = null;

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly ?ImpersonationContext $impersonationContext = null,
    ) {
    }

    public function record(AccountEventRecord $record): void
    {
        $entityManager = $this->independentEntityManager();

        $actorUser = null !== $record->actorUserId
            ? $entityManager->getReference(User::class, $record->actorUserId)
            : null;
        $subjectUser = $entityManager->getReference(User::class, $record->subjectUserId);
        \assert($subjectUser instanceof User);

        $context = $record->context;
        $impersonatorUserId = $this->impersonationContext?->impersonatorUserId();

        if (null !== $impersonatorUserId) {
            $context['impersonatorUserId'] = (string) $impersonatorUserId;
        }

        $accountEvent = new AccountEvent(
            $record->type->value,
            new \DateTimeImmutable(),
            $actorUser,
            $subjectUser,
            $record->ip,
            $record->userAgent,
            $context,
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
