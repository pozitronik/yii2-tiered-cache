<?php
declare(strict_types=1);

namespace Beeline\TieredCache\Resilience;

/**
 * Интерфейс для реализации паттерна Circuit Breaker
 *
 * Circuit Breaker предотвращает каскадные сбои в распределенных системах,
 * отслеживая частоту ошибок и временно блокируя запросы при превышении порога.
 *
 * Состояния:
 * - CLOSED: нормальная работа, запросы проходят
 * - OPEN: слишком много ошибок, запросы блокируются
 * - HALF_OPEN: тестовый период после таймаута, проверяем восстановление
 */
interface BreakerInterface
{
    /**
     * Состояние: цепь закрыта, нормальная работа
     */
    public const string STATE_CLOSED = 'closed';

    /**
     * Состояние: цепь открыта, запросы блокируются
     */
    public const string STATE_OPEN = 'open';

    /**
     * Состояние: полуоткрытое, тестирование восстановления
     */
    public const string STATE_HALF_OPEN = 'half_open';

    /**
     * Проверить, разрешен ли запрос
     *
     * @return bool True если запрос может быть выполнен
     */
    public function allowsRequest(): bool;

    /**
     * Зарегистрировать успешное выполнение операции
     */
    public function recordSuccess(): void;

    /**
     * Зарегистрировать ошибку выполнения операции
     */
    public function recordFailure(): void;

    /**
     * Получить текущее состояние breaker
     *
     * @return string Одно из: 'closed', 'open', 'half_open'
     */
    public function getState(): string;

    /**
     * Получить статистику работы breaker
     *
     * @return array Массив со статистикой (failures, successes, etc.)
     */
    public function getStats(): array;

    /**
     * Сбросить состояние breaker (для тестирования)
     */
    public function reset(): void;

    /**
     * Принудительно открыть breaker (для тестирования)
     */
    public function forceOpen(): void;

    /**
     * Принудительно закрыть breaker (для тестирования)
     */
    public function forceClose(): void;

    /**
     * Проверить, находится ли breaker в закрытом состоянии (нормальная работа)
     *
     * @return bool True если в состоянии CLOSED
     */
    public function isClosed(): bool;

    /**
     * Проверить, находится ли breaker в открытом состоянии (блокировка запросов)
     *
     * @return bool True если в состоянии OPEN
     */
    public function isOpen(): bool;

    /**
     * Проверить, находится ли breaker в полуоткрытом состоянии (тестирование)
     *
     * @return bool True если в состоянии HALF_OPEN
     */
    public function isHalfOpen(): bool;
}
