<?php
declare(strict_types=1);

namespace Beeline\TieredCache\Cache;

use Beeline\TieredCache\Resilience\BreakerInterface;
use Beeline\TieredCache\Resilience\CircuitBreaker;
use InvalidArgumentException;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;
use yii\caching\Cache;
use yii\caching\CacheInterface;
use yii\caching\Dependency;
use yii\di\Instance;

/**
 * Многоуровневый кеш с паттерном Circuit Breaker
 *
 * Поддерживает настраиваемые слои кеша с автоматическим переключением и восстановлением.
 * Каждый слой имеет собственный circuit breaker для предотвращения каскадных сбоев.
 *
 * Пример конфигурации:
 * ```php
 * 'cache' => [
 *     'class' => TieredCache::class,
 *     'layers' => [
 *         [
 *             'cache' => ['class' => ApcCache::class, 'useApcu' => true],
 *             'ttl' => 300, // 5 минут для L1
 *             'circuitBreaker' => [
 *                 'failureThreshold' => 0.5,
 *                 'windowSize' => 10,
 *                 'timeout' => 10,
 *             ],
 *         ],
 *         [
 *             'cache' => ['class' => RedisCache::class, 'redis' => 'redis'],
 *             'circuitBreaker' => [
 *                 'failureThreshold' => 0.5,
 *                 'windowSize' => 10,
 *                 'timeout' => 30,
 *             ],
 *         ],
 *         [
 *             'cache' => ['class' => DbCache::class, 'db' => 'db'],
 *             'circuitBreaker' => [
 *                 'failureThreshold' => 0.7,
 *                 'windowSize' => 5,
 *                 'timeout' => 60,
 *             ],
 *         ],
 *     ],
 *     'writeStrategy' => TieredCache::WRITE_THROUGH,
 *     'recoveryStrategy' => TieredCache::RECOVERY_POPULATE,
 * ],
 * ```
 */
class TieredCache extends Cache
{
    /**
     * Запись во все доступные слои (обеспечивает консистентность)
     */
    public const string WRITE_THROUGH = 'write_through';

    /**
     * Запись только в первый доступный слой (быстрее)
     */
    public const string WRITE_FIRST = 'write_first';

    /**
     * Заполнять восстановленный слой из слоев с более низким приоритетом
     */
    public const string RECOVERY_POPULATE = 'populate';

    /**
     * Позволить восстановленному слою заполняться естественным образом при промахах кеша
     */
    public const string RECOVERY_NATURAL = 'natural';

    /**
     * Операция set - записать значение в кеш (перезапишет существующее)
     */
    public const string OP_SET = 'set';

    /**
     * Операция add - добавить значение в кеш (только если ключ не существует)
     */
    public const string OP_ADD = 'add';

    /**
     * Конфигурации слоев кеша
     *
     * Формат:
     * [
     *     [
     *         'cache' => CacheInterface|array, // Экземпляр кеша или конфигурация
     *         'ttl' => int|null,               // Опционально: переопределить TTL для этого слоя
     *         'circuitBreaker' => array|null,  // Опционально: конфигурация circuit breaker
     *     ],
     *     ...
     * ]
     *
     * ВАЖНО: Это свойство используется только во время init(). После инициализации
     * его изменение не повлияет на поведение класса. Используется только $initializedLayers.
     *
     * @var array
     */
    public array $layers = [];

    /**
     * Стратегия записи: WRITE_THROUGH или WRITE_FIRST
     */
    public string $writeStrategy = self::WRITE_THROUGH;

    /**
     * Стратегия восстановления: RECOVERY_POPULATE или RECOVERY_NATURAL
     */
    public string $recoveryStrategy = self::RECOVERY_NATURAL;

    /**
     * Класс circuit breaker по умолчанию для слоев кеша
     *
     * Используется как значение по умолчанию для всех слоев, которые не указали
     * свою собственную реализацию circuit breaker в конфигурации.
     * Может быть переопределен в конфигурации компонента или для конкретного слоя.
     *
     * @var class-string<BreakerInterface>
     */
    public string $defaultBreakerClass = CircuitBreaker::class;

    /**
     * Строгий режим валидации формата кеша
     *
     * Определяет поведение при чтении значений, не являющихся WrappedCacheValue:
     * - false (по умолчанию): Автоматически оборачивать legacy-значения для обратной совместимости.
     *                         Позволяет читать данные, записанные стандартным Yii cache или другими приложениями.
     *                         Логирует события auto-wrap для мониторинга.
     * - true: Считать non-WrappedCacheValue ошибкой формата данных, фиксировать failure в circuit breaker.
     */
    public bool $strictMode = false;

    /**
     * Инициализированные слои кеша
     *
     * @var array<int, CacheLayerInterface>
     */
    private array $initializedLayers = [];

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        if ([] === $this->layers) {
            throw new InvalidConfigException('At least one cache layer must be configured.');
        }

        foreach ($this->layers as $index => $layerConfig) {
            if (!isset($layerConfig['cache'])) {
                throw new InvalidConfigException("Layer $index must have 'cache' configuration.");
            }

            // Инициализация экземпляра кеша
            /** @var CacheInterface $cache */
            $cache = Instance::ensure($layerConfig['cache'], CacheInterface::class);

            // Инициализация circuit breaker
            /** @var BreakerInterface $breaker */
            $breaker = Instance::ensure(
                array_merge($this->getDefaultBreakerConfig(), $layerConfig['circuitBreaker'] ?? []),
                BreakerInterface::class,
            );

            $this->initializedLayers[] = new CacheLayer(
                cache: $cache,
                breaker: $breaker,
                ttl: $layerConfig['ttl'] ?? null,
            );
        }
    }

    /**
     * Возвращает дефолтную конфигурацию circuit breaker
     *
     * @return array<string, mixed>
     */
    protected function getDefaultBreakerConfig(): array
    {
        return [
            'class' => $this->defaultBreakerClass,
            'failureThreshold' => 0.5,
            'windowSize' => 10,
            'timeout' => 30,
        ];
    }

    /**
     * @inheritdoc
     */
    protected function getValue($key)
    {
        $foundValue = false;
        $value = false;
        $populateFromLayer = null;
        $wrappedValue = null;

        foreach ($this->initializedLayers as $index => $layer) {
            try {
                // Метод getValue() слоя возвращает несериализованное значение с защитой circuit breaker
                $wrappedValue = $layer->getValue($key);

                if (false !== $wrappedValue) {
                    // Проверяем, что значение - WrappedCacheValue (Value Object)
                    if (!$wrappedValue instanceof WrappedCacheValue) {
                        if ($this->strictMode) {
                            // Строгий режим: считаем это ошибкой данных
                            $layer->getBreaker()->recordFailure();
                            throw new InvalidArgumentException(
                                "TieredCache strict mode: expected WrappedCacheValue for key '$key', got " .
                                get_debug_type($wrappedValue) . '. This indicates direct layer access or legacy data format.',
                            );
                        }

                        // Режим совместимости: автоматически оборачиваем legacy-значения
                        Yii::info(
                            "Layer $index: auto-wrapping legacy value for key '$key' (type: " . get_debug_type($wrappedValue) . '). ',
                            __METHOD__,
                        );

                        // Оборачиваем значение без метаданных истечения
                        // Backend TTL всё ещё действует на уровне хранилища
                        $wrappedValue = new WrappedCacheValue(
                            value: $wrappedValue,
                            expiresAt: null,  // Полагаемся на TTL бэкенда
                            dependencyMeta: null,
                        );
                    }

                    if ($wrappedValue->expired) {
                        // Пропускаем истекшее значение, полагаясь на то, что бэкэнд кэша сам его удалит по TTL
                        Yii::debug("Value expired in layer $index for key '$key'", __METHOD__);
                        continue;
                    }

                    $foundValue = true;
                    $populateFromLayer = $index;

                    Yii::debug("Cache hit on layer $index for key: $key", __METHOD__);

                    // Сериализуем фактическое значение с dependency перед возвратом (Cache::get() десериализует его)
                    $value = serialize([$wrappedValue->value, $wrappedValue->dependencyMeta?->recreate()]);
                    break;
                }

                // Не найдено, но без ошибки
            } catch (Throwable $e) {
                Yii::warning("Layer $index failed to read key '$key': {$e->getMessage()}", __METHOD__);
                // Переходим к следующему слою
            }
        }

        // Заполняем слои с более высоким приоритетом, если настроено
        if ($foundValue && $populateFromLayer > 0 && self::RECOVERY_POPULATE === $this->recoveryStrategy) {
            $this->populateHigherLayers($key,
                $wrappedValue->value,
                $populateFromLayer,
                $wrappedValue->expiresAt,
                $wrappedValue->dependencyMeta);
        }

        return $value;
    }

    /**
     * @inheritdoc
     */
    protected function setValue($key, $value, $duration): bool
    {
        [$actualValue, $dependencyMeta] = $this->extractValueAndDependency($value);
        return $this->writeToLayers(self::OP_SET, $key, $actualValue, $duration, $dependencyMeta);
    }

    /**
     * @inheritdoc
     */
    protected function addValue($key, $value, $duration): bool
    {
        [$actualValue, $dependencyMeta] = $this->extractValueAndDependency($value);
        return $this->writeToLayers(self::OP_ADD, $key, $actualValue, $duration, $dependencyMeta);
    }

    /**
     * @inheritdoc
     */
    protected function deleteValue($key): bool
    {
        $success = false;

        foreach ($this->initializedLayers as $index => $layer) {
            try {
                if ($layer->deleteValue($key)) {
                    $success = true;
                }
            } catch (Throwable $e) {
                Yii::warning("Layer $index failed to delete key '$key': {$e->getMessage()}", __METHOD__);
            }
        }

        return $success;
    }

    /**
     * @inheritdoc
     */
    protected function flushValues(): bool
    {
        $success = false;

        foreach ($this->initializedLayers as $index => $layer) {
            try {
                if ($layer->flushValues()) {
                    $success = true;
                }
            } catch (Throwable $e) {
                Yii::warning("Layer $index failed to flush: {$e->getMessage()}", __METHOD__);
            }
        }

        return $success;
    }

    /**
     * Получить состояние здоровья всех слоев
     *
     * @return array<int, array{index: int, class: string, breaker_class: string, state: string, stats: array}>
     */
    public function getLayerStatus(): array
    {
        $status = [];

        foreach ($this->initializedLayers as $index => $layer) {
            $breaker = $layer->getBreaker();
            $status[] = [
                'index' => $index,
                'class' => $layer->getCacheClass(),
                'breaker_class' => get_class($breaker),
                'state' => $breaker->getState(),
                'stats' => $breaker->getStats(),
            ];
        }

        return $status;
    }

    /**
     * Принудительно открыть circuit breaker слоя (полезно для тестирования)
     *
     * @param int $layerIndex
     */
    public function forceLayerOpen(int $layerIndex): void
    {
        if (isset($this->initializedLayers[$layerIndex])) {
            $this->initializedLayers[$layerIndex]->getBreaker()->forceOpen();
        }
    }

    /**
     * Принудительно закрыть circuit breaker слоя (полезно для тестирования)
     *
     * @param int $layerIndex
     */
    public function forceLayerClose(int $layerIndex): void
    {
        if (isset($this->initializedLayers[$layerIndex])) {
            $this->initializedLayers[$layerIndex]->getBreaker()->forceClose();
        }
    }

    /**
     * Сбросить все circuit breakers (полезно для тестирования)
     */
    public function resetCircuitBreakers(): void
    {
        foreach ($this->initializedLayers as $layer) {
            $layer->getBreaker()->reset();
        }
    }

    /**
     * Извлекает и валидирует значение и dependency из сериализованных данных
     *
     * Десериализует входное значение и извлекает из него фактическое значение и dependency.
     * Если dependency присутствует, валидирует его тип и конвертирует в DependencyMetadata.
     *
     * @param string $value Сериализованное значение (содержит либо чистое значение, либо [value, dependency])
     * @return array{0: mixed, 1: DependencyMetadata|null} Массив [фактическое значение, metadata dependency]
     * @throws InvalidArgumentException Если dependency имеет некорректный тип
     */
    private function extractValueAndDependency(string $value): array
    {
        // Десериализуем значение (Cache::set()/add() уже сериализовали его)
        // Разрешаем все классы, так как кешируемые значения могут быть любого типа (примитивы, массивы, объекты)
        $unserializedValue = unserialize($value, ['allowed_classes' => true]);

        $actualValue = $unserializedValue;
        $dependencyMeta = null;

        // Извлекаем value и dependency из массива [value, dependency]
        if (is_array($unserializedValue) && 2 === count($unserializedValue)) {
            [$actualValue, $dependency] = $unserializedValue;

            // Валидация и конвертация dependency в metadata
            if (null !== $dependency) {
                if (!$dependency instanceof Dependency) {
                    throw new InvalidArgumentException(
                        'Unexpected dependency type: expected null or Dependency instance, got ' .
                        get_debug_type($dependency) . '. This indicates data corruption or invalid cache structure.',
                    );
                }
                $dependencyMeta = DependencyMetadata::fromDependency($dependency);
            }
        }

        return [$actualValue, $dependencyMeta];
    }

    /**
     * Записать или добавить значение в слои кеша
     *
     * Унифицированный метод для записи значений в слои кеша с поддержкой двух стратегий:
     * - First Available: запись в первый доступный слой с немедленным возвратом после успеха
     * - Write-Through: попытка записи во все слои, возврат true если хотя бы один слой успешен
     *
     * Стратегия определяется из $this->writeStrategy.
     *
     * @param string $operation Операция кеша: self::OP_SET или self::OP_ADD
     * @param string $key Ключ кеша
     * @param mixed $value Несериализованное значение
     * @param int $duration Время жизни в секундах
     * @param DependencyMetadata|null $dependencyMeta Метаданные dependency или null
     *
     * @return bool true, если операция выполнена успешно хотя бы на одном слое
     */
    private function writeToLayers(
        string $operation,
        string $key,
        mixed $value,
        int $duration,
        ?DependencyMetadata $dependencyMeta = null,
    ): bool {
        $anySuccess = false;

        foreach ($this->initializedLayers as $index => $layer) {
            $ttl = $layer->getTtl() ?? $duration;

            try {
                // Вызываем соответствующий метод слоя (setValue или addValue)
                $result = ($operation === self::OP_SET)
                    ? $layer->setValue($key, $value, $ttl, $dependencyMeta)
                    : $layer->addValue($key, $value, $ttl, $dependencyMeta);

                if ($result) {
                    $anySuccess = true;

                    // First available: возвращаемся сразу после первого успеха
                    if (self::WRITE_FIRST === $this->writeStrategy) {
                        return true;
                    }
                }
            } catch (Throwable $e) {
                Yii::warning("Layer $index failed to $operation key '$key': {$e->getMessage()}", __METHOD__);
            }
        }

        return $anySuccess;
    }

    /**
     * Заполнить слои с более высоким приоритетом кешированным значением
     *
     * @param string $key
     * @param mixed $value Несериализованное значение (уже развернутое из wrapper)
     * @param int $fromLayerIndex Индекс слоя, где было найдено значение
     * @param int|null $expiresAt Абсолютное время истечения (Unix timestamp) или null для бесконечного TTL
     * @param DependencyMetadata|null $dependencyMeta Метаданные dependency или null
     */
    private function populateHigherLayers(
        string $key,
        mixed $value,
        int $fromLayerIndex,
        ?int $expiresAt,
        ?DependencyMetadata $dependencyMeta = null): void
    {
        for ($i = 0; $i < $fromLayerIndex; $i++) {
            $layer = $this->initializedLayers[$i];

            // Заполняем только если цепь закрыта (слой здоров)
            if (!$layer->getBreaker()->isClosed()) {
                continue;
            }

            // Вычисляем оставшееся время жизни из абсолютного времени истечения
            $remainingTtl = $this->calculateRemainingTtl($expiresAt, $layer->getTtl());

            try {
                $layer->setValue($key, $value, $remainingTtl, $dependencyMeta);
                Yii::debug("Populated layer $i with key '$key' from layer $fromLayerIndex (TTL: {$remainingTtl}s)", __METHOD__);
            } catch (Throwable $e) {
                Yii::warning("Failed to populate layer $i: {$e->getMessage()}", __METHOD__);
            }
        }
    }

    /**
     * Вычисляет оставшееся время жизни с учетом ограничений слоя кеша
     *
     * В отличие от WrappedCacheValue::$remainingTtl, этот метод применяет специфичную
     * для TieredCache бизнес-логику:
     * 1. Применяет максимальное TTL ограничение конкретного слоя (например, L1 может
     *    иметь ограничение на 5 минут, даже если значение имеет TTL на 1 час)
     * 2. Гарантирует минимум 1 секунду (защита от немедленного истечения при записи)
     * 3. Корректно обрабатывает бесконечный TTL с учетом ограничений слоя
     *
     * @param int|null $expiresAt Абсолютное время истечения (Unix timestamp) или null для бесконечного TTL
     * @param int|null $layerTtl Максимальное TTL слоя в секундах или null без ограничений
     * @return int Оставшееся время в секундах (минимум 1, максимум согласно layerTtl)
     */
    private function calculateRemainingTtl(?int $expiresAt, ?int $layerTtl): int
    {
        // Если время истечения не задано - бесконечный TTL
        if (null === $expiresAt) {
            return $layerTtl ?? 0;
        }

        // Вычисляем оставшееся время
        $remaining = max(1, $expiresAt - time());

        // Применяем ограничение TTL слоя, если оно установлено
        if (null !== $layerTtl && $layerTtl > 0) {
            $remaining = min($remaining, $layerTtl);
        }

        return $remaining;
    }
}
