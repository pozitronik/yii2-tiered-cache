<?php

declare(strict_types=1);

namespace Beeline\TieredCache\Cache;

use Beeline\TieredCache\Resilience\BreakerInterface;
use Throwable;

/**
 * Интерфейс слоя кеша для многоуровневого кеширования
 *
 * Определяет контракт для реализации слоев кеша с защитой через circuit breaker.
 *
 * Основные реализации:
 * - CacheLayer: стандартная реализация с WrappedCacheValue
 *
 * @see CacheLayer
 */
interface CacheLayerInterface
{
    /**
     * Получить circuit breaker для этого слоя
     *
     * Используется TieredCache для проверки доступности слоя и получения метрик.
     *
     * @return BreakerInterface Circuit breaker для мониторинга здоровья слоя
     */
    public function getBreaker(): BreakerInterface;

    /**
     * Получить переопределение TTL для этого слоя
     *
     * Если возвращает null, TieredCache использует глобальный TTL.
     * Если возвращает число, оно ограничивает максимальное время жизни для этого слоя.
     *
     * @return int|null Переопределение TTL в секундах или null для использования глобального
     */
    public function getTtl(): ?int;

    /**
     * Получить класс базового кеша этого слоя
     *
     * Используется для мониторинга и отладки. Возвращает полное имя класса
     * базового cache backend (RedisCache, ArrayCache, etc).
     *
     * @return string Полное имя класса кеша
     */
    public function getCacheClass(): string;

    /**
     * Получить значение из кеша с защитой circuit breaker
     *
     * Метод должен:
     * - Проверить доступность через circuit breaker
     * - Выполнить операцию чтения
     * - Зафиксировать успех/неудачу в circuit breaker
     * - Вернуть значение или false если не найдено/circuit открыт
     *
     * @param string $key Ключ для получения
     *
     * @return mixed Значение из кеша или false если не найдено/circuit открыт
     * @throws Throwable При ошибках взаимодействия с кешем (circuit breaker должен зафиксировать failure)
     */
    public function getValue(string $key): mixed;

    /**
     * Установить значение в кеш с защитой circuit breaker
     *
     * Реализация может выбрать стратегию хранения метаданных:
     * - Встроить в значение (WrappedCacheValue)
     * - Сохранить отдельно (DualKeyCacheLayer)
     * - Игнорировать (NoMetadataCacheLayer)
     *
     * @param string $key Ключ для сохранения
     * @param mixed $value Сохраняемое значение
     * @param int $ttl Время жизни в секундах
     * @param DependencyMetadata|null $dependencyMeta Метаданные зависимостей или null
     *
     * @return bool true если значение установлено успешно, false если circuit открыт или операция не удалась
     * @throws Throwable При ошибках взаимодействия с кешем
     */
    public function setValue(string $key, mixed $value, int $ttl, ?DependencyMetadata $dependencyMeta = null): bool;

    /**
     * Добавить значение в кеш (только если ключ не существует) с защитой circuit breaker
     *
     * Семантика аналогична setValue(), но операция выполнится только если ключ отсутствует.
     *
     * @param string $key Ключ для добавления
     * @param mixed $value Добавляемое значение
     * @param int $ttl Время жизни в секундах
     * @param DependencyMetadata|null $dependencyMeta Метаданные зависимостей или null
     *
     * @return bool true если значение добавлено, false если circuit открыт/ключ существует/операция не удалась
     * @throws Throwable При ошибках взаимодействия с кешем
     */
    public function addValue(string $key, mixed $value, int $ttl, ?DependencyMetadata $dependencyMeta = null): bool;

    /**
     * Удалить значение из кеша с защитой circuit breaker
     *
     * @param mixed $key Ключ для удаления
     *
     * @return bool true если операция выполнена успешно (независимо от существования ключа),
     *              false если circuit открыт
     * @throws Throwable При ошибках взаимодействия с кешем
     */
    public function deleteValue(mixed $key): bool;

    /**
     * Очистить весь кеш с защитой circuit breaker
     *
     * @return bool true если операция выполнена успешно, false если circuit открыт или операция не удалась
     * @throws Throwable При ошибках взаимодействия с кешем
     */
    public function flushValues(): bool;
}
