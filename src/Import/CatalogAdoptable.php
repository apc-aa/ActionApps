<?php

declare(strict_types=1);

namespace AA\Import;

/** A source that can turn a catalog entry into a new stream */
interface CatalogAdoptable
{
    /**
     * Called only after the caller checked the user's rights to adoptSlice(); writes the item only
     * through storeItem(), and only into adoptSlice() - no other slice's rights were checked.
     * @return string id of the item defining the stream; an existing one is only updated
     */
    public function adopt(CatalogEntry $e, Context $ctx): string;

    /** the slice adopt() writes to - its permissions are checked before the call */
    public function adoptSlice(): string;
}
