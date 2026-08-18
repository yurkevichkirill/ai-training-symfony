<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\RoleLandingResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The post-sign-in landing point. Every role is sent here and forwarded from
 * here, so no role-specific URL is ever baked into the login flow (AC-3, AC-16).
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(RoleLandingResolver $resolver): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->redirectToRoute($resolver->routeFor($user->getRole()));
    }
}
