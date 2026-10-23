<?php

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\CommerceManagement;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Standalone CLI harness: never loaded by public requests or ordinary SQLite tests.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
$driver = $db->getDriverName();
$database = $db->getDatabaseName();
if (! $app->environment('testing') || $app->configurationIsCached() || getenv('NOVINPAY_DISPOSABLE_COMMERCE') !== '1' || ! in_array($driver, ['mysql', 'mariadb'], true) || ! preg_match('/\Anovinpay_phase6c(?:_[a-z0-9_]+)?\z/', $database) || config('database.connections.'.$driver.'.host') !== '127.0.0.1' || (int) config('database.connections.'.$driver.'.port') <= 1024 || (int) config('database.connections.'.$driver.'.port') === 3306) {
    throw new RuntimeException('Refusing non-disposable concurrency database.');
}
$auditDirectory = realpath(getenv('NOVINPAY_COMMERCE_ARTIFACTS') ?: '');
if (! $auditDirectory || ! is_dir($auditDirectory) || str_starts_with(strtolower($auditDirectory.DIRECTORY_SEPARATOR), strtolower(base_path().DIRECTORY_SEPARATOR))) {
    throw new RuntimeException('An existing artifact directory outside the repository is required.');
}
if ($db->selectOne('select database() as name')->name !== $database) {
    throw new RuntimeException('Actual database target differs.');
}
function auditCheck(bool $ok, string $label): void
{
    if (! $ok) {
        throw new RuntimeException('Failed: '.$label);
    }
}
function auditCustomer(): Customer
{
    $c = new Customer;
    foreach (['public_id' => (string) Str::uuid(), 'name' => 'مشتری ساختگی انبار', 'contact_phone' => '09000000009', 'name_digest' => bin2hex(random_bytes(32)), 'phone_digest' => bin2hex(random_bytes(32)), 'identity_digest' => bin2hex(random_bytes(32)), 'is_active' => true] as $k => $v) {
        $c->$k = $v;
    }
    $c->save();

    return $c;
}
function auditCommand(User $actor, string $action, array $data, InventoryItem|SalesOrder|null $target = null): int
{
    return app(CommerceManagement::class)->execute($actor, $action, ['operation_key' => (string) Str::uuid()] + ($target ? ['version' => $target->fresh()->version] : []) + $data, $target);
}
function auditItem(User $actor, bool $serial = false): InventoryItem
{
    return InventoryItem::findOrFail(auditCommand($actor, 'item.create', ['sku' => 'QA-'.strtoupper(bin2hex(random_bytes(5))), 'name' => $serial ? 'کارتخوان سریالی ساختگی' : 'لوازم جانبی ساختگی', 'condition' => 'new', 'serialized' => $serial, 'low_stock_threshold' => 2, 'reason' => 'Synthetic fixture approval']));
}
function auditOrder(User $actor, Customer $customer, InventoryItem $item, int $quantity = 1): SalesOrder
{
    return SalesOrder::findOrFail(auditCommand($actor, 'order.save', ['customer' => $customer->public_id, 'lines' => [['item' => $item->public_id, 'quantity' => $quantity, 'unit_price' => '12345.67']], 'reason' => 'Synthetic internal agreement']));
}
