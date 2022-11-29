<?php

declare(strict_types=1);

namespace App\InformationCollection\Handler;

use App\InformationCollection\ContentForms\InformationCollectionMapper;
use eZ\Publish\Core\MVC\ConfigResolverInterface;
use Ibexa\Contracts\Core\Repository\ContentTypeService;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\Core\Repository\Values\Content\Location;
use Netgen\Bundle\InformationCollectionBundle\Ibexa\ContentForms\InformationCollectionType;
use Netgen\InformationCollection\API\Events;
use Netgen\InformationCollection\API\Value\Event\InformationCollected;
use Netgen\InformationCollection\API\Value\InformationCollectionStruct;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class Handler
{
    private FormFactoryInterface $formFactory;

    private ContentTypeService $contentTypeService;

    private EventDispatcherInterface $eventDispatcher;

    private $languages;

    public function __construct(
        FormFactoryInterface $formFactory,
        ContentTypeService $contentTypeService,
        EventDispatcherInterface $eventDispatcher,
        ConfigResolverInterface $configResolver
    ) {
        $this->formFactory = $formFactory;
        $this->contentTypeService = $contentTypeService;
        $this->eventDispatcher = $eventDispatcher;
        $this->languages = $configResolver->getParameter('languages');
    }

    public function getForm(Content $content, Location $location): FormInterface
    {
        $contentType = $this->contentTypeService->loadContentType($content->contentInfo->contentTypeId);

        $informationCollectionMapper = new InformationCollectionMapper();

        $languageCode = null;
        $availableLanguages = $content->versionInfo->languageCodes;
        foreach ($this->languages as $language) {
            if (in_array($language, $availableLanguages)) {
                $languageCode = $language;
                break;
            }
        }

        $data = $informationCollectionMapper->mapToFormData($content, $location, $contentType, $languageCode);

        return $this->formFactory->create(InformationCollectionType::class, $data, [
            'languageCode' => $content->contentInfo->mainLanguageCode,
            'mainLanguageCode' => $content->contentInfo->mainLanguageCode,
        ]);
    }

    public function handle(InformationCollectionStruct $struct, array $options): void
    {
        $event = new InformationCollected($struct, $options);

        $this->eventDispatcher->dispatch($event, Events::INFORMATION_COLLECTED);
    }
}