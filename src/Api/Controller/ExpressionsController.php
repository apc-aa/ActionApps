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
use AA\Api\LoggedInGate;
use AA\Api\SameHostGate;
use AA\Stringexpand\Catalog;

class ExpressionsController
{
    use LoggedInGate;
    use SameHostGate;

    public function list(): JsonResponse
    {
        if (!$this->sameHostRequest()) {
            return JsonResponse::error(401, 'Unauthorized');
        }
        if (!$this->isLoggedIn()) {
            return JsonResponse::error(401, 'Not logged in');
        }
        return JsonResponse::ok(['commands' => $this->catalog()]);
    }

    /** @return array<string, array<string, mixed>> */
    protected function catalog(): array
    {
        return Catalog::commands();
    }
}
