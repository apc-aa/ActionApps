<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\IO\DB\DB_AA;
use AA\Isds\Account;
use AA\Isds\Client;
use AA\Isds\DatovkaConf;
use AA\Isds\Recipients;
use AA\Isds\Schema;
use AA\Isds\SendItems;
use AA\Isds\SendQueue;
use AA\Isds\Setup;
use AA\Isds\SliceRules;
use AA\Upload\UploadDir;
use ItemContent;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * SendQueue over what TestSendRun gives a run - its clock and records, datovka_conf.php, ISDS, the
 * items in memory and memory_limit -, the campaign item as MemorySendItems stores it, the slices of
 * FakeSliceRules, the territory of FakeRecipients and an upload directory of the test
 */
final class TestSendQueue extends SendQueue
{
    public const UPLOAD_URL = 'https://example.org/img_upload/abc';
    /** the short id of the campaign item */
    public const SHORT = '4711';

    /** the slice the campaign item lies in, and whether it is active */
    public string $slice = Sending::CAMPAIGN;
    public bool $active = true;
    /** @var list<string> the URLs its attachment field holds */
    public array $files = [];
    /** another request holds the lock of the campaign */
    public bool $lockedByOther = false;
    /** @var list<string> each lock and unlock, and whether a transaction was open then */
    public array $lockLog = [];
    /** @var list<array{string, string, string}> campaign, user and run of each log row */
    public array $logged = [];
    /** ISDS for a login with a certificate, which the HTTP client of AA sends; TestSendRun::$http answers the others */
    public ?HttpClientInterface $transport = null;
    /** @var ?\Closure(): void runs when the letter's uploads are asked for - past the checks of the campaign, before the transaction */
    public ?\Closure $beforeLetter = null;
    /** memory_get_usage(true) when memory_limit was asked for - right after the need was counted */
    public int $usageAtCheck = 0;

    public function __construct(public FakeSliceRules $rules, public Recipients $resolver, private string $uploadDir) {}

    protected function runClass(): string
    {
        return TestSendRun::class;
    }

    protected function conf(): DatovkaConf
    {
        return TestSendRun::$conf ?? DatovkaConf::fromArray([]);
    }

    protected function rules(): SliceRules
    {
        return $this->rules;
    }

    protected function resolver(): Recipients
    {
        return $this->resolver;
    }

    protected function client(Account $account): Client
    {
        if (!is_null($account->certificate)) {
            return new Client($account, null, $this->transport ?? throw new \LogicException('This test must not call ISDS'), new MemoryLatch());
        }
        return new Client($account, TestSendRun::$http ?? throw new \LogicException('This test must not call ISDS'), null, new MemoryLatch());
    }

    // the fields MemorySendItems stores, the body as HTML, and what an item has besides
    protected function campaign(string $id): ?ItemContent
    {
        $item = TestSendRun::$items?->stored[$id] ?? null;
        if (is_null($item)) {
            return null;
        }
        $content = [
            'unpacked_id.....' => [['value' => $id]],
            'u_slice_id......' => [['value' => $this->slice]],
            'short_id........' => [['value' => self::SHORT]],
            'status_code.....' => [['value' => $this->active ? 1 : 3]],
            'publish_date....' => [['value' => 1]],
            'expiry_date.....' => [['value' => 4_000_000_000]],
        ];
        foreach ($item['fields'] as $field => $value) {
            $content[$field] = [['value' => $value, 'flag' => ($field === Schema::CAMPAIGN_BODY) ? FLAG_HTML : 0]];
        }
        $content[Schema::CAMPAIGN_FILES] = array_map(static fn(string $url): array => ['value' => $url, 'flag' => 0], $this->files);
        return new ItemContent($content);
    }

    protected function uploads(string $slice): UploadDir
    {
        if ($this->beforeLetter) {
            ($this->beforeLetter)();
        }
        return new UploadDir(self::UPLOAD_URL, $this->uploadDir);
    }

    protected function items(Setup $setup, string $campaign): SendItems
    {
        return TestSendRun::$items ?? throw new \LogicException('No items');
    }

    protected function lock(string $campaign): bool
    {
        $this->lockLog[] = "lock $campaign" . (DB_AA::inTransaction() ? ' in a transaction' : '');
        return !$this->lockedByOther;
    }

    protected function unlock(string $campaign): void
    {
        $this->lockLog[] = "unlock $campaign" . (DB_AA::inTransaction() ? ' in a transaction' : '');
    }

    protected function memoryLimit(): int
    {
        $this->usageAtCheck = memory_get_usage(true);
        return TestSendRun::$memoryLimit;
    }

    protected function raiseMemoryLimit(int $bytes): ?string
    {
        TestSendRun::$raisedTo = $bytes;
        return TestSendRun::$mayRaise ? (string) TestSendRun::$memoryLimit : null;
    }

    protected function log(string $campaign, string $uid, string $runId): void
    {
        $this->logged[] = [$campaign, $uid, $runId];
    }
}
