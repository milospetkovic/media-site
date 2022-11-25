<?php

declare(strict_types=1);

namespace App\Controller;

use App\InformationCollection\Handler\Handler;
use Ibexa\Contracts\Core\Repository\Values\Content\Query;
use Ibexa\Contracts\Core\Repository\Values\Content\LocationQuery;
use Ibexa\Contracts\Core\Repository\Values\Content\Query\Criterion\ContentTypeIdentifier;
use Ibexa\Contracts\Core\Repository\Values\Content\Query\Criterion\LogicalAnd;
use Ibexa\Contracts\Core\Repository\Values\Content\Query\Criterion\Subtree;
use Ibexa\Contracts\Core\Repository\Values\Content\Query\SortClause\Location\Priority;
use Ibexa\Contracts\Core\Repository\Values\ValueObject;
use Netgen\Bundle\IbexaSiteApiBundle\Controller\Controller;
use Netgen\Bundle\IbexaSiteApiBundle\View\ContentView;
use Netgen\IbexaSiteApi\API\Values\Location;
use Netgen\InformationCollection\API\Events;
use Netgen\InformationCollection\API\Service\CaptchaService;
use Netgen\InformationCollection\API\Value\Event\InformationCollected;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InfoCollectorController extends Controller
{
    private RequestStack $requestStack;
    private CaptchaService $captchaService;
    private Handler $handler;
    private EventDispatcherInterface $eventDispatcher;
    private RouterInterface $router;
    private TranslatorInterface $translator;
    private LoggerInterface $logger;

    public function __construct(
        RequestStack $requestStack,
        CaptchaService $captchaService,
        Handler $handler,
        EventDispatcherInterface $eventDispatcher,
        RouterInterface $router,
        TranslatorInterface $translator,
        LoggerInterface $logger
    ) {
        $this->requestStack = $requestStack;
        $this->captchaService = $captchaService;
        $this->handler = $handler;
        $this->eventDispatcher = $eventDispatcher;
        $this->router = $router;
        $this->translator = $translator;
        $this->logger = $logger;
    }

    /**
     * @throws \Ibexa\Contracts\Core\Repository\Exceptions\NotFoundException
     * @throws \Ibexa\Contracts\Core\Repository\Exceptions\UnauthorizedException
     * @throws \Netgen\IbexaSiteApi\API\Exceptions\TranslationNotMatchedException
     */
    public function serveModal(Request $request, int $formLocationId, ?int $refererLocationId = null): Response
    {
        $location = $this->getSite()->getLoadService()->loadLocation($formLocationId);

        $response = new Response();
        $response->setPrivate();
        $response->setSharedMaxAge(0);

        return $this->render(
            '@ibexadesign/info_collection/modal.html.twig',
            [
                'content' => $location->content,
                'location' => $location,
                'view_type' => 'embed',
                'referer' => $this->getReferer($refererLocationId),
            ]
        );
    }

    /**
     * @throws \Ibexa\Contracts\Core\Repository\Exceptions\NotFoundException
     * @throws \Ibexa\Contracts\Core\Repository\Exceptions\UnauthorizedException
     * @throws \Netgen\IbexaSiteApi\API\Exceptions\TranslationNotMatchedException
     */
    public function externalHandle(int $formLocationId): Response
    {
        $location = $this->getSite()->getLoadService()->loadLocation($formLocationId);

        $response = new Response();
        $response->setPrivate();
        $response->setSharedMaxAge(0);

        return $this->render(
            '@ibexadesign/info_collection/embedded_view.html.twig',
            [
                'content' => $location->content,
                'location' => $location,
                'view_type' => 'embedded_form',
            ]
        );
    }

    public function internalHandle(ContentView $view): ContentView
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            throw new RuntimeException('Missing Request');
        }

        $location = $view->getSiteLocation();

        if ($location === null) {
            throw new RuntimeException('Missing Location context');
        }

        $refererLocationId = null;

        if ($view->hasParameter('refererLocationId')) {
            $refererLocationId = $view->getParameter('refererLocationId');
        }

        $view->addParameters(
            array_merge(
                $this->collectInformation($location, $request),
                [
                    'content' => $location->content,
                    'location' => $location,
                    'view' => $view,
                    'referer' => $this->getReferer($refererLocationId),
                ],
            ),
        );

        $view->setCacheEnabled(false);

        return $view;
    }

    private function getReferer(?int $refererLocationId = null): string
    {
        if ($refererLocationId !== null) {
            return $this->router->generate(
                'ibexa.url.alias',
                [
                    'locationId' => $refererLocationId,
                ],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

        }

        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            throw new RuntimeException('Missing Request');
        }

        if ($request->headers->has('referer')) {
            return $request->headers->get('referer');
        }

        return $request->getPathInfo();
    }

    private function collectInformation(Location $location, Request $request): array
    {
        $form = $this->handler->getForm(
            $location->innerLocation->getContent(),
            $location->innerLocation
        );

        $isCollected = false;
        $captcha = $this->captchaService->getCaptcha($location->innerLocation);

        $form->handleRequest($request);
        $formSubmitted = $form->isSubmitted();
        $validCaptcha = $captcha->isValid($request);

        /** @noinspection NotOptimalIfConditionsInspection */
        if ($formSubmitted && $form->isValid() && $validCaptcha) {
            if ($this->isHoneypotEmpty($form)) {
                $event = new InformationCollected($form->getData(), []);

                $this->eventDispatcher->dispatch($event, Events::INFORMATION_COLLECTED);
            }

            $isCollected = true;
        }

        if ($formSubmitted && !$form->isValid()) {
            $this->logger->critical((string) $form->getErrors(true, false));
        }

        if ($formSubmitted && !$validCaptcha) {
            $message = $this->translator->trans('ngsite.collected_info.error.captcha_failed');

            $this->logger->critical($message);

            $form->addError(new FormError($message));
        }

        return [
            'is_collected' => $isCollected,
            'form' => $form->createView(),
        ];
    }

    private function isHoneypotEmpty(FormInterface $form): bool
    {
        if (!$form->has('sender_middle_name')) {
            return true;
        }

        $honeypot = $form->get('sender_middle_name');

        return $honeypot->getData() === '';
    }
}
