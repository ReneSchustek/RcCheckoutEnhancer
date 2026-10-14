<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Storefront\Controller;

use Ruhrcoder\RcCheckoutEnhancer\Checkout\CheckoutLayout;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\OnePageSelectionLoader;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liefert nach einem Wechsel von Versand- oder Zahlart nur den Ausschnitt des einseitigen
 * Checkouts neu.
 *
 * Die Formulare schicken an die Kontext-Route des Kerns und lassen sich hierher weiterleiten.
 * Das Ajax-Verfahren des Kerns setzt die Antwort als Ganzes in den Container; käme eine ganze
 * Seite zurück, stünde sie mitten im Adressformular.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class OnePageSelectionController extends StorefrontController
{
    public const TEMPLATE = '@RcCheckoutEnhancer/storefront/component/rc-checkout/one-page-selection.html.twig';

    public function __construct(
        private readonly OnePageSelectionLoader $loader,
        private readonly ConfigService $configService,
    ) {
    }

    #[Route(
        path: '/rc-checkout/one-page/selection',
        name: 'frontend.rc-checkout.one-page.selection',
        defaults: ['XmlHttpRequest' => true],
        methods: ['GET'],
    )]
    public function selection(Request $request, SalesChannelContext $context): Response
    {
        // Wie die übrigen Endpunkte: Ist die Funktion aus, gibt es die Route nicht. Ein Aufruf
        // schaltete sonst die Versandart um, ohne dass eine Seite ihn braucht.
        if (!CheckoutLayout::mayShowOnePage($this->configService->getCheckoutLayout($context->getSalesChannelId()))) {
            throw new NotFoundHttpException();
        }

        return $this->renderStorefront(self::TEMPLATE, [
            'rcOnePageSelection' => $this->loader->load($request, $context),
        ]);
    }
}
