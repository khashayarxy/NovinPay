<?php

require dirname(__DIR__).'/Concurrency/bootstrap.php';

use App\Models\FinancialDocument;
use App\Models\FinancialEntry;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\FinancialOperations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

Artisan::call('migrate:fresh', ['--force' => true]);
$one = User::factory()->superAdmin()->create();
$two = User::factory()->staff(['finance.view', 'finance.details', 'finance.charge', 'finance.receive', 'finance.correct', 'finance.expenses', 'orders.view'])->create();
$customer = auditCustomer();
function financeData(array $extra = []): array
{
    return $extra + ['operation_key' => (string) Str::uuid(), 'password' => 'password', 'reason' => 'Synthetic finance concurrency evidence'];
}
function financeOrder(): SalesOrder
{
    $order = new SalesOrder;
    foreach (['public_id' => (string) Str::uuid(), 'customer_id' => $GLOBALS['customer']->id, 'status' => 'fulfilled', 'currency' => 'TOMAN', 'total' => '100.00', 'created_by' => $GLOBALS['one']->id, 'version' => 1] as $key => $value) {
        $order->$key = $value;
    }
    $order->save();

    return $order;
}
function financeDocument(): FinancialDocument
{
    $order = financeOrder();
    app(FinancialOperations::class)->execute($GLOBALS['one'], 'issue', financeData(['source_type' => 'order', 'source' => $order->public_id, 'source_version' => 1]));

    return FinancialDocument::where('sales_order_id', $order->id)->firstOrFail();
}
function financeCommand(User $actor, string $action, array $data): array
{
    return ['actor' => $actor->id, 'action' => $action, 'data' => financeData($data)];
}
function financeRace(string $label, array $first, array $second, array $expected): array
{
    $base = $GLOBALS['auditDirectory'].'/finance-'.$label.'-'.bin2hex(random_bytes(3));
    $first += ['hold' => true, 'ready' => $base.'.ready', 'output' => $base.'-first.json'];
    $second += ['hold' => false, 'ready' => $base.'.ready', 'output' => $base.'-second.json'];
    file_put_contents($base.'-first-command.json', json_encode($first));
    file_put_contents($base.'-second-command.json', json_encode($second));
    $pipes = [];
    $processOne = proc_open([PHP_BINARY, __DIR__.'/worker.php', $base.'-first-command.json'], [0 => ['pipe', 'r'], 1 => ['file', $base.'-one.log', 'w'], 2 => ['file', $base.'-one-error.log', 'w']], $pipes);
    $deadline = microtime(true) + 20;
    while (! is_file($base.'.ready') && microtime(true) < $deadline) {
        usleep(10000);
    }
    auditCheck(is_file($base.'.ready'), 'first finance worker reached protected write');
    $processTwo = proc_open([PHP_BINARY, __DIR__.'/worker.php', $base.'-second-command.json'], [0 => ['pipe', 'r'], 1 => ['file', $base.'-two.log', 'w'], 2 => ['file', $base.'-two-error.log', 'w']], $pipes);
    auditCheck(proc_close($processOne) === 0 && proc_close($processTwo) === 0, 'finance workers completed');
    $a = json_decode(file_get_contents($first['output']), true, flags: JSON_THROW_ON_ERROR);
    $b = json_decode(file_get_contents($second['output']), true, flags: JSON_THROW_ON_ERROR);
    auditCheck([$a['status'], $b['status']] === $expected, $label.' expected outcomes');
    auditCheck($a['pid'] !== $b['pid'] && $a['connection_id'] !== $b['connection_id'] && $b['elapsed_ms'] > 500, 'distinct competing finance connections actually waited');

    return ['scenario' => $label, 'first' => $a, 'second' => $b];
}
$reports = [];
$document = financeDocument();
$data = ['document' => $document->public_id, 'version' => $document->version, 'amount' => '80.00', 'method' => 'transfer', 'reference' => 'PAY-RACE-A'];
$reports[] = financeRace('overpayment', financeCommand($one, 'receipt', $data), financeCommand($two, 'receipt', array_replace($data, ['reference' => 'PAY-RACE-B'])), [200, 409]);
auditCheck($document->fresh()->paid_minor === 8000, 'no concurrent overpayment');
$document = financeDocument();
$command = financeCommand($one, 'receipt', ['document' => $document->public_id, 'version' => $document->version, 'amount' => '10.00', 'method' => 'cash', 'reference' => 'PAY-RACE-IDEMPOTENT']);
$reports[] = financeRace('same-operation', $command, $command, [200, 200]);
auditCheck($document->fresh()->paid_minor === 1000, 'one idempotent receipt');
$a = financeDocument();
$b = financeDocument();
$reports[] = financeRace('duplicate-reference', financeCommand($one, 'receipt', ['document' => $a->public_id, 'version' => $a->version, 'amount' => '7.00', 'method' => 'transfer', 'reference' => 'PAY-RACE-DUPLICATE']), financeCommand($two, 'receipt', ['document' => $b->public_id, 'version' => $b->version, 'amount' => '7.00', 'method' => 'terminal', 'reference' => 'pay-race-duplicate']), [200, 409]);
auditCheck($a->fresh()->paid_minor === 700 && $b->fresh()->paid_minor === 0, 'global duplicate receipt prevented');
$receipt = FinancialEntry::where('financial_document_id', $document->id)->where('kind', 'receipt')->firstOrFail();
$data = ['entry' => $receipt->public_id, 'version' => $document->fresh()->version];
$reports[] = financeRace('double-reversal', financeCommand($one, 'reverse', $data), financeCommand($two, 'reverse', $data), [200, 409]);
auditCheck($document->fresh()->paid_minor === 0, 'only one compensating reversal');
$document = financeDocument();
$reports[] = financeRace('credit-versus-receipt', financeCommand($one, 'credit', ['document' => $document->public_id, 'version' => $document->version, 'amount' => '90.00']), financeCommand($two, 'receipt', ['document' => $document->public_id, 'version' => $document->version, 'amount' => '80.00', 'method' => 'cash', 'reference' => 'PAY-RACE-CREDIT']), [200, 409]);
auditCheck($document->fresh()->charged_minor === 1000 && $document->fresh()->paid_minor === 0, 'adjusted receivable preserved');
$order = financeOrder();
$data = ['source_type' => 'order', 'source' => $order->public_id, 'source_version' => 1];
$reports[] = financeRace('duplicate-document', financeCommand($one, 'issue', $data), financeCommand($two, 'issue', $data), [200, 409]);
auditCheck(FinancialDocument::where('sales_order_id', $order->id)->count() === 1, 'one issued source document');
foreach (FinancialDocument::cursor() as $document) {
    app(FinancialOperations::class)->reconcile($document);
}
file_put_contents($auditDirectory.'/finance-concurrency-mysql.json', json_encode(['mysql' => DB::selectOne('SELECT VERSION() AS v')->v, 'passed' => count($reports), 'scenarios' => $reports], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Passed '.count($reports).' actual competing financial-connection scenarios'.PHP_EOL;
