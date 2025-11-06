<?php

declare(strict_types=1);

namespace Beeline\TieredCache\Cache;

use Beeline\TieredCache\Resilience\BreakerInterface;
use Throwable;
use yii\caching\CacheInterface;

/**
 * Слой кеша с защитой через circuit breaker
 *
 * Стандартная реализация CacheLayerInterface, которая инкапсулирует операции
 * с одним слоем многоуровневого кеша. Каждая операция автоматически защищена
 * circuit breaker'ом для предотвращения каскадных сбоев.
 *
 * Метаданные (время истечения, зависимости) хранятся встроенными в значение
 * через WrappedCacheValue. Это обеспечивает атомарность операций, но делает
 * формат несовместимым со стандартным Yii cache.
 *
 * Для совместимости с внешними системами см. альтернативные реализации интерфейса.
 *
 * @see CacheLayerInterface
 */
final readonly class CacheLayer implements CacheLayerInterface
{
    /**
     * Конструктор слоя кеша
     *
     * @param CacheInterface $cache Экземпляр кеша слоя
     * @param BreakerInterface $breaker Circuit breaker для защиты слоя от каскадных сбоев
     * @param int|null $ttl Переопределение TTL слоя в секундах или null для использования глобального TTL
     */
    public function __construct(
        private CacheInterface $cache,
        private BreakerInterface $breaker,
        private ?int $ttl = null,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getBreaker(): BreakerInterface
    {
        return $this->breaker;
    }

    /**
     * @inheritDoc
     */
    public function getTtl(): ?int
    {
        return $this->ttl;
    }

    /**
     * @inheritDoc
     */
    public function getCacheClass(): string
    {
        return get_class($this->cache);
    }

    /**
     * Получить значение из кеша с защитой circuit breaker
     *
     * @param string $key Ключ для получения
     *
     * @return mixed Значение из кеша или false если ключ не найден/circuit открыт
     * @throws Throwable При ошибках взаимодействия с кешем
     */
    public function getValue(string $key): mixed
    {
        if (!$this->breaker->allowsRequest()) {
            return false;
        }

        try {
            $result = $this->cache->get($key);
            $this->breaker->recordSuccess();
            return $result;
        } catch (Throwable $e) {
            $this->breaker->recordFailure();
            throw $e;
        }
    }

    /**
     * Установить значение в кеш с защитой circuit breaker
     *
     * Оборачивает значение в WrappedCacheValue с метаданными времени истечения
     * и зависимостями перед сохранением.
     *
     * @param string $key Ключ для сохранения
     * @param mixed $value Сохраняемое значение (будет обернуто в WrappedCacheValue)
     * @param int $ttl Время жизни в секундах
     * @param DependencyMetadata|null $dependencyMeta Метаданные зависимостей или null
     *
     * @return bool true если значение установлено успешно, false если circuit открыт или операция не удалась
     * @throws Throwable При ошибках взаимодействия с кешем
     */
    public function setValue(string $key, mixed $value, int $ttl, ?DependencyMetadata $dependencyMeta = null): bool
    {
        if (!$this->breaker->allowsRequest()) {
            return false;
        }

        try {
            // Вычисляем абсолютное время истечения
            $expiresAt = ($ttl > 0) ? (time() + $ttl) : null;

            // Оборачиваем значение с метаданными истечения и dependency
            $wrappedValue = new WrappedCacheValue($value, $expiresAt, $dependencyMeta);

            $result = $this->cache->set($key, $wrappedValue, $ttl);
            $this->breaker->recordSuccess();
            return $result;
        } catch (Throwable $e) {
            $this->breaker->recordFailure();
            throw $e;
        }
    }

    /**
     * Добавить значение в кеш (только если ключ не существует) с защитой circuit breaker
     *
     * Оборачивает значение в WrappedCacheValue с метаданными времени истечения
     * и зависимостями перед сохранением. Операция выполнится только если ключ
     * отсутствует в кеше.
     *
     * @param string $key Ключ для добавления
     * @param mixed $value Добавляемое значение (будет обернуто в WrappedCacheValue)
     * @param int $ttl Время жизни в секундах
     * @param DependencyMetadata|null $dependencyMeta Метаданные зависимостей или null
     *
     * @return bool true если значение добавлено успешно, false если circuit открыт/ключ существует/операция не удалась
     * @throws Throwable При ошибках взаимодействия с кешем
     */
    public function addValue(string $key, mixed $value, int $ttl, ?DependencyMetadata $dependencyMeta = null): bool
    {
        if (!$this->breaker->allowsRequest()) {
            return false;
        }

        try {
            // Вычисляем абсолютное время истечения
            $expiresAt = ($ttl > 0) ? (time() + $ttl) : null;

            // Оборачиваем значение с метаданными истечения и dependency
            $wrappedValue = new WrappedCacheValue($value, $expiresAt, $dependencyMeta);

            $result = $this->cache->add($key, $wrappedValue, $ttl);
            $this->breaker->recordSuccess();
            return $result;
        } catch (Throwable $e) {
            $this->breaker->recordFailure();
            throw $e;
        }
    }

    /**
     * Удалить значение из кеша с защитой circuit breaker
     *
     * @param mixed $key Ключ для удаления
     *
     * @return bool true если операция выполнена успешно (независимо от существования ключа), false если circuit открыт
     * @throws Throwable При ошибках взаимодействия с кешем
     */
    public function deleteValue(mixed $key): bool
    {
        if (!$this->breaker->allowsRequest()) {
            return false;
        }

        try {
            $result = $this->cache->delete($key);
            $this->breaker->recordSuccess();
            return $result;
        } catch (Throwable $e) {
            $this->breaker->recordFailure();
            throw $e;
        }
    }

    /**
     * Очистить весь кеш с защитой circuit breaker
     *
     * @return bool true если операция выполнена успешно, false если circuit открыт или операция не удалась
     * @throws Throwable При ошибках взаимодействия с кешем
     */
    public function flushValues(): bool
    {
        if (!$this->breaker->allowsRequest()) {
            return false;
        }

        try {
            $result = $this->cache->flush();
            $this->breaker->recordSuccess();
            return $result;
        } catch (Throwable $e) {
            $this->breaker->recordFailure();
            throw $e;
        }
    }
}
