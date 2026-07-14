<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Pinning-Tests gegen die Confirm-Seiten-Integration. Hintergrund: das Plugin
 * ueberschrieb urspruenglich `page_checkout_confirm_container` — einen Block, den
 * der Storefront-Core in keiner unterstuetzten Version kennt — wodurch Mini-Cart
 * und Order-Summary auf der Bestaetigungsseite still nicht rendern. Diese Tests
 * halten die Korrektur fest, bis ein voller Render-Smoke-Test (Brief 013) steht.
 */
final class ConfirmTemplateContractTest extends TestCase
{
    private string $template;

    protected function setUp(): void
    {
        $path = \dirname(__DIR__, 3) . '/src/Resources/views/storefront/page/checkout/confirm/index.html.twig';
        $this->template = (string) file_get_contents($path);
    }

    public function testOverridesAnExistingCoreBlock(): void
    {
        self::assertStringContainsString('{% block page_checkout_confirm %}', $this->template);
        self::assertStringNotContainsString('page_checkout_confirm_container', $this->template);
    }

    public function testCallsParentSoCoreContentSurvives(): void
    {
        self::assertStringContainsString('{{ parent() }}', $this->template);
    }

    public function testIncludesSidebarComponents(): void
    {
        self::assertStringContainsString('mini-cart.html.twig', $this->template);
        self::assertStringContainsString('order-summary.html.twig', $this->template);
    }

    public function testSidebarVisibilityHonoursBothFlags(): void
    {
        self::assertStringContainsString('miniCartEnabled or page.extensions.rcCheckoutEnhancer.orderSummaryEnabled', $this->template);
    }
}
