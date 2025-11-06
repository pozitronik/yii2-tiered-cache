<?php
declare(strict_types=1);
namespace Beeline\TieredCache\Tests;


use Beeline\TieredCache\Cache\TieredCache;
use Beeline\TieredCache\Cache\WrappedCacheValue;
use Beeline\TieredCache\Resilience\BreakerInterface;
use Codeception\Test\Unit;
use yii\caching\ArrayCache;
use yii\redis\Cache as RedisCache;

/**
 * Тесты устойчивости для TieredCache с реальным Redis
 *
 * Набор интеграционных тестов, проверяющих работу многоуровневого кеша в реальных условиях
 * с подключением к Redis. Проверяется поведение компонента при отказах слоев,
 * автоматическое переключение между уровнями и восстановление после сбоев.
 *
 * Сценарии тестирования:
 * - Базовые операции кеширования с реальным Redis слоем
 * - Автоматическое переключение при недоступности Redis
 * - Восстановление работы при возвращении доступности Redis
 * - Многоуровневое кеширование ArrayCache → Redis → ArrayCache
 * - Стратегии записи (WRITE_THROUGH, WRITE_FIRST)
 * - Стратегии восстановления (RECOVERY_POPULATE)
 * - Работа circuit breaker для каждого слоя
 * - Переопределение TTL на уровне слоя
 *
 * Запуск: vendor/bin/codecept run resilience components/cache/TieredCacheResilienceTest
 */
class TieredCacheResilienceTest extends Unit
{

    /**
     * Сценарий: Базовые операции кеширования с реальным Redis слоем
     *
     * Проверяет работу основных операций set/get/delete с использованием
     * реального Redis в качестве одного из слоев кеша.
     *
     * Шаги теста:
     * 1. Создается TieredCache с ArrayCache (L1) и Redis (L2)
     * 2. Устанавливается значение с ключом 'test_key_basic'
     * 3. Проверяется успешность операции записи
     * 4. Считывается значение по ключу
     * 5. Проверяется корректность считанного значения
     * 6. Удаляется значение из кеша
     * 7. Проверяется, что значение действительно удалено
     *
     * Ожидаемое поведение:
     * - Значение успешно записывается во все слои кеша
     * - Значение корректно считывается из кеша
     * - Удаление работает корректно для всех слоев
     */
    public function testBasicCachingWithRedis(): void
    {
        $cache = $this->createTieredCache();

        // Set value
        $result = $cache->set('test_key_basic', 'test_value', 60);
        self::assertTrue($result, 'Should successfully set value');

        // Get value
        $value = $cache->get('test_key_basic');
        self::assertEquals('test_value', $value, 'Should retrieve correct value');

        // Delete value
        $cache->delete('test_key_basic');
        self::assertFalse($cache->get('test_key_basic'), 'Value should be deleted');
    }

    /**
     * Сценарий: Чтение из многоуровневого кеша с иерархией слоев
     *
     * Проверяет механизм последовательного поиска значения в слоях кеша:
     * сначала в быстрых верхних слоях, затем в более медленных нижних.
     * При нахождении значения в нижнем слое оно должно быть заполнено в верхние слои.
     *
     * Шаги теста:
     * 1. Создается TieredCache с тремя слоями: ArrayCache (L1), Redis (L2), ArrayCache (L3)
     * 2. Устанавливается стратегия восстановления RECOVERY_POPULATE
     * 3. Значение устанавливается только в L3 (третий слой)
     * 4. Выполняется чтение значения через TieredCache
     * 5. Проверяется, что значение считано из L3
     * 6. Проверяется, что значение было автоматически заполнено в L1
     * 7. Удаляется тестовое значение
     *
     * Ожидаемое поведение:
     * - Компонент последовательно проверяет L1, L2, L3
     * - Значение находится в L3
     * - С стратегией RECOVERY_POPULATE значение копируется в L1 и L2
     * - Последующие чтения будут быстрее благодаря кешированию в L1
     */
    public function testMultiTierReading(): void
    {
        $redis = $this->createRedisCache();
        $l1 = new ArrayCache();
        $l2 = new ArrayCache(); // Second in-memory cache instead of DB

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1, 'ttl' => 60],
                ['cache' => $redis, 'ttl' => 60],
                ['cache' => $l2, 'ttl' => 60],
            ],
            'recoveryStrategy' => TieredCache::RECOVERY_POPULATE,
        ]);

        // Set value through TieredCache to all layers
        $cache->set('test_key_tier', 'l2_value', 60);

        // Delete from L1 to simulate cache miss in first layer
        $l1->delete('test_key_tier');

        // First read should get from L2 (Redis) and populate L1
        $value = $cache->get('test_key_tier');
        self::assertEquals('l2_value', $value, 'Should read from L2 (second layer)');

        // Verify L1 was populated
        $l1Value = $l1->get('test_key_tier');
        self::assertInstanceOf(WrappedCacheValue::class, $l1Value, 'L1 should contain WrappedCacheValue');
        self::assertEquals('l2_value', $l1Value->value, 'L1 should be populated');

        // Clean up
        $cache->delete('test_key_tier');
    }

    /**
     * Сценарий: Стратегия сквозной записи (WRITE_THROUGH) записывает во все слои
     *
     * Проверяет работу стратегии WRITE_THROUGH, при которой операция записи
     * выполняется синхронно во всех слоях кеша. Это обеспечивает согласованность
     * данных, но требует больше времени на запись.
     *
     * Шаги теста:
     * 1. Создается TieredCache с тремя слоями: ArrayCache (L1), Redis (L2), ArrayCache (L3)
     * 2. Устанавливается стратегия записи WRITE_THROUGH
     * 3. Записывается значение через TieredCache
     * 4. Проверяется наличие значения непосредственно в L1
     * 5. Проверяется наличие значения непосредственно в L2 (Redis)
     * 6. Проверяется наличие значения непосредственно в L3
     * 7. Удаляется тестовое значение
     *
     * Ожидаемое поведение:
     * - Операция set() записывает значение во все три слоя одновременно
     * - Все слои содержат идентичное значение
     * - Данные согласованы на всех уровнях кеша
     */
    public function testWriteThroughStrategy(): void
    {
        $redis = $this->createRedisCache();
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1],
                ['cache' => $redis],
                ['cache' => $l2],
            ],
            'writeStrategy' => TieredCache::WRITE_THROUGH,
        ]);

        // Set value
        $cache->set('test_key_write_through', 'value', 60);

        // Verify all layers have the value
        $l1Value = $l1->get('test_key_write_through');
        self::assertInstanceOf(WrappedCacheValue::class, $l1Value);
        self::assertEquals('value', $l1Value->value, 'L1 should have value');

        $redisValue = $redis->get('test_key_write_through');
        self::assertInstanceOf(WrappedCacheValue::class, $redisValue);
        self::assertEquals('value', $redisValue->value, 'L2 (Redis) should have value');

        $l2Value = $l2->get('test_key_write_through');
        self::assertInstanceOf(WrappedCacheValue::class, $l2Value);
        self::assertEquals('value', $l2Value->value, 'L3 should have value');

        // Clean up
        $cache->delete('test_key_write_through');
    }

    /**
     * Сценарий: Стратегия записи в первый слой (WRITE_FIRST) записывает только в первый доступный слой
     *
     * Проверяет работу стратегии WRITE_FIRST, при которой запись выполняется только
     * в первый доступный слой. Это ускоряет операции записи, но может привести
     * к рассинхронизации слоев. Синхронизация происходит естественным образом через
     * чтение и стратегию восстановления.
     *
     * Шаги теста:
     * 1. Создается TieredCache с тремя слоями: ArrayCache (L1), Redis (L2), ArrayCache (L3)
     * 2. Устанавливается стратегия записи WRITE_FIRST
     * 3. Записывается значение через TieredCache
     * 4. Проверяется наличие значения в L1
     * 5. Проверяется отсутствие значения в L2 (Redis)
     * 6. Проверяется отсутствие значения в L3
     * 7. Удаляется тестовое значение
     *
     * Ожидаемое поведение:
     * - Операция set() записывает значение только в L1 (первый слой)
     * - L2 и L3 не содержат значение сразу после записи
     * - Запись выполняется быстрее, чем при WRITE_THROUGH
     */
    public function testWriteFirstStrategy(): void
    {
        $redis = $this->createRedisCache();
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1],
                ['cache' => $redis],
                ['cache' => $l2],
            ],
            'writeStrategy' => TieredCache::WRITE_FIRST,
        ]);

        // Set value
        $cache->set('test_key_write_first', 'value', 60);

        // Verify only L1 has the value
        $l1Value = $l1->get('test_key_write_first');
        self::assertInstanceOf(WrappedCacheValue::class, $l1Value);
        self::assertEquals('value', $l1Value->value, 'L1 should have value');
        self::assertFalse($redis->get('test_key_write_first'), 'L2 should not have value');
        self::assertFalse($l2->get('test_key_write_first'), 'L3 should not have value');

        // Clean up
        $cache->delete('test_key_write_first');
    }

    /**
     * Сценарий: Автоматическое переключение при недоступности Redis слоя
     *
     * Проверяет механизм автоматического failover - переключения на следующий слой
     * при недоступности текущего. Имитируется отказ Redis путем принудительного
     * открытия circuit breaker второго слоя.
     *
     * Шаги теста:
     * 1. Создается TieredCache с тремя слоями: ArrayCache (L1), Redis (L2), ArrayCache (L3)
     * 2. Записывается значение во все слои
     * 3. Проверяется успешное чтение значения
     * 4. Принудительно открывается circuit breaker Redis слоя (имитация отказа)
     * 5. Удаляется значение из L1 для имитации промаха верхнего слоя
     * 6. Выполняется чтение значения
     * 7. Проверяется, что значение успешно прочитано из L3 (Redis пропущен)
     * 8. Удаляется тестовое значение
     *
     * Ожидаемое поведение:
     * - При открытом circuit breaker Redis (L2) запросы к нему не выполняются
     * - Компонент автоматически переходит к следующему доступному слою (L3)
     * - Чтение выполняется успешно несмотря на недоступность Redis
     * - Failover происходит прозрачно для клиентского кода
     */
    public function testAutoFailoverWhenRedisUnavailable(): void
    {
        $redis = $this->createRedisCache();
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1],
                ['cache' => $redis],
                ['cache' => $l2],
            ],
        ]);
        $cache->init();

        // Set value in all layers
        $cache->set('test_key_failover', 'original_value', 60);

        // Verify value is set
        self::assertEquals('original_value', $cache->get('test_key_failover'));

        // Force Redis circuit breaker to open (simulating Redis failure)
        $cache->forceLayerOpen(1);

        // Clear L1 to force reading from lower layers
        $l1->delete('test_key_failover');

        // Should still read from L2 when Redis is unavailable
        $value = $cache->get('test_key_failover');
        self::assertEquals('original_value', $value, 'Should fallback to L2 when Redis is unavailable');

        // Clean up
        $cache->delete('test_key_failover');
    }

    /**
     * Сценарий: Circuit breaker блокирует чтения при открытом состоянии
     *
     * Проверяет, что открытый circuit breaker эффективно блокирует обращения к слою,
     * заставляя компонент использовать следующие доступные слои. Это предотвращает
     * повторные попытки обращения к неисправному слою.
     *
     * Шаги теста:
     * 1. Создается TieredCache с ArrayCache (L1), Redis (L2) с circuit breaker, ArrayCache (L3)
     * 2. Устанавливаются разные значения в Redis (L2) и L3
     * 3. Выполняется чтение - должно вернуть значение из Redis (circuit breaker закрыт)
     * 4. Проверяется, что прочитано значение из Redis
     * 5. Принудительно открывается circuit breaker Redis слоя
     * 6. Удаляется значение из L1 для имитации промаха верхнего слоя
     * 7. Выполняется повторное чтение
     * 8. Проверяется, что теперь прочитано значение из L3 (Redis пропущен)
     * 9. Удаляется тестовое значение
     *
     * Ожидаемое поведение:
     * - При закрытом circuit breaker чтение происходит из Redis (L2)
     * - При открытом circuit breaker Redis пропускается
     * - Компонент автоматически переходит к L3
     * - Circuit breaker эффективно изолирует неисправный слой
     */
    public function testCircuitBreakerBlocksReads(): void
    {
        $redis = $this->createRedisCache();
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1, 'ttl' => 60],
                [
                    'cache' => $redis,
                    'ttl' => 60,
                    'circuitBreaker' => [
                        'failureThreshold' => 0.5,
                        'windowSize' => 10,
                    ],
                ],
                ['cache' => $l2, 'ttl' => 60],
            ],
        ]);

        // Set value through TieredCache (writes to all layers)
        $cache->set('test_key_circuit', 'redis_value', 60);

        // Change value in L2 to differentiate which layer we're reading from
        $l2->set('test_key_circuit', new WrappedCacheValue('l2_value', time() + 60), 60);

        // Clear L1 to ensure we read from Redis
        $l1->delete('test_key_circuit');

        // Verify Redis layer starts closed and can read from Redis
        $value1 = $cache->get('test_key_circuit');
        self::assertEquals('redis_value', $value1, 'Should read from Redis when circuit is closed');

        // Force Redis circuit breaker to open
        $cache->forceLayerOpen(1);

        // Clear L1 to force reading from lower layers
        $l1->delete('test_key_circuit');

        // Now should skip Redis and read from L2
        $value2 = $cache->get('test_key_circuit');
        self::assertEquals('l2_value', $value2, 'Should skip Redis and read from L2 when circuit is open');

        // Clean up
        $cache->delete('test_key_circuit');
    }

    /**
     * Сценарий: TTL конкретного слоя переопределяет глобальный TTL
     *
     * Проверяет возможность настройки индивидуального времени жизни (TTL) для каждого
     * слоя кеша. Это позволяет хранить данные в быстрых слоях меньше времени,
     * чем в медленных, оптимизируя использование ресурсов.
     *
     * Шаги теста:
     * 1. Создается TieredCache с Redis слоем, для которого установлен TTL = 2 секунды
     * 2. Записывается значение с запрошенным TTL = 3600 секунд (1 час)
     * 3. Проверяется, что значение доступно сразу после записи
     * 4. Ожидание 3 секунды (больше, чем TTL слоя)
     * 5. Проверяется, что значение истекло
     *
     * Ожидаемое поведение:
     * - TTL слоя (2 секунды) переопределяет запрошенный TTL (3600 секунд)
     * - Значение истекает через 2 секунды согласно настройке слоя
     * - Каждый слой может иметь свою политику истечения данных
     */
    public function testLayerSpecificTtl(): void
    {
        $redis = $this->createRedisCache();

        $cache = new TieredCache([
            'layers' => [
                [
                    'cache' => $redis,
                    'ttl' => 2, // 2 seconds for Redis
                ],
            ],
        ]);

        // Set with longer TTL (should be overridden to 2 seconds)
        $cache->set('test_key_ttl', 'value', 3600);

        // Value should exist immediately
        self::assertEquals('value', $cache->get('test_key_ttl'));

        // Wait for layer TTL to expire
        sleep(3);

        // Value should be expired
        self::assertFalse($cache->get('test_key_ttl'), 'Value should expire after layer TTL');
    }

    /**
     * Сценарий: Операция flush очищает все слои кеша
     *
     * Проверяет, что операция полной очистки кеша (flush) корректно удаляет
     * все данные из всех слоев, обеспечивая полную синхронизацию при сбросе.
     *
     * Шаги теста:
     * 1. Создается TieredCache с тремя слоями: ArrayCache (L1), Redis (L2), ArrayCache (L3)
     * 2. Записываются два тестовых значения
     * 3. Проверяется наличие обоих значений в кеше
     * 4. Выполняется операция flush()
     * 5. Проверяется отсутствие первого значения
     * 6. Проверяется отсутствие второго значения
     *
     * Ожидаемое поведение:
     * - Операция flush() удаляет все данные из всех слоев
     * - После flush() все слои кеша пусты
     * - Не остается устаревших данных ни в одном слое
     */
    public function testFlushClearsAllLayers(): void
    {
        $redis = $this->createRedisCache();
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1],
                ['cache' => $redis],
                ['cache' => $l2],
            ],
        ]);

        // Set values
        $cache->set('test_flush_1', 'value1', 60);
        $cache->set('test_flush_2', 'value2', 60);

        // Verify values exist
        self::assertEquals('value1', $cache->get('test_flush_1'));
        self::assertEquals('value2', $cache->get('test_flush_2'));

        // Flush all layers
        $cache->flush();

        // Verify values are gone
        self::assertFalse($cache->get('test_flush_1'));
        self::assertFalse($cache->get('test_flush_2'));
    }

    /**
     * Сценарий: Стратегия восстановления с заполнением (RECOVERY_POPULATE)
     *
     * Проверяет работу стратегии RECOVERY_POPULATE, при которой значение,
     * найденное в нижнем слое, автоматически копируется во все вышестоящие слои.
     * Это ускоряет последующие обращения к этим данным.
     *
     * Шаги теста:
     * 1. Создается TieredCache с тремя слоями и стратегией RECOVERY_POPULATE
     * 2. Значение устанавливается только в L3 (нижний слой)
     * 3. Выполняется чтение через TieredCache
     * 4. Проверяется, что получено корректное значение
     * 5. Проверяется, что значение автоматически скопировано в L1
     * 6. Проверяется, что значение автоматически скопировано в L2 (Redis)
     * 7. Удаляется тестовое значение
     *
     * Ожидаемое поведение:
     * - При чтении из L3 значение автоматически заполняется в L1 и L2
     * - Последующие чтения будут быстрее благодаря данным в верхних слоях
     * - Стратегия автоматически оптимизирует распределение данных по слоям
     */
    public function testRecoveryPopulateStrategy(): void
    {
        $redis = $this->createRedisCache();
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1, 'ttl' => 60],
                ['cache' => $redis, 'ttl' => 60],
                ['cache' => $l2, 'ttl' => 60],
            ],
            'recoveryStrategy' => TieredCache::RECOVERY_POPULATE,
        ]);

        // Set value through TieredCache to all layers
        $cache->set('test_key_populate', 'l2_value', 60);

        // Delete from L1 and Redis to simulate cache misses
        $l1->delete('test_key_populate');
        $redis->delete('test_key_populate');

        // Read should get from L2 and populate higher layers
        $value = $cache->get('test_key_populate');
        self::assertEquals('l2_value', $value);

        // Verify L1 and Redis were populated
        $l1Value = $l1->get('test_key_populate');
        self::assertInstanceOf(WrappedCacheValue::class, $l1Value);
        self::assertEquals('l2_value', $l1Value->value, 'L1 should be populated');

        $redisValue = $redis->get('test_key_populate');
        self::assertInstanceOf(WrappedCacheValue::class, $redisValue);
        self::assertEquals('l2_value', $redisValue->value, 'Redis should be populated');

        // Clean up
        $cache->delete('test_key_populate');
    }

    /**
     * Сценарий: Получение статуса слоев для мониторинга
     *
     * Проверяет работу метода getLayerStatus(), который предоставляет информацию
     * о состоянии каждого слоя кеша и его circuit breaker. Это необходимо для
     * мониторинга работоспособности системы кеширования и диагностики проблем.
     *
     * Шаги теста:
     * 1. Создается TieredCache с настроенными слоями
     * 2. Запрашивается статус всех слоев через getLayerStatus()
     * 3. Проверяется наличие минимум 2 слоев в статусе
     * 4. Для каждого слоя проверяется наличие обязательных полей:
     *    - index: индекс слоя в иерархии
     *    - class: класс кеша слоя
     *    - state: состояние circuit breaker (CLOSED/OPEN/HALF_OPEN)
     *    - stats: статистика работы circuit breaker
     * 5. Проверяется, что все circuit breaker изначально в состоянии CLOSED
     *
     * Ожидаемое поведение:
     * - Метод возвращает массив с информацией о всех слоях
     * - Каждый элемент содержит полную информацию о состоянии слоя
     * - В начале работы все circuit breaker в состоянии CLOSED
     * - Информация пригодна для использования в системах мониторинга
     */
    public function testLayerStatusReporting(): void
    {
        $cache = $this->createTieredCache();

        $status = $cache->getLayerStatus();

        self::assertGreaterThanOrEqual(2, count($status), 'Should have at least 2 layers');

        foreach ($status as $layerStatus) {
            self::assertArrayHasKey('index', $layerStatus);
            self::assertArrayHasKey('class', $layerStatus);
            self::assertArrayHasKey('state', $layerStatus);
            self::assertArrayHasKey('stats', $layerStatus);

            // All circuits should be closed initially
            self::assertEquals(BreakerInterface::STATE_CLOSED, $layerStatus['state']);
        }
    }

    /**
     * Сценарий: Проверка стратегии восстановления POPULATE с истечением срока жизни
     *
     * Проверяет поведение многоуровневого кеша при использовании стратегии RECOVERY_POPULATE
     * в комбинации с TTL (временем жизни записей).
     *
     * Шаги теста:
     * 1. Создается TieredCache с тремя слоями и стратегией RECOVERY_POPULATE
     * 2. Значение записывается во все слои с TTL=1 секунда (WRITE_THROUGH)
     * 3. Удаляется L1 для имитации cache miss
     * 4. При чтении значение читается из L2 и заполняется обратно в L1
     * 5. Проверяется что L1 получила значение с корректным остаточным TTL
     * 6. После истечения TTL проверяется что значения истекли во всех слоях
     *
     * Ожидаемое поведение:
     * - Стратегия POPULATE распространяет значение в верхние слои с правильным remaining TTL
     * - Все слои уважают одинаковое время истечения
     * - После истечения TTL значения недоступны во всех слоях
     */
    public function testRecoveryStrategyPopulateWithExpiration(): void
    {
        $l1 = new ArrayCache();
        $l2 = new ArrayCache();
        $l3 = new ArrayCache();

        $cache = new TieredCache([
            'layers' => [
                ['cache' => $l1, 'ttl' => 3],
                ['cache' => $l2, 'ttl' => 3],
                ['cache' => $l3, 'ttl' => 3],
            ],
            'recoveryStrategy' => TieredCache::RECOVERY_POPULATE,
            'writeStrategy' => TieredCache::WRITE_THROUGH,
        ]);

        $duration = 2; // 2 seconds TTL

        // Write value through TieredCache - goes to all layers
        $cache->set('test_key', 'test_value', $duration);

        // Verify value is in all layers
        self::assertEquals('test_value', $cache->get('test_key'), 'Value should be accessible');

        // Clear L1 to simulate cache miss
        $l1->delete('test_key');

        sleep(1); // Wait 1 second (половина TTL)

        // Read through TieredCache - should get from L2 and populate L1 with REMAINING TTL
        $valueAfterMiss = $cache->get('test_key');
        self::assertEquals('test_value', $valueAfterMiss, 'Should read from L2 after L1 miss');

        // Verify L1 was repopulated
        $l1Value = $l1->get('test_key');
        self::assertInstanceOf(WrappedCacheValue::class, $l1Value, 'L1 should contain WrappedCacheValue');
        self::assertNotNull($l1Value->expiresAt, 'L1 wrapped value should have expiresAt');

        // expiresAt should be in the future but not too far (within original TTL)
        $now = time();
        self::assertGreaterThan($now, $l1Value->expiresAt, 'expiresAt should be in the future');
        self::assertLessThanOrEqual($now + $duration, $l1Value->expiresAt, 'expiresAt should not exceed original TTL');

        // Wait for total TTL to expire (1.2 seconds more = 2.2 seconds total > 2 seconds TTL)
        sleep(2);

        // Value should be expired in all layers
        $expiredValue = $cache->get('test_key');
        self::assertFalse($expiredValue, 'Value should be expired and return false');

        // Verify all layers have expired/cleaned values
        self::assertFalse($l1->get('test_key'), 'L1 should not have expired value');
        self::assertFalse($l2->get('test_key'), 'L2 should not have expired value');
        self::assertFalse($l3->get('test_key'), 'L3 should not have expired value');
    }

    /**
     * Создает TieredCache с ArrayCache и Redis слоями для тестирования
     *
     * Фабричный метод для создания предварительно настроенного экземпляра TieredCache
     * с двумя слоями: быстрый in-memory ArrayCache и распределенный Redis кеш.
     * Используется в большинстве тестов для обеспечения единообразной конфигурации.
     *
     * Конфигурация:
     * - Первый слой (L1): ArrayCache (локальная память, очень быстрый)
     * - Второй слой (L2): Redis (распределенное хранилище, среднее быстродействие)
     *
     * @return TieredCache Настроенный и инициализированный экземпляр
     */
    private function createTieredCache(): TieredCache
    {
        return new TieredCache([
            'layers' => [
                ['cache' => new ArrayCache()],
                ['cache' => $this->createRedisCache()],
            ],
        ]);
    }

    /**
     * Создает экземпляр Redis кеша для использования в тестах
     *
     * Вспомогательный метод для создания настроенного Redis кеша с подключением
     * к реальному Redis серверу через Yii::$app->redis.
     *
     * @return RedisCache Настроенный экземпляр Redis кеша
     */
    private function createRedisCache(): RedisCache
    {
        return new RedisCache([
            'redis' => Yii::$app->redis,
        ]);
    }
}
