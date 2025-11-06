<?php

declare(strict_types=1);

namespace Beeline\TieredCache\Cache;

/**
 * Value Object для обертывания кешированных значений с метаданными истечения и зависимостями
 *
 * Immutable объект, представляющий кешированное значение с абсолютным временем
 * истечения и опциональными метаданными dependency для поддержки механизма инвалидации Yii2.
 * Предоставляет вычисляемые свойства для проверки актуальности и расчета оставшегося TTL.
 *
 * Value Object обеспечивает:
 * - Immutability: все свойства readonly, изменение после создания невозможно
 * - Бизнес-логику: вычисляемые свойства для работы с временем истечения
 * - Тип-безопасность: строгая типизация всех свойств
 *
 * Свойства:
 * - $value: Оригинальное кешированное значение (любого типа)
 * - $expiresAt: Абсолютное время истечения (Unix timestamp) или null для бесконечного TTL
 * - $dependencyMeta: Метаданные dependency для инвалидации по тегам/условиям или null
 * - $expired: Вычисляемое свойство - истекло ли значение (bool, readonly)
 * - $remainingTtl: Вычисляемое свойство - оставшееся время жизни в секундах (int, readonly)
 *
 * @property-read bool $expired Истекло ли значение (проверяется на основе текущего времени)
 * @property-read int $remainingTtl Оставшееся время жизни в секундах (минимум 0)
 */
class WrappedCacheValue
{
    /**
     * Вычисляемое свойство: истекло ли значение
     *
     * Проверяет, истекло ли время жизни кешированного значения на основе
     * текущего времени и абсолютного времени истечения.
     */
    public bool $expired {
        /**
         * @return bool
         */
        get {
            return null !== $this->expiresAt && time() >= $this->expiresAt;
        }
    }

    /**
     * Вычисляемое свойство: оставшееся время жизни в секундах
     *
     * Возвращает количество секунд до истечения значения.
     * Если $expiresAt = null (бесконечный TTL), возвращает 0.
     * Если значение уже истекло, возвращает 0.
     * Иначе возвращает количество секунд до истечения (минимум 1).
     */
    public int $remainingTtl {
        /**
         * @return int
         */
        get {
            if (null === $this->expiresAt) {
                return 0; // Бесконечный TTL
            }

            $remaining = $this->expiresAt - time();

            if ($remaining <= 0) {
                return 0; // Уже истекло
            }

            return $remaining;
        }
    }

    /**
     * Конструктор Value Object обернутого значения кеша
     *
     * Создает immutable объект, представляющий кешированное значение с метаданными.
     * После создания все свойства readonly и не могут быть изменены.
     *
     * @param mixed $value Оригинальное кешированное значение
     * @param int|null $expiresAt Абсолютное время истечения (Unix timestamp) или null для бесконечного TTL
     * @param DependencyMetadata|null $dependencyMeta Метаданные dependency для поддержки механизма инвалидации
     */
    public function __construct(
        public readonly mixed $value,
        public readonly ?int $expiresAt,
        public readonly ?DependencyMetadata $dependencyMeta = null,
    ) {
    }
}
