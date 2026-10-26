<?php

declare(strict_types=1);

const ORDER_STATUSES = ['paid', 'pending'];
const SAMPLE_JSON = <<<'JSON'
[
  {"id": 101, "customer": "Иван", "amount": 1500, "status": "paid"},
  {"id": 102, "customer": "Мария", "amount": 2500, "status": "pending"},
  {"id": 103, "customer": "Пётр", "amount": 3000, "status": "paid"},
  {"id": 104, "customer": "Анна", "amount": 0, "status": "paid"}
]
JSON;

function readInput(?string $argument): string
{
    if ($argument === null) {
        return SAMPLE_JSON;
    }
    if ($argument === '-') {
        $input = stream_get_contents(STDIN);
        if ($input === false) {
            throw new RuntimeException('Не удалось прочитать stdin.');
        }
        return $input;
    }

    if (!is_file($argument) || !is_readable($argument)) {
        throw new RuntimeException("Файл '{$argument}' не найден или недоступен для чтения.");
    }

    $input = file_get_contents($argument);
    if ($input === false) {
        throw new RuntimeException("Не удалось прочитать файл '{$argument}'.");
    }
    return $input;
}

/** @return array{orders: list<array{id: int, customer: string, amount: int|float, status: string}>, errors: list<string>}
 * @throws JsonException
 */
function parseOrdersJson(string $json): array
{
    $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new InvalidArgumentException('Корневое значение JSON должно быть массивом заказов.');

    $orders = $errors = [];
    foreach ($data as $index => $item) {
        $position = $index + 1;
        if (!$item instanceof stdClass) {
            $errors[] = "Запись №{$position}: ожидался объект заказа; запись пропущена.";
            continue;
        }

        $v = get_object_vars($item);
        $issues = [];
        if (!isset($v['id']) || !is_int($v['id'])) {
            $issues[] = "поле 'id' должно быть целым числом";
        }
        if (!isset($v['customer']) || !is_string($v['customer']) || trim($v['customer']) === '') {
            $issues[] = "поле 'customer' должно быть непустой строкой";
        }
        if (!isset($v['amount']) || (!is_int($v['amount']) && !is_float($v['amount']))
            || !is_finite((float)($v['amount'] ?? 0)) || $v['amount'] < 0) {
            $issues[] = "поле 'amount' должно быть конечным числом не меньше нуля";
        }
        if (!isset($v['status']) || !is_string($v['status'])) {
            $issues[] = "поле 'status' должно быть строкой";
        } elseif (!in_array($v['status'], ORDER_STATUSES, true)) {
            $issues[] = "неизвестный статус '{$v['status']}'";
        }

        if ($issues !== []) {
            $errors[] = "Запись №{$position}: " . implode('; ', $issues) . '; запись пропущена.';
            continue;
        }

        $orders[] = [
            'id' => $v['id'], 'customer' => $v['customer'],
            'amount' => $v['amount'], 'status' => $v['status'],
        ];
    }

    return ['orders' => $orders, 'errors' => $errors];
}

/** @param list<array{id: int, customer: string, amount: int|float, status: string}> $orders */
function summarizeOrders(array $orders): array
{
    $summary = [
        'paidCount' => 0, 'paidTotal' => 0, 'mostExpensivePaidOrder' => null,
        'ordersByStatus' => array_fill_keys(ORDER_STATUSES, 0),
    ];

    foreach ($orders as $order) {
        $summary['ordersByStatus'][$order['status']]++;
        if ($order['status'] !== 'paid') {
            continue;
        }

        $summary['paidCount']++;
        $summary['paidTotal'] += $order['amount'];
        if ($summary['mostExpensivePaidOrder'] === null
            || $order['amount'] > $summary['mostExpensivePaidOrder']['amount']) {
            $summary['mostExpensivePaidOrder'] = $order;
        }
    }

    return $summary;
}

function printUsage($stream): void
{
    fwrite($stream, "Использование: php orders.php [путь-к-json | - | --help]" . PHP_EOL);
    fwrite($stream, "Без аргумента используется встроенный пример; '-' читает JSON из stdin." . PHP_EOL);
}

function printSummary(array $summary): void
{
    fwrite(STDOUT, "Оплаченных заказов: {$summary['paidCount']}" . PHP_EOL);
    fwrite(STDOUT, 'Сумма оплаченных заказов: ' . $summary['paidTotal'] . PHP_EOL);
    $order = $summary['mostExpensivePaidOrder'];
    fwrite(STDOUT, $order === null
        ? "Самый дорогой оплаченный заказ: нет" . PHP_EOL
        : 'Самый дорогой оплаченный заказ: № ' . $order['id'] . ' на сумму ' . $order['amount'] . PHP_EOL);
    fwrite(STDOUT, 'Заказы по статусам:' . PHP_EOL);
    foreach ($summary['ordersByStatus'] as $status => $count) {
        fwrite(STDOUT, "  {$status}: {$count}" . PHP_EOL);
    }
}

function main(array $argv): int
{
    if (count($argv) > 2) {
        fwrite(STDERR, "Ошибка: передан лишний аргумент." . PHP_EOL . PHP_EOL);
        printUsage(STDERR);
        return 2;
    }
    if (in_array($argv[1] ?? null, ['--help', '-h'], true)) {
        printUsage(STDOUT);
        return 0;
    }

    try {
        $parsed = parseOrdersJson(readInput($argv[1] ?? null));
    } catch (JsonException|InvalidArgumentException|RuntimeException $exception) {
        $message = $exception instanceof JsonException
            ? 'Не удалось декодировать JSON: ' . $exception->getMessage()
            : $exception->getMessage();
        fwrite(STDERR, 'Ошибка: ' . $message . PHP_EOL);
        return 1;
    }

    foreach ($parsed['errors'] as $error) {
        fwrite(STDERR, 'Ошибка: ' . $error . PHP_EOL);
    }
    printSummary(summarizeOrders($parsed['orders']));
    return $parsed['errors'] === [] ? 0 : 2;
}

exit(main($argv));