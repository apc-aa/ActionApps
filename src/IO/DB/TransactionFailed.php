<?php

declare(strict_types=1);

namespace AA\IO\DB;

/** The database refused the commit of DB_AA::transaction(): nothing of the block is stored. A failure
 *  of the database, not of the block's own code - a caller may try the block again later. */
final class TransactionFailed extends \RuntimeException {}
