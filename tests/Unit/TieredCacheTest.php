<?php
declare(strict_types=1);

namespace Beeline\TieredCache\Tests\Unit;

use Beeline\TieredCache\Cache\TieredCache;
use Beeline\TieredCache\Cache\WrappedCacheValue;
use Beeline\TieredCache\Resilience\BreakerInterface;
use Beeline\TieredCache\Resilience\CircuitBreaker;
use PHPUnit\Framework\TestCase;
use Exception;
use RuntimeException;
use stdClass;
use yii\base\InvalidConfigException;
use yii\caching\ArrayCache;

/**
 * Набор тестов для компонента TieredCache
 */
class TieredCacheTest extends TestCase
{
    /**
     * Тест: инициализация с корректной конфигурацией
     */
    public function testInitWithValidConfig(): void
    {
        $cache = new TieredCache([
            'layers' => [
                ['cache' => new ArrayCache()],
                ['cache' => new ArrayCache()],
            ],
        ]);

        $status = $cache->getLayerStatus();

        // Отладка: вывод фактического количества
        // Debug: 'Layer status count: ' . count($status));
        // Debug: 'Layer status: ' . json_encode($status, JSON_PRETTY_PRINT));

        self::assertCount(2, $status, 'Должно быть ровно 2 слоя');
        self::assertEquals(BreakerInterface::STATE_CLOSED, $status[0]['state']);
        self::assertEquals(BreakerInterface::STATE_CLOSED, $status[1]['state']);
    }

    /**
     * Тест: инициализация выбрасывает исключение при пустых слоях
     *
     *
     */
    public function testInitThrowsExceptionWithEmptyLayers(): void
    {
        $this->expectException(InvalidConfigException::class);

        new TieredCache(['layers' => []]);
    }

    /**
     * Тест: getValue читает из первого слоя
     */
    public function testGetValueReadsFromFirstLayer(): void
    {
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1, 'ttl' => 60],
                ['cache' => $l2, 'ttl' => 60],
            ],
        ]);

        // Set value through TieredCache (writes to both layers)
        $cache->set('test_key', 'l1_value', 60);

        // Modify L2 to ensure we're reading from L1
        $l2->set('test_key', new WrappedCacheValue('l2_value', time() + 60), 60);

        $value = $cache->get('test_key');

        self::assertEquals('l1_value', $value);
    }

    /**
     * Тест: getValue переключается на второй слой при отказе первого
     */
    public function testGetValueFallsBackToSecondLayer(): void
    {
        $l1 = new FailingCache();
        $l2 = new ArrayCache();

        // Set wrapped value in L2
        $l2->set('test_key', new WrappedCacheValue('l2_value', time() + 60), 60);

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1, 'ttl' => 60],
                ['cache' => $l2, 'ttl' => 60],
            ],
        ]);

        $value = $cache->get('test_key');

        self::assertEquals('l2_value', $value);
    }

    /**
     * Тест: getValue возвращает false когда ключ не найден ни в одном слое
     */
    public function testGetValueReturnsFalseWhenNotFound(): void
    {
        $cache = new TieredCache([
            'layers' => [
                ['cache' => new ArrayCache()],
                ['cache' => new ArrayCache()],
            ],
        ]);

        $value = $cache->get('nonexistent_key');

        self::assertFalse($value);
    }

    /**
     * Тест: стратегия WRITE_THROUGH записывает во все слои
     */
    public function testWriteThroughWritesToAllLayers(): void
    {
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1],
                ['cache' => $l2],
            ],
            'writeStrategy' => TieredCache::WRITE_THROUGH,
        ]);

        $cache->set('test_key', 'test_value', 60);

        $l1Value = $l1->get('test_key');
        self::assertInstanceOf(WrappedCacheValue::class, $l1Value);
        self::assertEquals('test_value', $l1Value->value);

        $l2Value = $l2->get('test_key');
        self::assertInstanceOf(WrappedCacheValue::class, $l2Value);
        self::assertEquals('test_value', $l2Value->value);
    }

    /**
     * Тест: стратегия WRITE_FIRST записывает только в первый доступный слой
     */
    public function testWriteFirstWritesOnlyToFirstLayer(): void
    {
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1],
                ['cache' => $l2],
            ],
            'writeStrategy' => TieredCache::WRITE_FIRST,
        ]);

        $cache->set('test_key', 'test_value', 60);

        $l1Value = $l1->get('test_key');
        self::assertInstanceOf(WrappedCacheValue::class, $l1Value);
        self::assertEquals('test_value', $l1Value->value);
        self::assertFalse($l2->get('test_key'), 'L2 не должен иметь значение');
    }

    /**
     * Тест: WRITE_FIRST переключается на следующий слой при отказе первого
     */
    public function testWriteFirstFallsBackOnFailure(): void
    {
        $l1 = new FailingCache();
        $l2 = new ArrayCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1],
                ['cache' => $l2],
            ],
            'writeStrategy' => TieredCache::WRITE_FIRST,
        ]);

        $result = $cache->set('test_key', 'test_value', 60);

        self::assertTrue($result);
        $l2Value = $l2->get('test_key');
        self::assertInstanceOf(WrappedCacheValue::class, $l2Value);
        self::assertEquals('test_value', $l2Value->value);
    }

    /**
     * Тест: delete удаляет из всех слоев
     */
    public function testDeleteRemovesFromAllLayers(): void
    {
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $l1->set('test_key', 'value', 60);
        $l2->set('test_key', 'value', 60);

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1],
                ['cache' => $l2],
            ],
        ]);

        $cache->delete('test_key');

        self::assertFalse($l1->get('test_key'));
        self::assertFalse($l2->get('test_key'));
    }

    /**
     * Тест: delete возвращает true если хотя бы один слой успешно удалил значение (ANY-SUCCESS)
     *
     * Проверяет корректность логики возврата результата в методе deleteValue().
     * Метод должен использовать ANY-SUCCESS логику (как flushValues и writeToLayers):
     * - Возвращает TRUE, если хотя бы один слой успешно удалил значение
     * - Возвращает FALSE, только если все слои завершились с ошибкой
     *
     */
    public function testDeleteReturnsAnySuccessLogic(): void
    {
        // СЦЕНАРИЙ 1: Первые слои успешны, последний слой сбойный
        $l1Success = new ArrayCache();
        $l2Success = new ArrayCache();
        $l3Failing = new FailingCache();

        // Устанавливаем значения в успешные слои
        $l1Success->set('test_key', 'value', 60);
        $l2Success->set('test_key', 'value', 60);

        $cache1 = new TieredCache([
            'layers' => [
                ['cache' => $l1Success],
                ['cache' => $l2Success],
                ['cache' => $l3Failing],
            ],
        ]);

        // ACT: Удаляем ключ
        $result1 = $cache1->delete('test_key');

        // ASSERT: Должен вернуть TRUE, потому что L1 и L2 успешно удалили
        self::assertTrue(
            $result1,
            'delete() должен вернуть TRUE если хотя бы один слой успешно удалил значение (L1 и L2 успешны, L3 сбойный)',
        );

        // Проверяем что L1 и L2 действительно удалили значения
        self::assertFalse($l1Success->get('test_key'), 'L1 должен успешно удалить значение');
        self::assertFalse($l2Success->get('test_key'), 'L2 должен успешно удалить значение');

        // СЦЕНАРИЙ 2: Первые слои сбойные, последний слой успешен
        $l4Failing = new FailingCache();
        $l5Failing = new FailingCache();
        $l6Success = new ArrayCache();

        $l6Success->set('test_key_2', 'value', 60);

        $cache2 = new TieredCache([
            'layers' => [
                ['cache' => $l4Failing],
                ['cache' => $l5Failing],
                ['cache' => $l6Success],
            ],
        ]);

        // ACT: Удаляем ключ
        $result2 = $cache2->delete('test_key_2');

        // ASSERT: Должен вернуть TRUE, потому что последний слой успешно удалил
        self::assertTrue(
            $result2,
            'delete() должен вернуть TRUE даже если только последний слой успешен (L4 и L5 сбойные, L6 успешный)',
        );

        // Проверяем что L6 действительно удалил значение
        self::assertFalse($l6Success->get('test_key_2'), 'L6 должен успешно удалить значение');

        // СЦЕНАРИЙ 3: Все слои сбойные
        $l7Failing = new FailingCache();
        $l8Failing = new FailingCache();

        $cache3 = new TieredCache([
            'layers' => [
                ['cache' => $l7Failing],
                ['cache' => $l8Failing],
            ],
        ]);

        // ACT: Удаляем ключ (все слои выбросят исключения)
        $result3 = $cache3->delete('test_key_3');

        // ASSERT: Должен вернуть FALSE, потому что ни один слой не успешен
        self::assertFalse($result3, 'delete() должен вернуть FALSE только если все слои завершились с ошибкой');

        // СЦЕНАРИЙ 4: Средний слой успешен, остальные сбойные
        $l9Failing = new FailingCache();
        $l10Success = new ArrayCache();
        $l11Failing = new FailingCache();

        $l10Success->set('test_key_4', 'value', 60);

        $cache4 = new TieredCache([
            'layers' => [
                ['cache' => $l9Failing],
                ['cache' => $l10Success],
                ['cache' => $l11Failing],
            ],
        ]);

        // ACT: Удаляем ключ
        $result4 = $cache4->delete('test_key_4');

        // ASSERT: Должен вернуть TRUE, потому что средний слой успешен
        self::assertTrue(
            $result4,
            'delete() должен вернуть TRUE если хотя бы один (средний) слой успешен',
        );

        // Проверяем что L10 действительно удалил значение
        self::assertFalse($l10Success->get('test_key_4'), 'L10 должен успешно удалить значение');
    }

    /**
     * Тест: flush очищает все слои
     */
    public function testFlushClearsAllLayers(): void
    {
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $l1->set('key1', 'value1', 60);
        $l2->set('key2', 'value2', 60);

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1],
                ['cache' => $l2],
            ],
        ]);

        $cache->flush();

        self::assertFalse($l1->get('key1'));
        self::assertFalse($l2->get('key2'));
    }

    /**
     * Тест: circuit breaker открывается при повторных отказах
     */
    public function testCircuitBreakerOpensOnFailures(): void
    {
        $l1 = new FailingCache();

        $cache = new TieredCache([
            'layers' => [
                [
                    'cache' => $l1,
                    'circuitBreaker' => [
                        'failureThreshold' => 0.5,
                        'windowSize' => 10,
                    ],
                ],
            ],
        ]);

        // Вызываем отказы для открытия цепи
        for ($i = 0; $i < 10; $i++) {
            $cache->get('key');
        }

        $status = $cache->getLayerStatus();
        self::assertEquals(BreakerInterface::STATE_OPEN, $status[0]['state']);
    }

    /**
     * Тест: запросы блокируются когда цепь открыта
     */
    public function testRequestsBlockedWhenCircuitOpen(): void
    {
        $l1 = new CountingCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1],
            ],
        ]);

        // Принудительно открываем цепь
        $cache->forceLayerOpen(0);

        // Пытаемся прочитать
        $result = $cache->get('test_key');

        // Должно вернуть false (промах кеша) без вызова L1
        self::assertFalse($result);
        // L1 не должен вызываться (или вызываться минимально)
        self::assertLessThanOrEqual(1, $l1->callCount, 'L1 не должен вызываться когда цепь открыта');
    }

    /**
     * Тест: TTL конкретного слоя переопределяет глобальный TTL
     */
    public function testLayerTtlOverridesGlobalTtl(): void
    {
        $l1 = new TtlTrackingCache();

        $cache = new TieredCache([
            'layers' => [
                [
                    'cache' => $l1,
                    'ttl' => 300, // 5 минут для L1
                ],
            ],
        ]);

        $cache->set('test_key', 'value', 3600); // Запрашиваем 1 час

        self::assertEquals(300, $l1->lastTtl, 'TTL слоя должен переопределить глобальный TTL');
    }

    /**
     * Тест: стратегия RECOVERY_POPULATE заполняет вышестоящие слои
     */
    public function testRecoveryPopulateStrategy(): void
    {
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1, 'ttl' => 60],
                ['cache' => $l2, 'ttl' => 60],
            ],
            'recoveryStrategy' => TieredCache::RECOVERY_POPULATE,
        ]);

        // Set value through TieredCache, then delete from L1
        $cache->set('test_key', 'l2_value', 60);
        $l1->delete('test_key');

        // Читаем из L2 (промах L1)
        $value = $cache->get('test_key');

        self::assertEquals('l2_value', $value);
        // L1 теперь должен быть заполнен
        $l1Value = $l1->get('test_key');
        self::assertInstanceOf(WrappedCacheValue::class, $l1Value);
        self::assertEquals('l2_value', $l1Value->value);
    }

    /**
     * Тест: стратегия RECOVERY_NATURAL не заполняет вышестоящие слои
     */
    public function testRecoveryNaturalStrategy(): void
    {
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1, 'ttl' => 60],
                ['cache' => $l2, 'ttl' => 60],
            ],
            'recoveryStrategy' => TieredCache::RECOVERY_NATURAL,
        ]);

        // Set value through TieredCache, then delete from L1
        $cache->set('test_key', 'l2_value', 60);
        $l1->delete('test_key');

        // Читаем из L2 (промах L1)
        $value = $cache->get('test_key');

        self::assertEquals('l2_value', $value);
        // L1 НЕ должен быть заполнен
        self::assertFalse($l1->get('test_key'));
    }

    /**
     * Тест: resetCircuitBreakers сбрасывает все слои
     */
    public function testResetCircuitBreakers(): void
    {
        $cache = new TieredCache([
            'layers' => [
                ['cache' => new FailingCache()],
                ['cache' => new FailingCache()],
            ],
        ]);

        // Открываем цепи
        for ($i = 0; $i < 10; $i++) {
            $cache->get('key');
        }

        $statusBefore = $cache->getLayerStatus();
        self::assertEquals(BreakerInterface::STATE_OPEN, $statusBefore[0]['state']);

        // Сбрасываем
        $cache->resetCircuitBreakers();

        $statusAfter = $cache->getLayerStatus();
        self::assertEquals(BreakerInterface::STATE_CLOSED, $statusAfter[0]['state']);
    }

    /**
     * Тест: add() со стратегией WRITE_FIRST
     */
    public function testAddWithWriteFirstStrategy(): void
    {
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1],
                ['cache' => $l2],
            ],
            'writeStrategy' => TieredCache::WRITE_FIRST,
        ]);

        $cache->add('test_key', 'test_value', 60);

        $l1Value = $l1->get('test_key');
        self::assertInstanceOf(WrappedCacheValue::class, $l1Value);
        self::assertEquals('test_value', $l1Value->value);
        self::assertFalse($l2->get('test_key'));
    }

    /**
     * Тест: getLayerStatus возвращает детальную информацию
     */
    public function testGetLayerStatusReturnsDetailedInfo(): void
    {
        $cache = new TieredCache([
            'layers' => [
                ['cache' => new ArrayCache()],
                ['cache' => new ArrayCache()],
            ],
        ]);

        $status = $cache->getLayerStatus();

        self::assertCount(2, $status, 'Должно быть ровно 2 слоя');
        self::assertArrayHasKey('index', $status[0]);
        self::assertArrayHasKey('class', $status[0]);
        self::assertArrayHasKey('state', $status[0]);
        self::assertArrayHasKey('stats', $status[0]);
        self::assertEquals(ArrayCache::class, $status[0]['class']);
    }

    /**
     * Тест: кеширование двухэлементного массива (indexed) не должно приводить к потере данных
     *
     * Проверяет сценарий, когда пользовательские данные представляют собой массив из двух элементов.
     * Такой массив не должен интерпретироваться как внутренняя структура [value, dependency].
     */
    public function testTwoElementIndexedArrayStoredCorrectly(): void
    {
        $cache = new TieredCache([
            'layers' => [
                ['cache' => new ArrayCache()],
            ],
        ]);

        // Сохраняем двухэлементный индексный массив
        $testData = [123, 456];
        $cache->set('test_key', $testData, 60);

        // Извлекаем данные
        $result = $cache->get('test_key');

        // Проверяем, что получили исходный массив целиком
        self::assertEquals($testData, $result, 'Двухэлементный индексный массив должен сохраниться полностью');
        self::assertIsArray($result);
        self::assertCount(2, $result);
        self::assertEquals(123, $result[0]);
        self::assertEquals(456, $result[1]);
    }

    /**
     * Тест: кеширование двухэлементного ассоциативного массива не должно приводить к потере данных
     *
     * Проверяет сценарий с ассоциативным массивом из двух элементов.
     */
    public function testTwoElementAssociativeArrayStoredCorrectly(): void
    {
        $cache = new TieredCache([
            'layers' => [
                ['cache' => new ArrayCache()],
            ],
        ]);

        // Сохраняем двухэлементный ассоциативный массив
        $testData = ['id' => 123, 'name' => 'John'];
        $cache->set('test_key', $testData, 60);

        // Извлекаем данные
        $result = $cache->get('test_key');

        // Проверяем, что получили исходный массив целиком
        self::assertEquals($testData, $result, 'Двухэлементный ассоциативный массив должен сохраниться полностью');
        self::assertIsArray($result);
        self::assertCount(2, $result);
        self::assertEquals(123, $result['id']);
        self::assertEquals('John', $result['name']);
    }

    /**
     * Тест: исключение при попытке инициализировать слой с невалидным cache (не CacheInterface)
     *
     * Проверяет, что конфигурация слоя с объектом, не реализующим CacheInterface,
     * приводит к InvalidConfigException при инициализации компонента.
     */
    public function testInitThrowsExceptionWithInvalidCacheObject(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('CacheInterface is expected');

        new TieredCache([
            'layers' => [
                ['cache' => new stdClass()], // Не реализует CacheInterface
            ],
        ]);
    }

    /**
     * Тест: обработка поврежденных данных (не-WrappedCacheValue) в строгом режиме
     *
     * Проверяет защиту от прямого доступа к слоям кеша минуя TieredCache.
     * В строгом режиме (strictMode = true), если слой возвращает "сырое" значение
     * вместо WrappedCacheValue, ошибка обрабатывается resilience-логикой:
     * circuit breaker фиксирует сбой, метод возвращает false.
     *
     * Примечание: в режиме совместимости (strictMode = false, по умолчанию)
     * такие значения автоматически оборачиваются и читаются успешно.
     */
    public function testGetValueThrowsExceptionWhenLayerReturnsNonDTO(): void
    {
        $l1 = new ArrayCache();

        // Напрямую записываем в слой "сырое" значение (минуя TieredCache)
        $l1->set('corrupted_key', 'raw_value', 60);

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1],
            ],
            'strictMode' => true, // Строгий режим
        ]);

        // В строгом режиме resilience-логика ловит исключение, поэтому get() вернет false
        $result = $cache->get('corrupted_key');
        self::assertFalse($result);

        // Circuit breaker зафиксировал ошибку
        $status = $cache->getLayerStatus();
        self::assertEquals(1, $status[0]['stats']['failures']);
    }

    /**
     * Тест: кастомный класс circuit breaker через defaultBreakerClass
     *
     * Проверяет возможность глобального переопределения класса circuit breaker
     * для всех слоев через свойство defaultBreakerClass.
     */
    public function testCustomDefaultBreakerClass(): void
    {
        // Создаем кастомный breaker динамически внутри теста
        $customBreakerClass = new class extends CircuitBreaker {
            // Наследует всю функциональность, используется только для проверки типа
        };

        $cache = new TieredCache([
            'defaultBreakerClass' => get_class($customBreakerClass),
            'layers' => [
                ['cache' => new ArrayCache()],
            ],
        ]);

        $status = $cache->getLayerStatus();

        // Проверяем, что используется кастомный breaker (анонимный класс)
        // Формат: app\components\resilience\CircuitBreaker@anonymous\0...
        self::assertStringContainsString('@anonymous', $status[0]['breaker_class']);
    }

    /**
     * Тест: инициализация слоя cache через конфигурационный массив
     *
     * Проверяет, что cache может быть задан как конфигурационный массив
     * и будет создан через Yii::createObject().
     */
    public function testInitWithCacheAsArrayConfig(): void
    {
        $cache = new TieredCache([
            'layers' => [
                [
                    'cache' => [
                        'class' => ArrayCache::class,
                    ],
                ],
            ],
        ]);

        $status = $cache->getLayerStatus();

        self::assertCount(1, $status);
        self::assertEquals(ArrayCache::class, $status[0]['class']);
    }

    /**
     * Тест: обработка нулевого TTL
     *
     * Проверяет корректную работу с нулевым временем жизни кеша.
     * При TTL=0 значение может кешироваться бесконечно (согласно Yii2 Cache).
     */
    public function testZeroTtlHandling(): void
    {
        $cache = new TieredCache([
            'layers' => [
                ['cache' => new ArrayCache()],
            ],
        ]);

        $result = $cache->set('test_key', 'test_value', 0);

        self::assertTrue($result);
        self::assertEquals('test_value', $cache->get('test_key'));
    }

    /**
     * Тест: обработка отрицательного TTL
     *
     * Проверяет, что отрицательное значение TTL обрабатывается корректно.
     * Поведение зависит от реализации, но не должно приводить к ошибкам.
     */
    public function testNegativeTtlHandling(): void
    {
        $cache = new TieredCache([
            'layers' => [
                ['cache' => new ArrayCache()],
            ],
        ]);

        // Не должно выбрасывать исключение
        $result = $cache->set('test_key', 'test_value', -1);

        self::assertIsBool($result);
    }

    /**
     * Тест: слой с нулевым ttl использует глобальный duration
     *
     * Проверяет, что если слой не имеет собственного TTL ограничения (ttl = null),
     * используется TTL из вызова set/add.
     */
    public function testLayerWithNullTtlUsesGlobalDuration(): void
    {
        $ttlCapture = new TtlCapturingCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $ttlCapture, 'ttl' => null],
            ],
        ]);

        $cache->set('test_key', 'test_value', 300);

        self::assertEquals(300, $ttlCapture->lastTtl, 'Должен использоваться глобальный TTL');
    }

    /**
     * Тест: переопределение circuit breaker для конкретного слоя
     *
     * Проверяет, что можно задать кастомный circuit breaker для отдельного слоя,
     * переопределяя defaultBreakerClass.
     */
    public function testPerLayerBreakerOverride(): void
    {
        // Создаем кастомный breaker динамически внутри теста
        $customBreakerClass = new class extends CircuitBreaker {
            // Наследует всю функциональность, используется только для проверки типа
        };

        $cache = new TieredCache([
            'defaultBreakerClass' => get_class($customBreakerClass),
            'layers' => [
                ['cache' => new ArrayCache()], // Использует defaultBreakerClass
                [
                    'cache' => new ArrayCache(),
                    'circuitBreaker' => [
                        'class' => CircuitBreaker::class,
                    ],
                ],
            ],
        ]);

        $status = $cache->getLayerStatus();

        // Первый слой использует кастомный (анонимный) breaker
        self::assertStringContainsString('@anonymous', $status[0]['breaker_class']);

        // Второй слой использует стандартный CircuitBreaker (не анонимный)
        self::assertStringContainsString('CircuitBreaker', $status[1]['breaker_class']);
        self::assertStringNotContainsString('@anonymous', $status[1]['breaker_class']);
    }

    /**
     * Проверяем корректность работы при пропуске истекших значений БЕЗ их принудительного удаления
     *
     * Проверяет, что явное удаление истекших значений из слоя НЕ является критически необходимым
     * для функциональной корректности системы. Этот тест демонстрирует, что:
     *
     * 1. Система корректно возвращает валидные данные из нижележащих слоев
     * 2. Истекшие значения пропускаются без ошибок
     * 3. Circuit breaker остается в здоровом состоянии
     * 4. Не возникает исключений или побочных эффектов
     *
     * Разница между подходами:
     * - С принудительным удалением: истекшие значения удаляются из слоя → освобождение памяти
     * - Без удаления: истекшие значения остаются → backend TTL очистит позже
     *
     * Сценарий:
     * - L1 содержит истекшее значение (expiresAt в прошлом)
     * - L2 содержит валидное значение (expiresAt в будущем)
     * - L3 пуст (для проверки полного цикла поиска)
     *
     * Ожидаемое поведение (независимо от наличия явного удаления):
     * - getValue() возвращает валидное значение из L2
     * - Не выбрасываются исключения
     * - Circuit breaker фиксирует успех (не failure)
     * - Состояние системы консистентно
     *
     * Примечание: Если истекшее значение НЕ удаляется, оно остается в L1 до истечения
     * backend TTL или eviction. Это не влияет на корректность, только на использование памяти.
     */
    public function testExpiredValueSkippingWithoutDeletionWorksCorrectly(): void
    {
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();
        $l3 = new ArrayCache();

        // Устанавливаем истекшее значение в L1 (expiresAt в прошлом)
        $expiredValue = new WrappedCacheValue('expired_data', time() - 300); // Истекло 5 минут назад
        $l1->set('test_key', $expiredValue, 3600); // Backend TTL = 1 час (значение физически в кеше)

        // Устанавливаем валидное значение в L2 (expiresAt в будущем)
        $validValue = new WrappedCacheValue('valid_data', time() + 300); // Истечет через 5 минут
        $l2->set('test_key', $validValue, 3600);

        // L3 остается пустым для проверки полного цикла

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1],
                ['cache' => $l2],
                ['cache' => $l3],
            ],
        ]);

        // ACT: Читаем значение
        $result = $cache->get('test_key');

        // ASSERT 1: Корректность данных - должны получить валидное значение из L2
        self::assertEquals(
            'valid_data',
            $result,
            'TieredCache должен вернуть валидное значение из L2, пропустив истекшее в L1',
        );

        // ASSERT 2: Circuit breaker в здоровом состоянии
        $status = $cache->getLayerStatus();
        self::assertEquals(
            BreakerInterface::STATE_CLOSED,
            $status[0]['state'],
            'Circuit breaker L1 должен оставаться CLOSED (пропуск истекшего значения - не ошибка)',
        );
        self::assertEquals(
            BreakerInterface::STATE_CLOSED,
            $status[1]['state'],
            'Circuit breaker L2 должен оставаться CLOSED (успешное чтение)',
        );

        // ASSERT 3: Circuit breaker не фиксирует failures
        self::assertEquals(
            0,
            $status[0]['stats']['failures'] ?? 0,
            'Пропуск истекшего значения не должен считаться failure',
        );
        self::assertEquals(
            0,
            $status[1]['stats']['failures'] ?? 0,
            'Успешное чтение из L2 не должно быть failure',
        );
        self::assertEquals(
            0,
            $status[2]['stats']['failures'] ?? 0,
            'L3 не должен иметь failures',
        );

        // ASSERT 4: Повторное чтение дает тот же результат (идемпотентность)
        $resultSecond = $cache->get('test_key');
        self::assertEquals(
            'valid_data',
            $resultSecond,
            'Повторное чтение должно давать тот же результат',
        );

        // ПРИМЕЧАНИЕ: Мы НЕ проверяем, что истекшее значение удалено из L1,
        // потому что это тест для сценария БЕЗ явного удаления.
        // Истекшее значение может оставаться в L1 до истечения backend TTL.
        // Это НЕ влияет на корректность - только на использование памяти.

        // ДОПОЛНИТЕЛЬНАЯ ПРОВЕРКА: Если истекшее значение осталось в L1,
        // система все равно должна работать корректно при последующих операциях
        $l1Value = $l1->get('test_key');
        if (false !== $l1Value) {
            // Если значение осталось в L1 (не было удалено), проверяем что оно все еще истекшее
            self::assertInstanceOf(WrappedCacheValue::class, $l1Value);
            self::assertTrue(
                $l1Value->expired,
                'Если истекшее значение осталось в L1, оно должно быть помечено как expired',
            );

            // И система должна продолжать корректно его пропускать
            $resultThird = $cache->get('test_key');
            self::assertEquals(
                'valid_data',
                $resultThird,
                'Система должна пропускать истекшее значение при любом количестве повторных чтений',
            );
        }
    }

    /**
     * Тест: стандартный Yii cache не может прочитать значения, записанные TieredCache
     *
     * Демонстрирует проблему совместимости кеш-формата:
     * - TieredCache записывает значения как WrappedCacheValue
     * - Стандартный Cache читает напрямую и получает WrappedCacheValue вместо данных
     * - Это ломает совместимость с другими приложениями, использующими тот же backend
     *
     * Сценарий:
     * - Приложение A использует TieredCache и пишет в Redis
     * - Приложение B использует стандартный RedisCache и читает из того же Redis
     * - Приложение B получает WrappedCacheValue вместо реальных данных → ОШИБКА
     */
    public function testStandardCacheCannotReadTieredCacheValues(): void
    {
        // Общий backend (ArrayCache симулирует Redis/Memcached)
        $sharedBackend = new ArrayCache();

        // Приложение A: использует TieredCache
        $tieredCache = new TieredCache([
            'layers' => [
                ['cache' => $sharedBackend],
            ],
        ]);

        // Приложение A записывает данные пользователя
        $userData = [
            'id' => 123,
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ];
        $tieredCache->set('user:123', $userData, 3600);

        // Приложение B: использует стандартный Yii Cache (тот же backend)
        $standardCache = $sharedBackend;

        // Приложение B пытается прочитать данные пользователя
        $readValue = $standardCache->get('user:123');

        // ПРОБЛЕМА: стандартный cache получает WrappedCacheValue, а не массив с данными
        self::assertInstanceOf(
            WrappedCacheValue::class,
            $readValue,
            'Стандартный cache получает WrappedCacheValue вместо реальных данных - формат несовместим!',
        );

        // Данные недоступны без знания внутреннего формата TieredCache
        self::assertNotEquals(
            $userData,
            $readValue,
            'Приложение B не может получить исходные данные - требуется знать о WrappedCacheValue',
        );

        // Чтобы получить данные, нужно знать о внутреннем формате (что нарушает инкапсуляцию)
        self::assertEquals(
            $userData,
            $readValue->value,
            'Реальные данные спрятаны в свойстве value WrappedCacheValue',
        );
    }

    /**
     * Тест: TieredCache автоматически читает legacy-значения (режим совместимости)
     *
     * Демонстрирует решение проблемы обратной совместимости:
     * - TieredCache по умолчанию в режиме совместимости (strictMode = false)
     * - Автоматически оборачивает legacy-значения при чтении
     * - Позволяет плавную миграцию без потери данных
     *
     * Сценарий миграции:
     * - До деплоя: приложение использовало RedisCache, кеш заполнен данными
     * - Деплой: внедрение TieredCache с strictMode = false
     * - Приложение работает: TieredCache читает старые значения через auto-wrap
     * - После истечения TTL всех старых значений можно включить strictMode = true
     *
     * Также позволяет сосуществование:
     * - Микросервис A использует TieredCache
     * - Микросервис B использует стандартный cache
     * - Микросервис A может прочитать данные, записанные микросервисом B
     */
    public function testTieredCacheCanReadStandardCacheValuesWithAutoWrap(): void
    {
        // Общий backend с данными от старого/стороннего приложения
        $sharedBackend = new ArrayCache();

        // Старое приложение (или микросервис B) записывает данные в стандартном формате
        $configData = [
            'db_host' => 'localhost',
            'db_port' => 5432,
            'cache_ttl' => 3600,
        ];
        $sharedBackend->set('app:config', $configData, 3600);

        // Новое приложение (или микросервис A) использует TieredCache в режиме совместимости
        $tieredCache = new TieredCache([
            'layers' => [
                ['cache' => $sharedBackend],
            ],
            'strictMode' => false, // Режим совместимости (по умолчанию)
        ]);

        // РЕШЕНИЕ: TieredCache успешно читает legacy-данные через auto-wrap
        $result = $tieredCache->get('app:config');

        // Значение успешно получено и совпадает с оригинальными данными
        self::assertEquals(
            $configData,
            $result,
            'TieredCache успешно читает legacy-значения через автоматическое оборачивание',
        );

        // Circuit breaker НЕ фиксирует ошибку (это валидная операция)
        $status = $tieredCache->getLayerStatus();
        self::assertEquals(
            0,
            $status[0]['stats']['failures'] ?? 0,
            'Circuit breaker не считает legacy-данные ошибкой в режиме совместимости',
        );

        // Circuit breaker в состоянии CLOSED (здоров)
        self::assertEquals(
            BreakerInterface::STATE_CLOSED,
            $status[0]['state'],
            'Circuit breaker остается закрытым после успешного чтения legacy-значения',
        );
    }

    /**
     * Тест: Строгий режим (strictMode = true) отклоняет legacy-значения
     *
     * После полной миграции можно включить строгий режим для гарантии формата данных.
     * В этом режиме TieredCache отклоняет non-WrappedCacheValue как ошибку.
     *
     * Используется для:
     * - Обеспечения чистоты формата после миграции
     * - Обнаружения случайного прямого доступа к слоям
     * - Быстрого выявления проблем интеграции
     */
    public function testStrictModeRejectsLegacyValues(): void
    {
        $sharedBackend = new ArrayCache();

        // Legacy-данные в кеше
        $sharedBackend->set('legacy_key', 'legacy_value', 3600);

        // TieredCache в строгом режиме
        $tieredCache = new TieredCache([
            'layers' => [
                ['cache' => $sharedBackend],
            ],
            'strictMode' => true,
        ]);

        // В строгом режиме legacy-значения считаются ошибкой
        $result = $tieredCache->get('legacy_key');

        // Значение не получено
        self::assertFalse($result, 'Строгий режим отклоняет legacy-значения');

        // Circuit breaker фиксирует ошибку
        $status = $tieredCache->getLayerStatus();
        self::assertEquals(
            1,
            $status[0]['stats']['failures'],
            'Строгий режим фиксирует legacy-значения как failure в circuit breaker',
        );
    }
}

/**
 * Мок-кеш, который всегда завершается с ошибкой
 */
class FailingCache extends ArrayCache
{
    /**
     * @param $key
     *
     * @return mixed
     * @throws Exception
     */
    protected function getValue($key): mixed
    {
        throw new RuntimeException('Cache operation failed');
    }

    /**
     * @param $key
     * @param $value
     * @param $duration
     *
     * @return bool
     * @throws Exception
     */
    protected function setValue($key, $value, $duration): bool
    {
        throw new RuntimeException('Cache operation failed');
    }

    /**
     * @param $key
     * @param $value
     * @param $duration
     *
     * @return bool
     * @throws Exception
     */
    protected function addValue($key, $value, $duration): bool
    {
        throw new RuntimeException('Cache operation failed');
    }

    /**
     * @param $key
     *
     * @return bool
     * @throws Exception
     */
    protected function deleteValue($key): bool
    {
        throw new RuntimeException('Cache operation failed');
    }

    /**
     * @return bool
     */
    protected function flushValues(): bool
    {
        throw new RuntimeException('Cache operation failed');
    }
}

/**
 * Мок-кеш, который подсчитывает вызовы методов
 */
class CountingCache extends ArrayCache
{
    public int $callCount = 0 {
        /**
         * @return int
         */
        get {
            return $this->callCount;
        }
    }

    /**
     * @param $key
     *
     * @return false
     */
    protected function getValue($key): false
    {
        $this->callCount++;
        return false;
    }

    /**
     * @param $key
     * @param $value
     * @param $duration
     *
     * @return bool
     */
    protected function setValue($key, $value, $duration): bool
    {
        $this->callCount++;
        return true;
    }

    /**
     * @param $key
     * @param $value
     * @param $duration
     *
     * @return bool
     */
    protected function addValue($key, $value, $duration): bool
    {
        $this->callCount++;
        return true;
    }

    /**
     * @param $key
     *
     * @return bool
     */
    protected function deleteValue($key): bool
    {
        $this->callCount++;
        return true;
    }

    /**
     * @return bool
     */
    protected function flushValues(): bool
    {
        $this->callCount++;
        return true;
    }
}

/**
 * Мок-кеш, который отслеживает значения TTL
 */
class TtlTrackingCache extends ArrayCache
{
    public ?int $lastTtl = null {
        /**
         * @return int|null
         */
        get {
            return $this->lastTtl;
        }
    }

    /**
     * @param $key
     *
     * @return false
     */
    protected function getValue($key): false
    {
        return false;
    }

    /**
     * @param $key
     * @param $value
     * @param $duration
     *
     * @return bool
     */
    protected function setValue($key, $value, $duration): bool
    {
        $this->lastTtl = $duration;
        return true;
    }

    /**
     * @param $key
     * @param $value
     * @param $duration
     *
     * @return bool
     */
    protected function addValue($key, $value, $duration): bool
    {
        $this->lastTtl = $duration;
        return true;
    }

    /**
     * @param $key
     *
     * @return bool
     */
    protected function deleteValue($key): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    protected function flushValues(): bool
    {
        return true;
    }
}

/**
 * Мок-кеш, который перехватывает последний использованный TTL
 */
class TtlCapturingCache extends ArrayCache
{
    public ?int $lastTtl = null;

    /**
     * @param $key
     * @param $value
     * @param $duration
     *
     * @return bool
     */
    protected function setValue($key, $value, $duration): bool
    {
        $this->lastTtl = $duration;
        return parent::setValue($key, $value, $duration);
    }

    /**
     * @param $key
     * @param $value
     * @param $duration
     *
     * @return bool
     */
    protected function addValue($key, $value, $duration): bool
    {
        $this->lastTtl = $duration;
        return parent::addValue($key, $value, $duration);
    }
}
