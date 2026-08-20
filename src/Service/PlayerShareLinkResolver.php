<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlayerShareLink;
use App\Repository\PlayerShareLinkRepository;
use App\Service\Exception\ShareLinkUnavailableException;

/**
 * Resolves a `/join/{code}` code to its owning trainer's `PlayerShareLink`
 * (AC-1). One repository query joins the trainer and filters
 * `trainer.status = ACTIVE`, so an unknown code and a code belonging to a
 * deactivated/deleted trainer both surface as the same
 * `ShareLinkUnavailableException` -- non-enumerating, and the same "this
 * invitation is no longer available" outcome the edge case asks for.
 */
final class PlayerShareLinkResolver
{
    public function __construct(private readonly PlayerShareLinkRepository $shareLinkRepository)
    {
    }

    /**
     * @throws ShareLinkUnavailableException the code is unknown, or its
     *                                        trainer is no longer ACTIVE
     */
    public function resolve(string $code): PlayerShareLink
    {
        $link = $this->shareLinkRepository->findActiveByCode($code);

        if (null === $link) {
            throw new ShareLinkUnavailableException();
        }

        return $link;
    }
}
