<?php

declare(strict_types=1);

namespace AA\Import;

/** A source that lists what it offers, page by page - a whole catalog may not fit in one step */
interface Cataloged
{
    /** @param string $cursor '' for the first page, then CatalogPage::$next */
    public function catalog(Context $ctx, string $cursor): CatalogPage;
}
