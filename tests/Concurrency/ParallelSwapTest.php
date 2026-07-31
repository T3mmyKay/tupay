<?php

namespace Tests\Concurrency;

use App\Models\Wallet;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PragmaRX\Google2FA\Google2FA;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

#[Group('concurrency')]
class ParallelSwapTest extends TestCase
{
    public function test_only_one_of_ten_concurrent_swaps_can_spend_the_available_balance(): void
    {
        $baseUrl = getenv('CONCURRENCY_BASE_URL');
        if (! is_string($baseUrl) || $baseUrl === '') {
            self::markTestSkipped('CONCURRENCY_BASE_URL is required for the external HTTP stress test.');
        }

        $client = new Client([
            'base_uri' => rtrim($baseUrl, '/'),
            'http_errors' => false,
            'timeout' => 15,
        ]);

        $login = $client->post('/api/v1/login', [
            'json' => [
                'email' => 'candidate@tupay.test',
                'password' => 'password',
            ],
        ]);
        self::assertSame(200, $login->getStatusCode(), (string) $login->getBody());

        /** @var array{data: array{token: string, wallets: list<array{id: string, currency: string}>}} $loginData */
        $loginData = json_decode((string) $login->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $bearer = $loginData['data']['token'];
        $sourceWalletId = $this->walletId($loginData['data']['wallets'], 'NGN');
        $destinationWalletId = $this->walletId($loginData['data']['wallets'], 'CNY');
        $amount = 100_000_000;
        $totp = (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP');

        $actionPayload = [
            'action' => 'swap',
            'source_wallet_id' => $sourceWalletId,
            'destination_wallet_id' => $destinationWalletId,
            'amount_subunits' => $amount,
        ];

        /** @var list<string> $elevatedTokens */
        $elevatedTokens = [];
        for ($index = 0; $index < 10; $index++) {
            $challenge = $client->post('/api/v1/2fa/challenge', [
                'headers' => ['Authorization' => 'Bearer '.$bearer],
                'json' => [
                    'totp_code' => $totp,
                    'action_payload' => $actionPayload,
                ],
            ]);

            self::assertSame(200, $challenge->getStatusCode(), (string) $challenge->getBody());
            /** @var array{data: array{elevated_action_token: string}} $challengeData */
            $challengeData = json_decode((string) $challenge->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $elevatedTokens[] = $challengeData['data']['elevated_action_token'];
        }

        $body = json_encode([
            'source_wallet_id' => $sourceWalletId,
            'destination_wallet_id' => $destinationWalletId,
            'amount_subunits' => $amount,
        ], JSON_THROW_ON_ERROR);

        $requests = static function () use ($elevatedTokens, $bearer, $body): iterable {
            foreach ($elevatedTokens as $index => $token) {
                yield new Request('POST', '/api/v1/swap', [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$bearer,
                    'X-Elevated-Action-Token' => $token,
                    'Idempotency-Key' => sprintf('parallel-swap-%02d', $index),
                    'X-Request-ID' => sprintf('parallel-request-%02d', $index),
                ], $body);
            }
        };

        /** @var list<int> $statuses */
        $statuses = [];
        $pool = new Pool($client, $requests(), [
            'concurrency' => 10,
            'options' => ['http_errors' => false],
            'fulfilled' => static function (ResponseInterface $response) use (&$statuses): void {
                $statuses[] = $response->getStatusCode();
            },
            'rejected' => static function (): void {
                self::fail('A concurrent request failed without an HTTP response.');
            },
        ]);
        $pool->promise()->wait();

        self::assertCount(10, $statuses);
        self::assertSame(1, count(array_filter($statuses, static fn (int $status): bool => $status === 200)), json_encode($statuses));
        self::assertSame(9, count(array_filter($statuses, static fn (int $status): bool => in_array($status, [409, 422], true))), json_encode($statuses));

        $sourceWallet = Wallet::query()->findOrFail($sourceWalletId);
        $sourceBalance = (int) DB::table('ledger_entries')->where('wallet_id', $sourceWallet->getKey())->sum('amount_subunits');
        self::assertSame(0, $sourceBalance);
        self::assertSame(1, DB::table('swaps')->count());

        $negativeUserWallets = DB::table('wallets')
            ->select('wallets.id')
            ->leftJoin('ledger_entries', 'ledger_entries.wallet_id', '=', 'wallets.id')
            ->where('wallets.type', 'USER')
            ->groupBy('wallets.id')
            ->havingRaw('COALESCE(SUM(ledger_entries.amount_subunits), 0) < 0')
            ->get()
            ->count();
        self::assertSame(0, $negativeUserWallets);

        $unbalancedGroups = DB::table('ledger_entries')
            ->select('ledger_entries.ledger_transaction_id', 'ledger_entries.currency')
            ->join('ledger_transactions', 'ledger_transactions.id', '=', 'ledger_entries.ledger_transaction_id')
            ->where('ledger_transactions.status', 'COMPLETED')
            ->groupBy('ledger_entries.ledger_transaction_id', 'ledger_entries.currency')
            ->havingRaw('SUM(ledger_entries.amount_subunits) <> 0')
            ->get()
            ->count();
        self::assertSame(0, $unbalancedGroups);
    }

    /**
     * @param  list<array{id: string, currency: string}>  $wallets
     */
    private function walletId(array $wallets, string $currency): string
    {
        foreach ($wallets as $wallet) {
            if ($wallet['currency'] === $currency) {
                return $wallet['id'];
            }
        }

        self::fail('Missing '.$currency.' wallet in login response.');
    }
}
