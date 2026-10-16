<?php

declare(strict_types=1);

/**
 * The {command:...} expressions for the code editor (<aa-code>): completion of names and the
 * parameter hint. Templates are admin work, so logged-in users only.
 *
 * @package Api
 */

namespace AA\Api\Controller;

use AA\Api\JsonResponse;
use AA\Api\SameHostGate;
use AA\Stringexpand\Catalog;
use Closure;

class ExpressionsController
{
    use SameHostGate;

    private Closure $catalog;

    /** @param ?Closure $catalog tests hand in a fixed list; api/index.php uses Catalog::commands() */
    public function __construct(?Closure $catalog = null)
    {
        $this->catalog = $catalog ?? Catalog::commands(...);
    }

    public function list(): JsonResponse
    {
        if (!$this->sameHostRequest()) {
            return JsonResponse::error(401, 'Unauthorized');
        }
        if (!$this->isLoggedIn()) {
            return JsonResponse::error(401, 'Not logged in');
        }
        return JsonResponse::ok(['commands' => ($this->catalog)()]);
    }

    protected function isLoggedIn(): bool
    {
        $auth = $GLOBALS['auth'] ?? null;
        return is_object($auth) && method_exists($auth, 'is_user') && $auth->is_user();
    }
}
