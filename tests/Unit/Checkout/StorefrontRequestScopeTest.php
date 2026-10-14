<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart\StorefrontRequestScope;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Woran die Pflichtprüfungen erkennen, dass sie in der Storefront laufen: am Route-Scope der
 * Hauptanfrage, nicht an der Sitzung.
 */
final class StorefrontRequestScopeTest extends TestCase
{
    public function testAStorefrontRouteIsStorefront(): void
    {
        self::assertTrue(StorefrontRequestScope::isStorefront($this->stack(['storefront'])));
    }

    public function testStoreApiAndAdminApiAreNot(): void
    {
        self::assertFalse(StorefrontRequestScope::isStorefront($this->stack(['store-api'])));
        self::assertFalse(StorefrontRequestScope::isStorefront($this->stack(['api'])));
    }

    /**
     * Was: Kein Scope gesetzt, oder gar keine Anfrage (Konsole, Warteschlange).
     * Warum: Im Zweifel keine Storefront; sonst sperrte eine Prüfung Bestellungen, die sich dort
     *        nicht nachbessern lassen.
     */
    public function testWithoutAScopeOrRequestItIsNot(): void
    {
        self::assertFalse(StorefrontRequestScope::isStorefront($this->stack(null)));
        self::assertFalse(StorefrontRequestScope::isStorefront(new RequestStack()));
    }

    /**
     * @param list<string>|null $scopes
     */
    private function stack(?array $scopes): RequestStack
    {
        $request = new Request();
        if ($scopes !== null) {
            $request->attributes->set(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, $scopes);
        }

        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }
}
