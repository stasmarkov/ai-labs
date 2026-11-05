<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ListingDetailService;
use App\Services\ListingPriceWatchService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;

final class ShowListingController extends Controller
{
    public const string SUBSCRIBER_COOKIE = 'mercureAuthorization';

    private const string COOKIE_PATH = '/.well-known/mercure';

    public function __invoke(int $id, Request $request, ListingDetailService $service, ListingPriceWatchService $watches): Response
    {
        // Read before the listing is loaded: any price change committed after this instant is published with a
        // later published_at_ms, so the page script applies it even when it arrives only through the hub's history.
        $renderedAtMs = (int) floor(microtime(true) * 1000);

        $listing = $service->find($id);
        $watch = $watches->watch($id, $request->query('watch') === 'private');

        $response = response()->view('listings.show', ['listing' => $listing, 'watch' => $watch, 'renderedAtMs' => $renderedAtMs]);

        if ($watch->subscriberToken !== null && $watch->subscriberTokenExpiresAt !== null) {
            // Max-Age comes out as exp - now, so the cookie dies with the token it carries.
            $response->headers->setCookie(new Cookie(
                self::SUBSCRIBER_COOKIE,
                $watch->subscriberToken,
                $watch->subscriberTokenExpiresAt,
                self::COOKIE_PATH,
                null,
                true,
                true,
                true,
                Cookie::SAMESITE_STRICT,
            ));
        } else {
            // A same-origin EventSource sends cookies whatever withCredentials says, so a leftover (stale, rotated or
            // another listing's) token would reach the hub on every public subscription and a bad one gets a 401.
            $response->headers->clearCookie(self::SUBSCRIBER_COOKIE, self::COOKIE_PATH, null, true, true, Cookie::SAMESITE_STRICT);
        }

        return $response;
    }
}
