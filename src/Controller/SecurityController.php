<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The sign-in surface.
 *
 * The controller only renders the form. Authentication itself is handled by the
 * firewall's form_login authenticator, which intercepts the POST to this same
 * route before the controller runs -- so there is deliberately no credential
 * handling here to get wrong.
 *
 * Note what this does NOT do: it does not read the last authentication error
 * from AuthenticationUtils, which is the Symfony default. That helper surfaces
 * the exception message, and the four failure causes have distinguishable
 * messages; rendering it would undo UniformAuthenticationFailureHandler. The
 * single uniform message reaches the page as a flash instead (AC-2).
 */
final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(): Response
    {
        // An already-authenticated visitor has no business on the login form.
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('security/login.html.twig');
    }

    /**
     * Never executed: the firewall's logout listener intercepts this path. The
     * route must exist so the path can be generated and matched.
     */
    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('This method is intercepted by the logout key on the firewall.');
    }
}
