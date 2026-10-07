<?php

declare(strict_types=1);

namespace AA\Import;

/**
 * A source whose probe() of any of its streams with a versionField, asked with no version known,
 * gives the maximum that field has at the source now - the version an import of the whole stream
 * would record; '' when it cannot tell, and the stream stays unversioned. A baseline records it;
 * without this a baseline leaves such streams unversioned, so the next changed run reads all of them.
 */
interface FieldMaxProbeable extends Probeable {}
