<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\FileStorage;
use App\Service\TransferManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, HeaderUtils};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\{CsrfToken, CsrfTokenManagerInterface};

final class TransferController extends AbstractController
{
    public function __construct(private TransferManager $transfers, private CsrfTokenManagerInterface $csrf, private string $appSecret, #[Target('uploads.limiter')] private RateLimiterFactory $uploads, #[Target('downloads.limiter')] private RateLimiterFactory $downloads)
    {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        $this->transfers->purgeExpired();
        return $this->render('home.html.twig', ['csrf' => $this->csrf->getToken('transfer')->getValue()]);
    }

    #[Route('/t/{token}', name: 'receive', methods: ['GET'])]
    public function receive(string $token): Response
    {
        try {
            $record = $this->transfers->resolve($token);
        } catch (HttpException $error) {
            return $this->render('receive.html.twig', ['transfer' => null, 'token' => '', 'csrf' => ''], new Response('', $error->getStatusCode()));
        }
        $transfer = ['protected' => $record['password_hash'] !== null, 'filename' => $record['password_hash'] ? null : $record['filename'], 'size' => $record['password_hash'] ? null : $record['size'], 'expiresAt' => gmdate('c', $record['expires_at'])];
        return $this->render('receive.html.twig', ['transfer' => $transfer, 'token' => $token, 'csrf' => $this->csrf->getToken('transfer')->getValue()]);
    }

    #[Route('/api/transfers', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->checkCsrf($request);
        $this->limit($request, $this->uploads, 'upload');
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || count($request->files->all()) !== 1) {
            throw new HttpException(400, 'Choisissez un fichier de 20 Mo maximum.');
        }
        $hours = filter_var($request->request->get('hours'), FILTER_VALIDATE_INT);
        $password = $request->request->get('password', '');
        if ($hours === false || !is_string($password)) {
            throw new HttpException(400, 'Paramètres de transfert invalides.');
        }
        return $this->json($this->transfers->create($file, $hours, $password), 201);
    }

    #[Route('/api/transfers/{token}', methods: ['GET'])]
    public function status(string $token, Request $request): JsonResponse
    {
        $record = $this->transfers->owned($token, $request->headers->get('X-Owner-Token', ''));
        return $this->json($this->transfers->describe($record, $token));
    }

    #[Route('/api/transfers/{token}', methods: ['DELETE'])]
    public function delete(string $token, Request $request): Response
    {
        $this->checkCsrf($request);
        $this->transfers->delete($token, $request->headers->get('X-Owner-Token', ''));
        return new Response('', 204);
    }

    #[Route('/api/transfers/{token}/download', methods: ['POST'])]
    public function download(string $token, Request $request): Response
    {
        $this->checkCsrf($request);
        $this->limit($request, $this->downloads, $token);
        $password = $request->request->get('password', '');
        if (!is_string($password) || strlen($password) > 64) {
            throw new HttpException(400, 'Mot de passe invalide.');
        }
        $file = $this->transfers->download($token, $password);
        return new Response($file['contents'], 200, ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) strlen($file['contents']), 'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $file['filename'], 'download'), 'X-Download-Options' => 'noopen']);
    }

    #[Route('/api/health', methods: ['GET'])]
    public function health(FileStorage $storage): JsonResponse
    {
        return $this->json(['ready' => $storage->ready()], $storage->ready() ? 200 : 503);
    }

    private function checkCsrf(Request $request): void
    {
        if ($request->headers->get('Sec-Fetch-Site') === 'cross-site' || !$this->csrf->isTokenValid(new CsrfToken('transfer', $request->headers->get('X-CSRF-Token', '')))) {
            throw new HttpException(403, 'La session a expiré. Rechargez la page.');
        }
    }

    private function limit(Request $request, RateLimiterFactory $factory, string $scope): void
    {
        $key = hash_hmac('sha256', $scope . '|' . $request->getClientIp(), $this->appSecret);
        if (!$factory->create($key)->consume()->isAccepted()) {
            throw new HttpException(429, 'Trop de tentatives. Réessayez dans une minute.');
        }
    }
}
